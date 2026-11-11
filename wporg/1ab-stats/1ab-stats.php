<?php
/**
 * Plugin Name:       1AB Stats
 * Description:       Cookieless visitor statistics with affiliate click tracking, heatmaps and an A/B test for read-next cards. No cookies, no stored IP addresses, all data stays on your own server.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Alexander Boeckmann
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       1ab-stats
 * Domain Path:       /languages
 *
 * @package OneAB_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( function_exists( 'oneab_stats_fs' ) ) {
	// The free and the Pro version are both installed: the one loaded second takes over, the other is switched off.
	oneab_stats_fs()->set_basename( false, __FILE__ );
	return;
}

// Declared inside a condition on purpose: PHP then defines it only when this line runs, so the check above can tell a second copy of the plugin apart.
if ( ! function_exists( 'oneab_stats_fs' ) ) {
	/**
	 * Freemius: licences, the optional Pro version and its updates. Nothing is sent before the site owner agrees on the opt-in screen.
	 *
	 * @return Freemius
	 */
	function oneab_stats_fs() {
		global $oneab_stats_fs;
		if ( ! isset( $oneab_stats_fs ) ) {
			require_once __DIR__ . '/vendor/freemius/start.php';
			$oneab_stats_fs = fs_dynamic_init(
				array(
					'id'                  => '44600',
					'slug'                => '1ab-stats',
					'type'                => 'plugin',
					'public_key'          => 'pk_0b508b2f73a433d1dd913f1f00467',
					'is_premium'          => false,
					'premium_suffix'      => 'Pro',
					'has_premium_version' => true,
					'has_addons'          => false,
					'has_paid_plans'      => true,
					'is_org_compliant'    => true,
					'trial'               => array(
						'days'               => 30,
						'is_require_payment' => true,
					),
					'menu'                => array(
						'slug'    => 'oneab-stats',
						'account' => true,
						'contact' => false,
						'support' => false,
					),
				)
			);
		}
		return $oneab_stats_fs;
	}
}

oneab_stats_fs();
do_action( 'oneab_stats_fs_loaded' );

define( 'ONEAB_STATS_VERSION', '1.0.0' );
define( 'ONEAB_STATS_DB_VERSION', '2' );
define( 'ONEAB_STATS_FILE', __FILE__ );
define( 'ONEAB_STATS_DIR', plugin_dir_path( __FILE__ ) );

require_once ONEAB_STATS_DIR . 'includes/class-oneab-stats.php';
require_once ONEAB_STATS_DIR . 'includes/class-oneab-stats-tracker.php';
require_once ONEAB_STATS_DIR . 'includes/class-oneab-stats-heat.php';
require_once ONEAB_STATS_DIR . 'includes/class-oneab-stats-next.php';
require_once ONEAB_STATS_DIR . 'includes/class-oneab-stats-elements.php';
require_once ONEAB_STATS_DIR . 'includes/class-oneab-stats-admin.php';

register_activation_hook( __FILE__, array( 'OneAB_Stats', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OneAB_Stats', 'deactivate' ) );

if ( oneab_stats_fs()->is__premium_only() ) {
	require_once ONEAB_STATS_DIR . 'includes/class-oneab-stats-products__premium_only.php';
	OneAB_Stats_Products::init();
}

oneab_stats_fs()->add_action( 'after_uninstall', array( 'OneAB_Stats', 'uninstall' ) );

OneAB_Stats::init();
OneAB_Stats_Tracker::init();
OneAB_Stats_Heat::init();
OneAB_Stats_Next::init();
OneAB_Stats_Elements::init();
OneAB_Stats_Admin::init();
