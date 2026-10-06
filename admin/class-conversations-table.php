<?php
/**
 * Listado de conversaciones.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Qhatuq_Conversations_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'conversación',
				'plural'   => 'conversaciones',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'            => '<input type="checkbox">',
			'created_at'    => 'Inicio',
			'first_message' => 'Primer mensaje',
			'user_messages' => 'Mensajes',
			'interest'      => 'Interés',
			'lead'          => 'Lead',
			'model'         => 'Modelo',
			'tokens'        => 'Tokens (entrada / caché / salida)',
		);
	}

	public function prepare_items() {
		global $wpdb;
		$c        = Qhatuq_DB::table( 'conversations' );
		$m        = Qhatuq_DB::table( 'messages' );
		$l        = Qhatuq_DB::table( 'leads' );
		$per_page = 25;
		$page     = $this->get_pagenum();
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c} WHERE user_messages > 0" );

		$this->items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.created_at, c.user_messages, c.provider, c.model, c.input_tokens, c.output_tokens, c.cache_read_tokens, c.summary, c.summary_status,
					(SELECT content FROM {$m} WHERE conversation_id = c.id AND role = 'user' ORDER BY id ASC LIMIT 1) AS first_message,
					(SELECT id FROM {$l} WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS lead_id
				FROM {$c} c WHERE c.user_messages > 0 ORDER BY c.id DESC LIMIT %d OFFSET %d",
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	protected function get_bulk_actions() {
		return array(
			'qhatuq_delete'     => 'Eliminar',
			'qhatuq_delete_all' => 'Eliminar con su lead',
		);
	}

	protected function column_cb( $item ) {
		return '<input type="checkbox" name="ids[]" value="' . (int) $item['id'] . '">';
	}

	protected function column_created_at( $item ) {
		$url     = admin_url( 'admin.php?page=qhatuq-conversations&conversation=' . (int) $item['id'] );
		$actions = array(
			'view'   => '<a href="' . esc_url( $url ) . '">Ver</a>',
			'delete' => Qhatuq_Admin::delete_link( 'conversation', (int) $item['id'], 'Eliminar' ),
		);
		return '<a href="' . esc_url( $url ) . '"><strong>' . esc_html( get_date_from_gmt( $item['created_at'], 'd/m/Y H:i' ) ) . '</strong></a>' . $this->row_actions( $actions );
	}

	protected function column_first_message( $item ) {
		return esc_html( wp_trim_words( (string) $item['first_message'], 14 ) );
	}

	protected function column_interest( $item ) {
		$s = json_decode( (string) $item['summary'], true );
		if ( ! is_array( $s ) ) {
			return '' === $item['summary_status'] ? '<span class="description">En curso</span>' : '—';
		}
		$out = esc_html( Qhatuq_Summaries::INTEREST[ $s['interes'] ?? '' ] ?? '—' );
		if ( ! empty( $s['lead_sin_contacto'] ) ) {
			$out .= ' <span style="color:#d63638" title="Interesado sin datos de contacto">⚠</span>';
		}
		if ( '' === $item['summary_status'] ) {
			$out .= ' <span class="description">(retomada, en curso)</span>';
		}
		return $out;
	}

	protected function column_lead( $item ) {
		if ( ! $item['lead_id'] ) {
			return '—';
		}
		return '<a href="' . esc_url( admin_url( 'admin.php?page=qhatuq-leads&lead=' . (int) $item['lead_id'] ) ) . '">Ver lead</a>';
	}

	protected function column_model( $item ) {
		return esc_html( $item['model'] );
	}

	protected function column_tokens( $item ) {
		return esc_html( number_format_i18n( (int) $item['input_tokens'] ) . ' / ' . number_format_i18n( (int) $item['cache_read_tokens'] ) . ' / ' . number_format_i18n( (int) $item['output_tokens'] ) );
	}

	protected function column_default( $item, $column_name ) {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	public function no_items() {
		echo 'Aún no hay conversaciones.';
	}
}
