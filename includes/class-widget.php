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

		// Saludo y sugerencias propios si la página corresponde a un producto o servicio.
		$greeting    = $s['greeting'];
		$suggestions = array_values( array_filter( explode( "\n", (string) $s['widget_suggestions'] ) ) );
		$scheme      = is_ssl() ? 'https://' : 'http://';
		$current     = $scheme . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		$offer       = Qhatuq_Catalog::offer_for_page( (int) get_queried_object_id(), $current );
		if ( $offer ) {
			$ctx = Qhatuq_Catalog::offer_context( $offer->ID );
			if ( '' !== $ctx['greeting'] ) {
				$greeting = $ctx['greeting'];
			}
			if ( '' !== $ctx['suggestions'] ) {
				$suggestions = array_values( array_filter( explode( "\n", $ctx['suggestions'] ) ) );
			}
		}

		wp_enqueue_script( 'qhatuq-widget', QHATUQ_URL . 'assets/widget.js', array(), QHATUQ_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script(
			'qhatuq-widget',
			'QhatuqConfig',
			array(
				'endpoint'      => esc_url_raw( rest_url( Qhatuq_Rest::NS ) ),
				'title'         => $s['widget_title'],
				'agentName'     => $s['agent_name'],
				'greeting'      => $greeting,
				'offerId'       => $offer ? (int) $offer->ID : 0,
				'privacyNotice' => $s['privacy_notice'],
				'privacyUrl'    => $s['privacy_url'],
				'color'         => $s['widget_color'],
				'position'      => $s['widget_position'],
				'avatar'        => Qhatuq_Settings::avatar_url(),
				'suggestions'   => $suggestions,
				'teaserDelay'   => (int) $s['teaser_delay'],
				// La hoja de estilos se carga dentro del Shadow DOM del widget, aislada del tema.
				'cssUrl'        => add_query_arg( 'ver', QHATUQ_VERSION, QHATUQ_URL . 'assets/widget.css' ),
				'maxChars'      => (int) $s['max_message_chars'],
				'storageKey'    => 'qhatuq_' . substr( md5( home_url() ), 0, 8 ),
			)
		);
	}
}
