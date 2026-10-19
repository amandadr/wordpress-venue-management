<?php
/**
 * Series tools: rules, generation, bulk copy, and title assignment.
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Events → Series tools.
 */
final class WVM_Admin {

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_wvm_series_tools', array( $this, 'handle' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . WVM_Post_Type::slug(),
			__( 'Series tools', 'wordpress-venue-management' ),
			__( 'Series tools', 'wordpress-venue-management' ),
			'edit_posts',
			'wvm-series-tools',
			array( $this, 'render' )
		);
	}

	/**
	 * Series tools screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$terms   = get_terms(
			array(
				'taxonomy'   => WVM_Series::TAXONOMY,
				'hide_empty' => false,
			)
		);
		$events  = get_posts(
			array(
				'post_type'      => WVM_Post_Type::slug(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$notice  = isset( $_GET['wvm_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['wvm_notice'] ) ) : '';
		$terms   = is_wp_error( $terms ) ? array() : $terms;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Series tools', 'wordpress-venue-management' ); ?></h1>
			<p><?php esc_html_e( 'A rule stores a template event and a rhythm. Generate fills dates from that template. The weekly cron extends every saved rule up to the horizon.', 'wordpress-venue-management' ); ?></p>
			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<?php if ( empty( $terms ) ) : ?>
				<p><?php esc_html_e( 'Add a series under Events → Series first.', 'wordpress-venue-management' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wvm_series_tools' ); ?>
					<input type="hidden" name="action" value="wvm_series_tools">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wvm_series_id"><?php esc_html_e( 'Series', 'wordpress-venue-management' ); ?></label></th>
							<td>
								<select name="wvm_series_id" id="wvm_series_id">
									<?php foreach ( $terms as $term ) : ?>
										<option value="<?php echo esc_attr( (string) $term->term_id ); ?>"><?php echo esc_html( $term->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wvm_template_id"><?php esc_html_e( 'Template event', 'wordpress-venue-management' ); ?></label></th>
							<td>
								<select name="wvm_template_id" id="wvm_template_id">
									<?php foreach ( $events as $event ) : ?>
										<option value="<?php echo esc_attr( (string) $event->ID ); ?>"><?php echo esc_html( $event->post_title . ' (#' . $event->ID . ')' ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wvm_recurrence"><?php esc_html_e( 'Rhythm', 'wordpress-venue-management' ); ?></label></th>
							<td>
								<select name="wvm_recurrence" id="wvm_recurrence">
									<option value="weekly"><?php esc_html_e( 'Weekly', 'wordpress-venue-management' ); ?></option>
									<option value="every_n_weeks"><?php esc_html_e( 'Every N weeks', 'wordpress-venue-management' ); ?></option>
									<option value="monthly"><?php esc_html_e( 'Monthly', 'wordpress-venue-management' ); ?></option>
									<option value="yearly"><?php esc_html_e( 'Yearly', 'wordpress-venue-management' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wvm_interval_weeks"><?php esc_html_e( 'Interval (weeks)', 'wordpress-venue-management' ); ?></label></th>
							<td><input type="number" min="1" max="12" name="wvm_interval_weeks" id="wvm_interval_weeks" value="2"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wvm_n_weeks"><?php esc_html_e( 'Span in weeks', 'wordpress-venue-management' ); ?></label></th>
							<td><input type="number" min="1" max="26" name="wvm_n_weeks" id="wvm_n_weeks" value="12"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wvm_n_months"><?php esc_html_e( 'Span in months', 'wordpress-venue-management' ); ?></label></th>
							<td><input type="number" min="1" max="6" name="wvm_n_months" id="wvm_n_months" value="6"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wvm_month_day"><?php esc_html_e( 'Day of month', 'wordpress-venue-management' ); ?></label></th>
							<td><input type="number" min="1" max="31" name="wvm_month_day" id="wvm_month_day" value="1"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wvm_start_from"><?php esc_html_e( 'Generate from', 'wordpress-venue-management' ); ?></label></th>
							<td>
								<input type="date" name="wvm_start_from" id="wvm_start_from">
								<p class="description"><?php esc_html_e( 'Leave empty to start at the template’s own date. The template’s clock time is kept.', 'wordpress-venue-management' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Copy onto existing events', 'wordpress-venue-management' ); ?></th>
							<td>
								<label><input type="checkbox" name="wvm_copy[]" value="content"> <?php esc_html_e( 'Description', 'wordpress-venue-management' ); ?></label><br>
								<label><input type="checkbox" name="wvm_copy[]" value="excerpt"> <?php esc_html_e( 'Excerpt', 'wordpress-venue-management' ); ?></label><br>
								<label><input type="checkbox" name="wvm_copy[]" value="cost"> <?php esc_html_e( 'Cost', 'wordpress-venue-management' ); ?></label><br>
								<label><input type="checkbox" name="wvm_copy[]" value="venue"> <?php esc_html_e( 'Venue', 'wordpress-venue-management' ); ?></label><br>
								<label><input type="checkbox" name="wvm_copy[]" value="ticket"> <?php esc_html_e( 'Ticket URL', 'wordpress-venue-management' ); ?></label><br>
								<label><input type="checkbox" name="wvm_copy[]" value="image"> <?php esc_html_e( 'Featured image', 'wordpress-venue-management' ); ?></label>
							</td>
						</tr>
					</table>
					<p>
						<button class="button button-primary" name="wvm_task" value="save"><?php esc_html_e( 'Save rule', 'wordpress-venue-management' ); ?></button>
						<button class="button" name="wvm_task" value="generate"><?php esc_html_e( 'Generate dates', 'wordpress-venue-management' ); ?></button>
						<button class="button" name="wvm_task" value="populate"><?php esc_html_e( 'Extend all saved rules', 'wordpress-venue-management' ); ?></button>
						<button class="button" name="wvm_task" value="copy"><?php esc_html_e( 'Copy checked fields', 'wordpress-venue-management' ); ?></button>
						<button class="button" name="wvm_task" value="titles"><?php esc_html_e( 'Assign empty events from title patterns', 'wordpress-venue-management' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save a rule, generate dates, copy fields, or assign from titles.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You cannot manage series.', 'wordpress-venue-management' ) );
		}

		check_admin_referer( 'wvm_series_tools' );

		$task      = isset( $_POST['wvm_task'] ) ? sanitize_key( wp_unslash( $_POST['wvm_task'] ) ) : '';
		$series_id = isset( $_POST['wvm_series_id'] ) ? absint( $_POST['wvm_series_id'] ) : 0;
		$template  = isset( $_POST['wvm_template_id'] ) ? absint( $_POST['wvm_template_id'] ) : 0;
		$config    = array(
			'template_id'    => $template,
			'recurrence'     => isset( $_POST['wvm_recurrence'] ) ? sanitize_key( wp_unslash( $_POST['wvm_recurrence'] ) ) : 'weekly',
			'n_weeks'        => isset( $_POST['wvm_n_weeks'] ) ? absint( $_POST['wvm_n_weeks'] ) : 12,
			'n_months'       => isset( $_POST['wvm_n_months'] ) ? absint( $_POST['wvm_n_months'] ) : 6,
			'interval_weeks' => isset( $_POST['wvm_interval_weeks'] ) ? absint( $_POST['wvm_interval_weeks'] ) : 2,
			'month_day'      => isset( $_POST['wvm_month_day'] ) ? absint( $_POST['wvm_month_day'] ) : 1,
		);
		$start_from = isset( $_POST['wvm_start_from'] ) ? sanitize_text_field( wp_unslash( $_POST['wvm_start_from'] ) ) : '';
		$notice     = __( 'Nothing to do.', 'wordpress-venue-management' );

		if ( 'titles' === $task ) {
			$count  = WVM_Series::assign_empty_from_titles();
			$notice = sprintf(
				/* translators: %d: event count */
				__( 'Assigned a series on %d events that had none.', 'wordpress-venue-management' ),
				$count
			);
		} elseif ( 'populate' === $task ) {
			$result = ( new WVM_Recurrence() )->auto_populate();
			$notice = sprintf(
				/* translators: 1: created count, 2: series count */
				__( 'Created %1$d events across %2$d series.', 'wordpress-venue-management' ),
				$result['created'],
				$result['series_processed']
			);
		} elseif ( $series_id > 0 && $template > 0 && in_array( $task, array( 'save', 'generate' ), true ) ) {
			WVM_Series::assign( $template, $this->series_name( $series_id ) );
			WVM_Recurrence::save_config( $series_id, $config );
			if ( 'save' === $task ) {
				$notice = __( 'Rule saved. The weekly cron will extend it.', 'wordpress-venue-management' );
			} else {
				$result = ( new WVM_Recurrence() )->generate(
					$series_id,
					$template,
					$config['recurrence'],
					$config['n_weeks'],
					$config['n_months'],
					$config['interval_weeks'],
					$config['month_day'],
					$start_from
				);
				$notice = sprintf(
					/* translators: %d: created count */
					__( 'Created %d events. Dates that already existed were skipped.', 'wordpress-venue-management' ),
					$result['created']
				);
				if ( $result['errors'] ) {
					$notice .= ' ' . implode( ' ', $result['errors'] );
				}
			}
		} elseif ( 'copy' === $task && $series_id > 0 && $template > 0 ) {
			$fields = isset( $_POST['wvm_copy'] ) && is_array( $_POST['wvm_copy'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['wvm_copy'] ) ) : array();
			$count  = $this->copy_fields( $series_id, $template, $fields );
			$notice = sprintf(
				/* translators: %d: event count */
				__( 'Updated %d events in the series.', 'wordpress-venue-management' ),
				$count
			);
		}

		wp_safe_redirect(
			add_query_arg(
				'wvm_notice',
				$notice,
				admin_url( 'edit.php?post_type=' . WVM_Post_Type::slug() . '&page=wvm-series-tools' )
			)
		);
		exit;
	}

	private function series_name( int $term_id ): string {
		$term = get_term( $term_id, WVM_Series::TAXONOMY );

		return $term instanceof WP_Term ? $term->name : '';
	}

	/**
	 * @param string[] $fields Field keys.
	 */
	private function copy_fields( int $series_id, int $template_id, array $fields ): int {
		if ( empty( $fields ) ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => WVM_Post_Type::slug(),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => WVM_Series::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => $series_id,
					),
				),
			)
		);

		$count = 0;
		foreach ( $ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id === $template_id ) {
				continue;
			}

			$update = array( 'ID' => $post_id );
			if ( in_array( 'content', $fields, true ) ) {
				$update['post_content'] = get_post_field( 'post_content', $template_id );
			}
			if ( in_array( 'excerpt', $fields, true ) ) {
				$update['post_excerpt'] = get_post_field( 'post_excerpt', $template_id );
			}
			if ( count( $update ) > 1 ) {
				wp_update_post( $update );
			}
			if ( in_array( 'cost', $fields, true ) ) {
				WVM_Meta::update( $post_id, 'cost', WVM_Meta::get( $template_id, 'cost' ) );
			}
			if ( in_array( 'venue', $fields, true ) ) {
				WVM_Meta::update( $post_id, 'venue_name', WVM_Meta::get( $template_id, 'venue_name' ) );
				WVM_Meta::update( $post_id, 'venue_address', WVM_Meta::get( $template_id, 'venue_address' ) );
				$venue_term = (int) get_post_meta( $template_id, '_wvm_venue_term_id', true );
				WVM_Venues::assign( $post_id, $venue_term );
			}
			if ( in_array( 'ticket', $fields, true ) ) {
				WVM_Meta::update( $post_id, 'ticket_url', WVM_Meta::get( $template_id, 'ticket_url' ) );
			}
			if ( in_array( 'image', $fields, true ) ) {
				$thumb = (int) get_post_thumbnail_id( $template_id );
				if ( $thumb > 0 ) {
					set_post_thumbnail( $post_id, $thumb );
				}
			}
			++$count;
		}

		return $count;
	}
}
