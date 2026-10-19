<?php
/**
 * Copy grb_event posts and grb_series terms into this plugin. Originals are left in place.
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time copier for the theme programme.
 */
final class WVM_Migrator {

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_wvm_migrate_legacy', array( $this, 'handle' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . WVM_Post_Type::slug(),
			__( 'Migrate legacy programme', 'wordpress-venue-management' ),
			__( 'Migrate legacy programme', 'wordpress-venue-management' ),
			'manage_options',
			'wvm-migrate',
			array( $this, 'render' )
		);
	}

	/**
	 * Legacy copy screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = isset( $_GET['wvm_migrated'] ) ? sanitize_text_field( wp_unslash( $_GET['wvm_migrated'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Migrate legacy programme', 'wordpress-venue-management' ); ?></h1>
			<p><?php esc_html_e( 'Copies grb_event posts and grb_series terms onto venue_event and venue_series. Existing events with the same slug are skipped. The theme posts are not deleted or edited.', 'wordpress-venue-management' ); ?></p>
			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! post_type_exists( 'grb_event' ) ) : ?>
				<p><?php esc_html_e( 'No grb_event post type is registered in this request. If the theme programme is disabled, its rows are still in the database and this tool will read them.', 'wordpress-venue-management' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wvm_migrate_legacy' ); ?>
				<input type="hidden" name="action" value="wvm_migrate_legacy">
				<?php submit_button( __( 'Copy legacy events', 'wordpress-venue-management' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Copy grb_event posts and grb_series terms.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot migrate events.', 'wordpress-venue-management' ) );
		}

		check_admin_referer( 'wvm_migrate_legacy' );

		$result = $this->copy();
		wp_safe_redirect(
			add_query_arg(
				'wvm_migrated',
				sprintf(
						/* translators: 1: events copied, 2: events skipped, 3: series copied */
						__( 'Copied %1$d events, skipped %2$d that already had the same slug, and ensured %3$d series.', 'wordpress-venue-management' ),
						$result['events'],
						$result['skipped'],
						$result['series']
					),
				admin_url( 'edit.php?post_type=' . WVM_Post_Type::slug() . '&page=wvm-migrate' )
			)
		);
		exit;
	}

	/**
	 * @return array{events: int, skipped: int, series: int}
	 */
	public function copy(): array {
		$series_map = $this->copy_series();
		$ids        = get_posts(
			array(
				'post_type'      => 'grb_event',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$copied    = 0;
		$skipped   = 0;
		$post_map  = array();
		foreach ( $ids as $old_id ) {
			$old_id = (int) $old_id;
			$old    = get_post( $old_id );
			if ( ! $old instanceof WP_Post ) {
				continue;
			}

			$existing = get_posts(
				array(
					'name'           => $old->post_name,
					'post_type'      => WVM_Post_Type::slug(),
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( $existing ) {
				++$skipped;
				continue;
			}

			$new_id = wp_insert_post(
				array(
					'post_type'    => WVM_Post_Type::slug(),
					'post_status'  => $old->post_status,
					'post_title'   => $old->post_title,
					'post_content' => $old->post_content,
					'post_excerpt' => $old->post_excerpt,
					'post_name'    => $old->post_name,
				),
				true
			);
			if ( is_wp_error( $new_id ) || ! $new_id ) {
				continue;
			}

			$new_id = (int) $new_id;
			$post_map[ $old_id ] = $new_id;
			foreach ( array( 'start', 'end', 'cost', 'venue_name', 'venue_address', 'ticket_url', 'series' ) as $purpose ) {
				$legacy = array(
					'start'         => '_grb_event_start',
					'end'           => '_grb_event_end',
					'cost'          => '_grb_event_cost',
					'venue_name'    => '_grb_event_venue_name',
					'venue_address' => '_grb_event_venue_address',
					'ticket_url'    => '_grb_event_ticket_url',
					'series'        => '_grb_event_series',
				);
				$value = get_post_meta( $old_id, $legacy[ $purpose ], true );
				if ( is_string( $value ) || is_numeric( $value ) ) {
					WVM_Meta::update( $new_id, $purpose, trim( (string) $value ) );
				}
			}

			$thumb = (int) get_post_thumbnail_id( $old_id );
			if ( $thumb > 0 ) {
				set_post_thumbnail( $new_id, $thumb );
			}

			$old_terms = wp_get_object_terms( $old_id, 'grb_series' );
			if ( ! is_wp_error( $old_terms ) ) {
				$new_terms = array();
				foreach ( $old_terms as $term ) {
					if ( $term instanceof WP_Term && isset( $series_map[ $term->term_id ] ) ) {
						$new_terms[] = $series_map[ $term->term_id ];
					}
				}
				if ( $new_terms ) {
					wp_set_object_terms( $new_id, $new_terms, WVM_Series::TAXONOMY, false );
				}
			}

			$venue_name    = WVM_Meta::get( $new_id, 'venue_name' );
			$venue_address = WVM_Meta::get( $new_id, 'venue_address' );
			if ( '' !== $venue_name ) {
				$venue = WVM_Venues::ensure( sanitize_title( $venue_name ), $venue_name, $venue_address );
				if ( $venue && $venue['term_id'] > 0 ) {
					WVM_Venues::assign( $new_id, $venue['term_id'] );
				}
			}

			++$copied;
		}

		$this->remap_recurrence_templates( $series_map, $post_map );

		return array(
			'events' => $copied,
			'skipped' => $skipped,
			'series' => count( $series_map ),
		);
	}

	/**
	 * @return array<int, int> Old term ID => new term ID.
	 */
	private function copy_series(): array {
		$map    = array();
		$terms  = get_terms(
			array(
				'taxonomy'   => 'grb_series',
				'hide_empty' => false,
			)
		);
		$images = get_option( 'grb_event_series_default_images', array() );
		$images = is_array( $images ) ? $images : array();

		if ( is_wp_error( $terms ) ) {
			return $map;
		}

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$existing = get_term_by( 'name', $term->name, WVM_Series::TAXONOMY );
			if ( $existing instanceof WP_Term ) {
				$new_id = (int) $existing->term_id;
			} else {
				$created = wp_insert_term( $term->name, WVM_Series::TAXONOMY, array( 'slug' => $term->slug ) );
				if ( is_wp_error( $created ) ) {
					continue;
				}
				$new_id = (int) $created['term_id'];
			}

			$map[ (int) $term->term_id ] = $new_id;

			$config = get_term_meta( $term->term_id, '_grb_series_recurrence_config', true );
			if ( is_array( $config ) && empty( get_term_meta( $new_id, WVM_Recurrence::META_CONFIG, true ) ) ) {
				update_term_meta( $new_id, WVM_Recurrence::META_CONFIG, $config );
			}

			if ( isset( $images[ $term->name ] ) && (int) $images[ $term->name ] > 0 && ! get_term_meta( $new_id, WVM_Series::IMAGE_META, true ) ) {
				update_term_meta( $new_id, WVM_Series::IMAGE_META, (int) $images[ $term->name ] );
			}
		}

		return $map;
	}

	/**
	 * Point saved rules at the copied template event.
	 *
	 * @param array<int, int> $series_map Old series term => new series term.
	 * @param array<int, int> $post_map   Old event ID => new event ID.
	 */
	private function remap_recurrence_templates( array $series_map, array $post_map ): void {
		foreach ( $series_map as $new_id ) {
			$config = get_term_meta( $new_id, WVM_Recurrence::META_CONFIG, true );
			if ( ! is_array( $config ) || empty( $config['template_id'] ) ) {
				continue;
			}

			$old_template = (int) $config['template_id'];
			if ( isset( $post_map[ $old_template ] ) ) {
				$config['template_id'] = $post_map[ $old_template ];
				update_term_meta( $new_id, WVM_Recurrence::META_CONFIG, $config );
			}
		}
	}
}
