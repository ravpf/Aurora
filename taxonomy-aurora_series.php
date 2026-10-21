<?php
/**
 * Series archive.
 *
 * Deliberately not archive.php: a series reads forward, so the list is ordered
 * oldest first and numbered, while every other archive is newest first.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();

$aurora_term  = get_queried_object();
$aurora_parts = function_exists( 'aurora_series_parts' ) ? aurora_series_parts( $aurora_term ) : array();
?>

<div class="aurora-container">
	<header class="aurora-pagehead aurora-series-head">
		<span class="aurora-series__eyebrow"><?php esc_html_e( '专题', 'aurora' ); ?></span>
		<h1 class="aurora-pagehead__title"><?php echo esc_html( $aurora_term->name ); ?></h1>
		<?php if ( $aurora_term->description ) : ?>
			<p class="aurora-pagehead__desc"><?php echo esc_html( $aurora_term->description ); ?></p>
		<?php endif; ?>
		<div class="aurora-pagehead__meta">
			<?php
			printf(
				/* translators: %d: number of parts. */
				esc_html__( '共 %d 篇 · 按发布顺序阅读', 'aurora' ),
				count( $aurora_parts )
			);
			?>
		</div>
	</header>
</div>

<div class="aurora-container">
	<div class="aurora-layout">
		<main id="aurora-primary" class="aurora-primary">
			<?php if ( $aurora_parts ) : ?>
				<ol class="aurora-series-list">
					<?php foreach ( $aurora_parts as $aurora_i => $aurora_part ) : ?>
						<li class="aurora-series-list__item">
							<span class="aurora-series-list__num"><?php echo esc_html( sprintf( '%02d', $aurora_i + 1 ) ); ?></span>
							<div class="aurora-series-list__body">
								<?php aurora_render_card( $aurora_part->ID, 'row' ); ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php else : ?>
				<p class="aurora-empty"><?php esc_html_e( '这个专题还没有文章。', 'aurora' ); ?></p>
			<?php endif; ?>
		</main>
		<?php get_sidebar(); ?>
	</div>
</div>

<?php
get_footer();
