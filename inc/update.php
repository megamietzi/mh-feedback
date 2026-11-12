<?php
/**
 * Automatische Updates über GitHub.
 *
 * WordPress fragt im Repository (benutzer/repo) nach der „Version:“-Zeile der
 * Haupt-Datei und zeigt „Update verfügbar“ – genau wie bei Plugins aus dem
 * offiziellen Verzeichnis. Ohne Eintrag in den Einstellungen gilt
 * MHF_GH_REPO_DEFAULT, es muss also auf keiner Seite etwas eingetragen werden.
 *
 * Öffentliches Repo → Token bleibt leer. Privates Repo → Personal Access Token.
 *
 * @package mh-feedback
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'MHF_GH_REPO_DEFAULT' ) ) {
	define( 'MHF_GH_REPO_DEFAULT', 'megamietzi/mh-feedback' );
}

/* Einstellungen registrieren (Repo + optionaler Token). */
add_action( 'admin_init', function () {
	register_setting( 'mhf', 'mhf_gh_repo', array(
		'type'              => 'string',
		'sanitize_callback' => 'mhf_sanitize_repo',
		// Nur für die Anzeige im Formular. Der Updater selbst liest die Vorgabe
		// aus MHF_GH_REPO_DEFAULT, weil er vor admin_init startet und im Cron
		// gar kein admin_init läuft.
		'default'           => MHF_GH_REPO_DEFAULT,
	) );
	register_setting( 'mhf', 'mhf_gh_token', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );
	register_setting( 'mhf', 'mhf_gh_branch', array(
		'type'              => 'string',
		'sanitize_callback' => function ( $v ) {
			$v = sanitize_text_field( (string) $v );
			return $v ? $v : 'main';
		},
		'default'           => 'main',
	) );
} );

/** Akzeptiert „benutzer/repo“ oder eine volle GitHub-URL und normalisiert auf „benutzer/repo“. */
function mhf_sanitize_repo( $v ) {
	$v = trim( (string) $v );
	if ( preg_match( '~github\.com/([^/]+/[^/#?]+)~i', $v, $m ) ) {
		$v = $m[1];
	}
	$v = preg_replace( '~\.git$~i', '', $v );
	return preg_match( '~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $v ) ? $v : '';
}

/**
 * Kleiner, eigenständiger Updater für GitHub-Releases.
 */
class MHF_GitHub_Updater {

	private $file;   // Vollständiger Pfad zur Haupt-Plugin-Datei.
	private $slug;   // z. B. mh-feedback/mh-feedback.php
	private $folder; // z. B. mh-feedback
	private $repo;   // benutzer/repo
	private $token;

	public function __construct( $file ) {
		$this->file   = $file;
		$this->slug   = plugin_basename( $file );
		$this->folder = dirname( $this->slug );
		$this->repo   = (string) get_option( 'mhf_gh_repo', '' );
		$this->token  = (string) get_option( 'mhf_gh_token', '' );

		// Bis 1.0.15 blieb der Updater ohne gespeicherte Einstellung stumm, weil
		// die Vorgabe aus register_setting() hier noch nicht gilt. Darum hier
		// selbst auf die Vorgabe zurückfallen.
		if ( ! $this->repo ) {
			$this->repo = MHF_GH_REPO_DEFAULT;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check' ) );
		add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_folder' ), 10, 4 );
		add_filter( 'http_request_args', array( $this, 'auth_download' ), 10, 2 );
	}

	/** GitHub-API abfragen (mit Token, falls hinterlegt). */
	private function api( $path ) {
		$args = array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'mh-feedback-updater',
			),
		);
		if ( $this->token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->token;
		}
		$res = wp_remote_get( 'https://api.github.com/repos/' . $this->repo . $path, $args );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		return json_decode( wp_remote_retrieve_body( $res ), true );
	}

	/**
	 * Neueste Version aus dem Branch lesen (12 h zwischengespeichert).
	 *
	 * KEINE Releases nötig: Es wird die Datei „mh-feedback.php“ im Branch geholt
	 * und deren „Version:“-Zeile im Kopf gelesen. Neue Version hochladen =
	 * einfach diese Zahl erhöhen und die Datei pushen (oder im GitHub-Browser
	 * editieren). Heruntergeladen wird dann der aktuelle Stand des Branches.
	 */
	private function latest() {
		$cache = get_transient( 'mhf_gh_latest' );
		if ( is_array( $cache ) ) {
			return $cache ? $cache : null;
		}

		$branch = (string) get_option( 'mhf_gh_branch' );
		if ( ! $branch ) {
			$branch = 'main';
		}

		// Datei-Header im Branch holen und Versionsnummer herauslesen.
		$file = $this->api( '/contents/mh-feedback.php?ref=' . rawurlencode( $branch ) );
		$ver  = '';
		if ( $file && ! empty( $file['content'] ) ) {
			$php = base64_decode( str_replace( array( "\n", "\r" ), '', $file['content'] ) );
			if ( $php && preg_match( '~^[ \t*]*Version:\s*([0-9][^\s]*)~mi', $php, $m ) ) {
				$ver = trim( $m[1] );
			}
		}
		if ( ! $ver ) {
			set_transient( 'mhf_gh_latest', array(), 6 * HOUR_IN_SECONDS );
			return null;
		}

		$data = array(
			'version' => ltrim( $ver, 'vV' ),
			'package' => 'https://api.github.com/repos/' . $this->repo . '/zipball/' . rawurlencode( $branch ),
			'url'     => 'https://github.com/' . $this->repo,
			'body'    => '',
		);
		set_transient( 'mhf_gh_latest', $data, 12 * HOUR_IN_SECONDS );
		return $data;
	}

	/** In die Update-Liste eintragen, wenn GitHub eine neuere Version hat. */
	public function check( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}
		$latest = $this->latest();
		if ( ! $latest || empty( $latest['version'] ) || empty( $latest['package'] ) ) {
			return $transient;
		}

		$installed = isset( $transient->checked[ $this->slug ] ) ? $transient->checked[ $this->slug ] : MHF_VERSION;

		if ( version_compare( $latest['version'], $installed, '>' ) ) {
			$transient->response[ $this->slug ] = (object) array(
				'slug'        => $this->folder,
				'plugin'      => $this->slug,
				'new_version' => $latest['version'],
				'url'         => $latest['url'],
				'package'     => $latest['package'],
			);
			unset( $transient->no_update[ $this->slug ] );
		} else {
			unset( $transient->response[ $this->slug ] );
			$transient->no_update[ $this->slug ] = (object) array(
				'slug'        => $this->folder,
				'plugin'      => $this->slug,
				'new_version' => $installed,
				'url'         => $latest['url'],
				'package'     => '',
			);
		}
		return $transient;
	}

	/** Inhalt für das „Details ansehen“-Fenster. */
	public function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->folder ) {
			return $result;
		}
		$latest = $this->latest();
		if ( ! $latest ) {
			return $result;
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$d = get_plugin_data( $this->file, false, false );

		return (object) array(
			'name'          => $d['Name'],
			'slug'          => $this->folder,
			'version'       => $latest['version'],
			'author'        => $d['Author'],
			'homepage'      => $d['PluginURI'],
			'download_link' => $latest['package'],
			'sections'      => array(
				'changelog' => wpautop( esc_html( $latest['body'] ? $latest['body'] : __( 'Siehe GitHub-Release.', 'mh-feedback' ) ) ),
			),
		);
	}

	/**
	 * GitHub entpackt das Zipball als „benutzer-repo-<hash>/“. Damit das Update
	 * nicht in einem falsch benannten Ordner landet, wird es auf den echten
	 * Plugin-Ordner umbenannt.
	 */
	public function fix_folder( $source, $remote_source, $upgrader, $hook_extra = null ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->slug ) {
			return $source;
		}
		global $wp_filesystem;
		$desired = trailingslashit( $remote_source ) . $this->folder;
		if ( untrailingslashit( $source ) === $desired ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), $desired ) ) {
			return trailingslashit( $desired );
		}
		return $source;
	}

	/** Beim eigentlichen Download (privates Repo) den Token als Header mitschicken. */
	public function auth_download( $args, $url ) {
		$is_download = ( false !== strpos( $url, '/zipball/' ) ) || preg_match( '~/releases/assets/\d+~', $url );
		if ( ! $is_download ) {
			return $args;
		}
		if ( $this->token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->token;
		}
		// Asset-Downloads liefern das Binär-Zip nur mit diesem Accept-Header.
		if ( preg_match( '~/releases/assets/\d+~', $url ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}
		return $args;
	}
}

/* Updater starten (Haupt-Plugin-Datei liegt eine Ebene über /inc). */
new MHF_GitHub_Updater( MHF_DIR . 'mh-feedback.php' );

/*
 * „Dashboard → Aktualisierungen → Erneut prüfen“ soll wirklich neu bei GitHub
 * nachsehen. Sonst antwortet bis zu 12 h (nach einem Fehler 6 h) der eigene
 * Zwischenspeicher. Priorität 1: vor wp_update_plugins() auf demselben Hook.
 */
add_action( 'load-update-core.php', function () {
	if ( ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- löscht nur einen Zwischenspeicher
		delete_transient( 'mhf_gh_latest' );
	}
}, 1 );

/*
 * Auto-Update standardmäßig AN: Sobald eine Seite eine neue Version im Repo
 * sieht, installiert WordPress sie im Hintergrund selbst – ohne Klick, auf
 * allen Seiten. So wird ein Update praktisch „überall gleichzeitig“ ausgerollt
 * (im nächsten WP-Prüf-Intervall, i. d. R. binnen ~12 h; sofort per
 * „Dashboard → Aktualisierungen → Erneut prüfen“).
 * Abschaltbar durch: define( 'MHF_NO_AUTOUPDATE', true ); in der wp-config.php.
 */
add_filter( 'auto_update_plugin', function ( $update, $item ) {
	if ( ! defined( 'MHF_NO_AUTOUPDATE' ) && isset( $item->plugin ) && 'mh-feedback/mh-feedback.php' === $item->plugin ) {
		return true;
	}
	return $update;
}, 10, 2 );
