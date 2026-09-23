<?php
/**
 * EDD file-version field integration.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds Composer version metadata to EDD's native Download Files rows.
 *
 * @since 0.1.0
 */
final class File_Version_Field {
	/**
	 * Admin stylesheet handle.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const STYLE_HANDLE = 'edd-composer-download-files';

	/**
	 * Registers the EDD integration hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'edd_download_file_table_row', array( $this, 'render' ), 9, 3 );
		add_filter( 'edd_file_row_args', array( $this, 'add_version_to_args' ), 10, 2 );
		add_filter( 'edd_metabox_save_edd_download_files', array( $this, 'sanitize_files' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_style' ) );
	}

	/**
	 * Renders the version input inside an EDD Download file row.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $post_id Download ID.
	 * @param int|string $key     EDD file key.
	 * @param array      $args    EDD file-row arguments.
	 * @return void
	 */
	public function render( $post_id, $key, $args ) {
		$version = isset( $args['version'] ) ? (string) $args['version'] : '';
		?>
		<div class="edd-form-group edd-composer-file-version">
			<label class="edd-form-group__label edd-repeatable-row-setting-label" for="edd_download_files-<?php echo esc_attr( $key ); ?>-version">
				<?php esc_html_e( 'Composer Version', 'edd-composer' ); ?>
			</label>
			<div class="edd-form-group__control">
				<?php
				// EDD's HTML helper escapes every field attribute before output.
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
				echo EDD()->html->text(
					array(
						'name'        => 'edd_download_files[' . $key . '][version]',
						'id'          => 'edd_download_files-' . $key . '-version',
						'value'       => $version,
						'placeholder' => __( 'e.g. 0.1.0 or v0.1.0', 'edd-composer' ),
						'class'       => 'edd-form-group__input edd-composer-file-version__input regular-text',
					)
				);
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Restores the saved version into EDD's row arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param array $args  EDD file-row arguments.
	 * @param array $value Saved EDD file data.
	 * @return array
	 */
	public function add_version_to_args( $args, $value ) {
		$args['version'] = isset( $value['version'] ) ? (string) $value['version'] : '';

		return $args;
	}

	/**
	 * Sanitizes version values while preserving EDD's nested file-data shape.
	 *
	 * Invalid SemVer is retained so the product catalogue can report a precise
	 * validation message rather than silently changing administrator input.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $files EDD Download files.
	 * @return mixed
	 */
	public function sanitize_files( $files ) {
		if ( ! is_array( $files ) ) {
			return $files;
		}

		foreach ( $files as $key => $file ) {
			if ( is_array( $file ) && isset( $file['version'] ) ) {
				$files[ $key ]['version'] = sanitize_text_field( $file['version'] );
			}
		}

		return $files;
	}

	/**
	 * Loads the small layout stylesheet only on EDD Download editors.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook_suffix Current WordPress admin page hook.
	 * @return void
	 */
	public function enqueue_style( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'download' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			EDD_COMPOSER_URL . 'assets/css/edd-download.css',
			array(),
			EDD_COMPOSER_VERSION
		);
	}
}
