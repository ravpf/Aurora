<?php
/**
 * Transactional email.
 *
 * Sends HTML receipts for the commerce events the theme itself raises. Delivery
 * is either PHP's mail() (whatever the host provides) or SMTP, configured here
 * because a stock server usually has no MTA at all — WordPress's bundled
 * PHPMailer is re-pointed through `phpmailer_init`, so no extra dependency is
 * needed.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Configuration
 * ---------------------------------------------------------------------- */

/**
 * Events that can be mailed, with their labels.
 *
 * @return array
 */
function aurora_mail_events() {
	return array(
		'order_created'  => __( '下单成功（待支付）', 'aurora' ),
		'order_paid'     => __( '支付成功 / 权益发放', 'aurora' ),
		'refund_settled' => __( '退款成功', 'aurora' ),
		'vip_expiring'   => __( '会员到期前提醒', 'aurora' ),
	);
}

/**
 * Is an event switched on?
 *
 * @param string $event Event key.
 * @return bool
 */
function aurora_mail_event_enabled( $event ) {
	// Master switch first: when notifications are off, nothing is sent.
	if ( ! get_theme_mod( 'aurora_mail_enabled', true ) ) {
		return false;
	}

	$enabled = get_theme_mod( 'aurora_mail_events', null );

	// Never saved: treat everything as on, so a fresh install just works.
	if ( ! is_array( $enabled ) ) {
		$enabled = array( 'order_created', 'order_paid', 'refund_settled', 'vip_expiring' );
	}

	return in_array( $event, $enabled, true );
}

/**
 * From address, falling back to the site identity.
 *
 * @return string
 */
function aurora_mail_from_address() {
	$from = trim( (string) get_theme_mod( 'aurora_mail_from_email', '' ) );
	if ( $from && is_email( $from ) ) {
		return $from;
	}

	$admin = get_option( 'admin_email' );

	return is_email( $admin ) ? $admin : 'wordpress@' . wp_parse_url( home_url(), PHP_URL_HOST );
}

/**
 * From name.
 *
 * @return string
 */
function aurora_mail_from_name() {
	$name = trim( (string) get_theme_mod( 'aurora_mail_from_name', '' ) );

	return $name ? $name : get_bloginfo( 'name' );
}

/**
 * Apply the sender identity to every message WordPress sends.
 *
 * @param string $value Incoming value.
 * @return string
 */
function aurora_mail_filter_from( $value ) {
	return aurora_mail_from_address();
}
add_filter( 'wp_mail_from', 'aurora_mail_filter_from', 99 );

/**
 * @param string $value Incoming value.
 * @return string
 */
function aurora_mail_filter_from_name( $value ) {
	return esc_html( aurora_mail_from_name() );
}
add_filter( 'wp_mail_from_name', 'aurora_mail_filter_from_name', 99 );

/**
 * Only our own messages are HTML; everything else keeps WordPress's default.
 *
 * @param string $type Incoming content type.
 * @return string
 */
function aurora_mail_filter_content_type( $type ) {
	return aurora_mail_rendering() ? 'text/html' : $type;
}
add_filter( 'wp_mail_content_type', 'aurora_mail_filter_content_type', 99 );

/**
 * Are we currently composing one of our HTML emails?
 *
 * @param bool|null $set Set the flag.
 * @return bool
 */
function aurora_mail_rendering( $set = null ) {
	static $on = false;

	if ( null !== $set ) {
		$on = (bool) $set;
	}

	return $on;
}

/**
 * Re-point PHPMailer at the configured SMTP server.
 *
 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance.
 */
function aurora_mail_smtp_setup( $phpmailer ) {
	if ( ! get_theme_mod( 'aurora_mail_smtp', false ) ) {
		return;
	}

	$host = trim( (string) get_theme_mod( 'aurora_mail_smtp_host', '' ) );
	if ( ! $host ) {
		return;
	}

	$secure = (string) get_theme_mod( 'aurora_mail_smtp_secure', 'ssl' );
	$port   = (int) get_theme_mod( 'aurora_mail_smtp_port', 0 );

	if ( $port < 1 ) {
		$port = ( 'ssl' === $secure ) ? 465 : ( ( 'tls' === $secure ) ? 587 : 25 );
	}

	$phpmailer->isSMTP();
	$phpmailer->Host    = $host;
	$phpmailer->Port    = $port;
	$phpmailer->CharSet = 'UTF-8';

	// 'ssl' wraps the socket from the start (SMTPSecure); 'tls' negotiates
	// STARTTLS mid-session (SMTPAutoTLS). 'none' must disable both, or PHPMailer
	// will opportunistically upgrade when the server advertises STARTTLS.
	$phpmailer->SMTPSecure   = ( 'none' === $secure ) ? '' : $secure;
	$phpmailer->SMTPAutoTLS  = ( 'tls' === $secure );

	$user = trim( (string) get_theme_mod( 'aurora_mail_smtp_user', '' ) );
	$pass = (string) get_theme_mod( 'aurora_mail_smtp_pass', '' );

	if ( $user ) {
		$phpmailer->SMTPAuth = true;
		$phpmailer->Username = $user;
		$phpmailer->Password = $pass;
	} else {
		$phpmailer->SMTPAuth = false;
	}
}
add_action( 'phpmailer_init', 'aurora_mail_smtp_setup', 99 );

/* -------------------------------------------------------------------------
 * Rendering
 * ---------------------------------------------------------------------- */

/**
 * Wrap content in the site's mail shell.
 *
 * Inline styles only: Gmail and QQ Mail strip <style> blocks.
 *
 * @param string $title   Heading.
 * @param string $intro   Lead paragraph.
 * @param array  $rows    label => value rows.
 * @param array  $cta     [text, url] or empty.
 * @param string $note    Small print.
 * @return string
 */
function aurora_mail_shell( $title, $intro = '', $rows = array(), $cta = array(), $note = '' ) {
	$site  = get_bloginfo( 'name' );
	$home  = home_url( '/' );
	$color = (string) get_theme_mod( 'aurora_accent', '#0071e3' );

	if ( ! preg_match( '/^#[0-9a-f]{3,8}$/i', $color ) ) {
		$color = '#0071e3';
	}

	$rows_html = '';
	foreach ( (array) $rows as $label => $value ) {
		$rows_html .= sprintf(
			'<tr><td style="padding:9px 0;color:#6e6e73;font-size:14px;border-bottom:1px solid #ececef">%s</td>'
			. '<td style="padding:9px 0;color:#1d1d1f;font-size:14px;text-align:right;border-bottom:1px solid #ececef">%s</td></tr>',
			esc_html( $label ),
			wp_kses_post( $value )
		);
	}

	$cta_html = '';
	if ( ! empty( $cta['text'] ) && ! empty( $cta['url'] ) ) {
		$cta_html = sprintf(
			'<tr><td style="padding:26px 0 6px"><a href="%s" style="display:inline-block;padding:12px 26px;background:%s;color:#fff;'
			. 'text-decoration:none;border-radius:980px;font-size:15px;font-weight:500">%s</a></td></tr>',
			esc_url( $cta['url'] ),
			esc_attr( $color ),
			esc_html( $cta['text'] )
		);
	}

	return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
		. '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
		. '<body style="margin:0;padding:32px 16px;background:#f5f5f7;'
		. '-apple-system,BlinkMacSystemFont,\'SF Pro Text\',\'PingFang SC\',\'Microsoft YaHei\',sans-serif">'
		. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:560px;margin:0 auto">'
		. '<tr><td style="background:#fff;border-radius:20px;padding:34px 30px;border:1px solid #e6e6ea">'
		. '<div style="font-size:13px;font-weight:600;letter-spacing:.02em;color:' . esc_attr( $color ) . ';margin-bottom:14px">'
		. esc_html( $site ) . '</div>'
		. '<h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;color:#1d1d1f;font-weight:600;letter-spacing:-.02em">' . esc_html( $title ) . '</h1>'
		. ( $intro ? '<p style="margin:0 0 20px;font-size:15px;line-height:1.65;color:#3a3a3c">' . wp_kses_post( $intro ) . '</p>' : '' )
		. ( $rows_html ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">' . $rows_html . '</table>' : '' )
		. '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tbody>' . $cta_html . '</tbody></table>'
		. ( $note ? '<p style="margin:22px 0 0;font-size:12.5px;line-height:1.6;color:#8e8e93">' . wp_kses_post( $note ) . '</p>' : '' )
		. '</td></tr>'
		. '<tr><td style="padding:18px 6px 0;font-size:12px;color:#8e8e93;text-align:center;line-height:1.6">'
		. '<a href="' . esc_url( $home ) . '" style="color:#8e8e93;text-decoration:none">' . esc_html( $site ) . '</a>'
		. '<br>' . esc_html__( '这是一封系统邮件，请勿直接回复。', 'aurora' )
		. '</td></tr></table></body></html>';
}

/**
 * Send one of the theme's HTML emails.
 *
 * @param string $to       Recipient.
 * @param string $subject  Subject line.
 * @param string $title    Heading.
 * @param string $intro    Lead paragraph.
 * @param array  $rows     label => value rows.
 * @param array  $cta      [text, url].
 * @param string $note     Small print.
 * @return bool
 */
function aurora_send_mail( $to, $subject, $title, $intro = '', $rows = array(), $cta = array(), $note = '' ) {
	if ( ! is_email( $to ) ) {
		return false;
	}

	aurora_mail_rendering( true );
	$sent = wp_mail(
		$to,
		$subject,
		aurora_mail_shell( $title, $intro, $rows, $cta, $note ),
		array( 'Content-Type: text/html; charset=UTF-8' )
	);
	aurora_mail_rendering( false );

	/**
	 * Fires after a theme email is attempted.
	 *
	 * @param bool   $sent    Whether wp_mail() reported success.
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 */
	do_action( 'aurora_mail_sent', $sent, $to, $subject );

	return (bool) $sent;
}

/**
 * Recipient for a user ID.
 *
 * @param int $user_id User ID.
 * @return string
 */
function aurora_mail_user_address( $user_id ) {
	$user = get_userdata( (int) $user_id );

	return $user && is_email( $user->user_email ) ? $user->user_email : '';
}

/* -------------------------------------------------------------------------
 * Triggers
 * ---------------------------------------------------------------------- */

/**
 * Order placed, awaiting payment.
 *
 * @param int   $order_id Order post ID.
 * @param array $args     Order args.
 */
function aurora_mail_order_created( $order_id, $args ) {
	if ( ! aurora_mail_event_enabled( 'order_created' ) ) {
		return;
	}

	$order = aurora_get_order( $order_id );
	$to    = aurora_mail_user_address( $order['user_id'] );
	if ( ! $to ) {
		return;
	}

	$rows = array(
		__( '订单号', 'aurora' ) => '<code>' . esc_html( $order['no'] ) . '</code>',
		__( '商品', 'aurora' )   => esc_html( $order['product'] ),
		__( '应付金额', 'aurora' ) => '<strong>¥' . esc_html( number_format( $order['amount'], 2 ) ) . '</strong>',
	);

	if ( $order['amount'] <= 0 ) {
		return; // A fully discounted order auto-completes; the receipt covers it.
	}

	aurora_send_mail(
		$to,
		sprintf( __( '【%1$s】订单已创建 · %2$s', 'aurora' ), get_bloginfo( 'name' ), $order['no'] ),
		__( '订单已创建，等待支付', 'aurora' ),
		__( '订单已为你保留。完成支付后权益会自动到账，无需联系客服。', 'aurora' ),
		$rows,
		array( 'text' => __( '查看订单与支付', 'aurora' ), 'url' => add_query_arg( 'order', $order_id, aurora_user_page_url() ) ),
		__( '如果这不是你本人的操作，忽略本邮件即可，订单会自动过期。', 'aurora' )
	);
}
add_action( 'aurora_order_created', 'aurora_mail_order_created', 10, 2 );

/**
 * Payment settled: receipt plus what was granted.
 *
 * @param int   $order_id Order post ID.
 * @param array $order    Order snapshot taken before granting.
 */
function aurora_mail_order_paid( $order_id, $order ) {
	if ( ! aurora_mail_event_enabled( 'order_paid' ) ) {
		return;
	}

	// Re-read: the caller's snapshot predates the status flip.
	$order = aurora_get_order( $order_id );
	$to    = aurora_mail_user_address( $order['user_id'] );
	if ( ! $to ) {
		return;
	}

	$coupon = (string) get_post_meta( $order_id, '_aurora_coupon', true );
	$base   = (float) get_post_meta( $order_id, '_aurora_original_amount', true );

	$rows = array(
		__( '订单号', 'aurora' ) => '<code>' . esc_html( $order['no'] ) . '</code>',
		__( '商品', 'aurora' )   => esc_html( $order['product'] ),
	);

	if ( $base > 0 && $coupon ) {
		$rows[ __( '原价', 'aurora' ) ]   = '¥' . esc_html( number_format( $base, 2 ) );
		$rows[ __( '优惠码', 'aurora' ) ] = esc_html( $coupon );
	}

	$rows[ __( '实付金额', 'aurora' ) ] = '<strong>¥' . esc_html( number_format( $order['amount'], 2 ) ) . '</strong>';

	if ( $order['level'] > 0 ) {
		$info = aurora_get_vip_level( $order['level'] );
		$rows[ __( '会员等级', 'aurora' ) ] = esc_html( $info ? $info['name'] : ( 'Lv' . $order['level'] ) );
		$rows[ __( '到期时间', 'aurora' ) ] = esc_html( (string) get_user_meta( $order['user_id'], 'aurora_vip_expires', true ) ?: '—' );
	} else {
		$object = (int) get_post_meta( $order_id, '_aurora_object', true );
		if ( $object ) {
			$rows[ __( '已解锁文章', 'aurora' ) ] = esc_html( get_the_title( $object ) );
		}
	}

	$cta = $order['level'] > 0
		? array( 'text' => __( '查看我的会员', 'aurora' ), 'url' => aurora_vip_page_url() )
		: array( 'text' => __( '开始阅读', 'aurora' ), 'url' => $object ? get_permalink( $object ) : aurora_user_page_url() );

	aurora_send_mail(
		$to,
		sprintf( __( '【%1$s】支付成功 · %2$s', 'aurora' ), get_bloginfo( 'name' ), $order['no'] ),
		__( '支付成功', 'aurora' ),
		__( '收到你的付款，权益已经生效。以下是本笔订单的明细。', 'aurora' ),
		$rows,
		$cta,
		__( '如需退款，请在会员有效期内联系管理员。', 'aurora' )
	);
}
add_action( 'aurora_order_paid', 'aurora_mail_order_paid', 10, 2 );

/**
 * Refund settled.
 *
 * @param int $refund_id Refund post ID.
 * @param int $order_id  Order post ID.
 */
function aurora_mail_refund_settled( $refund_id, $order_id ) {
	if ( ! aurora_mail_event_enabled( 'refund_settled' ) ) {
		return;
	}

	$refund = aurora_get_refund( $refund_id );
	$order  = aurora_get_order( $order_id );
	if ( ! $refund || ! $order['no'] ) {
		return;
	}

	$to = aurora_mail_user_address( $order['user_id'] );
	if ( ! $to ) {
		return;
	}

	$remaining = aurora_order_refundable( $order_id );
	$full      = $remaining <= 0.001;

	$rows = array(
		__( '订单号', 'aurora' )   => '<code>' . esc_html( $order['no'] ) . '</code>',
		__( '退款单号', 'aurora' ) => '<code>' . esc_html( $refund['no'] ) . '</code>',
		__( '退款金额', 'aurora' ) => '<strong>¥' . esc_html( number_format( $refund['amount'], 2 ) ) . '</strong>',
		__( '退款方式', 'aurora' ) => esc_html__( '原路退回', 'aurora' ),
	);

	if ( $refund['reason'] ) {
		$rows[ __( '退款原因', 'aurora' ) ] = esc_html( $refund['reason'] );
	}

	$note = $full
		? __( '本笔订单已全额退款，对应的会员权益已同步收回。到账时间以支付渠道为准，通常 1–3 个工作日。', 'aurora' )
		: __( '本笔为部分退款，剩余金额仍未退。到账时间以支付渠道为准，通常 1–3 个工作日。', 'aurora' );

	aurora_send_mail(
		$to,
		sprintf( __( '【%1$s】退款成功 · %2$s', 'aurora' ), get_bloginfo( 'name' ), $refund['no'] ),
		__( '退款已处理', 'aurora' ),
		__( '退款已经由支付渠道受理。', 'aurora' ),
		$rows,
		array( 'text' => __( '查看订单记录', 'aurora' ), 'url' => aurora_user_page_url() ),
		$note
	);
}
add_action( 'aurora_refund_settled', 'aurora_mail_refund_settled', 10, 2 );

/**
 * Membership granted outside the order flow (admin action, import, gift).
 *
 * @param int    $user_id User ID.
 * @param int    $level   Level.
 * @param string $expires Expiry.
 * @param string $source  Origin note.
 */
function aurora_mail_vip_granted( $user_id, $level, $expires, $source ) {
	// Orders already send a richer receipt; only mail the manual grants.
	if ( 0 === strpos( (string) $source, 'order:' ) ) {
		return;
	}
	if ( ! aurora_mail_event_enabled( 'order_paid' ) ) {
		return;
	}

	$to = aurora_mail_user_address( $user_id );
	if ( ! $to ) {
		return;
	}

	$info = aurora_get_vip_level( $level );

	aurora_send_mail(
		$to,
		sprintf( __( '【%s】会员已开通', 'aurora' ), get_bloginfo( 'name' ) ),
		__( '你的会员已开通', 'aurora' ),
		__( '管理员已为你的账号开通会员权益。', 'aurora' ),
		array(
			__( '会员等级', 'aurora' ) => esc_html( $info ? $info['name'] : ( 'Lv' . $level ) ),
			__( '到期时间', 'aurora' ) => esc_html( $expires ?: '—' ),
		),
		array( 'text' => __( '查看会员权益', 'aurora' ), 'url' => aurora_vip_page_url() )
	);
}
add_action( 'aurora_vip_granted', 'aurora_mail_vip_granted', 10, 4 );

/**
 * Send the expiry reminders. Called by the daily cron job.
 *
 * A user is reminded once per expiry date: the reminder is stamped with the
 * expiry it referred to, so renewing and expiring again reminds them again.
 *
 * @param int $days_ahead How many days before expiry to warn.
 * @return int Number of reminders sent.
 */
function aurora_mail_expiry_reminders( $days_ahead = 3 ) {
	if ( ! aurora_mail_event_enabled( 'vip_expiring' ) ) {
		return 0;
	}

	$days_ahead = max( 1, (int) $days_ahead );
	$sent       = 0;
	$now        = time();
	$horizon    = $now + ( $days_ahead * DAY_IN_SECONDS );

	$users = get_users( array(
		'meta_key'     => 'aurora_vip_expires',
		'meta_compare' => 'EXISTS',
		'fields'       => array( 'ID' ),
	) );

	foreach ( $users as $user ) {
		$expires = (string) get_user_meta( $user->ID, 'aurora_vip_expires', true );
		$stamp   = strtotime( $expires );

		if ( ! $stamp || $stamp <= $now || $stamp > $horizon ) {
			continue;
		}

		if ( get_user_meta( $user->ID, 'aurora_expiry_reminded_for', true ) === $expires ) {
			continue;
		}

		$level = (int) get_user_meta( $user->ID, 'aurora_vip_level', true );
		$info  = aurora_get_vip_level( $level );
		$to    = aurora_mail_user_address( $user->ID );

		if ( ! $to ) {
			continue;
		}

		$ok = aurora_send_mail(
			$to,
			sprintf( __( '【%s】会员即将到期', 'aurora' ), get_bloginfo( 'name' ) ),
			__( '你的会员即将到期', 'aurora' ),
			sprintf(
				/* translators: %d: days */
				esc_html__( '还有 %d 天，你的会员权益就要到期了。续费后有效期会顺延，不会浪费剩余天数。', 'aurora' ),
				(int) ceil( ( $stamp - $now ) / DAY_IN_SECONDS )
			),
			array(
				__( '当前等级', 'aurora' ) => esc_html( $info ? $info['name'] : ( 'Lv' . $level ) ),
				__( '到期时间', 'aurora' ) => esc_html( $expires ),
			),
			array( 'text' => __( '立即续费', 'aurora' ), 'url' => aurora_vip_page_url() )
		);

		update_user_meta( $user->ID, 'aurora_expiry_reminded_for', $expires );

		if ( $ok ) {
			$sent++;
		}
	}

	return $sent;
}

/* -------------------------------------------------------------------------
 * Diagnostics panel on the 邮件通知 tab
 * ---------------------------------------------------------------------- */

/**
 * Show the current delivery path, the last test result, and a test button.
 *
 * @param string $tab Current settings tab.
 */
function aurora_mail_settings_panel( $tab ) {
	if ( 'mail' !== $tab ) {
		return;
	}

	$smtp   = (bool) get_theme_mod( 'aurora_mail_smtp', false );
	$host   = trim( (string) get_theme_mod( 'aurora_mail_smtp_host', '' ) );
	$user   = trim( (string) get_theme_mod( 'aurora_mail_smtp_user', '' ) );
	$pass   = (string) get_theme_mod( 'aurora_mail_smtp_pass', '' );
	$secure = (string) get_theme_mod( 'aurora_mail_smtp_secure', 'ssl' );
	$port   = (int) get_theme_mod( 'aurora_mail_smtp_port', 465 );

	$transport = $smtp
		? sprintf( 'SMTP %s:%d（%s%s）', $host ?: '—', $port, strtoupper( $secure ?: 'none' ), $user ? '，已认证' : '，不认证' )
		: __( 'PHP mail() —— 本机若没有邮件服务，邮件会静默丢弃', 'aurora' );

	$result = get_transient( 'aurora_mail_test_' . get_current_user_id() );
	if ( $result ) {
		delete_transient( 'aurora_mail_test_' . get_current_user_id() );
	}

	$ready = true;
	if ( $smtp && ! $host ) {
		$ready = false;
	}
	// A relay on localhost or the internal network legitimately needs no
	// credentials, so only treat a username without a password as incomplete.
	if ( $smtp && $user && ! $pass ) {
		$ready = false;
	}
	if ( ! is_email( aurora_mail_from_address() ) ) {
		$ready = false;
	}
	?>
	<hr style="margin:32px 0 22px;max-width:820px">
	<h2 style="font-size:15px"><?php esc_html_e( '发信状态', 'aurora' ); ?></h2>

	<table class="widefat striped" style="max-width:820px">
		<tbody>
			<tr>
				<th style="width:180px"><?php esc_html_e( '发件人', 'aurora' ); ?></th>
				<td><?php echo esc_html( aurora_mail_from_name() . ' <' . aurora_mail_from_address() . '>' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( '发送通道', 'aurora' ); ?></th>
				<td><?php echo esc_html( $transport ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( '启用的通知', 'aurora' ); ?></th>
				<td>
					<?php
					$on = array();
					foreach ( aurora_mail_events() as $key => $label ) {
						if ( aurora_mail_event_enabled( $key ) ) {
							$on[] = $label;
						}
					}
					echo esc_html( $on ? implode( ' · ', $on ) : __( '（全部关闭）', 'aurora' ) );
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( '配置检查', 'aurora' ); ?></th>
				<td>
					<?php if ( $ready ) : ?>
						<span style="color:#1a7f37"><?php esc_html_e( '参数看起来是完整的', 'aurora' ); ?></span>
					<?php else : ?>
						<span style="color:#b32d2e"><?php esc_html_e( '缺少必填项（SMTP 服务器、只填了账号却没填密码，或发件人邮箱无效）', 'aurora' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $result ) : ?>
				<tr>
					<th><?php esc_html_e( '上次测试', 'aurora' ); ?></th>
					<td>
						<?php if ( 'ok' === $result ) : ?>
							<strong style="color:#1a7f37"><?php esc_html_e( '发送成功，请查收收件箱（含垃圾邮件箱）', 'aurora' ); ?></strong>
						<?php else : ?>
							<strong style="color:#b32d2e"><?php echo esc_html( $result ); ?></strong>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>

	<p style="margin-top:16px">
		<a class="button button-secondary"
			href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aurora_mail_test' ), 'aurora_mail_test' ) ); ?>">
			<?php esc_html_e( '发送测试邮件给我', 'aurora' ); ?>
		</a>
		<span class="description" style="margin-left:10px">
			<?php
			printf(
				/* translators: %s: admin email */
				esc_html__( '会发往 %s。先保存设置再测试。', 'aurora' ),
				esc_html( wp_get_current_user()->user_email )
			);
			?>
		</span>
	</p>

	<p class="description" style="max-width:820px;line-height:1.7;margin-top:14px">
		<?php esc_html_e( '会员到期提醒需要一个真实的定时任务（WordPress 自带定时并不精确）。服务器上已配置每天运行一次：', 'aurora' ); ?><br>
		<code>wp aurora expiry-reminders --days=3</code>
	</p>
	<?php
}
add_action( 'aurora_after_settings_tab', 'aurora_mail_settings_panel' );

/* -------------------------------------------------------------------------
 * Test send
 * ---------------------------------------------------------------------- */

/**
 * Admin action: send a test email to the current user.
 */
function aurora_handle_mail_test() {
	// Hooked to admin_post_aurora_mail_test, so the hook itself is the trigger:
	// this runs only for our own action. Do NOT move this to admin_init —
	// wp-admin/admin-ajax.php fires admin_init on every AJAX request, and a
	// capability check here would refuse every non-administrator's like,
	// bookmark, check-in and coupon call.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '权限不足。', 'aurora' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'aurora_mail_test' );

	$user = wp_get_current_user();

	$sent = aurora_send_mail(
		$user->user_email,
		sprintf( __( '【%s】邮件发送测试', 'aurora' ), get_bloginfo( 'name' ) ),
		__( '邮件通道工作正常', 'aurora' ),
		__( '如果你看到这封邮件，说明收件人、发件人和发信通道都已配置成功。', 'aurora' ),
		array(
			__( '发件人', 'aurora' ) => esc_html( aurora_mail_from_name() . ' <' . aurora_mail_from_address() . '>' ),
			__( '发送方式', 'aurora' ) => esc_html( get_theme_mod( 'aurora_mail_smtp', false ) ? 'SMTP' : 'PHP mail()' ),
			__( '收件人', 'aurora' ) => esc_html( $user->user_email ),
		),
		array( 'text' => __( '返回主题设置', 'aurora' ), 'url' => admin_url( 'admin.php?page=aurora-settings&tab=mail' ) ),
		__( '这封邮件由管理员手动触发。', 'aurora' )
	);

	if ( $sent ) {
		set_transient( 'aurora_mail_test_' . $user->ID, 'ok', 60 );
	} else {
		set_transient(
			'aurora_mail_test_' . $user->ID,
			sprintf(
				/* translators: %s: last mailer error, if any */
				__( '发送失败。当前用的是 %s。如果主机没有邮件服务，请在下方开启 SMTP 并填写参数。', 'aurora' ),
				get_theme_mod( 'aurora_mail_smtp', false ) ? 'SMTP' : 'PHP mail()'
			),
			60
		);
	}

	wp_safe_redirect( admin_url( 'admin.php?page=aurora-settings&tab=mail&aurora_mail_sent=1' ) );
	exit;
}
add_action( 'admin_post_aurora_mail_test', 'aurora_handle_mail_test' );

/* -------------------------------------------------------------------------
 * WP-CLI
 * ---------------------------------------------------------------------- */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Drive the theme's scheduled jobs from a real system cron.
	 */
	class Aurora_CLI {

		/**
		 * Send membership expiry reminders.
		 *
		 * ## OPTIONS
		 *
		 * [--days=<n>]
		 * : Days before expiry to warn. Default 3.
		 *
		 * ## EXAMPLES
		 *
		 *     wp aurora expiry-reminders --days=3
		 *
		 * @subcommand expiry-reminders
		 * @param array $args       Positional args.
		 * @param array $assoc_args Flags.
		 */
		public function expiry_reminders( $args, $assoc_args ) {
			$days = isset( $assoc_args['days'] ) ? (int) $assoc_args['days'] : 3;
			$sent = aurora_mail_expiry_reminders( $days );

			WP_CLI::success( sprintf( 'Sent %d expiry reminder(s).', $sent ) );
		}

		/**
		 * Render a sample receipt to stdout, to check the template without sending.
		 *
		 * @subcommand mail-preview
		 * @param array $args       Positional args.
		 * @param array $assoc_args Flags.
		 */
		public function mail_preview( $args, $assoc_args ) {
			echo aurora_mail_shell( // phpcs:ignore WordPress.Security.EscapeOutput
				'支付成功',
				'收到你的付款，权益已经生效。',
				array( '订单号' => '<code>A20261015ABCD1234</code>', '实付金额' => '<strong>¥39.00</strong>' ),
				array( 'text' => '查看我的会员', 'url' => home_url( '/' ) ),
				'如需退款，请在会员有效期内联系管理员。'
			);
		}
	}

	WP_CLI::add_command( 'aurora', 'Aurora_CLI' );
}
