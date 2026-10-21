<?php
/**
 * Site footer.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;
?>
</div><!-- #aurora-main -->

<footer class="aurora-footer">
	<div class="aurora-container">
		<div class="aurora-footer__cols">
			<div>
				<?php aurora_brand(); ?>
				<p style="margin-top:14px;max-width:340px;color:var(--aurora-text-secondary)">
					<?php echo esc_html( get_bloginfo( 'description' ) ); ?>
				</p>
				<?php if ( is_active_sidebar( 'footer-1' ) ) : ?>
					<?php dynamic_sidebar( 'footer-1' ); ?>
				<?php endif; ?>
			</div>

			<div>
				<h4><?php esc_html_e( '导航', 'aurora' ); ?></h4>
				<?php
				wp_nav_menu( array(
					'theme_location' => 'footer',
					'container'      => false,
					'menu_class'     => '',
					'depth'          => 1,
					'fallback_cb'    => false,
				) );
				?>
				<?php if ( ! has_nav_menu( 'footer' ) ) : ?>
					<ul>
						<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( '首页', 'aurora' ); ?></a></li>
						<li><a href="<?php echo esc_url( aurora_vip_page_url() ); ?>"><?php esc_html_e( '会员中心', 'aurora' ); ?></a></li>
						<li><a href="<?php echo esc_url( aurora_user_page_url() ); ?>"><?php esc_html_e( '用户中心', 'aurora' ); ?></a></li>
					</ul>
				<?php endif; ?>
			</div>

			<div>
				<h4><?php esc_html_e( '公告', 'aurora' ); ?></h4>
				<?php
				$aurora_footer_notices = aurora_get_notices( 3 );
				if ( $aurora_footer_notices->have_posts() ) :
					echo '<ul>';
					while ( $aurora_footer_notices->have_posts() ) :
						$aurora_footer_notices->the_post();
						printf(
							'<li><a href="%1$s">%2$s</a></li>',
							esc_url( get_permalink() ),
							esc_html( wp_trim_words( get_the_title(), 12, '…' ) )
						);
					endwhile;
					echo '</ul>';
					wp_reset_postdata();
				else :
					?>
					<ul><li style="color:var(--aurora-text-tertiary)"><?php esc_html_e( '暂无公告', 'aurora' ); ?></li></ul>
					<?php
				endif;
				?>
			</div>

			<div>
				<h4><?php esc_html_e( '关于', 'aurora' ); ?></h4>
				<ul>
					<li><a href="<?php echo esc_url( home_url( '/?s=' ) ); ?>"><?php esc_html_e( '站内搜索', 'aurora' ); ?></a></li>
					<?php if ( function_exists( 'wp_sitemaps_get_server' ) ) : ?>
						<li><a href="<?php echo esc_url( home_url( '/wp-sitemap.xml' ) ); ?>"><?php esc_html_e( '站点地图', 'aurora' ); ?></a></li>
					<?php endif; ?>
					<?php wp_meta(); ?>
				</ul>
			</div>

			<?php aurora_desk_pet(); ?>
		</div>

		<div class="aurora-footer__bottom">
			<div>
				<?php
				$aurora_footer_text = get_theme_mod( 'aurora_footer_text', '' );
				if ( $aurora_footer_text ) {
					echo wp_kses_post( $aurora_footer_text );
				} else {
					printf(
						'© %1$s %2$s',
						esc_html( gmdate( 'Y' ) ),
						esc_html( get_bloginfo( 'name' ) )
					);
				}
				?>
			</div>
			<div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
				<?php if ( get_theme_mod( 'aurora_icp', '' ) ) : ?>
					<a href="https://beian.miit.gov.cn/" rel="noopener" target="_blank"><?php echo esc_html( get_theme_mod( 'aurora_icp' ) ); ?></a>
				<?php endif; ?>
				<span><?php esc_html_e( '由 Aurora 主题驱动', 'aurora' ); ?></span>
			</div>
		</div>
	</div>
</footer>

<!-- Search overlay -->
<div class="aurora-searchbox" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( '站内搜索', 'aurora' ); ?>">
	<div class="aurora-searchbox__panel">
		<form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<input type="search" name="s" placeholder="<?php esc_attr_e( '搜索文章、页面、公告…', 'aurora' ); ?>" autocomplete="off" spellcheck="false">
		</form>
		<div class="aurora-searchbox__words" data-words hidden></div>
		<div class="aurora-searchbox__results" role="listbox"></div>
		<div class="aurora-searchbox__hint">
			<span><kbd>Esc</kbd> <?php esc_html_e( '关闭', 'aurora' ); ?></span>
			<span><kbd>/</kbd> <?php esc_html_e( '快速搜索', 'aurora' ); ?></span>
			<span><kbd>↵</kbd> <?php esc_html_e( '查看全部结果', 'aurora' ); ?></span>
		</div>
	</div>
</div>

<!-- Auth modal -->
<?php if ( ! is_user_logged_in() ) : ?>
<div id="aurora-auth" class="aurora-modal" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( '账户', 'aurora' ); ?>">
	<div class="aurora-modal__box">
		<div class="aurora-modal__head">
			<h2 class="aurora-modal__title"><?php esc_html_e( '欢迎回来', 'aurora' ); ?></h2>
			<button type="button" class="aurora-icon-btn" data-aurora-close aria-label="<?php esc_attr_e( '关闭', 'aurora' ); ?>">
				<?php aurora_the_icon( 'close', 18 ); ?>
			</button>
		</div>

		<?php if ( get_theme_mod( 'aurora_register_open', true ) && get_option( 'users_can_register' ) ) : ?>
			<div class="aurora-tabs" data-scope="auth">
				<button type="button" class="aurora-tab is-active" data-tab="login" aria-selected="true"><?php esc_html_e( '登录', 'aurora' ); ?></button>
				<button type="button" class="aurora-tab" data-tab="register" aria-selected="false"><?php esc_html_e( '注册', 'aurora' ); ?></button>
			</div>
		<?php endif; ?>

		<div class="aurora-tabpane is-active" data-pane="login" data-scope="auth">
			<?php aurora_login_form(); ?>
		</div>

		<?php if ( get_theme_mod( 'aurora_register_open', true ) && get_option( 'users_can_register' ) ) : ?>
			<div class="aurora-tabpane" data-pane="register" data-scope="auth">
				<?php aurora_register_form(); ?>
			</div>
		<?php endif; ?>
	</div>
</div>
<?php endif; ?>

<div class="aurora-lightbox" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( '图片预览', 'aurora' ); ?>">
	<button type="button" class="aurora-lightbox__nav aurora-lightbox__nav--prev" data-dir="prev" aria-label="<?php esc_attr_e( '上一张', 'aurora' ); ?>"><?php aurora_the_icon( 'chevron', 22 ); ?></button>

	<figure class="aurora-lightbox__figure">
		<?php // 打开前先挂一张 1×1 透明图：没有 src 的 img 会被当成加载失败的图片。 ?>
		<img alt="" width="1" height="1" decoding="async"
			src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7">
		<figcaption class="aurora-lightbox__caption" hidden></figcaption>
	</figure>

	<button type="button" class="aurora-lightbox__nav aurora-lightbox__nav--next" data-dir="next" aria-label="<?php esc_attr_e( '下一张', 'aurora' ); ?>"><?php aurora_the_icon( 'chevron', 22 ); ?></button>
</div>

<?php if ( get_theme_mod( 'aurora_mobile_bottom_nav', true ) ) : ?>
	<?php
	// Current section, so the right tab lights up.
	$aurora_home_url = home_url( '/' );
	$aurora_here     = trailingslashit( home_url( add_query_arg( array() ) ) );
	$aurora_tabs_bar = array(
		array(
			'label'  => __( '首页', 'aurora' ),
			'icon'   => 'grid',
			'url'    => $aurora_home_url,
			'active' => is_front_page() || is_home(),
		),
		array(
			'label'  => __( '热点', 'aurora' ),
			'icon'   => 'fire',
			'url'    => aurora_hot_page_url(),
			'active' => (int) get_queried_object_id() === (int) get_option( 'aurora_hot_page_id' ),
		),
		array(
			'label'  => __( '导航', 'aurora' ),
			'icon'   => 'link',
			'url'    => aurora_nav_page_url(),
			'active' => (int) get_queried_object_id() === (int) get_option( 'aurora_nav_page_id' ) || is_post_type_archive( 'aurora_link' ) || is_tax( 'aurora_link_cat' ),
		),
		array(
			'label'  => __( '会员', 'aurora' ),
			'icon'   => 'crown',
			'url'    => aurora_vip_page_url(),
			'active' => (int) get_queried_object_id() === (int) get_option( 'aurora_vip_page_id' ),
		),
		array(
			'label'  => is_user_logged_in() ? __( '我的', 'aurora' ) : __( '登录', 'aurora' ),
			'icon'   => 'user',
			'url'    => is_user_logged_in() ? aurora_user_page_url() : '#aurora-auth',
			'active' => (int) get_queried_object_id() === (int) get_option( 'aurora_user_page_id' ),
			'modal'  => ! is_user_logged_in() ? 'aurora-auth' : '',
		),
	);
	?>
	<nav class="aurora-bottomnav" aria-label="<?php esc_attr_e( '快捷导航', 'aurora' ); ?>">
		<div class="aurora-bottomnav__inner">
			<?php foreach ( $aurora_tabs_bar as $aurora_bar ) : ?>
				<a class="aurora-bottomnav__item <?php echo $aurora_bar['active'] ? 'is-active' : ''; ?>"
					href="<?php echo esc_url( $aurora_bar['url'] ); ?>"
					<?php if ( ! empty( $aurora_bar['modal'] ) ) : ?>
						data-aurora-modal="<?php echo esc_attr( $aurora_bar['modal'] ); ?>"
					<?php endif; ?>>
					<?php aurora_the_icon( $aurora_bar['icon'], 20 ); ?>
					<span><?php echo esc_html( $aurora_bar['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</nav>
<?php endif; ?>

<button type="button" class="aurora-scrolltop" aria-label="<?php esc_attr_e( '回到顶部', 'aurora' ); ?>">
	<?php aurora_the_icon( 'up', 18 ); ?>
</button>

<?php wp_footer(); ?>
</body>
</html>
