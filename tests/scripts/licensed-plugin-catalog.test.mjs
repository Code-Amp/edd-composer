import assert from 'node:assert/strict';
import test from 'node:test';

import {
	buildGitHubContentsUrl,
	findArchiveEntry,
	getCatalogRelease,
	readPluginVersion,
	selectPluginInstallSource,
	validateArchivePaths,
} from '../../scripts/lib/licensed-plugin-catalog.mjs';

const release = {
	file: 'plugins/example-plugin/1.2.3/example-plugin-1.2.3.zip',
	entryFile: 'example-plugin.php',
	sha256: 'a'.repeat( 64 ),
};
const catalog = {
	packages: {
		'example-plugin': {
			type: 'wordpress-plugin',
			versions: { '1.2.3': release },
		},
	},
};

test( 'builds a pinned GitHub Contents API URL', () => {
	assert.equal(
		buildGitHubContentsUrl(
			'Code-Amp/wp-dependencies',
			'a'.repeat( 40 ),
			'plugins/example plugin.zip'
		),
		`https://api.github.com/repos/Code-Amp/wp-dependencies/contents/plugins/example%20plugin.zip?ref=${ 'a'.repeat(
			40
		) }`
	);
	assert.throws(
		() =>
			buildGitHubContentsUrl(
				'Code-Amp/wp-dependencies',
				'main',
				'catalog.json'
			),
		/commit SHA/
	);
} );

test( 'selects plugin installation sources in precedence order', () => {
	assert.equal(
		selectPluginInstallSource( {
			overrideUrl: 'https://example.com/plugin.zip',
			catalogToken: 'catalog-token',
			isGitHubActions: false,
		} ),
		'url'
	);
	assert.equal(
		selectPluginInstallSource( {
			overrideUrl: '',
			catalogToken: 'catalog-token',
			isGitHubActions: false,
		} ),
		'token'
	);
	assert.equal(
		selectPluginInstallSource( {
			overrideUrl: '',
			catalogToken: '',
			isGitHubActions: false,
		} ),
		'github-cli'
	);
	assert.equal(
		selectPluginInstallSource( {
			overrideUrl: '',
			catalogToken: '',
			isGitHubActions: true,
		} ),
		null
	);
} );

test( 'resolves only canonical plugin releases', () => {
	assert.deepEqual(
		getCatalogRelease(
			catalog,
			'example-plugin',
			'1.2.3',
			'example-plugin.php'
		),
		release
	);
	assert.throws(
		() =>
			getCatalogRelease(
				catalog,
				'example-plugin',
				'2.0.0',
				'example-plugin.php'
			),
		/does not contain/
	);
	assert.throws(
		() =>
			getCatalogRelease(
				catalog,
				'example-plugin',
				'1.2.3',
				'wrong.php'
			),
		/metadata.*invalid/
	);
} );

test( 'rejects empty, absolute, drive-letter, and traversal paths', () => {
	assert.doesNotThrow( () =>
		validateArchivePaths( [ 'example-plugin/example-plugin.php' ] )
	);

	for ( const paths of [
		[],
		[ '/absolute.php' ],
		[ 'C:\\absolute.php' ],
		[ 'plugin/../outside.php' ],
		[ 'plugin\\..\\outside.php' ],
	] ) {
		assert.throws( () => validateArchivePaths( paths ) );
	}
} );

test( 'requires exactly one plugin entry file', () => {
	assert.equal(
		findArchiveEntry(
			[
				'example-plugin/example-plugin.php',
				'example-plugin/readme.txt',
			],
			'example-plugin.php'
		),
		'example-plugin/example-plugin.php'
	);
	assert.throws(
		() => findArchiveEntry( [], 'example-plugin.php' ),
		/exactly one.*found 0/
	);
	assert.throws(
		() =>
			findArchiveEntry(
				[ 'one/example-plugin.php', 'two/example-plugin.php' ],
				'example-plugin.php'
			),
		/exactly one.*found 2/
	);
} );

test( 'reads a WordPress plugin version header', () => {
	assert.equal(
		readPluginVersion( '<?php\n/**\n * Version: 1.2.3\n */' ),
		'1.2.3'
	);
	assert.equal( readPluginVersion( '<?php\n// No header.' ), null );
} );
