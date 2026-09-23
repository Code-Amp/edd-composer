<?php
/**
 * Tests for the EDD product catalogue.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Products;
use EDD_Composer\Repository\Versioned_Files;

/**
 * Verifies product discovery and enrichment.
 *
 * @since 0.1.0
 */
final class ProductsTest extends WP_UnitTestCase {
	/**
	 * Confirms catalogue defaults come from the EDD Download.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_catalogue_uses_download_defaults_and_file_versions() {
		$download_id = self::factory()->post->create(
			array(
				'post_type'    => 'download',
				'post_status'  => 'publish',
				'post_title'   => 'Example Package',
				'post_name'    => 'example-package',
				'post_excerpt' => 'Example package description.',
			)
		);
		update_post_meta(
			$download_id,
			'edd_download_files',
			array(
				array(
					'version' => '1.0.0',
					'file'    => 'https://example.org/package.zip',
				),
			)
		);
		update_post_meta( $download_id, '_edd_sl_enabled', 1 );

		$catalogue = ( new Products( new Versioned_Files() ) )->get_catalogue(
			array(
				'vendor'   => 'code-amp',
				'products' => array(),
			)
		);
		$product   = $this->find_product( $catalogue, $download_id );

		$this->assertSame( 'Example Package', $product['title'] );
		$this->assertSame( 'example-package', $product['package_slug'] );
		$this->assertSame( 'code-amp/example-package', $product['package_name'] );
		$this->assertSame( 'Example package description.', $product['description'] );
		$this->assertSame( array( '1.0.0' ), $product['versions'] );
		$this->assertTrue( $product['can_enable'] );
	}

	/**
	 * Confirms saved metadata overrides defaults and drafts cannot be enabled.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_catalogue_applies_saved_configuration_and_status_validation() {
		$download_id = self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_status' => 'draft',
				'post_title'  => 'Draft Package',
			)
		);
		update_post_meta(
			$download_id,
			'edd_download_files',
			array(
				array(
					'version' => '1.0.0',
					'file'    => 'https://example.org/package.zip',
				),
			)
		);
		update_post_meta( $download_id, '_edd_sl_enabled', 1 );
		$settings = array(
			'vendor'   => 'vendor',
			'products' => array(
				(string) $download_id => array(
					'enabled'      => true,
					'package_slug' => 'custom-slug',
					'type'         => 'wordpress-plugin',
					'description'  => 'Custom description.',
					'require_php'  => '^8.1',
				),
			),
		);

		$product = $this->find_product(
			( new Products( new Versioned_Files() ) )->get_catalogue( $settings ),
			$download_id
		);

		$this->assertTrue( $product['enabled'] );
		$this->assertSame( 'custom-slug', $product['package_slug'] );
		$this->assertSame( '^8.1', $product['require_php'] );
		$this->assertFalse( $product['can_enable'] );
		$this->assertStringContainsString( 'published', implode( ' ', $product['file_validation_messages'] ) );
	}

	/**
	 * Confirms products without Software Licensing cannot be published.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_catalogue_requires_software_licensing_for_product() {
		$download_id = self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_status' => 'publish',
				'post_title'  => 'Unlicensed Package',
			)
		);
		update_post_meta(
			$download_id,
			'edd_download_files',
			array(
				array(
					'version' => '1.0.0',
					'file'    => 'https://example.org/package.zip',
				),
			)
		);

		$product = $this->find_product(
			( new Products( new Versioned_Files() ) )->get_catalogue(
				array(
					'vendor'   => 'vendor',
					'products' => array(),
				)
			),
			$download_id
		);

		$this->assertFalse( $product['can_enable'] );
		$this->assertStringContainsString( 'Software Licensing', implode( ' ', $product['file_validation_messages'] ) );
	}

	/**
	 * Finds a product response by Download ID.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $catalogue  Product catalogue.
	 * @param int                              $product_id Download ID.
	 * @return array<string, mixed>
	 */
	private function find_product( array $catalogue, $product_id ) {
		foreach ( $catalogue as $product ) {
			if ( $product_id === $product['id'] ) {
				return $product;
			}
		}

		$this->fail( 'The expected EDD Download was not found in the catalogue.' );
	}
}
