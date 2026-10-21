<?php
/**
 * User centre: pages, tabs, profile data.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create the membership + user-centre pages on theme activation.
 */
function aurora_after_switch_theme() {
	$pages = array(
		'aurora_user_page_id' => array(
			'title'    => __( '用户中心', 'aurora' ),
			'slug'     => 'user',
			'template' => 'page-templates/user-center.php',
			'content'  => '',
		),
		'aurora_vip_page_id'  => array(
			'title'    => __( '会员中心', 'aurora' ),
			'slug'     => 'vip',
			'template' => 'page-templates/vip.php',
			'content'  => '',
		),
		'aurora_nav_page_id'  => array(
			'title'    => __( '网址导航', 'aurora' ),
			'slug'     => 'nav',
			'template' => 'page-templates/nav.php',
			'content'  => '',
		),
		'aurora_hot_page_id'  => array(
			'title'    => __( '热点榜单', 'aurora' ),
			'slug'     => 'hot',
			'template' => 'page-templates/hot.php',
			'content'  => '',
		),
	);

	foreach ( $pages as $option => $page ) {
		$existing = (int) get_option( $option );
		if ( $existing && get_post( $existing ) ) {
			continue;
		}

		$by_slug = get_page_by_path( $page['slug'] );
		if ( $by_slug ) {
			update_option( $option, $by_slug->ID );
			update_post_meta( $by_slug->ID, '_wp_page_template', $page['template'] );
			continue;
		}

		$id = wp_insert_post( array(
			'post_title'   => $page['title'],
			'post_name'    => $page['slug'],
			'post_content' => $page['content'],
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => get_current_user_id() ? get_current_user_id() : 1,
		) );

		if ( $id && ! is_wp_error( $id ) ) {
			update_post_meta( $id, '_wp_page_template', $page['template'] );
			update_option( $option, $id );
		}
	}

	// Sensible defaults on first activation.
	if ( ! get_option( 'aurora_installed' ) ) {
		update_option( 'aurora_installed', gmdate( 'Y-m-d' ) );
		update_option( 'users_can_register', 1 );
		update_option( 'default_role', 'subscriber' );
		update_option( 'show_avatars', 1 );
	}

	flush_rewrite_rules();
}
add_action( 'after_switch_theme', 'aurora_after_switch_theme' );

/**
 * Redirect subscribers away from wp-admin to the user centre.
 *
 * @param string $url       Target URL.
 * @param string $path      Requested path.
 * @param int    $blog_id   Blog ID.
 * @param string $scheme    Scheme.
 * @return string
 */
function aurora_redirect_subscribers( $url, $path, $blog_id, $scheme ) {
	if ( is_admin() || wp_doing_ajax() ) {
		return $url;
	}

	return $url;
}

/**
 * A user's initial for their local avatar tile.
 *
 * @param int $user_id User ID.
 * @return string
 */
function aurora_avatar_initial( $user_id ) {
	$user_id = (int) $user_id;
	$name    = $user_id ? (string) get_the_author_meta( 'display_name', $user_id ) : '';

	if ( ! $name && $user_id ) {
		$user = get_userdata( $user_id );
		$name = $user ? (string) $user->user_login : '';
	}

	$name = trim( wp_strip_all_tags( $name ) );

	if ( ! $name ) {
		// Imported content and seed data often has no author at all. A tile with
		// the site's own initial reads better than a bare "?".
		$name = (string) get_bloginfo( 'name' );
	}

	return $name ? mb_strtoupper( mb_substr( $name, 0, 1 ) ) : '?';
}

/**
 * Render an avatar as inline SVG: a filled circle with the user's initial.
 *
 * Deliberately not an <img>: WordPress builds avatar images on
 * secure.gravatar.com, and on a network that cannot reach it the request hangs
 * rather than failing, so the browser's load event never fires and the page
 * looks like it is loading forever. Inline SVG cannot hang — there is no
 * request at all — and it stays sharp at any size.
 *
 * @param string $seed    Anything stable per user (id, email) for the colour.
 * @param string $initial Letter to show.
 * @param int    $size    Size in pixels.
 * @param string $classes Extra classes.
 * @return string
 */
function aurora_avatar_svg( $seed, $initial, $size = 64, $classes = '' ) {
	$size  = max( 16, min( 512, (int) $size ) );
	$hash  = substr( md5( 'aurora-avatar-' . (string) $seed ), 0, 8 );
	$hue   = (int) ( hexdec( substr( $hash, 0, 4 ) ) % 360 );
	// 同一个用户一页出现多次时，渐变 id 必须各不相同，否则文档里会有一串重复 id。
	static $seq = 0;
	$seq++;
	$id    = 'av' . $hash . '-' . $seq;
	$label = esc_attr( (string) $initial );

	return '<svg class="' . esc_attr( trim( 'avatar aurora-avatar ' . $classes ) ) . '"'
		. ' width="' . $size . '" height="' . $size . '"'
		. ' viewBox="0 0 64 64" role="img" aria-label="' . $label . '" focusable="false">'
		. '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1">'
		. '<stop offset="0" stop-color="hsl(' . $hue . ', 68%, 58%)"/>'
		. '<stop offset="1" stop-color="hsl(' . $hue . ', 70%, 44%)"/>'
		. '</linearGradient></defs>'
		. '<rect width="64" height="64" rx="32" fill="url(#' . $id . ')"/>'
		. '<text x="32" y="32" text-anchor="middle" dominant-baseline="central"'
		. ' font-family="-apple-system, BlinkMacSystemFont, \'PingFang SC\', sans-serif"'
		. ' font-size="30" font-weight="600" fill="#fff">' . esc_html( (string) $initial ) . '</text>'
		. '</svg>';
}

/**
 * Front-end avatar: the local tile, never a remote image.
 *
 * @param int    $user_id User ID.
 * @param int    $size    Size in pixels.
 * @param string $classes Extra classes.
 * @return string
 */
function aurora_avatar( $user_id, $size = 64, $classes = '' ) {
	$user_id = (int) $user_id;

	if ( $user_id && get_userdata( $user_id ) ) {
		return aurora_avatar_svg( $user_id, aurora_avatar_initial( $user_id ), $size, $classes );
	}

	return '';
}

/**
 * Work out which user an avatar was requested for.
 *
 * @param mixed $id_or_email User ID, email, WP_User, WP_Post or WP_Comment.
 * @return int User ID, or 0 when there is nothing to resolve.
 */
function aurora_avatar_user_id( $id_or_email ) {
	if ( is_numeric( $id_or_email ) ) {
		return (int) $id_or_email;
	}
	if ( $id_or_email instanceof WP_User ) {
		return (int) $id_or_email->ID;
	}
	if ( $id_or_email instanceof WP_Post ) {
		return (int) $id_or_email->post_author;
	}
	if ( $id_or_email instanceof WP_Comment ) {
		if ( $id_or_email->user_id ) {
			return (int) $id_or_email->user_id;
		}
		$id_or_email = $id_or_email->comment_author_email;
	}
	if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
		$user = get_user_by( 'email', $id_or_email );
		return $user ? (int) $user->ID : 0;
	}

	return 0;
}

/**
 * Strip Gravatar out of every avatar WordPress renders.
 *
 * Covers the core comment list and anything else calling get_avatar(), not just
 * this theme's own call sites. A deliberate non-Gravatar URL is left alone, so
 * a plugin that serves its own avatars still works.
 *
 * @param string $avatar      Image tag.
 * @param mixed  $id_or_email Who the avatar is for.
 * @param int    $size        Size in pixels.
 * @param string $default     Default image.
 * @param string $alt         Alt text.
 * @param array  $args        Extra arguments.
 * @return string
 */
function aurora_filter_gravatar( $avatar, $id_or_email, $size, $default, $alt, $args = array() ) {
	if ( preg_match( '/<img[^>]+src=["\']([^"\']*)["\']/i', (string) $avatar, $m ) ) {
		$src = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
		if ( $src && false === stripos( $src, 'gravatar.com' ) ) {
			return $avatar;
		}
	}

	$user_id = aurora_avatar_user_id( $id_or_email );
	$seed    = $user_id ? $user_id : ( is_string( $id_or_email ) ? $id_or_email : 'aurora' );
	$initial = $user_id ? aurora_avatar_initial( $user_id ) : '?';
	$classes = isset( $args['class'] ) ? (string) $args['class'] : '';

	return aurora_avatar_svg( $seed, $initial, $size ? (int) $size : 64, $classes );
}
add_filter( 'get_avatar', 'aurora_filter_gravatar', 10, 6 );

/**
 * Profile stats: published posts, comments, bookmarks, points, likes received.
 *
 * @param int $user_id User ID.
 * @return array
 */
function aurora_user_stats( $user_id ) {
	global $wpdb;

	$posts = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d AND post_status = 'publish' AND post_type = 'post'",
		$user_id
	) );

	$comments = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id = %d AND comment_approved = '1'",
		$user_id
	) );

	$bookmarks = (array) get_user_meta( $user_id, 'aurora_bookmarks', true );

	// Likes across the author's posts.
	$likes = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COALESCE(SUM(pm.meta_value+0),0)
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key = 'aurora_likes' AND p.post_author = %d AND p.post_status = 'publish'",
		$user_id
	) );

	return array(
		'posts'     => $posts,
		'comments'  => $comments,
		'bookmarks' => count( array_filter( $bookmarks ) ),
		'points'    => (int) get_user_meta( $user_id, 'aurora_points', true ),
		'likes'     => $likes,
	);
}

/**
 * The user centre tab definitions.
 *
 * @return array
 */
/**
 * Which user-centre tab is active.
 *
 * Parts included with get_template_part() do not inherit the page template's
 * locals, so each of them reading `$aurora_active` directly always saw an empty
 * value — the pane was rendered but never marked active, and a direct
 * `?tab=...` link showed an empty column. Deriving it in one place fixes the
 * parts as a group and keeps the tab list as the single source of truth.
 */
function aurora_user_active_tab() {
	$tabs   = aurora_user_tabs();
	$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';

	return isset( $tabs[ $active ] ) ? $active : 'overview';
}

function aurora_user_tabs() {
	$tabs = array(
		'overview'  => array( 'label' => __( '概览', 'aurora' ), 'icon' => 'grid' ),
		'notify'    => array(
			'label' => __( '消息', 'aurora' ),
			'icon'  => 'bell',
			'badge' => function_exists( 'aurora_notify_unread' ) ? aurora_notify_unread() : 0,
		),
		'points'    => array( 'label' => __( '我的积分', 'aurora' ), 'icon' => 'fire' ),
		'redeem'    => array( 'label' => __( '卡密兑换', 'aurora' ), 'icon' => 'gift' ),
		'downloads' => array( 'label' => __( '我的下载', 'aurora' ), 'icon' => 'download' ),
		'orders'    => array( 'label' => __( '我的订单', 'aurora' ), 'icon' => 'receipt' ),
		'bookmarks' => array( 'label' => __( '我的收藏', 'aurora' ), 'icon' => 'heart' ),
		'posts'     => array( 'label' => __( '我的文章', 'aurora' ), 'icon' => 'list' ),
		'profile'   => array( 'label' => __( '编辑资料', 'aurora' ), 'icon' => 'user' ),
	);

	return apply_filters( 'aurora_user_tabs', $tabs );
}

/**
 * Posts favourited by a user.
 *
 * @param int $user_id User ID.
 * @param int $limit   Max posts.
 * @return WP_Query
 */
function aurora_user_bookmarks( $user_id, $limit = 12 ) {
	$ids = array_filter( array_map( 'intval', (array) get_user_meta( $user_id, 'aurora_bookmarks', true ) ) );

	if ( empty( $ids ) ) {
		return new WP_Query( array( 'post__in' => array( 0 ), 'posts_per_page' => 1 ) );
	}

	return new WP_Query( array(
		'post__in'       => $ids,
		'post_type'      => 'post',
		'posts_per_page' => $limit,
		'post_status'    => 'publish',
		'no_found_rows'  => true,
	) );
}

/**
 * Login form markup used both on the standalone page and in the modal.
 *
 * @param string $redirect Redirect target after login.
 */
function aurora_login_form( $redirect = '' ) {
	?>
	<form class="aurora-auth-form" data-mode="login" method="post" action="<?php echo esc_url( wp_login_url() ); ?>">
		<div class="aurora-field">
			<label class="aurora-field__label" for="aurora-login"><?php esc_html_e( '用户名或邮箱', 'aurora' ); ?></label>
			<input type="text" id="aurora-login" name="login" autocomplete="username" required>
		</div>
		<div class="aurora-field">
			<label class="aurora-field__label" for="aurora-pass"><?php esc_html_e( '密码', 'aurora' ); ?></label>
			<input type="password" id="aurora-pass" name="password" autocomplete="current-password" required>
		</div>
		<input type="hidden" name="redirect" value="<?php echo esc_attr( $redirect ? $redirect : aurora_user_page_url() ); ?>">
		<?php echo aurora_captcha_field( 'login' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the module. ?>
		<button type="submit" class="aurora-btn aurora-btn--primary aurora-btn--block aurora-btn--lg"><?php esc_html_e( '登录', 'aurora' ); ?></button>
		<p class="aurora-auth-form__msg" role="status" aria-live="polite"></p>
		<p style="margin:14px 0 0;font-size:.86rem;text-align:center;color:var(--aurora-text-tertiary)">
			<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( '忘记密码？', 'aurora' ); ?></a>
		</p>
	</form>
	<?php
}

/**
 * Registration form markup.
 */
function aurora_register_form() {
	?>
	<form class="aurora-auth-form" data-mode="register" method="post">
		<div class="aurora-field">
			<label class="aurora-field__label" for="aurora-reg-login"><?php esc_html_e( '用户名', 'aurora' ); ?></label>
			<input type="text" id="aurora-reg-login" name="login" autocomplete="username" required>
		</div>
		<div class="aurora-field">
			<label class="aurora-field__label" for="aurora-reg-email"><?php esc_html_e( '邮箱', 'aurora' ); ?></label>
			<input type="email" id="aurora-reg-email" name="email" autocomplete="email" required>
		</div>
		<div class="aurora-field">
			<label class="aurora-field__label" for="aurora-reg-pass"><?php esc_html_e( '密码（至少 8 位）', 'aurora' ); ?></label>
			<input type="password" id="aurora-reg-pass" name="password" autocomplete="new-password" required minlength="8">
		</div>
		<?php echo aurora_captcha_field( 'register' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the module. ?>
		<?php if ( get_option( 'users_can_register' ) ) : ?>
			<button type="submit" class="aurora-btn aurora-btn--primary aurora-btn--block aurora-btn--lg"><?php esc_html_e( '创建账户', 'aurora' ); ?></button>
		<?php else : ?>
			<p class="aurora-notice aurora-notice--warning"><?php esc_html_e( '管理员尚未开放注册。', 'aurora' ); ?></p>
		<?php endif; ?>
		<p class="aurora-auth-form__msg" role="status" aria-live="polite"></p>
	</form>
	<?php
}
