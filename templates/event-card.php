<?php
/**
 * Event card. Expects $post_id and the post to be set up.
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id   = isset( $post_id ) ? (int) $post_id : get_the_ID();
$start     = WVM_Meta::format_start( $post_id );
$start_raw = WVM_Meta::get( $post_id, 'start' );
$venue     = WVM_Meta::get( $post_id, 'venue_name' );
$series    = WVM_Meta::get( $post_id, 'series' );
$cost      = WVM_Meta::cost_display( $post_id );
$image_id  = WVM_Series::display_image_id( $post_id );
?>
<article class="wvm-card">
	<a class="wvm-card__link" href="<?php the_permalink(); ?>">
		<?php if ( $image_id ) : ?>
			<?php echo wp_get_attachment_image( $image_id, 'medium_large', false, array( 'class' => 'wvm-card__image' ) ); ?>
		<?php endif; ?>
		<div class="wvm-card__body">
			<?php if ( $start ) : ?>
				<time datetime="<?php echo esc_attr( $start_raw ); ?>"><?php echo esc_html( $start ); ?></time>
			<?php endif; ?>
			<?php if ( $series ) : ?>
				<p class="wvm-card__series"><?php echo esc_html( $series ); ?></p>
			<?php endif; ?>
			<h3><?php the_title(); ?></h3>
			<?php if ( $venue ) : ?>
				<p class="wvm-card__venue"><?php echo esc_html( $venue ); ?></p>
			<?php endif; ?>
			<?php if ( $cost ) : ?>
				<p class="wvm-card__cost"><?php echo esc_html( $cost ); ?></p>
			<?php endif; ?>
		</div>
	</a>
</article>
