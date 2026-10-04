<?php
/**
 * Orquesta una conversación: crea o recupera la conversación, ejecuta el turno con el
 * proveedor elegido y guarda el historial, los mensajes y el consumo.
 */

defined( 'ABSPATH' ) || exit;

class Qhatuq_Agent {

	const FALLBACK_REPLY = 'Disculpe, en este momento no puedo responder. Si me deja su nombre, teléfono o correo y lo que necesita, un representante se comunicará con usted. También puede intentarlo nuevamente en unos minutos.';

	/** Verificación en la web de productos fuera del catálogo (Claude: búsqueda propia; Gemini: verificar_producto). */
	public static function web_search_enabled( string $provider ): bool {
		return ! empty( Qhatuq_Settings::get( 'web_search' ) );
	}

	public static function make_provider( string $provider, string $model, bool $web_search = false ): Qhatuq_Provider {
		$key = Qhatuq_Settings::api_key( $provider );
		if ( '' === $key ) {
			throw new Qhatuq_Provider_Exception( 'No hay API key configurada para ' . $provider . '.' );
		}
		if ( 'gemini' === $provider ) {
			return new Qhatuq_Provider_Gemini( $key, $model, $web_search );
		}
		return new Qhatuq_Provider_Claude( $key, $model, (string) Qhatuq_Settings::get( 'claude_effort' ), $web_search );
	}

	public static function current_model( string $provider ): string {
		return (string) Qhatuq_Settings::get( 'gemini' === $provider ? 'gemini_model' : 'claude_model' );
	}

	/** Crea una conversación nueva con las instrucciones congeladas. Devuelve [conversación, token]. */
	public static function start( string $page_url ): array {
		$provider   = (string) Qhatuq_Settings::get( 'provider' );
		$web_search = self::web_search_enabled( $provider );
		$token      = wp_generate_password( 40, false, false );
		$conv     = Qhatuq_DB::create_conversation(
			array(
				'token_hash'    => hash( 'sha256', $token ),
				'provider'      => $provider,
				'model'         => self::current_model( $provider ),
				'system_prompt' => Qhatuq_Prompt::build( $web_search, $provider ),
				'web_search'    => $web_search,
				'page_url'      => $page_url,
				'ip_hash'       => self::ip_hash(),
				'user_agent'    => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
			)
		);
		return array( $conv, $token );
	}

	public static function verify( ?array $conv, string $token ): bool {
		return $conv && '' !== $token && hash_equals( $conv['token_hash'], hash( 'sha256', $token ) );
	}

	/**
	 * @return array{reply:string, ok:bool}
	 */
	public static function reply( array $conv, string $message, string $page_url ): array {
		$conv_id = (int) $conv['id'];
		Qhatuq_DB::add_message( $conv_id, 'user', $message );

		// El contexto de la página va dentro del mensaje (nunca en las instrucciones),
		// para no alterar la parte fija de la conversación.
		$user_text = $message;
		if ( 0 === (int) $conv['user_messages'] && $page_url ) {
			$user_text = '[El visitante escribe desde la página: ' . $page_url . "]\n\n" . $message;
		}

		$transcript = json_decode( (string) $conv['transcript'], true );
		$transcript = is_array( $transcript ) ? $transcript : array();

		try {
			// Una conversación sigue con el proveedor y modelo con que empezó.
			$provider   = self::make_provider( $conv['provider'], $conv['model'], ! empty( $conv['web_search'] ) );
			$transcript = 'gemini' === $conv['provider'] ? Qhatuq_Provider_Gemini::restore( $transcript ) : Qhatuq_Provider_Claude::restore( $transcript );
			$result     = $provider->run_turn(
				(string) $conv['system_prompt'],
				$transcript,
				$user_text,
				static fn( string $name, array $input ) => Qhatuq_Tools::run( $name, $input, $conv )
			);
		} catch ( \Throwable $e ) {
			error_log( '[qhatuq] Conversación ' . $conv_id . ': ' . $e->getMessage() );
			Qhatuq_DB::add_message( $conv_id, 'error', $e->getMessage() );
			Qhatuq_DB::add_message( $conv_id, 'assistant', self::FALLBACK_REPLY );
			Qhatuq_DB::update_conversation( $conv_id, array( 'user_messages' => (int) $conv['user_messages'] + 1 ) );
			return array(
				'reply' => self::FALLBACK_REPLY,
				'ok'    => false,
			);
		}

		Qhatuq_DB::update_conversation(
			$conv_id,
			array(
				'transcript'    => wp_json_encode( $transcript, JSON_UNESCAPED_UNICODE ),
				'user_messages' => (int) $conv['user_messages'] + 1,
			)
		);
		Qhatuq_DB::add_usage( $conv_id, $result['usage'] );
		// Las búsquedas quedan en la conversación para que el equipo vea qué se verificó.
		foreach ( $result['searches'] ?? array() as $query ) {
			Qhatuq_DB::add_message( $conv_id, 'search', $query );
		}
		Qhatuq_DB::add_message( $conv_id, 'assistant', $result['text'] );

		return array(
			'reply' => $result['text'],
			'ok'    => true,
		);
	}

	public static function ip_hash(): string {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		return hash( 'sha256', $ip . wp_salt( 'auth' ) );
	}
}
