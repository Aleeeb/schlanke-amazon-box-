<?php
/**
 * Pro: up to four variants per element, and tests that end by themselves.
 *
 * @package OneAB_Stats
 */

// phpcs:disable WordPress.Files.FileName -- The suffix marks files that Freemius leaves out of the free version.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * More variants and the automatic winner.
 */
final class OneAB_Stats_Tests {

	/**
	 * Registers hooks.
	 */
	public static function init() {
		if ( ! oneab_stats_fs()->can_use_premium_code() ) {
			return;
		}
		add_filter( 'oneab_stats_element_variants', array( __CLASS__, 'variants' ) );
		add_action( 'oneab_stats_element_test_fields', array( __CLASS__, 'element_field' ) );
		add_action( 'oneab_stats_next_test_fields', array( __CLASS__, 'next_field' ) );
		add_action( 'oneab_stats_cleanup', array( __CLASS__, 'check' ), 20 );
	}

	/**
	 * Four variants instead of two.
	 *
	 * @return string[]
	 */
	public static function variants() {
		return array( 'a', 'b', 'c', 'd' );
	}

	/**
	 * Prints the switch in the form of an element.
	 *
	 * @param array $element Element.
	 */
	public static function element_field( $element ) {
		echo '<p><label><input type="checkbox" name="auto" value="1"' . checked( 1, (int) $element['auto'], false ) . '> ' . esc_html__( 'End the test by itself as soon as there is a clear winner, and show the winner to everyone', '1ab-stats' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Checked once a day. A winner needs at least 100 page views per variant and a lead that is very unlikely to be chance.', '1ab-stats' ) . '</p>';
	}

	/**
	 * Prints the switch on the read-next tab.
	 *
	 * @param array $settings Settings.
	 */
	public static function next_field( $settings ) {
		echo '<p><label><input type="checkbox" name="ab_auto" value="1"' . checked( 1, (int) $settings['ab_auto'], false ) . '> ' . esc_html__( 'End the test by itself as soon as there is a clear winner, and show the winner to everyone', '1ab-stats' ) . '</label></p>';
	}

	/**
	 * Daily check: ends every test that has a clear winner, if its owner asked for that.
	 */
	public static function check() {
		$all     = OneAB_Stats_Elements::all();
		$changed = false;
		foreach ( $all as $id => $element ) {
			if ( ! $element['ab_on'] || ! $element['auto'] || ! $element['test'] ) {
				continue;
			}
			$winner = OneAB_Stats_Next::winner( OneAB_Stats_Next::totals( $element['test'], OneAB_Stats_Elements::keys() ) );
			if ( '' === $winner || '?' === $winner || ! isset( $element[ $winner ] ) ) {
				continue;
			}
			$element['a']      = $element[ $winner ];
			$element['ab_on']  = 0;
			$element['ended']  = wp_date( 'Y-m-d' );
			$element['winner'] = $winner;
			$all[ $id ]        = $element;
			$changed           = true;
		}
		if ( $changed ) {
			update_option( OneAB_Stats_Elements::OPTION, $all, false );
		}

		$s = OneAB_Stats::settings();
		if ( $s['next_on'] && $s['ab_on'] && $s['ab_auto'] && $s['ab_id'] ) {
			$winner = OneAB_Stats_Next::winner( OneAB_Stats_Next::totals() );
			if ( 'a' === $winner || 'b' === $winner ) {
				if ( 'b' === $winner ) {
					$s['next_a'] = $s['next_b'];
				}
				$s['ab_on'] = 0;
				update_option( OneAB_Stats::OPTION, $s, false );
			}
		}
	}
}
