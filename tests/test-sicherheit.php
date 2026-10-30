<?php
// Offline-Test ohne WordPress: php test-offline.php  (Stubs für die benötigten WP-Funktionen)
define('SAB_SYNC', 1); define('ABSPATH', dirname(__DIR__) . '/');
const DAY_IN_SECONDS = 86400; const HOUR_IN_SECONDS = 3600; const MINUTE_IN_SECONDS = 60;
$GLOBALS['opt'] = []; $GLOBALS['tr'] = []; $GLOBALS['sc'] = []; $GLOBALS['http'] = []; $GLOBALS['admin'] = false;
function add_shortcode($n, $f) { $GLOBALS['sc'][$n] = $f; }
function add_action(...$a) {} function add_menu_page(...$a) { $GLOBALS['menu'][] = ['top', $a[3]]; } function add_submenu_page(...$a) { $GLOBALS['menu'][] = ['sub', $a[4]]; } function add_filter(...$a) {} function wp_mail($t,$s,$b) { $GLOBALS['mail'][] = [$t,$s,$b]; return true; } function sanitize_email($e) { return $e; } function plugin_basename($f) { return 'schlanke-amazon-box/schlanke-amazon-box.php'; } function wp_next_scheduled($x) { return true; }
function get_option($k, $d = false) { return $GLOBALS['opt'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opt'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['tr'][$k] ?? false; }
function set_transient($k, $v, $t) { $GLOBALS['tr'][$k] = $v; }
function delete_transient($k) { unset($GLOBALS['tr'][$k]); }
function shortcode_atts($d, $a) { return array_merge($d, array_intersect_key((array) $a, $d)); }
function esc_url($u) { return htmlspecialchars($u); } function esc_html($t) { return htmlspecialchars($t); } function esc_attr($t) { return htmlspecialchars($t); }
function wp_json_encode($d) { return json_encode($d); }
function is_wp_error($x) { return false; }
function wp_remote_post($u, $a) {
    if (strpos($u, 'auth') !== false) return ['code' => 200, 'body' => json_encode(['access_token' => 't', 'expires_in' => 3600])];
    $ids = json_decode($a['body'], true)['itemIds']; $items = [];
    foreach ($ids as $id) { $items[] = $GLOBALS['http'][$id] ?? null; }
    return ['code' => 200, 'body' => json_encode(['itemsResult' => ['items' => array_values(array_filter($items))]])];
}
function wp_remote_get($u, $a) {
    if (strpos($u, 'update.json') !== false) return ['code' => 200, 'body' => json_encode($GLOBALS['upd'] ?? [])]; return ['code' => 200, 'body' => 'JPG', 'ct' => 'image/jpeg']; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_remote_retrieve_header($r, $h) { return $r['ct'] ?? ''; }
function wp_upload_dir() { $d = sys_get_temp_dir() . '/sabtest'; return ['basedir' => $d, 'baseurl' => 'https://x.test/up']; }
function wp_mkdir_p($d) { @mkdir($d, 0777, true); }
function get_queried_object_id() { return 42; } function current_user_can($c) { return $GLOBALS['admin']; }
function get_the_ID() { return $GLOBALS['pid'] ?? 0; }
function sanitize_key($k) { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) $k)); }
function get_permalink($i = 0) { return 'https://x.test/p'; }
function wp_date($f, $t) { return date($f, $t); }
if (!function_exists('wp_date')) { function wp_date($f, $t = null) { return date($f, $t); } }
if (!function_exists('add_query_arg')) { function add_query_arg($a, $u) { return $u . '?' . http_build_query($a); } }
if (!function_exists('admin_url')) { function admin_url($p) { return 'http://x/' . $p; } }
if (!function_exists('untrailingslashit')) { function untrailingslashit($s) { return rtrim($s, '/'); } }
if (!function_exists('home_url')) { function home_url($p = '') { return 'http://x' . $p; } }
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
class FW { public $prefix = 'wp_'; function prepare($q, ...$a) { return $q; } function get_results($q, $o = null) {
  if (strpos($q, 'DATE(ts)') !== false) return [['d' => gmdate('Y-m-d'), 'n' => 5], ['d' => gmdate('Y-m-d', time() - 86400), 'n' => 2]];
  if (strpos($q, "ziel <>") !== false) return [['ziel' => 'com:a', 'n' => 2], ['ziel' => 'ca:s', 'n' => 1]];
  return [['asin' => 'B000000001', 'n' => 5, 'b' => 3, 'x' => 2, 'z' => 1, 'm' => 120], ['asin' => 'B000000002', 'n' => 2, 'b' => 0, 'x' => 2, 'z' => 0, 'm' => null]]; } }
$GLOBALS['wpdb'] = new FW;
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($u, $c = -1) { return parse_url($u, $c); } }
if (!function_exists('gmdate')) { }
if (!class_exists('WP_REST_Response')) { class WP_REST_Response { public $d; public $s; function __construct($d = null, $s = 200) { $this->d = $d; $this->s = $s; } } }
if (!class_exists('WP_Error')) { class WP_Error { } }
if (!function_exists('hash_equals')) { }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return false; } }
if (!function_exists('current_user_can')) { function current_user_can($c) { return false; } }
if (!function_exists('wp_validate_auth_cookie')) { function wp_validate_auth_cookie() { return 0; } }
class Wpdb2 { public $prefix = 'wp_'; public $q = []; public $ins = [];
    function prepare($s, ...$a) { $a = isset($a[0]) && is_array($a[0]) ? $a[0] : $a; return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $s), $a); }
    function query($s) { $this->q[] = $s; return 1; } function insert($t, $r) { $this->ins[] = [$t, $r]; return 1; }
    function get_results($s, $o = null) { return []; } function get_col($s) { return []; } function get_var($s) { return 0; } }
$GLOBALS['wpdb'] = new Wpdb2;
require dirname(__DIR__) . '/1ab-affiliate-help.php';
$fail = 0; function ok($c, $n) { global $fail; echo ($c ? 'OK   ' : 'FEHLER ') . $n . "\n"; if (!$c) $fail++; }
$pf = new ReflectionMethod('SAB', 'pfad_sauber'); $pf->setAccessible(true);
ok($pf->invoke(null, '/schnecken/kupferband/?x=1') === '/schnecken/kupferband/', 'Pfad: Query wird entfernt');
ok($pf->invoke(null, '/a b/<script>') === '', 'Pfad: Sonderzeichen werden abgelehnt');
ok($pf->invoke(null, 'javascript:alert(1)') === '', 'Pfad: kein Pfad = leer');
ok(strlen($pf->invoke(null, '/' . str_repeat('a', 400))) === 190, 'Pfad: höchstens 190 Zeichen');
$me = new ReflectionMethod('SAB', 'messung_erlaubt'); $me->setAccessible(true);
$GLOBALS['tr'] = []; $n = 0; for ($i = 0; $i < 700; $i++) { if ($me->invoke(null)) { $n++; } }
ok($n === 600, 'Messung: nach 600 je Minute wird abgelehnt (erlaubt: ' . $n . ')');
$ma = new ReflectionMethod('SAB', 'messdaten_aufraeumen');
$GLOBALS['opt']['sab_aufbewahrung_tage'] = 100; $GLOBALS['wpdb']->q = []; $ma->invoke(null);
ok(count($GLOBALS['wpdb']->q) === 4 && strpos(implode(' ', $GLOBALS['wpdb']->q), 'sab_klicks') !== false && strpos(implode(' ', $GLOBALS['wpdb']->q), 'sab_heat') !== false, 'Aufräumen: Klicks, A/B, Seiten und Heatmap werden gekürzt');
$GLOBALS['opt']['sab_aufbewahrung_tage'] = 0; $GLOBALS['wpdb']->q = []; $ma->invoke(null);
ok(count($GLOBALS['wpdb']->q) === 0, 'Aufräumen: 0 = nichts löschen');
// Update-Prüfsumme
$tmp = tempnam(sys_get_temp_dir(), 'z'); file_put_contents($tmp, 'ZIPDATA');
function download_url($u, $t = 300) { return $GLOBALS['dl']; }
$GLOBALS['dl'] = $tmp; $zip = SAB::UPDATE_REPO . 'raw/main/x.zip';
$GLOBALS['tr']['sab_update_json'] = ['version' => '9', 'zip' => $zip, 'sha256' => hash('sha256', 'ZIPDATA')];
ok(SAB::update_summe_pruefen(false, $zip) === $tmp, 'Update-Summe: passende Summe lässt das ZIP durch');
$GLOBALS['tr']['sab_update_json']['sha256'] = str_repeat('0', 64);
$r = SAB::update_summe_pruefen(false, $zip);
ok($r instanceof WP_Error && !file_exists($tmp), 'Update-Summe: falsche Summe bricht ab und löscht die Datei');
$GLOBALS['tr']['sab_update_json'] = ['version' => '9', 'zip' => $zip];
ok(SAB::update_summe_pruefen(false, $zip) === false, 'Update-Summe: ohne Summe in der JSON läuft es wie bisher');
ok(SAB::update_summe_pruefen(false, 'https://evil.example/x.zip') === false, 'Update-Summe: fremde Adresse wird nicht angefasst');
echo $fail ? "$fail FEHLER\n" : "Alles bestanden\n";
