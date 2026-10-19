<?php
/**
 * Event post type.
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers `venue_event` and sorts the admin list by start time.
 */
final class WVM_Post_Type {

	public function hooks(): void {
		add_action( 'init', array( $this, 'register_admin_columns' ), 11 );
		add_action( 'pre_get_posts', array( $this, 'admin_sort' ) );
		add_filter( 'posts_clauses', array( $this, 'admin_sort_clauses' ), 10, 2 );
	}

	/**
	 * Column hooks need the filtered post type slug, which is final after init priority 5.
	 */
	public function register_admin_columns(): void {
		$slug = self::slug();
		add_filter( 'manage_' . $slug . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . $slug . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_filter( 'manage_edit-' . $slug . '_sortable_columns', array( $this, 'sortable' ) );
	}

	/**
	 * Post type slug. Filter `wvm_post_type` before init.
	 */
	public static function slug(): string {
		/**
		 * Post type slug.
		 *
		 * @param string $slug Default venue_event.
		 */
		$slug = apply_filters( 'wvm_post_type', 'venue_event' );
		$slug = is_string( $slug ) ? sanitize_key( $slug ) : 'venue_event';

		return '' !== $slug ? $slug : 'venue_event';
	}

	/**
	 * Archive base. Filter `wvm_archive_slug`.
	 */
	public static function archive_slug(): string {
		/**
		 * Archive rewrite slug.
		 *
		 * @param string $slug Default events.
		 */
		$slug = apply_filters( 'wvm_archive_slug', 'events' );
		$slug = is_string( $slug ) ? sanitize_title( $slug ) : 'events';

		return '' !== $slug ? $slug : 'events';
	}

	public static function register(): void {
		$slug = self::slug();

		register_post_type(
			$slug,
			array(
				'labels'             => array(
					'name'               => _x( 'Events', 'post type general name', 'wordpress-venue-management' ),
					'singular_name'      => _x( 'Event', 'post type singular name', 'wordpress-venue-management' ),
					'menu_name'          => _x( 'Events', 'admin menu', 'wordpress-venue-management' ),
					'add_new_item'       => __( 'Add New Event', 'wordpress-venue-management' ),
					'edit_item'          => __( 'Edit Event', 'wordpress-venue-management' ),
					'new_item'           => __( 'New Event', 'wordpress-venue-management' ),
					'view_item'          => __( 'View Event', 'wordpress-venue-management' ),
					'search_items'       => __( 'Search Events', 'wordpress-venue-management' ),
					'not_found'          => __( 'No events found.', 'wordpress-venue-management' ),
					'not_found_in_trash' => __( 'No events found in Trash.', 'wordpress-venue-management' ),
					'all_items'          => __( 'All Events', 'wordpress-venue-management' ),
				),
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'menu_icon'          => 'dashicons-calendar-alt',
				'menu_position'      => 6,
				'query_var'          => true,
				'rewrite'            => array(
					'slug'       => self::archive_slug(),
					'with_front' => false,
				),
				'capability_type'    => 'post',
				'has_archive'        => true,
				'hierarchical'       => false,
				'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ),
				'show_in_rest'       => true,
			)
		);
	}

	/**
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$new['wvm_event_date'] = _x( 'Event date', 'column name', 'wordpress-venue-management' );
			}
			$new[ $key ] = $label;
		}

		return $new;
	}

	/**
	 * Date, venue, and series cells on the events list.
	 *
	 * @param string $column Column key.
	 * @param int $post_id Event ID.
	 */
	public function column_content( string $column, int $post_id ): void {
		if ( 'wvm_event_date' !== $column ) {
			return;
		}

		$label = WVM_Meta::format_start( $post_id );
		echo $label ? esc_html( $label ) : '—';
	}

	/**
	 * @param array<string, string> $columns Sortable columns.
	 * @return array<string, string>
	 */
	public function sortable( array $columns ): array {
		$columns['wvm_event_date'] = 'wvm_event_date';

		return $columns;
	}

	public function admin_sort( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || $query->get( 'post_type' ) !== self::slug() ) {
			return;
		}

		$orderby = $query->get( 'orderby' );
		if ( '' === $orderby || 'date' === $orderby || 'wvm_event_date' === $orderby ) {
			$query->set( 'orderby', 'wvm_event_date' );
			if ( ! $query->get( 'order' ) ) {
				$query->set( 'order', 'DESC' );
			}
		}
	}

	/**
	 * Left join so events with no start still appear.
	 *
	 * @param array<string, string> $clauses Query clauses.
	 * @param WP_Query              $query   Query.
	 * @return array<string, string>
	 */
	public function admin_sort_clauses( array $clauses, WP_Query $query ): array {
		if ( ! is_admin() || ! $query->is_main_query() || 'wvm_event_date' !== $query->get( 'orderby' ) ) {
			return $clauses;
		}

		global $wpdb;

		$alias             = 'wvm_event_start_meta';
		$clauses['join']  .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} AS {$alias} ON ({$wpdb->posts}.ID = {$alias}.post_id AND {$alias}.meta_key = %s) ", WVM_Meta::key( 'start' ) );
		$order             = 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC';
		$clauses['orderby'] = "CAST({$alias}.meta_value AS DATETIME) {$order}, {$wpdb->posts}.ID DESC";

		return $clauses;
	}
}
