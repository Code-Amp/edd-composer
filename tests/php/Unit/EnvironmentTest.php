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
}
