<?php
/**
 * Tests for EDD Software Licensing entitlement resolution.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Licensing\Entitlements;

/**
 * Verifies direct and bundle-family authorization decisions.
 *
 * @since 1.0.0
 */
final class EntitlementsTest extends WP_UnitTestCase {
	/**
	 * Confirms a direct license grants its own activated product.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_resolves_direct_product_license() {
		$license = $this->license( 1, 100 );
		$result  = ( new Entitlements() )->resolve( $license, 100, 'example.org' );

		$this->assertSame( $license, $result );
	}

	/**
	 * Confirms a parent bundle credential can resolve a child product.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_resolves_child_from_parent_bundle_license() {
		$parent           = $this->license( 1, 100 );
		$child            = $this->license( 2, 200, 'active', 1 );
		$parent->children = array( $child );

		$result = ( new Entitlements() )->resolve( $parent, 200, 'example.org' );

		$this->assertSame( $child, $result );
	}

	/**
	 * Confirms a child credential can resolve an activated sibling via its parent.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_resolves_sibling_from_child_license() {
		$parent           = $this->license( 1, 100 );
		$credential       = $this->license( 2, 200, 'active', 1 );
		$sibling          = $this->license( 3, 300, 'active', 1 );
		$parent->children = array( $credential, $sibling );
		$resolver         = new Entitlements(
			static fn( $license_id ) => 1 === $license_id ? $parent : false
		);

		$result = $resolver->resolve( $credential, 300, 'example.org' );

		$this->assertSame( $sibling, $result );
	}

	/**
	 * Confirms status is revalidated on the license granting the target product.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_rejects_expired_target_even_when_credential_is_valid() {
		$parent           = $this->license( 1, 100 );
		$expired_child    = $this->license( 2, 200, 'expired', 1 );
		$parent->children = array( $expired_child );

		$result = ( new Entitlements() )->resolve( $parent, 200, 'example.org' );

		$this->assertWPError( $result );
		$this->assertSame( 'edd_composer_target_license_unavailable', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Confirms activation belongs to the target license, not just the credential.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_rejects_target_not_activated_for_site() {
		$parent           = $this->license( 1, 100, 'active', 0, true );
		$child            = $this->license( 2, 200, 'active', 1, false );
		$parent->children = array( $child );

		$result = ( new Entitlements() )->resolve( $parent, 200, 'example.org' );

		$this->assertWPError( $result );
		$this->assertSame( 'edd_composer_site_not_activated', $result->get_error_code() );
	}

	/**
	 * Confirms unrelated products cannot be reached through a bundle family.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_rejects_unrelated_product() {
		$license = $this->license( 1, 100 );
		$result  = ( new Entitlements() )->resolve( $license, 999, 'example.org' );

		$this->assertWPError( $result );
		$this->assertSame( 'edd_composer_product_not_entitled', $result->get_error_code() );
	}

	/**
	 * Creates a minimal license double.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $id          License ID.
	 * @param int    $download_id Download ID.
	 * @param string $status      License status.
	 * @param int    $parent_id   Parent license ID.
	 * @param bool   $site_active Whether the tested site is active.
	 * @return object
	 */
	private function license( $id, $download_id, $status = 'active', $parent_id = 0, $site_active = true ) {
		// phpcs:disable Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FunctionComment.Missing -- Compact test double.
		$license = new class( $id, $download_id, $status, $parent_id, $site_active ) {
			public $ID;
			public $download_id;
			public $status;
			public $parent;
			public $site_active;
			public $children = array();

			public function __construct( $id, $download_id, $status, $parent_id, $site_active ) {
				$this->ID          = $id;
				$this->download_id = $download_id;
				$this->status      = $status;
				$this->parent      = $parent_id;
				$this->site_active = $site_active;
			}

			public function get_child_licenses() {
				return $this->children;
			}

			public function is_site_active( $site_url ) {
				return $this->site_active && 'example.org' === $site_url;
			}
		};
		// phpcs:enable

		return $license;
	}
}
