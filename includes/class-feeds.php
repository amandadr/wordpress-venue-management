<?php
/**
 * ICS, Google Calendar, and Event JSON-LD.
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calendar feeds for a published event.
 */
final class WVM_Feeds {

	public function hooks(): void {
		add_action( 'template_redirect', array( $this, 'serve_ics' ), 1 );
		add_action( 'wp_head', array( $this, 'schema' ), 5 );
	}

	public static function google_url( int $post_id ): string {
		$start_ts = WVM_Meta::parse_local_datetime( WVM_Meta::get( $post_id, 'start' ) );
		if ( false === $start_ts ) {
			return '';
		}

		$end_ts = WVM_Meta::parse_local_datetime( WVM_Meta::get( $post_id, 'end' ) );
		if ( false === $end_ts ) {
			$end_ts = $start_ts + 3600;
		}

		return 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . rawurlencode( get_the_title( $post_id ) )
			. '&dates=' . gmdate( 'Ymd\THis\Z', $start_ts ) . '/' . gmdate( 'Ymd\THis\Z', $end_ts )
			. '&details=' . rawurlencode( wp_strip_all_tags( get_the_excerpt( $post_id ) ) )
			. '&location=' . rawurlencode( WVM_Meta::get( $post_id, 'venue_address' ) );
	}

	/**
	 * Download URL for this event’s iCalendar file.
	 *
	 * @param int $post_id Event ID.
	 */
	public static function ics_url( int $post_id ): string {
		return add_query_arg( 'wvm_ics', $post_id, get_permalink( $post_id ) );
	}

	/**
	 * Send the iCalendar file when wvm_ics is requested.
	 */
	public function serve_ics(): void {
		$post_id = isset( $_GET['wvm_ics'] ) ? absint( $_GET['wvm_ics'] ) : 0;
		if ( $post_id < 1 || get_post_type( $post_id ) !== WVM_Post_Type::slug() || 'publish' !== get_post_status( $post_id ) ) {
			return;
		}

		$start_ts = WVM_Meta::parse_local_datetime( WVM_Meta::get( $post_id, 'start' ) );
		if ( false === $start_ts ) {
			return;
		}

		$end_ts = WVM_Meta::parse_local_datetime( WVM_Meta::get( $post_id, 'end' ) );
		if ( false === $end_ts ) {
			$end_ts = $start_ts + 3600;
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$ics  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//WordPress Venue Management//Events//EN\r\nBEGIN:VEVENT\r\n";
		$ics .= 'UID:wvm-event-' . $post_id . '-' . $start_ts . '@' . $host . "\r\n";
		$ics .= 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ) . "\r\n";
		$ics .= 'DTSTART:' . gmdate( 'Ymd\THis\Z', $start_ts ) . "\r\n";
		$ics .= 'DTEND:' . gmdate( 'Ymd\THis\Z', $end_ts ) . "\r\n";
		$ics .= 'SUMMARY:' . self::escape_ics( get_the_title( $post_id ) ) . "\r\n";

		$description = wp_strip_all_tags( get_the_excerpt( $post_id ) );
		if ( '' !== $description ) {
			$ics .= 'DESCRIPTION:' . self::escape_ics( $description ) . "\r\n";
		}

		$location = WVM_Meta::get( $post_id, 'venue_address' );
		if ( '' !== $location ) {
			$ics .= 'LOCATION:' . self::escape_ics( $location ) . "\r\n";
		}

		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			$ics .= 'URL:' . $permalink . "\r\n";
		}

		$ics .= "END:VEVENT\r\nEND:VCALENDAR\r\n";

		/**
		 * iCalendar document for one event.
		 *
		 * @param string $ics     ICS body.
		 * @param int    $post_id Event ID.
		 */
		$ics = apply_filters( 'wvm_ics', $ics, $post_id );
		if ( ! is_string( $ics ) || '' === $ics ) {
			return;
		}

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="event-' . $post_id . '.ics"' );
		echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar payload.
		exit;
	}

	/**
	 * Print Event JSON-LD on a single event.
	 */
	public function schema(): void {
		if ( ! is_singular( WVM_Post_Type::slug() ) ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Event',
			'name'        => get_the_title( $post_id ),
			'url'         => get_permalink( $post_id ),
			'description' => wp_trim_words( get_the_excerpt( $post_id ), 30 ),
		);

		$start_ts = WVM_Meta::parse_local_datetime( WVM_Meta::get( $post_id, 'start' ) );
		if ( false !== $start_ts ) {
			$schema['startDate'] = wp_date( 'c', $start_ts );
		}

		$end_ts = WVM_Meta::parse_local_datetime( WVM_Meta::get( $post_id, 'end' ) );
		if ( false !== $end_ts ) {
			$schema['endDate'] = wp_date( 'c', $end_ts );
		}

		$venue   = WVM_Meta::get( $post_id, 'venue_name' );
		$address = WVM_Meta::get( $post_id, 'venue_address' );
		if ( '' !== $venue || '' !== $address ) {
			$schema['location'] = array( '@type' => 'Place' );
			if ( '' !== $venue ) {
				$schema['location']['name'] = $venue;
			}
			if ( '' !== $address ) {
				$schema['location']['address'] = array(
					'@type'         => 'PostalAddress',
					'streetAddress' => $address,
				);
			}
		}

		$image_id = WVM_Series::display_image_id( $post_id );
		if ( $image_id > 0 ) {
			$img = wp_get_attachment_image_src( $image_id, 'full' );
			if ( ! empty( $img[0] ) ) {
				$schema['image'] = $img[0];
			}
		}

		/**
		 * Schema.org Event graph before it is printed.
		 *
		 * @param array<string, mixed> $schema  JSON-LD.
		 * @param int                  $post_id Event ID.
		 */
		$schema = apply_filters( 'wvm_schema', $schema, $post_id );
		if ( ! is_array( $schema ) || count( $schema ) < 3 ) {
			return;
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . "</script>\n";
	}

	/**
	 * Escape a value for an iCalendar text property.
	 *
	 * @param string $value Stored value.
	 */
	public static function escape_ics( string $value ): string {
		return str_replace( array( "\r", "\n", ',', ';' ), array( '', '\\n', '\\,', '\\;' ), $value );
	}
}
