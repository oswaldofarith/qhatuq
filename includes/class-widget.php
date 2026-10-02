<?php
/**
 * Burbuja de chat en el sitio público.
 */

defined( 'ABSPATH' ) || exit;

class Qhatuq_Widget {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue(): void {
		$s = Qhatuq_Settings::all();
		if ( empty( $s['enabled'] ) || '' === Qhatuq_Settings::api_key( $s['provider'] ) ) {
			return;
		}
		if ( ! apply_filters( 'qhatuq_show_widget', true ) ) {
			return;
		}

		wp_enqueue_style( 'qhatuq-widget', QHATUQ_URL . 'assets/widget.css', array(), QHATUQ_VERSION );
		wp_enqueue_script( 'qhatuq-widget', QHATUQ_URL . 'assets/widget.js', array(), QHATUQ_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script(
			'qhatuq-widget',
			'QhatuqConfig',
			array(
				'endpoint'      => esc_url_raw( rest_url( Qhatuq_Rest::NS ) ),
				'title'         => $s['widget_title'],
				'agentName'     => $s['agent_name'],
				'greeting'      => $s['greeting'],
				'privacyNotice' => $s['privacy_notice'],
				'privacyUrl'    => $s['privacy_url'],
				'color'         => $s['widget_color'],
				'position'      => $s['widget_position'],
				'maxChars'      => (int) $s['max_message_chars'],
				'storageKey'    => 'qhatuq_' . substr( md5( home_url() ), 0, 8 ),
			)
		);
	}
}
