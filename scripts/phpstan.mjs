import { access } from 'node:fs/promises';

import { runProcess } from './lib/process.mjs';

const requiredPluginDirectories = [
	'wp-env/plugins/easy-digital-downloads-pro',
	'wp-env/plugins/edd-software-licensing',
];

const missingDirectories = [];

for ( const directory of requiredPluginDirectories ) {
	try {
		await access( directory );
	} catch {
		missingDirectories.push( directory );
	}
}

if ( missingDirectories.length > 0 ) {
	throw new Error(
		`PHPStan requires the local EDD test plugins. Missing:\n${ missingDirectories
			.map( ( directory ) => `- ${ directory }` )
			.join(
				'\n'
			) }\nSee wp-env/plugins/README.md for setup instructions.`
	);
}

await runProcess( process.platform === 'win32' ? 'composer.bat' : 'composer', [
	'run',
	'phpstan',
] );
