<?php
/**
 * Plugin deactivation lifecycle.
 *
 * @package EDD_Composer
 */

namespace EDD_Composer;

use EDD_Composer\Repository\Router;

defined( 'ABSPATH' ) || exit;

/**
 * Removes plugin rewrite rules without deleting product settings.
 *
 * @since 1.0.0
 */
final class Deactivator {
	/**
	 * Runs plugin deactivation tasks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function deactivate() {
		global $wp_rewrite;

		if ( isset( $wp_rewrite->extra_rules_top ) && is_array( $wp_rewrite->extra_rules_top ) ) {
			unset(
				$wp_rewrite->extra_rules_top['^composer/?$'],
				$wp_rewrite->extra_rules_top['^composer/packages\.json$'],
				$wp_rewrite->extra_rules_top['^composer/download/([^/]+)/([^/]+)/?$']
			);
		}

		delete_option( Router::REWRITE_VERSION_OPTION );
		flush_rewrite_rules( false );
	}
}
