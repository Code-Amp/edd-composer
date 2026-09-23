<?php
/**
 * Security-critical integration tests against real EDD and Software Licensing.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Licensing\Authenticator;
use EDD_Composer\Licensing\Entitlements;
use EDD_Composer\Licensing\Order_Resolver;
use EDD_Composer\Repository\Download;
use EDD_Composer\Repository\Versioned_Files;
use EDD_Composer\Settings;

/**
 * Verifies real database models and supported EDD/Software Licensing APIs.
 *
 * @since 0.1.0
 */
final class RealEDDIntegrationTest extends WP_UnitTestCase {
	/**
	 * IDs created by a test, grouped for safe cleanup.
	 *
	 * @since 0.1.0
	 * @var array<string, array<int, int>>
	 */
	private $resources = array();

	/**
	 * Creates isolated resource lists and repository settings.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->resources = array(
			'activations' => array(),
			'licenses'    => array(),
			'order_items' => array(),
			'orders'      => array(),
			'posts'       => array(),
		);
		update_option(
			Settings::OPTION_NAME,
			array(
				'schema_version'  => Settings::SCHEMA_VERSION,
				'repository_name' => 'Real EDD Integration Repository',
				'vendor'          => 'integration-test',
				'products'        => array(),
			)
		);
	}

	/**
	 * Removes all real-plugin rows made by the current test.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function tear_down() {
		foreach ( array_reverse( $this->resources['activations'] ) as $id ) {
			edd_software_licensing()->activations_db->delete( $id );
		}

		foreach ( array_reverse( $this->resources['licenses'] ) as $id ) {
			edd_software_licensing()->licenses_db->delete( $id );
		}

		foreach ( array_reverse( $this->resources['order_items'] ) as $id ) {
			edd_delete_order_item( $id );
		}

		foreach ( array_reverse( $this->resources['orders'] ) as $id ) {
			edd_delete_order( $id );
		}

		foreach ( array_reverse( $this->resources['posts'] ) as $id ) {
			wp_delete_post( $id, true );
		}

		delete_option( Settings::OPTION_NAME );
		delete_transient( Settings::PACKAGE_INDEX_TRANSIENT );
		parent::tear_down();
	}

	/**
	 * Confirms parent and child credentials traverse a real bundle family.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_real_bundle_child_and_sibling_entitlements() {
		$child_id   = $this->create_product( 'bundle-child' );
		$sibling_id = $this->create_product( 'bundle-sibling' );
		$bundle_id  = $this->create_product( 'parent-bundle', false );
		update_post_meta( $bundle_id, '_edd_product_type', 'bundle' );
		update_post_meta( $bundle_id, '_edd_bundled_products', array( $child_id, $sibling_id ) );
		$order_id         = $this->create_order( $bundle_id );
		$parent_license   = $this->create_license( $bundle_id, $order_id );
		$child_license    = $this->create_license( $child_id, $order_id, 'active', $parent_license['id'] );
		$sibling_license  = $this->create_license( $sibling_id, $order_id, 'active', $parent_license['id'] );
		$parent_to_child  = $this->service()->prepare( 'bundle-child', '1.0.0', $this->credentials( $parent_license['key'] ) );
		$child_to_sibling = $this->service()->prepare( 'bundle-sibling', '1.0.0', $this->credentials( $child_license['key'] ) );
		$sibling_to_child = $this->service()->prepare( 'bundle-child', '1.0.0', $this->credentials( $sibling_license['key'] ) );

		$this->assertNotWPError( $parent_to_child );
		$this->assertNotWPError( $child_to_sibling );
		$this->assertNotWPError( $sibling_to_child );
		$this->assertSame( $child_license['id'], $parent_to_child['license_id'] );
		$this->assertSame( $sibling_license['id'], $child_to_sibling['license_id'] );
	}

	/**
	 * Confirms all-price files and exact price/order selection use real models.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_real_variable_product_uses_exact_historical_price_context() {
		$download_id = $this->create_product( 'variable-product', true, true );
		$matching    = $this->create_order( $download_id, 1 );
		$newer_other = $this->create_order( $download_id, 2 );
		$license     = $this->create_license( $download_id, $matching, 'active', 0, 1 );
		$license_row = edd_software_licensing()->get_license( $license['id'] );
		$license_row->add_meta( '_edd_sl_payment_id', $newer_other );
		$signed = array();
		$result = $this->service(
			static function ( $item, $email, $filekey, $product_id, $price_id ) use ( &$signed ) {
				$signed = array(
					'item'       => $item,
					'email'      => $email,
					'filekey'    => $filekey,
					'product_id' => $product_id,
					'price_id'   => $price_id,
				);
				return home_url( '/index.php?eddfile=fixture&token=signed' );
			}
		)->prepare( 'variable-product', '1.0.0', $this->credentials( $license['key'] ) );

		$this->assertNotWPError( $result );
		$this->assertSame( $matching, $result['order_id'] );
		$this->assertSame( 1, $signed['price_id'] );
		$this->assertSame( $download_id, $signed['product_id'] );
	}

	/**
	 * Confirms revoked and expired submitted credentials cannot proceed.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_real_revoked_and_expired_credentials_are_rejected() {
		$download_id = $this->create_product( 'credential-status' );
		$order_id    = $this->create_order( $download_id );

		// Software Licensing persists a revoked licence with the `disabled` status.
		foreach ( array( 'disabled', 'expired' ) as $status ) {
			$license = $this->create_license( $download_id, $order_id, $status, 0, false, false );
			$result  = $this->service()->prepare( 'credential-status', '1.0.0', $this->credentials( $license['key'] ) );

			$this->assertWPError( $result );
			$this->assertSame( 'edd_composer_license_unavailable', $result->get_error_code() );
		}
	}

	/**
	 * Confirms unavailable target licences block valid parent credentials.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_real_unavailable_bundle_target_is_rejected() {
		$target_id = $this->create_product( 'unavailable-target' );
		$bundle_id = $this->create_product( 'unavailable-parent', false );
		update_post_meta( $bundle_id, '_edd_product_type', 'bundle' );
		update_post_meta( $bundle_id, '_edd_bundled_products', array( $target_id ) );
		$order_id = $this->create_order( $bundle_id );
		$parent   = $this->create_license( $bundle_id, $order_id );
		$this->create_license( $target_id, $order_id, 'disabled', $parent['id'], false, false );
		$result = $this->service()->prepare( 'unavailable-target', '1.0.0', $this->credentials( $parent['key'] ) );

		$this->assertWPError( $result );
		$this->assertSame( 'edd_composer_target_license_unavailable', $result->get_error_code() );
	}

	/**
	 * Confirms activation, relationship, publication, and order failures stay closed.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_real_negative_authorization_paths_fail_closed() {
		$first_id      = $this->create_product( 'first-product' );
		$second_id     = $this->create_product( 'second-product' );
		$disabled_id   = $this->create_product( 'disabled-product', false );
		$first_order   = $this->create_order( $first_id );
		$second_order  = $this->create_order( $second_id );
		$first_license = $this->create_license( $first_id, $first_order );
		$unactivated   = $this->create_license( $second_id, $second_order, 'active', 0, false, false );
		$missing_order = $this->create_license( $second_id, 999999999 );

		$unrelated = $this->service()->prepare( 'second-product', '1.0.0', $this->credentials( $first_license['key'] ) );
		$inactive  = $this->service()->prepare( 'second-product', '1.0.0', $this->credentials( $unactivated['key'] ) );
		$disabled  = $this->service()->prepare( 'disabled-product', '1.0.0', $this->credentials( $first_license['key'] ) );
		$missing   = $this->service()->prepare( 'second-product', '1.0.0', $this->credentials( $missing_order['key'] ) );

		$this->assertSame( 'edd_composer_product_not_entitled', $unrelated->get_error_code() );
		$this->assertSame( 'edd_composer_site_not_activated', $inactive->get_error_code() );
		$this->assertSame( 'edd_composer_product_not_found', $disabled->get_error_code() );
		$this->assertSame( 'edd_composer_order_not_found', $missing->get_error_code() );
		$this->assertSame( 500, $missing->get_error_data()['status'] );
		$this->assertNotSame( $first_id, $second_id );
		$this->assertGreaterThan( 0, $disabled_id );
	}

	/**
	 * Confirms invalid versions never call the EDD URL signer.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_real_invalid_version_never_reaches_signer() {
		$download_id = $this->create_product( 'invalid-version' );
		$order_id    = $this->create_order( $download_id );
		$license     = $this->create_license( $download_id, $order_id );
		$signatures  = 0;
		$result      = $this->service(
			static function () use ( &$signatures ) {
				++$signatures;
				return home_url( '/index.php?token=unexpected' );
			}
		)->prepare( 'invalid-version', '2.0.0', $this->credentials( $license['key'] ) );

		$this->assertWPError( $result );
		$this->assertSame( 'edd_composer_version_not_found', $result->get_error_code() );
		$this->assertSame( 0, $signatures );
	}

	/**
	 * Creates a configured EDD Download.
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug     Package slug.
	 * @param bool   $enabled  Whether Composer publication is enabled.
	 * @param bool   $variable Whether the product uses variable prices.
	 * @return int
	 */
	private function create_product( $slug, $enabled = true, $variable = false ) {
		$download_id                = self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_status' => 'publish',
				'post_title'  => ucwords( str_replace( '-', ' ', $slug ) ),
				'post_name'   => $slug,
			)
		);
		$this->resources['posts'][] = $download_id;
		$file                       = array(
			'name'    => 'Integration package',
			'file'    => home_url( '/integration-package.zip' ),
			'version' => '1.0.0',
		);

		if ( $variable ) {
			update_post_meta( $download_id, '_variable_pricing', 1 );
			update_post_meta(
				$download_id,
				'edd_variable_prices',
				array(
					1 => array(
						'name'   => 'One',
						'amount' => 10,
						'index'  => 1,
					),
					2 => array(
						'name'   => 'Two',
						'amount' => 20,
						'index'  => 2,
					),
				)
			);
			$file['condition'] = 'all';
		}

		update_post_meta( $download_id, 'edd_download_files', array( $file ) );
		update_post_meta( $download_id, '_edd_sl_enabled', 1 );
		$settings                                      = get_option( Settings::OPTION_NAME );
		$settings['products'][ (string) $download_id ] = array(
			'enabled'      => $enabled,
			'package_slug' => $slug,
			'type'         => 'wordpress-plugin',
			'description'  => 'Real EDD integration product.',
			'require_php'  => '>=8.0',
		);
		update_option( Settings::OPTION_NAME, $settings );

		return $download_id;
	}

	/**
	 * Creates a complete EDD order and product item.
	 *
	 * @since 0.1.0
	 *
	 * @param int       $download_id Product ID.
	 * @param int|false $price_id   Optional variation ID.
	 * @return int
	 */
	private function create_order( $download_id, $price_id = false ) {
		$order_id = edd_add_order(
			array(
				'status'      => 'complete',
				'type'        => 'sale',
				'email'       => 'integration@example.org',
				'gateway'     => 'manual',
				'mode'        => 'test',
				'currency'    => 'USD',
				'payment_key' => wp_generate_uuid4(),
				'subtotal'    => 10,
				'total'       => 10,
			)
		);
		$this->assertNotFalse( $order_id );
		$this->resources['orders'][] = $order_id;
		$item                        = array(
			'order_id'     => $order_id,
			'product_id'   => $download_id,
			'product_name' => get_the_title( $download_id ),
			'status'       => 'complete',
			'quantity'     => 1,
			'amount'       => 10,
			'subtotal'     => 10,
			'total'        => 10,
		);

		if ( is_numeric( $price_id ) ) {
			$item['price_id'] = absint( $price_id );
		}

		$item_id = edd_add_order_item( $item );
		$this->assertNotFalse( $item_id );
		$this->resources['order_items'][] = $item_id;

		return $order_id;
	}

	/**
	 * Creates a real EDD Software Licensing row and optional activation.
	 *
	 * @since 0.1.0
	 *
	 * @param int       $download_id Product ID.
	 * @param int       $order_id    Order ID.
	 * @param string    $status      Licence status.
	 * @param int       $parent_id   Parent licence ID.
	 * @param int|false $price_id    Variation ID.
	 * @param bool      $activate    Whether to activate for the test site.
	 * @return array{id: int, key: string}
	 */
	private function create_license( $download_id, $order_id, $status = 'active', $parent_id = 0, $price_id = false, $activate = true ) {
		$key  = 'integration-' . wp_generate_password( 20, false, false );
		$args = array(
			'license_key'  => $key,
			'status'       => $status,
			'download_id'  => $download_id,
			'payment_id'   => $order_id,
			'cart_index'   => 0,
			'date_created' => current_time( 'mysql' ),
			'expiration'   => 'expired' === $status ? time() - HOUR_IN_SECONDS : 0,
			'parent'       => $parent_id,
		);

		if ( is_numeric( $price_id ) ) {
			$args['price_id'] = absint( $price_id );
		}

		$license_id = edd_software_licensing()->licenses_db->insert( $args, 'license' );
		$this->assertNotFalse( $license_id );
		$this->resources['licenses'][] = $license_id;

		if ( $activate ) {
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
			$this->resources['activations'][] = $activation_id;
		}

		return array(
			'id'  => $license_id,
			'key' => $key,
		);
	}

	/**
	 * Creates the production download service with an optional observed signer.
	 *
	 * @since 0.1.0
	 *
	 * @param callable|null $signer URL signer.
	 * @return Download
	 */
	private function service( $signer = null ) {
		return new Download(
			new Settings(),
			new Versioned_Files(),
			new Authenticator(),
			new Entitlements(),
			new Order_Resolver(),
			$signer ? $signer : static fn() => home_url( '/index.php?eddfile=fixture&token=signed' )
		);
	}

	/**
	 * Builds HTTP Basic server values for the test site.
	 *
	 * @since 0.1.0
	 *
	 * @param string $license_key Licence key.
	 * @return array<string, string>
	 */
	private function credentials( $license_key ) {
		return array(
			'PHP_AUTH_USER' => $license_key,
			'PHP_AUTH_PW'   => home_url(),
		);
	}
}
