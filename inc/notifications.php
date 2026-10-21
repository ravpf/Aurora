<?php
/**
 * In-site notifications.
 *
 * Every event the commerce modules already fire becomes a row addressed to one
 * user: paid, refunded, membership granted, points moved, card redeemed, a reply
 * to your post. The header bell reads the unread count; the user centre has the
 * full list.
 *
 * Rows are private posts authored by the recipient, so the existing admin list,
 * capability checks and trash all work without a custom table.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

const AURORA_NOTIFY_READ = '_aurora_notify_read';

/* -------------------------------------------------------------------------
 * Storage
 * ---------------------------------------------------------------------- */

/**
 * Register the notification post type.
 */
function aurora_register_notify_type() {
	register_post_type( 'aurora_notify', array(
		'labels'          => array(
			'name'          => __( '站内通知', 'aurora' ),
			'singular_name' => __( '通知', 'aurora' ),
			'all_items'     => __( '站内通知', 'aurora' ),
		),
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => 'aurora-points',
		'capability_type' => 'post',
		'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap'    => true,
		'supports'        => array( 'title', 'editor' ),
		'show_in_rest'    => false,
	) );
}
add_action( 'init', 'aurora_register_notify_type' );

/**
 * Message kinds, for the icon and the admin filter.
 *
 * @return array
 */
function aurora_notify_types() {
	return apply_filters( 'aurora_notify_types', array(
		'order'   => array( 'label' => __( '订单', 'aurora' ), 'icon' => 'cart' ),
		'refund'  => array( 'label' => __( '退款', 'aurora' ), 'icon' => 'undo' ),
		'vip'     => array( 'label' => __( '会员', 'aurora' ), 'icon' => 'crown' ),
		'points'  => array( 'label' => __( '积分', 'aurora' ), 'icon' => 'fire' ),
		'card'    => array( 'label' => __( '卡密', 'aurora' ), 'icon' => 'gift' ),
		'download' => array( 'label' => __( '下载', 'aurora' ), 'icon' => 'download' ),
		'tip'     => array( 'label' => __( '打赏', 'aurora' ), 'icon' => 'gift' ),
		'reply'   => array( 'label' => __( '回复', 'aurora' ), 'icon' => 'mail' ),
		'system'  => array( 'label' => __( '系统', 'aurora' ), 'icon' => 'bell' ),
	) );
}

/**
 * One-time upgrade for the 打赏 event.
 *
 * The event list is stored as an explicit array, so adding a type in code is not
 * enough for a site that already saved its list: the new event would be created
 * and then silently swallowed. Append it once, after which the owner can turn it
 * off again in Theme Settings like any other event.
 */
function aurora_notify_migrate_tip_event() {
	if ( get_option( 'aurora_notify_tip_event_migrated' ) ) {
		return;
	}

	$events = get_theme_mod( 'aurora_notify_events', null );

	if ( is_array( $events ) && ! in_array( 'tip', $events, true ) ) {
		$events[] = 'tip';
		set_theme_mod( 'aurora_notify_events', array_values( array_unique( $events ) ) );
	}

	update_option( 'aurora_notify_tip_event_migrated', 1 );
}
add_action( 'admin_init', 'aurora_notify_migrate_tip_event' );
add_action( 'after_switch_theme', 'aurora_notify_migrate_tip_event' );

/**
 * Is this event type allowed to notify?
 *
 * A site owner may want order receipts but not a message for every points
 * movement. 'system' (admin broadcasts) is exempt so an announcement can never
 * be silently swallowed by an event-list edit.
 *
 * @param string $type Event type.
 * @return bool
 */
function aurora_notify_event_enabled( $type ) {
	if ( 'system' === $type ) {
		return true;
	}

	$events = get_theme_mod( 'aurora_notify_events', array( 'order', 'refund', 'vip', 'points', 'card', 'reply' ) );

	if ( ! is_array( $events ) ) {
		return false;
	}

	return in_array( $type, $events, true );
}

/* -------------------------------------------------------------------------
 * Writing
 * ---------------------------------------------------------------------- */

/**
 * Send a notification.
 *
 * @param int    $user_id Recipient.
 * @param string $title   Headline.
 * @param string $body    Body text.
 * @param array  $args    link, type, silent.
 * @return int|WP_Error Notification ID.
 */
function aurora_notify( $user_id, $title, $body = '', $args = array() ) {
	$user_id = (int) $user_id;

	if ( ! $user_id || ! get_userdata( $user_id ) ) {
		return new WP_Error( 'aurora_notify_no_user', __( '收件人不存在。', 'aurora' ) );
	}

	if ( ! get_theme_mod( 'aurora_notify_enabled', true ) ) {
		return new WP_Error( 'aurora_notify_off', __( '站内通知已关闭。', 'aurora' ) );
	}

	$args = wp_parse_args( $args, array(
		'link' => '',
		'type' => 'system',
	) );

	if ( ! aurora_notify_event_enabled( $args['type'] ) ) {
		return new WP_Error( 'aurora_notify_muted', __( '该事件的通知已在主题设置中关闭。', 'aurora' ) );
	}

	$id = wp_insert_post( array(
		'post_type'    => 'aurora_notify',
		'post_status'  => 'publish',
		'post_title'   => $title,
		'post_content' => $body,
		'post_author'  => $user_id,
		'meta_input'   => array(
			'_aurora_notify_link' => esc_url_raw( $args['link'] ),
			'_aurora_notify_type' => sanitize_key( $args['type'] ),
			AURORA_NOTIFY_READ    => 0,
		),
	), true );

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	aurora_notify_trim( $user_id );

	return $id;
}

/**
 * Keep a mailbox bounded: drop the oldest rows past the cap.
 *
 * This counts every row, not just the read ones. A mailbox whose messages are
 * all still unread — exactly what a broadcast leaves behind — would otherwise
 * grow without limit, and the unread count query gets slower for every user
 * forever. Read rows are dropped first so unread news survives as long as the
 * cap allows.
 *
 * @param int $user_id User ID.
 */
function aurora_notify_trim( $user_id ) {
	$user_id = (int) $user_id;
	$cap     = (int) get_theme_mod( 'aurora_notify_cap', 200 );

	if ( $cap <= 0 ) {
		return;
	}

	$all = get_posts( array(
		'post_type'      => 'aurora_notify',
		'post_status'    => 'publish',
		'author'         => $user_id,
		'posts_per_page' => $cap + 100,
		'orderby'        => 'ID',
		'order'          => 'DESC',
		'fields'         => 'ids',
	) );

	if ( count( $all ) <= $cap ) {
		return;
	}

	$excess = array_slice( $all, $cap );   // oldest first from here on

	// Spend the read rows before touching anything the user has not seen.
	usort( $excess, function ( $a, $b ) {
		$ra = '1' === (string) get_post_meta( $a, AURORA_NOTIFY_READ, true ) ? 0 : 1;
		$rb = '1' === (string) get_post_meta( $b, AURORA_NOTIFY_READ, true ) ? 0 : 1;

		return $ra === $rb ? $a <=> $b : $ra <=> $rb;
	} );

	foreach ( $excess as $id ) {
		wp_delete_post( $id, true );
	}
}

/* -------------------------------------------------------------------------
 * Reading
 * ---------------------------------------------------------------------- */

/**
 * Unread count.
 *
 * @param int $user_id User ID.
 * @return int
 */
function aurora_notify_unread( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return 0;
	}

	$q = new WP_Query( array(
		'post_type'      => 'aurora_notify',
		'post_status'    => 'publish',
		'author'         => $user_id,
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_query'     => array(
			array( 'key' => AURORA_NOTIFY_READ, 'value' => '1', 'compare' => '!=' ),
		),
	) );

	return (int) $q->found_posts;
}

/**
 * A user's notifications, newest first.
 *
 * @param int  $user_id User ID.
 * @param int  $limit   Rows.
 * @param bool $unread  Only unread.
 * @param int  $offset  Rows to skip (the message centre pages through them).
 * @return array
 */
function aurora_notify_list( $user_id = 0, $limit = 20, $unread = false, $offset = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return array();
	}

	$args = array(
		'post_type'      => 'aurora_notify',
		'post_status'    => 'publish',
		'author'         => $user_id,
		'posts_per_page' => (int) $limit,
		'orderby'        => 'ID',
		'order'          => 'DESC',
		'offset'         => max( 0, (int) $offset ),
	);

	if ( $unread ) {
		$args['meta_query'] = array(
			array( 'key' => AURORA_NOTIFY_READ, 'value' => '1', 'compare' => '!=' ),
		);
	}

	$types = aurora_notify_types();
	$rows  = array();

	foreach ( get_posts( $args ) as $p ) {
		$type = (string) get_post_meta( $p->ID, '_aurora_notify_type', true );

		$rows[] = array(
			'id'      => $p->ID,
			'title'   => $p->post_title,
			'body'    => $p->post_content,
			'link'    => (string) get_post_meta( $p->ID, '_aurora_notify_link', true ),
			'type'    => $type,
			'label'   => isset( $types[ $type ] ) ? $types[ $type ]['label'] : __( '通知', 'aurora' ),
			'icon'    => isset( $types[ $type ] ) ? $types[ $type ]['icon'] : 'bell',
			'read'    => '1' === (string) get_post_meta( $p->ID, AURORA_NOTIFY_READ, true ),
			'created' => get_the_date( 'Y-m-d H:i', $p ),
		);
	}

	return $rows;
}

/**
 * How many notifications a user has, optionally only unread ones.
 *
 * @param int  $user_id User ID.
 * @param bool $unread  Only unread.
 * @return int
 */
function aurora_notify_total( $user_id = 0, $unread = false ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return 0;
	}

	$args = array(
		'post_type'      => 'aurora_notify',
		'post_status'    => 'publish',
		'author'         => $user_id,
		'posts_per_page' => 1,
		'fields'         => 'ids',
	);

	if ( $unread ) {
		$args['meta_query'] = array(
			array( 'key' => AURORA_NOTIFY_READ, 'value' => '1', 'compare' => '!=' ),
		);
	}

	$q = new WP_Query( $args );

	return (int) $q->found_posts;
}

/**
 * Mark one notification read, or all of them.
 *
 * @param int $user_id User ID.
 * @param int $id      Notification ID, 0 for all.
 * @return int How many were marked.
 */
function aurora_notify_mark_read( $user_id = 0, $id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return 0;
	}

	$args = array(
		'post_type'      => 'aurora_notify',
		'post_status'    => 'publish',
		'author'         => $user_id,
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => array(
			array( 'key' => AURORA_NOTIFY_READ, 'value' => '1', 'compare' => '!=' ),
		),
	);

	if ( $id ) {
		$args['p'] = (int) $id;
	}

	$ids = get_posts( $args );

	foreach ( $ids as $post_id ) {
		// Guard against a caller passing someone else's notification ID.
		if ( (int) get_post_field( 'post_author', $post_id ) !== $user_id ) {
			continue;
		}
		update_post_meta( $post_id, AURORA_NOTIFY_READ, 1 );
	}

	return count( $ids );
}

/* -------------------------------------------------------------------------
 * Event listeners
 * ---------------------------------------------------------------------- */

/**
 * Payment succeeded.
 *
 * @param int $order_id Order ID.
 */
function aurora_notify_on_order_paid( $order_id ) {
	if ( ! function_exists( 'aurora_get_order' ) ) {
		return;
	}

	$order = aurora_get_order( $order_id );
	if ( ! $order['user_id'] ) {
		return;
	}

	$is_post = $order['object'] > 0;

	aurora_notify(
		$order['user_id'],
		$is_post
			? __( '购买成功', 'aurora' )
			: sprintf( __( '%s 已开通', 'aurora' ), $order['product'] ),
		$is_post
			? sprintf( __( '你已购买《%s》，现在可以阅读全文了。', 'aurora' ), $order['product'] )
			: sprintf(
				/* translators: 1: amount, 2: order number */
				__( '支付 ¥%1$s 已到账，订单 %2$s。', 'aurora' ),
				number_format( $order['amount'], 2 ),
				$order['no']
			),
		array(
			'type' => 'order',
			'link' => $is_post ? get_permalink( $order['object'] ) : aurora_user_page_url(),
		)
	);
}
add_action( 'aurora_order_paid', 'aurora_notify_on_order_paid', 30, 1 );

/**
 * Refund settled.
 *
 * @param int $refund_id Refund ID.
 * @param int $order_id  Order ID.
 */
function aurora_notify_on_refund( $refund_id, $order_id ) {
	if ( ! function_exists( 'aurora_get_refund' ) || ! function_exists( 'aurora_get_order' ) ) {
		return;
	}

	$refund = aurora_get_refund( $refund_id );
	$order  = aurora_get_order( $order_id );

	if ( ! $refund || ! $order['user_id'] ) {
		return;
	}

	// Only report the outcomes a buyer cares about; a pending gateway refund is
	// not news yet.
	if ( 'success' !== $refund['status'] ) {
		return;
	}

	$full = 'refunded' === $order['status'];

	aurora_notify(
		$order['user_id'],
		$full ? __( '退款完成', 'aurora' ) : __( '部分退款完成', 'aurora' ),
		sprintf(
			/* translators: 1: amount, 2: order number */
			__( '订单 %2$s 已退款 ¥%1$s。%3$s', 'aurora' ),
			number_format( $refund['amount'], 2 ),
			$order['no'],
			$full
				? __( '该订单对应的权益已收回。', 'aurora' )
				: __( '权益仍然保留。', 'aurora' )
		),
		array( 'type' => 'refund', 'link' => aurora_user_page_url() )
	);
}
add_action( 'aurora_refund_settled', 'aurora_notify_on_refund', 20, 2 );

/**
 * Membership granted by any route (card, manual, order).
 *
 * Note the third argument: aurora_vip_granted() hands over the resulting
 * *expiry datetime*, not a day count, so the message is built from the stored
 * expiry and the remaining days are derived from it.
 *
 * @param int    $user_id User ID.
 * @param int    $level   Level.
 * @param string $expires Expiry datetime.
 * @param string $source  Source tag.
 */
function aurora_notify_on_vip( $user_id, $level, $expires, $source ) {
	// An order already sent its own receipt; avoid saying it twice.
	if ( 0 === strpos( (string) $source, 'order' ) ) {
		return;
	}

	$info    = function_exists( 'aurora_get_vip_level' ) ? aurora_get_vip_level( $level ) : null;
	$name    = $info ? $info['name'] : sprintf( 'Lv%d', $level );
	$stored  = get_user_meta( $user_id, 'aurora_vip_expires', true );
	$expires = $expires ? $expires : $stored;

	$days = 0;
	if ( $expires && strtotime( $expires ) > time() ) {
		$days = (int) ceil( ( strtotime( $expires ) - time() ) / DAY_IN_SECONDS );
	}

	$sources = array(
		'card'   => __( '卡密兑换', 'aurora' ),
		'manual' => __( '管理员开通', 'aurora' ),
	);

	aurora_notify(
		$user_id,
		sprintf( __( '%s 已开通', 'aurora' ), $name ),
		sprintf(
			/* translators: 1: remaining days, 2: expiry date, 3: how it was granted */
			__( '剩余 %1$d 天，到期时间 %2$s。来源：%3$s。', 'aurora' ),
			$days,
			$expires ? substr( (string) $expires, 0, 10 ) : '—',
			isset( $sources[ $source ] ) ? $sources[ $source ] : $source
		),
		array( 'type' => 'vip', 'link' => aurora_user_page_url() )
	);
}
add_action( 'aurora_vip_granted', 'aurora_notify_on_vip', 20, 4 );

/**
 * Points moved.
 *
 * @param int    $user_id User ID.
 * @param int    $delta   Signed amount.
 * @param string $reason  Reason key.
 * @param int    $after   New balance.
 * @param string $label   Human label.
 */
function aurora_notify_on_points( $user_id, $delta, $reason, $after, $label ) {
	$verb = $delta > 0 ? __( '积分增加', 'aurora' ) : __( '积分扣除', 'aurora' );

	aurora_notify(
		$user_id,
		sprintf( '%s %d', $verb, abs( $delta ) ),
		sprintf(
			/* translators: 1: reason, 2: balance */
			__( '%1$s。当前余额 %2$d 分。', 'aurora' ),
			$label,
			(int) $after
		),
		array( 'type' => 'points', 'link' => aurora_user_page_url( 'points' ) )
	);
}
add_action( 'aurora_points_notify', 'aurora_notify_on_points', 10, 5 );

/**
 * Card redeemed.
 *
 * @param int   $card_id Card.
 * @param int   $user_id User.
 * @param array $card    Card payload.
 */
function aurora_notify_on_card( $card_id, $user_id, $card ) {
	aurora_notify(
		$user_id,
		__( '卡密兑换成功', 'aurora' ),
		sprintf(
			/* translators: 1: code, 2: value */
			__( '卡密 %1$s 已兑换：%2$s。', 'aurora' ),
			$card['code'],
			function_exists( 'aurora_card_label' ) ? aurora_card_label( $card ) : ''
		),
		array( 'type' => 'card', 'link' => aurora_user_page_url() )
	);
}
add_action( 'aurora_card_redeemed', 'aurora_notify_on_card', 10, 3 );

/**
 * Someone replied to your post.
 *
 * @param int        $comment_id Comment ID.
 * @param int|string $approved   1 when approved.
 */
function aurora_notify_on_reply( $comment_id, $approved ) {
	if ( 1 !== (int) $approved ) {
		return;
	}

	$comment = get_comment( $comment_id );
	if ( ! $comment ) {
		return;
	}

	$post = get_post( $comment->comment_post_ID );
	if ( ! $post ) {
		return;
	}

	$author = (int) $post->post_author;

	// Do not notify yourself, and do not notify a guest post (author 0).
	if ( ! $author || $author === (int) $comment->user_id ) {
		return;
	}

	aurora_notify(
		$author,
		__( '你的文章有新回复', 'aurora' ),
		sprintf(
			/* translators: 1: commenter, 2: post title */
			__( '%1$s 在《%2$s》下留言了。', 'aurora' ),
			$comment->comment_author,
			get_the_title( $post )
		),
		array(
			'type' => 'reply',
			'link' => get_comment_link( $comment ),
		)
	);
}
add_action( 'comment_post', 'aurora_notify_on_reply', 20, 2 );

/**
 * A comment that was held for moderation and then approved.
 *
 * @param string     $new_status New.
 * @param string     $old_status Old.
 * @param WP_Comment $comment    Comment.
 */
function aurora_notify_on_reply_approved( $new_status, $old_status, $comment ) {
	if ( 'approved' === $new_status && 'approved' !== $old_status ) {
		aurora_notify_on_reply( $comment->comment_ID, 1 );
	}
}
add_action( 'transition_comment_status', 'aurora_notify_on_reply_approved', 20, 3 );

/* -------------------------------------------------------------------------
 * AJAX
 * ---------------------------------------------------------------------- */

/**
 * Newest notifications plus the unread count, for the header bell.
 */
function aurora_ajax_notify_fetch() {
	aurora_check_nonce();

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( '请先登录。', 'aurora' ) ), 200 );
	}

	$user_id = get_current_user_id();

	wp_send_json_success( array(
		'unread' => aurora_notify_unread( $user_id ),
		'items'  => aurora_notify_list( $user_id, 10 ),
		'more'   => aurora_user_page_url( 'notify' ),
	) );
}
add_action( 'wp_ajax_aurora_notify_fetch', 'aurora_ajax_notify_fetch' );

/**
 * Mark one or all as read.
 */
function aurora_ajax_notify_read() {
	aurora_check_nonce();

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( '请先登录。', 'aurora' ) ), 200 );
	}

	$user_id = get_current_user_id();
	$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

	$count = aurora_notify_mark_read( $user_id, $id );

	wp_send_json_success( array(
		'marked' => $count,
		'unread' => aurora_notify_unread( $user_id ),
	) );
}
add_action( 'wp_ajax_aurora_notify_read', 'aurora_ajax_notify_read' );

/* -------------------------------------------------------------------------
 * Admin
 * ---------------------------------------------------------------------- */

/**
 * Notification list columns.
 *
 * @param array $columns Columns.
 * @return array
 */
function aurora_notify_columns( $columns ) {
	return array(
		'cb'           => $columns['cb'],
		'title'        => __( '标题', 'aurora' ),
		'aurora_to'    => __( '收件人', 'aurora' ),
		'aurora_kind'  => __( '类型', 'aurora' ),
		'aurora_state' => __( '状态', 'aurora' ),
		'date'         => __( '时间', 'aurora' ),
	);
}
add_filter( 'manage_aurora_notify_posts_columns', 'aurora_notify_columns' );

/**
 * Notification column values.
 *
 * @param string $column  Column.
 * @param int    $post_id Row.
 */
function aurora_notify_column( $column, $post_id ) {
	switch ( $column ) {
		case 'aurora_to':
			$u = get_userdata( (int) get_post_field( 'post_author', $post_id ) );
			echo $u ? esc_html( $u->user_login ) : '—';
			break;

		case 'aurora_kind':
			$type  = (string) get_post_meta( $post_id, '_aurora_notify_type', true );
			$types = aurora_notify_types();
			echo esc_html( isset( $types[ $type ] ) ? $types[ $type ]['label'] : $type );
			break;

		case 'aurora_state':
			echo '1' === (string) get_post_meta( $post_id, AURORA_NOTIFY_READ, true )
				? esc_html__( '已读', 'aurora' )
				: '<strong>' . esc_html__( '未读', 'aurora' ) . '</strong>';
			break;
	}
}
add_action( 'manage_aurora_notify_posts_custom_column', 'aurora_notify_column', 10, 2 );

/**
 * Broadcast page, under the Users menu.
 */
function aurora_notify_admin_menu() {
	add_submenu_page(
		'users.php',
		__( '发送站内通知', 'aurora' ),
		__( '站内通知', 'aurora' ),
		'manage_options',
		'aurora-broadcast',
		'aurora_notify_broadcast_page'
	);
}
add_action( 'admin_menu', 'aurora_notify_admin_menu' );

/**
 * The broadcast form.
 */
function aurora_notify_broadcast_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '权限不足。', 'aurora' ), '', array( 'response' => 403 ) );
	}

	$sent = isset( $_GET['sent'] ) ? (int) $_GET['sent'] : -1;
	?>
	<div class="wrap">
		<h1><?php esc_html_e( '发送站内通知', 'aurora' ); ?></h1>

		<?php if ( $sent >= 0 ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						/* translators: %d: number of recipients */
						esc_html__( '已发送给 %d 位用户。', 'aurora' ),
						$sent
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aurora_broadcast">
			<?php wp_nonce_field( 'aurora_broadcast' ); ?>

			<table class="form-table">
				<tr>
					<th><label for="aurora_bc_title"><?php esc_html_e( '标题', 'aurora' ); ?></label></th>
					<td><input type="text" class="regular-text" id="aurora_bc_title" name="title" required></td>
				</tr>
				<tr>
					<th><label for="aurora_bc_body"><?php esc_html_e( '内容', 'aurora' ); ?></label></th>
					<td><textarea id="aurora_bc_body" name="body" rows="5" class="large-text"></textarea></td>
				</tr>
				<tr>
					<th><label for="aurora_bc_link"><?php esc_html_e( '链接', 'aurora' ); ?></label></th>
					<td>
						<input type="url" class="regular-text" id="aurora_bc_link" name="link" placeholder="https://">
						<p class="description"><?php esc_html_e( '可留空。', 'aurora' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="aurora_bc_audience"><?php esc_html_e( '发送对象', 'aurora' ); ?></label></th>
					<td>
						<select id="aurora_bc_audience" name="audience">
							<option value="all"><?php esc_html_e( '全部用户', 'aurora' ); ?></option>
							<option value="members"><?php esc_html_e( '仅会员', 'aurora' ); ?></option>
						</select>
					</td>
				</tr>
			</table>

			<p class="description" style="max-width:640px">
				<?php esc_html_e( '群发会为每位收件人写入一条记录。用户量很大时请分批发送。', 'aurora' ); ?>
			</p>

			<?php submit_button( __( '发送', 'aurora' ) ); ?>
		</form>
	</div>
	<?php
}

/**
 * Handle a broadcast.
 */
function aurora_handle_broadcast() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '权限不足。', 'aurora' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'aurora_broadcast' );

	$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
	$body     = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '';
	$link     = isset( $_POST['link'] ) ? esc_url_raw( wp_unslash( $_POST['link'] ) ) : '';
	$audience = isset( $_POST['audience'] ) ? sanitize_key( $_POST['audience'] ) : 'all';

	if ( '' === $title ) {
		wp_safe_redirect( admin_url( 'users.php?page=aurora-broadcast' ) );
		exit;
	}

	$args = array( 'fields' => 'ID', 'number' => 5000 );

	if ( 'members' === $audience ) {
		$args['meta_query'] = array(
			array( 'key' => 'aurora_vip_level', 'value' => 1, 'compare' => '>=' ),
		);
	}

	$users = get_users( $args );
	$sent  = 0;

	foreach ( $users as $user_id ) {
		$result = aurora_notify( (int) $user_id, $title, $body, array(
			'type' => 'system',
			'link' => $link,
		) );

		if ( ! is_wp_error( $result ) ) {
			$sent++;
		}
	}

	wp_safe_redirect( admin_url( 'users.php?page=aurora-broadcast&sent=' . $sent ) );
	exit;
}
add_action( 'admin_post_aurora_broadcast', 'aurora_handle_broadcast' );
