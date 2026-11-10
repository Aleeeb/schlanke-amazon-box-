<?php
// Wird von WordPress beim Löschen des Plugins aufgerufen: entfernt alle Daten und Einstellungen.
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
global $wpdb;
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'mini_statistik');
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'mini_statistik_ga');
foreach (['mini_statistik', 'mini_statistik_db', 'mini_statistik_hidden', 'mini_statistik_salt'] as $o) { delete_option($o); }
wp_clear_scheduled_hook('mini_statistik_cleanup');
