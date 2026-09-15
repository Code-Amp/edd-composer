<?php
/**
 * Main plugin coordinator.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer;

use EDD_Composer\Admin\Admin_Page;

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

		$admin_page = new Admin_Page( $this->dependencies );
		$admin_page->register_hooks();

		if ( ! $this->dependencies->are_met() ) {
			return;
		}

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
