<?php
/** Modul Weiterlesen und Exit-Popup (1AB). Kein eigenes Plugin: wird aus der Mini-Statistik per require_once geladen.
 *  Einbau: require_once __DIR__.'/1ab-weiterlesen-modul.php'; AB1_Weiterlesen::init(); register_activation_hook(..., array('AB1_Weiterlesen','aktivieren'));
 *  Reiter: AB1_Weiterlesen::tab(); Filter: ab1_wl_eigenes_menue (false = kein eigenes Einstellungen-Menü), ab1_wl_seiten_url (URL des Reiters). */
if (!defined('ABSPATH')) exit;

class AB1_Weiterlesen {
    const VERSION = '0.2.0';
    const OPT = 'ab1_wl_opt';
    const REST = 'ab1-wl/v1';
    const SEITEN_OHNE_POPUP = 'impressum|datenschutz|kontakt|privacy|aviso-legal|mentions-legales|legal';

    /* ---------- Reine Funktionen (ohne WordPress, testbar) ---------- */

    /** Wörter eines Titels, klein, ohne Kurzwörter. */
    public static function woerter($t) {
        $t = function_exists('mb_strtolower') ? mb_strtolower((string) $t) : strtolower((string) $t);
        preg_match_all('/[\p{L}\p{N}]{4,}/u', $t, $m);
        return array_values(array_unique($m[0]));
    }

    /** Ähnlichkeitswert zweier Artikel. Mehr = passender. */
    public static function bewerte(array $a, array $b, $gewicht = 0) {
        $kat = count(array_intersect($a['kat'], $b['kat']));
        $tag = count(array_intersect($a['tag'], $b['tag']));
        $wa = self::woerter($a['titel']); $wb = self::woerter($b['titel']);
        $u = count(array_unique(array_merge($wa, $wb)));
        $jac = $u ? count(array_intersect($wa, $wb)) / $u : 0;
        return 3 * $kat + 2 * $tag + 5 * $jac + (float) $gewicht;
    }

    /** Harte Regeln: darf $b unter $a stehen? */
    public static function darf_verlinken(array $a, array $b) {
        if ($a['id'] === $b['id']) return false;                       // keine Selbstverlinkung
        if ($b['noindex']) return false;                               // nichts auf noindex
        if ($a['sprache'] !== $b['sprache']) return false;             // nie Sprachen mischen
        if ($a['kat'] && !array_intersect($a['kat'], $b['kat'])) return false; // gleiches Thema
        if ($a['fokus'] !== '' && $a['fokus'] === $b['fokus']) return false;   // Kannibalisierung
        return true;
    }

    /** Offene Blockelemente im HTML-Stück? Nur zwischen Top-Level-Absätzen einfügen. */
    public static function ist_toplevel($html) {
        foreach (array('ul', 'ol', 'table', 'div', 'figure', 'blockquote', 'section', 'aside', 'svg', 'details', 'script', 'style') as $t) {
            $auf = preg_match_all('/<' . $t . '[\s>]/i', $html);
            $zu = preg_match_all('/<\/' . $t . '>/i', $html);
            if ($auf !== $zu) return false;
        }
        return true;
    }

    /** Teaser nach dem n-ten Top-Level-Absatz einfügen, sonst unverändert. */
    public static function fuege_nach_absatz($inhalt, $html, $n) {
        $teile = preg_split('/(<\/p>)/i', $inhalt, -1, PREG_SPLIT_DELIM_CAPTURE);
        $absatz = 0; $vor = '';
        for ($i = 0; $i < count($teile); $i += 2) {
            $vor .= $teile[$i] . ($teile[$i + 1] ?? '');
            if (isset($teile[$i + 1])) {
                $absatz++;
                if ($absatz >= $n && self::ist_toplevel($vor)) {
                    return $vor . $html . implode('', array_slice($teile, $i + 2));
                }
            }
        }
        return $inhalt;
    }

    /** Wilson-Untergrenze/Obergrenze (95 %) für Klickraten. */
    public static function wilson($k, $n) {
        if ($n <= 0) return array(0, 0);
        $z = 1.96; $p = $k / $n;
        $d = 1 + $z * $z / $n;
        $m = ($p + $z * $z / (2 * $n)) / $d;
        $h = $z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n)) / $d;
        return array(max(0, $m - $h), min(1, $m + $h));
    }

    /** Wöchentlich wechselnde Kennung aus IP und Browser, nicht umkehrbar. */
    public static function kennung($ip, $ua, $geheim, $zeit) {
        $salz = hash_hmac('sha256', (string) floor($zeit / 604800), $geheim);
        return hash_hmac('sha256', $ip . '|' . $ua, $salz);
    }

    /** Popup-Variante aus Kennung und Tageszahl: wechselt täglich, nichts im Browser. */
    public static function popup_variante($kennung, $tageszahl, array $verfuegbar) {
        $verfuegbar = array_values($verfuegbar);
        if (!$verfuegbar) return '';
        $h = hash('sha256', $kennung . '|' . (int) $tageszahl);
        return $verfuegbar[hexdec(substr($h, 0, 8)) % count($verfuegbar)];
    }

    /* ---------- Einstellungen ---------- */

    public static function std() {
        return array(
            'weiterlesen' => 0, 'popup' => 0, 'anzahl' => 4, 'ueberschrift' => 'Weiterlesen',
            'push' => array(), 'push_themenfrei' => 1, 'ab' => 1,
            'teaser_oben' => 0, 'teaser_absatz' => 3,
            'pop_sek' => 12, 'pop_tage' => 7, 'pop_titel' => 'Bevor du gehst',
            'pop_knopf' => 'Jetzt lesen', 'pop_nein' => 'Nicht mehr anzeigen',
        );
    }
    public static function opt($k = null) {
        $o = array_merge(self::std(), (array) get_option(self::OPT, array()));
        return $k === null ? $o : $o[$k];
    }

    /* ---------- Start ---------- */

    public static function init() {
        add_action('ab1_wl_taeglich', array(__CLASS__, 'aufraeumen'));
        add_action('rest_api_init', array(__CLASS__, 'rest'));
        add_action('init', array(__CLASS__, 'nein_link'));
        add_action('save_post', array(__CLASS__, 'cache_leeren'));
        add_action('add_meta_boxes', array(__CLASS__, 'metabox'));
        add_action('save_post', array(__CLASS__, 'metabox_speichern'), 10, 2);
        add_action('admin_menu', array(__CLASS__, 'menue'));
        add_action('admin_post_ab1_wl_speichern', array(__CLASS__, 'einstellungen_speichern'));
        add_filter('the_content', array(__CLASS__, 'inhalt'), 30);
        add_shortcode('ab1_weiterlesen', array(__CLASS__, 'shortcode'));
        add_action('wp_footer', array(__CLASS__, 'popup_ausgeben'), 50);
    }

    public static function aktivieren() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}ab1_wl_zaehler (
            tag date NOT NULL, bereich varchar(4) NOT NULL, variante char(1) NOT NULL, ereignis char(1) NOT NULL,
            quelle bigint(20) unsigned NOT NULL DEFAULT 0, ziel bigint(20) unsigned NOT NULL DEFAULT 0,
            pos tinyint(3) unsigned NOT NULL DEFAULT 0, n int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (tag,bereich,variante,ereignis,quelle,ziel,pos)
        ) $c;");
        dbDelta("CREATE TABLE {$wpdb->prefix}ab1_wl_kennung (
            kennung char(64) NOT NULL, popup_bis datetime NOT NULL, zuletzt char(1) NOT NULL DEFAULT '',
            nein tinyint(1) NOT NULL DEFAULT 0, zeit datetime NOT NULL,
            PRIMARY KEY  (kennung)
        ) $c;");
        if (!wp_next_scheduled('ab1_wl_taeglich')) wp_schedule_event(time() + 3600, 'daily', 'ab1_wl_taeglich');
    }

    public static function aufraeumen() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}ab1_wl_kennung WHERE zeit < (UTC_TIMESTAMP() - INTERVAL 8 DAY)");
        $wpdb->query("DELETE FROM {$wpdb->prefix}ab1_wl_zaehler WHERE tag < (CURDATE() - INTERVAL 400 DAY)");
    }

    /* ---------- Auswahl der Artikel ---------- */

    public static function info($id) {
        $kat = wp_get_post_categories($id);
        $tags = wp_get_post_tags($id, array('fields' => 'ids'));
        $sprache = function_exists('pll_get_post_language') ? (string) pll_get_post_language($id) : (string) apply_filters('ab1_wl_sprache', get_locale(), $id);
        return array(
            'id' => (int) $id, 'titel' => get_the_title($id), 'kat' => array_map('intval', (array) $kat),
            'tag' => array_map('intval', (array) $tags), 'sprache' => $sprache,
            'noindex' => get_post_meta($id, '_yoast_wpseo_meta-robots-noindex', true) === '1' || get_post_meta($id, '_aioseo_robots_noindex', true) === '1',
            'fokus' => strtolower(trim((string) get_post_meta($id, '_yoast_wpseo_focuskw', true))),
        );
    }

    public static function cache_leeren($id = 0) {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_ab1wl\_%' OR option_name LIKE '\_transient\_timeout\_ab1wl\_%'");
    }

    /** Liste der Artikel-IDs für Quelle $id. Rückgabe: array('liste'=>[ids], 'push'=>[idA,idB]) */
    public static function auswahl($id) {
        $key = 'ab1wl_' . $id . '_' . get_post_modified_time('U', true, $id);
        $c = get_transient($key);
        if (is_array($c)) return $c;
        $o = self::opt();
        $a = self::info($id);
        $modus = get_post_meta($id, '_ab1_wl_modus', true) ?: 'auto';
        $liste = array();
        $max = max(1, (int) $o['anzahl']);

        $manuell = array_filter(array_map('intval', explode(',', (string) get_post_meta($id, '_ab1_wl_manuell', true))));
        if ($modus !== 'auto') {
            foreach ($manuell as $m) {
                if (get_post_status($m) === 'publish' && $m !== $id) $liste[] = $m; // manuell: Redakteur entscheidet (Ausnahme erlaubt)
            }
        }
        $push = array(); // Buchstabe => Beitrags-ID, Buchstabe bleibt am Platz in den Einstellungen hängen
        foreach (array_values((array) $o['push']) as $i => $p) {
            $p = (int) $p;
            if ($i > 4 || !$p || get_post_status($p) !== 'publish') continue;
            $b = self::info($p);
            if ($o['push_themenfrei']) $b['kat'] = $a['kat']; // Redakteur hat bewusst gewählt: Thema nicht prüfen
            if (self::darf_verlinken($a, $b)) $push[chr(97 + $i)] = $p;
        }
        if ($modus !== 'manuell' && count($liste) < $max) {
            $q = new WP_Query(array(
                'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 60, 'fields' => 'ids',
                'post__not_in' => array($id), 'category__in' => $a['kat'] ?: array(), 'no_found_rows' => true,
                'orderby' => 'date', 'order' => 'DESC',
            ));
            $kand = array();
            foreach ($q->posts as $pid) {
                if (in_array($pid, $liste, true) || in_array($pid, $push, true)) continue;
                $b = self::info($pid);
                if (!self::darf_verlinken($a, $b)) continue;
                $kand[$pid] = self::bewerte($a, $b, (float) get_post_meta($pid, '_ab1_wl_gewicht', true));
            }
            arsort($kand);
            foreach (array_keys($kand) as $pid) { if (count($liste) >= $max) break; $liste[] = $pid; }
        }
        $c = array('liste' => array_slice(array_values(array_unique($liste)), 0, $max), 'push' => $push);
        set_transient($key, $c, 12 * HOUR_IN_SECONDS);
        return $c;
    }

    /* ---------- Anzeige Weiterlesen ---------- */

    private static function karte($pid, $pos, $extra = '') {
        $bild = get_the_post_thumbnail_url($pid, 'medium');
        $t = get_the_title($pid);
        $h = '<li data-z="' . (int) $pid . '" data-p="' . (int) $pos . '"' . $extra . '><a href="' . esc_url(get_permalink($pid)) . '">';
        if ($bild) $h .= '<img src="' . esc_url($bild) . '" alt="" loading="lazy" decoding="async" width="300" height="200">';
        return $h . '<span>' . esc_html($t) . '</span></a></li>';
    }

    public static function box_html($id) {
        $o = self::opt();
        $w = self::auswahl($id);
        $liste = $w['liste'];
        $push = $w['push'];
        if (!$liste && !$push) return '';
        $h = '<aside class="ab1wl" data-q="' . (int) $id . '" aria-label="' . esc_attr($o['ueberschrift']) . '"><h2>' . esc_html($o['ueberschrift']) . '</h2><ul>';
        $pos = 1;
        if ($push) {
            // gepushte Artikel teilen sich Platz 1: je Aufruf zufällig einer (Varianten A bis E)
            $erste = true;
            foreach ($push as $buchstabe => $pid) {
                $h .= self::karte($pid, 1, ($o['ab'] && count($push) > 1) ? ' data-s="' . $buchstabe . '"' . ($erste ? '' : ' hidden') : '');
                $erste = false;
                if (!$o['ab']) break;
            }
            $pos = 2;
        }
        foreach ($liste as $pid) { if ($pos > max(1, (int) $o['anzahl'])) break; $h .= self::karte($pid, $pos++); }
        $h .= '</ul></aside>';
        // Teaser weiter oben: nur Testvariante B, kleine Textbox
                return $h;
    }

    public static function teaser_html($id, $push) {
        $ziel = reset($push);
        return '<p class="ab1wl-tz" data-q="' . (int) $id . '" data-z="' . (int) $ziel . '" data-p="0"><strong>' . esc_html__('Das könnte dich auch interessieren:', 'ab1-wl') . '</strong> <a href="' . esc_url(get_permalink($ziel)) . '">' . esc_html(get_the_title($ziel)) . '</a></p>';
    }

    private static $js_geladen = false;

    public static function inhalt($inhalt) {
        $o = self::opt();
        if (!$o['weiterlesen'] || !is_singular('post') || !in_the_loop() || !is_main_query()) return $inhalt;
        $id = get_the_ID();
        if (get_post_meta($id, '_ab1_wl_modus', true) === 'aus') return $inhalt;
        $w = self::auswahl($id);
        if ($o['teaser_oben'] && $w['push']) {
            $inhalt = self::fuege_nach_absatz($inhalt, '<div class="ab1wl-tz-platz" hidden>' . self::teaser_html($id, $w['push']) . '</div>', max(1, (int) $o['teaser_absatz']));
        }
        $box = self::box_html($id);
        if ($box) { self::$js_geladen = true; add_action('wp_footer', array(__CLASS__, 'weiterlesen_skript'), 40); }
        return $inhalt . $box;
    }

    public static function shortcode() {
        $id = get_the_ID();
        if (!$id) return '';
        self::$js_geladen = true;
        add_action('wp_footer', array(__CLASS__, 'weiterlesen_skript'), 40);
        return self::box_html($id);
    }

    public static function weiterlesen_skript() {
        static $fertig = false;
        if ($fertig) return; $fertig = true;
        $o = self::opt();
        $css = '.ab1wl{margin:2.5em 0;clear:both}.ab1wl h2{font-size:1.3em;margin:0 0 .8em}.ab1wl ul{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(4,1fr);gap:14px}'
            . '.ab1wl li{margin:0;padding:0}.ab1wl li[hidden]{display:none}.ab1wl a{display:block;text-decoration:none;color:inherit;border:1px solid rgba(0,0,0,.1);border-radius:12px;overflow:hidden;background:#fff;height:100%;transition:box-shadow .2s,transform .2s}'
            . '.ab1wl a:hover,.ab1wl a:focus{box-shadow:0 6px 18px rgba(0,0,0,.12);transform:translateY(-2px)}.ab1wl img{display:block;width:100%;height:auto;aspect-ratio:3/2;object-fit:cover}'
            . '.ab1wl span{display:block;padding:10px 12px;font-weight:600;line-height:1.3}@media(max-width:800px){.ab1wl ul{grid-template-columns:repeat(2,1fr)}}'
            . '.ab1wl-tz{margin:1.5em 0;padding:12px 16px;background:#f4f8f2;border-left:4px solid #6a9a5b;border-radius:6px}@media(prefers-reduced-motion:reduce){.ab1wl a{transition:none}}';
        $cfg = array('u' => esc_url_raw(rest_url(self::REST . '/ereignis')), 'ab' => (int) $o['ab'], 'top' => (int) $o['teaser_oben']);
        echo '<style id="ab1wl-css">' . $css . '</style><script id="ab1wl-js">(function(){var C=' . wp_json_encode($cfg) . ';'
            . 'function r(){return Math.random()<.5?"a":"b"}'
            . 'function s(o){try{var b=new Blob([JSON.stringify(o)],{type:"text/plain"});if(navigator.sendBeacon)navigator.sendBeacon(C.u,b);else fetch(C.u,{method:"POST",body:b,keepalive:true})}catch(e){}}'
            . 'function go(){var b=document.querySelector(".ab1wl");if(!b)return;var q=+b.getAttribute("data-q"),L=[].map.call(b.querySelectorAll("[data-s]"),function(e){return e.getAttribute("data-s")}),v=L.length?L[Math.floor(Math.random()*L.length)]:"a",t=C.top?r():"a";'
            . '[].forEach.call(b.querySelectorAll("[data-s]"),function(e){e.hidden=e.getAttribute("data-s")!==v});'
            . 'var pl=document.querySelector(".ab1wl-tz-platz");if(pl&&t==="b"){pl.hidden=false;}else if(pl){pl.parentNode.removeChild(pl);t="a"}'
            . 'var seen={};function imp(el,k,vv){if(!("IntersectionObserver"in window))return;var io=new IntersectionObserver(function(x){if(x[0].isIntersecting&&!seen[k]){seen[k]=1;s({b:k,v:vv,e:"i",q:q,z:0,p:0});io.disconnect()}},{threshold:.5});io.observe(el)}'
            . 'imp(b,"wl",v);if(pl&&t==="b")imp(pl,"tz",t);'
            . 'document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest(".ab1wl a,.ab1wl-tz a");if(!a)return;var h=a.closest("[data-z]");if(!h)return;var tz=!!a.closest(".ab1wl-tz");s({b:tz?"tz":"wl",v:tz?t:v,e:"c",q:q,z:+h.getAttribute("data-z"),p:+h.getAttribute("data-p")})},true)}'
            . '(window.requestIdleCallback||function(f){setTimeout(f,300)})(go)})();</script>';
    }

    /* ---------- Exit-Popup ---------- */

    public static function popup_inhalte($id) {
        $v = array();
        foreach (self::auswahl($id)['push'] as $buchstabe => $pid) $v[$buchstabe] = array('typ' => 'karte', 'ids' => array($pid));
        return $v;
    }

    public static function popup_ausgeben() {
        $o = self::opt();
        if (!$o['popup'] || !is_singular('post')) return;
        $id = get_the_ID();
        if (preg_match('/(' . self::SEITEN_OHNE_POPUP . ')/i', (string) get_post_field('post_name', $id))) return;
        $v = self::popup_inhalte($id);
        if (count($v) < 1) return;
        $html = '';
        foreach ($v as $k => $d) {
            $html .= '<div class="ab1p-v" data-v="' . $k . '" hidden>';
            if ($d['typ'] === 'karte') {
                $p = $d['ids'][0]; $bild = get_the_post_thumbnail_url($p, 'medium_large');
                $html .= '<a class="ab1p-karte" href="' . esc_url(get_permalink($p)) . '" data-z="' . (int) $p . '">';
                if ($bild) $html .= '<img src="' . esc_url($bild) . '" alt="" width="480" height="300" decoding="async">';
                $html .= '<span class="ab1p-t">' . esc_html(get_the_title($p)) . '</span><span class="ab1p-k">' . esc_html($o['pop_knopf']) . ' &rarr;</span></a>';
            } else {
                $html .= '<ul class="ab1p-liste">';
                foreach ($d['ids'] as $i => $p) $html .= '<li><a href="' . esc_url(get_permalink($p)) . '" data-z="' . (int) $p . '">' . esc_html(get_the_title($p)) . '</a></li>';
                $html .= '</ul>';
            }
            $html .= '</div>';
        }
        $cfg = array(
            'u' => esc_url_raw(rest_url(self::REST . '/')), 'q' => (int) $id, 'sek' => (int) $o['pop_sek'],
            'v' => array_keys($v), 'nein' => esc_url_raw(add_query_arg('ab1wl_nein', '1', home_url('/'))),
        );
        $css = '#ab1p{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(20,30,20,.55);opacity:0;transition:opacity .25s}'
            . '#ab1p.an{opacity:1}#ab1p[hidden]{display:none}.ab1p-box{position:relative;max-width:460px;width:100%;background:#fff;border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.35);padding:22px 22px 16px;transform:translateY(12px);transition:transform .25s}'
            . '#ab1p.an .ab1p-box{transform:none}.ab1p-h{font-size:1.25em;font-weight:700;margin:0 28px 12px 0;color:#2d4a26}.ab1p-x{position:absolute;top:8px;right:10px;border:0;background:none;font-size:28px;line-height:1;cursor:pointer;color:#666;padding:4px 8px}'
            . '.ab1p-karte{display:block;text-decoration:none;color:inherit;border-radius:12px;overflow:hidden;border:1px solid rgba(0,0,0,.1)}.ab1p-karte img{display:block;width:100%;height:auto;aspect-ratio:8/5;object-fit:cover}'
            . '.ab1p-t{display:block;padding:12px 14px 4px;font-weight:700;font-size:1.05em;line-height:1.3}.ab1p-k{display:block;padding:0 14px 14px;color:#2f7d32;font-weight:600}'
            . '.ab1p-liste{list-style:none;margin:0;padding:0}.ab1p-liste li{margin:0;border-top:1px solid rgba(0,0,0,.08)}.ab1p-liste li:first-child{border-top:0}.ab1p-liste a{display:block;padding:12px 4px;font-weight:600;text-decoration:none;color:#2f7d32}'
            . '.ab1p-n{display:block;margin-top:12px;font-size:.8em;color:#777;text-align:center}@media(prefers-reduced-motion:reduce){#ab1p,.ab1p-box{transition:none}}';
        $js = '(function(){var C=' . wp_json_encode($cfg) . ',t0=Date.now(),done=0,V,P;'
            . 'function post(u,o){try{var b=new Blob([JSON.stringify(o)],{type:"text/plain"});if(navigator.sendBeacon)navigator.sendBeacon(u,b)}catch(e){}}'
            . 'function zu(){P.classList.remove("an");setTimeout(function(){P.hidden=true},260);document.removeEventListener("keydown",kd)}'
            . 'function kd(e){if(e.key==="Escape")zu()}'
            . 'function zeig(){if(done||Date.now()-t0<C.sek*1000)return;done=1;'
            . 'fetch(C.u+"popup?q="+C.q+"&v="+C.v.join(""),{credentials:"omit"}).then(function(r){return r.json()}).then(function(j){if(!j||!j.zeigen||C.v.indexOf(j.v)<0)return;V=j.v;'
            . 'P=document.getElementById("ab1p");[].forEach.call(P.querySelectorAll(".ab1p-v"),function(e){e.hidden=e.getAttribute("data-v")!==V});P.hidden=false;void P.offsetWidth;P.classList.add("an");'
            . 'P.querySelector(".ab1p-x").focus();document.addEventListener("keydown",kd);post(C.u+"ereignis",{b:"pu",v:V,e:"i",q:C.q,z:0,p:0})}).catch(function(){})}'
            . 'function start(){var P0=document.getElementById("ab1p");P0.addEventListener("click",function(e){var a=e.target.closest("a[data-z]");if(a)post(C.u+"ereignis",{b:"pu",v:V,e:"c",q:C.q,z:+a.getAttribute("data-z"),p:0});if(e.target===P0||e.target.classList.contains("ab1p-x"))zu()});'
            . 'var touch=matchMedia("(pointer:coarse)").matches;'
            . 'if(!touch){document.addEventListener("mouseout",function(e){if(!e.relatedTarget&&e.clientY<=0)zeig()})}'
            . 'else{var ly=scrollY,mx=0;addEventListener("scroll",function(){var h=document.documentElement.scrollHeight-innerHeight||1,p=scrollY/h;if(p>mx)mx=p;if(mx>.45&&ly-scrollY>140)zeig();if(scrollY>ly)ly=scrollY},{passive:true})}}'
            . '(window.requestIdleCallback||function(f){setTimeout(f,1500)})(start)})();';
        echo '<style id="ab1p-css">' . $css . '</style><div id="ab1p" role="dialog" aria-modal="true" aria-labelledby="ab1p-h" hidden><div class="ab1p-box"><button type="button" class="ab1p-x" aria-label="Schließen">&times;</button><p class="ab1p-h" id="ab1p-h">'
            . esc_html($o['pop_titel']) . '</p>' . $html . '<a class="ab1p-n" href="' . esc_url($cfg['nein']) . '" rel="nofollow">' . esc_html($o['pop_nein']) . '</a></div></div><script id="ab1p-js">' . $js . '</script>';
    }

    /* ---------- Server: Zähler, Kennung, REST ---------- */

    private static function meine_kennung() {
        $ip = apply_filters('ab1_wl_ip', isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '');
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 300) : '';
        return self::kennung($ip, $ua, wp_salt('auth'), time());
    }

    public static function rest() {
        register_rest_route(self::REST, '/ereignis', array(
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array(__CLASS__, 'rest_ereignis'),
        ));
        register_rest_route(self::REST, '/popup', array(
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array(__CLASS__, 'rest_popup'),
        ));
    }

    public static function rest_ereignis($req) {
        global $wpdb;
        $d = json_decode($req->get_body(), true);
        if (!is_array($d)) return new WP_REST_Response(null, 204);
        $b = isset($d['b']) ? (string) $d['b'] : ''; $v = isset($d['v']) ? (string) $d['v'] : ''; $e = isset($d['e']) ? (string) $d['e'] : '';
        $q = (int) ($d['q'] ?? 0); $z = (int) ($d['z'] ?? 0); $p = max(0, min(20, (int) ($d['p'] ?? 0)));
        if (!in_array($b, array('wl', 'tz', 'pu'), true) || !preg_match('/^[a-e]$/', $v) || !in_array($e, array('i', 'c'), true)) return new WP_REST_Response(null, 204);
        if (get_post_status($q) !== 'publish' || ($z && get_post_status($z) !== 'publish')) return new WP_REST_Response(null, 204);
        $t = $wpdb->prefix . 'ab1_wl_zaehler';
        $wpdb->query($wpdb->prepare("INSERT INTO $t (tag,bereich,variante,ereignis,quelle,ziel,pos,n) VALUES (%s,%s,%s,%s,%d,%d,%d,1) ON DUPLICATE KEY UPDATE n=n+1", gmdate('Y-m-d'), $b, $v, $e, $q, $z, $p));
        return new WP_REST_Response(null, 204);
    }

    public static function rest_popup($req) {
        global $wpdb;
        $o = self::opt();
        $t = $wpdb->prefix . 'ab1_wl_kennung';
        $k = self::meine_kennung();
        $erlaubt = array_values(array_filter(str_split(preg_replace('/[^a-e]/', '', (string) $req->get_param('v')))));
        $res = new WP_REST_Response(array('zeigen' => false));
        $res->header('Cache-Control', 'no-store');
        if (!$o['popup'] || !$erlaubt) return $res;
        $r = $wpdb->get_row($wpdb->prepare("SELECT popup_bis, nein FROM $t WHERE kennung=%s", $k), ARRAY_A);
        $jetzt = gmdate('Y-m-d H:i:s');
        if ($r && ((int) $r['nein'] === 1 || $r['popup_bis'] > $jetzt)) return $res;
        $v = self::popup_variante($k, (int) floor(time() / 86400), $erlaubt);
        $bis = gmdate('Y-m-d H:i:s', time() + max(1, (int) $o['pop_tage']) * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare("INSERT INTO $t (kennung,popup_bis,zuletzt,nein,zeit) VALUES (%s,%s,%s,0,%s) ON DUPLICATE KEY UPDATE popup_bis=VALUES(popup_bis),zuletzt=VALUES(zuletzt),zeit=VALUES(zeit)", $k, $bis, $v, $jetzt));
        $res->set_data(array('zeigen' => true, 'v' => $v));
        return $res;
    }

    /** „Nicht mehr anzeigen“ als normaler Link, serverseitig, ohne Speicherung im Browser. */
    public static function nein_link() {
        if (empty($_GET['ab1wl_nein'])) return;
        global $wpdb;
        $t = $wpdb->prefix . 'ab1_wl_kennung';
        $k = self::meine_kennung(); $jetzt = gmdate('Y-m-d H:i:s');
        $wpdb->query($wpdb->prepare("INSERT INTO $t (kennung,popup_bis,zuletzt,nein,zeit) VALUES (%s,%s,'',1,%s) ON DUPLICATE KEY UPDATE nein=1,zeit=VALUES(zeit)", $k, $jetzt, $jetzt));
        $ziel = wp_get_referer();
        if (!$ziel || strpos($ziel, home_url()) !== 0) $ziel = home_url('/');
        nocache_headers();
        wp_safe_redirect($ziel);
        exit;
    }

    /* ---------- Admin ---------- */

    public static function metabox() {
        add_meta_box('ab1_wl', 'Weiterlesen (1AB)', array(__CLASS__, 'metabox_html'), 'post', 'side');
    }
    public static function metabox_html($post) {
        wp_nonce_field('ab1_wl_mb', 'ab1_wl_mb_n');
        $m = get_post_meta($post->ID, '_ab1_wl_modus', true) ?: 'auto';
        echo '<p><label>Modus<br><select name="ab1_wl_modus">';
        foreach (array('auto' => 'Automatisch', 'manuell' => 'Manuell', 'gemischt' => 'Gemischt (manuelle zuerst)', 'aus' => 'Keine Empfehlungen') as $k => $l) echo '<option value="' . esc_attr($k) . '"' . selected($m, $k, false) . '>' . esc_html($l) . '</option>';
        echo '</select></label></p><p><label>Manuell: Artikel-IDs, kommagetrennt (bis 4)<br><input type="text" class="widefat" name="ab1_wl_manuell" value="' . esc_attr(get_post_meta($post->ID, '_ab1_wl_manuell', true)) . '"></label></p>';
        echo '<p><label>Gewicht dieses Artikels (0–5, Säulenartikel höher)<br><input type="number" min="0" max="5" step="0.5" name="ab1_wl_gewicht" value="' . esc_attr(get_post_meta($post->ID, '_ab1_wl_gewicht', true)) . '"></label></p>';
    }
    public static function metabox_speichern($id, $post) {
        if (!isset($_POST['ab1_wl_mb_n']) || !wp_verify_nonce($_POST['ab1_wl_mb_n'], 'ab1_wl_mb') || !current_user_can('edit_post', $id) || wp_is_post_autosave($id)) return;
        $m = isset($_POST['ab1_wl_modus']) ? sanitize_key($_POST['ab1_wl_modus']) : 'auto';
        update_post_meta($id, '_ab1_wl_modus', in_array($m, array('auto', 'manuell', 'gemischt', 'aus'), true) ? $m : 'auto');
        $ids = array_slice(array_filter(array_map('intval', explode(',', (string) ($_POST['ab1_wl_manuell'] ?? '')))), 0, 4);
        update_post_meta($id, '_ab1_wl_manuell', implode(',', $ids));
        update_post_meta($id, '_ab1_wl_gewicht', max(0, min(5, (float) ($_POST['ab1_wl_gewicht'] ?? 0))));
    }

    public static function menue() {
        if (apply_filters('ab1_wl_eigenes_menue', true)) add_options_page('Weiterlesen (1AB)', 'Weiterlesen (1AB)', 'manage_options', 'ab1-wl', array(__CLASS__, 'seite'));
    }

    public static function einstellungen_speichern() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung');
        check_admin_referer('ab1_wl_s');
        $s = self::std(); $in = wp_unslash($_POST);
        $n = array();
        foreach (array('weiterlesen', 'popup', 'ab', 'teaser_oben', 'push_themenfrei') as $k) $n[$k] = empty($in[$k]) ? 0 : 1;
        foreach (array('anzahl' => array(1, 6), 'teaser_absatz' => array(1, 20), 'pop_sek' => array(3, 120), 'pop_tage' => array(1, 90)) as $k => $r) $n[$k] = max($r[0], min($r[1], (int) ($in[$k] ?? $s[$k])));
        foreach (array('ueberschrift', 'pop_titel', 'pop_knopf', 'pop_nein') as $k) $n[$k] = sanitize_text_field($in[$k] ?? $s[$k]);
        $push = array();
        for ($i = 0; $i < 5; $i++) {
            $p = (int) ($in['push'][$i] ?? 0);
            $push[] = ($p && get_post_type($p) === 'post' && get_post_status($p) === 'publish') ? $p : 0;
        }
        $n['push'] = $push;
        update_option(self::OPT, $n);
        self::cache_leeren();
        wp_safe_redirect(add_query_arg('ok', '1', apply_filters('ab1_wl_seiten_url', admin_url('options-general.php?page=ab1-wl'))));
        exit;
    }

    /** Aufrufe je Artikel (30 Tage): Mini-Statistik, falls vorhanden; sonst eigene Einblendungen der Box. */
    public static function besucher() {
        global $wpdb;
        $v = array(); $quelle = '';
        $t = $wpdb->prefix . 'mini_statistik';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t) {
            $quelle = 'Mini-Statistik';
            $rows = $wpdb->get_results("SELECT path, COUNT(*) n FROM $t WHERE kind='view' AND day >= (CURDATE() - INTERVAL 30 DAY) GROUP BY path ORDER BY n DESC LIMIT 300", ARRAY_A);
            foreach ($rows as $r) { $id = url_to_postid(home_url($r['path'])); if ($id) $v[$id] = ($v[$id] ?? 0) + (int) $r['n']; }
        } else {
            $quelle = 'eigene Einblendungen der Weiterlesen-Box';
            $z = $wpdb->prefix . 'ab1_wl_zaehler';
            $rows = $wpdb->get_results("SELECT quelle, SUM(n) n FROM $z WHERE bereich='wl' AND ereignis='i' AND tag >= (CURDATE() - INTERVAL 30 DAY) GROUP BY quelle ORDER BY n DESC LIMIT 300", ARRAY_A);
            foreach ($rows as $r) $v[(int) $r['quelle']] = (int) $r['n'];
        }
        return array($v, $quelle);
    }

    /** Fünf Vorschläge: meistbesuchte Artikel, ohne noindex, höchstens zwei je Kategorie. */
    public static function vorschlaege($n = 5) {
        list($v, $quelle) = self::besucher();
        $out = array(); $kat = array();
        foreach ($v as $id => $aufrufe) {
            if (get_post_type($id) !== 'post' || get_post_status($id) !== 'publish') continue;
            $i = self::info($id);
            if ($i['noindex']) continue;
            $k = $i['kat'] ? $i['kat'][0] : 0;
            if (($kat[$k] ?? 0) >= 2) continue;
            $kat[$k] = ($kat[$k] ?? 0) + 1;
            $out[] = array('id' => $id, 'aufrufe' => $aufrufe);
            if (count($out) >= $n) break;
        }
        if (count($out) < $n) { // Notbehelf: neueste Artikel, wenn noch keine Zahlen da sind
            $ids = get_posts(array('numberposts' => $n * 3, 'fields' => 'ids', 'post_status' => 'publish'));
            foreach ($ids as $id) { if (count($out) >= $n) break; if (!in_array($id, array_column($out, 'id'), true)) $out[] = array('id' => $id, 'aufrufe' => 0); }
        }
        return array($out, $quelle);
    }

    private static function ergebnis_tabelle($bereich, $titel, $beschreibung) {
        global $wpdb;
        $t = $wpdb->prefix . 'ab1_wl_zaehler';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT variante,ereignis,SUM(n) n FROM $t WHERE bereich=%s AND tag >= (CURDATE() - INTERVAL 30 DAY) GROUP BY variante,ereignis", $bereich), ARRAY_A);
        $m = array();
        foreach ($rows as $r) $m[$r['variante']][$r['ereignis']] = (int) $r['n'];
        $push = array_values((array) self::opt('push'));
        $h = '<div class="ab1-karte"><h3>' . esc_html($titel) . '</h3><p class="d">' . esc_html($beschreibung) . '</p>';
        if (!$m) return $h . '<p class="d">Noch keine Daten. Die Messung beginnt, sobald die Funktion eingeschaltet ist.</p></div>';
        ksort($m);
        $max = 0.0001;
        foreach ($m as $e) { $i = $e['i'] ?? 0; if ($i) $max = max($max, ($e['c'] ?? 0) / $i); }
        $h .= '<table class="ab1-t"><thead><tr><th>Variante</th><th>Artikel</th><th>Einblendungen</th><th>Klicks</th><th>Klickrate</th><th>95-%-Bereich</th></tr></thead><tbody>';
        foreach ($m as $v => $e) {
            $i = $e['i'] ?? 0; $c = $e['c'] ?? 0; list($lo, $hi) = self::wilson($c, $i);
            $idx = ord($v) - 97; $pid = (int) ($push[$idx] ?? 0);
            $rate = $i ? $c / $i : 0;
            $h .= '<tr><td><b>' . esc_html(strtoupper($v)) . '</b></td><td>' . ($pid ? esc_html(get_the_title($pid)) : '–') . '</td><td>' . (int) $i . '</td><td>' . (int) $c . '</td><td><div class="ab1-bar"><i style="width:' . round(100 * $rate / $max) . '%"></i></div>' . ($i ? esc_html(number_format_i18n(100 * $rate, 1)) . ' %' : '–') . '</td><td>' . ($i ? esc_html(number_format_i18n(100 * $lo, 1) . ' – ' . number_format_i18n(100 * $hi, 1)) . ' %' : '–') . '</td></tr>';
        }
        $h .= '</tbody></table>';
        $voll = true; foreach ($m as $e) if (($e['i'] ?? 0) < 300) $voll = false;
        $h .= '<p class="d">' . ($voll ? 'Genug Einblendungen je Variante. Ein Sieger ist erst klar, wenn sich die 95-%-Bereiche nicht überlappen.' : 'Vorläufig: unter 300 Einblendungen je Variante, noch kein Sieger erkennbar.') . ' Es wird nichts automatisch umgestellt.</p></div>';
        return $h;
    }

    /** Als Reiter in der Mini-Statistik: AB1_Weiterlesen::seite() ausgeben (ohne umgebendes wrap). */
    public static function tab() { self::seite(); }

    public static function seite() {
        if (!current_user_can('manage_options')) return;
        $o = self::opt();
        $push = array_pad(array_values((array) $o['push']), 5, 0);
        list($vor, $vquelle) = self::vorschlaege(5);
        $ck = function ($n, $l, $d = '') use ($o) {
            return '<label class="ab1-ck"><input type="checkbox" name="' . esc_attr($n) . '" value="1"' . checked($o[$n], 1, false) . '> <b>' . esc_html($l) . '</b>' . ($d ? '<span class="d">' . esc_html($d) . '</span>' : '') . '</label>';
        };
        $f = function ($n, $l, $w = 'regular-text') use ($o) {
            return '<label class="ab1-f">' . esc_html($l) . '<input name="' . esc_attr($n) . '" value="' . esc_attr($o[$n]) . '" class="' . $w . '"></label>';
        };
        echo '<style>.ab1-w{max-width:1000px}.ab1-karte{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px 20px;margin:16px 0}.ab1-karte h3{margin:0 0 4px;font-size:1.15em}.d{display:block;color:#646970;margin:2px 0 8px;font-size:12.5px}'
            . '.ab1-slots{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px}.ab1-slot{border:2px solid #e3e8e0;border-radius:12px;padding:10px;background:#f8faf7;position:relative}.ab1-slot .b{position:absolute;top:-10px;left:10px;background:#2f7d32;color:#fff;border-radius:10px;padding:0 9px;font-weight:700}'
            . '.ab1-slot img{width:100%;aspect-ratio:3/2;object-fit:cover;border-radius:8px;background:#e5eadf}.ab1-slot .t{font-weight:600;min-height:2.6em;margin:6px 0}.ab1-slot input[type=search]{width:100%}.ab1-erg{list-style:none;margin:2px 0 0;padding:0;max-height:180px;overflow:auto;background:#fff;border:1px solid #dcdcde;border-radius:6px}.ab1-erg:empty{display:none}.ab1-erg li{padding:6px 8px;cursor:pointer;margin:0}.ab1-erg li:hover{background:#eef5ec}'
            . '.ab1-vor{display:flex;flex-wrap:wrap;gap:8px}.ab1-vor button{border:1px solid #2f7d32;background:#fff;color:#2f7d32;border-radius:20px;padding:4px 12px;cursor:pointer}.ab1-vor button:hover{background:#2f7d32;color:#fff}'
            . '.ab1-ck{display:block;margin:8px 0}.ab1-f{display:block;margin:8px 0}.ab1-f input{display:block}.ab1-t{border-collapse:collapse;width:100%}.ab1-t th,.ab1-t td{text-align:left;padding:7px 8px;border-bottom:1px solid #eee}.ab1-bar{height:8px;background:#eef2ec;border-radius:4px;margin-bottom:3px;width:140px}.ab1-bar i{display:block;height:100%;background:#2f7d32;border-radius:4px}</style>';
        echo '<div class="wrap ab1-w"><h1>Weiterlesen und Exit-Popup (1AB)</h1>';
        if (!empty($_GET['ok'])) echo '<div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ab1_wl_speichern">';
        wp_nonce_field('ab1_wl_s');
        echo '<div class="ab1-karte"><h3>Schalter</h3><p class="d">Beides ist standardmäßig aus. Contextual Related Posts bleibt aktiv, bis es nach dem Test bewusst abgeschaltet wird.</p>'
            . $ck('weiterlesen', 'Weiterlesen-Box unter den Artikeln') . $ck('popup', 'Exit-Popup') . $ck('ab', 'A/B-Test der gepushten Artikel in der Weiterlesen-Box')
            . $ck('teaser_oben', 'Kleinen Teaser weiter oben im Artikel testen', 'Nur zwischen normalen Absätzen, nie in Listen, Tabellen oder Boxen.') . '</div>';
        echo '<div class="ab1-karte"><h3>Gepushte Artikel (bis zu fünf, Varianten A bis E)</h3><p class="d">Tippe einen Titel in ein Feld und wähle den Artikel. Jeder gepushte Artikel erscheint im Popup und auf Platz 1 der Weiterlesen-Box. Welcher, entscheidet je Besucher die serverseitige Kennung plus Tageszahl (wechselt täglich, nichts im Browser).</p><div class="ab1-slots">';
        foreach ($push as $i => $pid) {
            $L = chr(65 + $i);
            $bild = $pid ? get_the_post_thumbnail_url($pid, 'medium') : '';
            echo '<div class="ab1-slot" data-i="' . $i . '"><span class="b">' . $L . '</span>' . ($bild ? '<img src="' . esc_url($bild) . '" alt="">' : '<img alt="" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==">')
                . '<div class="t">' . ($pid ? esc_html(get_the_title($pid)) : 'noch leer') . '</div><input type="hidden" name="push[' . $i . ']" value="' . (int) $pid . '">'
                . '<input type="search" placeholder="Artikel suchen …" autocomplete="off"><ul class="ab1-erg"></ul><a href="#" class="ab1-weg">entfernen</a></div>';
        }
        echo '</div>' . $ck('push_themenfrei', 'Gepushte Artikel auch unter Artikeln anderer Kategorie zeigen', 'Sprache, noindex und gleiches Fokus-Keyword werden weiter geprüft. Aus = nur bei gleicher Kategorie, dann sehen weniger Seiten den Test.') . '</div>';
        echo '<div class="ab1-karte"><h3>Vorschläge nach Besucherzahlen</h3><p class="d">Meistbesuchte Artikel der letzten 30 Tage (Quelle: ' . esc_html($vquelle) . '), höchstens zwei je Kategorie. Klick übernimmt in den nächsten freien Platz.</p><div class="ab1-vor">';
        foreach ($vor as $r) echo '<button type="button" data-id="' . (int) $r['id'] . '" data-t="' . esc_attr(get_the_title($r['id'])) . '" data-b="' . esc_url(get_the_post_thumbnail_url($r['id'], 'medium')) . '">' . esc_html(get_the_title($r['id'])) . ($r['aufrufe'] ? ' · ' . (int) $r['aufrufe'] : '') . '</button>';
        echo '</div></div>';
        echo '<div class="ab1-karte"><h3>Einstellungen</h3>' . $f('ueberschrift', 'Überschrift der Box') . $f('anzahl', 'Anzahl Karten (1–6)', 'small-text') . $f('teaser_absatz', 'Teaser nach Absatz Nr.', 'small-text')
            . $f('pop_sek', 'Popup frühestens nach … Sekunden', 'small-text') . $f('pop_tage', 'Popup höchstens alle … Tage je Besucher', 'small-text')
            . $f('pop_titel', 'Popup-Titel') . $f('pop_knopf', 'Popup-Knopftext') . $f('pop_nein', 'Text „Nicht mehr anzeigen“') . '</div>';
        echo '<p><button class="button button-primary button-hero">Speichern</button></p></form>';
        echo '<h2>Vorläufige Ergebnisse, letzte 30 Tage</h2>'
            . self::ergebnis_tabelle('pu', 'Exit-Popup', 'Eine Einblendung zählt, wenn das Popup erscheint; ein Klick, wenn der Artikel angeklickt wird.')
            . self::ergebnis_tabelle('wl', 'Weiterlesen-Box, Platz 1', 'Einblendung zählt, wenn mindestens die Hälfte der Box im Sichtbereich war.')
            . self::ergebnis_tabelle('tz', 'Teaser weiter oben', 'Nur wenn der Teaser getestet wird.');
        $z = $GLOBALS['wpdb']->prefix . 'ab1_wl_zaehler';
        $top = $GLOBALS['wpdb']->get_results("SELECT ziel, SUM(n) n FROM $z WHERE ereignis='c' AND bereich='wl' AND tag >= (CURDATE() - INTERVAL 30 DAY) GROUP BY ziel ORDER BY n DESC LIMIT 10", ARRAY_A);
        if ($top) { echo '<div class="ab1-karte"><h3>Meistgeklickte Weiterlesen-Ziele</h3><ol>'; foreach ($top as $r) echo '<li>' . esc_html(get_the_title((int) $r['ziel'])) . ' (' . (int) $r['n'] . ')</li>'; echo '</ol></div>'; }
        $api = esc_url_raw(rest_url('wp/v2/posts'));
        echo '</div><script>(function(){var A=' . wp_json_encode($api) . ',N=' . wp_json_encode(wp_create_nonce('wp_rest')) . ';'
            . 'function set(sl,id,t,b){sl.querySelector("input[type=hidden]").value=id;sl.querySelector(".t").textContent=t||"noch leer";if(b)sl.querySelector("img").src=b;sl.querySelector(".ab1-erg").innerHTML="";}'
            . '[].forEach.call(document.querySelectorAll(".ab1-slot"),function(sl){var inp=sl.querySelector("input[type=search]"),ul=sl.querySelector(".ab1-erg"),tm;'
            . 'inp.addEventListener("input",function(){clearTimeout(tm);var q=inp.value.trim();if(q.length<2){ul.innerHTML="";return}tm=setTimeout(function(){fetch(A+"?per_page=8&_fields=id,title,jetpack_featured_media_url&search="+encodeURIComponent(q),{headers:{"X-WP-Nonce":N}}).then(function(r){return r.json()}).then(function(j){ul.innerHTML="";j.forEach(function(p){var li=document.createElement("li");li.textContent=p.title.rendered.replace(/<[^>]+>/g,"");li.onclick=function(){set(sl,p.id,li.textContent,"");inp.value=""};ul.appendChild(li)})})},250)});'
            . 'sl.querySelector(".ab1-weg").onclick=function(e){e.preventDefault();set(sl,0,"","")}});'
            . '[].forEach.call(document.querySelectorAll(".ab1-vor button"),function(b){b.onclick=function(){var s=[].slice.call(document.querySelectorAll(".ab1-slot")).filter(function(x){return x.querySelector("input[type=hidden]").value==="0"})[0];if(s)set(s,b.getAttribute("data-id"),b.getAttribute("data-t"),b.getAttribute("data-b"))}})})();</script>';
    }
}

