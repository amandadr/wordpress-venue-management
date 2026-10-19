<?php
/**
 * Uninstall. Events, series, and venues are content and are left in the database.
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'wvm_populate_series' );
delete_option( 'wvm_flush_rewrites' );
