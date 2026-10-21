<?php
/**
 * Site header.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="theme-color" content="#f5f5f7" media="(prefers-color-scheme: light)">
	<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<script>
	/* Resolve the palette before first paint so there is no flash of the wrong
	   scheme. The list comes from the theme's palette registry. */
	/* 先标记「脚本可用」，样式里所有依赖 JS 的效果（reveal）都挂在它下面。 */
	document.documentElement.classList.add('aurora-js');
	(function () {
		try {
			var palettes = <?php echo wp_json_encode( array_keys( aurora_palettes() ) ); ?>;
			/* The admin's default for a first-time visitor; the visitor's own
			   choice, once stored, wins over it. */
			var fallback = <?php echo wp_json_encode( get_theme_mod( 'aurora_default_mode', 'auto' ) ); ?>;
			var colours = { light: '#f5f5f7', dark: '#000000', spotify: '#121212' };
			var stored = localStorage.getItem('aurora-theme');
			// Drop anything stale: a palette the admin has since removed.
			if (stored !== null && stored !== 'auto' && palettes.indexOf(stored) === -1) { stored = null; }
			var choice = stored !== null ? stored : fallback;
			var explicit = choice !== 'auto';
			var mode = explicit ? choice : ((window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light');
			if (explicit) {
				document.documentElement.setAttribute('data-theme', mode);
				/* A deliberate choice outranks the OS preference, so both
				   media-queried theme-color tags take that palette's colour. */
				var metas = document.querySelectorAll('meta[name="theme-color"]');
				for (var i = 0; i < metas.length; i++) {
					metas[i].setAttribute('content', colours[mode] || '#f5f5f7');
				}
			}
		} catch (e) {}
	})();
	</script>
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link screen-reader-text" href="#aurora-main"><?php esc_html_e( '跳到主要内容', 'aurora' ); ?></a>

<div class="aurora-progress" aria-hidden="true"></div>

<header id="aurora-header" class="aurora-header">
	<div class="aurora-container aurora-header__inner">

		<?php aurora_brand(); ?>

		<nav class="aurora-nav" id="aurora-nav" aria-label="<?php esc_attr_e( '主导航', 'aurora' ); ?>">
			<?php
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'container'      => false,
				'menu_id'        => 'primary-menu',
				'menu_class'     => 'aurora-menu',
				'depth'          => 2,
				'fallback_cb'    => 'aurora_primary_menu_fallback',
				'walker'         => ( class_exists( 'Aurora_Mega_Walker' ) && aurora_mega_enabled() ) ? new Aurora_Mega_Walker() : '',
			) );
			?>

			<?php /* On a phone the header has no room for the mode switch, so it lives
			         here, where it gets a label and a full-size target. The script binds
			         by class, so this instance behaves exactly like the header's. */ ?>
			<div class="aurora-nav__tools">
				<span class="aurora-nav__tools-label"><?php esc_html_e( '配色模式', 'aurora' ); ?></span>
				<button type="button" class="aurora-theme-toggle aurora-btn" aria-label="<?php esc_attr_e( '切换配色模式', 'aurora' ); ?>">
					<span class="aurora-icon--sun"><?php aurora_the_icon( 'sun', 18 ); ?></span>
					<span class="aurora-icon--moon" style="display:none"><?php aurora_the_icon( 'moon', 18 ); ?></span>
					<span class="aurora-icon--wave" style="display:none"><?php aurora_the_icon( 'wave', 18 ); ?></span>
					<span><?php esc_html_e( '切换', 'aurora' ); ?></span>
				</button>
			</div>
		</nav>

		<div class="aurora-header__actions">
			<?php if ( get_theme_mod( 'aurora_cart_in_header', true ) ) : ?>
				<?php
				$aurora_cart_count = function_exists( 'aurora_cart_badge_count' ) ? aurora_cart_badge_count() : 0;
				$aurora_cart_url   = function_exists( 'aurora_cart_page_url' ) ? aurora_cart_page_url() : '';
				?>
				<a class="aurora-icon-btn aurora-cart-btn" href="<?php echo esc_url( $aurora_cart_url ); ?>" aria-label="<?php esc_attr_e( '购物车', 'aurora' ); ?>">
					<?php aurora_the_icon( 'cart', 19 ); ?>
					<span class="aurora-cart-btn__badge" data-cart-badge<?php echo $aurora_cart_count ? '' : ' hidden'; ?>><?php echo esc_html( (string) $aurora_cart_count ); ?></span>
				</a>
			<?php endif; ?>

			<button type="button" class="aurora-icon-btn aurora-search-trigger" aria-label="<?php esc_attr_e( '搜索', 'aurora' ); ?>">
				<?php aurora_the_icon( 'search', 19 ); ?>
			</button>

			<button type="button" class="aurora-icon-btn aurora-theme-toggle" aria-label="<?php esc_attr_e( '切换配色模式', 'aurora' ); ?>">
				<span class="aurora-icon--sun"><?php aurora_the_icon( 'sun', 19 ); ?></span>
				<span class="aurora-icon--moon" style="display:none"><?php aurora_the_icon( 'moon', 19 ); ?></span>
				<span class="aurora-icon--wave" style="display:none"><?php aurora_the_icon( 'wave', 19 ); ?></span>
			</button>

			<?php if ( is_user_logged_in() ) : ?>
				<?php $aurora_uid = get_current_user_id(); ?>

				<?php if ( get_theme_mod( 'aurora_notify_enabled', true ) && get_theme_mod( 'aurora_notify_bell', true ) ) : ?>
					<?php $aurora_unread = aurora_notify_unread( $aurora_uid ); ?>
					<div class="aurora-bell" id="aurora-bell">
						<button type="button" class="aurora-icon-btn aurora-bell__btn" aria-expanded="false"
							aria-haspopup="true" aria-controls="aurora-bell-panel"
							aria-label="<?php esc_attr_e( '消息通知', 'aurora' ); ?>">
							<?php aurora_the_icon( 'bell', 19 ); ?>
							<span class="aurora-bell__dot" id="aurora-bell-dot" <?php echo $aurora_unread ? '' : 'hidden'; ?>>
								<?php echo esc_html( $aurora_unread > 99 ? '99+' : (string) $aurora_unread ); ?>
							</span>
						</button>
						<div class="aurora-bell__panel" id="aurora-bell-panel" hidden>
							<div class="aurora-bell__head">
								<strong><?php esc_html_e( '消息通知', 'aurora' ); ?></strong>
								<span class="aurora-bell__unread" id="aurora-bell-unread"></span>
								<button type="button" class="aurora-bell__read" id="aurora-bell-read">
									<?php esc_html_e( '标为已读', 'aurora' ); ?>
								</button>
							</div>
							<div class="aurora-bell__list" id="aurora-bell-list">
								<p class="aurora-bell__empty"><?php esc_html_e( '加载中…', 'aurora' ); ?></p>
							</div>
							<a class="aurora-bell__more" href="<?php echo esc_url( aurora_user_page_url( 'notify' ) ); ?>">
								<?php esc_html_e( '查看全部消息', 'aurora' ); ?>
							</a>
						</div>
					</div>
				<?php endif; ?>

				<a class="aurora-btn aurora-hide-sm" href="<?php echo esc_url( aurora_user_page_url() ); ?>">
					<?php echo aurora_avatar( $aurora_uid, 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php echo esc_html( wp_trim_words( get_the_author_meta( 'display_name', $aurora_uid ), 2, '' ) ); ?></span>
					<?php aurora_vip_badge( $aurora_uid ); ?>
				</a>
			<?php else : ?>
				<a class="aurora-btn" href="#aurora-auth" data-aurora-modal="aurora-auth">
					<?php aurora_the_icon( 'user', 17 ); ?>
					<span class="aurora-hide-sm"><?php esc_html_e( '登录', 'aurora' ); ?></span>
				</a>
			<?php endif; ?>

			<button type="button" class="aurora-icon-btn aurora-mobile-toggle" aria-expanded="false" aria-controls="aurora-nav" aria-label="<?php esc_attr_e( '菜单', 'aurora' ); ?>">
				<?php aurora_the_icon( 'menu', 20 ); ?>
			</button>
		</div>
	</div>
</header>

<?php
// Announcement bar: customizer one-liner, plus the newest sticky announcement.
$aurora_notice_text = get_theme_mod( 'aurora_notice_text', '' );
if ( $aurora_notice_text ) :
	?>
	<div class="aurora-container" style="padding-top:16px">
		<div class="aurora-notice" data-key="aurora-notice-custom">
			<span class="aurora-notice__icon"><?php aurora_the_icon( 'fire', 18 ); ?></span>
			<span><?php echo esc_html( $aurora_notice_text ); ?></span>
			<?php if ( get_theme_mod( 'aurora_notice_link', '' ) ) : ?>
				<a class="aurora-notice__link" href="<?php echo esc_url( get_theme_mod( 'aurora_notice_link' ) ); ?>"><?php esc_html_e( '查看详情', 'aurora' ); ?></a>
			<?php endif; ?>
			<button type="button" class="aurora-icon-btn aurora-notice__dismiss" aria-label="<?php esc_attr_e( '关闭', 'aurora' ); ?>" style="width:26px;height:26px">
				<?php aurora_the_icon( 'close', 14 ); ?>
			</button>
		</div>
	</div>
	<?php
endif;
?>

<div id="aurora-main" class="aurora-main">
