<?php
/**
 * Series taxonomy, label cache, and default image.
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Series group occurrences. The term name is cached on the event for templates.
 */
final class WVM_Series {

	public const TAXONOMY = 'venue_series';

	public const IMAGE_META = '_venue_series_image_id';

	public const PATTERNS_META = '_venue_series_match_patterns';

	/**
	 * Series taxonomy admin and label-sync hooks.
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'register_save_hook' ), 11 );
		add_action( 'set_object_terms', array( $this, 'sync_on_set_terms' ), 10, 4 );
		add_action( self::TAXONOMY . '_add_form_fields', array( $this, 'add_fields' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'edit_fields' ) );
		add_action( 'created_' . self::TAXONOMY, array( $this, 'save_term' ) );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'save_term' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media' ) );
	}

	/**
	 * Bind the label sync to the filtered post type.
	 */
	public function register_save_hook(): void {
		add_action( 'save_post_' . WVM_Post_Type::slug(), array( $this, 'sync_label' ), 20 );
	}

	public static function register(): void {
		register_taxonomy(
			self::TAXONOMY,
			array( WVM_Post_Type::slug() ),
			array(
				'labels'            => array(
					'name'          => _x( 'Series', 'taxonomy general name', 'wordpress-venue-management' ),
					'singular_name' => _x( 'Series', 'taxonomy singular name', 'wordpress-venue-management' ),
					'search_items'  => __( 'Search Series', 'wordpress-venue-management' ),
					'all_items'     => __( 'All Series', 'wordpress-venue-management' ),
					'edit_item'     => __( 'Edit Series', 'wordpress-venue-management' ),
					'update_item'   => __( 'Update Series', 'wordpress-venue-management' ),
					'add_new_item'  => __( 'Add New Series', 'wordpress-venue-management' ),
					'new_item_name' => __( 'New Series Name', 'wordpress-venue-management' ),
					'menu_name'     => _x( 'Series', 'admin menu', 'wordpress-venue-management' ),
				),
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => false,
			)
		);
	}

	/**
	 * Copy the assigned series name into event meta.
	 *
	 * @param int $post_id Event ID.
	 */
	public function sync_label( int $post_id ): void {
		$this->write_label( $post_id );
	}

	/**
	 * @param int          $object_id Object ID.
	 * @param array<mixed> $terms     Terms.
	 * @param array<mixed> $tt_ids    Term taxonomy IDs.
	 * @param string       $taxonomy  Taxonomy.
	 */
	public function sync_on_set_terms( int $object_id, $terms, $tt_ids, string $taxonomy ): void {
		unset( $terms, $tt_ids );
		if ( self::TAXONOMY !== $taxonomy || get_post_type( $object_id ) !== WVM_Post_Type::slug() ) {
			return;
		}

		$this->write_label( $object_id );
	}

	private function write_label( int $post_id ): void {
		$terms = wp_get_object_terms( $post_id, self::TAXONOMY );
		$name  = ( is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof WP_Term ) ? $terms[0]->name : '';
		WVM_Meta::update( $post_id, 'series', $name );
	}

	/**
	 * Ensure a series term exists and assign it.
	 */
	public static function assign( int $post_id, string $name ): void {
		$name = trim( $name );
		if ( '' === $name ) {
			wp_set_object_terms( $post_id, array(), self::TAXONOMY );
			WVM_Meta::update( $post_id, 'series', '' );
			return;
		}

		$term = get_term_by( 'name', $name, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			$created = wp_insert_term( $name, self::TAXONOMY );
			if ( is_wp_error( $created ) ) {
				return;
			}
			$term = get_term( (int) $created['term_id'], self::TAXONOMY );
		}

		if ( $term instanceof WP_Term ) {
			wp_set_object_terms( $post_id, array( (int) $term->term_id ), self::TAXONOMY, false );
			WVM_Meta::update( $post_id, 'series', $term->name );
		}
	}

	/**
	 * Featured image, otherwise the series default. Zero when neither is set.
	 */
	public static function display_image_id( int $post_id ): int {
		$thumb = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb > 0 ) {
			return $thumb;
		}

		$terms = wp_get_object_terms( $post_id, self::TAXONOMY );
		if ( is_wp_error( $terms ) || empty( $terms ) || ! $terms[0] instanceof WP_Term ) {
			return 0;
		}

		$image = (int) get_term_meta( $terms[0]->term_id, self::IMAGE_META, true );
		if ( $image > 0 && wp_attachment_is_image( $image ) ) {
			return $image;
		}

		return 0;
	}

	/**
	 * Title patterns stored on series terms, longest first.
	 *
	 * @return array<int, array{pattern: string, series: string}>
	 */
	public static function match_patterns(): array {
		$patterns = array();
		$terms    = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$raw = (string) get_term_meta( $term->term_id, self::PATTERNS_META, true );
				foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: array() as $line ) {
					$line = trim( $line );
					if ( '' !== $line ) {
						$patterns[] = array(
							'pattern' => $line,
							'series'  => $term->name,
						);
					}
				}
			}
		}

		usort(
			$patterns,
			static function ( array $a, array $b ): int {
				return strlen( $b['pattern'] ) <=> strlen( $a['pattern'] );
			}
		);

		/**
		 * Deprecated. Pattern rows of {pattern, series}. Prefer series term meta.
		 *
		 * @param array<int, array{pattern: string, series: string}> $patterns Patterns.
		 */
		$filtered = apply_filters( 'grb_event_series_match_patterns', $patterns );

		return is_array( $filtered ) ? $filtered : $patterns;
	}

	public static function match_title( string $title ): string {
		$title = trim( $title );
		if ( '' === $title ) {
			return '';
		}

		foreach ( self::match_patterns() as $entry ) {
			$pattern = trim( (string) ( $entry['pattern'] ?? '' ) );
			$series  = trim( (string) ( $entry['series'] ?? '' ) );
			if ( '' !== $pattern && '' !== $series && false !== stripos( $title, $pattern ) ) {
				return $series;
			}
		}

		return '';
	}

	/**
	 * Assign a series only where the event has none and the title matches a pattern.
	 *
	 * @return int Events updated.
	 */
	public static function assign_empty_from_titles(): int {
		$ids = get_posts(
			array(
				'post_type'      => WVM_Post_Type::slug(),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$updated = 0;
		foreach ( $ids as $post_id ) {
			$post_id = (int) $post_id;
			$terms   = wp_get_object_terms( $post_id, self::TAXONOMY );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				continue;
			}

			$series = self::match_title( get_the_title( $post_id ) );
			if ( '' === $series ) {
				continue;
			}

			self::assign( $post_id, $series );
			++$updated;
		}

		return $updated;
	}

	/**
	 * Media picker script on the series term screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_media( string $hook ): void {
		if ( ! in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
			return;
		}

		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
		if ( self::TAXONOMY !== $taxonomy ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script( 'wvm-series-image', WVM_URL . 'assets/series-image.js', array( 'jquery' ), WVM_VERSION, true );
	}

	/**
	 * Series image and title patterns on the add-term screen.
	 */
	public function add_fields(): void {
		wp_nonce_field( 'wvm_series_term', 'wvm_series_term_nonce' );
		?>
		<div class="form-field">
			<label for="wvm_series_image_id"><?php esc_html_e( 'Default image', 'wordpress-venue-management' ); ?></label>
			<input type="hidden" name="wvm_series_image_id" id="wvm_series_image_id" value="">
			<button type="button" class="button" id="wvm-series-image"><?php esc_html_e( 'Select image', 'wordpress-venue-management' ); ?></button>
			<button type="button" class="button" id="wvm-series-image-clear"><?php esc_html_e( 'Remove', 'wordpress-venue-management' ); ?></button>
			<p><img id="wvm-series-image-preview" alt="" src="" style="display:none" width="80" height="80"></p>
			<p><?php esc_html_e( 'Used when an event in this series has no featured image.', 'wordpress-venue-management' ); ?></p>
		</div>
		<div class="form-field">
			<label for="wvm_series_patterns"><?php esc_html_e( 'Title patterns', 'wordpress-venue-management' ); ?></label>
			<textarea name="wvm_series_patterns" id="wvm_series_patterns" rows="4"></textarea>
			<p><?php esc_html_e( 'One phrase per line. Series tools can assign this series when an event title contains a phrase and has no series yet.', 'wordpress-venue-management' ); ?></p>
		</div>
		<?php
	}

	/**
	 * @param WP_Term $term Series term.
	 */
	public function edit_fields( WP_Term $term ): void {
		wp_nonce_field( 'wvm_series_term', 'wvm_series_term_nonce' );
		$image    = (int) get_term_meta( $term->term_id, self::IMAGE_META, true );
		$patterns = (string) get_term_meta( $term->term_id, self::PATTERNS_META, true );
		$src      = $image ? wp_get_attachment_image_url( $image, 'thumbnail' ) : '';
		?>
		<tr class="form-field">
			<th scope="row"><label for="wvm_series_image_id"><?php esc_html_e( 'Default image', 'wordpress-venue-management' ); ?></label></th>
			<td>
				<input type="hidden" name="wvm_series_image_id" id="wvm_series_image_id" value="<?php echo esc_attr( (string) $image ); ?>">
				<button type="button" class="button" id="wvm-series-image"><?php esc_html_e( 'Select image', 'wordpress-venue-management' ); ?></button>
				<button type="button" class="button" id="wvm-series-image-clear"><?php esc_html_e( 'Remove', 'wordpress-venue-management' ); ?></button>
				<p><img id="wvm-series-image-preview" alt="" src="<?php echo esc_url( (string) $src ); ?>" <?php echo $src ? '' : 'style="display:none"'; ?> width="80" height="80"></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="wvm_series_patterns"><?php esc_html_e( 'Title patterns', 'wordpress-venue-management' ); ?></label></th>
			<td>
				<textarea name="wvm_series_patterns" id="wvm_series_patterns" rows="4" class="large-text"><?php echo esc_textarea( $patterns ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One phrase per line. Used only by the explicit “assign from titles” tool, and only for events that have no series.', 'wordpress-venue-management' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save the series image and title patterns.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save_term( int $term_id ): void {
		if ( ! isset( $_POST['wvm_series_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wvm_series_term_nonce'] ) ), 'wvm_series_term' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$image = isset( $_POST['wvm_series_image_id'] ) ? absint( $_POST['wvm_series_image_id'] ) : 0;
		if ( $image > 0 ) {
			update_term_meta( $term_id, self::IMAGE_META, $image );
		} else {
			delete_term_meta( $term_id, self::IMAGE_META );
		}

		$patterns = isset( $_POST['wvm_series_patterns'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wvm_series_patterns'] ) ) : '';
		update_term_meta( $term_id, self::PATTERNS_META, $patterns );
	}
}
