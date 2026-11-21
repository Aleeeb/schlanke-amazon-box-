<?php
/**
 * Plugin Name: 1AB Speed
 * Description: Schlankes Lazy Load für Bilder, Videos und Iframes (YouTube-Klickvorschau ohne Datenabfrage vor dem Klick) plus Auswertungsseite mit Tempo-Hinweisen.
 * Version: 0.6.2
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: 1AB
 * License: GPL-2.0-or-later
 * Text Domain: 1ab-speed
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'ABSPEED_VER', '0.6.2' );
define( 'ABSPEED_REPO', 'https://github.com/Aleeeb/schlanke-amazon-box-/' );
define( 'ABSPEED_JSON', 'https://raw.githubusercontent.com/Aleeeb/schlanke-amazon-box-/main/update-1ab-speed.json' );

/* Notaus: in wp-config.php  define('ABSPEED_OFF', true);  */
function abspeed_defaults() {
	return array(
		'images'      => 1, // loading=lazy, decoding=async für Bilder
		'skip_first'  => 1, // erstes Bild (LCP) nie verzögern, fetchpriority=high
		'iframes'     => 1, // loading=lazy für Iframes
		'youtube'     => 1, // YouTube/Vimeo als Klickvorschau
		'yt_thumb'    => 0, // Vorschaubild von YouTube laden (Datenschutz: nein)
		'video'       => 1, // <video> preload=none
		'dims'        => 1, // fehlende Breite/Höhe an Bildern aus der Mediathek ergänzen (damit natives Lazy Load greift, kein Layout-Sprung)
		'exclude'     => 'no-lazy skip-lazy',
	);
}
function abspeed_opt( $k ) {
	$o = get_option( 'abspeed_options', array() );
	$d = abspeed_defaults();
	if ( ! is_array( $o ) ) { $o = array(); }
	return array_key_exists( $k, $o ) ? $o[ $k ] : $d[ $k ];
}
function abspeed_active() {
	if ( defined( 'ABSPEED_OFF' ) && ABSPEED_OFF ) { return false; }
	if ( is_admin() || is_feed() || is_embed() || wp_doing_ajax() ) { return false; }
	if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) { return false; }
	return true;
}
function abspeed_a3_active() {
	return defined( 'A3_LAZY_LOAD_VERSION' ) || class_exists( 'A3_Lazy_Load' ) || class_exists( 'A3_Lazy_Load_Global_Settings' );
}

/* ---------- Filter für Inhalte ---------- */
add_filter( 'the_content', 'abspeed_content', 99 );
add_filter( 'widget_text_content', 'abspeed_content', 99 );
add_filter( 'post_thumbnail_html', 'abspeed_content', 99 );

$GLOBALS['abspeed_img_count'] = 0;
$GLOBALS['abspeed_facade']    = false;

function abspeed_excluded( $tag ) {
	$ex = preg_split( '/\s+/', trim( (string) abspeed_opt( 'exclude' ) ) );
	foreach ( $ex as $c ) {
		if ( $c !== '' && preg_match( '/class=["\'][^"\']*\b' . preg_quote( $c, '/' ) . '\b/i', $tag ) ) { return true; }
	}
	return false;
}

function abspeed_content( $html ) {
	if ( ! abspeed_active() || ! is_string( $html ) || $html === '' ) { return $html; }
	if ( abspeed_opt( 'youtube' ) && stripos( $html, '<iframe' ) !== false ) {
		$html = preg_replace_callback( '#<iframe\b[^>]*>.*?</iframe>#is', 'abspeed_iframe', $html );
	}
	if ( abspeed_opt( 'images' ) && stripos( $html, '<img' ) !== false ) {
		$html = preg_replace_callback( '#<img\b[^>]*>#i', 'abspeed_img', $html );
	}
	if ( abspeed_opt( 'video' ) && stripos( $html, '<video' ) !== false ) {
		$html = preg_replace_callback( '#<video\b[^>]*>#i', 'abspeed_video', $html );
	}
	return $html;
}

function abspeed_img( $m ) {
	$tag = $m[0];
	if ( abspeed_excluded( $tag ) ) { return $tag; }
	// a3 Lazy Load / andere Plugins am Werk: nicht anfassen
	if ( preg_match( '/\b(data-src|data-lazy-src|data-lazy)=/i', $tag ) || preg_match( '/class=["\'][^"\']*\b(lazyload|lazyloaded|a3-lazy|lazy-hidden)\b/i', $tag ) ) { return $tag; }
	if ( abspeed_opt( 'dims' ) ) { $tag = abspeed_add_dims( $tag ); }
	$GLOBALS['abspeed_img_count']++;
	$first = ( $GLOBALS['abspeed_img_count'] === 1 && abspeed_opt( 'skip_first' ) );
	if ( $first ) {
		// Hauptbild: sofort laden, hohe Priorität
		$tag = preg_replace( '/\sloading=["\']?lazy["\']?/i', '', $tag );
		if ( stripos( $tag, 'fetchpriority' ) === false ) { $tag = preg_replace( '/<img\b/i', '<img fetchpriority="high"', $tag, 1 ); }
		return $tag;
	}
	if ( abspeed_a3_active() ) { return $tag; }
	if ( stripos( $tag, ' loading=' ) === false ) { $tag = preg_replace( '/<img\b/i', '<img loading="lazy"', $tag, 1 ); }
	if ( stripos( $tag, ' decoding=' ) === false ) { $tag = preg_replace( '/<img\b/i', '<img decoding="async"', $tag, 1 ); }
	return $tag;
}


/** Breite/Höhe aus der Datei lesen (nur Bilder aus dem Uploads-Ordner), 30 Tage zwischengespeichert. */
function abspeed_dims( $src ) {
	static $up = null;
	if ( null === $up ) { $up = wp_upload_dir( null, false ); }
	if ( empty( $up['baseurl'] ) || empty( $up['basedir'] ) ) { return false; }
	$src = html_entity_decode( $src );
	$src = preg_replace( '/^https?:/i', '', strtok( $src, '?#' ) );
	$base = preg_replace( '/^https?:/i', '', $up['baseurl'] );
	if ( strpos( $src, $base . '/' ) !== 0 ) { return false; }
	$rel = rawurldecode( substr( $src, strlen( $base ) ) );
	if ( strpos( $rel, '..' ) !== false || ! preg_match( '/\.(jpe?g|png|gif|webp)$/i', $rel ) ) { return false; }
	$key = 'abspeed_d_' . md5( $rel );
	$c   = get_transient( $key );
	if ( is_array( $c ) ) { return $c; }
	if ( 'none' === $c ) { return false; }
	$file = $up['basedir'] . $rel;
	$size = is_readable( $file ) ? @getimagesize( $file ) : false;
	if ( $size && $size[0] > 0 && $size[1] > 0 ) {
		$d = array( (int) $size[0], (int) $size[1] );
		set_transient( $key, $d, 30 * DAY_IN_SECONDS );
		return $d;
	}
	set_transient( $key, 'none', DAY_IN_SECONDS );
	return false;
}

function abspeed_add_dims( $tag ) {
	$hasw = preg_match( '/\swidth=["\']?(\d+)/i', $tag, $wm );
	$hash = preg_match( '/\sheight=["\']?(\d+)/i', $tag, $hm );
	if ( $hasw && $hash ) { return $tag; }
	if ( ! preg_match( '/\ssrc=["\']([^"\']+)["\']/i', $tag, $sm ) ) { return $tag; }
	$d = abspeed_dims( $sm[1] );
	if ( ! $d ) { return $tag; }
	if ( $hasw && ! $hash ) {
		$h = (int) round( (int) $wm[1] * $d[1] / $d[0] );
		return $h > 0 ? preg_replace( '/<img\b/i', '<img height="' . $h . '"', $tag, 1 ) : $tag;
	}
	if ( $hash && ! $hasw ) {
		$w = (int) round( (int) $hm[1] * $d[0] / $d[1] );
		return $w > 0 ? preg_replace( '/<img\b/i', '<img width="' . $w . '"', $tag, 1 ) : $tag;
	}
	return preg_replace( '/<img\b/i', '<img width="' . $d[0] . '" height="' . $d[1] . '"', $tag, 1 );
}

function abspeed_video( $m ) {
	$tag = $m[0];
	if ( abspeed_excluded( $tag ) || preg_match( '/\bautoplay\b/i', $tag ) ) { return $tag; }
	if ( preg_match( '/\spreload=/i', $tag ) ) { return preg_replace( '/\spreload=["\']?\w+["\']?/i', ' preload="none"', $tag ); }
	return preg_replace( '/<video\b/i', '<video preload="none"', $tag, 1 );
}

function abspeed_iframe( $m ) {
	$tag = $m[0];
	if ( abspeed_excluded( $tag ) ) { return $tag; }
	if ( ! preg_match( '/\ssrc=["\']([^"\']+)["\']/i', $tag, $s ) ) { return $tag; }
	$src = html_entity_decode( $s[1] );
	if ( strpos( $src, '//' ) === 0 ) { $src = 'https:' . $src; }
	$id = '';
	$provider = '';
	if ( preg_match( '#^https://(?:www\.)?(?:youtube\.com|youtube-nocookie\.com)/embed/([A-Za-z0-9_-]{6,15})#', $src, $y ) ) { $id = $y[1]; $provider = 'yt'; }
	elseif ( preg_match( '#^https://player\.vimeo\.com/video/(\d+)#', $src, $y ) ) { $id = $y[1]; $provider = 'vimeo'; }
	if ( $provider === '' ) {
		// andere Iframes: nur lazy
		if ( ! abspeed_opt( 'iframes' ) ) { return $tag; }
		// Andere Iframes (z. B. xeno-canto, Karten, Wikimedia): erst laden, wenn sie fast sichtbar sind (Beobachter + Rückfall noscript)
		if ( preg_match( '#^https?://#i', $src ) && stripos( $tag, 'srcdoc=' ) === false ) {
			$GLOBALS['abspeed_lziframe'] = true;
			$lazy = preg_replace( '/\ssrc=(["\'])[^"\']+\1/i', ' src="about:blank" data-abs-src="' . esc_attr( $src ) . '"', $tag, 1 );
			if ( stripos( $lazy, ' loading=' ) === false ) { $lazy = preg_replace( '/<iframe\b/i', '<iframe loading="lazy"', $lazy, 1 ); }
			return $lazy . '<noscript>' . $tag . '</noscript>';
		}
		if ( stripos( $tag, ' loading=' ) === false ) { return preg_replace( '/<iframe\b/i', '<iframe loading="lazy"', $tag, 1 ); }
		return $tag;
	}
	$w = 16; $h = 9;
	if ( preg_match( '/\swidth=["\']?(\d+)/i', $tag, $a ) && preg_match( '/\sheight=["\']?(\d+)/i', $tag, $b ) && $a[1] > 0 && $b[1] > 0 ) { $w = (int) $a[1]; $h = (int) $b[1]; }
	$title = 'Video abspielen';
	if ( preg_match( '/\stitle=["\']([^"\']*)["\']/i', $tag, $t ) && $t[1] !== '' ) { $title = $t[1]; }
	if ( $provider === 'yt' ) {
		$embed = 'https://www.youtube-nocookie.com/embed/' . $id . '?autoplay=1&rel=0';
		if ( preg_match( '/[?&](start|list)=([^&"\']+)/', $src, $q ) ) { $embed .= '&' . $q[1] . '=' . $q[2]; }
	} else {
		$embed = 'https://player.vimeo.com/video/' . $id . '?autoplay=1&dnt=1';
	}
	$bg = '';
	if ( $provider === 'yt' && abspeed_opt( 'yt_thumb' ) ) { $bg = ' style="background-image:url(https://i.ytimg.com/vi/' . esc_attr( $id ) . '/hqdefault.jpg)"'; }
	$GLOBALS['abspeed_facade'] = true;
	return '<div class="abspeed-fc" data-embed="' . esc_attr( $embed ) . '" data-title="' . esc_attr( $title ) . '" style="aspect-ratio:' . $w . '/' . $h . '"' . $bg . '>'
		. '<button type="button" class="abspeed-play" aria-label="' . esc_attr( $title ) . ' (Video abspielen)"><svg viewBox="0 0 68 48" width="68" height="48" aria-hidden="true"><path d="M66.5 7.7a8 8 0 0 0-5.6-5.7C56 .7 34 .7 34 .7s-22 0-26.900 1.300A8 8 0 0 0 1.500 7.700C.2 12.600.2 24 .2 24s0 11.400 1.300 16.300a8 8 0 0 0 5.600 5.700C12 47.300 34 47.300 34 47.300s22 0 26.900-1.300a8 8 0 0 0 5.600-5.700C67.800 35.400 67.800 24 67.800 24s0-11.400-1.300-16.300z" fill="#212121" fill-opacity=".85"/><path d="M45 24 27 14v20z" fill="#fff"/></svg></button>'
		. '<noscript><a href="' . esc_url( $embed ) . '">' . esc_html( $title ) . '</a></noscript></div>';
}

/* Facade-CSS + Klick-Skript nur wenn nötig (klein, inline) */
add_action( 'wp_footer', 'abspeed_footer', 99 );
function abspeed_footer() {
	if ( ! empty( $GLOBALS['abspeed_lziframe'] ) ) {
		echo '<script>(function(){var l=document.querySelectorAll("iframe[data-abs-src]");function go(f){var s=f.getAttribute("data-abs-src");if(s){f.removeAttribute("data-abs-src");f.src=s;}}if(!("IntersectionObserver" in window)){for(var i=0;i<l.length;i++)go(l[i]);return;}var o=new IntersectionObserver(function(e){for(var i=0;i<e.length;i++){if(e[i].isIntersecting){o.unobserve(e[i].target);go(e[i].target);}}},{rootMargin:"400px 0px"});for(var j=0;j<l.length;j++)o.observe(l[j]);})();</script>';
	}
	if ( empty( $GLOBALS['abspeed_facade'] ) ) { return; }
	echo '<style>.abspeed-fc{position:relative;width:100%;max-width:100%;background:#111 center/cover no-repeat;cursor:pointer;overflow:hidden;border-radius:6px}.abspeed-fc iframe{position:absolute;inset:0;width:100%;height:100%;border:0}.abspeed-play{position:absolute;inset:0;margin:auto;width:68px;height:48px;border:0;padding:0;background:none;cursor:pointer}.abspeed-fc:hover path:first-child{fill:#f00;fill-opacity:1}</style>';
	echo '<script>(function(){function go(b){var e=b.closest(".abspeed-fc");if(!e||e.querySelector("iframe"))return;var f=document.createElement("iframe");f.src=e.getAttribute("data-embed");f.title=e.getAttribute("data-title")||"Video";f.allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture;fullscreen";f.setAttribute("allowfullscreen","");e.appendChild(f);var p=e.querySelector(".abspeed-play");if(p)p.remove();}document.addEventListener("click",function(ev){var t=ev.target.closest&&ev.target.closest(".abspeed-fc");if(t)go(t);});})();</script>';
}

/* ---------- Admin ---------- */
add_action( 'admin_menu', function () {
	add_menu_page( '1AB Speed', '1AB Speed', 'manage_options', 'abspeed', 'abspeed_page', 'dashicons-performance', 81 );
} );
add_action( 'admin_init', function () {
	register_setting( 'abspeed', 'abspeed_options', array( 'sanitize_callback' => 'abspeed_sanitize' ) );
} );
function abspeed_sanitize( $in ) {
	$out = array();
	foreach ( array( 'images', 'skip_first', 'iframes', 'youtube', 'yt_thumb', 'video', 'dims' ) as $k ) { $out[ $k ] = empty( $in[ $k ] ) ? 0 : 1; }
	$out['exclude'] = isset( $in['exclude'] ) ? sanitize_text_field( $in['exclude'] ) : '';
	delete_transient( 'abspeed_report' );
	return $out;
}

function abspeed_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$tab = 'settings';
	if ( isset( $_GET['tab'] ) && in_array( $_GET['tab'], array( 'report', 'updates' ), true ) ) { $tab = $_GET['tab']; }
	if ( ! empty( $_GET['abspeed_update_now'] ) && check_admin_referer( 'abspeed_update_now' ) ) { abspeed_update_check( true ); }
	if ( ! empty( $_POST['abspeed_upd_save'] ) && check_admin_referer( 'abspeed_upd_save' ) ) {
		update_option( 'abspeed_auto', empty( $_POST['abspeed_auto'] ) ? 0 : 1, false );
		$mo = isset( $_POST['abspeed_upd_mode'] ) ? sanitize_key( $_POST['abspeed_upd_mode'] ) : 'adaptive';
		update_option( 'abspeed_upd_mode', in_array( $mo, array( 'adaptive', 'hourly', 'daily', 'off' ), true ) ? $mo : 'adaptive', false );
		update_option( 'abspeed_upd_max_h', max( 1, min( 168, isset( $_POST['abspeed_upd_max_h'] ) ? (int) $_POST['abspeed_upd_max_h'] : 12 ) ), false );
		update_option( 'abspeed_upd', array( 'iv' => 600, 'seen' => ABSPEED_VER, 'fails' => 0 ), false );
		wp_clear_scheduled_hook( 'abspeed_update_cron' );
		if ( $mo !== 'off' ) { wp_schedule_single_event( time() + 60, 'abspeed_update_cron' ); }
	}
	echo '<div class="wrap"><h1>1AB Speed <small>v' . esc_html( ABSPEED_VER ) . '</small></h1><h2 class="nav-tab-wrapper">';
	echo '<a class="nav-tab ' . ( $tab === 'settings' ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=abspeed' ) ) . '">Einstellungen</a>';
	echo '<a class="nav-tab ' . ( $tab === 'report' ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=abspeed&tab=report' ) ) . '">Auswertung</a>';
	echo '<a class="nav-tab ' . ( $tab === 'updates' ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=abspeed&tab=updates' ) ) . '">Updates</a></h2>';
	if ( defined( 'ABSPEED_OFF' ) && ABSPEED_OFF ) { echo '<div class="notice notice-warning"><p>Notaus aktiv (ABSPEED_OFF in wp-config.php). Das Plugin verändert nichts.</p></div>'; }
	if ( $tab === 'report' ) { abspeed_report_page(); } elseif ( $tab === 'updates' ) { abspeed_updates_page(); } else { abspeed_settings_page(); }
	echo '</div>';
}

function abspeed_settings_page() {
	$rows = array(
		'images'     => 'Bilder: verzögert laden (loading=lazy)',
		'skip_first' => 'Erstes Bild im Beitrag sofort laden (schnellere Hauptbild-Anzeige)',
		'iframes'    => 'Andere Iframes (Karten, Audio, …) verzögert laden',
		'youtube'    => 'YouTube/Vimeo erst nach Klick laden (youtube-nocookie, spart viel Ladezeit)',
		'yt_thumb'   => 'YouTube-Vorschaubild laden (schöner, aber verbindet beim Seitenaufruf mit Google)',
		'video'      => 'Eigene Videos: erst beim Abspielen laden (preload=none)',
		'dims'       => 'Fehlende Breite/Höhe an Mediathek-Bildern ergänzen (damit Lazy Load sauber greift, weniger Layout-Sprung)',
	);
	echo '<form method="post" action="options.php">';
	settings_fields( 'abspeed' );
	echo '<table class="form-table">';
	foreach ( $rows as $k => $label ) {
		echo '<tr><th>' . esc_html( $label ) . '</th><td><input type="checkbox" name="abspeed_options[' . esc_attr( $k ) . ']" value="1" ' . checked( 1, (int) abspeed_opt( $k ), false ) . '></td></tr>';
	}
	echo '<tr><th>Ausschluss-Klassen (Leerzeichen getrennt)</th><td><input type="text" class="regular-text" name="abspeed_options[exclude]" value="' . esc_attr( abspeed_opt( 'exclude' ) ) . '"></td></tr>';
	echo '</table>';
	submit_button();
	echo '</form>';
	if ( abspeed_a3_active() ) { echo '<p><strong>Hinweis:</strong> a3 Lazy Load ist aktiv. Bilder überlässt das Plugin a3; YouTube-Klickvorschau und Hauptbild-Priorität arbeiten zusätzlich.</p>'; }
	echo '<p>Notaus: <code>define(\'ABSPEED_OFF\', true);</code> in wp-config.php.</p>';
}

/* ---------- Auswertung ---------- */
function abspeed_check( $label, $status, $value, $tip = '' ) {
	return array( 'label' => $label, 'status' => $status, 'value' => $value, 'tip' => $tip );
}

function abspeed_build_report() {
	global $wpdb;
	$r = array();
	$php = PHP_VERSION;
	$r[] = abspeed_check( 'PHP-Version', version_compare( $php, '8.2', '>=' ) ? 'ok' : ( version_compare( $php, '8.0', '>=' ) ? 'warn' : 'bad' ), $php, version_compare( $php, '8.2', '>=' ) ? '' : 'Im Hoster-Panel auf PHP 8.2/8.3 stellen (schneller und sicherer).' );
	$op = function_exists( 'opcache_get_status' ) && ini_get( 'opcache.enable' );
	$r[] = abspeed_check( 'OPcache', $op ? 'ok' : 'warn', $op ? 'an' : 'aus/unbekannt', $op ? '' : 'OPcache beim Hoster aktivieren lassen.' );
	$mem = ini_get( 'memory_limit' );
	$r[] = abspeed_check( 'PHP-Speicherlimit', 'info', $mem );
	$oc = wp_using_ext_object_cache();
	$r[] = abspeed_check( 'Objekt-Cache (Redis/Memcached)', $oc ? 'ok' : 'info', $oc ? 'aktiv' : 'nicht aktiv', $oc ? '' : 'Optional; bringt vor allem bei vielen Datenbankabfragen im Backend etwas.' );
	$act = (array) get_option( 'active_plugins', array() );
	$n = count( $act );
	$r[] = abspeed_check( 'Aktive Plugins', $n <= 15 ? 'ok' : ( $n <= 25 ? 'warn' : 'bad' ), (string) $n, $n > 15 ? 'Nicht genutzte Plugins löschen.' : '' );
	// Revisionen, Transients, Autoload
	$rev = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='revision'" );
	$r[] = abspeed_check( 'Beitrags-Revisionen', $rev < 500 ? 'ok' : 'warn', (string) $rev, $rev >= 500 ? 'Alte Revisionen löschen (Datenbank aufräumen).' : '' );
	$tr = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%'" );
	$r[] = abspeed_check( 'Transients in der Datenbank', $tr < 500 ? 'ok' : 'warn', (string) $tr, $tr >= 500 ? 'Abgelaufene Transients entfernen.' : '' );
	$al = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')" );
	$r[] = abspeed_check( 'Autoload-Optionen (bei jedem Aufruf geladen)', $al < 1000000 ? 'ok' : ( $al < 3000000 ? 'warn' : 'bad' ), size_format( $al ), $al >= 1000000 ? 'Große Autoload-Einträge alter Plugins prüfen/löschen.' : '' );
	// Konkurrenz-Plugins
	$seen = array();
	foreach ( $act as $p ) {
		foreach ( array( 'a3-lazy-load' => 'a3 Lazy Load', 'sg-cachepress' => 'SiteGround Optimizer', 'wp-rocket' => 'WP Rocket', 'autoptimize' => 'Autoptimize', 'w3-total-cache' => 'W3 Total Cache', 'wp-super-cache' => 'WP Super Cache', 'litespeed-cache' => 'LiteSpeed Cache', 'contextual-related-posts' => 'Contextual Related Posts', 'aawp' => 'AAWP', 'easyazon' => 'EasyAzon' ) as $slug => $name ) {
			if ( strpos( $p, $slug ) === 0 ) { $seen[] = $name; }
		}
	}
	if ( $seen ) { $r[] = abspeed_check( 'Plugins mit Tempo-Einfluss', 'info', implode( ', ', $seen ), 'SiteGround Optimizer nach Umzug zu DreamHost entfernen; Contextual Related Posts belastet die Datenbank.' ); }
	// Startseite abrufen
	$t0 = microtime( true );
	$res = wp_remote_get( home_url( '/' ), array( 'timeout' => 15, 'redirection' => 3, 'headers' => array( 'Accept-Encoding' => 'gzip, br', 'Cache-Control' => 'no-cache' ), 'user-agent' => '1AB-Speed-Report' ) );
	$dt = round( ( microtime( true ) - $t0 ) * 1000 );
	if ( is_wp_error( $res ) ) {
		$r[] = abspeed_check( 'Startseite abrufen', 'warn', $res->get_error_message() );
		return $r;
	}
	$body = wp_remote_retrieve_body( $res );
	$h    = wp_remote_retrieve_headers( $res );
	$r[] = abspeed_check( 'Startseite: Abrufzeit vom Server selbst', $dt < 800 ? 'ok' : ( $dt < 2000 ? 'warn' : 'bad' ), $dt . ' ms', $dt >= 800 ? 'Seiten-Cache/Cloudflare-Cache prüfen; Besucher sehen meist die Cache-Version.' : '' );
	$size = strlen( $body );
	$r[] = abspeed_check( 'Startseite: HTML-Größe', $size < 150000 ? 'ok' : 'warn', size_format( $size ) );
	$enc = isset( $h['content-encoding'] ) ? $h['content-encoding'] : '';
	$r[] = abspeed_check( 'Komprimierung (gzip/br)', $enc ? 'ok' : 'warn', $enc ? $enc : 'keine erkannt', $enc ? '' : 'Komprimierung beim Hoster/Cloudflare einschalten.' );
	$cf = isset( $h['cf-cache-status'] ) ? $h['cf-cache-status'] : '';
	$srv = isset( $h['server'] ) ? $h['server'] : '';
	$r[] = abspeed_check( 'Cloudflare-Cache', $cf ? ( stripos( $cf, 'HIT' ) !== false ? 'ok' : 'info' ) : 'warn', $cf ? $cf : 'Kein Cloudflare-Header (Abruf kam evtl. direkt)', stripos( (string) $cf, 'HIT' ) === false ? 'Cloudflare: Cache-Regel „Alles cachen“ für Besucher ohne Login, lange Cache-Dauer.' : '' );
	$cc = isset( $h['cache-control'] ) ? $h['cache-control'] : '';
	$r[] = abspeed_check( 'Cache-Control der Startseite', 'info', $cc ? $cc : 'leer' );
	if ( isset( $h['sg-f-cache'] ) || isset( $h['x-sg-cache'] ) ) { $r[] = abspeed_check( 'SiteGround-Cache-Header', 'warn', 'gefunden', 'Reste des SiteGround Optimizers entfernen.' ); }
	$r[] = abspeed_check( 'Server', 'info', $srv ? $srv : '–' );
	$css = preg_match_all( '#<link[^>]+rel=["\']stylesheet["\'][^>]*>#i', $body );
	$js  = preg_match_all( '#<script\b[^>]*\ssrc=#i', $body );
	$r[] = abspeed_check( 'Stylesheets (extern geladen)', $css <= 8 ? 'ok' : 'warn', (string) $css, $css > 8 ? 'Zusammenfassen/Plugins entfernen, die eigene CSS laden.' : '' );
	$r[] = abspeed_check( 'Skripte (extern geladen)', $js <= 10 ? 'ok' : 'warn', (string) $js, $js > 10 ? 'Nicht benötigte Skripte/Plugins entfernen, Rest mit defer laden.' : '' );
	$ext = array();
	if ( preg_match_all( '#(?:<(?:script|img|iframe|source|video|audio)\b[^>]*\ssrc|<link\b[^>]*\shref)=["\'](https?://([^/"\']+)[^"\']*)["\']#i', $body, $mm ) ) {
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( $mm[2] as $d ) { if ( strcasecmp( $d, $home ) !== 0 && stripos( $d, preg_replace( '/^www\./', '', $home ) ) === false ) { $ext[ strtolower( $d ) ] = 1; } }
	}
	$r[] = abspeed_check( 'Fremde Domains (geladene Dateien, ohne Links)', count( $ext ) <= 5 ? 'ok' : 'warn', (string) count( $ext ) . ( $ext ? ': ' . implode( ', ', array_slice( array_keys( $ext ), 0, 8 ) ) : '' ), count( $ext ) > 5 ? 'Jede fremde Domain kostet Verbindungszeit. Unnötiges entfernen.' : '' );
	$fonts = preg_match_all( '#fonts\.googleapis\.com|fonts\.gstatic\.com#i', $body ) + preg_match_all( '#\.(woff2?|ttf)\b#i', $body );
	$r[] = abspeed_check( 'Schrift-Verweise (Fonts)', $fonts === 0 ? 'ok' : 'info', (string) $fonts, preg_match( '#fonts\.googleapis\.com#i', $body ) ? 'Google Fonts lokal hosten (schneller, besser für Datenschutz).' : '' );
	$ga = preg_match( '#googletagmanager\.com|google-analytics\.com#i', $body );
	$r[] = abspeed_check( 'Google Analytics/Tag Manager', $ga ? 'warn' : 'ok', $ga ? 'geladen' : 'nicht gefunden', $ga ? 'Abschalten, sobald die eigene Mini-Statistik reicht.' : '' );
	$imgs = preg_match_all( '#<img\b[^>]*>#i', $body, $im );
	$nolazy = 0; $nodim = 0;
	foreach ( $im[0] as $i => $t ) { if ( $i > 0 && stripos( $t, 'loading=' ) === false && stripos( $t, 'data-src' ) === false ) { $nolazy++; } if ( ! preg_match( '/\swidth=/i', $t ) || ! preg_match( '/\sheight=/i', $t ) ) { $nodim++; } }
	$r[] = abspeed_check( 'Bilder auf der Startseite', 'info', (string) $imgs );
	$r[] = abspeed_check( 'Bilder ohne verzögertes Laden (ab 2. Bild)', $nolazy === 0 ? 'ok' : 'warn', (string) $nolazy, $nolazy ? 'Lazy Load für Bilder aktivieren (Einstellungen).' : '' );
	$r[] = abspeed_check( 'Bilder ohne Breite/Höhe (Layout-Sprung)', $nodim === 0 ? 'ok' : 'warn', (string) $nodim, $nodim ? 'Breite und Höhe an Bildern ergänzen (verbessert CLS).' : '' );
	$ifr = preg_match_all( '#<iframe\b#i', $body );
	$r[] = abspeed_check( 'Iframes mit sofortigem Laden (Startseite)', $ifr === 0 ? 'ok' : 'warn', (string) $ifr, $ifr ? 'Iframes/Videos per Klickvorschau laden (Einstellungen).' : '' );
	$r[] = abspeed_check( 'HTTP-Version / Protokoll', 'info', isset( $h['alt-svc'] ) ? 'HTTP/3 angeboten' : 'keine Angabe' );
	return $r;
}

function abspeed_report_page() {
	$r = get_transient( 'abspeed_report' );
	if ( isset( $_GET['refresh'] ) && check_admin_referer( 'abspeed_refresh' ) ) { $r = false; }
	if ( ! $r ) { $r = abspeed_build_report(); set_transient( 'abspeed_report', $r, 1800 ); }
	echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=abspeed&tab=report&refresh=1' ), 'abspeed_refresh' ) ) . '">Neu messen</a> <span class="description">Messung vom Server selbst; echte Besucherwerte stehen in der Statistik.</span></p>';
	$ic = array( 'ok' => '🟢', 'warn' => '🟠', 'bad' => '🔴', 'info' => '⚪' );
	echo '<table class="widefat striped"><thead><tr><th></th><th>Punkt</th><th>Wert</th><th>Verbesserungsvorschlag</th></tr></thead><tbody>';
	foreach ( $r as $c ) {
		echo '<tr><td>' . $ic[ $c['status'] ] . '</td><td>' . esc_html( $c['label'] ) . '</td><td>' . esc_html( $c['value'] ) . '</td><td>' . esc_html( $c['tip'] ) . '</td></tr>';
	}
	echo '</tbody></table>';
}


/* ---------- Updates über GitHub (wie bei 1AB Affiliate Help) ---------- */
add_filter( 'pre_set_site_transient_update_plugins', 'abspeed_update_filter' );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $l ) {
	$l[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=abspeed&tab=updates&abspeed_update_now=1' ), 'abspeed_update_now' ) ) . '">Nach Update suchen</a>';
	return $l;
} );
add_action( 'abspeed_update_cron', 'abspeed_update_cron' );
add_action( 'admin_init', function () {
	if ( ! current_user_can( 'update_plugins' ) ) { return; }
	if ( get_option( 'abspeed_upd_mode', 'adaptive' ) !== 'off' && ! wp_next_scheduled( 'abspeed_update_cron' ) ) { wp_schedule_single_event( time() + 120, 'abspeed_update_cron' ); }
	abspeed_update_verify();
	// Frisch installiert: Eintrag bei WordPress anlegen, damit der Schalter „Automatische Updates“ sofort erscheint
	$key = plugin_basename( __FILE__ );
	$t   = get_site_transient( 'update_plugins' );
	if ( is_object( $t ) && ! isset( $t->no_update[ $key ] ) && ! isset( $t->response[ $key ] ) && time() > (int) get_option( 'abspeed_try', 0 ) ) {
		update_option( 'abspeed_try', time() + 3600, false );
		abspeed_update_check();
	}
} );
register_activation_hook( __FILE__, function () { abspeed_update_check(); } );

function abspeed_update_json( $force = false ) {
	$m = $force ? false : get_transient( 'abspeed_update_json' );
	if ( $m === false ) {
		$r    = wp_remote_get( ABSPEED_JSON . '?t=' . time(), array( 'timeout' => 8 ) );
		$code = is_wp_error( $r ) ? $r->get_error_message() : wp_remote_retrieve_response_code( $r );
		$m    = ( ! is_wp_error( $r ) && 200 === $code ) ? json_decode( (string) wp_remote_retrieve_body( $r ), true ) : array();
		if ( ! is_array( $m ) ) { $m = array(); }
		update_option( 'abspeed_update_status', array( 'ts' => time(), 'code' => (string) $code, 'version' => isset( $m['version'] ) ? (string) $m['version'] : '' ), false );
		set_transient( 'abspeed_update_json', $m, $m ? 3600 : 300 );
	}
	return $m;
}

function abspeed_update_filter( $t ) {
	if ( ! is_object( $t ) || empty( $t->checked ) ) { return $t; }
	if ( isset( $_GET['force-check'] ) ) { delete_transient( 'abspeed_update_json' ); }
	$m   = abspeed_update_json();
	$v   = isset( $m['version'] ) ? (string) $m['version'] : '';
	$zip = isset( $m['zip'] ) ? (string) $m['zip'] : '';
	if ( $v === '' || strpos( $zip, ABSPEED_REPO ) !== 0 ) { return $t; }
	$key = plugin_basename( __FILE__ );
	$e   = (object) array( 'id' => 'github.com/Aleeeb/schlanke-amazon-box-/1ab-speed', 'slug' => dirname( $key ), 'plugin' => $key, 'new_version' => $v, 'package' => $zip, 'url' => ABSPEED_REPO );
	if ( ! isset( $t->response ) || ! is_array( $t->response ) ) { $t->response = array(); }
	if ( ! isset( $t->no_update ) || ! is_array( $t->no_update ) ) { $t->no_update = array(); }
	if ( version_compare( $v, ABSPEED_VER, '<=' ) ) { $t->no_update[ $key ] = $e; unset( $t->response[ $key ] ); } else { $t->response[ $key ] = $e; }
	return $t;
}

/** Sofort bei GitHub nachsehen und WordPress die neue Version melden. Gibt neue Version oder '' zurück. */
function abspeed_update_check( $force = false ) {
	delete_transient( 'abspeed_update_json' );
	$key = plugin_basename( __FILE__ );
	$t   = get_site_transient( 'update_plugins' );
	if ( ! is_object( $t ) ) { $t = new stdClass(); }
	if ( empty( $t->checked ) || ! is_array( $t->checked ) ) { $t->checked = array(); }
	if ( ! isset( $t->checked[ $key ] ) ) { $t->checked[ $key ] = ABSPEED_VER; }
	if ( empty( $t->last_checked ) ) { $t->last_checked = time(); }
	$t = abspeed_update_filter( $t );
	set_site_transient( 'update_plugins', $t );
	return isset( $t->response[ $key ] ) ? (string) $t->response[ $key ]->new_version : '';
}

/* Sperre: Nicht mitten in Sicherung/Wiederherstellung aktualisieren */
function abspeed_busy() { return function_exists( 'abbk_busy' ) && abbk_busy(); }

/* Nächster Abstand: nach einer Veröffentlichung zuerst alle 10 Min., dann jeweils doppelt so lang bis zum Höchstwert (Standard 12 Std.), bis wieder eine neue Version kommt. */
function abspeed_next_interval( $found_new ) {
	$mode = get_option( 'abspeed_upd_mode', 'adaptive' );
	if ( $mode === 'off' ) { return 0; }
	if ( $mode === 'hourly' ) { return 3600; }
	if ( $mode === 'daily' ) { return 86400; }
	$st  = (array) get_option( 'abspeed_upd', array() );
	$max = max( 1, min( 168, (int) get_option( 'abspeed_upd_max_h', 12 ) ) ) * 3600;
	$iv  = isset( $st['iv'] ) ? (int) $st['iv'] : $max;
	$seen = isset( $st['seen'] ) ? (string) $st['seen'] : '';
	if ( $found_new || $seen !== ABSPEED_VER ) { $iv = 600; } else { $iv = min( $max, max( 600, $iv * 2 ) ); }
	$st['iv'] = $iv; $st['seen'] = ABSPEED_VER;
	update_option( 'abspeed_upd', $st, false );
	return $iv;
}

/* Nach dem Neuladen mit neuer Version: Ergebnis des letzten Installationsversuchs festhalten */
function abspeed_update_verify() {
	$l = (array) get_option( 'abspeed_upd_log', array() );
	if ( ! empty( $l['to'] ) && empty( $l['done'] ) && version_compare( ABSPEED_VER, $l['to'], '>=' ) ) {
		$l['done'] = time(); $l['ok'] = true; update_option( 'abspeed_upd_log', $l, false );
		$st = (array) get_option( 'abspeed_upd', array() ); $st['fails'] = 0; update_option( 'abspeed_upd', $st, false );
	}
}

function abspeed_update_cron() {
	if ( abspeed_busy() ) { wp_schedule_single_event( time() + 900, 'abspeed_update_cron' ); return; }
	$neu = abspeed_update_check( true );
	$key = plugin_basename( __FILE__ );
	$st  = (array) get_option( 'abspeed_upd', array() );
	$fails = isset( $st['fails'] ) ? (int) $st['fails'] : 0;
	$lg = (array) get_option( 'abspeed_upd_log', array() );
	if ( $neu !== '' && ( empty( $lg['to'] ) || $lg['to'] !== $neu ) ) { $fails = 0; } // andere (neuere) Version: wieder versuchen
	$auto = get_option( 'abspeed_auto', 1 ) || in_array( $key, (array) get_site_option( 'auto_update_plugins', array() ), true );
	if ( $neu !== '' && $auto && $fails < 3 && function_exists( 'wp_maybe_auto_update' ) ) {
		update_option( 'abspeed_upd_log', array( 'from' => ABSPEED_VER, 'to' => $neu, 'start' => time(), 'done' => 0, 'ok' => false ), false );
		$st['fails'] = $fails + 1; update_option( 'abspeed_upd', $st, false ); // wird bei Erfolg nach dem Neuladen auf 0 gesetzt
		$only = function ( $u, $i ) use ( $key ) { return is_object( $i ) && isset( $i->plugin ) && (string) $i->plugin === $key ? true : $u; };
		add_filter( 'auto_update_plugin', $only, 99999, 2 );
		wp_maybe_auto_update();
		remove_filter( 'auto_update_plugin', $only, 99999 );
	}
	$iv = abspeed_next_interval( $neu !== '' );
	if ( $iv ) { wp_schedule_single_event( time() + $iv, 'abspeed_update_cron' ); }
}

function abspeed_updates_page() {
	$key  = plugin_basename( __FILE__ );
	$st   = (array) get_option( 'abspeed_update_status', array() );
	$t    = get_site_transient( 'update_plugins' );
	$neu  = ( is_object( $t ) && isset( $t->response[ $key ] ) ) ? $t->response[ $key ]->new_version : '';
	$auto = in_array( $key, (array) get_site_option( 'auto_update_plugins', array() ), true );
	echo '<p>Installiert: <strong>' . esc_html( ABSPEED_VER ) . '</strong></p>';
	if ( $neu ) {
		echo '<p><strong>Neue Version ' . esc_html( $neu ) . ' verfügbar.</strong> <a class="button button-primary" href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Jetzt unter Plugins aktualisieren</a></p>';
	} elseif ( ! empty( $st['ts'] ) ) {
		echo '<p>Aktuell (neueste Version bei GitHub: ' . esc_html( $st['version'] ? $st['version'] : 'unbekannt' ) . ').</p>';
	}
	echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=abspeed&tab=updates&abspeed_update_now=1' ), 'abspeed_update_now' ) ) . '">Nach Update suchen</a></p>';
	if ( ! empty( $st['ts'] ) ) {
		echo '<p class="description">Letzte Abfrage bei GitHub vor ' . max( 0, (int) round( ( time() - (int) $st['ts'] ) / 60 ) ) . ' Min (Antwort: ' . esc_html( $st['code'] ) . ').</p>';
	}
	$mode = get_option( 'abspeed_upd_mode', 'adaptive' ); $auto2 = (bool) get_option( 'abspeed_auto', 1 ); $mx = (int) get_option( 'abspeed_upd_max_h', 12 );
	$l = (array) get_option( 'abspeed_upd_log', array() ); $nx = wp_next_scheduled( 'abspeed_update_cron' );
	echo '<h3>Selbst suchen und installieren</h3><form method="post">';
	wp_nonce_field( 'abspeed_upd_save' );
	echo '<p><label><input type="checkbox" name="abspeed_auto" value="1"' . ( $auto2 ? ' checked' : '' ) . '> Neue Versionen selbst installieren (nicht während einer Sicherung)</label></p>';
	echo '<p>Prüfen: <select name="abspeed_upd_mode">';
	foreach ( array( 'adaptive' => 'nach einer neuen Version oft, dann immer seltener', 'hourly' => 'stündlich', 'daily' => 'täglich', 'off' => 'gar nicht' ) as $k => $t ) { echo '<option value="' . esc_attr( $k ) . '"' . selected( $mode, $k, false ) . '>' . esc_html( $t ) . '</option>'; }
	echo '</select> · höchster Abstand bei „immer seltener“: <input type="number" name="abspeed_upd_max_h" min="1" max="168" value="' . (int) $mx . '" style="width:60px"> Std. <span class="description">Ablauf: zuerst alle 10 Min., dann 20, 40 … bis zum höchsten Abstand, bis wieder eine neue Version kommt.</span></p>';
	echo '<p><button class="button button-primary" name="abspeed_upd_save" value="1">Speichern</button></p></form>';
	echo '<p class="description">' . ( $nx ? 'Nächste Prüfung: ' . esc_html( wp_date( 'd.m.Y H:i', $nx ) ) . '. ' : 'Keine Prüfung geplant. ' ) . ( ! empty( $l['to'] ) ? 'Letzter Installationsversuch: ' . esc_html( $l['from'] ) . ' → ' . esc_html( $l['to'] ) . ' am ' . esc_html( wp_date( 'd.m.Y H:i', (int) $l['start'] ) ) . ( ! empty( $l['ok'] ) ? ' – geklappt.' : ' – noch nicht bestätigt (nach spätestens 3 Fehlversuchen gibt das Plugin auf; dann unter Plugins von Hand aktualisieren oder per FTP).' ) : '' ) . '</p>';
}


/* Eigene Prüfsummen-Kontrolle: Läuft vor der von 1AB Affiliate Help (die sonst jedes ZIP aus diesem Repo gegen ihre eigene Summe prüft). */
add_filter( 'upgrader_pre_download', 'abspeed_pre_download', 5, 3 );
function abspeed_pre_download( $reply, $package ) {
	if ( false !== $reply || ! is_string( $package ) || strpos( $package, ABSPEED_REPO ) !== 0 || ! preg_match( '#/1ab-speed[^/]*\.zip$#', $package ) ) { return $reply; }
	$m = abspeed_update_json( true ); // immer frisch: Summe muss zum aktuellen ZIP passen, nicht zum Zwischenspeicher
	$h = strtolower( isset( $m['sha256'] ) ? (string) $m['sha256'] : '' );
	if ( ! preg_match( '/^[a-f0-9]{64}$/', $h ) ) { return new WP_Error( 'abspeed_summe', 'Keine Prüfsumme in der Update-Datei. Es wurde nichts installiert.' ); }
	if ( ! function_exists( 'download_url' ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
	$file = download_url( $package, 300 );
	if ( is_wp_error( $file ) ) { return $file; }
	if ( ! hash_equals( $h, (string) hash_file( 'sha256', $file ) ) ) { @unlink( $file ); return new WP_Error( 'abspeed_summe', 'Prüfsumme des Updates stimmt nicht. Es wurde nichts installiert.' ); }
	return $file;
}

/* Modul Sicherung/Wiederherstellung (eigene Datei, legt Untermenü „Sicherung“ an) */
if ( file_exists( __DIR__ . '/modules/backup.php' ) ) { require_once __DIR__ . '/modules/backup.php'; }
