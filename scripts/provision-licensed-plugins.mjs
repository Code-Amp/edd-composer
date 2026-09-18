/* eslint-disable no-console */

import { cp, mkdtemp, readdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { runProcess } from './lib/process.mjs';

const projectDirectory = resolve(
	dirname( fileURLToPath( import.meta.url ) ),
	'..'
);
const plugins = [
	{
		name: 'Easy Digital Downloads Pro',
		environmentVariable: 'EDD_PRO_ZIP_URL',
		entryFile: 'easy-digital-downloads.php',
		destination: 'wp-env/plugins/easy-digital-downloads-pro',
	},
	{
		name: 'EDD Software Licensing',
		environmentVariable: 'EDD_SOFTWARE_LICENSING_ZIP_URL',
		entryFile: 'edd-software-licenses.php',
		destination: 'wp-env/plugins/edd-software-licensing',
	},
];

/**
 * Finds a plugin entry file within an extracted archive.
 *
 * @param {string} directory Directory to search.
 * @param {string} entryFile Expected plugin entry filename.
 * @return {Promise<string|null>} Entry file path when found.
 */
const findEntryFile = async ( directory, entryFile ) => {
	for ( const entry of await readdir( directory, { withFileTypes: true } ) ) {
		const path = join( directory, entry.name );

		if ( entry.isDirectory() ) {
			const nested = await findEntryFile( path, entryFile );

			if ( nested ) {
				return nested;
			}
		} else if ( entry.isFile() && entry.name === entryFile ) {
			return path;
		}
	}

	return null;
};

/**
 * Downloads and safely extracts one licensed plugin archive.
 *
 * @param {Object} plugin Plugin provisioning definition.
 * @return {Promise<void>}
 */
const provision = async ( plugin ) => {
	const sourceUrl = process.env[ plugin.environmentVariable ];

	if ( ! sourceUrl ) {
		throw new Error(
			`${ plugin.environmentVariable } must contain a private, short-lived download URL for ${ plugin.name }.`
		);
	}

	const temporaryDirectory = await mkdtemp(
		join( tmpdir(), 'edd-composer-plugin-' )
	);
	const archivePath = join( temporaryDirectory, 'plugin.zip' );
	const extractionPath = join( temporaryDirectory, 'extracted' );

	try {
		const response = await fetch( sourceUrl, { redirect: 'follow' } );

		if ( ! response.ok ) {
			throw new Error(
				`${ plugin.name } archive download failed with HTTP ${ response.status }.`
			);
		}

		await writeFile(
			archivePath,
			Buffer.from( await response.arrayBuffer() )
		);
		const listing = await runProcess( 'unzip', [ '-Z1', archivePath ], {
			capture: true,
		} );
		const paths = listing.stdout.split( /\r?\n/ ).filter( Boolean );

		if (
			paths.length === 0 ||
			paths.some(
				( path ) =>
					path.startsWith( '/' ) ||
					path.startsWith( '\\' ) ||
					path.split( /[\\/]/ ).includes( '..' )
			)
		) {
			throw new Error(
				`${ plugin.name } archive contains an unsafe path.`
			);
		}

		await runProcess( 'unzip', [
			'-q',
			archivePath,
			'-d',
			extractionPath,
		] );
		const entryFile = await findEntryFile(
			extractionPath,
			plugin.entryFile
		);

		if ( ! entryFile ) {
			throw new Error(
				`${ plugin.name } archive does not contain ${ plugin.entryFile }.`
			);
		}

		const destination = resolve( projectDirectory, plugin.destination );
		await rm( destination, { force: true, recursive: true } );
		await cp( dirname( entryFile ), destination, { recursive: true } );
		console.log(
			`Provisioned ${ plugin.name } for the wp-env test profile.`
		);
	} finally {
		await rm( temporaryDirectory, { force: true, recursive: true } );
	}
};

for ( const plugin of plugins ) {
	await provision( plugin );
}
