<?php
/**
 * Pro: affiliate link clicks for every single product.
 *
 * @package OneAB_Stats
 */

// phpcs:disable WordPress.Files.FileName -- The suffix marks files that Freemius leaves out of the free version.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clicks per product.
 */
final class OneAB_Stats_Products {

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'oneab_stats_report_after', array( __CLASS__, 'report' ), 10, 2 );
	}

	/**
	 * Address of a product in its shop, or an empty string.
	 *
	 * @param string $target Host the click went to.
	 * @param string $item   Product number.
	 * @return string
	 */
	public static function product_url( $target, $item ) {
		$network = OneAB_Stats::network( $target );
		if ( 'Amazon' === $network && preg_match( '/^[A-Z0-9]{10}$/', $item ) && 0 === strpos( $target, 'amazon.' ) ) {
			return 'https://www.' . $target . '/dp/' . $item;
		}
		if ( 'eBay' === $network && preg_match( '/^\d{9,}$/', $item ) ) {
			return 'https://www.' . $target . '/itm/' . $item;
		}
		return '';
	}

	/**
	 * Prints the table below the report.
	 *
	 * @param string $from First day of the period.
	 * @param string $to   Last day of the period.
	 */
	public static function report( $from, $to ) {
		if ( oneab_stats_fs()->can_use_premium_code() ) {
			self::render( $from, $to );
		}
	}

	/**
	 * Builds and prints the table.
	 *
	 * @param string $from First day of the period.
	 * @param string $to   Last day of the period.
	 */
	public static function render( $from, $to ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table, administrators only.
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT target, item, path, COUNT(*) AS n FROM %i WHERE kind = 'click' AND item <> '' AND day BETWEEN %s AND %s GROUP BY target, item, path ORDER BY n DESC LIMIT 3000", OneAB_Stats::table(), $from, $to ), ARRAY_A );
		$products = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$key = $row['target'] . '|' . $row['item'];
			if ( ! isset( $products[ $key ] ) ) {
				// Rows arrive with the most clicked page first, so the first page seen is the strongest one.
				$products[ $key ] = array(
					'target' => $row['target'],
					'item'   => $row['item'],
					'n'      => 0,
					'pages'  => 0,
					'top'    => $row['path'],
				);
			}
			$products[ $key ]['n'] += (int) $row['n'];
			++$products[ $key ]['pages'];
		}
		uasort(
			$products,
			static function ( $a, $b ) {
				return $b['n'] <=> $a['n'];
			}
		);
		$products = array_slice( $products, 0, 25 );

		echo '<div class="oneab-card"><h2>' . esc_html__( 'Affiliate link clicks by product', '1ab-stats' ) . '</h2>';
		if ( ! $products ) {
			echo '<p class="oneab-empty">' . esc_html__( 'Nothing counted yet.', '1ab-stats' ) . '</p></div>';
			return;
		}
		echo '<table class="oneab-table"><thead><tr><th>' . esc_html__( 'Product', '1ab-stats' ) . '</th><th>' . esc_html__( 'Shop', '1ab-stats' ) . '</th><th class="num">' . esc_html__( 'Clicks', '1ab-stats' ) . '</th><th class="num">' . esc_html__( 'Pages', '1ab-stats' ) . '</th><th>' . esc_html__( 'Most clicks on', '1ab-stats' ) . '</th></tr></thead><tbody>';
		foreach ( $products as $product ) {
			$url = self::product_url( $product['target'], $product['item'] );
			echo '<tr><td>';
			if ( $url ) {
				echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $product['item'] ) . '</a>';
			} else {
				echo esc_html( $product['item'] );
			}
			echo '</td><td>' . esc_html( $product['target'] ) . '</td><td class="num">' . esc_html( number_format_i18n( $product['n'] ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $product['pages'] ) ) . '</td><td>' . esc_html( $product['top'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
