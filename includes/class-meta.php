<?php
/**
 * Event meta keys, site-local datetimes, and legacy key fallback.
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes occurrence meta. Old `_grb_event_*` values are used when the new key is empty.
 */
final class WVM_Meta {

	/**
	 * Theme-era keys, keyed by purpose. Read fallback only.
	 *
	 * @var array<string, string>
	 */
	private const LEGACY = array(
		'start'         => '_grb_event_start',
		'end'           => '_grb_event_end',
		'cost'          => '_grb_event_cost',
		'venue_name'    => '_grb_event_venue_name',
		'venue_address' => '_grb_event_venue_address',
		'ticket_url'    => '_grb_event_ticket_url',
		'series'        => '_grb_event_series',
	);

	/**
	 * Meta box hooks.
	 */
	public function hooks(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );
	}

	/**
	 * Purpose => meta key. Filter `wvm_event_meta_keys` to rename storage.
	 *
	 * @return array<string, string>
	 */
	public static function keys(): array {
		$keys = array(
			'start'         => '_venue_event_start',
			'end'           => '_venue_event_end',
			'cost'          => '_venue_event_cost',
			'venue_name'    => '_venue_event_venue_name',
			'venue_address' => '_venue_event_venue_address',
			'ticket_url'    => '_venue_event_ticket_url',
			'series'        => '_venue_event_series',
		);

		/**
		 * Map of purpose to post meta key.
		 *
		 * @param array<string, string> $keys Purposes: start, end, cost, venue_name, venue_address, ticket_url, series.
		 */
		$filtered = apply_filters( 'wvm_event_meta_keys', $keys );

		return is_array( $filtered ) ? $filtered : $keys;
	}

	public static function key( string $purpose ): string {
		$keys = self::keys();

		return (string) ( $keys[ $purpose ] ?? '' );
	}

	/**
	 * Register event meta for the REST API.
	 */
	public static function register(): void {
		foreach ( self::keys() as $purpose => $meta_key ) {
			if ( '' === $meta_key ) {
				continue;
			}

			register_post_meta(
				WVM_Post_Type::slug(),
				$meta_key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ): bool {
						unset( $allowed, $meta_key );

						return current_user_can( 'edit_post', (int) $post_id );
					},
					'sanitize_callback' => 'ticket_url' === $purpose ? 'esc_url_raw' : ( 'venue_address' === $purpose ? 'sanitize_textarea_field' : 'sanitize_text_field' ),
				)
			);
		}
	}

	/**
	 * Meta value for a purpose. Falls back to the theme-era key.
	 */
	public static function get( int $post_id, string $purpose ): string {
		$key = self::key( $purpose );
		if ( '' === $key ) {
			return '';
		}

		$value = get_post_meta( $post_id, $key, true );
		if ( ( '' === $value || false === $value ) && isset( self::LEGACY[ $purpose ] ) ) {
			$value = get_post_meta( $post_id, self::LEGACY[ $purpose ], true );
		}

		if ( is_string( $value ) || is_numeric( $value ) ) {
			return trim( (string) $value );
		}

		return '';
	}

	/**
	 * Write one meta value, or delete it when empty.
	 *
	 * @param int $post_id Event ID.
	 * @param string $purpose Meta purpose.
	 * @param string $value Stored value.
	 */
	public static function update( int $post_id, string $purpose, string $value ): void {
		$key = self::key( $purpose );
		if ( '' === $key ) {
			return;
		}

		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
			return;
		}

		update_post_meta( $post_id, $key, $value );
	}

	/**
	 * Parse a stored `Y-m-d H:i:s` (or date-only) string as site-local time.
	 *
	 * @return int|false Unix timestamp, or false when the string does not parse.
	 */
	public static function parse_local_datetime( string $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return false;
		}

		$tz = wp_timezone();
		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d' ) as $format ) {
			$dt = DateTimeImmutable::createFromFormat( $format, $value, $tz );
			if ( $dt instanceof DateTimeImmutable ) {
				$errors = DateTimeImmutable::getLastErrors();
				if ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
					continue;
				}

				return $dt->getTimestamp();
			}
		}

		return false;
	}

	/**
	 * Normalize a datetime-local or stored value to `Y-m-d H:i:s` in site time.
	 */
	public static function normalize_stored( string $value ): string {
		$ts = self::parse_local_datetime( $value );
		if ( false === $ts ) {
			return '';
		}

		$dt = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( wp_timezone() );

		return $dt->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Start time formatted with the site date and time formats.
	 *
	 * @param int $post_id Event ID.
	 */
	public static function format_start( int $post_id ): string {
		$ts = self::parse_local_datetime( self::get( $post_id, 'start' ) );
		if ( false === $ts ) {
			return '';
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	/**
	 * Cost label, or an empty string when unset.
	 *
	 * @param int $post_id Event ID.
	 */
	public static function cost_display( int $post_id ): string {
		return self::get( $post_id, 'cost' );
	}

	/**
	 * Event details meta box.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'wvm_event_details',
			__( 'Event details', 'wordpress-venue-management' ),
			array( $this, 'render_meta_box' ),
			WVM_Post_Type::slug(),
			'normal',
			'high'
		);
	}

	/**
	 * @param WP_Post $post Event post.
	 */
	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'wvm_event_details', 'wvm_event_details_nonce' );

		$start_ts = self::parse_local_datetime( self::get( $post->ID, 'start' ) );
		$end_ts   = self::parse_local_datetime( self::get( $post->ID, 'end' ) );
		$acf      = WVM_Acf::is_active();
		$venue_id = (int) get_post_meta( $post->ID, '_wvm_venue_term_id', true );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wvm_event_start"><?php esc_html_e( 'Start', 'wordpress-venue-management' ); ?></label></th>
				<td><input type="datetime-local" id="wvm_event_start" name="wvm_event_start" value="<?php echo esc_attr( false !== $start_ts ? wp_date( 'Y-m-d\TH:i', $start_ts ) : '' ); ?>" class="regular-text"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wvm_event_end"><?php esc_html_e( 'End', 'wordpress-venue-management' ); ?></label></th>
				<td>
					<input type="datetime-local" id="wvm_event_end" name="wvm_event_end" value="<?php echo esc_attr( false !== $end_ts ? wp_date( 'Y-m-d\TH:i', $end_ts ) : '' ); ?>" class="regular-text">
					<p class="description"><?php esc_html_e( 'Leave empty for a one-hour event when a series rule copies this template.', 'wordpress-venue-management' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wvm_event_cost"><?php esc_html_e( 'Cost', 'wordpress-venue-management' ); ?></label></th>
				<td><input type="text" id="wvm_event_cost" name="wvm_event_cost" value="<?php echo esc_attr( self::get( $post->ID, 'cost' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Free', 'wordpress-venue-management' ); ?>"></td>
			</tr>
			<?php if ( ! $acf ) : ?>
				<?php WVM_Venues::render_meta_fields( $post->ID, $venue_id ); ?>
				<tr>
					<th scope="row"><label for="wvm_event_ticket_url"><?php esc_html_e( 'Ticket URL', 'wordpress-venue-management' ); ?></label></th>
					<td><input type="url" id="wvm_event_ticket_url" name="wvm_event_ticket_url" value="<?php echo esc_attr( self::get( $post->ID, 'ticket_url' ) ); ?>" class="large-text" placeholder="https://"></td>
				</tr>
			<?php else : ?>
				<tr>
					<td colspan="2"><p class="description"><?php esc_html_e( 'Venue and ticket URL are in Event location & tickets.', 'wordpress-venue-management' ); ?></p></td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_meta_box( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['wvm_event_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wvm_event_details_nonce'] ) ), 'wvm_event_details' ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( $post->post_type !== WVM_Post_Type::slug() ) {
			return;
		}

		$start = isset( $_POST['wvm_event_start'] ) ? self::normalize_stored( sanitize_text_field( wp_unslash( $_POST['wvm_event_start'] ) ) ) : '';
		$end   = isset( $_POST['wvm_event_end'] ) ? self::normalize_stored( sanitize_text_field( wp_unslash( $_POST['wvm_event_end'] ) ) ) : '';
		self::update( $post_id, 'start', $start );
		self::update( $post_id, 'end', $end );
		self::update( $post_id, 'cost', isset( $_POST['wvm_event_cost'] ) ? sanitize_text_field( wp_unslash( $_POST['wvm_event_cost'] ) ) : '' );

		if ( ! WVM_Acf::is_active() ) {
			WVM_Venues::save_meta_fields( $post_id );
			$ticket = isset( $_POST['wvm_event_ticket_url'] ) ? esc_url_raw( wp_unslash( $_POST['wvm_event_ticket_url'] ) ) : '';
			self::update( $post_id, 'ticket_url', $ticket );
		}
	}
}
