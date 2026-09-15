<?php
/**
 * EDD Composer Extension class autoloader.
 *
 * @package EDD_Composer
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		$namespace = 'EDD_Composer\\';

		if ( 0 !== strpos( $class_name, $namespace ) ) {
			return;
		}

		$relative_name = substr( $class_name, strlen( $namespace ) );
		$parts         = explode( '\\', $relative_name );
		$class         = array_pop( $parts );
		$directories   = array_map(
			static function ( $part ) {
				return strtolower( str_replace( '_', '-', $part ) );
			},
			$parts
		);
		$file_name     = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		$file_path     = EDD_COMPOSER_PATH . 'includes/';

		if ( ! empty( $directories ) ) {
			$file_path .= implode( '/', $directories ) . '/';
		}

		$file_path .= $file_name;

		if ( is_readable( $file_path ) ) {
			require_once $file_path;
		}
	}
);
