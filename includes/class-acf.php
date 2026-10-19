<?php
/**
 * Optional Advanced Custom Fields group. The meta box covers the same fields when ACF is absent.
 *
 * @package WordPress_Venue_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a venue select and ticket URL, and writes them into event meta.
 */
final class WVM_Acf {

	public function hooks(): void {
		add_action( 'acf/init', array( $this, 'register_group' ) );
		add_filter( 'acf/load_field/name=wvm_venue', array( $this, 'load_venue_choices' ) );
		add_action( 'acf/save_post', array( $this, 'sync' ), 20 );
	}

	/**
	 * Whether Advanced Custom Fields can register a field group.
	 */
	public static function is_active(): bool {
		return function_exists( 'acf_add_local_field_group' );
	}

	/**
	 * Register the optional venue and ticket field group.
	 */
	public function register_group(): void {
		if ( ! self::is_active() ) {
			return;
		}

		acf_add_local_field_group(
			array(
				'key'      => 'group_wvm_event_location',
				'title'    => __( 'Event location & tickets', 'wordpress-venue-management' ),
				'fields'   => array(
					array(
						'key'           => 'field_wvm_venue',
						'label'         => __( 'Venue', 'wordpress-venue-management' ),
						'name'          => 'wvm_venue',
						'type'          => 'select',
						'choices'       => array( 'other' => __( 'Other address', 'wordpress-venue-management' ) ),
						'allow_null'    => 1,
						'return_format' => 'value',
					),
					array(
						'key'               => 'field_wvm_venue_other_name',
						'label'             => __( 'Other venue name', 'wordpress-venue-management' ),
						'name'              => 'wvm_venue_other_name',
						'type'              => 'text',
						'conditional_logic' => array(
							array(
								array(
									'field'    => 'field_wvm_venue',
									'operator' => '==',
									'value'    => 'other',
								),
							),
						),
					),
					array(
						'key'               => 'field_wvm_venue_other_address',
						'label'             => __( 'Address', 'wordpress-venue-management' ),
						'name'              => 'wvm_venue_other_address',
						'type'              => 'textarea',
						'rows'              => 2,
						'conditional_logic' => array(
							array(
								array(
									'field'    => 'field_wvm_venue',
									'operator' => '==',
									'value'    => 'other',
								),
							),
						),
					),
					array(
						'key'   => 'field_wvm_ticket_url',
						'label' => __( 'Ticket URL', 'wordpress-venue-management' ),
						'name'  => 'wvm_ticket_url',
						'type'  => 'url',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => WVM_Post_Type::slug(),
						),
					),
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $field ACF field.
	 * @return array<string, mixed>
	 */
	public function load_venue_choices( array $field ): array {
		$choices = array(
			''      => __( '— None —', 'wordpress-venue-management' ),
			'other' => __( 'Other address', 'wordpress-venue-management' ),
		);
		foreach ( WVM_Venues::all() as $venue ) {
			if ( $venue['term_id'] > 0 ) {
				$choices[ (string) $venue['term_id'] ] = $venue['label'];
			}
		}
		$field['choices'] = $choices;

		return $field;
	}

	/**
	 * @param int|string $post_id Post ID.
	 */
	public function sync( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id < 1 || get_post_type( $post_id ) !== WVM_Post_Type::slug() || ! function_exists( 'get_field' ) ) {
			return;
		}

		if ( ! isset( $_POST['acf'] ) || ! is_array( $_POST['acf'] ) ) {
			return;
		}

		$choice = (string) get_field( 'wvm_venue', $post_id );
		if ( 'other' === $choice ) {
			WVM_Venues::assign( $post_id, 0 );
			WVM_Meta::update( $post_id, 'venue_name', sanitize_text_field( (string) get_field( 'wvm_venue_other_name', $post_id ) ) );
			WVM_Meta::update( $post_id, 'venue_address', sanitize_textarea_field( (string) get_field( 'wvm_venue_other_address', $post_id ) ) );
		} elseif ( '' !== $choice && is_numeric( $choice ) ) {
			$term = get_term( (int) $choice, WVM_Venues::TAXONOMY );
			if ( $term instanceof WP_Term ) {
				$venue = WVM_Venues::from_term( $term );
				WVM_Venues::assign( $post_id, (int) $term->term_id );
				WVM_Meta::update( $post_id, 'venue_name', $venue['name'] );
				WVM_Meta::update( $post_id, 'venue_address', $venue['address'] );
			}
		} else {
			WVM_Venues::assign( $post_id, 0 );
			WVM_Meta::update( $post_id, 'venue_name', '' );
			WVM_Meta::update( $post_id, 'venue_address', '' );
		}

		$ticket = (string) get_field( 'wvm_ticket_url', $post_id );
		WVM_Meta::update( $post_id, 'ticket_url', esc_url_raw( $ticket ) );
	}
}
