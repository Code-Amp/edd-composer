<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package EDD_Composer
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $_tests_dir || '' === $_tests_dir ) {
	$_tests_dir = '/wordpress-phpunit';
}

$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );

if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test library at {$_tests_dir}.\n";
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load the plugins required by the test suite.
 *
 * @since 1.0.0
 * @return void
 */
function edd_composer_tests_load_plugins() {
	require dirname( __DIR__ ) . '/wp-content/plugins/easy-digital-downloads/easy-digital-downloads.php';
	add_action( 'plugins_loaded', 'edd_composer_tests_install_edd_tables', 101 );
	require dirname( __DIR__ ) . '/wp-content/plugins/edd-composer/edd-composer.php';
}

/**
 * Install EDD's component tables after EDD has registered them.
 *
 * @since 1.0.0
 * @return void
 */
function edd_composer_tests_install_edd_tables() {
	edd_install_component_database_tables();
}

tests_add_filter( 'muplugins_loaded', 'edd_composer_tests_load_plugins' );

require $_tests_dir . '/includes/bootstrap.php';
