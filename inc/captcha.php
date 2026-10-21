<?php
/**
 * Human verification.
 *
 * Two built-in providers and two configuration slots:
 *
 * - `math`   an arithmetic question rendered as text. No GD, no image
 *            functions: a small VPS may not have GD, and a captcha that fails
 *            to render is worse than none. This is the weakest option and the
 *            code says so rather than pretending otherwise — a script can read
 *            and solve it. Its job is to make bulk automation cost something;
 *            the rate limiter is what makes it expensive.
 * - `slider` a drag-to-align puzzle. The target offset stays on the server and
 *            is never rendered, so the answer has to be searched for, and the
 *            token is single-use. Still a speed bump rather than a wall: with a
 *            few percent of tolerance the search space is small. Paired with a
 *            per-context ceiling it costs an attacker real attempts.
 * - `tencent` / `geetest` are configuration slots. The keys are stored and the
 *            server-side check is exposed as a filter for the vendor SDK. Until
 *            something is hooked up there, the settings screen says so and the
 *            front end keeps using a built-in — refusing every visitor because
 *            an unconfigured provider cannot be verified would be the worst of
 *            both worlds.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

/**
 * Available providers.
 *
 * @return array Label by key.
 */
function aurora_captcha_providers() {
	return apply_filters( 'aurora_captcha_providers', array(
		'math'    => __( '算术题（内置，不依赖 GD 扩展）', 'aurora' ),
		'slider'  => __( '拖动滑块（内置）', 'aurora' ),
		'tencent' => __( '腾讯天御验证码（需密钥与官方 SDK 接入）', 'aurora' ),
		'geetest' => __( '极验验证（需密钥与官方 SDK 接入）', 'aurora' ),
	) );
}

/**
 * Forms that can be protected.
 *
 * @return array Label by key.
 */
function aurora_captcha_contexts() {
	return apply_filters( 'aurora_captcha_contexts', array(
		'register' => __( '注册', 'aurora' ),
		'login'    => __( '登录', 'aurora' ),
		'comment'  => __( '评论', 'aurora' ),
		'order'    => __( '提交订单', 'aurora' ),
	) );
}

/**
 * Current configuration.
 *
 * @return array
 */
function aurora_captcha_settings() {
	$provider = (string) get_theme_mod( 'aurora_captcha_provider', 'math' );
	if ( ! array_key_exists( $provider, aurora_captcha_providers() ) ) {
		$provider = 'math';
	}

	$contexts = get_theme_mod( 'aurora_captcha_contexts', array( 'register', 'login' ) );
	$contexts = is_array( $contexts ) ? $contexts : array();
	$known    = array_keys( aurora_captcha_contexts() );
	$contexts = array_values( array_intersect( array_map( 'strval', $contexts ), $known ) );

	return array(
		'provider'  => $provider,
		'contexts'  => $contexts,
		'ttl'       => max( 60, min( 3600, (int) get_theme_mod( 'aurora_captcha_ttl', 600 ) ) ),
		'tolerance' => max( 1, min( 25, (int) get_theme_mod( 'aurora_captcha_tolerance', 6 ) ) ),
	);
}

/**
 * The provider actually used, which is not always the one configured.
 *
 * @return string
 */
function aurora_captcha_provider() {
	$provider = aurora_captcha_settings()['provider'];

	if ( in_array( $provider, array( 'tencent', 'geetest' ), true )
		&& ! has_filter( 'aurora_captcha_verify_thirdparty' ) ) {
		return 'math';
	}

	return (string) apply_filters( 'aurora_captcha_provider', $provider );
}

/**
 * Is a third-party provider configured but not yet wired up?
 *
 * @return bool
 */
function aurora_captcha_provider_pending() {
	$provider = aurora_captcha_settings()['provider'];

	return in_array( $provider, array( 'tencent', 'geetest' ), true )
		&& ! has_filter( 'aurora_captcha_verify_thirdparty' );
}

/**
 * Does this form want a challenge?
 *
 * @param string $context Context key.
 * @return bool
 */
function aurora_captcha_required( $context ) {
	// Escape hatch. The challenge is on the login form, so a fault in this
	// module could keep everyone — including the administrator — out of the
	// dashboard, where the switch that turns it off lives. One line in
	// wp-config.php ends it: define( 'AURORA_DISABLE_CAPTCHA', true );
	if ( defined( 'AURORA_DISABLE_CAPTCHA' ) && AURORA_DISABLE_CAPTCHA ) {
		return false;
	}

	$settings = aurora_captcha_settings();

	if ( ! in_array( $context, $settings['contexts'], true ) ) {
		return false;
	}

	// An administrator testing their own site keeps working.
	if ( current_user_can( 'manage_options' ) ) {
		return (bool) apply_filters( 'aurora_captcha_required_for_admin', false, $context );
	}

	return (bool) apply_filters( 'aurora_captcha_required', true, $context );
}

/* -------------------------------------------------------------------------
 * Challenges
 * ---------------------------------------------------------------------- */

/**
 * Transient name for a token.
 *
 * @param string $token Token.
 * @return string
 */
function aurora_captcha_transient( $token ) {
	return 'aurora_cap_' . md5( $token );
}

/**
 * Mint a challenge.
 *
 * @param string $context Context key.
 * @return array
 */
function aurora_captcha_issue( $context ) {
	$settings = aurora_captcha_settings();
	$provider = aurora_captcha_provider();
	$token    = bin2hex( random_bytes( 16 ) );

	$challenge = array(
		'context'  => $context,
		'provider' => $provider,
		'issued'   => time(),
		'prompt'   => '',
		'answer'   => '',
		'target'   => 0,
	);

	if ( 'math' === $provider ) {
		$a  = wp_rand( 2, 9 );
		$b  = wp_rand( 2, 9 );
		$op = wp_rand( 0, 1 ) ? '+' : '-';

		// Keep every question positive: a seven year old could do it, and a
		// negative answer invites typo failures that look like bot failures.
		if ( '-' === $op && $b > $a ) {
			$swap = $a;
			$a    = $b;
			$b    = $swap;
		}

		$challenge['prompt'] = $a . ' ' . $op . ' ' . $b;
		$challenge['answer'] = (string) ( '+' === $op ? $a + $b : $a - $b );
	} elseif ( 'slider' === $provider ) {
		// Expressed as a percentage of the track so the check does not depend on
		// the visitor's screen width. Biased towards the end of the track: a
		// slider that is meant to be dragged across and then simply submitted at
		// 100 is not a check at all.
		$challenge['target'] = wp_rand( 86, 98 );
	}

	set_transient( aurora_captcha_transient( $token ), $challenge, $settings['ttl'] );

	return array(
		'token'     => $token,
		'challenge' => $challenge,
		'ttl'       => $settings['ttl'],
	);
}

/**
 * Read a challenge and burn it.
 *
 * Single use, and consumed whether or not the answer is right: if a wrong answer
 * left the token alive, the same challenge could be attacked repeatedly, which
 * is precisely what the short-lived token exists to prevent.
 *
 * @param string $token Token.
 * @return array|null
 */
function aurora_captcha_take( $token ) {
	$token = (string) $token;
	if ( '' === $token ) {
		return null;
	}

	$key       = aurora_captcha_transient( $token );
	$challenge = get_transient( $key );

	if ( is_array( $challenge ) ) {
		delete_transient( $key );
	}

	return is_array( $challenge ) ? $challenge : null;
}

/**
 * Check a submitted answer.
 *
 * @param string     $context Context key.
 * @param array|null $payload Submitted values; $_POST when omitted.
 * @return true|WP_Error
 */
function aurora_captcha_check( $context, $payload = null ) {
	if ( ! aurora_captcha_required( $context ) ) {
		return true;
	}

	$payload   = null === $payload ? $_POST : $payload;
	$token     = isset( $payload['aurora_captcha_token'] ) ? sanitize_text_field( (string) $payload['aurora_captcha_token'] ) : '';
	$challenge = aurora_captcha_take( $token );
	$settings  = aurora_captcha_settings();

	$fail = new WP_Error(
		'aurora_captcha_failed',
		__( '验证未通过，请重新完成验证。', 'aurora' ),
		array( 'status' => 422 )
	);

	if ( ! $challenge ) {
		return $fail;
	}
	if ( $challenge['context'] !== $context ) {
		return $fail;
	}
	if ( ( time() - (int) $challenge['issued'] ) > $settings['ttl'] ) {
		return $fail;
	}

	if ( 'math' === $challenge['provider'] ) {
		$answer = isset( $payload['aurora_captcha_answer'] ) ? trim( (string) $payload['aurora_captcha_answer'] ) : '';

		return ( '' !== $answer && hash_equals( (string) $challenge['answer'], $answer ) ) ? true : $fail;
	}

	if ( 'slider' === $challenge['provider'] ) {
		$x  = isset( $payload['aurora_captcha_x'] ) ? (float) $payload['aurora_captcha_x'] : -1.0;
		$ms = isset( $payload['aurora_captcha_ms'] ) ? (int) $payload['aurora_captcha_ms'] : 0;

		// A drag that took no time was not a drag. Crude, and a bot can lie
		// about the duration — but it costs nothing and stops the laziest
		// scripts, which is all this provider ever claimed to do.
		if ( $ms < 250 ) {
			return $fail;
		}

		return ( abs( $x - (float) $challenge['target'] ) <= $settings['tolerance'] ) ? true : $fail;
	}

	return apply_filters( 'aurora_captcha_verify_thirdparty', $fail, $challenge['provider'], $payload, $challenge );
}

/**
 * Refuse a JSON handler when verification fails.
 *
 * @param string     $context Context key.
 * @param array|null $payload Submitted values.
 */
function aurora_captcha_guard_json( $context, $payload = null ) {
	$result = aurora_captcha_check( $context, $payload );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 422 );
	}
}

/* -------------------------------------------------------------------------
 * Markup
 * ---------------------------------------------------------------------- */

/**
 * The field block for a form.
 *
 * @param string $context Context key.
 * @return string
 */
function aurora_captcha_field( $context ) {
	if ( ! aurora_captcha_required( $context ) ) {
		return '';
	}

	$issued = aurora_captcha_issue( $context );

	return aurora_captcha_field_html( $issued['token'], $issued['challenge'] );
}

/**
 * Markup for one issued challenge.
 *
 * @param string $token     Token.
 * @param array  $challenge Challenge.
 * @return string
 */
function aurora_captcha_field_html( $token, $challenge ) {
	$provider = $challenge['provider'];
	$uid      = 'aurora-captcha-' . substr( $token, 0, 8 );

	ob_start();
	?>
	<div class="aurora-captcha" id="<?php echo esc_attr( $uid ); ?>" data-provider="<?php echo esc_attr( $provider ); ?>">
		<input type="hidden" name="aurora_captcha_token" value="<?php echo esc_attr( $token ); ?>">

		<?php if ( 'math' === $provider ) : ?>
			<div class="aurora-captcha__row">
				<span class="aurora-captcha__prompt"><?php echo esc_html( $challenge['prompt'] ); ?> =</span>
				<input type="text" name="aurora_captcha_answer" class="aurora-captcha__answer"
					inputmode="numeric" autocomplete="off" required
					aria-label="<?php esc_attr_e( '请填写计算结果', 'aurora' ); ?>">
				<button type="button" class="aurora-captcha__refresh" data-context="<?php echo esc_attr( $challenge['context'] ); ?>">
					<?php esc_html_e( '换一题', 'aurora' ); ?>
				</button>
			</div>
		<?php elseif ( 'slider' === $provider ) : ?>
			<div class="aurora-captcha__slider">
				<div class="aurora-captcha__track">
					<div class="aurora-captcha__fill"></div>
					<span class="aurora-captcha__handle" role="slider" tabindex="0"
						aria-label="<?php esc_attr_e( '按住并向右拖动', 'aurora' ); ?>">›</span>
				</div>
				<p class="aurora-captcha__hint"><?php esc_html_e( '按住滑块，拖到最右侧完成验证', 'aurora' ); ?></p>
				<input type="hidden" name="aurora_captcha_x" value="">
				<input type="hidden" name="aurora_captcha_ms" value="0">
			</div>
		<?php else : ?>
			<div class="aurora-captcha__thirdparty"
				data-provider="<?php echo esc_attr( $provider ); ?>"
				data-appid="<?php echo esc_attr( (string) get_theme_mod( 'aurora_captcha_' . $provider . '_id', '' ) ); ?>">
				<p class="aurora-captcha__hint"><?php esc_html_e( '请完成安全验证。', 'aurora' ); ?></p>
			</div>
		<?php endif; ?>
	</div>
	<?php

	return trim( (string) ob_get_clean() );
}

/**
 * Front-end behaviour for the built-in providers.
 *
 * Printed once, and only when a built-in provider is in play, so a site using a
 * vendor SDK does not carry this around.
 */
function aurora_captcha_script() {
	static $printed = false;

	if ( $printed || ! aurora_captcha_settings()['contexts'] ) {
		return;
	}
	if ( ! in_array( aurora_captcha_provider(), array( 'math', 'slider' ), true ) ) {
		return;
	}

	$printed = true;
	$endpoint = admin_url( 'admin-ajax.php' );
	?>
	<script>
	( function () {
		var endpoint = <?php echo wp_json_encode( esc_url_raw( $endpoint ) ); ?>;

		// Swap in a fresh challenge without reloading the page, so a mistyped
		// answer or a missed drag does not cost the visitor their comment.
		function refresh( box ) {
			var button = box.querySelector( '.aurora-captcha__refresh' );
			var context = button ? button.getAttribute( 'data-context' ) : '';
			var body = new URLSearchParams();
			body.set( 'action', 'aurora_captcha' );
			body.set( 'context', context );

			fetch( endpoint, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					if ( data && data.success && data.data && data.data.html ) {
						var wrapper = document.createElement( 'div' );
						wrapper.innerHTML = data.data.html;
						box.replaceWith( wrapper.firstElementChild );
					}
				} )
				.catch( function () {} );
		}

		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.aurora-captcha__refresh' );
			if ( ! button ) { return; }
			event.preventDefault();
			refresh( button.closest( '.aurora-captcha' ) );
		} );

		function wireSlider( box ) {
			var track = box.querySelector( '.aurora-captcha__track' );
			var fill = box.querySelector( '.aurora-captcha__fill' );
			var handle = box.querySelector( '.aurora-captcha__handle' );
			var xField = box.querySelector( 'input[name="aurora_captcha_x"]' );
			var msField = box.querySelector( 'input[name="aurora_captcha_ms"]' );
			var dragging = false, started = 0, percent = 0;

			if ( ! track || ! handle ) { return; }

			function move( clientX ) {
				var box2 = track.getBoundingClientRect();
				if ( box2.width < 1 ) { return; }
				percent = Math.max( 0, Math.min( 100, ( ( clientX - box2.left ) / box2.width ) * 100 ) );
				handle.style.left = percent + '%';
				fill.style.width = percent + '%';
			}

			handle.addEventListener( 'pointerdown', function ( event ) {
				dragging = true;
				started = Date.now();
				handle.setPointerCapture( event.pointerId );
				event.preventDefault();
			} );

			handle.addEventListener( 'pointermove', function ( event ) {
				if ( dragging ) { move( event.clientX ); }
			} );

			handle.addEventListener( 'pointerup', function ( event ) {
				if ( ! dragging ) { return; }
				dragging = false;
				move( event.clientX );
				xField.value = percent.toFixed( 1 );
				msField.value = String( Date.now() - started );
				box.classList.add( 'is-answered' );
			} );
		}

		function init() {
			document.querySelectorAll( '.aurora-captcha' ).forEach( function ( box ) {
				if ( 'slider' === box.getAttribute( 'data-provider' ) ) { wireSlider( box ); }
			} );
		}

		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', init );
		} else {
			init();
		}
	}() );
	</script>
	<?php
}
add_action( 'wp_footer', 'aurora_captcha_script' );

/* -------------------------------------------------------------------------
 * Ajax: hand out a fresh challenge
 * ---------------------------------------------------------------------- */

/**
 * Serve a new challenge for an in-place retry.
 */
function aurora_ajax_captcha() {
	// Deliberately cheap: this endpoint exists so a visitor can retry, and
	// rate limiting it is what keeps it from becoming a free token dispenser.
	aurora_rate_json_guard( 'captcha' );

	$context  = isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : '';
	$contexts = array_keys( aurora_captcha_contexts() );

	if ( ! in_array( $context, $contexts, true ) || ! aurora_captcha_required( $context ) ) {
		wp_send_json_error( array( 'message' => __( '无效的验证请求。', 'aurora' ) ), 400 );
	}

	$issued = aurora_captcha_issue( $context );

	wp_send_json_success( array(
		'html' => aurora_captcha_field_html( $issued['token'], $issued['challenge'] ),
	) );
}
add_action( 'wp_ajax_nopriv_aurora_captcha', 'aurora_ajax_captcha' );
add_action( 'wp_ajax_aurora_captcha', 'aurora_ajax_captcha' );

/* -------------------------------------------------------------------------
 * Wiring: the theme's own forms and WordPress' built-ins
 * ---------------------------------------------------------------------- */

/**
 * Comment form: print the field for guests.
 */
function aurora_captcha_comment_field() {
	echo aurora_captcha_field( 'comment' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped above.
}
add_action( 'comment_form_after_fields', 'aurora_captcha_comment_field' );

/**
 * Comment form: print the field for signed-in members.
 */
function aurora_captcha_comment_field_logged_in() {
	echo aurora_captcha_field( 'comment' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'comment_form_logged_in_after', 'aurora_captcha_comment_field_logged_in' );

/**
 * Comment submission: verify.
 *
 * After the permission matrix (10) and the rate limiter (20), so a visitor who
 * is not allowed to comment at all is told that, rather than being asked to
 * solve a puzzle first.
 *
 * @param array $commentdata Comment data.
 * @return array
 */
function aurora_captcha_gate_comment( $commentdata ) {
	$result = aurora_captcha_check( 'comment' );

	if ( is_wp_error( $result ) ) {
		wp_die(
			esc_html( $result->get_error_message() ),
			esc_html__( '验证未通过', 'aurora' ),
			array( 'response' => 422, 'back_link' => true )
		);
	}

	return $commentdata;
}
add_filter( 'preprocess_comment', 'aurora_captcha_gate_comment', 25 );

/**
 * WordPress' login form: print the field.
 */
function aurora_captcha_login_field() {
	echo aurora_captcha_field( 'login' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'login_form', 'aurora_captcha_login_field' );

/**
 * WordPress login: verify.
 *
 * @param WP_User|WP_Error|null $user     User or error.
 * @param string                $username Username.
 * @return WP_User|WP_Error|null
 */
function aurora_captcha_gate_login( $user, $username ) {
	if ( ! $username ) {
		return $user;
	}

	$result = aurora_captcha_check( 'login' );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return $user;
}
add_filter( 'authenticate', 'aurora_captcha_gate_login', 50, 2 );

/**
 * WordPress' registration form: print the field.
 */
function aurora_captcha_register_field() {
	echo aurora_captcha_field( 'register' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'register_form', 'aurora_captcha_register_field' );

/**
 * WordPress registration: verify.
 *
 * @param WP_Error $errors Errors.
 * @return WP_Error
 */
function aurora_captcha_gate_registration( $errors ) {
	$result = aurora_captcha_check( 'register' );

	if ( is_wp_error( $result ) ) {
		$errors->add( 'aurora_captcha_failed', $result->get_error_message() );
	}

	return $errors;
}
add_filter( 'registration_errors', 'aurora_captcha_gate_registration', 30 );

/* -------------------------------------------------------------------------
 * Styles
 * ---------------------------------------------------------------------- */

/**
 * Inline styles for the field.
 *
 * Kept with the module rather than in the main stylesheet: a site that switches
 * to a vendor SDK should not carry markup it never renders.
 */
function aurora_captcha_style() {
	if ( ! aurora_captcha_settings()['contexts'] ) {
		return;
	}
	?>
	<style>
	.aurora-captcha{margin:14px 0;padding:12px 14px;border-radius:14px;background:rgba(127,127,127,.08)}
	.aurora-captcha__row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
	.aurora-captcha__prompt{font:600 17px/1 -apple-system,"PingFang SC",sans-serif;letter-spacing:.5px;color:var(--aurora-text-secondary,#48484d)}
	.aurora-captcha__answer{width:88px;padding:8px 10px;border-radius:10px;border:1px solid rgba(127,127,127,.35);background:transparent;color:inherit;font-size:16px}
	.aurora-captcha__refresh,.aurora-captcha__refresh:visited{margin-left:auto;background:none;border:0;padding:6px;color:var(--aurora-accent-text,#0066cc);font-size:13px;font-weight:600;cursor:pointer}
	.aurora-captcha__slider{user-select:none}
	.aurora-captcha__track{position:relative;height:42px;border-radius:21px;background:rgba(127,127,127,.18);overflow:hidden}
	.aurora-captcha__fill{position:absolute;inset:0 auto 0 0;width:0;background:rgba(10,132,255,.25)}
	.aurora-captcha__handle{position:absolute;top:3px;left:0;width:36px;height:36px;border-radius:50%;background:#fff;box-shadow:0 1px 6px rgba(0,0,0,.28);display:flex;align-items:center;justify-content:center;font:600 18px/1 -apple-system,sans-serif;color:#333;cursor:grab;touch-action:none}
	.aurora-captcha__handle:active{cursor:grabbing}
	.aurora-captcha__hint{margin:8px 0 0;font-size:12px;opacity:.65}
	.aurora-captcha.is-answered .aurora-captcha__track{background:rgba(48,209,88,.25)}
	</style>
	<?php
}
add_action( 'wp_head', 'aurora_captcha_style' );

/* -------------------------------------------------------------------------
 * Admin screen, on the anti-abuse page
 * ---------------------------------------------------------------------- */

/**
 * A bucket for the refresh endpoint.
 *
 * @param array $buckets Buckets.
 * @return array
 */
function aurora_captcha_rate_bucket( $buckets ) {
	$buckets['captcha'] = __( '刷新验证码', 'aurora' );

	return $buckets;
}
add_filter( 'aurora_rate_buckets', 'aurora_captcha_rate_bucket' );

/**
 * Ceilings for that bucket.
 *
 * @param array $defaults Defaults.
 * @return array
 */
function aurora_captcha_rate_defaults( $defaults ) {
	$defaults['captcha'] = array( 'short' => 20, 'hour' => 120, 'day' => 500 );

	return $defaults;
}
add_filter( 'aurora_rate_defaults', 'aurora_captcha_rate_defaults' );

/**
 * Settings block on the anti-abuse page.
 */
function aurora_captcha_admin_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = aurora_captcha_settings();
	$pending  = aurora_captcha_provider_pending();
	?>
	<hr style="margin:34px 0 22px">

	<h2><?php esc_html_e( '人机验证', 'aurora' ); ?></h2>
	<p class="description" style="max-width:900px">
		<?php esc_html_e( '勾选哪些表单需要先完成验证。内置的算术题与滑块都不依赖任何扩展，但都只是「提高成本」级别的验证：算术题可以被脚本读取并计算，滑块的容差内可以被搜索。真正需要对抗专业刷量时，请用腾讯天御或极验，并在下边填入密钥。', 'aurora' ); ?>
	</p>

	<?php if ( $pending ) : ?>
		<div class="notice notice-warning inline" style="margin:12px 0">
			<p>
				<?php esc_html_e( '当前选择的是第三方验证码，但服务端校验还没有接入 —— 在接入前，前台自动回落到内置算术题，不会把访客挡在门外。接入方式见下方说明。', 'aurora' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['aurora-captcha-saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '验证设置已保存。', 'aurora' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="aurora_save_captcha">
		<?php wp_nonce_field( 'aurora_save_captcha' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( '验证方式', 'aurora' ); ?></th>
				<td>
					<select name="aurora_captcha_provider">
						<?php foreach ( aurora_captcha_providers() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['provider'], $key ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( '需要验证的表单', 'aurora' ); ?></th>
				<td>
					<?php foreach ( aurora_captcha_contexts() as $key => $label ) : ?>
						<label style="margin-right:18px">
							<input type="checkbox" name="aurora_captcha_contexts[]" value="<?php echo esc_attr( $key ); ?>"
								<?php checked( in_array( $key, $settings['contexts'], true ) ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( '管理员自己登录与发言时不验证，避免把自己锁在门外。', 'aurora' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( '题目有效期', 'aurora' ); ?></th>
				<td>
					<input type="number" min="60" max="3600" step="30" name="aurora_captcha_ttl" value="<?php echo esc_attr( $settings['ttl'] ); ?>"> <?php esc_html_e( '秒', 'aurora' ); ?>
					<p class="description"><?php esc_html_e( '每个题目只能使用一次，无论答对答错都会作废。', 'aurora' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( '滑块容差', 'aurora' ); ?></th>
				<td>
					<input type="number" min="1" max="25" step="1" name="aurora_captcha_tolerance" value="<?php echo esc_attr( $settings['tolerance'] ); ?>"> %
					<p class="description"><?php esc_html_e( '越小越难。低于 4% 会让正常访客在手机上很难拖准。', 'aurora' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( '第三方密钥', 'aurora' ); ?></th>
				<td>
					<label style="display:block;margin-bottom:6px">
						<?php esc_html_e( '腾讯天御 AppID', 'aurora' ); ?>
						<input type="text" class="regular-text" name="aurora_captcha_tencent_id" value="<?php echo esc_attr( (string) get_theme_mod( 'aurora_captcha_tencent_id', '' ) ); ?>">
					</label>
					<label style="display:block">
						<?php esc_html_e( '极验 Captcha ID', 'aurora' ); ?>
						<input type="text" class="regular-text" name="aurora_captcha_geetest_id" value="<?php echo esc_attr( (string) get_theme_mod( 'aurora_captcha_geetest_id', '' ) ); ?>">
					</label>
					<p class="description" style="max-width:900px">
						<?php
						printf(
							/* translators: %s: filter name */
							esc_html__( '这里只存 ID。密钥本身请放在 wp-config.php 里，不要写进数据库；服务端校验用 %s 过滤器接入官方 SDK 的结果 —— 该过滤器一旦挂上东西，第三方验证就自动生效。', 'aurora' ),
							'<code>aurora_captcha_verify_thirdparty</code>'
						);
						?>
					</p>
				</td>
			</tr>
		</table>

		<p><button type="submit" class="button button-primary"><?php esc_html_e( '保存验证设置', 'aurora' ); ?></button></p>
	</form>
	<?php
}
add_action( 'aurora_antispam_page_after', 'aurora_captcha_admin_section' );

/**
 * Persist the verification settings.
 *
 * @return void
 */
function aurora_handle_captcha_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die(
			esc_html__( '权限不足。', 'aurora' ),
			esc_html__( '权限不足', 'aurora' ),
			array( 'response' => 403 )
		);
	}
	check_admin_referer( 'aurora_save_captcha' );

	$providers = array_keys( aurora_captcha_providers() );
	$provider  = isset( $_POST['aurora_captcha_provider'] ) ? sanitize_key( wp_unslash( $_POST['aurora_captcha_provider'] ) ) : 'math';
	set_theme_mod( 'aurora_captcha_provider', in_array( $provider, $providers, true ) ? $provider : 'math' );

	$known    = array_keys( aurora_captcha_contexts() );
	$posted   = isset( $_POST['aurora_captcha_contexts'] ) ? (array) wp_unslash( $_POST['aurora_captcha_contexts'] ) : array();
	$contexts = array_values( array_intersect( array_map( 'sanitize_key', $posted ), $known ) );
	set_theme_mod( 'aurora_captcha_contexts', $contexts );

	set_theme_mod( 'aurora_captcha_ttl', max( 60, min( 3600, (int) ( $_POST['aurora_captcha_ttl'] ?? 600 ) ) ) );
	set_theme_mod( 'aurora_captcha_tolerance', max( 1, min( 25, (int) ( $_POST['aurora_captcha_tolerance'] ?? 6 ) ) ) );

	set_theme_mod( 'aurora_captcha_tencent_id', sanitize_text_field( wp_unslash( $_POST['aurora_captcha_tencent_id'] ?? '' ) ) );
	set_theme_mod( 'aurora_captcha_geetest_id', sanitize_text_field( wp_unslash( $_POST['aurora_captcha_geetest_id'] ?? '' ) ) );

	wp_safe_redirect( add_query_arg( 'aurora-captcha-saved', '1', admin_url( 'admin.php?page=aurora-antispam' ) ) );
	exit;
}
add_action( 'admin_post_aurora_save_captcha', 'aurora_handle_captcha_save' );
