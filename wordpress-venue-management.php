<?php
/**
 * Plugin Name: WordPress Venue Management
 * Description: A venue programme of occurrences, series, and recurrence. Public while it is extracted from theme code. This build does not register events yet — follow the repository README.
 * Version: 0.1.0
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

define( 'WVM_VERSION', '0.1.0' );
define( 'WVM_FILE', __FILE__ );
define( 'WVM_PATH', plugin_dir_path( __FILE__ ) );
define( 'WVM_URL', plugin_dir_url( __FILE__ ) );
