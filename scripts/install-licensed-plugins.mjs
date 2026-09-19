/* eslint-disable no-console */

import { createHash } from 'node:crypto';
import { spawn } from 'node:child_process';
import { cp, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
	buildGitHubContentsUrl,
	findArchiveEntry,
	getCatalogRelease,
	readPluginVersion,
	selectPluginInstallSource,
	validateArchivePaths,
} from './lib/licensed-plugin-catalog.mjs';
import { runProcess } from './lib/process.mjs';

const projectDirectory = resolve(
	dirname( fileURLToPath( import.meta.url ) ),
	'..'
);
const catalogSource = {
	repository: 'Code-Amp/wp-dependencies',
	ref: '9ace9fd37db82bee6d515ac15c805116abb73917',
};
const maximumDownloadBytes = 100 * 1024 * 1024;
const maximumGitHubCliResponseBytes =
	Math.ceil( ( maximumDownloadBytes * 4 ) / 3 ) + 1024 * 1024;
const profileName = process.argv[ 2 ] ?? 'minimum';
const testMatrix = JSON.parse(
	await readFile(
		resolve( projectDirectory, 'scripts/test-matrix.json' ),
		'utf8'
	)
);
const profile = testMatrix[ profileName ];

if ( ! profile ) {
	throw new Error(
		`Unknown test profile "${ profileName }". Use: ${ Object.keys(
			testMatrix
		).join( ', ' ) }.`
	);
}

const plugins = [
	{
		name: 'Easy Digital Downloads Pro',
		packageSlug: 'easy-digital-downloads-pro',
		version: profile.eddVersion,
		environmentVariable: 'EDD_PRO_ZIP_URL',
		entryFile: 'easy-digital-downloads.php',
		destination: 'wp-env/plugins/easy-digital-downloads-pro',
	},
	{
		name: 'EDD Software Licensing',
		packageSlug: 'edd-software-licensing',
		version: profile.softwareLicensingVersion,
		environmentVariable: 'EDD_SOFTWARE_LICENSING_ZIP_URL',
		entryFile: 'edd-software-licenses.php',
		destination: 'wp-env/plugins/edd-software-licensing',
	},
];
let catalogPromise;

/**
 * Runs a bounded GitHub CLI API request.
 *
 * @param {string} endpoint       GitHub API endpoint.
 * @param {number} maximumBytes   Maximum response size.
 * @param {string} failureMessage Safe failure message.
 * @return {Promise<Buffer>} Response body.
 */
const runGitHubCliRequest = ( endpoint, maximumBytes, failureMessage ) =>
	new Promise( ( resolveDownload, reject ) => {
		const child = spawn(
			'gh',
			[
				'api',
				'--hostname',
				'github.com',
				'--method',
				'GET',
				'--header',
				'X-GitHub-Api-Version: 2022-11-28',
				endpoint,
			],
			{ stdio: [ 'ignore', 'pipe', 'pipe' ] }
		);
		const chunks = [];
		let byteLength = 0;
		let settled = false;

		const fail = ( message ) => {
			if ( settled ) {
				return;
			}

			settled = true;
			reject( new Error( message ) );
		};

		child.stdout.on( 'data', ( chunk ) => {
			byteLength += chunk.length;

			if ( byteLength > maximumBytes ) {
				child.kill();
				fail( failureMessage );
				return;
			}

			chunks.push( chunk );
		} );
		child.stderr.resume();
		child.on( 'error', ( error ) => {
			fail(
				error.code === 'ENOENT'
					? 'GitHub CLI is required for Code Amp catalogue access. Install gh, run `gh auth login`, or use private archive URL overrides.'
					: failureMessage
			);
		} );
		child.on( 'close', ( code ) => {
			if ( settled ) {
				return;
			}

			if ( code !== 0 ) {
				fail( failureMessage );
				return;
			}

			settled = true;
			resolveDownload( Buffer.concat( chunks ) );
		} );
	} );

/**
 * Downloads a private GitHub file using the developer's existing gh session.
 *
 * The credential remains inside GitHub CLI's credential store and is never
 * returned to this process. Git blobs are transported as JSON-safe base64 so
 * GitHub CLI does not attempt to render binary archive output.
 *
 * @param {string} url   Pinned GitHub Contents API URL.
 * @param {string} label Safe diagnostic label.
 * @return {Promise<Buffer>} Response body.
 */
const downloadWithGitHubCli = async ( url, label ) => {
	const parsedUrl = new URL( url );
	const metadataEndpoint = `${ parsedUrl.pathname.replace( /^\//, '' ) }${ parsedUrl.search }`;
	const failureMessage =
		'The Code Amp dependency catalogue could not be accessed with GitHub CLI. Run `gh auth login` with an account that can read Code-Amp/wp-dependencies, install the plugins manually, or use private archive URL overrides.';
	const metadataResponse = await runGitHubCliRequest(
		metadataEndpoint,
		1024 * 1024,
		failureMessage
	);
	let metadata;

	try {
		metadata = JSON.parse( metadataResponse.toString( 'utf8' ) );
	} catch {
		throw new Error( `${ label } metadata contains invalid JSON.` );
	}

	if (
		metadata?.type !== 'file' ||
		! /^[a-f0-9]{40}$/.test( metadata.sha ?? '' ) ||
		! Number.isSafeInteger( metadata.size ) ||
		metadata.size < 0
	) {
		throw new Error( `${ label } metadata is invalid.` );
	}

	if ( metadata.size > maximumDownloadBytes ) {
		throw new Error( `${ label } exceeds the 100 MB download limit.` );
	}

	const blobResponse = await runGitHubCliRequest(
		`repos/${ catalogSource.repository }/git/blobs/${ metadata.sha }`,
		maximumGitHubCliResponseBytes,
		failureMessage
	);
	let blob;

	try {
		blob = JSON.parse( blobResponse.toString( 'utf8' ) );
	} catch {
		throw new Error( `${ label } blob contains invalid JSON.` );
	}

	if ( blob?.encoding !== 'base64' || typeof blob.content !== 'string' ) {
		throw new Error( `${ label } blob encoding is invalid.` );
	}

	const body = Buffer.from( blob.content.replaceAll( /\s/g, '' ), 'base64' );

	if ( body.length !== metadata.size ) {
		throw new Error( `${ label } blob size does not match its metadata.` );
	}

	return body;
};

/**
 * Downloads a bounded response without exposing its source URL in errors.
 *
 * @param {string} url     Download URL.
 * @param {Object} options Fetch options.
 * @param {string} label   Safe diagnostic label.
 * @return {Promise<Buffer>} Response body.
 */
const download = async ( url, options, label ) => {
	let response;

	try {
		response = await fetch( url, { redirect: 'follow', ...options } );
	} catch {
		throw new Error( `${ label } request failed.` );
	}

	if ( ! response.ok ) {
		throw new Error( `${ label } failed with HTTP ${ response.status }.` );
	}

	const contentLength = Number( response.headers.get( 'content-length' ) );

	if ( contentLength > maximumDownloadBytes ) {
		throw new Error( `${ label } exceeds the 100 MB download limit.` );
	}

	const body = Buffer.from( await response.arrayBuffer() );

	if ( body.length > maximumDownloadBytes ) {
		throw new Error( `${ label } exceeds the 100 MB download limit.` );
	}

	return body;
};

/**
 * Creates headers for a read-only GitHub Contents API request.
 *
 * @param {string} token GitHub token.
 * @return {Object} Request headers.
 */
const getGitHubHeaders = ( token ) => ( {
	Accept: 'application/vnd.github.raw+json',
	Authorization: `Bearer ${ token }`,
	'User-Agent': 'Code-Amp-EDD-Composer-CI',
	'X-GitHub-Api-Version': '2022-11-28',
} );

/**
 * Loads the catalogue once for all required plugins.
 *
 * @return {Promise<Object>} Parsed catalogue.
 */
const getCatalog = () => {
	if ( ! catalogPromise ) {
		catalogPromise = ( async () => {
			const url = buildGitHubContentsUrl(
				catalogSource.repository,
				catalogSource.ref,
				'catalog.json'
			);
			const token = process.env.WP_DEPENDENCIES_TOKEN;
			const body = token
				? await download(
						url,
						{ headers: getGitHubHeaders( token ) },
						'Dependency catalogue download'
					)
				: await downloadWithGitHubCli(
						url,
						'Dependency catalogue download'
					);

			try {
				return JSON.parse( body.toString( 'utf8' ) );
			} catch {
				throw new Error(
					'The dependency catalogue contains invalid JSON.'
				);
			}
		} )();
	}

	return catalogPromise;
};

/**
 * Resolves an override URL or a pinned catalogue archive.
 *
 * @param {Object} plugin Plugin provisioning definition.
 * @return {Promise<Object>} Archive contents and expected checksum.
 */
const getArchive = async ( plugin ) => {
	const sourceUrl = process.env[ plugin.environmentVariable ];
	const token = process.env.WP_DEPENDENCIES_TOKEN;
	const source = selectPluginInstallSource( {
		overrideUrl: sourceUrl,
		catalogToken: token,
		isGitHubActions: process.env.GITHUB_ACTIONS === 'true',
	} );

	if ( source === 'url' ) {
		let parsedUrl;

		try {
			parsedUrl = new URL( sourceUrl );
		} catch {
			throw new Error(
				`${ plugin.environmentVariable } must contain a valid HTTPS URL.`
			);
		}

		if ( parsedUrl.protocol !== 'https:' ) {
			throw new Error(
				`${ plugin.environmentVariable } must contain a valid HTTPS URL.`
			);
		}

		return {
			body: await download(
				sourceUrl,
				{},
				`${ plugin.name } archive download`
			),
			checksum: null,
			source: 'URL override',
		};
	}

	if ( ! source ) {
		throw new Error(
			`Set ${ plugin.environmentVariable } to your own private archive URL or provide WP_DEPENDENCIES_TOKEN for the Code Amp dependency catalogue. GitHub CLI fallback is intentionally disabled in GitHub Actions.`
		);
	}

	const catalog = await getCatalog();
	const release = getCatalogRelease(
		catalog,
		plugin.packageSlug,
		plugin.version,
		plugin.entryFile
	);
	const url = buildGitHubContentsUrl(
		catalogSource.repository,
		catalogSource.ref,
		release.file
	);

	return {
		body:
			source === 'token'
				? await download(
						url,
						{ headers: getGitHubHeaders( token ) },
						`${ plugin.name } catalogue archive download`
					)
				: await downloadWithGitHubCli(
						url,
						`${ plugin.name } catalogue archive download`
					),
		checksum: release.sha256,
		source:
			source === 'token'
				? 'dependency catalogue'
				: 'dependency catalogue via GitHub CLI',
	};
};

/**
 * Downloads, validates, and safely extracts one licensed plugin archive.
 *
 * @param {Object} plugin Plugin provisioning definition.
 * @return {Promise<void>}
 */
const installPlugin = async ( plugin ) => {
	const archive = await getArchive( plugin );
	const checksum = createHash( 'sha256' )
		.update( archive.body )
		.digest( 'hex' );

	if ( archive.checksum && checksum !== archive.checksum ) {
		throw new Error(
			`${ plugin.name } archive failed SHA-256 verification.`
		);
	}

	const temporaryDirectory = await mkdtemp(
		join( tmpdir(), 'edd-composer-plugin-' )
	);
	const archivePath = join( temporaryDirectory, 'plugin.zip' );
	const extractionPath = join( temporaryDirectory, 'extracted' );

	try {
		await writeFile( archivePath, archive.body );
		const listing = await runProcess( 'unzip', [ '-Z1', archivePath ], {
			capture: true,
		} );
		const paths = listing.stdout.split( /\r?\n/ ).filter( Boolean );

		try {
			validateArchivePaths( paths );
		} catch ( error ) {
			throw new Error( `${ plugin.name }: ${ error.message }` );
		}

		const detailedListing = await runProcess(
			'unzip',
			[ '-Z', '-l', archivePath ],
			{ capture: true }
		);

		if (
			detailedListing.stdout
				.split( /\r?\n/ )
				.some( ( line ) => /^l[rwx-]{9}\s/.test( line ) )
		) {
			throw new Error(
				`${ plugin.name } archive contains a symbolic link.`
			);
		}

		let entryArchivePath;

		try {
			entryArchivePath = findArchiveEntry( paths, plugin.entryFile );
		} catch ( error ) {
			throw new Error( `${ plugin.name }: ${ error.message }` );
		}

		await runProcess( 'unzip', [
			'-q',
			archivePath,
			'-d',
			extractionPath,
		] );
		const entryPath = resolve(
			extractionPath,
			...entryArchivePath.split( '/' )
		);
		const installedVersion = readPluginVersion(
			await readFile( entryPath, 'utf8' )
		);

		if ( installedVersion !== plugin.version ) {
			throw new Error(
				`${ plugin.name } must be ${ plugin.version }; found ${ installedVersion ?? 'no plugin version' }.`
			);
		}

		const destination = resolve( projectDirectory, plugin.destination );
		await rm( destination, { force: true, recursive: true } );
		await cp( dirname( entryPath ), destination, { recursive: true } );
		console.log(
			`Installed ${ plugin.name } ${ plugin.version } from ${ archive.source }.`
		);
	} finally {
		await rm( temporaryDirectory, { force: true, recursive: true } );
	}
};

for ( const plugin of plugins ) {
	await installPlugin( plugin );
}
