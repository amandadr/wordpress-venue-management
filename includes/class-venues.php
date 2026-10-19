<?php
/**
 * Venues stored as taxonomy terms, with archive rewrites.
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A venue is a term: name, slug, public label, and address. Events archive at /{events}/{venue-slug}/.
 */
final class WVM_Venues {

	public const TAXONOMY = 'venue_place';

	public const QUERY_VAR = 'wvm_venue';

	public function hooks(): void {
		add_action( self::TAXONOMY . '_add_form_fields', array( $this, 'add_fields' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( $this, 'edit_fields' ) );
		add_action( 'created_' . self::TAXONOMY, array( $this, 'save_term' ) );
		add_action( 'edited_' . self::TAXONOMY, array( $this, 'save_term' ) );
		add_action( 'delete_' . self::TAXONOMY, array( self::class, 'flag_rewrite_flush' ) );
		add_filter( 'query_vars', array( $this, 'query_var' ) );
		add_filter( 'document_title_parts', array( $this, 'archive_title' ) );
	}

	/**
	 * Register the venue taxonomy and its term meta.
	 */
	public static function register(): void {
		register_taxonomy(
			self::TAXONOMY,
			array( WVM_Post_Type::slug() ),
			array(
				'labels'            => array(
					'name'          => _x( 'Venues', 'taxonomy general name', 'wordpress-venue-management' ),
					'singular_name' => _x( 'Venue', 'taxonomy singular name', 'wordpress-venue-management' ),
					'search_items'  => __( 'Search Venues', 'wordpress-venue-management' ),
					'all_items'     => __( 'All Venues', 'wordpress-venue-management' ),
					'edit_item'     => __( 'Edit Venue', 'wordpress-venue-management' ),
					'update_item'   => __( 'Update Venue', 'wordpress-venue-management' ),
					'add_new_item'  => __( 'Add New Venue', 'wordpress-venue-management' ),
					'new_item_name' => __( 'New Venue Name', 'wordpress-venue-management' ),
					'menu_name'     => _x( 'Venues', 'admin menu', 'wordpress-venue-management' ),
				),
				'hierarchical'      => false,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => false,
			)
		);
	}

	/**
	 * Add the /{archive}/{venue}/ rewrite.
	 */
	public static function register_rewrites(): void {
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([^/]+)' );

		$slugs = array();
		foreach ( self::all() as $venue ) {
			if ( '' !== $venue['slug'] ) {
				$slugs[] = preg_quote( $venue['slug'], '/' );
			}
		}

		if ( empty( $slugs ) ) {
			return;
		}

		$archive = WVM_Post_Type::archive_slug();
		$pattern = $archive . '/(' . implode( '|', $slugs ) . ')/?$';
		add_rewrite_rule( $pattern, 'index.php?post_type=' . WVM_Post_Type::slug() . '&' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Ask the next admin request to flush rewrites.
	 */
	public static function flag_rewrite_flush(): void {
		update_option( 'wvm_flush_rewrites', 1, false );
	}

	/**
	 * Flush rewrites once after a venue term changes.
	 */
	public static function maybe_flush_rewrites(): void {
		if ( ! get_option( 'wvm_flush_rewrites' ) ) {
			return;
		}

		flush_rewrite_rules( false );
		delete_option( 'wvm_flush_rewrites' );
	}

	/**
	 * @param array<int, string> $vars Query vars.
	 * @return array<int, string>
	 */
	public function query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Venues for filters, rewrites, and the archive dropdown.
	 *
	 * @return array<int, array{term_id: int, slug: string, name: string, label: string, address: string}>
	 */
	public static function all(): array {
		$venues = array();
		$terms  = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$venues[] = self::from_term( $term );
			}
		}

		/**
		 * Venue records. Each item has term_id, slug, name, label, and address.
		 *
		 * @param array<int, array{term_id: int, slug: string, name: string, label: string, address: string}> $venues Stored venues.
		 */
		$filtered = apply_filters( 'wvm_venues', $venues );
		$venues   = self::normalize_list( is_array( $filtered ) ? $filtered : $venues );

		$legacy = array();
		foreach ( $venues as $venue ) {
			$legacy[ $venue['slug'] ] = array(
				'name'    => $venue['name'],
				'label'   => $venue['label'],
				'address' => $venue['address'],
			);
		}

		/**
		 * Deprecated. Associative slug => {name, label, address}. Prefer `wvm_venues`.
		 *
		 * @param array<string, array{name: string, label: string, address: string}> $legacy Legacy shape.
		 */
		$legacy = apply_filters( 'grb_event_venues', $legacy );
		if ( is_array( $legacy ) ) {
			$venues = self::normalize_legacy( $legacy, $venues );
		}

		return $venues;
	}

	/**
	 * @param WP_Term $term Venue term.
	 * @return array{term_id: int, slug: string, name: string, label: string, address: string}
	 */
	public static function from_term( WP_Term $term ): array {
		$label   = (string) get_term_meta( $term->term_id, 'wvm_label', true );
		$address = (string) get_term_meta( $term->term_id, 'wvm_address', true );

		return array(
			'term_id' => (int) $term->term_id,
			'slug'    => $term->slug,
			'name'    => $term->name,
			'label'   => '' !== $label ? $label : $term->name,
			'address' => $address,
		);
	}

	/**
	 * @param array<int, mixed> $list Filter return.
	 * @return array<int, array{term_id: int, slug: string, name: string, label: string, address: string}>
	 */
	private static function normalize_list( array $list ): array {
		$out = array();
		foreach ( $list as $venue ) {
			if ( ! is_array( $venue ) ) {
				continue;
			}
			$slug = sanitize_title( (string) ( $venue['slug'] ?? $venue['name'] ?? '' ) );
			$name = trim( (string) ( $venue['name'] ?? '' ) );
			if ( '' === $slug || '' === $name ) {
				continue;
			}
			$out[] = array(
				'term_id' => (int) ( $venue['term_id'] ?? 0 ),
				'slug'    => $slug,
				'name'    => $name,
				'label'   => trim( (string) ( $venue['label'] ?? $name ) ) ?: $name,
				'address' => trim( (string) ( $venue['address'] ?? '' ) ),
			);
		}

		return $out;
	}

	/**
	 * @param array<string, mixed>                                                              $legacy  Old filter shape.
	 * @param array<int, array{term_id: int, slug: string, name: string, label: string, address: string}> $current Current list.
	 * @return array<int, array{term_id: int, slug: string, name: string, label: string, address: string}>
	 */
	private static function normalize_legacy( array $legacy, array $current ): array {
		$by_slug = array();
		foreach ( $current as $venue ) {
			$by_slug[ $venue['slug'] ] = $venue;
		}

		$out = array();
		foreach ( $legacy as $slug => $venue ) {
			$slug = sanitize_title( (string) $slug );
			if ( is_string( $venue ) ) {
				$venue = array( 'name' => $venue );
			}
			if ( ! is_array( $venue ) || '' === $slug ) {
				continue;
			}
			$name    = trim( (string) ( $venue['name'] ?? ( $by_slug[ $slug ]['name'] ?? $slug ) ) );
			$out[]   = array(
				'term_id' => (int) ( $by_slug[ $slug ]['term_id'] ?? 0 ),
				'slug'    => $slug,
				'name'    => $name,
				'label'   => trim( (string) ( $venue['label'] ?? $name ) ) ?: $name,
				'address' => trim( (string) ( $venue['address'] ?? '' ) ),
			);
		}

		return $out;
	}

	/**
	 * @return array{term_id: int, slug: string, name: string, label: string, address: string}|null
	 */
	public static function by_slug( string $slug ): ?array {
		$slug = sanitize_title( $slug );
		foreach ( self::all() as $venue ) {
			if ( $venue['slug'] === $slug ) {
				return $venue;
			}
		}

		return null;
	}

	/**
	 * Find or create a venue term from an import row.
	 *
	 * @return array{term_id: int, slug: string, name: string, label: string, address: string}|null
	 */
	public static function ensure( string $slug, string $name, string $address ): ?array {
		$slug = sanitize_title( $slug );
		$name = trim( $name );
		if ( '' === $slug && '' === $name ) {
			return null;
		}
		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}
		if ( '' === $name ) {
			$name = $slug;
		}

		$term = get_term_by( 'slug', $slug, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			$created = wp_insert_term( $name, self::TAXONOMY, array( 'slug' => $slug ) );
			if ( is_wp_error( $created ) ) {
				return null;
			}
			$term = get_term( (int) $created['term_id'], self::TAXONOMY );
			self::flag_rewrite_flush();
		}

		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		if ( '' !== $address && '' === (string) get_term_meta( $term->term_id, 'wvm_address', true ) ) {
			update_term_meta( $term->term_id, 'wvm_address', $address );
		}

		return self::from_term( $term );
	}

	/**
	 * Set the venue term on an event, or clear it.
	 *
	 * @param int $post_id Event ID.
	 * @param int $term_id Term ID.
	 */
	public static function assign( int $post_id, int $term_id ): void {
		if ( $term_id > 0 ) {
			wp_set_object_terms( $post_id, array( $term_id ), self::TAXONOMY, false );
			update_post_meta( $post_id, '_wvm_venue_term_id', $term_id );
			return;
		}

		wp_set_object_terms( $post_id, array(), self::TAXONOMY );
		delete_post_meta( $post_id, '_wvm_venue_term_id' );
	}

	/**
	 * Venue select and other-address fields for the meta box.
	 *
	 * @param int $post_id Event ID.
	 * @param int $selected Selected venue term ID, or 0.
	 */
	public static function render_meta_fields( int $post_id, int $selected ): void {
		$venues  = self::all();
		$address = WVM_Meta::get( $post_id, 'venue_address' );
		$name    = WVM_Meta::get( $post_id, 'venue_name' );
		?>
		<tr>
			<th scope="row"><label for="wvm_venue_term"><?php esc_html_e( 'Venue', 'wordpress-venue-management' ); ?></label></th>
			<td>
				<select name="wvm_venue_term" id="wvm_venue_term">
					<option value=""><?php esc_html_e( '— None —', 'wordpress-venue-management' ); ?></option>
					<?php foreach ( $venues as $venue ) : ?>
						<?php if ( $venue['term_id'] < 1 ) : ?>
							<?php continue; ?>
						<?php endif; ?>
						<option value="<?php echo esc_attr( (string) $venue['term_id'] ); ?>" <?php selected( $selected, $venue['term_id'] ); ?>><?php echo esc_html( $venue['label'] ); ?></option>
					<?php endforeach; ?>
					<option value="other" <?php selected( 0 === $selected && '' !== $name ); ?>><?php esc_html_e( 'Other address', 'wordpress-venue-management' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Add rooms under Events → Venues. Choose Other to type a one-off address.', 'wordpress-venue-management' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wvm_venue_other_name"><?php esc_html_e( 'Other venue name', 'wordpress-venue-management' ); ?></label></th>
			<td><input type="text" class="regular-text" id="wvm_venue_other_name" name="wvm_venue_other_name" value="<?php echo esc_attr( 0 === $selected ? $name : '' ); ?>"></td>
		</tr>
		<tr>
			<th scope="row"><label for="wvm_venue_other_address"><?php esc_html_e( 'Address', 'wordpress-venue-management' ); ?></label></th>
			<td><textarea class="large-text" rows="2" id="wvm_venue_other_address" name="wvm_venue_other_address"><?php echo esc_textarea( $address ); ?></textarea></td>
		</tr>
		<?php
	}

	/**
	 * Save the venue choice from the meta box.
	 *
	 * @param int $post_id Event ID.
	 */
	public static function save_meta_fields( int $post_id ): void {
		$choice = isset( $_POST['wvm_venue_term'] ) ? sanitize_text_field( wp_unslash( $_POST['wvm_venue_term'] ) ) : '';

		if ( '' === $choice ) {
			self::assign( $post_id, 0 );
			WVM_Meta::update( $post_id, 'venue_name', '' );
			WVM_Meta::update( $post_id, 'venue_address', '' );
			return;
		}

		if ( 'other' === $choice ) {
			self::assign( $post_id, 0 );
			$name    = isset( $_POST['wvm_venue_other_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wvm_venue_other_name'] ) ) : '';
			$address = isset( $_POST['wvm_venue_other_address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wvm_venue_other_address'] ) ) : '';
			WVM_Meta::update( $post_id, 'venue_name', $name );
			WVM_Meta::update( $post_id, 'venue_address', $address );
			return;
		}

		$term_id = absint( $choice );
		$term    = get_term( $term_id, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		$venue = self::from_term( $term );
		self::assign( $post_id, $term_id );
		WVM_Meta::update( $post_id, 'venue_name', $venue['name'] );
		WVM_Meta::update( $post_id, 'venue_address', $venue['address'] );
	}

	/**
	 * Venue label and address on the add-term screen.
	 */
	public function add_fields(): void {
		?>
		<div class="form-field">
			<label for="wvm_label"><?php esc_html_e( 'Public label', 'wordpress-venue-management' ); ?></label>
			<input name="wvm_label" id="wvm_label" type="text" value="">
			<p><?php esc_html_e( 'Shown in the archive filter. Defaults to the venue name.', 'wordpress-venue-management' ); ?></p>
		</div>
		<div class="form-field">
			<label for="wvm_address"><?php esc_html_e( 'Address', 'wordpress-venue-management' ); ?></label>
			<textarea name="wvm_address" id="wvm_address" rows="3"></textarea>
			<p><?php esc_html_e( 'Copied onto new events that pick this venue. Used for maps and calendar locations.', 'wordpress-venue-management' ); ?></p>
		</div>
		<?php
	}

	/**
	 * @param WP_Term $term Venue term.
	 */
	public function edit_fields( WP_Term $term ): void {
		$label   = (string) get_term_meta( $term->term_id, 'wvm_label', true );
		$address = (string) get_term_meta( $term->term_id, 'wvm_address', true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="wvm_label"><?php esc_html_e( 'Public label', 'wordpress-venue-management' ); ?></label></th>
			<td><input name="wvm_label" id="wvm_label" type="text" value="<?php echo esc_attr( $label ); ?>" class="regular-text"></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="wvm_address"><?php esc_html_e( 'Address', 'wordpress-venue-management' ); ?></label></th>
			<td><textarea name="wvm_address" id="wvm_address" rows="3" class="large-text"><?php echo esc_textarea( $address ); ?></textarea></td>
		</tr>
		<?php
	}

	/**
	 * Save venue label and address, then flag a rewrite flush.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save_term( int $term_id ): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		if ( isset( $_POST['wvm_label'] ) ) {
			update_term_meta( $term_id, 'wvm_label', sanitize_text_field( wp_unslash( $_POST['wvm_label'] ) ) );
		}
		if ( isset( $_POST['wvm_address'] ) ) {
			update_term_meta( $term_id, 'wvm_address', sanitize_textarea_field( wp_unslash( $_POST['wvm_address'] ) ) );
		}

		self::flag_rewrite_flush();
	}

	/**
	 * @param array<string, string> $title Title parts.
	 * @return array<string, string>
	 */
	public function archive_title( array $title ): array {
		if ( ! is_post_type_archive( WVM_Post_Type::slug() ) ) {
			return $title;
		}

		$slug  = sanitize_title( (string) get_query_var( self::QUERY_VAR ) );
		$venue = self::by_slug( $slug );
		if ( $venue ) {
			$title['title'] = sprintf(
				/* translators: %s: venue name */
				__( 'Events at %s', 'wordpress-venue-management' ),
				$venue['name']
			);
		}

		return $title;
	}
}
