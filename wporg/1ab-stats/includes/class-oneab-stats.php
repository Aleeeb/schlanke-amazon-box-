<?php
/**
 * Shared basics: settings, database table, clean-up.
 *
 * @package OneAB_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings, table and housekeeping.
 */
final class OneAB_Stats {

	const OPTION = 'oneab_stats_settings';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'oneab_stats_cleanup', array( __CLASS__, 'cleanup' ) );
	}

	/**
	 * Loads the bundled translations.
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( '1ab-stats', false, dirname( plugin_basename( ONEAB_STATS_FILE ) ) . '/languages' );
	}

	/**
	 * Returns the settings with defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$defaults = array(
			'keep_days'     => 0,  // 0 = keep forever.
			'salt_days'     => 1,  // Days until the random value behind the visitor identifier is replaced.
			'extra_domains' => '', // Additional affiliate link domains, one per line.
			'trust_proxy'   => 0,  // Read the visitor address from X-Forwarded-For (only behind a trusted proxy).
			'heatmap'       => 1,  // Collect heatmap counters.
			'next_on'       => 0,  // Show read-next cards below posts.
			'next_count'    => 3,
			'next_a'        => array(),
			'next_b'        => array(),
			'ab_on'         => 0,  // A/B test of the read-next cards is running.
			'ab_id'         => 0,  // Number of the current test; counts of earlier tests stay separate.
			'ab_start'      => '',
		);
		return wp_parse_args( (array) get_option( self::OPTION, array() ), $defaults );
	}

	/**
	 * Name of the data table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'oneab_stats';
	}

	/**
	 * Creates the table and schedules the daily clean-up.
	 */
	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			day DATE NOT NULL,
			ts DATETIME NOT NULL,
			kind VARCHAR(5) NOT NULL DEFAULT 'view',
			path VARCHAR(255) NOT NULL,
			visitor CHAR(16) NOT NULL,
			ref VARCHAR(100) NOT NULL DEFAULT '',
			device VARCHAR(10) NOT NULL DEFAULT '',
			cc CHAR(2) NOT NULL DEFAULT '',
			target VARCHAR(100) NOT NULL DEFAULT '',
			item VARCHAR(20) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY day_kind (day, kind)
			) $charset;"
		);
		$heat = OneAB_Stats_Heat::table();
		dbDelta(
			"CREATE TABLE $heat (
			day DATE NOT NULL,
			path VARCHAR(190) NOT NULL,
			dev CHAR(1) NOT NULL,
			kind CHAR(1) NOT NULL,
			px SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			py SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			n INT UNSIGNED NOT NULL DEFAULT 0,
			s INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (day, path, dev, kind, px, py)
			) $charset;"
		);
		$ab = OneAB_Stats_Next::table();
		dbDelta(
			"CREATE TABLE $ab (
			day DATE NOT NULL,
			test INT UNSIGNED NOT NULL,
			variant CHAR(1) NOT NULL,
			event CHAR(1) NOT NULL,
			n INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (day, test, variant, event)
			) $charset;"
		);
		update_option( 'oneab_stats_db', ONEAB_STATS_DB_VERSION, false );
		if ( ! wp_next_scheduled( 'oneab_stats_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'oneab_stats_cleanup' );
		}
	}

	/**
	 * Removes the scheduled clean-up.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'oneab_stats_cleanup' );
	}

	/**
	 * Removes all data and settings when the plugin is deleted.
	 */
	public static function uninstall() {
		global $wpdb;
		foreach ( array( self::table(), OneAB_Stats_Heat::table(), OneAB_Stats_Next::table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own tables.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
		delete_option( self::OPTION );
		delete_option( 'oneab_stats_salt' );
		delete_option( 'oneab_stats_elements' );
		delete_option( 'oneab_stats_test_seq' );
		delete_option( 'oneab_stats_db' );
		wp_clear_scheduled_hook( 'oneab_stats_cleanup' );
	}

	/**
	 * Updates the table after a plugin update.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'oneab_stats_db' ) !== ONEAB_STATS_DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * Deletes rows older than the retention period.
	 */
	public static function cleanup() {
		global $wpdb;
		$keep = (int) self::settings()['keep_days'];
		if ( $keep < 1 ) {
			return;
		}
		$limit = wp_date( 'Y-m-d', time() - $keep * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table, clean-up job.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', self::table(), $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table, clean-up job.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', OneAB_Stats_Heat::table(), $limit ) );
	}

	/**
	 * Lower-cased host names that count as affiliate link targets in addition to Amazon and eBay.
	 *
	 * @return string[]
	 */
	public static function extra_domains() {
		$out = array();
		foreach ( preg_split( '/\R/', (string) self::settings()['extra_domains'] ) as $line ) {
			// Accept a pasted address as well: drop the scheme and everything after the host.
			$line = preg_replace( '#^\s*[a-z]+://#i', '', (string) $line );
			$line = preg_split( '#[/?\#:]#', $line )[0];
			$host = strtolower( preg_replace( '/[^A-Za-z0-9.\-]/', '', $line ) );
			$host = preg_replace( '/^www\./', '', $host );
			if ( '' !== $host && false !== strpos( $host, '.' ) ) {
				$out[] = $host;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Name of the affiliate network a host belongs to, or an empty string.
	 *
	 * @param string $host Host name.
	 * @return string
	 */
	public static function network( $host ) {
		$host = strtolower( preg_replace( '/^www\./', '', (string) $host ) );
		if ( preg_match( '/(^|\.)(amazon\.[a-z.]+|amzn\.[a-z]+|a\.co)$/', $host ) ) {
			return 'Amazon';
		}
		if ( preg_match( '/(^|\.)ebay\.[a-z.]+$/', $host ) ) {
			return 'eBay';
		}
		foreach ( self::extra_domains() as $domain ) {
			if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
				return $domain;
			}
		}
		return '';
	}
}
