<?php
/**
 * Proveedor Gemini (Google) mediante la API REST de generateContent y la API HTTP de WordPress.
 *
 * El historial guarda los "contents" tal como los devuelve la API (incluidas las firmas
 * de pensamiento de las llamadas a funciones) y se reenvía sin modificar.
 */

defined( 'ABSPATH' ) || exit;

class Qhatuq_Provider_Gemini implements Qhatuq_Provider {

	const MAX_TOOL_ROUNDS = 6;
	const ENDPOINT        = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

	private string $api_key;
	private string $model;

	public function __construct( string $api_key, string $model ) {
		$this->api_key = $api_key;
		$this->model   = $model;
	}

	public function id(): string {
		return 'gemini';
	}

	public function model(): string {
		return $this->model;
	}

	public function run_turn( string $system, array &$transcript, string $user_text, callable $run_tool ): array {
		$original = $transcript;
		$usage    = array( 'input' => 0, 'output' => 0, 'cache_read' => 0 );

		$transcript[] = array(
			'role'  => 'user',
			'parts' => array( array( 'text' => $user_text ) ),
		);

		try {
			for ( $round = 0; $round < self::MAX_TOOL_ROUNDS; $round++ ) {
				$data = $this->request( $system, $transcript );

				$meta                 = $data['usageMetadata'] ?? array();
				$usage['input']      += (int) ( $meta['promptTokenCount'] ?? 0 ) - (int) ( $meta['cachedContentTokenCount'] ?? 0 );
				$usage['output']     += (int) ( $meta['candidatesTokenCount'] ?? 0 ) + (int) ( $meta['thoughtsTokenCount'] ?? 0 );
				$usage['cache_read'] += (int) ( $meta['cachedContentTokenCount'] ?? 0 );

				$candidate = $data['candidates'][0] ?? null;
				$content   = $candidate['content'] ?? null;
				if ( ! $candidate || empty( $content['parts'] ) ) {
					$transcript = $original;
					return array(
						'text'  => 'Disculpe, no puedo ayudarle con eso. ¿Hay algo sobre nuestros productos o servicios en lo que le pueda orientar?',
						'usage' => $usage,
					);
				}

				$content['role'] = 'model';
				foreach ( $content['parts'] as &$part ) {
					if ( isset( $part['functionCall'] ) && array() === ( $part['functionCall']['args'] ?? array() ) ) {
						$part['functionCall']['args'] = new \stdClass();
					}
				}
				unset( $part );
				$transcript[] = $content;

				$calls = array_values( array_filter( $content['parts'], static fn( $p ) => isset( $p['functionCall'] ) ) );
				if ( ! $calls ) {
					$text = '';
					foreach ( $content['parts'] as $p ) {
						if ( isset( $p['text'] ) && empty( $p['thought'] ) ) {
							$text .= $p['text'];
						}
					}
					$text = trim( $text );
					return array(
						'text'  => '' !== $text ? $text : 'Disculpe, ¿podría repetirme su consulta?',
						'usage' => $usage,
					);
				}

				$responses = array();
				foreach ( $calls as $p ) {
					$call                      = $p['functionCall'];
					list( $result, $is_error ) = $run_tool( (string) $call['name'], (array) ( $call['args'] ?? array() ) );
					$response                  = array(
						'name'     => $call['name'],
						'response' => $is_error ? array( 'error' => $result ) : array( 'result' => $result ),
					);
					if ( ! empty( $call['id'] ) ) {
						$response['id'] = $call['id'];
					}
					$responses[] = array( 'functionResponse' => $response );
				}
				$transcript[] = array(
					'role'  => 'user',
					'parts' => $responses,
				);
			}
			throw new Qhatuq_Provider_Exception( 'Se superó el número máximo de llamadas a herramientas en un turno.' );
		} catch ( Qhatuq_Provider_Exception $e ) {
			$transcript = $original;
			throw $e;
		}
	}

	private function request( string $system, array $transcript ): array {
		$body = array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
			'contents'          => $transcript,
			'tools'             => array( array( 'functionDeclarations' => self::tools() ) ),
			'generationConfig'  => array( 'maxOutputTokens' => 8192 ),
		);

		$response = wp_remote_post(
			apply_filters( 'qhatuq_gemini_endpoint', sprintf( self::ENDPOINT, rawurlencode( $this->model ) ), $this->model ),
			array(
				'timeout' => 90,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $this->api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Qhatuq_Provider_Exception( 'No se pudo conectar con la API de Gemini: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) ) {
			$msg = is_array( $data ) ? ( $data['error']['message'] ?? '' ) : '';
			throw new Qhatuq_Provider_Exception( sprintf( 'Error de la API de Gemini (%d): %s', $code, $msg ) );
		}
		return $data;
	}

	private static function tools(): array {
		return array_map(
			static fn( $t ) => array(
				'name'        => $t['name'],
				'description' => $t['description'],
				'parameters'  => $t['schema'],
			),
			Qhatuq_Tools::definitions()
		);
	}

	/** Restaura los objetos vacíos al cargar un historial guardado. */
	public static function restore( array $transcript ): array {
		foreach ( $transcript as &$content ) {
			foreach ( $content['parts'] ?? array() as $i => $part ) {
				if ( isset( $part['functionCall'] ) && array() === ( $part['functionCall']['args'] ?? array() ) ) {
					$content['parts'][ $i ]['functionCall']['args'] = new \stdClass();
				}
			}
		}
		return $transcript;
	}
}
