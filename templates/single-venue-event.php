<?php
/**
 * Single event.
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

if ( have_posts() ) :
	while ( have_posts() ) :
		the_post();
		$post_id    = get_the_ID();
		$start      = WVM_Meta::format_start( $post_id );
		$start_raw  = WVM_Meta::get( $post_id, 'start' );
		$cost       = WVM_Meta::cost_display( $post_id );
		$venue      = WVM_Meta::get( $post_id, 'venue_name' );
		$address    = WVM_Meta::get( $post_id, 'venue_address' );
		$ticket_url = WVM_Meta::get( $post_id, 'ticket_url' );
		$image_id   = WVM_Series::display_image_id( $post_id );
		$events_url = get_post_type_archive_link( WVM_Post_Type::slug() );
		$gcal_url   = WVM_Feeds::google_url( $post_id );
		$ics_url    = WVM_Feeds::ics_url( $post_id );
		$map_src    = $address ? 'https://www.google.com/maps?q=' . rawurlencode( $address ) . '&output=embed' : '';
		?>
		<main class="wvm-event">
			<article>
				<p class="wvm-event__back"><a href="<?php echo esc_url( (string) $events_url ); ?>"><?php esc_html_e( 'All events', 'wordpress-venue-management' ); ?></a></p>
				<header class="wvm-event__header">
					<h1><?php the_title(); ?></h1>
					<?php if ( $start ) : ?>
						<p><time datetime="<?php echo esc_attr( $start_raw ); ?>"><?php echo esc_html( $start ); ?></time></p>
					<?php endif; ?>
					<?php if ( $venue ) : ?>
						<p class="wvm-event__venue"><?php echo esc_html( $venue ); ?></p>
					<?php endif; ?>
					<?php if ( $address ) : ?>
						<p class="wvm-event__address"><?php echo esc_html( $address ); ?></p>
					<?php endif; ?>
					<?php if ( $cost ) : ?>
						<p class="wvm-event__cost"><?php echo esc_html( $cost ); ?></p>
					<?php endif; ?>
					<?php if ( $gcal_url || $ics_url ) : ?>
						<ul class="wvm-event__calendar">
							<?php if ( $gcal_url ) : ?>
								<li><a href="<?php echo esc_url( $gcal_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Calendar', 'wordpress-venue-management' ); ?></a></li>
							<?php endif; ?>
							<?php if ( $ics_url ) : ?>
								<li><a href="<?php echo esc_url( $ics_url ); ?>"><?php esc_html_e( 'Download .ics', 'wordpress-venue-management' ); ?></a></li>
							<?php endif; ?>
						</ul>
					<?php endif; ?>
				</header>
				<?php if ( $image_id ) : ?>
					<figure class="wvm-event__figure"><?php echo wp_get_attachment_image( $image_id, 'large' ); ?></figure>
				<?php endif; ?>
				<div class="wvm-event__content"><?php the_content(); ?></div>
				<?php if ( $ticket_url ) : ?>
					<p><a class="wvm-event__tickets" href="<?php echo esc_url( $ticket_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Tickets', 'wordpress-venue-management' ); ?></a></p>
				<?php endif; ?>
				<?php if ( $map_src ) : ?>
					<iframe class="wvm-event__map" src="<?php echo esc_url( $map_src ); ?>" title="<?php esc_attr_e( 'Venue map', 'wordpress-venue-management' ); ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
				<?php endif; ?>
			</article>
		</main>
		<?php
	endwhile;
else :
	?>
	<main class="wvm-event"><p><?php esc_html_e( 'This event could not be found.', 'wordpress-venue-management' ); ?></p></main>
	<?php
endif;

get_footer();
