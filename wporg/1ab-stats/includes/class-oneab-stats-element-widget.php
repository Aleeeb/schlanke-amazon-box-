<?php
/**
 * Classic widget that shows one element in a sidebar.
 *
 * @package OneAB_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Widget for themes with classic widget areas.
 */
final class OneAB_Stats_Element_Widget extends WP_Widget {

	/**
	 * Sets the widget up.
	 */
	public function __construct() {
		parent::__construct(
			'oneab_stats_element',
			__( '1AB Stats element', '1ab-stats' ),
			array( 'description' => __( 'A button, image or card with an A/B test.', '1ab-stats' ) )
		);
	}

	/**
	 * Prints the widget.
	 *
	 * @param array $args     Markup of the widget area.
	 * @param array $instance Settings of this widget.
	 */
	public function widget( $args, $instance ) {
		$html = OneAB_Stats_Elements::render( isset( $instance['id'] ) ? absint( $instance['id'] ) : 0 );
		if ( '' === $html ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme markup around the widget; the element itself is escaped in render().
		echo $args['before_widget'] . $html . $args['after_widget'];
	}

	/**
	 * Prints the settings of the widget.
	 *
	 * @param array $instance Settings of this widget.
	 * @return string
	 */
	public function form( $instance ) {
		$current = isset( $instance['id'] ) ? absint( $instance['id'] ) : 0;
		$all     = OneAB_Stats_Elements::all();
		if ( ! $all ) {
			echo '<p>' . esc_html__( 'Choose one of your elements. You create them under 1AB Stats, tab "Buttons, images and cards".', '1ab-stats' ) . '</p>';
			return '';
		}
		echo '<p><label for="' . esc_attr( $this->get_field_id( 'id' ) ) . '">' . esc_html__( 'Element', '1ab-stats' ) . '</label> ';
		echo '<select class="widefat" id="' . esc_attr( $this->get_field_id( 'id' ) ) . '" name="' . esc_attr( $this->get_field_name( 'id' ) ) . '">';
		foreach ( $all as $id => $element ) {
			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $id, $current, false ) . '>' . esc_html( OneAB_Stats_Elements::label( $id, $element ) ) . '</option>';
		}
		echo '</select></p>';
		return '';
	}

	/**
	 * Cleans the settings of the widget.
	 *
	 * @param array $new_instance New settings.
	 * @param array $old_instance Old settings.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		unset( $old_instance );
		return array( 'id' => isset( $new_instance['id'] ) ? absint( $new_instance['id'] ) : 0 );
	}
}
