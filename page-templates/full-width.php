<?php
/**
 * Template Name: 全宽页面
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="aurora-container aurora-container--wide">
	<?php while ( have_posts() ) : the_post(); ?>
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'aurora-article' ); ?> style="margin-top:34px">
			<header class="aurora-article__header">
				<h1 class="aurora-article__title"><?php the_title(); ?></h1>
			</header>
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
	<?php endwhile; ?>
</div>

<?php
get_footer();
