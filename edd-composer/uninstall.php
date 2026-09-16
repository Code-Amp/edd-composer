<?php
/**
 * EDD Composer Extension uninstall cleanup.
 *
 * @package EDD_Composer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'edd_composer_settings' );
delete_option( 'edd_composer_cache_version' );
delete_option( 'edd_composer_rewrite_version' );
delete_transient( 'edd_composer_package_index' );
