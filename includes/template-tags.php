<?php
/**
 * Template tags for themes.
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upcoming events. Pass a venue slug to limit the list.
 */
function wvm_get_upcoming_events( ?string $venue_slug = null, string $search = '' ): WP_Query {
	return WVM_Templates::query( 'upcoming', $venue_slug, $search );
}

/**
 * Past events, newest first.
 */
function wvm_get_past_events( ?string $venue_slug = null, string $search = '' ): WP_Query {
	return WVM_Templates::query( 'past', $venue_slug, $search );
}

/**
 * Print one event card. Themes can replace templates/event-card.php with venue-event/event-card.php.
 */
function wvm_render_event_card( int $post_id ): void {
	$template = WVM_Templates::locate( 'event-card' );
	if ( '' === $template ) {
		return;
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- card template tags read the global post.
	setup_postdata( $post );
	include $template;
	wp_reset_postdata();
}
