<?php
/**
 * Points.
 *
 * A points economy with a real ledger. The balance in user meta is a cache for
 * cheap reads; every change is also written as an append-only row, so the number
 * shown to a user can always be explained and a bad adjustment can be traced
 * back to the rule that made it.
 *
 * Everything that grants or spends points goes through aurora_points_earn() or
 * aurora_points_spend(), never through a bare update_user_meta() — otherwise the
 * balance and the ledger drift apart and the caps stop working.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

const AURORA_POINTS_META = 'aurora_points';

/* -------------------------------------------------------------------------
 * Reasons
 * ---------------------------------------------------------------------- */

/**
 * Why points moved. Modules that ship later (cards, downloads, referrals) add
 * their own reasons through the filter rather than editing this list.
 *
 * @return array key => label
 */
function aurora_points_reasons() {
	$reasons = array(
		'register' => __( '注册奖励', 'aurora' ),
		'checkin'  => __( '每日签到', 'aurora' ),
		'comment'  => __( '发表评论', 'aurora' ),
		'purchase' => __( '消费返积分', 'aurora' ),
		'redeem'   => __( '卡密兑换', 'aurora' ),
		'download' => __( '兑换资源', 'aurora' ),
		'admin'    => __( '管理员调整', 'aurora' ),
		'refund'   => __( '退款扣回', 'aurora' ),
		'tip_out'     => __( '打赏作者', 'aurora' ),
		'tip_in'      => __( '收到打赏', 'aurora' ),
		'tip_refund'  => __( '打赏退回', 'aurora' ),
	);

	return apply_filters( 'aurora_points_reasons', $reasons );
}

/**
 * Human label for a reason key.
 *
 * @param string $reason Reason key.
 * @return string
 */
function aurora_points_reason_label( $reason ) {
	$all = aurora_points_reasons();

	return isset( $all[ $reason ] ) ? $all[ $reason ] : $reason;
}

/* -------------------------------------------------------------------------
 * Ledger storage
 * ---------------------------------------------------------------------- */

/**
 * Register the ledger post type. Private: no front-end views, admin list only.
 */
function aurora_register_points_log() {
	register_post_type( 'aurora_points_log', array(
		'labels'          => array(
			'name'          => __( '积分明细', 'aurora' ),
			'singular_name' => __( '积分记录', 'aurora' ),
			'all_items'     => __( '积分明细', 'aurora' ),
		),
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => 'aurora-points',
		'capability_type' => 'post',
		'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap'    => true,
		'supports'        => array( 'title' ),
		'show_in_rest'    => false,
	) );
}
add_action( 'init', 'aurora_register_points_log' );

/* -------------------------------------------------------------------------
 * Balance
 * ---------------------------------------------------------------------- */

/**
 * Current balance.
 *
 * @param int $user_id User ID, 0 for the current user.
 * @return int
 */
function aurora_points_balance( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return 0;
	}

	return max( 0, (int) get_user_meta( $user_id, AURORA_POINTS_META, true ) );
}

/**
 * Move points and record it.
 *
 * This is the only function that writes the balance. It clamps at zero, keeps
 * the user meta and the ledger in step, and fires an action so notifications can
 * react without this module knowing about them.
 *
 * @param int    $user_id User ID.
 * @param int    $amount  Signed amount (negative spends).
 * @param string $reason  Reason key.
 * @param array  $args    note, ref, silent.
 * @return int|WP_Error New balance, or an error.
 */
function aurora_points_add( $user_id, $amount, $reason, $args = array() ) {
	$user_id = (int) $user_id;
	$amount  = (int) $amount;

	if ( ! $user_id || ! get_userdata( $user_id ) ) {
		return new WP_Error( 'aurora_points_no_user', __( '用户不存在。', 'aurora' ) );
	}

	if ( 0 === $amount ) {
		return aurora_points_balance( $user_id );
	}

	$args = wp_parse_args( $args, array(
		'note'   => '',
		'ref'    => 0,
		'silent' => false,   // suppress the notification hook
	) );

	$before  = aurora_points_balance( $user_id );
	$after   = max( 0, $before + $amount );
	$applied = $after - $before;

	// A deduction larger than the balance is clamped, so record what actually
	// moved rather than what was asked for.
	if ( 0 === $applied ) {
		return $before;
	}

	$label = $args['note'] ? $args['note'] : aurora_points_reason_label( $reason );

	$log_id = wp_insert_post( array(
		'post_type'   => 'aurora_points_log',
		'post_status' => 'publish',
		'post_title'  => $label,
		'post_author' => $user_id,
		'meta_input'  => array(
			'_aurora_points_amount' => $applied,
			'_aurora_points_after'  => $after,
			'_aurora_points_reason' => $reason,
			'_aurora_points_ref'    => (int) $args['ref'],
			'_aurora_points_day'    => current_time( 'Y-m-d' ),
		),
	), true );

	if ( is_wp_error( $log_id ) ) {
		return $log_id;
	}

	update_user_meta( $user_id, AURORA_POINTS_META, $after );

	/**
	 * Fires after a points movement is recorded.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $applied Signed amount actually moved.
	 * @param string $reason  Reason key.
	 * @param int    $after   New balance.
	 * @param int    $log_id  Ledger row.
	 */
	do_action( 'aurora_points_changed', $user_id, $applied, $reason, $after, $log_id );

	if ( ! $args['silent'] ) {
		do_action( 'aurora_points_notify', $user_id, $applied, $reason, $after, $label );
	}

	return $after;
}

/**
 * Grant points, applying the daily cap and the member bonus.
 *
 * @param int    $user_id User ID.
 * @param int    $base    Base amount before bonuses.
 * @param string $reason  Reason key.
 * @param array  $args    Extra args for aurora_points_add().
 * @return int|WP_Error New balance, or an error.
 */
function aurora_points_earn( $user_id, $base, $reason, $args = array() ) {
	if ( ! aurora_points_enabled() ) {
		return aurora_points_balance( $user_id );
	}

	$base = (int) $base;

	if ( $base <= 0 ) {
		return aurora_points_balance( $user_id );
	}

	// Daily cap per reason, counted from the ledger so it survives meta writes.
	$cap = (int) get_theme_mod( 'aurora_points_cap_' . $reason, 0 );
	if ( $cap > 0 && aurora_points_daily_count( $user_id, $reason ) >= $cap ) {
		return new WP_Error(
			'aurora_points_capped',
			sprintf(
				/* translators: %s: reason label */
				__( '%s 的今日积分已达上限。', 'aurora' ),
				aurora_points_reason_label( $reason )
			)
		);
	}

	$amount = $base + (int) round( $base * aurora_points_member_bonus_pct( $reason, $user_id ) / 100 );

	return aurora_points_add( $user_id, $amount, $reason, $args );
}

/**
 * Spend points, refusing when the balance is short.
 *
 * Never clamps: a partial spend would silently under-charge for whatever the
 * points were buying.
 *
 * @param int    $user_id User ID.
 * @param int    $amount  Positive amount to spend.
 * @param string $reason  Reason key.
 * @param array  $args    Extra args for aurora_points_add().
 * @return int|WP_Error New balance, or an error.
 */
function aurora_points_spend( $user_id, $amount, $reason, $args = array() ) {
	$amount = (int) $amount;

	if ( $amount <= 0 ) {
		return new WP_Error( 'aurora_points_bad_amount', __( '积分数额无效。', 'aurora' ) );
	}

	if ( aurora_points_balance( $user_id ) < $amount ) {
		return new WP_Error( 'aurora_points_low', __( '积分不足。', 'aurora' ) );
	}

	// 'silent' by default: spending is usually the second half of an action that
	// already notifies (a redemption, a download).
	$args = wp_parse_args( $args, array( 'silent' => true ) );

	return aurora_points_add( $user_id, -$amount, $reason, $args );
}

/**
 * Is the economy switched on?
 *
 * @return bool
 */
function aurora_points_enabled() {
	return (bool) get_theme_mod( 'aurora_points_enabled', true );
}

/**
 * Extra earnings for members, as a percentage, for reasons that qualify.
 *
 * The user must be passed in, not inferred from the session: points can be
 * credited by an administrator or a cron job on behalf of somebody who is not
 * the current user, and reading the level off the session would silently deny
 * the bonus in those cases.
 *
 * @param string $reason  Reason key.
 * @param int    $user_id User being credited, 0 for the current user.
 * @return int
 */
function aurora_points_member_bonus_pct( $reason, $user_id = 0 ) {
	if ( ! get_theme_mod( 'aurora_points_member_bonus_on', true ) ) {
		return 0;
	}

	// Only participation rewards scale with membership; a card redemption is
	// worth what it says on the card, not more because the user is a member.
	if ( ! in_array( $reason, array( 'checkin', 'comment' ), true ) ) {
		return 0;
	}

	if ( aurora_get_user_level( $user_id ? (int) $user_id : null ) < 1 ) {
		return 0;
	}

	return max( 0, (int) get_theme_mod( 'aurora_points_member_bonus', 50 ) );
}

/**
 * How many times this user earned a reason today.
 *
 * @param int    $user_id User ID.
 * @param string $reason  Reason key.
 * @return int
 */
function aurora_points_daily_count( $user_id, $reason ) {
	$q = new WP_Query( array(
		'post_type'      => 'aurora_points_log',
		'post_status'    => 'publish',
		'author'         => (int) $user_id,
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => false,
		'meta_query'     => array(
			array( 'key' => '_aurora_points_reason', 'value' => $reason ),
			array( 'key' => '_aurora_points_day', 'value' => current_time( 'Y-m-d' ) ),
			array( 'key' => '_aurora_points_amount', 'value' => 0, 'compare' => '>' ),
		),
	) );

	return (int) $q->found_posts;
}

/**
 * A user's ledger, newest first.
 *
 * @param int $user_id User ID.
 * @param int $limit   Rows.
 * @return array
 */
function aurora_points_ledger( $user_id, $limit = 20 ) {
	$posts = get_posts( array(
		'post_type'      => 'aurora_points_log',
		'post_status'    => 'publish',
		'author'         => (int) $user_id,
		'posts_per_page' => (int) $limit,
		'orderby'        => 'ID',
		'order'          => 'DESC',
	) );

	$rows = array();
	foreach ( $posts as $p ) {
		$rows[] = array(
			'label'   => $p->post_title,
			'amount'  => (int) get_post_meta( $p->ID, '_aurora_points_amount', true ),
			'reason'  => (string) get_post_meta( $p->ID, '_aurora_points_reason', true ),
			'after'   => (int) get_post_meta( $p->ID, '_aurora_points_after', true ),
			'created' => get_the_date( 'Y-m-d H:i', $p ),
		);
	}

	return $rows;
}

/**
 * Top balances, for a leaderboard.
 *
 * @param int $limit How many users.
 * @return array user_id => points
 */
function aurora_points_top( $limit = 10 ) {
	$q = new WP_User_Query( array(
		'number'      => (int) $limit,
		'meta_key'    => AURORA_POINTS_META,
		'orderby'     => 'meta_value_num',
		'order'       => 'DESC',
		'meta_compare' => '>',
		'meta_value'  => 0,
		'fields'      => array( 'ID' ),
	) );

	$out = array();
	foreach ( $q->get_results() as $u ) {
		$out[ $u->ID ] = aurora_points_balance( $u->ID );
	}

	return $out;
}

/* -------------------------------------------------------------------------
 * Earning rules
 * ---------------------------------------------------------------------- */

/**
 * Registration bonus.
 *
 * @param int $user_id New user.
 */
function aurora_points_on_register( $user_id ) {
	$amount = (int) get_theme_mod( 'aurora_points_register', 50 );
	if ( $amount <= 0 ) {
		return;
	}

	aurora_points_add( $user_id, $amount, 'register' );
}
add_action( 'user_register', 'aurora_points_on_register' );

/**
 * Comment reward, once per approved comment up to a daily cap.
 *
 * @param int        $comment_id Comment ID.
 * @param int|string $approved   1 when approved.
 */
function aurora_points_on_comment( $comment_id, $approved ) {
	if ( 1 !== (int) $approved ) {
		return;
	}

	$comment = get_comment( $comment_id );
	if ( ! $comment || ! $comment->user_id ) {
		return;   // guest comments earn nothing: there is no account to credit
	}

	// Commenting on your own post is not participation.
	$post = get_post( $comment->comment_post_ID );
	if ( $post && (int) $post->post_author === (int) $comment->user_id ) {
		return;
	}

	$amount = (int) get_theme_mod( 'aurora_points_comment', 5 );
	if ( $amount <= 0 ) {
		return;
	}

	aurora_points_earn(
		$comment->user_id,
		$amount,
		'comment',
		array( 'ref' => $comment_id )
	);
}
add_action( 'comment_post', 'aurora_points_on_comment', 10, 2 );

/**
 * Reward a comment that was held for moderation and later approved.
 *
 * @param string     $new_status New status.
 * @param string     $old_status Old status.
 * @param WP_Comment $comment    Comment.
 */
function aurora_points_on_comment_approved( $new_status, $old_status, $comment ) {
	if ( 'approved' === $new_status && 'approved' !== $old_status ) {
		aurora_points_on_comment( $comment->comment_ID, 1 );
	}
}
add_action( 'transition_comment_status', 'aurora_points_on_comment_approved', 10, 3 );

/**
 * Points back on a paid order, at a configurable rate per unit of currency.
 *
 * @param int $order_id Order ID.
 */
function aurora_points_on_order_paid( $order_id ) {
	if ( ! function_exists( 'aurora_get_order' ) ) {
		return;
	}

	$order = aurora_get_order( $order_id );
	if ( ! $order['user_id'] || $order['amount'] <= 0 ) {
		return;
	}

	// Only once per order, however many times settlement is replayed.
	if ( get_post_meta( $order_id, '_aurora_points_granted', true ) ) {
		return;
	}

	$rate = (float) get_theme_mod( 'aurora_points_per_currency', 1 );
	if ( $rate <= 0 ) {
		return;
	}

	$amount = (int) floor( $order['amount'] * $rate );

	update_post_meta( $order_id, '_aurora_points_granted', $amount );

	if ( $amount <= 0 ) {
		return;
	}

	aurora_points_add(
		$order['user_id'],
		$amount,
		'purchase',
		array( 'ref' => $order_id )
	);
}
add_action( 'aurora_order_paid', 'aurora_points_on_order_paid', 20, 1 );

/**
 * Take back the points an order granted, once that order is fully refunded.
 *
 * A partial refund keeps them: the buyer still holds part of what they paid for.
 *
 * @param int $refund_id Refund ID.
 * @param int $order_id  Order ID.
 */
function aurora_points_on_refund( $refund_id, $order_id ) {
	$order_id = (int) $order_id;
	if ( ! $order_id || ! function_exists( 'aurora_get_order' ) ) {
		return;
	}

	$granted = (int) get_post_meta( $order_id, '_aurora_points_granted', true );
	if ( $granted <= 0 ) {
		return;
	}

	$order = aurora_get_order( $order_id );
	if ( 'refunded' !== $order['status'] ) {
		return;   // partial refund: leave the points alone
	}

	if ( get_post_meta( $order_id, '_aurora_points_reclaimed', true ) ) {
		return;   // already taken back
	}

	update_post_meta( $order_id, '_aurora_points_reclaimed', 1 );

	$user_id = $order['user_id'];
	if ( ! $user_id ) {
		return;
	}

	// Clamp: a user may already have spent them, and a negative balance would
	// break every later deduction.
	$take = min( $granted, aurora_points_balance( $user_id ) );

	if ( $take > 0 ) {
		aurora_points_add(
			$user_id,
			-$take,
			'refund',
			array(
				'ref'    => $order_id,
				'note'   => sprintf(
					/* translators: %s: order number */
					__( '订单 %s 退款，扣回赠送积分', 'aurora' ),
					$order['no']
				),
				'silent' => false,
			)
		);
	}
}
add_action( 'aurora_refund_settled', 'aurora_points_on_refund', 10, 2 );

/* -------------------------------------------------------------------------
 * Admin
 * ---------------------------------------------------------------------- */

/**
 * Let an administrator set a balance by hand, recorded as an adjustment.
 *
 * @param WP_User $user User being edited.
 */
function aurora_points_admin_field( $user ) {
	if ( ! current_user_can( 'edit_users' ) ) {
		return;
	}
	?>
	<h2><?php esc_html_e( 'Aurora 积分', 'aurora' ); ?></h2>
	<table class="form-table">
		<tr>
			<th><label for="aurora_points_balance"><?php esc_html_e( '积分余额', 'aurora' ); ?></label></th>
			<td>
				<input type="number" step="1" id="aurora_points_balance" name="aurora_points_balance"
					value="<?php echo esc_attr( aurora_points_balance( $user->ID ) ); ?>" class="small-text">
				<p class="description">
					<?php esc_html_e( '直接改这里会记为一次「管理员调整」，并写入积分明细。', 'aurora' ); ?>
				</p>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'aurora_points_admin_field' );
add_action( 'edit_user_profile', 'aurora_points_admin_field' );

/**
 * Persist a manual balance change as a delta against the stored value.
 *
 * @param int $user_id User ID.
 */
function aurora_points_admin_save( $user_id ) {
	if ( ! current_user_can( 'edit_users' ) ) {
		return;
	}
	if ( ! isset( $_POST['aurora_points_balance'] ) ) {
		return;
	}
	if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'update-user_' . $user_id ) ) {
		return;
	}

	$target = (int) $_POST['aurora_points_balance'];
	$current = aurora_points_balance( $user_id );
	$delta   = $target - $current;

	if ( 0 === $delta ) {
		return;
	}

	aurora_points_add(
		$user_id,
		$delta,
		'admin',
		array(
			'note' => sprintf(
				/* translators: 1: old balance, 2: new balance */
				__( '管理员调整（%1$d → %2$d）', 'aurora' ),
				$current,
				$target
			),
		)
	);
}
add_action( 'personal_options_update', 'aurora_points_admin_save' );
add_action( 'edit_user_profile_update', 'aurora_points_admin_save' );

/**
 * Trim the ledger title column so long notes do not wrap ten lines.
 *
 * @param array $columns Columns.
 * @return array
 */
function aurora_points_log_columns( $columns ) {
	return array(
		'cb'           => $columns['cb'],
		'title'        => __( '说明', 'aurora' ),
		'aurora_user'  => __( '用户', 'aurora' ),
		'aurora_delta' => __( '变动', 'aurora' ),
		'aurora_after' => __( '余额', 'aurora' ),
		'aurora_why'   => __( '类型', 'aurora' ),
		'date'         => __( '时间', 'aurora' ),
	);
}
add_filter( 'manage_aurora_points_log_posts_columns', 'aurora_points_log_columns' );

/**
 * Ledger column values.
 *
 * @param string $column  Column key.
 * @param int    $post_id Row.
 */
function aurora_points_log_column( $column, $post_id ) {
	switch ( $column ) {
		case 'aurora_user':
			$uid = (int) get_post_field( 'post_author', $post_id );
			$u   = get_userdata( $uid );
			echo $u ? esc_html( $u->user_login ) : '—';
			break;

		case 'aurora_delta':
			$amount = (int) get_post_meta( $post_id, '_aurora_points_amount', true );
			printf(
				'<span style="color:%s;font-weight:600">%s%d</span>',
				$amount >= 0 ? '#1a7f37' : '#b32d2e',
				$amount >= 0 ? '+' : '',
				$amount
			);
			break;

		case 'aurora_after':
			echo (int) get_post_meta( $post_id, '_aurora_points_after', true );
			break;

		case 'aurora_why':
			echo esc_html( aurora_points_reason_label( (string) get_post_meta( $post_id, '_aurora_points_reason', true ) ) );
			break;
	}
}
add_action( 'manage_aurora_points_log_posts_custom_column', 'aurora_points_log_column', 10, 2 );

/**
 * Make the ledger sortable by balance.
 *
 * @param array $columns Sortable columns.
 * @return array
 */
function aurora_points_log_sortable( $columns ) {
	$columns['aurora_delta'] = 'aurora_delta';

	return $columns;
}
add_filter( 'manage_edit-aurora_points_log_sortable_columns', 'aurora_points_log_sortable' );

/**
 * Keep the ledger read-only: no "Add New" button.
 */
function aurora_points_log_admin_css() {
	$screen = get_current_screen();
	if ( ! $screen || 'aurora_points_log' !== $screen->post_type ) {
		return;
	}
	echo '<style>.page-title-action{display:none}</style>';
}
add_action( 'admin_head', 'aurora_points_log_admin_css' );

/* -------------------------------------------------------------------------
 * Admin: hub menu
 * ---------------------------------------------------------------------- */

/**
 * One parent menu for the economy, so the ledger, notifications and download
 * log sit together instead of scattering across the sidebar.
 */
function aurora_points_admin_menu() {
	add_menu_page(
		__( '积分与通知', 'aurora' ),
		__( '积分与通知', 'aurora' ),
		'manage_options',
		'aurora-points',
		'aurora_points_hub_page',
		'dashicons-star-filled',
		26
	);
}
add_action( 'admin_menu', 'aurora_points_admin_menu', 5 );

/**
 * Overview: what the economy has issued, who holds the most, latest activity.
 */
function aurora_points_hub_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '权限不足。', 'aurora' ), '', array( 'response' => 403 ) );
	}

	global $wpdb;

	$issued = (int) $wpdb->get_var(
		"SELECT COALESCE(SUM(CAST(pm.meta_value AS SIGNED)), 0)
		   FROM {$wpdb->postmeta} pm
		   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		  WHERE pm.meta_key = '_aurora_points_amount'
		    AND pm.meta_value > 0
		    AND p.post_type = 'aurora_points_log'"
	);

	$spent = (int) abs( $wpdb->get_var(
		"SELECT COALESCE(SUM(CAST(pm.meta_value AS SIGNED)), 0)
		   FROM {$wpdb->postmeta} pm
		   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		  WHERE pm.meta_key = '_aurora_points_amount'
		    AND pm.meta_value < 0
		    AND p.post_type = 'aurora_points_log'"
	) );

	$holders = new WP_User_Query( array(
		'meta_key'     => AURORA_POINTS_META,
		'meta_value'   => 0,
		'meta_compare' => '>',
		'count_total'  => true,
		'number'       => 1,
		'fields'       => 'ID',
	) );

	$log_count = wp_count_posts( 'aurora_points_log' );
	$top       = aurora_points_top( 10 );
	$recent    = get_posts( array(
		'post_type'      => 'aurora_points_log',
		'post_status'    => 'publish',
		'posts_per_page' => 15,
	) );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( '积分与通知', 'aurora' ); ?></h1>

		<div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0">
			<?php
			$cards = array(
				__( '累计发放', 'aurora' )   => number_format_i18n( $issued ),
				__( '累计消耗', 'aurora' )   => number_format_i18n( $spent ),
				__( '净流通', 'aurora' )     => number_format_i18n( $issued - $spent ),
				__( '持有积分用户', 'aurora' ) => number_format_i18n( (int) $holders->get_total() ),
				__( '流水条数', 'aurora' )   => number_format_i18n( (int) $log_count->publish ),
			);

			foreach ( $cards as $label => $value ) {
				printf(
					'<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 20px;min-width:130px">
						<div style="font-size:12px;color:#646970">%s</div>
						<div style="font-size:22px;font-weight:600;margin-top:4px">%s</div>
					</div>',
					esc_html( $label ),
					esc_html( $value )
				);
			}
			?>
		</div>

		<h2><?php esc_html_e( '积分排行', 'aurora' ); ?></h2>
		<?php if ( $top ) : ?>
			<table class="widefat striped" style="max-width:560px">
				<thead><tr>
					<th style="width:60px">#</th>
					<th><?php esc_html_e( '用户', 'aurora' ); ?></th>
					<th style="width:120px"><?php esc_html_e( '积分', 'aurora' ); ?></th>
				</tr></thead>
				<tbody>
				<?php $rank = 0; foreach ( $top as $uid => $balance ) : $rank++; ?>
					<tr>
						<td><?php echo (int) $rank; ?></td>
						<td>
							<a href="<?php echo esc_url( get_edit_user_link( $uid ) ); ?>">
								<?php echo esc_html( get_userdata( $uid )->user_login ); ?>
							</a>
						</td>
						<td><?php echo esc_html( number_format_i18n( $balance ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><em><?php esc_html_e( '还没有人持有积分。', 'aurora' ); ?></em></p>
		<?php endif; ?>

		<h2><?php esc_html_e( '最近流水', 'aurora' ); ?></h2>
		<?php if ( $recent ) : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( '说明', 'aurora' ); ?></th>
					<th style="width:180px"><?php esc_html_e( '用户', 'aurora' ); ?></th>
					<th style="width:80px"><?php esc_html_e( '变动', 'aurora' ); ?></th>
					<th style="width:160px"><?php esc_html_e( '时间', 'aurora' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $recent as $row ) : ?>
					<?php
					$delta = (int) get_post_meta( $row->ID, '_aurora_points_amount', true );
					$owner = get_userdata( (int) $row->post_author );
					?>
					<tr>
						<td><?php echo esc_html( $row->post_title ); ?></td>
						<td><?php echo $owner ? esc_html( $owner->user_login ) : '—'; ?></td>
						<td>
							<span style="color:<?php echo $delta >= 0 ? '#1a7f37' : '#b32d2e'; ?>;font-weight:600">
								<?php echo ( $delta >= 0 ? '+' : '' ) . (int) $delta; ?>
							</span>
						</td>
						<td><?php echo esc_html( get_the_date( 'Y-m-d H:i', $row ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><em><?php esc_html_e( '暂无流水。', 'aurora' ); ?></em></p>
		<?php endif; ?>

		<h2><?php esc_html_e( '常用入口', 'aurora' ); ?></h2>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=aurora_card' ) ); ?>"><?php esc_html_e( '卡密库存', 'aurora' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=aurora_card&page=aurora-cards-generate' ) ); ?>"><?php esc_html_e( '批量生成卡密', 'aurora' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'users.php?page=aurora-broadcast' ) ); ?>"><?php esc_html_e( '发送站内通知', 'aurora' ); ?></a>
		</p>
	</div>
	<?php
}
