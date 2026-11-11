<?php
/**
 * Dashboard pages: report and settings.
 *
 * @package OneAB_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report and settings.
 */
final class OneAB_Stats_Admin {

	const SLUG = 'oneab-stats';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_oneab_stats_save', array( __CLASS__, 'save' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ONEAB_STATS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Adds the menu entry.
	 */
	public static function menu() {
		add_menu_page(
			__( '1AB Stats', '1ab-stats' ),
			__( '1AB Stats', '1ab-stats' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'page' ),
			'dashicons-chart-bar',
			3
		);
	}

	/**
	 * Loads the style sheet on the plugin's own page only.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'oneab-stats-admin', plugins_url( 'assets/admin.css', ONEAB_STATS_FILE ), array(), ONEAB_STATS_VERSION );
	}

	/**
	 * Adds a link to the report in the plugin list.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Statistics', '1ab-stats' ) . '</a>' );
		return $links;
	}

	/**
	 * Saves the settings.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', '1ab-stats' ) );
		}
		check_admin_referer( 'oneab_stats_save' );
		$domains                   = isset( $_POST['extra_domains'] ) ? sanitize_textarea_field( wp_unslash( $_POST['extra_domains'] ) ) : '';
		$settings                  = OneAB_Stats::settings();
		$settings['keep_days']     = isset( $_POST['keep_days'] ) ? max( 0, min( 3650, absint( $_POST['keep_days'] ) ) ) : 0;
		$settings['salt_days']     = isset( $_POST['salt_days'] ) ? max( 1, min( 30, absint( $_POST['salt_days'] ) ) ) : 1;
		$settings['extra_domains'] = $domains;
		$settings['trust_proxy']   = empty( $_POST['trust_proxy'] ) ? 0 : 1;
		$settings['heatmap']       = empty( $_POST['heatmap'] ) ? 0 : 1;
		update_option( OneAB_Stats::OPTION, $settings, false );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=settings&saved=1' ) );
		exit;
	}

	/**
	 * Prints the page.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view parameters.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'report';
		$tab  = in_array( $tab, array( 'next', 'settings' ), true ) ? $tab : 'report';
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
		$days = in_array( $days, array( 7, 30, 90, 365 ), true ) ? $days : 30;
		$done = ! empty( $_GET['saved'] );
		// phpcs:enable

		$base = admin_url( 'admin.php?page=' . self::SLUG );
		echo '<div class="wrap oneab-stats"><h1>' . esc_html__( '1AB Stats', '1ab-stats' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		echo '<a class="nav-tab' . ( 'report' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $base ) . '">' . esc_html__( 'Overview', '1ab-stats' ) . '</a>';
		echo '<a class="nav-tab' . ( 'next' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $base . '&tab=next' ) . '">' . esc_html__( 'Read next and A/B test', '1ab-stats' ) . '</a>';
		echo '<a class="nav-tab' . ( 'settings' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $base . '&tab=settings' ) . '">' . esc_html__( 'Settings', '1ab-stats' ) . '</a>';
		echo '</nav>';

		if ( $done && 'report' !== $tab ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Settings saved.', '1ab-stats' ) . '</p></div>';
		}
		if ( 'settings' === $tab ) {
			self::settings_form();
		} elseif ( 'next' === $tab ) {
			OneAB_Stats_Next::tab();
		} else {
			self::report( $days, $base );
		}
		echo '</div>';
	}

	/**
	 * Prints the settings form.
	 */
	private static function settings_form() {
		$s = OneAB_Stats::settings();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="oneab_stats_save">';
		wp_nonce_field( 'oneab_stats_save' );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="oneab-keep">' . esc_html__( 'Data retention', '1ab-stats' ) . '</label></th><td>';
		echo '<input type="number" id="oneab-keep" name="keep_days" min="0" max="3650" value="' . esc_attr( (string) (int) $s['keep_days'] ) . '" class="small-text"> ' . esc_html__( 'days', '1ab-stats' );
		echo '<p class="description">' . esc_html__( '0 keeps the data without limit. Otherwise older entries are deleted once a day.', '1ab-stats' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="oneab-salt">' . esc_html__( 'Recognise returning visitors for', '1ab-stats' ) . '</label></th><td>';
		echo '<input type="number" id="oneab-salt" name="salt_days" min="1" max="30" value="' . esc_attr( (string) (int) $s['salt_days'] ) . '" class="small-text"> ' . esc_html__( 'days', '1ab-stats' );
		echo '<p class="description">' . esc_html__( 'After this time the random value behind the visitor identifier is replaced. Older identifiers can then no longer be matched. 1 is the most privacy-friendly choice.', '1ab-stats' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="oneab-domains">' . esc_html__( 'More affiliate link domains', '1ab-stats' ) . '</label></th><td>';
		echo '<textarea id="oneab-domains" name="extra_domains" rows="5" class="regular-text code" placeholder="awin1.com">' . esc_textarea( (string) $s['extra_domains'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One domain per line. Clicks on links to Amazon and eBay are always counted.', '1ab-stats' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Heatmap', '1ab-stats' ) . '</th><td><label>';
		echo '<input type="checkbox" name="heatmap" value="1"' . checked( 1, (int) $s['heatmap'], false ) . '> ' . esc_html__( 'Count reading depth, time spent and click positions per page', '1ab-stats' );
		echo '</label><p class="description">' . esc_html__( 'Only totals per page, day and device class are stored, nothing about single visitors. Open a heatmap from the list of most viewed pages.', '1ab-stats' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Proxy or CDN', '1ab-stats' ) . '</th><td><label>';
		echo '<input type="checkbox" name="trust_proxy" value="1"' . checked( 1, (int) $s['trust_proxy'], false ) . '> ' . esc_html__( 'My site is behind a proxy or CDN that passes the visitor address in the X-Forwarded-For header', '1ab-stats' );
		echo '</label><p class="description">' . esc_html__( 'Only tick this if it is true for your site. Otherwise all visitors behind the proxy may be counted as one, or visitor numbers can be inflated by forged headers.', '1ab-stats' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'What is counted', '1ab-stats' ) . '</th><td><p>' . esc_html__( 'Logged-in users, previews, robots and browsers that send "Do Not Track" are not counted. No cookie is set and nothing is stored in the visitor\'s browser. The visitor address is never stored.', '1ab-stats' ) . '</p></td></tr>';

		echo '</table>';
		submit_button();
		echo '</form>';
	}

	/**
	 * Runs a grouped count for the report.
	 *
	 * @param string $column Column to group by (fixed list).
	 * @param string $kind   Row kind.
	 * @param string $from   First day.
	 * @param string $to     Last day.
	 * @param int    $limit  Maximum number of rows.
	 * @return array[] Rows with keys k, n and v.
	 */
	private static function grouped( $column, $kind, $from, $to, $limit = 15 ) {
		global $wpdb;
		if ( ! in_array( $column, array( 'day', 'path', 'ref', 'device', 'cc', 'target' ), true ) ) {
			return array();
		}
		$order = 'day' === $column ? 'k ASC' : 'n DESC';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; $order comes from the two fixed strings above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT %i AS k, COUNT(*) AS n, COUNT(DISTINCT visitor) AS v FROM %i WHERE kind = %s AND day BETWEEN %s AND %s GROUP BY %i ORDER BY $order LIMIT %d",
				$column,
				OneAB_Stats::table(),
				$kind,
				$from,
				$to,
				$column,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Formats the change against the previous period.
	 *
	 * @param int $now    Current value.
	 * @param int $before Previous value.
	 * @return string HTML.
	 */
	private static function change( $now, $before ) {
		if ( $before < 1 ) {
			return '<span class="oneab-change">' . esc_html__( 'no data for the previous period', '1ab-stats' ) . '</span>';
		}
		$pct   = (int) round( 100 * ( $now - $before ) / $before );
		$class = $pct > 0 ? ' up' : ( $pct < 0 ? ' down' : '' );
		/* translators: %s: change in percent with sign, e.g. +12 % */
		$text = sprintf( __( '%s vs. previous period', '1ab-stats' ), ( $pct > 0 ? '+' : '' ) . number_format_i18n( $pct ) . ' %' );
		return '<span class="oneab-change' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Prints one table of the report.
	 *
	 * @param string   $title  Heading.
	 * @param string   $label  Heading of the first column.
	 * @param array[]  $rows   Rows from grouped().
	 * @param callable $name   Turns a key into the text shown.
	 * @param bool     $unique Show the visitors column.
	 * @param bool     $heat   Add a link to the heatmap of each row's page.
	 */
	private static function table( $title, $label, $rows, $name = null, $unique = true, $heat = false ) {
		echo '<div class="oneab-card"><h2>' . esc_html( $title ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="oneab-empty">' . esc_html__( 'Nothing counted yet.', '1ab-stats' ) . '</p></div>';
			return;
		}
		$max = 1;
		foreach ( $rows as $r ) {
			$max = max( $max, (int) $r['n'] );
		}
		echo '<table class="oneab-table"><thead><tr><th>' . esc_html( $label ) . '</th><th class="num">' . esc_html__( 'Count', '1ab-stats' ) . '</th>';
		if ( $unique ) {
			echo '<th class="num">' . esc_html__( 'Visitors', '1ab-stats' ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$text = $name ? call_user_func( $name, (string) $r['k'] ) : (string) $r['k'];
			$pct  = (int) round( 100 * (int) $r['n'] / $max );
			echo '<tr><td><span class="oneab-bar" style="width:' . esc_attr( (string) $pct ) . '%"></span><span class="oneab-label">' . esc_html( $text );
			if ( $heat ) {
				echo ' <a class="oneab-heat" target="_blank" rel="noopener" href="' . esc_url( OneAB_Stats_Heat::link( (string) $r['k'] ) ) . '">' . esc_html__( 'Heatmap', '1ab-stats' ) . '</a>';
			}
			echo '</span></td>';
			echo '<td class="num">' . esc_html( number_format_i18n( (int) $r['n'] ) ) . '</td>';
			if ( $unique ) {
				echo '<td class="num">' . esc_html( number_format_i18n( (int) $r['v'] ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Name of a country in the user's language.
	 *
	 * @param string $cc Two-letter code.
	 * @return string
	 */
	public static function country_name( $cc ) {
		if ( ! preg_match( '/^[A-Z]{2}$/', $cc ) ) {
			return __( 'Unknown', '1ab-stats' );
		}
		if ( class_exists( 'Locale' ) ) {
			$name = Locale::getDisplayRegion( '-' . $cc, get_user_locale() );
			if ( $name && $name !== $cc ) {
				return $name . ' (' . $cc . ')';
			}
		}
		return $cc;
	}

	/**
	 * Name of a device class.
	 *
	 * @param string $device Stored value.
	 * @return string
	 */
	public static function device_name( $device ) {
		$names = array(
			'mobile'  => __( 'Phone', '1ab-stats' ),
			'tablet'  => __( 'Tablet', '1ab-stats' ),
			'desktop' => __( 'Computer', '1ab-stats' ),
		);
		return isset( $names[ $device ] ) ? $names[ $device ] : __( 'Unknown', '1ab-stats' );
	}

	/**
	 * Prints the report.
	 *
	 * @param int    $days Length of the period in days.
	 * @param string $base Address of the page.
	 */
	private static function report( $days, $base ) {
		$now       = time();
		$to        = wp_date( 'Y-m-d', $now );
		$from      = wp_date( 'Y-m-d', $now - ( $days - 1 ) * DAY_IN_SECONDS );
		$prev_to   = wp_date( 'Y-m-d', $now - $days * DAY_IN_SECONDS );
		$prev_from = wp_date( 'Y-m-d', $now - ( 2 * $days - 1 ) * DAY_IN_SECONDS );

		$by_day = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$by_day[ wp_date( 'Y-m-d', $now - $i * DAY_IN_SECONDS ) ] = array( 0, 0, 0 ); // Views, visitors, clicks.
		}
		$sum  = array( 0, 0, 0 );
		$prev = array( 0, 0, 0 );
		foreach ( self::grouped( 'day', 'view', $from, $to, 400 ) as $r ) {
			if ( isset( $by_day[ $r['k'] ] ) ) {
				$by_day[ $r['k'] ][0] = (int) $r['n'];
				$by_day[ $r['k'] ][1] = (int) $r['v'];
			}
			$sum[0] += (int) $r['n'];
			$sum[1] += (int) $r['v'];
		}
		foreach ( self::grouped( 'day', 'click', $from, $to, 400 ) as $r ) {
			if ( isset( $by_day[ $r['k'] ] ) ) {
				$by_day[ $r['k'] ][2] = (int) $r['n'];
			}
			$sum[2] += (int) $r['n'];
		}
		foreach ( self::grouped( 'day', 'view', $prev_from, $prev_to, 400 ) as $r ) {
			$prev[0] += (int) $r['n'];
			$prev[1] += (int) $r['v'];
		}
		foreach ( self::grouped( 'day', 'click', $prev_from, $prev_to, 400 ) as $r ) {
			$prev[2] += (int) $r['n'];
		}

		echo '<p class="oneab-period">';
		foreach ( array( 7, 30, 90, 365 ) as $d ) {
			/* translators: %d: number of days */
			$text = sprintf( _n( '%d day', '%d days', $d, '1ab-stats' ), $d );
			echo '<a class="button' . ( $d === $days ? ' button-primary' : '' ) . '" href="' . esc_url( $base . '&days=' . $d ) . '">' . esc_html( $text ) . '</a> ';
		}
		echo '</p>';

		$cards = array(
			array( __( 'Page views', '1ab-stats' ), $sum[0], $prev[0] ),
			array( __( 'Visitors', '1ab-stats' ), $sum[1], $prev[1] ),
			array( __( 'Affiliate link clicks', '1ab-stats' ), $sum[2], $prev[2] ),
		);
		echo '<div class="oneab-cards">';
		foreach ( $cards as $c ) {
			echo '<div class="oneab-card oneab-kpi"><span class="oneab-kpi-label">' . esc_html( $c[0] ) . '</span><span class="oneab-kpi-value">' . esc_html( number_format_i18n( $c[1] ) ) . '</span>';
			echo wp_kses( self::change( $c[1], $c[2] ), array( 'span' => array( 'class' => true ) ) );
			echo '</div>';
		}
		echo '</div>';

		self::chart( $by_day );

		echo '<div class="oneab-grid">';
		self::table( __( 'Most viewed pages', '1ab-stats' ), __( 'Page', '1ab-stats' ), self::grouped( 'path', 'view', $from, $to ), null, true, (bool) OneAB_Stats::settings()['heatmap'] );
		self::table( __( 'Where visitors come from', '1ab-stats' ), __( 'Website', '1ab-stats' ), self::grouped( 'ref', 'view', $from, $to, 16 ), array( __CLASS__, 'referrer_name' ) );
		self::table( __( 'Countries', '1ab-stats' ), __( 'Country', '1ab-stats' ), self::grouped( 'cc', 'view', $from, $to ), array( __CLASS__, 'country_name' ) );
		self::table( __( 'Devices', '1ab-stats' ), __( 'Device', '1ab-stats' ), self::grouped( 'device', 'view', $from, $to ), array( __CLASS__, 'device_name' ) );
		self::table( __( 'Affiliate link clicks by page', '1ab-stats' ), __( 'Page', '1ab-stats' ), self::grouped( 'path', 'click', $from, $to ), null, false );
		self::table( __( 'Affiliate link clicks by target', '1ab-stats' ), __( 'Target', '1ab-stats' ), self::grouped( 'target', 'click', $from, $to ), null, false );
		echo '</div>';

		echo '<p class="description">' . esc_html__( 'Visitors are counted per day. Someone who comes on two days counts twice in the total. Countries are estimated from the time zone and language of the browser, not from the address.', '1ab-stats' ) . '</p>';

		/**
		 * Fires below the report so that add-ons can print further sections.
		 *
		 * @param string $from First day of the period (Y-m-d).
		 * @param string $to   Last day of the period (Y-m-d).
		 */
		do_action( 'oneab_stats_report_after', $from, $to );
	}

	/**
	 * Text for a referring site; direct visits have no referrer.
	 *
	 * @param string $host Host name or an empty string.
	 * @return string
	 */
	public static function referrer_name( $host ) {
		return '' === $host ? __( 'Direct or unknown', '1ab-stats' ) : $host;
	}

	/**
	 * Prints the bar chart of page views per day.
	 *
	 * @param array $by_day Day => views, visitors, clicks.
	 */
	private static function chart( $by_day ) {
		$max = 1;
		foreach ( $by_day as $v ) {
			$max = max( $max, $v[0] );
		}
		$count = count( $by_day );
		$w     = 1000;
		$h     = 220;
		$step  = $w / $count;
		$bar   = max( 1, $step * 0.72 );
		echo '<div class="oneab-card"><h2>' . esc_html__( 'Page views per day', '1ab-stats' ) . '</h2>';
		echo '<svg class="oneab-chart" viewBox="0 0 ' . esc_attr( (string) $w ) . ' ' . esc_attr( (string) ( $h + 24 ) ) . '" role="img" aria-label="' . esc_attr__( 'Page views per day', '1ab-stats' ) . '" preserveAspectRatio="none">';
		$i = 0;
		foreach ( $by_day as $day => $v ) {
			$bh = round( $h * $v[0] / $max, 1 );
			$x  = round( $i * $step + ( $step - $bar ) / 2, 1 );
			/* translators: 1: date, 2: page views, 3: visitors, 4: affiliate link clicks */
			$tip = sprintf( __( '%1$s: %2$s page views, %3$s visitors, %4$s affiliate link clicks', '1ab-stats' ), wp_date( get_option( 'date_format' ), strtotime( $day . ' 12:00:00' ) ), number_format_i18n( $v[0] ), number_format_i18n( $v[1] ), number_format_i18n( $v[2] ) );
			echo '<rect x="' . esc_attr( (string) $x ) . '" y="' . esc_attr( (string) ( $h - $bh ) ) . '" width="' . esc_attr( (string) round( $bar, 1 ) ) . '" height="' . esc_attr( (string) $bh ) . '" rx="2"><title>' . esc_html( $tip ) . '</title></rect>';
			++$i;
		}
		echo '<line x1="0" y1="' . esc_attr( (string) $h ) . '" x2="' . esc_attr( (string) $w ) . '" y2="' . esc_attr( (string) $h ) . '"/>';
		echo '</svg>';
		$days = array_keys( $by_day );
		echo '<div class="oneab-axis"><span>' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $days[0] . ' 12:00:00' ) ) ) . '</span><span>' . esc_html( number_format_i18n( $max ) ) . ' ' . esc_html__( 'at most per day', '1ab-stats' ) . '</span><span>' . esc_html( wp_date( get_option( 'date_format' ), strtotime( end( $days ) . ' 12:00:00' ) ) ) . '</span></div></div>';
	}
}
