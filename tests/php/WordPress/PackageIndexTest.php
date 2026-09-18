<?php
/**
 * Tests for Composer package-index generation.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Products;
use EDD_Composer\Repository\Package_Index;
use EDD_Composer\Repository\Versioned_Files;
use EDD_Composer\Settings;

/**
 * Verifies package metadata, filtering, and cache invalidation.
 *
 * @since 1.0.0
 */
final class PackageIndexTest extends WP_UnitTestCase {
	/**
	 * Restores options, transients, and filters after each test.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_NAME );
		delete_option( Package_Index::CACHE_VERSION_OPTION );
		delete_transient( Package_Index::TRANSIENT_NAME );
		remove_all_filters( 'edd_composer_package_index_cache_ttl' );
		remove_all_filters( 'edd_composer_product_metadata' );
		remove_all_filters( 'edd_composer_package_index_entry' );
		parent::tear_down();
	}

	/**
	 * Confirms enabled valid Downloads produce the proven Composer shape.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_builds_enabled_package_versions_newest_first() {
		$download_id = $this->create_download(
			'Example Package',
			array(
				array(
					'version' => '1.0.0',
					'file'    => 'https://example.org/one.zip',
				),
				array(
					'version' => 'v2.0.0',
					'file'    => 'https://example.org/two.zip',
				),
			)
		);
		$this->save_enabled_settings( $download_id, 'example-package' );

		$packages = $this->index()->get_packages();
		$versions = $packages['code-amp/example-package'];
		$latest   = $versions['2.0.0'];

		$this->assertSame( array( '2.0.0', '1.0.0' ), array_keys( $versions ) );
		$this->assertSame( 'wordpress-plugin', $latest['type'] );
		$this->assertSame( 'zip', $latest['dist']['type'] );
		$this->assertSame(
			home_url( '/composer/download/example-package/2.0.0' ),
			$latest['dist']['url']
		);
		$this->assertSame( '>=8.0', $latest['require']['php'] );
		$this->assertSame( '^1.0 || ^2.0', $latest['require']['composer/installers'] );
		$this->assertSame( 'example-package', $latest['extra']['installer-name'] );
		$this->assertArrayNotHasKey( 'file', $latest['dist'] );
	}

	/**
	 * Confirms disabled and invalid Downloads are omitted from a valid document.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_empty_repository_uses_a_json_object() {
		$invalid_download_id  = $this->create_download(
			'Invalid Package',
			array(
				array(
					'version' => 'not-semver',
					'file'    => 'https://example.org/package.zip',
				),
			)
		);
		$disabled_download_id = $this->create_download(
			'Disabled Package',
			array(
				array(
					'version' => '1.0.0',
					'file'    => 'https://example.org/disabled.zip',
				),
			)
		);
		$this->save_product_settings(
			array(
				(string) $invalid_download_id  => array(
					'enabled'      => true,
					'package_slug' => 'invalid-package',
				),
				(string) $disabled_download_id => array(
					'enabled'      => false,
					'package_slug' => 'disabled-package',
				),
			)
		);

		$payload = $this->index()->get_payload();

		$this->assertInstanceOf( stdClass::class, $payload['packages'] );
		$this->assertSame( '{"packages":{}}', wp_json_encode( $payload ) );
	}

	/**
	 * Confirms extension filters can adapt generic generated metadata.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_applies_product_and_version_entry_filters() {
		$download_id = $this->create_download(
			'Filterable Package',
			array(
				array(
					'version' => '1.0.0',
					'file'    => 'https://example.org/package.zip',
				),
			)
		);
		$this->save_enabled_settings( $download_id, 'filterable-package' );
		add_filter(
			'edd_composer_product_metadata',
			static function ( $metadata ) {
				$metadata['description'] = 'Filtered description.';
				return $metadata;
			}
		);
		add_filter(
			'edd_composer_package_index_entry',
			static function ( $entry ) {
				$entry['extra']['filtered'] = true;
				return $entry;
			}
		);

		$entry = $this->index()->get_packages()['code-amp/filterable-package']['1.0.0'];

		$this->assertSame( 'Filtered description.', $entry['description'] );
		$this->assertTrue( $entry['extra']['filtered'] );
	}

	/**
	 * Confirms settings and file mutations invalidate generated metadata.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_relevant_mutations_invalidate_the_transient() {
		$download_id = $this->create_download(
			'Cached Package',
			array(
				array(
					'version' => '1.0.0',
					'file'    => 'https://example.org/package.zip',
				),
			)
		);
		$this->save_enabled_settings( $download_id, 'cached-package' );

		set_transient( Package_Index::TRANSIENT_NAME, array( 'cached' ), HOUR_IN_SECONDS );
		update_post_meta(
			$download_id,
			'edd_download_files',
			array(
				array(
					'version' => '2.0.0',
					'file'    => 'https://example.org/package.zip',
				),
			)
		);
		$this->assertFalse( get_transient( Package_Index::TRANSIENT_NAME ) );

		set_transient( Package_Index::TRANSIENT_NAME, array( 'cached' ), HOUR_IN_SECONDS );
		update_post_meta( $download_id, '_edd_sl_enabled', 0 );
		$this->assertFalse( get_transient( Package_Index::TRANSIENT_NAME ) );

		set_transient( Package_Index::TRANSIENT_NAME, array( 'cached' ), HOUR_IN_SECONDS );
		update_post_meta( $download_id, '_variable_pricing', 1 );
		$this->assertFalse( get_transient( Package_Index::TRANSIENT_NAME ) );

		set_transient( Package_Index::TRANSIENT_NAME, array( 'cached' ), HOUR_IN_SECONDS );
		wp_trash_post( $download_id );
		$this->assertFalse( get_transient( Package_Index::TRANSIENT_NAME ) );

		wp_untrash_post( $download_id );

		set_transient( Package_Index::TRANSIENT_NAME, array( 'cached' ), HOUR_IN_SECONDS );
		$settings           = get_option( Settings::OPTION_NAME );
		$settings['vendor'] = 'new-vendor';
		update_option( Settings::OPTION_NAME, $settings );
		$this->assertFalse( get_transient( Package_Index::TRANSIENT_NAME ) );
	}

	/**
	 * Confirms plugin upgrades invalidate once and record their version.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_plugin_version_change_invalidates_cache_once() {
		$index = $this->index();
		set_transient( Package_Index::TRANSIENT_NAME, array( 'cached' ), HOUR_IN_SECONDS );
		update_option( Package_Index::CACHE_VERSION_OPTION, '0.9.0' );

		$index->maybe_invalidate_for_plugin_version();

		$this->assertFalse( get_transient( Package_Index::TRANSIENT_NAME ) );
		$this->assertSame( EDD_COMPOSER_VERSION, get_option( Package_Index::CACHE_VERSION_OPTION ) );
	}

	/**
	 * Creates the package-index service.
	 *
	 * @since 1.0.0
	 * @return Package_Index
	 */
	private function index() {
		return new Package_Index( new Settings(), new Products( new Versioned_Files() ) );
	}

	/**
	 * Creates a published EDD Download with file metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param string                           $title Download title.
	 * @param array<int, array<string, mixed>> $files Download files.
	 * @return int
	 */
	private function create_download( $title, array $files ) {
		$download_id = self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_name'   => sanitize_title( $title ),
			)
		);
		update_post_meta( $download_id, 'edd_download_files', $files );
		update_post_meta( $download_id, '_edd_sl_enabled', 1 );

		return $download_id;
	}

	/**
	 * Persists one enabled package configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $download_id Download ID.
	 * @param string $slug        Package slug.
	 * @return void
	 */
	private function save_enabled_settings( $download_id, $slug ) {
		$this->save_product_settings(
			array(
				(string) $download_id => array(
					'enabled'      => true,
					'package_slug' => $slug,
				),
			)
		);
	}

	/**
	 * Persists one or more product configurations.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array<string, mixed>> $products Product settings keyed by Download ID.
	 * @return void
	 */
	private function save_product_settings( array $products ) {
		$products = array_map(
			static function ( $product ) {
				return array_merge(
					array(
						'enabled'      => false,
						'package_slug' => '',
						'type'         => 'wordpress-plugin',
						'description'  => 'Example description.',
						'require_php'  => '>=8.0',
					),
					$product
				);
			},
			$products
		);

		update_option(
			Settings::OPTION_NAME,
			array(
				'schema_version'  => 1,
				'repository_name' => 'Code Amp Packages',
				'vendor'          => 'code-amp',
				'products'        => $products,
			)
		);
	}
}
