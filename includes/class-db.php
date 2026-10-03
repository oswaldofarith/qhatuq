<?php
/**
 * Tablas propias: conversaciones, mensajes (para el panel) y leads.
 */

defined( 'ABSPATH' ) || exit;

class Qhatuq_DB {

	const CLEANUP_HOOK = 'qhatuq_daily_cleanup';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'qhatuq_' . $name;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$conv    = self::table( 'conversations' );
		$msgs    = self::table( 'messages' );
		$leads   = self::table( 'leads' );

		// El "transcript" guarda el historial en el formato nativo del proveedor (bloques de
		// Claude o "contents" de Gemini) y se reenvía tal cual: el historial es solo de anexar.
		// "system_prompt" es una copia congelada al iniciar la conversación.
		dbDelta(
			"CREATE TABLE {$conv} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			public_id char(36) NOT NULL,
			token_hash char(64) NOT NULL,
			provider varchar(20) NOT NULL,
			model varchar(80) NOT NULL,
			system_prompt longtext NOT NULL,
			transcript longtext NOT NULL,
			page_url varchar(500) NOT NULL DEFAULT '',
			ip_hash char(64) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			user_messages int(10) unsigned NOT NULL DEFAULT 0,
			input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			cache_read_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			web_search tinyint(1) NOT NULL DEFAULT 0,
			web_searches int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'open',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$msgs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			role varchar(20) NOT NULL,
			content longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$leads} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(200) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			email varchar(200) NOT NULL DEFAULT '',
			company varchar(200) NOT NULL DEFAULT '',
			need text NOT NULL,
			items longtext NOT NULL,
			temperature varchar(20) NOT NULL DEFAULT 'frio',
			summary text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'nuevo',
			off_catalog text NULL,
			notes text NOT NULL,
			notified_at datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id),
			KEY status (status)
			) {$charset};"
		);

		update_option( 'qhatuq_db_version', QHATUQ_DB_VERSION );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'qhatuq_db_version' ) !== QHATUQ_DB_VERSION ) {
			self::install();
		}
	}

	public static function init_cleanup(): void {
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup' ) );
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	public static function unschedule_cleanup(): void {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	/** Borra conversaciones más antiguas que el plazo de retención. Los leads se conservan. */
	public static function cleanup(): void {
		global $wpdb;
		$days = (int) Qhatuq_Settings::get( 'retention_days' );
		if ( $days <= 0 ) {
			return;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$conv   = self::table( 'conversations' );
		$msgs   = self::table( 'messages' );
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$conv} WHERE updated_at < %s LIMIT 500", $cutoff ) );
		if ( ! $ids ) {
			return;
		}
		$in = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( "DELETE FROM {$msgs} WHERE conversation_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$conv} WHERE id IN ({$in})" );
	}

	/* ---------------------------------------------------------------- Conversaciones */

	public static function now(): string {
		return current_time( 'mysql', true );
	}

	public static function create_conversation( array $data ): array {
		global $wpdb;
		$row = array(
			'public_id'     => wp_generate_uuid4(),
			'token_hash'    => $data['token_hash'],
			'provider'      => $data['provider'],
			'model'         => $data['model'],
			'system_prompt' => $data['system_prompt'],
			'transcript'    => '[]',
			'page_url'      => mb_substr( (string) $data['page_url'], 0, 500 ),
			'ip_hash'       => $data['ip_hash'],
			'user_agent'    => mb_substr( (string) $data['user_agent'], 0, 255 ),
			'web_search'    => empty( $data['web_search'] ) ? 0 : 1,
			'created_at'    => self::now(),
			'updated_at'    => self::now(),
		);
		$wpdb->insert( self::table( 'conversations' ), $row );
		$row['id'] = (int) $wpdb->insert_id;
		return self::get_conversation( $row['id'] );
	}

	public static function get_conversation( int $id ): ?array {
		global $wpdb;
		$t   = self::table( 'conversations' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $row : null;
	}

	public static function get_conversation_by_public_id( string $public_id ): ?array {
		global $wpdb;
		$t   = self::table( 'conversations' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE public_id = %s", $public_id ), ARRAY_A );
		return $row ? $row : null;
	}

	public static function update_conversation( int $id, array $fields ): void {
		global $wpdb;
		$fields['updated_at'] = self::now();
		$wpdb->update( self::table( 'conversations' ), $fields, array( 'id' => $id ) );
	}

	public static function add_usage( int $id, array $usage ): void {
		global $wpdb;
		$t = self::table( 'conversations' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET input_tokens = input_tokens + %d, output_tokens = output_tokens + %d, cache_read_tokens = cache_read_tokens + %d, web_searches = web_searches + %d WHERE id = %d",
				(int) ( $usage['input'] ?? 0 ),
				(int) ( $usage['output'] ?? 0 ),
				(int) ( $usage['cache_read'] ?? 0 ),
				(int) ( $usage['web_searches'] ?? 0 ),
				$id
			)
		);
	}

	public static function add_message( int $conversation_id, string $role, string $content ): void {
		global $wpdb;
		$wpdb->insert(
			self::table( 'messages' ),
			array(
				'conversation_id' => $conversation_id,
				'role'            => $role,
				'content'         => $content,
				'created_at'      => self::now(),
			)
		);
	}

	public static function get_messages( int $conversation_id ): array {
		global $wpdb;
		$t = self::table( 'messages' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT role, content, created_at FROM {$t} WHERE conversation_id = %d ORDER BY id ASC", $conversation_id ), ARRAY_A );
	}

	/* ---------------------------------------------------------------- Leads */

	public static function get_lead_by_conversation( int $conversation_id ): ?array {
		global $wpdb;
		$t   = self::table( 'leads' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE conversation_id = %d ORDER BY id DESC LIMIT 1", $conversation_id ), ARRAY_A );
		return $row ? $row : null;
	}

	public static function get_lead( int $id ): ?array {
		global $wpdb;
		$t   = self::table( 'leads' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $row : null;
	}

	public static function insert_lead( array $fields ): int {
		global $wpdb;
		$fields = array_merge(
			array(
				'need'    => '',
				'items'   => '[]',
				'summary' => '',
				'notes'   => '',
			),
			$fields
		);
		$fields['created_at'] = self::now();
		$fields['updated_at'] = self::now();
		$wpdb->insert( self::table( 'leads' ), $fields );
		return (int) $wpdb->insert_id;
	}

	public static function update_lead( int $id, array $fields ): void {
		global $wpdb;
		$fields['updated_at'] = self::now();
		$wpdb->update( self::table( 'leads' ), $fields, array( 'id' => $id ) );
	}
}
