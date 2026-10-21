<?php
/**
 * Theme supports, menus, image sizes.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register theme features.
 */
function aurora_setup() {
	load_theme_textdomain( 'aurora', AURORA_DIR . '/languages' );

	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-logo', array(
		'height'      => 64,
		'width'       => 240,
		'flex-height' => true,
		'flex-width'  => true,
	) );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'editor-styles' );
	// Without this the block editor canvas falls back to core typography while
	// the front end is set in the system stack, so the same post looks different
	// before and after publishing.
	add_editor_style( 'assets/css/editor.css' );
	add_theme_support( 'custom-background', array( 'default-color' => 'f5f5f7' ) );
	add_theme_support( 'post-formats', array( 'aside', 'gallery', 'link', 'quote', 'video', 'audio' ) );

	register_nav_menus( array(
		'primary' => __( '主导航', 'aurora' ),
		'mobile'  => __( '移动端导航', 'aurora' ),
		'footer'  => __( '页脚导航', 'aurora' ),
		'user'    => __( '用户中心菜单', 'aurora' ),
	) );

	// A wide card crop that suits the Apple-style gallery grid.
	add_image_size( 'aurora-card', 720, 420, true );
	add_image_size( 'aurora-card-wide', 1200, 520, true );
	add_image_size( 'aurora-avatar', 160, 160, true );

	set_post_thumbnail_size( 720, 420, true );
}
add_action( 'after_setup_theme', 'aurora_setup' );

/**
 * Content width for oEmbeds and wide images.
 */
function aurora_content_width() {
	$GLOBALS['content_width'] = apply_filters( 'aurora_content_width', 760 );
}
add_action( 'after_setup_theme', 'aurora_content_width', 0 );

/**
 * Body classes that drive the layout variants.
 *
 * @param array $classes Existing classes.
 * @return array
 */
function aurora_body_classes( $classes ) {
	$classes[] = 'aurora-body';

	if ( is_singular() ) {
		$classes[] = 'aurora-singular';
	}
	if ( ! is_active_sidebar( 'sidebar-main' ) || aurora_is_full_width() ) {
		$classes[] = 'aurora-no-sidebar';
	}
	$classes[] = 'aurora-radius-' . get_theme_mod( 'aurora_radius', 'large' );

	if ( get_theme_mod( 'aurora_mobile_bottom_nav', true ) ) {
		$classes[] = 'aurora-has-bottomnav';
	}

	return $classes;
}
add_filter( 'body_class', 'aurora_body_classes' );

/**
 * Should the current view drop the sidebar?
 *
 * Posts read as a single immersive column; ordinary pages should read the same
 * way. A page that carries its own template (directory, trends, member centre,
 * checkout…) keeps whatever layout that template builds for itself.
 *
 * @return bool
 */
function aurora_is_full_width() {
	if ( is_page_template( 'page-templates/full-width.php' ) ) {
		return true;
	}
	if ( function_exists( 'is_product' ) && ( is_product() || is_cart() || is_checkout() ) ) {
		return true;
	}
	// A post with contents or widgets gets the rail, which is a real column: the
	// layout must not collapse to one column just because the sidebar mod is on.
	if ( is_singular( 'post' ) && function_exists( 'aurora_rail_needed' ) && aurora_rail_needed() ) {
		return false;
	}

	if ( ! get_theme_mod( 'aurora_hide_sidebar_single', true ) ) {
		return false;
	}

	return is_singular( 'post' ) || ( is_page() && ! is_page_template() );
}

/**
 * Excerpt tweaks so cards stay a predictable height.
 *
 * @param string $more Default "more" string.
 * @return string
 */
function aurora_excerpt_more( $more ) {
	return '…';
}
add_filter( 'excerpt_more', 'aurora_excerpt_more' );

/**
 * Excerpt length in words.
 *
 * @return int
 */
function aurora_excerpt_length() {
	return 42;
}
add_filter( 'excerpt_length', 'aurora_excerpt_length', 999 );

/**
 * Fallback menu when no primary menu is assigned yet.
 */
function aurora_primary_menu_fallback() {
	echo '<ul id="primary-menu" class="aurora-menu">';
	wp_list_pages( array(
		'title_li' => '',
		'depth'    => 1,
		// This used to be a hard 5, which silently dropped every page past the
		// fifth once the site grew. Eight keeps the bar readable without hiding
		// content, and the filter lets a bigger site tune it.
		'number'   => (int) apply_filters( 'aurora_primary_menu_limit', 8 ),
	) );
	echo '</ul>';
}

/**
 * Keep WordPress.org from offering to "update" this theme.
 *
 * The public directory happens to contain an unrelated theme with the same
 * slug; accepting that update would overwrite every file here.
 *
 * @param object $transient Update transient.
 * @return object
 */
function aurora_block_directory_update( $transient ) {
	if ( is_object( $transient ) && isset( $transient->response['aurora'] ) ) {
		unset( $transient->response['aurora'] );
	}
	if ( is_object( $transient ) && isset( $transient->no_update['aurora'] ) ) {
		unset( $transient->no_update['aurora'] );
	}

	return $transient;
}
add_filter( 'site_transient_update_themes', 'aurora_block_directory_update' );

/**
 * Add a pingback header on singular views.
 */
/**
 * The colour palettes this theme can paint, in switch order.
 *
 * @return array<string,string> Palette slug => human label.
 */
/**
 * Background gradients for the light palette.
 *
 * Stops stay in the near-white range on purpose. A gradient behind text is a
 * contrast decision before it is a decorative one: past roughly this lightness
 * the body copy starts failing against its own background, and no amount of
 * subtlety makes that acceptable.
 *
 * Dark and Spotify keep their flat backgrounds; their whole point is the deep
 * unlit surface, and a wash of colour over it only muddies the cards that sit on
 * top.
 *
 * @return array Label by key.
 */
function aurora_gradients() {
	return apply_filters( 'aurora_gradients', array(
		'none'   => __( '纯色（关闭渐变）', 'aurora' ),
		'aurora' => __( '极光（蓝紫粉）', 'aurora' ),
		'dawn'   => __( '晨曦（橙粉）', 'aurora' ),
		'mint'   => __( '薄荷（绿青）', 'aurora' ),
		'sakura' => __( '樱花（粉紫）', 'aurora' ),
		'ocean'  => __( '海（浅蓝）', 'aurora' ),
		'slate'  => __( '雾（灰蓝，最克制）', 'aurora' ),
	) );
}

/**
 * The CSS image for a gradient key.
 *
 * @param string $key Gradient key.
 * @return string Empty string when there is no gradient.
 */
function aurora_gradient_css( $key = '' ) {
	$key = $key ? $key : get_theme_mod( 'aurora_gradient', 'aurora' );

	$gradients = array(
		'aurora' => 'linear-gradient(160deg, #eef2ff 0%, #f6f0ff 38%, #fdf2f8 72%, #f0f7ff 100%)',
		'dawn'   => 'linear-gradient(165deg, #fff5ec 0%, #ffeef0 45%, #fdf1e7 100%)',
		'mint'   => 'linear-gradient(160deg, #eefaf3 0%, #e9f7f8 52%, #f2fbff 100%)',
		'sakura' => 'linear-gradient(155deg, #fdf0f6 0%, #fbf4fb 46%, #f3f1fd 100%)',
		'ocean'  => 'linear-gradient(160deg, #eaf4ff 0%, #f0f8ff 42%, #e9f7fb 100%)',
		'slate'  => 'linear-gradient(160deg, #f2f4f7 0%, #f6f7f9 50%, #eef1f5 100%)',
	);

	return isset( $gradients[ $key ] ) ? $gradients[ $key ] : '';
}

function aurora_palettes() {
	return array(
		'light'   => __( '浅色', 'aurora' ),
		'dark'    => __( '深色', 'aurora' ),
		'spotify' => __( 'Spotify 风格（暗绿）', 'aurora' ),
	);
}

/**
 * Palettes the header switch offers, filtered to the ones the admin enabled
 * and ordered as the registry declares them.
 *
 * @return string[]
 */
function aurora_enabled_modes() {
	$all  = array_keys( aurora_palettes() );
	$raw  = get_theme_mod( 'aurora_modes_enabled', $all );
	$raw  = is_array( $raw ) ? $raw : (array) $raw;
	$list = array();

	foreach ( $all as $mode ) {
		if ( in_array( $mode, $raw, true ) ) {
			$list[] = $mode;
		}
	}

	// Unchecking everything would leave the switch with nothing to cycle; fall
	// back rather than render a dead button.
	return $list ? $list : array( 'light', 'dark' );
}

/**
 * Add a pingback header on singular views.
 */
function aurora_pingback_header() {
	if ( is_singular() && pings_open() ) {
		printf( '<link rel="pingback" href="%s">' . "\n", esc_url( get_bloginfo( 'pingback_url' ) ) );
	}
}
add_action( 'wp_head', 'aurora_pingback_header' );
