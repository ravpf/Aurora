<?php
/**
 * Orders and payment gateway abstraction.
 *
 * The theme ships the order ledger, the checkout UI and a gateway registry.
 * Real money movement needs merchant credentials; the built-in "manual"
 * gateway records an intent and lets an administrator confirm it. Gateways
 * are registered through the `aurora_payment_gateways` filter.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enabled gateway definitions.
 *
 * @return array
 */
function aurora_payment_gateways() {
	$gateways = array(
		'manual' => array(
			'id'      => 'manual',
			'title'   => __( '转账 / 人工确认', 'aurora' ),
			'desc'    => __( '提交订单后按页面提示完成转账，管理员确认后开通会员。', 'aurora' ),
			'enabled' => (bool) get_theme_mod( 'aurora_manual_pay', true ),
			'ready'   => true,
		),
		'wechat' => array(
			'id'      => 'wechat',
			'title'   => __( '微信扫码支付', 'aurora' ),
			'desc'    => __( '微信支付 APIv3 原生扫码。需要在「Aurora 主题 → 微信支付」中填入商户信息。', 'aurora' ),
			'enabled' => (bool) get_theme_mod( 'aurora_wechat_pay', false ),
			'ready'   => function_exists( 'aurora_wxpay_configured' ) && aurora_wxpay_configured(),
		),
		'alipay' => array(
			'id'      => 'alipay',
			'title'   => __( '支付宝', 'aurora' ),
			'desc'    => __( '需要应用 AppID 与商户私钥。请在下方填入后启用。', 'aurora' ),
			'enabled' => (bool) get_theme_mod( 'aurora_alipay', false ),
			'ready'   => (bool) ( get_theme_mod( 'aurora_alipay_appid', '' ) && get_theme_mod( 'aurora_alipay_key', '' ) ),
		),
	);

	return apply_filters( 'aurora_payment_gateways', $gateways );
}

/**
 * Gateways that are both switched on and fully configured.
 *
 * @return array
 */
function aurora_available_gateways() {
	return array_filter( aurora_payment_gateways(), function ( $gw ) {
		return ! empty( $gw['enabled'] ) && ! empty( $gw['ready'] );
	} );
}

/**
 * Create a pending order and return its ID.
 *
 * @param array $args Order data.
 * @return int|WP_Error
 */
function aurora_create_order( $args ) {
	$defaults = array(
		'user_id'   => get_current_user_id(),
		'product'   => '',
		'level'     => 0,
		'days'      => 30,
		'amount'    => 0,
		'gateway'   => 'manual',
		'object_id' => 0,
	);
	$args = wp_parse_args( $args, $defaults );

	if ( ! $args['user_id'] ) {
		return new WP_Error( 'aurora_no_user', __( '请先登录后再下单。', 'aurora' ) );
	}

	$base = round( (float) $args['amount'], 2 );
	if ( $base <= 0 ) {
		return new WP_Error( 'aurora_bad_amount', __( '订单金额无效。', 'aurora' ) );
	}

	// The discount is resolved here, from the code alone: a caller can never
	// hand in its own price, so a tampered form cannot change what is charged.
	$discount    = 0.0;
	$coupon_id   = 0;
	$coupon_code = '';

	if ( ! empty( $args['coupon'] ) && function_exists( 'aurora_coupon_apply' ) ) {
		$applied = aurora_coupon_apply( $args['coupon'], array(
			'amount'    => $base,
			'user_id'   => (int) $args['user_id'],
			'scope'     => (int) $args['object_id'] ? 'post' : 'vip',
			'object_id' => (int) $args['object_id'],
		) );

		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$discount    = (float) $applied['discount'];
		$coupon_id   = (int) $applied['id'];
		$coupon_code = (string) $applied['code'];
	}

	$amount = round( max( 0, $base - $discount ), 2 );
	$free   = $amount <= 0;

	$order_no = aurora_generate_order_no();
	$order_id = wp_insert_post( array(
		'post_type'   => 'aurora_order',
		'post_status' => 'publish',
		'post_title'  => $order_no,
		'post_author' => (int) $args['user_id'],
		'meta_input'  => array(
			'_aurora_order_no'        => $order_no,
			'_aurora_user_id'         => (int) $args['user_id'],
			'_aurora_product'         => sanitize_text_field( $args['product'] ),
			'_aurora_level'           => (int) $args['level'],
			'_aurora_days'            => (int) $args['days'],
			'_aurora_amount'          => $amount,
			'_aurora_original_amount' => $base,
			'_aurora_discount'        => $discount,
			'_aurora_coupon'          => $coupon_code,
			'_aurora_coupon_id'       => $coupon_id,
			'_aurora_gateway'         => sanitize_key( $args['gateway'] ),
			'_aurora_status'          => 'pending',
			'_aurora_object'          => (int) $args['object_id'],
		),
	), true );

	if ( is_wp_error( $order_id ) ) {
		return $order_id;
	}

	do_action( 'aurora_order_created', $order_id, $args );

	// A 100%-off code needs no payment step: settle it on the spot so the user
	// is not left staring at a checkout for ¥0.00.
	if ( $free ) {
		aurora_redeem_and_settle_free_order( $order_id );
	}

	return $order_id;
}

/**
 * Complete an order whose coupon covered the whole price.
 *
 * @param int $order_id Order post ID.
 */
function aurora_redeem_and_settle_free_order( $order_id ) {
	// aurora_mark_order_paid() fires aurora_order_paid, which is what counts the
	// coupon redemption.
	aurora_mark_order_paid( $order_id, 'coupon-free' );
}

/**
 * Sequential, human-readable order number.
 *
 * @return string
 */
function aurora_generate_order_no() {
	return 'A' . gmdate( 'Ymd' ) . strtoupper( wp_generate_password( 8, false, false ) );
}

/**
 * Read an order's meta as one array.
 *
 * @param int $order_id Order post ID.
 * @return array
 */
function aurora_get_order( $order_id ) {
	return array(
		'id'      => (int) $order_id,
		'no'      => get_post_meta( $order_id, '_aurora_order_no', true ),
		'user_id' => (int) get_post_meta( $order_id, '_aurora_user_id', true ),
		'product' => get_post_meta( $order_id, '_aurora_product', true ),
		'level'   => (int) get_post_meta( $order_id, '_aurora_level', true ),
		'days'    => (int) get_post_meta( $order_id, '_aurora_days', true ),
		'amount'  => (float) get_post_meta( $order_id, '_aurora_amount', true ),
		'gateway' => get_post_meta( $order_id, '_aurora_gateway', true ),
		'status'   => get_post_meta( $order_id, '_aurora_status', true ),
		'created'  => get_the_date( 'Y-m-d H:i', $order_id ),
		'object'   => (int) get_post_meta( $order_id, '_aurora_object', true ),
		'original' => (float) get_post_meta( $order_id, '_aurora_original_amount', true ),
		'discount' => (float) get_post_meta( $order_id, '_aurora_discount', true ),
		'coupon'   => (string) get_post_meta( $order_id, '_aurora_coupon', true ),
		'txn'      => (string) get_post_meta( $order_id, '_aurora_txn', true ),
	);
}

/**
 * Mark an order paid and grant the attached entitlement. Idempotent.
 *
 * @param int    $order_id  Order post ID.
 * @param string $reference Optional external transaction reference.
 * @return bool
 */
function aurora_mark_order_paid( $order_id, $reference = '' ) {
	$order = aurora_get_order( $order_id );

	if ( 'paid' === $order['status'] ) {
		return true;
	}
	if ( ! $order['no'] ) {
		return false;
	}

	update_post_meta( $order_id, '_aurora_status', 'paid' );
	update_post_meta( $order_id, '_aurora_paid_at', current_time( 'mysql' ) );
	if ( $reference ) {
		update_post_meta( $order_id, '_aurora_txn', sanitize_text_field( $reference ) );
	}

	if ( $order['level'] > 0 && $order['user_id'] ) {
		aurora_grant_vip( $order['user_id'], $order['level'], $order['days'] ? $order['days'] : 30, 'order:' . $order['no'] );
	}

	do_action( 'aurora_order_paid', $order_id, $order );

	return true;
}

/**
 * Orders belonging to a user.
 *
 * @param int $user_id User ID.
 * @param int $limit   Max rows.
 * @return WP_Query
 */
function aurora_user_orders( $user_id, $limit = 30 ) {
	return new WP_Query( array(
		'post_type'      => 'aurora_order',
		'posts_per_page' => $limit,
		'author'         => (int) $user_id,
		'post_status'    => 'publish',
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	) );
}

/* -------------------------------------------------------------------------
 * Admin: order columns and manual confirmation
 * ---------------------------------------------------------------------- */

/**
 * Order list columns.
 *
 * @param array $columns Columns.
 * @return array
 */
function aurora_order_columns( $columns ) {
	return array(
		'cb'              => $columns['cb'],
		'title'           => __( '订单号', 'aurora' ),
		'aurora_user'     => __( '用户', 'aurora' ),
		'aurora_product'  => __( '商品', 'aurora' ),
		'aurora_amount'   => __( '金额', 'aurora' ),
		'aurora_gateway'  => __( '支付方式', 'aurora' ),
		'aurora_status'   => __( '状态', 'aurora' ),
		'date'            => __( '创建时间', 'aurora' ),
	);
}
add_filter( 'manage_aurora_order_posts_columns', 'aurora_order_columns' );

/**
 * Render order columns.
 *
 * @param string $column  Column key.
 * @param int    $post_id Post ID.
 */
function aurora_order_column_content( $column, $post_id ) {
	$order = aurora_get_order( $post_id );

	switch ( $column ) {
		case 'aurora_user':
			$user = get_userdata( $order['user_id'] );
			echo $user ? esc_html( $user->display_name ) : '—';
			break;
		case 'aurora_product':
			echo esc_html( $order['product'] );
			if ( $order['level'] > 0 ) {
				$info = aurora_get_vip_level( $order['level'] );
				echo '<br><small>' . esc_html( $info ? $info['name'] : '' ) . ' · ' . esc_html( $order['days'] ) . esc_html__( ' 天', 'aurora' ) . '</small>';
			}
			break;
		case 'aurora_amount':
			echo esc_html( '¥' . number_format( $order['amount'], 2 ) );
			break;
		case 'aurora_gateway':
			$gateways = aurora_payment_gateways();
			echo esc_html( isset( $gateways[ $order['gateway'] ] ) ? $gateways[ $order['gateway'] ]['title'] : $order['gateway'] );
			break;
		case 'aurora_status':
			$statuses = aurora_order_statuses();
			$status   = isset( $statuses[ $order['status'] ] ) ? $order['status'] : 'pending';
			printf(
				'<span class="aurora-status aurora-status--%1$s">%2$s</span>',
				esc_attr( $status ),
				esc_html( $statuses[ $status ] )
			);
			if ( 'pending' === $status ) {
				$url = wp_nonce_url( admin_url( 'post.php?post=' . $post_id . '&action=edit&aurora_confirm=1' ), 'aurora_confirm_' . $post_id );
				echo '<br><a class="button button-small" style="margin-top:6px" href="' . esc_url( $url ) . '">' . esc_html__( '标记为已支付', 'aurora' ) . '</a>';
			}
			break;
	}
}
add_action( 'manage_aurora_order_posts_custom_column', 'aurora_order_column_content', 10, 2 );

/**
 * Handle the admin "mark paid" action.
 */
function aurora_maybe_confirm_order() {
	if ( ! is_admin() || ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}
	if ( ! isset( $_GET['aurora_confirm'], $_GET['post'] ) ) {
		return;
	}
	$post_id = (int) $_GET['post'];
	check_admin_referer( 'aurora_confirm_' . $post_id );
	aurora_mark_order_paid( $post_id, 'admin-manual' );

	wp_safe_redirect( admin_url( 'edit.php?post_type=aurora_order&aurora_done=1' ) );
	exit;
}
add_action( 'admin_init', 'aurora_maybe_confirm_order' );

/**
 * Notice after a manual confirmation.
 */
function aurora_order_admin_notice() {
	if ( isset( $_GET['aurora_done'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '订单已标记为已支付，会员权益已发放。', 'aurora' ) . '</p></div>';
	}
}
add_action( 'admin_notices', 'aurora_order_admin_notice' );

/* -------------------------------------------------------------------------
 * Checkout endpoint
 * ---------------------------------------------------------------------- */

/**
 * Handle a "buy membership" submission with a nonce.
 */
function aurora_handle_checkout() {
	if ( ! isset( $_POST['aurora_checkout_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_key( $_POST['aurora_checkout_nonce'] ), 'aurora_checkout' ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( get_permalink() ) );
		exit;
	}

	// A product purchase posts a product id rather than a membership level. That
	// flow belongs to the shop module, so hand over instead of teaching this
	// function about two shapes of order. Every path out of the shop handler
	// redirects and exits.
	if ( isset( $_POST['aurora_product_id'] ) && function_exists( 'aurora_shop_handle_checkout' ) ) {
		aurora_shop_handle_checkout();
	}

	$level = isset( $_POST['level'] ) ? (int) $_POST['level'] : 0;
	$gw    = isset( $_POST['gateway'] ) ? sanitize_key( $_POST['gateway'] ) : 'manual';
	$info  = aurora_get_vip_level( $level );
	$ready = aurora_available_gateways();

	if ( ! $info || $level < 1 || ! isset( $ready[ $gw ] ) ) {
		wp_safe_redirect( add_query_arg( 'aurora_msg', 'invalid', aurora_vip_page_url() ) );
		exit;
	}

	$days = isset( $_POST['days'] ) ? max( 1, (int) $_POST['days'] ) : 30;
	$rate = $days / 30;
	$amount = round( (float) $info['price'] * $rate, 2 );
	$coupon = isset( $_POST['coupon'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon'] ) ) : '';

	$order_id = aurora_create_order( array(
		'product' => $info['name'],
		'level'   => $level,
		'days'    => $days,
		'amount'  => $amount,
		'gateway' => $gw,
		'coupon'  => $coupon,
	) );

	if ( is_wp_error( $order_id ) ) {
		// Surface the real reason (a rejected coupon code, for instance).
		wp_safe_redirect( add_query_arg(
			'aurora_msg',
			rawurlencode( $order_id->get_error_message() ),
			aurora_vip_page_url()
		) );
		exit;
	}

	do_action( 'aurora_checkout_created', $order_id, $gw );

	wp_safe_redirect( add_query_arg(
		array( 'order' => $order_id ),
		aurora_user_page_url()
	) );
	exit;
}
add_action( 'template_redirect', 'aurora_handle_checkout' );

/**
 * Render the "your pending order" instructions block.
 *
 * @param int $order_id Order post ID.
 */
function aurora_render_order_payment( $order_id ) {
	$order = aurora_get_order( $order_id );
	if ( 'pending' !== $order['status'] ) {
		return;
	}
	$gateways = aurora_payment_gateways();
	?>
	<div class="aurora-panel aurora-order-pay">
		<h3 class="aurora-panel__title"><?php aurora_the_icon( 'clock', 18 ); ?><?php esc_html_e( '待支付订单', 'aurora' ); ?></h3>
		<table class="aurora-table">
			<tbody>
				<tr><th><?php esc_html_e( '订单号', 'aurora' ); ?></th><td><code><?php echo esc_html( $order['no'] ); ?></code></td></tr>
				<tr><th><?php esc_html_e( '商品', 'aurora' ); ?></th><td><?php echo esc_html( $order['product'] ); ?></td></tr>
				<tr><th><?php esc_html_e( '金额', 'aurora' ); ?></th><td><strong>¥<?php echo esc_html( number_format( $order['amount'], 2 ) ); ?></strong></td></tr>
				<tr><th><?php esc_html_e( '支付方式', 'aurora' ); ?></th><td><?php echo esc_html( isset( $gateways[ $order['gateway'] ] ) ? $gateways[ $order['gateway'] ]['title'] : $order['gateway'] ); ?></td></tr>
			</tbody>
		</table>
		<?php do_action( 'aurora_order_payment_ui', $order ); ?>
		<p class="aurora-panel__hint" style="margin-top:14px;font-size:.86rem;color:var(--aurora-text-secondary)">
			<?php esc_html_e( '完成支付后请等待管理员确认，权益会自动发放到你的账户。', 'aurora' ); ?>
		</p>
	</div>
	<?php
}
