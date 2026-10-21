<?php
/**
 * Homepage composition.
 *
 * The home page was a fixed stack of sections. This makes the stack a list the
 * owner controls: order comes from one setting, and every block renders through
 * a single switch, so a section can be reordered or dropped without editing a
 * template.
 *
 * The default order reproduces the previous layout exactly, so switching this on
 * changes nothing for a site that never touches the setting.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * The available blocks.
 *
 * @return array<string,string>
 */
function aurora_home_block_keys() {
	$blocks = array(
		'hero'   => __( '头条大图', 'aurora' ),
		'chips'  => __( '分类快捷条', 'aurora' ),
		'hot'    => __( '热点榜', 'aurora' ),
		'grid'   => __( '精选三列', 'aurora' ),
		'cats'   => __( '分类精选', 'aurora' ),
		'latest' => __( '最新内容', 'aurora' ),
		'newsletter' => __( '订阅框', 'aurora' ),
	);

	return apply_filters( 'aurora_home_block_keys', $blocks );
}

/**
 * The configured order, filtered to keys that exist.
 *
 * @return string[]
 */
function aurora_home_blocks() {
	$raw = (string) get_theme_mod( 'aurora_home_blocks', 'chips,hot,latest' );
	$want = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
	$valid = array_keys( aurora_home_block_keys() );
	// A repeated key would render the same section twice (two identical hero
	// cards), so duplicates collapse while keeping the first position.
	$out   = array_values( array_unique( array_intersect( $want, $valid ) ) );

	// An unknown-only or empty setting must not produce a blank home page.
	if ( ! $out ) {
		$out = array( 'latest' );
	}

	return $out;
}

/**
 * Posts for the hero: the newest sticky, else the newest post.
 *
 * @return WP_Post|null
 */
function aurora_home_hero_post() {
	$sticky = get_option( 'sticky_posts' );

	if ( $sticky ) {
		$q = new WP_Query(
			apply_filters(
				'aurora_listing_query_args',
				array(
					'post__in'            => $sticky,
					'posts_per_page'      => 1,
					'ignore_sticky_posts' => 1,
					'no_found_rows'       => true,
					'post_status'         => 'publish',
				)
			)
		);

		if ( $q->have_posts() ) {
			return $q->posts[0];
		}
	}

	$latest = get_posts(
		apply_filters(
			'aurora_listing_query_args',
			array(
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'post_status'    => 'publish',
			)
		)
	);

	return $latest ? $latest[0] : null;
}

/**
 * Render one block.
 *
 * @param string $key Block key.
 */
function aurora_render_home_block( $key ) {
	switch ( $key ) {
		case 'hero':
			$post = aurora_home_hero_post();

			if ( ! $post ) {
				return;
			}

			$cat = aurora_primary_category( $post->ID );
			?>
			<section class="aurora-hero">
				<a class="aurora-hero__media" href="<?php echo esc_url( get_permalink( $post ) ); ?>">
					<?php if ( has_post_thumbnail( $post ) ) : ?>
						<?php echo get_the_post_thumbnail( $post, 'large', array( 'loading' => 'eager' ) ); ?>
					<?php else : ?>
						<span class="aurora-hero__fallback"><?php aurora_the_icon( 'image', 28 ); ?></span>
					<?php endif; ?>
				</a>
				<div class="aurora-hero__body">
					<?php if ( $cat ) : ?>
						<a class="aurora-hero__cat" href="<?php echo esc_url( get_category_link( $cat ) ); ?>"><?php echo esc_html( $cat->name ); ?></a>
					<?php endif; ?>
					<h2 class="aurora-hero__title">
						<a href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a>
					</h2>
					<p class="aurora-hero__excerpt"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $post->post_content ), 42, '…' ) ); ?></p>
					<div class="aurora-hero__meta">
						<span><?php echo esc_html( get_the_date( '', $post ) ); ?></span>
						<span><?php echo esc_html( get_the_author_meta( 'display_name', $post->post_author ) ); ?></span>
					</div>
				</div>
			</section>
			<?php
			return;

		case 'chips':
			aurora_category_chips();
			return;

		case 'hot':
			if ( get_theme_mod( 'aurora_hot_on_home', true ) ) {
				get_template_part(
					'template-parts/hot-topics',
					null,
					array(
						'layout' => 'card',
						'limit'  => 10,
					)
				);
			}
			return;

		case 'grid':
			$sticky = get_option( 'sticky_posts' );
			$args   = array(
				'posts_per_page' => 3,
				'no_found_rows'  => true,
				'post_status'    => 'publish',
			);

			if ( $sticky ) {
				$args['post__in']            = $sticky;
				$args['ignore_sticky_posts'] = 1;
			}

			$q = new WP_Query( apply_filters( 'aurora_listing_query_args', $args ) );

			if ( ! $q->have_posts() ) {
				return;
			}
			?>
			<section class="aurora-section" style="margin-top:26px">
				<div class="aurora-section__head">
					<h2 class="aurora-section__title"><?php esc_html_e( '精选推荐', 'aurora' ); ?></h2>
				</div>
				<div class="aurora-grid">
					<?php
					while ( $q->have_posts() ) {
						$q->the_post();
						aurora_render_card( get_the_ID(), 'grid' );
					}
					wp_reset_postdata();
					?>
				</div>
			</section>
			<?php
			return;

		case 'cats':
			$terms = get_categories(
				array(
					'hide_empty' => true,
					'number'     => 3,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);

			foreach ( $terms as $term ) {
				$q = new WP_Query(
					apply_filters(
						'aurora_listing_query_args',
						array(
							'cat'            => $term->term_id,
							'posts_per_page' => 3,
							'no_found_rows'  => true,
							'post_status'    => 'publish',
						)
					)
				);

				if ( ! $q->have_posts() ) {
					continue;
				}
				?>
				<section class="aurora-section" style="margin-top:26px">
					<div class="aurora-section__head">
						<h2 class="aurora-section__title"><?php echo esc_html( $term->name ); ?></h2>
						<a class="aurora-section__more" href="<?php echo esc_url( get_category_link( $term ) ); ?>">
							<?php esc_html_e( '更多', 'aurora' ); ?><?php aurora_the_icon( 'chevron', 15 ); ?>
						</a>
					</div>
					<div class="aurora-grid">
						<?php
						while ( $q->have_posts() ) {
							$q->the_post();
							aurora_render_card( get_the_ID(), 'grid' );
						}
						wp_reset_postdata();
						?>
					</div>
				</section>
				<?php
			}
			return;

		case 'newsletter':
			if ( ! function_exists( 'aurora_newsletter_form' ) || ! aurora_newsletter_enabled() ) {
				return;
			}
			?>
			<section class="aurora-section aurora-section--newsletter" style="margin-top:26px">
				<?php aurora_newsletter_form( 'home' ); ?>
			</section>
			<?php
			return;

		case 'latest':
			?>
			<section class="aurora-section">
				<div class="aurora-section__head">
					<h2 class="aurora-section__title"><?php esc_html_e( '最新内容', 'aurora' ); ?></h2>
					<a class="aurora-section__more" href="<?php echo esc_url( get_permalink( get_option( 'page_for_posts' ) ) ? get_permalink( get_option( 'page_for_posts' ) ) : home_url( '/' ) ); ?>">
						<?php esc_html_e( '全部', 'aurora' ); ?><?php aurora_the_icon( 'chevron', 15 ); ?>
					</a>
				</div>
				<?php aurora_loop( get_theme_mod( 'aurora_home_layout', 'grid' ) ); ?>
			</section>
			<?php
			return;
	}

	/**
	 * Fires for a block key this module does not render, so a plugin can add one.
	 *
	 * @param string $key Block key.
	 */
	do_action( 'aurora_home_block', $key );
}
