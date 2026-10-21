<?php
/**
 * Reading and engagement extras.
 *
 * Feature parity work gathered from studying the most installed and best
 * selling themes (Astra, Kadence, Blocksy, OceanWP, Newspaper, JNews,
 * Soledad, Foxiz, Flatsome, Divi, Avada, Bimber, Zibll and friends) plus the
 * Chinese community themes. Everything here is written from scratch: no theme
 * code, icons, screenshots or copy is reused, and no third party brand asset
 * is embedded (share targets are plain text labels).
 *
 * Adds: related posts, a share panel, an in-article author card with follow,
 * a copyright / source note, home category chips and a "hot" card flag.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Related posts
 * ---------------------------------------------------------------------- */

/**
 * Pick related post ids: shared tags first, then the same category, then the
 * newest posts so the block is never empty on a thin site.
 *
 * @param int $post_id Source post.
 * @param int $limit   How many ids to return.
 * @return int[]
 */
function aurora_related_ids( $post_id = 0, $limit = 3 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
	$limit   = max( 1, (int) $limit );

	if ( ! $post_id ) {
		return array();
	}

	$cache_key = 'rel_' . $post_id . '_' . $limit;
	$cached    = wp_cache_get( $cache_key, 'aurora' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$ids  = array();
	$tags = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );
	$cats = wp_get_post_categories( $post_id );

	$base = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
		'fields'              => 'ids',
		'orderby'             => 'date',
		'order'               => 'DESC',
	);

	if ( $tags ) {
		$ids = get_posts(
			array_merge(
				$base,
				array(
					'tag__in'         => $tags,
					'posts_per_page'  => $limit,
					'post__not_in'    => array( $post_id ),
				)
			)
		);
	}

	if ( count( $ids ) < $limit && $cats ) {
		$ids = array_merge(
			$ids,
			get_posts(
				array_merge(
					$base,
					array(
						'category__in'   => $cats,
						'posts_per_page' => $limit - count( $ids ),
						'post__not_in'   => array_merge( array( $post_id ), $ids ),
					)
				)
			)
		);
	}

	if ( count( $ids ) < $limit ) {
		$ids = array_merge(
			$ids,
			get_posts(
				array_merge(
					$base,
					array(
						'posts_per_page' => $limit - count( $ids ),
						'post__not_in'   => array_merge( array( $post_id ), $ids ),
					)
				)
			)
		);
	}

	$ids = array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), 0, $limit );

	wp_cache_set( $cache_key, $ids, 'aurora', 10 * MINUTE_IN_SECONDS );

	return apply_filters( 'aurora_related_ids', $ids, $post_id, $limit );
}

/**
 * Render the related posts block.
 *
 * @param int $post_id Source post.
 * @param int $limit   Card count.
 */
function aurora_related_block( $post_id = 0, $limit = 3 ) {
	if ( ! get_theme_mod( 'aurora_show_related', true ) ) {
		return;
	}

	$ids = aurora_related_ids( $post_id, $limit );
	if ( ! $ids ) {
		return;
	}
	?>
	<section class="aurora-related" aria-labelledby="aurora-related-title">
		<h2 id="aurora-related-title" class="aurora-section__title"><?php esc_html_e( '相关阅读', 'aurora' ); ?></h2>
		<div class="aurora-grid aurora-grid--related">
			<?php
			foreach ( $ids as $aurora_rid ) {
				aurora_render_card( $aurora_rid );
			}
			?>
		</div>
	</section>
	<?php
}

/* -------------------------------------------------------------------------
 * Share panel
 * ---------------------------------------------------------------------- */

/**
 * Share targets for a post. Text labels only — no brand logos are embedded.
 *
 * @param int $post_id Post id.
 * @return array
 */
function aurora_share_links( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
	$url     = get_permalink( $post_id );
	$title   = wp_strip_all_tags( get_the_title( $post_id ) );

	$links = array(
		'weibo' => array(
			'label' => __( '微博', 'aurora' ),
			'url'   => 'https://service.weibo.com/share/share.php?url=' . rawurlencode( $url ) . '&title=' . rawurlencode( $title ),
		),
		'qzone' => array(
			'label' => __( 'QQ空间', 'aurora' ),
			'url'   => 'https://sns.qzone.qq.com/cgi-bin/qzshare/cgi_qzshare_onekey?url=' . rawurlencode( $url ) . '&title=' . rawurlencode( $title ),
		),
		'x'     => array(
			'label' => __( 'X', 'aurora' ),
			'url'   => 'https://twitter.com/intent/tweet?url=' . rawurlencode( $url ) . '&text=' . rawurlencode( $title ),
		),
		'mail'  => array(
			'label' => __( '邮件', 'aurora' ),
			'url'   => 'mailto:?subject=' . rawurlencode( $title ) . '&body=' . rawurlencode( $url ),
		),
	);

	return apply_filters( 'aurora_share_links', $links, $post_id );
}

/**
 * Render the share row. WeChat has no web intent, so it copies the link and
 * tells the reader to paste it in the app instead of faking a QR code.
 *
 * @param int $post_id Post id.
 */
function aurora_share_bar( $post_id = 0 ) {
	if ( ! get_theme_mod( 'aurora_show_share', true ) ) {
		return;
	}

	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
	$url     = get_permalink( $post_id );
	$links   = aurora_share_links( $post_id );
	?>
	<div class="aurora-share" data-share-url="<?php echo esc_url( $url ); ?>">
		<span class="aurora-share__label"><?php aurora_the_icon( 'share', 16 ); ?><?php esc_html_e( '分享', 'aurora' ); ?></span>

		<button type="button" class="aurora-share__btn aurora-copy" data-clipboard="<?php echo esc_url( $url ); ?>">
			<?php aurora_the_icon( 'link', 15 ); ?><span data-label=""><?php esc_html_e( '复制链接', 'aurora' ); ?></span>
		</button>

		<button type="button" class="aurora-share__btn aurora-copy aurora-share__wechat" data-clipboard="<?php echo esc_url( $url ); ?>"
			data-tip="<?php esc_attr_e( '链接已复制，打开微信粘贴给好友', 'aurora' ); ?>">
			<?php esc_html_e( '微信', 'aurora' ); ?>
		</button>

		<?php foreach ( $links as $aurora_key => $aurora_link ) : ?>
			<a class="aurora-share__btn" href="<?php echo esc_url( $aurora_link['url'] ); ?>"
				target="_blank" rel="nofollow noopener noreferrer">
				<?php echo esc_html( $aurora_link['label'] ); ?>
			</a>
		<?php endforeach; ?>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Copyright / source note
 * ---------------------------------------------------------------------- */

/**
 * Article footer note: author, canonical link, licence line, and the original
 * source when the post came in through the AI collector.
 *
 * @param int $post_id Post id.
 */
function aurora_copyright_box( $post_id = 0 ) {
	if ( ! get_theme_mod( 'aurora_show_copyright', true ) ) {
		return;
	}

	$post_id  = $post_id ? (int) $post_id : (int) get_the_ID();
	$author   = get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $post_id ) );
	$home     = home_url( '/' );
	$url      = get_permalink( $post_id );
	?>
	<div class="aurora-copyright">
		<div class="aurora-copyright__grid">
			<div class="aurora-copyright__row">
				<span class="aurora-copyright__k"><?php esc_html_e( '作者', 'aurora' ); ?></span>
				<span class="aurora-copyright__v"><?php echo esc_html( $author ); ?></span>
			</div>
			<div class="aurora-copyright__row">
				<span class="aurora-copyright__k"><?php esc_html_e( '出处', 'aurora' ); ?></span>
				<span class="aurora-copyright__v"><a href="<?php echo esc_url( $home ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a></span>
			</div>
			<div class="aurora-copyright__row">
				<span class="aurora-copyright__k"><?php esc_html_e( '链接', 'aurora' ); ?></span>
				<span class="aurora-copyright__v aurora-copyright__link"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $url ); ?></a></span>
			</div>
		</div>
		<p class="aurora-copyright__note">
			<?php
			printf(
				/* translators: 1: site name. */
				esc_html__( '本文由 %s 原创或整理，转载请注明出处并保留原文链接。商业转载请联系作者获得授权。', 'aurora' ),
				esc_html( get_bloginfo( 'name' ) )
			);
			?>
		</p>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Author card + follow
 * ---------------------------------------------------------------------- */

/**
 * Ids the given user follows.
 *
 * @param int $user_id Follower.
 * @return int[]
 */
function aurora_following_ids( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : (int) get_current_user_id();
	if ( ! $user_id ) {
		return array();
	}
	$list = (array) get_user_meta( $user_id, 'aurora_following', true );
	return array_values( array_unique( array_filter( array_map( 'intval', $list ) ) ) );
}

/**
 * How many readers follow an author.
 *
 * @param int $author_id Author user id.
 * @return int
 */
function aurora_follower_count( $author_id ) {
	return (int) get_user_meta( (int) $author_id, 'aurora_followers', true );
}

/**
 * Render the author card shown under an article.
 *
 * @param int $post_id Post id.
 */
function aurora_author_box( $post_id = 0 ) {
	if ( ! get_theme_mod( 'aurora_show_author_box', true ) ) {
		return;
	}

	$post_id   = $post_id ? (int) $post_id : (int) get_the_ID();
	$author_id = (int) get_post_field( 'post_author', $post_id );
	if ( ! $author_id ) {
		return;
	}

	$name      = get_the_author_meta( 'display_name', $author_id );
	$bio       = get_the_author_meta( 'description', $author_id );
	$posts     = (int) count_user_posts( $author_id, 'post', true );
	$followers = aurora_follower_count( $author_id );
	$following = in_array( $author_id, aurora_following_ids(), true );
	$is_self   = get_current_user_id() === $author_id;
	?>
	<div class="aurora-author" data-author="<?php echo esc_attr( $author_id ); ?>">
		<div class="aurora-author__avatar">
			<?php echo get_avatar( $author_id, 96, '', '', array( 'class' => 'aurora-avatar' ) ); ?>
		</div>
		<div class="aurora-author__body">
			<div class="aurora-author__name">
				<?php echo esc_html( $name ); ?>
				<?php
				if ( function_exists( 'aurora_vip_badge' ) ) {
					aurora_vip_badge( $author_id );
				}
				?>
			</div>

			<div class="aurora-author__stats">
				<span><?php echo esc_html( number_format_i18n( $posts ) ); ?><?php esc_html_e( ' 篇文章', 'aurora' ); ?></span>
				<span class="aurora-author__followers" data-count="<?php echo esc_attr( $followers ); ?>" data-suffix="<?php esc_attr_e( ' 位关注', 'aurora' ); ?>">
					<?php echo esc_html( number_format_i18n( $followers ) ); ?><?php esc_html_e( ' 位关注', 'aurora' ); ?>
				</span>
			</div>

			<?php if ( $bio ) : ?>
				<p class="aurora-author__bio"><?php echo esc_html( wp_trim_words( $bio, 40 ) ); ?></p>
			<?php endif; ?>
		</div>

		<div class="aurora-author__actions">
			<?php if ( $is_self ) : ?>
				<a class="aurora-btn aurora-btn--ghost" href="<?php echo esc_url( get_edit_user_link( $author_id ) ); ?>"><?php esc_html_e( '编辑资料', 'aurora' ); ?></a>
			<?php elseif ( is_user_logged_in() ) : ?>
				<button type="button" class="aurora-btn aurora-follow <?php echo $following ? 'is-active' : ''; ?>"
					data-author="<?php echo esc_attr( $author_id ); ?>"
					data-on="<?php esc_attr_e( '已关注', 'aurora' ); ?>"
					data-off="<?php esc_attr_e( '关注', 'aurora' ); ?>">
					<?php echo $following ? esc_html__( '已关注', 'aurora' ) : esc_html__( '关注', 'aurora' ); ?>
				</button>
			<?php else : ?>
				<a class="aurora-btn aurora-need-login" href="#"><?php esc_html_e( '关注', 'aurora' ); ?></a>
			<?php endif; ?>

			<a class="aurora-btn aurora-btn--ghost" href="<?php echo esc_url( get_author_posts_url( $author_id ) ); ?>"><?php esc_html_e( '全部文章', 'aurora' ); ?></a>
		</div>
	</div>
	<?php
}

/**
 * Toggle following an author. One relationship per reader, stored both ways
 * so a profile can show followers without scanning every user.
 */
function aurora_ajax_follow() {
	aurora_check_nonce();
	aurora_rate_json_guard( 'follow' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( '请先登录。', 'aurora' ) ), 401 );
	}

	$author  = isset( $_POST['author'] ) ? (int) $_POST['author'] : 0;
	$me      = get_current_user_id();

	if ( ! $author || $author === $me || ! get_userdata( $author ) ) {
		wp_send_json_error( array( 'message' => __( '无法关注该作者。', 'aurora' ) ), 400 );
	}

	$list     = aurora_following_ids( $me );
	$following = in_array( $author, $list, true );
	$count    = aurora_follower_count( $author );

	if ( $following ) {
		$list  = array_values( array_diff( $list, array( $author ) ) );
		$count = max( 0, $count - 1 );
	} else {
		$list[] = $author;
		$count++;
	}

	update_user_meta( $me, 'aurora_following', array_values( array_unique( $list ) ) );
	update_user_meta( $author, 'aurora_followers', $count );

	do_action( 'aurora_author_followed', $author, $me, ! $following );

	wp_send_json_success(
		array(
			'following' => ! $following,
			'count'     => $count,
			'message'   => $following ? __( '已取消关注', 'aurora' ) : __( '已关注，TA 的新文章会出现在你的动态里', 'aurora' ),
		)
	);
}
add_action( 'wp_ajax_aurora_follow', 'aurora_ajax_follow' );

/* -------------------------------------------------------------------------
 * Home category chips
 * ---------------------------------------------------------------------- */

/**
 * A pill row of the busiest categories, the way magazine themes open a home
 * page. Counts are shown so the row also works as an index.
 *
 * @param int $limit How many chips.
 */
function aurora_category_chips( $limit = 8 ) {
	$cats = get_categories(
		array(
			'orderby'    => 'count',
			'order'      => 'DESC',
			'hide_empty' => true,
			'number'     => max( 1, (int) $limit ),
		)
	);

	if ( count( $cats ) < 2 ) {
		return;
	}
	?>
	<nav class="aurora-chips" aria-label="<?php esc_attr_e( '分类导航', 'aurora' ); ?>">
		<a class="aurora-chip <?php echo is_front_page() ? 'is-active' : ''; ?>" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( '全部', 'aurora' ); ?></a>
		<?php foreach ( $cats as $aurora_cat ) : ?>
			<a class="aurora-chip <?php echo is_category( $aurora_cat->term_id ) ? 'is-active' : ''; ?>"
				href="<?php echo esc_url( get_category_link( $aurora_cat ) ); ?>">
				<?php echo esc_html( $aurora_cat->name ); ?>
				<span class="aurora-chip__count"><?php echo esc_html( number_format_i18n( $aurora_cat->count ) ); ?></span>
			</a>
		<?php endforeach; ?>
	</nav>
	<?php
}

/* -------------------------------------------------------------------------
 * "Hot" flag
 * ---------------------------------------------------------------------- */

/**
 * Ids of the most read posts, used for the 热门 flag.
 *
 * A flat read threshold looked wrong on a small site: with seven posts and a
 * 100-view floor, six of them were "hot". Rank against the site instead, so
 * the flag always means something at any traffic level.
 *
 * @param int $limit How many ids.
 * @return int[]
 */
function aurora_hot_ids( $limit = 0 ) {
	$limit = $limit ? (int) $limit : (int) apply_filters( 'aurora_hot_count', get_theme_mod( 'aurora_hot_count', 5 ) );
	if ( $limit < 1 ) {
		return array();
	}

	$cache_key = 'hot_' . $limit;
	$cached    = wp_cache_get( $cache_key, 'aurora' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$ids = get_posts(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'fields'              => 'ids',
			'meta_key'            => 'aurora_views',
			'orderby'             => 'meta_value_num',
			'order'               => 'DESC',
		)
	);

	$ids = array_map( 'intval', (array) $ids );
	wp_cache_set( $cache_key, $ids, 'aurora', 5 * MINUTE_IN_SECONDS );

	return $ids;
}

/**
 * Whether a post is among the most read of the site.
 *
 * @param int $post_id Post id.
 * @return bool
 */
function aurora_is_hot( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
	if ( ! $post_id ) {
		return false;
	}

	// A brand new post with no reads yet should not wear the flag.
	if ( (int) get_post_meta( $post_id, 'aurora_views', true ) < 1 ) {
		return false;
	}

	return in_array( $post_id, aurora_hot_ids(), true );
}
