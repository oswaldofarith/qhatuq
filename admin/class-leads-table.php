<?php
/**
 * Listado de leads.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Qhatuq_Leads_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'lead',
				'plural'   => 'leads',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox">',
			'name'        => 'Contacto',
			'company'     => 'Empresa',
			'items'       => 'Necesidad',
			'temperature' => 'Temperatura',
			'status'      => 'Estado',
			'created_at'  => 'Fecha',
		);
	}

	protected function get_sortable_columns() {
		return array(
			'created_at'  => array( 'created_at', true ),
			'temperature' => array( 'temperature', false ),
			'status'      => array( 'status', false ),
		);
	}

	protected function get_views() {
		global $wpdb;
		$t       = Qhatuq_DB::table( 'leads' );
		$counts  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$t} GROUP BY status", OBJECT_K );
		$current = sanitize_key( $_GET['status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$base    = admin_url( 'admin.php?page=qhatuq-leads' );
		$total   = array_sum( array_map( static fn( $r ) => (int) $r->n, $counts ) );
		$views   = array( 'all' => sprintf( '<a href="%s"%s>Todos <span class="count">(%d)</span></a>', esc_url( $base ), '' === $current ? ' class="current"' : '', $total ) );
		foreach ( Qhatuq_Leads::STATUSES as $k => $label ) {
			$n = isset( $counts[ $k ] ) ? (int) $counts[ $k ]->n : 0;
			if ( $n ) {
				$views[ $k ] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( add_query_arg( 'status', $k, $base ) ), $current === $k ? ' class="current"' : '', esc_html( $label ), $n );
			}
		}
		return $views;
	}

	public function prepare_items() {
		global $wpdb;
		$t        = Qhatuq_DB::table( 'leads' );
		$per_page = 20;
		$page     = $this->get_pagenum();
		$where    = array( '1=1' );
		$params   = array();

		$status = sanitize_key( $_GET['status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( array_key_exists( $status, Qhatuq_Leads::STATUSES ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(name LIKE %s OR company LIKE %s OR email LIKE %s OR phone LIKE %s OR need LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like, $like, $like ) );
		}

		$orderby = sanitize_key( $_GET['orderby'] ?? 'created_at' ); // phpcs:ignore WordPress.Security.NonceVerification
		$orderby = in_array( $orderby, array( 'created_at', 'temperature', 'status' ), true ) ? $orderby : 'created_at';
		$order   = 'asc' === strtolower( (string) ( $_GET['order'] ?? '' ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification

		$sql_where = implode( ' AND ', $where );
		$total_sql = "SELECT COUNT(*) FROM {$t} WHERE {$sql_where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : $wpdb->get_var( $total_sql ) );

		$list_sql    = "SELECT * FROM {$t} WHERE {$sql_where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$this->items = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	protected function get_bulk_actions() {
		return array(
			'qhatuq_delete'      => 'Eliminar',
			'qhatuq_delete_all'  => 'Eliminar con su conversación',
		);
	}

	protected function column_cb( $item ) {
		return '<input type="checkbox" name="ids[]" value="' . (int) $item['id'] . '">';
	}

	protected function column_name( $item ) {
		$url  = admin_url( 'admin.php?page=qhatuq-leads&lead=' . (int) $item['id'] );
		$name = '' !== $item['name'] ? $item['name'] : '(sin nombre)';
		$out  = '<strong><a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></strong>';
		if ( '' !== $item['phone'] || '' !== $item['email'] ) {
			$out .= '<br><span class="description">';
			$out .= esc_html( $item['phone'] );
			$out .= Qhatuq_Admin::whatsapp_link( $item );
			$out .= ( '' !== $item['phone'] && '' !== $item['email'] ? ' · ' : '' ) . esc_html( $item['email'] );
			$out .= '</span>';
		}
		$actions = array(
			'view'   => '<a href="' . esc_url( $url ) . '">Ver</a>',
			'delete' => Qhatuq_Admin::delete_link( 'lead', (int) $item['id'], 'Eliminar' ),
		);
		return $out . $this->row_actions( $actions );
	}

	protected function column_items( $item ) {
		$text = Qhatuq_Leads::items_text( $item );
		$text = '' !== $text ? $text : $item['need'];
		return esc_html( wp_trim_words( $text, 18 ) );
	}

	protected function column_temperature( $item ) {
		$colors = array(
			'caliente' => '#d63638',
			'tibio'    => '#dba617',
			'frio'     => '#2271b1',
		);
		return sprintf(
			'<span style="color:%s;font-weight:600">● %s</span>',
			esc_attr( $colors[ $item['temperature'] ] ?? '#787c82' ),
			esc_html( Qhatuq_Leads::TEMPERATURES[ $item['temperature'] ] ?? $item['temperature'] )
		);
	}

	protected function column_status( $item ) {
		return esc_html( Qhatuq_Leads::STATUSES[ $item['status'] ] ?? $item['status'] );
	}

	protected function column_created_at( $item ) {
		return esc_html( get_date_from_gmt( $item['created_at'], 'd/m/Y H:i' ) );
	}

	protected function column_default( $item, $column_name ) {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	public function no_items() {
		echo 'Aún no hay leads.';
	}
}
