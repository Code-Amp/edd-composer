<?php
/**
 * Main plugin coordinator.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer;

use EDD_Composer\Admin\Admin_Page;
use EDD_Composer\Admin\File_Version_Field;
use EDD_Composer\Licensing\Authenticator;
use EDD_Composer\Licensing\Entitlements;
use EDD_Composer\Licensing\Order_Resolver;
use EDD_Composer\Repository\Download;
use EDD_Composer\Repository\Package_Index;
use EDD_Composer\Repository\Responses;
use EDD_Composer\Repository\Router;
use EDD_Composer\Repository\URL_Policy;
use EDD_Composer\Repository\Versioned_Files;
use EDD_Composer\REST_API\Admin_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates plugin startup and the dependency gate.
 *
 * @since 1.0.0
 */
final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var self|null
	 */
	private static $instance;

	/**
	 * Runtime dependency evaluator.
	 *
	 * @since 1.0.0
	 * @var Dependencies|null
	 */
	private $dependencies;

	/**
	 * Gets the singleton plugin instance.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers startup hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'plugins_loaded', array( $this, 'boot' ), 20 );
	}

	/**
	 * Boots the requirements experience and conditionally enables features.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function boot() {
		$this->dependencies = Dependencies::from_environment();
		$url_policy         = new URL_Policy();

		$admin_page = new Admin_Page( $this->dependencies, $url_policy );
		$admin_page->register_hooks();

		if ( ! $this->dependencies->are_met() || ! $url_policy->is_ready() ) {
			return;
		}

		$settings      = new Settings();
		$versioned     = new Versioned_Files();
		$products      = new Products( $versioned );
		$package_index = new Package_Index( $settings, $products );
		$download      = new Download(
			$settings,
			$versioned,
			new Authenticator(),
			new Entitlements(),
			new Order_Resolver(),
			url_policy: $url_policy
		);

		$settings->register_hooks();
		$package_index->register_hooks();
		( new File_Version_Field() )->register_hooks();
		( new Router( $package_index, new Responses( $settings, $url_policy ), $download ) )->register_hooks();

		$rest_controller = new Admin_Controller(
			$settings,
			$products,
			array( $admin_page, 'get_management_capability' )
		);
		$rest_controller->register_hooks();

		/**
		 * Fires when every supported runtime dependency is available.
		 *
		 * Feature services must be registered from this gated action.
		 *
		 * @since 1.0.0
		 *
		 * @param Plugin $plugin Main plugin instance.
		 */
		do_action( 'edd_composer_ready', $this );
	}

	/**
	 * Gets the evaluated runtime dependencies after startup.
	 *
	 * @since 1.0.0
	 *
	 * @return Dependencies|null
	 */
	public function get_dependencies() {
		return $this->dependencies;
	}

	/**
	 * Prevents direct construction.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}
}
