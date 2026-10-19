<?php
/**
 * Series recurrence.
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copies a template event forward and keeps a horizon filled from term meta.
 *
 * Rules are weekly, every N weeks, monthly on a day of month, or yearly.
 * Clock time and duration come from the template. A start that already exists in the series is skipped.
 * Intervals of six months or more create one occurrence per run. Shorter rules stop at the horizon (default six months).
 */
final class WVM_Recurrence {

	public const META_CONFIG = '_venue_series_recurrence';

	public const LONG_INTERVAL_MONTHS = 6;

	public function hooks(): void {
		add_action( 'wvm_populate_series', array( $this, 'auto_populate' ) );
	}

	/**
	 * @return array{template_id: int, recurrence: string, n_weeks: int, n_months: int, interval_weeks: int, month_day: int}
	 */
	public static function config( int $term_id ): array {
		$stored = get_term_meta( $term_id, self::META_CONFIG, true );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'template_id'    => (int) ( $stored['template_id'] ?? 0 ),
			'recurrence'     => (string) ( $stored['recurrence'] ?? 'weekly' ),
			'n_weeks'        => max( 1, min( 26, (int) ( $stored['n_weeks'] ?? 12 ) ) ),
			'n_months'       => max( 1, min( 6, (int) ( $stored['n_months'] ?? 6 ) ) ),
			'interval_weeks' => max( 1, min( 12, (int) ( $stored['interval_weeks'] ?? 2 ) ) ),
			'month_day'      => max( 1, min( 31, (int) ( $stored['month_day'] ?? 1 ) ) ),
		);
	}

	/**
	 * @param array{template_id: int, recurrence: string, n_weeks: int, n_months: int, interval_weeks: int, month_day: int} $config Config.
	 */
	public static function save_config( int $term_id, array $config ): void {
		update_term_meta(
			$term_id,
			self::META_CONFIG,
			array(
				'template_id'    => (int) $config['template_id'],
				'recurrence'     => (string) $config['recurrence'],
				'n_weeks'        => max( 1, min( 26, (int) $config['n_weeks'] ) ),
				'n_months'       => max( 1, min( 6, (int) $config['n_months'] ) ),
				'interval_weeks' => max( 1, min( 12, (int) $config['interval_weeks'] ) ),
				'month_day'      => max( 1, min( 31, (int) $config['month_day'] ) ),
			)
		);
	}

	/**
	 * @return array{created: int, errors: string[]}
	 */
	public function generate( int $series_term_id, int $template_id, string $recurrence, int $n_weeks, int $n_months, int $interval_weeks, int $month_day, string $start_from_date ): array {
		$template = get_post( $template_id );
		if ( ! $template instanceof WP_Post || $template->post_type !== WVM_Post_Type::slug() ) {
			return array(
				'created' => 0,
				'errors'  => array( __( 'Invalid template event.', 'wordpress-venue-management' ) ),
			);
		}

		$term        = get_term( $series_term_id, WVM_Series::TAXONOMY );
		$series_name = $term instanceof WP_Term ? $term->name : '';
		$tz          = wp_timezone();
		$template_dt = $this->template_start( $template_id, $tz );
		$duration    = $this->duration_seconds( $template_id, $template_dt );

		if ( '' !== $start_from_date ) {
			$cursor = DateTimeImmutable::createFromFormat( 'Y-m-d', $start_from_date, $tz );
			if ( ! $cursor instanceof DateTimeImmutable ) {
				return array(
					'created' => 0,
					'errors'  => array( __( 'Invalid start date.', 'wordpress-venue-management' ) ),
				);
			}
			$cursor = $cursor->setTime( (int) $template_dt->format( 'H' ), (int) $template_dt->format( 'i' ), (int) $template_dt->format( 's' ) );
		} else {
			$cursor = $template_dt;
		}

		$n_weeks        = max( 1, min( 26, $n_weeks ) );
		$n_months       = max( 1, min( 6, $n_months ) );
		$interval_weeks = max( 1, min( 12, $interval_weeks ) );
		$month_day      = max( 1, min( 31, $month_day ) );

		$horizon_months = (int) apply_filters( 'wvm_recurrence_horizon_months', 6 );
		$horizon_months = max( 1, $horizon_months );
		$now            = new DateTimeImmutable( 'now', $tz );
		$horizon        = $now->modify( '+' . $horizon_months . ' months' );
		$limit          = $this->window_end( $cursor, $recurrence, $n_weeks, $n_months, $interval_weeks, $month_day, $horizon );

		$existing = $this->existing_starts( $series_term_id );
		$created  = 0;
		$errors   = array();

		for ( $i = 0; $i < 100; $i++ ) {
			if ( $cursor >= $limit ) {
				break;
			}

			$start_str = $cursor->format( 'Y-m-d H:i:s' );
			$end_dt    = $cursor->modify( '+' . $duration . ' seconds' );
			if ( ! isset( $existing[ $start_str ] ) ) {
				$result = $this->insert_occurrence( $template, $series_term_id, $series_name, $cursor, $end_dt, $start_str );
				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				} elseif ( $result > 0 ) {
					$existing[ $start_str ] = true;
					++$created;
				}
			}

			$next = self::advance( $cursor, $recurrence, $interval_weeks, $month_day );
			if ( $next <= $cursor ) {
				break;
			}
			$cursor = $next;
		}

		return array(
			'created' => $created,
			'errors'  => $errors,
		);
	}

	/**
	 * Extend every series that has a saved rule.
	 *
	 * @return array{created: int, series_processed: int}
	 */
	public function auto_populate(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => WVM_Series::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array(
				'created'          => 0,
				'series_processed' => 0,
			);
		}

		$total     = 0;
		$processed = 0;
		$tz        = wp_timezone();

		foreach ( $terms as $term ) {
			$config = self::config( (int) $term->term_id );
			if ( $config['template_id'] < 1 || '' === $config['recurrence'] ) {
				continue;
			}

			$template = get_post( $config['template_id'] );
			if ( ! $template instanceof WP_Post || $template->post_type !== WVM_Post_Type::slug() ) {
				continue;
			}

			$last = $this->latest_start( (int) $term->term_id, $tz );
			if ( ! $last instanceof DateTimeImmutable ) {
				continue;
			}

			$next = self::advance( $last, $config['recurrence'], $config['interval_weeks'], $config['month_day'] );
			$result = $this->generate(
				(int) $term->term_id,
				$config['template_id'],
				$config['recurrence'],
				$config['n_weeks'],
				$config['n_months'],
				$config['interval_weeks'],
				$config['month_day'],
				$next->format( 'Y-m-d' )
			);
			$total += $result['created'];
			++$processed;
		}

		/**
		 * After a manual or cron populate.
		 *
		 * @param int $total     Occurrences created.
		 * @param int $processed Series that had a rule.
		 */
		do_action( 'wvm_series_populated', $total, $processed );

		return array(
			'created'          => $total,
			'series_processed' => $processed,
		);
	}

	/**
	 * Next occurrence, keeping the clock time and clamping the day to the month length.
	 */
	public static function advance( DateTimeImmutable $cursor, string $recurrence, int $interval_weeks, int $month_day ): DateTimeImmutable {
		$hour   = (int) $cursor->format( 'H' );
		$minute = (int) $cursor->format( 'i' );
		$second = (int) $cursor->format( 's' );

		if ( 'weekly' === $recurrence ) {
			return $cursor->modify( '+1 week' );
		}

		if ( 'every_n_weeks' === $recurrence ) {
			$n = max( 1, min( 12, $interval_weeks ) );

			return $cursor->modify( '+' . $n . ' weeks' );
		}

		if ( 'yearly' === $recurrence ) {
			$year  = (int) $cursor->format( 'Y' ) + 1;
			$month = (int) $cursor->format( 'm' );
			$probe = $cursor->setDate( $year, $month, 1 );
			$day   = min( max( 1, $month_day ), (int) $probe->format( 't' ) );

			return $probe->setDate( $year, $month, $day )->setTime( $hour, $minute, $second );
		}

		$next = $cursor->modify( 'first day of next month' );
		$day  = min( max( 1, $month_day ), (int) $next->format( 't' ) );

		return $next->setDate( (int) $next->format( 'Y' ), (int) $next->format( 'm' ), $day )->setTime( $hour, $minute, $second );
	}

	private function template_start( int $template_id, DateTimeZone $tz ): DateTimeImmutable {
		$ts = WVM_Meta::parse_local_datetime( WVM_Meta::get( $template_id, 'start' ) );
		if ( false === $ts ) {
			return new DateTimeImmutable( 'now', $tz );
		}

		return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
	}

	private function duration_seconds( int $template_id, DateTimeImmutable $start ): int {
		$end_ts = WVM_Meta::parse_local_datetime( WVM_Meta::get( $template_id, 'end' ) );
		if ( false === $end_ts ) {
			return 3600;
		}

		$end = ( new DateTimeImmutable( '@' . $end_ts ) )->setTimezone( $start->getTimezone() );
		$diff = $end->getTimestamp() - $start->getTimestamp();

		return $diff > 0 ? $diff : 3600;
	}

	private function window_end( DateTimeImmutable $cursor, string $recurrence, int $n_weeks, int $n_months, int $interval_weeks, int $month_day, DateTimeImmutable $horizon ): DateTimeImmutable {
		$interval_months = 0.25;
		if ( 'every_n_weeks' === $recurrence ) {
			$interval_months = $interval_weeks * 7 / 30.44;
		} elseif ( 'monthly' === $recurrence ) {
			$interval_months = 1;
		} elseif ( 'yearly' === $recurrence ) {
			$interval_months = 12;
		}

		if ( $interval_months >= self::LONG_INTERVAL_MONTHS ) {
			return self::advance( $cursor, $recurrence, $interval_weeks, $month_day )->modify( '+1 second' );
		}

		if ( 'monthly' === $recurrence ) {
			$limit = $cursor->modify( '+' . $n_months . ' months' );
		} elseif ( 'weekly' === $recurrence || 'every_n_weeks' === $recurrence ) {
			$limit = $cursor->modify( '+' . $n_weeks . ' weeks' );
		} else {
			$limit = $cursor->modify( '+1 year' );
		}

		return $limit < $horizon ? $limit : $horizon;
	}

	/**
	 * @return array<string, true>
	 */
	private function existing_starts( int $series_term_id ): array {
		$starts = array();
		foreach ( $this->event_ids( $series_term_id ) as $post_id ) {
			$start = WVM_Meta::get( $post_id, 'start' );
			if ( '' !== $start ) {
				$starts[ substr( $start, 0, 19 ) ] = true;
			}
		}

		return $starts;
	}

	private function latest_start( int $series_term_id, DateTimeZone $tz ): ?DateTimeImmutable {
		$latest = null;
		foreach ( $this->event_ids( $series_term_id ) as $post_id ) {
			$ts = WVM_Meta::parse_local_datetime( WVM_Meta::get( $post_id, 'start' ) );
			if ( false === $ts ) {
				continue;
			}
			$dt = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
			if ( null === $latest || $dt > $latest ) {
				$latest = $dt;
			}
		}

		return $latest;
	}

	/**
	 * @return int[]
	 */
	private function event_ids( int $series_term_id ): array {
		$ids = get_posts(
			array(
				'post_type'      => WVM_Post_Type::slug(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => WVM_Series::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => $series_term_id,
					),
				),
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * @return int|WP_Error New post ID.
	 */
	private function insert_occurrence( WP_Post $template, int $series_term_id, string $series_name, DateTimeImmutable $start, DateTimeImmutable $end, string $start_str ) {
		$status = apply_filters( 'wvm_generated_event_status', 'publish', $template, $series_term_id );
		$status = is_string( $status ) && '' !== $status ? $status : 'publish';

		$postarr = array(
			'post_type'    => WVM_Post_Type::slug(),
			'post_status'  => $status,
			'post_title'   => $template->post_title,
			'post_content' => $template->post_content,
			'post_excerpt' => $template->post_excerpt,
			'post_name'    => sanitize_title( $series_name ) . '-' . $start->format( 'Y-m-d' ) . '-' . $start->getTimestamp(),
		);

		/**
		 * wp_insert_post() arguments for one generated occurrence.
		 *
		 * @param array<string, mixed> $postarr          Post array.
		 * @param WP_Post              $template         Template event.
		 * @param int                  $series_term_id   Series term ID.
		 */
		$postarr = apply_filters( 'wvm_generated_event_postarr', $postarr, $template, $series_term_id );
		if ( ! is_array( $postarr ) ) {
			return new WP_Error( 'wvm_postarr', __( 'Generated event was rejected.', 'wordpress-venue-management' ) );
		}

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return is_wp_error( $post_id ) ? $post_id : new WP_Error( 'wvm_insert', __( 'Could not create the event.', 'wordpress-venue-management' ) );
		}

		$post_id = (int) $post_id;
		wp_set_object_terms( $post_id, array( $series_term_id ), WVM_Series::TAXONOMY, false );
		WVM_Meta::update( $post_id, 'start', $start_str );
		WVM_Meta::update( $post_id, 'end', $end->format( 'Y-m-d H:i:s' ) );
		WVM_Meta::update( $post_id, 'series', $series_name );
		WVM_Meta::update( $post_id, 'cost', WVM_Meta::get( $template->ID, 'cost' ) );
		WVM_Meta::update( $post_id, 'venue_name', WVM_Meta::get( $template->ID, 'venue_name' ) );
		WVM_Meta::update( $post_id, 'venue_address', WVM_Meta::get( $template->ID, 'venue_address' ) );
		WVM_Meta::update( $post_id, 'ticket_url', WVM_Meta::get( $template->ID, 'ticket_url' ) );

		$venue_term = (int) get_post_meta( $template->ID, '_wvm_venue_term_id', true );
		if ( $venue_term > 0 ) {
			WVM_Venues::assign( $post_id, $venue_term );
		}

		$thumb = (int) get_post_thumbnail_id( $template->ID );
		if ( $thumb > 0 ) {
			set_post_thumbnail( $post_id, $thumb );
		}

		/**
		 * A series occurrence was created.
		 *
		 * @param int $post_id        New event ID.
		 * @param int $series_term_id Series term ID.
		 * @param int $template_id    Template event ID.
		 */
		do_action( 'wvm_event_generated', $post_id, $series_term_id, $template->ID );

		return $post_id;
	}
}
