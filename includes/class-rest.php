<?php
/**
 * Endpoints públicos del chat:
 *   POST /wp-json/qhatuq/v1/chat     envía un mensaje (crea la conversación si hace falta)
 *   POST /wp-json/qhatuq/v1/history  recupera los mensajes de la conversación en curso
 *
 * No usan cookies de sesión: cada conversación tiene un token secreto que solo conoce
 * el navegador del visitante, así que funcionan aunque la página esté en caché.
 */

defined( 'ABSPATH' ) || exit;

class Qhatuq_Rest {

	const NS = 'qhatuq/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'chat' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'message'         => array(
						'type'     => 'string',
						'required' => true,
					),
					'conversation_id' => array( 'type' => array( 'string', 'null' ) ),
					'token'           => array( 'type' => array( 'string', 'null' ) ),
					'page_url'        => array( 'type' => array( 'string', 'null' ) ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/history',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'history' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function chat( WP_REST_Request $req ) {
		$s = Qhatuq_Settings::all();
		if ( empty( $s['enabled'] ) ) {
			return self::error( 'disabled', 'El asistente no está disponible.', 503 );
		}

		$message = trim( sanitize_textarea_field( (string) $req->get_param( 'message' ) ) );
		if ( '' === $message ) {
			return self::error( 'empty', 'Escriba un mensaje.', 400 );
		}
		if ( mb_strlen( $message ) > (int) $s['max_message_chars'] ) {
			return self::error( 'too_long', 'El mensaje es demasiado largo. Por favor, resúmalo.', 400 );
		}

		if ( ! self::within_ip_limit( (int) $s['max_per_ip_hour'] ) ) {
			return self::error( 'rate_limited', 'Ha enviado muchos mensajes seguidos. Intente de nuevo en un rato.', 429 );
		}

		$page_url = esc_url_raw( (string) $req->get_param( 'page_url' ) );
		if ( $page_url && wp_parse_url( $page_url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			$page_url = '';
		}

		$public_id = sanitize_text_field( (string) $req->get_param( 'conversation_id' ) );
		$token     = sanitize_text_field( (string) $req->get_param( 'token' ) );
		$conv      = $public_id ? Qhatuq_DB::get_conversation_by_public_id( $public_id ) : null;
		$new_token = null;

		if ( ! Qhatuq_Agent::verify( $conv, $token ) ) {
			list( $conv, $new_token ) = Qhatuq_Agent::start( $page_url );
		}

		if ( (int) $conv['user_messages'] >= (int) $s['max_messages'] ) {
			return self::error( 'conversation_limit', 'Esta conversación alcanzó su límite de mensajes. Un representante puede continuar la atención por teléfono o correo.', 429 );
		}

		// Evita que dos mensajes simultáneos de la misma conversación pisen el historial.
		$lock = 'qhatuq_lock_' . $conv['id'];
		if ( get_transient( $lock ) ) {
			return self::error( 'busy', 'Estoy respondiendo su mensaje anterior, un momento por favor.', 409 );
		}
		set_transient( $lock, 1, 120 );

		try {
			$result = Qhatuq_Agent::reply( $conv, $message, $page_url );
		} finally {
			delete_transient( $lock );
		}

		$data = array(
			'conversation_id' => $conv['public_id'],
			'reply'           => $result['reply'],
		);
		if ( $new_token ) {
			$data['token'] = $new_token;
		}
		return rest_ensure_response( $data );
	}

	public static function history( WP_REST_Request $req ) {
		$conv  = Qhatuq_DB::get_conversation_by_public_id( sanitize_text_field( (string) $req->get_param( 'conversation_id' ) ) );
		$token = sanitize_text_field( (string) $req->get_param( 'token' ) );
		if ( ! Qhatuq_Agent::verify( $conv, $token ) ) {
			return self::error( 'not_found', 'Conversación no encontrada.', 404 );
		}
		$messages = array_values(
			array_filter(
				Qhatuq_DB::get_messages( (int) $conv['id'] ),
				static fn( $m ) => in_array( $m['role'], array( 'user', 'assistant' ), true )
			)
		);
		return rest_ensure_response(
			array(
				'messages' => array_map(
					static fn( $m ) => array(
						'role'    => $m['role'],
						'content' => $m['content'],
					),
					$messages
				),
			)
		);
	}

	private static function within_ip_limit( int $max ): bool {
		$key   = 'qhatuq_rl_' . substr( Qhatuq_Agent::ip_hash(), 0, 32 );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	private static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( 'qhatuq_' . $code, $message, array( 'status' => $status ) );
	}
}
