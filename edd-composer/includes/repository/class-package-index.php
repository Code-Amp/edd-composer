<?php
/**
 * Composer package-index generation and caching.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer\Repository;

use EDD_Composer\Products;
use EDD_Composer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the public Composer packages document from enabled EDD Downloads.
 *
 * @since 0.1.0
 */
final class Package_Index {
	/**
	 * Package-index transient name.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const TRANSIENT_NAME = Settings::PACKAGE_INDEX_TRANSIENT;

	/**
	 * Option used to invalidate cached metadata after plugin upgrades.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const CACHE_VERSION_OPTION = 'edd_composer_cache_version';

	/**
	 * Default public index cache lifetime.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const DEFAULT_CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Settings service.
	 *
	 * @since 0.1.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Product catalogue service.
	 *
	 * @since 0.1.0
	 * @var Products
	 */
	private $products;

	/**
	 * Creates the package-index service.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings $settings Settings service.
	 * @param Products $products Product catalogue service.
	 */
	public function __construct( Settings $settings, Products $products ) {
		$this->settings = $settings;
		$this->products = $products;
	}

	/**
	 * Registers cache invalidation hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'add_option_' . Settings::OPTION_NAME, array( $this, 'invalidate' ), 10, 0 );
		add_action( 'update_option_' . Settings::OPTION_NAME, array( $this, 'invalidate' ), 10, 0 );
		add_action( 'save_post_download', array( $this, 'invalidate_for_download' ), 100, 3 );
		add_action( 'added_post_meta', array( $this, 'invalidate_for_product_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'invalidate_for_product_meta' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'invalidate_for_product_meta' ), 10, 4 );
		add_action( 'trashed_post', array( $this, 'invalidate_for_download_lifecycle' ) );
		add_action( 'untrashed_post', array( $this, 'invalidate_for_download_lifecycle' ) );
		add_action( 'before_delete_post', array( $this, 'invalidate_for_download_lifecycle' ) );
		add_action( 'init', array( $this, 'maybe_invalidate_for_plugin_version' ), 100 );
	}

	/**
	 * Returns the package map, using the shared transient when available.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function get_packages() {
		$packages = get_transient( self::TRANSIENT_NAME );

		if ( false !== $packages && is_array( $packages ) ) {
			return $packages;
		}

		$packages = $this->build_packages();
		set_transient( self::TRANSIENT_NAME, $packages, $this->get_cache_ttl() );

		return $packages;
	}

	/**
	 * Returns a JSON-ready Composer repository document.
	 *
	 * An empty package map is represented as an object so Composer receives the
	 * required `{ "packages": {} }` shape instead of a JSON list.
	 *
	 * @since 0.1.0
	 *
	 * @return array{packages: array<string, mixed>|\stdClass}
	 */
	public function get_payload() {
		$packages = $this->get_packages();

		return array(
			'packages' => empty( $packages ) ? new \stdClass() : $packages,
		);
	}

	/**
	 * Gets the cache lifetime shared by the transient and HTTP response.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function get_cache_ttl() {
		/**
		 * Filters the package-index cache lifetime in seconds.
		 *
		 * @since 0.1.0
		 *
		 * @param int $ttl Cache lifetime in seconds.
		 */
		$ttl = apply_filters( 'edd_composer_package_index_cache_ttl', self::DEFAULT_CACHE_TTL );

		return max( 1, absint( $ttl ) );
	}

	/**
	 * Deletes cached package metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function invalidate() {
		delete_transient( self::TRANSIENT_NAME );
	}

	/**
	 * Invalidates metadata after an enabled Download is saved.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $post_id Download ID.
	 * @param \WP_Post $post    Download post object.
	 * @param bool     $update  Whether this is an existing post update.
	 * @return void
	 */
	public function invalidate_for_download( $post_id, $post, $update ) {
		unset( $post, $update );

		if ( $this->is_enabled_download( $post_id ) ) {
			$this->invalidate();
		}
	}

	/**
	 * Invalidates metadata when enabled Download files or licensing state change.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $meta_id    Metadata row ID.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Metadata value.
	 * @return void
	 */
	public function invalidate_for_product_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id, $meta_value );

		if (
			in_array( $meta_key, array( 'edd_download_files', '_edd_sl_enabled', '_variable_pricing', 'edd_variable_prices', '_edd_price_options_mode' ), true )
			&& $this->is_enabled_download( $object_id )
		) {
			$this->invalidate();
		}
	}

	/**
	 * Invalidates metadata when an enabled Download is trashed or deleted.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function invalidate_for_download_lifecycle( $post_id ) {
		if ( 'download' === get_post_type( $post_id ) && $this->is_enabled_download( $post_id ) ) {
			$this->invalidate();
		}
	}

	/**
	 * Invalidates metadata once when the installed plugin version changes.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function maybe_invalidate_for_plugin_version() {
		if ( EDD_COMPOSER_VERSION === get_option( self::CACHE_VERSION_OPTION ) ) {
			return;
		}

		$this->invalidate();
		update_option( self::CACHE_VERSION_OPTION, EDD_COMPOSER_VERSION, false );
	}

	/**
	 * Builds the uncached package map.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function build_packages() {
		$settings = $this->settings->validate( $this->settings->get() );

		if ( is_wp_error( $settings ) ) {
			return array();
		}

		$packages = array();

		foreach ( $this->products->get_catalogue( $settings ) as $product ) {
			if ( ! $product['enabled'] || ! $product['can_enable'] ) {
				continue;
			}

			$metadata = array(
				'name'         => $product['package_name'],
				'package_slug' => $product['package_slug'],
				'type'         => $product['type'],
				'description'  => $product['description'],
				'require_php'  => $product['require_php'],
				'download_id'  => $product['id'],
				'download_url' => Router::get_download_base_url( $product['package_slug'] ),
			);

			/**
			 * Filters Composer metadata derived for an enabled EDD Download.
			 *
			 * The result is cached. Call `delete_transient( 'edd_composer_package_index' )`
			 * when external data used by this filter changes.
			 *
			 * @since 0.1.0
			 *
			 * @param array<string, mixed> $metadata Composer product metadata.
			 * @param int                  $download_id EDD Download ID.
			 * @param array<string, mixed> $product Enriched product data.
			 */
			$metadata = apply_filters( 'edd_composer_product_metadata', $metadata, $product['id'], $product );

			if ( ! is_array( $metadata ) || empty( $metadata['name'] ) || empty( $metadata['package_slug'] ) ) {
				continue;
			}

			$versions = array();

			foreach ( $product['versions'] as $version ) {
				$entry = $this->build_version_entry( $metadata, $version, $product );

				if ( is_array( $entry ) ) {
					$versions[ $version ] = $entry;
				}
			}

			if ( ! empty( $versions ) ) {
				$packages[ $metadata['name'] ] = $versions;
			}
		}

		ksort( $packages, SORT_STRING );

		return $packages;
	}

	/**
	 * Builds one Composer package-version entry.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $metadata Product metadata.
	 * @param string               $version  Canonical package version.
	 * @param array<string, mixed> $product  Enriched product data.
	 * @return array<string, mixed>|null
	 */
	private function build_version_entry( array $metadata, $version, array $product ) {
		$entry = array(
			'name'    => (string) $metadata['name'],
			'version' => $version,
			'type'    => isset( $metadata['type'] ) ? (string) $metadata['type'] : 'wordpress-plugin',
			'dist'    => array(
				'url'  => trailingslashit( (string) $metadata['download_url'] ) . rawurlencode( $version ),
				'type' => 'zip',
			),
			'require' => array(
				'php'                 => isset( $metadata['require_php'] ) ? (string) $metadata['require_php'] : '>=7.4',
				'composer/installers' => '^1.0 || ^2.0',
			),
			'extra'   => array(
				'installer-name' => (string) $metadata['package_slug'],
			),
		);

		if ( ! empty( $metadata['description'] ) ) {
			$entry['description'] = (string) $metadata['description'];
		}

		/**
		 * Filters one generated Composer package-version entry.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $entry Composer version entry.
		 * @param int                  $download_id EDD Download ID.
		 * @param string               $version Canonical package version.
		 * @param array<string, mixed> $product Enriched product data.
		 */
		$entry = $this->filter_version_entry( $entry, $product, $version );

		return is_array( $entry ) ? $entry : null;
	}

	/**
	 * Applies the public package-version filter without assuming its return type.
	 *
	 * The documented filter contract requires an array, but a defensive boundary
	 * keeps invalid third-party values out of the public repository response.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $entry   Composer version entry.
	 * @param array<string, mixed> $product Enriched product data.
	 * @param string               $version Canonical package version.
	 * @return mixed Filtered value.
	 */
	private function filter_version_entry( array $entry, array $product, $version ) {
		return apply_filters( 'edd_composer_package_index_entry', $entry, $product['id'], $version, $product );
	}

	/**
	 * Checks whether a Download is currently enabled in plugin settings.
	 *
	 * @since 0.1.0
	 *
	 * @param int $download_id EDD Download ID.
	 * @return bool
	 */
	private function is_enabled_download( $download_id ) {
		$settings = $this->settings->get();

		return ! empty( $settings['products'][ (string) absint( $download_id ) ]['enabled'] );
	}
}
