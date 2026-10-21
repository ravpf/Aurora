<?php
/**
 * Default listing template.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="aurora-container">
	<?php
	if ( is_home() && ! is_front_page() ) :
		$aurora_posts_page = get_option( 'page_for_posts' );
		?>
		<header class="aurora-pagehead">
			<h1 class="aurora-pagehead__title">
				<?php echo esc_html( $aurora_posts_page ? get_the_title( $aurora_posts_page ) : __( '文章', 'aurora' ) ); ?>
			</h1>
		</header>
	<?php endif; ?>
</div>

<div class="aurora-container">
	<div class="aurora-layout">
		<main id="aurora-primary" class="aurora-primary">
			<?php get_template_part( 'template-parts/loop-notices' ); ?>
			<?php aurora_loop( get_theme_mod( 'aurora_home_layout', 'grid' ) ); ?>
		</main>

		<?php get_sidebar(); ?>
	</div>
</div>

<?php
get_footer();
