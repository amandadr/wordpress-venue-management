<?php
/**
 * Bootstrap.
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the programme modules and the weekly populate cron.
 */
final class WVM_Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register types and flush rewrites. Schedules the weekly series populate.
	 */
	public static function activate(): void {
		self::register_types();
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( 'wvm_populate_series' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', 'wvm_populate_series' );
		}
	}

	/**
	 * Clear the populate cron and flush rewrites. Events, series, and venues stay.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'wvm_populate_series' );
		flush_rewrite_rules();
	}

	public function boot(): void {
		add_action( 'init', array( self::class, 'register_types' ), 5 );
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'admin_init', array( 'WVM_Venues', 'maybe_flush_rewrites' ) );
		add_action( 'admin_notices', array( $this, 'theme_programme_notice' ) );

		( new WVM_Post_Type() )->hooks();
		( new WVM_Meta() )->hooks();
		( new WVM_Venues() )->hooks();
		( new WVM_Series() )->hooks();
		( new WVM_Recurrence() )->hooks();
		( new WVM_Feeds() )->hooks();
		( new WVM_Templates() )->hooks();
		( new WVM_Importer() )->hooks();
		( new WVM_Migrator() )->hooks();
		( new WVM_Acf() )->hooks();
		( new WVM_Admin() )->hooks();
	}

	/**
	 * Load the plugin translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wordpress-venue-management', false, dirname( plugin_basename( WVM_FILE ) ) . '/languages' );
	}

	/**
	 * Post type and both taxonomies. Safe to call from activation before init.
	 */
	public static function register_types(): void {
		WVM_Post_Type::register();
		WVM_Venues::register();
		WVM_Series::register();
		WVM_Meta::register();
		WVM_Venues::register_rewrites();
	}

	/**
	 * The reference theme still registers grb_event at /events/. Say so while both are loaded.
	 */
	public function theme_programme_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) || ! post_type_exists( 'grb_event' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$slug   = WVM_Post_Type::slug();
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'edit-' . $slug, $slug ), true ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'WordPress Venue Management is active, and the theme still registers its own events archive at /events/. Public URLs will not belong to this plugin until that theme loader is removed. Events → Migrate legacy programme can copy the old events first.', 'wordpress-venue-management' );
		echo '</p></div>';
	}
}
