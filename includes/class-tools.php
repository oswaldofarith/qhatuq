<?php
/**
 * Herramientas que el modelo puede usar. Las definiciones son fijas (no dependen
 * de los ajustes) para que el arreglo de herramientas sea idéntico en cada turno.
 */

defined( 'ABSPATH' ) || exit;

class Qhatuq_Tools {

	/** Definiciones neutrales (JSON Schema); cada proveedor las adapta a su formato. */
	public static function definitions(): array {
		return array(
			array(
				'name'        => 'registrar_lead',
				'description' => 'Crea o actualiza el registro del cliente potencial de esta conversación para que un representante prepare la cotización. Úsala cada vez que el cliente dé un dato de contacto o un detalle concreto de lo que necesita, enviando siempre todo lo que sabes hasta el momento (los campos vacíos no borran datos anteriores).',
				'schema'      => array(
					'type'                 => 'object',
					'properties'           => array(
						'nombre'      => array( 'type' => 'string', 'description' => 'Nombre y apellido de la persona.' ),
						'telefono'    => array( 'type' => 'string', 'description' => 'Teléfono o celular.' ),
						'correo'      => array( 'type' => 'string', 'description' => 'Correo electrónico.' ),
						'empresa'     => array( 'type' => 'string', 'description' => 'Empresa o institución.' ),
						'necesidad'   => array( 'type' => 'string', 'description' => 'Qué necesita, con el detalle que haya dado.' ),
						'productos'   => array(
							'type'        => 'array',
							'description' => 'Productos o servicios de interés con su cantidad.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'producto' => array( 'type' => 'string', 'description' => 'Nombre del producto o servicio.' ),
									'cantidad' => array( 'type' => 'string', 'description' => 'Cantidad, si la indicó (ej.: "25 licencias").' ),
								),
								'required'   => array( 'producto' ),
							),
						),
						'temperatura' => array(
							'type'        => 'string',
							'enum'        => array( 'caliente', 'tibio', 'frio' ),
							'description' => 'Qué tan cerca está de comprar, según los criterios indicados.',
						),
						'resumen'     => array( 'type' => 'string', 'description' => 'Resumen de 1 a 3 oraciones para el representante comercial.' ),
						'fuera_de_catalogo' => array(
							'type'        => 'string',
							'description' => 'Solo si pidió un producto que no está en el catálogo: cuál es y el resultado de la verificación (ej.: "AutoCAD LT 2027: disponible, se vende en línea en EE. UU." o "Equipo X: no concluyente, solo contactando a ventas").',
						),
					),
					'required'             => array( 'temperatura', 'resumen' ),
				),
			),
			array(
				'name'        => 'consultar_precio_referencial',
				'description' => 'Devuelve el precio referencial "desde" de un producto o servicio del catálogo, si está disponible para compartir. Úsala solo cuando el cliente insista en conocer el precio después de haberle ofrecido una cotización con un representante.',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'producto_id' => array( 'type' => 'integer', 'description' => 'El número de id del producto o servicio en el catálogo.' ),
					),
					'required'   => array( 'producto_id' ),
				),
			),
		);
	}

	/**
	 * Ejecuta una herramienta y devuelve el texto de resultado para el modelo.
	 *
	 * @return array{0:string,1:bool} [resultado, es_error]
	 */
	public static function run( string $name, $input, array $conversation ): array {
		$input = is_array( $input ) ? $input : array();
		try {
			switch ( $name ) {
				case 'registrar_lead':
					return array( Qhatuq_Leads::upsert_from_tool( (int) $conversation['id'], $input ), false );
				case 'consultar_precio_referencial':
					return array( self::price( (int) ( $input['producto_id'] ?? 0 ) ), false );
			}
			return array( 'Herramienta desconocida.', true );
		} catch ( \Throwable $e ) {
			error_log( '[qhatuq] Error en herramienta ' . $name . ': ' . $e->getMessage() );
			return array( 'No se pudo completar la acción. Continúa la conversación sin ella.', true );
		}
	}

	private static function price( int $post_id ): string {
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || Qhatuq_Catalog::OFFER !== $post->post_type || 'publish' !== $post->post_status ) {
			return 'No existe ese id en el catálogo. Revisa el id en la lista del catálogo.';
		}
		$m = Qhatuq_Catalog::offer_meta( $post_id );
		if ( '' === trim( $m['price_from'] ) || '1' !== $m['price_allowed'] ) {
			return 'No hay un precio referencial que se pueda compartir para "' . $post->post_title . '". Ofrece que un representante le envíe una cotización.';
		}
		$unit = $m['price_unit'] ? ' por ' . $m['price_unit'] : '';
		return sprintf( 'Precio referencial de "%s": desde %s USD%s. Es solo referencial; el valor final depende de la cotización de un representante.', $post->post_title, $m['price_from'], $unit );
	}
}
