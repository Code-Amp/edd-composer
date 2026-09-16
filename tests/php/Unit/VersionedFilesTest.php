<?php
/**
 * Tests for EDD download-file version diagnostics.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Repository\Versioned_Files;

/**
 * Verifies strict SemVer and file publication rules.
 *
 * @since 1.0.0
 */
final class VersionedFilesTest extends WP_UnitTestCase {
	/**
	 * Confirms supported SemVer forms are canonicalized consistently.
	 *
	 * @since 1.0.0
	 *
	 * @dataProvider provide_valid_versions
	 *
	 * @param string $version   Stored file version.
	 * @param string $canonical Expected canonical version.
	 * @return void
	 */
	public function test_canonicalizes_valid_semver( $version, $canonical ) {
		$files = new Versioned_Files();

		$this->assertSame( $canonical, $files->canonicalize( $version ) );
	}

	/**
	 * Supplies supported version strings.
	 *
	 * @since 1.0.0
	 * @return array<string, array<string, string>>
	 */
	public function provide_valid_versions() {
		return array(
			'stable'            => array( '1.2.3', '1.2.3' ),
			'v prefix'          => array( 'v1.2.3', '1.2.3' ),
			'prerelease'        => array( '2.0.0-beta.2', '2.0.0-beta.2' ),
			'release candidate' => array( '3.0.0-rc.1+build.8', '3.0.0-rc.1+build.8' ),
		);
	}

	/**
	 * Confirms malformed versions are rejected.
	 *
	 * @since 1.0.0
	 *
	 * @dataProvider provide_invalid_versions
	 *
	 * @param mixed $version Invalid version.
	 * @return void
	 */
	public function test_rejects_invalid_semver( $version ) {
		$files = new Versioned_Files();

		$this->assertNull( $files->canonicalize( $version ) );
	}

	/**
	 * Supplies rejected version values.
	 *
	 * @since 1.0.0
	 * @return array<string, array<int, mixed>>
	 */
	public function provide_invalid_versions() {
		return array(
			'missing patch'       => array( '1.2' ),
			'uppercase prefix'    => array( 'V1.2.3' ),
			'leading zero'        => array( '01.2.3' ),
			'bad prerelease zero' => array( '1.2.3-01' ),
			'empty'               => array( '' ),
			'non-string'          => array( 123 ),
		);
	}

	/**
	 * Confirms valid versions are sorted newest-first.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_analyzes_and_sorts_versioned_files() {
		$download_id = $this->create_download_with_files(
			array(
				array(
					'version' => '1.0.0',
					'file'    => 'https://example.org/one.zip',
				),
				array(
					'version' => 'v2.0.0',
					'file'    => 'https://example.org/two.zip',
				),
				array( 'file' => 'https://example.org/unversioned.zip' ),
			)
		);

		$analysis = ( new Versioned_Files() )->analyze( $download_id );

		$this->assertTrue( $analysis['valid'] );
		$this->assertSame( array( '2.0.0', '1.0.0' ), $analysis['versions'] );
		$this->assertSame( 2, $analysis['count'] );
		$this->assertSame( array(), $analysis['messages'] );
	}

	/**
	 * Confirms versions that canonicalize to the same key are rejected.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_detects_duplicate_canonical_versions() {
		$download_id = $this->create_download_with_files(
			array(
				array(
					'version' => '1.2.3',
					'file'    => 'https://example.org/one.zip',
				),
				array(
					'version' => 'v1.2.3',
					'file'    => 'https://example.org/two.zip',
				),
			)
		);

		$analysis = ( new Versioned_Files() )->analyze( $download_id );

		$this->assertFalse( $analysis['valid'] );
		$this->assertStringContainsString( 'duplicated', implode( ' ', $analysis['messages'] ) );
	}

	/**
	 * Confirms variable-price files must apply to every price variation.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_rejects_price_specific_versioned_files() {
		$download_id = $this->create_download_with_files(
			array(
				array(
					'version'   => '1.2.3',
					'file'      => 'https://example.org/one.zip',
					'condition' => '1',
				),
			)
		);
		update_post_meta( $download_id, '_variable_pricing', 1 );

		$analysis = ( new Versioned_Files() )->analyze( $download_id );

		$this->assertFalse( $analysis['valid'] );
		$this->assertStringContainsString( 'all price variations', implode( ' ', $analysis['messages'] ) );
	}

	/**
	 * Creates a published EDD Download with file metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<string, mixed>> $files EDD file data.
	 * @return int
	 */
	private function create_download_with_files( array $files ) {
		$download_id = self::factory()->post->create(
			array(
				'post_type'   => 'download',
				'post_status' => 'publish',
				'post_title'  => 'Versioned product',
			)
		);
		update_post_meta( $download_id, 'edd_download_files', $files );

		return $download_id;
	}
}
