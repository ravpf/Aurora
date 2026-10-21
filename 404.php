<?php
/**
 * 404 template.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="aurora-container">
	<div class="aurora-empty" style="padding:110px 20px">
		<div style="font-size:clamp(3.4rem,2rem+7vw,6rem);font-weight:700;letter-spacing:-0.05em;background:linear-gradient(135deg,var(--aurora-accent),#6f4ff2);-webkit-background-clip:text;background-clip:text;color:transparent;line-height:1">404</div>
		<h3 style="margin-top:18px"><?php esc_html_e( '这个页面不见了', 'aurora' ); ?></h3>
		<p><?php esc_html_e( '链接可能已经失效，或者内容被移动了。', 'aurora' ); ?></p>

		<form class="aurora-search-form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" style="max-width:420px;margin:26px auto 0">
			<?php aurora_the_icon( 'search', 19 ); ?>
			<input type="search" name="s" placeholder="<?php esc_attr_e( '搜点什么…', 'aurora' ); ?>">
		</form>

		<div style="margin-top:24px;display:flex;gap:10px;justify-content:center">
			<a class="aurora-btn aurora-btn--primary" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( '返回首页', 'aurora' ); ?></a>
			<a class="aurora-btn aurora-btn--ghost" href="<?php echo esc_url( get_permalink( get_option( 'page_for_posts' ) ) ? get_permalink( get_option( 'page_for_posts' ) ) : home_url( '/' ) ); ?>"><?php esc_html_e( '浏览文章', 'aurora' ); ?></a>
		</div>
	</div>
</div>

<?php
get_footer();
