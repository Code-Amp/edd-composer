<?php
/**
 * Tests for the wp-env PHPUnit runtime.
 *
 * @package EDD_Composer
 */

/**
 * Verifies the pinned test environment.
 *
 * @since 0.1.0
 */
final class EnvironmentTest extends WP_UnitTestCase {
	/**
	 * Confirms the supported WordPress, PHP, EDD, and plugin fixtures load.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_pinned_environment_is_loaded() {
		$this->assertTrue( defined( 'EDD_COMPOSER_TEST_EXPECTED_WORDPRESS_VERSION' ) );
		$this->assertTrue( defined( 'EDD_COMPOSER_TEST_EXPECTED_PHP_VERSION' ) );
		$this->assertTrue( defined( 'EDD_COMPOSER_TEST_EXPECTED_EDD_VERSION' ) );
		$this->assertTrue( defined( 'EDD_COMPOSER_TEST_EXPECTED_SL_VERSION' ) );
		$this->assertSame( EDD_COMPOSER_TEST_EXPECTED_WORDPRESS_VERSION, get_bloginfo( 'version' ) );
		$this->assertStringStartsWith( EDD_COMPOSER_TEST_EXPECTED_PHP_VERSION, PHP_VERSION );
		$this->assertSame( EDD_COMPOSER_TEST_EXPECTED_EDD_VERSION, EDD_VERSION );
		$this->assertContains(
			WP_PLUGIN_DIR . '/edd-composer/edd-composer.php',
			get_included_files()
		);
	}

	/**
	 * Confirms the required EDD extensions pass the dependency gate.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_required_extensions_are_ready() {
		$dependencies = EDD_Composer\Plugin::instance()->get_dependencies();

		$this->assertInstanceOf( EDD_Composer\Dependencies::class, $dependencies );
		$this->assertTrue( function_exists( 'edd_software_licensing' ) );
		$this->assertSame( EDD_COMPOSER_TEST_EXPECTED_SL_VERSION, EDD_SL_VERSION );
		$this->assertTrue( $dependencies->are_met() );
		$this->assertSame( 1, did_action( 'edd_composer_ready' ) );
	}
}
