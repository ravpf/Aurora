<?php
/**
 * Newsletter subscriptions.
 *
 * Double opt-in, because a list built from unconfirmed addresses is a spam
 * complaint waiting to happen: subscribing creates a pending record, the
 * confirmation link activates it, and every message carries a working
 * unsubscribe link.
 *
 * Subscribers live in a private post type so they show up in wp-admin with no
 * bespoke table, can be searched and deleted from the normal UI, and never leak
 * into search or the REST API.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the subscriber store (admin-only; not public).
 */
function aurora_newsletter_register_cpt() {
	register_post_type(
		'aurora_subscriber',
		array(
			'labels'          => array(
				'name'          => __( '订阅者', 'aurora' ),
				'singular_name' => __( '订阅者', 'aurora' ),
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'menu_icon'       => 'dashicons-email-alt',
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
			'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
			'map_meta_cap'    => true,
		)
	);
}
add_action( 'init', 'aurora_newsletter_register_cpt' );

/**
 * Is the newsletter open?
 *
 * @return bool
 */
function aurora_newsletter_enabled() {
	return (bool) get_theme_mod( 'aurora_newsletter_enabled', true );
}

/**
 * Find a subscriber by address.
 *
 * @param string $email Email.
 * @return WP_Post|null
 */
function aurora_newsletter_find( $email ) {
	$email = strtolower( trim( (string) $email ) );

	if ( ! $email ) {
		return null;
	}

	$found = get_posts(
		array(
			'post_type'      => 'aurora_subscriber',
			'post_status'    => 'private',
			'title'          => $email,
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		)
	);

	return $found ? $found[0] : null;
}

/**
 * Add a subscription in the pending state.
 *
 * @param string $email  Email.
 * @param string $source Where it came from.
 * @return int|WP_Error Subscriber id.
 */
function aurora_newsletter_subscribe( $email, $source = 'form' ) {
	$email = strtolower( trim( (string) $email ) );

	if ( ! is_email( $email ) ) {
		return new WP_Error( 'bad_email', __( '邮箱格式不正确。', 'aurora' ) );
	}

	$existing = aurora_newsletter_find( $email );

	if ( $existing ) {
		$state = get_post_meta( $existing->ID, '_aurora_sub_state', true );

		if ( 'confirmed' === $state ) {
			return new WP_Error( 'already', __( '这个邮箱已经订阅过了。', 'aurora' ) );
		}

		// Still pending: re-send (a lost confirmation mail is the usual reason
		// someone subscribes twice), but do not create a second record.
		update_post_meta( $existing->ID, '_aurora_sub_token', wp_generate_password( 24, false ) );

		return (int) $existing->ID;
	}

	$id = wp_insert_post(
		array(
			'post_type'   => 'aurora_subscriber',
			'post_status' => 'private',
			'post_title'  => $email,
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	update_post_meta( $id, '_aurora_sub_state', 'pending' );
	update_post_meta( $id, '_aurora_sub_token', wp_generate_password( 24, false ) );
	update_post_meta( $id, '_aurora_sub_source', sanitize_key( $source ) );
	update_post_meta( $id, '_aurora_sub_at', current_time( 'mysql' ) );

	return (int) $id;
}

/**
 * Confirm a subscription by token.
 *
 * @param string $token Token.
 * @return WP_Post|WP_Error
 */
function aurora_newsletter_confirm( $token ) {
	$token = sanitize_text_field( (string) $token );

	if ( ! $token ) {
		return new WP_Error( 'no_token', __( '链接不完整。', 'aurora' ) );
	}

	$found = get_posts(
		array(
			'post_type'      => 'aurora_subscriber',
			'post_status'    => 'private',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_key'       => '_aurora_sub_token',
			'meta_value'     => $token,
		)
	);

	if ( ! $found ) {
		return new WP_Error( 'bad_token', __( '链接已失效。', 'aurora' ) );
	}

	$sub = $found[0];
	update_post_meta( $sub->ID, '_aurora_sub_state', 'confirmed' );
	update_post_meta( $sub->ID, '_aurora_sub_confirmed', current_time( 'mysql' ) );

	return $sub;
}

/**
 * Unsubscribe by token.
 *
 * @param string $token Token.
 * @return WP_Post|WP_Error
 */
function aurora_newsletter_unsubscribe( $token ) {
	$token = sanitize_text_field( (string) $token );

	$found = get_posts(
		array(
			'post_type'      => 'aurora_subscriber',
			'post_status'    => 'private',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_key'       => '_aurora_sub_token',
			'meta_value'     => $token,
		)
	);

	if ( ! $found ) {
		return new WP_Error( 'bad_token', __( '链接已失效。', 'aurora' ) );
	}

	wp_delete_post( $found[0]->ID, true );

	return $found[0];
}

/**
 * Confirmed subscribers.
 *
 * @return WP_Post[]
 */
function aurora_newsletter_confirmed() {
	return get_posts(
		array(
			'post_type'      => 'aurora_subscriber',
			'post_status'    => 'private',
			'posts_per_page' => 2000,
			'no_found_rows'  => true,
			'meta_key'       => '_aurora_sub_state',
			'meta_value'     => 'confirmed',
		)
	);
}

/**
 * The signup form.
 *
 * @param string $source Where the form lives.
 */
function aurora_newsletter_form( $source = 'form' ) {
	if ( ! aurora_newsletter_enabled() ) {
		return;
	}

	$sent = isset( $_GET['subscribed'] ) ? sanitize_key( wp_unslash( $_GET['subscribed'] ) ) : '';
	?>
	<form class="aurora-newsletter" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="aurora_subscribe">
		<input type="hidden" name="aurora_source" value="<?php echo esc_attr( $source ); ?>">
		<?php wp_nonce_field( 'aurora_subscribe', 'aurora_subscribe_nonce' ); ?>

		<label class="aurora-newsletter__label" for="aurora-sub-email"><?php esc_html_e( '订阅更新', 'aurora' ); ?></label>
		<div class="aurora-newsletter__row">
			<input id="aurora-sub-email" type="email" name="aurora_email" required
				placeholder="<?php esc_attr_e( '你的邮箱', 'aurora' ); ?>">
			<button type="submit" class="aurora-btn aurora-btn--primary"><?php esc_html_e( '订阅', 'aurora' ); ?></button>
		</div>
		<p class="aurora-newsletter__hint">
			<?php esc_html_e( '会先发一封确认邮件，点里面的链接才算订阅成功。', 'aurora' ); ?>
		</p>

		<?php if ( 'ok' === $sent ) : ?>
			<p class="aurora-newsletter__hint is-ok"><?php esc_html_e( '确认邮件已发出，请查收（含垃圾邮件箱）。', 'aurora' ); ?></p>
		<?php elseif ( 'exists' === $sent ) : ?>
			<p class="aurora-newsletter__hint is-err"><?php esc_html_e( '这个邮箱已经订阅过了。', 'aurora' ); ?></p>
		<?php elseif ( 'bad' === $sent ) : ?>
			<p class="aurora-newsletter__hint is-err"><?php esc_html_e( '邮箱格式不正确。', 'aurora' ); ?></p>
		<?php endif; ?>
	</form>
	<?php
}

/**
 * Handle the signup post.
 */
function aurora_newsletter_handle() {
	$back = wp_get_referer() ? wp_get_referer() : home_url( '/' );
	$back = remove_query_arg( array( 'subscribed', 'confirm', 'unsub' ), $back );

	if ( ! isset( $_POST['aurora_subscribe_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['aurora_subscribe_nonce'] ) ), 'aurora_subscribe' ) ) {
		wp_safe_redirect( add_query_arg( 'subscribed', 'bad', $back ) );
		exit;
	}

	if ( function_exists( 'aurora_rate_guard' ) ) {
		$gate = aurora_rate_guard( 'subscribe' );
		if ( is_wp_error( $gate ) ) {
			wp_safe_redirect( add_query_arg( 'subscribed', 'bad', $back ) );
			exit;
		}
	}

	$email  = isset( $_POST['aurora_email'] ) ? sanitize_email( wp_unslash( $_POST['aurora_email'] ) ) : '';
	$source = isset( $_POST['aurora_source'] ) ? sanitize_key( wp_unslash( $_POST['aurora_source'] ) ) : 'form';
	$result = aurora_newsletter_subscribe( $email, $source );

	if ( is_wp_error( $result ) ) {
		$code = 'already' === $result->get_error_code() ? 'exists' : 'bad';
		wp_safe_redirect( add_query_arg( 'subscribed', $code, $back ) );
		exit;
	}

	aurora_newsletter_send_confirmation( $result );

	wp_safe_redirect( add_query_arg( 'subscribed', 'ok', $back ) );
	exit;
}
add_action( 'admin_post_aurora_subscribe', 'aurora_newsletter_handle' );
add_action( 'admin_post_nopriv_aurora_subscribe', 'aurora_newsletter_handle' );

/**
 * Mail the confirmation link.
 *
 * @param int $sub_id Subscriber id.
 */
function aurora_newsletter_send_confirmation( $sub_id ) {
	$token = get_post_meta( $sub_id, '_aurora_sub_token', true );
	$email = get_the_title( $sub_id );
	$link  = add_query_arg( 'confirm', $token, home_url( '/' ) );
	$site  = get_bloginfo( 'name' );

	wp_mail(
		$email,
		sprintf( __( '[%s] 请确认订阅', 'aurora' ), $site ),
		sprintf(
			/* translators: 1: site, 2: confirm link. */
			__( "感谢订阅 %1\$s。\n\n点这个链接完成确认：\n%2\$s\n\n如果这不是你本人操作，忽略这封邮件即可。", 'aurora' ),
			$site,
			$link
		)
	);

	update_post_meta( $sub_id, '_aurora_sub_sent', current_time( 'mysql' ) );
}

/**
 * Confirm / unsubscribe landings.
 */
function aurora_newsletter_handle_tokens() {
	if ( ! is_singular() && ! is_front_page() ) {
		return;
	}

	if ( isset( $_GET['confirm'] ) ) {
		$res = aurora_newsletter_confirm( wp_unslash( $_GET['confirm'] ) );

		add_action(
			'wp_footer',
			function () use ( $res ) {
				printf(
					'<div class="aurora-sub-toast">%s</div>',
					esc_html( is_wp_error( $res ) ? $res->get_error_message() : __( '订阅已确认，谢谢。', 'aurora' ) )
				);
			}
		);
	}

	if ( isset( $_GET['unsub'] ) ) {
		$res = aurora_newsletter_unsubscribe( wp_unslash( $_GET['unsub'] ) );

		add_action(
			'wp_footer',
			function () use ( $res ) {
				printf(
					'<div class="aurora-sub-toast">%s</div>',
					esc_html( is_wp_error( $res ) ? $res->get_error_message() : __( '已退订。', 'aurora' ) )
				);
			}
		);
	}
}
add_action( 'template_redirect', 'aurora_newsletter_handle_tokens' );

/**
 * Send a message to every confirmed subscriber.
 *
 * @param string $subject Subject.
 * @param string $body    Plain text body.
 * @return array{sent:int,failed:int}
 */
function aurora_newsletter_broadcast( $subject, $body ) {
	$sent   = 0;
	$failed = 0;

	foreach ( aurora_newsletter_confirmed() as $sub ) {
		$token = get_post_meta( $sub->ID, '_aurora_sub_token', true );
		$unsub = add_query_arg( 'unsub', $token, home_url( '/' ) );
		$text  = $body . "\n\n--\n" . sprintf( __( '不想再收到？退订：%s', 'aurora' ), $unsub );

		if ( wp_mail( $sub->post_title, $subject, $text ) ) {
			$sent++;
			update_post_meta( $sub->ID, '_aurora_sub_last', current_time( 'mysql' ) );
		} else {
			$failed++;
		}
	}

	return array( 'sent' => $sent, 'failed' => $failed );
}

/**
 * WP-CLI: broadcast to confirmed subscribers.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'aurora newsletter-send',
		function ( $args, $assoc ) {
			$subject = isset( $assoc['subject'] ) ? $assoc['subject'] : '';
			$body    = isset( $assoc['body'] ) ? $assoc['body'] : '';

			if ( ! $subject || ! $body ) {
				WP_CLI::error( '需要 --subject 和 --body。' );
			}

			$result = aurora_newsletter_broadcast( $subject, $body );
			WP_CLI::success( sprintf( '已发送 %d 封，失败 %d 封。', $result['sent'], $result['failed'] ) );
		}
	);

	WP_CLI::add_command(
		'aurora newsletter-stats',
		function () {
			$confirmed = count( aurora_newsletter_confirmed() );
			$pending   = count(
				get_posts(
					array(
						'post_type'      => 'aurora_subscriber',
						'post_status'    => 'private',
						'posts_per_page' => 2000,
						'no_found_rows'  => true,
						'meta_key'       => '_aurora_sub_state',
						'meta_value'     => 'pending',
					)
				)
			);

			WP_CLI::line( sprintf( '已确认 %d · 待确认 %d', $confirmed, $pending ) );
		}
	);
}
