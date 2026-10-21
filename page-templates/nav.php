<?php
/**
 * Template Name: 网址导航
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();

$aurora_cats = aurora_link_categories();
$aurora_cols = get_theme_mod( 'aurora_nav_columns', 'auto' );
$aurora_cols_class = 'auto' === $aurora_cols ? '' : ' aurora-navgrid--' . (int) $aurora_cols;
?>

<div class="aurora-container">
	<header class="aurora-pagehead">
		<h1 class="aurora-pagehead__title"><?php echo esc_html( get_the_title() ); ?></h1>
		<?php if ( get_the_content() ) : ?>
			<div class="aurora-pagehead__desc"><?php the_content(); ?></div>
		<?php else : ?>
			<p class="aurora-pagehead__desc"><?php esc_html_e( '收录常用站点与工具，点击卡片直接跳转。', 'aurora' ); ?></p>
		<?php endif; ?>

		<div class="aurora-navbar">
			<div class="aurora-navbar__search">
				<?php aurora_the_icon( 'search', 18 ); ?>
				<input type="search" id="aurora-nav-search" placeholder="<?php esc_attr_e( '搜索站点名称、域名或简介…', 'aurora' ); ?>" autocomplete="off">
			</div>
			<div class="aurora-navbar__filters">
				<button type="button" class="aurora-chip is-active" data-nav-filter="all"><?php esc_html_e( '全部', 'aurora' ); ?></button>
				<?php foreach ( $aurora_cats as $aurora_cat ) : ?>
					<button type="button" class="aurora-chip" data-nav-filter="<?php echo esc_attr( $aurora_cat->slug ); ?>">
						<?php echo esc_html( $aurora_cat->name ); ?>
						<span class="aurora-chip__count"><?php echo esc_html( $aurora_cat->count ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
		</div>
	</header>
</div>

<div class="aurora-container">
	<?php
	$aurora_grouped = array();
	foreach ( $aurora_cats as $aurora_cat ) {
		$aurora_grouped[ $aurora_cat->term_id ] = array(
			'term'  => $aurora_cat,
			'posts' => array(),
		);
	}

	$aurora_all_links = new WP_Query( array(
		'post_type'      => 'aurora_link',
		'posts_per_page' => 300,
		'post_status'    => 'publish',
		'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
		'no_found_rows'  => true,
	) );

	while ( $aurora_all_links->have_posts() ) {
		$aurora_all_links->the_post();
		$aurora_terms = wp_get_post_terms( get_the_ID(), 'aurora_link_cat', array( 'fields' => 'ids' ) );
		if ( $aurora_terms && ! is_wp_error( $aurora_terms ) ) {
			foreach ( $aurora_terms as $aurora_tid ) {
				if ( isset( $aurora_grouped[ $aurora_tid ] ) ) {
					$aurora_grouped[ $aurora_tid ]['posts'][] = get_the_ID();
				}
			}
		} else {
			$aurora_grouped[0]['term']  = (object) array( 'name' => __( '未分类', 'aurora' ), 'slug' => 'uncategorized' );
			$aurora_grouped[0]['posts'][] = get_the_ID();
		}
	}
	wp_reset_postdata();

	$aurora_rendered = false;
	?>

	<div class="aurora-navgrid<?php echo esc_attr( $aurora_cols_class ); ?>" id="aurora-navgrid">
		<?php foreach ( $aurora_grouped as $aurora_group ) : ?>
			<?php if ( empty( $aurora_group['posts'] ) ) { continue; } ?>
			<?php $aurora_rendered = true; ?>
			<div class="aurora-navgroup" data-group="<?php echo esc_attr( $aurora_group['term']->slug ); ?>">
				<h2 class="aurora-navgroup__title">
					<?php echo esc_html( $aurora_group['term']->name ); ?>
					<span><?php echo esc_html( count( $aurora_group['posts'] ) ); ?></span>
				</h2>
				<div class="aurora-navgroup__grid">
					<?php foreach ( $aurora_group['posts'] as $aurora_link_id ) : ?>
						<?php aurora_render_link_card( $aurora_link_id ); ?>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( ! $aurora_rendered ) : ?>
		<div class="aurora-empty">
			<h3><?php esc_html_e( '还没有收录网址', 'aurora' ); ?></h3>
			<p><?php esc_html_e( '在后台「网址导航 → 添加网址」中录入第一条记录。', 'aurora' ); ?></p>
			<a class="aurora-btn aurora-btn--primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=aurora_link' ) ); ?>"><?php esc_html_e( '添加网址', 'aurora' ); ?></a>
		</div>
	<?php endif; ?>

	<p class="aurora-navempty" id="aurora-navempty" hidden><?php esc_html_e( '没有匹配的站点，换个关键词试试。', 'aurora' ); ?></p>
</div>

<?php
get_footer();
