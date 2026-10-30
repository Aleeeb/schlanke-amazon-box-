<?php
// Offline-Test ohne WordPress: php test-offline.php  (Stubs für die benötigten WP-Funktionen)
define('ABSPATH', dirname(__DIR__) . '/');
const DAY_IN_SECONDS = 86400; const HOUR_IN_SECONDS = 3600; const MINUTE_IN_SECONDS = 60;
$GLOBALS['opt'] = []; $GLOBALS['tr'] = []; $GLOBALS['sc'] = []; $GLOBALS['http'] = []; $GLOBALS['admin'] = false;
function add_shortcode($n, $f) { $GLOBALS['sc'][$n] = $f; }
function add_action(...$a) {} function add_menu_page(...$a) { $GLOBALS['menu'][] = ['top', $a[3]]; } function add_submenu_page(...$a) { $GLOBALS['menu'][] = ['sub', $a[4]]; } function add_filter(...$a) {} function wp_mail($t,$s,$b) { $GLOBALS['mail'][] = [$t,$s,$b]; return true; } function sanitize_email($e) { return $e; } function plugin_basename($f) { return 'schlanke-amazon-box/schlanke-amazon-box.php'; } 
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
$GLOBALS['sched'] = [];
function wp_next_scheduled($h, $a = []) { if ($h === "sab_pruefen" || $h === "sab_auffrischen") return true; foreach ($GLOBALS['sched'] as $s) { if ($s === [$h, $a]) return 1; } return false; }
function wp_schedule_single_event($t, $h, $a = []) { $GLOBALS['sched'][] = [$h, $a]; return true; }
function spawn_cron() {}
require dirname(__DIR__) . '/1ab-affiliate-help.php';
$fail = 0;
function ok($c, $n) { global $fail; echo ($c ? 'OK   ' : 'FEHLER ') . $n . "\n"; if (!$c) $fail++; }
function item($asin) { return ['asin' => $asin, 'detailPageURL' => "https://www.amazon.es/dp/$asin?tag=t-21", 'itemInfo' => ['title' => ['displayValue' => "T$asin"]],
    'images' => ['primary' => ['large' => ['url' => 'https://m.media/x.jpg']]], 'offersV2' => ['listings' => [['isBuyBoxWinner' => true, 'price' => ['money' => ['displayAmount' => '9 €']]]]]]; }
$GLOBALS['opt'] += ['sab_client_id' => 'i', 'sab_client_secret' => 's', 'sab_tags' => "es=t-21", 'sab_default_land' => 'es'];
$GLOBALS['http']['B000000001'] = item('B000000001');
$r = new ReflectionClass('SAB');
$m = fn($n) => (function () use ($r, $n) { $x = $r->getMethod($n); $x->setAccessible(true); return $x; })();
$GLOBALS['http']['B000000002'] = item('B000000002');
$p2 = $m('produkt')->invoke(null, 'B000000002', 'es');
ok(is_array($p2) && ($p2['titel'] ?? '') === 'TB000000002' && !$GLOBALS['sched'], 'leerer Speicher: erste Produkte werden sofort geholt, kein Bild fehlt');
define('SAB_KEIN_SOFORT', true);   // ab hier den Hintergrundweg prüfen
$GLOBALS['posts'] = 0;
$p = $m('produkt')->invoke(null, 'B000000001', 'es');
ok($p === null, 'leerer Speicher: Besucher bekommt sofort null, kein Amazon-Aufruf');
ok(count($GLOBALS['sched']) === 1 && $GLOBALS['sched'][0][0] === 'sab_holen', 'Hintergrundabruf geplant');
$m('produkt')->invoke(null, 'B000000001', 'es');
ok(count($GLOBALS['sched']) === 1, 'nicht doppelt geplant');
SAB::holen('B000000001', 'es');
$p = $m('produkt')->invoke(null, 'B000000001', 'es');
ok(is_array($p) && ($p['titel'] ?? '') === 'TB000000001', 'nach Hintergrundabruf liegt das Produkt im Speicher');
$GLOBALS['sched'] = [];
$c = $GLOBALS['tr']['sab_es_B000000001']; $c['ts'] = time() - DAY_IN_SECONDS + 3600; $GLOBALS['tr']['sab_es_B000000001'] = $c;
$p = $m('produkt')->invoke(null, 'B000000001', 'es');
ok(is_array($p) && count($GLOBALS['sched']) === 1, 'kurz vor Ablauf: alter Stand sofort, Auffrischung im Hintergrund');
echo $fail ? "\n$fail FEHLER\n" : "\nAlles bestanden\n";
