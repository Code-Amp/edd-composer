<?php
/**
 * Plugin activation lifecycle.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer;

use EDD_Composer\Repository\Router;
use EDD_Composer\Repository\URL_Policy;

defined( 'ABSPATH' ) || exit;

/**
 * Installs rewrite rules when supported dependencies are active.
 *
 * @since 1.0.0
 */
final class Activator {
	/**
	 * Runs plugin activation tasks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function activate() {
		if ( ! Dependencies::from_environment()->are_met() || ! ( new URL_Policy() )->is_ready() ) {
			return;
		}

		Router::register_rewrite_rules();
		flush_rewrite_rules( false );
		update_option( Router::REWRITE_VERSION_OPTION, Router::REWRITE_VERSION, false );
	}
}
