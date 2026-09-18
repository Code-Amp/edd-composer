<?php
/**
 * Runtime constants required while PHPStan analyses the plugin in isolation.
 *
 * @package EDD_Composer
 */

if ( ! defined( 'EDD_COMPOSER_VERSION' ) ) {
	define( 'EDD_COMPOSER_VERSION', '1.0.0' );
}

if ( ! defined( 'EDD_COMPOSER_FILE' ) ) {
	define( 'EDD_COMPOSER_FILE', __DIR__ . '/edd-composer/edd-composer.php' );
}

if ( ! defined( 'EDD_COMPOSER_PATH' ) ) {
	define( 'EDD_COMPOSER_PATH', __DIR__ . '/edd-composer/' );
}

if ( ! defined( 'EDD_COMPOSER_URL' ) ) {
	define( 'EDD_COMPOSER_URL', 'http://localhost/wp-content/plugins/edd-composer/' );
}
