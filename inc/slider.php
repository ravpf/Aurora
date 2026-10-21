<?php
/**
 * Home page slideshow.
 *
 * Two sources: an owner-written list (image | title | link | description) and
 * an automatic list built from sticky posts, then the newest articles. The
 * automatic one means the slide exists the moment the module is switched on,
 * instead of showing an empty frame until somebody fills in four fields.
 *
 * Everything is server-rendered; the script only moves the track. No slide
 * content is fetched after load, so the first paint is complete even without
 * JavaScript — the first slide is simply the visible one.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is the slideshow on?
 *
 * @return bool
 */
function aurora_slider_enabled() {
	return (bool) get_theme_mod( 'aurora_slider_enabled', true );
}

/**
 * How many slides at most.
 *
 * @return int
 */
function aurora_slider_count() {
	return max( 1, min( 8, (int) get_theme_mod( 'aurora_slider_count', 3 ) ) );
}

/**
 * Turn one field into a usable URL, or nothing.
 *
 * esc_url_raw() prepends http:// to anything without a scheme, so free text
 * like "封面图" comes back as a "valid" URL and renders as a broken image.
 * A scheme is required here; a site-relative path is resolved first so that
 * /wp-content/... still works.
 *
 * @param string $raw Raw field.
 * @return string Empty when it is not a real http(s) URL.
 */
function aurora_slider_url( $raw ) {
	$raw = trim( (string) $raw );

	if ( '' === $raw ) {
		return '';
	}

	if ( '/' === $raw[0] ) {
		$raw = home_url( $raw );
	}

	if ( ! preg_match( '#^https?://[^\s/]+#i', $raw ) ) {
		return '';
	}

	return (string) esc_url_raw( $raw, array( 'http', 'https' ) );
}

/**
 * Manual slides, parsed.
 *
 * Each non-empty line is `image | title | link | description`. Only the image
 * is required; anything with a non-http(s) URL is dropped rather than rendered.
 *
 * @return array<int,array{image:string,title:string,link:string,desc:string}>
 */
function aurora_slider_manual_slides() {
	$raw = (string) get_theme_mod( 'aurora_slider_slides', '' );

	if ( '' === trim( $raw ) ) {
		return array();
	}

	$slides = array();

	foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$line = trim( $line );

		if ( '' === $line ) {
			continue;
		}

		$parts = array_map( 'trim', explode( '|', $line ) );

		$image = aurora_slider_url( $parts[0] ?? '' );

		if ( ! $image ) {
			continue;
		}

		$link = aurora_slider_url( $parts[2] ?? '' );

		$slides[] = array(
			'image' => $image,
			'title' => sanitize_text_field( $parts[1] ?? '' ),
			'link'  => $link ? $link : $image,
			'desc'  => sanitize_text_field( $parts[3] ?? '' ),
		);
	}

	return array_slice( $slides, 0, aurora_slider_count() );
}

/**
 * Post IDs typed into the admin, parsed.
 *
 * Comma, space and newline all work — editors paste whichever they have. The
 * order is the playback order, duplicates collapse, and the list stops at the
 * same eight-slide ceiling the rest of the slider uses.
 *
 * @return array<int,int>
 */
function aurora_slider_post_ids() {
	$raw = (string) get_theme_mod( 'aurora_slider_post_ids', '' );

	if ( '' === trim( $raw ) ) {
		return array();
	}

	$ids = array();

	foreach ( preg_split( '/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $chunk ) {
		$which = (int) $chunk;

		if ( $which > 0 && ! in_array( $which, $ids, true ) ) {
			$ids[] = $which;
		}
	}

	return array_slice( $ids, 0, 8 );
}

/**
 * Cover for one chosen post.
 *
 * Featured image first; when an article has none, the first image in its body
 * stands in, so a writer does not have to set a feature image just to be on the
 * home page.
 *
 * @param WP_Post $post Post.
 * @return string
 */
function aurora_slider_post_image( $post ) {
	$image = get_the_post_thumbnail_url( $post, 'aurora-card-wide' );

	if ( $image ) {
		return $image;
	}

	if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $post->post_content, $match ) ) {
		return aurora_slider_url( html_entity_decode( $match[1], ENT_QUOTES ) );
	}

	return '';
}

/**
 * Slides picked by post ID.
 *
 * Only published, publicly viewable posts with a usable image survive; anything
 * else is silently skipped so a deleted draft never leaves a hole in the deck.
 *
 * @return array<int,array{image:string,title:string,link:string,desc:string}>
 */
function aurora_slider_id_slides() {
	$slides = array();

	foreach ( aurora_slider_post_ids() as $which ) {
		$post = get_post( $which );

		if ( ! $post || 'publish' !== $post->post_status ) {
			continue;
		}

		if ( post_password_required( $post ) || ! is_post_type_viewable( $post->post_type ) ) {
			continue;
		}

		$image = aurora_slider_post_image( $post );

		if ( ! $image ) {
			continue;
		}

		$slides[] = array(
			'image' => $image,
			'title' => get_the_title( $post ),
			'link'  => get_permalink( $post ),
			'desc'  => aurora_safe_excerpt( $post->ID, 18 ),
		);
	}

	return $slides;
}

/**
 * Read-out under the ID field: what each number resolved to, and why one did not.
 *
 * Runs inside the settings form, so it never posts anything back — it is there
 * to catch a typo before the home page shows it.
 */
function aurora_slider_ids_preview() {
	if ( 'ids' !== get_theme_mod( 'aurora_slider_source', 'auto' ) ) {
		echo '<p class="description">' . esc_html__( '当前来源不是「按文章编号」，这段编号暂时不生效。', 'aurora' ) . '</p>';
		return;
	}

	$ids = aurora_slider_post_ids();

	if ( ! $ids ) {
		echo '<p class="description">' . esc_html__( '还没有填编号：首页会退回自动来源。', 'aurora' ) . '</p>';
		return;
	}

	echo '<ul class="aurora-ids-preview" style="margin:6px 0 0;padding-left:18px;line-height:1.7">';

	foreach ( $ids as $which ) {
		$post = get_post( $which );

		if ( ! $post ) {
			printf(
				'<li style="color:#b32d2e">#%d — %s</li>',
				$which,
				esc_html__( '找不到这篇文章', 'aurora' )
			);
			continue;
		}

		$title = get_the_title( $post );
		$edit  = get_edit_post_link( $which );
		$state = 'publish' === $post->post_status
			? ( aurora_slider_post_image( $post ) ? '' : __( '（没有特色图，正文也没图，会被跳过）', 'aurora' ) )
			: __( '（未发布，会被跳过）', 'aurora' );

		printf(
			'<li>#%d — <a href="%s">%s</a> <span style="color:#646970">%s</span></li>',
			$which,
			esc_url( $edit ? $edit : '#' ),
			esc_html( '' !== $title ? $title : __( '(无标题)', 'aurora' ) ),
			esc_html( $state )
		);
	}

	echo '</ul>';
}

/**
 * Automatic slides from sticky posts, then the newest articles.
 *
 * @return array<int,array{image:string,title:string,link:string,desc:string}>
 */
function aurora_slider_auto_slides() {
	$limit  = aurora_slider_count();
	$sticky = array_filter( array_map( 'intval', (array) get_option( 'sticky_posts' ) ) );
	$slides = array();
	$seen   = array();

	// Ask for more candidates than slides: posts without a featured image are
	// skipped, and if the query only fetched $limit rows the slider would come
	// out empty whenever the newest few articles happen to have no cover.
	$pool = max( $limit * 4, 8 );

	$take = function ( $args ) use ( &$slides, &$seen, $limit, $pool ) {
		$args = apply_filters(
			'aurora_listing_query_args',
			array_merge(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => $pool,
					'no_found_rows'  => true,
				),
				$args
			)
		);

		foreach ( get_posts( $args ) as $post ) {
			if ( count( $slides ) >= $limit || isset( $seen[ $post->ID ] ) ) {
				continue;
			}

			$image = get_the_post_thumbnail_url( $post, 'aurora-card-wide' );

			if ( ! $image ) {
				continue;
			}

			$seen[ $post->ID ] = true;

			$slides[] = array(
				'image' => $image,
				'title' => get_the_title( $post ),
				'link'  => get_permalink( $post ),
				'desc'  => aurora_safe_excerpt( $post->ID, 18 ),
			);
		}
	};

	if ( $sticky ) {
		$take( array( 'post__in' => $sticky, 'orderby' => 'post__in' ) );
	}

	if ( count( $slides ) < $limit ) {
		$take( array( 'ignore_sticky_posts' => 1 ) );
	}

	return $slides;
}

/**
 * The slides the page should show.
 *
 * @return array
 */
function aurora_slider_slides() {
	$source = get_theme_mod( 'aurora_slider_source', 'auto' );

	if ( 'manual' === $source ) {
		$slides = aurora_slider_manual_slides();
	} elseif ( 'ids' === $source ) {
		// 编号模式：填了哪几篇就放哪几篇，顺序就是播放顺序，「最多几张」不参与裁剪。
		$slides = aurora_slider_id_slides();
	} else {
		$slides = array();
	}

	if ( ! $slides ) {
		$slides = aurora_slider_auto_slides();
	}

	/**
	 * Filter the slides before rendering.
	 *
	 * @param array $slides Slides.
	 */
	return apply_filters( 'aurora_slider_slides', $slides );
}

/**
 * Render it.
 */
function aurora_slider_render() {
	if ( ! is_front_page() || ! aurora_slider_enabled() ) {
		return;
	}

	$slides = aurora_slider_slides();

	if ( ! $slides ) {
		return;
	}

	$autoplay = (bool) get_theme_mod( 'aurora_slider_autoplay', true );
	$interval = max( 2500, min( 15000, (int) get_theme_mod( 'aurora_slider_interval', 5000 ) ) );
	?>
	<div class="aurora-container aurora-slider-wrap">
	<section class="aurora-slider" data-autoplay="<?php echo $autoplay ? '1' : '0'; ?>"
		data-interval="<?php echo esc_attr( (string) $interval ); ?>"
		role="region" aria-roledescription="carousel" aria-label="<?php esc_attr_e( '首页推荐', 'aurora' ); ?>">
		<div class="aurora-slider__viewport">
			<div class="aurora-slider__track">
				<?php foreach ( $slides as $index => $slide ) : ?>
					<article class="aurora-slider__slide<?php echo 0 === $index ? ' is-active' : ''; ?>"
						role="group" aria-roledescription="slide"
						aria-label="<?php echo esc_attr( sprintf( /* translators: 1: index, 2: total. */ __( '第 %1$d 张，共 %2$d 张', 'aurora' ), $index + 1, count( $slides ) ) ); ?>"
						aria-hidden="<?php echo 0 === $index ? 'false' : 'true'; ?>">
						<a class="aurora-slider__link" href="<?php echo esc_url( $slide['link'] ); ?>"
							<?php echo 0 === $index ? '' : 'tabindex="-1"'; ?>>
							<img class="aurora-slider__img" src="<?php echo esc_url( $slide['image'] ); ?>" alt=""
								loading="<?php echo 0 === $index ? 'eager' : 'lazy'; ?>" decoding="async">
							<span class="aurora-slider__scrim" aria-hidden="true"></span>

							<?php if ( $slide['title'] || $slide['desc'] ) : ?>
								<span class="aurora-slider__text">
									<?php if ( $slide['title'] ) : ?>
										<span class="aurora-slider__title"><?php echo esc_html( $slide['title'] ); ?></span>
									<?php endif; ?>
									<?php if ( $slide['desc'] ) : ?>
										<span class="aurora-slider__desc"><?php echo esc_html( $slide['desc'] ); ?></span>
									<?php endif; ?>
									<span class="aurora-slider__cta"><?php esc_html_e( '阅读', 'aurora' ); ?></span>
								</span>
							<?php endif; ?>
						</a>
					</article>
				<?php endforeach; ?>
			</div>
		</div>

		<?php if ( count( $slides ) > 1 ) : ?>
			<button type="button" class="aurora-slider__nav aurora-slider__nav--prev" aria-label="<?php esc_attr_e( '上一张', 'aurora' ); ?>">
				<span aria-hidden="true">‹</span>
			</button>
			<button type="button" class="aurora-slider__nav aurora-slider__nav--next" aria-label="<?php esc_attr_e( '下一张', 'aurora' ); ?>">
				<span aria-hidden="true">›</span>
			</button>

			<div class="aurora-slider__dots" role="tablist" aria-label="<?php esc_attr_e( '选择幻灯片', 'aurora' ); ?>">
				<?php foreach ( $slides as $index => $slide ) : ?>
					<button type="button" class="aurora-slider__dot<?php echo 0 === $index ? ' is-active' : ''; ?>"
						role="tab" aria-selected="<?php echo 0 === $index ? 'true' : 'false'; ?>"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %d: index. */ __( '第 %d 张', 'aurora' ), $index + 1 ) ); ?>"></button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
	</div>
	<?php
}
add_action( 'aurora_before_home_main', 'aurora_slider_render' );
