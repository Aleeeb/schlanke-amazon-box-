<?php
/**
 * Plugin Name: 1AB-Test-Statistik (Basis, Entwurf für WordPress.org)
 * Description: Cookielose, datensparsame Besucherstatistik (Aufrufe, Verweildauer, Scrolltiefe, Klicks auf Partnerlinks, Echtzeit). Die IP wird nie gespeichert.
 * Version: 0.37.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: 1ab-statistik
 */
if (!defined('ABSPATH')) exit;

class Mini_Statistik {
    const OPT = 'mini_statistik';
    const VERSION = '0.37.0';
    const DB_VERSION = '7';

    static function opts() {
        return wp_parse_args(get_option(self::OPT, []), [
            'mode' => 'off',      // off | hub | client
            'hub_url' => '',      // nur Client: https://hub-seite.de
            'site_key' => '',     // Kennwort dieser Seite, am Hub eingetragen
            'sites' => '',        // nur Hub: eine Zeile je Seite "domain|key"
            'keep_days' => 0,     // nur Hub: Aufbewahrung in Tagen, 0 = unbegrenzt
            'salt_days' => 7,     // nur Hub: so viele Tage bleibt die Besucher-Kennung gültig (1 = täglich neu)
        ]);
    }

    static function init() {
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('wp_footer', [__CLASS__, 'print_script'], 99);
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('mini_statistik_cleanup', [__CLASS__, 'cleanup']);
        add_action('wp_ajax_mini_statistik_live', [__CLASS__, 'live']);
        add_action('admin_init', [__CLASS__, 'maybe_upgrade']);
    }

    static function table() { global $wpdb; return $wpdb->prefix . 'mini_statistik'; }
    static function table_ga() { global $wpdb; return $wpdb->prefix . 'mini_statistik_ga'; }

    static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        dbDelta("CREATE TABLE $t (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            day DATE NOT NULL,
            ts DATETIME NOT NULL,
            kind VARCHAR(5) NOT NULL DEFAULT 'view',
            site VARCHAR(100) NOT NULL,
            path VARCHAR(255) NOT NULL,
            visitor CHAR(16) NOT NULL,
            ref VARCHAR(100) NOT NULL DEFAULT '',
            device VARCHAR(10) NOT NULL DEFAULT '',
            cc CHAR(2) NOT NULL DEFAULT '',
            target VARCHAR(100) NOT NULL DEFAULT '',
            label VARCHAR(80) NOT NULL DEFAULT '',
            item VARCHAR(20) NOT NULL DEFAULT '',
            img VARCHAR(500) NOT NULL DEFAULT '',
            pn VARCHAR(100) NOT NULL DEFAULT '',
            via VARCHAR(3) NOT NULL DEFAULT '',
            secs SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            scroll TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY day_site (day, site),
            KEY ts (ts)
        ) {$wpdb->get_charset_collate()};");
        $g = self::table_ga();
        dbDelta("CREATE TABLE $g (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            day DATE NOT NULL,
            site VARCHAR(100) NOT NULL,
            path VARCHAR(255) NOT NULL DEFAULT '',
            views INT UNSIGNED NOT NULL DEFAULT 0,
            users INT UNSIGNED NOT NULL DEFAULT 0,
            secs INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY site_day (site, day)
        ) {$wpdb->get_charset_collate()};");
        if (!wp_next_scheduled('mini_statistik_cleanup')) wp_schedule_event(time() + 3600, 'daily', 'mini_statistik_cleanup');
    }

    static function maybe_upgrade() {
        if (get_option('mini_statistik_db') !== self::DB_VERSION) { self::activate(); update_option('mini_statistik_db', self::DB_VERSION, false); }
    }

    static function cleanup() {
        global $wpdb;
        $keep = (int)self::opts()['keep_days'];
        if ($keep < 1) return; // 0 = nichts löschen
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE day < %s', gmdate('Y-m-d', time() - $keep * 86400)));
    }

    // ---------- Zählskript (alle Seiten) ----------
    static function print_script() {
        $o = self::opts();
        $why = $o['mode'] === 'off' ? 'aus' : (is_admin() ? 'admin' : (is_user_logged_in() ? 'angemeldet' : ((is_preview() || is_customize_preview()) ? 'vorschau' : (($o['mode'] === 'client' && !$o['hub_url']) ? 'keine-zentrale' : 'ok'))));
        echo "\n<!-- mini-statistik 0.37 {$o['mode']} {$why} -->\n";
        if ($why === 'angemeldet' && current_user_can('edit_posts')) echo '<script>try{localStorage.setItem("ms_off","1")}catch(e){}</script>' . "\n"; // eigenes Gerät markieren: Besuche ohne Anmeldung zählen dann auch nicht
        if ($why !== 'ok') return;
        // ?rest_route= funktioniert unabhängig von den Permalink-Einstellungen
        if ($o['mode'] === 'hub') $endpoint = home_url('/?rest_route=/mini-statistik/v1/hit');
        else $endpoint = trailingslashit($o['hub_url']) . '?rest_route=/mini-statistik/v1/hit';
        if ($o['mode'] === 'client' && !$o['hub_url']) return;
        $site = wp_parse_url(home_url(), PHP_URL_HOST);
        $key = $o['site_key'];
        if ($o['mode'] === 'hub' && $key === '') $key = self::allowed_sites()[preg_replace('/^www\./', '', strtolower($site))] ?? '';
        $payload = ['e' => $endpoint, 's' => $site, 'k' => $key];
        // Roboter ohne JavaScript sieht nur der Server: Zugriff vermerken (je Roboter und Seite höchstens alle 10 Minuten; der Seitenaufbau wartet nicht darauf)
        $ua_ = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (self::is_bot($ua_) && !is_404() && ($bi = self::bot_info($ua_))) {
            $rp = substr(wp_parse_url('http://x' . (string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/', 0, 255);
            $tk = 'ms_rb_' . md5($bi[0] . $rp);
            if (get_transient($tk) === false) {
                set_transient($tk, 1, 600);
                if ($o['mode'] === 'hub') self::note_robot(preg_replace('/^www\./', '', strtolower($site)), $rp, $ua_, 'srv');
                else wp_remote_post($endpoint, ['blocking' => false, 'timeout' => 0.5, 'headers' => ['Content-Type' => 'text/plain'], 'body' => wp_json_encode(['t' => 'r', 's' => $site, 'k' => $key, 'p' => $rp, 'u' => $ua_])]);
            }
        }
        ?>
<script>(function(){var c=<?php echo wp_json_encode($payload); ?>;if(navigator.doNotTrack==="1")return;try{if(localStorage.getItem("ms_off"))return;}catch(e){}
function send(d){d.s=c.s;d.k=c.k;d.p=location.pathname;try{var b=new Blob([JSON.stringify(d)],{type:"text/plain"});
if(!(navigator.sendBeacon&&navigator.sendBeacon(c.e,b)))fetch(c.e,{method:"POST",body:b,keepalive:true,mode:"no-cors"});}catch(e){}}
var tz="";try{tz=Intl.DateTimeFormat().resolvedOptions().timeZone||"";}catch(e){}
var wd=0;try{wd=(navigator.webdriver===true||/HeadlessChrome|PhantomJS/i.test(navigator.userAgent)||!navigator.languages||!navigator.languages.length)?1:0;}catch(e){}
send({t:"v",r:document.referrer,w:screen.width,z:tz,g:(navigator.language||""),a:wd});
var hs=0,hev=["pointermove","pointerdown","touchstart","keydown","wheel","scroll"];function hu(){if(hs)return;hs=1;hev.forEach(function(n){removeEventListener(n,hu,true);});send({t:"h"});}
hev.forEach(function(n){addEventListener(n,hu,{capture:true,passive:true});});
var act=0,last=Date.now(),vis=!document.hidden,sc=0,pend=0,out=0;
function tick(){var n=Date.now();if(vis)act+=n-last;last=n;}
function scrolled(){var h=document.documentElement.scrollHeight||1,p=Math.round(100*((window.pageYOffset||0)+window.innerHeight)/h);if(p>sc)sc=Math.min(100,p);}
function flush(){tick();var s=Math.round(act/1000);if(s>=1){send({t:"t",x:Math.min(s,1800),g:sc});act=0;}}
addEventListener("scroll",scrolled,{passive:true});scrolled();
function back(){if(!pend)return;var d=Math.round((Date.now()-out)/1000);pend=0;if(d>=1&&d<=10800)send({t:"b",d:d});}
document.addEventListener("visibilitychange",function(){tick();vis=!document.hidden;last=Date.now();if(document.hidden)flush();else back();});
addEventListener("pageshow",function(e){if(e.persisted){last=Date.now();vis=!document.hidden;back();}});
addEventListener("pagehide",flush);
function item(a){var m,p=a.pathname||"";if(/amazon|amzn/i.test(a.hostname)){m=p.match(/\/(?:dp|gp\/product|gp\/aw\/d|exec\/obidos\/ASIN|ASIN)\/([A-Z0-9]{10})/i);return m?m[1].toUpperCase():"";}
if(/ebay\./i.test(a.hostname)){m=p.match(/\/itm\/(?:[^\/]*\/)?(\d{9,})/)||(a.search||"").match(/[?&]item=(\d{9,})/);return m?m[1]:"";}return "";}
function cl(s){return (s||"").replace(/\s+/g," ").trim();}
function gen(s){return s.length<4||/^(angebot|jetzt|zum |zur |bei |auf amazon|auf ebay|amazon$|ebay$|ansehen|kaufen|mehr|hier$|link|weiter|preis|produkt$)/i.test(s);}
function src(i){if(!i)return "";var c=[i.getAttribute("data-src"),i.getAttribute("data-lazy-src"),i.getAttribute("data-orig-file"),i.currentSrc,i.src,(i.getAttribute("data-srcset")||i.getAttribute("srcset")||"").split(/\s+/)[0]],k,s;for(k=0;k<c.length;k++){s=c[k]||"";if(s.indexOf("//")===0)s=location.protocol+s;if(/^https?:/i.test(s))return s.split("?")[0].slice(0,500);}return "";}
function ph(a){var e=a,k,s,h;for(k=0;k<8&&e;k++){s=e.previousElementSibling;while(s){if(/^H[1-6]$/.test(s.tagName))return cl(s.textContent);h=s.querySelectorAll?s.querySelectorAll("h2,h3,h4"):[];if(h.length)return cl(h[h.length-1].textContent);s=s.previousElementSibling;}e=e.parentElement;}return "";}
function bx(a){var e=a,n=0,h=(a.hostname.split(".").slice(-2,-1)[0]||"x");while(e.parentElement&&n<3){e=e.parentElement;n++;if(e.querySelectorAll('a[href*="'+h+'"]').length>4)return null;if(e.querySelector("img"))return e;}return null;}
function lc(td){var n=0,e=td.previousElementSibling;while(e){n+=e.colSpan||1;e=e.previousElementSibling;}return n;}
function shop(h){return /amazon|amzn/i.test(h)?"a":/ebay\./i.test(h)?"e":"";}
function tbl(a){var td=a.closest("td,th"),t=td&&td.closest("table"),r,c,k,x,j,w,l,e,z;if(!t)return null;c=lc(td);r=td.parentElement.rowIndex;
for(k=r-1;k>=0&&k>=r-6;k--){x=t.rows[k];if(!x)continue;for(j=0;j<x.cells.length;j++){w=lc(x.cells[j]);if(c>=w&&c<w+(x.cells[j].colSpan||1)){l=x.cells[j].querySelectorAll("a[href]");for(e=0;e<l.length;e++){z=item(l[e]);if(z&&shop(l[e].hostname)===shop(a.hostname))return{it:z,i:l[e].querySelector("img")};}}}}return null;}
function tnm(a){var td=a.closest("td,th"),t=td&&td.closest("table"),c,r,k,x,j,w,s,z,o=a.querySelector("img")?[1,-1]:[-1,1];if(!t)return "";c=lc(td);r=td.parentElement.rowIndex;for(z=0;z<2;z++)for(k=1;k<=4;k++){x=t.rows[r+o[z]*k];if(!x)break;for(j=0;j<x.cells.length;j++){w=lc(x.cells[j]);if(c>=w&&c<w+(x.cells[j].colSpan||1)){if(x.cells[j].querySelector("img"))continue;s=cl(x.cells[j].textContent);if(s.length>=8&&!gen(s)&&/[A-Za-z\u00c4\u00d6\u00dc\u00e4\u00f6\u00fc\u00df]{4,}/.test(s))return s;}}}return "";}
function box1(a){var e=a,k,l,j,z,seen,sh=shop(a.hostname);for(k=0;k<7&&e.parentElement;k++){e=e.parentElement;l=e.querySelectorAll("a[href]");seen={};var n=0,hit=null;for(j=0;j<l.length;j++){if(l[j]===a||shop(l[j].hostname)!==sh)continue;z=item(l[j]);if(z&&!seen[z]){seen[z]=1;n++;if(!hit||l[j].querySelector("img"))hit={it:z,i:l[j].querySelector("img")};}}if(n===1)return hit;if(n>1)return null;}return null;}
function nb(a){var l=[].slice.call(document.querySelectorAll("a[href]")).filter(function(x){return shop(x.hostname)===shop(a.hostname)}),k=l.indexOf(a),j,z,e,n;if(k<0)return null;for(j=k-1;j>=0&&j>=k-2;j--){z=item(l[j]);if(z){e=a;for(n=0;n<5&&e.parentElement;n++){e=e.parentElement;if(e.contains(l[j]))return{it:z,i:l[j].querySelector("img")};}return null;}}return null;}
function click(e){var a=e.target&&e.target.closest?e.target.closest("a[href]"):null;if(!a||!a.hostname||a.hostname===location.hostname)return;
var it=item(a),i=a.querySelector("img"),via=i?"img":"txt",b,pn="",l=cl(a.getAttribute("aria-label")||a.textContent||(i&&i.alt)||a.title);
if(it||/amazon|amzn|ebay/i.test(a.hostname)){if(!it){var tt=tbl(a)||box1(a)||nb(a);if(tt){it=tt.it;if(!i)i=tt.i;}}b=bx(a);if(!i&&it)i=document.querySelector('a[href*="'+it+'"] img');if(!i&&b)i=b.querySelector("img");
pn=cl(i&&i.alt);if(gen(pn))pn=cl(a.title);if(gen(pn))pn=tnm(a);if(gen(pn)&&b){var hd=b.querySelector("h1,h2,h3,h4,h5,h6,figcaption")||b.querySelector("strong,b");pn=hd?cl(hd.textContent):"";}if(gen(pn))pn=ph(a);if(gen(l)===false&&gen(pn))pn=l;}
var sc=(/^(amzn\.to|a\.co)$/i.test(a.hostname)&&!item(a))?(a.pathname||"").replace(/^\//,"").split("/")[0].slice(0,12):"";
flush();send({t:"c",to:a.hostname,sc:sc,l:l.slice(0,80),i:it,m:src(i),v:via,n:pn.slice(0,100)});pend=1;out=Date.now();}
document.addEventListener("click",click,true);document.addEventListener("auxclick",click,true);})();</script>
        <?php
    }

    // ---------- Sammelstelle (Hub) ----------
    static function routes() {
        register_rest_route('mini-statistik/v1', '/hit', [
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => [__CLASS__, 'hit'],
        ]);
    }

    static function allowed_sites() {
        $out = [];
        foreach (preg_split('/\R/', self::opts()['sites']) as $line) {
            $p = array_map('trim', explode('|', $line));
            if (count($p) === 2 && $p[0] !== '' && $p[1] !== '') $out[preg_replace('/^www\./', '', strtolower($p[0]))] = $p[1];
        }
        return $out;
    }

    // Kurzlinks (amzn.to/xxxx) enthalten keine Produktnummer: die Zentrale liest einmal die Weiterleitung und merkt sich die ASIN (Partner-ID und Query werden nie gespeichert)
    static function resolve_short($host, $code) {
        $k = 'ms_sc_' . md5($host . '/' . $code);
        $c = get_transient($k);
        if ($c !== false) return (string)$c;
        $asin = '';
        $r = wp_remote_head('https://' . $host . '/' . $code, ['redirection' => 0, 'timeout' => 4, 'user-agent' => 'Mozilla/5.0 (compatible; MiniStatistik)']);
        if (!is_wp_error($r)) {
            $loc = (string)wp_remote_retrieve_header($r, 'location');
            if (preg_match('~/(?:dp|gp/product|gp/aw/d|exec/obidos/ASIN|ASIN)/([A-Z0-9]{10})~i', $loc, $m)) $asin = strtoupper($m[1]);
        }
        set_transient($k, $asin, $asin !== '' ? 90 * DAY_IN_SECONDS : HOUR_IN_SECONDS);
        return $asin;
    }

    static function is_bot($ua) {
        return $ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|headless|python|curl|wget|monitor|lighthouse|gtmetrix|pingdom/i', $ua);
    }

    // Roboter erkennen: [Muster im User-Agent, Name, Art, Merkmal]; nur der User-Agent wird ausgewertet, nie eine IP
    static function bot_info($ua) {
        static $L = [
            ['/googlebot|google-inspectiontool|googleother|adsbot-google|mediapartners-google|apis-google|storebot-google/i', 'Google', 'Suchmaschine', 'Googlebot ruft Seiten und robots.txt ab; die echte Herkunft lässt sich nur per Rückwärts-DNS beweisen'],
            ['/google-extended|gemini-deep-research|google-cloudvertexbot/i', 'Google KI', 'KI-Crawler', 'Trainings- und KI-Abruf von Google'],
            ['/bingbot|bingpreview|msnbot/i', 'Bing', 'Suchmaschine', 'Microsoft-Suche, auch Grundlage von Copilot'],
            ['/duckduckbot|duckassistbot/i', 'DuckDuckGo', 'Suchmaschine', ''],
            ['/yandex/i', 'Yandex', 'Suchmaschine', ''], ['/baiduspider/i', 'Baidu', 'Suchmaschine', ''],
            ['/applebot/i', 'Apple', 'Suchmaschine', 'Siri/Spotlight-Suche'],
            ['/gptbot|oai-searchbot|chatgpt-user|openai/i', 'OpenAI (ChatGPT)', 'KI-Crawler', 'GPTBot sammelt Trainingsdaten, ChatGPT-User ruft Seiten auf Nutzerwunsch ab'],
            ['/claudebot|claude-web|claude-user|claude-searchbot|anthropic/i', 'Anthropic (Claude)', 'KI-Crawler', ''],
            ['/perplexity/i', 'Perplexity', 'KI-Crawler', ''],
            ['/bytespider|bytedance|tiktokspider/i', 'ByteDance', 'KI-Crawler', 'Sehr häufig und oft ohne Rücksicht auf robots.txt'],
            ['/ccbot/i', 'Common Crawl', 'KI-Crawler', 'Offenes Webarchiv, Grundlage vieler KI-Modelle'],
            ['/amazonbot/i', 'Amazon', 'KI-Crawler', 'Alexa/Amazon-KI'], ['/meta-externalagent|meta-externalfetcher|facebookbot/i', 'Meta', 'KI-Crawler', ''],
            ['/mistralai|cohere|diffbot|youbot|ai2bot|timpibot|petalbot|omgili|imagesift/i', 'Weitere KI-/Daten-Crawler', 'KI-Crawler', ''],
            ['/ahrefsbot|semrushbot|mj12bot|dotbot|dataforseo|serpstat|screaming frog|blexbot|seokicks|sistrix|barkrowler|rogerbot|seranking/i', 'SEO-Werkzeug', 'SEO-Tool', 'Wertet Links und Rankings für Dritte aus; für dich ohne Nutzen'],
            ['/facebookexternalhit|twitterbot|linkedinbot|pinterest|slackbot|whatsapp|telegrambot|discordbot|skypeuri|embedly|redditbot/i', 'Link-Vorschau', 'Social-Vorschau', 'Wird ausgelöst, wenn jemand deinen Link teilt: ein Hinweis auf geteilte Links'],
            ['/uptimerobot|pingdom|statuscake|site24x7|betterstack|monitor|nagios|zabbix|checkly|hetrix/i', 'Überwachung', 'Überwachung', 'Dienst, der regelmäßig prüft, ob deine Seite erreichbar und schnell ist'],
            ['/lighthouse|gtmetrix|pagespeed|webpagetest/i', 'Geschwindigkeitstest', 'Überwachung', 'Misst die Ladezeit deiner Seite'],
            ['/python|curl|wget|go-http|java\/|okhttp|libwww|scrapy|httpclient|node-fetch|axios|php\/|headlesschrome|phantomjs|puppeteer|playwright/i', 'Skript / Programm', 'Skript', 'Kein Browser: Programmierbibliothek oder Headless-Browser'],
            ['/bot|crawl|spider|slurp|preview/i', 'Sonstiger Roboter', 'Unbekannt', 'Nennt sich Bot/Crawler, ist aber keinem bekannten Dienst zuzuordnen'],
        ];
        if (trim($ua) === '') return ['Ohne Kennung', 'Unbekannt', 'Leerer User-Agent: fast immer ein Skript'];
        foreach ($L as $r) {
            if (!preg_match($r[0], $ua)) continue;
            if ($r[2] === 'Unbekannt' && preg_match('/([A-Za-z0-9_.\-]*(?:bot|crawl[a-z]*|spider|slurp|preview)[A-Za-z0-9_.\-]*)/i', $ua, $m)) return [mb_substr($m[1], 0, 40) . ' (nicht in der Liste)', 'Unbekannt', 'Nennt sich selbst so, steht aber nicht in der Liste bekannter Dienste'];
            return [$r[1], $r[2], $r[3]];
        }
        return null;
    }

    // Roboter-Zugriff vermerken (nur Name, Art, Seite, gekürzter User-Agent; keine IP). $ua = User-Agent des Roboters
    static function note_robot($site, $path, $ua, $via) {
        global $wpdb;
        $i = self::bot_info($ua);
        if (!$i) return;
        $path = substr(wp_parse_url('http://x' . (string)$path, PHP_URL_PATH) ?: '/', 0, 255);
        $t = self::table();
        if ($via === 'js' && $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE kind='robot' AND day=%s AND site=%s AND path=%s AND label=%s AND ts > %s LIMIT 1", gmdate('Y-m-d'), $site, $path, $i[0], gmdate('Y-m-d H:i:s', time() - 600)))) return; // schon serverseitig vermerkt
        $wpdb->insert($t, ['day' => gmdate('Y-m-d'), 'ts' => gmdate('Y-m-d H:i:s'), 'kind' => 'robot', 'site' => substr($site, 0, 100), 'path' => $path, 'visitor' => '', 'label' => $i[0], 'target' => $i[1], 'pn' => mb_substr(wp_strip_all_tags($ua), 0, 100), 'via' => $via === 'js' ? 'js' : 'srv']);
    }

    static function visitor_salt() {
        $days = max(1, min(30, (int)self::opts()['salt_days']));
        $s = get_option('mini_statistik_salt');
        $age = is_array($s) ? (time() - strtotime($s['day'] . ' 00:00:00 UTC')) / 86400 : 999;
        if (!is_array($s) || $age >= $days) { // alter Zufallswert wird überschrieben, alte Kennungen sind danach nicht mehr rückrechenbar
            $s = ['day' => gmdate('Y-m-d'), 'salt' => wp_generate_password(32, true, true)];
            update_option('mini_statistik_salt', $s, false);
        }
        return $s['salt'];
    }

    static function hit(WP_REST_Request $req) {
        $none = new WP_REST_Response(null, 204);
        $o = self::opts();
        if ($o['mode'] !== 'hub') return $none;
        $d = json_decode($req->get_body(), true);
        if (!is_array($d)) return $none;
        $site = preg_replace('/^www\./', '', strtolower((string)($d['s'] ?? '')));
        $sites = self::allowed_sites();
        if (!isset($sites[$site]) || !hash_equals($sites[$site], (string)($d['k'] ?? ''))) return $none;
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (($d['t'] ?? '') === 'r') { // Roboter-Meldung einer Sende-Seite (Server an Zentrale), User-Agent steckt in 'u'
            self::note_robot($site, (string)($d['p'] ?? '/'), substr((string)($d['u'] ?? ''), 0, 200), 'srv');
            return $none;
        }
        if (self::is_bot($ua)) {
            if (($d['t'] ?? '') === 'v') self::note_robot($site, (string)($d['p'] ?? '/'), $ua, 'js'); // Roboter mit JavaScript
            return $none;
        }
        $type = (string)($d['t'] ?? 'v');
        if (!in_array($type, ['v', 't', 'c', 'b', 'h'], true)) return $none;

        $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        $ip = trim(explode(',', $ip)[0]);
        // IP und UA nur als Hash mit regelmäßig wechselndem Zufallswert; die IP selbst wird nie gespeichert
        $visitor = substr(hash('sha256', self::visitor_salt() . '|' . $site . '|' . $ip . '|' . $ua), 0, 16);
        $path = substr(wp_parse_url('http://x' . (string)($d['p'] ?? '/'), PHP_URL_PATH) ?: '/', 0, 255); // ohne Query-String

        $row = ['day' => gmdate('Y-m-d'), 'ts' => gmdate('Y-m-d H:i:s'), 'site' => $site, 'path' => $path, 'visitor' => $visitor];
        if ($type === 'v') {
            $ref = '';
            $rh = wp_parse_url((string)($d['r'] ?? ''), PHP_URL_HOST);
            if ($rh) { $rh = strtolower(preg_replace('/^www\./', '', $rh)); if ($rh !== preg_replace('/^www\./', '', $site)) $ref = substr($rh, 0, 100); }
            $w = (int)($d['w'] ?? 0);
            $row += ['kind' => 'view', 'cc' => self::country(substr(preg_replace('/[^A-Za-z0-9_\/+\-]/', '', (string)($d['z'] ?? '')), 0, 60), substr((string)($d['g'] ?? ''), 0, 12)), 'via' => !empty($d['a']) ? 'bot' : '', 'ref' => $ref, 'device' => $w === 0 ? '' : ($w < 600 ? 'mobil' : ($w < 1024 ? 'tablet' : 'desktop'))];
        } elseif ($type === 'h') {
            $row += ['kind' => 'human'];
        } elseif ($type === 'b') {
            $row += ['kind' => 'back', 'secs' => max(0, min(10800, (int)($d['d'] ?? 0)))];
        } elseif ($type === 't') {
            $row += ['kind' => 'time', 'secs' => max(0, min(1800, (int)($d['x'] ?? 0))), 'scroll' => max(0, min(100, (int)($d['g'] ?? 0)))];
        } else {
            $to = strtolower(preg_replace('/[^a-z0-9.\-]/i', '', (string)($d['to'] ?? '')));
            $to = preg_replace('/^www\./', '', $to);
            if ($to === '') return $none;
            $it = substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($d['i'] ?? '')), 0, 20);
            $sc = substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($d['sc'] ?? '')), 0, 12);
            if ($sc !== '' && ($to === 'amzn.to' || $to === 'a.co')) { $res = self::resolve_short($to, $sc); if ($res !== '') $it = $res; }
            $img = esc_url_raw((string)($d['m'] ?? ''), ['http', 'https']);
            $img = $img ? substr(strtok($img, '?'), 0, 500) : '';
            $row += ['kind' => 'click', 'target' => substr($to, 0, 100), 'label' => mb_substr(wp_strip_all_tags((string)($d['l'] ?? '')), 0, 80),
                'item' => $it, 'img' => $img, 'pn' => mb_substr(wp_strip_all_tags((string)($d['n'] ?? '')), 0, 100), 'via' => (($d['v'] ?? '') === 'img') ? 'img' : 'txt'];
        }
        global $wpdb;
        $wpdb->insert(self::table(), $row);
        return $none;
    }


    // ---------- Herkunftsland (ohne IP-Abfrage: aus Zeitzone und Browsersprache des Besuchers geschätzt) ----------
    static function country($tz, $lang) {
        static $map = null;
        if ($map === null) $map = [
            'Europe/Berlin'=>'DE','Europe/Busingen'=>'DE','Europe/Vienna'=>'AT','Europe/Zurich'=>'CH','Europe/Vaduz'=>'LI','Europe/Luxembourg'=>'LU','Europe/Amsterdam'=>'NL','Europe/Brussels'=>'BE','Europe/Paris'=>'FR','Europe/Madrid'=>'ES','Atlantic/Canary'=>'ES','Europe/Lisbon'=>'PT','Atlantic/Madeira'=>'PT','Atlantic/Azores'=>'PT','Europe/Rome'=>'IT','Europe/London'=>'GB','Europe/Dublin'=>'IE','Europe/Copenhagen'=>'DK','Europe/Stockholm'=>'SE','Europe/Oslo'=>'NO','Europe/Helsinki'=>'FI','Europe/Warsaw'=>'PL','Europe/Prague'=>'CZ','Europe/Bratislava'=>'SK','Europe/Budapest'=>'HU','Europe/Bucharest'=>'RO','Europe/Sofia'=>'BG','Europe/Athens'=>'GR','Europe/Zagreb'=>'HR','Europe/Ljubljana'=>'SI','Europe/Belgrade'=>'RS','Europe/Sarajevo'=>'BA','Europe/Skopje'=>'MK','Europe/Tirane'=>'AL','Europe/Vilnius'=>'LT','Europe/Riga'=>'LV','Europe/Tallinn'=>'EE','Europe/Kiev'=>'UA','Europe/Kyiv'=>'UA','Europe/Minsk'=>'BY','Europe/Chisinau'=>'MD','Europe/Moscow'=>'RU','Europe/Istanbul'=>'TR','Asia/Istanbul'=>'TR','Atlantic/Reykjavik'=>'IS','Europe/Malta'=>'MT','Asia/Nicosia'=>'CY',
            'America/New_York'=>'US','America/Chicago'=>'US','America/Denver'=>'US','America/Los_Angeles'=>'US','America/Phoenix'=>'US','America/Anchorage'=>'US','Pacific/Honolulu'=>'US','America/Detroit'=>'US','America/Indiana/Indianapolis'=>'US','America/Toronto'=>'CA','America/Vancouver'=>'CA','America/Edmonton'=>'CA','America/Winnipeg'=>'CA','America/Halifax'=>'CA','America/Montreal'=>'CA','America/Mexico_City'=>'MX','America/Sao_Paulo'=>'BR','America/Argentina/Buenos_Aires'=>'AR','America/Buenos_Aires'=>'AR','America/Santiago'=>'CL','America/Bogota'=>'CO','America/Lima'=>'PE','America/Caracas'=>'VE','America/Montevideo'=>'UY',
            'Asia/Tokyo'=>'JP','Asia/Seoul'=>'KR','Asia/Shanghai'=>'CN','Asia/Hong_Kong'=>'HK','Asia/Taipei'=>'TW','Asia/Singapore'=>'SG','Asia/Bangkok'=>'TH','Asia/Jakarta'=>'ID','Asia/Manila'=>'PH','Asia/Kuala_Lumpur'=>'MY','Asia/Ho_Chi_Minh'=>'VN','Asia/Saigon'=>'VN','Asia/Kolkata'=>'IN','Asia/Calcutta'=>'IN','Asia/Karachi'=>'PK','Asia/Dhaka'=>'BD','Asia/Dubai'=>'AE','Asia/Riyadh'=>'SA','Asia/Tehran'=>'IR','Asia/Jerusalem'=>'IL','Asia/Tel_Aviv'=>'IL','Asia/Baghdad'=>'IQ','Asia/Almaty'=>'KZ','Australia/Sydney'=>'AU','Australia/Melbourne'=>'AU','Australia/Brisbane'=>'AU','Australia/Perth'=>'AU','Australia/Adelaide'=>'AU','Pacific/Auckland'=>'NZ','Africa/Johannesburg'=>'ZA','Africa/Cairo'=>'EG','Africa/Lagos'=>'NG','Africa/Nairobi'=>'KE','Africa/Casablanca'=>'MA','Africa/Algiers'=>'DZ','Africa/Tunis'=>'TN',
        ];
        if ($tz !== '' && isset($map[$tz])) return $map[$tz];
        if (preg_match('/^[a-z]{2,3}[-_]([A-Za-z]{2})\b/', $lang, $m)) return strtoupper($m[1]);
        return '';
    }

    static function country_name($cc) {
        static $n = ['DE'=>'Deutschland','AT'=>'Österreich','CH'=>'Schweiz','LI'=>'Liechtenstein','LU'=>'Luxemburg','NL'=>'Niederlande','BE'=>'Belgien','FR'=>'Frankreich','ES'=>'Spanien','PT'=>'Portugal','IT'=>'Italien','GB'=>'Großbritannien','IE'=>'Irland','DK'=>'Dänemark','SE'=>'Schweden','NO'=>'Norwegen','FI'=>'Finnland','PL'=>'Polen','CZ'=>'Tschechien','SK'=>'Slowakei','HU'=>'Ungarn','RO'=>'Rumänien','BG'=>'Bulgarien','GR'=>'Griechenland','HR'=>'Kroatien','SI'=>'Slowenien','RS'=>'Serbien','BA'=>'Bosnien-Herzegowina','MK'=>'Nordmazedonien','AL'=>'Albanien','LT'=>'Litauen','LV'=>'Lettland','EE'=>'Estland','UA'=>'Ukraine','BY'=>'Belarus','MD'=>'Moldau','RU'=>'Russland','TR'=>'Türkei','IS'=>'Island','MT'=>'Malta','CY'=>'Zypern','US'=>'USA','CA'=>'Kanada','MX'=>'Mexiko','BR'=>'Brasilien','AR'=>'Argentinien','CL'=>'Chile','CO'=>'Kolumbien','PE'=>'Peru','VE'=>'Venezuela','UY'=>'Uruguay','JP'=>'Japan','KR'=>'Südkorea','CN'=>'China','HK'=>'Hongkong','TW'=>'Taiwan','SG'=>'Singapur','TH'=>'Thailand','ID'=>'Indonesien','PH'=>'Philippinen','MY'=>'Malaysia','VN'=>'Vietnam','IN'=>'Indien','PK'=>'Pakistan','BD'=>'Bangladesch','AE'=>'Ver. Arab. Emirate','SA'=>'Saudi-Arabien','IR'=>'Iran','IL'=>'Israel','IQ'=>'Irak','KZ'=>'Kasachstan','AU'=>'Australien','NZ'=>'Neuseeland','ZA'=>'Südafrika','EG'=>'Ägypten','NG'=>'Nigeria','KE'=>'Kenia','MA'=>'Marokko','DZ'=>'Algerien','TN'=>'Tunesien'];
        return $n[$cc] ?? $cc;
    }

    static function flag($cc) {
        if (!preg_match('/^[A-Z]{2}$/', $cc)) return '';
        $f = '';
        foreach (str_split($cc) as $ch) $f .= mb_chr(0x1F1E6 + ord($ch) - 65, 'UTF-8');
        return $f;
    }

    // ---------- Einordnung der Klickziele ----------
    static function partner($host) {
        $h = strtolower(preg_replace('/^www\./', '', $host));
        if (isset(self::allowed_sites()[$h])) return 'Eigene Seiten';
        if (preg_match('/(^|\.)(amazon\.[a-z.]+|amzn\.[a-z]+|a\.co|link\.amazon)$/', $h)) return 'Amazon';
        if (preg_match('/(^|\.)ebay\.[a-z.]+$|(^|\.)ebay\.us$/', $h)) return 'eBay';
        return 'Sonstige';
    }

    // ---------- Echtzeit ----------
    static function live() {
        if (!current_user_can('manage_options')) wp_send_json_error(null, 403);
        global $wpdb;
        $t = self::table();
        $span = (isset($_GET['min']) && (int)$_GET['min'] >= 60) ? 60 : 30;
        $since = gmdate('Y-m-d H:i:s', time() - $span * 60);
        $now = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT visitor) FROM $t WHERE kind='view' AND ts >= %s", gmdate('Y-m-d H:i:s', time() - 1800)));
        $sites = $wpdb->get_results($wpdb->prepare("SELECT site, COUNT(DISTINCT visitor) n FROM $t WHERE kind='view' AND ts >= %s GROUP BY site ORDER BY n DESC", gmdate('Y-m-d H:i:s', time() - 1800)), ARRAY_A);
        $last = $wpdb->get_results($wpdb->prepare("SELECT ts, kind, site, path, ref, device, cc, target, secs, visitor, item, via, pn, label FROM $t WHERE kind IN ('view','click','back') AND ts >= %s ORDER BY id DESC LIMIT 30", gmdate('Y-m-d H:i:s', time() - 1800)), ARRAY_A);
        $vclicks = [];
        foreach ($wpdb->get_results($wpdb->prepare("SELECT site, visitor, COUNT(*) n FROM $t WHERE kind='click' AND ts >= %s GROUP BY site, visitor", gmdate('Y-m-d H:i:s', time() - 1800))) as $x) $vclicks[$x->site . '|' . $x->visitor] = (int)$x->n;
        $multi = 0; foreach ($vclicks as $n_) if ($n_ > 1) $multi++;
        $lab = [];
        foreach (array_reverse($last) as &$r) {
            $vk = $r['site'] . '|' . $r['visitor'];
            if (!isset($lab[$vk])) { $q_ = count($lab); $lab[$vk] = $q_ < 26 ? chr(65 + $q_) : (string)($q_ + 1); }
        }
        unset($r);
        foreach ($last as &$r) {
            $vk = $r['site'] . '|' . $r['visitor'];
            $r['u'] = $lab[$vk]; $r['uc'] = $vclicks[$vk] ?? 0; unset($r['visitor']);
            $r['ago'] = max(0, time() - strtotime($r['ts'] . ' UTC'));
            $r['partner'] = $r['kind'] === 'click' ? self::partner($r['target']) : '';
            $r['href'] = '';
            if ($r['kind'] === 'click') {
                $hh = preg_match('/^[a-z0-9.\-]+$/', (string)$r['target']) ? $r['target'] : '';
                if ($r['partner'] === 'Amazon' && $r['item'] !== '') $r['href'] = 'https://www.amazon.de/dp/' . $r['item'];
                elseif ($r['partner'] === 'eBay' && $r['item'] !== '') $r['href'] = 'https://www.ebay.de/itm/' . $r['item'];
                elseif (($r['partner'] === 'Sonstige' || $r['partner'] === 'Eigene Seiten') && $hh !== '') $r['href'] = 'https://' . $hh;
                $r['how'] = $r['via'] === 'img' ? 'Bild' : 'Text';
            }
            unset($r['via']);
            $r['flag'] = self::flag($r['cc']);
        }
        unset($r);
        // Verlauf: je Minute Aufrufe, Besucher und Klicks zu Amazon/eBay (älteste links)
        $bk = array_fill(0, $span, ['v' => 0, 'c' => 0]);
        $mins = [];
        foreach ($wpdb->get_results($wpdb->prepare("SELECT ts, kind, target FROM $t WHERE kind IN ('view','click') AND ts >= %s", $since)) as $x) {
            $i = $span - 1 - (int)floor((time() - strtotime($x->ts . ' UTC')) / 60);
            if ($i < 0 || $i >= $span) continue;
            if ($x->kind === 'view') $bk[$i]['v']++;
            else { $p = self::partner($x->target); if ($p === 'Amazon' || $p === 'eBay') $bk[$i]['c']++; }
        }
        $cc = $wpdb->get_results($wpdb->prepare("SELECT cc, COUNT(DISTINCT visitor) n FROM $t WHERE kind='view' AND ts >= %s AND cc <> '' GROUP BY cc ORDER BY n DESC LIMIT 6", gmdate('Y-m-d H:i:s', time() - 1800)), ARRAY_A);
        foreach ($cc as &$c) { $c['name'] = self::country_name($c['cc']); $c['flag'] = self::flag($c['cc']); }
        unset($c);
        wp_send_json_success(['now' => $now, 'sites' => $sites, 'last' => $last, 'span' => $span, 'buckets' => $bk, 'countries' => $cc, 'cv' => count($vclicks), 'cm' => $multi]);
    }

    static function live_box() {
        $url = admin_url('admin-ajax.php?action=mini_statistik_live');
        echo '<div class="ms-card"><h2>Jetzt <small>(<a href="#" id="ms-s30" class="ms-on">30 Min.</a> · <a href="#" id="ms-s60">1 Std.</a>) &nbsp; <a href="#" id="ms-tg">ausblenden</a></small></h2><div id="ms-live"><p>Lade …</p></div></div>';
        ?>
<script>
(function(){var box=document.getElementById('ms-live'),u=<?php echo wp_json_encode($url); ?>,span=30,more=false,off=false,tg=document.getElementById('ms-tg');
try{off=localStorage.getItem('ms_live_off')==='1';}catch(e){}
function vis(){box.style.display=off?'none':'';tg.textContent=off?'einblenden':'ausblenden';}
tg.onclick=function(e){e.preventDefault();off=!off;try{localStorage.setItem('ms_live_off',off?'1':'0');}catch(x){}vis();if(!off)load();};vis();
function esc(s){var d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
function pg(r){var t=r.site+r.path;return '<a href="'+esc('https://'+t).replace(/"/g,'&quot;')+'" target="_blank" rel="noopener noreferrer">'+esc(t)+'</a>';}
function chart(b,sp){var n=b.length,mx=1,i,w=1000/n,h='';for(i=0;i<n;i++)mx=Math.max(mx,b[i].v,b[i].c);
h='<svg viewBox="0 0 1000 130" width="100%" role="img" aria-label="Aufrufe und Klicks pro Minute"><line x1="0" y1="100" x2="1000" y2="100" stroke="#dcdcde"/>';
for(i=0;i<n;i++){var vh=Math.round(90*b[i].v/mx),ch=Math.round(90*b[i].c/mx),x=i*w;
h+='<rect class="ms-bar-v" x="'+(x+w*0.12).toFixed(1)+'" y="'+(100-vh)+'" width="'+(w*0.76).toFixed(1)+'" height="'+vh+'" rx="3" fill="#2271b1" style="animation-delay:'+(i*12)+'ms"><title>vor '+(n-1-i)+' min: '+b[i].v+' Aufrufe, '+b[i].c+' Klicks</title></rect>';
if(b[i].c)h+='<circle cx="'+(x+w/2).toFixed(1)+'" cy="'+(100-ch-8)+'" r="'+Math.min(9,5+b[i].c)+'" fill="#d98200" stroke="#fff" stroke-width="2"><title>'+b[i].c+' Klick(s) zu Amazon/eBay</title></circle>';}
h+='<text x="0" y="122" font-size="13" fill="#646970">vor '+sp+' min</text><text x="1000" y="122" font-size="13" text-anchor="end" fill="#646970">jetzt</text></svg>';
h+='<p class="ms-note"><span style="color:#2271b1">▮</span> Aufrufe pro Minute &nbsp; <span style="color:#d98200">●</span> Klicks zu Amazon / eBay</p>';return h;}
function load(){if(off)return;fetch(u+'&min='+span,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
if(!j.success){box.innerHTML='<p>Keine Berechtigung.</p>';return;}var d=j.data;
var h='<div class="ms-now"><span class="ms-big">'+d.now+'</span> Besucher in den letzten 30 Min.<span class="ms-dot"></span></div>';
h+='<p class="ms-sub">'+(d.sites.map(function(s){return esc(s.site)+': <b>'+s.n+'</b>';}).join(' &nbsp;·&nbsp; ')||'Gerade niemand.')+'</p>';
h+=chart(d.buckets,d.span);
h+='<p class="ms-sub">Besucher mit Klick (30 Min.): <b>'+d.cv+'</b>'+(d.cm?', davon <b>'+d.cm+'</b> mit mehreren Klicks':'')+'</p>';
if(d.countries.length)h+='<p class="ms-sub">'+d.countries.map(function(c){return '<span class="ms-pill">'+esc(c.flag)+' '+esc(c.name)+' <b>'+c.n+'</b></span>';}).join(' ')+'</p>';
var rs=d.last.map(function(r){var m=Math.floor(r.ago/60),w=m<1?r.ago+' s':m+' min',fl=r.flag?esc(r.flag)+' ':'';
var cc=['#2271b1','#d98200','#00a32a','#8c3fb5','#d63638','#0a9396','#a0a000','#e0457b'][(String(r.u).charCodeAt(0)||0)%8],uc=' <span class="ms-note"><span style="color:'+cc+'">●</span> Besucher <b style="color:'+cc+'">'+esc(r.u)+'</b></span>';
if(r.kind==='back')return '<tr><td class="ms-w">vor '+w+'</td><td>'+pg(r)+uc+'</td><td colspan="2"><span class="ms-pill">kam zurück nach '+(r.secs<60?r.secs+' s':Math.round(r.secs/60)+' min')+'</span></td></tr>';
return r.kind==='click'?'<tr><td class="ms-w">vor '+w+'</td><td>'+pg(r)+uc+'</td><td colspan="2"><span class="ms-pill ms-p-'+esc(r.partner)+'">Klick ('+esc(r.how)+') → '+(r.href?'<a href="'+esc(r.href).replace(/"/g,'&quot;')+'" target="_blank" rel="noopener noreferrer">'+esc(r.partner)+(r.pn?': '+esc(r.pn.slice(0,50)):(r.item?' '+esc(r.item):''))+'</a>':esc(r.partner)+(r.pn?': '+esc(r.pn.slice(0,50)):(r.label?' · '+esc(r.label):'')))+'</span>'+(r.uc>1?' <b style="color:'+cc+'">'+r.uc+' Klicks von Besucher '+esc(r.u)+'</b>':'')+'</td></tr>'
:'<tr><td class="ms-w">vor '+w+'</td><td>'+pg(r)+uc+'</td><td>'+fl+esc(r.ref||'direkt')+'</td><td>'+esc(r.device)+'</td></tr>';});h+='<table class="ms-tbl"><tbody>'+rs.slice(0,more?rs.length:3).join('')+'</tbody></table>';if(rs.length>3)h+='<p><a href="#" id="ms-more">'+(more?'weniger anzeigen':(rs.length-3)+' weitere anzeigen')+'</a></p>';
box.innerHTML=h;var mo=document.getElementById('ms-more');if(mo)mo.onclick=function(e){e.preventDefault();more=!more;load();};}).catch(function(){});}
function sw(n,e){e.preventDefault();span=n;document.getElementById('ms-s30').className=n===30?'ms-on':'';document.getElementById('ms-s60').className=n===60?'ms-on':'';load();}
document.getElementById('ms-s30').onclick=function(e){sw(30,e);};document.getElementById('ms-s60').onclick=function(e){sw(60,e);};
load();setInterval(load,10000);})();
</script>
        <?php
    }


    // ---------- Besucherfluss ----------
    static function flow_report($w, $multi) {
        global $wpdb;
        $t = self::table();
        $rows = $wpdb->get_results("SELECT site, visitor, path, ref, ts FROM $t WHERE kind='view' AND $w ORDER BY site, visitor, ts, id LIMIT 60000");
        $lab = function ($site, $path) use ($multi) { return ($multi ? $site : '') . $path; };
        $visits = []; $cur = null; $prev_ts = 0;
        foreach ($rows as $r) {
            $ts = strtotime($r->ts . ' UTC');
            if ($cur === null || $cur['k'] !== $r->site . '|' . $r->visitor || $ts - $prev_ts > 1800) {
                if ($cur) $visits[] = $cur;
                $cur = ['k' => $r->site . '|' . $r->visitor, 'ref' => $r->ref, 'p' => [], 'lp' => '', 'lt' => 0];
            }
            $l = $lab($r->site, $r->path);
            if (!$cur['p'] || end($cur['p']) !== $l) $cur['p'][] = $l; // Neuladen zählt nicht als neuer Schritt
            $cur['lp'] = $r->path; $cur['lt'] = $ts; // letzter Aufruf (roher Pfad) für die Ausstiegsanalyse
            $prev_ts = $ts;
        }
        if ($cur) $visits[] = $cur;
        $n = count($visits);
        if (!$n) { ob_start(); self::bars('Besucherfluss', []); return ['kpi' => ob_get_clean(), 'exit' => '', 'grid' => '', 'warn' => '']; }
        // Zeit/Scrolltiefe je Besucher und Seite sowie Klicks für die Ausstiegsanalyse
        $tmap = [];
        foreach ($wpdb->get_results("SELECT site, visitor, path, SUM(secs) s, MAX(scroll) g FROM $t WHERE kind='time' AND $w GROUP BY site, visitor, path LIMIT 60000") as $r) $tmap[$r->site . '|' . $r->visitor . '|' . $r->path] = [(int)$r->s, (int)$r->g];
        $cmap = [];
        foreach ($wpdb->get_results("SELECT site, visitor, path, target, ts FROM $t WHERE kind='click' AND $w ORDER BY ts LIMIT 60000") as $r) $cmap[$r->site . '|' . $r->visitor][] = [strtotime($r->ts . ' UTC'), $r->path, self::partner($r->target)];
        $pagecount = []; $ex = [];
        $entry = []; $exit = []; $trans = []; $paths = []; $src = []; $single = 0; $steps = 0;
        foreach ($visits as $v) {
            $p = $v['p']; $c = count($p); $steps += $c;
            foreach ($p as $pl) $pagecount[$pl] = ($pagecount[$pl] ?? 0) + 1;
            $last = $p[$c - 1];
            if (!isset($ex[$last])) $ex[$last] = ['n' => 0, 'shop' => 0, 'ext' => 0, 'secs' => 0, 'ns' => 0, 'scr' => 0, 'nsc' => 0, 'single' => 0];
            $e =& $ex[$last]; $e['n']++;
            if ($c === 1) $e['single']++;
            $tk = $v['k'] . '|' . $v['lp'];
            if (isset($tmap[$tk])) { $e['secs'] += $tmap[$tk][0]; $e['ns']++; $e['scr'] += $tmap[$tk][1]; $e['nsc']++; }
            foreach ($cmap[$v['k']] ?? [] as $ck) {
                if ($ck[1] === $v['lp'] && $ck[0] >= $v['lt'] - 5 && $ck[0] <= $v['lt'] + 1800 && $ck[2] !== 'Eigene Seiten') { if ($ck[2] === 'Sonstige') $e['ext']++; else $e['shop']++; break; }
            }
            unset($e);
            if ($c === 1) $single++;
            $entry[$p[0]] = ($entry[$p[0]] ?? 0) + 1;
            $exit[$p[$c - 1]] = ($exit[$p[$c - 1]] ?? 0) + 1;
            $q = $v['ref'] !== '' ? $v['ref'] : 'direkt / unbekannt';
            $k = $q . '  →  ' . $p[0]; $src[$k] = ($src[$k] ?? 0) + 1;
            for ($i = 0; $i < $c - 1; $i++) { $k = $p[$i] . '  →  ' . $p[$i + 1]; $trans[$k] = ($trans[$k] ?? 0) + 1; }
            if ($c >= 3) { $k = $p[0] . '  →  ' . $p[1] . '  →  ' . $p[2]; $paths[$k] = ($paths[$k] ?? 0) + 1; }
        }
        $top = function ($a, $k = 10) { arsort($a); $o = []; foreach (array_slice($a, 0, $k, true) as $l => $v) $o[] = [$l, $v]; return $o; };
        ob_start();
        echo '<h2 style="margin-top:28px">Besucherfluss</h2><div class="ms-kpis">';
        foreach ([
            [number_format_i18n($n), 'Besuche (Pause über 30 Min = neuer Besuch)'],
            [number_format_i18n(round($steps / $n, 1), 1), 'Ø Seiten pro Besuch'],
            [round(100 * $single / $n) . ' %', 'Besuche mit nur einer Seite'],
            [round(100 * ($n - $single) / $n) . ' %', 'Besucher, die weiterklicken'],
        ] as $k) echo '<div class="ms-kpi"><b>' . esc_html($k[0]) . '</b><span>' . esc_html($k[1]) . '</span></div>';
        echo '</div>'; $kpi_html = ob_get_clean();
        ob_start(); echo '<div class="ms-grid2">';
        self::bars('Häufigste Übergänge (von → nach)', $top($trans, 15), '', true);
        self::bars('Typische Wege (3 Seiten)', $top($paths, 10), '', true);
        self::bars('Woher → auf welcher Seite gelandet', $top($src, 12), '', true);
        self::bars('Einstiegsseiten', $top($entry, 10), '', true);
        self::bars('Ausstiegsseiten', $top($exit, 10), '', true);
        echo '</div>'; $grid_html = ob_get_clean();

        // Ausstiege
        ob_start();
        $exits_total = array_sum(array_column($ex, 'n'));
        $avg_rate = $steps ? $exits_total / $steps : 0;
        uasort($ex, fn($a, $b) => $b['n'] <=> $a['n']);
        echo '<div class="ms-card"><h2>Wo verlassen Besucher die Seite?</h2><table class="ms-tbl"><thead><tr><th>Seite</th><th class="num">Aufrufe</th><th class="num">Ausstiege</th><th class="num">Ausstiegsrate</th><th class="num">davon zu Amazon / eBay</th><th class="num">davon zu anderen externen Seiten</th><th class="num">Ø Zeit auf der Seite</th><th class="num">Ø Scrolltiefe</th><th></th></tr></thead><tbody>';
        foreach (array_slice($ex, 0, 20, true) as $pg => $e) {
            $views_p = $pagecount[$pg] ?? 0; $rate = $views_p ? $e['n'] / $views_p : 0;
            $hint = ($views_p >= 20 && $rate > 1.25 * $avg_rate) ? '<span class="ms-pill ms-p-eBay">auffällig hoch</span>' : '';
            echo '<tr><td>' . esc_html($pg) . '</td><td class="num">' . (int)$views_p . '</td><td class="num"><b>' . (int)$e['n'] . '</b></td><td class="num">' . round(100 * $rate) . ' %</td><td class="num">' . (int)$e['shop'] . '</td><td class="num">' . (int)$e['ext'] . '</td><td class="num">' . ($e['ns'] ? self::fmt_time($e['secs'] / $e['ns']) : '–') . '</td><td class="num">' . ($e['nsc'] ? round($e['scr'] / $e['nsc']) . ' %' : '–') . '</td><td>' . $hint . '</td></tr>';
        }
        echo '</tbody></table><p class="ms-note">Ausstieg = letzte Seite eines Besuchs (30 Minuten ohne weiteren Aufruf, oder Klick auf einen externen Link). Die Ausstiegsrate ist der Anteil der Aufrufe einer Seite, nach denen der Besuch dort endet; im Durchschnitt aller Seiten ' . round(100 * $avg_rate) . ' %. „Auffällig hoch" = mehr als das 1,25-Fache des Durchschnitts bei mindestens 20 Aufrufen. Die Zeit und die Scrolltiefe beziehen sich auf die Besuche, die auf dieser Seite enden: Wer früh aufhört zu scrollen, springt eher inhaltlich ab, wer weit gescrollt hat und zu Amazon/eBay geht, hat die Seite erfolgreich genutzt.</p></div>';
        $exit_html = ob_get_clean();
        $warn = count($rows) >= 60000 ? '<p class="ms-note">Hinweis: Es wurden nur die ersten 60.000 Aufrufe ausgewertet. Wähle einen kürzeren Zeitraum.</p>' : '';
        return ['kpi' => $kpi_html, 'exit' => $exit_html, 'grid' => $grid_html, 'warn' => $warn];
    }


    // ---------- Import früherer Daten (Google-Analytics-CSV, nur Tageszahlen) ----------
    static function ga_num($v) { return (int)preg_replace('/\D/', '', (string)$v); }

    static function ga_date($v) {
        $v = trim((string)$v);
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/', $v, $m)) return "$m[1]-$m[2]-$m[3]";
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $v, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        return '';
    }

    static function ga_secs($v) {
        $v = trim((string)$v);
        if (preg_match('/^(\d+):(\d{2}):(\d{2})$/', $v, $m)) return $m[1] * 3600 + $m[2] * 60 + $m[3];
        if (preg_match('/^(\d+):(\d{2})$/', $v, $m)) return $m[1] * 60 + $m[2];
        return (int)round((float)str_replace(',', '.', $v));
    }

    static function ga_import($file, $site) {
        global $wpdb;
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return 'Die Datei ist leer oder nicht lesbar.';
        $rows = [];
        foreach ($lines as $l) {
            $l = preg_replace('/^\xEF\xBB\xBF/', '', $l);
            if ($l === '' || $l[0] === '#') continue;
            $rows[] = $l;
        }
        if (count($rows) < 2) return 'Keine Datenzeilen gefunden.';
        $delim = ','; $best = 0;
        foreach ([',', ';', "\t"] as $d) { $c = substr_count($rows[0], $d); if ($c > $best) { $best = $c; $delim = $d; } }
        $head = array_map(fn($h) => mb_strtolower(trim($h)), str_getcsv($rows[0], $delim));
        $col = ['date' => -1, 'path' => -1, 'views' => -1, 'users' => -1, 'secs' => -1];
        foreach ($head as $i => $h) {
            if ($col['date'] < 0 && preg_match('/^(datum|date|tag|day)/u', $h)) $col['date'] = $i;
            elseif ($col['path'] < 0 && preg_match('/(seitenpfad|page path|pagepath|seitenpfad und)/u', $h)) $col['path'] = $i;
            elseif ($col['views'] < 0 && preg_match('/^(aufrufe|views|seitenaufrufe|screen)/u', $h)) $col['views'] = $i;
            elseif ($col['secs'] < 0 && preg_match('/(interaktionsdauer|engagement time|durchschnittliche)/u', $h)) $col['secs'] = $i;
            elseif ($col['users'] < 0 && preg_match('/(nutzer|users|besucher)/u', $h)) $col['users'] = $i;
        }
        if ($col['date'] < 0 || $col['views'] < 0) return 'Spalten nicht erkannt. Es werden eine Spalte "Datum" und eine Spalte "Aufrufe" (englisch: Date, Views) gebraucht. Gefunden: ' . implode(', ', $head);
        $data = []; $min = '9999-99-99'; $max = '0000-00-00';
        foreach (array_slice($rows, 1) as $l) {
            $c = str_getcsv($l, $delim);
            $day = self::ga_date($c[$col['date']] ?? '');
            if ($day === '') continue;
            $path = $col['path'] >= 0 ? substr(wp_parse_url('http://x' . trim((string)($c[$col['path']] ?? '')), PHP_URL_PATH) ?: '/', 0, 255) : '';
            $us = $col['users'] >= 0 ? self::ga_num($c[$col['users']] ?? 0) : 0;
            $sc = $col['secs'] >= 0 ? self::ga_secs($c[$col['secs']] ?? 0) * max(1, $us) : 0;
            $data[] = [$day, $path, self::ga_num($c[$col['views']] ?? 0), $us, $sc];
            if ($day < $min) $min = $day; if ($day > $max) $max = $day;
        }
        if (!$data) return 'Keine gültigen Zeilen (Datum nicht lesbar).';
        $g = self::table_ga();
        $wpdb->query($wpdb->prepare("DELETE FROM $g WHERE site = %s AND day BETWEEN %s AND %s", $site, $min, $max));
        foreach (array_chunk($data, 200) as $chunk) {
            $vals = []; $args = [];
            foreach ($chunk as $d) { $vals[] = '(%s,%s,%s,%d,%d,%d)'; array_push($args, $d[0], $site, $d[1], $d[2], $d[3], $d[4]); }
            $wpdb->query($wpdb->prepare("INSERT INTO $g (day, site, path, views, users, secs) VALUES " . implode(',', $vals), $args));
        }
        return count($data) . ' Zeilen importiert (' . $min . ' bis ' . $max . '). Frühere Importe desselben Zeitraums für diese Seite wurden ersetzt.';
    }

    static function ga_report($sel) {
        global $wpdb;
        $g = self::table_ga();
        $where = $sel ? $wpdb->prepare('WHERE site = %s', $sel) : '';
        if (!(int)$wpdb->get_var("SELECT COUNT(*) FROM $g $where")) return;
        $per = $wpdb->get_results("SELECT SUBSTRING(day, 1, 7) m, SUM(views) v, SUM(users) u FROM $g $where GROUP BY SUBSTRING(day, 1, 7) ORDER BY m");
        $rng = $wpdb->get_row("SELECT MIN(day) a, MAX(day) b FROM $g $where");
        echo '<div class="ms-card"><h2>Früherer Zeitraum <small>(aus Google Analytics importiert, ' . esc_html($rng->a . ' bis ' . $rng->b) . ')</small></h2>';
        $mx = 1; foreach ($per as $p) $mx = max($mx, (int)$p->v);
        foreach ($per as $p) echo '<div class="ms-row"><span class="n">' . esc_html($p->m) . '</span><div class="ms-bar"><i style="width:' . round(100 * $p->v / $mx) . '%"></i></div><span class="v">' . number_format_i18n((int)$p->v) . '</span></div>';
        $w2 = $sel ? $wpdb->prepare("WHERE site = %s AND path <> ''", $sel) : "WHERE path <> ''";
        $top = $wpdb->get_results("SELECT path, SUM(views) v FROM $g $w2 GROUP BY path ORDER BY v DESC LIMIT 15");
        if ($top) {
            echo '<table class="ms-tbl" style="margin-top:12px"><thead><tr><th>Seite</th><th class="num">Aufrufe</th></tr></thead><tbody>';
            foreach ($top as $r) echo '<tr><td>' . esc_html($r->path) . '</td><td class="num">' . number_format_i18n((int)$r->v) . '</td></tr>';
            echo '</tbody></table>';
        }
        echo '<p class="ms-note">Balken = Seitenaufrufe pro Monat laut Google Analytics. Diese Zahlen sind nur Tagessummen aus dem Export und werden nicht mit den eigenen Daten vermischt; Google zählt anders (Cookies, Sitzungen, ohne Bot-Filter dieses Plugins), die Werte sind deshalb nur grob vergleichbar.</p></div>';
    }

    // ---------- Einstellungen + Übersicht ----------
    static function menu() {
        if (self::opts()['mode'] !== 'hub') { // kleine Seiten: unauffällig unter Werkzeuge, nur die Zentrale bekommt den Hauptpunkt
            add_management_page('1AB-Test-Statistik', '1AB-Test-Statistik', 'manage_options', 'mini-statistik', [__CLASS__, 'page']);
            return;
        }
        add_menu_page('1AB-Test-Statistik', '1AB-Test-Statistik', 'manage_options', 'mini-statistik', [__CLASS__, 'page'], 'dashicons-chart-bar', '58.5'); // direkt unter 1AB Affiliate Help (Position 58)
        add_submenu_page('mini-statistik', 'Auswertung', 'Auswertung', 'manage_options', 'mini-statistik', [__CLASS__, 'page']);
        add_submenu_page('mini-statistik', 'Einstellungen', 'Einstellungen', 'manage_options', 'mini-statistik-einstellungen', function () { $_GET['ms_einst'] = '1'; self::page(); });
    }

    static function page() {
        if (!current_user_can('manage_options')) return;
        $um = '';
        if (isset($_POST['ms_save']) && check_admin_referer('ms_save')) {
            update_option(self::OPT, [
                'mode' => in_array($_POST['mode'] ?? '', ['off', 'hub', 'client'], true) ? $_POST['mode'] : 'off',
                'hub_url' => esc_url_raw(wp_unslash($_POST['hub_url'] ?? '')),
                'site_key' => sanitize_text_field(wp_unslash($_POST['site_key'] ?? '')),
                'sites' => sanitize_textarea_field(wp_unslash($_POST['sites'] ?? '')),
                'keep_days' => max(0, min(3650, (int)($_POST['keep_days'] ?? 0))),
                'salt_days' => max(1, min(30, (int)($_POST['salt_days'] ?? 7))),
            ]);
            echo '<div class="updated"><p>Gespeichert.</p></div>';
        }
        if (isset($_POST['ms_import']) && check_admin_referer('ms_import')) {
            $isite = sanitize_text_field(wp_unslash($_POST['import_site'] ?? ''));
            if (!isset(self::allowed_sites()[$isite])) echo '<div class="error"><p>Bitte eine erlaubte Seite wählen.</p></div>';
            elseif (empty($_FILES['import_file']['tmp_name']) || !is_uploaded_file($_FILES['import_file']['tmp_name'])) echo '<div class="error"><p>Keine Datei gewählt.</p></div>';
            else echo '<div class="updated"><p>' . esc_html(self::ga_import($_FILES['import_file']['tmp_name'], $isite)) . '</p></div>';
        }
        if (isset($_POST['ms_hide']) && check_admin_referer('ms_hide')) {
            $hid = (array)get_option('mini_statistik_hidden', []);
            $hk = sanitize_text_field(wp_unslash($_POST['pk'] ?? ''));
            if ($_POST['ms_hide'] === 'show') $hid = [];
            elseif ($hk !== '') $hid[] = $hk;
            update_option('mini_statistik_hidden', array_values(array_unique($hid)), false);
        }
        $o = self::opts();
        self::styles();
        echo '<div class="wrap ms"><h1>1AB-Test-Statistik</h1>' . $um;
        if ($o['mode'] === 'hub') { self::live_box(); self::report(); }
        echo '<details class="ms-card"' . ($o['mode'] === 'hub' && empty($_GET['ms_einst']) ? '' : ' open') . '><summary><b>Einstellungen</b></summary><form method="post">';
        wp_nonce_field('ms_save');
        echo '<table class="form-table">';
        echo '<tr><th>Modus</th><td><select name="mode">';
        foreach (['off' => 'Aus', 'hub' => 'Zentrale (sammelt und zeigt alle Seiten)', 'client' => 'Sendet an die Zentrale'] as $k => $l)
            echo '<option value="' . esc_attr($k) . '"' . selected($o['mode'], $k, false) . '>' . esc_html($l) . '</option>';
        echo '</select></td></tr>';
        echo '<tr><th>Zentrale-Adresse (nur Sender)</th><td><input class="regular-text" name="hub_url" value="' . esc_attr($o['hub_url']) . '" placeholder="https://zentrale.de"></td></tr>';
        echo '<tr><th>Kennwort dieser Seite (nur Sender; die Zentrale nimmt es aus der Liste)</th><td><input class="regular-text" name="site_key" value="' . esc_attr($o['site_key']) . '"></td></tr>';
        echo '<tr><th>Erlaubte Seiten (nur Zentrale)</th><td><textarea name="sites" rows="9" cols="60" placeholder="domain.de|kennwort">' . esc_textarea($o['sites']) . '</textarea><p class="description">Eine Zeile je Seite: <code>domain.de|kennwort</code>. Auch die Zentrale selbst eintragen.</p></td></tr>';
        echo '<tr><th>Eigene Besuche</th><td>Angemeldete Benutzer werden nie gezählt. Wenn du auf einer Seite angemeldet die Startseite ansiehst, merkt sich dieser Browser das (kleiner Eintrag nur auf deinem Gerät) und zählt dich auch ohne Anmeldung nicht mehr. Einmal pro Seite und Browser nötig; im privaten Fenster zählst du weiter.</td></tr>';
        echo '<tr><th>Aufbewahrung (nur Zentrale)</th><td><input type="number" min="0" max="3650" name="keep_days" value="' . (int)$o['keep_days'] . '"> Tage<p class="description">0 = unbegrenzt aufbewahren. Sonst werden Einzeldaten nach so vielen Tagen gelöscht.</p></td></tr>';
        echo '<tr><th>Wiedererkennung (nur Zentrale)</th><td><input type="number" min="1" max="30" name="salt_days" value="' . (int)$o['salt_days'] . '"> Tage<p class="description">So lange wird derselbe Besucher über mehrere Tage erkannt (Wiederkehrer). Danach wird der Zufallswert überschrieben, alte Kennungen lassen sich nicht mehr zuordnen. 1 = nur innerhalb eines Tages.</p></td></tr>';
        echo '</table><p><button class="button button-primary" name="ms_save" value="1">Speichern</button></p></form>';
        if ($o['mode'] === 'hub') {
            echo '<hr><h3>Früheren Zeitraum aus Google Analytics importieren</h3><form method="post" enctype="multipart/form-data">';
            wp_nonce_field('ms_import');
            echo '<p>Seite: <select name="import_site">';
            foreach (array_keys(self::allowed_sites()) as $d) echo '<option>' . esc_html($d) . '</option>';
            echo '</select> &nbsp; CSV-Datei: <input type="file" name="import_file" accept=".csv,text/csv,text/plain"></p><p class="description">GA4: Berichte, Seiten und Bildschirme (oder Erkunden mit Datum und Seitenpfad), Zeitraum wählen, Export als CSV. Gebraucht werden Datum und Aufrufe, optional Seitenpfad, Nutzer und durchschnittliche Interaktionsdauer. Ein Import desselben Zeitraums ersetzt den früheren.</p><p><button class="button" name="ms_import" value="1">Importieren</button></p></form>';
        }
        echo '</details></div>';
    }

    static function styles() {
        echo '<style>
.ms{max-width:1100px}.ms-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px 20px;margin:16px 0}
.ms-card h2{margin:0 0 10px;font-size:16px}.ms-card h2 small{font-weight:400;color:#646970}
.ms-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,150px),1fr));gap:12px;margin:16px 0}
.ms-kpi{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 16px}.ms-kpi b{display:block;font-size:26px;line-height:1.2}.ms-kpi span{color:#646970;font-size:12px}
.ms-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:16px}
.ms-row{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,2fr) 48px;gap:8px;align-items:center;margin:5px 0;font-size:13px}
.ms-grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,480px),1fr));gap:16px}.ms-wide .ms-row .n{white-space:normal;overflow:visible;line-height:1.3}.ms-wide .ms-row{grid-template-columns:minmax(0,3fr) minmax(0,1.2fr) 48px}.ms-row .n{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ms-row .v{text-align:right;font-variant-numeric:tabular-nums}
.ms-bar{height:10px;background:#f0f0f1;border-radius:5px;overflow:hidden}.ms-bar i{display:block;height:100%;background:#2271b1;border-radius:5px}
.ms-bar.amz i{background:#d98200}.ms-bar.ebay i{background:#c0392b}.ms-bar.oth i{background:#787c82}.ms-bar.own i{background:#00a32a}
.ms-tbl{width:100%;border-collapse:collapse;font-size:13px}.ms-tbl td,.ms-tbl th{padding:6px 8px;border-bottom:1px solid #f0f0f1;text-align:left}.ms-tbl th{color:#646970;font-weight:600}
.ms-tbl td.num,.ms-tbl th.num{text-align:right;font-variant-numeric:tabular-nums}.ms-w{width:80px;color:#646970}
.ms-big{font-size:34px;font-weight:600}.ms-now{display:flex;align-items:baseline;gap:8px}.ms-sub{color:#50575e;margin:4px 0 10px}
.ms-dot{width:9px;height:9px;border-radius:50%;background:#00a32a;display:inline-block;animation:msp 1.6s infinite}@keyframes msp{50%{opacity:.25}}
.ms-pill{display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;background:#e5e5e5}.ms-p-Amazon{background:#fcefd6;color:#8a5600}.ms-p-eBay{background:#fbe1de;color:#a02a1e}.ms-p-Eigene\ Seiten{background:#d7f0dd;color:#14631f}
.ms-card h2 small a{text-decoration:none;color:#646970}.ms-card h2 small a.ms-on{color:#2271b1;font-weight:600}.ms-d{display:block;font-style:normal;font-size:12px;font-weight:600;margin-top:2px}.ms-bar-v{transform-box:fill-box;transform-origin:bottom;animation:msg .5s ease-out both}@keyframes msg{from{transform:scaleY(0)}}
.ms-nav a{margin-right:12px}.ms-note{color:#646970;font-size:12px}
.ms-card{overflow-x:auto;box-sizing:border-box;max-width:100%}.ms-tbl td{word-break:break-word}.ms .regular-text,.ms input[type=text],.ms input[type=url],.ms textarea{max-width:100%;box-sizing:border-box}
@media(max-width:700px){.ms-card{padding:12px}.ms-kpis{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.ms-kpi{padding:10px 12px}.ms-kpi b{font-size:21px}.ms-tbl{min-width:560px;font-size:12px}.ms-tbl td,.ms-tbl th{padding:5px 6px}.ms-row{grid-template-columns:minmax(0,1.2fr) minmax(0,1.4fr) 40px}.ms-wide .ms-row{grid-template-columns:minmax(0,2fr) minmax(0,1fr) 40px}.ms-big{font-size:28px}.ms .form-table,.ms .form-table tbody,.ms .form-table tr,.ms .form-table th,.ms .form-table td{display:block;width:auto;padding:6px 0}}
</style>';
    }

    static function bars($title, $rows, $class = '', $wide = false) {
        // $rows: [[label, value], ...]
        $max = 1; foreach ($rows as $r) $max = max($max, (int)$r[1]);
        echo '<div class="ms-card' . ($wide ? ' ms-wide' : '') . '"><h2>' . esc_html($title) . '</h2>';
        if (!$rows) echo '<p class="ms-note">Noch keine Daten.</p>';
        foreach ($rows as $r) {
            $cls = $class;
            if (is_array($class)) $cls = $class[$r[0]] ?? '';
            echo '<div class="ms-row"><span class="n" title="' . esc_attr($r[0]) . '">' . esc_html($r[0]) . '</span><div class="ms-bar ' . esc_attr($cls) . '"><i style="width:' . round(100 * (int)$r[1] / $max) . '%"></i></div><span class="v">' . (int)$r[1] . '</span></div>';
        }
        echo '</div>';
    }

    // Klicks nach Seite (Artikel) und nach Website
    static function clicks_by_page($w, $multi) {
        global $wpdb;
        $t = self::table();
        $rows = $wpdb->get_results("SELECT site, path, target, item, pn, label, COUNT(*) n FROM $t WHERE kind='click' AND $w GROUP BY site, path, target, item, pn, label LIMIT 8000");
        $views = []; foreach ($wpdb->get_results("SELECT site, path, COUNT(*) v FROM $t WHERE kind='view' AND $w GROUP BY site, path") as $r) $views[$r->site . $r->path] = (int)$r->v;
        $pg = []; $si = [];
        foreach ($rows as $r) {
            $k = $r->site . $r->path; $pp = self::partner($r->target); $n = (int)$r->n;
            $pg[$k] ??= ['n' => 0, 'Amazon' => 0, 'eBay' => 0, 'Sonstige' => 0, 'Eigene Seiten' => 0, 'prod' => [], 'site' => $r->site];
            $pg[$k]['n'] += $n; $pg[$k][$pp] += $n;
            $nm = $r->pn !== '' ? $r->pn : ($r->label !== '' ? $r->label : ($r->item !== '' ? $r->item : $r->target));
            $pg[$k]['prod'][$nm] = ($pg[$k]['prod'][$nm] ?? 0) + $n;
            $si[$r->site] ??= ['n' => 0, 'Amazon' => 0, 'eBay' => 0, 'Sonstige' => 0, 'Eigene Seiten' => 0]; $si[$r->site]['n'] += $n; $si[$r->site][$pp] += $n;
        }
        uasort($pg, fn($a, $b) => $b['n'] <=> $a['n']); uasort($si, fn($a, $b) => $b['n'] <=> $a['n']);
        $sv = []; foreach ($views as $k => $v) { $site = strstr($k, '/', true); if ($site !== false) $sv[$site] = ($sv[$site] ?? 0) + $v; }
        $out = '';
        if ($multi) {
            $out .= '<div class="ms-card"><h2>Klicks nach Website</h2><table class="ms-tbl"><thead><tr><th>Website</th><th class="num">Klicks</th><th class="num">Amazon</th><th class="num">eBay</th><th class="num">Sonstige</th><th class="num">Aufrufe</th><th class="num">Klickrate</th></tr></thead><tbody>';
            foreach ($si as $site => $x) { $v = $sv[$site] ?? 0; $out .= '<tr><td>' . esc_html($site) . '</td><td class="num"><b>' . $x['n'] . '</b></td><td class="num">' . $x['Amazon'] . '</td><td class="num">' . $x['eBay'] . '</td><td class="num">' . ($x['Sonstige'] + $x['Eigene Seiten']) . '</td><td class="num">' . $v . '</td><td class="num">' . ($v ? round(100 * $x['n'] / $v) . ' %' : '–') . '</td></tr>'; }
            if (!$si) $out .= '<tr><td colspan="7">Noch keine Klicks.</td></tr>';
            $out .= '</tbody></table></div>';
        }
        $out .= '<div class="ms-card"><h2>Klicks nach Seite (Artikel)</h2><div class="ms-scroll"><table class="ms-tbl"><thead><tr><th>Seite</th><th class="num">Klicks</th><th class="num">Amazon</th><th class="num">eBay</th><th class="num">Sonstige</th><th class="num">Aufrufe</th><th class="num">Klickrate</th><th>Meistgeklickt auf dieser Seite</th></tr></thead><tbody>';
        foreach (array_slice($pg, 0, 100, true) as $k => $x) {
            $v = $views[$k] ?? 0; arsort($x['prod']);
            $top = []; foreach (array_slice($x['prod'], 0, 3, true) as $nm => $c) $top[] = esc_html(mb_substr((string)$nm, 0, 50)) . ' (' . (int)$c . ')';
            $out .= '<tr><td><a href="' . esc_url('https://' . $k) . '" target="_blank" rel="noopener noreferrer">' . esc_html($k) . '</a></td><td class="num"><b>' . $x['n'] . '</b></td><td class="num">' . $x['Amazon'] . '</td><td class="num">' . $x['eBay'] . '</td><td class="num">' . ($x['Sonstige'] + $x['Eigene Seiten']) . '</td><td class="num">' . $v . '</td><td class="num">' . ($v ? round(100 * $x['n'] / $v) . ' %' : '–') . '</td><td class="ms-note">' . implode('<br>', $top) . '</td></tr>';
        }
        if (!$pg) $out .= '<tr><td colspan="8">Noch keine Klicks.</td></tr>';
        return $out . '</tbody></table></div><p class="ms-note">Die 100 Seiten mit den meisten Klicks im gewählten Zeitraum. Spaltenköpfe anklicken zum Sortieren.</p></div>';
    }

    // Roboter in der Übersicht: wer sich zu erkennen gibt, wie viele sich verstecken, Geräte
    static function robot_overview($w, $meas, $bots, $quiet, $rdev) {
        global $wpdb;
        $t = self::table();
        $named = []; $tot = 0;
        foreach ($wpdb->get_results("SELECT label, COUNT(*) n FROM $t WHERE kind='robot' AND $w GROUP BY label ORDER BY n DESC LIMIT 30") as $r) { $named[$r->label] = (int)$r->n; $tot += (int)$r->n; }
        if (!$named && $meas < 20) return '';
        ob_start();
        echo '<div class="ms-grid">';
        $rows = []; foreach ($named as $nm => $n) $rows[] = [$nm . ' (' . round(100 * $n / max(1, $tot)) . ' %)', $n];
        self::bars('Roboter, die sich zu erkennen geben', $rows);
        if ($meas >= 20) {
            $hid = $bots + $quiet;
            self::bars('Roboter, die sich verstecken (Schätzung)', [
                ['als automatisiert gemeldet (' . round(100 * $bots / max(1, $hid)) . ' %)', $bots],
                ['ganz ohne Maus, Touch oder Scrollen (' . round(100 * $quiet / max(1, $hid)) . ' %)', $quiet],
            ]);
            $dr = []; foreach (['desktop' => 'Desktop', 'mobil' => 'Mobil', 'tablet' => 'Tablet', 'unbekannt' => 'Unbekannt'] as $k => $l) if (!empty($rdev[$k])) $dr[] = [$l . ' (' . round(100 * $rdev[$k] / max(1, $hid)) . ' %)', $rdev[$k]];
            self::bars('Gerät der versteckten Roboter', $dr);
        }
        echo '</div><p class="ms-note">Zahlen links: Zugriffe von Robotern, die sich selbst benennen (Prozent = Anteil an allen benannten Robotern). Rechts: Besucher, die wie Roboter wirken. „Ohne Maus, Touch oder Scrollen" fasst die Signale zusammen, einzeln lassen sie sich nicht trennen. Alles sind Schätzungen und stehen nicht in den Besucherzahlen. <a href="#" class="ms-gotab button button-small" data-t="r">Alle Roboter mit Details ansehen</a></p><script>(function(){document.querySelectorAll(".ms-gotab").forEach(function(l){l.onclick=function(e){e.preventDefault();var t=document.querySelector(".ms-tabs a[data-t=\""+l.dataset.t+"\"]");if(t){t.click();window.scrollTo(0,0);}};});})();</script>';
        return ob_get_clean();
    }

    // Roboter-Statistik (aus User-Agent und Server-Zugriffen; keine IP). Wege und Abstände werden aus der Reihenfolge der Zugriffe geschätzt.
    static function robot_report($w) {
        global $wpdb;
        $t = self::table();
        $rows = $wpdb->get_results("SELECT ts, label, target, site, path, pn, via FROM $t WHERE kind='robot' AND $w ORDER BY label, site, ts, id LIMIT 20000");
        $typ = []; $bot = []; $total = 0;
        foreach ($rows as $r) {
            $total++; $typ[$r->target] = ($typ[$r->target] ?? 0) + 1;
            $b = &$bot[$r->label];
            if (!isset($b)) $b = ['n' => 0, 'typ' => $r->target, 'pages' => [], 'ua' => $r->pn, 'js' => 0, 'trans' => [], 'gaps' => [], 'days' => [], 'first' => $r->ts, 'last' => $r->ts, 'prev' => null];
            $b['n']++; $b['last'] = $r->ts; $k = $r->site . $r->path;
            $b['pages'][$k] = ($b['pages'][$k] ?? 0) + 1;
            if ($r->via === 'js') $b['js']++;
            $day = substr($r->ts, 0, 10); $b['days'][$day] = ($b['days'][$day] ?? 0) + 1;
            if ($b['prev'] && $b['prev'][0] === $r->site) { // Folgezugriff derselben Roboterart auf derselben Website innerhalb von 10 Minuten = gleicher Lauf
                $gap = strtotime($r->ts . ' UTC') - strtotime($b['prev'][2] . ' UTC');
                if ($gap >= 0 && $gap <= 600) { $tk = $b['prev'][1] . ' → ' . $r->path; if ($b['prev'][1] !== $r->path) $b['trans'][$tk] = ($b['trans'][$tk] ?? 0) + 1; $b['gaps'][] = $gap; }
            }
            $b['prev'] = [$r->site, $r->path, $r->ts];
            unset($b);
        }
        arsort($typ); uasort($bot, fn($a, $b) => $b['n'] <=> $a['n']);
        ob_start();
        echo '<div class="ms-card"><h2>Roboter auf deinen Seiten</h2><p><span class="ms-big">' . number_format_i18n($total) . '</span> Roboter-Zugriffe im gewählten Zeitraum (nicht in den Besucherzahlen oben enthalten).</p>';
        echo '<p class="ms-note">Erkannt wird nur am Namen, den sich der Roboter selbst gibt (User-Agent). Wer sich als normaler Browser tarnt, fällt hier nicht auf; dafür dient die Roboter-Schätzung in der Übersicht. Gezählt wird, wenn die Seite vom Server neu gebaut wird (nicht bei Seiten aus dem Zwischenspeicher) und wenn der Roboter JavaScript ausführt. Die echte Zahl ist daher eher höher. Gespeichert werden nur Name, Seite, Zeit und gekürzte Kennung, nie IP-Adressen.</p></div>';
        self::bars('Roboter nach Art', array_map(fn($k, $v) => [$k, $v], array_keys($typ), array_values($typ)));
        echo '<div class="ms-card"><h2>Was bedeuten die Arten?</h2><ul style="margin:0 0 0 18px;list-style:disc">'
            . '<li><b>Suchmaschine</b>: Google, Bing & Co. lesen deine Seiten, damit sie in den Suchergebnissen erscheinen.</li>'
            . '<li><b>KI-Crawler</b>: Dienste wie ChatGPT, Claude, Perplexity lesen Seiten, um Antworten zu geben oder Modelle zu trainieren.</li>'
            . '<li><b>SEO-Tool</b>: Auswertungsdienste (Ahrefs, Semrush …), die Links und Rankings für ihre Kunden sammeln.</li>'
            . '<li><b>Social-Vorschau</b>: Wenn jemand deinen Link teilt, holt Facebook, Pinterest, WhatsApp usw. Titel und Bild für die Vorschau.</li>'
            . '<li><b>Überwachung</b>: Dienste, die regelmäßig nachsehen, ob eine Seite erreichbar und schnell ist (z. B. UptimeRobot, Pingdom). Meist vom Seitenbetreiber oder Hoster beauftragt.</li>'
            . '<li><b>Skript</b>: Selbstgeschriebene Programme oder Headless-Browser, kein Mensch am Bildschirm.</li>'
            . '<li><b>Unbekannt</b>: Nennt sich Roboter, steht aber nicht in der Liste. Wenn möglich steht der Name aus der Kennung dahinter.</li></ul></div>';
        echo '<div class="ms-card"><h2>Welche Roboter und was sie tun <small>(Zeile anklicken für Details)</small></h2>';
        $i = 0;
        foreach (array_slice($bot, 0, 40, true) as $nm => $x) {
            arsort($x['pages']); arsort($x['trans']); ksort($x['days']);
            $info = self::bot_info($x['ua']);
            $med = null; if ($x['gaps']) { sort($x['gaps']); $med = $x['gaps'][(int)floor(count($x['gaps']) / 2)]; }
            echo '<details class="ms-bot"' . ($i++ === 0 ? ' open' : '') . '><summary><b>' . esc_html($nm) . '</b> &nbsp;<span class="ms-pill">' . esc_html($x['typ']) . '</span> &nbsp;' . (int)$x['n'] . ' Zugriffe, ' . count($x['pages']) . ' Seiten</summary><div style="padding:6px 0 14px 14px">';
            echo '<p class="ms-note">' . esc_html($info[2] ?? '') . ' &nbsp;·&nbsp; Kennung: <code>' . esc_html(mb_substr($x['ua'], 0, 100)) . '</code></p>';
            echo '<p>Zuerst gesehen ' . esc_html(wp_date('d.m.y H:i', strtotime($x['first'] . ' UTC'))) . ', zuletzt ' . esc_html(wp_date('d.m.y H:i', strtotime($x['last'] . ' UTC'))) . '. '
                . ($med !== null ? 'Läufe mit mehreren Seiten: typischer Abstand zwischen zwei Abrufen ' . ($med < 60 ? (int)$med . ' Sekunden' : round($med / 60, 1) . ' Minuten') . '. ' : 'Meist ein einzelner Abruf pro Lauf, keine Seite-zu-Seite-Wege erkennbar. ')
                . ($x['js'] ? (int)$x['js'] . ' Zugriffe führten JavaScript aus (kann Seiten wie ein Browser darstellen).' : 'Führt kein JavaScript aus: sieht nur den Text im Quellcode deiner Seite.') . '</p>';
            echo '<div class="ms-grid2"><div><b>Ruft am häufigsten auf</b><table class="ms-tbl"><tbody>';
            foreach (array_slice($x['pages'], 0, 12, true) as $pg => $c) echo '<tr><td>' . esc_html($pg) . '</td><td class="num">' . (int)$c . '</td></tr>';
            echo '</tbody></table></div><div><b>Wege von Seite zu Seite</b>';
            if ($x['trans']) { echo '<table class="ms-tbl"><tbody>'; foreach (array_slice($x['trans'], 0, 12, true) as $tr => $c) echo '<tr><td>' . esc_html($tr) . '</td><td class="num">' . (int)$c . '</td></tr>'; echo '</tbody></table>'; }
            else echo '<p class="ms-note">Keine aufeinanderfolgenden Abrufe innerhalb von 10 Minuten gesehen.</p>';
            echo '</div></div>';
            if (count($x['days']) > 1) { $dd = []; foreach ($x['days'] as $d => $c) $dd[] = esc_html(wp_date('d.m.', strtotime($d))) . ': ' . (int)$c; echo '<p class="ms-note">Zugriffe pro Tag: ' . implode(' · ', array_slice($dd, -14)) . '</p>'; }
            echo '</div></details>';
        }
        if (!$bot) echo '<p>Noch keine Roboter-Zugriffe erfasst.</p>';
        echo '<p class="ms-note">Zeiten auf der Seite und Scrolltiefe lassen sich bei Robotern nicht messen: Sie bleiben nicht „auf" der Seite, sondern holen sie nur ab. Gemessen wird der Abstand zwischen zwei Abrufen. Wege entstehen aus der Reihenfolge der Abrufe derselben Roboterart auf derselben Website (innerhalb von 10 Minuten); bei mehreren gleichzeitigen Robotern derselben Art können Wege vermischt sein.</p></div>';
        echo '<style>.ms-bot{border-top:1px solid #dcdcde;padding:8px 0}.ms-bot summary{cursor:pointer}</style>';
        return ob_get_clean();
    }

    static function fmt_time($s) {
        $s = (int)round($s);
        return $s < 60 ? $s . ' s' : floor($s / 60) . ' min ' . ($s % 60) . ' s';
    }

    static function report() {
        global $wpdb;
        $t = self::table();
        $sel = isset($_GET['seite']) ? sanitize_text_field(wp_unslash($_GET['seite'])) : '';
        $von = preg_match('/^\d{4}-\d\d-\d\d$/', (string)($_GET['von'] ?? '')) ? $_GET['von'] : '';
        $bis = preg_match('/^\d{4}-\d\d-\d\d$/', (string)($_GET['bis'] ?? '')) ? $_GET['bis'] : '';
        $to = gmdate('Y-m-d'); $custom = false; $yday = (($_GET['tage'] ?? '') === 'gestern');
        if ($von && $bis && $von <= $bis && strtotime($von) && strtotime($bis)) { $from = $von; $to = $bis; $days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1; $custom = true; }
        elseif ($yday) { $from = $to = gmdate('Y-m-d', time() - 86400); $days = 1; }
        else { $days = isset($_GET['tage']) ? max(1, min(3650, (int)$_GET['tage'])) : 30; $from = gmdate('Y-m-d', time() - ($days - 1) * 86400); }
        $w = $sel ? $wpdb->prepare('day >= %s AND day <= %s AND site = %s', $from, $to, $sel) : $wpdb->prepare('day >= %s AND day <= %s', $from, $to);

        $sites = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT site FROM $t WHERE day >= %s AND day <= %s ORDER BY site", $from, $to));
        $base = admin_url('admin.php?page=mini-statistik');
        echo '<div class="ms-card ms-nav"><b>Auswertung</b> &nbsp; ';
        foreach (['1' => 'Heute', 'gestern' => 'Gestern', '7' => '7 Tage', '30' => '30 Tage', '90' => '90 Tage', '365' => '1 Jahr', '3650' => 'Alles'] as $n => $nl) {
            $on = !$custom && (($n === 'gestern') ? $yday : (!$yday && (int)$n === $days));
            echo '<a href="' . esc_url(add_query_arg(['tage' => $n, 'seite' => $sel], $base)) . '">' . ($on ? '<b>' . $nl . '</b>' : $nl) . '</a>';
        }
        echo '<form method="get" style="display:inline-block;margin-right:10px"><input type="hidden" name="page" value="mini-statistik"><input type="hidden" name="seite" value="' . esc_attr($sel) . '"><input type="date" name="von" value="' . esc_attr($custom ? $from : '') . '" style="width:140px"> – <input type="date" name="bis" value="' . esc_attr($custom ? $to : '') . '" style="width:140px"> <button class="button button-small">Zeitraum</button></form>';
        echo '<form method="get" style="display:inline"><input type="hidden" name="page" value="mini-statistik"><input type="hidden" name="tage" value="' . ($yday ? 'gestern' : (int)$days) . '">' . ($custom ? '<input type="hidden" name="von" value="' . esc_attr($from) . '"><input type="hidden" name="bis" value="' . esc_attr($to) . '">' : '') . '<select name="seite" onchange="this.form.submit()"><option value="">Alle Seiten</option>';
        foreach ($sites as $s) echo '<option' . selected($sel, $s, false) . '>' . esc_html($s) . '</option>';
        echo '</select></form></div>';

        // Aufrufe pro Tag, je Seite farbig gestapelt
        $pal = ['#2271b1', '#d98200', '#00a32a', '#8c3fb5', '#d63638', '#0a9396', '#a0a000', '#e0457b'];
        $rows_d = $wpdb->get_results("SELECT day, site, COUNT(*) n FROM $t WHERE kind='view' AND $w GROUP BY day, site");
        $series = []; $site_tot = [];
        foreach ($rows_d as $r) {
            $key = $days > 120 ? substr($r->day, 0, 7) : $r->day;
            $series[$key][$r->site] = ($series[$key][$r->site] ?? 0) + (int)$r->n;
            $site_tot[$r->site] = ($site_tot[$r->site] ?? 0) + (int)$r->n;
        }
        arsort($site_tot); $col = []; $ci = 0;
        foreach ($site_tot as $sn => $x_) $col[$sn] = $pal[$ci++ % count($pal)];
        if ($days <= 120) { $full = []; for ($i = $days - 1; $i >= 0; $i--) { $d = gmdate('Y-m-d', strtotime($to) - $i * 86400); $full[$d] = $series[$d] ?? []; } $series = $full; $unit = 'Tag'; }
        else { ksort($series); $unit = 'Monat'; }
        if (!$series) $series = [gmdate('Y-m-d') => []];
        $tot = array_map('array_sum', $series);
        $max = max(1, max($tot)); $n = count($series); $bw = 1000 / $n; $lab_on = $n <= 45;
        $sec = []; $cut = function ($t) use (&$sec) { $sec[$t] = ($sec[$t] ?? '') . ob_get_clean(); ob_start(); }; ob_start();
        echo '<div class="ms-card"><h2>Aufrufe pro ' . $unit . '</h2><svg viewBox="0 0 1000 190" width="100%" role="img" aria-label="Aufrufe pro ' . $unit . ', nach Seite gestapelt">';
        echo '<line x1="0" y1="150" x2="1000" y2="150" stroke="#dcdcde"/>';
        $i = 0;
        foreach ($series as $d => $parts) {
            $x = $i * $bw; $y = 150; $tip = $d . ': ' . $tot[$d] . ' Aufrufe';
            arsort($parts);
            foreach ($parts as $sn => $v) $tip .= "\n" . $sn . ': ' . $v;
            foreach ($parts as $sn => $v) {
                $h = 125 * $v / $max; $y -= $h;
                echo '<rect x="' . round($x + $bw * 0.12, 1) . '" y="' . round($y, 1) . '" width="' . round($bw * 0.76, 1) . '" height="' . round(max($h, 1), 1) . '" fill="' . $col[$sn] . '"><title>' . esc_html($tip) . '</title></rect>';
            }
            if ($lab_on) {
                if ($tot[$d] > 0) echo '<text x="' . round($x + $bw / 2, 1) . '" y="' . round($y - 4, 1) . '" font-size="' . ($n > 25 ? 10 : 12) . '" text-anchor="middle" fill="#1d2327">' . (int)$tot[$d] . '</text>';
                echo '<text x="' . round($x + $bw / 2, 1) . '" y="168" font-size="10" text-anchor="middle" fill="#646970">' . esc_html($days > 120 ? substr($d, 2) : substr($d, 8) . '.' . substr($d, 5, 2)) . '</text>';
            }
            $i++;
        }
        echo '<text x="0" y="186" font-size="12" fill="#646970">' . esc_html(array_key_first($series)) . '</text><text x="1000" y="186" font-size="12" text-anchor="end" fill="#646970">' . esc_html(array_key_last($series)) . '  (Spitze: ' . $max . ')</text></svg><p class="ms-note">';
        foreach ($col as $sn => $c_) echo '<span style="color:' . $c_ . '">▮</span> ' . esc_html($sn) . ' (' . (int)$site_tot[$sn] . ') &nbsp; ';
        echo '</p></div>';

        // Kennzahlen
        $views = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE kind='view' AND $w");
        $visitors = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT DISTINCT day, visitor FROM $t WHERE kind='view' AND $w) x"); // Besucher je Tag (ein Besucher an zwei Tagen zählt zweimal)
        $uniq = (int)$wpdb->get_var("SELECT COUNT(DISTINCT visitor) FROM $t WHERE kind='view' AND $w");
        $returners = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT visitor FROM $t WHERE kind='view' AND $w GROUP BY visitor HAVING COUNT(DISTINCT day) > 1) x");
        $tm = $wpdb->get_row("SELECT SUM(secs) s, AVG(scroll) g FROM $t WHERE kind='time' AND $w");
        $avg_time = $views ? ((int)$tm->s) / $views : 0;
        $clicks = $wpdb->get_results("SELECT site, path, target, label, item, img, pn, via, COUNT(*) n, MAX(ts) lt FROM $t WHERE kind='click' AND $w GROUP BY site, path, target, label, item, img, pn, via ORDER BY n DESC LIMIT 5000");
        // Klicks ohne Produktnummer (Kurzlinks, Suchlinks) einem Produkt mit Nummer zuordnen, wenn Bild oder Produktname auf derselben Seite übereinstimmt
        $by_img = []; $by_pn = [];
        foreach ($clicks as $c) if ($c->item !== '') {
            if ($c->img !== '') $by_img[$c->site . $c->path . '|' . $c->img] = [$c->item, $c->target];
            if ($c->pn !== '') $by_pn[$c->site . $c->path . '|' . strtolower($c->pn)] = [$c->item, $c->target];
        }
        $prod_key = function ($c, $p) use ($by_img, $by_pn) {
            $k = $c->site . $c->path; $item = $c->item; $host = $c->target;
            if ($item === '' && $c->img !== '' && isset($by_img[$k . '|' . $c->img])) [$item, $host] = $by_img[$k . '|' . $c->img];
            if ($item === '' && $c->pn !== '' && isset($by_pn[$k . '|' . strtolower($c->pn)])) [$item, $host] = $by_pn[$k . '|' . strtolower($c->pn)];
            $key = $item !== '' ? $item : ($c->img !== '' ? 'I:' . $c->img : ($c->pn !== '' ? 'N:' . strtolower($c->pn) : 'T:' . strtolower($c->label)));
            return [$p . '|' . $key . ($item === '' && $c->img === '' && $c->pn === '' ? '|' . $c->target : ''), $item, $host];
        };
        $by_partner = []; $products = []; $click_total = 0; $partner_clicks = 0; $click_by_page = []; $top_links = [];
        foreach ($clicks as $c) {
            $p = self::partner($c->target);
            $by_partner[$p] = ($by_partner[$p] ?? 0) + $c->n;
            if ($p === 'Eigene Seiten') continue;
            $click_total += $c->n;
            if ($p === 'Amazon' || $p === 'eBay') $partner_clicks += $c->n;
            $k = $c->site . $c->path; $click_by_page[$k] = ($click_by_page[$k] ?? 0) + $c->n;
            if ($p === 'Amazon' || $p === 'eBay') {
                [$pk, $item, $host] = $prod_key($c, $p);
                if (!isset($products[$pk])) $products[$pk] = ['p' => $p, 'item' => $item, 'host' => $host, 'img' => '', 'n' => 0, 'ni' => 0, 'nt' => 0, 'names' => [], 'labels' => [], 'pages' => [], 'lt' => ''];
                $q =& $products[$pk];
                if ($c->lt > $q['lt']) $q['lt'] = $c->lt;
                $q['n'] += $c->n; if ($c->via === 'img') $q['ni'] += $c->n; else $q['nt'] += $c->n;
                if ($q['img'] === '' && $c->img !== '') $q['img'] = $c->img;
                if ($c->pn !== '') $q['names'][$c->pn] = ($q['names'][$c->pn] ?? 0) + $c->n;
                if ($c->label !== '') $q['labels'][$c->label] = ($q['labels'][$c->label] ?? 0) + $c->n;
                $q['pages'][$k] = ($q['pages'][$k] ?? 0) + $c->n;
                unset($q);
            }
            $top_links[] = [$c->label !== '' ? $c->label : '(Bild/ohne Text)', $p, $c->site . $c->path, (int)$c->n, ($p === 'Amazon' || $p === 'eBay') ? $prod_key($c, $p)[0] : '', '', $c->target];
        }
        arsort($by_partner);
        foreach ($top_links as &$tl) {
            $tl[5] = ''; $tl[7] = '';
            if ($tl[4] !== '' && isset($products[$tl[4]])) {
                $pq = $products[$tl[4]]; arsort($pq['names']); $tl[5] = $pq['names'] ? array_key_first($pq['names']) : '';
                if ($pq['item'] !== '') $tl[7] = $pq['p'] === 'Amazon' ? 'https://www.amazon.de/dp/' . $pq['item'] : 'https://www.ebay.de/itm/' . $pq['item'];
            }
            if ($tl[7] === '' && $tl[1] === 'Sonstige' && preg_match('/^[a-z0-9.\-]+$/i', (string)$tl[6])) $tl[7] = 'https://' . $tl[6];
        }
        unset($tl);

        // Anteil der Besucher mit Klick (je Besucher nur einmal pro Ziel gezählt)
        $vis_click = ['Amazon' => [], 'eBay' => [], 'Sonstige' => [], 'any' => []];
        foreach ($wpdb->get_results("SELECT DISTINCT day, visitor, target FROM $t WHERE kind='click' AND $w LIMIT 50000") as $r) {
            $pp = self::partner($r->target);
            if ($pp === 'Eigene Seiten') continue;
            $vk = $r->day . '|' . $r->visitor; $vis_click[$pp][$vk] = 1;
            if ($pp !== 'Sonstige') $vis_click['any'][$vk] = 1;
        }
        $pct = function ($k) use ($vis_click, $visitors) { return $visitors ? round(100 * count($vis_click[$k]) / $visitors, 1) : 0; };

        // Rückkehr nach Klick auf externe Links
        $ret = ['Amazon' => [], 'eBay' => [], 'Sonstige' => []]; $pret = [];
        $vis_list = []; $back_list = [];
        foreach ($wpdb->get_results("SELECT site, visitor, ts FROM $t WHERE kind='view' AND $w ORDER BY ts LIMIT 60000") as $r) $vis_list[$r->site . '|' . $r->visitor][] = strtotime($r->ts . ' UTC');
        foreach ($wpdb->get_results("SELECT site, visitor, ts, secs FROM $t WHERE kind='back' AND $w ORDER BY ts LIMIT 60000") as $r) $back_list[$r->site . '|' . $r->visitor][] = [strtotime($r->ts . ' UTC'), (int)$r->secs];
        foreach ($wpdb->get_results("SELECT site, path, visitor, target, label, item, img, pn, ts FROM $t WHERE kind='click' AND $w ORDER BY ts LIMIT 30000") as $r) {
            $pp = self::partner($r->target);
            if (!isset($ret[$pp])) continue;
            $ct = strtotime($r->ts . ' UTC'); $kk = $r->site . '|' . $r->visitor; $res = ['n' => 1, 'type' => 'none', 'd' => 0];
            foreach ($back_list[$kk] ?? [] as $b) if ($b[0] >= $ct && $b[0] - $ct <= 10800) { $res['type'] = 'back'; $res['d'] = $b[1] > 0 ? $b[1] : $b[0] - $ct; break; }
            if ($res['type'] === 'none') foreach ($vis_list[$kk] ?? [] as $vt) if ($vt > $ct + 1 && $vt - $ct <= 10800) { $res['type'] = 'view'; $res['d'] = $vt - $ct; break; }
            if ($res['type'] === 'none') foreach ($vis_list[$kk] ?? [] as $vt) if ($vt - $ct > 10800) { $res['type'] = 'later'; $res['d'] = $vt - $ct; break; }
            $ret[$pp][] = $res;
            if ($pp === 'Amazon' || $pp === 'eBay') $pret[$prod_key($r, $pp)[0]][] = $res;
        }
        // Vergleich mit dem Zeitraum davor (gleich lang)
        $dl = function ($cur, $prev) { if ($prev === null || $prev <= 0) return ''; $p = round(100 * ($cur - $prev) / $prev); return $p == 0 ? '<i class="ms-d">± 0 %</i>' : '<i class="ms-d" style="color:' . ($p > 0 ? '#00a32a' : '#d63638') . '">' . ($p > 0 ? '▲ +' : '▼ ') . $p . ' %</i>'; };
        $dv = $dvis = $dclk = $dtime = null;
        if ($days < 3650) {
            $pto = gmdate('Y-m-d', strtotime($from) - 86400); $pfrom = gmdate('Y-m-d', strtotime($pto) - ($days - 1) * 86400);
            $wp = $sel ? $wpdb->prepare('day >= %s AND day <= %s AND site = %s', $pfrom, $pto, $sel) : $wpdb->prepare('day >= %s AND day <= %s', $pfrom, $pto);
            $dv = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE kind='view' AND $wp");
            $dvis = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT DISTINCT day, visitor FROM $t WHERE kind='view' AND $wp) x");
            $psecs = (int)$wpdb->get_var("SELECT SUM(secs) FROM $t WHERE kind='time' AND $wp");
            $dtime = $dv ? $psecs / $dv : null;
            $dclk = 0;
            foreach ($wpdb->get_results("SELECT target, COUNT(*) n FROM $t WHERE kind='click' AND $wp GROUP BY target") as $r) { $pp_ = self::partner($r->target); if ($pp_ === 'Amazon' || $pp_ === 'eBay') $dclk += (int)$r->n; }
        }
        // Roboter-Schätzung: nur für Seiten und Zeiten, ab denen sie das Signal „Bewegung“ senden (Version 0.23)
        $since_h = [];
        foreach ($wpdb->get_results("SELECT site, MIN(ts) m FROM $t WHERE kind='human' GROUP BY site") as $r_) $since_h[$r_->site] = $r_->m;
        $meas = $bots = $quiet = 0;
        if ($since_h) {
            $hum = [];
            foreach ($wpdb->get_results("SELECT DISTINCT site, day, visitor FROM $t WHERE kind='human' AND $w LIMIT 60000") as $r_) $hum[$r_->site . '|' . $r_->day . '|' . $r_->visitor] = 1;
            $rdev = ['mobil' => 0, 'tablet' => 0, 'desktop' => 0, 'unbekannt' => 0];
            foreach ($wpdb->get_results("SELECT site, day, visitor, MIN(ts) m, MAX(via='bot') b, MAX(device) dv FROM $t WHERE kind='view' AND $w GROUP BY site, day, visitor LIMIT 60000") as $r_) {
                if (!isset($since_h[$r_->site]) || $r_->m < $since_h[$r_->site]) continue;
                $meas++;
                $dk = $r_->dv !== '' && isset($rdev[$r_->dv]) ? $r_->dv : 'unbekannt';
                if ((int)$r_->b) { $bots++; $rdev[$dk]++; }
                elseif (!isset($hum[$r_->site . '|' . $r_->day . '|' . $r_->visitor])) { $quiet++; $rdev[$dk]++; }
            }
        }
        $rob = $meas ? ($bots + $quiet) / $meas : 0;
        echo '<div class="ms-kpis">';
        foreach ([
            [number_format_i18n($visitors), 'Besucher (je Tag gezählt)', $dl($visitors, $dvis)],
            [$uniq ? round(100 * $returners / $uniq) . ' %' : '–', 'Wiederkehrer (an mehr als einem Tag, innerhalb von ' . (int)self::opts()['salt_days'] . ' Tagen)'],
            [number_format_i18n($views), 'Seitenaufrufe', $dl($views, $dv)],
            [self::fmt_time($avg_time), 'Ø Verweildauer pro Aufruf', $dl($avg_time, $dtime)],
            [self::fmt_time($visitors ? ((int)$tm->s) / $visitors : 0), 'Ø Verweildauer pro Besucher und Tag'],
            [round((float)$tm->g) . ' %', 'Ø Scrolltiefe'],
            [number_format_i18n($partner_clicks), 'Klicks zu Amazon / eBay', $dl($partner_clicks, $dclk)],
            [$pct('any') . ' %', 'Besucher mit Klick auf Amazon oder eBay'],
        ] as $k) echo '<div class="ms-kpi"><b>' . esc_html($k[0]) . '</b><span>' . esc_html($k[1]) . '</span>' . ($k[2] ?? '') . '</div>';
        if ($meas >= 20) foreach ([
            [round(100 * $rob) . ' %', 'vermutlich Roboter (' . number_format_i18n($bots) . ' als automatisiert gemeldet, ' . number_format_i18n($quiet) . ' ganz ohne Maus, Touch oder Scrollen; gemessen an ' . number_format_i18n($meas) . ' Besuchern seit Version 0.23)'],
            [number_format_i18n(round($visitors * (1 - $rob))), 'Besucher ohne Roboter (geschätzt, Hochrechnung)'],
        ] as $k) echo '<div class="ms-kpi"><b>' . esc_html($k[0]) . '</b><span>' . esc_html($k[1]) . '</span>' . ($k[2] ?? '') . '</div>';
        echo '</div>';
        echo self::robot_overview($w, $meas, $bots, $quiet, $rdev ?? []);
        echo ($dv !== null ? '<p class="ms-note">Pfeile: Veränderung gegenüber dem gleich langen Zeitraum davor (' . esc_html($pfrom . ' bis ' . $pto) . ').</p>' : '');

        $cut('u');
        // Meistgeklickte Produkte
        $hidden = (array)get_option('mini_statistik_hidden', []);
        $nhid = 0;
        foreach ($hidden as $hk) if (isset($products[$hk])) { unset($products[$hk]); $nhid++; }
        uasort($products, fn($a, $b) => $b['n'] <=> $a['n']);
        $onerr = 'var f=this.dataset.f?this.dataset.f.split(\'|\'):[];if(f.length){this.src=f.shift();this.dataset.f=f.join(\'|\');}else{this.style.visibility=\'hidden\'}';
        $onload = 'if(this.naturalWidth<3){var f=this.dataset.f?this.dataset.f.split(\'|\'):[];if(f.length){this.src=f.shift();this.dataset.f=f.join(\'|\');}else{this.style.visibility=\'hidden\'}}';
        echo '<style>.ms-scroll{max-height:70vh;overflow:auto}#ms-produkte .ms-tbl thead th{position:sticky;top:0;background:#fff;z-index:2;box-shadow:0 1px 0 #dcdcde}</style><div class="ms-card" id="ms-produkte"><h2>Meistgeklickte Produkte (Amazon / eBay)</h2><p class="ms-note">Anzeigen: <select id="ms-plim"><option>10</option><option>25</option><option>50</option><option>100</option></select> Produkte · Spaltenüberschrift anklicken zum Sortieren</p><div class="ms-scroll"><table class="ms-tbl"><thead><tr><th></th><th>Produkt</th><th>Shop</th><th class="num">Klicks</th><th class="num">Bild</th><th class="num">Text</th><th class="num">kamen zurück</th><th class="num">Ø Zeit bis Rückkehr</th><th>Hauptsächlich auf</th><th>Letzter Klick</th><th></th></tr></thead><tbody>';
        foreach (array_slice($products, 0, 100, true) as $pk => $q) {
            arsort($q['names']); arsort($q['labels']); arsort($q['pages']);
            $name = $q['names'] ? array_key_first($q['names']) : ($q['labels'] ? array_key_first($q['labels']) : '(Produkt nicht erkannt)');
            $srcs = [];
            if ($q['img'] !== '') $srcs[] = $q['img'];
            if ($q['p'] === 'Amazon' && $q['item'] !== '') {
                $srcs[] = 'https://m.media-amazon.com/images/P/' . $q['item'] . '.01._SL160_.jpg';
                $srcs[] = 'https://images-na.ssl-images-amazon.com/images/P/' . $q['item'] . '.03._SL160_.jpg';
            }
            $thumb = $srcs
                ? '<img src="' . esc_url(array_shift($srcs)) . '" data-f="' . esc_attr(implode('|', $srcs)) . '" onerror="' . esc_attr($onerr) . '" onload="' . esc_attr($onload) . '" alt="" width="64" height="64" loading="lazy" referrerpolicy="no-referrer" style="object-fit:contain;border-radius:6px;background:#f6f7f7">'
                : '<div style="width:64px;height:64px;border-radius:6px;background:#f0f0f1;color:#a7aaad;display:flex;align-items:center;justify-content:center;font-size:11px;text-align:center">kein Bild</div>';
            $id = '';
            $unas = ($q['item'] === '' && $q['img'] === '' && !$q['names']);
            if ($unas) $thumb = '<div style="width:64px;height:64px;border-radius:6px;background:#fcf0f1;color:#d63638;display:flex;align-items:center;justify-content:center;font-size:11px;text-align:center">nicht zuordenbar</div>';
            if ($q['item'] !== '') {
                $hh = preg_match('/amzn\.|ebay\.us|link\.amazon/', $q['host']) ? ($q['p'] === 'Amazon' ? 'www.amazon.de' : 'www.ebay.de') : $q['host'];
                $href = $q['p'] === 'Amazon' ? 'https://' . $hh . '/dp/' . $q['item'] : 'https://' . $hh . '/itm/' . $q['item'];
                $id = '<a href="' . esc_url($href) . '" target="_blank" rel="noopener noreferrer">' . esc_html(($q['p'] === 'Amazon' ? 'ASIN ' : 'Artikel-Nr. ') . $q['item']) . '</a>';
            }
            $lbl = array_slice(array_keys($q['labels']), 0, 3);
            $sub = trim(($id ? $id . ' · ' : '') . ($lbl ? 'Linktext: „' . esc_html(implode('“, „', $lbl)) . '“' : ''), ' ·');
            $pr = $pret[$pk] ?? []; $rb = 0; $rd = [];
            foreach ($pr as $x) if ($x['type'] === 'back' || $x['type'] === 'view') { $rb++; $rd[] = $x['d']; }
            $back_cell = $pr ? '<b>' . round(100 * $rb / count($pr)) . ' %</b>' : '–';
            $time_cell = $rd ? self::fmt_time(array_sum($rd) / count($rd)) : '–';
            echo '<tr><td style="width:72px">' . $thumb . '</td><td><b>' . esc_html($name) . '</b>' . ($sub ? '<br><span class="ms-note">' . $sub . '</span>' : '') . '</td><td><span class="ms-pill ms-p-' . esc_attr($q['p']) . '">' . esc_html($q['p']) . '</span></td><td class="num"><b>' . (int)$q['n'] . '</b></td><td class="num">' . (int)$q['ni'] . '</td><td class="num">' . (int)$q['nt'] . '</td><td class="num">' . $back_cell . '</td><td class="num">' . $time_cell . '</td><td>' . implode('<br>', array_map(function ($pg, $n_) use ($unas) { return '<a href="' . esc_url('https://' . $pg) . '" target="_blank" rel="noopener noreferrer">' . esc_html($pg) . '</a>' . ($unas ? ' (' . (int)$n_ . ')' : ''); }, array_slice(array_keys($q['pages']), 0, $unas ? 4 : 1), array_slice(array_values($q['pages']), 0, $unas ? 4 : 1))) . '</td><td>' . ($q['lt'] ? esc_html(wp_date('d.m.y H:i', strtotime($q['lt'] . ' UTC'))) : '–') . '</td><td><form method="post">' . wp_nonce_field('ms_hide', '_wpnonce', true, false) . '<input type="hidden" name="pk" value="' . esc_attr($pk) . '"><button class="button button-small" name="ms_hide" value="hide" title="Aus dieser Liste ausblenden (Daten bleiben gespeichert)">ausblenden</button></form></td></tr>';
        }
        if (!$products) echo '<tr><td colspan="11">Noch keine Klicks auf Amazon oder eBay.</td></tr>';
        echo '</tbody></table></div>';
        if ($hidden) echo '<form method="post" style="margin-top:8px">' . wp_nonce_field('ms_hide', '_wpnonce', true, false) . '<button class="button button-small" name="ms_hide" value="show">Ausgeblendete Produkte wieder einblenden (' . count($hidden) . ')</button></form>';
        echo '<p class="ms-note">Zeigt bis zu 100 der meistgeklickten Produkte im gewählten Zeitraum (oben „Auswertung"). Produktname und Bild werden beim Klick aus der Seite gelesen. Text- und Kurzlinks werden dem Produkt zugeordnet, wenn auf derselben Seite Bild oder Name übereinstimmen. „Kamen zurück" = innerhalb von 3 Stunden wieder auf deiner Seite; „Ø Zeit" gilt nur für die, die zurückkamen. Bei wenigen Klicks sind die Werte nur grobe Hinweise.</p></div>';

        echo '<div class="ms-card"><h2>Welcher Anteil der Besucher klickt auf einen ausgehenden Link?</h2>';
        foreach ([['Amazon', 'amz', 'Amazon'], ['eBay', 'ebay', 'eBay'], ['Sonstige', 'oth', 'andere externe Seiten'], ['any', '', 'Amazon oder eBay (mindestens ein Klick)']] as $b) {
            $v = $pct($b[0]);
            echo '<div class="ms-row"><span class="n">' . esc_html($b[2]) . '</span><div class="ms-bar ' . $b[1] . '"><i style="width:' . min(100, $v * 2) . '%"></i></div><span class="v">' . number_format_i18n($v, 1) . ' %</span></div>';
        }
        echo '<p class="ms-note">Anteil an allen Besuchern im gewählten Zeitraum (Balken auf 50 % skaliert). Pro Besucher zählt ein Ziel nur einmal.</p></div>';

        echo '<div class="ms-card"><h2>Was passiert nach dem Klick auf Amazon / eBay?</h2><table class="ms-tbl"><thead><tr><th>Ziel</th><th class="num">Klicks</th><th class="num">kamen zurück</th><th class="num">davon sofort (Seite aus Speicher)</th><th class="num">davon mit neuem Aufruf</th><th class="num">kamen später wieder (neuer Besuch)</th><th class="num">nicht zurück</th><th class="num">Ø Zeit bis Rückkehr</th><th class="num">mittlere Zeit (Median)</th></tr></thead><tbody>';
        $all_d = []; $later_d = [];
        foreach ($ret as $pp => $list) {
            $n = count($list); if (!$n) continue;
            $b = $v = $l = 0; $ds = [];
            foreach ($list as $x) { if ($x['type'] === 'back') { $b++; $ds[] = $x['d']; } elseif ($x['type'] === 'view') { $v++; $ds[] = $x['d']; } elseif ($x['type'] === 'later') { $l++; $later_d[] = $x['d']; } }
            sort($ds); $m = count($ds); $all_d = array_merge($all_d, $ds);
            $med = $m ? ($m % 2 ? $ds[intdiv($m, 2)] : ($ds[$m / 2 - 1] + $ds[$m / 2]) / 2) : 0;
            echo '<tr><td><span class="ms-pill ms-p-' . esc_attr($pp) . '">' . esc_html($pp) . '</span></td><td class="num">' . $n . '</td><td class="num"><b>' . round(100 * ($b + $v) / $n) . ' %</b></td><td class="num">' . $b . '</td><td class="num">' . $v . '</td><td class="num">' . $l . '</td><td class="num">' . ($n - $b - $v - $l) . '</td><td class="num">' . ($m ? self::fmt_time(array_sum($ds) / $m) : '–') . '</td><td class="num">' . ($m ? self::fmt_time($med) : '–') . '</td></tr>';
        }
        if (!array_filter($ret)) echo '<tr><td colspan="9">Noch keine Klicks.</td></tr>';
        echo '</tbody></table>';
        if ($all_d) {
            $bk = ['unter 30 s' => 0, '30 s bis 2 min' => 0, '2 bis 5 min' => 0, '5 bis 15 min' => 0, 'über 15 min' => 0];
            foreach ($all_d as $d) { if ($d < 30) $bk['unter 30 s']++; elseif ($d < 120) $bk['30 s bis 2 min']++; elseif ($d < 300) $bk['2 bis 5 min']++; elseif ($d < 900) $bk['5 bis 15 min']++; else $bk['über 15 min']++; }
            $mx = max($bk); echo '<h3 style="margin:14px 0 6px;font-size:13px">Wie lange dauert es bis zur Rückkehr?</h3>';
            foreach ($bk as $l => $c) echo '<div class="ms-row"><span class="n">' . esc_html($l) . '</span><div class="ms-bar"><i style="width:' . round(100 * $c / max(1, $mx)) . '%"></i></div><span class="v">' . $c . '</span></div>';
        }
        if ($later_d) {
            $lb = ['am selben Tag später (nach über 3 Std.)' => 0, 'nach 1 bis 2 Tagen' => 0, 'nach mehr als 2 Tagen' => 0];
            foreach ($later_d as $d) { if ($d <= 86400) $lb['am selben Tag später (nach über 3 Std.)']++; elseif ($d <= 172800) $lb['nach 1 bis 2 Tagen']++; else $lb['nach mehr als 2 Tagen']++; }
            $mx = max($lb); echo '<h3 style="margin:14px 0 6px;font-size:13px">Wer später wiederkommt: nach wie langer Zeit?</h3>';
            foreach ($lb as $l2 => $c) echo '<div class="ms-row"><span class="n">' . esc_html($l2) . '</span><div class="ms-bar own"><i style="width:' . round(100 * $c / max(1, $mx)) . '%"></i></div><span class="v">' . $c . '</span></div>';
        }
        echo '<p class="ms-note">Gemessen wird die Zeit vom Klick bis zur Rückkehr auf deine Seite, nicht das Verhalten bei Amazon oder eBay selbst. „Kamen zurück" = innerhalb von 3 Stunden. „Später wieder" = neuer Besuch desselben Browsers, erkennbar nur innerhalb der eingestellten Wiedererkennungsdauer (' . (int)self::opts()['salt_days'] . ' Tage). „Nicht zurück" = in dieser Zeit nicht wieder gesehen. Ein anderes Gerät oder Browser wird nicht erkannt.</p></div>';

        $cut('k');
        // Partner-Klicks
        $pc = []; foreach ($by_partner as $k => $v) $pc[] = [$k, (int)$v];
        echo '<div class="ms-grid">';
        self::bars('Klicks nach Ziel', $pc, ['Amazon' => 'amz', 'eBay' => 'ebay', 'Sonstige' => 'oth', 'Eigene Seiten' => 'own']);
        echo '</div>'; $cut('k'); echo '<div class="ms-grid">';
        $ref = $wpdb->get_results("SELECT COALESCE(NULLIF(ref,''),'direkt / unbekannt') a, COUNT(*) n FROM $t WHERE kind='view' AND $w GROUP BY COALESCE(NULLIF(ref,''),'direkt / unbekannt') ORDER BY n DESC LIMIT 10");
        $kinds = [];
        foreach ($wpdb->get_results("SELECT COALESCE(NULLIF(ref,''),'') a, COUNT(*) n FROM $t WHERE kind='view' AND $w GROUP BY COALESCE(NULLIF(ref,''),'')") as $r) {
            $h = strtolower($r->a);
            if ($h === '') $k = 'Direkt / unbekannt';
            elseif (isset(self::allowed_sites()[preg_replace('/^www\./', '', $h)])) $k = 'Eigene Seiten (intern)';
            elseif (preg_match('/chatgpt|openai\.com|perplexity|gemini\.google|bard\.google|(^|\.)claude\.ai$|copilot\.microsoft|copilot\.cloud|(^|\.)you\.com$|(^|\.)poe\.com$|mistral\.ai|deepseek|(^|\.)grok\.com$|(^|\.)x\.ai$|(^|\.)meta\.ai$|kagi\.com|phind\.com/', $h)) $k = 'KI-Assistenten';
            elseif (preg_match('/google\.|bing\.|duckduckgo|ecosia|yahoo\.|startpage|qwant|brave\.com|yandex|baidu/', $h)) $k = 'Suchmaschinen';
            elseif (preg_match('/facebook|instagram|pinterest|(^|\.)t\.co$|twitter|(^|\.)x\.com$|youtube|reddit|tiktok|whatsapp|linkedin|lnkd|mastodon|telegram/', $h)) $k = 'Soziale Netze';
            else $k = 'Andere Seiten';
            $kinds[$k] = ($kinds[$k] ?? 0) + (int)$r->n;
        }
        arsort($kinds);
        self::bars('Herkunft nach Art', array_map(fn($k, $v) => [$k, $v], array_keys($kinds), array_values($kinds)));
        self::bars('Herkunft', array_map(fn($r) => [$r->a, (int)$r->n], $ref));
        $dev = $wpdb->get_results("SELECT COALESCE(NULLIF(device,''),'unbekannt') a, COUNT(*) n FROM $t WHERE kind='view' AND $w GROUP BY COALESCE(NULLIF(device,''),'unbekannt') ORDER BY n DESC");
        self::bars('Geräte', array_map(fn($r) => [$r->a, (int)$r->n], $dev));
        $cty = $wpdb->get_results("SELECT cc, COUNT(*) n FROM $t WHERE kind='view' AND cc <> '' AND $w GROUP BY cc ORDER BY n DESC LIMIT 10");
        self::bars('Länder (geschätzt)', array_map(fn($r) => [trim(self::flag($r->cc) . ' ' . self::country_name($r->cc)), (int)$r->n], $cty));
        echo '</div>';

        $cut('u');
        // Seiten im Detail
        $pv = $wpdb->get_results("SELECT site, path, COUNT(*) v FROM $t WHERE kind='view' AND $w GROUP BY site, path ORDER BY v DESC LIMIT 25");
        $pt = []; foreach ($wpdb->get_results("SELECT site, path, SUM(secs) s, AVG(scroll) g FROM $t WHERE kind='time' AND $w GROUP BY site, path") as $r) $pt[$r->site . $r->path] = $r;
        echo '<div class="ms-card"><h2>Seiten im Detail</h2><table class="ms-tbl"><thead><tr><th>Seite</th><th class="num">Aufrufe</th><th class="num">Ø Zeit</th><th class="num">Scrolltiefe</th><th class="num">Klicks extern</th><th class="num">Klickrate</th></tr></thead><tbody>';
        foreach ($pv as $r) {
            $k = $r->site . $r->path; $c = $click_by_page[$k] ?? 0; $x = $pt[$k] ?? null;
            echo '<tr><td>' . esc_html($k) . '</td><td class="num">' . (int)$r->v . '</td><td class="num">' . ($x ? self::fmt_time($x->s / $r->v) : '–') . '</td><td class="num">' . ($x ? round($x->g) . ' %' : '–') . '</td><td class="num">' . (int)$c . '</td><td class="num">' . round(100 * $c / $r->v) . ' %</td></tr>';
        }
        if (!$pv) echo '<tr><td colspan="6">Noch keine Daten.</td></tr>';
        echo '</tbody></table></div>';

        $cut('v');
        // Meistgeklickte Links
        usort($top_links, fn($a, $b) => $b[3] <=> $a[3]);
        echo '<div class="ms-card"><h2>Meistgeklickte Links <small><a href="#ms-produkte">→ zu den meistgeklickten Produkten</a></small></h2><p class="ms-note">Linktext, Ziel und Produkt öffnen das Ziel des Klicks (Produktseite bzw. Domain); „Auf Seite“ öffnet die Seite, auf der geklickt wurde. Bei Kurzlinks ohne erkannte Produktnummer führt nur die Seite weiter.</p><table class="ms-tbl"><thead><tr><th>Linktext</th><th>Ziel</th><th>Produkt</th><th>Auf Seite</th><th class="num">Klicks</th></tr></thead><tbody>';
        foreach (array_slice($top_links, 0, 20) as $l) {
            $lnk = function ($txt) use ($l) { return $l[7] !== '' ? '<a href="' . esc_url($l[7]) . '" target="_blank" rel="noopener noreferrer">' . esc_html($txt) . '</a>' : esc_html($txt); };
            echo '<tr><td>' . $lnk($l[0]) . '</td><td><span class="ms-pill ms-p-' . esc_attr($l[1]) . '">' . $lnk($l[1]) . '</span></td><td>' . ($l[5] !== '' ? $lnk($l[5]) : '') . '</td><td><a href="' . esc_url('https://' . $l[2]) . '" target="_blank" rel="noopener noreferrer">' . esc_html($l[2]) . '</a></td><td class="num">' . (int)$l[3] . '</td></tr>';
        }
        if (!$top_links) echo '<tr><td colspan="5">Noch keine Klicks.</td></tr>';
        echo '</tbody></table></div>';

        $cut('k');
        $fr = self::flow_report($w, !$sel);
        $sec['v'] = ($sec['v'] ?? '') . $fr['exit']; $sec['p'] = ($sec['p'] ?? '') . $fr['kpi'] . $fr['grid'] . $fr['warn'];

        self::ga_report($sel);

        $cut('u');
        // Sonstige externe Ziele
        $oth = [];
        foreach ($clicks as $c) {
            if (self::partner($c->target) !== 'Sonstige') continue;
            $o2 =& $oth[strtolower(preg_replace('/^www\./', '', $c->target))];
            if (!$o2) $o2 = ['n' => 0, 'labels' => [], 'pages' => []];
            $o2['n'] += $c->n;
            if ($c->label !== '') $o2['labels'][$c->label] = ($o2['labels'][$c->label] ?? 0) + $c->n;
            $o2['pages'][$c->site . $c->path] = ($o2['pages'][$c->site . $c->path] ?? 0) + $c->n;
            unset($o2);
        }
        uasort($oth, fn($a, $b) => $b['n'] <=> $a['n']);
        echo '<div class="ms-card"><h2>Sonstige externe Links (nicht Amazon/eBay)</h2><table class="ms-tbl"><thead><tr><th>Ziel-Domain</th><th class="num">Klicks</th><th>Linktext</th><th>Meist auf Seite</th></tr></thead><tbody>';
        foreach (array_slice($oth, 0, 30, true) as $h => $x) {
            arsort($x['labels']); arsort($x['pages']);
            echo '<tr><td>' . (preg_match('/^[a-z0-9.\-]+$/i', (string)$h) ? '<a href="' . esc_url('https://' . $h) . '" target="_blank" rel="noopener noreferrer">' . esc_html($h) . '</a>' : esc_html($h)) . '</td><td class="num"><b>' . (int)$x['n'] . '</b></td><td>' . esc_html(implode(' · ', array_slice(array_keys($x['labels']), 0, 3))) . '</td><td><a href="' . esc_url('https://' . array_key_first($x['pages'])) . '" target="_blank" rel="noopener noreferrer">' . esc_html(array_key_first($x['pages'])) . '</a></td></tr>';
        }
        if (!$oth) echo '<tr><td colspan="4">Noch keine Klicks auf sonstige Seiten.</td></tr>';
        echo '</tbody></table></div>';

        $cut('k'); ob_end_clean();
        $sec['k'] = '<div class="ms-card"><b>Klicks nach:</b> <a href="#" class="button button-primary ms-kvb" data-v="p">Produkt</a> <a href="#" class="button ms-kvb" data-v="s">Seite / Website</a></div><div id="ms-kv-p">' . ($sec['k'] ?? '') . '</div><div id="ms-kv-s" style="display:none">' . self::clicks_by_page($w, !$sel) . '</div><script>(function(){var b=document.querySelectorAll(".ms-kvb");b.forEach(function(x){x.addEventListener("click",function(e){e.preventDefault();var v=x.dataset.v;b.forEach(function(y){y.className="button ms-kvb"+(y===x?" button-primary":"");});document.getElementById("ms-kv-p").style.display=v==="p"?"":"none";document.getElementById("ms-kv-s").style.display=v==="s"?"":"none";});});})();</script>';
        $sec['r'] = self::robot_report($w);
        $tabs = ['u' => 'Übersicht', 'k' => 'Klicks', 'v' => 'Verhalten auf den Seiten', 'p' => 'Pfade', 'r' => 'Roboter'];
        echo '<style>.ms-pane{display:none}.ms-pane.on{display:block}.ms-tabs{padding:8px 12px;position:sticky;top:32px;z-index:5}.ms-tabs a.button{margin-right:6px}</style><div class="ms-card ms-tabs">';
        foreach ($tabs as $tk => $tn) echo '<a href="#ms-t-' . $tk . '" data-t="' . $tk . '" class="button' . ($tk === 'u' ? ' button-primary' : '') . '">' . esc_html($tn) . '</a>';
        echo '</div>';
        foreach ($tabs as $tk => $tn) echo '<div class="ms-pane' . ($tk === 'u' ? ' on' : '') . '" id="ms-t-' . $tk . '">' . ($sec[$tk] ?? '') . '</div>';
        echo '<script>(function(){var a=document.querySelectorAll(".ms-tabs a"),p=document.querySelectorAll(".ms-pane");function show(k){var ok=0;a.forEach(function(x){if(x.dataset.t===k)ok=1;});if(!ok)k="u";a.forEach(function(x){x.className="button"+(x.dataset.t===k?" button-primary":"");});p.forEach(function(x){x.className="ms-pane"+(x.id==="ms-t-"+k?" on":"");});try{localStorage.setItem("ms_tab",k);}catch(e){}}
a.forEach(function(x){x.addEventListener("click",function(e){e.preventDefault();show(x.dataset.t);});});var k="u";try{k=localStorage.getItem("ms_tab")||"u";}catch(e){}show(k);})();</script>';
        echo '<script>(function(){function v(t){t=t.trim();var m=t.match(/^(\\d+) min(?: (\\d+) s)?/);if(m)return m[1]*60+(+m[2]||0);m=t.match(/^(\\d+) s\\b/);if(m)return +m[1];var n=parseFloat(t.replace(/\\./g,"").replace(",","."));return isNaN(n)?t.toLowerCase():n;}
document.querySelectorAll(".ms-tbl").forEach(function(tb){var b=tb.tBodies[0];if(!b||b.rows.length<2)return;tb.querySelectorAll("th").forEach(function(th,i){if(!th.textContent.trim())return;th.style.cursor="pointer";th.title="Zum Sortieren klicken";th.addEventListener("click",function(){var d=th.dataset.d==="a"?-1:1;th.dataset.d=d>0?"a":"d";var r=[].slice.call(b.rows);r.sort(function(x,y){var p=v(x.cells[i]?x.cells[i].textContent:""),q=v(y.cells[i]?y.cells[i].textContent:"");if(typeof p==="number"&&typeof q!=="number")return -1;if(typeof q==="number"&&typeof p!=="number")return 1;return (p<q?-1:p>q?1:0)*d;});r.forEach(function(x){b.appendChild(x);});});});});})();</script>';
        echo '<script>(function(){var s=document.getElementById("ms-plim"),t=document.querySelector("#ms-produkte .ms-tbl");if(!s||!t||!t.tBodies[0])return;var lim=10;try{lim=+localStorage.getItem("ms_plim")||10;}catch(e){}s.value=String(lim);if(!s.value)s.value="10";function ap(){var r=t.tBodies[0].rows,n=+s.value;for(var i=0;i<r.length;i++)r[i].style.display=i<n?"":"none";}
s.onchange=function(){try{localStorage.setItem("ms_plim",s.value);}catch(e){}ap();};t.querySelectorAll("th").forEach(function(th){th.addEventListener("click",function(){setTimeout(ap,0);});});ap();})();</script>';
        echo '<p class="ms-note">' . (self::opts()['keep_days'] > 0 ? 'Einzeldaten werden nach ' . (int)self::opts()['keep_days'] . ' Tagen gelöscht.' : 'Einzeldaten werden unbegrenzt aufbewahrt (einstellbar).') . ' IP-Adressen werden nicht gespeichert. Suchbegriffe: Google Search Console. Klicks werden nur als Zielseite (Domain), Produktnummer, Linktext und Bild-Adresse erfasst, nie mit Partner-IDs oder Query-Strings. Derselbe Browser wird ' . (int)self::opts()['salt_days'] . ' Tage lang wiedererkannt, danach ändert sich die Kennung.</p>';
    }
}
Mini_Statistik::init();
