<?php
/**
 * Plugin Name:       1AB Stats
 * Description:       Cookieless visitor statistics with affiliate click tracking. No cookies, no stored IP addresses, all data stays on your own server.
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

define( 'ONEAB_STATS_VERSION', '1.0.0' );
define( 'ONEAB_STATS_DB_VERSION', '1' );
define( 'ONEAB_STATS_FILE', __FILE__ );
define( 'ONEAB_STATS_DIR', plugin_dir_path( __FILE__ ) );

require_once ONEAB_STATS_DIR . 'includes/class-oneab-stats.php';
require_once ONEAB_STATS_DIR . 'includes/class-oneab-stats-tracker.php';
require_once ONEAB_STATS_DIR . 'includes/class-oneab-stats-admin.php';

register_activation_hook( __FILE__, array( 'OneAB_Stats', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OneAB_Stats', 'deactivate' ) );

OneAB_Stats::init();
OneAB_Stats_Tracker::init();
OneAB_Stats_Admin::init();
