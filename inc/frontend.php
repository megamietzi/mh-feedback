<?php
/**
 * Einbindung im Frontend.
 *
 * @package mh-feedback
 */

defined( 'ABSPATH' ) || exit;


/**
 * Alle sichtbaren Texte des Werkzeugs an einer Stelle.
 *
 * @return array
 */
function mhf_texte() {
	$label = trim( (string) get_option( 'mhf_label', '' ) );
	$intro = trim( (string) get_option( 'mhf_intro', '' ) );

	return array(
			'start'       => $label ? $label : __( 'Rückmeldung geben', 'mh-feedback' ),
			'hint'        => $intro ? $intro : __( 'Klicken Sie auf die Seite, um einen Kommentar zu hinterlassen, oder markieren Sie eine Stelle. Am Ende auf „Fertig“.', 'mh-feedback' ),
			'gotit'       => __( 'Alles klar', 'mh-feedback' ),
			'view'        => __( 'Ansehen', 'mh-feedback' ),
			'comment'     => __( 'Kommentar', 'mh-feedback' ),
			'pen'         => __( 'Freihand', 'mh-feedback' ),
			'circle'      => __( 'Einringeln', 'mh-feedback' ),
			'underline'   => __( 'Unterstreichen', 'mh-feedback' ),
			'undo'        => __( 'Rückgängig', 'mh-feedback' ),
			'redo'        => __( 'Wiederholen', 'mh-feedback' ),
			'done'        => __( 'Fertig', 'mh-feedback' ),
			'cancel'      => __( 'Abbrechen', 'mh-feedback' ),
			'cancelAsk'   => __( 'Alle Anmerkungen verwerfen?', 'mh-feedback' ),
			'helpHead'    => __( 'So geht’s', 'mh-feedback' ),
			'helpPin'     => __( '– auf die Stelle klicken und dazuschreiben, was Ihnen auffällt.', 'mh-feedback' ),
			'helpPen'     => __( '– mit gedrückter Maustaste etwas einkringeln oder markieren.', 'mh-feedback' ),
			'helpCircle'  => __( '– einen Bereich mit einer Ellipse hervorheben.', 'mh-feedback' ),
			'helpLine'    => __( '– eine Zeile oder ein Wort unterstreichen.', 'mh-feedback' ),
			'helpUndo'    => __( '– nimmt den letzten Schritt zurück, daneben liegt Wiederholen.', 'mh-feedback' ),
			'helpDone'    => __( 'Am Ende auf „Fertig“ – dort sehen Sie alles noch einmal und können absenden. „Abbrechen“ verwirft alles.', 'mh-feedback' ),
			'helpSkip'    => __( 'Hinweise nicht mehr zeigen', 'mh-feedback' ),
			'placeholder' => __( 'Was möchten Sie hier anmerken?', 'mh-feedback' ),
			'apply'       => __( 'Übernehmen', 'mh-feedback' ),
			'delete'      => __( 'löschen', 'mh-feedback' ),
			'reviewKick'  => __( 'Rückmeldung', 'mh-feedback' ),
			'reviewHead'  => __( 'Ihre Anmerkungen', 'mh-feedback' ),
			'reviewBack'  => __( 'Zurück, noch etwas ergänzen', 'mh-feedback' ),
			'send'        => __( 'Absenden', 'mh-feedback' ),
			'sending'     => __( 'Wird gesendet …', 'mh-feedback' ),
			'thanks'      => __( 'Danke — Ihre Rückmeldung ist angekommen.', 'mh-feedback' ),
			'thanksSub'   => __( 'Sie können das Fenster jetzt schließen.', 'mh-feedback' ),
			'failed'      => __( 'Das hat nicht geklappt. Bitte später erneut versuchen.', 'mh-feedback' ),
			'empty'       => __( 'Noch keine Anmerkung — schließen und ein Werkzeug wählen.', 'mh-feedback' ),
			'noText'      => __( '(kein Text)', 'mh-feedback' ),
			'marks'       => __( 'Markierungen auf der Seite', 'mh-feedback' ),
			'namePrompt'  => __( 'Ihr Name (optional)', 'mh-feedback' ),
		
	);
}

add_action( 'wp_enqueue_scripts', function () {
	$replay = mhf_replay_data();

	if ( ! $replay && ! mhf_active() ) {
		return;
	}

	wp_enqueue_style( 'mhf', MHF_URL . 'assets/feedback.css', array(), MHF_VERSION );
	wp_enqueue_script( 'mhf', MHF_URL . 'assets/feedback.js', array(), MHF_VERSION, true );

	$label = trim( (string) get_option( 'mhf_label', '' ) );
	$intro = trim( (string) get_option( 'mhf_intro', '' ) );

	$data = array(
		'ajax'  => admin_url( 'admin-ajax.php' ),
		'nonce' => wp_create_nonce( 'mhf' ),
		// Der Schlüssel steht bewusst NICHT im Quelltext: Eine zwischengespeicherte
		// Seite könnte ihn sonst an Fremde ausliefern. Das Skript nimmt ihn aus
		// der Adresse bzw. aus dem Speicher des Browsers.
		'page'  => esc_url( home_url( add_query_arg( array() ) ) ),
		'i18n'  => mhf_texte(),
	);

	if ( $replay ) {
		$data['mode'] = 'show';
		$data['show'] = $replay;
	}

	wp_localize_script( 'mhf', 'MHF', $data );
} );

/**
 * Zurückspielen: eine gespeicherte Rückmeldung über die Seite legen.
 *
 * Nur für angemeldete Redakteure und nur mit ?mhf_show=ID.
 */
function mhf_replay_data() {
	if ( is_admin() || ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
		return null;
	}
	$id = isset( $_GET['mhf_show'] ) ? absint( $_GET['mhf_show'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! $id || MHF_CPT !== get_post_type( $id ) ) {
		return null;
	}
	$items = json_decode( (string) get_post_meta( $id, '_mhf_items', true ), true );
	if ( ! is_array( $items ) ) {
		return null;
	}
	return array(
		'items' => $items,
		'title' => get_the_title( $id ),
		'back'  => admin_url( 'admin.php?page=mhf' ),
	);
}

/**
 * Nachladen auf zwischengespeicherten Seiten.
 *
 * Läuft die Seite aus dem Zwischenspeicher, wird PHP übersprungen: das Werkzeug
 * würde fehlen, obwohl jemand den Schlüssel hat. Darum steht im Fußbereich ein
 * kurzes Skript, das den Schlüssel aus der Adresse merkt und die Dateien bei
 * Bedarf selbst nachlädt.
 */
add_action( 'wp_footer', function () {

	if ( is_admin() || 'token' !== (string) get_option( 'mhf_mode', 'token' ) ) {
		return;
	}

	// Schon regulär geladen? Dann ist nichts zu tun.
	if ( wp_script_is( 'mhf', 'enqueued' ) || wp_script_is( 'mhf', 'done' ) ) {
		return;
	}

	$css = MHF_URL . 'assets/feedback.css?ver=' . MHF_VERSION;
	$js  = MHF_URL . 'assets/feedback.js?ver=' . MHF_VERSION;
	?>
	<script id="mhf-weiche">
	( function () {
		var schluessel = '';
		var adresse = new URL( window.location.href );

		if ( adresse.searchParams.get( 'mhf' ) ) {
			schluessel = adresse.searchParams.get( 'mhf' );
			try { window.localStorage.setItem( 'mhfPass', schluessel ); } catch ( e ) {}
			adresse.searchParams.delete( 'mhf' );
			window.history.replaceState( {}, '', adresse.toString() );
		} else {
			try { schluessel = window.localStorage.getItem( 'mhfPass' ) || ''; } catch ( e ) {}
		}

		if ( ! schluessel ) {
			return;
		}

		window.MHF = window.MHF || {
			ajax: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
			nonce: '',
			pass: schluessel,
			page: window.location.href,
			i18n: <?php echo wp_json_encode( mhf_texte() ); ?>
		};
		window.MHF.pass = schluessel;

		var stil = document.createElement( 'link' );
		stil.rel = 'stylesheet';
		stil.href = <?php echo wp_json_encode( $css ); ?>;
		document.head.appendChild( stil );

		var skript = document.createElement( 'script' );
		skript.src = <?php echo wp_json_encode( $js ); ?>;
		document.body.appendChild( skript );
	}() );
	</script>
	<?php
}, 99 );
