<?php
/**
 * Panel de administración: leads, conversaciones, catálogo y ajustes.
 */

defined( 'ABSPATH' ) || exit;

class Qhatuq_Admin {

	const CAP = 'manage_options';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_qhatuq_lead_update', array( __CLASS__, 'handle_lead_update' ) );
		add_action( 'admin_post_qhatuq_leads_csv', array( __CLASS__, 'handle_csv' ) );
		add_action( 'admin_post_qhatuq_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** Selector de la foto del agente con la Biblioteca de medios (solo en Ajustes). */
	public static function assets(): void {
		if ( 'qhatuq-settings' !== ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		wp_enqueue_media();
		wp_register_style( 'qhatuq-admin', false, array(), QHATUQ_VERSION );
		wp_enqueue_style( 'qhatuq-admin' );
		wp_add_inline_style(
			'qhatuq-admin',
			'.qhatuq-avatar-field{display:flex;align-items:center;gap:12px}
			.qhatuq-avatar-preview,.qhatuq-avatar-empty{width:64px;height:64px;border-radius:50%;overflow:hidden;flex:none;background:#2271b1;color:#fff;font-size:26px;font-weight:600;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 0 0 1px #dcdcde}
			.qhatuq-avatar-preview img{width:100%;height:100%;object-fit:cover}'
		);
		wp_add_inline_script(
			'media-editor',
			"jQuery(function($){
				var frame;
				function setAvatar(id, url){
					$('#qhatuq-avatar-id').val(id || 0);
					$('#qhatuq-avatar-url').val('');
					$('.qhatuq-avatar-preview').toggle(!!url).find('img').attr('src', url || '');
					$('.qhatuq-avatar-empty').toggle(!url);
					$('#qhatuq-avatar-remove').toggle(!!url);
					$('#qhatuq-avatar-pick').text(url ? 'Cambiar foto' : 'Subir o elegir foto');
				}
				$('#qhatuq-avatar-pick').on('click', function(e){
					e.preventDefault();
					if (!frame) {
						frame = wp.media({ title: 'Foto del agente', button: { text: 'Usar esta foto' }, library: { type: 'image' }, multiple: false });
						frame.on('select', function(){
							var a = frame.state().get('selection').first().toJSON();
							var url = (a.sizes && (a.sizes.thumbnail || a.sizes.medium) || a).url;
							setAvatar(a.id, url);
						});
					}
					frame.open();
				});
				$('#qhatuq-avatar-remove').on('click', function(e){ e.preventDefault(); setAvatar(0, ''); });
			});"
		);
	}

	public static function menu(): void {
		add_menu_page( 'Qhatuq', 'Agente de ventas', self::CAP, 'qhatuq', array( __CLASS__, 'page_leads' ), 'dashicons-format-chat', 26 );
		add_submenu_page( 'qhatuq', 'Leads', 'Leads', self::CAP, 'qhatuq-leads', array( __CLASS__, 'page_leads' ) );
		add_submenu_page( 'qhatuq', 'Conversaciones', 'Conversaciones', self::CAP, 'qhatuq-conversations', array( __CLASS__, 'page_conversations' ) );
		add_submenu_page( 'qhatuq', 'Ajustes del agente', 'Ajustes', self::CAP, 'qhatuq-settings', array( __CLASS__, 'page_settings' ) );
		// La primera entrada duplicada del menú se elimina: "Leads" es la portada.
		remove_submenu_page( 'qhatuq', 'qhatuq' );
	}

	public static function notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'qhatuq' ) ) {
			return;
		}
		$s = Qhatuq_Settings::all();
		if ( 'claude' === $s['provider'] && ! Qhatuq_Provider_Claude::available() ) {
			echo '<div class="notice notice-error"><p><strong>Qhatuq:</strong> falta la carpeta <code>vendor/</code> con el SDK de Anthropic. Instale el ZIP compilado del plugin o ejecute <code>composer install --no-dev</code> en la carpeta del plugin.</p></div>';
		}
		if ( '' === Qhatuq_Settings::api_key( $s['provider'] ) ) {
			echo '<div class="notice notice-warning"><p><strong>Qhatuq:</strong> configure la API key del proveedor elegido en <a href="' . esc_url( admin_url( 'admin.php?page=qhatuq-settings' ) ) . '">Ajustes</a>. Mientras tanto el chat no se muestra en el sitio.</p></div>';
		}
		if ( isset( $_GET['qhatuq_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$msg = sanitize_text_field( wp_unslash( $_GET['qhatuq_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$ok  = ! empty( $_GET['qhatuq_ok'] ); // phpcs:ignore WordPress.Security.NonceVerification
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $ok ? 'success' : 'error', esc_html( $msg ) );
		}
	}

	/* ---------------------------------------------------------------- Ajustes */

	public static function register_settings(): void {
		register_setting(
			'qhatuq',
			Qhatuq_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Qhatuq_Settings', 'sanitize' ),
			)
		);
	}

	public static function page_settings(): void {
		$s    = Qhatuq_Settings::all();
		$name = Qhatuq_Settings::OPTION;
		$f    = static fn( $k ) => esc_attr( $name . '[' . $k . ']' );
		?>
		<div class="wrap">
			<h1>Ajustes del agente de ventas</h1>
			<p>El catálogo se administra en <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Qhatuq_Catalog::OFFER ) ); ?>">Productos y servicios</a> y <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Qhatuq_Catalog::EXCLUSION ) ); ?>">Lo que no ofrecemos</a>. Los cambios se aplican a las conversaciones nuevas.</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'qhatuq' ); ?>

				<h2 class="title">Inteligencia artificial</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th>Estado</th>
						<td><label><input type="checkbox" name="<?php echo $f( 'enabled' ); ?>" value="1" <?php checked( $s['enabled'], 1 ); ?>> Mostrar el chat en el sitio</label></td>
					</tr>
					<tr>
						<th>Proveedor</th>
						<td>
							<label><input type="radio" name="<?php echo $f( 'provider' ); ?>" value="claude" <?php checked( $s['provider'], 'claude' ); ?>> Claude (Anthropic)</label>&nbsp;&nbsp;
							<label><input type="radio" name="<?php echo $f( 'provider' ); ?>" value="gemini" <?php checked( $s['provider'], 'gemini' ); ?>> Gemini (Google)</label>
							<p class="description">Las conversaciones ya iniciadas terminan con el proveedor y el modelo con que empezaron.</p>
						</td>
					</tr>
					<?php self::key_row( 'claude', 'API key de Claude', 'platform.claude.com', $s ); ?>
					<tr>
						<th><label for="qhatuq-claude-model">Modelo de Claude</label></th>
						<td>
							<select id="qhatuq-claude-model" name="<?php echo $f( 'claude_model' ); ?>">
								<?php foreach ( Qhatuq_Settings::CLAUDE_MODELS as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['claude_model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="qhatuq-effort">Nivel de razonamiento (Claude)</label></th>
						<td>
							<select id="qhatuq-effort" name="<?php echo $f( 'claude_effort' ); ?>">
								<option value="low" <?php selected( $s['claude_effort'], 'low' ); ?>>Bajo (recomendado para chat: más rápido y económico)</option>
								<option value="medium" <?php selected( $s['claude_effort'], 'medium' ); ?>>Medio</option>
								<option value="high" <?php selected( $s['claude_effort'], 'high' ); ?>>Alto</option>
							</select>
							<p class="description">No aplica a Claude Haiku 4.5.</p>
						</td>
					</tr>
					<tr>
						<th>Productos fuera del catálogo</th>
						<td>
							<label><input type="checkbox" name="<?php echo $f( 'web_search' ); ?>" value="1" <?php checked( $s['web_search'], 1 ); ?>> Verificar en la web si se venden (solo con Claude)</label>
							<p class="description">Si el cliente pide una licencia, suscripción o equipo que no está en el catálogo ni en "Lo que no ofrecemos", el agente busca si se vende abiertamente en línea en EE. UU. o Ecuador. Si se vende, lo trata como un producto más; si no es concluyente, deriva a un representante. Nunca menciona precios encontrados. Costo: US$10 por cada 1.000 búsquedas (máximo 3 por mensaje). Con Gemini, o con esta opción desactivada, esos casos siempre se derivan a un representante.</p>
						</td>
					</tr>
					<?php self::key_row( 'gemini', 'API key de Gemini', 'aistudio.google.com', $s ); ?>
					<tr>
						<th><label for="qhatuq-gemini-model">Modelo de Gemini</label></th>
						<td>
							<input type="text" id="qhatuq-gemini-model" class="regular-text" name="<?php echo $f( 'gemini_model' ); ?>" value="<?php echo esc_attr( $s['gemini_model'] ); ?>">
							<p class="description">Identificador del modelo en la API de Gemini (ej.: <code>gemini-3-flash-preview</code>). Google retira modelos con frecuencia: verifique que siga disponible.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Empresa y personalidad</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="qhatuq-company">Nombre de la empresa</label></th>
						<td><input type="text" id="qhatuq-company" class="regular-text" name="<?php echo $f( 'company_name' ); ?>" value="<?php echo esc_attr( $s['company_name'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="qhatuq-desc">Descripción de la empresa</label></th>
						<td>
							<textarea id="qhatuq-desc" class="large-text" rows="5" name="<?php echo $f( 'company_description' ); ?>"><?php echo esc_textarea( $s['company_description'] ); ?></textarea>
							<p class="description">A qué se dedica, dónde atiende, a quién vende, qué la diferencia, horarios, formas de contacto…</p>
						</td>
					</tr>
					<tr>
						<th><label for="qhatuq-agent">Nombre del agente</label></th>
						<td><input type="text" id="qhatuq-agent" class="regular-text" name="<?php echo $f( 'agent_name' ); ?>" value="<?php echo esc_attr( $s['agent_name'] ); ?>"></td>
					</tr>
					<tr>
						<th>Trato al cliente</th>
						<td>
							<label><input type="radio" name="<?php echo $f( 'treatment' ); ?>" value="usted" <?php checked( $s['treatment'], 'usted' ); ?>> Usted</label>&nbsp;&nbsp;
							<label><input type="radio" name="<?php echo $f( 'treatment' ); ?>" value="tu" <?php checked( $s['treatment'], 'tu' ); ?>> Tú</label>
						</td>
					</tr>
					<tr>
						<th><label for="qhatuq-greeting">Saludo inicial</label></th>
						<td><input type="text" id="qhatuq-greeting" class="large-text" name="<?php echo $f( 'greeting' ); ?>" value="<?php echo esc_attr( $s['greeting'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="qhatuq-prohib">Prohibiciones</label></th>
						<td>
							<textarea id="qhatuq-prohib" class="large-text" rows="7" name="<?php echo $f( 'prohibitions' ); ?>"><?php echo esc_textarea( $s['prohibitions'] ); ?></textarea>
							<p class="description">Una por línea. El agente nunca hará estas cosas, aunque el cliente insista.</p>
						</td>
					</tr>
					<tr>
						<th><label for="qhatuq-criteria">Criterios de temperatura del lead</label></th>
						<td><textarea id="qhatuq-criteria" class="large-text" rows="4" name="<?php echo $f( 'lead_criteria' ); ?>"><?php echo esc_textarea( $s['lead_criteria'] ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="qhatuq-extra">Indicaciones adicionales</label></th>
						<td>
							<textarea id="qhatuq-extra" class="large-text" rows="4" name="<?php echo $f( 'extra_instructions' ); ?>"><?php echo esc_textarea( $s['extra_instructions'] ); ?></textarea>
							<p class="description">Opcional. Respuestas a objeciones frecuentes, casos de éxito que puede mencionar, etc.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Avisos y privacidad</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="qhatuq-emails">Correos para avisos de leads</label></th>
						<td>
							<input type="text" id="qhatuq-emails" class="large-text" name="<?php echo $f( 'notify_emails' ); ?>" value="<?php echo esc_attr( $s['notify_emails'] ); ?>">
							<p class="description">Separados por coma. Se avisa una vez por lead, cuando tiene un medio de contacto y una necesidad.</p>
						</td>
					</tr>
					<tr>
						<th><label for="qhatuq-notice">Aviso en el chat</label></th>
						<td><textarea id="qhatuq-notice" class="large-text" rows="2" name="<?php echo $f( 'privacy_notice' ); ?>"><?php echo esc_textarea( $s['privacy_notice'] ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="qhatuq-privacy">URL de la política de privacidad</label></th>
						<td>
							<input type="url" id="qhatuq-privacy" class="large-text" name="<?php echo $f( 'privacy_url' ); ?>" value="<?php echo esc_attr( $s['privacy_url'] ); ?>">
							<p class="description">Recuerde mencionar en ella que las conversaciones del chat se procesan con un proveedor de IA externo (Anthropic o Google).</p>
						</td>
					</tr>
					<tr>
						<th><label for="qhatuq-retention">Conservar conversaciones</label></th>
						<td><input type="number" id="qhatuq-retention" min="0" max="3650" name="<?php echo $f( 'retention_days' ); ?>" value="<?php echo esc_attr( $s['retention_days'] ); ?>"> días <span class="description">(0 = no borrar nunca; los leads se conservan siempre)</span></td>
					</tr>
				</table>

				<h2 class="title">Apariencia y límites</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="qhatuq-title">Título del chat</label></th>
						<td><input type="text" id="qhatuq-title" class="regular-text" name="<?php echo $f( 'widget_title' ); ?>" value="<?php echo esc_attr( $s['widget_title'] ); ?>"></td>
					</tr>
					<tr>
						<th>Foto del agente</th>
						<td>
							<?php $avatar = Qhatuq_Settings::avatar_url(); ?>
							<div class="qhatuq-avatar-field">
								<span class="qhatuq-avatar-preview" style="<?php echo $avatar ? '' : 'display:none'; ?>"><img src="<?php echo esc_url( $avatar ); ?>" alt=""></span>
								<span class="qhatuq-avatar-empty" style="<?php echo $avatar ? 'display:none' : ''; ?>"><?php echo esc_html( mb_strtoupper( mb_substr( $s['agent_name'] ? $s['agent_name'] : 'A', 0, 1 ) ) ); ?></span>
								<input type="hidden" id="qhatuq-avatar-id" name="<?php echo $f( 'widget_avatar_id' ); ?>" value="<?php echo esc_attr( (int) $s['widget_avatar_id'] ); ?>">
								<input type="hidden" id="qhatuq-avatar-url" name="<?php echo $f( 'widget_avatar' ); ?>" value="<?php echo esc_attr( $s['widget_avatar'] ); ?>">
								<button type="button" class="button" id="qhatuq-avatar-pick"><?php echo $avatar ? 'Cambiar foto' : 'Subir o elegir foto'; ?></button>
								<button type="button" class="button-link button-link-delete" id="qhatuq-avatar-remove" style="<?php echo $avatar ? '' : 'display:none'; ?>">Quitar</button>
							</div>
							<p class="description">Se muestra en la cabecera del chat y junto a cada respuesta. Use una imagen cuadrada de al menos 200 × 200 px. Sin foto se muestra la inicial del agente.</p>
						</td>
					</tr>
					<tr>
						<th><label for="qhatuq-suggestions">Sugerencias rápidas</label></th>
						<td>
							<textarea id="qhatuq-suggestions" class="large-text" rows="4" name="<?php echo $f( 'widget_suggestions' ); ?>"><?php echo esc_textarea( $s['widget_suggestions'] ); ?></textarea>
							<p class="description">Botones que se muestran al iniciar el chat; una por línea, máximo 6. Deje vacío para no mostrarlos.</p>
						</td>
					</tr>
					<tr>
						<th><label for="qhatuq-teaser">Burbuja de invitación</label></th>
						<td><input type="number" id="qhatuq-teaser" min="0" max="300" name="<?php echo $f( 'teaser_delay' ); ?>" value="<?php echo esc_attr( $s['teaser_delay'] ); ?>"> segundos <span class="description">(muestra el saludo junto al botón tras ese tiempo, una vez por visita; 0 = desactivada)</span></td>
					</tr>
					<tr>
						<th><label for="qhatuq-color">Color</label></th>
						<td><input type="color" id="qhatuq-color" name="<?php echo $f( 'widget_color' ); ?>" value="<?php echo esc_attr( $s['widget_color'] ); ?>"></td>
					</tr>
					<tr>
						<th>Posición</th>
						<td>
							<label><input type="radio" name="<?php echo $f( 'widget_position' ); ?>" value="right" <?php checked( $s['widget_position'], 'right' ); ?>> Derecha</label>&nbsp;&nbsp;
							<label><input type="radio" name="<?php echo $f( 'widget_position' ); ?>" value="left" <?php checked( $s['widget_position'], 'left' ); ?>> Izquierda</label>
						</td>
					</tr>
					<tr>
						<th>Límites contra el abuso</th>
						<td>
							<p><input type="number" min="4" max="200" name="<?php echo $f( 'max_messages' ); ?>" value="<?php echo esc_attr( $s['max_messages'] ); ?>"> mensajes por conversación</p>
							<p><input type="number" min="5" max="1000" name="<?php echo $f( 'max_per_ip_hour' ); ?>" value="<?php echo esc_attr( $s['max_per_ip_hour'] ); ?>"> mensajes por hora desde una misma IP</p>
							<p><input type="number" min="200" max="5000" name="<?php echo $f( 'max_message_chars' ); ?>" value="<?php echo esc_attr( $s['max_message_chars'] ); ?>"> caracteres por mensaje</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Guardar ajustes' ); ?>
			</form>

			<hr>
			<h2>Probar la conexión</h2>
			<p>Envía un mensaje corto al proveedor y modelo guardados para comprobar la API key.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="qhatuq_test">
				<?php wp_nonce_field( 'qhatuq_test' ); ?>
				<?php submit_button( 'Probar conexión', 'secondary', 'submit', false ); ?>
			</form>

			<h2>Vista previa de las instrucciones</h2>
			<p class="description">Así recibe el agente la configuración y el catálogo actuales (los precios no se incluyen).</p>
			<textarea class="large-text code" rows="16" readonly><?php echo esc_textarea( Qhatuq_Prompt::build( Qhatuq_Agent::web_search_enabled( (string) $s['provider'] ) ) ); ?></textarea>
		</div>
		<?php
	}

	private static function key_row( string $provider, string $label, string $where, array $s ): void {
		$name = Qhatuq_Settings::OPTION;
		$has  = '' !== Qhatuq_Settings::api_key( $provider );
		?>
		<tr>
			<th><label for="qhatuq-<?php echo esc_attr( $provider ); ?>-key"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php if ( Qhatuq_Settings::key_from_constant( $provider ) ) : ?>
					<p>Definida en <code>wp-config.php</code>.</p>
				<?php else : ?>
					<input type="password" id="qhatuq-<?php echo esc_attr( $provider ); ?>-key" class="regular-text" autocomplete="new-password"
						name="<?php echo esc_attr( $name . '[' . $provider . '_api_key]' ); ?>" value=""
						placeholder="<?php echo $has ? esc_attr( '•••••••• (guardada; deje vacío para conservarla)' ) : ''; ?>">
					<?php if ( $has ) : ?>
						<label><input type="checkbox" name="<?php echo esc_attr( $name . '[' . $provider . '_api_key_clear]' ); ?>" value="1"> Borrar</label>
					<?php endif; ?>
					<p class="description">Se obtiene en <?php echo esc_html( $where ); ?>. Más seguro: defínala en <code>wp-config.php</code> con <code><?php echo esc_html( 'define( \'QHATUQ_' . strtoupper( $provider ) . '_API_KEY\', \'...\' );' ); ?></code></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	public static function handle_test(): void {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'qhatuq_test' ) ) {
			wp_die( 'No autorizado.' );
		}
		$provider_id = (string) Qhatuq_Settings::get( 'provider' );
		$ok          = false;
		try {
			$provider   = Qhatuq_Agent::make_provider( $provider_id, Qhatuq_Agent::current_model( $provider_id ) );
			$transcript = array();
			$result     = $provider->run_turn(
				'Eres un asistente de prueba. Responde en una sola oración breve en español.',
				$transcript,
				'Hola, ¿me escuchas?',
				static fn() => array( 'No disponible en la prueba.', true )
			);
			$ok  = true;
			$msg = sprintf( 'Conexión correcta con %s (%s). Respuesta: %s', $provider_id, $provider->model(), mb_substr( $result['text'], 0, 200 ) );
		} catch ( \Throwable $e ) {
			$msg = 'La prueba falló: ' . $e->getMessage();
		}
		wp_safe_redirect( add_query_arg( array( 'qhatuq_msg' => rawurlencode( $msg ), 'qhatuq_ok' => $ok ? 1 : 0 ), admin_url( 'admin.php?page=qhatuq-settings' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------- Leads */

	public static function page_leads(): void {
		$lead_id = absint( $_GET['lead'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $lead_id ) {
			self::lead_detail( $lead_id );
			return;
		}
		$table = new Qhatuq_Leads_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Leads</h1>
			<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=qhatuq_leads_csv' ), 'qhatuq_csv' ) ); ?>">Exportar CSV</a>
			<hr class="wp-header-end">
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="qhatuq-leads">
				<?php $table->search_box( 'Buscar leads', 'qhatuq-lead' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	private static function lead_detail( int $lead_id ): void {
		$lead = Qhatuq_DB::get_lead( $lead_id );
		if ( ! $lead ) {
			echo '<div class="wrap"><p>Lead no encontrado.</p></div>';
			return;
		}
		?>
		<div class="wrap">
			<h1>Lead #<?php echo (int) $lead['id']; ?> <?php echo esc_html( $lead['company'] ? '· ' . $lead['company'] : '' ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=qhatuq-leads' ) ); ?>">&larr; Volver a leads</a></p>
			<div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">
				<div style="flex:1;min-width:320px">
					<table class="widefat striped">
						<tbody>
						<?php foreach ( explode( "\n", Qhatuq_Leads::as_text( $lead ) ) as $line ) : ?>
							<?php list( $label, $value ) = array_pad( explode( ': ', $line, 2 ), 2, '' ); ?>
							<tr><th style="width:140px"><?php echo esc_html( $label ); ?></th><td><?php echo nl2br( esc_html( $value ) ); ?></td></tr>
						<?php endforeach; ?>
						<tr><th>Creado</th><td><?php echo esc_html( get_date_from_gmt( $lead['created_at'], 'd/m/Y H:i' ) ); ?></td></tr>
						<tr><th>Aviso por correo</th><td><?php echo $lead['notified_at'] ? esc_html( get_date_from_gmt( $lead['notified_at'], 'd/m/Y H:i' ) ) : 'Pendiente (faltan datos de contacto o necesidad)'; ?></td></tr>
						</tbody>
					</table>

					<h2>Seguimiento</h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="qhatuq_lead_update">
						<input type="hidden" name="lead" value="<?php echo (int) $lead['id']; ?>">
						<?php wp_nonce_field( 'qhatuq_lead_' . $lead['id'] ); ?>
						<p>
							<label>Estado:
								<select name="status">
									<?php foreach ( Qhatuq_Leads::STATUSES as $k => $label ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $lead['status'], $k ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						</p>
						<p><label>Notas internas:<br><textarea name="notes" class="large-text" rows="4"><?php echo esc_textarea( $lead['notes'] ); ?></textarea></label></p>
						<?php submit_button( 'Guardar', 'primary', 'submit', false ); ?>
					</form>
				</div>
				<div style="flex:1;min-width:320px">
					<h2 style="margin-top:0">Conversación</h2>
					<?php self::render_transcript( (int) $lead['conversation_id'] ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	public static function handle_lead_update(): void {
		$lead_id = absint( $_POST['lead'] ?? 0 );
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'qhatuq_lead_' . $lead_id ) ) {
			wp_die( 'No autorizado.' );
		}
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		Qhatuq_DB::update_lead(
			$lead_id,
			array(
				'status' => array_key_exists( $status, Qhatuq_Leads::STATUSES ) ? $status : 'nuevo',
				'notes'  => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			)
		);
		wp_safe_redirect( add_query_arg( array( 'qhatuq_msg' => rawurlencode( 'Lead actualizado.' ), 'qhatuq_ok' => 1 ), admin_url( 'admin.php?page=qhatuq-leads&lead=' . $lead_id ) ) );
		exit;
	}

	public static function handle_csv(): void {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'qhatuq_csv' ) ) {
			wp_die( 'No autorizado.' );
		}
		global $wpdb;
		$t    = Qhatuq_DB::table( 'leads' );
		$rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id DESC", ARRAY_A );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=leads-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM para que Excel reconozca UTF-8.
		fputcsv( $out, array( 'ID', 'Fecha', 'Nombre', 'Empresa', 'Teléfono', 'Correo', 'Necesidad', 'Productos', 'Temperatura', 'Estado', 'Resumen', 'Fuera de catálogo', 'Notas' ) );
		foreach ( $rows as $r ) {
			$cells = array( $r['id'], get_date_from_gmt( $r['created_at'], 'Y-m-d H:i' ), $r['name'], $r['company'], $r['phone'], $r['email'], $r['need'], Qhatuq_Leads::items_text( $r ), $r['temperature'], $r['status'], $r['summary'], (string) $r['off_catalog'], $r['notes'] );
			// Evita que una celda que empiece con =, +, - o @ se interprete como fórmula.
			$cells = array_map( static fn( $c ) => preg_match( '/^[=+\-@\t\r]/', (string) $c ) ? "'" . $c : $c, $cells );
			fputcsv( $out, $cells );
		}
		fclose( $out );
		exit;
	}

	/* ---------------------------------------------------------------- Conversaciones */

	public static function page_conversations(): void {
		$conv_id = absint( $_GET['conversation'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $conv_id ) {
			$conv = Qhatuq_DB::get_conversation( $conv_id );
			$lead = $conv ? Qhatuq_DB::get_lead_by_conversation( $conv_id ) : null;
			?>
			<div class="wrap">
				<h1>Conversación #<?php echo (int) $conv_id; ?></h1>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=qhatuq-conversations' ) ); ?>">&larr; Volver a conversaciones</a></p>
				<?php if ( $conv ) : ?>
					<p>
						<?php echo esc_html( get_date_from_gmt( $conv['created_at'], 'd/m/Y H:i' ) ); ?> ·
						<?php echo esc_html( $conv['provider'] . ' / ' . $conv['model'] ); ?> ·
						Tokens: <?php echo esc_html( number_format_i18n( (int) $conv['input_tokens'] ) . ' entrada, ' . number_format_i18n( (int) $conv['cache_read_tokens'] ) . ' desde caché, ' . number_format_i18n( (int) $conv['output_tokens'] ) . ' salida' ); ?>
						<?php if ( (int) $conv['web_searches'] ) : ?>
							· Búsquedas web: <?php echo (int) $conv['web_searches']; ?>
						<?php endif; ?>
						<?php if ( $conv['page_url'] ) : ?>
							· Página: <a href="<?php echo esc_url( $conv['page_url'] ); ?>" target="_blank"><?php echo esc_html( $conv['page_url'] ); ?></a>
						<?php endif; ?>
						<?php if ( $lead ) : ?>
							· <a href="<?php echo esc_url( admin_url( 'admin.php?page=qhatuq-leads&lead=' . $lead['id'] ) ); ?>">Ver lead</a>
						<?php endif; ?>
					</p>
					<div style="max-width:760px"><?php self::render_transcript( $conv_id ); ?></div>
				<?php else : ?>
					<p>Conversación no encontrada (puede haberse borrado por el plazo de retención).</p>
				<?php endif; ?>
			</div>
			<?php
			return;
		}

		$table = new Qhatuq_Conversations_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1>Conversaciones</h1>
			<form method="get">
				<input type="hidden" name="page" value="qhatuq-conversations">
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	private static function render_transcript( int $conversation_id ): void {
		$messages = $conversation_id ? Qhatuq_DB::get_messages( $conversation_id ) : array();
		if ( ! $messages ) {
			echo '<p>Sin mensajes (la conversación pudo haberse borrado por el plazo de retención).</p>';
			return;
		}
		$labels = array(
			'user'      => 'Visitante',
			'assistant' => 'Agente',
			'search'    => 'Búsqueda web del agente',
			'error'     => 'Error técnico',
		);
		$colors = array(
			'user'      => '#e8f0fb',
			'assistant' => '#f6f7f7',
			'search'    => '#fcf9e8',
			'error'     => '#fcf0f1',
		);
		foreach ( $messages as $m ) {
			printf(
				'<div style="background:%s;border-radius:8px;padding:8px 12px;margin-bottom:8px"><strong>%s</strong> <span style="color:#787c82;font-size:12px">%s</span><div>%s</div></div>',
				esc_attr( $colors[ $m['role'] ] ?? '#fff' ),
				esc_html( $labels[ $m['role'] ] ?? $m['role'] ),
				esc_html( get_date_from_gmt( $m['created_at'], 'd/m/Y H:i' ) ),
				nl2br( esc_html( $m['content'] ) )
			);
		}
	}
}
