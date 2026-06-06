<?php
/**
 * Plugin Name:       MrMurphy Apps
 * Plugin URI:        https://github.com/mrmurphy/mrmurphy-apps
 * Description:       Host static HTML/JS/CSS apps at /apps/{slug} with visit tracking.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Murphy Randle
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mrmurphy-apps
 *
 * @package MrMurphyApps
 */

defined( 'ABSPATH' ) || exit;

define( 'MRMURPHY_APPS_VERSION', '1.0.0' );
define( 'MRMURPHY_APPS_FILE', __FILE__ );
define( 'MRMURPHY_APPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'MRMURPHY_APPS_URL', plugin_dir_url( __FILE__ ) );
define( 'MRMURPHY_APPS_META_ENTRY', '_mrmurphy_app_entry' );
define( 'MRMURPHY_APPS_ROUTE_PREFIX', 'apps' );

require_once MRMURPHY_APPS_DIR . 'inc/class-plugin.php';

/**
 * Plugin activation.
 */
function mrmurphy_apps_activate() {
	MRMurphy_Apps_Plugin::activate();
}

/**
 * Plugin deactivation.
 */
function mrmurphy_apps_deactivate() {
	MRMurphy_Apps_Plugin::deactivate();
}

register_activation_hook( __FILE__, 'mrmurphy_apps_activate' );
register_deactivation_hook( __FILE__, 'mrmurphy_apps_deactivate' );

MRMurphy_Apps_Plugin::instance();
