<?php
/**
 * AJAX endpoints.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verify the shared nonce or die with a JSON error.
 */
function aurora_check_nonce() {
	$nonce = isset( $_POST['nonce'] ) ? sanitize_key( $_POST['nonce'] ) : '';
	if ( ! wp_verify_nonce( $nonce, 'aurora_nonce' ) ) {
		wp_send_json_error( array( 'message' => __( '安全校验失败，请刷新页面重试。', 'aurora' ) ), 403 );
	}
}

/**
 * Live search for the overlay.
 */
function aurora_ajax_search() {
	aurora_check_nonce();

	$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
	if ( mb_strlen( $term ) < 2 ) {
		wp_send_json_success( array() );
	}

	$query = new WP_Query( array(
		's'                   => $term,
		'post_type'           => array( 'post', 'page', 'aurora_notice' ),
		'post_status'         => 'publish',
		'posts_per_page'      => 8,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	) );

	$out = array();
	while ( $query->have_posts() ) {
		$query->the_post();
		$thumb = get_the_post_thumbnail_url( get_the_ID(), 'thumbnail' );
		$out[] = array(
			'title'   => wp_strip_all_tags( get_the_title() ),
			'link'    => get_permalink(),
			'excerpt' => wp_trim_words( wp_strip_all_tags( has_excerpt() ? get_the_excerpt() : get_the_content() ), 14 ),
			'thumb'   => $thumb ? $thumb : '',
		);
	}
	wp_reset_postdata();

	wp_send_json_success( $out );
}
add_action( 'wp_ajax_aurora_search', 'aurora_ajax_search' );
add_action( 'wp_ajax_nopriv_aurora_search', 'aurora_ajax_search' );

/**
 * Toggle a like on a post. One like per visitor, tracked by cookie + IP hash.
 */
function aurora_ajax_like() {
	aurora_check_nonce();

	$post_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
		wp_send_json_error( array( 'message' => __( '无效的内容。', 'aurora' ) ), 400 );
	}

	$count = (int) get_post_meta( $post_id, 'aurora_likes', true );
	$key   = 'aurora_liked_' . $post_id;
	$liked = isset( $_COOKIE[ $key ] ) && '1' === $_COOKIE[ $key ];

	if ( $liked ) {
		$count  = max( 0, $count - 1 );
		$new    = false;
		setcookie( $key, '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN );
	} else {
		$count++;
		$new = true;
		setcookie( $key, '1', time() + YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN );
	}

	update_post_meta( $post_id, 'aurora_likes', $count );
	do_action( 'aurora_post_liked', $post_id, $new );

	wp_send_json_success( array(
		'count' => $count,
		'liked' => $new,
	) );
}
add_action( 'wp_ajax_aurora_like', 'aurora_ajax_like' );
add_action( 'wp_ajax_nopriv_aurora_like', 'aurora_ajax_like' );

/**
 * Front-end login.
 */
function aurora_ajax_login() {
	aurora_check_nonce();
	aurora_rate_json_guard( 'login' );
	aurora_captcha_guard_json( 'login' );

	$creds = array(
		'user_login'    => isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ) ) : '',
		'user_password' => isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '',
		'remember'      => true,
	);

	$user = wp_signon( $creds, is_ssl() );
	if ( is_wp_error( $user ) ) {
		wp_send_json_error( array( 'message' => wp_strip_all_tags( $user->get_error_message() ) ), 200 );
	}

	wp_send_json_success( array(
		'message'  => __( '登录成功', 'aurora' ),
		'redirect' => ! empty( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : aurora_user_page_url(),
	) );
}
add_action( 'wp_ajax_nopriv_aurora_login', 'aurora_ajax_login' );

/**
 * Front-end registration.
 */
function aurora_ajax_register() {
	aurora_check_nonce();
	aurora_rate_json_guard( 'register' );
	aurora_captcha_guard_json( 'register' );

	if ( ! get_theme_mod( 'aurora_register_open', true ) ) {
		wp_send_json_error( array( 'message' => __( '本站暂未开放注册。', 'aurora' ) ), 200 );
	}
	if ( ! get_option( 'users_can_register' ) ) {
		wp_send_json_error( array( 'message' => __( '管理员未开启注册功能。', 'aurora' ) ), 200 );
	}

	$username = isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ) ) : '';
	$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

	if ( strlen( $username ) < 3 || ! validate_username( $username ) ) {
		wp_send_json_error( array( 'message' => __( '用户名至少 3 个字符，且不能包含特殊符号。', 'aurora' ) ), 200 );
	}
	if ( username_exists( $username ) ) {
		wp_send_json_error( array( 'message' => __( '该用户名已被占用。', 'aurora' ) ), 200 );
	}
	if ( ! is_email( $email ) || email_exists( $email ) ) {
		wp_send_json_error( array( 'message' => __( '邮箱无效或已被注册。', 'aurora' ) ), 200 );
	}
	if ( strlen( $password ) < 8 ) {
		wp_send_json_error( array( 'message' => __( '密码至少 8 位。', 'aurora' ) ), 200 );
	}

	$user_id = wp_insert_user( array(
		'user_login'   => $username,
		'user_email'   => $email,
		'user_pass'    => $password,
		'display_name' => $username,
		'role'         => 'subscriber',
	) );

	if ( is_wp_error( $user_id ) ) {
		wp_send_json_error( array( 'message' => wp_strip_all_tags( $user_id->get_error_message() ) ), 200 );
	}

	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true, is_ssl() );

	wp_send_json_success( array(
		'message'  => __( '注册成功，正在进入用户中心…', 'aurora' ),
		'redirect' => aurora_user_page_url(),
	) );
}
add_action( 'wp_ajax_nopriv_aurora_register', 'aurora_ajax_register' );

/**
 * Update the current user's public profile.
 */
function aurora_ajax_profile() {
	aurora_check_nonce();

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( '请先登录。', 'aurora' ) ), 200 );
	}

	$user_id = get_current_user_id();
	$display = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
	$url     = isset( $_POST['user_url'] ) ? esc_url_raw( wp_unslash( $_POST['user_url'] ) ) : '';
	$bio     = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

	$result = wp_update_user( array(
		'ID'           => $user_id,
		'display_name' => $display ? $display : get_the_author_meta( 'display_name', $user_id ),
		'user_url'     => $url,
		'description'  => $bio,
	) );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => wp_strip_all_tags( $result->get_error_message() ) ), 200 );
	}

	wp_send_json_success( array( 'message' => __( '资料已更新。', 'aurora' ) ) );
}
add_action( 'wp_ajax_aurora_profile', 'aurora_ajax_profile' );

/**
 * Daily check-in reward.
 */
function aurora_ajax_checkin() {
	aurora_check_nonce();

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( '请先登录。', 'aurora' ) ), 200 );
	}

	$user_id = get_current_user_id();
	$today   = current_time( 'Y-m-d' );
	$last    = get_user_meta( $user_id, 'aurora_checkin_last', true );

	if ( $today === $last ) {
		wp_send_json_error( array( 'message' => __( '今天已经签到过了。', 'aurora' ) ), 200 );
	}

	// Streak: consecutive calendar days, reset the moment one is missed.
	$streak    = (int) get_user_meta( $user_id, 'aurora_checkin_streak', true );
	$yesterday = gmdate( 'Y-m-d', strtotime( $today ) - DAY_IN_SECONDS );
	$streak    = ( $last === $yesterday ) ? $streak + 1 : 1;

	$base = (int) get_theme_mod( 'aurora_points_checkin', 10 );
	$step = (int) get_theme_mod( 'aurora_points_checkin_streak', 2 );
	$cap  = (int) get_theme_mod( 'aurora_points_checkin_streak_cap', 10 );

	// The step is capped so a year-long streak does not pay out without limit.
	$extra  = min( max( $streak - 1, 0 ), $cap ) * $step;
	$amount = $base + $extra;

	$before = aurora_points_balance( $user_id );

	$earned = aurora_points_earn(
		$user_id,
		$amount,
		'checkin',
		array(
			'silent' => false,
			'note'   => sprintf(
				/* translators: %d: consecutive days */
				__( '连续签到第 %d 天', 'aurora' ),
				$streak
			),
		)
	);

	if ( is_wp_error( $earned ) ) {
		wp_send_json_error( array( 'message' => $earned->get_error_message() ), 200 );
	}

	update_user_meta( $user_id, 'aurora_checkin_last', $today );
	update_user_meta( $user_id, 'aurora_checkin_streak', $streak );

	/**
	 * Fires after a successful check-in.
	 *
	 * @param int $user_id User ID.
	 * @param int $streak  Consecutive days.
	 */
	do_action( 'aurora_checkin', $user_id, $streak );

	// Report what was actually credited, not what was requested: the member
	// bonus means the two numbers differ.
	$credited = max( 0, (int) $earned - $before );

	wp_send_json_success( array(
		'message' => sprintf(
			/* translators: 1: points credited, 2: streak days */
			__( '签到成功，积分 +%1$d（连续 %2$d 天）', 'aurora' ),
			$credited,
			$streak
		),
		'points'  => $earned,
		'streak'  => $streak,
		'earned'  => $credited,
	) );
}
add_action( 'wp_ajax_aurora_checkin', 'aurora_ajax_checkin' );

/**
 * Toggle bookmark / favourite.
 */
function aurora_ajax_bookmark() {
	aurora_check_nonce();

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( '请先登录。', 'aurora' ) ), 200 );
	}

	$user_id = get_current_user_id();
	$post_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	if ( ! $post_id ) {
		wp_send_json_error( array( 'message' => __( '无效的内容。', 'aurora' ) ), 200 );
	}

	$list = (array) get_user_meta( $user_id, 'aurora_bookmarks', true );
	$list = array_filter( array_map( 'intval', $list ) );

	$index = array_search( $post_id, $list, true );
	if ( false !== $index ) {
		unset( $list[ $index ] );
		$added = false;
		$msg   = __( '已取消收藏', 'aurora' );
	} else {
		$list[] = $post_id;
		$added  = true;
		$msg    = __( '已加入收藏', 'aurora' );
	}

	update_user_meta( $user_id, 'aurora_bookmarks', array_values( array_unique( $list ) ) );

	wp_send_json_success( array(
		'message' => $msg,
		'added'   => $added,
		'count'   => count( $list ),
	) );
}
add_action( 'wp_ajax_aurora_bookmark', 'aurora_ajax_bookmark' );

/**
 * Redirect browsers that land on admin-ajax.php without an action.
 */
function aurora_ajax_fallback() {
	wp_send_json_error( array( 'message' => __( '未知的请求。', 'aurora' ) ), 400 );
}
add_action( 'wp_ajax_aurora_noop', 'aurora_ajax_fallback' );
