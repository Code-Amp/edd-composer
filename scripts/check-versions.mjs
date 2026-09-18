import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectDirectory = resolve(
	dirname( fileURLToPath( import.meta.url ) ),
	'..'
);
const read = ( path ) => readFile( resolve( projectDirectory, path ), 'utf8' );
const [
	packageManifest,
	pluginSource,
	readme,
	dependenciesSource,
	testConfigSource,
	matrixSource,
	phpunitManifestSource,
] = await Promise.all( [
	read( 'package.json' ),
	read( 'edd-composer/edd-composer.php' ),
	read( 'edd-composer/readme.txt' ),
	read( 'edd-composer/includes/class-dependencies.php' ),
	read( '.wp-env.tests.json' ),
	read( 'scripts/test-matrix.json' ),
	read( 'wp-env/phpunit/composer.json' ),
] );
const packageData = JSON.parse( packageManifest );
const testConfig = JSON.parse( testConfigSource );
const matrix = JSON.parse( matrixSource );
const phpunitManifest = JSON.parse( phpunitManifestSource );
const failures = [];
const match = ( source, pattern, label ) => {
	const value = source.match( pattern )?.[ 1 ];

	if ( ! value ) {
		failures.push( `Could not read ${ label }.` );
	}

	return value;
};
const expectEqual = ( label, actual, expected ) => {
	if ( actual !== expected ) {
		failures.push(
			`${ label } must be ${ expected }; found ${ actual ?? 'nothing' }.`
		);
	}
};

const pluginVersion = match(
	pluginSource,
	/^ \* Version:\s*([^\s]+)/m,
	'the plugin header version'
);
const constantVersion = match(
	pluginSource,
	/EDD_COMPOSER_VERSION',\s*'([^']+)'/,
	'EDD_COMPOSER_VERSION'
);
const stableTag = match(
	readme,
	/^Stable tag:\s*([^\s]+)/m,
	'the readme stable tag'
);

expectEqual( 'package.json version', packageData.version, pluginVersion );
expectEqual( 'EDD_COMPOSER_VERSION', constantVersion, pluginVersion );
expectEqual( 'readme stable tag', stableTag, pluginVersion );

const minimums = {
	wordpress: match(
		dependenciesSource,
		/MINIMUM_WORDPRESS_VERSION = '([^']+)'/,
		'the WordPress minimum'
	),
	php: match(
		dependenciesSource,
		/MINIMUM_PHP_VERSION = '([^']+)'/,
		'the PHP minimum'
	),
	edd: match(
		dependenciesSource,
		/MINIMUM_EDD_VERSION = '([^']+)'/,
		'the EDD minimum'
	),
	softwareLicensing: match(
		dependenciesSource,
		/MINIMUM_SOFTWARE_LICENSING_VERSION = '([^']+)'/,
		'the Software Licensing minimum'
	),
};

expectEqual(
	'readme WordPress minimum',
	match(
		readme,
		/^Requires at least:\s*([^\s]+)/m,
		'the readme WordPress minimum'
	),
	minimums.wordpress
);
expectEqual(
	'readme PHP minimum',
	match( readme, /^Requires PHP:\s*([^\s]+)/m, 'the readme PHP minimum' ),
	minimums.php
);
expectEqual(
	'minimum matrix WordPress',
	matrix.minimum.wordpressVersion,
	minimums.wordpress
);
expectEqual( 'minimum matrix PHP', matrix.minimum.phpVersion, minimums.php );
expectEqual( 'minimum matrix EDD', matrix.minimum.eddVersion, minimums.edd );
expectEqual(
	'minimum matrix Software Licensing',
	matrix.minimum.softwareLicensingVersion,
	minimums.softwareLicensing
);
expectEqual(
	'baseline WordPress expectation',
	testConfig.config.EDD_COMPOSER_TEST_EXPECTED_WORDPRESS_VERSION,
	matrix.minimum.wordpressVersion
);
expectEqual(
	'baseline PHP version',
	testConfig.phpVersion,
	matrix.minimum.phpVersion
);
expectEqual( 'baseline port', testConfig.port, matrix.minimum.port );
expectEqual(
	'baseline PHPUnit version',
	phpunitManifest[ 'require-dev' ][ 'phpunit/phpunit' ],
	matrix.minimum.phpunitVersion
);
expectEqual(
	'baseline WordPress PHPUnit version',
	phpunitManifest[ 'require-dev' ][ 'wp-phpunit/wp-phpunit' ],
	matrix.minimum.wordpressPhpunitVersion
);
expectEqual(
	'readme Tested up to',
	match(
		readme,
		/^Tested up to:\s*([^\s]+)/m,
		'the readme Tested up to value'
	),
	matrix.latest.wordpressVersion
);

if ( failures.length > 0 ) {
	throw new Error( failures.join( '\n' ) );
}

process.stdout.write(
	'Plugin, dependency, test-matrix, and readme versions are consistent.\n'
);
