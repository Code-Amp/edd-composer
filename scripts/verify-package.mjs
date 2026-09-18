import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { runPnpm, runProcess } from './lib/process.mjs';

const projectDirectory = resolve(
	dirname( fileURLToPath( import.meta.url ) ),
	'..'
);
const configPath = resolve( projectDirectory, '.wp-env.package.json' );
const runWpEnv = ( args, options = {} ) =>
	runPnpm(
		[
			'--dir',
			projectDirectory,
			'exec',
			'wp-env',
			`--config=${ configPath }`,
			...args,
		],
		options
	);

const archive = await runProcess( 'unzip', [ '-Z1', 'edd-composer.zip' ], {
	capture: true,
	cwd: projectDirectory,
} );
const archiveFiles = archive.stdout
	.split( /\r?\n/ )
	.filter( ( path ) => path && ! path.endsWith( '/' ) )
	.sort();
const tracked = await runProcess( 'git', [ 'ls-files', '--', 'edd-composer' ], {
	capture: true,
	cwd: projectDirectory,
} );
const trackedFiles = tracked.stdout.split( /\r?\n/ ).filter( Boolean ).sort();

if (
	archiveFiles.length === 0 ||
	archiveFiles.some(
		( path ) =>
			! path.startsWith( 'edd-composer/' ) ||
			path.includes( '/.DS_Store' ) ||
			path.includes( '/../' )
	)
) {
	throw new Error( 'The plugin ZIP contains an invalid path.' );
}

if ( JSON.stringify( archiveFiles ) !== JSON.stringify( trackedFiles ) ) {
	const missing = trackedFiles.filter(
		( path ) => ! archiveFiles.includes( path )
	);
	const unexpected = archiveFiles.filter(
		( path ) => ! trackedFiles.includes( path )
	);
	throw new Error(
		[
			'The plugin ZIP does not match the tracked distributable.',
			missing.length ? `Missing: ${ missing.join( ', ' ) }` : '',
			unexpected.length ? `Unexpected: ${ unexpected.join( ', ' ) }` : '',
		]
			.filter( Boolean )
			.join( '\n' )
	);
}

let failure = null;

await runWpEnv( [ 'destroy', '--force' ], {
	allowFailure: true,
	capture: true,
} );

try {
	await runWpEnv( [ 'start' ] );
	await runWpEnv( [
		'run',
		'cli',
		'wp',
		'plugin',
		'install',
		'/var/www/html/edd-composer.zip',
		'--activate',
		'--force',
	] );
	await runWpEnv( [
		'run',
		'cli',
		'wp',
		'plugin',
		'is-active',
		'edd-composer',
	] );
} catch ( error ) {
	failure = error;
}

try {
	await runWpEnv( [ 'destroy', '--force' ] );
} catch ( cleanupError ) {
	if ( failure ) {
		throw new AggregateError(
			[ failure, cleanupError ],
			'The package verification and clean-environment teardown both failed.'
		);
	}

	throw cleanupError;
}

if ( failure ) {
	throw failure;
}

process.stdout.write(
	`Verified ${ archiveFiles.length } distributable files and clean ZIP activation.\n`
);
