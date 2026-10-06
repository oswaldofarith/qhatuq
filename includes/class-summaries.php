<?php
/**
 * Resumen de cada conversación por correo y resumen semanal.
 *
 * Cada 5 minutos se buscan conversaciones inactivas (sin mensajes durante el tiempo
 * configurado) que aún no tienen resumen. Para cada una se genera un resumen con el
 * mismo proveedor y modelo de la conversación y se envía al equipo comercial.
 */

defined( 'ABSPATH' ) || exit;

use Anthropic\Client;

class Qhatuq_Summaries {

	const HOOK        = 'qhatuq_process_summaries';
	const WEEKLY_HOOK = 'qhatuq_weekly_digest';
	const SCHEDULE    = 'qhatuq_five_minutes';
	const BATCH       = 5;

	const INTEREST = array(
		'alto'    => 'Alto',
		'medio'   => 'Medio',
		'bajo'    => 'Bajo',
		'ninguno' => 'Sin interés comercial',
	);

	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'process' ) );
		add_action( self::WEEKLY_HOOK, array( __CLASS__, 'weekly_digest' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedules( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Cada 5 minutos (Qhatuq)',
		);
		return $schedules;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
		if ( ! wp_next_scheduled( self::WEEKLY_HOOK ) ) {
			// Lunes a las 8:00, hora del sitio.
			$next = new DateTime( 'next monday 08:00', wp_timezone() );
			wp_schedule_event( $next->getTimestamp(), 'weekly', self::WEEKLY_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::WEEKLY_HOOK );
	}

	/* ---------------------------------------------------------------- Proceso */

	public static function process(): void {
		if ( empty( Qhatuq_Settings::get( 'summary_enabled' ) ) ) {
			return;
		}
		// Evita dos ejecuciones simultáneas (WP-Cron puede solaparse).
		if ( get_transient( 'qhatuq_summaries_lock' ) ) {
			return;
		}
		set_transient( 'qhatuq_summaries_lock', 1, 5 * MINUTE_IN_SECONDS );

		try {
			global $wpdb;
			$t      = Qhatuq_DB::table( 'conversations' );
			$idle   = (int) Qhatuq_Settings::get( 'summary_idle_minutes' );
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $idle * MINUTE_IN_SECONDS );
			$ids    = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$t} WHERE summary_status = '' AND user_messages > 0 AND updated_at < %s ORDER BY id ASC LIMIT %d",
					$cutoff,
					self::BATCH
				)
			);
			foreach ( $ids as $id ) {
				self::summarize_and_send( (int) $id );
			}
		} finally {
			delete_transient( 'qhatuq_summaries_lock' );
		}
	}

	public static function summarize_and_send( int $conversation_id ): void {
		$conv = Qhatuq_DB::get_conversation( $conversation_id );
		if ( ! $conv ) {
			return;
		}
		$lead = Qhatuq_DB::get_lead_by_conversation( $conversation_id );

		try {
			$summary = self::generate( $conv, $lead );
			$status  = 'sent';
		} catch ( \Throwable $e ) {
			error_log( '[qhatuq] Resumen de la conversación ' . $conversation_id . ': ' . $e->getMessage() );
			$summary = array(
				'resumen'           => 'No se pudo generar el resumen automático (' . $e->getMessage() . '). Revise la conversación completa en el panel.',
				'interes'           => $lead ? 'medio' : 'bajo',
				'datos_contacto'    => '',
				'pendiente'         => '',
				'siguiente_paso'    => '',
				'lead_sin_contacto' => false,
			);
			$status  = 'failed';
		}

		$send = ! empty( Qhatuq_Settings::get( 'summary_all' ) ) || 'ninguno' !== $summary['interes'] || $lead;
		if ( $send ) {
			self::send_email( $conv, $lead, $summary );
		} elseif ( 'sent' === $status ) {
			$status = 'skipped';
		}

		Qhatuq_DB::update_conversation_raw(
			$conversation_id,
			array(
				'summary'        => wp_json_encode( $summary, JSON_UNESCAPED_UNICODE ),
				'summary_status' => $status,
				'summary_at'     => Qhatuq_DB::now(),
			)
		);
	}

	/* ---------------------------------------------------------------- Generación */

	private static function schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'resumen'           => array(
					'type'        => 'string',
					'description' => '2 a 4 oraciones: qué buscaba el visitante y cómo terminó la conversación.',
				),
				'interes'           => array(
					'type'        => 'string',
					'enum'        => array_keys( self::INTEREST ),
					'description' => 'Interés comercial: alto (necesidad concreta y lista para cotizar), medio, bajo o ninguno (no era un posible cliente).',
				),
				'datos_contacto'    => array(
					'type'        => 'string',
					'description' => 'Datos que dejó (nombre, institución, teléfono, correo) o vacío si ninguno.',
				),
				'pendiente'         => array(
					'type'        => 'string',
					'description' => 'Qué quedó sin resolver o qué datos faltan para cotizar; vacío si nada.',
				),
				'siguiente_paso'    => array(
					'type'        => 'string',
					'description' => 'Acción concreta sugerida al ejecutivo comercial.',
				),
				'lead_sin_contacto' => array(
					'type'        => 'boolean',
					'description' => 'true si mostró interés concreto de compra pero no dejó teléfono ni correo.',
				),
			),
			'required'             => array( 'resumen', 'interes', 'datos_contacto', 'pendiente', 'siguiente_paso', 'lead_sin_contacto' ),
			'additionalProperties' => false,
		);
	}

	private static function transcript_text( int $conversation_id ): string {
		$labels = array(
			'user'      => 'Visitante',
			'assistant' => 'Agente',
			'search'    => '(Búsqueda web del agente)',
			'error'     => '(Error técnico)',
		);
		$lines  = array();
		foreach ( Qhatuq_DB::get_messages( $conversation_id ) as $m ) {
			$lines[] = ( $labels[ $m['role'] ] ?? $m['role'] ) . ': ' . $m['content'];
		}
		$text = implode( "\n", $lines );
		// Conversaciones muy largas: se resume el final, que es lo más relevante para el seguimiento.
		return mb_strlen( $text ) > 40000 ? '[…]' . mb_substr( $text, -40000 ) : $text;
	}

	public static function generate( array $conv, ?array $lead ): array {
		$instructions = 'Eres asistente de un equipo comercial. Lee la conversación entre un visitante del sitio web y el agente virtual de ventas, y prepara un resumen breve y útil para el ejecutivo que hará el seguimiento. Escribe en español. Básate solo en lo que dice la conversación; no inventes datos.';
		$content      = "<conversacion>\n" . self::transcript_text( (int) $conv['id'] ) . "\n</conversacion>";
		if ( $lead ) {
			$content .= "\n\n<lead_registrado>\n" . Qhatuq_Leads::as_text( $lead ) . "\n</lead_registrado>";
		}
		$content .= "\n\nDevuelve el resumen en el formato JSON indicado.";

		$raw = 'gemini' === $conv['provider']
			? self::generate_gemini( (string) $conv['model'], $instructions, $content )
			: self::generate_claude( (string) $conv['model'], $instructions, $content );

		return self::normalize( $raw );
	}

	private static function generate_claude( string $model, string $instructions, string $content ): string {
		if ( ! class_exists( Client::class ) ) {
			throw new Qhatuq_Provider_Exception( 'Falta el SDK de Anthropic (carpeta vendor/).' );
		}
		$key = Qhatuq_Settings::api_key( 'claude' );
		if ( '' === $key ) {
			throw new Qhatuq_Provider_Exception( 'No hay API key de Claude.' );
		}
		$client = new Client( apiKey: $key, requestOptions: array( 'maxRetries' => 2, 'timeout' => 90 ) );
		$args   = array(
			'model'        => $model,
			'maxTokens'    => 4000,
			'system'       => $instructions,
			'messages'     => array( array( 'role' => 'user', 'content' => $content ) ),
			'outputConfig' => array(
				'format' => array(
					'type'   => 'json_schema',
					'schema' => self::schema(),
				),
			),
		);
		if ( in_array( $model, Qhatuq_Provider_Claude::CURRENT_MODELS, true ) ) {
			$args['outputConfig']['effort'] = 'low';
		}
		$message = $client->messages->create( ...$args );
		if ( 'refusal' === $message->stopReason ) {
			throw new Qhatuq_Provider_Exception( 'El modelo no generó el resumen.' );
		}
		foreach ( $message->content as $block ) {
			if ( 'text' === $block->type ) {
				return (string) $block->text;
			}
		}
		throw new Qhatuq_Provider_Exception( 'Respuesta vacía al generar el resumen.' );
	}

	private static function generate_gemini( string $model, string $instructions, string $content ): string {
		$key = Qhatuq_Settings::api_key( 'gemini' );
		if ( '' === $key ) {
			throw new Qhatuq_Provider_Exception( 'No hay API key de Gemini.' );
		}
		$schema = wp_json_encode( self::schema(), JSON_UNESCAPED_UNICODE );
		$body   = array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => $instructions . "\nResponde solo con un objeto JSON que cumpla este esquema: " . $schema ) ) ),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $content ) ),
				),
			),
			'generationConfig'  => array(
				'responseMimeType' => 'application/json',
				'maxOutputTokens'  => 4000,
			),
		);
		$url      = apply_filters( 'qhatuq_gemini_endpoint', sprintf( Qhatuq_Provider_Gemini::ENDPOINT, rawurlencode( $model ) ), $model );
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 90,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Qhatuq_Provider_Exception( $response->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) ) {
			throw new Qhatuq_Provider_Exception( 'Error de Gemini: ' . ( is_array( $data ) ? ( $data['error']['message'] ?? '' ) : '' ) );
		}
		$text = '';
		foreach ( (array) ( $data['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			if ( isset( $part['text'] ) && empty( $part['thought'] ) ) {
				$text .= $part['text'];
			}
		}
		return $text;
	}

	/** Valida y completa el JSON del modelo. */
	public static function normalize( string $raw ): array {
		$raw = trim( preg_replace( '/^```(?:json)?|```$/m', '', trim( $raw ) ) );
		$d   = json_decode( $raw, true );
		if ( ! is_array( $d ) ) {
			throw new Qhatuq_Provider_Exception( 'El resumen no llegó en formato JSON.' );
		}
		$str = static fn( $k ) => isset( $d[ $k ] ) && is_scalar( $d[ $k ] ) ? trim( sanitize_textarea_field( (string) $d[ $k ] ) ) : '';
		return array(
			'resumen'           => $str( 'resumen' ),
			'interes'           => array_key_exists( $d['interes'] ?? '', self::INTEREST ) ? $d['interes'] : 'bajo',
			'datos_contacto'    => $str( 'datos_contacto' ),
			'pendiente'         => $str( 'pendiente' ),
			'siguiente_paso'    => $str( 'siguiente_paso' ),
			'lead_sin_contacto' => ! empty( $d['lead_sin_contacto'] ),
		);
	}

	/* ---------------------------------------------------------------- Correos */

	private static function recipients(): array {
		return array_filter( array_map( 'trim', explode( ',', (string) Qhatuq_Settings::get( 'notify_emails' ) ) ) );
	}

	private static function send_email( array $conv, ?array $lead, array $s ): void {
		$to = self::recipients();
		if ( ! $to ) {
			return;
		}
		$who = $lead && ( $lead['company'] || $lead['name'] )
			? ( $lead['company'] ? $lead['company'] : $lead['name'] )
			: 'Visitante sin identificar';

		$prefix  = $s['lead_sin_contacto'] ? '⚠ Interesado sin datos de contacto' : 'Conversación';
		$subject = sprintf( '[%s] %s · Interés %s · %s', get_bloginfo( 'name' ), $prefix, mb_strtolower( self::INTEREST[ $s['interes'] ] ), $who );

		$body   = array();
		if ( $s['lead_sin_contacto'] ) {
			$body[] = 'ATENCIÓN: el visitante mostró interés concreto pero no dejó teléfono ni correo. Revise si hay otra pista (institución, nombre) para contactarlo.';
			$body[] = '';
		}
		$body[] = 'Resumen: ' . $s['resumen'];
		$body[] = 'Interés comercial: ' . self::INTEREST[ $s['interes'] ];
		$body[] = 'Datos de contacto: ' . ( $s['datos_contacto'] ? $s['datos_contacto'] : 'ninguno' );
		if ( $s['pendiente'] ) {
			$body[] = 'Pendiente: ' . $s['pendiente'];
		}
		if ( $s['siguiente_paso'] ) {
			$body[] = 'Siguiente paso sugerido: ' . $s['siguiente_paso'];
		}
		if ( $lead ) {
			$body[] = '';
			$body[] = '— Lead registrado —';
			$body[] = Qhatuq_Leads::as_text( $lead );
			$wa = Qhatuq_Leads::whatsapp_url( $lead );
			if ( $wa ) {
				$body[] = 'Escribirle por WhatsApp: ' . $wa;
			}
			$body[] = 'Ver lead: ' . admin_url( 'admin.php?page=qhatuq-leads&lead=' . (int) $lead['id'] );
		}
		$body[] = '';
		$body[] = sprintf( 'Mensajes del visitante: %d · Inicio: %s', (int) $conv['user_messages'], get_date_from_gmt( $conv['created_at'], 'd/m/Y H:i' ) );
		if ( $conv['page_url'] ) {
			$body[] = 'Página de origen: ' . $conv['page_url'];
		}
		$body[] = 'Conversación completa: ' . admin_url( 'admin.php?page=qhatuq-conversations&conversation=' . (int) $conv['id'] );

		wp_mail( $to, $subject, implode( "\n", $body ) );
	}

	/** Resumen semanal: actividad de los últimos 7 días. Solo se envía si hubo conversaciones. */
	public static function weekly_digest(): void {
		if ( empty( Qhatuq_Settings::get( 'weekly_digest' ) ) ) {
			return;
		}
		$to = self::recipients();
		if ( ! $to ) {
			return;
		}
		global $wpdb;
		$c     = Qhatuq_DB::table( 'conversations' );
		$l     = Qhatuq_DB::table( 'leads' );
		$since = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );

		$convs = $wpdb->get_results( $wpdb->prepare( "SELECT id, summary FROM {$c} WHERE user_messages > 0 AND created_at >= %s ORDER BY id ASC", $since ), ARRAY_A );
		if ( ! $convs ) {
			return;
		}
		$leads = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$l} WHERE created_at >= %s ORDER BY id ASC", $since ), ARRAY_A );

		$by_interest = array_fill_keys( array_keys( self::INTEREST ), 0 );
		$no_contact  = array();
		foreach ( $convs as $cv ) {
			$s = json_decode( (string) $cv['summary'], true );
			if ( ! is_array( $s ) ) {
				continue;
			}
			$by_interest[ $s['interes'] ?? 'bajo' ] = ( $by_interest[ $s['interes'] ?? 'bajo' ] ?? 0 ) + 1;
			if ( ! empty( $s['lead_sin_contacto'] ) ) {
				$no_contact[] = '- ' . $s['resumen'] . ' → ' . admin_url( 'admin.php?page=qhatuq-conversations&conversation=' . (int) $cv['id'] );
			}
		}

		$temps = array_fill_keys( array_keys( Qhatuq_Leads::TEMPERATURES ), 0 );
		$off   = array();
		foreach ( $leads as $ld ) {
			$temps[ $ld['temperature'] ] = ( $temps[ $ld['temperature'] ] ?? 0 ) + 1;
			if ( ! empty( $ld['off_catalog'] ) ) {
				$off[] = '- ' . $ld['off_catalog'];
			}
		}

		$body   = array();
		$body[] = sprintf( 'Actividad del asistente en los últimos 7 días (%s).', get_bloginfo( 'name' ) );
		$body[] = '';
		$body[] = 'Conversaciones: ' . count( $convs );
		$body[] = 'Por interés comercial: ' . implode( ', ', array_map( static fn( $k, $n ) => self::INTEREST[ $k ] . ' ' . $n, array_keys( $by_interest ), $by_interest ) );
		$body[] = 'Leads nuevos: ' . count( $leads ) . ' (' . implode( ', ', array_map( static fn( $k, $n ) => Qhatuq_Leads::TEMPERATURES[ $k ] . ' ' . $n, array_keys( $temps ), $temps ) ) . ')';
		if ( $no_contact ) {
			$body[] = '';
			$body[] = 'Interesados que no dejaron contacto:';
			$body   = array_merge( $body, $no_contact );
		}
		if ( $off ) {
			$body[] = '';
			$body[] = 'Productos fuera del catálogo que pidieron (ideas para el catálogo):';
			$body   = array_merge( $body, $off );
		}
		$body[] = '';
		$body[] = 'Leads: ' . admin_url( 'admin.php?page=qhatuq-leads' );
		$body[] = 'Conversaciones: ' . admin_url( 'admin.php?page=qhatuq-conversations' );

		wp_mail( $to, sprintf( '[%s] Resumen semanal del asistente de ventas', get_bloginfo( 'name' ) ), implode( "\n", $body ) );
	}
}
