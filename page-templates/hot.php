<?php
/**
 * Template Name: 热点榜单
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="aurora-container">
	<header class="aurora-pagehead">
		<h1 class="aurora-pagehead__title"><?php echo esc_html( get_the_title() ); ?></h1>
		<p class="aurora-pagehead__desc">
			<?php esc_html_e( '抖音、微博、百度、B站 实时热搜同步，服务端定时抓取。', 'aurora' ); ?>
		</p>
	</header>
</div>

<div class="aurora-container">
	<div class="aurora-layout">
		<main class="aurora-primary">
			<?php
			while ( have_posts() ) :
				the_post();
				if ( trim( (string) get_the_content() ) ) {
					echo '<div class="aurora-content" style="margin-bottom:28px">';
					the_content();
					echo '</div>';
				}
			endwhile;
			?>

			<?php
			get_template_part( 'template-parts/hot-topics', null, array(
				'layout' => 'page',
				'limit'  => 12,
			) );
			?>
		</main>

		<aside class="aurora-sidebar">
			<div class="aurora-panel">
				<h3 class="aurora-panel__title"><?php aurora_the_icon( 'clock', 17 ); ?><?php esc_html_e( '刷新说明', 'aurora' ); ?></h3>
				<p style="font-size:.88rem;color:var(--aurora-text-secondary)">
					<?php
					printf(
						/* translators: %d: minutes */
						esc_html__( '榜单每 %d 分钟自动同步一次，不消耗访客带宽。', 'aurora' ),
						(int) get_theme_mod( 'aurora_hot_ttl', 30 )
					);
					?>
				</p>
			</div>

			<div class="aurora-panel">
				<h3 class="aurora-panel__title"><?php aurora_the_icon( 'fire', 17 ); ?><?php esc_html_e( '站内热文', 'aurora' ); ?></h3>
				<?php
				$aurora_page_hot = new WP_Query( array(
					'post_type'           => 'post',
					'posts_per_page'      => 6,
					'meta_key'            => 'aurora_views',
					'orderby'             => 'meta_value_num',
					'order'               => 'DESC',
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
				) );
				if ( $aurora_page_hot->have_posts() ) :
					echo '<ul class="aurora-ranklist">';
					while ( $aurora_page_hot->have_posts() ) :
						$aurora_page_hot->the_post();
						printf( '<li><a href="%1$s">%2$s</a></li>', esc_url( get_permalink() ), esc_html( get_the_title() ) );
					endwhile;
					echo '</ul>';
					wp_reset_postdata();
				else :
					echo '<p style="font-size:.88rem;color:var(--aurora-text-tertiary)">' . esc_html__( '暂无数据', 'aurora' ) . '</p>';
				endif;
				?>
			</div>
		</aside>
	</div>
</div>

<?php
get_footer();
