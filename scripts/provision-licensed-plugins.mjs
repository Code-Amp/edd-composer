/* eslint-disable no-console */

import { createHash } from 'node:crypto';
import { cp, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
	buildGitHubContentsUrl,
	findArchiveEntry,
	getCatalogRelease,
	readPluginVersion,
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
 * @param {string} token GitHub token.
 * @return {Promise<Object>} Parsed catalogue.
 */
const getCatalog = ( token ) => {
	if ( ! catalogPromise ) {
		catalogPromise = ( async () => {
			const url = buildGitHubContentsUrl(
				catalogSource.repository,
				catalogSource.ref,
				'catalog.json'
			);
			const body = await download(
				url,
				{ headers: getGitHubHeaders( token ) },
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

	if ( sourceUrl ) {
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

	const token = process.env.WP_DEPENDENCIES_TOKEN;

	if ( ! token ) {
		throw new Error(
			`Set ${ plugin.environmentVariable } to your own private archive URL or provide WP_DEPENDENCIES_TOKEN for the Code Amp dependency catalogue.`
		);
	}

	const catalog = await getCatalog( token );
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
		body: await download(
			url,
			{ headers: getGitHubHeaders( token ) },
			`${ plugin.name } catalogue archive download`
		),
		checksum: release.sha256,
		source: 'dependency catalogue',
	};
};

/**
 * Downloads, validates, and safely extracts one licensed plugin archive.
 *
 * @param {Object} plugin Plugin provisioning definition.
 * @return {Promise<void>}
 */
const provision = async ( plugin ) => {
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
			`Provisioned ${ plugin.name } ${ plugin.version } from ${ archive.source }.`
		);
	} finally {
		await rm( temporaryDirectory, { force: true, recursive: true } );
	}
};

for ( const plugin of plugins ) {
	await provision( plugin );
}
