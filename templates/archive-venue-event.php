<?php
/**
 * Upcoming and past event archive.
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$venue_slug  = sanitize_title( (string) get_query_var( WVM_Venues::QUERY_VAR ) );
$venue       = $venue_slug ? WVM_Venues::by_slug( $venue_slug ) : null;
$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$upcoming    = wvm_get_upcoming_events( $venue_slug, $search );
$past        = wvm_get_past_events( $venue_slug, $search );
$events_url  = get_post_type_archive_link( WVM_Post_Type::slug() );
$venues      = WVM_Venues::all();
$filter_base = ( $venue && $events_url ) ? trailingslashit( $events_url ) . $venue_slug . '/' : $events_url;
?>
<main class="wvm-events">
	<header class="wvm-events__header">
		<h1><?php echo $venue ? esc_html( sprintf( /* translators: %s: venue name */ __( 'Events at %s', 'wordpress-venue-management' ), $venue['name'] ) ) : esc_html__( 'Events', 'wordpress-venue-management' ); ?></h1>
	</header>

	<form class="wvm-events__filters" method="get" action="<?php echo esc_url( (string) $filter_base ); ?>">
		<?php if ( count( $venues ) > 1 || ( 1 === count( $venues ) && $venues[0]['term_id'] > 0 ) ) : ?>
			<label>
				<?php esc_html_e( 'Location', 'wordpress-venue-management' ); ?>
				<select onchange="if (this.value) { window.location.href = this.value; }">
					<option value="<?php echo esc_url( (string) $events_url ); ?>" <?php selected( ! $venue ); ?>><?php esc_html_e( 'All locations', 'wordpress-venue-management' ); ?></option>
					<?php foreach ( $venues as $item ) : ?>
						<?php if ( '' === $item['slug'] ) : ?>
							<?php continue; ?>
						<?php endif; ?>
						<?php $url = trailingslashit( (string) $events_url ) . $item['slug'] . '/'; ?>
						<option value="<?php echo esc_url( $url ); ?>" <?php selected( $venue_slug, $item['slug'] ); ?>><?php echo esc_html( $item['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		<?php endif; ?>
		<label>
			<?php esc_html_e( 'Search', 'wordpress-venue-management' ); ?>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>">
		</label>
		<button type="submit"><?php esc_html_e( 'Search', 'wordpress-venue-management' ); ?></button>
	</form>

	<?php if ( '' !== $search && ! $upcoming->have_posts() && ! $past->have_posts() ) : ?>
		<p><?php echo esc_html( sprintf( /* translators: %s: search query */ __( 'No events found for “%s”.', 'wordpress-venue-management' ), $search ) ); ?></p>
	<?php endif; ?>

	<section class="wvm-events__section" aria-labelledby="wvm-upcoming">
		<h2 id="wvm-upcoming"><?php esc_html_e( 'Upcoming', 'wordpress-venue-management' ); ?></h2>
		<?php if ( $upcoming->have_posts() ) : ?>
			<div class="wvm-events__grid">
				<?php
				while ( $upcoming->have_posts() ) {
					$upcoming->the_post();
					wvm_render_event_card( get_the_ID() );
				}
				wp_reset_postdata();
				?>
			</div>
		<?php else : ?>
			<p><?php esc_html_e( 'No upcoming events.', 'wordpress-venue-management' ); ?></p>
		<?php endif; ?>
	</section>

	<section class="wvm-events__section" id="wvm-past" aria-labelledby="wvm-past-heading">
		<h2 id="wvm-past-heading"><?php esc_html_e( 'Past', 'wordpress-venue-management' ); ?></h2>
		<?php if ( $past->have_posts() ) : ?>
			<div class="wvm-events__grid">
				<?php
				while ( $past->have_posts() ) {
					$past->the_post();
					wvm_render_event_card( get_the_ID() );
				}
				wp_reset_postdata();
				?>
			</div>
		<?php else : ?>
			<p><?php esc_html_e( 'No past events.', 'wordpress-venue-management' ); ?></p>
		<?php endif; ?>
	</section>
</main>
<?php
get_footer();
