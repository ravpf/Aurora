<?php
/**
 * Rate limiting: how often the same caller may do the same thing.
 *
 * The theme has several endpoints a script can hammer — registration, login,
 * commenting, coupon validation, points redemption, the download unlocker — and
 * before this module the only throttle anywhere was a single counter inside the
 * card-key redeemer. One bucket is not a policy: brute forcing card keys and
 * mail-bombing a member through the signup form are different problems, and
 * they need different ceilings.
 *
 * The model is deliberately small: a bucket (what is being done) x a key (who
 * is doing it, the client IP by default) x three rolling windows. A window is
 * anchored at its first hit and does not slide, so once a caller is over the
 * limit the ban expires at a predictable moment instead of being pushed back by
 * every further attempt.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Windows and rules
 * ---------------------------------------------------------------------- */

/**
 * The windows every bucket is measured over.
 *
 * @return array Keyed by window name, value in seconds.
 */
function aurora_rate_windows() {
	return apply_filters( 'aurora_rate_windows', array(
		'short' => 10 * MINUTE_IN_SECONDS,
		'hour'  => HOUR_IN_SECONDS,
		'day'   => DAY_IN_SECONDS,
	) );
}

/**
 * Human label for a window.
 *
 * @param string $window Window key.
 * @return string
 */
function aurora_rate_window_label( $window ) {
	$labels = array(
		'short' => __( '10 分钟', 'aurora' ),
		'hour'  => __( '1 小时', 'aurora' ),
		'day'   => __( '1 天', 'aurora' ),
	);

	return isset( $labels[ $window ] ) ? $labels[ $window ] : $window;
}

/**
 * What each bucket is called in the settings screen.
 *
 * @return array
 */
function aurora_rate_buckets() {
	return apply_filters( 'aurora_rate_buckets', array(
		'register'   => __( '注册账号', 'aurora' ),
		'login'      => __( '登录尝试', 'aurora' ),
		'comment'    => __( '发表评论', 'aurora' ),
		'post'       => __( '前台投稿 / 发帖', 'aurora' ),
		'upload'     => __( '上传文件', 'aurora' ),
		'order'      => __( '提交订单', 'aurora' ),
		'coupon'     => __( '优惠码校验', 'aurora' ),
		'redeem'     => __( '卡密兑换', 'aurora' ),
		'download'   => __( '下载资源', 'aurora' ),
		'search'     => __( '站内搜索', 'aurora' ),
		'send_email' => __( '发送邮件验证码', 'aurora' ),
		'send_sms'   => __( '发送短信验证码', 'aurora' ),
		'follow'     => __( '关注作者', 'aurora' ),
		'tip'        => __( '打赏作者', 'aurora' ),
		'editor'     => __( '前台编辑器保存', 'aurora' ),
		'subscribe'  => __( '邮件订阅', 'aurora' ),
		'rate'       => __( '文章评分', 'aurora' ),
	) );
}

/**
 * Default ceilings per bucket and window.
 *
 * 0 means unlimited. Values are counts per window from the same caller.
 *
 * @return array
 */
function aurora_rate_defaults() {
	return apply_filters( 'aurora_rate_defaults', array(
		'register'   => array( 'short' => 3,  'hour' => 10,  'day' => 30 ),
		'login'      => array( 'short' => 5,  'hour' => 30,  'day' => 100 ),
		'comment'    => array( 'short' => 5,  'hour' => 30,  'day' => 120 ),
		'post'       => array( 'short' => 3,  'hour' => 15,  'day' => 50 ),
		'upload'     => array( 'short' => 10, 'hour' => 60,  'day' => 200 ),
		'order'      => array( 'short' => 5,  'hour' => 30,  'day' => 100 ),
		'coupon'     => array( 'short' => 10, 'hour' => 60,  'day' => 300 ),
		'redeem'     => array( 'short' => 5,  'hour' => 20,  'day' => 60 ),
		'download'   => array( 'short' => 20, 'hour' => 100, 'day' => 400 ),
		'search'     => array( 'short' => 30, 'hour' => 300, 'day' => 1000 ),
		'send_email' => array( 'short' => 2,  'hour' => 5,   'day' => 15 ),
		'send_sms'   => array( 'short' => 2,  'hour' => 5,   'day' => 10 ),
		'follow'     => array( 'short' => 10, 'hour' => 60,  'day' => 200 ),
		'tip'        => array( 'short' => 5,  'hour' => 30,  'day' => 100 ),
		'editor'     => array( 'short' => 30, 'hour' => 400, 'day' => 1500 ),
		'subscribe'  => array( 'short' => 3,  'hour' => 10,  'day' => 30 ),
		'rate'       => array( 'short' => 5,  'hour' => 40,  'day' => 150 ),
	) );
}

/**
 * The limits in force, merged against the defaults.
 *
 * Merged per cell, not per row: a bucket added by a filter after the
 * administrator last saved must still answer every window, or it would read as
 * "no limit" and silently open a hole.
 *
 * @return array
 */
function aurora_rate_rules() {
	$defaults = aurora_rate_defaults();
	$saved    = get_option( 'aurora_rate_rules', array() );
	$saved    = is_array( $saved ) ? $saved : array();

	$rules = array();
	foreach ( $defaults as $bucket => $windows ) {
		$rules[ $bucket ] = array();
		foreach ( $windows as $window => $limit ) {
			$rules[ $bucket ][ $window ] = isset( $saved[ $bucket ][ $window ] )
				? max( 0, (int) $saved[ $bucket ][ $window ] )
				: (int) $limit;
		}
	}

	return apply_filters( 'aurora_rate_rules', $rules );
}

/**
 * Is limiting switched on at all?
 *
 * @return bool
 */
function aurora_rate_enabled() {
	return (bool) get_theme_mod( 'aurora_rate_enable', true );
}

/**
 * Is a bucket switched on?
 *
 * @param string $bucket Bucket key.
 * @return bool
 */
function aurora_rate_bucket_enabled( $bucket ) {
	return (bool) apply_filters( 'aurora_rate_bucket_enabled', true, $bucket );
}

/* -------------------------------------------------------------------------
 * Caller identity
 * ---------------------------------------------------------------------- */

/**
 * The address a limit is counted against.
 *
 * Only the socket address is trusted by default. X-Forwarded-For is trivially
 * spoofable, so honouring it on a site that is not actually behind a proxy would
 * hand every attacker an unlimited supply of fresh identities — exactly the
 * thing being defended against. A site behind Cloudflare or nginx can opt in.
 *
 * @return string
 */
function aurora_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

	if ( apply_filters( 'aurora_trust_proxy_headers', false ) ) {
		$forwarded = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' );

		foreach ( $forwarded as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}
			// X-Forwarded-For is a list; the client is the first entry.
			$parts = explode( ',', (string) wp_unslash( $_SERVER[ $header ] ) );
			$candidate = trim( $parts[0] );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
				break;
			}
		}
	}

	return $ip ? $ip : 'unknown';
}

/* -------------------------------------------------------------------------
 * Counting
 * ---------------------------------------------------------------------- */

/**
 * The identity a limit is counted against.
 *
 * A signed-in caller is counted per account, not per address: a family, an
 * office or a campus shares one IP, and charging all of them to the same
 * ten-a-day allowance would punish a member for their neighbours. Anonymous
 * callers have nothing else to count on, so the address it is.
 *
 * @return string
 */
function aurora_client_key() {
	$user_id = get_current_user_id();

	return $user_id ? 'user:' . $user_id : 'ip:' . aurora_client_ip();
}

/**
 * Transient key for one counter.
 *
 * @param string $bucket Bucket key.
 * @param string $key    Caller key.
 * @param string $window Window key.
 * @return string
 */
function aurora_rate_transient( $bucket, $key, $window ) {
	// Transient names are limited to 172 characters, so the caller key is
	// hashed rather than embedded; an IPv6 address or a long e-mail would blow
	// the limit and silently disable the counter.
	return 'aurora_rl_' . $bucket . '_' . $window . '_' . md5( $key );
}

/**
 * Register a hit and report whether the caller is still under every ceiling.
 *
 * @param string     $bucket Bucket key.
 * @param string     $key    Caller key; the client IP when omitted.
 * @param array|null $rules  Rule set, when overriding.
 * @return array {
 *     @type bool   $allowed     Whether the caller may proceed.
 *     @type bool   $checked     False when limiting is switched off.
 *     @type string $window      The window that was exceeded, if any.
 *     @type int    $hits        Hits recorded in that window.
 *     @type int    $limit       The ceiling for that window.
 *     @type int    $retry_after Seconds until the caller may try again.
 * }
 */
/**
 * Read one caller's counters and say whether a ceiling is already spent.
 *
 * Split out because two callers need the same reading and then do opposite
 * things with it: `aurora_rate_check()` records the attempt, `aurora_rate_peek()`
 * must not.
 *
 * @param string $bucket Bucket key.
 * @param string $key    Caller key.
 * @param array  $limits Ceilings for this bucket.
 * @param int    $now    Current timestamp.
 * @return array [ counters, breached ]
 */
function aurora_rate_scan( $bucket, $key, $limits, $now ) {
	$counters = array();
	$breached = null;

	foreach ( aurora_rate_windows() as $window => $seconds ) {
		$limit = isset( $limits[ $window ] ) ? (int) $limits[ $window ] : 0;
		if ( $limit < 1 ) {
			continue;
		}

		$name = aurora_rate_transient( $bucket, $key, $window );
		$data = get_transient( $name );

		// A window past its end is simply a fresh one. It is not written back
		// here: a peek has to stay free of side effects.
		if ( ! is_array( $data ) || ! isset( $data['start'], $data['hits'] ) || ( $now - (int) $data['start'] ) >= $seconds ) {
			$data = array(
				'start' => $now,
				'hits'  => 0,
			);
		}

		$counters[ $window ] = array(
			'name'    => $name,
			'data'    => $data,
			'limit'   => $limit,
			'seconds' => $seconds,
		);

		// Sitting on the ceiling already means the next call is the refused one.
		if ( (int) $data['hits'] >= $limit ) {
			$retry = max( 1, ( (int) $data['start'] + $seconds ) - $now );

			// The longest wait owed is the honest answer when more than one
			// window is spent.
			if ( null === $breached || $retry > $breached['retry_after'] ) {
				$breached = array(
					'window'      => $window,
					'hits'        => (int) $data['hits'],
					'limit'       => $limit,
					'retry_after' => $retry,
				);
			}
		}
	}

	return array( $counters, $breached );
}

/**
 * Record one attempt in every window, and report the window nearest its ceiling.
 *
 * @param array $counters Counters from aurora_rate_scan().
 * @param int   $now      Current timestamp.
 * @return array|null
 */
function aurora_rate_record( $counters, $now ) {
	$tightest = null;

	foreach ( $counters as $window => $entry ) {
		$data         = $entry['data'];
		$data['hits'] = (int) $data['hits'] + 1;

		// Stored with the remaining lifetime of the anchored window, so a
		// counter cannot outlive the period it measures.
		set_transient( $entry['name'], $data, max( 1, $entry['seconds'] - ( $now - (int) $data['start'] ) ) );

		$ratio = $data['hits'] / $entry['limit'];
		if ( null === $tightest || $ratio > $tightest['ratio'] ) {
			$tightest = array(
				'ratio'  => $ratio,
				'window' => $window,
				'hits'   => (int) $data['hits'],
				'limit'  => $entry['limit'],
			);
		}
	}

	return $tightest;
}

/**
 * A blank result.
 *
 * @param bool $checked Whether limiting applied at all.
 * @return array
 */
function aurora_rate_result( $checked = false ) {
	return array(
		'allowed'     => true,
		'checked'     => $checked,
		'window'      => '',
		'hits'        => 0,
		'limit'       => 0,
		'retry_after' => 0,
	);
}

/**
 * Resolve the caller and the ceilings a bucket is measured against.
 *
 * @param string     $bucket Bucket key.
 * @param string     $key    Caller key.
 * @param array|null $rules  Rule set, when overriding.
 * @return array|null Null when this bucket is not being limited.
 */
function aurora_rate_context( $bucket, $key, $rules ) {
	$rules  = null === $rules ? aurora_rate_rules() : $rules;
	$limits = isset( $rules[ $bucket ] ) ? $rules[ $bucket ] : array();

	if ( ! aurora_rate_enabled() || ! aurora_rate_bucket_enabled( $bucket ) || ! $limits ) {
		return null;
	}

	return array(
		'key'    => ( '' === $key ) ? aurora_client_key() : (string) $key,
		'limits' => $limits,
	);
}

/**
 * Register a hit and report whether the caller is still under every ceiling.
 *
 * @param string     $bucket Bucket key.
 * @param string     $key    Caller key; the client identity when omitted.
 * @param array|null $rules  Rule set, when overriding.
 * @return array {
 *     @type bool   $allowed     Whether the caller may proceed.
 *     @type bool   $checked     False when limiting is switched off.
 *     @type string $window      The window under the most pressure.
 *     @type int    $hits        Hits recorded in that window.
 *     @type int    $limit       The ceiling for that window.
 *     @type int    $retry_after Seconds until the caller may try again.
 * }
 */
function aurora_rate_check( $bucket, $key = '', $rules = null ) {
	$context = aurora_rate_context( $bucket, $key, $rules );

	if ( null === $context ) {
		return aurora_rate_result();
	}

	$now = time();
	list( $counters, $breached ) = aurora_rate_scan( $bucket, $context['key'], $context['limits'], $now );
	$ok = aurora_rate_result( true );

	// An attempt from a caller who has already spent a ceiling is NOT recorded:
	// counting it would let a bot escalate its own ban by retrying — the short
	// ceiling is spent, so further hits pile onto the hour one and then the day
	// one — and the reported wait would jump from ten minutes to an hour to a
	// day as it hammered away. Retrying harder must not mean waiting longer;
	// the ban expires when it was always going to.
	if ( $breached ) {
		return apply_filters(
			'aurora_rate_check',
			array_merge( $ok, $breached, array( 'allowed' => false ) ),
			$bucket,
			$context['key']
		);
	}

	$tightest = aurora_rate_record( $counters, $now );

	if ( $tightest ) {
		$ok['window'] = $tightest['window'];
		$ok['hits']   = $tightest['hits'];
		$ok['limit']  = $tightest['limit'];
	}

	return apply_filters( 'aurora_rate_check', $ok, $bucket, $context['key'] );
}

/**
 * Ask whether a caller is over a ceiling WITHOUT spending an attempt.
 *
 * For gates where the request itself is not the thing being limited — a login,
 * where what needs counting is the *failure*. Recording attempts instead would
 * lock out an office, a school or a family sharing one address after five
 * successful sign-ins, and the ceiling exists to stop password guessing.
 *
 * @param string     $bucket Bucket key.
 * @param string     $key    Caller key.
 * @param array|null $rules  Rule set, when overriding.
 * @return array Same shape as aurora_rate_check().
 */
function aurora_rate_peek( $bucket, $key = '', $rules = null ) {
	$context = aurora_rate_context( $bucket, $key, $rules );

	if ( null === $context ) {
		return aurora_rate_result();
	}

	$now = time();
	list( $counters, $breached ) = aurora_rate_scan( $bucket, $context['key'], $context['limits'], $now );
	$ok = aurora_rate_result( true );

	if ( $breached ) {
		return apply_filters(
			'aurora_rate_peek',
			array_merge( $ok, $breached, array( 'allowed' => false ) ),
			$bucket,
			$context['key']
		);
	}

	// Report the window nearest its ceiling, counting the attempt the caller is
	// about to make — without recording it.
	$tightest = null;

	foreach ( $counters as $window => $entry ) {
		$ratio = ( (int) $entry['data']['hits'] + 1 ) / $entry['limit'];

		if ( null === $tightest || $ratio > $tightest['ratio'] ) {
			$tightest = array(
				'ratio'  => $ratio,
				'window' => $window,
				'hits'   => (int) $entry['data']['hits'],
				'limit'  => $entry['limit'],
			);
		}
	}

	if ( $tightest ) {
		$ok['window'] = $tightest['window'];
		$ok['hits']   = $tightest['hits'];
		$ok['limit']  = $tightest['limit'];
	}

	return apply_filters( 'aurora_rate_peek', $ok, $bucket, $context['key'] );
}

/**
 * A short wait label that reads correctly inside a sentence.
 *
 * human_time_diff() renders "10 分" — the space before the unit is jarring
 * mid-sentence in Chinese ("请等待 10 分 后再试").
 *
 * @param int $seconds Seconds to wait.
 * @return string
 */
function aurora_rate_wait_label( $seconds ) {
	$seconds = max( 1, (int) $seconds );

	if ( $seconds >= HOUR_IN_SECONDS ) {
		/* translators: %d: number of hours */
		return sprintf( __( '%d 小时', 'aurora' ), (int) ceil( $seconds / HOUR_IN_SECONDS ) );
	}
	if ( $seconds >= MINUTE_IN_SECONDS ) {
		/* translators: %d: number of minutes */
		return sprintf( __( '%d 分钟', 'aurora' ), (int) ceil( $seconds / MINUTE_IN_SECONDS ) );
	}
	/* translators: %d: number of seconds */
	return sprintf( __( '%d 秒', 'aurora' ), $seconds );
}

/**
 * Gate an action: true when it may proceed, a WP_Error when the caller is over.
 *
 * The error carries `retry_after` so a JSON handler can hand the client a
 * countdown instead of a bare refusal.
 *
 * @param string $bucket Bucket key.
 * @param string $key    Caller key; the client IP when omitted.
 * @return true|WP_Error
 */
function aurora_rate_guard( $bucket, $key = '' ) {
	return aurora_rate_gate_result( aurora_rate_check( $bucket, $key ) );
}

/**
 * The peeking counterpart of aurora_rate_guard().
 *
 * @param string $bucket Bucket key.
 * @param string $key    Caller key.
 * @return true|WP_Error
 */
function aurora_rate_guard_peek( $bucket, $key = '' ) {
	return aurora_rate_gate_result( aurora_rate_peek( $bucket, $key ) );
}

/**
 * Turn a result into a refusal, or into true.
 *
 * The error carries `retry_after` so a JSON handler can hand the client a
 * countdown instead of a bare refusal.
 *
 * @param array $result Result from check() or peek().
 * @return true|WP_Error
 */
function aurora_rate_gate_result( $result ) {
	if ( ! empty( $result['allowed'] ) ) {
		return true;
	}

	return new WP_Error(
		'aurora_rate_limited',
		sprintf(
			/* translators: %s: how long the caller must wait */
			__( '操作过于频繁，请等待 %s后再试。', 'aurora' ),
			aurora_rate_wait_label( $result['retry_after'] )
		),
		array(
			'status'      => 429,
			'retry_after' => $result['retry_after'],
			'window'      => $result['window'],
		)
	);
}

/**
 * Clear every counter for a caller, usually after a success.
 *
 * @param string $bucket Bucket key.
 * @param string $key    Caller key; the client IP when omitted.
 */
function aurora_rate_reset( $bucket, $key = '' ) {
	$key = ( '' === $key ) ? aurora_client_key() : (string) $key;

	foreach ( array_keys( aurora_rate_windows() ) as $window ) {
		delete_transient( aurora_rate_transient( $bucket, $key, $window ) );
	}
}

/**
 * Turn a WP_Error from the guard into a JSON failure, or return.
 *
 * @param string $bucket Bucket key.
 * @param string $key    Caller key.
 */
function aurora_rate_json_guard( $bucket, $key = '' ) {
	$gate = aurora_rate_guard( $bucket, $key );

	if ( is_wp_error( $gate ) ) {
		wp_send_json_error(
			array(
				'message'     => $gate->get_error_message(),
				'retry_after' => (int) $gate->get_error_data()['retry_after'],
			),
			429
		);
	}
}

/**
 * Clear every counter on the site.
 *
 * Transients live in the options table on a site with no external object cache,
 * which is the case this targets; with a persistent object cache the keys have
 * to be flushed from that cache instead.
 *
 * @return int Rows removed.
 */
function aurora_rate_reset_all() {
	global $wpdb;

	$transient = $wpdb->esc_like( '_transient_aurora_rl_' ) . '%';
	$timeout   = $wpdb->esc_like( '_transient_timeout_aurora_rl_' ) . '%';

	return (int) $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$transient,
			$timeout
		)
	);
}

/* -------------------------------------------------------------------------
 * Enforcement
 * ---------------------------------------------------------------------- */

/**
 * Limit commenting.
 *
 * Runs after the permission matrix has already decided *whether* the caller may
 * comment; this decides how often.
 *
 * @param array $commentdata Comment about to be inserted.
 * @return array
 */
function aurora_rate_gate_comment( $commentdata ) {
	$by_user = get_current_user_id();
	$key     = $by_user ? 'user:' . $by_user : aurora_client_ip();

	$gate = aurora_rate_guard( 'comment', $key );

	if ( is_wp_error( $gate ) ) {
		wp_die(
			esc_html( $gate->get_error_message() ),
			esc_html__( '操作过于频繁', 'aurora' ),
			array( 'response' => 429, 'back_link' => true )
		);
	}

	return $commentdata;
}
add_filter( 'preprocess_comment', 'aurora_rate_gate_comment', 20 );

/**
 * Limit registration through WordPress' own form.
 *
 * Only failures are counted, because a successful signup already ends the
 * caller's run — but a script creating accounts gets stopped here.
 *
 * @param WP_Error $errors Errors collected so far.
 * @return WP_Error
 */
function aurora_rate_gate_wp_registration( $errors ) {
	if ( is_wp_error( $errors ) && $errors->get_error_codes() ) {
		return $errors;
	}

	$gate = aurora_rate_guard( 'register' );

	if ( is_wp_error( $gate ) ) {
		$errors->add( 'aurora_rate_limited', $gate->get_error_message() );
	}

	return $errors;
}
add_filter( 'registration_errors', 'aurora_rate_gate_wp_registration', 20 );

/**
 * Count a successful registration as a hit of its own.
 *
 * Without this, an attacker could register until the failures piled up, then
 * succeed and start over: the successes were never counted.
 *
 * @param int $user_id New user ID.
 */
function aurora_rate_note_registration( $user_id ) {
	aurora_rate_check( 'register' );
}
add_action( 'user_register', 'aurora_rate_note_registration', 20 );

/**
 * The keys a login is measured against: the caller's address, and the account
 * being tried.
 *
 * Both, because they stop different attacks. One address trying many passwords
 * is stopped by the address; many addresses trying one password — the shape of
 * a distributed guessing run — is stopped by the account.
 *
 * @param string $username Submitted login.
 * @return array
 */
function aurora_rate_login_keys( $username ) {
	$keys     = array( 'ip:' . aurora_client_ip() );
	$username = strtolower( trim( (string) $username ) );

	if ( '' !== $username ) {
		// Hashed into the transient name by aurora_rate_transient(), so the
		// attempted account never appears in the options table.
		$keys[] = 'login-name:' . $username;
	}

	return $keys;
}

/**
 * Count a failed login against both keys.
 *
 * @param string $username Attempted login.
 */
function aurora_rate_note_login_failure( $username ) {
	foreach ( aurora_rate_login_keys( $username ) as $key ) {
		aurora_rate_check( 'login', $key );
	}
}
add_action( 'wp_login_failed', 'aurora_rate_note_login_failure', 10 );

/**
 * A successful sign-in clears the failures recorded for that caller and account.
 *
 * Someone who mistyped four times and then got it right is not a brute-force
 * attacker; leaving them one attempt from a lockout would be punishing them for
 * their own typo.
 *
 * @param string  $user_login Signed-in username.
 * @param WP_User $user       The user.
 */
function aurora_rate_clear_login( $user_login, $user = null ) {
	foreach ( aurora_rate_login_keys( $user_login ) as $key ) {
		aurora_rate_reset( 'login', $key );
	}
}
add_action( 'wp_login', 'aurora_rate_clear_login', 10, 2 );

/**
 * Refuse a login from a caller who has already spent the failure allowance.
 *
 * Deliberately a peek rather than a check: the ceiling counts failures, which
 * `wp_login_failed` records. Counting every attempt here would lock out an
 * office or a family sharing one address after five *successful* sign-ins.
 *
 * @param WP_User|WP_Error|null $user     User or error from earlier filters.
 * @param string                $username Submitted login.
 * @return WP_User|WP_Error|null
 */
function aurora_rate_gate_login( $user, $username ) {
	if ( ! $username ) {
		return $user;
	}

	foreach ( aurora_rate_login_keys( $username ) as $key ) {
		$gate = aurora_rate_guard_peek( 'login', $key );

		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
	}

	return $user;
}
add_filter( 'authenticate', 'aurora_rate_gate_login', 40, 2 );

/**
 * Limit the unauthenticated search endpoint.
 *
 * @param WP_Query $query Query.
 */
function aurora_rate_note_search( $query ) {
	if ( ! $query instanceof WP_Query ) {
		return;
	}
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
		return;
	}

	aurora_rate_check( 'search' );
}
add_action( 'pre_get_posts', 'aurora_rate_note_search', 99 );

/* -------------------------------------------------------------------------
 * Admin screen
 * ---------------------------------------------------------------------- */

/**
 * Add the anti-abuse screen.
 */
function aurora_rate_admin_menu() {
	add_submenu_page(
		'aurora-settings',
		__( '防刷与验证', 'aurora' ),
		__( '防刷与验证', 'aurora' ),
		'manage_options',
		'aurora-antispam',
		'aurora_rate_page'
	);
}
add_action( 'admin_menu', 'aurora_rate_admin_menu', 21 );

/**
 * Render the bucket x window table.
 */
function aurora_rate_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '权限不足。', 'aurora' ), '', array( 'response' => 403 ) );
	}

	$buckets = aurora_rate_buckets();
	$windows = aurora_rate_windows();
	$rules   = aurora_rate_rules();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( '防刷与验证', 'aurora' ); ?></h1>
		<p class="description" style="max-width:900px">
			<?php esc_html_e( '每一行是一个动作，每一列是统计窗口。填「同一个来源在窗口内最多可执行几次」，填 0 表示不限制。计数器按访客 IP 统计（登录用户按账号统计），窗口从第一次操作开始计算，次数用尽后要等到窗口结束才能继续。', 'aurora' ); ?>
		</p>

		<?php if ( isset( $_GET['aurora-saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '防刷设置已保存。', 'aurora' ); ?></p></div>
		<?php endif; ?>

		<p style="margin:14px 0">
			<label>
				<input type="checkbox" disabled <?php checked( aurora_rate_enabled() ); ?>>
				<?php
				printf(
					/* translators: %s: link to the general settings page */
					esc_html__( '总开关在 %s 里（「关闭频率限制」）。', 'aurora' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=aurora-settings&tab=members' ) ) . '">' . esc_html__( '主题设置 → 会员与付费', 'aurora' ) . '</a>'
				);
				?>
			</label>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aurora_save_antispam">
			<?php wp_nonce_field( 'aurora_save_antispam' ); ?>

			<table class="widefat striped" style="max-width:760px">
				<thead>
					<tr>
						<th><?php esc_html_e( '动作', 'aurora' ); ?></th>
						<?php foreach ( $windows as $window => $seconds ) : ?>
							<th style="text-align:center"><?php echo esc_html( aurora_rate_window_label( $window ) ); ?></th>
						<?php endforeach; ?>
						<th style="text-align:center"><?php esc_html_e( '当前来源', 'aurora' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $buckets as $bucket => $label ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $label ); ?></strong>
								<div style="color:#646970;font-size:11px"><?php echo esc_html( $bucket ); ?></div>
							</td>
							<?php foreach ( $windows as $window => $seconds ) : ?>
								<td style="text-align:center">
									<input type="number" min="0" max="100000" step="1" style="width:80px"
										name="aurora_rate[<?php echo esc_attr( $bucket ); ?>][<?php echo esc_attr( $window ); ?>]"
										value="<?php echo esc_attr( isset( $rules[ $bucket ][ $window ] ) ? $rules[ $bucket ][ $window ] : 0 ); ?>">
								</td>
							<?php endforeach; ?>
							<?php
							$seen = 0;
							foreach ( array_keys( $windows ) as $window ) {
								$data = get_transient( aurora_rate_transient( $bucket, aurora_client_key(), $window ) );
								if ( is_array( $data ) ) {
									$seen = max( $seen, (int) $data['hits'] );
								}
							}
							?>
							<td style="text-align:center;color:#646970"><?php echo (int) $seen; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:16px">
				<button type="submit" class="button button-primary"><?php esc_html_e( '保存', 'aurora' ); ?></button>
				<button type="submit" class="button" name="aurora_reset" value="1"
					onclick="return confirm('<?php esc_js( __( '恢复为默认限流值？', 'aurora' ) ); ?>')">
					<?php esc_html_e( '恢复默认', 'aurora' ); ?>
				</button>
			</p>
		</form>

		<p class="description" style="max-width:900px;margin-top:18px">
			<?php esc_html_e( '注意：限流默认只信任连接来源的 IP。如果你的站点前面有 Cloudflare 或反向代理，需要用 aurora_trust_proxy_headers 过滤器显式开启转发头信任 —— 否则任何人都能伪造 X-Forwarded-For 绕过全部限制。', 'aurora' ); ?>
		</p>

		<?php
		/**
		 * Fires at the end of the anti-abuse screen.
		 *
		 * The verification module hangs its own settings block here rather than
		 * this file knowing about it.
		 */
		do_action( 'aurora_antispam_page_after' );
		?>
	</div>
	<?php
}

/**
 * Persist the ceilings.
 *
 * @return void
 */
function aurora_handle_rate_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die(
			esc_html__( '权限不足。', 'aurora' ),
			esc_html__( '权限不足', 'aurora' ),
			array( 'response' => 403 )
		);
	}
	check_admin_referer( 'aurora_save_antispam' );

	if ( ! empty( $_POST['aurora_reset'] ) ) {
		delete_option( 'aurora_rate_rules' );
		wp_safe_redirect( add_query_arg( 'aurora-saved', '1', admin_url( 'admin.php?page=aurora-antispam' ) ) );
		exit;
	}

	$posted = isset( $_POST['aurora_rate'] ) ? (array) wp_unslash( $_POST['aurora_rate'] ) : array();
	$saved  = array();

	foreach ( array_keys( aurora_rate_buckets() ) as $bucket ) {
		foreach ( array_keys( aurora_rate_windows() ) as $window ) {
			// Absent and zero mean the same thing here: no ceiling. Writing the
			// zero explicitly is what makes "clear the field to allow more"
			// work, instead of silently keeping the old number.
			$saved[ $bucket ][ $window ] = isset( $posted[ $bucket ][ $window ] )
				? max( 0, min( 100000, (int) $posted[ $bucket ][ $window ] ) )
				: 0;
		}
	}

	update_option( 'aurora_rate_rules', $saved, false );

	wp_safe_redirect( add_query_arg( 'aurora-saved', '1', admin_url( 'admin.php?page=aurora-antispam' ) ) );
	exit;
}
add_action( 'admin_post_aurora_save_antispam', 'aurora_handle_rate_save' );
