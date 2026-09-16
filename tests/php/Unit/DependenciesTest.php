<?php
/**
 * Tests for the shared runtime dependency gate.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Dependencies;

/**
 * Verifies dependency version handling.
 *
 * @since 1.0.0
 */
final class DependenciesTest extends WP_UnitTestCase {
	/**
	 * Confirms all supported minimum versions pass the gate.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_minimum_supported_versions_pass() {
		$dependencies = new Dependencies(
			array(
				'wordpress'          => '6.9',
				'php'                => '8.0.0',
				'edd'                => '3.7.0',
				'software_licensing' => '3.9.7',
			)
		);

		$this->assertTrue( $dependencies->are_met() );
	}

	/**
	 * Confirms a missing Software Licensing extension fails the gate.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_missing_software_licensing_fails() {
		$dependencies = new Dependencies(
			array(
				'wordpress' => '6.9',
				'php'       => '8.0.0',
				'edd'       => '3.7.0',
			)
		);

		$this->assertFalse( $dependencies->are_met() );
		$this->assertFalse( $dependencies->get_requirements()['software_licensing']['met'] );
	}

	/**
	 * Confirms every below-minimum version fails independently.
	 *
	 * @since 1.0.0
	 *
	 * @dataProvider provide_outdated_dependencies
	 *
	 * @param string $dependency Dependency key to make outdated.
	 * @param string $version    Outdated version.
	 * @return void
	 */
	public function test_outdated_dependency_fails( $dependency, $version ) {
		$versions                = array(
			'wordpress'          => '6.9',
			'php'                => '8.0.0',
			'edd'                => '3.7.0',
			'software_licensing' => '3.9.7',
		);
		$versions[ $dependency ] = $version;

		$dependencies = new Dependencies( $versions );

		$this->assertFalse( $dependencies->are_met() );
		$this->assertFalse( $dependencies->get_requirements()[ $dependency ]['met'] );
	}

	/**
	 * Supplies unsupported dependency versions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, string>>
	 */
	public function provide_outdated_dependencies() {
		return array(
			'WordPress'          => array( 'wordpress', '6.8.3' ),
			'PHP'                => array( 'php', '7.4.33' ),
			'EDD'                => array( 'edd', '3.6.9' ),
			'Software Licensing' => array( 'software_licensing', '3.9.6' ),
		);
	}

	/**
	 * Confirms the test runtime detects both required EDD plugins.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_environment_detection_uses_loaded_plugins() {
		$dependencies = Dependencies::from_environment();
		$requirements = $dependencies->get_requirements();

		$this->assertSame( '3.7.0', $requirements['edd']['current'] );
		$this->assertSame( '3.9.7', $requirements['software_licensing']['current'] );
		$this->assertTrue( $dependencies->are_met() );
	}
}
