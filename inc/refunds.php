<?php
/**
 * Refunds.
 *
 * A refund is its own record (`aurora_refund`) rather than a flag on the order,
 * so partial refunds, retries and audit history all have somewhere to live. The
 * amount arithmetic is enforced server side: the sum of every non-failed refund
 * on an order can never exceed what was actually paid.
 *
 * Settling a refund also rolls back the entitlement the order granted, because
 * otherwise a user could pay, receive the benefit, then claim the money back.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Model
 * ---------------------------------------------------------------------- */

/**
 * Register the refund ledger.
 */
function aurora_register_refund_type() {
	register_post_type( 'aurora_refund', array(
		'labels'          => array(
			'name'          => __( '退款', 'aurora' ),
			'singular_name' => __( '退款', 'aurora' ),
			'all_items'     => __( '所有退款', 'aurora' ),
		),
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => true,
		'menu_icon'       => 'dashicons-undo',
		'menu_position'   => 25,
		'capability_type' => 'post',
		'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap'    => true,
		'supports'        => array( 'title' ),
		'show_in_rest'    => false,
	) );
}
add_action( 'init', 'aurora_register_refund_type' );

/**
 * Refund states.
 *
 * @return array
 */
function aurora_refund_statuses() {
	return array(
		'pending'  => __( '退款中', 'aurora' ),
		'success'  => __( '已退款', 'aurora' ),
		'failed'   => __( '退款失败', 'aurora' ),
		'closed'   => __( '已关闭', 'aurora' ),
	);
}

/**
 * Read a refund record.
 *
 * @param int $refund_id Refund post ID.
 * @return array|null
 */
function aurora_get_refund( $refund_id ) {
	$post = get_post( $refund_id );
	if ( ! $post || 'aurora_refund' !== $post->post_type ) {
		return null;
	}

	return array(
		'id'       => (int) $refund_id,
		'no'       => (string) get_post_meta( $refund_id, '_aurora_refund_no', true ),
		'order'    => (int) get_post_meta( $refund_id, '_aurora_refund_order', true ),
		'amount'   => (float) get_post_meta( $refund_id, '_aurora_refund_amount', true ),
		'status'   => (string) get_post_meta( $refund_id, '_aurora_refund_status', true ),
		'reason'   => (string) get_post_meta( $refund_id, '_aurora_refund_reason', true ),
		'gateway'  => (string) get_post_meta( $refund_id, '_aurora_refund_gateway', true ),
		'txn'      => (string) get_post_meta( $refund_id, '_aurora_refund_txn', true ),
		'operator' => (int) get_post_meta( $refund_id, '_aurora_refund_operator', true ),
		'created'  => get_the_date( 'Y-m-d H:i', $refund_id ),
	);
}

/**
 * A new, unique refund number.
 *
 * @return string
 */
function aurora_generate_refund_no() {
	return 'R' . gmdate( 'Ymd' ) . strtoupper( wp_generate_password( 8, false, false ) );
}

/**
 * How much of an order has already been (or is being) refunded.
 *
 * Failed and closed attempts are excluded: they moved no money.
 *
 * @param int $order_id Order post ID.
 * @return float
 */
function aurora_order_refunded_total( $order_id ) {
	$refunds = get_posts( array(
		'post_type'      => 'aurora_refund',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			array(
				'key'   => '_aurora_refund_order',
				'value' => (int) $order_id,
			),
		),
	) );

	$total = 0.0;
	foreach ( $refunds as $id ) {
		$refund = aurora_get_refund( $id );
		if ( $refund && in_array( $refund['status'], array( 'success', 'pending' ), true ) ) {
			$total += $refund['amount'];
		}
	}

	return round( $total, 2 );
}

/**
 * How much of an order is still refundable.
 *
 * @param int $order_id Order post ID.
 * @return float
 */
function aurora_order_refundable( $order_id ) {
	$order = aurora_get_order( $order_id );
	if ( 'paid' !== $order['status'] && 'partial' !== $order['status'] ) {
		return 0.0;
	}

	return round( max( 0, $order['amount'] - aurora_order_refunded_total( $order_id ) ), 2 );
}

/* -------------------------------------------------------------------------
 * Creating and settling
 * ---------------------------------------------------------------------- */

/**
 * Issue a refund against an order.
 *
 * @param int    $order_id Order post ID.
 * @param float  $amount   Amount to refund in ¥. Pass null for the full remainder.
 * @param string $reason   Free-form reason.
 * @return int|WP_Error    Refund post ID.
 */
function aurora_create_refund( $order_id, $amount = null, $reason = '' ) {
	$order = aurora_get_order( $order_id );

	if ( ! $order['no'] ) {
		return new WP_Error( 'aurora_refund_order', __( '订单不存在。', 'aurora' ) );
	}

	$remaining = aurora_order_refundable( $order_id );
	if ( $remaining <= 0 ) {
		return new WP_Error( 'aurora_refund_none', __( '该订单没有可退金额（未支付、已全额退款或退款处理中）。', 'aurora' ) );
	}

	$amount = ( null === $amount ) ? $remaining : round( (float) $amount, 2 );

	if ( $amount <= 0 ) {
		return new WP_Error( 'aurora_refund_amount', __( '退款金额必须大于 0。', 'aurora' ) );
	}
	if ( $amount > $remaining + 0.001 ) {
		return new WP_Error(
			'aurora_refund_over',
			sprintf(
				/* translators: %s: remaining refundable amount */
				__( '退款金额超过可退余额（最多可退 ¥%s）。', 'aurora' ),
				number_format( $remaining, 2 )
			)
		);
	}

	$refund_no = aurora_generate_refund_no();
	$refund_id = wp_insert_post( array(
		'post_type'   => 'aurora_refund',
		'post_status' => 'publish',
		'post_title'  => $refund_no,
		'post_author' => get_current_user_id(),
		'meta_input'  => array(
			'_aurora_refund_no'       => $refund_no,
			'_aurora_refund_order'    => (int) $order_id,
			'_aurora_refund_amount'   => $amount,
			'_aurora_refund_status'   => 'pending',
			'_aurora_refund_reason'   => sanitize_text_field( $reason ),
			'_aurora_refund_gateway'  => $order['gateway'],
			'_aurora_refund_operator' => get_current_user_id(),
		),
	), true );

	if ( is_wp_error( $refund_id ) ) {
		return $refund_id;
	}

	do_action( 'aurora_refund_created', $refund_id, $order_id, $amount );

	$gateway = $order['gateway'];

	if ( 'wechat' === $gateway && function_exists( 'aurora_wxpay_refund' ) && aurora_wxpay_configured() ) {
		$result = aurora_wxpay_refund( $refund_id );

		if ( is_wp_error( $result ) ) {
			update_post_meta( $refund_id, '_aurora_refund_status', 'failed' );
			update_post_meta( $refund_id, '_aurora_refund_error', $result->get_error_message() );

			return new WP_Error(
				'aurora_refund_gateway',
				sprintf(
					/* translators: %s: gateway error */
					__( '微信退款请求失败：%s', 'aurora' ),
					$result->get_error_message()
				)
			);
		}

		// WeChat can answer with an immediate verdict, or PROCESSING and call back later.
		aurora_wxpay_apply_refund_result( $refund_id, $result );

		return $refund_id;
	}

	// Manual gateway (or a gateway with no refund API): settle immediately, the
	// money movement happens out of band and the admin is the source of truth.
	aurora_settle_refund( $refund_id, __( '人工退款', 'aurora' ) );

	return $refund_id;
}

/**
 * Mark a refund successful and roll back what the order granted. Idempotent.
 *
 * @param int    $refund_id Refund post ID.
 * @param string $reference External refund reference.
 * @return bool
 */
function aurora_settle_refund( $refund_id, $reference = '' ) {
	$refund = aurora_get_refund( $refund_id );

	if ( ! $refund || 'success' === $refund['status'] ) {
		return false;
	}

	update_post_meta( $refund_id, '_aurora_refund_status', 'success' );
	update_post_meta( $refund_id, '_aurora_refund_settled', current_time( 'mysql' ) );
	if ( $reference ) {
		update_post_meta( $refund_id, '_aurora_refund_txn', sanitize_text_field( $reference ) );
	}

	aurora_refresh_order_after_refund( $refund['order'] );

	do_action( 'aurora_refund_settled', $refund_id, $refund['order'] );

	return true;
}

/**
 * Recompute an order's status/refunded total and roll back entitlements when it
 * is now fully refunded.
 *
 * @param int $order_id Order post ID.
 */
function aurora_refresh_order_after_refund( $order_id ) {
	$order   = aurora_get_order( $order_id );
	$refunded = aurora_order_refunded_total( $order_id );

	update_post_meta( $order_id, '_aurora_refunded_amount', $refunded );

	$full = $refunded >= $order['amount'] - 0.001;

	if ( $full ) {
		update_post_meta( $order_id, '_aurora_status', 'refunded' );
		aurora_revoke_order_entitlement( $order_id );

		// A fully refunded order should not keep a redeemed code consumed.
		if ( function_exists( 'aurora_coupon_release' ) ) {
			aurora_coupon_release( $order_id );
		}
	} elseif ( $refunded > 0 ) {
		update_post_meta( $order_id, '_aurora_status', 'partial' );
	}
}

/**
 * Undo whatever an order granted.
 *
 * Guests are not the only case: monthly membership extends an existing expiry,
 * so the fair rollback is to subtract the days this order added rather than to
 * wipe the membership outright. Anything left in the past means level 0.
 *
 * @param int $order_id Order post ID.
 * @return bool Whether anything was rolled back.
 */
function aurora_revoke_order_entitlement( $order_id ) {
	if ( get_post_meta( $order_id, '_aurora_revoked', true ) ) {
		return false;
	}

	$order = aurora_get_order( $order_id );
	$did   = false;

	if ( $order['level'] > 0 && $order['user_id'] ) {
		$days    = $order['days'] > 0 ? $order['days'] : 30;
		$current = (string) get_user_meta( $order['user_id'], 'aurora_vip_expires', true );
		$base    = ( $current && strtotime( $current ) > time() ) ? strtotime( $current ) : time();
		$new     = $base - ( $days * DAY_IN_SECONDS );

		if ( $new <= time() ) {
			update_user_meta( $order['user_id'], 'aurora_vip_level', 0 );
			update_user_meta( $order['user_id'], 'aurora_vip_expires', '' );
		} else {
			update_user_meta( $order['user_id'], 'aurora_vip_expires', gmdate( 'Y-m-d H:i:s', $new ) );
		}

		update_user_meta( $order['user_id'], 'aurora_vip_revoked_note', 'order:' . $order['no'] );
		$did = true;

		do_action( 'aurora_vip_revoked', $order['user_id'], $order, $days );
	}

	$object_id = (int) get_post_meta( $order_id, '_aurora_object', true );
	if ( $object_id && $order['user_id'] && function_exists( 'aurora_revoke_post_access' ) ) {
		aurora_revoke_post_access( $order['user_id'], $object_id );
		$did = true;
	}

	update_post_meta( $order_id, '_aurora_revoked', 1 );

	return $did;
}

/* -------------------------------------------------------------------------
 * Admin
 * ---------------------------------------------------------------------- */

/**
 * Refund list columns.
 *
 * @param array $columns Columns.
 * @return array
 */
function aurora_refund_columns( $columns ) {
	return array(
		'cb'             => $columns['cb'],
		'title'          => __( '退款单号', 'aurora' ),
		'aurora_rorder'  => __( '关联订单', 'aurora' ),
		'aurora_ramount' => __( '退款金额', 'aurora' ),
		'aurora_rstatus' => __( '状态', 'aurora' ),
		'aurora_rreason' => __( '原因', 'aurora' ),
		'date'           => __( '发起时间', 'aurora' ),
	);
}
add_filter( 'manage_aurora_refund_posts_columns', 'aurora_refund_columns' );

/**
 * Render refund columns.
 *
 * @param string $column  Column key.
 * @param int    $post_id Refund ID.
 */
function aurora_refund_column_content( $column, $post_id ) {
	$refund = aurora_get_refund( $post_id );
	if ( ! $refund ) {
		return;
	}

	switch ( $column ) {
		case 'aurora_rorder':
			$order = aurora_get_order( $refund['order'] );
			if ( $order['no'] ) {
				printf(
					'<a href="%s"><code>%s</code></a>',
					esc_url( admin_url( 'post.php?post=' . $refund['order'] . '&action=edit' ) ),
					esc_html( $order['no'] )
				);
			}
			break;

		case 'aurora_ramount':
			echo esc_html( '¥' . number_format( $refund['amount'], 2 ) );
			if ( $refund['txn'] ) {
				echo '<br><small>' . esc_html( $refund['txn'] ) . '</small>';
			}
			break;

		case 'aurora_rstatus':
			$list = aurora_refund_statuses();
			$key  = isset( $list[ $refund['status'] ] ) ? $refund['status'] : 'pending';
			printf(
				'<span class="aurora-status aurora-status--%1$s">%2$s</span>',
				esc_attr( $key ),
				esc_html( $list[ $key ] )
			);
			break;

		case 'aurora_rreason':
			echo esc_html( $refund['reason'] ?: '—' );
			break;
	}
}
add_action( 'manage_aurora_refund_posts_custom_column', 'aurora_refund_column_content', 10, 2 );

/**
 * Refund box on the order edit screen, plus the "mark refunded" escape hatch for
 * a gateway that has no refund API of its own.
 *
 * @param WP_Post $post Order being edited.
 */
function aurora_order_refund_box( $post ) {
	$order = aurora_get_order( $post->ID );
	if ( ! $order['no'] ) {
		return;
	}

	$refundable = aurora_order_refundable( $post->ID );
	$refunded   = aurora_order_refunded_total( $post->ID );
	?>
	<div class="aurora-refund-box">
		<table class="widefat striped" style="margin-bottom:12px">
			<tbody>
				<tr><th><?php esc_html_e( '订单金额', 'aurora' ); ?></th><td>¥<?php echo esc_html( number_format( $order['amount'], 2 ) ); ?></td></tr>
				<tr><th><?php esc_html_e( '已退款', 'aurora' ); ?></th><td>¥<?php echo esc_html( number_format( $refunded, 2 ) ); ?></td></tr>
				<tr><th><?php esc_html_e( '可退余额', 'aurora' ); ?></th><td><strong>¥<?php echo esc_html( number_format( $refundable, 2 ) ); ?></strong></td></tr>
			</tbody>
		</table>

		<?php if ( $refundable > 0 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="aurora_issue_refund">
				<input type="hidden" name="order_id" value="<?php echo esc_attr( $post->ID ); ?>">
				<?php wp_nonce_field( 'aurora_issue_refund_' . $post->ID ); ?>
				<p>
					<label for="aurora_refund_amount"><strong><?php esc_html_e( '退款金额', 'aurora' ); ?></strong></label><br>
					<input type="number" step="0.01" min="0.01" max="<?php echo esc_attr( $refundable ); ?>"
						name="amount" id="aurora_refund_amount" value="<?php echo esc_attr( $refundable ); ?>" style="width:140px">
					<?php esc_html_e( '元（留空或填满额即为全额退款）', 'aurora' ); ?>
				</p>
				<p>
					<label for="aurora_refund_reason"><strong><?php esc_html_e( '退款原因', 'aurora' ); ?></strong></label><br>
					<input type="text" name="reason" id="aurora_refund_reason" class="large-text" maxlength="120"
						placeholder="<?php esc_attr_e( '例如：用户申请 / 重复购买', 'aurora' ); ?>">
				</p>
				<p>
					<button type="submit" class="button button-primary"
						onclick="return confirm('<?php echo esc_js( __( '确认发起退款？全额退款会同时收回已发放的会员权益。', 'aurora' ) ); ?>')">
						<?php esc_html_e( '发起退款', 'aurora' ); ?>
					</button>
				</p>
			</form>
		<?php else : ?>
			<p><em>
				<?php
				if ( 'refunded' === $order['status'] ) {
					esc_html_e( '该订单已全额退款。', 'aurora' );
				} elseif ( 'pending' === $order['status'] ) {
					esc_html_e( '订单尚未支付，无需退款。', 'aurora' );
				} else {
					esc_html_e( '当前没有可退金额。', 'aurora' );
				}
				?>
			</em></p>
		<?php endif; ?>

		<?php
		$history = get_posts( array(
			'post_type'      => 'aurora_refund',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => '_aurora_refund_order', 'value' => $post->ID ) ),
		) );

		if ( $history ) :
			$list = aurora_refund_statuses();
			?>
			<p><strong><?php esc_html_e( '退款记录', 'aurora' ); ?></strong></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( '退款单号', 'aurora' ); ?></th>
						<th><?php esc_html_e( '金额', 'aurora' ); ?></th>
						<th><?php esc_html_e( '状态', 'aurora' ); ?></th>
						<th><?php esc_html_e( '时间', 'aurora' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $history as $rid ) : ?>
						<?php $r = aurora_get_refund( $rid ); ?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $rid ) ); ?>"><code><?php echo esc_html( $r['no'] ); ?></code></a></td>
							<td>¥<?php echo esc_html( number_format( $r['amount'], 2 ) ); ?></td>
							<td><?php echo esc_html( $list[ $r['status'] ] ?? $r['status'] ); ?></td>
							<td><?php echo esc_html( $r['created'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Register the order-side refund meta box.
 */
function aurora_add_refund_metabox() {
	add_meta_box(
		'aurora-refund',
		__( 'Aurora · 退款', 'aurora' ),
		'aurora_order_refund_box',
		'aurora_order',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'aurora_add_refund_metabox' );

/**
 * Add a refund shortcut to the order list row actions.
 *
 * @param array   $actions Row actions.
 * @param WP_Post $post    Order.
 * @return array
 */
function aurora_order_row_action( $actions, $post ) {
	if ( 'aurora_order' !== $post->post_type || ! current_user_can( 'edit_others_posts' ) ) {
		return $actions;
	}
	if ( aurora_order_refundable( $post->ID ) > 0 ) {
		$actions['aurora_refund'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'post.php?post=' . $post->ID . '&action=edit#aurora-refund' ) ),
			esc_html__( '退款', 'aurora' )
		);
	}

	return $actions;
}
add_filter( 'post_row_actions', 'aurora_order_row_action', 10, 2 );

/**
 * Handle the refund button.
 */
function aurora_handle_issue_refund() {
	if ( ! is_admin() || ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( '权限不足。', 'aurora' ), '', array( 'response' => 403 ) );
	}

	$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
	check_admin_referer( 'aurora_issue_refund_' . $order_id );

	$raw    = isset( $_POST['amount'] ) ? trim( (string) $_POST['amount'] ) : '';
	$amount = ( '' === $raw ) ? null : (float) $raw;
	$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

	$result = aurora_create_refund( $order_id, $amount, $reason );

	if ( is_wp_error( $result ) ) {
		set_transient( 'aurora_refund_error_' . get_current_user_id(), $result->get_error_message(), 60 );
		wp_safe_redirect( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) );
		exit;
	}

	wp_safe_redirect( admin_url( 'post.php?post=' . $order_id . '&action=edit&aurora_refunded=1' ) );
	exit;
}
add_action( 'admin_post_aurora_issue_refund', 'aurora_handle_issue_refund' );

/**
 * Refund notices on the order screen.
 */
function aurora_refund_admin_notice() {
	$screen = get_current_screen();
	if ( ! $screen || 'aurora_order' !== $screen->post_type ) {
		return;
	}

	if ( isset( $_GET['aurora_refunded'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '退款已发起。微信退款为异步到账，成功后会自动收回对应权益。', 'aurora' ) . '</p></div>';
	}

	$key   = 'aurora_refund_error_' . get_current_user_id();
	$error = get_transient( $key );
	if ( $error ) {
		delete_transient( $key );
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
	}
}
add_action( 'admin_notices', 'aurora_refund_admin_notice' );

/**
 * A "refund" filter on the order list so refunded orders are easy to find.
 */
function aurora_order_refund_digest() {
	$screen = get_current_screen();
	if ( ! $screen || 'aurora_order' !== $screen->post_type ) {
		return;
	}

	$rows = get_posts( array(
		'post_type'      => 'aurora_order',
		'post_status'    => 'publish',
		'posts_per_page' => 200,
		'fields'         => 'ids',
		'meta_key'       => '_aurora_refunded_amount',
		'meta_compare'   => 'EXISTS',
	) );

	$sum = 0.0;
	foreach ( $rows as $id ) {
		$sum += (float) get_post_meta( $id, '_aurora_refunded_amount', true );
	}

	if ( $sum > 0 ) {
		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html( sprintf(
				/* translators: 1: count, 2: total */
				__( '共 %1$d 笔订单产生过退款，累计退款 ¥%2$s。', 'aurora' ),
				count( $rows ),
				number_format( $sum, 2 )
			) )
		);
	}
}
add_action( 'admin_notices', 'aurora_order_refund_digest' );
