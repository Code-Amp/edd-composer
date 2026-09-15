<?php
/**
 * Tests for the requirements-only administration experience.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Admin\Admin_Page;
use EDD_Composer\Dependencies;

/**
 * Verifies administration access decisions.
 *
 * @since 1.0.0
 */
final class AdminPageTest extends WP_UnitTestCase {
	/**
	 * Confirms EDD stores use its settings-management capability.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_edd_capability_is_used_when_edd_is_present() {
		$page = new Admin_Page( $this->dependencies( '3.7.0' ) );

		$this->assertSame( 'manage_shop_settings', $page->get_management_capability() );
	}

	/**
	 * Confirms the requirements fallback remains accessible without EDD.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_wordpress_capability_is_used_when_edd_is_missing() {
		$page = new Admin_Page( $this->dependencies( null ) );

		$this->assertSame( 'manage_options', $page->get_management_capability() );
	}

	/**
	 * Creates an otherwise-supported dependency set.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $edd_version Detected EDD version.
	 * @return Dependencies
	 */
	private function dependencies( $edd_version ) {
		return new Dependencies(
			array(
				'wordpress'          => '6.9',
				'php'                => '8.0.0',
				'edd'                => $edd_version,
				'software_licensing' => null,
			)
		);
	}
}
