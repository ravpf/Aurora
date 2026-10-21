<?php
/**
 * Aurora theme bootstrap.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

define( 'AURORA_VERSION', '2.5.21' );
define( 'AURORA_DIR', get_template_directory() );
define( 'AURORA_URI', get_template_directory_uri() );

/**
 * Load a module from inc/.
 *
 * @param string $name Module file name without extension.
 */
function aurora_load( $name ) {
	$file = AURORA_DIR . '/inc/' . $name . '.php';
	if ( is_readable( $file ) ) {
		require_once $file;
	}
}

foreach ( array( 'setup', 'enqueue', 'template-tags', 'customizer', 'admin', 'permissions', 'ratelimit', 'captcha', 'post-types', 'vip', 'points', 'levels', 'checkin', 'post-access', 'coupons', 'redeem', 'downloads', 'payment', 'shop', 'shop-admin', 'cart', 'admin-style', 'article-rail', 'refunds', 'notifications', 'mailer', 'wechatpay', 'hot-topics', 'nav-links', 'gallery', 'gallery-admin', 'boards', 'qa', 'user-center', 'ajax', 'seo', 'engagement', 'comments-plus', 'submission', 'editor', 'reward', 'search-plus', 'home-blocks', 'mega-menu', 'newsletter', 'rating', 'series', 'inbox', 'pwa', 'media-formats', 'listing-scope', 'slider', 'widgets', 'ai', 'ai-admin', 'ai-summary', 'ai-qa', 'ai-writer', 'desk-pet', 'onboard' ) as $aurora_module ) {
	aurora_load( $aurora_module );
}
unset( $aurora_module );
