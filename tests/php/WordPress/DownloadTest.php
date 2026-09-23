<?php
/**
 * Tests for the complete protected package-download preparation flow.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Licensing\Authenticator;
use EDD_Composer\Licensing\Entitlements;
use EDD_Composer\Licensing\Order_Resolver;
use EDD_Composer\Repository\Download;
use EDD_Composer\Repository\URL_Policy;
use EDD_Composer\Repository\Versioned_Files;
use EDD_Composer\Settings;

/**
 * Verifies request-to-signed-URL authorization behavior.
 *
 * @since 0.1.0
 */
final class DownloadTest extends WP_UnitTestCase {
	/**
	 * Restores settings after each test.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_NAME );
		remove_all_filters( 'edd_composer_download_proxy_origin' );
		remove_all_filters( 'edd_composer_allowed_repository_origins' );
		parent::tear_down();
	}

	/**
	 * Confirms the complete direct-license flow signs the exact version and price.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_prepares_same_origin_signed_download() {
		$download_id = $this->create_enabled_download();
		$license     = $this->license( $download_id );
		$order_item  = $this->item( $download_id );
		$order       = $this->order( $order_item );
		$signed_args = array();
		$service     = $this->service(
			$license,
			$order,
			static function ( $item, $email, $filekey, $product_id, $price_id ) use ( &$signed_args ) {
				$signed_args = array(
					'item'       => $item,
					'email'      => $email,
					'filekey'    => $filekey,
					'product_id' => $product_id,
					'price_id'   => $price_id,
				);
				return home_url( '/index.php?eddfile=77%3A123%3A0&token=signed' );
			}
		);

		$result = $service->prepare( 'example-package', 'v1.2.3', $this->credentials() );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( $download_id, $result['download_id'] );
		$this->assertSame( '1.2.3', $result['version'] );
		$this->assertSame( 55, $result['license_id'] );
		$this->assertSame( 77, $result['order_id'] );
		$this->assertSame( $order_item, $signed_args['item'] );
		$this->assertSame( 'customer@example.org', $signed_args['email'] );
		$this->assertSame( 0, $signed_args['filekey'] );
		$this->assertSame( $download_id, $signed_args['product_id'] );
		$this->assertSame( 3, $signed_args['price_id'] );
	}

	/**
	 * Confirms the supported EDD and Software Licensing APIs work end to end.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_prepares_real_edd_signed_url_for_activated_license() {
		$download_id   = $this->create_enabled_download();
		$order_id      = 0;
		$order_item_id = 0;
		$license_id    = 0;
		$activation_id = 0;
		$license_key   = 'edd-composer-integration-license';

		try {
			$order_id = edd_add_order(
				array(
					'status'      => 'complete',
					'type'        => 'sale',
					'email'       => 'customer@example.org',
					'gateway'     => 'manual',
					'mode'        => 'test',
					'currency'    => 'USD',
					'payment_key' => 'edd-composer-integration-order',
					'subtotal'    => 10,
					'total'       => 10,
				)
			);
			$this->assertNotFalse( $order_id );

			$order_item_id = edd_add_order_item(
				array(
					'order_id'     => $order_id,
					'product_id'   => $download_id,
					'product_name' => 'Example Package',
					'status'       => 'complete',
					'quantity'     => 1,
					'amount'       => 10,
					'subtotal'     => 10,
					'total'        => 10,
				)
			);
			$this->assertNotFalse( $order_item_id );

			$license_id = edd_software_licensing()->licenses_db->insert(
				array(
					'license_key'  => $license_key,
					'status'       => 'active',
					'download_id'  => $download_id,
					'payment_id'   => $order_id,
					'cart_index'   => 0,
					'date_created' => current_time( 'mysql' ),
					'expiration'   => 0,
					'parent'       => 0,
				),
				'license'
			);
			$this->assertNotFalse( $license_id );

			$activation_id = edd_software_licensing()->activations_db->insert(
				array(
					'site_name'  => edd_software_licensing()->clean_site_url( home_url() ),
					'license_id' => $license_id,
					'activated'  => 1,
					'is_local'   => 1,
				),
				'site_activation'
			);
			$this->assertNotFalse( $activation_id );

			$service = new Download(
				new Settings(),
				new Versioned_Files(),
				new Authenticator(),
				new Entitlements(),
				new Order_Resolver()
			);
			$result  = $service->prepare(
				'example-package',
				'1.2.3',
				array(
					'PHP_AUTH_USER' => $license_key,
					'PHP_AUTH_PW'   => home_url(),
				)
			);

			$this->assertFalse( is_wp_error( $result ) );
			$this->assertStringStartsWith( site_url( '/index.php' ), $result['redirect_url'] );
			$this->assertStringContainsString( 'eddfile=', $result['redirect_url'] );
			$this->assertStringContainsString( 'token=', $result['redirect_url'] );
		} finally {
			if ( $activation_id ) {
				edd_software_licensing()->activations_db->delete( $activation_id );
			}

			if ( $license_id ) {
				edd_software_licensing()->licenses_db->delete( $license_id );
			}

			if ( $order_item_id ) {
				edd_delete_order_item( $order_item_id );
			}

			if ( $order_id ) {
				edd_delete_order( $order_id );
			}
		}
	}

	/**
	 * Confirms generated URLs cannot leave the configured WordPress origin.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_rejects_external_signed_url() {
		$download_id = $this->create_enabled_download();
		$license     = $this->license( $download_id );
		$order       = $this->order( $this->item( $download_id ) );
		$service     = $this->service( $license, $order, static fn() => 'https://files.example.net/package.zip' );

		$result = $service->prepare( 'example-package', '1.2.3', $this->credentials() );

		$this->assertWPError( $result );
		$this->assertSame( 'edd_composer_download_url_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	/**
	 * Confirms the authorized flow can redirect through an explicit proxy origin.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_rewrites_signed_download_to_allowlisted_proxy_origin() {
		$download_id = $this->create_enabled_download();
		$license     = $this->license( $download_id );
		$order       = $this->order( $this->item( $download_id ) );

		add_filter( 'edd_composer_download_proxy_origin', static fn() => 'https://downloads.example.com' );
		add_filter(
			'edd_composer_allowed_repository_origins',
			static function ( $origins ) {
				$origins[] = 'https://downloads.example.com';
				return $origins;
			}
		);

		$service = new Download(
			new Settings(),
			new Versioned_Files(),
			new Authenticator( static fn() => $license, static fn() => 'example.org' ),
			new Entitlements(),
			new Order_Resolver( static fn() => $order ),
			static fn() => home_url( '/index.php?eddfile=77%3A123%3A0&token=a%2Bb%3D' ),
			new URL_Policy( false )
		);

		$result = $service->prepare( 'example-package', '1.2.3', $this->credentials() );

		$this->assertNotWPError( $result );
		$this->assertSame(
			'https://downloads.example.com/index.php?eddfile=77%3A123%3A0&token=a%2Bb%3D',
			$result['redirect_url']
		);
	}

	/**
	 * Confirms authentication occurs before protected package discovery.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_requires_authentication_before_product_lookup() {
		$service = $this->service( false, false, static fn() => '' );
		$result  = $service->prepare( 'missing-package', '1.2.3', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'edd_composer_missing_credentials', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Confirms malformed versions and disabled products never reach signing.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_rejects_invalid_version_and_disabled_product() {
		$download_id = $this->create_enabled_download();
		$license     = $this->license( $download_id );
		$order       = $this->order( $this->item( $download_id ) );
		$service     = $this->service( $license, $order, static fn() => home_url( '/index.php?token=signed' ) );

		$invalid_version = $service->prepare( 'example-package', 'latest', $this->credentials() );
		$this->assertSame( 400, $invalid_version->get_error_data()['status'] );

		$settings = get_option( Settings::OPTION_NAME );
		$settings['products'][ (string) $download_id ]['enabled'] = false;
		update_option( Settings::OPTION_NAME, $settings );
		$disabled = $service->prepare( 'example-package', '1.2.3', $this->credentials() );

		$this->assertSame( 'edd_composer_product_not_found', $disabled->get_error_code() );
		$this->assertSame( 404, $disabled->get_error_data()['status'] );

		$settings['products'][ (string) $download_id ]['enabled'] = true;
		update_option( Settings::OPTION_NAME, $settings );
		update_post_meta( $download_id, '_edd_sl_enabled', 0 );
		$unlicensed = $service->prepare( 'example-package', '1.2.3', $this->credentials() );

		$this->assertSame( 'edd_composer_product_not_found', $unlicensed->get_error_code() );
		$this->assertSame( 404, $unlicensed->get_error_data()['status'] );
	}

	/**
	 * Creates a published, enabled Download with one versioned file.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	private function create_enabled_download() {
		$download_id = self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_status' => 'publish',
				'post_title'  => 'Example Package',
				'post_name'   => 'example-package',
			)
		);
		update_post_meta(
			$download_id,
			'edd_download_files',
			array(
				array(
					'name'    => 'Example Package',
					'file'    => 'https://storage.example.org/package.zip',
					'version' => 'v1.2.3',
				),
			)
		);
		update_post_meta( $download_id, '_edd_sl_enabled', 1 );
		update_option(
			Settings::OPTION_NAME,
			array(
				'schema_version'  => 1,
				'repository_name' => 'EDD Composer Repository',
				'vendor'          => 'vendor',
				'products'        => array(
					(string) $download_id => array(
						'enabled'      => true,
						'package_slug' => 'example-package',
						'type'         => 'wordpress-plugin',
						'description'  => 'Example package.',
						'require_php'  => '>=8.0',
					),
				),
			)
		);

		return $download_id;
	}

	/**
	 * Creates a fully wired download service with controlled external APIs.
	 *
	 * @since 0.1.0
	 *
	 * @param object|false $license    License lookup result.
	 * @param object|false $order      Order lookup result.
	 * @param callable     $url_signer Signed-URL callback.
	 * @return Download
	 */
	private function service( $license, $order, $url_signer ) {
		return new Download(
			new Settings(),
			new Versioned_Files(),
			new Authenticator( static fn() => $license, static fn() => 'example.org' ),
			new Entitlements(),
			new Order_Resolver( static fn() => $order ),
			$url_signer
		);
	}

	/**
	 * Creates a minimal direct-product license.
	 *
	 * @since 0.1.0
	 *
	 * @param int $download_id Download ID.
	 * @return object
	 */
	private function license( $download_id ) {
		// phpcs:disable Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FunctionComment.Missing -- Compact test double.
		$license = new class( $download_id ) {
			public $ID = 55;
			public $download_id;
			public $status      = 'active';
			public $parent      = 0;
			public $payment_id  = 77;
			public $payment_ids = array( 77 );

			public function __construct( $download_id ) {
				$this->download_id = $download_id;
			}

			public function get_child_licenses() {
				return array();
			}

			public function is_site_active( $site_url ) {
				return 'example.org' === $site_url;
			}
		};
		// phpcs:enable

		return $license;
	}

	/**
	 * Creates a minimal deliverable target order item.
	 *
	 * @since 0.1.0
	 *
	 * @param int $download_id Download ID.
	 * @return object
	 */
	private function item( $download_id ) {
		// phpcs:disable Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FunctionComment.Missing -- Compact test double.
		$item = new class( $download_id ) {
			public $product_id;
			public $price_id = 3;

			public function __construct( $product_id ) {
				$this->product_id = $product_id;
			}

			public function is_deliverable() {
				return true;
			}
		};
		// phpcs:enable

		return $item;
	}

	/**
	 * Creates a minimal EDD order exposing the target item.
	 *
	 * @since 0.1.0
	 *
	 * @param object $item Target order item.
	 * @return object
	 */
	private function order( $item ) {
		// phpcs:disable Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FunctionComment.Missing -- Compact test double.
		$order = new class( $item ) {
			public $id           = 77;
			public $email        = 'customer@example.org';
			public $date_created = '2026-01-01 10:00:00';
			private $item;

			public function __construct( $order_item ) {
				$this->item = $order_item;
			}

			public function get_items_with_bundles() {
				return array( $this->item );
			}
		};
		// phpcs:enable

		return $order;
	}

	/**
	 * Returns valid HTTP Basic server variables.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	private function credentials() {
		return array(
			'PHP_AUTH_USER' => 'license-key',
			'PHP_AUTH_PW'   => 'https://example.org',
		);
	}
}
