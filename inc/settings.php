<?php
/**
 * Einstellungen.
 *
 * Das Werkzeug soll nicht für jeden Besucher erscheinen. Wer es sehen darf,
 * entscheidet der Betreiber: alle, nur Angemeldete, oder nur wer einen Link mit
 * Schlüssel aufgerufen hat.
 *
 * @package mh-feedback
 */

defined( 'ABSPATH' ) || exit;

const MHF_COOKIE = 'mhf_pass';

add_action( 'admin_init', function () {
	register_setting( 'mhf', 'mhf_mode', array(
		'type'              => 'string',
		'sanitize_callback' => function ( $v ) {
			return in_array( $v, array( 'off', 'all', 'logged', 'token' ), true ) ? $v : 'token';
		},
		'default'           => 'token',
	) );
	register_setting( 'mhf', 'mhf_token', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => '' ) );
	register_setting( 'mhf', 'mhf_label', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
	register_setting( 'mhf', 'mhf_intro', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ) );
	register_setting( 'mhf', 'mhf_notify_to', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );

	if ( ! get_option( 'mhf_token' ) ) {
		update_option( 'mhf_token', wp_generate_password( 16, false, false ) );
	}
} );

/**
 * Stimmt der Schlüssel? Geprüft wird Cookie, Adresse und Formularfeld.
 *
 * Auf zwischengespeicherten Seiten läuft PHP nicht, dort wird also auch kein
 * Cookie gesetzt. Darum zählt der Schlüssel auch dann, wenn er beim Absenden
 * mitgeschickt oder in der Adresse übergeben wird.
 */
function mhf_token_ok() {

	$token = (string) get_option( 'mhf_token' );
	if ( ! $token ) {
		return false;
	}

	$kandidaten = array();

	if ( ! empty( $_COOKIE[ MHF_COOKIE ] ) ) {
		$kandidaten[] = sanitize_key( wp_unslash( $_COOKIE[ MHF_COOKIE ] ) );
	}
	if ( ! empty( $_GET['mhf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$kandidaten[] = sanitize_key( wp_unslash( $_GET['mhf'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	if ( ! empty( $_POST['pass'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$kandidaten[] = sanitize_key( wp_unslash( $_POST['pass'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	foreach ( $kandidaten as $wert ) {
		if ( hash_equals( $token, $wert ) ) {
			return true;
		}
	}

	return false;
}

/** Darf das Werkzeug hier erscheinen? */
function mhf_active() {

	/*
	 * Wichtig: admin-ajax.php gilt in WordPress als Backend. Ohne die
	 * Ausnahme würde jede abgeschickte Rückmeldung hier scheitern, obwohl
	 * der Besucher berechtigt ist.
	 */
	if ( is_admin() && ! wp_doing_ajax() ) {
		return false;
	}

	$mode = (string) get_option( 'mhf_mode', 'token' );

	if ( 'off' === $mode ) {
		return false;
	}
	if ( 'all' === $mode ) {
		return true;
	}
	if ( 'logged' === $mode ) {
		return is_user_logged_in();
	}

	// Schlüssel: Cookie, Adresse oder mitgeschicktes Feld.
	return mhf_token_ok();
}

/**
 * Schlüssel aus der Adresse einlösen und aus der Adresszeile entfernen.
 *
 * Der Schlüssel soll nicht im Verlauf und nicht in Verweisen landen, deshalb
 * die Weiterleitung auf dieselbe Adresse ohne Parameter.
 */
add_action( 'template_redirect', function () {
	if ( empty( $_GET['mhf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$token = (string) get_option( 'mhf_token' );
	$given = sanitize_key( wp_unslash( $_GET['mhf'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( $token && hash_equals( $token, $given ) ) {
		setcookie( MHF_COOKIE, $token, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		wp_safe_redirect( remove_query_arg( 'mhf' ) );
		exit;
	}
}, 1 );
