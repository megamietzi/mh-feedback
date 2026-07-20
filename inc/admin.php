<?php
/**
 * Backend: Übersicht der Rückmeldungen und Einstellungen.
 *
 * @package mh-feedback
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	$count = wp_count_posts( MHF_CPT );
	$open  = isset( $count->private ) ? (int) $count->private : 0;

	$label = __( 'Rückmeldungen', 'mh-feedback' );
	if ( $open ) {
		$label .= ' <span class="update-plugins count-' . $open . '"><span class="plugin-count">' . $open . '</span></span>';
	}

	add_menu_page(
		__( 'Rückmeldungen', 'mh-feedback' ),
		$label,
		'edit_posts',
		'mhf',
		'mhf_admin_page',
		'dashicons-edit-large',
		58
	);
} );

function mhf_admin_page() {
	if ( isset( $_GET['del'] ) && check_admin_referer( 'mhf_del' ) ) { // phpcs:ignore
		wp_delete_post( absint( $_GET['del'] ), true );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rückmeldung gelöscht.', 'mh-feedback' ) . '</p></div>';
	}

	$posts = get_posts( array(
		'post_type'   => MHF_CPT,
		'post_status' => 'private',
		'numberposts' => 100,
	) );

	$link = home_url( '/?mhf=' . get_option( 'mhf_token' ) );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Rückmeldungen', 'mh-feedback' ); ?></h1>

		<form method="post" action="options.php" style="background:#fff;border:1px solid #ccd0d4;padding:6px 18px 12px;margin:16px 0 26px">
			<?php settings_fields( 'mhf' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Wer darf anmerken?', 'mh-feedback' ); ?></th>
					<td>
						<?php $mode = get_option( 'mhf_mode', 'token' ); ?>
						<label style="display:block;margin-bottom:7px"><input type="radio" name="mhf_mode" value="token" <?php checked( $mode, 'token' ); ?>>
							<?php esc_html_e( 'Nur wer den Link mit Schlüssel aufgerufen hat', 'mh-feedback' ); ?></label>
						<label style="display:block;margin-bottom:7px"><input type="radio" name="mhf_mode" value="logged" <?php checked( $mode, 'logged' ); ?>>
							<?php esc_html_e( 'Nur angemeldete Benutzer', 'mh-feedback' ); ?></label>
						<label style="display:block;margin-bottom:7px"><input type="radio" name="mhf_mode" value="all" <?php checked( $mode, 'all' ); ?>>
							<?php esc_html_e( 'Alle Besucher', 'mh-feedback' ); ?></label>
						<label style="display:block"><input type="radio" name="mhf_mode" value="off" <?php checked( $mode, 'off' ); ?>>
							<?php esc_html_e( 'Ausgeschaltet', 'mh-feedback' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Link für den Kunden', 'mh-feedback' ); ?></th>
					<td>
						<input type="text" class="large-text code" readonly onclick="this.select()" value="<?php echo esc_url( $link ); ?>" style="max-width:620px">
						<p class="description" style="max-width:70ch">
							<?php esc_html_e( 'Diesen Link weitergeben. Beim Aufruf verschwindet der Schlüssel aus der Adresse, damit er nicht in fremden Verläufen landet — kopieren Sie ihn deshalb hier, nicht aus dem Browser. Zurückziehen lässt er sich, indem der Schlüssel unten geändert wird.', 'mh-feedback' ); ?>
						</p>
						<p><label><?php esc_html_e( 'Schlüssel', 'mh-feedback' ); ?><br>
							<input type="text" name="mhf_token" class="regular-text code" value="<?php echo esc_attr( get_option( 'mhf_token' ) ); ?>"></label></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Beschriftung des Knopfes', 'mh-feedback' ); ?></th>
					<td><input type="text" name="mhf_label" class="regular-text" value="<?php echo esc_attr( get_option( 'mhf_label' ) ); ?>" placeholder="<?php esc_attr_e( 'Rückmeldung geben', 'mh-feedback' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Hinweis beim Öffnen', 'mh-feedback' ); ?></th>
					<td><textarea name="mhf_intro" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Klicken Sie auf die Seite, um einen Kommentar zu hinterlassen …', 'mh-feedback' ); ?>"><?php echo esc_textarea( get_option( 'mhf_intro' ) ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Benachrichtigung an', 'mh-feedback' ); ?></th>
					<td>
						<input type="text" name="mhf_notify_to" class="regular-text" value="<?php echo esc_attr( get_option( 'mhf_notify_to' ) ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
						<p class="description" style="max-width:70ch">
							<?php esc_html_e( 'An diese Adresse geht eine E-Mail, sobald hier jemand eine Rückmeldung absendet – so bist du auch auf Kundenseiten sofort informiert. Der Seitenname steht im Betreff. Mehrere Adressen durch Komma trennen. Leer = Admin-E-Mail dieser Seite.', 'mh-feedback' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatische Updates (GitHub)', 'mh-feedback' ); ?></th>
					<td>
						<p><label><?php esc_html_e( 'Repository', 'mh-feedback' ); ?><br>
							<input type="text" name="mhf_gh_repo" class="regular-text code" value="<?php echo esc_attr( get_option( 'mhf_gh_repo' ) ); ?>" placeholder="benutzer/mh-feedback"></label></p>
						<p><label><?php esc_html_e( 'Branch', 'mh-feedback' ); ?><br>
							<input type="text" name="mhf_gh_branch" class="regular-text code" value="<?php echo esc_attr( get_option( 'mhf_gh_branch', 'main' ) ); ?>" placeholder="main"></label></p>
						<p><label><?php esc_html_e( 'Zugriffs-Token (nur bei privatem Repo)', 'mh-feedback' ); ?><br>
							<input type="password" name="mhf_gh_token" class="regular-text code" value="<?php echo esc_attr( get_option( 'mhf_gh_token' ) ); ?>" autocomplete="off"></label></p>
						<p class="description" style="max-width:70ch">
							<?php esc_html_e( 'Format „benutzer/repo“ (oder die volle GitHub-Adresse). Sobald ein Repository hinterlegt ist, prüft WordPress dort auf neue Versionen und zeigt „Update verfügbar“ wie bei normalen Plugins. Öffentliches Repo → Token leer lassen.', 'mh-feedback' ); ?>
							<br><br>
							<strong><?php esc_html_e( 'Neue Version veröffentlichen:', 'mh-feedback' ); ?></strong>
							<?php esc_html_e( 'Einfach im Repo in der Datei mh-feedback.php die „Version:“-Zahl oben erhöhen und speichern/pushen. Keine Releases nötig. Alle Seiten sehen das Update dann automatisch (oder sofort über „Nach Updates suchen“).', 'mh-feedback' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<?php if ( ! $posts ) : ?>
			<p><?php esc_html_e( 'Noch keine Rückmeldungen. Sobald jemand auf „Fertig“ klickt, erscheinen sie hier.', 'mh-feedback' ); ?></p>
		<?php else : ?>
			<?php foreach ( $posts as $p ) :
				$items = mhf_items( $p->ID );
				$items = is_array( $items ) ? $items : array();
				$page  = (string) get_post_meta( $p->ID, '_mhf_page', true );
				$pins  = array_filter( $items, function ( $i ) { return 'pin' === ( $i['type'] ?? '' ); } );
				$marks = count( $items ) - count( $pins );
				$show  = $page ? add_query_arg( 'mhf_show', $p->ID, $page ) : '';
				$del   = wp_nonce_url( admin_url( 'admin.php?page=mhf&del=' . $p->ID ), 'mhf_del' );
				?>
				<div class="postbox" style="margin-top:14px">
					<div class="postbox-header"><h2 class="hndle" style="padding:10px 14px">
						<?php echo esc_html( get_the_title( $p ) ); ?>
						<?php if ( $show ) : ?>
							<a href="<?php echo esc_url( $show ); ?>" target="_blank" rel="noopener" style="font-weight:400;font-size:13px;margin-left:12px"><?php esc_html_e( 'Auf der Seite ansehen', 'mh-feedback' ); ?> ↗</a>
						<?php endif; ?>
						<a href="<?php echo esc_url( $del ); ?>" style="font-weight:400;font-size:12px;margin-left:12px;color:#b32d2e"
							onclick="return confirm('<?php echo esc_js( __( 'Diese Rückmeldung löschen?', 'mh-feedback' ) ); ?>')"><?php esc_html_e( 'löschen', 'mh-feedback' ); ?></a>
					</h2></div>
					<div class="inside" style="padding:0 14px 12px">
						<?php if ( $pins ) : ?>
							<ol style="margin-left:18px">
								<?php foreach ( $pins as $pin ) : ?>
									<li style="margin:8px 0;line-height:1.5">
										<?php $t = trim( (string) ( $pin['text'] ?? '' ) ); ?>
										<?php echo $t ? esc_html( $t ) : '<em style="color:#888">' . esc_html__( '(kein Text)', 'mh-feedback' ) . '</em>'; ?>
										<br><span style="color:#888;font-size:12px"><?php echo esc_html( __( 'bezieht sich auf:', 'mh-feedback' ) . ' ' . ( $pin['anchor'] ?? '—' ) ); ?></span>
									</li>
								<?php endforeach; ?>
							</ol>
						<?php endif; ?>
						<?php if ( $marks > 0 ) : ?>
							<p style="color:#666"><?php printf( esc_html__( 'Dazu %d Markierung(en) auf der Seite.', 'mh-feedback' ), (int) $marks ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php
}
