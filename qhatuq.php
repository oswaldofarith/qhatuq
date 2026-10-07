<?php
/**
 * Plugin Name:       Qhatuq – Agente de ventas con IA
 * Description:       Asistente de chat que atiende a los visitantes como un agente comercial: conoce el catálogo, sabe lo que no se ofrece, registra conversaciones e identifica leads. Funciona con Claude (Anthropic) o Gemini (Google).
 * Version:           0.9.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Hanaq
 * Text Domain:       qhatuq
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'QHATUQ_VERSION', '0.9.0' );
define( 'QHATUQ_DB_VERSION', '3' );
define( 'QHATUQ_FILE', __FILE__ );
define( 'QHATUQ_DIR', plugin_dir_path( __FILE__ ) );
define( 'QHATUQ_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( QHATUQ_DIR . 'vendor/autoload.php' ) ) {
	require_once QHATUQ_DIR . 'vendor/autoload.php';
}

require_once QHATUQ_DIR . 'includes/class-settings.php';
require_once QHATUQ_DIR . 'includes/class-db.php';
require_once QHATUQ_DIR . 'includes/class-catalog.php';
require_once QHATUQ_DIR . 'includes/class-prompt.php';
require_once QHATUQ_DIR . 'includes/class-tools.php';
require_once QHATUQ_DIR . 'includes/class-leads.php';
require_once QHATUQ_DIR . 'includes/class-web-verifier.php';
require_once QHATUQ_DIR . 'includes/class-summaries.php';
require_once QHATUQ_DIR . 'includes/providers/interface-provider.php';
require_once QHATUQ_DIR . 'includes/providers/class-provider-claude.php';
require_once QHATUQ_DIR . 'includes/providers/class-provider-gemini.php';
require_once QHATUQ_DIR . 'includes/class-agent.php';
require_once QHATUQ_DIR . 'includes/class-rest.php';
require_once QHATUQ_DIR . 'includes/class-widget.php';

if ( is_admin() ) {
	require_once QHATUQ_DIR . 'admin/class-admin.php';
	require_once QHATUQ_DIR . 'admin/class-leads-table.php';
	require_once QHATUQ_DIR . 'admin/class-conversations-table.php';
	require_once QHATUQ_DIR . 'admin/class-transfer.php';
}

register_activation_hook( __FILE__, array( 'Qhatuq_DB', 'install' ) );
register_activation_hook( __FILE__, array( 'Qhatuq_Catalog', 'seed_defaults' ) );
register_deactivation_hook( __FILE__, array( 'Qhatuq_DB', 'unschedule_cleanup' ) );
register_deactivation_hook( __FILE__, array( 'Qhatuq_Summaries', 'unschedule' ) );

add_action(
	'plugins_loaded',
	static function () {
		Qhatuq_DB::maybe_upgrade();
		Qhatuq_Catalog::init();
		Qhatuq_Rest::init();
		Qhatuq_Widget::init();
		Qhatuq_DB::init_cleanup();
		Qhatuq_Summaries::init();
		if ( is_admin() ) {
			Qhatuq_Admin::init();
			Qhatuq_Transfer::init();
		}
	}
);
