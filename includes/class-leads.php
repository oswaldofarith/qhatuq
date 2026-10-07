<?php
/**
 * Registro de leads y aviso por correo al equipo comercial.
 */

defined( 'ABSPATH' ) || exit;

class Qhatuq_Leads {

	const STATUSES = array(
		'nuevo'      => 'Nuevo',
		'contactado' => 'Contactado',
		'cotizado'   => 'Cotizado',
		'ganado'     => 'Ganado',
		'perdido'    => 'Perdido',
	);

	const TEMPERATURES = array(
		'caliente' => 'Caliente',
		'tibio'    => 'Tibio',
		'frio'     => 'Frío',
	);

	public static function upsert_from_tool( int $conversation_id, array $in ): string {
		$fields = array();
		$map    = array(
			'nombre'    => array( 'name', 200 ),
			'telefono'  => array( 'phone', 60 ),
			'empresa'   => array( 'company', 200 ),
			'necesidad' => array( 'need', 2000 ),
			'resumen'   => array( 'summary', 2000 ),
			'fuera_de_catalogo' => array( 'off_catalog', 1000 ),
		);
		$single_line = array( 'name', 'phone', 'company' );
		foreach ( $map as $key => list( $col, $max ) ) {
			$raw = isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? (string) $in[ $key ] : '';
			$val = trim( in_array( $col, $single_line, true ) ? sanitize_text_field( $raw ) : sanitize_textarea_field( $raw ) );
			if ( '' !== $val ) {
				$fields[ $col ] = mb_substr( $val, 0, $max );
			}
		}

		$warnings = array();
		if ( ! empty( $in['correo'] ) && is_scalar( $in['correo'] ) ) {
			$email = sanitize_email( (string) $in['correo'] );
			if ( is_email( $email ) ) {
				$fields['email'] = $email;
			} else {
				$warnings[] = 'El correo "' . sanitize_text_field( (string) $in['correo'] ) . '" no parece válido; pide al cliente que lo confirme.';
			}
		}

		if ( isset( $in['temperatura'] ) && array_key_exists( $in['temperatura'], self::TEMPERATURES ) ) {
			$fields['temperature'] = $in['temperatura'];
		}

		if ( ! empty( $in['productos'] ) && is_array( $in['productos'] ) ) {
			$items = array();
			foreach ( array_slice( $in['productos'], 0, 50 ) as $item ) {
				if ( ! is_array( $item ) || empty( $item['producto'] ) || ! is_scalar( $item['producto'] ) ) {
					continue;
				}
				$items[] = array(
					'producto' => mb_substr( sanitize_text_field( (string) $item['producto'] ), 0, 200 ),
					'cantidad' => isset( $item['cantidad'] ) && is_scalar( $item['cantidad'] ) ? mb_substr( sanitize_text_field( (string) $item['cantidad'] ), 0, 100 ) : '',
				);
			}
			if ( $items ) {
				$fields['items'] = wp_json_encode( $items, JSON_UNESCAPED_UNICODE );
			}
		}

		if ( ! $fields ) {
			return 'No se recibió ningún dato para registrar.';
		}

		$lead = Qhatuq_DB::get_lead_by_conversation( $conversation_id );
		if ( $lead ) {
			Qhatuq_DB::update_lead( (int) $lead['id'], $fields );
			$lead_id = (int) $lead['id'];
		} else {
			$lead_id = Qhatuq_DB::insert_lead( array( 'conversation_id' => $conversation_id ) + $fields );
		}

		$lead    = Qhatuq_DB::get_lead( $lead_id );
		$missing = self::missing( $lead );
		self::maybe_notify( $lead );

		$msg = 'Registro guardado.';
		if ( $missing ) {
			$msg .= ' Datos que aún faltan para la cotización: ' . implode( ', ', $missing ) . '. Pídelos con naturalidad si el cliente está dispuesto.';
		} else {
			$msg .= ' El registro está completo: confirma al cliente lo anotado y que un representante lo contactará con la cotización.';
		}
		return trim( $msg . ' ' . implode( ' ', $warnings ) );
	}

	public static function missing( array $lead ): array {
		$missing = array();
		$labels  = array(
			'name'    => 'nombre',
			'phone'   => 'teléfono',
			'email'   => 'correo',
			'company' => 'empresa o institución',
		);
		foreach ( $labels as $col => $label ) {
			if ( '' === trim( (string) $lead[ $col ] ) ) {
				$missing[] = $label;
			}
		}
		$items = json_decode( (string) $lead['items'], true );
		if ( '' === trim( (string) $lead['need'] ) && empty( $items ) ) {
			$missing[] = 'qué productos o servicios necesita y en qué cantidad';
		}
		return $missing;
	}

	/**
	 * Avisa una sola vez por lead, cuando hay al menos un medio de contacto y una necesidad.
	 */
	private static function maybe_notify( array $lead ): void {
		if ( ! empty( $lead['notified_at'] ) ) {
			return;
		}
		$has_contact = '' !== $lead['phone'] || '' !== $lead['email'];
		$has_need    = '' !== $lead['need'] || ! empty( json_decode( (string) $lead['items'], true ) );
		if ( ! $has_contact || ! $has_need ) {
			return;
		}
		$to = array_filter( array_map( 'trim', explode( ',', (string) Qhatuq_Settings::get( 'notify_emails' ) ) ) );
		if ( ! $to ) {
			return;
		}

		$temp    = self::TEMPERATURES[ $lead['temperature'] ] ?? $lead['temperature'];
		$subject = sprintf( '[%s] Nuevo lead (%s): %s', get_bloginfo( 'name' ), $temp, $lead['company'] ? $lead['company'] : $lead['name'] );
		$wa      = self::whatsapp_url( $lead );
		$body    = "Hay un nuevo cliente potencial registrado por el asistente del sitio.\n\n" . self::as_text( $lead ) .
			( $wa ? "\n\nEscribirle por WhatsApp: " . $wa : '' ) .
			"\n\nVer en el panel: " . admin_url( 'admin.php?page=qhatuq-leads&lead=' . (int) $lead['id'] );

		if ( wp_mail( $to, $subject, $body ) ) {
			Qhatuq_DB::update_lead( (int) $lead['id'], array( 'notified_at' => Qhatuq_DB::now() ) );
		}
	}

	/**
	 * Número en formato internacional para WhatsApp, o '' si no parece un celular.
	 * Ecuador: 09XXXXXXXX → 5939XXXXXXXX; los fijos (02…07) no tienen WhatsApp.
	 */
	public static function whatsapp_number( string $phone ): string {
		$raw    = trim( $phone );
		$digits = preg_replace( '/\D/', '', $raw );
		if ( '' === $digits ) {
			return '';
		}
		$cc = (string) Qhatuq_Settings::get( 'whatsapp_country' );
		if ( str_starts_with( $raw, '+' ) || str_starts_with( $digits, '00' ) ) {
			$digits = ltrim( $digits, '0' ); // Ya viene con código de país.
		} elseif ( str_starts_with( $digits, '0' ) ) {
			$digits = $cc . substr( $digits, 1 ); // Número nacional con 0 inicial.
		} elseif ( ! str_starts_with( $digits, $cc ) ) {
			$digits = $cc . $digits; // Número nacional sin 0 (ej. 991234567).
		}
		if ( strlen( $digits ) < 10 || strlen( $digits ) > 15 ) {
			return '';
		}
		// En Ecuador solo los celulares (9…) usan WhatsApp.
		if ( str_starts_with( $digits, '593' ) && ( '9' !== ( $digits[3] ?? '' ) || 12 !== strlen( $digits ) ) ) {
			return '';
		}
		return $digits;
	}

	public static function whatsapp_url( array $lead ): string {
		$number = self::whatsapp_number( (string) ( $lead['phone'] ?? '' ) );
		if ( '' === $number ) {
			return '';
		}
		$first   = trim( strtok( (string) $lead['name'], ' ' ) ?: '' );
		$message = strtr(
			(string) Qhatuq_Settings::get( 'whatsapp_message' ),
			array(
				'{nombre}'  => $first,
				'{sitio}'   => get_bloginfo( 'name' ),
				'{empresa}' => (string) $lead['company'],
			)
		);
		$message = trim( preg_replace( '/\s+([,.])/', '$1', preg_replace( '/ {2,}/', ' ', $message ) ) );
		return 'https://wa.me/' . $number . ( '' !== $message ? '?text=' . rawurlencode( $message ) : '' );
	}

	public static function items_text( array $lead ): string {
		$items = json_decode( (string) $lead['items'], true );
		if ( ! $items ) {
			return '';
		}
		return implode(
			'; ',
			array_map(
				static fn( $i ) => $i['producto'] . ( ! empty( $i['cantidad'] ) ? ' (' . $i['cantidad'] . ')' : '' ),
				$items
			)
		);
	}

	public static function as_text( array $lead ): string {
		$rows = array(
			'Nombre'      => $lead['name'],
			'Empresa'     => $lead['company'],
			'Teléfono'    => $lead['phone'],
			'Correo'      => $lead['email'],
			'Necesidad'   => $lead['need'],
			'Productos'   => self::items_text( $lead ),
			'Temperatura' => self::TEMPERATURES[ $lead['temperature'] ] ?? $lead['temperature'],
			'Resumen'     => $lead['summary'],
		);
		if ( ! empty( $lead['off_catalog'] ) ) {
			$rows['Fuera de catálogo'] = $lead['off_catalog'];
		}
		$out = array();
		foreach ( $rows as $label => $value ) {
			$out[] = $label . ': ' . ( '' !== (string) $value ? $value : '—' );
		}
		return implode( "\n", $out );
	}
}
