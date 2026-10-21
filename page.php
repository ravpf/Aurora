<?php
/**
 * Page template.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();

// Sits above the container so the trail spans the shell, not the article card.
aurora_breadcrumbs();
?>

<div class="aurora-container">
	<?php while ( have_posts() ) : the_post(); ?>
		<div class="aurora-layout">
			<main id="aurora-primary" class="aurora-primary">
				<article id="post-<?php the_ID(); ?>" <?php post_class( 'aurora-article' ); ?>>
					<header class="aurora-article__header">
						<h1 class="aurora-article__title"><?php the_title(); ?></h1>

						<?php if ( has_excerpt() ) : ?>
							<p class="aurora-article__lead"><?php echo esc_html( get_the_excerpt() ); ?></p>
						<?php endif; ?>

						<div class="aurora-article__meta">
							<span><?php aurora_the_icon( 'clock', 15 ); ?><?php esc_html_e( '更新于 ', 'aurora' ); ?><?php echo esc_html( get_the_modified_date() ); ?></span>
							<span><?php aurora_the_icon( 'fire', 15 ); ?><?php echo esc_html( aurora_reading_time() ); ?><?php esc_html_e( ' 分钟', 'aurora' ); ?></span>
							<?php if ( comments_open() ) : ?>
								<span><?php aurora_the_icon( 'comment', 15 ); ?><?php echo esc_html( number_format_i18n( get_comments_number() ) ); ?></span>
							<?php endif; ?>
						</div>
					</header>

					<?php if ( has_post_thumbnail() ) : ?>
						<figure class="aurora-hero">
							<?php the_post_thumbnail( 'aurora-card-wide', array( 'loading' => 'eager' ) ); ?>
						</figure>
					<?php endif; ?>

					<div class="aurora-content">
						<?php
						the_content();
						wp_link_pages( array(
							'before' => '<nav class="aurora-pagination">',
							'after'  => '</nav>',
						) );
						?>
					</div>
				</article>

				<?php
				if ( comments_open() || get_comments_number() ) {
					comments_template();
				}
				?>
			</main>

			<?php get_sidebar(); ?>
		</div>
	<?php endwhile; ?>
</div>

<?php
get_footer();
