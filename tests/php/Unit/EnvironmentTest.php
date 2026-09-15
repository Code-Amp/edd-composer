<?php
/**
 * Tests for the wp-env PHPUnit runtime.
 *
 * @package EDD_Composer
 */

/**
 * Verifies the pinned test environment.
 *
 * @since 1.0.0
 */
final class EnvironmentTest extends WP_UnitTestCase {
	/**
	 * Confirms the supported WordPress, PHP, EDD, and plugin fixtures load.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_pinned_environment_is_loaded() {
		$this->assertSame( '6.9', get_bloginfo( 'version' ) );
		$this->assertTrue( version_compare( PHP_VERSION, '8.0', '>=' ) );
		$this->assertSame( '3.7.0', EDD_VERSION );
		$this->assertContains(
			WP_PLUGIN_DIR . '/edd-composer/edd-composer.php',
			get_included_files()
		);
	}

	/**
	 * Confirms missing Software Licensing leaves only the admin gate active.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_missing_software_licensing_does_not_signal_readiness() {
		$dependencies = EDD_Composer\Plugin::instance()->get_dependencies();

		$this->assertInstanceOf( EDD_Composer\Dependencies::class, $dependencies );
		$this->assertFalse( $dependencies->are_met() );
		$this->assertSame( 0, did_action( 'edd_composer_ready' ) );
	}
}
