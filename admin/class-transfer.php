<?php
/**
 * Exportar e importar la configuración del agente entre sitios.
 *
 * El archivo (.json) contiene los ajustes (nunca las API keys), el catálogo, lo que no se
 * ofrece y, opcionalmente, la foto del agente. La importación se hace en dos pasos: se
 * sube el archivo y se muestra una vista previa; solo al confirmar se aplican los cambios.
 */

defined( 'ABSPATH' ) || exit;

class Qhatuq_Transfer {

	const FORMAT     = 'qhatuq-export';
	const VERSION    = 1;
	const PAGE       = 'qhatuq-transfer';
	const MAX_FILE   = 6 * MB_IN_BYTES;
	const MAX_AVATAR = 2 * MB_IN_BYTES;

	/** Ajustes que nunca salen del sitio. */
	const PRIVATE_KEYS = array( 'claude_api_key', 'gemini_api_key', 'widget_avatar', 'widget_avatar_id', 'enabled' );

	/** Grupos de ajustes que se pueden importar por separado. [etiqueta, claves, marcado por defecto] */
	public static function groups(): array {
		return array(
			'ia'           => array( 'Inteligencia artificial (proveedor, modelos, verificación web)', array( 'provider', 'claude_model', 'claude_effort', 'gemini_model', 'web_search' ), true ),
			'personalidad' => array( 'Personalidad y reglas (nombre del agente, trato, saludo, prohibiciones, criterios de leads, indicaciones adicionales)', array( 'agent_name', 'treatment', 'greeting', 'prohibitions', 'lead_criteria', 'extra_instructions' ), true ),
			'empresa'      => array( 'Datos de la empresa (nombre y descripción)', array( 'company_name', 'company_description' ), false ),
			'avisos'       => array( 'Avisos, WhatsApp y resúmenes por correo', array( 'notify_emails', 'whatsapp_country', 'whatsapp_message', 'summary_enabled', 'summary_idle_minutes', 'summary_all', 'weekly_digest' ), true ),
			'privacidad'   => array( 'Privacidad (aviso del chat, URL de la política, retención)', array( 'privacy_notice', 'privacy_url', 'retention_days' ), false ),
			'apariencia'   => array( 'Apariencia (título, color, posición, sugerencias, invitación)', array( 'widget_title', 'widget_color', 'widget_position', 'widget_suggestions', 'teaser_delay' ), false ),
			'limites'      => array( 'Límites contra el abuso', array( 'max_messages', 'max_per_ip_hour', 'max_message_chars' ), true ),
		);
	}

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_qhatuq_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_qhatuq_import_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_qhatuq_import_apply', array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_qhatuq_import_cancel', array( __CLASS__, 'handle_cancel' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'qhatuq', 'Exportar / Importar', 'Exportar / Importar', Qhatuq_Admin::CAP, self::PAGE, array( __CLASS__, 'page' ) );
	}

	private static function transient_key(): string {
		return 'qhatuq_import_' . get_current_user_id();
	}

	private static function redirect( string $msg = '', bool $ok = true ): void {
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		if ( $msg ) {
			$url = add_query_arg( array( 'qhatuq_msg' => rawurlencode( $msg ), 'qhatuq_ok' => $ok ? 1 : 0 ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	private static function authorize( string $nonce ): void {
		if ( ! current_user_can( Qhatuq_Admin::CAP ) || ! check_admin_referer( $nonce ) ) {
			wp_die( 'No autorizado.' );
		}
	}

	/* ---------------------------------------------------------------- Exportar */

	public static function build_export( bool $with_avatar = true ): array {
		$settings = Qhatuq_Settings::all();
		foreach ( self::PRIVATE_KEYS as $k ) {
			unset( $settings[ $k ] );
		}

		$offers = array();
		foreach ( Qhatuq_Catalog::offers() as $post ) {
			// Las páginas elegidas se exportan como direcciones: los ID cambian entre sitios.
			$ctx      = Qhatuq_Catalog::offer_context( $post->ID );
			$patterns = array_filter( array_map( 'trim', explode( "\n", $ctx['url_match'] ) ) );
			foreach ( $ctx['page_ids'] as $page_id ) {
				// Ruta según el nombre de la página (y sus páginas madre), p. ej. /servicios/telefonia-ip.
				$uri  = get_post( $page_id ) ? get_page_uri( $page_id ) : '';
				$path = $uri ? '/' . trim( $uri, '/' ) : '';
				if ( $path && '/' !== $path && ! in_array( $path, $patterns, true ) ) {
					$patterns[] = $path;
				}
			}
			$offers[] = array(
				'title'      => $post->post_title,
				'content'    => $post->post_content,
				'menu_order' => (int) $post->menu_order,
				'meta'       => Qhatuq_Catalog::offer_meta( $post->ID ),
				'context'    => array(
					'url_match'   => implode( "\n", $patterns ),
					'greeting'    => $ctx['greeting'],
					'suggestions' => $ctx['suggestions'],
				),
			);
		}
		$exclusions = array();
		foreach ( Qhatuq_Catalog::exclusions() as $post ) {
			$exclusions[] = array(
				'title' => $post->post_title,
				'meta'  => Qhatuq_Catalog::exclusion_meta( $post->ID ),
			);
		}

		$data = array(
			'format'         => self::FORMAT,
			'version'        => self::VERSION,
			'plugin_version' => QHATUQ_VERSION,
			'site'           => home_url(),
			'exported_at'    => gmdate( 'c' ),
			'settings'       => $settings,
			'offers'         => $offers,
			'exclusions'     => $exclusions,
		);

		$avatar_id = (int) Qhatuq_Settings::get( 'widget_avatar_id' );
		$file      = $avatar_id ? get_attached_file( $avatar_id ) : '';
		if ( $with_avatar && $file && is_readable( $file ) && filesize( $file ) <= self::MAX_AVATAR ) {
			$data['avatar'] = array(
				'filename' => basename( $file ),
				'mime'     => (string) get_post_mime_type( $avatar_id ),
				'data'     => base64_encode( (string) file_get_contents( $file ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions
			);
		}
		return $data;
	}

	public static function handle_export(): void {
		self::authorize( 'qhatuq_export' );
		$data = self::build_export( ! empty( $_POST['with_avatar'] ) );
		$host = preg_replace( '/[^a-z0-9.-]/', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=qhatuq-' . $host . '-' . gmdate( 'Y-m-d' ) . '.json' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/* ---------------------------------------------------------------- Importar: paso 1 */

	public static function handle_upload(): void {
		self::authorize( 'qhatuq_import_upload' );
		$file = $_FILES['qhatuq_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::redirect( 'No se recibió el archivo. Inténtelo de nuevo.', false );
		}
		if ( (int) $file['size'] > self::MAX_FILE ) {
			self::redirect( 'El archivo es demasiado grande.', false );
		}
		$raw  = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$data = json_decode( (string) $raw, true );

		try {
			$clean = self::validate( $data );
		} catch ( \InvalidArgumentException $e ) {
			self::redirect( $e->getMessage(), false );
		}
		set_transient( self::transient_key(), $clean, HOUR_IN_SECONDS );
		self::redirect();
	}

	/**
	 * Valida y sanea el archivo completo. Lo que no se reconoce se descarta.
	 *
	 * @throws InvalidArgumentException Si el archivo no es una exportación válida.
	 */
	public static function validate( $data ): array {
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? '' ) ) {
			throw new \InvalidArgumentException( 'El archivo no es una exportación del agente de ventas.' );
		}
		if ( (int) ( $data['version'] ?? 0 ) > self::VERSION ) {
			throw new \InvalidArgumentException( 'El archivo viene de una versión más nueva del plugin. Actualice el plugin en este sitio e inténtelo otra vez.' );
		}

		$known    = array_diff( array_keys( Qhatuq_Settings::defaults() ), self::PRIVATE_KEYS );
		$settings = array_intersect_key( is_array( $data['settings'] ?? null ) ? $data['settings'] : array(), array_flip( $known ) );
		$settings = array_filter( $settings, 'is_scalar' );

		$offers = array();
		foreach ( array_slice( (array) ( $data['offers'] ?? array() ), 0, 500 ) as $o ) {
			$title = is_array( $o ) ? sanitize_text_field( (string) ( $o['title'] ?? '' ) ) : '';
			if ( '' === $title ) {
				continue;
			}
			$meta     = is_array( $o['meta'] ?? null ) ? $o['meta'] : array();
			$offers[] = array(
				'title'      => $title,
				'content'    => wp_kses_post( (string) ( $o['content'] ?? '' ) ),
				'menu_order' => (int) ( $o['menu_order'] ?? 0 ),
				'meta'       => array(
					'kind'          => in_array( $meta['kind'] ?? '', array( 'servicio', 'producto', 'licencia' ), true ) ? $meta['kind'] : 'servicio',
					'category'      => sanitize_text_field( (string) ( $meta['category'] ?? '' ) ),
					'audience'      => sanitize_textarea_field( (string) ( $meta['audience'] ?? '' ) ),
					'benefits'      => sanitize_textarea_field( (string) ( $meta['benefits'] ?? '' ) ),
					'requirements'  => sanitize_textarea_field( (string) ( $meta['requirements'] ?? '' ) ),
					'price_from'    => sanitize_text_field( (string) ( $meta['price_from'] ?? '' ) ),
					'price_unit'    => sanitize_text_field( (string) ( $meta['price_unit'] ?? '' ) ),
					'price_allowed' => empty( $meta['price_allowed'] ) ? '0' : '1',
				),
				'context'    => array(
					'url_match'   => sanitize_textarea_field( (string) ( $o['context']['url_match'] ?? '' ) ),
					'greeting'    => sanitize_text_field( (string) ( $o['context']['greeting'] ?? '' ) ),
					'suggestions' => sanitize_textarea_field( (string) ( $o['context']['suggestions'] ?? '' ) ),
				),
			);
		}

		$exclusions = array();
		foreach ( array_slice( (array) ( $data['exclusions'] ?? array() ), 0, 500 ) as $x ) {
			$title = is_array( $x ) ? sanitize_text_field( (string) ( $x['title'] ?? '' ) ) : '';
			if ( '' === $title ) {
				continue;
			}
			$meta         = is_array( $x['meta'] ?? null ) ? $x['meta'] : array();
			$exclusions[] = array(
				'title' => $title,
				'meta'  => array(
					'action' => array_key_exists( $meta['action'] ?? '', Qhatuq_Catalog::ACTIONS ) ? $meta['action'] : 'decline',
					'detail' => sanitize_textarea_field( (string) ( $meta['detail'] ?? '' ) ),
				),
			);
		}

		$avatar = null;
		if ( is_array( $data['avatar'] ?? null ) && ! empty( $data['avatar']['data'] ) ) {
			$bin  = base64_decode( (string) $data['avatar']['data'], true );
			$info = $bin && strlen( $bin ) <= self::MAX_AVATAR ? @getimagesizefromstring( $bin ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$ext  = array(
				'image/png'  => 'png',
				'image/jpeg' => 'jpg',
				'image/webp' => 'webp',
				'image/gif'  => 'gif',
			);
			if ( $info && isset( $ext[ $info['mime'] ] ) ) {
				$name   = sanitize_file_name( pathinfo( (string) ( $data['avatar']['filename'] ?? 'avatar' ), PATHINFO_FILENAME ) );
				$avatar = array(
					'filename' => ( $name ? $name : 'avatar' ) . '.' . $ext[ $info['mime'] ],
					'data'     => base64_encode( $bin ),
				);
			}
		}

		return array(
			'site'        => esc_url_raw( (string) ( $data['site'] ?? '' ) ),
			'exported_at' => sanitize_text_field( (string) ( $data['exported_at'] ?? '' ) ),
			'settings'    => $settings,
			'offers'      => $offers,
			'exclusions'  => $exclusions,
			'avatar'      => $avatar,
		);
	}

	/* ---------------------------------------------------------------- Importar: paso 2 */

	public static function handle_cancel(): void {
		self::authorize( 'qhatuq_import_cancel' );
		delete_transient( self::transient_key() );
		self::redirect( 'Importación cancelada. No se hizo ningún cambio.' );
	}

	public static function handle_apply(): void {
		self::authorize( 'qhatuq_import_apply' );
		$data = get_transient( self::transient_key() );
		if ( ! is_array( $data ) ) {
			self::redirect( 'La vista previa expiró. Suba el archivo de nuevo.', false );
		}
		delete_transient( self::transient_key() );

		$groups  = array_map( 'sanitize_key', (array) ( $_POST['groups'] ?? array() ) );
		$catalog = sanitize_key( wp_unslash( $_POST['catalog_mode'] ?? 'skip' ) );
		$excl    = sanitize_key( wp_unslash( $_POST['exclusions_mode'] ?? 'skip' ) );
		$report  = self::apply( $data, $groups, $catalog, $excl, ! empty( $_POST['avatar'] ) );

		self::redirect( 'Importación completada. ' . implode( ' ', $report ) );
	}

	/**
	 * Aplica la importación.
	 *
	 * @param string $catalog_mode    replace | merge | skip
	 * @param string $exclusions_mode replace | merge | skip
	 * @return string[] Resumen de lo hecho.
	 */
	public static function apply( array $data, array $groups, string $catalog_mode, string $exclusions_mode, bool $import_avatar ): array {
		$report = array();

		// Ajustes por grupos.
		$picked = array();
		foreach ( self::groups() as $id => list( , $keys ) ) {
			if ( in_array( $id, $groups, true ) ) {
				$picked += array_intersect_key( $data['settings'], array_flip( $keys ) );
			}
		}
		if ( $import_avatar && ! empty( $data['avatar'] ) ) {
			$avatar_id = self::import_avatar( $data['avatar'] );
			if ( $avatar_id ) {
				$picked['widget_avatar_id'] = $avatar_id;
				$picked['widget_avatar']    = '';
				$report[]                   = 'Foto del agente importada.';
			}
		}
		if ( $picked ) {
			$current = Qhatuq_Settings::all();
			update_option( Qhatuq_Settings::OPTION, Qhatuq_Settings::sanitize( array_merge( $current, $picked ) ) );
			$report[] = sprintf( 'Ajustes importados: %d.', count( $picked ) );
		}

		if ( in_array( $catalog_mode, array( 'replace', 'merge' ), true ) ) {
			list( $added, $updated, $removed ) = self::sync_posts( Qhatuq_Catalog::OFFER, $data['offers'], 'replace' === $catalog_mode );
			$report[]                          = sprintf( 'Productos y servicios: %d nuevos, %d actualizados%s.', $added, $updated, $removed ? ", {$removed} enviados a la papelera" : '' );
		}
		if ( in_array( $exclusions_mode, array( 'replace', 'merge' ), true ) ) {
			list( $added, $updated, $removed ) = self::sync_posts( Qhatuq_Catalog::EXCLUSION, $data['exclusions'], 'replace' === $exclusions_mode );
			$report[]                          = sprintf( 'Lo que no ofrecemos: %d nuevos, %d actualizados%s.', $added, $updated, $removed ? ", {$removed} enviados a la papelera" : '' );
		}

		return $report ? $report : array( 'No se seleccionó nada para importar.' );
	}

	/**
	 * Crea o actualiza entradas por título. En modo reemplazo, las que no vienen en el
	 * archivo se envían a la papelera (se pueden recuperar).
	 *
	 * @return int[] [nuevos, actualizados, a la papelera]
	 */
	private static function sync_posts( string $type, array $items, bool $replace ): array {
		$existing = array();
		foreach ( get_posts( array( 'post_type' => $type, 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'posts_per_page' => -1 ) ) as $p ) {
			$existing[ mb_strtolower( trim( $p->post_title ) ) ] = $p->ID;
		}

		$added   = 0;
		$updated = 0;
		$seen    = array();
		foreach ( $items as $item ) {
			$key  = mb_strtolower( trim( $item['title'] ) );
			$post = array(
				'post_type'   => $type,
				'post_status' => 'publish',
				'post_title'  => $item['title'],
			);
			if ( isset( $item['content'] ) ) {
				$post['post_content'] = $item['content'];
				$post['menu_order']   = $item['menu_order'];
			}
			if ( isset( $existing[ $key ] ) ) {
				$post['ID'] = $existing[ $key ];
				$id         = wp_update_post( wp_slash( $post ), true );
				++$updated;
			} else {
				$id = wp_insert_post( wp_slash( $post ), true );
				++$added;
			}
			if ( is_wp_error( $id ) || ! $id ) {
				continue;
			}
			foreach ( $item['meta'] as $k => $v ) {
				update_post_meta( $id, '_qhatuq_' . $k, $v );
			}
			if ( isset( $item['context'] ) ) {
				// Se conservan las páginas elegidas en este sitio; las del archivo llegan como direcciones.
				Qhatuq_Catalog::save_context( $id, array( 'page_ids' => Qhatuq_Catalog::offer_context( $id )['page_ids'] ) + $item['context'] );
			}
			$seen[] = (int) $id;
		}

		$removed = 0;
		if ( $replace ) {
			foreach ( $existing as $id ) {
				if ( ! in_array( (int) $id, $seen, true ) && wp_trash_post( $id ) ) {
					++$removed;
				}
			}
		}
		return array( $added, $updated, $removed );
	}

	private static function import_avatar( array $avatar ): int {
		$bin = base64_decode( (string) $avatar['data'], true );
		if ( ! $bin ) {
			return 0;
		}
		$upload = wp_upload_bits( $avatar['filename'], null, $bin );
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}
		$check = wp_check_filetype_and_ext( $upload['file'], $avatar['filename'] );
		if ( empty( $check['type'] ) || 0 !== strpos( (string) $check['type'], 'image/' ) ) {
			wp_delete_file( $upload['file'] );
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $check['type'],
				'post_title'     => 'Foto del agente',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		return (int) $id;
	}

	/* ---------------------------------------------------------------- Pantalla */

	public static function page(): void {
		$preview = get_transient( self::transient_key() );
		?>
		<div class="wrap">
			<h1>Exportar / Importar configuración</h1>
			<p>Use esto para copiar la configuración del agente entre sus sitios. Las API keys nunca se incluyen en el archivo: cada sitio conserva las suyas.</p>

			<?php if ( is_array( $preview ) ) : ?>
				<?php self::render_preview( $preview ); ?>
			<?php else : ?>
				<div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">
					<div class="card" style="max-width:520px;margin-top:0">
						<h2>Exportar</h2>
						<p>Descarga un archivo con los ajustes, <?php echo (int) count( Qhatuq_Catalog::offers() ); ?> productos y servicios y <?php echo (int) count( Qhatuq_Catalog::exclusions() ); ?> elementos de "Lo que no ofrecemos".</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="qhatuq_export">
							<?php wp_nonce_field( 'qhatuq_export' ); ?>
							<?php if ( Qhatuq_Settings::get( 'widget_avatar_id' ) ) : ?>
								<p><label><input type="checkbox" name="with_avatar" value="1" checked> Incluir la foto del agente</label></p>
							<?php endif; ?>
							<?php submit_button( 'Descargar archivo de configuración', 'primary', 'submit', false ); ?>
						</form>
					</div>
					<div class="card" style="max-width:520px;margin-top:0">
						<h2>Importar</h2>
						<p>Suba un archivo exportado desde otro sitio. Antes de aplicar nada verá una vista previa y podrá elegir qué importar.</p>
						<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="qhatuq_import_upload">
							<?php wp_nonce_field( 'qhatuq_import_upload' ); ?>
							<p><input type="file" name="qhatuq_file" accept=".json,application/json" required></p>
							<?php submit_button( 'Ver vista previa', 'secondary', 'submit', false ); ?>
						</form>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_preview( array $p ): void {
		$titles = static function ( string $type ): array {
			$out = array();
			foreach ( get_posts( array( 'post_type' => $type, 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'posts_per_page' => -1 ) ) as $post ) {
				$out[ mb_strtolower( trim( $post->post_title ) ) ] = true;
			}
			return $out;
		};
		$diff = static function ( array $items, array $existing ): array {
			$new = 0;
			$upd = 0;
			$in  = array();
			foreach ( $items as $i ) {
				$k        = mb_strtolower( trim( $i['title'] ) );
				$in[ $k ] = true;
				isset( $existing[ $k ] ) ? ++$upd : ++$new;
			}
			return array( $new, $upd, count( array_diff_key( $existing, $in ) ) );
		};
		list( $o_new, $o_upd, $o_gone ) = $diff( $p['offers'], $titles( Qhatuq_Catalog::OFFER ) );
		list( $x_new, $x_upd, $x_gone ) = $diff( $p['exclusions'], $titles( Qhatuq_Catalog::EXCLUSION ) );
		$current                        = Qhatuq_Settings::all();
		?>
		<div class="notice notice-info inline"><p>
			Archivo de <strong><?php echo esc_html( $p['site'] ? $p['site'] : 'origen desconocido' ); ?></strong>
			<?php echo $p['exported_at'] ? esc_html( '· exportado el ' . wp_date( 'd/m/Y H:i', strtotime( $p['exported_at'] ) ) ) : ''; ?>.
			Revise y confirme; todavía no se ha cambiado nada.
		</p></div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="qhatuq_import_apply">
			<?php wp_nonce_field( 'qhatuq_import_apply' ); ?>

			<h2>Ajustes</h2>
			<p class="description">Los grupos desmarcados por defecto suelen ser distintos en cada sitio.</p>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
				<?php foreach ( self::groups() as $id => list( $label, $keys, $default ) ) : ?>
					<?php
					$in      = array_intersect_key( $p['settings'], array_flip( $keys ) );
					$changes = 0;
					foreach ( $in as $k => $v ) {
						$changes += ( (string) $v !== (string) ( $current[ $k ] ?? '' ) ) ? 1 : 0;
					}
					?>
					<tr>
						<td style="width:30px"><input type="checkbox" id="g-<?php echo esc_attr( $id ); ?>" name="groups[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $default && $in ); ?> <?php disabled( ! $in ); ?>></td>
						<td><label for="g-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></td>
						<td style="width:200px"><?php echo $in ? esc_html( $changes ? sprintf( '%d cambio(s)', $changes ) : 'Sin cambios' ) : '<span class="description">No viene en el archivo</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! empty( $p['avatar'] ) ) : ?>
					<tr>
						<td><input type="checkbox" id="g-avatar" name="avatar" value="1"></td>
						<td><label for="g-avatar">Foto del agente</label> <span class="description">(se agrega a la Biblioteca de medios)</span></td>
						<td><img src="data:image/<?php echo esc_attr( pathinfo( $p['avatar']['filename'], PATHINFO_EXTENSION ) === 'jpg' ? 'jpeg' : pathinfo( $p['avatar']['filename'], PATHINFO_EXTENSION ) ); ?>;base64,<?php echo esc_attr( $p['avatar']['data'] ); ?>" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;vertical-align:middle"></td>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>

			<h2>Productos y servicios</h2>
			<p><?php echo esc_html( sprintf( 'El archivo trae %d: %d nuevos y %d que ya existen con el mismo nombre (se actualizarán). En este sitio hay %d que no vienen en el archivo.', count( $p['offers'] ), $o_new, $o_upd, $o_gone ) ); ?></p>
			<?php self::mode_radios( 'catalog_mode', $o_gone ); ?>

			<h2>Lo que no ofrecemos</h2>
			<p><?php echo esc_html( sprintf( 'El archivo trae %d: %d nuevos y %d que ya existen (se actualizarán). En este sitio hay %d que no vienen en el archivo.', count( $p['exclusions'] ), $x_new, $x_upd, $x_gone ) ); ?></p>
			<?php self::mode_radios( 'exclusions_mode', $x_gone ); ?>

			<p class="submit">
				<?php submit_button( 'Importar lo seleccionado', 'primary', 'submit', false ); ?>
			</p>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="qhatuq_import_cancel">
			<?php wp_nonce_field( 'qhatuq_import_cancel' ); ?>
			<?php submit_button( 'Cancelar', 'secondary', 'cancel', false ); ?>
		</form>
		<?php
	}

	private static function mode_radios( string $name, int $gone ): void {
		$options = array(
			'merge'   => 'Combinar: agregar los nuevos y actualizar los existentes, sin borrar nada',
			'replace' => 'Reemplazar: dejar exactamente lo del archivo' . ( $gone ? sprintf( ' (los %d que no vienen se envían a la papelera)', $gone ) : '' ),
			'skip'    => 'No importar',
		);
		foreach ( $options as $value => $label ) {
			printf(
				'<label style="display:block;margin:4px 0"><input type="radio" name="%s" value="%s" %s> %s</label>',
				esc_attr( $name ),
				esc_attr( $value ),
				checked( 'merge', $value, false ),
				esc_html( $label )
			);
		}
	}
}
