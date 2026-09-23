<?php
/**
 * Tests for the EDD file-version field integration.
 *
 * @package EDD_Composer
 */

use EDD_Composer\Admin\File_Version_Field;

/**
 * Verifies the native EDD file-row extension.
 *
 * @since 0.1.0
 */
final class FileVersionFieldTest extends WP_UnitTestCase {
	/**
	 * Restores registered styles and the current admin screen.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function tear_down() {
		wp_dequeue_style( File_Version_Field::STYLE_HANDLE );
		wp_deregister_style( File_Version_Field::STYLE_HANDLE );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Confirms saved version metadata is restored into row arguments.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_adds_saved_version_to_file_row_arguments() {
		$field = new File_Version_Field();

		$this->assertSame(
			'v1.2.3-rc.1',
			$field->add_version_to_args( array( 'name' => 'Package' ), array( 'version' => 'v1.2.3-rc.1' ) )['version']
		);
	}

	/**
	 * Confirms the exact nested EDD field name is rendered.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_renders_version_inside_edd_file_storage_shape() {
		$field = new File_Version_Field();

		ob_start();
		$field->render( 123, 7, array( 'version' => '1.2.3' ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="edd_download_files[7][version]"', $output );
		$this->assertStringContainsString( 'id="edd_download_files-7-version"', $output );
		$this->assertStringContainsString( 'value="1.2.3"', $output );
		$this->assertStringContainsString( 'Composer Version', $output );
		$this->assertStringContainsString( 'edd-composer-file-version__input', $output );
		$this->assertStringNotContainsString( 'edd_repeatable_upload_field', $output );
	}

	/**
	 * Confirms saving sanitizes but does not silently rewrite invalid input.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_sanitizes_versions_without_changing_storage_shape() {
		$field = new File_Version_Field();
		$files = $field->sanitize_files(
			array(
				'file-key' => array(
					'name'    => 'Package',
					'file'    => 'https://example.org/package.zip',
					'version' => '<b>not-semver</b>',
				),
			)
		);

		$this->assertSame( 'not-semver', $files['file-key']['version'] );
		$this->assertSame( 'Package', $files['file-key']['name'] );
	}

	/**
	 * Confirms file-row styling loads only on Download editors.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function test_enqueues_style_only_on_download_editors() {
		$field = new File_Version_Field();

		set_current_screen( 'edit-post' );
		$field->enqueue_style( 'post.php' );
		$this->assertFalse( wp_style_is( File_Version_Field::STYLE_HANDLE, 'enqueued' ) );

		set_current_screen( 'download' );
		$field->enqueue_style( 'post-new.php' );
		$this->assertTrue( wp_style_is( File_Version_Field::STYLE_HANDLE, 'enqueued' ) );
	}
}
