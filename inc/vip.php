<?php
/**
 * VIP membership: levels, user meta, content gating, purchase flow.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Available membership levels. Extend with the `aurora_vip_levels` filter.
 *
 * @return array
 */
function aurora_vip_levels() {
	// Prices are owner-editable; fall back to the shipped defaults if malformed.
	$configured = array_map( 'trim', explode( ',', (string) get_theme_mod( 'aurora_vip_prices', '19,39,99' ) ) );
	$prices     = array();
	foreach ( array( 1, 2, 3 ) as $index ) {
		$value      = isset( $configured[ $index - 1 ] ) ? (float) $configured[ $index - 1 ] : 0;
		$prices[ $index ] = $value > 0 ? $value : array( 1 => 19, 2 => 39, 3 => 99 )[ $index ];
	}

	$levels = array(
		0 => array(
			'name'     => __( '普通用户', 'aurora' ),
			'slug'     => 'free',
			'price'    => 0,
			'period'   => '',
			'class'    => '',
			'features' => array(),
		),
		1 => array(
			'name'     => __( '白银会员', 'aurora' ),
			'slug'     => 'silver',
			'price'    => $prices[1],
			'period'   => __( '月', 'aurora' ),
			'class'    => 'aurora-vip-badge--l1',
			'features' => array(
				__( '解锁白银及以下内容', 'aurora' ),
				__( '移除文章内广告', 'aurora' ),
				__( '高清图片下载', 'aurora' ),
			),
		),
		2 => array(
			'name'     => __( '黄金会员', 'aurora' ),
			'slug'     => 'gold',
			'price'    => $prices[2],
			'period'   => __( '月', 'aurora' ),
			'class'    => 'aurora-vip-badge--l2',
			'features' => array(
				__( '解锁黄金及以下全部内容', 'aurora' ),
				__( '专属会员标识', 'aurora' ),
				__( '优先评论回复', 'aurora' ),
			),
		),
		3 => array(
			'name'     => __( '钻石会员', 'aurora' ),
			'slug'     => 'diamond',
			'price'    => $prices[3],
			'period'   => __( '月', 'aurora' ),
			'class'    => 'aurora-vip-badge--l3',
			'features' => array(
				__( '解锁全站内容', 'aurora' ),
				__( '源文件 / 素材下载', 'aurora' ),
				__( '一对一技术支持', 'aurora' ),
				__( '月度专属内容', 'aurora' ),
			),
		),
	);

	return apply_filters( 'aurora_vip_levels', $levels );
}

/**
 * Get a single level definition.
 *
 * @param int $level Level number.
 * @return array|null
 */
function aurora_get_vip_level( $level ) {
	$levels = aurora_vip_levels();
	$level  = (int) $level;

	return isset( $levels[ $level ] ) ? $levels[ $level ] : null;
}

/**
 * Current user's level. Members are stored in user meta.
 *
 * @param int|null $user_id User ID.
 * @return int
 */
function aurora_get_user_level( $user_id = null ) {
	// An explicit user is always evaluated, even with no session: cron jobs and
	// CLI checks ask about a specific user, and returning 0 for everyone there
	// would silently mis-gate membership content.
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return 0;
	}

	if ( user_can( $user_id, 'manage_options' ) ) {
		return 3; // Administrators see everything.
	}

	$level   = (int) get_user_meta( $user_id, 'aurora_vip_level', true );
	$expires = get_user_meta( $user_id, 'aurora_vip_expires', true );

	if ( $level > 0 && $expires && strtotime( $expires ) < time() ) {
		return 0; // Expired.
	}

	return max( 0, min( 3, $level ) );
}

/**
 * Expiry date for a user, if any.
 *
 * @param int|null $user_id User ID.
 * @return string
 */
function aurora_get_user_expiry( $user_id = null ) {
	$user_id = $user_id ? $user_id : get_current_user_id();

	return (string) get_user_meta( $user_id, 'aurora_vip_expires', true );
}

/**
 * Move a user up to a level for N days (stacks onto an existing expiry).
 *
 * @param int    $user_id User ID.
 * @param int    $level   Target level.
 * @param int    $days    Duration in days.
 * @param string $source  Free-form audit note.
 */
function aurora_grant_vip( $user_id, $level, $days = 30, $source = '' ) {
	$user_id = (int) $user_id;
	$level   = max( 0, min( 3, (int) $level ) );
	if ( $level < 1 ) {
		return;
	}

	$current_expiry = get_user_meta( $user_id, 'aurora_vip_expires', true );
	$base           = ( $current_expiry && strtotime( $current_expiry ) > time() ) ? strtotime( $current_expiry ) : time();
	$new_expiry     = gmdate( 'Y-m-d H:i:s', $base + ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );

	update_user_meta( $user_id, 'aurora_vip_level', $level );
	update_user_meta( $user_id, 'aurora_vip_expires', $new_expiry );
	do_action( 'aurora_vip_granted', $user_id, $level, $new_expiry, $source );
}

/**
 * Does the current viewer satisfy the level required by a post?
 *
 * @param int|null $post_id Post ID.
 * @return bool
 */
function aurora_content_locked( $post_id = null ) {
	$post_id = $post_id ? $post_id : get_the_ID();

	if ( ! get_theme_mod( 'aurora_enable_paywall', true ) ) {
		return false;
	}
	if ( ! get_theme_mod( 'aurora_vip_enabled', true ) ) {
		return false;
	}

	// A category rule is broader than anything set on the article itself — it
	// covers every article filed under that term, and its descendants — so it
	// is evaluated before the per-article price tag or level.
	if ( function_exists( 'aurora_post_category_locked' ) && aurora_post_category_locked( $post_id ) ) {
		return true;
	}

	// A price tag on the article takes priority over level gating: it is the
	// more specific sale, and the editor warns when both are set.
	if ( function_exists( 'aurora_post_is_paid' ) && aurora_post_is_paid( $post_id ) ) {
		return aurora_post_locked( $post_id );
	}

	$required = (int) get_post_meta( $post_id, 'aurora_vip_level', true );
	if ( $required < 1 ) {
		return false;
	}

	return aurora_get_user_level() < $required;
}

/**
 * Render a VIP badge for a user.
 *
 * @param int|null $user_id User ID.
 * @param bool     $show_free Whether to show a badge for level 0.
 */
function aurora_vip_badge( $user_id = null, $show_free = false ) {
	$level = aurora_get_user_level( $user_id );
	if ( 0 === $level && ! $show_free ) {
		return;
	}
	$info = aurora_get_vip_level( $level );
	if ( ! $info ) {
		return;
	}
	printf(
		'<span class="aurora-vip-badge %1$s">%2$s%3$s</span>',
		esc_attr( $info['class'] ),
		$level > 0 ? aurora_icon( 'crown', 12 ) : '',
		esc_html( $info['name'] )
	);
}

/* -------------------------------------------------------------------------
 * Post meta box: restrict a post to a membership level
 * ---------------------------------------------------------------------- */

/**
 * Register the post meta box.
 */
function aurora_add_vip_metabox() {
	add_meta_box(
		'aurora-vip',
		__( 'Aurora · 会员权限', 'aurora' ),
		'aurora_vip_metabox_html',
		array( 'post', 'page' ),
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'aurora_add_vip_metabox' );

/**
 * Meta box markup.
 *
 * @param WP_Post $post Post being edited.
 */
function aurora_vip_metabox_html( $post ) {
	wp_nonce_field( 'aurora_save_vip', 'aurora_vip_nonce' );
	$current = (int) get_post_meta( $post->ID, 'aurora_vip_level', true );
	?>
	<p>
		<label for="aurora_vip_level"><strong><?php esc_html_e( '最低会员等级', 'aurora' ); ?></strong></label>
	</p>
	<select name="aurora_vip_level" id="aurora_vip_level" style="width:100%">
		<option value="0" <?php selected( $current, 0 ); ?>><?php esc_html_e( '公开（所有人可见）', 'aurora' ); ?></option>
		<?php foreach ( aurora_vip_levels() as $index => $info ) : ?>
			<?php if ( 0 === $index ) { continue; } ?>
			<option value="<?php echo esc_attr( $index ); ?>" <?php selected( $current, $index ); ?>>
				<?php echo esc_html( sprintf( '%d · %s', $index, $info['name'] ) ); ?>
			</option>
		<?php endforeach; ?>
	</select>
	<p class="description"><?php esc_html_e( '低于该等级的用户只能看到摘要部分。', 'aurora' ); ?></p>
	<?php
}

/**
 * Persist the restriction level.
 *
 * @param int $post_id Post ID.
 */
function aurora_save_vip_metabox( $post_id ) {
	if ( ! isset( $_POST['aurora_vip_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['aurora_vip_nonce'] ), 'aurora_save_vip' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$level = isset( $_POST['aurora_vip_level'] ) ? (int) $_POST['aurora_vip_level'] : 0;
	update_post_meta( $post_id, 'aurora_vip_level', max( 0, min( 3, $level ) ) );
}
add_action( 'save_post', 'aurora_save_vip_metabox' );

/* -------------------------------------------------------------------------
 * Shortcode: [aurora_vip_only level="2"] ... [/aurora_vip_only]
 * ---------------------------------------------------------------------- */

/**
 * Gate arbitrary content behind a membership level.
 *
 * @param array  $atts    Shortcode attributes.
 * @param string $content Inner content.
 * @return string
 */
function aurora_vip_only_shortcode( $atts, $content = '' ) {
	$atts  = shortcode_atts( array( 'level' => 1 ), $atts, 'aurora_vip_only' );
	$level = max( 1, (int) $atts['level'] );

	if ( aurora_get_user_level() >= $level ) {
		return do_shortcode( $content );
	}

	$info = aurora_get_vip_level( $level );

	return sprintf(
		'<div class="aurora-paywall"><div class="aurora-paywall__title">%1$s</div><div class="aurora-paywall__desc">%2$s</div><div class="aurora-paywall__actions"><a class="aurora-btn aurora-btn--primary" href="%3$s">%4$s</a></div></div>',
		esc_html__( '此部分内容需要会员权限', 'aurora' ),
		esc_html( sprintf( __( '升级到「%s」即可查看完整内容。', 'aurora' ), $info ? $info['name'] : __( '会员', 'aurora' ) ) ),
		esc_url( aurora_vip_page_url() ),
		esc_html__( '立即开通', 'aurora' )
	);
}
add_shortcode( 'aurora_vip_only', 'aurora_vip_only_shortcode' );

/**
 * Shortcode: [aurora_vip_plans]
 *
 * @return string
 */
function aurora_vip_plans_shortcode() {
	ob_start();
	get_template_part( 'template-parts/vip-plans' );

	return ob_get_clean();
}
add_shortcode( 'aurora_vip_plans', 'aurora_vip_plans_shortcode' );

/**
 * URL of the membership page (created on activation).
 *
 * @return string
 */
function aurora_vip_page_url() {
	$page_id = (int) get_option( 'aurora_vip_page_id' );

	return $page_id ? get_permalink( $page_id ) : home_url( '/vip/' );
}

/**
 * Display name for a level number, for copy that has no level array to hand.
 *
 * @param int $level Level number.
 * @return string
 */
function aurora_vip_level_name( $level ) {
	$info = aurora_get_vip_level( (int) $level );

	return $info ? $info['name'] : sprintf( 'Lv%d', (int) $level );
}

/**
 * URL of the user centre page.
 *
 * @param string $tab Optional tab to deep-link, e.g. 'points'.
 * @return string
 */
function aurora_user_page_url( $tab = '' ) {
	$page_id = (int) get_option( 'aurora_user_page_id' );
	$url     = $page_id ? get_permalink( $page_id ) : home_url( '/user/' );

	return $tab ? add_query_arg( 'tab', sanitize_key( $tab ), $url ) : $url;
}

/**
 * URL of the hot-topics page.
 *
 * @return string
 */
function aurora_hot_page_url() {
	$page_id = (int) get_option( 'aurora_hot_page_id' );

	return $page_id ? get_permalink( $page_id ) : home_url( '/hot' );
}

/**
 * URL of the link-directory page.
 *
 * @return string
 */
function aurora_nav_page_url() {
	$page_id = (int) get_option( 'aurora_nav_page_id' );

	return $page_id ? get_permalink( $page_id ) : home_url( '/nav' );
}
