<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package EDD_Composer
 */

$edd_composer_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $edd_composer_tests_dir || '' === $edd_composer_tests_dir ) {
	$edd_composer_tests_dir = '/wordpress-phpunit';
}

$edd_composer_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );

if ( false !== $edd_composer_phpunit_polyfills_path ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Required by the WordPress test library.
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $edd_composer_phpunit_polyfills_path );
}

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

if ( ! file_exists( $edd_composer_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress is not loaded and this is CLI-only test output.
	echo "Could not find the WordPress test library at {$edd_composer_tests_dir}.\n";
	exit( 1 );
}

require_once $edd_composer_tests_dir . '/includes/functions.php';

/**
 * Load the plugins required by the test suite.
 *
 * @since 1.0.0
 * @return void
 */
function edd_composer_tests_load_plugins() {
	$plugins_directory = dirname( __DIR__ ) . '/wp-content/plugins/';

	require $plugins_directory . 'easy-digital-downloads-pro/easy-digital-downloads.php';
	require $plugins_directory . 'edd-software-licensing/edd-software-licenses.php';

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

require $edd_composer_tests_dir . '/includes/bootstrap.php';
