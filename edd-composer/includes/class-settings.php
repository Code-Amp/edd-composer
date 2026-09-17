<?php
/**
 * Plugin settings storage and validation.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the versioned EDD Composer settings option.
 *
 * @since 1.0.0
 */
final class Settings {
	/**
	 * Settings option name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const OPTION_NAME = 'edd_composer_settings';

	/**
	 * Public package-index transient name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const PACKAGE_INDEX_TRANSIENT = 'edd_composer_package_index';

	/**
	 * Current settings schema version.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Composer vendor segment pattern.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const VENDOR_PATTERN = '/^[a-z0-9]([_.-]?[a-z0-9]+)*$/';

	/**
	 * Composer package segment pattern.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const PACKAGE_PATTERN = '/^[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/';

	/**
	 * Maximum public repository-name length.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const REPOSITORY_NAME_MAX_LENGTH = 100;

	/**
	 * Registers settings with WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Registers the plugin option and its REST-independent schema.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			'edd_composer',
			self::OPTION_NAME,
			array(
				'type'              => 'object',
				'default'           => $this->get_defaults(),
				'sanitize_callback' => array( $this, 'sanitize_option' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Gets normalized saved settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function get() {
		$value = get_option( self::OPTION_NAME, $this->get_defaults() );

		return is_array( $value ) ? array_merge( $this->get_defaults(), $value ) : $this->get_defaults();
	}

	/**
	 * Gets default settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array{schema_version: int, repository_name: string, vendor: string, products: array<string, array<string, mixed>>}
	 */
	public function get_defaults() {
		return array(
			'schema_version'  => self::SCHEMA_VERSION,
			'repository_name' => __( 'EDD Composer Repository', 'edd-composer' ),
			'vendor'          => 'vendor',
			'products'        => array(),
		);
	}

	/**
	 * Saves settings that have already passed validation.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings Validated settings.
	 * @return bool
	 */
	public function save( array $settings ) {
		return update_option( self::OPTION_NAME, $settings, false );
	}

	/**
	 * Sanitizes direct option writes without allowing invalid data to replace settings.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Candidate option value.
	 * @return array<string, mixed>
	 */
	public function sanitize_option( $value ) {
		$validated = $this->validate( $value );

		if ( is_wp_error( $validated ) ) {
			add_settings_error(
				self::OPTION_NAME,
				$validated->get_error_code(),
				$validated->get_error_message()
			);

			return $this->get();
		}

		return $validated;
	}

	/**
	 * Validates and sanitizes a complete settings payload.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Candidate settings payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function validate( $value ) {
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'edd_composer_invalid_settings', __( 'Settings must be an object.', 'edd-composer' ) );
		}

		$repository_name = isset( $value['repository_name'] ) && is_string( $value['repository_name'] )
			? trim( sanitize_text_field( $value['repository_name'] ) )
			: '';

		if ( '' === $repository_name || strlen( $repository_name ) > self::REPOSITORY_NAME_MAX_LENGTH ) {
			return new \WP_Error( 'edd_composer_invalid_repository_name', __( 'Enter a repository title containing no more than 100 characters.', 'edd-composer' ) );
		}

		$vendor = isset( $value['vendor'] ) && is_string( $value['vendor'] )
			? trim( $value['vendor'] )
			: '';

		if ( 1 !== preg_match( self::VENDOR_PATTERN, $vendor ) ) {
			return new \WP_Error( 'edd_composer_invalid_vendor', __( 'Enter a lowercase Composer vendor containing only letters, numbers, dots, underscores, or hyphens.', 'edd-composer' ) );
		}

		$products = isset( $value['products'] ) ? $value['products'] : array();

		if ( ! is_array( $products ) ) {
			return new \WP_Error( 'edd_composer_invalid_products', __( 'Product settings must be an object.', 'edd-composer' ) );
		}

		$sanitized = array();
		$enabled   = array();

		foreach ( $products as $product_id => $config ) {
			$product_id = absint( $product_id );

			if ( ! $product_id || 'download' !== get_post_type( $product_id ) ) {
				return new \WP_Error( 'edd_composer_invalid_product', __( 'One or more configured products are not EDD Downloads.', 'edd-composer' ) );
			}

			if ( ! is_array( $config ) ) {
				return new \WP_Error( 'edd_composer_invalid_product_settings', __( 'Each product configuration must be an object.', 'edd-composer' ) );
			}

			$package_slug = isset( $config['package_slug'] ) && is_string( $config['package_slug'] )
				? trim( $config['package_slug'] )
				: '';

			if ( ! $this->is_valid_package_slug( $package_slug ) ) {
				return new \WP_Error( 'edd_composer_invalid_package_slug', __( 'Package slugs must be lowercase Composer package segments.', 'edd-composer' ) );
			}

			$type = isset( $config['type'] ) && is_string( $config['type'] )
				? trim( $config['type'] )
				: 'wordpress-plugin';

			if ( 'wordpress-plugin' !== $type ) {
				return new \WP_Error( 'edd_composer_invalid_package_type', __( 'Only the wordpress-plugin package type is supported in version 1.', 'edd-composer' ) );
			}

			$require_php = isset( $config['require_php'] ) && is_string( $config['require_php'] )
				? trim( sanitize_text_field( $config['require_php'] ) )
				: '>=7.4';

			if ( ! $this->is_valid_php_constraint( $require_php ) ) {
				return new \WP_Error( 'edd_composer_invalid_php_constraint', __( 'Enter a valid Composer-style PHP version constraint.', 'edd-composer' ) );
			}

			$is_enabled = isset( $config['enabled'] ) ? rest_sanitize_boolean( $config['enabled'] ) : false;

			if ( $is_enabled && isset( $enabled[ $package_slug ] ) ) {
				return new \WP_Error( 'edd_composer_duplicate_package_slug', __( 'Enabled products must use unique package slugs.', 'edd-composer' ) );
			}

			if ( $is_enabled ) {
				$enabled[ $package_slug ] = true;
			}

			$sanitized[ (string) $product_id ] = array(
				'enabled'      => $is_enabled,
				'package_slug' => $package_slug,
				'type'         => $type,
				'description'  => isset( $config['description'] ) && is_string( $config['description'] )
					? sanitize_textarea_field( $config['description'] )
					: '',
				'require_php'  => $require_php,
			);
		}

		return array(
			'schema_version'  => self::SCHEMA_VERSION,
			'repository_name' => $repository_name,
			'vendor'          => $vendor,
			'products'        => $sanitized,
		);
	}

	/**
	 * Validates one Composer package-name segment without rewriting it.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $package_slug Candidate package slug.
	 * @return bool
	 */
	public function is_valid_package_slug( $package_slug ) {
		return is_string( $package_slug ) && 1 === preg_match( self::PACKAGE_PATTERN, $package_slug );
	}

	/**
	 * Performs conservative validation without adding a Composer runtime dependency.
	 *
	 * @since 1.0.0
	 *
	 * @param string $constraint PHP version constraint.
	 * @return bool
	 */
	private function is_valid_php_constraint( $constraint ) {
		return '' !== $constraint
			&& strlen( $constraint ) <= 100
			&& 1 === preg_match( '/^[0-9A-Za-z.*<>=!~^|, +\-]+$/', $constraint )
			&& 1 === preg_match( '/[0-9]/', $constraint );
	}
}
