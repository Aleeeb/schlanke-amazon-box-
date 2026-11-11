<?php
/**
 * Heatmap: reading depth, time spent and click positions per page, stored as plain counters.
 *
 * @package OneAB_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Heatmap counters and the overlay for administrators.
 */
final class OneAB_Stats_Heat {

	const DAYS = 90;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'overlay' ) );
	}

	/**
	 * Name of the counter table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'oneab_stats_heat';
	}

	/**
	 * Adds one report to the counters. Nothing about the visitor is stored, only sums per page, day and device class.
	 *
	 * @param array  $data Decoded report.
	 * @param string $path Sanitized path of the page.
	 */
	public static function store( array $data, $path ) {
		global $wpdb;
		if ( ! OneAB_Stats::settings()['heatmap'] ) {
			return;
		}
		$path = substr( $path, 0, 190 );
		$dev  = isset( $data['dv'] ) && 'm' === $data['dv'] ? 'm' : 'd';
		$rows = array();

		if ( ! empty( $data['f'] ) ) {
			$rows[] = array( 'v', 0, 0, 0 );
		}

		// Reading depth: the twentieths of the page newly reached since the last report.
		$to   = isset( $data['r'] ) ? max( -1, min( 19, (int) $data['r'] ) ) : -1;
		$from = isset( $data['r0'] ) ? max( -1, min( 19, (int) $data['r0'] ) ) : -1;
		for ( $band = $from + 1; $band <= $to; $band++ ) {
			$rows[] = array( 'r', 0, $band, 0 );
		}

		// Seconds spent with each twentieth of the page in the middle of the screen.
		$total = 0;
		$zones = isset( $data['z'] ) && is_array( $data['z'] ) ? array_slice( $data['z'], 0, 20, true ) : array();
		foreach ( $zones as $band => $secs ) {
			$band = (int) $band;
			$secs = min( 900 - $total, max( 0, (int) $secs ) );
			if ( $band < 0 || $band > 19 || $secs < 1 ) {
				continue;
			}
			$total += $secs;
			$rows[] = array( 'z', 0, $band, $secs );
		}

		// Click positions in a coarse grid: 40 columns across the width, rows of 25 pixels.
		$clicks = isset( $data['k'] ) && is_array( $data['k'] ) ? array_slice( $data['k'], 0, 20 ) : array();
		foreach ( $clicks as $click ) {
			if ( is_array( $click ) && 2 === count( $click ) ) {
				$rows[] = array( 'k', max( 0, min( 39, (int) $click[0] ) ), max( 0, min( 2000, (int) $click[1] ) ), 0 );
			}
		}

		if ( ! $rows ) {
			return;
		}
		$day    = wp_date( 'Y-m-d' );
		$values = array();
		foreach ( $rows as $row ) {
			$values[] = $wpdb->prepare( '(%s,%s,%s,%s,%d,%d,1,%d)', $day, $path, $dev, $row[0], $row[1], $row[2], $row[3] );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Own table; every value list above went through prepare().
		$wpdb->query(
			$wpdb->prepare( 'INSERT INTO %i (day, path, dev, kind, px, py, n, s) VALUES ', self::table() )
			. implode( ',', $values )
			. ' ON DUPLICATE KEY UPDATE n = n + VALUES(n), s = s + VALUES(s)'
		);
		// phpcs:enable
	}

	/**
	 * Registers the endpoint the overlay reads from.
	 */
	public static function routes() {
		register_rest_route(
			'oneab-stats/v1',
			'/heat',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => array( __CLASS__, 'read' ),
				'args'                => array(
					'path' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'dev'  => array(
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Returns the heatmap of one page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function read( WP_REST_Request $request ) {
		global $wpdb;
		$path = wp_parse_url( 'http://x' . (string) $request->get_param( 'path' ), PHP_URL_PATH );
		$path = substr( $path ? $path : '/', 0, 190 );
		$dev  = (string) $request->get_param( 'dev' );
		$from = wp_date( 'Y-m-d', time() - self::DAYS * DAY_IN_SECONDS );
		$out  = array(
			'v' => 0,
			'r' => array_fill( 0, 20, 0 ),
			'z' => array_fill( 0, 20, 0 ),
			'k' => array(),
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table, administrators only.
		if ( in_array( $dev, array( 'm', 'd' ), true ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT kind, px, py, SUM(n) AS n, SUM(s) AS s FROM %i WHERE path = %s AND day >= %s AND dev = %s GROUP BY kind, px, py', self::table(), $path, $from, $dev ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT kind, px, py, SUM(n) AS n, SUM(s) AS s FROM %i WHERE path = %s AND day >= %s GROUP BY kind, px, py', self::table(), $path, $from ), ARRAY_A );
		}
		// phpcs:enable
		$rows = is_array( $rows ) ? $rows : array();

		foreach ( $rows as $row ) {
			if ( 'v' === $row['kind'] ) {
				$out['v'] = (int) $row['n'];
			}
		}
		foreach ( $rows as $row ) {
			$py = (int) $row['py'];
			if ( 'r' === $row['kind'] && $py < 20 && $out['v'] > 0 ) {
				$out['r'][ $py ] = min( 1, round( (int) $row['n'] / $out['v'], 3 ) );
			} elseif ( 'z' === $row['kind'] && $py < 20 && $out['v'] > 0 ) {
				$out['z'][ $py ] = round( (int) $row['s'] / $out['v'], 1 );
			} elseif ( 'k' === $row['kind'] ) {
				$out['k'][] = array( (int) $row['px'], $py, (int) $row['n'] );
			}
		}
		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * Address that shows a page with the heatmap laid over it.
	 *
	 * @param string $path Path of the page.
	 * @return string
	 */
	public static function link( $path ) {
		return add_query_arg( 'oneab_heat', '1', home_url( $path ) );
	}

	/**
	 * Loads the overlay for administrators who opened a page with ?oneab_heat=1.
	 */
	public static function overlay() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch; the data itself is protected by capability and REST nonce.
		if ( ! isset( $_GET['oneab_heat'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_script( 'oneab-stats-heat', plugins_url( 'assets/heatmap.js', ONEAB_STATS_FILE ), array(), ONEAB_STATS_VERSION, array( 'in_footer' => true ) );
		wp_localize_script(
			'oneab-stats-heat',
			'oneabHeat',
			array(
				'u' => esc_url_raw( rest_url( 'oneab-stats/v1/heat' ) ),
				'n' => wp_create_nonce( 'wp_rest' ),
				's' => array(
					'title'   => __( 'Heatmap', '1ab-stats' ),
					/* translators: 1: number of page views, 2: number of days */
					'views'   => sprintf( __( '%1$s page views in the last %2$s days', '1ab-stats' ), '{v}', (string) self::DAYS ),
					'legend'  => __( 'Stripes: blue = read briefly, red = read for long. Dots: clicks.', '1ab-stats' ),
					/* translators: 1: share of visitors in percent, 2: seconds */
					'band'    => __( '%1$s %% reach this · %2$s s on average', '1ab-stats' ),
					/* translators: %s: number of clicks */
					'clicks'  => __( '%s clicks', '1ab-stats' ),
					'all'     => __( 'All', '1ab-stats' ),
					'desktop' => __( 'Computer', '1ab-stats' ),
					'mobile'  => __( 'Phone', '1ab-stats' ),
					'few'     => __( 'Only little data so far.', '1ab-stats' ),
				),
			)
		);
	}
}
