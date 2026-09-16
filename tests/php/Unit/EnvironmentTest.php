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
	 * Confirms the required EDD extensions pass the dependency gate.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_required_extensions_are_ready() {
		$dependencies = EDD_Composer\Plugin::instance()->get_dependencies();

		$this->assertInstanceOf( EDD_Composer\Dependencies::class, $dependencies );
		$this->assertTrue( function_exists( 'edd_software_licensing' ) );
		$this->assertSame( '3.9.7', EDD_SL_VERSION );
		$this->assertTrue( $dependencies->are_met() );
		$this->assertSame( 1, did_action( 'edd_composer_ready' ) );
	}
}
