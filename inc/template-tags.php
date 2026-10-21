<?php
/**
 * Template helpers.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Card corner radius token based on the customizer choice.
 *
 * @return string
 */
function aurora_radius_card() {
	$map = array(
		'small' => '12px',
		'large' => '22px',
	);
	$choice = get_theme_mod( 'aurora_radius', 'large' );

	return isset( $map[ $choice ] ) ? $map[ $choice ] : '20px';
}

/**
 * Reading time estimate.
 *
 * @param int|null $post_id Post ID.
 * @return int Minutes, minimum 1.
 */
function aurora_reading_time( $post_id = null ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	$content = get_post_field( 'post_content', $post_id );
	$chars   = mb_strlen( wp_strip_all_tags( (string) $content ) );
	$minutes = (int) ceil( $chars / 400 );

	return max( 1, $minutes );
}

/**
 * Human readable view count.
 *
 * @param int|null $post_id Post ID.
 * @return int
 */
function aurora_get_views( $post_id = null ) {
	$post_id = $post_id ? $post_id : get_the_ID();

	return (int) get_post_meta( $post_id, 'aurora_views', true );
}

/**
 * Register a view for the current post.
 *
 * @param int $post_id Post ID.
 */
function aurora_count_view( $post_id ) {
	if ( is_user_logged_in() && get_current_user_id() === (int) get_post_field( 'post_author', $post_id ) ) {
		return;
	}
	$views = aurora_get_views( $post_id );
	update_post_meta( $post_id, 'aurora_views', $views + 1 );
}

/**
 * Post thumbnail or a deterministic gradient placeholder.
 *
 * @param int|null $post_id Post ID.
 * @param string   $size    Image size.
 */
function aurora_thumbnail( $post_id = null, $size = 'aurora-card' ) {
	$post_id = $post_id ? $post_id : get_the_ID();

	if ( has_post_thumbnail( $post_id ) ) {
		echo get_the_post_thumbnail( $post_id, $size, array(
			'loading' => 'lazy',
			'alt'     => esc_attr( get_the_title( $post_id ) ),
		) );
		return;
	}

	// Deterministic gradient so the grid never collapses on missing images.
	$seed  = (int) $post_id;
	$hue_a = $seed * 47 % 360;
	$hue_b = ( $hue_a + 48 ) % 360;
	printf(
		'<span class="aurora-thumb-fallback" style="background:linear-gradient(135deg,hsl(%1$d 78%% 62%%),hsl(%2$d 74%% 52%%))" aria-hidden="true"></span>',
		(int) $hue_a,
		(int) $hue_b
	);
}

/**
 * First category of a post.
 *
 * @param int|null $post_id Post ID.
 * @return WP_Term|null
 */
function aurora_primary_category( $post_id = null ) {
	$terms = get_the_category( $post_id ? $post_id : get_the_ID() );

	return ! empty( $terms ) ? $terms[0] : null;
}

/**
 * Render the card meta line (author, date, views).
 *
 * @param int|null $post_id Post ID.
 */
function aurora_card_meta( $post_id = null ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	?>
	<div class="aurora-card__meta">
		<span class="aurora-card__author">
			<?php echo get_avatar( get_the_author_meta( 'ID', get_post_field( 'post_author', $post_id ) ), 44, '', '', array( 'class' => 'aurora-card__avatar' ) ); ?>
			<?php echo esc_html( get_the_author_meta( 'display_name', get_post_field( 'post_author', $post_id ) ) ); ?>
		</span>
		<span><?php aurora_the_icon( 'clock', 14 ); ?><?php echo esc_html( aurora_reading_time( $post_id ) ); ?><?php esc_html_e( ' 分钟', 'aurora' ); ?></span>
		<span><?php aurora_the_icon( 'eye', 14 ); ?><?php echo esc_html( number_format_i18n( aurora_get_views( $post_id ) ) ); ?></span>
		<?php if ( comments_open( $post_id ) ) : ?>
			<span><?php aurora_the_icon( 'comment', 14 ); ?><?php echo esc_html( number_format_i18n( get_comments_number( $post_id ) ) ); ?></span>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * A teaser that is safe to print for a locked post.
 *
 * WordPress derives an excerpt from the body when the author has not written
 * one, so `the_excerpt()` on a short protected article reproduces that article
 * in full — exactly the text the paywall exists to withhold. For a locked post
 * we therefore print the author's own excerpt when they wrote one, and a
 * neutral line otherwise; never an auto-derived summary.
 *
 * @param int $post_id Post ID, or 0 for the current post.
 * @param int $words   Word limit applied to genuine excerpts.
 * @return string
 */
function aurora_safe_excerpt( $post_id = 0, $words = 26 ) {
	$post_id = $post_id ? $post_id : get_the_ID();

	if ( ! $post_id ) {
		return '';
	}

	$locked = function_exists( 'aurora_content_locked' ) && aurora_content_locked( $post_id );

	if ( $locked && ! has_excerpt( $post_id ) ) {
		return __( '此内容为付费 / 会员专享，登录或购买后可见。', 'aurora' );
	}

	return wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post_id ) ), $words );
}

/**
 * Render a single post card.
 *
 * @param int|null $post_id Post ID.
 * @param string   $variant Card layout: grid|row.
 */
function aurora_render_card( $post_id = null, $variant = 'grid' ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	$classes = 'aurora-card aurora-reveal';
	if ( 'row' === $variant ) {
		$classes .= ' aurora-card--row';
	}
	$locked = function_exists( 'aurora_content_locked' ) && aurora_content_locked( $post_id );
	?>
	<article id="post-<?php echo esc_attr( $post_id ); ?>" class="<?php echo esc_attr( $classes ); ?>">
		<a class="aurora-card__media" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" aria-hidden="true" tabindex="-1">
			<?php aurora_thumbnail( $post_id, 'row' === $variant ? 'aurora-card-wide' : 'aurora-card' ); ?>
		</a>

		<?php if ( is_sticky( $post_id ) ) : ?>
			<span class="aurora-card__flag aurora-card__flag--top"><?php esc_html_e( '置顶', 'aurora' ); ?></span>
		<?php elseif ( $locked ) : ?>
			<span class="aurora-card__flag aurora-card__flag--vip"><?php aurora_the_icon( 'crown', 12 ); ?><?php esc_html_e( '会员', 'aurora' ); ?></span>
		<?php elseif ( function_exists( 'aurora_is_hot' ) && aurora_is_hot( $post_id ) ) : ?>
			<span class="aurora-card__flag aurora-card__flag--hot"><?php aurora_the_icon( 'fire', 12 ); ?><?php esc_html_e( '热门', 'aurora' ); ?></span>
		<?php endif; ?>

		<div class="aurora-card__body">
			<?php
			$cat = aurora_primary_category( $post_id );
			if ( $cat ) :
				?>
				<div class="aurora-card__cats">
					<a class="aurora-card__cat" href="<?php echo esc_url( get_category_link( $cat ) ); ?>"><?php echo esc_html( $cat->name ); ?></a>
					<?php
					if ( function_exists( 'aurora_media_badge' ) ) {
						aurora_media_badge( $post_id );
					}
					?>
				</div>
			<?php endif; ?>

			<h3 class="aurora-card__title">
				<a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
			</h3>

			<p class="aurora-card__excerpt"><?php echo esc_html( aurora_safe_excerpt( $post_id, 26 ) ); ?></p>

			<?php aurora_card_meta( $post_id ); ?>
		</div>
	</article>
	<?php
}

/**
 * Output the post loop in a given layout.
 *
 * @param string $layout grid|grid-two|list.
 */
function aurora_loop( $layout = 'grid' ) {
	if ( have_posts() ) {
		$grid_class = 'aurora-grid';
		$variant    = 'grid';
		if ( 'grid-two' === $layout ) {
			$grid_class .= ' aurora-grid--two';
		} elseif ( 'list' === $layout ) {
			$grid_class .= ' aurora-grid--list';
			$variant     = 'row';
		}
		echo '<div id="aurora-loop" class="' . esc_attr( $grid_class ) . '">';
		while ( have_posts() ) {
			the_post();
			aurora_render_card( get_the_ID(), $variant );
		}
		echo '</div>';

		aurora_pagination();
	} else {
		get_template_part( 'template-parts/empty' );
	}
}

/**
 * Pagination: numbered on archives, load-more anchor when enabled.
 */
function aurora_pagination() {
	global $wp_query;

	if ( $wp_query->max_num_pages < 2 ) {
		return;
	}

	$links = paginate_links( array(
		'mid_size'  => 1,
		'end_size'  => 1,
		'prev_text' => aurora_icon( 'chevron', 16 ),
		'next_text' => aurora_icon( 'chevron', 16 ),
		'type'      => 'array',
		'base'      => str_replace( 999999999, '%#%', esc_url( get_pagenum_link( 999999999 ) ) ),
		'format'    => '?paged=%#%',
		'current'   => max( 1, get_query_var( 'paged' ) ? get_query_var( 'paged' ) : get_query_var( 'page' ) ),
		'total'     => $wp_query->max_num_pages,
	) );

	if ( empty( $links ) ) {
		return;
	}

	if ( 'loadmore' === get_theme_mod( 'aurora_pagination_style', 'number' ) ) {
		$next = get_next_posts_page_link( $wp_query->max_num_pages );
		echo '<div class="aurora-loadmore"><button type="button" class="aurora-btn aurora-btn--primary aurora-btn--lg"';
		if ( $next ) {
			echo ' data-next="' . esc_url( $next ) . '"';
		} else {
			echo ' disabled';
		}
		echo ' data-target="#aurora-loop" data-item="' . esc_attr( '.aurora-grid > *' ) . '">';
		echo esc_html( $next ? __( '加载更多', 'aurora' ) : __( '没有更多了', 'aurora' ) );
		echo '</button></div>';
		return;
	}

	echo '<nav class="aurora-pagination" aria-label="' . esc_attr__( '分页', 'aurora' ) . '">';
	foreach ( $links as $link ) {
		echo wp_kses_post( $link );
	}
	echo '</nav>';
}

/**
 * Post navigation on single views.
 */
function aurora_post_nav() {
	$prev = get_previous_post();
	$next = get_next_post();

	if ( ! $prev && ! $next ) {
		return;
	}
	?>
	<nav class="aurora-postnav aurora-grid aurora-grid--two" style="margin-top:26px">
		<?php if ( $prev ) : ?>
			<a class="aurora-panel" href="<?php echo esc_url( get_permalink( $prev ) ); ?>" style="margin:0">
				<span class="aurora-tag"><?php esc_html_e( '上一篇', 'aurora' ); ?></span>
				<div style="margin-top:8px;font-weight:500;color:var(--aurora-text)"><?php echo esc_html( get_the_title( $prev ) ); ?></div>
			</a>
		<?php endif; ?>
		<?php if ( $next ) : ?>
			<a class="aurora-panel" href="<?php echo esc_url( get_permalink( $next ) ); ?>" style="margin:0;text-align:right">
				<span class="aurora-tag"><?php esc_html_e( '下一篇', 'aurora' ); ?></span>
				<div style="margin-top:8px;font-weight:500;color:var(--aurora-text)"><?php echo esc_html( get_the_title( $next ) ); ?></div>
			</a>
		<?php endif; ?>
	</nav>
	<?php
}

/**
 * Breadcrumb trail.
 */
function aurora_breadcrumbs() {
	if ( is_front_page() ) {
		return;
	}
	echo '<nav class="aurora-crumbs" aria-label="' . esc_attr__( '面包屑', 'aurora' ) . '"><div class="aurora-container" style="font-size:.84rem;color:var(--aurora-text-tertiary)">';
	echo '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( '首页', 'aurora' ) . '</a>';
	if ( is_singular() ) {
		$cat = aurora_primary_category();
		if ( $cat ) {
			echo ' <span>/</span> <a href="' . esc_url( get_category_link( $cat ) ) . '">' . esc_html( $cat->name ) . '</a>';
		}
		echo ' <span>/</span> <span>' . esc_html( wp_trim_words( get_the_title(), 8, '…' ) ) . '</span>';
	} elseif ( is_category() || is_tag() || is_tax() ) {
		echo ' <span>/</span> <span>' . esc_html( single_term_title( '', false ) ) . '</span>';
	} elseif ( is_search() ) {
		echo ' <span>/</span> <span>' . esc_html__( '搜索', 'aurora' ) . '</span>';
	} elseif ( is_author() ) {
		echo ' <span>/</span> <span>' . esc_html( get_the_author() ) . '</span>';
	} elseif ( is_404() ) {
		echo ' <span>/</span> <span>404</span>';
	}
	echo '</div></nav>';
}

/**
 * Does this post have a members-only gate?
 *
 * @param int|null $post_id Post ID.
 * @return bool
 */
function aurora_post_is_vip( $post_id = null ) {
	$post_id = $post_id ? $post_id : get_the_ID();

	return (bool) get_post_meta( $post_id, 'aurora_vip_level', true );
}

/**
 * Render the site brand (custom logo or wordmark).
 */
function aurora_brand() {
	if ( has_custom_logo() ) {
		the_custom_logo();
		return;
	}
	printf(
		'<a class="aurora-brand" href="%1$s" rel="home"><span class="aurora-brand__mark">A</span><span>%2$s</span></a>',
		esc_url( home_url( '/' ) ),
		esc_html( get_bloginfo( 'name' ) )
	);
}
