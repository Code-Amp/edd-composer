import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import matrix from './test-matrix.json' with { type: 'json' };

const projectDirectory = resolve(
	dirname( fileURLToPath( import.meta.url ) ),
	'..'
);
const profileName = process.argv[ 2 ];
const profile = matrix[ profileName ];

if ( ! profile ) {
	throw new Error(
		`Unknown test profile "${ profileName ?? '' }". Use: ${ Object.keys(
			matrix
		).join( ', ' ) }.`
	);
}

const outputDirectory = resolve(
	projectDirectory,
	'.wp-env.generated',
	profileName
);
const phpunitDirectory = resolve( outputDirectory, 'phpunit' );
const configPath = resolve( outputDirectory, 'wp-env.json' );
const phpunitManifestPath = resolve( phpunitDirectory, 'composer.json' );
const relativePhpunitManifest = `.wp-env.generated/${ profileName }/phpunit/composer.json`;
const config = {
	$schema: 'https://schemas.wp.org/trunk/wp-env.json',
	core: profile.wordpressSource,
	port: profile.port,
	phpVersion: profile.phpVersion,
	testsEnvironment: false,
	plugins: [
		'./wp-env/plugins/easy-digital-downloads-pro',
		'./wp-env/plugins/edd-software-licensing',
		'./edd-composer',
	],
	config: {
		EDD_COMPOSER_ALLOW_INSECURE_HTTP: true,
		EDD_COMPOSER_TEST_EXPECTED_WORDPRESS_VERSION: profile.wordpressVersion,
		EDD_COMPOSER_TEST_EXPECTED_PHP_VERSION: profile.phpVersion,
		EDD_COMPOSER_TEST_EXPECTED_EDD_VERSION: profile.eddVersion,
		EDD_COMPOSER_TEST_EXPECTED_SL_VERSION: profile.softwareLicensingVersion,
		WP_ENVIRONMENT_TYPE: 'local',
		WP_DEBUG: true,
		WP_DEBUG_DISPLAY: true,
		SCRIPT_DEBUG: true,
	},
	mappings: {
		'composer.json': `./${ relativePhpunitManifest }`,
		tests: './tests/php',
	},
};
const phpunitManifest = {
	name: 'code-amp/edd-composer-wp-env-phpunit',
	description: `Generated PHPUnit dependencies for the ${ profileName } compatibility profile.`,
	type: 'project',
	license: 'GPL-2.0-or-later',
	'require-dev': {
		'phpunit/phpunit': profile.phpunitVersion,
		'wp-phpunit/wp-phpunit': profile.wordpressPhpunitVersion,
		'yoast/phpunit-polyfills': '4.0.0',
	},
	config: {
		platform: { php: `${ profile.phpVersion }.0` },
	},
};

await mkdir( phpunitDirectory, { recursive: true } );
await writeFile(
	phpunitManifestPath,
	`${ JSON.stringify( phpunitManifest, null, '\t' ) }\n`,
	'utf8'
);
await writeFile(
	configPath,
	`${ JSON.stringify( config, null, '\t' ) }\n`,
	'utf8'
);

process.stdout.write( `${ configPath }\n` );
