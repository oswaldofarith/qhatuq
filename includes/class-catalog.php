<?php
/**
 * Catálogo configurable: productos/servicios que se ofrecen y lo que NO se ofrece.
 * Ambos son tipos de contenido privados con su propia pantalla de edición en wp-admin.
 */

defined( 'ABSPATH' ) || exit;

class Qhatuq_Catalog {

	const OFFER     = 'qhatuq_offer';
	const EXCLUSION = 'qhatuq_exclusion';

	/** Qué hacer cuando piden algo que no se ofrece. */
	const ACTIONS = array(
		'decline'     => 'Decir que no, con amabilidad',
		'alternative' => 'Ofrecer una alternativa que sí tenemos',
		'partner'     => 'Recomendar a un socio de negocio',
	);

	const OFFER_FIELDS = array(
		'kind'          => 'Tipo',
		'category'      => 'Categoría o marca',
		'audience'      => 'Para quién es',
		'benefits'      => 'Beneficios principales',
		'requirements'  => 'Requisitos o condiciones',
		'price_from'    => 'Precio referencial "desde"',
		'price_unit'    => 'Unidad del precio',
		'price_allowed' => 'Puede mencionar el precio referencial',
	);

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_types' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::OFFER, array( __CLASS__, 'save_offer' ) );
		add_action( 'save_post_' . self::EXCLUSION, array( __CLASS__, 'save_exclusion' ) );
		add_filter( 'manage_' . self::OFFER . '_posts_columns', array( __CLASS__, 'offer_columns' ) );
		add_action( 'manage_' . self::OFFER . '_posts_custom_column', array( __CLASS__, 'offer_column' ), 10, 2 );
		add_filter( 'manage_' . self::EXCLUSION . '_posts_columns', array( __CLASS__, 'exclusion_columns' ) );
		add_action( 'manage_' . self::EXCLUSION . '_posts_custom_column', array( __CLASS__, 'exclusion_column' ), 10, 2 );
	}

	public static function seed_defaults(): void {
		add_option( Qhatuq_Settings::OPTION, Qhatuq_Settings::defaults() );
	}

	public static function register_types(): void {
		$common = array(
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => 'qhatuq',
			'show_in_rest' => false,
			'supports'     => array( 'title', 'editor' ),
			'map_meta_cap' => true,
		);

		register_post_type(
			self::OFFER,
			$common + array(
				'labels' => array(
					'name'          => 'Productos y servicios',
					'singular_name' => 'Producto o servicio',
					'add_new'       => 'Añadir nuevo',
					'add_new_item'  => 'Añadir producto o servicio',
					'edit_item'     => 'Editar producto o servicio',
					'all_items'     => 'Productos y servicios',
					'search_items'  => 'Buscar',
					'not_found'     => 'Aún no hay productos ni servicios.',
				),
			)
		);

		register_post_type(
			self::EXCLUSION,
			array_merge( $common, array( 'supports' => array( 'title' ) ) ) + array(
				'labels' => array(
					'name'          => 'Lo que no ofrecemos',
					'singular_name' => 'Exclusión',
					'add_new'       => 'Añadir nuevo',
					'add_new_item'  => 'Añadir algo que no ofrecemos',
					'edit_item'     => 'Editar',
					'all_items'     => 'Lo que no ofrecemos',
					'not_found'     => 'Aún no hay elementos.',
				),
			)
		);
	}

	/* ---------------------------------------------------------------- Meta boxes */

	public static function add_meta_boxes(): void {
		add_meta_box( 'qhatuq_offer_meta', 'Datos para el agente', array( __CLASS__, 'render_offer_box' ), self::OFFER, 'normal', 'high' );
		add_meta_box( 'qhatuq_exclusion_meta', 'Qué debe hacer el agente', array( __CLASS__, 'render_exclusion_box' ), self::EXCLUSION, 'normal', 'high' );
	}

	public static function render_offer_box( WP_Post $post ): void {
		wp_nonce_field( 'qhatuq_offer', 'qhatuq_offer_nonce' );
		$m = self::offer_meta( $post->ID );
		?>
		<p class="description">La descripción (editor de arriba) es lo que el agente sabrá de este producto o servicio. Escríbala como se la explicaría a un cliente.</p>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="qhatuq_kind">Tipo</label></th>
				<td>
					<select name="qhatuq[kind]" id="qhatuq_kind">
						<option value="servicio" <?php selected( $m['kind'], 'servicio' ); ?>>Servicio</option>
						<option value="producto" <?php selected( $m['kind'], 'producto' ); ?>>Producto</option>
						<option value="licencia" <?php selected( $m['kind'], 'licencia' ); ?>>Licencia de software</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="qhatuq_category">Categoría o marca</label></th>
				<td><input type="text" class="regular-text" name="qhatuq[category]" id="qhatuq_category" value="<?php echo esc_attr( $m['category'] ); ?>" placeholder="Ej.: Microsoft, Telefonía IP, Soporte"></td>
			</tr>
			<tr>
				<th><label for="qhatuq_audience">Para quién es</label></th>
				<td><textarea class="large-text" rows="2" name="qhatuq[audience]" id="qhatuq_audience"><?php echo esc_textarea( $m['audience'] ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="qhatuq_benefits">Beneficios principales</label></th>
				<td><textarea class="large-text" rows="3" name="qhatuq[benefits]" id="qhatuq_benefits"><?php echo esc_textarea( $m['benefits'] ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="qhatuq_requirements">Requisitos o condiciones</label></th>
				<td><textarea class="large-text" rows="2" name="qhatuq[requirements]" id="qhatuq_requirements"><?php echo esc_textarea( $m['requirements'] ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="qhatuq_price_from">Precio referencial "desde"</label></th>
				<td>
					<input type="text" name="qhatuq[price_from]" id="qhatuq_price_from" value="<?php echo esc_attr( $m['price_from'] ); ?>" placeholder="Ej.: 120">
					USD por
					<input type="text" name="qhatuq[price_unit]" value="<?php echo esc_attr( $m['price_unit'] ); ?>" placeholder="usuario/año, equipo, hora…">
					<p><label><input type="checkbox" name="qhatuq[price_allowed]" value="1" <?php checked( $m['price_allowed'], '1' ); ?>> El agente puede mencionarlo si el cliente insiste en saber el precio</label></p>
					<p class="description">El precio nunca se incluye en las instrucciones del agente: solo lo consulta cuando el cliente insiste, y siempre lo presenta como referencial y lo remite a un representante.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function render_exclusion_box( WP_Post $post ): void {
		wp_nonce_field( 'qhatuq_exclusion', 'qhatuq_exclusion_nonce' );
		$m = self::exclusion_meta( $post->ID );
		?>
		<p class="description">En el título escriba lo que no ofrecen (ej.: "Venta de computadoras al por menor").</p>
		<table class="form-table" role="presentation">
			<tr>
				<th>Acción</th>
				<td>
					<?php foreach ( self::ACTIONS as $value => $label ) : ?>
						<label style="display:block;margin-bottom:4px">
							<input type="radio" name="qhatuq[action]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $m['action'], $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th><label for="qhatuq_detail">Detalle</label></th>
				<td>
					<textarea class="large-text" rows="3" name="qhatuq[detail]" id="qhatuq_detail"><?php echo esc_textarea( $m['detail'] ); ?></textarea>
					<p class="description">Alternativa a ofrecer, o nombre y contacto del socio a recomendar. Opcional si la acción es decir que no.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_offer( int $post_id ): void {
		if ( ! self::can_save( $post_id, 'qhatuq_offer' ) ) {
			return;
		}
		$in = wp_unslash( $_POST['qhatuq'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_post_meta( $post_id, '_qhatuq_kind', in_array( $in['kind'] ?? '', array( 'servicio', 'producto', 'licencia' ), true ) ? $in['kind'] : 'servicio' );
		foreach ( array( 'category', 'price_from', 'price_unit' ) as $k ) {
			update_post_meta( $post_id, '_qhatuq_' . $k, sanitize_text_field( $in[ $k ] ?? '' ) );
		}
		foreach ( array( 'audience', 'benefits', 'requirements' ) as $k ) {
			update_post_meta( $post_id, '_qhatuq_' . $k, sanitize_textarea_field( $in[ $k ] ?? '' ) );
		}
		update_post_meta( $post_id, '_qhatuq_price_allowed', empty( $in['price_allowed'] ) ? '0' : '1' );
	}

	public static function save_exclusion( int $post_id ): void {
		if ( ! self::can_save( $post_id, 'qhatuq_exclusion' ) ) {
			return;
		}
		$in     = wp_unslash( $_POST['qhatuq'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$action = array_key_exists( $in['action'] ?? '', self::ACTIONS ) ? $in['action'] : 'decline';
		update_post_meta( $post_id, '_qhatuq_action', $action );
		update_post_meta( $post_id, '_qhatuq_detail', sanitize_textarea_field( $in['detail'] ?? '' ) );
	}

	private static function can_save( int $post_id, string $nonce_action ): bool {
		$nonce = $_POST[ $nonce_action . '_nonce' ] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $nonce || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			return false;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		return current_user_can( 'edit_post', $post_id );
	}

	/* ---------------------------------------------------------------- Lectura */

	public static function offer_meta( int $post_id ): array {
		$m = array();
		foreach ( array_keys( self::OFFER_FIELDS ) as $k ) {
			$m[ $k ] = (string) get_post_meta( $post_id, '_qhatuq_' . $k, true );
		}
		$m['kind'] = $m['kind'] ? $m['kind'] : 'servicio';
		return $m;
	}

	public static function exclusion_meta( int $post_id ): array {
		$action = (string) get_post_meta( $post_id, '_qhatuq_action', true );
		return array(
			'action' => array_key_exists( $action, self::ACTIONS ) ? $action : 'decline',
			'detail' => (string) get_post_meta( $post_id, '_qhatuq_detail', true ),
		);
	}

	/** @return WP_Post[] */
	public static function offers(): array {
		return get_posts(
			array(
				'post_type'      => self::OFFER,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			)
		);
	}

	/** @return WP_Post[] */
	public static function exclusions(): array {
		return get_posts(
			array(
				'post_type'      => self::EXCLUSION,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/* ---------------------------------------------------------------- Columnas del listado */

	public static function offer_columns( array $cols ): array {
		$date = $cols['date'] ?? null;
		unset( $cols['date'] );
		$cols['qhatuq_kind']  = 'Tipo';
		$cols['qhatuq_price'] = 'Precio referencial';
		if ( $date ) {
			$cols['date'] = $date;
		}
		return $cols;
	}

	public static function offer_column( string $col, int $post_id ): void {
		$m = self::offer_meta( $post_id );
		if ( 'qhatuq_kind' === $col ) {
			echo esc_html( ucfirst( $m['kind'] ) . ( $m['category'] ? ' · ' . $m['category'] : '' ) );
		} elseif ( 'qhatuq_price' === $col ) {
			if ( '' === $m['price_from'] ) {
				echo '—';
			} else {
				echo esc_html( 'Desde ' . $m['price_from'] . ' USD' . ( $m['price_unit'] ? ' / ' . $m['price_unit'] : '' ) );
				echo $m['price_allowed'] ? '' : ' <em>(no se menciona)</em>';
			}
		}
	}

	public static function exclusion_columns( array $cols ): array {
		$date = $cols['date'] ?? null;
		unset( $cols['date'] );
		$cols['qhatuq_action'] = 'Acción';
		if ( $date ) {
			$cols['date'] = $date;
		}
		return $cols;
	}

	public static function exclusion_column( string $col, int $post_id ): void {
		if ( 'qhatuq_action' === $col ) {
			$m = self::exclusion_meta( $post_id );
			echo esc_html( self::ACTIONS[ $m['action'] ] );
		}
	}
}
