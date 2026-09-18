import { readdir } from 'node:fs/promises';
import { dirname, extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { runProcess } from './lib/process.mjs';

const projectDirectory = resolve(
	dirname( fileURLToPath( import.meta.url ) ),
	'..'
);

const findPhpFiles = async ( directory ) => {
	const entries = await readdir( directory, { withFileTypes: true } );
	const files = [];

	for ( const entry of entries ) {
		const path = resolve( directory, entry.name );

		if ( entry.isDirectory() ) {
			files.push( ...( await findPhpFiles( path ) ) );
		} else if ( extname( entry.name ) === '.php' ) {
			files.push( path );
		}
	}

	return files;
};

const files = (
	await Promise.all(
		[ 'edd-composer', 'tests/php' ].map( ( directory ) =>
			findPhpFiles( resolve( projectDirectory, directory ) )
		)
	)
).flat();
const failures = [];

for ( const file of files ) {
	try {
		await runProcess( 'php', [ '-l', file ], {
			capture: true,
			cwd: projectDirectory,
		} );
	} catch ( error ) {
		failures.push( error );
	}
}

if ( failures.length > 0 ) {
	throw new AggregateError(
		failures,
		failures.map( ( error ) => error.message ).join( '\n' )
	);
}

process.stdout.write( `PHP syntax is valid for ${ files.length } files.\n` );
