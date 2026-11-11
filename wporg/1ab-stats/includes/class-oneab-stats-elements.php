<?php
/**
 * Buttons, images and cards that can be placed anywhere, each with its own A/B test.
 *
 * @package OneAB_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elements (button, image, card) and their A/B tests.
 */
final class OneAB_Stats_Elements {

	const OPTION = 'oneab_stats_elements';

	/**
	 * Test numbers of elements start here, far away from the numbers of the read-next test.
	 */
	const FIRST_TEST = 1000000;

	/**
	 * Whether the current request has already loaded the front-end script.
	 *
	 * @var bool
	 */
	private static $loaded = false;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_shortcode( 'oneab_element', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'block' ) );
		add_action( 'widgets_init', array( __CLASS__, 'widget' ) );
		add_filter( 'widget_types_to_hide_from_legacy_widget_block', array( __CLASS__, 'hide_widget' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'style' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'admin_post_oneab_stats_element', array( __CLASS__, 'save' ) );
	}

	/**
	 * All elements, keyed by their number.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		$out    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $id => $element ) {
			$out[ (int) $id ] = self::clean( $element );
		}
		return $out;
	}

	/**
	 * One element.
	 *
	 * @param int $id Number.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ (int) $id ] ) ? $all[ (int) $id ] : null;
	}

	/**
	 * Cleans one variant of an element.
	 *
	 * @param mixed $variant Raw variant.
	 * @return array
	 */
	public static function variant( $variant ) {
		$variant = is_array( $variant ) ? $variant : array();
		$text    = function ( $key ) use ( $variant ) {
			return isset( $variant[ $key ] ) && is_string( $variant[ $key ] ) ? sanitize_text_field( $variant[ $key ] ) : '';
		};
		$color   = isset( $variant['color'] ) && is_string( $variant['color'] ) ? sanitize_hex_color( $variant['color'] ) : '';
		return array(
			'title' => $text( 'title' ),
			'text'  => isset( $variant['text'] ) && is_string( $variant['text'] ) ? sanitize_textarea_field( $variant['text'] ) : '',
			'label' => $text( 'label' ),
			'url'   => isset( $variant['url'] ) && is_string( $variant['url'] ) ? esc_url_raw( trim( $variant['url'] ) ) : '',
			'image' => isset( $variant['image'] ) ? absint( $variant['image'] ) : 0,
			'color' => $color ? $color : '#2271b1',
		);
	}

	/**
	 * Cleans one element.
	 *
	 * @param mixed $element Raw element.
	 * @return array
	 */
	public static function clean( $element ) {
		$element = is_array( $element ) ? $element : array();
		$type    = isset( $element['type'] ) ? $element['type'] : '';
		return array(
			'name'      => isset( $element['name'] ) && is_string( $element['name'] ) ? sanitize_text_field( $element['name'] ) : '',
			'type'      => in_array( $type, array( 'button', 'image', 'card' ), true ) ? $type : 'button',
			'sponsored' => empty( $element['sponsored'] ) ? 0 : 1,
			'a'         => self::variant( isset( $element['a'] ) ? $element['a'] : array() ),
			'b'         => self::variant( isset( $element['b'] ) ? $element['b'] : array() ),
			'ab_on'     => empty( $element['ab_on'] ) ? 0 : 1,
			'test'      => isset( $element['test'] ) ? absint( $element['test'] ) : 0,
			'start'     => isset( $element['start'] ) && is_string( $element['start'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $element['start'] ) ? $element['start'] : '',
		);
	}

	/**
	 * Names of the three kinds.
	 *
	 * @return array
	 */
	public static function types() {
		return array(
			'button' => __( 'Button', '1ab-stats' ),
			'image'  => __( 'Image', '1ab-stats' ),
			'card'   => __( 'Card', '1ab-stats' ),
		);
	}

	/**
	 * Black or white, whichever reads better on the given colour.
	 *
	 * @param string $hex Background colour.
	 * @return string
	 */
	private static function ink( $hex ) {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$light = ( 299 * hexdec( substr( $hex, 0, 2 ) ) + 587 * hexdec( substr( $hex, 2, 2 ) ) + 114 * hexdec( substr( $hex, 4, 2 ) ) ) / 1000;
		return $light > 150 ? '#000' : '#fff';
	}

	/**
	 * Markup of one variant.
	 *
	 * @param array $element Element.
	 * @param array $variant Variant of it.
	 * @return string
	 */
	private static function markup( $element, $variant ) {
		$url   = $variant['url'];
		$attrs = '';
		if ( $url ) {
			$attrs = ' href="' . esc_url( $url ) . '"';
			if ( $element['sponsored'] ) {
				$attrs .= ' rel="sponsored nofollow noopener" target="_blank"';
			}
		}
		$image = $variant['image'] ? wp_get_attachment_image( $variant['image'], 'large', false, array( 'loading' => 'lazy' ) ) : '';
		$style = ' style="background:' . esc_attr( $variant['color'] ) . ';color:' . esc_attr( self::ink( $variant['color'] ) ) . '"';

		if ( 'image' === $element['type'] ) {
			if ( ! $image ) {
				return '';
			}
			return $url ? '<a class="oneab-el__image"' . $attrs . '>' . $image . '</a>' : '<span class="oneab-el__image">' . $image . '</span>';
		}
		if ( 'card' === $element['type'] ) {
			if ( '' === $variant['title'] && '' === $variant['text'] && ! $image ) {
				return '';
			}
			$tag   = $url ? 'a' : 'div';
			$html  = '<' . $tag . ' class="oneab-el__card"' . $attrs . '>';
			$html .= $image ? '<span class="oneab-el__media">' . $image . '</span>' : '';
			$html .= '<span class="oneab-el__body">';
			$html .= '' !== $variant['title'] ? '<strong class="oneab-el__title">' . esc_html( $variant['title'] ) . '</strong>' : '';
			$html .= '' !== $variant['text'] ? '<span class="oneab-el__text">' . nl2br( esc_html( $variant['text'] ) ) . '</span>' : '';
			$html .= '' !== $variant['label'] && $url ? '<span class="oneab-el__btn"' . $style . '>' . esc_html( $variant['label'] ) . '</span>' : '';
			return $html . '</span></' . $tag . '>';
		}
		if ( '' === $variant['label'] || ! $url ) {
			return '';
		}
		return '<a class="oneab-el__btn"' . $attrs . $style . '>' . esc_html( $variant['label'] ) . '</a>';
	}

	/**
	 * Markup of an element. Search engines and visitors without JavaScript always get variant A.
	 *
	 * @param int $id Number of the element.
	 * @return string
	 */
	public static function render( $id ) {
		$element = self::get( $id );
		if ( ! $element ) {
			return '';
		}
		$a = self::markup( $element, $element['a'] );
		if ( '' === $a ) {
			return '';
		}
		$html    = '<div class="oneab-el oneab-el--' . esc_attr( $element['type'] ) . '"';
		$b       = $element['ab_on'] && $element['test'] ? self::markup( $element, $element['b'] ) : '';
		$preview = is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed();
		if ( '' === $b || $preview ) {
			return $html . '>' . $a . '</div>';
		}
		$html .= ' data-oneab-el="' . esc_attr( (string) $element['test'] ) . '">' . $a . '<template>' . $b . '</template></div>';
		// The draw happens right here, before the element is painted, so nothing flickers.
		$html .= wp_get_inline_script_tag( '(function(s){var e=s&&s.previousElementSibling,t=e&&e.querySelector("template");if(!t||e.dataset.oneabV)return;if(Math.random()<.5){var c=t.content.cloneNode(true);e.textContent="";e.appendChild(c);e.dataset.oneabV="b"}else{e.dataset.oneabV="a"}})(document.currentScript);' );
		if ( ! self::$loaded ) {
			self::$loaded = true;
			wp_enqueue_script(
				'oneab-stats-elements',
				plugins_url( 'assets/elements.js', ONEAB_STATS_FILE ),
				array(),
				ONEAB_STATS_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
		return $html;
	}

	/**
	 * Shortcode [oneab_element id="1"].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'oneab_element' );
		return self::render( absint( $atts['id'] ) );
	}

	/**
	 * Loads the small style sheet when at least one element exists.
	 */
	public static function style() {
		if ( get_option( self::OPTION ) ) {
			wp_enqueue_style( 'oneab-stats-elements', plugins_url( 'assets/elements.css', ONEAB_STATS_FILE ), array(), ONEAB_STATS_VERSION );
		}
	}

	/**
	 * Registers the block for the editor, the site editor and block-based widget areas.
	 */
	public static function block() {
		wp_register_script( 'oneab-stats-block', plugins_url( 'assets/block.js', ONEAB_STATS_FILE ), array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render' ), ONEAB_STATS_VERSION, true );
		wp_register_style( 'oneab-stats-elements', plugins_url( 'assets/elements.css', ONEAB_STATS_FILE ), array(), ONEAB_STATS_VERSION );
		register_block_type(
			'oneab-stats/element',
			array(
				'api_version'     => 2,
				'title'           => __( '1AB Stats element', '1ab-stats' ),
				'description'     => __( 'A button, image or card with an A/B test.', '1ab-stats' ),
				'category'        => 'widgets',
				'icon'            => 'chart-bar',
				'attributes'      => array(
					'id' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'left', 'center', 'right' ),
				),
				'editor_script'   => 'oneab-stats-block',
				'editor_style'    => 'oneab-stats-elements',
				'render_callback' => array( __CLASS__, 'render_block' ),
			)
		);
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_data' ) );
	}

	/**
	 * Hands the list of elements to the block in the editor.
	 */
	public static function block_data() {
		$options = array(
			array(
				'value' => 0,
				'label' => __( 'Choose an element', '1ab-stats' ),
			),
		);
		foreach ( self::all() as $id => $element ) {
			$options[] = array(
				'value' => $id,
				'label' => self::label( $id, $element ),
			);
		}
		$data = array(
			'options' => $options,
			'title'   => __( '1AB Stats element', '1ab-stats' ),
			'choose'  => __( 'Element', '1ab-stats' ),
			'empty'   => __( 'Choose one of your elements. You create them under 1AB Stats, tab "Buttons, images and cards".', '1ab-stats' ),
			'manage'  => admin_url( 'admin.php?page=' . OneAB_Stats_Admin::SLUG . '&tab=elements' ),
			'link'    => __( 'Create or edit elements', '1ab-stats' ),
		);
		wp_add_inline_script( 'oneab-stats-block', 'window.oneabElements=' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Output of the block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_block( $attributes ) {
		$html = self::render( isset( $attributes['id'] ) ? absint( $attributes['id'] ) : 0 );
		if ( '' === $html ) {
			return '';
		}
		$align = isset( $attributes['align'] ) && in_array( $attributes['align'], array( 'left', 'center', 'right' ), true ) ? $attributes['align'] : '';
		return $align ? '<div class="oneab-el-align oneab-el-align--' . esc_attr( $align ) . '">' . $html . '</div>' : $html;
	}

	/**
	 * Registers the classic widget for themes with classic sidebars.
	 */
	public static function widget() {
		require_once ONEAB_STATS_DIR . 'includes/class-oneab-stats-element-widget.php';
		register_widget( 'OneAB_Stats_Element_Widget' );
	}

	/**
	 * Keeps the classic widget out of the block-based widget screen, where the block does the same job.
	 *
	 * @param array $types Widget ids.
	 * @return array
	 */
	public static function hide_widget( $types ) {
		$types[] = 'oneab_stats_element';
		return $types;
	}

	/**
	 * Name shown in lists.
	 *
	 * @param int   $id      Number.
	 * @param array $element Element.
	 * @return string
	 */
	public static function label( $id, $element ) {
		$types = self::types();
		$name  = '' !== $element['name'] ? $element['name'] : '#' . $id;
		return $name . ' (' . $types[ $element['type'] ] . ')';
	}

	/**
	 * Adds one event of an element's running test to the daily counters.
	 *
	 * @param array $data Decoded report.
	 */
	public static function count( array $data ) {
		$test = isset( $data['id'] ) ? (int) $data['id'] : 0;
		foreach ( self::all() as $element ) {
			if ( $element['ab_on'] && $element['test'] === $test ) {
				OneAB_Stats_Next::add( $test, $data );
				return;
			}
		}
	}

	/**
	 * Loads the media picker on the tab.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function admin_assets( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter.
		if ( 'toplevel_page_' . OneAB_Stats_Admin::SLUG !== $hook || ! isset( $_GET['tab'] ) || 'elements' !== $_GET['tab'] ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'oneab-stats-elements', plugins_url( 'assets/elements.css', ONEAB_STATS_FILE ), array(), ONEAB_STATS_VERSION );
		wp_enqueue_script( 'oneab-stats-admin-elements', plugins_url( 'assets/admin-elements.js', ONEAB_STATS_FILE ), array( 'jquery' ), ONEAB_STATS_VERSION, true );
		wp_add_inline_script(
			'oneab-stats-admin-elements',
			'window.oneabAdmin=' . wp_json_encode(
				array(
					'pick' => __( 'Choose image', '1ab-stats' ),
					'use'  => __( 'Use this image', '1ab-stats' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Saves, deletes or switches an element.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', '1ab-stats' ) );
		}
		check_admin_referer( 'oneab_stats_element' );
		$all  = self::all();
		$id   = isset( $_POST['el'] ) ? absint( $_POST['el'] ) : 0;
		$step = isset( $_POST['oneab_step'] ) ? sanitize_key( wp_unslash( $_POST['oneab_step'] ) ) : '';
		$back = admin_url( 'admin.php?page=' . OneAB_Stats_Admin::SLUG . '&tab=elements' );

		if ( 'delete' === $step ) {
			unset( $all[ $id ] );
			update_option( self::OPTION, $all, false );
			wp_safe_redirect( $back . '&saved=1' );
			exit;
		}

		$old = isset( $all[ $id ] ) ? $all[ $id ] : null;
		if ( ! $old ) {
			$id = $all ? max( array_keys( $all ) ) + 1 : 1;
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field by field in clean().
		$element = self::clean(
			array(
				'name'      => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
				'type'      => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '',
				'sponsored' => ! empty( $_POST['sponsored'] ),
				'a'         => isset( $_POST['a'] ) ? wp_unslash( $_POST['a'] ) : array(),
				'b'         => isset( $_POST['b'] ) ? wp_unslash( $_POST['b'] ) : array(),
				'ab_on'     => ! empty( $_POST['ab_on'] ),
				'test'      => $old ? $old['test'] : 0,
				'start'     => $old ? $old['start'] : '',
			)
		);
		// phpcs:enable
		$was_on = $old ? $old['ab_on'] : 0;
		if ( 'use_b' === $step ) {
			$element['a']     = $element['b'];
			$element['ab_on'] = 0;
		} elseif ( 'use_a' === $step ) {
			$element['ab_on'] = 0;
		}
		// A new number separates the counts of a new test from those of earlier ones.
		if ( 'restart' === $step || ( $element['ab_on'] && ! $was_on ) ) {
			$next             = max( self::FIRST_TEST, (int) get_option( 'oneab_stats_test_seq', 0 ) + 1 );
			$element['test']  = $next;
			$element['start'] = wp_date( 'Y-m-d' );
			$element['ab_on'] = 1;
			update_option( 'oneab_stats_test_seq', $next, false );
		}
		$all[ $id ] = $element;
		update_option( self::OPTION, $all, false );
		wp_safe_redirect( $back . '&el=' . $id . '&saved=1' );
		exit;
	}

	/**
	 * Prints the fields of one variant.
	 *
	 * @param string $key     Field name, a or b.
	 * @param array  $variant Variant.
	 */
	private static function variant_fields( $key, $variant ) {
		$name = function ( $field ) use ( $key ) {
			return esc_attr( $key . '[' . $field . ']' );
		};
		$src  = $variant['image'] ? wp_get_attachment_image_url( $variant['image'], 'medium' ) : '';
		echo '<div class="oneab-field" data-types="image card"><p>' . esc_html__( 'Image', '1ab-stats' ) . '</p>';
		echo '<div class="oneab-pick"><img class="oneab-pick__preview" alt="" src="' . esc_url( (string) $src ) . '"' . ( $src ? '' : ' hidden' ) . '>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure.
		echo '<input type="hidden" class="oneab-pick__id" name="' . $name( 'image' ) . '" value="' . esc_attr( (string) $variant['image'] ) . '">';
		echo '<button type="button" class="button oneab-pick__choose">' . esc_html__( 'Choose image', '1ab-stats' ) . '</button> ';
		echo '<button type="button" class="button-link oneab-pick__clear">' . esc_html__( 'Remove', '1ab-stats' ) . '</button></div></div>';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Field names are escaped in the closure.
		echo '<p class="oneab-field" data-types="card"><label>' . esc_html__( 'Heading', '1ab-stats' ) . '<br><input type="text" class="regular-text" name="' . $name( 'title' ) . '" value="' . esc_attr( $variant['title'] ) . '"></label></p>';
		echo '<p class="oneab-field" data-types="card"><label>' . esc_html__( 'Text', '1ab-stats' ) . '<br><textarea class="large-text" rows="3" name="' . $name( 'text' ) . '">' . esc_textarea( $variant['text'] ) . '</textarea></label></p>';
		echo '<p class="oneab-field" data-types="button card"><label>' . esc_html__( 'Button label', '1ab-stats' ) . '<br><input type="text" class="regular-text" name="' . $name( 'label' ) . '" value="' . esc_attr( $variant['label'] ) . '"></label></p>';
		echo '<p class="oneab-field" data-types="button card"><label>' . esc_html__( 'Button colour', '1ab-stats' ) . '<br><input type="color" name="' . $name( 'color' ) . '" value="' . esc_attr( $variant['color'] ) . '"></label></p>';
		echo '<p class="oneab-field" data-types="button image card"><label>' . esc_html__( 'Link target', '1ab-stats' ) . '<br><input type="url" class="regular-text" name="' . $name( 'url' ) . '" value="' . esc_attr( $variant['url'] ) . '" placeholder="https://"></label></p>';
		// phpcs:enable
	}

	/**
	 * Prints the tab: the list of elements, or the form of one element.
	 */
	public static function tab() {
		$all  = self::all();
		$base = admin_url( 'admin.php?page=' . OneAB_Stats_Admin::SLUG . '&tab=elements' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter.
		$open = isset( $_GET['el'] ) ? sanitize_key( wp_unslash( $_GET['el'] ) ) : '';
		if ( 'new' === $open || ( '' !== $open && isset( $all[ (int) $open ] ) ) ) {
			self::form( 'new' === $open ? 0 : (int) $open, 'new' === $open ? self::clean( array() ) : $all[ (int) $open ], $base );
			return;
		}

		echo '<div class="oneab-card"><h2>' . esc_html__( 'Buttons, images and cards', '1ab-stats' ) . '</h2>';
		echo '<p>' . esc_html__( 'Build a button, an image or a card once and place it wherever you like: on the home page, inside any page or post, or in the sidebar. Give it a second variant, and the plugin shows you which one gets more clicks.', '1ab-stats' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'To place an element, add the block "1AB Stats element" in the editor or in a widget area, or paste its shortcode.', '1ab-stats' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $base . '&el=new' ) . '">' . esc_html__( 'Add element', '1ab-stats' ) . '</a></p></div>';

		if ( ! $all ) {
			return;
		}
		$types = self::types();
		echo '<div class="oneab-card"><table class="oneab-table"><thead><tr><th>' . esc_html__( 'Name', '1ab-stats' ) . '</th><th>' . esc_html__( 'Kind', '1ab-stats' ) . '</th><th>' . esc_html__( 'Shortcode', '1ab-stats' ) . '</th><th>' . esc_html__( 'A/B test', '1ab-stats' ) . '</th><th class="num">' . esc_html__( 'Click rate A', '1ab-stats' ) . '</th><th class="num">' . esc_html__( 'Click rate B', '1ab-stats' ) . '</th></tr></thead><tbody>';
		foreach ( $all as $id => $element ) {
			$rates = array( '–', '–' );
			$state = __( 'No test', '1ab-stats' );
			if ( $element['test'] ) {
				$totals = OneAB_Stats_Next::totals( $element['test'] );
				$state  = $element['ab_on'] ? __( 'Running', '1ab-stats' ) : __( 'Stopped', '1ab-stats' );
				foreach ( array( 'a', 'b' ) as $i => $v ) {
					$rates[ $i ] = $totals[ $v ]['s'] > 0 ? number_format_i18n( 100 * $totals[ $v ]['c'] / $totals[ $v ]['s'], 1 ) . ' %' : '–';
				}
			}
			echo '<tr><td><a href="' . esc_url( $base . '&el=' . $id ) . '"><strong>' . esc_html( '' !== $element['name'] ? $element['name'] : '#' . $id ) . '</strong></a></td>';
			echo '<td>' . esc_html( $types[ $element['type'] ] ) . '</td><td><code>[oneab_element id="' . esc_html( (string) $id ) . '"]</code></td>';
			echo '<td>' . esc_html( $state ) . '</td><td class="num">' . esc_html( $rates[0] ) . '</td><td class="num">' . esc_html( $rates[1] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Prints the form of one element.
	 *
	 * @param int    $id      Number, 0 for a new element.
	 * @param array  $element Element.
	 * @param string $base    Address of the tab.
	 */
	private static function form( $id, $element, $base ) {
		echo '<p><a href="' . esc_url( $base ) . '">&larr; ' . esc_html__( 'All elements', '1ab-stats' ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="oneab-element-form">';
		echo '<input type="hidden" name="action" value="oneab_stats_element"><input type="hidden" name="el" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( 'oneab_stats_element' );

		echo '<div class="oneab-card"><h2>' . esc_html( $id ? __( 'Edit element', '1ab-stats' ) : __( 'New element', '1ab-stats' ) ) . '</h2>';
		echo '<p><label>' . esc_html__( 'Name (only for you)', '1ab-stats' ) . '<br><input type="text" class="regular-text" name="name" value="' . esc_attr( $element['name'] ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Kind', '1ab-stats' ) . '<br><select name="type" class="oneab-type">';
		foreach ( self::types() as $type => $label ) {
			echo '<option value="' . esc_attr( $type ) . '"' . selected( $type, $element['type'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label><input type="checkbox" name="sponsored" value="1"' . checked( 1, $element['sponsored'], false ) . '> ' . esc_html__( 'Affiliate link: mark it as sponsored for search engines and open it in a new tab', '1ab-stats' ) . '</label></p>';
		if ( $id ) {
			echo '<p>' . esc_html__( 'Shortcode:', '1ab-stats' ) . ' <code>[oneab_element id="' . esc_html( (string) $id ) . '"]</code></p>';
		}
		echo '</div>';

		echo '<div class="oneab-grid">';
		echo '<div class="oneab-card"><h2>' . esc_html__( 'Variant A', '1ab-stats' ) . '</h2>';
		self::variant_fields( 'a', $element['a'] );
		echo '</div><div class="oneab-card"><h2>' . esc_html__( 'Variant B', '1ab-stats' ) . '</h2>';
		self::variant_fields( 'b', $element['b'] );
		echo '<p><label><input type="checkbox" name="ab_on" value="1"' . checked( 1, $element['ab_on'], false ) . '> ' . esc_html__( 'Run the A/B test: half of the page views get variant B', '1ab-stats' ) . '</label></p>';
		echo '</div></div>';
		echo '<p class="description">' . esc_html__( 'The variant is drawn by chance for every page view, in the visitor\'s browser. Nothing is stored there, and only daily totals reach your server. Search engines always get variant A.', '1ab-stats' ) . '</p>';
		echo '<p class="submit"><button class="button button-primary">' . esc_html__( 'Save element', '1ab-stats' ) . '</button> ';
		if ( $id ) {
			echo '<button class="button-link oneab-delete" name="oneab_step" value="delete">' . esc_html__( 'Delete element', '1ab-stats' ) . '</button>';
		}
		echo '</p>';

		if ( $id ) {
			$preview = self::render( $id );
			if ( '' !== $preview ) {
				echo '<div class="oneab-card"><h2>' . esc_html__( 'Preview of variant A', '1ab-stats' ) . '</h2><div class="oneab-preview">';
				echo wp_kses_post( $preview );
				echo '</div></div>';
			}
		}
		if ( $id && $element['test'] ) {
			$totals = OneAB_Stats_Next::totals( $element['test'] );
			echo '<div class="oneab-card"><h2>' . esc_html__( 'Test result', '1ab-stats' ) . '</h2>';
			if ( $element['start'] ) {
				/* translators: %s: date */
				echo '<p>' . esc_html( sprintf( __( 'Counting since %s.', '1ab-stats' ), wp_date( get_option( 'date_format' ), strtotime( $element['start'] . ' 12:00:00' ) ) ) ) . ' ' . esc_html( $element['ab_on'] ? __( 'The test is running.', '1ab-stats' ) : __( 'The test is stopped.', '1ab-stats' ) ) . '</p>';
			}
			OneAB_Stats_Next::result( $totals );
			echo '<p><button class="button" name="oneab_step" value="use_a">' . esc_html__( 'Keep A and end the test', '1ab-stats' ) . '</button> ';
			echo '<button class="button" name="oneab_step" value="use_b">' . esc_html__( 'Use B from now on and end the test', '1ab-stats' ) . '</button> ';
			echo '<button class="button" name="oneab_step" value="restart">' . esc_html__( 'Start a new test from zero', '1ab-stats' ) . '</button></p></div>';
		}
		echo '</form>';
	}
}
