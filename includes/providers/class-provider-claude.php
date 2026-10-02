<?php
/**
 * Proveedor Claude (Anthropic) con el SDK oficial de PHP.
 *
 * El historial guarda los bloques tal como los devuelve la API (incluidos los de
 * "thinking" con su firma) y se reenvía sin modificar; las instrucciones y las
 * herramientas son idénticas en todos los turnos. Eso mantiene válido el razonamiento
 * previo del modelo y aprovecha el caché de prompts.
 */

defined( 'ABSPATH' ) || exit;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\RateLimitException;

class Qhatuq_Provider_Claude implements Qhatuq_Provider {

	const MAX_TOOL_ROUNDS = 6;

	/** Modelos con pensamiento adaptativo, esfuerzo configurable y respaldo automático del servidor. */
	const CURRENT_MODELS = array( 'claude-opus-5-5', 'claude-sonnet-5-5' );

	private string $api_key;
	private string $model;
	private string $effort;

	public function __construct( string $api_key, string $model, string $effort = 'low' ) {
		$this->api_key = $api_key;
		$this->model   = $model;
		$this->effort  = $effort;
	}

	public static function available(): bool {
		return class_exists( Client::class );
	}

	public function id(): string {
		return 'claude';
	}

	public function model(): string {
		return $this->model;
	}

	public function run_turn( string $system, array &$transcript, string $user_text, callable $run_tool ): array {
		if ( ! self::available() ) {
			throw new Qhatuq_Provider_Exception( 'Falta la carpeta vendor/ del plugin (SDK de Anthropic). Instale el ZIP compilado o ejecute "composer install".' );
		}

		$client   = new Client( apiKey: $this->api_key, requestOptions: array( 'maxRetries' => 2, 'timeout' => 90 ) );
		$original = $transcript;
		$usage    = array( 'input' => 0, 'output' => 0, 'cache_read' => 0 );

		$transcript[] = array( 'role' => 'user', 'content' => $user_text );

		try {
			for ( $round = 0; $round < self::MAX_TOOL_ROUNDS; $round++ ) {
				$response = $client->beta->messages->create( ...$this->request_args( $system, $transcript ) );

				$usage['input']      += (int) $response->usage->inputTokens + (int) $response->usage->cacheCreationInputTokens;
				$usage['output']     += (int) $response->usage->outputTokens;
				$usage['cache_read'] += (int) $response->usage->cacheReadInputTokens;

				if ( 'refusal' === $response->stopReason ) {
					// La respuesta rechazada no entra al historial: se deshace todo el turno.
					$transcript = $original;
					return array(
						'text'  => 'Disculpe, no puedo ayudarle con eso. ¿Hay algo sobre nuestros productos o servicios en lo que le pueda orientar?',
						'usage' => $usage,
					);
				}

				$blocks       = self::blocks_to_array( $response->content );
				$transcript[] = array( 'role' => 'assistant', 'content' => $blocks );

				if ( 'tool_use' !== $response->stopReason ) {
					$text = self::text_of( $blocks );
					if ( '' === $text ) {
						$text = 'Disculpe, ¿podría repetirme su consulta?';
					}
					return array( 'text' => $text, 'usage' => $usage );
				}

				// Todas las respuestas de herramientas van en un único mensaje de usuario.
				$results = array();
				foreach ( $blocks as $block ) {
					if ( 'tool_use' !== ( $block['type'] ?? '' ) ) {
						continue;
					}
					list( $result, $is_error ) = $run_tool( (string) $block['name'], (array) $block['input'] );
					$results[]                 = array(
						'type'        => 'tool_result',
						'tool_use_id' => $block['id'],
						'content'     => $result,
						'is_error'    => $is_error,
					);
				}
				$transcript[] = array( 'role' => 'user', 'content' => $results );
			}
			throw new Qhatuq_Provider_Exception( 'Se superó el número máximo de llamadas a herramientas en un turno.' );
		} catch ( AuthenticationException $e ) {
			$transcript = $original;
			throw new Qhatuq_Provider_Exception( 'La API key de Claude no es válida.', 0, $e );
		} catch ( RateLimitException $e ) {
			$transcript = $original;
			throw new Qhatuq_Provider_Exception( 'Claude: límite de uso alcanzado (429).', 0, $e );
		} catch ( BadRequestException $e ) {
			$transcript = $original;
			throw new Qhatuq_Provider_Exception( 'Claude rechazó la solicitud (400): ' . $e->getMessage(), 0, $e );
		} catch ( APIStatusException $e ) {
			$transcript = $original;
			throw new Qhatuq_Provider_Exception( 'Error de la API de Claude: ' . $e->getMessage(), 0, $e );
		} catch ( APIConnectionException $e ) {
			$transcript = $original;
			throw new Qhatuq_Provider_Exception( 'No se pudo conectar con la API de Claude: ' . $e->getMessage(), 0, $e );
		} catch ( Qhatuq_Provider_Exception $e ) {
			$transcript = $original;
			throw $e;
		}
	}

	private function request_args( string $system, array $transcript ): array {
		$args = array(
			'model'        => $this->model,
			'maxTokens'    => 16000,
			// Punto de caché al final de las instrucciones (la parte grande y estable)
			// y caché automático para el resto de la conversación.
			'system'       => array(
				array(
					'type'          => 'text',
					'text'          => $system,
					'cache_control' => array( 'type' => 'ephemeral' ),
				),
			),
			'cacheControl' => array( 'type' => 'ephemeral' ),
			'tools'        => self::tools(),
			'messages'     => $transcript,
		);

		if ( in_array( $this->model, self::CURRENT_MODELS, true ) ) {
			$args['outputConfig'] = array( 'effort' => $this->effort );
			// Si una edición accidental del historial invalidara el razonamiento anterior,
			// la API lo descarta en lugar de fallar.
			$args['thinking'] = array(
				'type'          => 'adaptive',
				'block_binding' => array( 'prefix_mismatch_behavior' => 'drop_block' ),
			);
			// Si el modelo declina por política, la API reintenta con su modelo de respaldo.
			$args['fallbacks'] = 'default';
			$args['betas']     = array( 'server-side-fallback-2026-07-01', 'thinking-binding-controls-2026-08-01' );
		}

		return $args;
	}

	private static function tools(): array {
		return array_map(
			static fn( $t ) => array(
				'name'         => $t['name'],
				'description'  => $t['description'],
				'input_schema' => $t['schema'],
			),
			Qhatuq_Tools::definitions()
		);
	}

	/** Convierte los bloques del SDK al formato de la API para guardarlos y reenviarlos tal cual. */
	private static function blocks_to_array( array $content ): array {
		$blocks = json_decode( wp_json_encode( $content ), true );
		$blocks = is_array( $blocks ) ? $blocks : array();
		foreach ( $blocks as &$block ) {
			// json_decode convierte {} en [], y la API exige un objeto.
			if ( 'tool_use' === ( $block['type'] ?? '' ) && array() === ( $block['input'] ?? array() ) ) {
				$block['input'] = new \stdClass();
			}
		}
		return $blocks;
	}

	private static function text_of( array $blocks ): string {
		$text = '';
		foreach ( $blocks as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= $block['text'];
			}
		}
		return trim( $text );
	}

	/** Restaura los objetos vacíos al cargar un historial guardado. */
	public static function restore( array $transcript ): array {
		foreach ( $transcript as &$message ) {
			if ( ! is_array( $message['content'] ) ) {
				continue;
			}
			foreach ( $message['content'] as &$block ) {
				if ( is_array( $block ) && 'tool_use' === ( $block['type'] ?? '' ) && array() === ( $block['input'] ?? array() ) ) {
					$block['input'] = new \stdClass();
				}
			}
		}
		return $transcript;
	}
}
