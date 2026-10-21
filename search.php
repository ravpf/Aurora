<?php
/**
 * Search results.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="aurora-container">
	<header class="aurora-pagehead">
		<h1 class="aurora-pagehead__title">
			<?php
			printf(
				/* translators: %s: search term */
				esc_html__( '“%s” 的搜索结果', 'aurora' ),
				esc_html( get_search_query() )
			);
			?>
		</h1>
		<p class="aurora-pagehead__desc"><?php esc_html_e( '没有找到？换个关键词再试试。', 'aurora' ); ?></p>

		<form class="aurora-search-form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" style="max-width:460px;margin-top:20px">
			<?php aurora_the_icon( 'search', 19 ); ?>
			<input type="search" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="<?php esc_attr_e( '搜索…', 'aurora' ); ?>">
		</form>

		<div class="aurora-pagehead__meta">
			<?php
			printf(
				/* translators: %s: result count */
				esc_html__( '找到 %s 条结果', 'aurora' ),
				esc_html( number_format_i18n( (int) $GLOBALS['wp_query']->found_posts ) )
			);
			?>
		</div>
	</header>
</div>

<div class="aurora-container">
	<div class="aurora-layout">
		<main id="aurora-primary" class="aurora-primary">
			<?php aurora_loop( 'list' ); ?>
		</main>
		<?php get_sidebar(); ?>
	</div>
</div>

<?php
get_footer();
