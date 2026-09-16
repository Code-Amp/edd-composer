<?php
/**
 * Plugin administration page.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer\Admin;

use EDD_Composer\Dependencies;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin screen and dependency notice.
 *
 * @since 1.0.0
 */
final class Admin_Page {
	/**
	 * Admin script handle.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SCRIPT_HANDLE = 'edd-composer-admin';

	/**
	 * Admin stylesheet handle.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const STYLE_HANDLE = 'edd-composer-admin';

	/**
	 * Admin page slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const PAGE_SLUG = 'edd-composer';

	/**
	 * Runtime dependencies.
	 *
	 * @since 1.0.0
	 * @var Dependencies
	 */
	private $dependencies;

	/**
	 * Registered WordPress admin page hook.
	 *
	 * @since 1.0.0
	 * @var string|false|null
	 */
	private $page_hook;

	/**
	 * Creates the admin page service.
	 *
	 * @since 1.0.0
	 *
	 * @param Dependencies $dependencies Runtime dependency evaluator.
	 */
	public function __construct( Dependencies $dependencies ) {
		$this->dependencies = $dependencies;
	}

	/**
	 * Registers WordPress admin hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );

		if ( ! $this->dependencies->are_met() ) {
			add_action( 'admin_notices', array( $this, 'render_dependency_notice' ) );
			return;
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the screen beneath Downloads or the Settings fallback.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_menu() {
		$page_title = __( 'EDD Composer Extension', 'edd-composer' );
		$menu_title = __( 'Composer', 'edd-composer' );
		$capability = $this->get_management_capability();

		if ( $this->dependencies->has_edd() ) {
			$this->page_hook = add_submenu_page(
				'edit.php?post_type=download',
				$page_title,
				$menu_title,
				$capability,
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
			return;
		}

		$this->page_hook = add_options_page(
			$page_title,
			$menu_title,
			$capability,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueues the admin application only on the plugin screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current WordPress admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if (
			! $this->dependencies->are_met()
			|| ! is_string( $this->page_hook )
			|| $this->page_hook !== $hook_suffix
		) {
			return;
		}

		$asset_path = EDD_COMPOSER_PATH . 'assets/index.asset.php';

		if ( ! is_readable( $asset_path ) ) {
			return;
		}

		$asset = require $asset_path;

		if (
			! is_array( $asset )
			|| ! isset( $asset['dependencies'], $asset['version'] )
			|| ! is_array( $asset['dependencies'] )
			|| ! is_string( $asset['version'] )
		) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			EDD_COMPOSER_URL . 'assets/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'edd-composer',
			EDD_COMPOSER_PATH . 'languages'
		);

		$style_path = EDD_COMPOSER_PATH . 'assets/style-index.css';

		if ( is_readable( $style_path ) ) {
			wp_enqueue_style(
				self::STYLE_HANDLE,
				EDD_COMPOSER_URL . 'assets/style-index.css',
				array( 'wp-components' ),
				$asset['version']
			);
		}
	}

	/**
	 * Gets the capability required to manage the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_management_capability() {
		if ( ! $this->dependencies->has_edd() ) {
			return 'manage_options';
		}

		/**
		 * Filters the capability required to manage EDD Composer Extension.
		 *
		 * @since 1.0.0
		 *
		 * @param string $capability Management capability.
		 */
		return apply_filters( 'edd_composer_management_capability', 'manage_shop_settings' );
	}

	/**
	 * Renders the plugin admin screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( $this->get_management_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage EDD Composer Extension.', 'edd-composer' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'EDD Composer Extension', 'edd-composer' ); ?></h1>
			<?php if ( ! $this->dependencies->are_met() ) : ?>
				<p><?php esc_html_e( 'The following minimum requirements must be met before Composer repository features can be enabled.', 'edd-composer' ); ?></p>
				<?php $this->render_requirements_table(); ?>
			<?php else : ?>
				<div id="edd-composer-admin"></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders an actionable missing-dependency notice.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_dependency_notice() {
		if ( ! current_user_can( $this->get_management_capability() ) ) {
			return;
		}

		$url = $this->dependencies->has_edd()
			? admin_url( 'edit.php?post_type=download&page=' . self::PAGE_SLUG )
			: admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: URL to the plugin requirements page. */
					wp_kses_post( __( 'EDD Composer Extension is inactive until its minimum requirements are met. <a href="%s">Review requirements</a>.', 'edd-composer' ) ),
					esc_url( $url )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the dependency status table.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_requirements_table() {
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Requirement', 'edd-composer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Minimum', 'edd-composer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Detected', 'edd-composer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'edd-composer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $this->dependencies->get_requirements() as $requirement ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $requirement['label'] ); ?></th>
						<td><?php echo esc_html( $requirement['minimum'] ); ?></td>
						<td>
							<?php
							echo esc_html(
								is_string( $requirement['current'] )
									? $requirement['current']
									: __( 'Not detected', 'edd-composer' )
							);
							?>
						</td>
						<td>
							<?php
							echo esc_html(
								$requirement['met']
									? __( 'Ready', 'edd-composer' )
									: __( 'Action required', 'edd-composer' )
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><?php esc_html_e( 'Install or update Easy Digital Downloads and its Software Licensing extension, then reload this page.', 'edd-composer' ); ?></p>
		<?php
	}
}
