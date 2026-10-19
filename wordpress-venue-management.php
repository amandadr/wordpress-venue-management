<?php
/**
 * Plugin Name: WordPress Venue Management
 * Description: A venue programme of occurrences, series, and recurrence. Each date is its own event. A saved series rule fills the future calendar.
 * Version: 0.2.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author: amandadr
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wordpress-venue-management
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WVM_VERSION', '0.2.0' );
define( 'WVM_FILE', __FILE__ );
define( 'WVM_PATH', plugin_dir_path( __FILE__ ) );
define( 'WVM_URL', plugin_dir_url( __FILE__ ) );

require_once WVM_PATH . 'includes/class-meta.php';
require_once WVM_PATH . 'includes/class-post-type.php';
require_once WVM_PATH . 'includes/class-venues.php';
require_once WVM_PATH . 'includes/class-series.php';
require_once WVM_PATH . 'includes/class-recurrence.php';
require_once WVM_PATH . 'includes/class-feeds.php';
require_once WVM_PATH . 'includes/class-templates.php';
require_once WVM_PATH . 'includes/class-importer.php';
require_once WVM_PATH . 'includes/class-migrator.php';
require_once WVM_PATH . 'includes/class-acf.php';
require_once WVM_PATH . 'includes/class-admin.php';
require_once WVM_PATH . 'includes/class-plugin.php';
require_once WVM_PATH . 'includes/template-tags.php';

register_activation_hook( WVM_FILE, array( 'WVM_Plugin', 'activate' ) );
register_deactivation_hook( WVM_FILE, array( 'WVM_Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		WVM_Plugin::instance()->boot();
	}
);
