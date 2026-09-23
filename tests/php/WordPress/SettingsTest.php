<?php
/**
 * Tests for plugin settings validation.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Settings;

/**
 * Verifies the versioned settings document.
 *
 * @since 0.1.0
 */
final class SettingsTest extends WP_UnitTestCase {
	/**
	 * Removes saved settings after every test.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * Confirms defaults are stable and versioned.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_returns_versioned_defaults() {
		$this->assertSame(
			array(
				'schema_version'  => 1,
				'repository_name' => 'EDD Composer Repository',
				'vendor'          => 'vendor',
				'products'        => array(),
			),
			( new Settings() )->get()
		);
	}

	/**
	 * Confirms valid settings are sanitized without rewriting package identity.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_validates_and_sanitizes_settings() {
		$download_id = $this->create_download();
		$validated   = ( new Settings() )->validate(
			array(
				'schema_version'  => 99,
				'repository_name' => '  Code Amp <em>Packages</em>  ',
				'vendor'          => 'code-amp',
				'products'        => array(
					(string) $download_id => array(
						'enabled'      => 'true',
						'package_slug' => 'sample-plugin',
						'type'         => 'wordpress-plugin',
						'description'  => "Useful <script>alert('x')</script> plugin",
						'require_php'  => '^8.1 || ^8.2',
					),
				),
			)
		);

		$this->assertNotWPError( $validated );
		$this->assertSame( 1, $validated['schema_version'] );
		$this->assertSame( 'Code Amp Packages', $validated['repository_name'] );
		$this->assertSame( 'code-amp', $validated['vendor'] );
		$this->assertTrue( $validated['products'][ (string) $download_id ]['enabled'] );
		$this->assertSame( 'sample-plugin', $validated['products'][ (string) $download_id ]['package_slug'] );
		$this->assertStringNotContainsString( '<script>', $validated['products'][ (string) $download_id ]['description'] );
	}

	/**
	 * Confirms repository titles must be present and reasonably sized.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider provide_invalid_repository_names
	 *
	 * @param mixed $repository_name Repository title.
	 * @return void
	 */
	public function test_rejects_invalid_repository_names( $repository_name ) {
		$value                    = $this->settings_payload( $this->create_download(), 'code-amp', 'package' );
		$value['repository_name'] = $repository_name;

		$validated = ( new Settings() )->validate( $value );

		$this->assertWPError( $validated );
		$this->assertSame( 'edd_composer_invalid_repository_name', $validated->get_error_code() );
	}

	/**
	 * Confirms repository title limits count Unicode characters, not UTF-8 bytes.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_repository_name_length_counts_unicode_characters() {
		$value                    = $this->settings_payload( $this->create_download(), 'code-amp', 'package' );
		$value['repository_name'] = str_repeat( '界', 100 );

		$this->assertNotWPError( ( new Settings() )->validate( $value ) );

		$value['repository_name'] .= '界';
		$this->assertSame(
			'edd_composer_invalid_repository_name',
			( new Settings() )->validate( $value )->get_error_code()
		);
	}

	/**
	 * Confirms unchanged valid settings count as a successful persisted save.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_save_succeeds_when_settings_are_unchanged() {
		$settings_service = new Settings();
		$value            = $this->settings_payload( $this->create_download(), 'code-amp', 'package' );
		$validated        = $settings_service->validate( $value );

		$this->assertTrue( $settings_service->save( $validated ) );
		$this->assertTrue( $settings_service->save( $validated ) );
	}

	/**
	 * Confirms permanently deleted Downloads cannot poison future settings reads.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_permanent_download_deletion_removes_product_settings() {
		$download_id = $this->create_download();
		$settings    = $this->settings_payload( $download_id, 'code-amp', 'package' );
		update_option( Settings::OPTION_NAME, $settings );

		wp_delete_post( $download_id, true );

		$this->assertSame( array(), ( new Settings() )->get()['products'] );
	}

	/**
	 * Supplies invalid repository titles.
	 *
	 * @since 0.1.0
	 * @return array<string, array{mixed}>
	 */
	public function provide_invalid_repository_names() {
		return array(
			'missing'  => array( null ),
			'blank'    => array( '   ' ),
			'too long' => array( str_repeat( 'a', 101 ) ),
		);
	}

	/**
	 * Confirms invalid identity values are rejected instead of rewritten.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider provide_invalid_identity_settings
	 *
	 * @param string $vendor       Composer vendor.
	 * @param string $package_slug Composer package slug.
	 * @param string $error_code   Expected error code.
	 * @return void
	 */
	public function test_rejects_invalid_identity_values( $vendor, $package_slug, $error_code ) {
		$download_id = $this->create_download();
		$validated   = ( new Settings() )->validate(
			$this->settings_payload( $download_id, $vendor, $package_slug )
		);

		$this->assertWPError( $validated );
		$this->assertSame( $error_code, $validated->get_error_code() );
	}

	/**
	 * Supplies invalid vendor and package identity values.
	 *
	 * @since 0.1.0
	 * @return array<string, array<string, string>>
	 */
	public function provide_invalid_identity_settings() {
		return array(
			'uppercase vendor' => array( 'Code-Amp', 'package', 'edd_composer_invalid_vendor' ),
			'vendor spaces'    => array( 'code amp', 'package', 'edd_composer_invalid_vendor' ),
			'uppercase slug'   => array( 'code-amp', 'Package', 'edd_composer_invalid_package_slug' ),
			'slug spaces'      => array( 'code-amp', 'my package', 'edd_composer_invalid_package_slug' ),
		);
	}

	/**
	 * Confirms configured IDs must remain EDD Downloads.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_rejects_non_download_product_ids() {
		$post_id   = self::factory()->post->create();
		$validated = ( new Settings() )->validate(
			$this->settings_payload( $post_id, 'code-amp', 'package' )
		);

		$this->assertWPError( $validated );
		$this->assertSame( 'edd_composer_invalid_product', $validated->get_error_code() );
	}

	/**
	 * Confirms enabled packages cannot collide.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_rejects_duplicate_enabled_package_slugs() {
		$first                                 = $this->create_download();
		$second                                = $this->create_download();
		$value                                 = $this->settings_payload( $first, 'code-amp', 'shared' );
		$value['products'][ (string) $second ] = $value['products'][ (string) $first ];

		$validated = ( new Settings() )->validate( $value );

		$this->assertWPError( $validated );
		$this->assertSame( 'edd_composer_duplicate_package_slug', $validated->get_error_code() );
	}

	/**
	 * Creates a published EDD Download.
	 *
	 * @since 0.1.0
	 * @return int
	 */
	private function create_download() {
		return self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Creates a complete settings payload.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $download_id Download ID.
	 * @param string $vendor      Composer vendor.
	 * @param string $slug        Package slug.
	 * @return array<string, mixed>
	 */
	private function settings_payload( $download_id, $vendor, $slug ) {
		return array(
			'schema_version'  => 1,
			'repository_name' => 'Code Amp Packages',
			'vendor'          => $vendor,
			'products'        => array(
				(string) $download_id => array(
					'enabled'      => true,
					'package_slug' => $slug,
					'type'         => 'wordpress-plugin',
					'description'  => 'A package.',
					'require_php'  => '>=7.4',
				),
			),
		);
	}
}
