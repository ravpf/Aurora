<?php
/**
 * Sidebar.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

if ( aurora_is_full_width() ) {
	return;
}
?>
<aside id="aurora-sidebar" class="aurora-sidebar">
	<?php if ( is_active_sidebar( 'sidebar-main' ) ) : ?>
		<?php dynamic_sidebar( 'sidebar-main' ); ?>
	<?php else : ?>
		<div class="aurora-panel">
			<h3 class="aurora-panel__title"><?php aurora_the_icon( 'user', 17 ); ?><?php esc_html_e( '关于本站', 'aurora' ); ?></h3>
			<p style="font-size:.9rem;color:var(--aurora-text-secondary)">
				<?php echo esc_html( get_bloginfo( 'description' ) ); ?>
			</p>
			<div style="display:flex;gap:8px;margin-top:12px">
				<?php if ( is_user_logged_in() ) : ?>
					<a class="aurora-btn aurora-btn--sm" href="<?php echo esc_url( aurora_user_page_url() ); ?>"><?php esc_html_e( '用户中心', 'aurora' ); ?></a>
				<?php else : ?>
					<a class="aurora-btn aurora-btn--sm aurora-btn--primary" href="#aurora-auth" data-aurora-modal="aurora-auth"><?php esc_html_e( '登录 / 注册', 'aurora' ); ?></a>
				<?php endif; ?>
				<a class="aurora-btn aurora-btn--sm" href="<?php echo esc_url( aurora_vip_page_url() ); ?>"><?php esc_html_e( '开通会员', 'aurora' ); ?></a>
			</div>
		</div>

		<div class="aurora-panel">
			<h3 class="aurora-panel__title"><?php aurora_the_icon( 'fire', 17 ); ?><?php esc_html_e( '热门文章', 'aurora' ); ?></h3>
			<?php
			$aurora_side_hot = new WP_Query( array(
				'post_type'           => 'post',
				'posts_per_page'      => 6,
				'meta_key'            => 'aurora_views',
				'orderby'             => 'meta_value_num',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			) );
			if ( $aurora_side_hot->have_posts() ) :
				echo '<ul class="aurora-ranklist">';
				while ( $aurora_side_hot->have_posts() ) :
					$aurora_side_hot->the_post();
					printf( '<li><a href="%1$s">%2$s</a></li>', esc_url( get_permalink() ), esc_html( get_the_title() ) );
				endwhile;
				echo '</ul>';
				wp_reset_postdata();
			else :
				echo '<p style="font-size:.88rem;color:var(--aurora-text-tertiary)">' . esc_html__( '还没有文章。', 'aurora' ) . '</p>';
			endif;
			?>
		</div>

		<div class="aurora-panel">
			<h3 class="aurora-panel__title"><?php aurora_the_icon( 'star', 17 ); ?><?php esc_html_e( '标签', 'aurora' ); ?></h3>
			<?php
			$aurora_tags = get_tags( array( 'number' => 18, 'orderby' => 'count', 'order' => 'DESC' ) );
			if ( $aurora_tags ) :
				echo '<div style="display:flex;flex-wrap:wrap;gap:7px">';
				foreach ( $aurora_tags as $aurora_tag ) {
					printf(
						'<a class="aurora-tag" href="%1$s">%2$s</a>',
						esc_url( get_tag_link( $aurora_tag ) ),
						esc_html( $aurora_tag->name )
					);
				}
				echo '</div>';
			else :
				echo '<p style="font-size:.88rem;color:var(--aurora-text-tertiary)">' . esc_html__( '暂无标签。', 'aurora' ) . '</p>';
			endif;
			?>
		</div>

		<div class="aurora-panel">
			<h3 class="aurora-panel__title"><?php aurora_the_icon( 'crown', 17 ); ?><?php esc_html_e( '会员', 'aurora' ); ?></h3>
			<p style="font-size:.9rem;color:var(--aurora-text-secondary)"><?php esc_html_e( '开通会员，解锁全部会员专属内容与下载资源。', 'aurora' ); ?></p>
			<a class="aurora-btn aurora-btn--primary aurora-btn--block" style="margin-top:10px" href="<?php echo esc_url( aurora_vip_page_url() ); ?>"><?php esc_html_e( '查看权益', 'aurora' ); ?></a>
		</div>
	<?php endif; ?>
</aside>
