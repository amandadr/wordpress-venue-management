<?php
/**
 * Front-end templates and event queries.
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upcoming and past queries, template resolution, and a stylesheet themes can disable.
 */
final class WVM_Templates {

	public function hooks(): void {
		add_filter( 'template_include', array( $this, 'template_include' ), 20 );
		add_filter( 'document_title_parts', array( $this, 'search_title' ), 15 );
		add_filter( 'body_class', array( $this, 'search_body_class' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * @param string               $which       upcoming or past.
	 * @param string|null          $venue_slug  Venue slug, or null for all.
	 * @param string               $search      Search string.
	 */
	public static function query( string $which, ?string $venue_slug = null, string $search = '' ): WP_Query {
		$today = wp_date( 'Y-m-d' );
		$args  = array(
			'post_type'      => WVM_Post_Type::slug(),
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => 'meta_value',
			'meta_key'       => WVM_Meta::key( 'start' ),
			'meta_type'      => 'CHAR',
			'order'          => 'upcoming' === $which ? 'ASC' : 'DESC',
			'meta_query'     => array(
				array(
					'key'     => WVM_Meta::key( 'start' ),
					'value'   => $today,
					'compare' => 'upcoming' === $which ? '>=' : '<',
					'type'    => 'CHAR',
				),
			),
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$venue_slug = null === $venue_slug ? '' : sanitize_title( $venue_slug );
		if ( '' !== $venue_slug && WVM_Venues::by_slug( $venue_slug ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => WVM_Venues::TAXONOMY,
					'field'    => 'slug',
					'terms'    => $venue_slug,
				),
			);
		}

		return new WP_Query( $args );
	}

	/**
	 * Theme template first, then the plugin copy.
	 *
	 * @param string $name archive, single, or event-card.
	 */
	public static function locate( string $name ): string {
		$map = array(
			'archive'    => array( 'venue-event/archive.php', 'templates/archive-venue-event.php' ),
			'single'     => array( 'venue-event/single.php', 'templates/single-venue-event.php' ),
			'event-card' => array( 'venue-event/event-card.php', 'templates/event-card.php' ),
		);

		$candidates = $map[ $name ] ?? array( 'venue-event/' . $name . '.php' );
		$theme      = locate_template( array( $candidates[0] ) );
		if ( $theme ) {
			return $theme;
		}

		$plugin = WVM_PATH . ( $candidates[1] ?? '' );

		return file_exists( $plugin ) ? $plugin : '';
	}

	/**
	 * Use the events archive or single template.
	 *
	 * @param string $template Candidate template path.
	 */
	public function template_include( string $template ): string {
		if ( is_singular( WVM_Post_Type::slug() ) ) {
			$single = self::locate( 'single' );
			return $single ? $single : $template;
		}

		if ( is_post_type_archive( WVM_Post_Type::slug() ) || self::is_events_search() ) {
			$archive = self::locate( 'archive' );
			return $archive ? $archive : $template;
		}

		return $template;
	}

	/**
	 * Whether the current search sits under the events archive.
	 */
	public static function is_events_search(): bool {
		if ( ! is_search() ) {
			return false;
		}

		return self::request_is_under_archive();
	}

	/**
	 * Whether the request path is the archive or a child of it.
	 */
	public static function request_is_under_archive(): bool {
		$request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path    = trim( (string) wp_parse_url( $request, PHP_URL_PATH ), '/' );
		$archive = trim( (string) WVM_Post_Type::archive_slug(), '/' );

		return $path === $archive || str_starts_with( $path, $archive . '/' );
	}

	/**
	 * @param array<string, string> $title Title parts.
	 * @return array<string, string>
	 */
	public function search_title( array $title ): array {
		if ( ! self::is_events_search() ) {
			return $title;
		}

		$query            = get_search_query();
		$title['title']   = '' !== $query
			? sprintf(
				/* translators: %s: search query */
				__( 'Events: %s', 'wordpress-venue-management' ),
				$query
			)
			: __( 'Events', 'wordpress-venue-management' );

		return $title;
	}

	/**
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function search_body_class( array $classes ): array {
		if ( ! self::is_events_search() ) {
			return $classes;
		}

		$classes[] = 'post-type-archive';
		$classes[] = 'post-type-archive-' . WVM_Post_Type::slug();

		return array_values( array_diff( $classes, array( 'search', 'search-results' ) ) );
	}

	/**
	 * Enqueue the events stylesheet on archive, single, and events search.
	 */
	public function enqueue(): void {
		$on_events = is_singular( WVM_Post_Type::slug() ) || is_post_type_archive( WVM_Post_Type::slug() ) || self::is_events_search();
		if ( ! $on_events ) {
			return;
		}

		/**
		 * Whether to enqueue the plugin stylesheet.
		 *
		 * @param bool $enqueue Default true.
		 */
		if ( ! apply_filters( 'wvm_enqueue_styles', true ) ) {
			return;
		}

		wp_enqueue_style( 'wvm-events', WVM_URL . 'assets/venue-events.css', array(), WVM_VERSION );
	}
}
