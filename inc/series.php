<?php
/**
 * Series / topic collections.
 *
 * A series is a taxonomy, not a bespoke table: it gets an archive, a slug, a
 * term editor and REST support for free, and authors assign it from the normal
 * post sidebar.
 *
 * Order inside a series is publication order, oldest first — that is how a
 * tutorial series is meant to be read, and it needs no extra bookkeeping field
 * that authors would forget to fill in.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the taxonomy.
 */
function aurora_series_register() {
	register_taxonomy(
		'aurora_series',
		array( 'post' ),
		array(
			'labels'            => array(
				'name'          => __( '专题', 'aurora' ),
				'singular_name' => __( '专题', 'aurora' ),
				'add_new_item'  => __( '新建专题', 'aurora' ),
				'edit_item'     => __( '编辑专题', 'aurora' ),
				'search_items'  => __( '搜索专题', 'aurora' ),
			),
			'public'            => true,
			'hierarchical'      => false,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => 'series', 'with_front' => false ),
		)
	);
}
add_action( 'init', 'aurora_series_register', 9 );

/**
 * Canonical series links.
 *
 * This site uses /%post_id%.html, so WordPress treats the slash-less URL as
 * canonical and 301s `/series/foo/` to `/series/foo`. get_term_link() still
 * emits the trailing slash, which meant a redirect hop on every click from the
 * article box or the archive — so strip it at the source.
 *
 * @param string $url      Term link.
 * @param WP_Term $term    Term.
 * @param string $taxonomy Taxonomy.
 * @return string
 */
add_filter(
	'term_link',
	function ( $url, $term, $taxonomy ) {
		return 'aurora_series' === $taxonomy ? rtrim( $url, '/' ) : $url;
	},
	10,
	3
);

/**
 * Series a post belongs to (the first one, if several).
 *
 * @param int $post_id Post.
 * @return WP_Term|null
 */
function aurora_series_of( $post_id ) {
	$terms = get_the_terms( $post_id, 'aurora_series' );

	return ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;
}

/**
 * Every published part of a series, oldest first.
 *
 * @param WP_Term $term Series.
 * @return WP_Post[]
 */
function aurora_series_parts( $term ) {
	if ( ! $term instanceof WP_Term ) {
		return array();
	}

	return get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'no_found_rows'  => true,
			'tax_query'      => array(
				array(
					'taxonomy' => 'aurora_series',
					'field'    => 'term_id',
					'terms'    => $term->term_id,
				),
			),
			'orderby'        => array( 'date' => 'ASC', 'ID' => 'ASC' ),
		)
	);
}

/**
 * Position of a post inside its series.
 *
 * @param int $post_id Post.
 * @return array{term:WP_Term,index:int,total:int,ids:int[]}|null
 */
function aurora_series_position( $post_id ) {
	$term = aurora_series_of( $post_id );

	if ( ! $term ) {
		return null;
	}

	$parts = aurora_series_parts( $term );
	$ids   = wp_list_pluck( $parts, 'ID' );
	$index = array_search( (int) $post_id, array_map( 'intval', $ids ), true );

	if ( false === $index ) {
		return null;
	}

	return array(
		'term'  => $term,
		'index' => (int) $index,
		'total' => count( $ids ),
		'ids'   => array_map( 'intval', $ids ),
	);
}

/**
 * The box shown under an article that belongs to a series.
 */
function aurora_series_nav() {
	if ( ! is_singular( 'post' ) ) {
		return;
	}

	$pos = aurora_series_position( get_the_ID() );

	if ( ! $pos || $pos['total'] < 2 ) {
		return;
	}

	$prev = $pos['index'] > 0 ? get_post( $pos['ids'][ $pos['index'] - 1 ] ) : null;
	$next = isset( $pos['ids'][ $pos['index'] + 1 ] ) ? get_post( $pos['ids'][ $pos['index'] + 1 ] ) : null;
	$pct  = (int) round( ( $pos['index'] + 1 ) / $pos['total'] * 100 );
	?>
	<section class="aurora-series">
		<div class="aurora-series__head">
			<span class="aurora-series__eyebrow"><?php esc_html_e( '专题', 'aurora' ); ?></span>
			<a class="aurora-series__name" href="<?php echo esc_url( get_term_link( $pos['term'] ) ); ?>">
				<?php echo esc_html( $pos['term']->name ); ?>
			</a>
			<span class="aurora-series__progress-text">
				<?php
				printf(
					/* translators: 1: current part, 2: total parts. */
					esc_html__( '第 %1$d / 共 %2$d 篇', 'aurora' ),
					(int) ( $pos['index'] + 1 ),
					(int) $pos['total']
				);
				?>
			</span>
		</div>

		<div class="aurora-series__bar" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $pct ); ?>"
			aria-valuemin="0" aria-valuemax="100"
			aria-label="<?php esc_attr_e( '专题阅读进度', 'aurora' ); ?>">
			<span class="aurora-series__bar-fill" style="width:<?php echo esc_attr( (string) $pct ); ?>%"></span>
		</div>

		<div class="aurora-series__step">
			<?php if ( $prev ) : ?>
				<a class="aurora-series__link aurora-series__link--prev" href="<?php echo esc_url( get_permalink( $prev ) ); ?>">
					<span class="aurora-series__dir"><?php esc_html_e( '上一篇', 'aurora' ); ?></span>
					<span class="aurora-series__title"><?php echo esc_html( get_the_title( $prev ) ); ?></span>
				</a>
			<?php else : ?>
				<span class="aurora-series__link is-empty"><?php esc_html_e( '这是第一篇', 'aurora' ); ?></span>
			<?php endif; ?>

			<?php if ( $next ) : ?>
				<a class="aurora-series__link aurora-series__link--next" href="<?php echo esc_url( get_permalink( $next ) ); ?>">
					<span class="aurora-series__dir"><?php esc_html_e( '下一篇', 'aurora' ); ?></span>
					<span class="aurora-series__title"><?php echo esc_html( get_the_title( $next ) ); ?></span>
				</a>
			<?php else : ?>
				<span class="aurora-series__link is-empty"><?php esc_html_e( '这是最后一篇', 'aurora' ); ?></span>
			<?php endif; ?>
		</div>

		<details class="aurora-series__all">
			<summary><?php esc_html_e( '查看全目录', 'aurora' ); ?></summary>
			<ol class="aurora-series__list">
				<?php
				foreach ( $pos['ids'] as $i => $id ) {
					printf(
						'<li%s><a href="%s">%s</a></li>',
						0 === $i - $pos['index'] ? ' class="is-current" aria-current="page"' : '',
						esc_url( get_permalink( $id ) ),
						esc_html( get_the_title( $id ) )
					);
				}
				?>
			</ol>
		</details>
	</section>
	<?php
}

/**
 * Series archive header, used by taxonomy-aurora_series.php.
 */
function aurora_series_archive_head() {
	if ( ! is_tax( 'aurora_series' ) ) {
		return;
	}

	$term  = get_queried_object();
	$parts = aurora_series_parts( $term );
	?>
	<header class="aurora-archive-head aurora-series-head">
		<span class="aurora-series__eyebrow"><?php esc_html_e( '专题', 'aurora' ); ?></span>
		<h1 class="aurora-archive-head__title"><?php echo esc_html( $term->name ); ?></h1>
		<?php if ( $term->description ) : ?>
			<p class="aurora-archive-head__desc"><?php echo esc_html( $term->description ); ?></p>
		<?php endif; ?>
		<p class="aurora-archive-head__meta">
			<?php
			printf(
				/* translators: %d: number of parts. */
				esc_html__( '共 %d 篇，按发布顺序阅读', 'aurora' ),
				count( $parts )
			);
			?>
		</p>
	</header>
	<?php
}
// Rendered by taxonomy-aurora_series.php, which also owns the reading order.
