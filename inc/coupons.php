<?php
/**
 * Discount codes.
 *
 * A coupon is a private post of type `aurora_coupon` whose title is the code
 * itself. All validation and arithmetic happens server side: the browser only
 * ever sends a code string, never a price. The order stores the base amount,
 * the discount and the code that produced it, so a discount can always be
 * audited after the fact.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Model
 * ---------------------------------------------------------------------- */

/**
 * Register the coupon post type.
 */
function aurora_register_coupon_type() {
	register_post_type( 'aurora_coupon', array(
		'labels'          => array(
			'name'          => __( '优惠码', 'aurora' ),
			'singular_name' => __( '优惠码', 'aurora' ),
			'add_new_item'  => __( '新建优惠码', 'aurora' ),
			'edit_item'     => __( '编辑优惠码', 'aurora' ),
			'all_items'     => __( '所有优惠码', 'aurora' ),
			'search_items'  => __( '搜索优惠码', 'aurora' ),
		),
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => true,
		'menu_icon'       => 'dashicons-tag',
		'menu_position'   => 24,
		'capability_type' => 'post',
		'supports'        => array( 'title' ),
		'show_in_rest'    => false,
	) );
}
add_action( 'init', 'aurora_register_coupon_type' );

/**
 * Canonical form of a code: upper case, no spaces, ASCII-safe key.
 *
 * @param string $code Raw input.
 * @return string
 */
function aurora_coupon_normalize( $code ) {
	return strtoupper( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $code ) );
}

/**
 * Type choices.
 *
 * @return array
 */
function aurora_coupon_types() {
	return array(
		'percent' => __( '按比例打折', 'aurora' ),
		'fixed'   => __( '固定金额立减', 'aurora' ),
	);
}

/**
 * Read a coupon as a plain array, or null when the ID is not a coupon.
 *
 * @param int $coupon_id Coupon post ID.
 * @return array|null
 */
function aurora_get_coupon( $coupon_id ) {
	$post = get_post( $coupon_id );
	if ( ! $post || 'aurora_coupon' !== $post->post_type ) {
		return null;
	}

	$type  = get_post_meta( $coupon_id, '_aurora_coupon_type', true );
	$type  = isset( aurora_coupon_types()[ $type ] ) ? $type : 'percent';
	$scope = get_post_meta( $coupon_id, '_aurora_coupon_scope', true );
	$scope = in_array( $scope, array( 'all', 'vip', 'post' ), true ) ? $scope : 'all';

	return array(
		'id'         => (int) $coupon_id,
		'code'       => aurora_coupon_normalize( $post->post_title ),
		'type'       => $type,
		'value'      => (float) get_post_meta( $coupon_id, '_aurora_coupon_value', true ),
		'min'        => (float) get_post_meta( $coupon_id, '_aurora_coupon_min', true ),
		'scope'      => $scope,
		'max_uses'   => (int) get_post_meta( $coupon_id, '_aurora_coupon_max_uses', true ),
		'used'       => (int) get_post_meta( $coupon_id, '_aurora_coupon_used', true ),
		'per_user'   => (int) get_post_meta( $coupon_id, '_aurora_coupon_per_user', true ),
		'start'      => (string) get_post_meta( $coupon_id, '_aurora_coupon_start', true ),
		'end'        => (string) get_post_meta( $coupon_id, '_aurora_coupon_end', true ),
		'active'     => 'publish' === $post->post_status,
	);
}

/**
 * Look a coupon up by its code.
 *
 * @param string $code Code.
 * @return array|null
 */
function aurora_find_coupon( $code ) {
	$code = aurora_coupon_normalize( $code );
	if ( '' === $code ) {
		return null;
	}

	$found = get_posts( array(
		'post_type'        => 'aurora_coupon',
		'post_status'      => array( 'publish', 'draft' ),
		'title'            => $code,
		'posts_per_page'   => 1,
		'fields'           => 'ids',
		'suppress_filters' => false,
	) );

	if ( ! $found ) {
		return null;
	}

	return aurora_get_coupon( $found[0] );
}

/**
 * How many times a user has already redeemed a coupon.
 *
 * @param int $coupon_id Coupon ID.
 * @param int $user_id   User ID.
 * @return int
 */
function aurora_coupon_user_uses( $coupon_id, $user_id ) {
	$orders = get_posts( array(
		'post_type'      => 'aurora_order',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'author'         => (int) $user_id,
		'meta_query'     => array(
			array(
				'key'     => '_aurora_coupon_id',
				'value'   => (int) $coupon_id,
				'compare' => '=',
			),
			array(
				'key'     => '_aurora_status',
				'value'   => array( 'paid', 'refunded' ),
				'compare' => 'IN',
			),
		),
	) );

	return count( $orders );
}

/**
 * Compute the discount a coupon yields against an amount.
 *
 * @param array $coupon Coupon array from aurora_get_coupon().
 * @param float $amount Base amount.
 * @return float
 */
function aurora_coupon_discount( $coupon, $amount ) {
	$amount = max( 0, (float) $amount );

	if ( 'percent' === $coupon['type'] ) {
		$percent = max( 0, min( 100, $coupon['value'] ) );
		$off     = $amount * ( $percent / 100 );
	} else {
		$off = max( 0, $coupon['value'] );
	}

	// Never exceed the order total, and never produce a negative charge.
	return round( min( $off, $amount ), 2 );
}

/**
 * Validate a code against a context and return the resulting discount.
 *
 * @param string $code      Raw code.
 * @param array  $context   amount, user_id, scope ('vip'|'post'), object_id.
 * @return array|WP_Error   id, code, discount, discount_label, free.
 */
function aurora_coupon_apply( $code, $context ) {
	$amount = isset( $context['amount'] ) ? (float) $context['amount'] : 0;
	$user   = isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id();
	$scope  = isset( $context['scope'] ) && 'post' === $context['scope'] ? 'post' : 'vip';

	$coupon = aurora_find_coupon( $code );

	if ( ! $coupon ) {
		return new WP_Error( 'aurora_coupon_missing', __( '优惠码不存在。', 'aurora' ) );
	}
	if ( ! $coupon['active'] ) {
		return new WP_Error( 'aurora_coupon_off', __( '该优惠码已停用。', 'aurora' ) );
	}

	$now = current_time( 'timestamp' );
	if ( $coupon['start'] && strtotime( $coupon['start'] . ' 00:00:00' ) > $now ) {
		return new WP_Error( 'aurora_coupon_early', __( '该优惠码还未到生效时间。', 'aurora' ) );
	}
	if ( $coupon['end'] && strtotime( $coupon['end'] . ' 23:59:59' ) < $now ) {
		return new WP_Error( 'aurora_coupon_expired', __( '该优惠码已过期。', 'aurora' ) );
	}
	if ( $coupon['max_uses'] > 0 && $coupon['used'] >= $coupon['max_uses'] ) {
		return new WP_Error( 'aurora_coupon_used_up', __( '该优惠码已被领完。', 'aurora' ) );
	}
	if ( 'all' !== $coupon['scope'] && $coupon['scope'] !== $scope ) {
		return new WP_Error( 'aurora_coupon_scope', __( '该优惠码不适用于此商品。', 'aurora' ) );
	}
	if ( $coupon['min'] > 0 && $amount < $coupon['min'] ) {
		return new WP_Error(
			'aurora_coupon_min',
			sprintf(
				/* translators: %s: minimum amount */
				__( '订单满 ¥%s 才能使用该优惠码。', 'aurora' ),
				number_format( $coupon['min'], 2 )
			)
		);
	}
	if ( $coupon['per_user'] > 0 && $user && aurora_coupon_user_uses( $coupon['id'], $user ) >= $coupon['per_user'] ) {
		return new WP_Error( 'aurora_coupon_per_user', __( '你已使用过该优惠码。', 'aurora' ) );
	}

	$discount = aurora_coupon_discount( $coupon, $amount );

	if ( 'percent' === $coupon['type'] ) {
		$label = sprintf( '%s%% OFF', rtrim( rtrim( number_format( $coupon['value'], 2 ), '0' ), '.' ) );
	} else {
		$label = '−¥' . number_format( $coupon['value'], 2 );
	}

	return array(
		'id'             => $coupon['id'],
		'code'           => $coupon['code'],
		'discount'       => $discount,
		'discount_label' => $label,
		'free'           => $discount >= $amount && $amount > 0,
	);
}

/**
 * Record a redemption: bump the counter and stamp the order.
 *
 * Called once the order is actually settled, not when it is created, so an
 * abandoned checkout does not burn a limited-use code.
 *
 * @param int $coupon_id Coupon ID.
 * @param int $order_id  Order post ID.
 */
function aurora_coupon_redeem( $coupon_id, $order_id ) {
	$coupon_id = (int) $coupon_id;
	$order_id  = (int) $order_id;

	if ( ! $coupon_id || ! $order_id ) {
		return;
	}
	if ( get_post_meta( $order_id, '_aurora_coupon_redeemed', true ) ) {
		return; // Already counted for this order.
	}

	update_post_meta( $order_id, '_aurora_coupon_redeemed', 1 );
	update_post_meta( $coupon_id, '_aurora_coupon_used', (int) get_post_meta( $coupon_id, '_aurora_coupon_used', true ) + 1 );

	do_action( 'aurora_coupon_redeemed', $coupon_id, $order_id );
}

/**
 * Count the redemption when the order actually settles.
 *
 * Wiring this to the payment event (rather than to order creation) means an
 * abandoned checkout does not consume a limited-use code, and the free-order
 * path in aurora_create_order() is covered by the same hook.
 *
 * @param int   $order_id Order post ID.
 * @param array $order    Order snapshot.
 */
function aurora_coupon_on_order_paid( $order_id, $order ) {
	$coupon_id = (int) get_post_meta( $order_id, '_aurora_coupon_id', true );

	if ( $coupon_id ) {
		aurora_coupon_redeem( $coupon_id, $order_id );
	}
}
add_action( 'aurora_order_paid', 'aurora_coupon_on_order_paid', 5, 2 );

/**
 * Give a redeemed code back (refund, cancellation).
 *
 * @param int $order_id Order post ID.
 */
function aurora_coupon_release( $order_id ) {
	$coupon_id = (int) get_post_meta( $order_id, '_aurora_coupon_id', true );

	if ( ! $coupon_id || ! get_post_meta( $order_id, '_aurora_coupon_redeemed', true ) ) {
		return;
	}

	update_post_meta( $coupon_id, '_aurora_coupon_used', max( 0, (int) get_post_meta( $coupon_id, '_aurora_coupon_used', true ) - 1 ) );
	delete_post_meta( $order_id, '_aurora_coupon_redeemed' );
}

/* -------------------------------------------------------------------------
 * Admin
 * ---------------------------------------------------------------------- */

/**
 * Coupon list columns.
 *
 * @param array $columns Columns.
 * @return array
 */
function aurora_coupon_columns( $columns ) {
	return array(
		'cb'            => $columns['cb'],
		'title'         => __( '优惠码', 'aurora' ),
		'aurora_kind'   => __( '优惠', 'aurora' ),
		'aurora_use'    => __( '已用 / 总量', 'aurora' ),
		'aurora_window' => __( '有效期', 'aurora' ),
		'aurora_scope'  => __( '适用范围', 'aurora' ),
		'aurora_state'  => __( '状态', 'aurora' ),
	);
}
add_filter( 'manage_aurora_coupon_posts_columns', 'aurora_coupon_columns' );

/**
 * Render coupon columns.
 *
 * @param string $column  Column key.
 * @param int    $post_id Coupon ID.
 */
function aurora_coupon_column_content( $column, $post_id ) {
	$c = aurora_get_coupon( $post_id );
	if ( ! $c ) {
		return;
	}

	switch ( $column ) {
		case 'aurora_kind':
			if ( 'percent' === $c['type'] ) {
				echo esc_html( sprintf( '%s%% OFF', rtrim( rtrim( number_format( $c['value'], 2 ), '0' ), '.' ) ) );
			} else {
				echo esc_html( '−¥' . number_format( $c['value'], 2 ) );
			}
			if ( $c['min'] > 0 ) {
				echo '<br><small>' . esc_html( sprintf( __( '满 ¥%s 可用', 'aurora' ), number_format( $c['min'], 2 ) ) ) . '</small>';
			}
			break;

		case 'aurora_use':
			$cap = $c['max_uses'] > 0 ? $c['max_uses'] : '∞';
			printf(
				'<strong>%s</strong> / %s',
				esc_html( $c['used'] ),
				esc_html( $cap )
			);
			if ( $c['per_user'] > 0 ) {
				echo '<br><small>' . esc_html( sprintf( __( '每人限 %d 次', 'aurora' ), $c['per_user'] ) ) . '</small>';
			}
			break;

		case 'aurora_window':
			if ( ! $c['start'] && ! $c['end'] ) {
				esc_html_e( '长期有效', 'aurora' );
				break;
			}
			echo esc_html( ( $c['start'] ?: '—' ) . ' → ' . ( $c['end'] ?: '—' ) );
			break;

		case 'aurora_scope':
			$scopes = array(
				'all'  => __( '全部商品', 'aurora' ),
				'vip'  => __( '仅会员', 'aurora' ),
				'post' => __( '仅单篇购买', 'aurora' ),
			);
			echo esc_html( $scopes[ $c['scope'] ] );
			break;

		case 'aurora_state':
			if ( ! $c['active'] ) {
				echo '<span style="color:#b32d2e">' . esc_html__( '已停用', 'aurora' ) . '</span>';
			} elseif ( $c['end'] && strtotime( $c['end'] . ' 23:59:59' ) < current_time( 'timestamp' ) ) {
				echo '<span style="color:#b32d2e">' . esc_html__( '已过期', 'aurora' ) . '</span>';
			} elseif ( $c['max_uses'] > 0 && $c['used'] >= $c['max_uses'] ) {
				echo '<span style="color:#b32d2e">' . esc_html__( '已领完', 'aurora' ) . '</span>';
			} else {
				echo '<span style="color:#1a7f37">' . esc_html__( '生效中', 'aurora' ) . '</span>';
			}
			break;
	}
}
add_action( 'manage_aurora_coupon_posts_custom_column', 'aurora_coupon_column_content', 10, 2 );

/**
 * Coupon edit meta box.
 */
function aurora_coupon_metabox() {
	add_meta_box(
		'aurora-coupon',
		__( 'Aurora · 优惠码设置', 'aurora' ),
		'aurora_coupon_metabox_html',
		'aurora_coupon',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'aurora_coupon_metabox' );

/**
 * Coupon meta box markup.
 *
 * @param WP_Post $post Coupon being edited.
 */
function aurora_coupon_metabox_html( $post ) {
	wp_nonce_field( 'aurora_save_coupon', 'aurora_coupon_nonce' );

	$c = aurora_get_coupon( $post->ID );
	if ( ! $c ) {
		return;
	}
	?>
	<style>
		.aurora-coupon-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:14px; }
		.aurora-coupon-grid label { display:block; font-weight:600; margin-bottom:4px; }
		.aurora-coupon-grid input[type=text],
		.aurora-coupon-grid input[type=number],
		.aurora-coupon-grid input[type=date],
		.aurora-coupon-grid select { width:100%; }
		.aurora-coupon-grid .desc { color:#646970; font-size:12px; margin-top:3px; }
	</style>
	<div class="aurora-coupon-grid">
		<p>
			<label for="aurora_coupon_type"><?php esc_html_e( '优惠方式', 'aurora' ); ?></label>
			<select name="aurora_coupon_type" id="aurora_coupon_type">
				<?php foreach ( aurora_coupon_types() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $c['type'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="aurora_coupon_value"><?php esc_html_e( '优惠数值', 'aurora' ); ?></label>
			<input type="number" step="0.01" min="0" name="aurora_coupon_value" id="aurora_coupon_value" value="<?php echo esc_attr( $c['value'] ); ?>">
			<span class="desc"><?php esc_html_e( '按比例填 1–100（百分比）；固定金额填 ¥ 数值。', 'aurora' ); ?></span>
		</p>
		<p>
			<label for="aurora_coupon_min"><?php esc_html_e( '最低订单金额', 'aurora' ); ?></label>
			<input type="number" step="0.01" min="0" name="aurora_coupon_min" id="aurora_coupon_min" value="<?php echo esc_attr( $c['min'] ); ?>">
			<span class="desc"><?php esc_html_e( '0 表示不限制。', 'aurora' ); ?></span>
		</p>
		<p>
			<label for="aurora_coupon_scope"><?php esc_html_e( '适用范围', 'aurora' ); ?></label>
			<select name="aurora_coupon_scope" id="aurora_coupon_scope">
				<option value="all" <?php selected( $c['scope'], 'all' ); ?>><?php esc_html_e( '全部商品', 'aurora' ); ?></option>
				<option value="vip" <?php selected( $c['scope'], 'vip' ); ?>><?php esc_html_e( '仅会员开通', 'aurora' ); ?></option>
				<option value="post" <?php selected( $c['scope'], 'post' ); ?>><?php esc_html_e( '仅单篇购买', 'aurora' ); ?></option>
			</select>
		</p>
		<p>
			<label for="aurora_coupon_max_uses"><?php esc_html_e( '总可用次数', 'aurora' ); ?></label>
			<input type="number" min="0" name="aurora_coupon_max_uses" id="aurora_coupon_max_uses" value="<?php echo esc_attr( $c['max_uses'] ); ?>">
			<span class="desc"><?php echo esc_html( sprintf( __( '0 = 不限。已用 %d 次。', 'aurora' ), $c['used'] ) ); ?></span>
		</p>
		<p>
			<label for="aurora_coupon_per_user"><?php esc_html_e( '每人限用', 'aurora' ); ?></label>
			<input type="number" min="0" name="aurora_coupon_per_user" id="aurora_coupon_per_user" value="<?php echo esc_attr( $c['per_user'] ); ?>">
			<span class="desc"><?php esc_html_e( '0 = 不限。', 'aurora' ); ?></span>
		</p>
		<p>
			<label for="aurora_coupon_start"><?php esc_html_e( '开始日期', 'aurora' ); ?></label>
			<input type="date" name="aurora_coupon_start" id="aurora_coupon_start" value="<?php echo esc_attr( $c['start'] ); ?>">
		</p>
		<p>
			<label for="aurora_coupon_end"><?php esc_html_e( '结束日期', 'aurora' ); ?></label>
			<input type="date" name="aurora_coupon_end" id="aurora_coupon_end" value="<?php echo esc_attr( $c['end'] ); ?>">
		</p>
	</div>
	<p class="desc"><?php esc_html_e( '发布状态即为启用状态：草稿 = 停用。优惠码不区分大小写。', 'aurora' ); ?></p>
	<?php
}

/**
 * Save coupon meta.
 *
 * @param int $post_id Coupon ID.
 */
function aurora_save_coupon_metabox( $post_id ) {
	if ( ! isset( $_POST['aurora_coupon_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['aurora_coupon_nonce'] ), 'aurora_save_coupon' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	$type = isset( $_POST['aurora_coupon_type'] ) ? sanitize_key( $_POST['aurora_coupon_type'] ) : 'percent';
	if ( ! isset( aurora_coupon_types()[ $type ] ) ) {
		$type = 'percent';
	}
	$value = isset( $_POST['aurora_coupon_value'] ) ? (float) $_POST['aurora_coupon_value'] : 0;
	$value = max( 0, $value );
	if ( 'percent' === $type ) {
		$value = min( 100, $value );
	}

	$scope = isset( $_POST['aurora_coupon_scope'] ) ? sanitize_key( $_POST['aurora_coupon_scope'] ) : 'all';
	if ( ! in_array( $scope, array( 'all', 'vip', 'post' ), true ) ) {
		$scope = 'all';
	}

	$date = static function ( $key ) {
		$raw = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
	};

	update_post_meta( $post_id, '_aurora_coupon_type', $type );
	update_post_meta( $post_id, '_aurora_coupon_value', $value );
	update_post_meta( $post_id, '_aurora_coupon_min', max( 0, (float) ( $_POST['aurora_coupon_min'] ?? 0 ) ) );
	update_post_meta( $post_id, '_aurora_coupon_scope', $scope );
	update_post_meta( $post_id, '_aurora_coupon_max_uses', max( 0, (int) ( $_POST['aurora_coupon_max_uses'] ?? 0 ) ) );
	update_post_meta( $post_id, '_aurora_coupon_per_user', max( 0, (int) ( $_POST['aurora_coupon_per_user'] ?? 0 ) ) );
	update_post_meta( $post_id, '_aurora_coupon_start', $date( 'aurora_coupon_start' ) );
	update_post_meta( $post_id, '_aurora_coupon_end', $date( 'aurora_coupon_end' ) );

	// The code lives in the title; keep it canonical.
	$code = aurora_coupon_normalize( get_the_title( $post_id ) );
	if ( $code && $code !== get_the_title( $post_id ) ) {
		remove_action( 'save_post_aurora_coupon', 'aurora_save_coupon_metabox' );
		wp_update_post( array( 'ID' => $post_id, 'post_title' => $code ) );
		add_action( 'save_post_aurora_coupon', 'aurora_save_coupon_metabox' );
	}
}
add_action( 'save_post_aurora_coupon', 'aurora_save_coupon_metabox' );

/**
 * Warn when a duplicate code exists: lookups take the first match, so a
 * duplicate would make a code's behaviour ambiguous.
 *
 * @param int $post_id Coupon ID.
 */
function aurora_coupon_duplicate_warning( $post_id ) {
	$code = aurora_coupon_normalize( get_the_title( $post_id ) );
	if ( ! $code ) {
		return;
	}

	$others = get_posts( array(
		'post_type'      => 'aurora_coupon',
		'post_status'    => array( 'publish', 'draft' ),
		'title'          => $code,
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'exclude'        => array( $post_id ),
	) );

	if ( $others ) {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( sprintf( __( '优惠码 %s 已存在（另有 %d 条）。重复的码只会匹配到其中一条，建议改成唯一值。', 'aurora' ), $code, count( $others ) ) )
		);
	}
}
add_action( 'admin_notices', 'aurora_coupon_duplicate_warning' );

/* -------------------------------------------------------------------------
 * Front-end: live validation while the user types
 * ---------------------------------------------------------------------- */

/**
 * AJAX: validate a coupon for the current cart context.
 */
function aurora_ajax_coupon() {
	check_ajax_referer( 'aurora_nonce', 'nonce' );

	// Unauthenticated on purpose (guests may try a code), which is exactly why
	// it needs a ceiling: guessing codes is a pure brute-force target.
	aurora_rate_json_guard( 'coupon' );

	$code  = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
	$scope = ( isset( $_POST['scope'] ) && 'post' === $_POST['scope'] ) ? 'post' : 'vip';

	// Recompute the base amount server side; do not trust a posted price.
	$amount = 0;
	if ( 'post' === $scope ) {
		$object_id = isset( $_POST['object_id'] ) ? (int) $_POST['object_id'] : 0;
		$amount    = $object_id ? aurora_post_price( $object_id ) : 0;
	} else {
		$level  = isset( $_POST['level'] ) ? (int) $_POST['level'] : 0;
		$days   = isset( $_POST['days'] ) ? max( 1, (int) $_POST['days'] ) : 30;
		$info   = aurora_get_vip_level( $level );
		$amount = $info ? round( (float) $info['price'] * ( $days / 30 ), 2 ) : 0;
	}

	$result = aurora_coupon_apply( $code, array(
		'amount'    => $amount,
		'user_id'   => get_current_user_id(),
		'scope'     => $scope,
		'object_id' => isset( $_POST['object_id'] ) ? (int) $_POST['object_id'] : 0,
	) );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( array(
		'code'     => $result['code'],
		'label'    => $result['discount_label'],
		'discount' => $result['discount'],
		'base'     => $amount,
		'total'    => round( max( 0, $amount - $result['discount'] ), 2 ),
		'free'     => $result['free'],
		'message'  => $result['free']
			? __( '该优惠码可全额抵扣，下单后立即开通。', 'aurora' )
			: sprintf( __( '已抵扣 ¥%s', 'aurora' ), number_format( $result['discount'], 2 ) ),
	) );
}
add_action( 'wp_ajax_aurora_coupon', 'aurora_ajax_coupon' );
add_action( 'wp_ajax_nopriv_aurora_coupon', 'aurora_ajax_coupon' );
