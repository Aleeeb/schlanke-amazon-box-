<?php
/**
 * 1AB Speed – Modul Sicherung & Umzug (lokal).
 * Eigene Datei, wird von 1ab-speed.php per require_once geladen.
 * Teile: Datenbank, Plugins, Themes, Uploads, Sonstiges. Pakete mit Prüfsumme,
 * Wiederherstellung über Temp-Tabellen (atomarer Tausch) mit Vorab-Sicherung,
 * URL-Ersetzung (serialisiert-sicher) mit Probelauf, Ablage auf dem eigenen Computer (Chrome/Edge).
 * 0.5.0: inkrementell (nur geänderte/neue Dateien, Index je Sicherung), Aufbewahrung (neueste, ca. 1 Woche, optional ca. 1 Monat).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'ABBK_VER', '0.6.3' );
define( 'ABBK_TMP', 'abt_' );
define( 'ABBK_OLD', 'abo_' );

/* ================= Hilfen ================= */
function abbk_root() {
	$d = get_option( 'abbk_dir' );
	if ( $d && is_dir( WP_CONTENT_DIR . '/' . $d ) ) { return WP_CONTENT_DIR . '/' . $d; }
	$d = 'abbk-' . strtolower( wp_generate_password( 12, false ) );
	$p = WP_CONTENT_DIR . '/' . $d;
	if ( ! wp_mkdir_p( $p ) ) { return false; }
	@file_put_contents( $p . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" );
	@file_put_contents( $p . '/index.php', "<?php // Silence\n" );
	@file_put_contents( $p . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' );
	update_option( 'abbk_dir', $d, false );
	return $p;
}
function abbk_valid_id( $id ) { return is_string( $id ) && preg_match( '/^[0-9]{8}-[0-9]{6}-[a-z0-9]{4,8}$/', $id ); }
function abbk_set_dir( $id ) {
	if ( ! abbk_valid_id( $id ) ) { return false; }
	$r = abbk_root();
	return $r ? $r . '/' . $id : false;
}
function abbk_deadline() {
	@set_time_limit( 90 );
	$m = (int) ini_get( 'max_execution_time' );
	$b = $m > 0 ? min( 20, max( 4, $m - 8 ) ) : 20;
	return microtime( true ) + $b;
}
function abbk_chunk_bytes() { return max( 5, min( 500, (int) get_option( 'abbk_chunk_mb', 50 ) ) ) * 1048576; }
function abbk_jload( $f ) { $j = @file_get_contents( $f ); $a = $j ? json_decode( $j, true ) : null; return is_array( $a ) ? $a : null; }
function abbk_jsave( $f, $a ) { file_put_contents( $f, wp_json_encode( $a ), LOCK_EX ); }
function abbk_esc( $v ) {
	global $wpdb;
	static $link = null;
	if ( $link === null ) {
		$link = false;
		try { if ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof mysqli ) { $link = $wpdb->dbh; } } catch ( Throwable $e ) {}
	}
	if ( $link ) { return mysqli_real_escape_string( $link, $v ); }
	return str_replace( array( "\n", "\r", "\x1a" ), array( '\n', '\r', '\Z' ), addslashes( $v ) );
}
function abbk_components() {
	return array( 'db' => 'Datenbank', 'plugins' => 'Plugins', 'themes' => 'Themes', 'uploads' => 'Uploads (Mediathek)', 'others' => 'Sonstiges in wp-content' );
}
function abbk_comp_root( $c ) {
	if ( $c === 'plugins' ) { return WP_PLUGIN_DIR; }
	if ( $c === 'themes' ) { return get_theme_root(); }
	if ( $c === 'uploads' ) { $u = wp_get_upload_dir(); return $u['basedir']; }
	return WP_CONTENT_DIR;
}
function abbk_gz() { return function_exists( 'gzopen' ); }
/* Dateinamen für Tab-Listen: nur %, Tab, Zeilenumbruch kodieren */
function abbk_enc( $s ) { return str_replace( array( '%', "\t", "\n", "\r" ), array( '%25', '%09', '%0A', '%0D' ), $s ); }
function abbk_dec( $s ) { return str_replace( array( '%09', '%0A', '%0D', '%25' ), array( "\t", "\n", "\r", '%' ), $s ); }
function abbk_part_ok( $n ) { return is_string( $n ) && preg_match( '/^(manifest\.json|index\.tsv(\.gz)?|db-[0-9]{3}\.sql(\.gz)?|files-[a-z]+-[0-9]{3}\.zip)$/', $n ); }
function abbk_ropen( $f ) { return substr( $f, -3 ) === '.gz' ? gzopen( $f, 'rb' ) : fopen( $f, 'rb' ); }
function abbk_rline( $h, $f ) { $l = substr( $f, -3 ) === '.gz' ? gzgets( $h, 65536 ) : fgets( $h, 65536 ); return $l; }
function abbk_rclose( $h, $f ) { substr( $f, -3 ) === '.gz' ? gzclose( $h ) : fclose( $h ); }
function abbk_home() { return untrailingslashit( home_url() ); }
/* Läuft gerade eine Sicherung oder Wiederherstellung? (für Selbst-Update) */
function abbk_busy() {
	$r = abbk_root();
	if ( ! $r ) { return false; }
	foreach ( (array) glob( $r . '/*/job.json' ) as $f ) { $j = abbk_jload( $f ); if ( $j && empty( $j['done'] ) && time() - (int) $j['created'] < 3 * 3600 && ! is_file( dirname( $f ) . '/manifest.json' ) ) { return true; } }
	foreach ( (array) glob( $r . '/r*.json' ) as $f ) { $j = abbk_jload( $f ); if ( $j && empty( $j['done'] ) && time() - (int) filemtime( $f ) < 3 * 3600 ) { return true; } }
	return false;
}
/* Zeus-Modus: Sicherungen liegen nach geprüfter Kopie nur auf dem Computer; der Server behält nur die kleine Stand-Liste. */
function abbk_zeus() { return (bool) get_option( 'abbk_zeus', 1 ); }
function abbk_state_file() { $r = abbk_root(); return $r ? $r . '/state.tsv.gz' : false; }
function abbk_state_parts() { $r = abbk_root(); $a = $r ? abbk_jload( $r . '/state-parts.json' ) : null; return is_array( $a ) ? $a : array(); }
function abbk_pins() { $p = get_option( 'abbk_pins', array() ); return is_array( $p ) ? $p : array(); }
function abbk_rm_dir( $dir ) { foreach ( (array) glob( $dir . '/*' ) as $f ) { if ( is_file( $f ) ) { @unlink( $f ); } } foreach ( (array) glob( $dir . '/.*' ) as $f ) { if ( is_file( $f ) ) { @unlink( $f ); } } return @rmdir( $dir ); }
/* Schlüssel eines Teils: <Sicherungs-ID>/<Dateiname> */
function abbk_key_path( $key ) {
	if ( ! is_string( $key ) || strpos( $key, '/' ) === false ) { return false; }
	list( $s, $n ) = explode( '/', $key, 2 );
	if ( ! abbk_valid_id( $s ) || ! abbk_part_ok( $n ) ) { return false; }
	$d = abbk_set_dir( $s );
	return $d ? $d . '/' . $n : false;
}
/* Index einer Sicherung: Zeilen comp \t rel \t size \t mtime \t key */
function abbk_index_file( $dir ) {
	foreach ( array( 'index.tsv.gz', 'index.tsv' ) as $n ) { if ( is_file( $dir . '/' . $n ) ) { return $dir . '/' . $n; } }
	return false;
}
function abbk_index_each( $dir, $cb ) { return abbk_index_each_file( abbk_index_file( $dir ), $cb ); }
function abbk_index_each_file( $f, $cb ) {
	if ( ! $f || ! is_file( $f ) ) { return false; }
	$h = abbk_ropen( $f );
	if ( ! $h ) { return false; }
	while ( ( $l = abbk_rline( $h, $f ) ) !== false ) {
		$a = explode( "\t", rtrim( $l, "\r\n" ) );
		if ( count( $a ) >= 5 ) { $cb( $a[0], abbk_dec( $a[1] ), (int) $a[2], (int) $a[3], $a[4] ); }
	}
	abbk_rclose( $h, $f );
	return true;
}
/* alle fertigen, nicht gelöschten Sicherungen: id => Manifest */
function abbk_live_manifests() {
	$r = abbk_root();
	$out = array();
	if ( ! $r ) { return $out; }
	foreach ( (array) glob( $r . '/*', GLOB_ONLYDIR ) as $d ) {
		$id = basename( $d );
		if ( ! abbk_valid_id( $id ) ) { continue; }
		$m = abbk_jload( $d . '/manifest.json' );
		if ( $m && empty( $m['pruned'] ) ) { $out[ $id ] = $m; }
	}
	uasort( $out, function ( $a, $b ) { return $b['created'] <=> $a['created']; } );
	return $out;
}

/* ================= Zeus-Modus: Freigabe nach geprüfter Kopie ================= */
/* Stand-Liste übernehmen, Sicherung vom Server entfernen. Aufruf erst, nachdem der Browser alle Teile geprüft kopiert hat. */
function abbk_release( $id ) {
	$dir = abbk_set_dir( $id );
	$m = $dir ? abbk_jload( $dir . '/manifest.json' ) : null;
	if ( ! $m || ! empty( $m['pruned'] ) || ! abbk_zeus() || ( isset( $m['kind'] ) && $m['kind'] === 'pre' ) ) { return false; }
	$sf = abbk_state_file(); $ix = abbk_index_file( $dir );
	if ( ! $sf || ! $ix ) { return false; }
	$tmp = $sf . '.new';
	if ( substr( $ix, -3 ) === '.gz' ) { if ( ! copy( $ix, $tmp ) ) { return false; } }
	else { $o = gzopen( $tmp, 'wb6' ); $h = fopen( $ix, 'rb' ); if ( ! $o || ! $h ) { return false; } while ( ( $l = fgets( $h ) ) !== false ) { gzwrite( $o, $l ); } fclose( $h ); gzclose( $o ); }
	rename( $tmp, $sf );
	$parts = array();
	foreach ( $m['parts'] as $p ) { if ( $p['type'] === 'index' ) { continue; } $o = isset( $p['set'] ) ? $p['set'] : $id; $e = $p; $e['set'] = $o; $parts[ $o . '/' . $p['name'] ] = $e; }
	abbk_jsave( abbk_root() . '/state-parts.json', $parts );
	update_option( 'abbk_state_home', abbk_home(), false );
	update_option( 'abbk_last_info', array( 'id' => $id, 'created' => (int) $m['created'], 'mode' => isset( $m['mode'] ) ? $m['mode'] : 'full', 'auto' => ! empty( $m['auto'] ), 'new' => isset( $m['new_bytes'] ) ? (int) $m['new_bytes'] : null, 'label' => $m['label'], 'released' => time() ), false );
	$m['pruned'] = true;
	abbk_jsave( $dir . '/manifest.json', $m );
	abbk_gc( 0 );
	return true;
}
/* Vor einer neuen Sicherung: alles Alte auf dem Server löschen (außer laufende Aufträge und frische Vorab-Sicherungen) */
function abbk_zeus_clean() {
	if ( ! abbk_zeus() ) { return; }
	foreach ( abbk_live_manifests() as $id => $m ) {
		if ( isset( $m['kind'] ) && $m['kind'] === 'pre' && time() - (int) $m['created'] < 3 * 86400 ) { continue; }
		$m['pruned'] = true;
		abbk_jsave( abbk_set_dir( $id ) . '/manifest.json', $m );
	}
	$r = abbk_root();
	foreach ( (array) glob( $r . '/*', GLOB_ONLYDIR ) as $d ) { // abgebrochene Aufträge (älter als 30 Min.)
		$j = abbk_jload( $d . '/job.json' );
		if ( $j && empty( $j['done'] ) && ! is_file( $d . '/manifest.json' ) && time() - (int) $j['created'] > 1800 ) { abbk_rm_dir( $d ); }
	}
	abbk_gc( 0 );
}

/* ================= Speicherplatz ================= */
function abbk_dir_size( $d ) {
	$n = 0;
	if ( ! is_dir( $d ) ) { return 0; }
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ) ) as $f ) { if ( $f->isFile() ) { $n += (int) $f->getSize(); } }
	return $n;
}
/* Größe dessen, was gesichert würde (ohne Sicherungsordner). Zwischenspeicher 24 h; mit Zeitgrenze. */
function abbk_site_size( $force = false ) {
	$c = get_option( 'abbk_site_size', array() );
	if ( ! $force && ! empty( $c['t'] ) && time() - $c['t'] < 86400 ) { return $c; }
	$end = microtime( true ) + 12; $sum = 0; $partial = false; $by = array(); $bc = array();
	foreach ( array_keys( abbk_components() ) as $comp ) {
		if ( $comp === 'db' ) { continue; }
		$root = rtrim( abbk_comp_root( $comp ), '/\\' );
		if ( ! is_dir( $root ) ) { continue; }
		$it = new RecursiveIteratorIterator( new RecursiveCallbackFilterIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), function ( $cur ) use ( $comp, $root ) {
			if ( $cur->isLink() ) { return false; }
			if ( $cur->isDir() ) { $rel = ltrim( substr( $cur->getPathname(), strlen( $root ) ), '/\\' ); return ! abbk_skip_dir( $cur->getFilename(), $comp === 'others' && strpos( $rel, '/' ) === false && strpos( $rel, '\\' ) === false ); }
			return true;
		} ), RecursiveIteratorIterator::LEAVES_ONLY );
		$cl = strlen( $root ) + 1;
		foreach ( $it as $f ) {
			if ( $f->isFile() ) { $z = (int) $f->getSize(); $sum += $z; $rel = substr( str_replace( '\\', '/', $f->getPathname() ), $cl ); $top = $comp . '/' . ( strpos( $rel, '/' ) !== false ? strstr( $rel, '/', true ) : '(lose Dateien)' ); $by[ $top ] = ( isset( $by[ $top ] ) ? $by[ $top ] : 0 ) + $z; $bc[ $comp ] = ( isset( $bc[ $comp ] ) ? $bc[ $comp ] : 0 ) + $z; }
			if ( microtime( true ) > $end ) { $partial = true; break 2; }
		}
	}
	arsort( $by );
	$dbs = 0;
	global $wpdb;
	if ( isset( $wpdb ) && method_exists( $wpdb, 'get_results' ) ) {
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ), ARRAY_A );
		foreach ( (array) $rows as $r ) { $dbs += (int) ( isset( $r['Data_length'] ) ? $r['Data_length'] : 0 ) + (int) ( isset( $r['Index_length'] ) ? $r['Index_length'] : 0 ); }
	}
	$c = array( 't' => time(), 'size' => $sum, 'db' => $dbs, 'partial' => $partial, 'comp' => $bc, 'top' => array_slice( $by, 0, 6, true ) );
	update_option( 'abbk_site_size', $c, false );
	return $c;
}
function abbk_last_full_size() {
	foreach ( abbk_live_manifests() as $m ) { if ( empty( $m['kind'] ) && isset( $m['mode'] ) && $m['mode'] === 'full' && isset( $m['new_bytes'] ) ) { return (int) $m['new_bytes']; } }
	return 0;
}
/* Erwartete Größe einer vollen Sicherung */
function abbk_est_full() { $l = abbk_last_full_size(); if ( $l ) { return $l; } $s = abbk_site_size(); return (int) $s['size']; }
/* Platz: total, free (Byte oder null), module, quota. */
function abbk_space() {
	$r = abbk_root();
	$mod = $r ? abbk_dir_size( $r ) : 0;
	$q = (float) get_option( 'abbk_quota_gb', 0 );
	$total = null; $free = null; $src = 'unknown';
	if ( $q > 0 ) {
		$site = abbk_site_size();
		$total = (int) ( $q * 1073741824 );
		$free = max( 0, $total - (int) $site['size'] - ( isset( $site['db'] ) ? (int) $site['db'] : 0 ) - $mod );
		$src = 'quota';
	} elseif ( $r && function_exists( 'disk_free_space' ) ) {
		$f = @disk_free_space( $r ); $t = @disk_total_space( $r );
		if ( $f !== false && $t !== false && $t > 0 ) { $free = (int) $f; $total = (int) $t; $src = 'disk'; }
	}
	return array( 'total' => $total, 'free' => $free, 'module' => $mod, 'src' => $src );
}
/* Ampel: grün/gelb/rot/grau. Rot: frei < 2x volle Sicherung oder Modul > 1/3; Gelb: frei < 4x oder Modul > 1/5. */
function abbk_light( $sp, $est ) {
	if ( $sp['src'] === 'disk' && $sp['total'] > 200 * 1073741824 ) { return array( 'grau', 'PHP meldet nur die ganze Festplatte des Hosters (' . round( $sp['total'] / 1073741824 ) . ' GB), nicht das Limit Deines Tarifs. Bitte das Speicherlimit des Tarifs eintragen (z. B. 2 GB), sonst ist die Ampel nicht aussagekräftig.' ); }
	if ( $sp['total'] === null || $sp['free'] === null ) { return array( 'grau', 'Freier Speicher nicht ermittelbar (der Hoster gibt ihn nicht heraus). Tipp: Speicherlimit des Tarifs eintragen.' ); }
	if ( $sp['free'] < 2 * $est ) { return array( 'rot', 'Weniger als das Doppelte einer vollen Sicherung ist frei.' ); }
	if ( $sp['module'] > $sp['total'] / 3 ) { return array( 'rot', 'Die Sicherungen belegen mehr als ein Drittel des Speichers.' ); }
	if ( $sp['free'] < 4 * $est ) { return array( 'gelb', 'Der freie Platz reicht nur für wenige weitere Sicherungen.' ); }
	if ( $sp['module'] > $sp['total'] / 5 ) { return array( 'gelb', 'Die Sicherungen belegen mehr als ein Fünftel des Speichers.' ); }
	return array( 'gruen', 'Genug Platz.' );
}
/* Vor dem Start: genug Platz? Gibt Fehlertext oder '' */
function abbk_need( $full_needed, $kind ) {
	if ( $kind === 'pre' ) { return 100 * 1048576; }
	if ( $full_needed ) { return (int) ( abbk_est_full() * 1.1 ); }
	return max( 100 * 1048576, 2 * abbk_last_new() );
}
/* Vorabprüfung für die Oberfläche: ok / warn / block / unknown */
function abbk_precheck( $full ) {
	$need = abbk_need( $full || ! abbk_last_full_size(), '' );
	$sp = abbk_space(); $light = abbk_light( $sp, max( $need, 1 ) );
	$lv = 'ok'; $why = '';
	if ( $sp['free'] === null ) { $lv = 'unknown'; $why = 'Der freie Speicher ist nicht ermittelbar.'; }
	elseif ( $sp['free'] < $need ) { $lv = 'block'; $why = 'Es ist nicht genug Platz frei.'; }
	elseif ( $sp['total'] && ( $sp['free'] - $need < 0.2 * $sp['total'] || $sp['module'] + $need > $sp['total'] / 3 ) ) { $lv = 'warn'; $why = 'Danach wäre der Speicher fast voll oder die Sicherungen würden mehr als ein Drittel belegen.'; }
	elseif ( $light[0] === 'grau' ) { $lv = 'unknown'; $why = $light[1]; }
	return array( 'level' => $lv, 'why' => $why, 'need' => $need, 'free' => $sp['free'], 'total' => $sp['total'], 'module' => $sp['module'], 'src' => $sp['src'], 'zeus' => abbk_zeus() );
}
function abbk_space_check( $full_needed, $kind ) {
	if ( $kind !== 'pre' ) { abbk_zeus_clean(); }
	abbk_enforce_cap(); abbk_gc();
	$sp = abbk_space();
	if ( $sp['free'] === null ) { return ''; }
	$need = abbk_need( $full_needed, $kind );
	if ( $sp['free'] >= $need ) { return ''; }
	return 'Nicht genug freier Speicher auf dem Server: frei ' . round( $sp['free'] / 1048576 ) . ' MB, für diese Sicherung nötig ca. ' . round( $need / 1048576 ) . ' MB. Lösung: zuerst die letzte Sicherung auf den Computer kopieren und alte Sicherungen löschen („Jetzt aufräumen“ oder „Vom Server löschen“), die Höchstzahl der Sicherungen senken oder beim Hoster mehr Speicher buchen.';
}
function abbk_last_new() { foreach ( abbk_live_manifests() as $m ) { if ( empty( $m['kind'] ) && isset( $m['new_bytes'] ) ) { return (int) $m['new_bytes']; } } return 0; }

/* ================= Sicherung ================= */
function abbk_backup_start( $comps, $label = '', $full = false, $kind = '', $auto = false ) {
	$r = abbk_root();
	if ( ! $r ) { return new WP_Error( 'dir', 'Sicherungsordner nicht anlegbar.' ); }
	if ( ! class_exists( 'ZipArchive' ) ) { return new WP_Error( 'zip', 'PHP-Erweiterung ZipArchive fehlt.' ); }
	$ok = array_keys( abbk_components() );
	$comps = array_values( array_intersect( $ok, (array) $comps ) );
	if ( ! $comps ) { return new WP_Error( 'none', 'Nichts ausgewählt.' ); }
	$full_needed = $full || ! abbk_last_full_size();
	$sc = abbk_space_check( $full_needed, $kind );
	if ( $sc ) { return new WP_Error( 'space', $sc ); }
	$id  = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 5, false ) );
	$id  = preg_replace( '/[^a-z0-9-]/', 'x', $id );
	$dir = $r . '/' . $id;
	if ( ! wp_mkdir_p( $dir ) ) { return new WP_Error( 'dir', 'Ordner nicht anlegbar.' ); }
	$tasks = array();
	foreach ( $comps as $c ) { $tasks[] = $c === 'db' ? 'db' : 'f:' . $c; }
	$tasks[] = 'final';
	abbk_jsave( $dir . '/job.json', array( 'id' => $id, 'label' => sanitize_text_field( $label ), 'full' => (bool) $full, 'auto' => (bool) $auto, 'kind' => $kind === 'pre' ? 'pre' : '', 'comps' => $comps, 'tasks' => $tasks, 'ti' => 0, 'st' => new stdClass(), 'done' => false, 'created' => time(), 'msg' => 'Start' ) );
	return $id;
}

/* ein Schritt (Zeitbudget); gibt Status-Array oder WP_Error */
function abbk_backup_step( $id ) {
	$dir = abbk_set_dir( $id );
	if ( ! $dir || ! is_dir( $dir ) ) { return new WP_Error( 'id', 'Sicherung unbekannt.' ); }
	$job = abbk_jload( $dir . '/job.json' );
	if ( ! $job ) { return new WP_Error( 'job', 'Auftrag nicht lesbar.' ); }
	if ( ! empty( $job['done'] ) ) { return array( 'done' => true, 'pct' => 100, 'msg' => 'Fertig' ); }
	$dl = abbk_deadline();
	if ( is_object( $job['st'] ) || ! is_array( $job['st'] ) ) { $job['st'] = array(); }
	while ( $job['ti'] < count( $job['tasks'] ) && microtime( true ) < $dl ) {
		$t = $job['tasks'][ $job['ti'] ];
		if ( $t === 'db' ) { $fin = abbk_task_db( $job, $dir, $dl ); }
		elseif ( $t === 'final' ) { $fin = abbk_task_final( $job, $dir, $dl ); }
		else { $fin = abbk_task_files( $job, $dir, $dl, substr( $t, 2 ) ); }
		if ( is_wp_error( $fin ) ) { abbk_jsave( $dir . '/job.json', $job ); return $fin; }
		if ( $fin ) { $job['ti']++; $job['st'] = array(); }
		abbk_jsave( $dir . '/job.json', $job );
	}
	if ( $job['ti'] >= count( $job['tasks'] ) ) { $job['done'] = true; $job['msg'] = 'Fertig'; abbk_jsave( $dir . '/job.json', $job ); }
	$pct = (int) floor( 100 * $job['ti'] / max( 1, count( $job['tasks'] ) ) );
	return array( 'done' => ! empty( $job['done'] ), 'pct' => $pct, 'msg' => $job['msg'] );
}

function abbk_db_write( &$st, $dir, $sql ) {
	if ( $st['bytes'] > 20 * 1048576 ) { $st['part']++; $st['bytes'] = 0; }
	$gz = abbk_gz();
	$f  = $dir . '/db-' . sprintf( '%03d', $st['part'] ) . ( $gz ? '.sql.gz' : '.sql' );
	$h  = $gz ? gzopen( $f, 'ab6' ) : fopen( $f, 'ab' );
	if ( ! $h ) { return false; }
	$gz ? gzwrite( $h, $sql ) : fwrite( $h, $sql );
	$gz ? gzclose( $h ) : fclose( $h );
	$st['bytes'] += strlen( $sql );
	return true;
}

function abbk_task_db( &$job, $dir, $dl ) {
	global $wpdb;
	$st = &$job['st'];
	if ( empty( $st ) ) {
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ) );
		$st = array( 'tables' => $tables, 'ti' => 0, 'last' => null, 'off' => 0, 'part' => 1, 'bytes' => 0, 'started' => false, 'pk' => null );
	}
	while ( $st['ti'] < count( $st['tables'] ) && microtime( true ) < $dl ) {
		$t  = $st['tables'][ $st['ti'] ];
		$q  = '`' . str_replace( '`', '``', $t ) . '`';
		$job['msg'] = 'Datenbank: ' . $t;
		if ( ! $st['started'] ) {
			$c = $wpdb->get_row( "SHOW CREATE TABLE $q", ARRAY_N );
			if ( ! $c || empty( $c[1] ) ) { $st['ti']++; continue; } // z. B. Views
			if ( ! abbk_db_write( $st, $dir, "DROP TABLE IF EXISTS $q;\n" . str_replace( array( "\n", "\r" ), ' ', $c[1] ) . ";\n" ) ) { return new WP_Error( 'w', 'Schreiben in Sicherungsordner fehlgeschlagen.' ); }
			$keys = $wpdb->get_results( "SHOW KEYS FROM $q WHERE Key_name = 'PRIMARY'", ARRAY_A );
			$st['pk'] = ( is_array( $keys ) && count( $keys ) === 1 ) ? $keys[0]['Column_name'] : null;
			$st['started'] = true; $st['last'] = null; $st['off'] = 0;
			abbk_jsave( $dir . '/job.json', $job );
		}
		$n = 300;
		if ( $st['pk'] ) {
			$pk = '`' . str_replace( '`', '``', $st['pk'] ) . '`';
			$sql = $st['last'] === null ? "SELECT * FROM $q ORDER BY $pk LIMIT $n" : $wpdb->prepare( "SELECT * FROM $q WHERE $pk > %s ORDER BY $pk LIMIT $n", $st['last'] );
		} else {
			$sql = "SELECT * FROM $q LIMIT " . (int) $st['off'] . ",$n";
		}
		$rows = $wpdb->get_results( $sql, ARRAY_N );
		if ( $wpdb->last_error ) { return new WP_Error( 'db', 'Datenbankfehler bei ' . $t . ': ' . $wpdb->last_error ); }
		if ( $rows ) {
			$out = ''; $buf = array();
			foreach ( $rows as $r ) {
				$vals = array();
				foreach ( $r as $v ) { $vals[] = $v === null ? 'NULL' : "'" . abbk_esc( (string) $v ) . "'"; }
				$buf[] = '(' . implode( ',', $vals ) . ')';
				if ( strlen( implode( ',', $buf ) ) > 400000 ) { $out .= "REPLACE INTO $q VALUES " . implode( ',', $buf ) . ";\n"; $buf = array(); }
			}
			if ( $buf ) { $out .= "REPLACE INTO $q VALUES " . implode( ',', $buf ) . ";\n"; }
			if ( ! abbk_db_write( $st, $dir, $out ) ) { return new WP_Error( 'w', 'Schreiben in Sicherungsordner fehlgeschlagen.' ); }
			if ( $st['pk'] ) {
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM $q" );
				$ix   = array_search( $st['pk'], $cols, true );
				$last = end( $rows );
				$st['last'] = (string) $last[ $ix ];
			}
			$st['off'] += count( $rows );
		}
		if ( count( $rows ) < $n ) { $st['ti']++; $st['started'] = false; }
		abbk_jsave( $dir . '/job.json', $job );
	}
	return $st['ti'] >= count( $st['tables'] );
}

function abbk_skip_dir( $name, $top ) {
	if ( strpos( $name, 'abbk-' ) === 0 ) { return true; }
	if ( $top ) {
		$skip = array( 'plugins', 'themes', 'uploads', 'cache', 'upgrade', 'updraft', 'wflogs', 'ai1wm-backups', 'backup', 'backups', 'backup-db', 'backwpup', 'mu-plugins-cache' );
		if ( in_array( strtolower( $name ), $skip, true ) ) { return true; }
	}
	return in_array( $name, array( '.git', 'node_modules' ), true );
}

/* Stand der letzten passenden Sicherung für ein Teil: rel => array( size, mtime, key ). Nur Einträge, deren Teil noch existiert. */
function abbk_base_map( $comp, &$base_id ) {
	$base_id = '';
	$home = abbk_home();
	if ( abbk_zeus() ) {
		$sf = abbk_state_file();
		if ( ! $sf || ! is_file( $sf ) || get_option( 'abbk_state_home' ) !== $home ) { return array(); }
		$map = array();
		abbk_index_each_file( $sf, function ( $c, $rel, $size, $mt, $key ) use ( $comp, &$map ) { if ( $c === $comp ) { $map[ $rel ] = array( $size, $mt, $key ); } } );
		if ( $map ) { $base_id = 'Stand'; }
		return $map;
	}
	$all  = abbk_live_manifests();
	foreach ( $all as $id => $m ) {
		if ( ( isset( $m['kind'] ) && $m['kind'] === 'pre' ) || empty( $m['format'] ) || $m['format'] < 2 ) { continue; }
		if ( untrailingslashit( $m['home'] ) !== $home || ! in_array( $comp, $m['comps'], true ) ) { continue; }
		$dir = abbk_set_dir( $id );
		$ok  = array();
		$map = array();
		abbk_index_each( $dir, function ( $c, $rel, $size, $mt, $key ) use ( $comp, &$ok, &$map, $all ) {
			if ( $c !== $comp ) { return; }
			if ( ! isset( $ok[ $key ] ) ) {
				$ok[ $key ] = false;
				$p = abbk_key_path( $key );
				$o = strstr( $key, '/', true );
				if ( $p && is_file( $p ) && isset( $all[ $o ] ) ) {
					foreach ( $all[ $o ]['parts'] as $pp ) { if ( ! isset( $pp['set'] ) && $key === $o . '/' . $pp['name'] ) { $ok[ $key ] = true; } }
				}
			}
			if ( $ok[ $key ] ) { $map[ $rel ] = array( $size, $mt, $key ); }
		} );
		$base_id = $id;
		return $map;
	}
	return array();
}

/* Liste der aktuellen Dateien: geänderte/neue -> $file (rel, size, mtime), unveränderte -> $idxfile (rel, size, mtime, key) */
function abbk_build_list( $comp, $file, $idxfile, $base ) {
	$root = rtrim( abbk_comp_root( $comp ), '/\\' );
	$bp   = str_replace( '\\', '/', rtrim( WP_CONTENT_DIR, '/\\' ) );
	$out  = fopen( $file, 'wb' );
	$ix   = fopen( $idxfile, 'wb' );
	if ( ! $out || ! $ix || ! is_dir( $root ) ) { if ( $out ) { fclose( $out ); } if ( $ix ) { fclose( $ix ); } return array( 0, 0 ); }
	$n = 0; $ch = 0;
	$it = new RecursiveIteratorIterator( new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		function ( $cur, $key, $iter ) use ( $comp, $root ) {
			if ( $cur->isLink() ) { return false; }
			if ( $cur->isDir() ) {
				$rel = ltrim( substr( $cur->getPathname(), strlen( $root ) ), '/\\' );
				return ! abbk_skip_dir( $cur->getFilename(), $comp === 'others' && strpos( $rel, '/' ) === false && strpos( $rel, '\\' ) === false );
			}
			return true;
		}
	), RecursiveIteratorIterator::LEAVES_ONLY );
	foreach ( $it as $f ) {
		if ( ! $f->isFile() ) { continue; }
		$p = str_replace( '\\', '/', $f->getPathname() );
		if ( strpos( $p, $bp . '/' ) !== 0 ) { continue; }
		$rel = substr( $p, strlen( $bp ) + 1 );
		$sz = (int) $f->getSize(); $mt = (int) $f->getMTime();
		$n++;
		if ( $base && isset( $base[ $rel ] ) && $base[ $rel ][0] === $sz && $base[ $rel ][1] === $mt ) {
			fwrite( $ix, abbk_enc( $rel ) . "\t" . $sz . "\t" . $mt . "\t" . $base[ $rel ][2] . "\n" );
		} else {
			fwrite( $out, abbk_enc( $rel ) . "\t" . $sz . "\t" . $mt . "\n" );
			$ch++;
		}
	}
	fclose( $out ); fclose( $ix );
	return array( $n, $ch );
}

function abbk_task_files( &$job, $dir, $dl, $comp ) {
	$st = &$job['st'];
	$list = $dir . '/list-' . $comp . '.txt';
	$idx  = $dir . '/idx-' . $comp . '.tsv';
	if ( empty( $st ) ) {
		$job['msg'] = 'Dateien suchen: ' . $comp;
		$bid = ''; $base = array();
		if ( empty( $job['full'] ) ) { $base = abbk_base_map( $comp, $bid ); }
		$r  = abbk_build_list( $comp, $list, $idx, $base );
		$st = array( 'pos' => 0, 'part' => 1, 'total' => $r[1], 'all' => $r[0], 'done' => 0, 'base' => $bid );
		$job['msg'] = 'Dateien: ' . $comp . ' (' . $r[1] . ' neu/geändert von ' . $r[0] . ( $bid ? ', Basis ' . $bid : ', ohne Basis' ) . ')';
		abbk_jsave( $dir . '/job.json', $job );
	}
	$limit = abbk_chunk_bytes();
	$base  = rtrim( WP_CONTENT_DIR, '/\\' );
	$h = @fopen( $list, 'rb' );
	if ( ! $h ) { return true; }
	while ( microtime( true ) < $dl ) {
		fseek( $h, $st['pos'] );
		$zname = 'files-' . $comp . '-' . sprintf( '%03d', $st['part'] ) . '.zip';
		$zf = $dir . '/' . $zname;
		if ( file_exists( $zf ) ) { unlink( $zf ); }
		$zip = new ZipArchive();
		$sum = 0; $cnt = 0; $pos = $st['pos']; $any = false; $lines = '';
		while ( ( $line = fgets( $h ) ) !== false ) {
			$a = explode( "\t", rtrim( $line, "\r\n" ) );
			$rel = abbk_dec( $a[0] ); $sz = isset( $a[1] ) ? (int) $a[1] : 0; $mt = isset( $a[2] ) ? (int) $a[2] : 0;
			$pos += strlen( $line );
			if ( $rel === '' ) { continue; }
			if ( ! $any ) {
				if ( $zip->open( $zf, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) { fclose( $h ); return new WP_Error( 'zip', 'ZIP nicht anlegbar.' ); }
				$any = true;
			}
			$full = $base . '/' . $rel;
			if ( is_file( $full ) && is_readable( $full ) ) {
				$zip->addFile( $full, $rel );
				if ( preg_match( '/\.(jpe?g|png|gif|webp|avif|zip|gz|mp3|mp4|ogg|webm|woff2?)$/i', $rel ) ) { $zip->setCompressionName( $rel, ZipArchive::CM_STORE ); }
				$lines .= abbk_enc( $rel ) . "\t" . $sz . "\t" . $mt . "\t" . $job['id'] . '/' . $zname . "\n";
				$sum += $sz; $cnt++;
			}
			if ( $sum >= $limit || $cnt >= 5000 || microtime( true ) >= $dl ) { break; }
		}
		if ( $any ) {
			if ( ! $zip->close() && $cnt ) { fclose( $h ); return new WP_Error( 'zip', 'ZIP konnte nicht geschrieben werden (Speicherplatz?).' ); }
			if ( $cnt ) { file_put_contents( $idx, $lines, FILE_APPEND ); } elseif ( file_exists( $zf ) ) { @unlink( $zf ); }
			$st['part']++;
		}
		$st['pos']  = $pos;
		$st['done'] += $cnt;
		$job['msg'] = 'Dateien: ' . $comp . ' (' . $st['done'] . ' von ' . $st['total'] . ' neu/geändert)';
		abbk_jsave( $dir . '/job.json', $job );
		if ( feof( $h ) || ! $any ) { fclose( $h ); @unlink( $list ); return true; }
	}
	fclose( $h );
	return false;
}

function abbk_task_final( &$job, $dir, $dl ) {
	$st = &$job['st'];
	if ( empty( $st ) ) {
		/* Index schreiben: je Datei der letzte Eintrag gewinnt */
		$gz = abbk_gz();
		$in = $dir . '/index.tsv' . ( $gz ? '.gz' : '' );
		$o  = $gz ? gzopen( $in, 'wb6' ) : fopen( $in, 'wb' );
		if ( ! $o ) { return new WP_Error( 'w', 'Index nicht schreibbar.' ); }
		foreach ( $job['comps'] as $c ) {
			if ( $c === 'db' ) { continue; }
			$f = $dir . '/idx-' . $c . '.tsv';
			if ( ! is_file( $f ) ) { continue; }
			$map = array();
			$h = fopen( $f, 'rb' );
			while ( ( $l = fgets( $h ) ) !== false ) { $a = explode( "\t", rtrim( $l, "\r\n" ), 2 ); if ( $a[0] !== '' ) { $map[ $a[0] ] = $a[1]; } }
			fclose( $h );
			foreach ( $map as $rel => $rest ) { $line = $c . "\t" . $rel . "\t" . $rest . "\n"; $gz ? gzwrite( $o, $line ) : fwrite( $o, $line ); }
			unset( $map );
			@unlink( $f );
		}
		$gz ? gzclose( $o ) : fclose( $o );
		$names = array();
		foreach ( (array) glob( $dir . '/{db-*.sql,db-*.sql.gz,files-*.zip,index.tsv,index.tsv.gz}', GLOB_BRACE ) as $f ) { $names[] = basename( $f ); }
		sort( $names );
		$st = array( 'names' => $names, 'i' => 0, 'parts' => array() );
	}
	while ( $st['i'] < count( $st['names'] ) && microtime( true ) < $dl ) {
		$n = $st['names'][ $st['i'] ];
		$job['msg'] = 'Prüfsumme: ' . $n;
		$type = strpos( $n, 'db-' ) === 0 ? 'db' : ( strpos( $n, 'index.' ) === 0 ? 'index' : preg_replace( '/^files-([a-z]+)-.*$/', '$1', $n ) );
		$st['parts'][] = array( 'name' => $n, 'size' => filesize( $dir . '/' . $n ), 'sha256' => hash_file( 'sha256', $dir . '/' . $n ), 'type' => $type );
		$st['i']++;
	}
	if ( $st['i'] < count( $st['names'] ) ) { return false; }
	/* Teile früherer Sicherungen, auf die der Index verweist */
	$foreign = array();
	abbk_index_each( $dir, function ( $c, $rel, $size, $mt, $key ) use ( &$foreign, $job ) { if ( strpos( $key, $job['id'] . '/' ) !== 0 ) { $foreign[ $key ] = $c; } } );
	$bases = array();
	foreach ( $foreign as $key => $c ) {
		list( $o, $n ) = explode( '/', $key, 2 );
		$om = abbk_jload( abbk_set_dir( $o ) . '/manifest.json' );
		$found = null;
		if ( $om ) { foreach ( $om['parts'] as $pp ) { if ( ! isset( $pp['set'] ) && $pp['name'] === $n ) { $found = $pp; } } }
		if ( ! $found && abbk_zeus() ) { $sp = abbk_state_parts(); if ( isset( $sp[ $key ] ) ) { $found = $sp[ $key ]; unset( $found['set'] ); } }
		if ( ! $found || ( ! abbk_zeus() && ! is_file( abbk_key_path( $key ) ) ) ) { return new WP_Error( 'base', 'Teil der Basis-Sicherung fehlt: ' . $key . ' – bitte „Volle Sicherung“ wählen.' ); }
		$found['set'] = $o;
		$st['parts'][] = $found;
		$bases[ $o ] = 1;
	}
	global $wpdb;
	$man = array(
		'format' => 2, 'id' => $job['id'], 'label' => $job['label'], 'created' => $job['created'], 'kind' => isset( $job['kind'] ) ? $job['kind'] : '',
		'home' => home_url(), 'siteurl' => site_url(), 'prefix' => $wpdb->base_prefix,
		'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'plugin' => ABBK_VER,
		'comps' => $job['comps'], 'bases' => array_keys( $bases ), 'parts' => $st['parts'],
		'mode' => $bases ? 'inc' : 'full', 'auto' => ! empty( $job['auto'] ), 'new_bytes' => array_sum( array_map( function ( $p ) { return isset( $p['set'] ) ? 0 : (int) $p['size']; }, $st['parts'] ) ),
	);
	abbk_jsave( $dir . '/manifest.json', $man );
	$job['msg'] = 'Fertig';
	$l = abbk_prune( (bool) get_option( 'abbk_autoprune', 0 ) ); if ( $l ) { $job['msg'] = 'Fertig. Aufgeräumt: ' . implode( ' ', $l ); }
	return true;
}

/* ================= Aufbewahrung ================= */
/* $sets: Liste aus id, created, kind, home, pinned. Rückgabe: Liste der zu behaltenden IDs. */
function abbk_policy( $sets, $now, $month, $home, $week = true ) {
	$keep = array(); $elig = array(); $pre = array();
	foreach ( $sets as $s ) {
		if ( $s['kind'] === 'pre' ) { $pre[] = $s; continue; }
		if ( ! empty( $s['pinned'] ) || untrailingslashit( $s['home'] ) !== $home ) { $keep[ $s['id'] ] = 1; continue; }
		$elig[] = $s;
	}
	usort( $elig, function ( $a, $b ) { return $b['created'] <=> $a['created']; } );
	if ( $elig ) { $keep[ $elig[0]['id'] ] = 1; }
	$wk = null; $mo = null; $day = 86400;
	for ( $i = 1; $i < count( $elig ); $i++ ) {
		$age = $now - $elig[ $i ]['created'];
		if ( $age < 8 * $day ) { $wk = $elig[ $i ]; }
		elseif ( $month && $age < 35 * $day ) { $mo = $elig[ $i ]; }
	}
	if ( $wk && $week ) { $keep[ $wk['id'] ] = 1; }
	if ( $mo ) { $keep[ $mo['id'] ] = 1; }
	usort( $pre, function ( $a, $b ) { return $b['created'] <=> $a['created']; } );
	foreach ( array_slice( $pre, 0, 2 ) as $p ) { if ( $now - $p['created'] < 7 * $day ) { $keep[ $p['id'] ] = 1; } }
	return array_keys( $keep );
}

/* Teile entfernen, die keine behaltene Sicherung mehr braucht; leere Ordner löschen */
function abbk_gc( $grace = 7200 ) {
	$r = abbk_root();
	$log = array();
	if ( ! $r ) { return $log; }
	$refs = array();
	foreach ( abbk_live_manifests() as $id => $m ) {
		foreach ( $m['parts'] as $p ) { $refs[ ( isset( $p['set'] ) ? $p['set'] : $id ) . '/' . $p['name'] ] = 1; }
	}
	$freed = 0;
	foreach ( (array) glob( $r . '/*', GLOB_ONLYDIR ) as $d ) {
		$id = basename( $d );
		if ( ! abbk_valid_id( $id ) ) { continue; }
		$m = abbk_jload( $d . '/manifest.json' );
		$j = abbk_jload( $d . '/job.json' );
		if ( ! $m && $j && empty( $j['done'] ) ) {
			if ( $j['created'] < time() - 3 * 86400 ) { abbk_rm_dir( $d ); }
			continue; // läuft noch oder abgebrochen (jünger als 3 Tage)
		}
		foreach ( (array) glob( $d . '/*' ) as $f ) {
			$n = basename( $f );
			if ( ! is_file( $f ) || $n === 'manifest.json' || $n === 'job.json' ) { continue; }
			$stale = @filemtime( $f ) <= time() - $grace;
			if ( preg_match( '/^(db-[0-9]{3}\.sql(\.gz)?|files-[a-z]+-[0-9]{3}\.zip|index\.tsv(\.gz)?)$/', $n ) ) {
				if ( empty( $refs[ $id . '/' . $n ] ) && $stale ) { $freed += (int) @filesize( $f ); @unlink( $f ); }
			} elseif ( preg_match( '/\.part$/', $n ) || preg_match( '/^(list|idx)-/', $n ) ) {
				if ( $stale ) { @unlink( $f ); }
			}
		}
		if ( $m && ! empty( $m['pruned'] ) ) {
			$m['parts'] = array_values( array_filter( $m['parts'], function ( $p ) use ( $d ) { return is_file( $d . '/' . $p['name'] ); } ) );
			if ( ! $m['parts'] ) { abbk_rm_dir( $d ); continue; }
			abbk_jsave( $d . '/manifest.json', $m );
		}
		if ( ! $m && ! $j && ! glob( $d . '/*' ) ) { @rmdir( $d ); }
	}
	if ( $freed ) { $log[] = round( $freed / 1048576 ) . ' MB frei.'; }
	return $log;
}

function abbk_policy_sets() {
	$pins = abbk_pins(); $out = array();
	foreach ( abbk_live_manifests() as $id => $m ) {
		$out[] = array( 'id' => $id, 'created' => (int) $m['created'], 'kind' => isset( $m['kind'] ) ? $m['kind'] : '', 'home' => $m['home'], 'pinned' => in_array( $id, $pins, true ) );
	}
	return $out;
}

/* Aufräumen nach Regel: neueste + ca. 1 Woche (+ optional ca. 1 Monat) */
/* Höchstzahl: älteste Sicherungen entfernen, aber nie die neueste und (Standard) nur, wenn sie geprüft auf dem Computer liegen */
function abbk_enforce_cap() {
	$max  = max( 1, min( 10, (int) get_option( 'abbk_max', 3 ) ) );
	$need = (bool) get_option( 'abbk_need_pc', 1 );
	$pc   = (array) get_option( 'abbk_pc_ids', array() );
	$pins = abbk_pins(); $home = abbk_home(); $el = array();
	foreach ( abbk_live_manifests() as $id => $m ) {
		if ( ( isset( $m['kind'] ) && $m['kind'] === 'pre' ) || in_array( $id, $pins, true ) || untrailingslashit( $m['home'] ) !== $home ) { continue; }
		$el[] = $id;
	}
	$gone = array();
	for ( $i = count( $el ) - 1; $i > 0 && count( $el ) - count( $gone ) > $max; $i-- ) {
		if ( $need && ! in_array( $el[ $i ], $pc, true ) ) { continue; }
		$m = abbk_jload( abbk_set_dir( $el[ $i ] ) . '/manifest.json' );
		if ( ! $m ) { continue; }
		$m['pruned'] = true;
		abbk_jsave( abbk_set_dir( $el[ $i ] ) . '/manifest.json', $m );
		$gone[] = $el[ $i ];
	}
	return $gone;
}

/* Aufräumen: nach Regel (neueste + ca. 1 Woche + optional Monat), wenn $policy; danach immer Höchstzahl */
function abbk_prune( $policy = true ) {
	$gone = array();
	if ( $policy ) {
		$keep = abbk_policy( abbk_policy_sets(), time(), (bool) get_option( 'abbk_month', 0 ), abbk_home(), (bool) get_option( 'abbk_week', 0 ) );
		foreach ( abbk_live_manifests() as $id => $m ) {
			if ( in_array( $id, $keep, true ) ) { continue; }
			$m['pruned'] = true;
			abbk_jsave( abbk_set_dir( $id ) . '/manifest.json', $m );
			$gone[] = $id;
		}
	}
	$gone = array_merge( $gone, abbk_enforce_cap() );
	$log = array();
	if ( $gone ) { $log[] = count( $gone ) . ' alte Sicherung(en) entfernt.'; }
	return array_merge( $log, abbk_gc() );
}

/* Größe der eigenen (neuen) Teile je Typ; Teile früherer Sicherungen zählen hier nicht mit */
function abbk_own_by_type( $m ) {
	$by = array();
	foreach ( $m['parts'] as $p ) { if ( isset( $p['set'] ) || $p['type'] === 'index' ) { continue; } $by[ $p['type'] ] = ( isset( $by[ $p['type'] ] ) ? $by[ $p['type'] ] : 0 ) + (int) $p['size']; }
	return $by;
}

function abbk_list_sets() {
	$r = abbk_root();
	$out = array();
	if ( ! $r ) { return $out; }
	$pins = abbk_pins();
	foreach ( (array) glob( $r . '/*', GLOB_ONLYDIR ) as $d ) {
		$id = basename( $d );
		if ( ! abbk_valid_id( $id ) ) { continue; }
		$m = abbk_jload( $d . '/manifest.json' );
		$size = 0;
		foreach ( (array) glob( $d . '/*' ) as $f ) { $size += (int) @filesize( $f ); }
		if ( $m ) {
			if ( ! empty( $m['pruned'] ) ) { continue; }
			$out[] = array( 'id' => $id, 'label' => $m['label'], 'created' => $m['created'], 'comps' => $m['comps'], 'home' => $m['home'], 'prefix' => $m['prefix'], 'size' => $size, 'parts' => array_map( function ( $p ) use ( $id ) { $k = ( isset( $p['set'] ) ? $p['set'] : $id ) . '/' . $p['name']; $f = abbk_key_path( $k ); $p['srv'] = $f && is_file( $f ); return $p; }, $m['parts'] ), 'complete' => true, 'kind' => isset( $m['kind'] ) ? $m['kind'] : '', 'pinned' => in_array( $id, $pins, true ), 'inc' => ! empty( $m['bases'] ), 'by' => abbk_own_by_type( $m ) );
		} else {
			$j = abbk_jload( $d . '/job.json' );
			if ( ! $j ) { continue; }
			$out[] = array( 'id' => $id, 'label' => $j['label'], 'created' => $j['created'], 'comps' => $j['comps'], 'home' => '', 'prefix' => '', 'size' => $size, 'parts' => array(), 'complete' => false, 'kind' => '', 'pinned' => false, 'inc' => false );
		}
	}
	usort( $out, function ( $a, $b ) { return $b['created'] <=> $a['created']; } );
	return $out;
}

function abbk_delete_set( $id ) {
	$dir = abbk_set_dir( $id );
	if ( ! $dir || ! is_dir( $dir ) ) { return false; }
	$m = abbk_jload( $dir . '/manifest.json' );
	if ( ! $m ) { return abbk_rm_dir( $dir ); }
	$m['pruned'] = true;
	abbk_jsave( $dir . '/manifest.json', $m );
	update_option( 'abbk_pins', array_values( array_diff( abbk_pins(), array( $id ) ) ), false );
	abbk_gc();
	return true;
}

/* ================= Wiederherstellung ================= */
function abbk_restore_start( $id, $comps, $from, $to ) {
	$dir = abbk_set_dir( $id );
	$man = $dir ? abbk_jload( $dir . '/manifest.json' ) : null;
	if ( ! $man ) { return new WP_Error( 'man', 'Sicherung unvollständig (manifest.json fehlt).' ); }
	if ( ! class_exists( 'ZipArchive' ) ) { return new WP_Error( 'zip', 'PHP-Erweiterung ZipArchive fehlt.' ); }
	$comps = array_values( array_intersect( $man['comps'], (array) $comps ) );
	if ( ! $comps ) { return new WP_Error( 'none', 'Nichts ausgewählt.' ); }
	$tasks = array( 'verify' );
	if ( in_array( 'db', $comps, true ) ) { $tasks[] = 'pre'; $tasks[] = 'dbimport'; if ( $from !== '' && $to !== '' ) { $tasks[] = 'sr'; } }
	foreach ( $comps as $c ) { if ( $c !== 'db' ) { $tasks[] = 'x:' . $c; } }
	if ( in_array( 'db', $comps, true ) ) { $tasks[] = 'swap'; }
	$rid = 'r' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 5, false ) );
	$rid = preg_replace( '/[^a-z0-9-]/', 'x', $rid );
	global $wpdb;
	abbk_restore_cleanup_tmp();
	abbk_jsave( abbk_root() . '/' . $rid . '.json', array(
		'rid' => $rid, 'set' => $id, 'comps' => $comps, 'tasks' => $tasks, 'ti' => 0, 'st' => array(), 'done' => false, 'msg' => 'Start',
		'from' => $from, 'to' => $to, 'cur_siteurl' => get_option( 'siteurl' ), 'cur_home' => get_option( 'home' ), 'cur_prefix' => $wpdb->base_prefix, 'log' => array(),
	) );
	return $rid;
}
function abbk_restore_cleanup_tmp() {
	global $wpdb;
	foreach ( (array) $wpdb->get_col( "SHOW TABLES LIKE 'abt\\_%'" ) as $t ) { $wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $t ) . '`' ); }
}
function abbk_restore_step( $rid ) {
	if ( ! preg_match( '/^r[0-9]{8}-[0-9]{6}-[a-z0-9]{4,8}$/', (string) $rid ) ) { return new WP_Error( 'id', 'Ungültig.' ); }
	$f   = abbk_root() . '/' . $rid . '.json';
	$job = abbk_jload( $f );
	if ( ! $job ) { return new WP_Error( 'job', 'Auftrag nicht gefunden.' ); }
	if ( ! empty( $job['done'] ) ) { return array( 'done' => true, 'pct' => 100, 'msg' => $job['msg'], 'log' => $job['log'] ); }
	$dir = abbk_set_dir( $job['set'] );
	$man = abbk_jload( $dir . '/manifest.json' );
	$dl  = abbk_deadline();
	while ( $job['ti'] < count( $job['tasks'] ) && microtime( true ) < $dl ) {
		$t = $job['tasks'][ $job['ti'] ];
		if ( $t === 'verify' ) { $fin = abbk_rtask_verify( $job, $dir, $man, $dl ); }
		elseif ( $t === 'pre' ) { $fin = abbk_rtask_pre( $job, $dl ); }
		elseif ( $t === 'dbimport' ) { $fin = abbk_rtask_dbimport( $job, $dir, $man, $dl ); }
		elseif ( $t === 'sr' ) { $fin = abbk_rtask_sr( $job, $man, $dl ); }
		elseif ( $t === 'swap' ) { $fin = abbk_rtask_swap( $job, $man ); }
		else { $fin = abbk_rtask_extract( $job, $dir, $man, $dl, substr( $t, 2 ) ); }
		if ( is_wp_error( $fin ) ) { $job['msg'] = 'Abbruch: ' . $fin->get_error_message(); abbk_jsave( $f, $job ); return $fin; }
		if ( $fin ) { $job['ti']++; $job['st'] = array(); }
		abbk_jsave( $f, $job );
	}
	if ( $job['ti'] >= count( $job['tasks'] ) ) { $job['done'] = true; $job['msg'] = 'Wiederherstellung fertig'; abbk_jsave( $f, $job ); }
	return array( 'done' => ! empty( $job['done'] ), 'pct' => (int) floor( 100 * $job['ti'] / max( 1, count( $job['tasks'] ) ) ), 'msg' => $job['msg'], 'log' => $job['log'] );
}

function abbk_part_selected( $p, $comps ) { return in_array( $p['type'], $comps, true ) || $p['type'] === 'index'; }
/* Teil einer Sicherung: Schlüssel <Besitzer>/<Name> (Besitzer = frühere Sicherung bei inkrementellen Sätzen) */
function abbk_pkey( $p, $id ) { return ( isset( $p['set'] ) ? $p['set'] : $id ) . '/' . $p['name']; }

function abbk_rtask_verify( &$job, $dir, $man, $dl ) {
	$st = &$job['st'];
	if ( empty( $st ) ) { $st = array( 'i' => 0 ); }
	$parts = array_values( array_filter( $man['parts'], function ( $p ) use ( $job ) { return abbk_part_selected( $p, $job['comps'] ); } ) );
	while ( $st['i'] < count( $parts ) && microtime( true ) < $dl ) {
		$p = $parts[ $st['i'] ];
		$job['msg'] = 'Prüfsumme prüfen: ' . $p['name'];
		$fp = abbk_key_path( abbk_pkey( $p, $man['id'] ) );
		if ( ! $fp || ! is_file( $fp ) ) { return new WP_Error( 'miss', 'Teil fehlt: ' . abbk_pkey( $p, $man['id'] ) ); }
		if ( ! hash_equals( $p['sha256'], hash_file( 'sha256', $fp ) ) ) { return new WP_Error( 'sum', 'Prüfsumme falsch: ' . $p['name'] ); }
		$st['i']++;
	}
	return $st['i'] >= count( $parts );
}

function abbk_rtask_pre( &$job, $dl ) {
	$st = &$job['st'];
	if ( empty( $st['pid'] ) ) {
		$pid = abbk_backup_start( array( 'db' ), 'Vorab-Sicherung vor Wiederherstellung', true, 'pre' );
		if ( is_wp_error( $pid ) ) { return $pid; }
		$st = array( 'pid' => $pid );
		$job['log'][] = 'Vorab-Sicherung der Datenbank: ' . $pid;
	}
	$job['msg'] = 'Vorab-Sicherung der aktuellen Datenbank';
	$r = abbk_backup_step( $st['pid'] );
	if ( is_wp_error( $r ) ) { return $r; }
	return ! empty( $r['done'] );
}

function abbk_read_line( $h, $gz ) {
	$line = '';
	while ( true ) {
		$c = $gz ? gzgets( $h, 65536 ) : fgets( $h, 65536 );
		if ( $c === false ) { return $line === '' ? false : $line; }
		$line .= $c;
		if ( substr( $c, -1 ) === "\n" ) { return $line; }
	}
}

function abbk_rtask_dbimport( &$job, $dir, $man, $dl ) {
	global $wpdb;
	$st = &$job['st'];
	if ( empty( $st ) ) {
		$files = array();
		foreach ( $man['parts'] as $p ) { if ( $p['type'] === 'db' && ! isset( $p['set'] ) ) { $files[] = $p['name']; } }
		sort( $files );
		$st = array( 'files' => $files, 'fi' => 0, 'off' => 0, 'tables' => array(), 'err' => 0 );
	}
	$wpdb->suppress_errors( true );
	$bp = $man['prefix'];
	while ( $st['fi'] < count( $st['files'] ) && microtime( true ) < $dl ) {
		$name = basename( $st['files'][ $st['fi'] ] );
		$gz   = substr( $name, -3 ) === '.gz';
		$h    = $gz ? gzopen( $dir . '/' . $name, 'rb' ) : fopen( $dir . '/' . $name, 'rb' );
		if ( ! $h ) { return new WP_Error( 'r', 'Teil nicht lesbar: ' . $name ); }
		if ( $st['off'] > 0 ) { $gz ? gzseek( $h, $st['off'] ) : fseek( $h, $st['off'] ); }
		$job['msg'] = 'Datenbank einspielen: ' . $name;
		$finished = true;
		while ( ( $line = abbk_read_line( $h, $gz ) ) !== false ) {
			$line = rtrim( $line, "\r\n" );
			if ( $line !== '' ) {
				if ( ! preg_match( '/^(DROP TABLE IF EXISTS|CREATE TABLE|REPLACE INTO) `([^`]+)`/', $line, $m ) ) { $st['err']++; continue; }
				if ( strpos( $m[2], $bp ) !== 0 ) { continue; }
				$tmp  = ABBK_TMP . $m[2];
				$line = $m[1] . ' `' . $tmp . '`' . substr( $line, strlen( $m[0] ) );
				$r = $wpdb->query( $line );
				if ( $r === false ) {
					$st['err']++;
					if ( count( $job['log'] ) < 15 ) { $job['log'][] = 'SQL-Fehler: ' . substr( $wpdb->last_error, 0, 200 ); }
					if ( $m[1] === 'CREATE TABLE' ) { $wpdb->suppress_errors( false ); $gz ? gzclose( $h ) : fclose( $h ); return new WP_Error( 'sql', 'Tabelle konnte nicht angelegt werden: ' . $m[2] ); }
				}
				if ( $m[1] === 'CREATE TABLE' ) { $st['tables'][] = $m[2]; }
			}
			$st['off'] = $gz ? gztell( $h ) : ftell( $h );
			if ( microtime( true ) >= $dl ) { $finished = false; break; }
		}
		$gz ? gzclose( $h ) : fclose( $h );
		if ( ! $finished ) { break; }
		$st['fi']++; $st['off'] = 0;
		abbk_jsave( abbk_root() . '/' . $job['rid'] . '.json', $job );
	}
	$wpdb->suppress_errors( false );
	if ( $st['fi'] >= count( $st['files'] ) ) {
		if ( ! $st['tables'] ) { return new WP_Error( 'none', 'Keine Tabellen in der Sicherung.' ); }
		$job['log'][] = count( $st['tables'] ) . ' Tabellen in Temp-Tabellen eingespielt, ' . $st['err'] . ' Fehler.';
		$job['tables'] = $st['tables'];
		return true;
	}
	return false;
}

function abbk_rtask_sr( &$job, $man, $dl ) {
	$st = &$job['st'];
	if ( empty( $st ) ) { $st = array( 'ti' => 0, 'last' => null, 'cnt' => 0 ); }
	$prefix = ABBK_TMP . $man['prefix'];
	$job['msg'] = 'URLs ersetzen';
	$r = abbk_sr_step( $job['from'], $job['to'], false, $prefix, $st['ti'], $st['last'], $dl );
	if ( is_wp_error( $r ) ) { return $r; }
	$st['ti'] = $r['ti']; $st['last'] = $r['last']; $st['cnt'] += $r['changed'];
	if ( $r['done'] ) { $job['log'][] = 'URL-Ersetzung: ' . $st['cnt'] . ' Werte geändert.'; $job['sr_done'] = true; return true; }
	return false;
}

function abbk_rtask_swap( &$job, $man ) {
	global $wpdb;
	$bp = $man['prefix'];
	$cp = $job['cur_prefix'];
	$tables = isset( $job['tables'] ) ? $job['tables'] : array();
	if ( ! $tables ) { return new WP_Error( 'none', 'Keine Tabellen zum Tauschen.' ); }
	/* Präfix-Wechsel: Daten in Temp-Tabellen anpassen */
	if ( $bp !== $cp ) {
		$to = '`' . ABBK_TMP . $bp . 'options`'; $um = '`' . ABBK_TMP . $bp . 'usermeta`';
		$wpdb->query( $wpdb->prepare( "UPDATE $to SET option_name = CONCAT(%s, SUBSTRING(option_name, %d)) WHERE option_name LIKE %s", $cp, strlen( $bp ) + 1, $wpdb->esc_like( $bp ) . '%' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE $um SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s", $cp, strlen( $bp ) + 1, $wpdb->esc_like( $bp ) . '%' ) );
	}
	$pairs = array(); $drops = array(); $moves = array();
	foreach ( $tables as $t ) {
		$target = $cp . substr( $t, strlen( $bp ) );
		$q = function ( $x ) { return '`' . str_replace( '`', '``', $x ) . '`'; };
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $q( ABBK_OLD . $target ) );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $target ) ) );
		if ( $exists ) { $pairs[] = $q( $target ) . ' TO ' . $q( ABBK_OLD . $target ); $drops[] = ABBK_OLD . $target; }
		$pairs[] = $q( ABBK_TMP . $t ) . ' TO ' . $q( $target );
	}
	$ok = $wpdb->query( 'RENAME TABLE ' . implode( ', ', $pairs ) );
	if ( $ok === false ) { return new WP_Error( 'swap', 'Tausch fehlgeschlagen: ' . $wpdb->last_error . ' (alte Daten unverändert).' ); }
	foreach ( $drops as $d ) { $wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $d ) . '`' ); }
	wp_cache_flush();
	if ( empty( $job['sr_done'] ) ) {
		update_option( 'siteurl', $job['cur_siteurl'] );
		update_option( 'home', $job['cur_home'] );
	}
	$d = basename( abbk_root() );
	update_option( 'abbk_dir', $d, false );
	$job['log'][] = 'Datenbank getauscht. Bitte danach neu anmelden und Permalinks speichern.';
	return true;
}

function abbk_rtask_extract( &$job, $dir, $man, $dl, $comp ) {
	$st = &$job['st'];
	if ( empty( $st ) ) {
		$files = array();
		foreach ( $man['parts'] as $p ) { if ( $p['type'] === $comp ) { $files[] = abbk_pkey( $p, $man['id'] ); } }
		sort( $files );
		$st = array( 'files' => $files, 'fi' => 0, 'ei' => 0, 'n' => 0 );
	}
	$base = rtrim( WP_CONTENT_DIR, '/\\' );
	$self = 'plugins/' . basename( dirname( dirname( __FILE__ ) ) ) . '/';
	while ( $st['fi'] < count( $st['files'] ) && microtime( true ) < $dl ) {
		$zip = new ZipArchive();
		$key = $st['files'][ $st['fi'] ];
		$zp  = abbk_key_path( $key );
		if ( ! $zp || $zip->open( $zp ) !== true ) { return new WP_Error( 'zip', 'ZIP nicht lesbar: ' . $key ); }
		/* Sätze ab Format 2: nur Dateien entpacken, die laut Index zu diesem Teil gehören */
		$allow = null;
		if ( ! empty( $man['format'] ) && $man['format'] >= 2 ) {
			static $cache = array();
			$ck = $man['id'] . '|' . $key;
			if ( ! isset( $cache[ $ck ] ) ) {
				$cache = array(); $names = array();
				abbk_index_each( $dir, function ( $c, $rel, $size, $mt, $k ) use ( $comp, $key, &$names ) { if ( $c === $comp && $k === $key ) { $names[ $rel ] = 1; } } );
				$cache[ $ck ] = $names;
			}
			$allow = $cache[ $ck ];
		}
		$job['msg'] = 'Dateien entpacken: ' . $comp;
		$cnt = $zip->numFiles;
		while ( $st['ei'] < $cnt && microtime( true ) < $dl ) {
			$name = $zip->getNameIndex( $st['ei'] );
			$st['ei']++;
			if ( $name === false || substr( $name, -1 ) === '/' ) { continue; }
			if ( strpos( $name, '..' ) !== false || $name[0] === '/' || preg_match( '/^[a-zA-Z]:/', $name ) || strpos( $name, "\0" ) !== false ) { continue; }
			if ( strpos( $name, $self ) === 0 || strpos( $name, 'abbk-' ) === 0 ) { continue; } // sich selbst nicht überschreiben
			if ( $allow !== null && ! isset( $allow[ $name ] ) ) { continue; }
			$target = $base . '/' . $name;
			wp_mkdir_p( dirname( $target ) );
			$in = $zip->getStream( $name );
			if ( ! $in ) { continue; }
			$out = @fopen( $target, 'wb' );
			if ( $out ) { stream_copy_to_stream( $in, $out ); fclose( $out ); $st['n']++; }
			fclose( $in );
		}
		$zip->close();
		if ( $st['ei'] >= $cnt ) { $st['fi']++; $st['ei'] = 0; }
	}
	if ( $st['fi'] >= count( $st['files'] ) ) { $job['log'][] = $comp . ': ' . $st['n'] . ' Dateien entpackt.'; return true; }
	return false;
}

/* ================= Suchen/Ersetzen (serialisiert-sicher) ================= */
function abbk_has_incomplete( $d ) {
	if ( is_object( $d ) ) { return true; }
	if ( is_array( $d ) ) { foreach ( $d as $v ) { if ( abbk_has_incomplete( $v ) ) { return true; } } }
	return false;
}
function abbk_replace( $data, $from, $to, &$c ) {
	if ( is_string( $data ) ) {
		if ( $data !== '' && is_serialized( $data ) ) {
			$u = @unserialize( $data, array( 'allowed_classes' => false ) );
			if ( $u !== false || $data === 'b:0;' ) {
				if ( abbk_has_incomplete( $u ) ) { $c['skipped']++; return $data; }
				$n = abbk_replace( $u, $from, $to, $c );
				return $n === $u ? $data : serialize( $n );
			}
		}
		$rp = ! empty( $c['ci'] ) ? 'str_ireplace' : 'str_replace';
		$n = $rp( $from, $to, $data );
		if ( strpos( $from, '/' ) !== false ) { $n = $rp( str_replace( '/', '\/', $from ), str_replace( '/', '\/', $to ), $n ); } // JSON
		if ( $n !== $data ) { $c['hits']++; }
		return $n;
	}
	if ( is_array( $data ) ) {
		foreach ( $data as $k => $v ) { $data[ $k ] = abbk_replace( $v, $from, $to, $c ); }
	}
	return $data;
}

/* arbeitet im Zeitbudget; Rückgabe: ti, last, done, changed, rows, skipped, samples */
function abbk_sr_step( $from, $to, $dry, $prefix, $ti, $last, $dl, $only = null, $ci = false ) {
	global $wpdb;
	if ( $from === '' || $from === $to ) { return new WP_Error( 'sr', 'Suchtext leer oder gleich dem Ersatz.' ); }
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
	sort( $tables );
	if ( is_array( $only ) ) { $tables = array_values( array_intersect( $tables, $only ) ); }
	$res = array( 'ti' => (int) $ti, 'last' => $last, 'done' => false, 'changed' => 0, 'rows' => 0, 'skipped' => 0, 'samples' => array(), 'table' => '', 'per' => array(), 'nopk' => array() );
	while ( $res['ti'] < count( $tables ) && microtime( true ) < $dl ) {
		$t = $tables[ $res['ti'] ];
		$q = '`' . str_replace( '`', '``', $t ) . '`';
		$res['table'] = $t;
		$keys = $wpdb->get_results( "SHOW KEYS FROM $q WHERE Key_name = 'PRIMARY'", ARRAY_A );
		if ( ! is_array( $keys ) || count( $keys ) !== 1 ) { $res['nopk'][] = $t; $res['ti']++; $res['last'] = null; continue; }
		$pk  = $keys[0]['Column_name'];
		$pkq = '`' . str_replace( '`', '``', $pk ) . '`';
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM $q" );
		$sql = $res['last'] === null ? "SELECT * FROM $q ORDER BY $pkq LIMIT 200" : $wpdb->prepare( "SELECT * FROM $q WHERE $pkq > %s ORDER BY $pkq LIMIT 200", $res['last'] );
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$upd = array(); $c = array( 'hits' => 0, 'skipped' => 0, 'ci' => $ci );
			foreach ( $row as $col => $val ) {
				if ( $col === $pk || $col === 'guid' || $val === null || $val === '' ) { continue; }
				$n = abbk_replace( (string) $val, $from, $to, $c );
				if ( $n !== (string) $val ) { $upd[ $col ] = $n; }
			}
			$res['skipped'] += $c['skipped'];
			$res['rows']++;
			if ( ! isset( $res['per'][ $t ] ) ) { $res['per'][ $t ] = array( 0, 0 ); }
			$res['per'][ $t ][0]++;
			if ( $upd ) {
				$res['changed'] += count( $upd );
				$res['per'][ $t ][1] += count( $upd );
				if ( count( $res['samples'] ) < 8 ) { $res['samples'][] = $t . ' #' . $row[ $pk ] . ': ' . implode( ', ', array_keys( $upd ) ); }
				if ( ! $dry ) { $wpdb->update( $t, $upd, array( $pk => $row[ $pk ] ) ); }
			}
			$res['last'] = (string) $row[ $pk ];
		}
		if ( count( (array) $rows ) < 200 ) { $res['ti']++; $res['last'] = null; }
	}
	$res['done'] = $res['ti'] >= count( $tables );
	return $res;
}

/* ================= Zeitplan (WP-Cron) und Status ================= */
function abbk_interval() { $f = get_option( 'abbk_freq', 'off' ); return $f === 'daily' ? 23 * 3600 : ( $f === 'weekly' ? 6 * 86400 + 20 * 3600 : 0 ); }
function abbk_cron_setup() {
	if ( abbk_interval() ) {
		if ( ! wp_next_scheduled( 'abbk_cron_tick' ) ) { wp_schedule_event( time() + 300, 'hourly', 'abbk_cron_tick' ); }
	} else {
		wp_clear_scheduled_hook( 'abbk_cron_tick' );
	}
}
add_action( 'init', function () { if ( abbk_interval() && ! wp_next_scheduled( 'abbk_cron_tick' ) ) { abbk_cron_setup(); } } );
add_action( 'abbk_cron_tick', 'abbk_cron_tick' );
function abbk_cron_tick() {
	$iv = abbk_interval();
	if ( ! $iv ) { return; }
	$a = get_option( 'abbk_auto_run', array() );
	if ( ! empty( $a['id'] ) && ! empty( $a['start'] ) && time() - $a['start'] < 6 * 3600 ) { // läuft noch
		$j = abbk_jload( abbk_set_dir( $a['id'] ) . '/job.json' );
		if ( $j && empty( $j['done'] ) ) { return; }
	}
	if ( abbk_zeus() ) {
		foreach ( abbk_live_manifests() as $m ) { if ( empty( $m['kind'] ) ) { update_option( 'abbk_auto_run', array( 'start' => time(), 'error' => 'Eine Sicherung wartet noch auf die Kopie auf den Computer. Bitte die Seite „Sicherung“ in Chrome auf dem Computer öffnen, dann wird kopiert und der Server geleert.' ), false ); return; } }
		$li = get_option( 'abbk_last_info', array() );
		if ( ! empty( $li['created'] ) && time() - (int) $li['created'] < $iv ) { return; }
	}
	foreach ( abbk_live_manifests() as $m ) {
		if ( ( isset( $m['kind'] ) && $m['kind'] === 'pre' ) || untrailingslashit( $m['home'] ) !== abbk_home() ) { continue; }
		if ( time() - (int) $m['created'] < $iv ) { return; } // jüngste Sicherung ist neu genug
		break;
	}
	$id = abbk_backup_start( array_keys( abbk_components() ), 'Automatisch', false, '', true );
	if ( is_wp_error( $id ) ) { update_option( 'abbk_auto_run', array( 'start' => time(), 'error' => $id->get_error_message() ), false ); return; }
	update_option( 'abbk_auto_run', array( 'id' => $id, 'start' => time() ), false );
	wp_schedule_single_event( time() + 10, 'abbk_cron_step', array( $id ) );
}
add_action( 'abbk_cron_step', function ( $id ) {
	$r = abbk_backup_step( $id );
	if ( is_wp_error( $r ) ) { update_option( 'abbk_auto_run', array( 'id' => $id, 'start' => time(), 'error' => $r->get_error_message() ), false ); return; }
	if ( empty( $r['done'] ) ) { wp_schedule_single_event( time() + 15, 'abbk_cron_step', array( $id ) ); }
} );

function abbk_disk_status() {
	$sp = abbk_space(); $est = abbk_est_full(); $l = abbk_light( $sp, max( $est, 1 ) );
	$pc = (array) get_option( 'abbk_pc_ids', array() ); $home = abbk_home(); $n = 0; $nc = 0;
	foreach ( abbk_live_manifests() as $id => $m ) { if ( ( isset( $m['kind'] ) && $m['kind'] === 'pre' ) || untrailingslashit( $m['home'] ) !== $home ) { continue; } $n++; if ( ! in_array( $id, $pc, true ) ) { $nc++; } }
	$ss = get_option( 'abbk_site_size', array() );
	return array( 'light' => $l[0], 'why' => $l[1], 'total' => $sp['total'], 'free' => $sp['free'], 'module' => $sp['module'], 'src' => $sp['src'], 'est' => $est, 'site' => isset( $ss['size'] ) ? (int) $ss['size'] : null, 'sitecomp' => isset( $ss['comp'] ) ? $ss['comp'] : null, 'sitetop' => isset( $ss['top'] ) ? $ss['top'] : null, 'max' => max( 1, min( 10, (int) get_option( 'abbk_max', 3 ) ) ), 'needpc' => (bool) get_option( 'abbk_need_pc', 1 ), 'quota' => (float) get_option( 'abbk_quota_gb', 0 ), 'count' => $n, 'notpc' => $nc );
}
function abbk_status() {
	$last = null;
	foreach ( abbk_live_manifests() as $id => $m ) { if ( empty( $m['kind'] ) ) { $last = array( 'id' => $id, 'created' => (int) $m['created'], 'mode' => isset( $m['mode'] ) ? $m['mode'] : ( empty( $m['bases'] ) ? 'full' : 'inc' ), 'auto' => ! empty( $m['auto'] ), 'new' => isset( $m['new_bytes'] ) ? (int) $m['new_bytes'] : null, 'format' => isset( $m['format'] ) ? (int) $m['format'] : 1, 'label' => $m['label'] ); break; } }
	$pending = $last ? true : false;
	if ( ! $last ) { $li = get_option( 'abbk_last_info', array() ); if ( ! empty( $li['id'] ) ) { $last = array( 'id' => $li['id'], 'created' => $li['created'], 'mode' => $li['mode'], 'auto' => $li['auto'], 'new' => $li['new'], 'format' => 2, 'label' => $li['label'] ); } }
	$total = 0; $n = 0;
	foreach ( abbk_list_sets() as $s ) { $total += $s['size']; $n++; }
	$a = get_option( 'abbk_auto_run', array() );
	$iv = abbk_interval();
	$next = $iv ? ( $last ? max( time(), $last['created'] + $iv ) : time() ) : 0;
	return array(
		'last' => $last, 'sets' => $n, 'total' => $total, 'dir' => 'wp-content/' . get_option( 'abbk_dir', '' ),
		'pc' => get_option( 'abbk_pc', null ), 'freq' => get_option( 'abbk_freq', 'off' ), 'next' => $next,
		'err' => ! empty( $a['error'] ) ? $a['error'] : '', 'now' => time(),
		'disk' => abbk_disk_status(), 'zeus' => abbk_zeus(), 'pending' => abbk_zeus() ? $pending : false, 'week' => (bool) get_option( 'abbk_week', 0 ),
	);
}

/* ================= AJAX ================= */
add_action( 'wp_ajax_abbk', 'abbk_ajax' );
function abbk_ajax() {
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Keine Berechtigung.', 403 ); }
	check_ajax_referer( 'abbk', '_wpnonce' );
	$op = isset( $_REQUEST['op'] ) ? sanitize_key( $_REQUEST['op'] ) : '';
	$id = isset( $_REQUEST['id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['id'] ) ) : '';
	$ok = function ( $r ) { if ( is_wp_error( $r ) ) { wp_send_json_error( $r->get_error_message() ); } wp_send_json_success( $r ); };
	switch ( $op ) {
		case 'list':
			wp_send_json_success( abbk_list_sets() );
		case 'start':
			$comps = isset( $_POST['comps'] ) ? array_map( 'sanitize_key', (array) $_POST['comps'] ) : array();
			if ( isset( $_POST['chunk'] ) ) { update_option( 'abbk_chunk_mb', max( 5, min( 500, (int) $_POST['chunk'] ) ), false ); }
			update_option( 'abbk_month', empty( $_POST['month'] ) || $_POST['month'] === '0' ? 0 : 1, false );
				update_option( 'abbk_autoprune', ! empty( $_POST['autoprune'] ) && $_POST['autoprune'] !== '0' ? 1 : 0, false );
				$ok( abbk_backup_start( $comps, isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : '', ! empty( $_POST['full'] ) && $_POST['full'] !== '0' ) );
		case 'step':
			$ok( abbk_backup_step( $id ) );
		case 'status':
				wp_send_json_success( abbk_status() );
			case 'precheck':
				wp_send_json_success( abbk_precheck( ! empty( $_POST['full'] ) && $_POST['full'] !== '0' ) );
			case 'cfg':
				$f = isset( $_POST['freq'] ) ? sanitize_key( $_POST['freq'] ) : 'off';
				update_option( 'abbk_freq', in_array( $f, array( 'daily', 'weekly' ), true ) ? $f : 'off', false );
				abbk_cron_setup();
				if ( isset( $_POST['max'] ) ) { update_option( 'abbk_max', max( 1, min( 10, (int) $_POST['max'] ) ), false ); }
				if ( isset( $_POST['needpc'] ) ) { update_option( 'abbk_need_pc', $_POST['needpc'] === '0' ? 0 : 1, false ); }
				if ( isset( $_POST['quota'] ) ) { update_option( 'abbk_quota_gb', max( 0, min( 100000, (float) str_replace( ',', '.', $_POST['quota'] ) ) ), false ); }
				if ( isset( $_POST['zeus'] ) ) { update_option( 'abbk_zeus', $_POST['zeus'] === '0' ? 0 : 1, false ); }
				if ( isset( $_POST['week'] ) ) { update_option( 'abbk_week', $_POST['week'] === '0' ? 0 : 1, false ); }
				if ( ! empty( $_POST['rescan'] ) ) { abbk_site_size( true ); }
				wp_send_json_success( abbk_status() );
			case 'pcsaved':
				if ( ! abbk_valid_id( $id ) ) { wp_send_json_error( 'Ungültig.' ); }
				$ids = (array) get_option( 'abbk_pc_ids', array() ); $ids[] = $id; update_option( 'abbk_pc_ids', array_slice( array_values( array_unique( $ids ) ), -20 ), false );
				$rel = abbk_release( $id );
				update_option( 'abbk_pc', array( 'time' => time(), 'id' => $id, 'name' => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '' ), false );
				wp_send_json_success( abbk_status() );
			case 'prune':
				wp_send_json_success( abbk_prune( true ) );
			case 'pin':
				$p = abbk_pins();
				if ( ! abbk_valid_id( $id ) ) { wp_send_json_error( 'Ungültig.' ); }
				$p = in_array( $id, $p, true ) ? array_values( array_diff( $p, array( $id ) ) ) : array_merge( $p, array( $id ) );
				update_option( 'abbk_pins', $p, false );
				wp_send_json_success( true );
			case 'policy': // für Aufräumen auf dem eigenen Computer: welche IDs behalten?
				$sets = json_decode( isset( $_POST['sets'] ) ? wp_unslash( $_POST['sets'] ) : '[]', true );
				$pins = abbk_pins(); $in = array();
				foreach ( (array) $sets as $s ) {
					if ( ! is_array( $s ) || ! isset( $s['id'], $s['created'], $s['home'] ) || ! abbk_valid_id( $s['id'] ) ) { continue; }
					$in[] = array( 'id' => $s['id'], 'created' => (int) $s['created'], 'kind' => ( isset( $s['kind'] ) && $s['kind'] === 'pre' ) ? 'pre' : '', 'home' => (string) $s['home'], 'pinned' => in_array( $s['id'], $pins, true ) );
				}
				wp_send_json_success( abbk_policy( $in, time(), (bool) get_option( 'abbk_month', 0 ), abbk_home(), (bool) get_option( 'abbk_week', 0 ) ) );
			case 'delete':
			wp_send_json_success( abbk_delete_set( $id ) );
		case 'rstart':
			$comps = isset( $_POST['comps'] ) ? array_map( 'sanitize_key', (array) $_POST['comps'] ) : array();
			$from  = isset( $_POST['from'] ) ? trim( wp_unslash( $_POST['from'] ) ) : '';
			$to    = isset( $_POST['to'] ) ? trim( wp_unslash( $_POST['to'] ) ) : '';
			$ok( abbk_restore_start( $id, $comps, $from, $to ) );
		case 'rstep':
			$ok( abbk_restore_step( $id ) );
		case 'sr':
			$from = isset( $_POST['from'] ) ? wp_unslash( $_POST['from'] ) : '';
			$to   = isset( $_POST['to'] ) ? wp_unslash( $_POST['to'] ) : '';
			global $wpdb;
			$only = isset( $_POST['tables'] ) ? json_decode( wp_unslash( $_POST['tables'] ), true ) : null;
			$only = is_array( $only ) ? array_values( array_filter( $only, 'is_string' ) ) : null;
			$ci   = empty( $_POST['cs'] ) || $_POST['cs'] === '0'; // cs=1: Groß-/Kleinschreibung beachten
			$dry  = ! empty( $_POST['dry'] ) && $_POST['dry'] !== '0';
			$key  = md5( $from . '|' . $to . '|' . wp_json_encode( $only ) . '|' . ( $ci ? 1 : 0 ) );
			if ( ! $dry && get_option( 'abbk_sr_ok' ) !== $key ) { wp_send_json_error( 'Bitte zuerst einen Probelauf mit genau diesen Eingaben machen.' ); }
			$r = abbk_sr_step( $from, $to, $dry, $wpdb->base_prefix, isset( $_POST['ti'] ) ? (int) $_POST['ti'] : 0, isset( $_POST['last'] ) && $_POST['last'] !== '' ? wp_unslash( $_POST['last'] ) : null, abbk_deadline(), $only, $ci );
			if ( ! is_wp_error( $r ) && $r['done'] ) { if ( $dry ) { update_option( 'abbk_sr_ok', $key, false ); } else { delete_option( 'abbk_sr_ok' ); } }
			$ok( $r );
		case 'srtables':
			global $wpdb;
			$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ), ARRAY_A );
			$out = array();
			foreach ( (array) $rows as $t ) { $out[] = array( 'name' => $t['Name'], 'rows' => (int) $t['Rows'] ); }
			wp_send_json_success( $out );
		case 'srbackup':
			$ok( abbk_backup_start( array( 'db' ), 'Vor Suchen & Ersetzen', true, 'pre' ) );
		case 'up': // Teil hochladen (Umzug vom eigenen Computer)
			$name = isset( $_POST['name'] ) ? basename( sanitize_text_field( wp_unslash( $_POST['name'] ) ) ) : '';
			if ( ! abbk_valid_id( $id ) || ! abbk_part_ok( $name ) ) { wp_send_json_error( 'Ungültiger Name.' ); }
			if ( empty( $_FILES['chunk']['tmp_name'] ) ) { wp_send_json_error( 'Kein Datenblock.' ); }
			$dir = abbk_set_dir( $id );
			if ( ! $dir || ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) ) { wp_send_json_error( 'Ordner nicht anlegbar.' ); }
			$off = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
			$f   = $dir . '/' . $name . '.part';
			if ( $off === 0 && file_exists( $f ) ) { unlink( $f ); }
			if ( $off !== ( file_exists( $f ) ? filesize( $f ) : 0 ) ) { wp_send_json_error( 'Reihenfolge falsch.' ); }
			$data = file_get_contents( $_FILES['chunk']['tmp_name'] );
			file_put_contents( $f, $data, FILE_APPEND );
			if ( ! empty( $_POST['last'] ) ) { rename( $f, $dir . '/' . $name ); }
			wp_send_json_success( true );
		default:
			wp_send_json_error( 'Unbekannte Aktion.' );
	}
}

/* Download eines Teils (GET, mit Nonce) */
add_action( 'wp_ajax_abbk_dl', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Keine Berechtigung.', 403 ); }
	check_ajax_referer( 'abbk', '_wpnonce' );
	$id   = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
	$name = isset( $_GET['name'] ) ? basename( sanitize_text_field( wp_unslash( $_GET['name'] ) ) ) : '';
	if ( ! abbk_part_ok( $name ) ) { wp_die( 'Ungültig.', 400 ); }
	$dir = abbk_set_dir( $id );
	$f   = $dir ? $dir . '/' . $name : '';
	if ( ! $f || ! is_file( $f ) ) { wp_die( 'Nicht gefunden.', 404 ); }
	while ( ob_get_level() ) { ob_end_clean(); }
	nocache_headers();
	header( 'Content-Type: application/octet-stream' );
	header( 'Content-Length: ' . filesize( $f ) );
	header( 'Content-Disposition: attachment; filename="' . $name . '"' );
	readfile( $f );
	exit;
} );

/* ================= Seite ================= */
add_action( 'admin_menu', function () {
	/* eigener Menüpunkt, direkt unter „Affiliate Help“ (Position 58) */
	add_menu_page( 'Sicherung', 'Sicherung', 'manage_options', 'abspeed-backup', 'abbk_page', 'dashicons-backup', '58.5' );
}, 20 );

function abbk_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$nonce = wp_create_nonce( 'abbk' );
	$comps = abbk_components();
	$u     = wp_get_upload_dir();
	echo '<div class="wrap" id="abbk"><h1>Sicherung &amp; Umzug <small>v' . esc_html( ABBK_VER ) . '</small></h1>';
	if ( function_exists( 'abspeed_update_box' ) ) { abspeed_update_box(); }
	if ( ! class_exists( 'ZipArchive' ) ) { echo '<div class="notice notice-error"><p>PHP-Erweiterung ZipArchive fehlt – Sicherung nicht möglich.</p></div>'; }
	echo '<h2>Stand</h2><div id="abbk-status" style="background:#fff;border:1px solid #ccd0d4;padding:10px;max-width:900px">Lade …</div>';
	echo '<p><label>Automatisch sichern: <select id="abbk-freq"><option value="off">aus</option><option value="daily">täglich</option><option value="weekly">wöchentlich</option></select></label> <span class="description">läuft auf dem Server über den WordPress-Zeitplaner (startet bei Seitenaufrufen, kein offener Browser nötig).</span></p>';
	echo '<p><label><input type="checkbox" id="abbk-zeus"> Sicherungen nach der geprüften Kopie auf meinen Computer vom Server löschen (empfohlen: auf dem Server bleibt nur eine kleine Stand-Liste)</label> · <label><input type="checkbox" id="abbk-week"> auf dem Computer zusätzlich einen Wochenstand behalten</label></p>';
	echo '<p>Höchstens <input type="number" id="abbk-max" min="1" max="10" style="width:60px"> Sicherungen auf dem Server · <label><input type="checkbox" id="abbk-needpc"> erst löschen, wenn sie geprüft auf meinem Computer liegen</label> · Speicherlimit meines Tarifs (GB, optional) <input type="text" id="abbk-quota" style="width:70px"> <button class="button" id="abbk-savecfg">Speichern</button> <button class="button" id="abbk-rescan">Platzbedarf neu messen</button></p>';
	echo '<p><label><input type="checkbox" id="abbk-autopc"> Automatisch auf meinen Computer kopieren, sobald der Ordner verfügbar ist</label> <button class="button" id="abbk-pcfolder">Ordner wählen und merken</button> <button class="button" id="abbk-pcallow" style="display:none">Zugriff erlauben und jetzt kopieren</button><br><span class="description">Chrome fragt einmal nach der Ordner-Freigabe: „Bei jedem Besuch zulassen“ wählen, dann braucht es danach keine Klicks mehr (Chrome ab Version 122). Geht nur in Chrome/Edge und nur, solange dieser Admin-Tab geöffnet ist. Ohne offenen Tab wird nicht kopiert.</span></p>';
	echo '<h2>1. Neue Sicherung</h2><p>';
	foreach ( $comps as $k => $l ) { echo '<label style="margin-right:14px"><input type="checkbox" class="abbk-c" value="' . esc_attr( $k ) . '" checked> ' . esc_html( $l ) . '</label>'; }
	echo '</p><p><label>Bezeichnung <input type="text" id="abbk-label" class="regular-text" placeholder="z. B. vor Umzug"></label> <label>Teilgröße (MB) <input type="number" id="abbk-chunk" min="5" max="500" value="' . (int) get_option( 'abbk_chunk_mb', 50 ) . '" style="width:70px"></label></p>';
	echo '<p><label style="margin-right:14px"><input type="checkbox" id="abbk-full"> Volle Sicherung (alles neu, sonst nur Änderungen seit der letzten)</label> <label style="margin-right:14px"><input type="checkbox" id="abbk-month"' . ( get_option( 'abbk_month', 0 ) ? ' checked' : '' ) . '> Monatsstand behalten</label> <label><input type="checkbox" id="abbk-auto"' . ( get_option( 'abbk_autoprune', 0 ) ? ' checked' : '' ) . '> Danach automatisch aufräumen</label></p>';
	echo '<p><button class="button button-primary" id="abbk-go">Sicherung starten</button> <span id="abbk-prog"></span></p>';
	echo '<p class="description">Aufbewahrung: neuester Stand, ein Stand von ca. einer Woche, optional ca. ein Monat. „Behalten“ schützt eine Sicherung vor dem Aufräumen.</p>';
	echo '<p class="description">Wird auf dem Server in einem geschützten Ordner abgelegt. Sie enthält auch Nutzerdaten (Kommentare, E-Mails): Nicht weitergeben, nach dem Kopieren auf den eigenen Computer vom Server löschen. wp-config.php wird bewusst nicht gesichert.</p>';
	echo '<h2>2. Vorhandene Sicherungen</h2><div id="abbk-list">Lade …</div><p><button class="button" id="abbk-prune">Jetzt aufräumen (Server)</button> <button class="button" id="abbk-pcclean">Aufräumen im Ordner auf meinem Computer</button></p>';
	echo '<h2>3. Umzug: Sicherung vom eigenen Computer hochladen</h2><p><button class="button" id="abbk-up">Ordner mit Sicherung wählen und hochladen</button> <span id="abbk-upprog"></span></p>';
	echo '<h2>4. Suchen &amp; Ersetzen in der Datenbank</h2><p class="description" style="max-width:800px">Ersetzt Text in der ganzen Datenbank, auch in serialisierten Daten (Widgets, Einstellungen), ohne sie zu beschädigen. Zuerst Probelauf: Er ändert nichts und zeigt, wo etwas gefunden wird. Das echte Ersetzen legt vorher automatisch eine Sicherung der Datenbank an.</p>';
	echo '<p><input type="text" id="abbk-from" class="regular-text" placeholder="Suchen, z. B. https://alt.example"> → <input type="text" id="abbk-to" class="regular-text" placeholder="Ersetzen durch"> <label><input type="checkbox" id="abbk-cs" checked> Groß-/Kleinschreibung beachten</label></p>';
	echo '<details id="abbk-tabbox"><summary>Tabellen auswählen (<span id="abbk-tabcount">alle</span>)</summary><p><a href="#" id="abbk-taball">alle</a> · <a href="#" id="abbk-tabnone">keine</a></p><div id="abbk-tabs" style="columns:3;max-width:900px">Lade …</div></details>';
	echo '<p><button class="button" id="abbk-dry">Probelauf (ändert nichts)</button> <button class="button button-primary" id="abbk-real" disabled>Jetzt ersetzen (mit Sicherung vorher)</button></p><div id="abbk-sr"></div>';
	echo '<h2>Verlauf</h2><div id="abbk-bar" style="height:14px;background:#e0e0e0;border-radius:7px;max-width:900px;overflow:hidden"><div id="abbk-barin" style="height:100%;width:0;background:#2271b1;transition:width .3s"></div></div><p id="abbk-now" style="margin:6px 0;font-weight:600"></p><div id="abbk-err" style="display:none"></div><ul id="abbk-story" style="list-style:none;margin:0;padding:0;max-width:900px"></ul>';
	echo '<details style="margin-top:8px"><summary>Technisches Protokoll (für Fehlersuche)</summary><pre id="abbk-log" style="white-space:pre-wrap;background:#fff;border:1px solid #ccd0d4;padding:8px;max-height:240px;overflow:auto"></pre></details>';
	echo '<p class="description">Ordnerzugriff auf dem eigenen Computer: Chrome oder Edge (Windows und Mac). Safari/Firefox: Teile einzeln herunterladen.</p></div>';
	?>
<script>
(function(){
var N=<?php echo wp_json_encode( $nonce ); ?>, AJ=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, COMPS=<?php echo wp_json_encode( $comps ); ?>;
var $=function(i){return document.getElementById(i)};
function log(t){var l=$('abbk-log');l.textContent+=new Date().toLocaleTimeString()+'  '+t+'\n';l.scrollTop=l.scrollHeight;if(String(t).indexOf('FEHLER: ')===0)showErr(String(t).slice(8));}
function say(t,c){var li=document.createElement('li');li.style.cssText='padding:3px 0;color:'+(c==='err'?'#b32d2e':'#1d2327');li.textContent=(c==='err'?'✗ ':c==='wait'?'… ':'✓ ')+t;$('abbk-story').appendChild(li);}
function bar(p,t){$('abbk-barin').style.width=Math.max(0,Math.min(100,p))+'%';if(t!==undefined)$('abbk-now').textContent=t;}
function showErr(m){var c=[[/Speicherplatz|nicht geschrieben|Schreiben in Sicherungsordner|No space|ZIP nicht anlegbar/i,'Auf dem Server ist vermutlich kein Platz mehr.','Alte Sicherungen löschen („Jetzt aufräumen“ oder „Vom Server löschen“) und noch einmal starten.'],[/Nicht genug freier Speicher/i,'Auf dem Server ist nicht genug Platz für diese Sicherung.','Die letzte Sicherung zuerst auf den Computer kopieren, alte Sicherungen löschen („Jetzt aufräumen“ / „Vom Server löschen“), die Höchstzahl senken oder mehr Speicher beim Hoster buchen.'],[/Serverantwort unlesbar/i,'Der Server hat nicht richtig geantwortet, meist wegen Zeitüberschreitung oder zu wenig Arbeitsspeicher.','Teilgröße kleiner stellen (z. B. 20 MB) und neu starten. Hilft das nicht, beim Hoster nach dem PHP-Fehlerprotokoll fragen.'],[/Kopieren von|Kopie unterbrochen|Download .* HTTP|NetworkError|AbortError|Failed to fetch/i,'Die Kopie auf den Computer wurde unterbrochen (Verbindung, Laufwerk nicht erreichbar oder Browser-Zugriff entzogen).','Noch einmal „Auf meinen Computer speichern“ klicken: schon kopierte, geprüfte Teile werden übersprungen, es geht dort weiter.'],[/Größe falsch|Prüfsumme falsch/i,'Ein Teil der Sicherung ist beschädigt (Übertragung abgebrochen oder Datei verändert).','Teil erneut kopieren oder die Sicherung neu anlegen.'],[/Teil fehlt|Teil der Basis/i,'Ein Teil der Sicherung fehlt (gelöscht oder nicht vollständig hochgeladen).','Eine volle Sicherung anlegen (Häkchen „Volle Sicherung“) oder beim Umzug den Ordner mit allen Teilen wählen.'],[/ZipArchive/i,'Auf dem Server fehlt die PHP-Erweiterung „zip“.','Beim Hoster aktivieren lassen (Raidboxes/DreamHost: im Panel oder per Support).'],[/Sicherungsordner nicht anlegbar|Ordner nicht anlegbar/i,'Das Modul darf im Ordner wp-content nichts anlegen.','Schreibrechte des Ordners wp-content beim Hoster prüfen.'],[/Tabelle konnte nicht angelegt|Datenbankfehler|SQL/i,'Die Datenbank hat einen Befehl abgelehnt.','Technisches Protokoll öffnen, die Meldung kopieren und mir schicken.'],[/Probelauf/i,'Es wurde noch kein passender Probelauf gemacht.','Zuerst „Probelauf“ mit denselben Eingaben klicken.']];var u='Unbekannter Fehler.',l='Technisches Protokoll öffnen, die Meldung kopieren und mir schicken.';for(var i=0;i<c.length;i++){if(c[i][0].test(m)){u=c[i][1];l=c[i][2];break;}}var e=$('abbk-err');e.style.cssText='display:block;background:#fcf0f1;border-left:4px solid #b32d2e;padding:8px 12px;max-width:900px;margin:6px 0';e.innerHTML='<strong>Das hat nicht geklappt.</strong><br>Ursache: '+u+'<br>Lösung: '+l+'<br><small style="color:#555">Meldung: '+String(m).replace(/</g,'&lt;')+'</small>';bar(0,'Abgebrochen');}
var PH={};function phase(k,t){if(PH.k!==k){PH.k=k;say(t,'wait');}}
function human(m){var r;if(/^Datenbank: /.test(m))return['db','Datenbank wird gesichert'];if(r=m.match(/^Dateien suchen: (\w+)/))return['s'+r[1],(COMPS[r[1]]||r[1])+' werden durchsucht'];if(r=m.match(/^Dateien: (\w+) \((\d+) neu\/geändert von (\d+)/))return['n'+r[1],(COMPS[r[1]]||r[1])+': '+r[2]+' Dateien neu oder geändert, '+(r[3]-r[2])+' unverändert übernommen'];if(r=m.match(/^Dateien: (\w+) \((\d+) von (\d+)/))return['p'+r[1],(COMPS[r[1]]||r[1])+' werden gepackt: '+r[2]+' von '+r[3]];if(/^Prüfsumme: /.test(m))return['sum','Prüfsummen werden berechnet'];if(/^Prüfsumme prüfen/.test(m))return['ver','Sicherung wird auf Vollständigkeit geprüft'];if(/^Vorab-Sicherung/.test(m))return['pre','Sicherheitskopie der aktuellen Datenbank wird angelegt'];if(/^Datenbank einspielen/.test(m))return['imp','Datenbank wird eingespielt'];if(/^URLs ersetzen/.test(m))return['sr','Adressen werden angepasst'];if(r=m.match(/^Dateien entpacken: (\w+)/))return['x'+r[1],(COMPS[r[1]]||r[1])+' werden entpackt'];if(/^Start/.test(m))return['st','Start'];if(/Fertig/.test(m))return['fin','Fertig'];return['o'+m,m];}
function humanLog(l){var r;if(r=l.match(/^(\d+) Tabellen .* eingespielt, (\d+) Fehler/))return r[1]+' Datenbank-Tabellen eingespielt'+(r[2]>0?' ('+r[2]+' Hinweise, siehe technisches Protokoll)':'');if(r=l.match(/^URL-Ersetzung: (\d+) Werte/))return 'Adressen angepasst: '+r[1]+' Stellen';if(/^Datenbank getauscht/.test(l))return 'Datenbank ist umgestellt. Bitte neu anmelden und unter Einstellungen → Permalinks einmal „Speichern“ klicken';if(r=l.match(/^(\w+): (\d+) Dateien entpackt/))return (COMPS[r[1]]||r[1])+': '+r[2]+' Dateien wiederhergestellt';if(/^Vorab-Sicherung der Datenbank/.test(l))return 'Sicherheitskopie der alten Datenbank angelegt';return null;}
async function post(d,tries){var fd=new FormData();fd.append('action','abbk');fd.append('_wpnonce',N);for(var k in d){if(Array.isArray(d[k])){d[k].forEach(function(v){fd.append(k+'[]',v)});}else if(d[k] instanceof Blob){fd.append(k,d[k],'chunk');}else{fd.append(k,d[k]);}}
 tries=tries||3;var err;for(var i=0;i<tries;i++){try{var r=await fetch(AJ,{method:'POST',body:fd,credentials:'same-origin'});var t=await r.text();var j;try{j=JSON.parse(t)}catch(e){throw new Error('Serverantwort unlesbar (HTTP '+r.status+')')}
 if(!j.success)throw new Error(typeof j.data==='string'?j.data:'Fehler');return j.data;}catch(e){err=e;if(String(e.message).indexOf('Serverantwort')!==0)throw e;await new Promise(function(r){setTimeout(r,2000)});}}throw err;}
function mb(b){return (b/1048576).toFixed(1)+' MB'}
async function drive(op,id,prog){var last='';$('abbk-err').style.display='none';while(true){var r=await post({op:op,id:id});if(r.msg!==last){last=r.msg;log(r.msg);var h=human(r.msg);if(h[0]!=='fin'&&h[0]!=='st'&&!/^p/.test(h[0])||(/^p/.test(h[0])&&PH.k!==h[0]&&false))phase(h[0],h[1]);bar(r.pct,h[1]);}prog.textContent=r.pct+' %';if(r.done){bar(100,'Fertig');(r.log||[]).forEach(function(l){var t=humanLog(l);if(t)say(t);});return r;}}}
$('abbk-go').onclick=async function(){if(COPYING){alert('Es wird gerade auf den Computer kopiert. Bitte warten, bis das fertig ist.');return;}if(RUNNING){return;}var c=[].slice.call(document.querySelectorAll('.abbk-c:checked')).map(function(x){return x.value});if(!c.length){alert('Bitte Teile wählen.');return;}
 RUNNING=true;this.disabled=true;try{var pc0=await post({op:'precheck',full:$('abbk-full').checked?1:0});
 if(pc0.level==='block'){showErr('Nicht genug freier Speicher auf dem Server: frei '+mb(pc0.free)+', nötig ca. '+mb(pc0.need)+'.');RUNNING=false;this.disabled=false;return;}
 if(pc0.level!=='ok'||!pc0.zeus){var q='Achtung: Diese Sicherung wird auf dem Server angelegt und braucht dort ca. '+mb(pc0.need)+(pc0.zeus?' (nur bis sie auf dem Computer liegt)':'')+'.\n'+(pc0.free!==null?'Frei sind '+mb(pc0.free)+(pc0.total?' von '+mb(pc0.total):'')+'.':'Der freie Speicher ist nicht ermittelbar.')+(pc0.why?'\n'+pc0.why:'')+'\n\nTrotzdem anlegen?';if(!confirm(q)){RUNNING=false;this.disabled=false;return;}}
 var id=await post({op:'start',comps:c,label:$('abbk-label').value,chunk:$('abbk-chunk').value,full:$('abbk-full').checked?1:0,month:$('abbk-month').checked?1:0,autoprune:$('abbk-auto').checked?1:0});$('abbk-story').innerHTML='';PH={};log('Sicherung '+id+' gestartet');say('Sicherung gestartet','wait');await drive('step',id,$('abbk-prog'));log('Sicherung fertig.');$('abbk-prog').textContent='Fertig';await showStatus();if(ST&&ST.last)say('Sicherung fertig ('+(ST.last.mode==='inc'?'nur Änderungen'+(ST.last.new!==null?', '+mb(ST.last.new)+' neu':''):'voll')+'). Insgesamt auf dem Server: '+mb(ST.total));$('abbk-label').value='';$('abbk-full').checked=false;RUNNING=false;showStatus();if(ST&&ST.zeus){say('Jetzt wird auf den Computer kopiert; danach wird die Sicherung vom Server gelöscht.','wait');try{await autoCopy(true);}catch(e2){}}}catch(e){log('FEHLER: '+e.message);$('abbk-prog').textContent='Fehler: '+e.message;}RUNNING=false;this.disabled=false;load();};
async function load(){var L=await post({op:'list'});var h='';if(!L.length)h='<p>Noch keine Sicherung.</p>';
 L.forEach(function(s){var d=new Date(s.created*1000).toLocaleString();h+='<div style="background:#fff;border:1px solid #ccd0d4;padding:8px;margin:6px 0"><strong>'+d+'</strong> '+(s.label?'– '+s.label+' ':'')+' <em>'+mb(s.size)+'</em>'+(s.inc?' <span style="color:#555">(nur Änderungen)</span>':'')+' <span style="color:#555">neu: '+Object.keys(s.by||{}).map(function(k){return (COMPS[k]||k)+' '+mb(s.by[k])}).join(', ')+'</span>'+(s.pinned?' <strong>(behalten)</strong>':'')+(s.complete?'':' <span style="color:#b32d2e">(unvollständig)</span>')+'<br>';
 if(s.complete){h+='Enthält: '+s.comps.map(function(c){return COMPS[c]||c}).join(', ')+' · Quelle: '+s.home+'<br>';
 h+='<button class="button" data-a="pc" data-id="'+s.id+'">Auf meinen Computer speichern</button> <button class="button" data-a="rs" data-id="'+s.id+'" data-home="'+s.home+'" data-comps="'+s.comps.join(',')+'">Wiederherstellen …</button> ';}
 if(s.complete)h+='<button class="button" data-a="pin" data-id="'+s.id+'">'+(s.pinned?'Nicht mehr behalten':'Behalten')+'</button> ';h+='<button class="button" data-a="del" data-id="'+s.id+'">Vom Server löschen</button></div>';});
 $('abbk-list').innerHTML=h;}
$('abbk-list').addEventListener('click',async function(ev){var b=ev.target.closest('button');if(!b)return;var a=b.dataset.a,id=b.dataset.id;
 if(a==='del'){if(!confirm('Diese Sicherung vom Server löschen?'))return;await post({op:'delete',id:id});load();}
 if(a==='pc'){savePC(id,b);}
 if(a==='pin'){await post({op:'pin',id:id});load();}
 if(a==='rs'){restore(id,b.dataset.comps.split(','),b.dataset.home);}});
var COPYING=false,RUNNING=false;
async function savePC(id,btn,root0,quiet){if(!window.showDirectoryPicker){alert('Ordnerzugriff nur in Chrome/Edge. Bitte dort öffnen oder Teile einzeln herunterladen.');return;}
 if(COPYING){if(!quiet)alert('Es wird gerade schon kopiert. Bitte warten, bis es fertig ist.');return;}
 if(RUNNING){if(!quiet)alert('Es läuft gerade eine Sicherung oder Wiederherstellung. Bitte warten, bis sie fertig ist.');return;}
 var root=root0;if(!root){try{root=await window.showDirectoryPicker({mode:'readwrite'});await idbSet(root);}catch(e){return;}}
 var L=await post({op:'list'});var s=L.filter(function(x){return x.id===id})[0];if(!s)return;if(btn)btn.disabled=true;COPYING=true;
 var list=s.parts.map(function(p){return {name:p.name,size:p.size,sha256:p.sha256,own:p.set||id,srv:p.srv!==false}}).concat([{name:'manifest.json',size:0,sha256:'',own:id}]);
 $('abbk-err').style.display='none';$('abbk-story').innerHTML='';PH={};say('Kopie auf den Computer gestartet ('+list.length+' Teile)','wait');
 var cur='';
 try{for(var i=0;i<list.length;i++){var p=list[i];cur=p.own+'/'+p.name;bar(Math.round(100*i/list.length),'Kopiere '+(i+1)+' von '+list.length+': '+p.name);
  var sub=await root.getDirectoryHandle(p.own,{create:true});
  if(!p.srv){var zf=null;try{zf=await(await sub.getFileHandle(p.name)).getFile();}catch(e){}
   if(!zf||(p.size&&zf.size!==p.size))throw new Error('Basisteil '+cur+' fehlt auf dem Computer. Bitte eine volle Sicherung machen (Häkchen „Volle Sicherung“) oder den Ordner wählen, in dem die frühere Sicherung liegt.');
   log('Basisteil liegt schon auf dem Computer: '+cur);continue;}
  var have=false;try{var f=await(await sub.getFileHandle(p.name)).getFile();if(p.size&&f.size===p.size){have=p.sha256&&p.size<400*1048576?(await sha(await f.arrayBuffer()))===p.sha256:true;}}catch(e){}
  if(have){log('Schon vorhanden: '+cur);continue;}
  var ok=false,err=null;
  for(var t=1;t<=3&&!ok;t++){try{
    var r=await fetch(AJ+'?action=abbk_dl&_wpnonce='+N+'&id='+p.own+'&name='+encodeURIComponent(p.name),{credentials:'same-origin'});if(!r.ok)throw new Error('Download '+p.name+' HTTP '+r.status);
    var w=await(await sub.getFileHandle(p.name,{create:true})).createWritable();
    try{await r.body.pipeTo(w);}catch(e){try{await w.abort();}catch(x){}throw e;}
    var f2=await(await sub.getFileHandle(p.name)).getFile();
    if(p.size&&f2.size!==p.size)throw new Error('Größe falsch bei '+p.name+' ('+f2.size+' statt '+p.size+' Byte)');
    if(p.sha256&&p.size<400*1048576){if((await sha(await f2.arrayBuffer()))!==p.sha256)throw new Error('Prüfsumme falsch: '+p.name);}
    ok=true;log('Kopiert und geprüft: '+cur);
   }catch(e){err=e;log('Versuch '+t+' von 3 für '+cur+' fehlgeschlagen: '+(e.name||'')+' '+e.message);try{await sub.removeEntry(p.name);}catch(x){}await new Promise(function(r2){setTimeout(r2,3000)});}}
  if(!ok)throw new Error('Kopieren von '+cur+' nach 3 Versuchen abgebrochen: '+(err?(err.name||'')+' '+err.message:''));
 }
 bar(100,'Fertig');say('Alle '+list.length+' Teile liegen im Ordner „'+root.name+'“ (vorhandene Teile wurden übersprungen).');
 await post({op:'pcsaved',id:id,name:root.name});COPYING=false;showStatus();load();if(!quiet)alert('Fertig. Ordner: '+id);}
 catch(e){say('Kopie unterbrochen bei '+cur+'. Beim nächsten Start werden schon kopierte, geprüfte Teile übersprungen.','err');log('FEHLER: Kopie unterbrochen – '+e.message);}
 COPYING=false;if(btn)btn.disabled=false;}
async function sha(buf){var d=await crypto.subtle.digest('SHA-256',buf);return [].map.call(new Uint8Array(d),function(x){return ('0'+x.toString(16)).slice(-2)}).join('');}
$('abbk-up').onclick=async function(){if(!window.showDirectoryPicker){alert('Nur in Chrome/Edge.');return;}
 try{var dir=await window.showDirectoryPicker({mode:'read'});}catch(e){return;}
 var RX=/^[0-9]{8}-[0-9]{6}-[a-z0-9]{4,8}$/;this.disabled=true;var P=$('abbk-upprog');
 try{var dirs={},mans={};
  async function scan(h,id){try{var mh=await h.getFileHandle('manifest.json');var m=JSON.parse(await(await mh.getFile()).text());dirs[id]=h;mans[id]=m;}catch(e){dirs[id]=h;}}
  if(RX.test(dir.name)){await scan(dir,dir.name);}
  for await(var e of dir.values()){if(e.kind==='directory'&&RX.test(e.name)){await scan(await dir.getDirectoryHandle(e.name),e.name);}}
  var ids=Object.keys(mans).sort(function(a,b){return mans[b].created-mans[a].created});if(!ids.length){alert('Keine Sicherung (manifest.json) im gewählten Ordner gefunden. Bitte den Ordner wählen, der die Sicherungsordner (Name wie 20261120-194500-ab12c) enthält.');this.disabled=false;return;}
  var id=ids[0];if(ids.length>1){var c=prompt('Welche Sicherung hochladen? Neueste ist vorgewählt:\n'+ids.map(function(i){return i+'  '+new Date(mans[i].created*1000).toLocaleString()+' '+(mans[i].label||'')}).join('\n'),id);if(!c)return;id=c.trim();if(!mans[id]){alert('Unbekannte ID.');return;}}
  var man=mans[id];var up=man.parts.map(function(p){return {own:p.set||id,name:p.name}});
  for(var i=0;i<up.length;i++){var o=up[i];if(!dirs[o.own]){throw new Error('Ordner '+o.own+' fehlt (enthält Teile der Sicherung). Bitte den übergeordneten Ordner wählen.');}
   var f=await(await dirs[o.own].getFileHandle(o.name)).getFile();var CH=4*1048576,off=0;do{var part=f.slice(off,off+CH);var last=(off+CH>=f.size)?1:'';await post({op:'up',id:o.own,name:o.name,offset:off,last:last,chunk:part});off+=CH;P.textContent=o.name+' '+Math.min(100,Math.round(100*off/Math.max(1,f.size)))+' % ('+(i+1)+'/'+(up.length+1)+')';}while(off<f.size);log('Hochgeladen: '+o.own+'/'+o.name);}
  var mf=await(await dirs[id].getFileHandle('manifest.json')).getFile();await post({op:'up',id:id,name:'manifest.json',offset:0,last:1,chunk:mf});
  P.textContent='Fertig';log('Sicherung '+id+' hochgeladen.');load();}catch(e){log('FEHLER: '+e.message);P.textContent='Fehler: '+e.message;}this.disabled=false;};
$('abbk-prune').onclick=async function(){this.disabled=true;try{var l=await post({op:'prune'});log('Aufgeräumt. '+(l.length?l.join(' '):'Nichts zu entfernen.'));}catch(e){log('FEHLER: '+e.message);}this.disabled=false;load();};
$('abbk-pcclean').onclick=async function(){if(!window.showDirectoryPicker){alert('Nur in Chrome/Edge.');return;}
 var RX=/^[0-9]{8}-[0-9]{6}-[a-z0-9]{4,8}$/;try{var root=await window.showDirectoryPicker({mode:'readwrite'});}catch(e){return;}this.disabled=true;
 try{var mans={},dirs={};for await(var e of root.values()){if(e.kind==='directory'&&RX.test(e.name)){var h=await root.getDirectoryHandle(e.name);dirs[e.name]=h;try{mans[e.name]=JSON.parse(await(await(await h.getFileHandle('manifest.json')).getFile()).text());}catch(x){}}}
  var sets=Object.keys(mans).map(function(i){return {id:i,created:mans[i].created,home:mans[i].home,kind:mans[i].kind||''}});if(!sets.length){alert('Keine Sicherungen im Ordner.');this.disabled=false;return;}
  var keep=await post({op:'policy',sets:JSON.stringify(sets)});var need={};keep.forEach(function(i){if(mans[i])mans[i].parts.forEach(function(p){need[(p.set||i)+'/'+p.name]=1});});
  var del=[],tot=0;for(var id in dirs){for await(var f of dirs[id].values()){if(f.kind!=='file')continue;var keepit=f.name==='manifest.json'?keep.indexOf(id)>-1:!!need[id+'/'+f.name];if(!keepit){var fl=await f.getFile();tot+=fl.size;del.push([id,f.name]);}}}
  if(!del.length){alert('Nichts aufzuräumen. Behalten: '+keep.join(', '));this.disabled=false;return;}
  if(!confirm(del.length+' Dateien ('+mb(tot)+') im Ordner löschen? Behalten werden: '+keep.join(', ')+'. Hinweis: Nur Sicherungen dieser Webseite werden nach der Regel aufgeräumt.')){this.disabled=false;return;}
  for(var i=0;i<del.length;i++){await dirs[del[i][0]].removeEntry(del[i][1]);}
  for(var id2 in dirs){var n=0;for await(var g of dirs[id2].values()){n++;}if(!n)await root.removeEntry(id2);}
  log('Computer-Ordner aufgeräumt: '+del.length+' Dateien ('+mb(tot)+') gelöscht.');}catch(e){log('FEHLER: '+e.message);}this.disabled=false;};
async function restore(id,comps,home){var sel=[];var msg='Wiederherstellen. Teile wählen (kommagetrennt aus: '+comps.join(', ')+'):';var c=prompt(msg,comps.join(','));if(!c)return;sel=c.split(',').map(function(x){return x.trim()}).filter(Boolean);
 var from='',to='';if(sel.indexOf('db')>-1&&home&&home.replace(/\/$/,'')!==<?php echo wp_json_encode( untrailingslashit( home_url() ) ); ?>){if(confirm('Die Sicherung stammt von '+home+', diese Seite ist <?php echo esc_js( untrailingslashit( home_url() ) ); ?>. Adressen automatisch ersetzen?')){from=home.replace(/\/$/,'');to=<?php echo wp_json_encode( untrailingslashit( home_url() ) ); ?>;}}
 if(!confirm('Wirklich wiederherstellen? Vorher wird automatisch eine Sicherung der aktuellen Datenbank angelegt. Danach ggf. neu anmelden.'))return;
 if(COPYING||RUNNING){alert('Es läuft gerade etwas anderes (Sicherung oder Kopie). Bitte warten.');return;}RUNNING=true;var P=$('abbk-prog');try{$('abbk-story').innerHTML='';PH={};var rid=await post({op:'rstart',id:id,comps:sel,from:from,to:to});log('Wiederherstellung '+rid+' gestartet');say('Wiederherstellung gestartet','wait');var r=await drive('rstep',rid,P);(r.log||[]).forEach(log);log('Wiederherstellung fertig. Bitte neu anmelden, Permalinks speichern, Seite prüfen.');alert('Fertig. Bitte neu anmelden und die Seite prüfen.');}catch(e){log('FEHLER: '+e.message);P.textContent='Fehler: '+e.message;}RUNNING=false;}
var SRKEY='';
function srTabs(){return [].slice.call(document.querySelectorAll('.abbk-t:checked')).map(function(x){return x.value});}
function srKey(){return $('abbk-from').value+'|'+$('abbk-to').value+'|'+srTabs().join(',')+'|'+($('abbk-cs').checked?1:0);}
function srCount(){var n=srTabs().length,t=document.querySelectorAll('.abbk-t').length;$('abbk-tabcount').textContent=n===t?'alle '+t:n+' von '+t;}
async function loadTabs(){try{var T=await post({op:'srtables'});$('abbk-tabs').innerHTML=T.map(function(t){return '<label style="display:block"><input type="checkbox" class="abbk-t" value="'+t.name+'" checked> '+t.name+' <small style="color:#777">('+t.rows+')</small></label>'}).join('');srCount();}catch(e){$('abbk-tabs').textContent='Tabellen nicht ladbar: '+e.message;}}
$('abbk-tabs').addEventListener('change',function(){srCount();$('abbk-real').disabled=true;});
$('abbk-taball').onclick=function(e){e.preventDefault();[].forEach.call(document.querySelectorAll('.abbk-t'),function(x){x.checked=true});srCount();$('abbk-real').disabled=true;};
$('abbk-tabnone').onclick=function(e){e.preventDefault();[].forEach.call(document.querySelectorAll('.abbk-t'),function(x){x.checked=false});srCount();$('abbk-real').disabled=true;};
['abbk-from','abbk-to','abbk-cs'].forEach(function(i){$(i).addEventListener('input',function(){$('abbk-real').disabled=true;});});
async function srRun(dry,from,to,tabs){var ti=0,last='',tot=0,rows=0,sk=0,samples=[],per={},nopk=[];var o=$('abbk-sr');
 while(true){var r=await post({op:'sr',from:from,to:to,dry:dry?1:0,ti:ti,last:last||'',tables:JSON.stringify(tabs),cs:$('abbk-cs').checked?1:0});tot+=r.changed;rows+=r.rows;sk+=r.skipped;samples=samples.concat(r.samples).slice(0,10);ti=r.ti;last=r.last||'';nopk=nopk.concat(r.nopk||[]);
  for(var t in (r.per||{})){per[t]=per[t]||[0,0];per[t][0]+=r.per[t][0];per[t][1]+=r.per[t][1];}
  bar(Math.round(100*ti/Math.max(1,tabs.length)),(dry?'Probelauf: ':'Ersetzen: ')+(r.table||'')+' – '+tot+' Treffer bisher');
  if(r.done)break;}
 var h='<p><strong>'+(dry?'Probelauf fertig – es wurde nichts geändert.':'Ersetzen fertig.')+'</strong> '+tot+' Stellen in '+rows+' geprüften Datenbank-Zeilen'+(dry?' würden geändert.':' geändert.')+'</p>';
 var keys=Object.keys(per).filter(function(k){return per[k][1]>0});
 if(keys.length){h+='<table class="widefat striped" style="max-width:700px"><thead><tr><th>Tabelle</th><th>Zeilen geprüft</th><th>Treffer</th></tr></thead><tbody>'+keys.map(function(k){return '<tr><td>'+k+'</td><td>'+per[k][0]+'</td><td>'+per[k][1]+'</td></tr>'}).join('')+'</tbody></table>';}else{h+='<p>Nirgends gefunden.</p>';}
 if(sk)h+='<p style="color:#996800">'+sk+' Werte mit Objekten wurden aus Sicherheitsgründen übersprungen.</p>';
 if(nopk.length)h+='<p style="color:#996800">Übersprungen, weil ohne eindeutigen Schlüssel: '+nopk.join(', ')+'</p>';
 o.innerHTML=h;return tot;}
async function sr(dry){var from=$('abbk-from').value,to=$('abbk-to').value,tabs=srTabs();if(!from){alert('Bitte bei „Suchen“ etwas eintragen.');return;}if(from===to){alert('„Suchen“ und „Ersetzen“ sind gleich.');return;}if(!tabs.length){alert('Bitte mindestens eine Tabelle wählen.');return;}
 $('abbk-err').style.display='none';$('abbk-dry').disabled=true;$('abbk-real').disabled=true;
 try{if(dry){var tot=await srRun(true,from,to,tabs);SRKEY=srKey();bar(100,'Probelauf fertig');$('abbk-real').disabled=false;}
  else{if(SRKEY!==srKey()){alert('Bitte zuerst einen Probelauf mit genau diesen Eingaben machen.');return;}
   if(!confirm('Wirklich ersetzen? Zuerst wird automatisch eine Sicherung der Datenbank angelegt.'))return;
   $('abbk-story').innerHTML='';PH={};say('Sicherheitskopie der Datenbank wird angelegt','wait');var pid=await post({op:'srbackup'});await drive('step',pid,$('abbk-prog'));say('Sicherheitskopie fertig (Vorab-Sicherung in der Liste)');
   say('Ersetzen läuft','wait');var tot2=await srRun(false,from,to,tabs);say('Ersetzen fertig: '+tot2+' Stellen geändert');bar(100,'Fertig');$('abbk-real').disabled=true;}
 }catch(e){log('FEHLER: '+e.message);}finally{$('abbk-dry').disabled=false;}}
$('abbk-dry').onclick=function(){sr(true)};$('abbk-real').onclick=function(){sr(false)};loadTabs();
/* ---- Stand, Zeitplan, automatische Kopie ---- */
function idb(){return new Promise(function(res,rej){var q=indexedDB.open('abbk',1);q.onupgradeneeded=function(){q.result.createObjectStore('h');};q.onsuccess=function(){res(q.result)};q.onerror=function(){rej(q.error)};});}
async function idbSet(h){var d=await idb();return new Promise(function(res){var t=d.transaction('h','readwrite');t.objectStore('h').put(h,'dir');t.oncomplete=res;});}
async function idbGet(){try{var d=await idb();return await new Promise(function(res){var r=d.transaction('h').objectStore('h').get('dir');r.onsuccess=function(){res(r.result||null)};r.onerror=function(){res(null)};});}catch(e){return null;}}
function ago(t){var d=Math.round((Date.now()/1000-t)/60);if(d<2)return 'gerade eben';if(d<120)return 'vor '+d+' Min.';if(d<2880)return 'vor '+Math.round(d/60)+' Std.';return 'vor '+Math.round(d/1440)+' Tagen';}
var ST=null;
async function showStatus(){ST=await post({op:'status'});var s=ST,h='';var fr={off:'aus',daily:'täglich',weekly:'wöchentlich'};
 if(s.last){h+='<p><strong>Letzte Sicherung:</strong> '+new Date(s.last.created*1000).toLocaleString()+' ('+ago(s.last.created)+') · '+(s.last.mode==='inc'?'nur Änderungen'+(s.last.new!==null?' (neu: '+mb(s.last.new)+')':''):'voll')+' · '+(s.last.auto?'automatisch':'von Hand')+'</p>';}else{h+='<p><strong>Letzte Sicherung:</strong> noch keine</p>';}
 h+='<p><strong>Auf dem Server:</strong> '+s.dir+' · '+s.sets+' Sicherung(en), zusammen '+mb(s.total)+'</p>';
 if(s.zeus&&s.pending)h+='<p style="color:#996800"><strong>Wartet auf Kopie:</strong> Die Sicherung liegt noch auf dem Server und wird gelöscht, sobald sie geprüft auf dem Computer liegt (Admin-Tab in Chrome auf dem Computer öffnen).</p>';
 if(s.pc){var old=s.last&&s.pc.id!==s.last.id;h+='<p><strong>Kopie auf meinem Computer:</strong> Ordner „'+s.pc.name+'“, zuletzt '+new Date(s.pc.time*1000).toLocaleString()+' ('+ago(s.pc.time)+')'+(old?' <span style="color:#b32d2e">– neuere Sicherung noch nicht kopiert</span>':' – aktuell')+'</p>';}else{h+='<p><strong>Kopie auf meinem Computer:</strong> <span style="color:#b32d2e">noch nie kopiert</span></p>';}
var D=s.disk;if(D){var col={gruen:'#00a32a',gelb:'#dba617',rot:'#d63638',grau:'#8c8f94'}[D.light]||'#8c8f94';var nm={gruen:'Grün',gelb:'Gelb',rot:'Rot',grau:'Unbekannt'}[D.light];
  h+='<p><strong>Speicher:</strong> <span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:'+col+';vertical-align:middle"></span> <strong>'+nm+'</strong> – '+D.why+'<br>'+(D.total!==null?'Gesamt '+mb(D.total)+(D.src==='quota'?' (Tarif-Limit)':' (Datenträger des Servers)')+' · frei '+mb(D.free)+' · ':'')+'Sicherungen belegen '+mb(D.module)+(D.site!==null?' · Seite ca. '+mb(D.site):'')+' · erwartete volle Sicherung ca. '+mb(D.est)+'<br>'+(D.sitecomp?'Davon: '+Object.keys(D.sitecomp).map(function(k){return (COMPS[k]||k)+' '+mb(D.sitecomp[k])}).join(', ')+'<br>':'')+(D.sitetop?'Größte Ordner: '+Object.keys(D.sitetop).map(function(k){return k+' '+mb(D.sitetop[k])}).join(', ')+'<br>':'')+D.count+' von höchstens '+D.max+' Sicherungen auf dem Server'+(D.notpc>0?', davon '+D.notpc+' noch nicht auf meinem Computer':'')+'</p>';
  if(!window._cfgset){window._cfgset=1;$('abbk-zeus').checked=s.zeus;$('abbk-week').checked=s.week;$('abbk-max').value=D.max;$('abbk-needpc').checked=D.needpc;$('abbk-quota').value=D.quota||'';}}
 h+='<p><strong>Zeitplan:</strong> '+fr[s.freq]+(s.freq!=='off'?', nächste Sicherung ab '+new Date(s.next*1000).toLocaleString():'')+'</p>';
 if(s.err)h+='<p style="color:#b32d2e">Letzter automatischer Lauf: Fehler – '+s.err+'</p>';
 $('abbk-status').innerHTML=h;$('abbk-freq').value=s.freq;}
$('abbk-savecfg').onclick=async function(){await post({op:'cfg',zeus:$('abbk-zeus').checked?1:0,week:$('abbk-week').checked?1:0,freq:$('abbk-freq').value,max:$('abbk-max').value,needpc:$('abbk-needpc').checked?1:0,quota:$('abbk-quota').value});log('Einstellungen gespeichert.');showStatus();};
$('abbk-rescan').onclick=async function(){this.disabled=true;bar(0,'Seitengröße wird gemessen …');await post({op:'cfg',freq:$('abbk-freq').value,rescan:1});bar(100,'Gemessen');showStatus();this.disabled=false;};
$('abbk-freq').onchange=async function(){await post({op:'cfg',freq:this.value});showStatus();};
var AUTO=localStorage.getItem('abbk_autopc')==='1';$('abbk-autopc').checked=AUTO;
$('abbk-autopc').onchange=function(){AUTO=this.checked;localStorage.setItem('abbk_autopc',AUTO?'1':'0');if(AUTO)autoCopy(true);};
$('abbk-pcfolder').onclick=async function(){if(!window.showDirectoryPicker){alert('Nur in Chrome/Edge.');return;}try{var r=await window.showDirectoryPicker({mode:'readwrite'});await idbSet(r);log('Ordner gemerkt: '+r.name);autoCopy(true);}catch(e){}};
$('abbk-pcallow').onclick=function(){autoCopy(true);};
var BUSY=false;
async function autoCopy(interactive){if(BUSY||COPYING||RUNNING||!window.showDirectoryPicker||(!AUTO&&!interactive))return;var h=await idbGet();if(!h){if(interactive)alert('Bitte zuerst „Ordner wählen und merken“.');return;}
 var perm=await h.queryPermission({mode:'readwrite'});if(perm!=='granted'){if(interactive){perm=await h.requestPermission({mode:'readwrite'});}}
 if(perm!=='granted'){$('abbk-pcallow').style.display='';return;}$('abbk-pcallow').style.display='none';
 try{await h.values().next();}catch(e){log('Ordner auf dem Computer nicht erreichbar (Laufwerk nicht verbunden?)');return;}
 var L=await post({op:'list'});var s=L.filter(function(x){return x.complete&&!x.kind})[0];if(!s)return;var st=await post({op:'status'});if(st.pc&&st.pc.id===s.id&&!interactive)return;
 BUSY=true;try{await savePC(s.id,null,h,true);}finally{BUSY=false;}}
setInterval(function(){showStatus();autoCopy(false);},600000);
showStatus().then(function(){autoCopy(false);});
load();
})();
</script>
	<?php
}
