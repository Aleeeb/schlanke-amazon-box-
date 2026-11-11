<?php
/**
 * Counting: front-end script and the endpoint that stores page views and affiliate clicks.
 *
 * @package OneAB_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end script and collecting endpoint.
 */
final class OneAB_Stats_Tracker {

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Loads the counting script for visitors. Logged-in users and previews are never counted.
	 */
	public static function enqueue() {
		if ( is_user_logged_in() || is_preview() || is_customize_preview() ) {
			return;
		}
		wp_enqueue_script(
			'oneab-stats',
			plugins_url( 'assets/tracker.js', ONEAB_STATS_FILE ),
			array(),
			ONEAB_STATS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		$config = array(
			'e' => esc_url_raw( rest_url( 'oneab-stats/v1/hit' ) ),
			'd' => OneAB_Stats::extra_domains(),
		);
		wp_add_inline_script( 'oneab-stats', 'window.oneabStats=' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Registers the collecting endpoint.
	 */
	public static function routes() {
		register_rest_route(
			'oneab-stats/v1',
			'/hit',
			array(
				'methods'             => 'POST',
				// Public by design: anonymous visitors report their own page view. Every field is validated in hit().
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'hit' ),
			)
		);
	}

	/**
	 * Whether a user agent looks like a robot.
	 *
	 * @param string $ua User agent.
	 * @return bool
	 */
	public static function is_bot( $ua ) {
		return '' === $ua || (bool) preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|preview|headless|python|curl|wget|monitor|lighthouse|gtmetrix|pingdom/i', $ua );
	}

	/**
	 * Random value mixed into the visitor identifier. It is replaced regularly; older identifiers can then no longer be matched.
	 *
	 * @return string
	 */
	private static function salt() {
		$days = max( 1, min( 30, (int) OneAB_Stats::settings()['salt_days'] ) );
		$salt = get_option( 'oneab_stats_salt' );
		$age  = is_array( $salt ) ? ( time() - (int) $salt['time'] ) / DAY_IN_SECONDS : PHP_INT_MAX;
		if ( ! is_array( $salt ) || $age >= $days ) {
			$salt = array(
				'time'  => strtotime( gmdate( 'Y-m-d' ) . ' 00:00:00 UTC' ),
				'value' => wp_generate_password( 32, true, true ),
			);
			update_option( 'oneab_stats_salt', $salt, false );
		}
		return (string) $salt['value'];
	}

	/**
	 * Address of the visitor. Used in memory only, never stored.
	 *
	 * @return string
	 */
	private static function address() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( OneAB_Stats::settings()['trust_proxy'] && isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$list = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip   = trim( $list[0] );
		}
		return $ip;
	}

	/**
	 * Stores one page view or one affiliate click.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function hit( WP_REST_Request $request ) {
		$none = new WP_REST_Response( null, 204 );
		$data = json_decode( $request->get_body(), true );
		if ( ! is_array( $data ) ) {
			return $none;
		}

		// Only accept reports sent from this site's own pages.
		$origin = (string) $request->get_header( 'origin' );
		if ( '' !== $origin && wp_parse_url( $origin, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return $none;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( self::is_bot( $ua ) || ! empty( $data['a'] ) ) {
			return $none;
		}

		$type = isset( $data['t'] ) ? (string) $data['t'] : 'v';
		$path = wp_parse_url( 'http://x' . ( isset( $data['p'] ) && is_string( $data['p'] ) ? $data['p'] : '/' ), PHP_URL_PATH );
		$path = substr( sanitize_text_field( $path ? $path : '/' ), 0, 255 );

		$row = array(
			'day'     => wp_date( 'Y-m-d' ),
			'ts'      => gmdate( 'Y-m-d H:i:s' ),
			'path'    => $path,
			// The address and the user agent only pass through a one-way hash with a regularly replaced random value.
			'visitor' => substr( hash( 'sha256', self::salt() . '|' . self::address() . '|' . $ua ), 0, 16 ),
		);

		if ( 'v' === $type ) {
			$ref      = '';
			$ref_host = wp_parse_url( isset( $data['r'] ) && is_string( $data['r'] ) ? $data['r'] : '', PHP_URL_HOST );
			$own_host = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
			if ( $ref_host ) {
				$ref_host = preg_replace( '/^www\./', '', strtolower( preg_replace( '/[^A-Za-z0-9.\-]/', '', $ref_host ) ) );
				if ( $ref_host !== $own_host ) {
					$ref = substr( $ref_host, 0, 100 );
				}
			}
			$width = isset( $data['w'] ) ? (int) $data['w'] : 0;
			$tz    = substr( preg_replace( '/[^A-Za-z0-9_\/+\-]/', '', isset( $data['z'] ) && is_string( $data['z'] ) ? $data['z'] : '' ), 0, 60 );
			$lang  = substr( preg_replace( '/[^A-Za-z_\-]/', '', isset( $data['g'] ) && is_string( $data['g'] ) ? $data['g'] : '' ), 0, 12 );
			$row  += array(
				'kind'   => 'view',
				'ref'    => $ref,
				'device' => 0 === $width ? '' : ( $width < 600 ? 'mobile' : ( $width < 1024 ? 'tablet' : 'desktop' ) ),
				'cc'     => self::country( $tz, $lang ),
			);
		} elseif ( 'c' === $type ) {
			$target = strtolower( preg_replace( '/[^A-Za-z0-9.\-]/', '', isset( $data['to'] ) && is_string( $data['to'] ) ? $data['to'] : '' ) );
			$target = preg_replace( '/^www\./', '', $target );
			if ( '' === $target || '' === OneAB_Stats::network( $target ) ) {
				return $none;
			}
			$row += array(
				'kind'   => 'click',
				'target' => substr( $target, 0, 100 ),
				'item'   => substr( preg_replace( '/[^A-Za-z0-9]/', '', isset( $data['i'] ) && is_string( $data['i'] ) ? $data['i'] : '' ), 0, 20 ),
			);
		} else {
			/**
			 * Fires for report types this plugin does not store itself, so that add-ons can.
			 *
			 * @param string $type Report type.
			 * @param array  $data Decoded report, not sanitized.
			 * @param array  $row  Day, time, path and visitor identifier, sanitized.
			 */
			do_action( 'oneab_stats_event', $type, $data, $row );
			return $none;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
		$wpdb->insert( OneAB_Stats::table(), $row );
		return $none;
	}

	/**
	 * Estimates the country from the time zone and browser language. No address lookup is involved.
	 *
	 * @param string $tz   Time zone name reported by the browser.
	 * @param string $lang Browser language.
	 * @return string Two-letter country code or an empty string.
	 */
	public static function country( $tz, $lang ) {
		static $map = null;
		if ( null === $map ) {
			$map = require ONEAB_STATS_DIR . 'includes/timezones.php';
		}
		if ( '' !== $tz && isset( $map[ $tz ] ) ) {
			return $map[ $tz ];
		}
		if ( preg_match( '/^[a-z]{2,3}[-_]([A-Za-z]{2})\b/', $lang, $m ) ) {
			return strtoupper( $m[1] );
		}
		return '';
	}
}
