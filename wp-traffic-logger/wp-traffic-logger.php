<?php
/**
 * Plugin Name: WP Traffic Logger
 * Description: Logs public, REST, and AJAX traffic to rotating files and exposes a dashboard viewer for admins.
 * Version: 0.2.0
 * Author: Damador
 * License: GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 7.2
 * Text Domain: wp-traffic-logger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WTL_VERSION', '0.2.0' );
define( 'WTL_PLUGIN_FILE', __FILE__ );
define( 'WTL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WTL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WTL_PLUGIN_DIR . 'includes/class-wtl-plugin.php';
require_once WTL_PLUGIN_DIR . 'includes/class-wtl-request-capture.php';
require_once WTL_PLUGIN_DIR . 'includes/class-wtl-log-writer.php';
require_once WTL_PLUGIN_DIR . 'includes/class-wtl-retention.php';
require_once WTL_PLUGIN_DIR . 'includes/class-wtl-log-reader.php';
require_once WTL_PLUGIN_DIR . 'includes/class-wtl-admin-page.php';

register_activation_hook( __FILE__, array( 'WTL_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WTL_Plugin', 'deactivate' ) );

WTL_Plugin::bootstrap();
