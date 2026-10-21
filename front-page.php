<?php
/**
 * Front page: hero, announcements, featured grid, latest posts.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();

/*
 * 全站权重最高的页面却没有 h1：页头把站名放在 <a> 里，下面的区块都是 h2 起头。
 * 这里补一个只给读屏和爬虫看的 h1（站名 + 副标题），版面上不新增任何东西。
 */
?>
<h1 class="aurora-sr"><?php
	echo esc_html( get_bloginfo( 'name' ) );
	$aurora_tagline = get_bloginfo( 'description' );
	if ( $aurora_tagline ) {
		echo ' · ' . esc_html( $aurora_tagline );
	}
?></h1>
<?php

$aurora_sticky   = get_option( 'sticky_posts' );
$aurora_notices  = aurora_get_notices( 1 );
?>

<?php
/*
 * The front page used to open with a page-head block (site name, tagline and a
 * search box). The header already carries the site name and a search button, so
 * it read as a second, near-duplicate header stacked above the announcement and
 * the slider. Removed on request; the same .aurora-pagehead is still used by the
 * blog index, boards and series, so its styles stay.
 */
?>

<?php if ( is_active_sidebar( 'front-top' ) ) : ?>
	<div class="aurora-container"><?php dynamic_sidebar( 'front-top' ); ?></div>
<?php endif; ?>

<?php
if ( $aurora_notices->have_posts() ) :
	$aurora_notices->the_post();
	?>
	<div class="aurora-container">
		<div class="aurora-notice">
			<span class="aurora-notice__icon"><?php aurora_the_icon( 'fire', 18 ); ?></span>
			<span><strong><?php esc_html_e( '公告', 'aurora' ); ?></strong> · <?php echo esc_html( get_the_title() ); ?></span>
			<a class="aurora-notice__link" href="<?php echo esc_url( get_permalink() ); ?>"><?php esc_html_e( '查看详情', 'aurora' ); ?></a>
		</div>
	</div>
	<?php
	wp_reset_postdata();
endif;
?>

<?php if ( ! empty( $aurora_sticky ) ) : ?>
	<div class="aurora-container">
		<section class="aurora-section" style="margin-top:26px">
			<div class="aurora-section__head">
				<h2 class="aurora-section__title"><?php esc_html_e( '精选推荐', 'aurora' ); ?></h2>
			</div>
			<?php
			$aurora_featured = new WP_Query(
				apply_filters(
					'aurora_listing_query_args',
					array(
						'post__in'            => $aurora_sticky,
						'posts_per_page'      => 3,
						'ignore_sticky_posts' => 1,
						'post_status'         => 'publish',
						'no_found_rows'       => true,
					)
				)
			);
			if ( $aurora_featured->have_posts() ) :
				echo '<div class="aurora-grid">';
				while ( $aurora_featured->have_posts() ) :
					$aurora_featured->the_post();
					aurora_render_card( get_the_ID(), 'grid' );
				endwhile;
				echo '</div>';
				wp_reset_postdata();
			endif;
			?>
		</section>
	</div>
<?php endif; ?>

<?php do_action( 'aurora_before_home_main' ); ?>

<div class="aurora-container">
	<div class="aurora-layout">
		<main id="aurora-primary" class="aurora-primary">
			<?php
			// The home page is a list the owner controls (Theme Settings → 首页区块).
			// Default order reproduces the original layout exactly.
			foreach ( aurora_home_blocks() as $aurora_block ) {
				aurora_render_home_block( $aurora_block );
			}
			?>
		</main>

		<?php get_sidebar(); ?>
	</div>
</div>

<?php
get_footer();
