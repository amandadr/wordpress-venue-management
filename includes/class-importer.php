<?php
/**
 * CSV and JSON import, and a matching export.
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Round-trips title, slug, start, end, cost, venue_slug, address, ticket_url, series, excerpt, content, image_url.
 */
final class WVM_Importer {

	public const COLUMNS = array(
		'title',
		'slug',
		'start',
		'end',
		'cost',
		'venue_slug',
		'address',
		'ticket_url',
		'series',
		'excerpt',
		'content',
		'image_url',
	);

	/**
	 * Import screen and admin-post handlers.
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_wvm_import_events', array( $this, 'handle_import' ) );
		add_action( 'admin_post_wvm_export_events', array( $this, 'handle_export' ) );
		add_action( 'admin_post_wvm_sample_csv', array( $this, 'handle_sample' ) );
	}

	/**
	 * Events → Import & export.
	 */
	public function menu(): void {
		$cap = 'edit_posts';
		add_submenu_page(
			'edit.php?post_type=' . WVM_Post_Type::slug(),
			__( 'Import & export', 'wordpress-venue-management' ),
			__( 'Import & export', 'wordpress-venue-management' ),
			$cap,
			'wvm-import',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Import, export, and sample download forms.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$notice = isset( $_GET['wvm_import'] ) ? sanitize_text_field( wp_unslash( $_GET['wvm_import'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import & export events', 'wordpress-venue-management' ); ?></h1>
			<p><?php esc_html_e( 'CSV and JSON use the same columns. start and end are site-local Y-m-d H:i:s. A second import updates rows with the same slug, or the same title and start date when the slug is empty.', 'wordpress-venue-management' ); ?></p>
			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<h2><?php esc_html_e( 'Import', 'wordpress-venue-management' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'wvm_import_events' ); ?>
				<input type="hidden" name="action" value="wvm_import_events">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wvm_import_file"><?php esc_html_e( 'File', 'wordpress-venue-management' ); ?></label></th>
						<td><input type="file" name="wvm_import_file" id="wvm_import_file" accept=".csv,.json,text/csv,application/json" required></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'wordpress-venue-management' ); ?></th>
						<td>
							<label><input type="checkbox" name="wvm_update_existing" value="1" checked> <?php esc_html_e( 'Update events that already match', 'wordpress-venue-management' ); ?></label><br>
							<label><input type="checkbox" name="wvm_import_images" value="1"> <?php esc_html_e( 'Sideload image_url as the featured image', 'wordpress-venue-management' ); ?></label>
						</td>
					</tr>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Trash missing', 'wordpress-venue-management' ); ?></th>
							<td>
								<label><input type="checkbox" name="wvm_trash_missing" value="1"> <?php esc_html_e( 'Trash published events that are not in this file', 'wordpress-venue-management' ); ?></label><br>
								<label><input type="checkbox" name="wvm_trash_confirm" value="yes"> <?php esc_html_e( 'I understand this trashes events missing from the file', 'wordpress-venue-management' ); ?></label>
							</td>
						</tr>
					<?php endif; ?>
				</table>
				<?php submit_button( __( 'Import', 'wordpress-venue-management' ) ); ?>
			</form>
			<h2><?php esc_html_e( 'Export', 'wordpress-venue-management' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wvm_export_events' ); ?>
				<input type="hidden" name="action" value="wvm_export_events">
				<?php submit_button( __( 'Download CSV', 'wordpress-venue-management' ), 'secondary' ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wvm_sample_csv' ); ?>
				<input type="hidden" name="action" value="wvm_sample_csv">
				<?php submit_button( __( 'Download sample CSV', 'wordpress-venue-management' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Parse an uploaded CSV or JSON file and write events.
	 */
	public function handle_import(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You cannot import events.', 'wordpress-venue-management' ) );
		}

		check_admin_referer( 'wvm_import_events' );

		if ( empty( $_FILES['wvm_import_file']['tmp_name'] ) ) {
			$this->redirect_notice( __( 'Choose a CSV or JSON file.', 'wordpress-venue-management' ) );
		}

		$tmp  = (string) $_FILES['wvm_import_file']['tmp_name'];
		$name = isset( $_FILES['wvm_import_file']['name'] ) ? (string) $_FILES['wvm_import_file']['name'] : '';
		$rows = $this->parse_file( $tmp, $name );
		if ( is_wp_error( $rows ) ) {
			$this->redirect_notice( $rows->get_error_message() );
		}

		$update  = ! empty( $_POST['wvm_update_existing'] );
		$images  = ! empty( $_POST['wvm_import_images'] );
		$trash   = current_user_can( 'manage_options' ) && ! empty( $_POST['wvm_trash_missing'] ) && isset( $_POST['wvm_trash_confirm'] ) && 'yes' === $_POST['wvm_trash_confirm'];
		$result  = $this->import_rows( $rows, $update, $images, $trash );

		$this->redirect_notice(
			sprintf(
				/* translators: 1: created count, 2: updated count, 3: skipped count, 4: trashed count, 5: error count */
				__( 'Import finished. Created %1$d, updated %2$d, skipped %3$d, trashed %4$d, errors %5$d.', 'wordpress-venue-management' ),
				$result['created'],
				$result['updated'],
				$result['skipped'],
				$result['trashed'],
				count( $result['errors'] )
			)
		);
	}

	/**
	 * @param array<int, array<string, string>> $rows Rows.
	 * @return array{created: int, updated: int, skipped: int, trashed: int, errors: string[]}
	 */
	public function import_rows( array $rows, bool $update_existing, bool $import_images, bool $trash_missing ): array {
		$created = 0;
		$updated = 0;
		$skipped = 0;
		$errors  = array();
		$seen    = array();

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			/**
			 * One import row before it is written.
			 *
			 * @param array<string, string> $row   Associative columns.
			 * @param int                   $index Zero-based row index.
			 */
			$row = apply_filters( 'wvm_import_row', $row, $index );
			if ( ! is_array( $row ) ) {
				++$skipped;
				continue;
			}

			$result = $this->import_row( $row, $update_existing, $import_images );
			if ( 'created' === $result['action'] ) {
				++$created;
			} elseif ( 'updated' === $result['action'] ) {
				++$updated;
			} else {
				++$skipped;
				if ( ! empty( $result['error'] ) ) {
					$errors[] = $result['error'];
				}
			}

			if ( ! empty( $result['id'] ) ) {
				$seen[ (int) $result['id'] ] = true;
			}
		}

		$trashed = 0;
		if ( $trash_missing ) {
			$trashed = $this->trash_missing( array_keys( $seen ) );
		}

		/**
		 * After a file import finishes.
		 *
		 * @param array{created: int, updated: int, skipped: int, trashed: int, errors: string[]} $result Counts.
		 */
		$result = array(
			'created' => $created,
			'updated' => $updated,
			'skipped' => $skipped,
			'trashed' => $trashed,
			'errors'  => $errors,
		);
		do_action( 'wvm_events_imported', $result );

		return $result;
	}

	/**
	 * @param array<string, string> $row Row.
	 * @return array{action: string, id?: int, error?: string}
	 */
	private function import_row( array $row, bool $update_existing, bool $import_images ): array {
		$row     = $this->normalize_row( $row );
		$title   = $row['title'];
		$start   = WVM_Meta::normalize_stored( $row['start'] );
		if ( '' === $title || '' === $start ) {
			return array(
				'action' => 'skip',
				'error'  => __( 'A row needs a title and a start.', 'wordpress-venue-management' ),
			);
		}

		$slug    = '' !== $row['slug'] ? sanitize_title( $row['slug'] ) : sanitize_title( $title . '-' . substr( $start, 0, 10 ) );
		$post_id = $this->find_by_slug( $slug );
		if ( $post_id < 1 ) {
			$post_id = $this->find_by_title_and_start( $title, substr( $start, 0, 10 ) );
		}

		$is_update = $post_id > 0;
		if ( $is_update && ! $update_existing ) {
			return array(
				'action' => 'skip',
				'id'     => $post_id,
				'error'  => '',
			);
		}

		$postarr = array(
			'post_type'    => WVM_Post_Type::slug(),
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $row['content'],
			'post_excerpt' => $row['excerpt'],
			'post_name'    => $slug,
		);

		if ( $is_update ) {
			$postarr['ID'] = $post_id;
			$updated_id    = wp_update_post( $postarr, true );
			if ( is_wp_error( $updated_id ) ) {
				return array(
					'action' => 'skip',
					'error'  => $updated_id->get_error_message(),
				);
			}
		} else {
			$post_id = wp_insert_post( $postarr, true );
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				return array(
					'action' => 'skip',
					'error'  => is_wp_error( $post_id ) ? $post_id->get_error_message() : __( 'Insert failed.', 'wordpress-venue-management' ),
				);
			}
			$post_id = (int) $post_id;
		}

		$end = WVM_Meta::normalize_stored( $row['end'] );
		WVM_Meta::update( $post_id, 'start', $start );
		WVM_Meta::update( $post_id, 'end', $end );
		WVM_Meta::update( $post_id, 'cost', '0' === $row['cost'] ? '' : $row['cost'] );
		WVM_Meta::update( $post_id, 'ticket_url', $row['ticket_url'] );

		if ( '' !== $row['venue_slug'] || '' !== $row['address'] ) {
			$venue_name = '' !== $row['venue_slug'] ? ucwords( str_replace( array( '-', '_' ), ' ', $row['venue_slug'] ) ) : '';
			$venue      = WVM_Venues::ensure( $row['venue_slug'], $venue_name, $row['address'] );
			if ( $venue ) {
				WVM_Venues::assign( $post_id, $venue['term_id'] );
				WVM_Meta::update( $post_id, 'venue_name', $venue['name'] );
				WVM_Meta::update( $post_id, 'venue_address', '' !== $row['address'] ? $row['address'] : $venue['address'] );
			} else {
				WVM_Meta::update( $post_id, 'venue_address', $row['address'] );
			}
		}

		if ( '' !== $row['series'] ) {
			WVM_Series::assign( $post_id, $row['series'] );
		}

		if ( $import_images && '' !== $row['image_url'] && filter_var( $row['image_url'], FILTER_VALIDATE_URL ) ) {
			$attachment = $this->sideload( $row['image_url'], $post_id );
			if ( ! is_wp_error( $attachment ) && $attachment > 0 ) {
				set_post_thumbnail( $post_id, $attachment );
			}
		}

		return array(
			'action' => $is_update ? 'updated' : 'created',
			'id'     => $post_id,
		);
	}

	/**
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, string>
	 */
	private function normalize_row( array $row ): array {
		$out = array();
		foreach ( self::COLUMNS as $column ) {
			$value = $row[ $column ] ?? '';
			$out[ $column ] = is_string( $value ) || is_numeric( $value ) ? trim( (string) $value ) : '';
		}

		if ( '' !== $out['ticket_url'] ) {
			$out['ticket_url'] = esc_url_raw( $out['ticket_url'] );
		}

		return $out;
	}

	/**
	 * @return array<int, array<string, string>>|WP_Error
	 */
	private function parse_file( string $path, string $filename ) {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( 'json' === $ext ) {
			$data = json_decode( (string) file_get_contents( $path ), true );
			if ( ! is_array( $data ) ) {
				return new WP_Error( 'wvm_json', __( 'JSON must be a list of objects.', 'wordpress-venue-management' ) );
			}

			$rows = array();
			foreach ( $data as $item ) {
				if ( is_array( $item ) ) {
					$rows[] = array_map(
						static function ( $value ): string {
							return is_scalar( $value ) ? (string) $value : '';
						},
						$item
					);
				}
			}

			return $rows;
		}

		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return new WP_Error( 'wvm_csv', __( 'Could not read the CSV file.', 'wordpress-venue-management' ) );
		}

		$header = fgetcsv( $handle );
		if ( ! is_array( $header ) ) {
			fclose( $handle );
			return new WP_Error( 'wvm_csv', __( 'The CSV file has no header row.', 'wordpress-venue-management' ) );
		}

		$header = array_map(
			static function ( $column ): string {
				return strtolower( trim( (string) $column ) );
			},
			$header
		);

		$rows = array();
		while ( ( $data = fgetcsv( $handle ) ) !== false ) {
			if ( array( null ) === $data ) {
				continue;
			}
			$row = array();
			foreach ( $header as $index => $column ) {
				$row[ $column ] = isset( $data[ $index ] ) ? (string) $data[ $index ] : '';
			}
			$rows[] = $row;
		}
		fclose( $handle );

		return $rows;
	}

	private function find_by_slug( string $slug ): int {
		$posts = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => WVM_Post_Type::slug(),
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return $posts ? (int) $posts[0] : 0;
	}

	private function find_by_title_and_start( string $title, string $start_ymd ): int {
		if ( strlen( $start_ymd ) < 10 ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => WVM_Post_Type::slug(),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => WVM_Meta::key( 'start' ),
						'value'   => $start_ymd,
						'compare' => 'LIKE',
					),
				),
			)
		);

		$wanted = $this->normalize_title( $title );
		foreach ( $ids as $id ) {
			if ( $this->normalize_title( get_the_title( (int) $id ) ) === $wanted ) {
				return (int) $id;
			}
		}

		return 0;
	}

	private function normalize_title( string $title ): string {
		$title = strtolower( trim( preg_replace( '/\s+/', ' ', $title ) ?? '' ) );

		return $title;
	}

	/**
	 * @param int[] $keep_ids Event IDs that were in the file.
	 */
	private function trash_missing( array $keep_ids ): int {
		$ids = get_posts(
			array(
				'post_type'      => WVM_Post_Type::slug(),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$trashed = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( in_array( $id, $keep_ids, true ) ) {
				continue;
			}
			if ( wp_trash_post( $id ) ) {
				++$trashed;
			}
		}

		return $trashed;
	}

	/**
	 * @return int|WP_Error
	 */
	private function sideload( string $url, int $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $name || ! str_contains( $name, '.' ) ) {
			$name = 'venue-event-' . $post_id . '.jpg';
		}

		$attachment = media_handle_sideload(
			array(
				'name'     => sanitize_file_name( $name ),
				'tmp_name' => $tmp,
			),
			$post_id
		);

		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		return $attachment;
	}

	/**
	 * Download every event as CSV.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You cannot export events.', 'wordpress-venue-management' ) );
		}

		check_admin_referer( 'wvm_export_events' );

		$ids = get_posts(
			array(
				'post_type'      => WVM_Post_Type::slug(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'meta_value',
				'meta_key'       => WVM_Meta::key( 'start' ),
				'order'          => 'ASC',
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="venue-events.csv"' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		fputcsv( $out, self::COLUMNS );
		foreach ( $ids as $post_id ) {
			$post_id = (int) $post_id;
			$terms   = wp_get_object_terms( $post_id, WVM_Venues::TAXONOMY );
			$slug    = ( ! is_wp_error( $terms ) && isset( $terms[0] ) && $terms[0] instanceof WP_Term ) ? $terms[0]->slug : '';
			$image   = get_the_post_thumbnail_url( $post_id, 'full' );
			fputcsv(
				$out,
				array(
					get_the_title( $post_id ),
					get_post_field( 'post_name', $post_id ),
					WVM_Meta::get( $post_id, 'start' ),
					WVM_Meta::get( $post_id, 'end' ),
					WVM_Meta::get( $post_id, 'cost' ),
					$slug,
					WVM_Meta::get( $post_id, 'venue_address' ),
					WVM_Meta::get( $post_id, 'ticket_url' ),
					WVM_Meta::get( $post_id, 'series' ),
					get_the_excerpt( $post_id ),
					get_post_field( 'post_content', $post_id ),
					is_string( $image ) ? $image : '',
				)
			);
		}
		fclose( $out );
		exit;
	}

	/**
	 * Download the fictional sample CSV.
	 */
	public function handle_sample(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You cannot download the sample.', 'wordpress-venue-management' ) );
		}

		check_admin_referer( 'wvm_sample_csv' );

		$path = WVM_PATH . 'samples/events.csv';
		if ( ! is_readable( $path ) ) {
			wp_die( esc_html__( 'Sample file is missing.', 'wordpress-venue-management' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="events-sample.csv"' );
		readfile( $path );
		exit;
	}

	private function redirect_notice( string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				'wvm_import',
				$message,
				admin_url( 'edit.php?post_type=' . WVM_Post_Type::slug() . '&page=wvm-import' )
			)
		);
		exit;
	}
}
