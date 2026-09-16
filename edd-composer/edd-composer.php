<?php
/**
 * Plugin Name:       EDD Composer Extension
 * Description:       Expose eligible Easy Digital Downloads products as authenticated Composer packages.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.0
 * Text Domain:       edd-composer
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package EDD_Composer
 */

defined( 'ABSPATH' ) || exit;

define( 'EDD_COMPOSER_VERSION', '1.0.0' );
define( 'EDD_COMPOSER_FILE', __FILE__ );
define( 'EDD_COMPOSER_PATH', plugin_dir_path( __FILE__ ) );
define( 'EDD_COMPOSER_URL', plugin_dir_url( __FILE__ ) );

require_once EDD_COMPOSER_PATH . 'autoload.php';

register_activation_hook( EDD_COMPOSER_FILE, array( 'EDD_Composer\\Activator', 'activate' ) );
register_deactivation_hook( EDD_COMPOSER_FILE, array( 'EDD_Composer\\Deactivator', 'deactivate' ) );

EDD_Composer\Plugin::instance()->register_hooks();
