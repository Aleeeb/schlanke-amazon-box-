<?php
/* 1AB Speed – Modul Überwachung: Diese Seite (die Zentrale) prüft alle 10 Minuten, ob die anderen Seiten erreichbar sind.
   Standardmäßig aus. Zwei Fehlschläge hintereinander = eine Mail „Ausfall“, danach eine Mail „wieder erreichbar“. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'ABMON_IV', 600 );

function abmon_urls() {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) get_option( 'abmon_urls', '' ) ) as $l ) {
		$l = trim( $l );
		if ( $l === '' ) { continue; }
		if ( ! preg_match( '#^https?://#i', $l ) ) { $l = 'https://' . $l; }
		$out[ esc_url_raw( $l ) ] = 1;
	}
	return array_keys( $out );
}
function abmon_mail() { $m = sanitize_email( (string) get_option( 'abmon_mail', 'info@schneckenhilfe.de' ) ); return is_email( $m ) ? $m : ''; }

/** Eine Adresse prüfen: leichte Anfrage (nur Kopfzeile). Gibt array( ok, code, ms, msg ) */
function abmon_probe( $url ) {
	$t0 = microtime( true );
	$r  = wp_remote_head( $url, array( 'timeout' => 10, 'redirection' => 3, 'user-agent' => '1AB-Speed-Ueberwachung', 'sslverify' => true ) );
	if ( ! is_wp_error( $r ) && in_array( (int) wp_remote_retrieve_response_code( $r ), array( 405, 501 ), true ) ) { // HEAD nicht erlaubt: ersatzweise normale Anfrage
		$r = wp_remote_get( $url, array( 'timeout' => 10, 'redirection' => 3, 'user-agent' => '1AB-Speed-Ueberwachung', 'limit_response_size' => 2048 ) );
	}
	$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
	if ( is_wp_error( $r ) ) { return array( 'ok' => false, 'code' => 0, 'ms' => $ms, 'msg' => $r->get_error_message() ); }
	$c = (int) wp_remote_retrieve_response_code( $r );
	return array( 'ok' => $c > 0 && $c < 400, 'code' => $c, 'ms' => $ms, 'msg' => $c >= 400 ? 'HTTP ' . $c : '' );
}

/** Mail verschicken (wenn Adresse da). Gibt true bei Versand. */
function abmon_notify( $subject, $body ) {
	$to = abmon_mail();
	if ( $to === '' ) { return false; }
	return (bool) wp_mail( $to, '[1AB Speed] ' . $subject, $body );
}

/** Alle Seiten prüfen und Zustände nachführen. $probe: Funktion zum Prüfen (für Tests austauschbar). */
function abmon_run( $probe = 'abmon_probe' ) {
	$st  = (array) get_option( 'abmon_state', array() );
	$now = time();
	foreach ( abmon_urls() as $u ) {
		$p = call_user_func( $probe, $u );
		$s = isset( $st[ $u ] ) ? (array) $st[ $u ] : array( 'fails' => 0, 'down_since' => 0, 'mailed' => 0 );
		$s['last'] = $now; $s['code'] = $p['code']; $s['ms'] = $p['ms']; $s['msg'] = $p['msg']; $s['ok'] = $p['ok'];
		if ( $p['ok'] ) {
			if ( ! empty( $s['mailed'] ) ) {
				$min = max( 1, (int) round( ( $now - (int) $s['down_since'] ) / 60 ) );
				abmon_notify( 'Wieder erreichbar: ' . $u, $u . " ist wieder erreichbar (Antwort " . $p['code'] . ', ' . $p['ms'] . " ms).\nAusfall seit ca. " . $min . ' Minuten (' . gmdate( 'd.m.Y H:i', (int) $s['down_since'] ) . " UTC).\n" );
			}
			$s['fails'] = 0; $s['down_since'] = 0; $s['mailed'] = 0;
		} else {
			$s['fails'] = (int) $s['fails'] + 1;
			if ( $s['fails'] === 1 ) { $s['down_since'] = $now; }
			if ( $s['fails'] >= 2 && empty( $s['mailed'] ) ) {
				$s['mailed'] = abmon_notify( 'AUSFALL: ' . $u, $u . " ist seit ca. " . gmdate( 'd.m.Y H:i', (int) $s['down_since'] ) . " UTC nicht erreichbar.\nLetzter Fehler: " . ( $p['msg'] !== '' ? $p['msg'] : 'unbekannt' ) . ".\nGeprüft von " . home_url() . " alle 10 Minuten; zwei Fehlschläge hintereinander lösen diese Mail aus.\n" ) ? $now : 0;
				$s['mail_failed'] = $s['mailed'] ? 0 : 1; // Mailversand selbst gescheitert: im nächsten Lauf nochmal versuchen
			}
		}
		$st[ $u ] = $s;
	}
	foreach ( array_keys( $st ) as $k ) { if ( ! in_array( $k, abmon_urls(), true ) ) { unset( $st[ $k ] ); } } // entfernte Adressen vergessen
	update_option( 'abmon_state', $st, false );
	update_option( 'abmon_lastrun', $now, false );
	return $st;
}

add_action( 'abmon_cron', function () {
	if ( defined( 'ABSPEED_OFF' ) && ABSPEED_OFF ) { return; }
	if ( get_option( 'abmon_on' ) && abmon_urls() ) { abmon_run(); }
	if ( get_option( 'abmon_on' ) ) { wp_schedule_single_event( time() + ABMON_IV, 'abmon_cron' ); }
} );
/* Falls der Zeitplan fehlt (z. B. nach Umzug), bei einem Aufruf wieder anlegen */
add_action( 'init', function () {
	if ( get_option( 'abmon_on' ) && ! wp_next_scheduled( 'abmon_cron' ) ) { wp_schedule_single_event( time() + 60, 'abmon_cron' ); }
} );

add_action( 'admin_menu', function () {
	add_submenu_page( 'abspeed', 'Überwachung', 'Überwachung', 'manage_options', 'abspeed-monitor', 'abmon_page' );
}, 30 );

function abmon_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	if ( ! empty( $_POST['abmon_save'] ) && check_admin_referer( 'abmon_save' ) ) {
		update_option( 'abmon_urls', sanitize_textarea_field( wp_unslash( isset( $_POST['abmon_urls'] ) ? $_POST['abmon_urls'] : '' ) ), false );
		update_option( 'abmon_mail', sanitize_email( wp_unslash( isset( $_POST['abmon_mail'] ) ? $_POST['abmon_mail'] : '' ) ), false );
		$on = empty( $_POST['abmon_on'] ) ? 0 : 1;
		update_option( 'abmon_on', $on, false );
		wp_clear_scheduled_hook( 'abmon_cron' );
		if ( $on ) { wp_schedule_single_event( time() + 30, 'abmon_cron' ); }
		echo '<div class="notice notice-success"><p>Gespeichert.</p></div>';
	}
	if ( ! empty( $_POST['abmon_now'] ) && check_admin_referer( 'abmon_now' ) ) { abmon_run(); echo '<div class="notice notice-success"><p>Geprüft.</p></div>'; }
	if ( ! empty( $_POST['abmon_test'] ) && check_admin_referer( 'abmon_now' ) ) {
		$ok = abmon_notify( 'Testmail', 'Diese Testmail kommt von ' . home_url() . ". Die Überwachung schickt Ausfallmeldungen an diese Adresse.\n" );
		echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . '"><p>' . ( $ok ? 'Testmail verschickt an ' . esc_html( abmon_mail() ) . '.' : 'Testmail konnte nicht verschickt werden (keine gültige Adresse oder der Server versendet keine Mails).' ) . '</p></div>';
	}
	$st = (array) get_option( 'abmon_state', array() );
	echo '<div class="wrap"><h1>Überwachung <small>1AB Speed v' . esc_html( ABSPEED_VER ) . '</small></h1>';
	if ( function_exists( 'abspeed_update_box' ) ) { abspeed_update_box(); }
	echo '<p>Diese Seite fragt alle 10 Minuten bei den unten eingetragenen Seiten an (nur Kopfzeile, wenige hundert Bytes). Nach zwei Fehlschlägen hintereinander kommt eine Mail, und eine weitere, wenn die Seite wieder läuft. Nur auf der Zentrale einschalten.</p>';
	echo '<form method="post">'; wp_nonce_field( 'abmon_save' );
	echo '<p><label><input type="checkbox" name="abmon_on" value="1" ' . checked( (bool) get_option( 'abmon_on' ), true, false ) . '> Überwachung einschalten</label></p>';
	echo '<p><label>Seiten (eine Adresse pro Zeile)<br><textarea name="abmon_urls" rows="8" cols="60" placeholder="https://beispiel.de">' . esc_textarea( (string) get_option( 'abmon_urls', '' ) ) . '</textarea></label></p>';
	echo '<p><label>Mail an <input type="email" name="abmon_mail" class="regular-text" value="' . esc_attr( abmon_mail() ) . '"></label></p>';
	echo '<p><button class="button button-primary" name="abmon_save" value="1">Speichern</button></p></form>';
	echo '<form method="post">'; wp_nonce_field( 'abmon_now' );
	echo '<p><button class="button" name="abmon_now" value="1">Jetzt prüfen</button> <button class="button" name="abmon_test" value="1">Testmail senden</button></p></form>';
	if ( $st ) {
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Seite</th><th>Zustand</th><th>Antwort</th><th>Zeit</th><th>Zuletzt geprüft (UTC)</th></tr></thead><tbody>';
		foreach ( $st as $u => $s ) {
			echo '<tr><td>' . esc_html( $u ) . '</td><td>' . ( ! empty( $s['ok'] ) ? '<span style="color:#00a32a">● läuft</span>' : '<span style="color:#b32d2e">● Fehler (' . (int) $s['fails'] . 'x)' . ( ! empty( $s['mailed'] ) ? ', Mail gesendet' : '' ) . '</span>' ) . '</td><td>' . esc_html( $s['code'] . ( $s['msg'] ? ' ' . $s['msg'] : '' ) ) . '</td><td>' . (int) $s['ms'] . ' ms</td><td>' . esc_html( gmdate( 'd.m.Y H:i', (int) $s['last'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '<p class="description">Hinweis: WP-Cron läuft nur bei Seitenaufrufen. Für verlässliche Prüfungen sollte auf der Zentrale ein echter Server-Cron den Aufruf wp-cron.php anstoßen. UptimeRobot bleibt als Außenprüfung für die Zentrale selbst.</p></div>';
}
