<?php
/**
 * Admin skin.
 *
 * Loads one stylesheet that reuses the front end's design language across the
 * dashboard: system fonts, one soft shadow, generous radii, the site's accent
 * colour, and the light gradient the front page uses instead of flat white.
 *
 * @package Aurora
 */

/**
 * Enqueue the admin stylesheet.
 *
 * @param string $hook Current admin page hook.
 */
function aurora_admin_style( $hook ) {
	$css = AURORA_DIR . '/assets/css/admin.css';

	wp_enqueue_style(
		'aurora-admin',
		AURORA_URI . '/assets/css/admin.css',
		array(),
		file_exists( $css ) ? (string) filemtime( $css ) : AURORA_VERSION
	);

	// The accent belongs to the site, not to the skin: an owner who picked green on
	// the front end should not get a blue dashboard.
	$accent = get_theme_mod( 'aurora_accent', '#0071e3' );

	if ( ! preg_match( '/^#[0-9a-f]{3,8}$/i', (string) $accent ) ) {
		$accent = '#0071e3';
	}

	$vars = sprintf(
		':root{--au-accent:%1$s;--au-accent-soft:%2$s;}',
		$accent,
		aurora_admin_style_alpha( $accent, 0.12 )
	);

	wp_add_inline_style( 'aurora-admin', $vars );

	unset( $hook );
}
add_action( 'admin_enqueue_scripts', 'aurora_admin_style' );

/**
 * An rgba() colour from a hex accent.
 *
 * @param string $hex   Hex colour.
 * @param float  $alpha Alpha.
 * @return string
 */
function aurora_admin_style_alpha( $hex, $alpha ) {
	$hex = ltrim( $hex, '#' );

	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}

	if ( 6 !== strlen( $hex ) ) {
		return 'rgba(0, 113, 227, ' . $alpha . ')';
	}

	$r = hexdec( substr( $hex, 0, 2 ) );
	$g = hexdec( substr( $hex, 2, 2 ) );
	$b = hexdec( substr( $hex, 4, 2 ) );

	return sprintf( 'rgba(%d, %d, %d, %s)', $r, $g, $b, $alpha );
}

/**
 * The login screen wears the same clothes.
 */
function aurora_login_style() {
	$css = AURORA_DIR . '/assets/css/admin.css';

	wp_enqueue_style(
		'aurora-admin-login',
		AURORA_URI . '/assets/css/admin.css',
		array(),
		file_exists( $css ) ? (string) filemtime( $css ) : AURORA_VERSION
	);
}
add_action( 'login_enqueue_scripts', 'aurora_login_style' );
