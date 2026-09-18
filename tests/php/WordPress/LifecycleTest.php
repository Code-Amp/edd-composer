<?php
/**
 * Tests for plugin rewrite lifecycle behavior.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Activator;
use EDD_Composer\Deactivator;
use EDD_Composer\Repository\Router;
use EDD_Composer\Settings;

/**
 * Verifies activation and deactivation manage only rewrite-owned state.
 *
 * @since 1.0.0
 */
final class LifecycleTest extends WP_UnitTestCase {
	/**
	 * Restores plugin-owned options after each test.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function tear_down() {
		delete_option( Router::REWRITE_VERSION_OPTION );
		delete_option( Settings::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * Confirms supported activation installs and versions rewrite rules.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_activation_installs_repository_rewrite_rules() {
		global $wp_rewrite;

		Activator::activate();

		$this->assertSame( Router::REWRITE_VERSION, get_option( Router::REWRITE_VERSION_OPTION ) );
		$this->assertArrayHasKey( '^composer/?$', $wp_rewrite->extra_rules_top );
		$this->assertArrayHasKey( '^composer/packages\.json$', $wp_rewrite->extra_rules_top );
		$this->assertArrayHasKey( '^composer/download/([^/]+)/([^/]+)/?$', $wp_rewrite->extra_rules_top );
	}

	/**
	 * Confirms deactivation removes rewrites but preserves product settings.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_deactivation_removes_rewrites_without_deleting_settings() {
		global $wp_rewrite;

		$settings = array(
			'schema_version'  => 1,
			'repository_name' => 'Example Packages',
			'vendor'          => 'example',
			'products'        => array(),
		);
		update_option( Settings::OPTION_NAME, $settings );
		Router::register_rewrite_rules();
		update_option( Router::REWRITE_VERSION_OPTION, Router::REWRITE_VERSION );

		Deactivator::deactivate();

		$this->assertArrayNotHasKey( '^composer/?$', $wp_rewrite->extra_rules_top );
		$this->assertArrayNotHasKey( '^composer/packages\.json$', $wp_rewrite->extra_rules_top );
		$this->assertArrayNotHasKey( '^composer/download/([^/]+)/([^/]+)/?$', $wp_rewrite->extra_rules_top );
		$this->assertFalse( get_option( Router::REWRITE_VERSION_OPTION ) );
		$this->assertSame( $settings, get_option( Settings::OPTION_NAME ) );
	}
}
