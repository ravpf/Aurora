<?php
/**
 * Shop admin: the product editor.
 *
 * Options are declared as text and the variant table is generated from their
 * cartesian product. A hand-built "add variant" repeater would let the owner
 * invent a combination that the options do not describe; deriving the rows means
 * every row is sellable by construction, and a row whose option value is later
 * renamed simply drops out on read.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the product metabox.
 */
function aurora_shop_metabox() {
	add_meta_box(
		'aurora_product_data',
		__( '商品设置', 'aurora' ),
		'aurora_shop_metabox_html',
		'aurora_product',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'aurora_shop_metabox' );

/**
 * The editor.
 *
 * @param WP_Post $post Product.
 */
function aurora_shop_metabox_html( $post ) {
	wp_nonce_field( 'aurora_save_product', 'aurora_product_nonce' );

	$meta     = aurora_product_meta( $post->ID );
	$options  = $meta['options'];
	$variants = $meta['variants'];

	// The rows on screen are the product of what is declared right now, plus any
	// combination that already has data — so a stale row keeps its price while
	// the owner is mid-edit instead of vanishing on the next save.
	$combos = aurora_shop_combinations( $options );

	foreach ( $variants as $key => $variant ) {
		if ( ! isset( $combos[ $key ] ) ) {
			$combos[ $key ] = $variant['options'];
		}
	}
	?>
	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><label for="aurora_price"><?php esc_html_e( '价格', 'aurora' ); ?></label></th>
				<td>
					<input type="number" step="0.01" min="0" id="aurora_price" name="aurora_price" class="small-text"
						value="<?php echo esc_attr( $meta['price'] ); ?>">
					<label style="margin-left:18px">
						<?php esc_html_e( '划线价', 'aurora' ); ?>
						<input type="number" step="0.01" min="0" name="aurora_compare_price" class="small-text"
							value="<?php echo esc_attr( $meta['compare_price'] ); ?>">
					</label>
					<label style="margin-left:18px">
						<?php esc_html_e( 'SKU', 'aurora' ); ?>
						<input type="text" name="aurora_sku" class="regular-text" style="width:140px"
							value="<?php echo esc_attr( $meta['sku'] ); ?>">
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="aurora_kind"><?php esc_html_e( '商品类型', 'aurora' ); ?></label></th>
				<td>
					<select id="aurora_kind" name="aurora_kind">
						<?php foreach ( aurora_product_kinds() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $meta['kind'], $slug ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( '实物商品下单时会要求填写收货地址。', 'aurora' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( '库存', 'aurora' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="aurora_stock_managed" value="1" <?php checked( $meta['managed'] ); ?>>
						<?php esc_html_e( '启用库存管理', 'aurora' ); ?>
					</label>
					<?php if ( ! $variants ) : ?>
						<label style="margin-left:18px">
							<?php esc_html_e( '库存数量', 'aurora' ); ?>
							<input type="number" step="1" min="0" name="aurora_stock" class="small-text"
								value="<?php echo esc_attr( $meta['stock'] ); ?>">
						</label>
					<?php else : ?>
						<span class="description" style="margin-left:14px">
							<?php esc_html_e( '有规格时库存按规格分别填写（见下表）。', 'aurora' ); ?>
						</span>
					<?php endif; ?>
					<p class="description">
						<?php esc_html_e( '下单即占用库存，未支付超时自动归还；退款一并归还。', 'aurora' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="aurora_limit"><?php esc_html_e( '每人限购', 'aurora' ); ?></label></th>
				<td>
					<input type="number" step="1" min="0" id="aurora_limit" name="aurora_limit" class="small-text"
						value="<?php echo esc_attr( $meta['limit'] ); ?>">
					<span class="description"><?php esc_html_e( '0 表示不限购。按已支付订单累计。', 'aurora' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="aurora_options"><?php esc_html_e( '商品选项', 'aurora' ); ?></label></th>
				<td>
					<textarea id="aurora_options" name="aurora_options" rows="4" class="large-text code"
						placeholder="颜色: 红, 蓝&#10;尺码: S, M, L"><?php echo esc_textarea( aurora_shop_options_text( $options ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( '每行一个选项组，格式「名称: 值1, 值2」。留空表示该商品没有规格。保存后下方会列出所有组合。', 'aurora' ); ?>
					</p>
				</td>
			</tr>
		</tbody>
	</table>

	<?php if ( $combos ) : ?>
		<h3 style="margin-bottom:6px"><?php esc_html_e( '规格明细', 'aurora' ); ?></h3>
		<p class="description" style="margin-top:0">
			<?php esc_html_e( '价格留空表示沿用商品价格。库存只在启用库存管理时生效。', 'aurora' ); ?>
		</p>
		<table class="widefat striped" style="max-width:900px">
			<thead>
				<tr>
					<th><?php esc_html_e( '规格组合', 'aurora' ); ?></th>
					<th style="width:130px"><?php esc_html_e( '价格', 'aurora' ); ?></th>
					<th style="width:110px"><?php esc_html_e( '库存', 'aurora' ); ?></th>
					<th style="width:150px"><?php esc_html_e( 'SKU', 'aurora' ); ?></th>
					<th style="width:80px"><?php esc_html_e( '上架', 'aurora' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $combos as $key => $picked ) : ?>
					<?php $row = isset( $variants[ $key ] ) ? $variants[ $key ] : null; ?>
					<tr>
						<td>
							<strong><?php echo esc_html( implode( ' / ', array_values( $picked ) ) ); ?></strong>
							<input type="hidden" name="aurora_variant_key[]" value="<?php echo esc_attr( $key ); ?>">
							<?php foreach ( $picked as $group_name => $value ) : ?>
								<input type="hidden" name="aurora_variant_option[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $group_name ); ?>]"
									value="<?php echo esc_attr( $value ); ?>">
							<?php endforeach; ?>
						</td>
						<td>
							<input type="number" step="0.01" min="0" class="small-text"
								name="aurora_variant_price[<?php echo esc_attr( $key ); ?>]"
								value="<?php echo esc_attr( $row && null !== $row['price'] ? $row['price'] : '' ); ?>">
						</td>
						<td>
							<input type="number" step="1" min="0" class="small-text"
								name="aurora_variant_stock[<?php echo esc_attr( $key ); ?>]"
								value="<?php echo esc_attr( aurora_product_stock( $post->ID, $key ) ); ?>">
						</td>
						<td>
							<input type="text" class="regular-text" style="width:130px"
								name="aurora_variant_sku[<?php echo esc_attr( $key ); ?>]"
								value="<?php echo esc_attr( $row ? $row['sku'] : '' ); ?>">
						</td>
						<td>
							<input type="checkbox" name="aurora_variant_enabled[<?php echo esc_attr( $key ); ?>]" value="1"
								<?php checked( ! $row || ! empty( $row['enabled'] ) ); ?>>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
}

/**
 * The cartesian product of the declared option groups, keyed by variant key.
 *
 * @param array $groups Option groups.
 * @return array
 */
function aurora_shop_combinations( $groups ) {
	$combos = array();

	foreach ( (array) $groups as $group ) {
		$name   = isset( $group['name'] ) ? (string) $group['name'] : '';
		$values = isset( $group['values'] ) ? (array) $group['values'] : array();

		if ( '' === $name || ! $values ) {
			continue;
		}

		if ( ! $combos ) {
			foreach ( $values as $value ) {
				$combos[ aurora_variant_key( array( $name => $value ) ) ] = array( $name => $value );
			}

			continue;
		}

		$next = array();

		foreach ( $combos as $picked ) {
			foreach ( $values as $value ) {
				$merged                     = $picked;
				$merged[ $name ]            = $value;
				$next[ aurora_variant_key( $merged ) ] = $merged;
			}
		}

		$combos = $next;
	}

	return $combos;
}

/**
 * Render option groups back into the editor's text format.
 *
 * @param array $groups Option groups.
 * @return string
 */
function aurora_shop_options_text( $groups ) {
	$lines = array();

	foreach ( (array) $groups as $group ) {
		if ( ! isset( $group['name'], $group['values'] ) ) {
			continue;
		}

		$lines[] = $group['name'] . ': ' . implode( ', ', (array) $group['values'] );
	}

	return implode( "\n", $lines );
}

/**
 * Parse the editor's text format into option groups.
 *
 * Accepts both ASCII and full-width colons and commas, because the site is
 * Chinese and an owner will type what their keyboard produces.
 *
 * @param string $text Raw textarea.
 * @return array
 */
function aurora_shop_parse_options( $text ) {
	$groups = array();

	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$line = trim( $line );

		if ( '' === $line ) {
			continue;
		}

		$parts = preg_split( '/[:：]/u', $line, 2 );

		if ( count( $parts ) < 2 ) {
			continue;
		}

		$name   = trim( $parts[0] );
		$values = array_filter( array_map( 'trim', preg_split( '/[,，]/u', $parts[1] ) ), 'strlen' );
		$values = array_values( array_unique( $values ) );

		if ( '' === $name || ! $values ) {
			continue;
		}

		$groups[] = array(
			'name'   => $name,
			'values' => $values,
		);
	}

	return $groups;
}

/**
 * Save the product fields.
 *
 * @param int $post_id Product ID.
 */
function aurora_shop_save_metabox( $post_id ) {
	if ( ! isset( $_POST['aurora_product_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['aurora_product_nonce'] ) ), 'aurora_save_product' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	update_post_meta( $post_id, '_aurora_price', round( (float) ( $_POST['aurora_price'] ?? 0 ), 2 ) );
	update_post_meta( $post_id, '_aurora_compare_price', round( (float) ( $_POST['aurora_compare_price'] ?? 0 ), 2 ) );
	update_post_meta( $post_id, '_aurora_sku', sanitize_text_field( wp_unslash( $_POST['aurora_sku'] ?? '' ) ) );
	update_post_meta( $post_id, '_aurora_limit_per_user', max( 0, (int) ( $_POST['aurora_limit'] ?? 0 ) ) );

	$kind = sanitize_key( wp_unslash( $_POST['aurora_kind'] ?? 'virtual' ) );
	update_post_meta( $post_id, '_aurora_kind', isset( aurora_product_kinds()[ $kind ] ) ? $kind : 'virtual' );

	$managed = ! empty( $_POST['aurora_stock_managed'] );
	update_post_meta( $post_id, '_aurora_stock_managed', $managed ? 1 : 0 );

	$groups = aurora_shop_parse_options( wp_unslash( $_POST['aurora_options'] ?? '' ) );
	update_post_meta( $post_id, '_aurora_options', $groups );

	$rows    = array();
	$keys    = isset( $_POST['aurora_variant_key'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['aurora_variant_key'] ) ) : array();
	$options = isset( $_POST['aurora_variant_option'] ) ? (array) wp_unslash( $_POST['aurora_variant_option'] ) : array();

	foreach ( $keys as $key ) {
		$picked = array();

		foreach ( (array) ( $options[ $key ] ?? array() ) as $group_name => $value ) {
			$picked[ sanitize_text_field( $group_name ) ] = sanitize_text_field( $value );
		}

		if ( ! $picked ) {
			continue;
		}

		$price_raw = isset( $_POST['aurora_variant_price'][ $key ] ) ? trim( (string) wp_unslash( $_POST['aurora_variant_price'][ $key ] ) ) : '';

		$rows[] = array(
			'options' => $picked,
			'price'   => '' === $price_raw ? '' : round( (float) $price_raw, 2 ),
			'sku'     => sanitize_text_field( wp_unslash( $_POST['aurora_variant_sku'][ $key ] ?? '' ) ),
			'enabled' => ! empty( $_POST['aurora_variant_enabled'][ $key ] ),
		);

		// Stock lives in its own scalar row per variant so it can be claimed
		// atomically; it is written here, not inside the serialised row.
		if ( $managed && isset( $_POST['aurora_variant_stock'][ $key ] ) ) {
			aurora_product_set_stock( $post_id, $key, (int) $_POST['aurora_variant_stock'][ $key ] );
		}
	}

	update_post_meta( $post_id, '_aurora_variants', $rows );

	if ( ! $rows ) {
		update_post_meta( $post_id, '_aurora_stock', max( 0, (int) ( $_POST['aurora_stock'] ?? 0 ) ) );
	}

	do_action( 'aurora_product_saved', $post_id );
}
add_action( 'save_post_aurora_product', 'aurora_shop_save_metabox' );

/**
 * Show the price and stock in the product list.
 *
 * @param array $columns Columns.
 * @return array
 */
function aurora_shop_columns( $columns ) {
	$new = array();

	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;

		if ( 'title' === $key ) {
			$new['aurora_price'] = __( '价格', 'aurora' );
			$new['aurora_stock'] = __( '库存', 'aurora' );
			$new['aurora_sold']  = __( '已售', 'aurora' );
		}
	}

	return $new;
}
add_filter( 'manage_aurora_product_posts_columns', 'aurora_shop_columns' );

/**
 * Fill the product list columns.
 *
 * @param string $column  Column key.
 * @param int    $post_id Product ID.
 */
function aurora_shop_column_content( $column, $post_id ) {
	if ( 'aurora_price' === $column ) {
		$meta = aurora_product_meta( $post_id );
		echo esc_html( '¥' . number_format( $meta['price'], 2 ) );

		if ( $meta['variants'] ) {
			printf( ' <span class="description">(%s)</span>', esc_html( sprintf( __( '%d 个规格', 'aurora' ), count( $meta['variants'] ) ) ) );
		}

		return;
	}

	if ( 'aurora_stock' === $column ) {
		if ( ! aurora_product_stock_managed( $post_id ) ) {
			esc_html_e( '不限', 'aurora' );

			return;
		}

		$meta   = aurora_product_meta( $post_id );
		$stocks = array();

		if ( $meta['variants'] ) {
			foreach ( array_keys( $meta['variants'] ) as $key ) {
				$stocks[] = (int) aurora_product_stock( $post_id, $key );
			}

			$total = array_sum( $stocks );
			printf( '%s', esc_html( (string) $total ) );
			printf( ' <span class="description">(%s)</span>', esc_html( implode( '/', $stocks ) ) );

			return;
		}

		echo esc_html( (string) aurora_product_stock( $post_id ) );

		return;
	}

	if ( 'aurora_sold' === $column ) {
		echo esc_html( (string) aurora_product_sold_qty( $post_id ) );
	}
}
add_action( 'manage_aurora_product_posts_custom_column', 'aurora_shop_column_content', 10, 2 );

/**
 * Units of a product sold across paid orders.
 *
 * @param int $product_id Product ID.
 * @return int
 */
function aurora_product_sold_qty( $product_id ) {
	global $wpdb;

	$total = 0;

	$orders = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->postmeta} s ON s.post_id = pm.post_id AND s.meta_key = '_aurora_status' AND s.meta_value = 'paid'
			 WHERE pm.meta_key = '_aurora_lines' AND pm.meta_value LIKE %s
			 LIMIT 2000",
			'%' . $wpdb->esc_like( '"product";i:' . (int) $product_id . ';' ) . '%'
		)
	);

	foreach ( $orders as $order_id ) {
		foreach ( aurora_order_lines( $order_id ) as $line ) {
			if ( (int) $line['product'] === (int) $product_id ) {
				$total += (int) $line['qty'];
			}
		}
	}

	return $total;
}

/**
 * Register the shop settings screen.
 */
function aurora_shop_admin_menu() {
	add_submenu_page(
		'aurora-settings',
		__( '商城设置', 'aurora' ),
		__( '商城设置', 'aurora' ),
		'manage_options',
		'aurora-shop',
		'aurora_shop_admin_page'
	);
}
add_action( 'admin_menu', 'aurora_shop_admin_menu' );

/**
 * The shop settings screen: expiry policy, plus what the shop currently holds.
 */
function aurora_shop_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '权限不足。', 'aurora' ), 403 );
	}

	$ttl = aurora_order_ttl_minutes();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( '商城设置', 'aurora' ); ?></h1>

		<?php if ( isset( $_GET['aurora_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '商城设置已保存。', 'aurora' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aurora_save_shop">
			<?php wp_nonce_field( 'aurora_save_shop' ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="aurora_order_ttl"><?php esc_html_e( '未支付订单保留', 'aurora' ); ?></label></th>
						<td>
							<input type="number" min="5" max="1440" step="5" id="aurora_order_ttl" name="aurora_order_ttl"
								class="small-text" value="<?php echo esc_attr( $ttl ); ?>">
							<?php esc_html_e( '分钟', 'aurora' ); ?>
							<p class="description">
								<?php esc_html_e( '超过时限未支付的订单自动取消，并把它占用的库存归还。由系统定时任务每 10 分钟检查一次。', 'aurora' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( '商城概况', 'aurora' ); ?></h2>
		<?php
		$products = wp_count_posts( 'aurora_product' );
		$pending  = aurora_shop_count_orders( 'pending' );
		$paid     = aurora_shop_count_orders( 'paid' );
		?>
		<table class="widefat striped" style="max-width:640px">
			<tbody>
				<tr>
					<td><?php esc_html_e( '已上架商品', 'aurora' ); ?></td>
					<td><strong><?php echo esc_html( (string) (int) ( $products->publish ?? 0 ) ); ?></strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( '待支付订单', 'aurora' ); ?></td>
					<td><strong><?php echo esc_html( (string) $pending ); ?></strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( '已支付订单', 'aurora' ); ?></td>
					<td><strong><?php echo esc_html( (string) $paid ); ?></strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( '库存管理中的商品', 'aurora' ); ?></td>
					<td><strong><?php echo esc_html( (string) aurora_shop_managed_count() ); ?></strong></td>
				</tr>
			</tbody>
		</table>

		<p class="description" style="margin-top:12px">
			<?php
			printf(
				/* translators: %s: wp-cli command */
				esc_html__( '手动清理超时订单：%s', 'aurora' ),
				'<code>wp aurora expire-orders</code>'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Orders in a given status.
 *
 * @param string $status Order status.
 * @return int
 */
function aurora_shop_count_orders( $status ) {
	$query = new WP_Query(
		array(
			'post_type'      => 'aurora_order',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_aurora_status',
					'value' => $status,
				),
			),
		)
	);

	return (int) $query->found_posts;
}

/**
 * How many products track stock.
 *
 * @return int
 */
function aurora_shop_managed_count() {
	$query = new WP_Query(
		array(
			'post_type'      => 'aurora_product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_aurora_stock_managed',
					'value' => '1',
				),
			),
		)
	);

	return (int) $query->found_posts;
}

/**
 * Save the shop settings.
 */
function aurora_shop_admin_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '权限不足。', 'aurora' ), 403 );
	}

	check_admin_referer( 'aurora_save_shop' );

	set_theme_mod( AURORA_PRODUCT_TTL_MOD, max( 5, min( 1440, (int) ( $_POST['aurora_order_ttl'] ?? 30 ) ) ) );

	wp_safe_redirect( add_query_arg( array( 'page' => 'aurora-shop', 'aurora_saved' => 1 ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_post_aurora_save_shop', 'aurora_shop_admin_save' );
