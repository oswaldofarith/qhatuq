<?php
/**
 * Ajustes del agente (una sola opción serializada).
 */

defined( 'ABSPATH' ) || exit;

class Qhatuq_Settings {

	const OPTION = 'qhatuq_settings';

	/** Modelos de Claude ofrecidos en el selector. */
	const CLAUDE_MODELS = array(
		'claude-opus-5-5'   => 'Claude Opus 5.5 (recomendado, US$4 / US$20 por millón de tokens)',
		'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (US$2 / US$10 por millón de tokens)',
		'claude-haiku-4-5'  => 'Claude Haiku 4.5 (US$1 / US$5 por millón de tokens)',
	);

	public static function defaults(): array {
		return array(
			'enabled'              => 1,
			'provider'             => 'claude',
			'claude_api_key'       => '',
			'claude_model'         => 'claude-opus-5-5',
			'claude_effort'        => 'low',
			'web_search'           => 1,
			'gemini_api_key'       => '',
			'gemini_model'         => 'gemini-3-flash-preview',

			'company_name'         => get_bloginfo( 'name' ),
			'company_description'  => '',
			'agent_name'           => 'Asistente',
			'treatment'            => 'usted',
			'greeting'             => '¡Hola! Soy el asistente virtual. ¿En qué le puedo ayudar hoy?',
			'prohibitions'         => implode(
				"\n",
				array(
					'Ofrecer o insinuar descuentos, promociones o condiciones especiales.',
					'Comprometer plazos de entrega o de implementación.',
					'Hablar mal de la competencia o compararse con ella por nombre.',
					'Dar asesoría legal, tributaria o sobre procesos de contratación pública más allá de información general.',
					'Inventar productos, servicios, características, certificaciones o clientes.',
					'Responder temas ajenos a la empresa y su oferta.',
				)
			),
			'lead_criteria'        => implode(
				"\n",
				array(
					'Caliente: indicó qué producto o servicio necesita y la cantidad, y dejó sus datos de contacto o pide cotización.',
					'Tibio: tiene una necesidad clara pero falta cantidad, detalle o algún dato de contacto.',
					'Frío: solo está explorando o pidiendo información general.',
				)
			),
			'extra_instructions'   => '',

			'notify_emails'        => get_option( 'admin_email' ),
			'privacy_url'          => function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '',
			'privacy_notice'       => 'Este chat es atendido por un asistente de inteligencia artificial. Si comparte sus datos, los usaremos solo para atender su solicitud.',

			'widget_color'         => '#0b5cab',
			'widget_position'      => 'right',
			'widget_title'         => 'Asesor comercial',
			'widget_avatar'        => '',
			'widget_avatar_id'     => 0,
			'widget_suggestions'   => "Licencias de software\nCentrales IP PBX\nServicios de TI\nQuiero una cotización",
			'teaser_delay'         => 12,

			'max_messages'         => 40,
			'max_per_ip_hour'      => 60,
			'max_message_chars'    => 1500,
			'retention_days'       => 365,
		);
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Las claves pueden definirse en wp-config.php (recomendado) para no guardarlas en la base de datos.
	 */
	public static function api_key( string $provider ): string {
		$const = 'claude' === $provider ? 'QHATUQ_CLAUDE_API_KEY' : 'QHATUQ_GEMINI_API_KEY';
		if ( defined( $const ) && constant( $const ) ) {
			return (string) constant( $const );
		}
		return (string) self::get( $provider . '_api_key' );
	}

	public static function key_from_constant( string $provider ): bool {
		$const = 'claude' === $provider ? 'QHATUQ_CLAUDE_API_KEY' : 'QHATUQ_GEMINI_API_KEY';
		return defined( $const ) && constant( $const );
	}

	/** URL de la foto del agente: la imagen elegida en la Biblioteca de medios o, si no hay, la URL antigua. */
	public static function avatar_url(): string {
		$id = (int) self::get( 'widget_avatar_id' );
		if ( $id ) {
			$url = wp_get_attachment_image_url( $id, array( 192, 192 ) );
			if ( $url ) {
				return $url;
			}
		}
		return (string) self::get( 'widget_avatar' );
	}

	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$current  = self::all();
		$defaults = self::defaults();
		$out      = array();

		$out['enabled']  = empty( $input['enabled'] ) ? 0 : 1;
		$out['web_search'] = empty( $input['web_search'] ) ? 0 : 1;
		$out['provider'] = in_array( $input['provider'] ?? '', array( 'claude', 'gemini' ), true ) ? $input['provider'] : 'claude';

		// Un campo de clave vacío conserva la clave guardada.
		foreach ( array( 'claude_api_key', 'gemini_api_key' ) as $k ) {
			$val       = trim( (string) ( $input[ $k ] ?? '' ) );
			$out[ $k ] = '' === $val ? $current[ $k ] : sanitize_text_field( $val );
			if ( ! empty( $input[ $k . '_clear' ] ) ) {
				$out[ $k ] = '';
			}
		}

		$out['claude_model']  = array_key_exists( $input['claude_model'] ?? '', self::CLAUDE_MODELS ) ? $input['claude_model'] : $defaults['claude_model'];
		$out['claude_effort'] = in_array( $input['claude_effort'] ?? '', array( 'low', 'medium', 'high' ), true ) ? $input['claude_effort'] : 'low';
		$gm                   = preg_replace( '/[^a-z0-9.\-]/', '', strtolower( (string) ( $input['gemini_model'] ?? '' ) ) );
		$out['gemini_model']  = $gm ? $gm : $defaults['gemini_model'];

		foreach ( array( 'company_name', 'agent_name', 'greeting', 'widget_title' ) as $k ) {
			$out[ $k ] = sanitize_text_field( $input[ $k ] ?? $defaults[ $k ] );
		}
		foreach ( array( 'company_description', 'prohibitions', 'lead_criteria', 'extra_instructions', 'privacy_notice' ) as $k ) {
			$out[ $k ] = sanitize_textarea_field( $input[ $k ] ?? '' );
		}
		$out['treatment'] = 'tu' === ( $input['treatment'] ?? '' ) ? 'tu' : 'usted';

		$emails = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) ( $input['notify_emails'] ?? '' ) ) ) ) );
		$out['notify_emails'] = implode( ', ', $emails );
		$out['privacy_url']   = esc_url_raw( $input['privacy_url'] ?? '' );

		$color                  = sanitize_hex_color( $input['widget_color'] ?? '' );
		$out['widget_color']    = $color ? $color : $defaults['widget_color'];
		$out['widget_position'] = 'left' === ( $input['widget_position'] ?? '' ) ? 'left' : 'right';
		$out['widget_avatar']   = esc_url_raw( $input['widget_avatar'] ?? '' );
		$avatar_id              = absint( $input['widget_avatar_id'] ?? 0 );
		$out['widget_avatar_id'] = $avatar_id && wp_attachment_is_image( $avatar_id ) ? $avatar_id : 0;
		$suggestions            = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( "\n", (string) ( $input['widget_suggestions'] ?? '' ) ) ) ) );
		$out['widget_suggestions'] = implode( "\n", array_slice( $suggestions, 0, 6 ) );

		$ints = array(
			'max_messages'      => array( 4, 200 ),
			'max_per_ip_hour'   => array( 5, 1000 ),
			'max_message_chars' => array( 200, 5000 ),
			'retention_days'    => array( 0, 3650 ),
			'teaser_delay'      => array( 0, 300 ),
		);
		foreach ( $ints as $k => list( $min, $max ) ) {
			$out[ $k ] = max( $min, min( $max, absint( $input[ $k ] ?? $defaults[ $k ] ) ) );
		}

		return $out;
	}
}
