<?php
/**
 * Speicherung der Rückmeldungen.
 *
 * @package mh-feedback
 */

defined( 'ABSPATH' ) || exit;

const MHF_CPT = 'mhf_note';

add_action( 'init', function () {
	register_post_type(
		MHF_CPT,
		array(
			'labels'          => array(
				'name'          => __( 'Rückmeldungen', 'mh-feedback' ),
				'singular_name' => __( 'Rückmeldung', 'mh-feedback' ),
			),
			'public'          => false,   // nichts davon gehört ins Frontend
			'show_ui'         => false,   // eigene, aufgeräumte Ansicht
			'capability_type' => 'post',
			'supports'        => array( 'title' ),
			'rewrite'         => false,
			'query_var'       => false,
		)
	);
} );

add_action( 'wp_ajax_nopriv_mhf_submit', 'mhf_submit' );
add_action( 'wp_ajax_mhf_submit', 'mhf_submit' );

function mhf_submit() {

	// Auf zwischengespeicherten Seiten kann der Sicherheitsschlüssel veraltet
	// sein. Wer den richtigen Zugangsschlüssel mitschickt, darf trotzdem senden.
	$mit_schluessel = function_exists( 'mhf_token_ok' ) ? mhf_token_ok() : false;

	if ( ! check_ajax_referer( 'mhf', 'nonce', false ) && ! $mit_schluessel ) {
		wp_send_json_error( 'bad_nonce', 403 );
	}
	if ( ! mhf_active() ) {
		wp_send_json_error( 'not_allowed', 403 );
	}

	$raw  = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$data = json_decode( (string) $raw, true );

	if ( ! is_array( $data ) || empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
		wp_send_json_error( 'empty', 400 );
	}

	// Deckel gegen Missbrauch: kein Roman, keine Flut.
	$clean = array();
	foreach ( array_slice( $data['items'], 0, 200 ) as $it ) {
		if ( ! is_array( $it ) || empty( $it['type'] ) ) {
			continue;
		}
		$type = sanitize_key( $it['type'] );
		$rec  = array( 'type' => $type );

		if ( 'pin' === $type ) {
			$rec['n']        = absint( $it['n'] ?? 0 );
			$rec['text']     = mb_substr( sanitize_textarea_field( $it['text'] ?? '' ), 0, 2000 );
			$rec['anchor']   = sanitize_text_field( mb_substr( (string) ( $it['anchor'] ?? '' ), 0, 120 ) );
			$rec['selector'] = sanitize_text_field( mb_substr( (string) ( $it['selector'] ?? '' ), 0, 300 ) );
			$rec['ax']       = (float) ( $it['ax'] ?? 0 );
			$rec['ay']       = (float) ( $it['ay'] ?? 0 );
			$rec['x']        = (float) ( $it['x'] ?? 0 );
			$rec['y']        = (float) ( $it['y'] ?? 0 );
		} else {
			// Zeichnungen: nur Zahlen und Pfadbefehle, damit nichts eingeschleust wird.
			$rec['path'] = isset( $it['path'] ) ? preg_replace( '/[^0-9., MLle-]/', '', (string) $it['path'] ) : '';
			$rec['w']    = (float) ( $it['w'] ?? 0 );
		}
		$clean[] = $rec;
	}

	if ( ! $clean ) {
		wp_send_json_error( 'empty', 400 );
	}

	$name = sanitize_text_field( mb_substr( (string) ( $data['name'] ?? '' ), 0, 80 ) );
	$page = esc_url_raw( (string) ( $data['page'] ?? '' ) );

	$title = wp_date( 'j.n.Y H:i' );
	if ( $name ) {
		$title = $name . ' — ' . $title;
	}

	$id = wp_insert_post(
		array(
			'post_type'   => MHF_CPT,
			'post_status' => 'private',
			'post_title'  => $title,
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		wp_send_json_error( 'save_failed', 500 );
	}

	update_post_meta( $id, '_mhf_items', wp_json_encode( $clean ) );
	update_post_meta( $id, '_mhf_page', $page );
	update_post_meta( $id, '_mhf_name', $name );

	mhf_notify( $id, $name, $page, $clean );

	wp_send_json_success( array( 'id' => $id ) );
}

/**
 * Benachrichtigung an den Betreiber.
 *
 * Ohne sie müsste man das Backend im Blick behalten; eine Rückmeldung könnte
 * tagelang liegen bleiben. Absender auf der eigenen Domain, damit die Nachricht
 * nicht als Fälschung eingestuft wird.
 */
function mhf_notify( $id, $name, $page, $items ) {
	// Einstellbarer Empfänger: So bekommst DU die Info auf jeder Seite an deine
	// Adresse – unabhängig davon, wer dort Admin ist. Mehrere durch Komma trennen.
	// Leer = Fallback auf die Admin-E-Mail der Seite.
	$konfig = trim( (string) get_option( 'mhf_notify_to', '' ) );
	$to     = array();
	if ( $konfig ) {
		foreach ( preg_split( '/[,;]+/', $konfig ) as $mail ) {
			$mail = sanitize_email( trim( $mail ) );
			if ( is_email( $mail ) ) {
				$to[] = $mail;
			}
		}
	}
	if ( ! $to ) {
		$admin = get_option( 'admin_email' );
		if ( $admin ) {
			$to[] = $admin;
		}
	}
	if ( ! $to ) {
		return;
	}

	$lines = array();
	foreach ( $items as $it ) {
		if ( 'pin' !== $it['type'] ) {
			continue;
		}
		$lines[] = sprintf( "%d. %s\n   bezieht sich auf: %s", $it['n'], $it['text'] ? $it['text'] : '(kein Text)', $it['anchor'] );
	}
	$marks = count( $items ) - count( $lines );

	$body  = sprintf( "Neue Rückmeldung%s\n\nSeite: %s\n\n", $name ? ' von ' . $name : '', $page );
	$body .= $lines ? implode( "\n\n", $lines ) . "\n" : "Keine Kommentare.\n";
	if ( $marks > 0 ) {
		$body .= sprintf( "\nDazu %d Markierung(en) auf der Seite.\n", $marks );
	}
	$body .= sprintf( "\nAnsehen: %s\n", admin_url( 'admin.php?page=mhf' ) );

	$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

	wp_mail(
		$to,
		sprintf( '[%s] Rückmeldung', get_bloginfo( 'name' ) ),
		$body,
		array(
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <noreply@' . $host . '>',
		)
	);
}
