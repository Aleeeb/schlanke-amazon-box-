<?php
/**
 * "Read next" cards below articles, with an A/B test of two variants.
 *
 * @package OneAB_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-next cards and their A/B test.
 */
final class OneAB_Stats_Next {

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'append' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'save_post', array( __CLASS__, 'forget' ) );
		add_action( 'admin_post_oneab_stats_next', array( __CLASS__, 'save' ) );
	}

	/**
	 * Name of the counter table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'oneab_stats_ab';
	}

	/**
	 * Cleans one variant.
	 *
	 * @param mixed $variant Raw variant.
	 * @return array
	 */
	public static function variant( $variant ) {
		$variant = is_array( $variant ) ? $variant : array();
		return array(
			'title'  => isset( $variant['title'] ) ? sanitize_text_field( $variant['title'] ) : '',
			'layout' => isset( $variant['layout'] ) && 'list' === $variant['layout'] ? 'list' : 'cards',
			'pos'    => isset( $variant['pos'] ) && 'middle' === $variant['pos'] ? 'middle' : 'end',
		);
	}

	/**
	 * Heading of a variant; an empty setting means the translated default.
	 *
	 * @param array $variant Variant.
	 * @return string
	 */
	private static function title( $variant ) {
		return '' !== $variant['title'] ? $variant['title'] : __( 'Read next', '1ab-stats' );
	}

	/**
	 * Whether the box belongs on the current view.
	 *
	 * @return bool
	 */
	private static function active() {
		return OneAB_Stats::settings()['next_on'] && is_singular( 'post' );
	}

	/**
	 * Picks related articles: shared tags weigh double, shared categories single, newer first on a tie.
	 *
	 * @param int $post_id Article.
	 * @param int $count   Number wanted.
	 * @return int[]
	 */
	public static function related( $post_id, $count ) {
		$key    = 'oneab_stats_next_' . $post_id . '_' . $count;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$tags  = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );
		$cats  = wp_get_post_categories( $post_id );
		$query = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => 40,
			'post__not_in'        => array( $post_id ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- One id, result cached below.
			'ignore_sticky_posts' => true,
			'has_password'        => false,
			'no_found_rows'       => true,
			'fields'              => 'ids',
		);
		if ( $tags || $cats ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Result is cached for twelve hours.
			$query['tax_query'] = array( 'relation' => 'OR' );
			if ( $tags ) {
				$query['tax_query'][] = array(
					'taxonomy' => 'post_tag',
					'terms'    => $tags,
				);
			}
			if ( $cats ) {
				$query['tax_query'][] = array(
					'taxonomy' => 'category',
					'terms'    => $cats,
				);
			}
		}
		$ids    = get_posts( $query );
		$scores = array();
		foreach ( $ids as $rank => $id ) {
			$score         = 2 * count( array_intersect( $tags, wp_get_post_tags( $id, array( 'fields' => 'ids' ) ) ) ) + count( array_intersect( $cats, wp_get_post_categories( $id ) ) );
			$scores[ $id ] = $score * 1000 - $rank;
		}
		arsort( $scores );
		$out = array_slice( array_keys( $scores ), 0, $count );
		set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Drops the cached selection of an article when it is saved.
	 *
	 * @param int $post_id Article.
	 */
	public static function forget( $post_id ) {
		for ( $count = 2; $count <= 6; $count++ ) {
			delete_transient( 'oneab_stats_next_' . $post_id . '_' . $count );
		}
	}

	/**
	 * Appends the box to the article. Search engines and visitors without JavaScript always get variant A at the end.
	 *
	 * @param string $content Article content.
	 * @return string
	 */
	public static function append( $content ) {
		if ( ! self::active() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$s   = OneAB_Stats::settings();
		$ids = self::related( get_the_ID(), max( 2, min( 6, (int) $s['next_count'] ) ) );
		if ( ! $ids ) {
			return $content;
		}
		$a      = self::variant( $s['next_a'] );
		$config = array(
			'a' => array( 'pos' => $a['pos'] ),
		);
		if ( $s['ab_on'] ) {
			$b           = self::variant( $s['next_b'] );
			$config['t'] = (int) $s['ab_id'];
			$config['b'] = array(
				'pos'    => $b['pos'],
				'layout' => $b['layout'],
				'title'  => self::title( $b ),
			);
		}
		$html  = '<aside class="oneab-next oneab-next--' . esc_attr( $a['layout'] ) . '" data-oneab-next="' . esc_attr( wp_json_encode( $config ) ) . '">';
		$html .= '<h2 class="oneab-next__title">' . esc_html( self::title( $a ) ) . '</h2><ul class="oneab-next__list">';
		foreach ( $ids as $id ) {
			$image = get_the_post_thumbnail( $id, 'medium', array( 'loading' => 'lazy' ) );
			$html .= '<li class="oneab-next__item"><a class="oneab-next__link" href="' . esc_url( get_permalink( $id ) ) . '">';
			$html .= $image ? '<span class="oneab-next__image">' . $image . '</span>' : '';
			$html .= '<span class="oneab-next__text">' . esc_html( get_the_title( $id ) ) . '</span></a></li>';
		}
		$html .= '</ul></aside>';
		return $content . $html;
	}

	/**
	 * Loads style and script on articles.
	 */
	public static function assets() {
		if ( ! self::active() ) {
			return;
		}
		wp_enqueue_style( 'oneab-stats-next', plugins_url( 'assets/readnext.css', ONEAB_STATS_FILE ), array(), ONEAB_STATS_VERSION );
		wp_enqueue_script(
			'oneab-stats-next',
			plugins_url( 'assets/readnext.js', ONEAB_STATS_FILE ),
			array(),
			ONEAB_STATS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Adds one event of the running test to the daily counters. No visitor identifier is involved.
	 *
	 * @param array $data Decoded report.
	 */
	public static function count( array $data ) {
		global $wpdb;
		$s = OneAB_Stats::settings();
		if ( ! $s['next_on'] || ! $s['ab_on'] || ! isset( $data['id'] ) || (int) $data['id'] !== (int) $s['ab_id'] ) {
			return;
		}
		$variant = isset( $data['v'] ) && 'b' === $data['v'] ? 'b' : 'a';
		$event   = isset( $data['e'] ) && is_string( $data['e'] ) ? $data['e'] : '';
		if ( ! in_array( $event, array( 's', 'v', 'c' ), true ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (day, test, variant, event, n) VALUES (%s, %d, %s, %s, 1) ON DUPLICATE KEY UPDATE n = n + 1', self::table(), wp_date( 'Y-m-d' ), (int) $s['ab_id'], $variant, $event ) );
	}

	/**
	 * Totals of the running test.
	 *
	 * @return array Variant => event => count.
	 */
	public static function totals() {
		global $wpdb;
		$out = array(
			'a' => array(
				's' => 0,
				'v' => 0,
				'c' => 0,
			),
			'b' => array(
				's' => 0,
				'v' => 0,
				'c' => 0,
			),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT variant, event, SUM(n) AS n FROM %i WHERE test = %d GROUP BY variant, event', self::table(), (int) OneAB_Stats::settings()['ab_id'] ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $out[ $row['variant'] ][ $row['event'] ] ) ) {
				$out[ $row['variant'] ][ $row['event'] ] = (int) $row['n'];
			}
		}
		return $out;
	}

	/**
	 * Range in which the true rate lies with 95 % confidence (Wilson score interval).
	 *
	 * @param int $hits  Clicks.
	 * @param int $total Page views.
	 * @return float[] Lower and upper bound, 0 to 1.
	 */
	public static function wilson( $hits, $total ) {
		if ( $total < 1 ) {
			return array( 0.0, 0.0 );
		}
		$z      = 1.96;
		$p      = min( 1, $hits / $total );
		$centre = $p + $z * $z / ( 2 * $total );
		$spread = $z * sqrt( ( $p * ( 1 - $p ) + $z * $z / ( 4 * $total ) ) / $total );
		$bottom = 1 + $z * $z / $total;
		return array( max( 0, ( $centre - $spread ) / $bottom ), min( 1, ( $centre + $spread ) / $bottom ) );
	}

	/**
	 * Plain verdict on the test.
	 *
	 * @param array $totals Result of totals().
	 * @return string
	 */
	public static function verdict( $totals ) {
		$na = $totals['a']['s'];
		$nb = $totals['b']['s'];
		if ( $na < 100 || $nb < 100 ) {
			return __( 'Too early to say. Wait until each variant has at least 100 page views.', '1ab-stats' );
		}
		$pa     = min( 1, $totals['a']['c'] / $na );
		$pb     = min( 1, $totals['b']['c'] / $nb );
		$pooled = min( 1, ( $totals['a']['c'] + $totals['b']['c'] ) / ( $na + $nb ) );
		$error  = sqrt( $pooled * ( 1 - $pooled ) * ( 1 / $na + 1 / $nb ) );
		$z      = $error > 0 ? abs( $pa - $pb ) / $error : 0;
		if ( $z >= 1.96 ) {
			return $pb > $pa
				? __( 'Variant B is ahead, and a difference this large is very unlikely to be chance.', '1ab-stats' )
				: __( 'Variant A is ahead, and a difference this large is very unlikely to be chance.', '1ab-stats' );
		}
		return __( 'No clear winner yet. The difference could still be chance. Let the test run longer.', '1ab-stats' );
	}

	/**
	 * Saves the settings of this tab and handles the test buttons.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', '1ab-stats' ) );
		}
		check_admin_referer( 'oneab_stats_next' );
		$s = OneAB_Stats::settings();

		$s['next_on']    = empty( $_POST['next_on'] ) ? 0 : 1;
		$s['next_count'] = isset( $_POST['next_count'] ) ? max( 2, min( 6, absint( $_POST['next_count'] ) ) ) : 3;
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field by field in variant().
		$s['next_a'] = self::variant( isset( $_POST['next_a'] ) ? wp_unslash( $_POST['next_a'] ) : array() );
		$s['next_b'] = self::variant( isset( $_POST['next_b'] ) ? wp_unslash( $_POST['next_b'] ) : array() );
		// phpcs:enable
		$was_on     = (int) $s['ab_on'];
		$s['ab_on'] = empty( $_POST['ab_on'] ) ? 0 : 1;

		$step = isset( $_POST['oneab_step'] ) ? sanitize_key( wp_unslash( $_POST['oneab_step'] ) ) : '';
		if ( 'use_b' === $step ) {
			$s['next_a'] = $s['next_b'];
			$s['ab_on']  = 0;
		} elseif ( 'use_a' === $step ) {
			$s['ab_on'] = 0;
		}
		// A new number separates the counts of a new test from those of earlier ones.
		if ( 'restart' === $step || ( $s['ab_on'] && ! $was_on ) ) {
			$s['ab_id']    = (int) $s['ab_id'] + 1;
			$s['ab_start'] = wp_date( 'Y-m-d' );
			$s['ab_on']    = 1;
		}
		update_option( OneAB_Stats::OPTION, $s, false );
		wp_safe_redirect( admin_url( 'admin.php?page=' . OneAB_Stats_Admin::SLUG . '&tab=next&saved=1' ) );
		exit;
	}

	/**
	 * Prints the fields of one variant.
	 *
	 * @param string $name    Field name, next_a or next_b.
	 * @param array  $variant Variant.
	 */
	private static function variant_fields( $name, $variant ) {
		$variant = self::variant( $variant );
		echo '<p><label>' . esc_html__( 'Heading', '1ab-stats' ) . '<br><input type="text" class="regular-text" name="' . esc_attr( $name ) . '[title]" value="' . esc_attr( $variant['title'] ) . '" placeholder="' . esc_attr__( 'Read next', '1ab-stats' ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Look', '1ab-stats' ) . '<br><select name="' . esc_attr( $name ) . '[layout]">';
		echo '<option value="cards"' . selected( 'cards', $variant['layout'], false ) . '>' . esc_html__( 'Cards with image', '1ab-stats' ) . '</option>';
		echo '<option value="list"' . selected( 'list', $variant['layout'], false ) . '>' . esc_html__( 'Plain list of links', '1ab-stats' ) . '</option></select></label></p>';
		echo '<p><label>' . esc_html__( 'Place', '1ab-stats' ) . '<br><select name="' . esc_attr( $name ) . '[pos]">';
		echo '<option value="end"' . selected( 'end', $variant['pos'], false ) . '>' . esc_html__( 'At the end of the article', '1ab-stats' ) . '</option>';
		echo '<option value="middle"' . selected( 'middle', $variant['pos'], false ) . '>' . esc_html__( 'In the middle of the article', '1ab-stats' ) . '</option></select></label></p>';
	}

	/**
	 * Prints the tab.
	 */
	public static function tab() {
		$s = OneAB_Stats::settings();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="oneab_stats_next">';
		wp_nonce_field( 'oneab_stats_next' );

		echo '<div class="oneab-card"><h2>' . esc_html__( 'Read-next cards', '1ab-stats' ) . '</h2>';
		echo '<p><label><input type="checkbox" name="next_on" value="1"' . checked( 1, (int) $s['next_on'], false ) . '> ' . esc_html__( 'Show related articles below every post', '1ab-stats' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Number of articles', '1ab-stats' ) . ' <input type="number" class="small-text" name="next_count" min="2" max="6" value="' . esc_attr( (string) (int) $s['next_count'] ) . '"></label></p>';
		echo '<p class="description">' . esc_html__( 'Articles are chosen by shared tags and categories. Search engines and visitors without JavaScript always get variant A at the end of the article.', '1ab-stats' ) . '</p></div>';

		echo '<div class="oneab-grid">';
		echo '<div class="oneab-card"><h2>' . esc_html__( 'Variant A', '1ab-stats' ) . '</h2>';
		self::variant_fields( 'next_a', $s['next_a'] );
		echo '</div><div class="oneab-card"><h2>' . esc_html__( 'Variant B', '1ab-stats' ) . '</h2>';
		self::variant_fields( 'next_b', $s['next_b'] );
		echo '<p><label><input type="checkbox" name="ab_on" value="1"' . checked( 1, (int) $s['ab_on'], false ) . '> ' . esc_html__( 'Run the A/B test: half of the page views get variant B', '1ab-stats' ) . '</label></p>';
		echo '</div></div>';
		echo '<p class="description">' . esc_html__( 'The variant is drawn by chance for every page view, in the visitor\'s browser. Nothing is stored there, and only daily totals reach your server.', '1ab-stats' ) . '</p>';
		submit_button();

		if ( $s['ab_id'] ) {
			$totals = self::totals();
			echo '<div class="oneab-card"><h2>' . esc_html__( 'Test result', '1ab-stats' ) . '</h2>';
			if ( $s['ab_start'] ) {
				/* translators: %s: date */
				echo '<p>' . esc_html( sprintf( __( 'Counting since %s.', '1ab-stats' ), wp_date( get_option( 'date_format' ), strtotime( $s['ab_start'] . ' 12:00:00' ) ) ) ) . ' ' . esc_html( $s['ab_on'] ? __( 'The test is running.', '1ab-stats' ) : __( 'The test is stopped.', '1ab-stats' ) ) . '</p>';
			}
			echo '<table class="oneab-table"><thead><tr><th>' . esc_html__( 'Variant', '1ab-stats' ) . '</th><th class="num">' . esc_html__( 'Page views', '1ab-stats' ) . '</th><th class="num">' . esc_html__( 'Box seen', '1ab-stats' ) . '</th><th class="num">' . esc_html__( 'Clicks', '1ab-stats' ) . '</th><th class="num">' . esc_html__( 'Click rate', '1ab-stats' ) . '</th><th class="num">' . esc_html__( 'Likely range', '1ab-stats' ) . '</th></tr></thead><tbody>';
			foreach ( array( 'a', 'b' ) as $v ) {
				$t     = $totals[ $v ];
				$rate  = $t['s'] > 0 ? 100 * $t['c'] / $t['s'] : 0;
				$range = self::wilson( $t['c'], $t['s'] );
				echo '<tr><td>' . esc_html( strtoupper( $v ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $t['s'] ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $t['v'] ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $t['c'] ) ) . '</td>';
				echo '<td class="num">' . esc_html( number_format_i18n( $rate, 1 ) ) . ' %</td><td class="num">' . esc_html( number_format_i18n( 100 * $range[0], 1 ) . ' – ' . number_format_i18n( 100 * $range[1], 1 ) ) . ' %</td></tr>';
			}
			echo '</tbody></table>';
			echo '<p><strong>' . esc_html( self::verdict( $totals ) ) . '</strong></p>';
			echo '<p class="description">' . esc_html__( 'Click rate = clicks on the box divided by page views. The likely range shows where the true rate lies with 95 % confidence; it narrows as more visitors come.', '1ab-stats' ) . '</p>';
			echo '<p><button class="button" name="oneab_step" value="use_a">' . esc_html__( 'Keep A and end the test', '1ab-stats' ) . '</button> ';
			echo '<button class="button" name="oneab_step" value="use_b">' . esc_html__( 'Use B from now on and end the test', '1ab-stats' ) . '</button> ';
			echo '<button class="button" name="oneab_step" value="restart">' . esc_html__( 'Start a new test from zero', '1ab-stats' ) . '</button></p></div>';
		}
		echo '</form>';
	}
}
