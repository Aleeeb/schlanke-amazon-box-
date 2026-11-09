<?php
/**
 * Plugin Name: 1AB Weiterlesen (Übergang, nur zum Testen)
 * Description: Dünne Hülle um das Modul, bis es als Reiter in die Mini-Statistik eingebaut ist. Danach löschen.
 * Version: 0.3.0
 * Author: Alexander Boeckmann
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/1ab-weiterlesen-modul.php';
register_activation_hook(__FILE__, array('AB1_Weiterlesen', 'aktivieren'));
AB1_Weiterlesen::init();
