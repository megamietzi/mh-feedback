<?php
/**
 * Plugin Name: MH Anmerkungen
 * Plugin URI:  https://www.mh-design.co.at
 * Description: Kunden hinterlassen Rückmeldungen direkt auf der Seite: Kommentare an Ort und Stelle, Markierungen, Einringelungen. Beim Absenden landet alles im Backend. Eigenständig, ohne Abhängigkeit von einem bestimmten Theme.
 * Version:     1.0.9
 * Author:      MH-Design, Markus Habenreich
 * Author URI:  https://www.mh-design.co.at
 * License:     GPL-2.0-or-later
 * Text Domain: mh-feedback
 *
 * @package mh-feedback
 */

defined( 'ABSPATH' ) || exit;

// Version wird automatisch aus der „Version:“-Zeile oben gelesen – du musst also
// nur EINE Zahl (im Kopf dieser Datei) ändern, sonst nichts.
$mhf_hdr = get_file_data( __FILE__, array( 'v' => 'Version' ) );
define( 'MHF_VERSION', ! empty( $mhf_hdr['v'] ) ? $mhf_hdr['v'] : '1.0.7' );
define( 'MHF_DIR', plugin_dir_path( __FILE__ ) );
define( 'MHF_URL', plugin_dir_url( __FILE__ ) );

require_once MHF_DIR . 'inc/settings.php';
require_once MHF_DIR . 'inc/frontend.php';
require_once MHF_DIR . 'inc/store.php';
require_once MHF_DIR . 'inc/admin.php';
require_once MHF_DIR . 'inc/update.php';
