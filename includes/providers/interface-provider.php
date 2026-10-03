<?php
/**
 * Contrato común de los proveedores de IA.
 */

defined( 'ABSPATH' ) || exit;

interface Qhatuq_Provider {

	public function id(): string;

	public function model(): string;

	/**
	 * Ejecuta un turno del visitante: llama al modelo, resuelve las herramientas que pida
	 * y devuelve la respuesta final. El historial se modifica solo anexando; si el turno
	 * falla, el proveedor lo deja como estaba.
	 *
	 * @param string   $system      Instrucciones congeladas de la conversación.
	 * @param array    $transcript  Historial en el formato nativo del proveedor (por referencia).
	 * @param string   $user_text   Mensaje del visitante.
	 * @param callable $run_tool    fn(string $name, array $input): array{0:string,1:bool}
	 * @return array{text:string, usage:array{input:int,output:int,cache_read:int,web_searches?:int}, searches?:string[]}
	 * @throws Qhatuq_Provider_Exception
	 */
	public function run_turn( string $system, array &$transcript, string $user_text, callable $run_tool ): array;
}

class Qhatuq_Provider_Exception extends \RuntimeException {}
