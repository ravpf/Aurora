<?php
/**
 * Archive template (categories, tags, dates, authors, custom taxonomies).
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="aurora-container">
	<header class="aurora-pagehead">
		<?php
		the_archive_title( '<h1 class="aurora-pagehead__title">', '</h1>' );
		the_archive_description( '<p class="aurora-pagehead__desc">', '</p>' );
		?>
		<?php if ( is_author() ) : ?>
			<div style="display:flex;align-items:center;gap:14px;margin-top:18px">
				<?php echo get_avatar( get_the_author_meta( 'ID' ), 96, '', '', array( 'class' => 'aurora-avatar', 'style' => 'border-radius:50%;width:56px;height:56px' ) ); ?>
				<div>
					<div style="font-weight:600"><?php echo esc_html( get_the_author() ); ?></div>
					<div style="font-size:.86rem;color:var(--aurora-text-tertiary)"><?php echo esc_html( get_the_author_meta( 'description' ) ); ?></div>
				</div>
			</div>
		<?php endif; ?>
		<div class="aurora-pagehead__meta">
			<?php
			printf(
				/* translators: %s: post count */
				esc_html__( '共 %s 篇内容', 'aurora' ),
				esc_html( number_format_i18n( (int) $GLOBALS['wp_query']->found_posts ) )
			);
			?>
		</div>
	</header>
</div>

<div class="aurora-container">
	<div class="aurora-layout">
		<main id="aurora-primary" class="aurora-primary">
			<?php aurora_loop( 'grid' ); ?>
		</main>
		<?php get_sidebar(); ?>
	</div>
</div>

<?php
get_footer();
