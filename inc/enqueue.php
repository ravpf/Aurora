<?php
/**
 * Front-end assets.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue styles and scripts.
 */
function aurora_enqueue_assets() {
	$css = AURORA_DIR . '/assets/css/main.css';
	$js  = AURORA_DIR . '/assets/js/main.js';

	wp_enqueue_style( 'aurora-base', get_stylesheet_uri(), array(), AURORA_VERSION );
	wp_enqueue_style( 'aurora-main', AURORA_URI . '/assets/css/main.css', array( 'aurora-base' ), file_exists( $css ) ? (string) filemtime( $css ) : AURORA_VERSION );

	// Inline critical tokens so the first paint already matches the chosen theme.
	$mode = get_theme_mod( 'aurora_default_mode', 'auto' );
	$tokens = ':root{--aurora-radius-card:' . esc_attr( aurora_radius_card() ) . '}';

	// Published as a token rather than written straight onto body, so the
	// dark and Spotify blocks can switch it off by redefining one variable
	// instead of each needing its own override.
	$gradient = aurora_gradient_css();
	$tokens  .= ':root{--aurora-bg-image:' . ( $gradient ? $gradient : 'none' ) . '}';
	if ( 'light' === $mode ) {
		$tokens .= 'html{color-scheme:light}';
	} elseif ( array_key_exists( $mode, aurora_palettes() ) ) {
		// Every non-light palette is dark-on-black.
		$tokens .= 'html{color-scheme:dark}';
	}
	wp_add_inline_style( 'aurora-base', $tokens );

	wp_enqueue_script( 'aurora-main', AURORA_URI . '/assets/js/main.js', array(), file_exists( $js ) ? (string) filemtime( $js ) : AURORA_VERSION, true );

	$auth = AURORA_DIR . '/assets/js/auth.js';
	wp_enqueue_script( 'aurora-auth', AURORA_URI . '/assets/js/auth.js', array(), file_exists( $auth ) ? (string) filemtime( $auth ) : AURORA_VERSION, true );

	wp_localize_script( 'aurora-main', 'Aurora', array(
		'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'aurora_nonce' ),
		'restUrl'  => esc_url_raw( rest_url( 'wp/v2/' ) ),
		'loggedIn' => is_user_logged_in(),
		'defaultMode' => get_theme_mod( 'aurora_default_mode', 'auto' ),
		'modes'    => array_values( aurora_enabled_modes() ),
		'modeNames' => array(
			'light'   => __( '浅色模式', 'aurora' ),
			'dark'    => __( '深色模式', 'aurora' ),
			'spotify' => __( 'Spotify 风格', 'aurora' ),
			'auto'    => __( '跟随系统', 'aurora' ),
		),
		'modeSwitch' => array(
			'light'   => __( '切换到浅色', 'aurora' ),
			'dark'    => __( '切换到深色', 'aurora' ),
			'spotify' => __( '切换到 Spotify 风格', 'aurora' ),
			'auto'    => __( '切换到跟随系统', 'aurora' ),
		),
		'i18n'     => array(
			'copied'     => __( '已复制', 'aurora' ),
			'copy'       => __( '复制', 'aurora' ),
			'searching'  => __( '搜索中…', 'aurora' ),
			'noResult'   => __( '没有找到相关内容', 'aurora' ),
			'loading'    => __( '加载中…', 'aurora' ),
			'more'       => __( '加载更多', 'aurora' ),
			'noMore'     => __( '没有更多了', 'aurora' ),
			'loginFirst' => __( '请先登录', 'aurora' ),
			'noMessages' => __( '暂时没有消息', 'aurora' ),
			'markedRead' => __( '已标为已读', 'aurora' ),
			'download'   => __( '下载', 'aurora' ),
			'viewPost'   => __( '查看文章', 'aurora' ),
		),
	) );

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'aurora_enqueue_assets' );

/**
 * Preconnect to the font/asset origins we actually use.
 *
 * @param array  $urls           URLs to print.
 * @param string $relation_type  Relation type.
 * @return array
 */
function aurora_resource_hints( $urls, $relation_type ) {
	if ( 'dns-prefetch' === $relation_type ) {
		$urls[] = 'https://s.w.org';
	}
	return $urls;
}
add_filter( 'wp_resource_hints', 'aurora_resource_hints', 10, 2 );

/**
 * Drop the emoji script: the theme ships its own icon set.
 */
function aurora_disable_emojis() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
}
add_action( 'init', 'aurora_disable_emojis' );

/**
 * A small SVG sprite for inline icons.
 *
 * @param string $name Icon key.
 * @param int    $size Pixel size.
 * @return string
 */
function aurora_icon( $name, $size = 20 ) {
	$paths = array(
		'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		'sun'      => '<circle cx="12" cy="12" r="4.2"/><path d="M12 2v2.6M12 19.4V22M2 12h2.6M19.4 12H22M4.9 4.9l1.9 1.9M17.2 17.2l1.9 1.9M19.1 4.9l-1.9 1.9M6.8 17.2l-1.9 1.9"/>',
		'moon'     => '<path d="M20.5 14.6A8.6 8.6 0 1 1 9.4 3.5a7 7 0 0 0 11.1 11.1Z"/>',
		// Bars, not anybody's logo.
		'wave'     => '<path d="M4 9.5v5M8 5.5v13M12 8.5v7M16 4.5v15M20 10v4"/>',
		'user'     => '<circle cx="12" cy="8.5" r="3.8"/><path d="M4.5 20.2a7.7 7.7 0 0 1 15 0"/>',
		'heart'    => '<path d="M12 20.3s-7.6-4.6-7.6-9.6a4.3 4.3 0 0 1 7.6-2.8 4.3 4.3 0 0 1 7.6 2.8c0 5-7.6 9.6-7.6 9.6Z"/>',
		'star'     => '<path d="m12 3.4 2.6 5.4 5.9.8-4.3 4.1 1 5.9-5.2-2.8-5.2 2.8 1-5.9L3.5 9.6l5.9-.8Z"/>',
		'crown'    => '<path d="M3 17.5h18M4.6 7.2l4.1 3.4L12 5l3.3 5.6 4.1-3.4-1.6 8.1H6.2Z"/>',
		'clock'    => '<circle cx="12" cy="12" r="8.4"/><path d="M12 7.6V12l3 1.9"/>',
		'eye'      => '<path d="M2.4 12S5.9 5.8 12 5.8 21.6 12 21.6 12 18.1 18.2 12 18.2 2.4 12 2.4 12Z"/><circle cx="12" cy="12" r="3"/>',
		'comment'  => '<path d="M20.5 12.4c0 4-3.8 7.2-8.5 7.2a10 10 0 0 1-2.6-.3L4.5 21l1.3-3.8A6.8 6.8 0 0 1 3.5 12.4c0-4 3.8-7.2 8.5-7.2s8.5 3.2 8.5 7.2Z"/>',
		'share'    => '<path d="M12 15.5V3.8M7.8 7.9 12 3.6l4.2 4.3M5.4 13.5v5.3a1.6 1.6 0 0 0 1.6 1.6h10a1.6 1.6 0 0 0 1.6-1.6v-5.3"/>',
		'link'     => '<path d="M9.4 14.6 14.6 9.4M10.9 6.9l1.7-1.7a4.2 4.2 0 0 1 6 6l-1.7 1.7M13.1 17.1l-1.7 1.7a4.2 4.2 0 0 1-6-6l1.7-1.7"/>',
		'menu'     => '<path d="M3.6 7.2h16.8M3.6 12h16.8M3.6 16.8h16.8"/>',
		'close'    => '<path d="M6 6l12 12M18 6 6 18"/>',
		'chevron'  => '<path d="m9 6 6 6-6 6"/>',
		'grid'     => '<rect x="3.4" y="3.4" width="7.2" height="7.2" rx="2"/><rect x="13.4" y="3.4" width="7.2" height="7.2" rx="2"/><rect x="3.4" y="13.4" width="7.2" height="7.2" rx="2"/><rect x="13.4" y="13.4" width="7.2" height="7.2" rx="2"/>',
		'list'     => '<path d="M4 6.5h16M4 12h16M4 17.5h16"/>',
		'download' => '<path d="M12 3.6v11M7.8 10.4 12 14.6l4.2-4.2M4.6 19.4h14.8"/>',
		'check'    => '<path d="m4.8 12.6 4.8 4.8L19.2 7.4"/>',
		'fire'     => '<path d="M12 21c3.6 0 6.2-2.4 6.2-5.7 0-3.9-3.4-5.6-3.9-9.9-2.3 1.2-3.5 3.2-3.5 4.9 0 1.2-.7 2-1.7 2s-1.6-.8-1.8-1.8C6.1 12.1 5.8 13 5.8 15.3 5.8 18.6 8.4 21 12 21Z"/>',
		'up'       => '<path d="M12 19.4V4.6M6.4 10.2 12 4.6l5.6 5.6"/>',
		'down'     => '<path d="M12 4.6v14.8M6.4 13.8 12 19.4l5.6-5.6"/>',
		// 板块图标用（骨架图上这些名字之前没画，页面上是空方块）
		'message'  => '<path d="M20.6 11.8c0 3.9-3.8 7-8.6 7-1 0-2-.1-2.9-.4L4.2 20.4l1.5-3.8A6.6 6.6 0 0 1 3.4 11.8c0-3.9 3.8-7 8.6-7s8.6 3.1 8.6 7Z"/>',
		'book'     => '<path d="M4.4 4.8h5.2a2.4 2.4 0 0 1 2.4 2.4v12a2 2 0 0 0-2-2H4.4Zm15.2 0h-5.2A2.4 2.4 0 0 0 12 7.2v12a2 2 0 0 1 2-2h5.6Z"/>',
		'bolt'     => '<path d="M13.4 2.6 4.8 13.4h5.6l-1 8 8.6-10.8h-5.6Z"/>',
		'lock'     => '<rect x="4.6" y="10.4" width="14.8" height="10" rx="2.6"/><path d="M8.2 10.4V7.8a3.8 3.8 0 0 1 7.6 0v2.6"/>',
		'unlock'   => '<rect x="4.6" y="10.4" width="14.8" height="10" rx="2.6"/><path d="M8.2 10.4V7.8a3.8 3.8 0 0 1 7.6-.4"/>',
		'tag'      => '<path d="M12.6 3.4H20a.6.6 0 0 1 .6.6v7.4a1.6 1.6 0 0 1-.5 1.1l-7.2 7.2a1.6 1.6 0 0 1-2.3 0l-6.3-6.3a1.6 1.6 0 0 1 0-2.3l7.2-7.2a1.6 1.6 0 0 1 1.1-.5Z"/><circle cx="16.4" cy="7.6" r="1.4"/>',
		'mail'     => '<rect x="3" y="5.4" width="18" height="13.2" rx="2.6"/><path d="m3.8 7.4 7.1 5.3a2 2 0 0 0 2.2 0l7.1-5.3"/>',
		'undo'     => '<path d="M9.4 5.6 4.6 10.4l4.8 4.8"/><path d="M4.6 10.4h9.2a5.4 5.4 0 0 1 0 10.8h-2.4"/>',
		'receipt'  => '<path d="M6 3.6h12v16.8l-2.4-1.6-2.4 1.6-2.4-1.6L8.4 20.4 6 18.8Z"/><path d="M9.2 8.4h5.6M9.2 12.4h5.6"/>',
		'cart'     => '<circle cx="9.6" cy="19.4" r="1.5"/><circle cx="17.6" cy="19.4" r="1.5"/><path d="M2.8 3.6h2.5l2.5 11.6h11l2-8.4H7.2"/>',
		'gift'     => '<path d="M3.8 11.6h16.4v7.6a2 2 0 0 1-2 2H5.8a2 2 0 0 1-2-2z"/><path d="M2.8 7.8h18.4v3.8H2.8zM12 7.8v13.4"/><path d="M12 7.8S10.8 3.2 8.2 3.2a2.3 2.3 0 0 0 0 4.6zM12 7.8s1.2-4.6 3.8-4.6a2.3 2.3 0 0 1 0 4.6z"/>',
		'bell'     => '<path d="M18.4 16.4V11a6.4 6.4 0 1 0-12.8 0v5.4l-1.8 2.2h16.4z"/><path d="M9.9 21.4h4.2"/>',
		'shield'   => '<path d="M12 3.2 5 6v6c0 4.2 2.9 7.4 7 9 4.1-1.6 7-4.8 7-9V6z"/><path d="m9.2 12.2 2 2 3.6-3.8"/>',
		'sparkles' => '<path d="M10.4 3.6 12 8l4.4 1.6L12 11.2 10.4 15.6 8.8 11.2 4.4 9.6 8.8 8z"/><path d="m17.8 13.8.7 1.9 1.9.7-1.9.7-.7 1.9-.7-1.9-1.9-.7 1.9-.7z"/>',
	);

	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}

	return sprintf(
		'<svg class="aurora-icon aurora-icon--%1$s" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%3$s</svg>',
		esc_attr( $name ),
		(int) $size,
		$paths[ $name ]
	);
}

/**
 * Echo helper for aurora_icon().
 *
 * @param string $name Icon key.
 * @param int    $size Pixel size.
 */
function aurora_the_icon( $name, $size = 20 ) {
	echo aurora_icon( $name, $size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup.
}

/**
 * Admin-side editor styles so the block editor matches the front end.
 */
function aurora_editor_assets() {
	wp_enqueue_style( 'aurora-editor', AURORA_URI . '/assets/css/editor.css', array(), AURORA_VERSION );
}
add_action( 'enqueue_block_editor_assets', 'aurora_editor_assets' );
