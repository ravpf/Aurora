<?php
/**
 * Widget areas.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register sidebars.
 */
function aurora_register_sidebars() {
	register_sidebar( array(
		'name'          => __( '页脚区域', 'aurora' ),
		'id'            => 'footer-1',
		'description'   => __( '显示在页脚品牌区下方。', 'aurora' ),
		'before_widget' => '<div id="%1$s" class="aurora-widget %2$s">',
		'after_widget'  => '</div>',
		'before_title'  => '<h4>',
		'after_title'   => '</h4>',
	) );

	register_sidebar( array(
		'name'          => __( '主侧边栏', 'aurora' ),
		'id'            => 'sidebar-main',
		'description'   => __( '文章与列表页右侧的小工具区域。', 'aurora' ),
		'before_widget' => '<section id="%1$s" class="aurora-panel aurora-widget %2$s">',
		'after_widget'  => '</section>',
		'before_title'  => '<h3 class="aurora-widget__title">',
		'after_title'   => '</h3>',
	) );

	register_sidebar( array(
		'name'          => __( '用户中心侧栏', 'aurora' ),
		'id'            => 'sidebar-user',
		'description'   => __( '用户中心页面使用的小工具区域。', 'aurora' ),
		'before_widget' => '<section id="%1$s" class="aurora-panel aurora-widget %2$s">',
		'after_widget'  => '</section>',
		'before_title'  => '<h3 class="aurora-widget__title">',
		'after_title'   => '</h3>',
	) );

	register_sidebar( array(
		'name'          => __( '首页顶栏', 'aurora' ),
		'id'            => 'front-top',
		'description'   => __( '仅显示在首页列表上方的区域。', 'aurora' ),
		'before_widget' => '<section id="%1$s" class="aurora-panel aurora-widget %2$s">',
		'after_widget'  => '</section>',
		'before_title'  => '<h3 class="aurora-widget__title">',
		'after_title'   => '</h3>',
	) );
}
add_action( 'widgets_init', 'aurora_register_sidebars' );

/**
 * Popular posts widget (by view counter).
 */
class Aurora_Popular_Widget extends WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'aurora_popular', __( 'Aurora · 热门文章', 'aurora' ), array(
			'description' => __( '按浏览量排序的文章列表。', 'aurora' ),
		) );
	}

	/**
	 * Front-end output.
	 *
	 * @param array $args     Sidebar args.
	 * @param array $instance Widget settings.
	 */
	public function widget( $args, $instance ) {
		$title  = ! empty( $instance['title'] ) ? $instance['title'] : __( '热门文章', 'aurora' );
		$number = ! empty( $instance['number'] ) ? (int) $instance['number'] : 6;

		$query = new WP_Query( array(
			'post_type'           => 'post',
			'posts_per_page'      => $number,
			'meta_key'            => 'aurora_views',
			'orderby'             => 'meta_value_num',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'post_status'         => 'publish',
		) );

		if ( ! $query->have_posts() ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<ul class="aurora-ranklist">';
		while ( $query->have_posts() ) {
			$query->the_post();
			printf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( get_permalink() ),
				esc_html( get_the_title() )
			);
		}
		echo '</ul>';
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_reset_postdata();
	}

	/**
	 * Admin form.
	 *
	 * @param array $instance Widget settings.
	 */
	public function form( $instance ) {
		$title  = isset( $instance['title'] ) ? $instance['title'] : __( '热门文章', 'aurora' );
		$number = isset( $instance['number'] ) ? (int) $instance['number'] : 6;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( '标题', 'aurora' ); ?></label>
			<input class="widefat" type="text" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>"><?php esc_html_e( '数量', 'aurora' ); ?></label>
			<input class="tiny-text" type="number" min="1" max="20" id="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'number' ) ); ?>" value="<?php echo esc_attr( $number ); ?>">
		</p>
		<?php
	}

	/**
	 * Persist settings.
	 *
	 * @param array $new_instance New values.
	 * @param array $old_instance Old values.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title'  => sanitize_text_field( $new_instance['title'] ?? '' ),
			'number' => max( 1, min( 20, (int) ( $new_instance['number'] ?? 6 ) ) ),
		);
	}
}

/**
 * Announcements widget.
 */
class Aurora_Notice_Widget extends WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'aurora_notice_widget', __( 'Aurora · 最新公告', 'aurora' ), array(
			'description' => __( '显示最新发布的公告。', 'aurora' ),
		) );
	}

	/**
	 * Front-end output.
	 *
	 * @param array $args     Sidebar args.
	 * @param array $instance Widget settings.
	 */
	public function widget( $args, $instance ) {
		$query = aurora_get_notices( isset( $instance['number'] ) ? (int) $instance['number'] : 4 );
		if ( ! $query->have_posts() ) {
			return;
		}

		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( '最新公告', 'aurora' );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<ul>';
		while ( $query->have_posts() ) {
			$query->the_post();
			printf(
				'<li><a href="%1$s">%2$s</a> <span style="color:var(--aurora-text-tertiary);font-size:.78rem">%3$s</span></li>',
				esc_url( get_permalink() ),
				esc_html( get_the_title() ),
				esc_html( get_the_date( 'm-d' ) )
			);
		}
		echo '</ul>';
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_reset_postdata();
	}

	/**
	 * Admin form.
	 *
	 * @param array $instance Widget settings.
	 */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : __( '最新公告', 'aurora' );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( '标题', 'aurora' ); ?></label>
			<input class="widefat" type="text" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<?php
	}
}

/**
 * Register the custom widgets.
 */
function aurora_register_widgets() {
	register_widget( 'Aurora_Popular_Widget' );
	register_widget( 'Aurora_Notice_Widget' );

	if ( class_exists( 'Aurora_Toc_Widget' ) ) {
		register_widget( 'Aurora_Toc_Widget' );
	}
}
add_action( 'widgets_init', 'aurora_register_widgets' );


/* -------------------------------------------------------------------------
   Sidebar cards
   ---------------------------------------------------------------------- */

/**
 * Author / site card.
 *
 * The shape every Chinese magazine theme has: face, name, one line of voice,
 * three numbers, and a way to reach the person behind the site.
 */
class Aurora_Author_Widget extends WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'aurora_author', __( 'Aurora · 站长卡片', 'aurora' ), array(
			'description' => __( '头像、简介与站点数据，放在侧边栏顶部。', 'aurora' ),
		) );
	}

	/**
	 * Front-end output.
	 *
	 * @param array $args     Sidebar args.
	 * @param array $instance Widget settings.
	 */
	public function widget( $args, $instance ) {
		$uid      = (int) get_option( 'aurora_site_author_id' );
		$admins   = $uid ? array() : get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		$uid      = $uid ? $uid : (int) ( $admins ? $admins[0] : 0 );
		$user     = $uid ? get_userdata( $uid ) : null;

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['before_title'] . esc_html( ! empty( $instance['title'] ) ? $instance['title'] : __( '关于站长', 'aurora' ) ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<div class="aurora-authorcard">';
		if ( $user ) {
			echo '<div class="aurora-authorcard__face">' . aurora_avatar( $uid, 64 ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div class="aurora-authorcard__name">' . esc_html( $user->display_name ) . '</div>';
			if ( function_exists( 'aurora_vip_badge' ) ) {
				aurora_vip_badge( $uid );
			}
		}

		$bio = ! empty( $instance['bio'] ) ? $instance['bio'] : get_bloginfo( 'description' );
		if ( $bio ) {
			echo '<p class="aurora-authorcard__bio">' . esc_html( $bio ) . '</p>';
		}

		$posts    = wp_count_posts( 'post' );
		$comments = wp_count_comments();
		$users    = count_users();

		echo '<div class="aurora-authorcard__stats">';
		printf( '<span><strong>%s</strong>%s</span>', esc_html( number_format_i18n( (int) $posts->publish ) ), esc_html__( '文章', 'aurora' ) );
		printf( '<span><strong>%s</strong>%s</span>', esc_html( number_format_i18n( (int) $comments->approved ) ), esc_html__( '评论', 'aurora' ) );
		printf( '<span><strong>%s</strong>%s</span>', esc_html( number_format_i18n( (int) $users['total_users'] ) ), esc_html__( '会员', 'aurora' ) );
		echo '</div>';

		$links = array();
		if ( ! empty( $instance['rss'] ) ) {
			$links[] = array( 'rss', __( '订阅', 'aurora' ), ! empty( $instance['rss_url'] ) ? $instance['rss_url'] : get_bloginfo( 'rss2_url' ) );
		}
		if ( ! empty( $instance['link_label'] ) && ! empty( $instance['link_url'] ) ) {
			$links[] = array( 'link', $instance['link_label'], $instance['link_url'] );
		}
		if ( ! empty( $instance['mail'] ) ) {
			$links[] = array( 'comment', __( '联系', 'aurora' ), 'mailto:' . antispambot( get_option( 'admin_email' ) ) );
		}

		if ( $links ) {
			echo '<div class="aurora-authorcard__links">';
			foreach ( $links as $link ) {
				printf(
					'<a class="aurora-btn aurora-btn--sm" href="%s" rel="nofollow">%s%s</a>',
					esc_url( $link[2] ),
					aurora_icon( $link[0], 15 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( $link[1] )
				);
			}
			echo '</div>';
		}

		echo '</div>';
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Admin form.
	 *
	 * @param array $instance Widget settings.
	 */
	public function form( $instance ) {
		$fields = array(
			'title'      => array( __( '标题', 'aurora' ), '' ),
			'bio'        => array( __( '一句话简介', 'aurora' ), '' ),
			'link_label' => array( __( '按钮文字', 'aurora' ), '' ),
			'link_url'   => array( __( '按钮链接', 'aurora' ), '' ),
		);
		foreach ( $fields as $key => $meta ) {
			printf(
				'<p><label for="%s">%s</label><input class="widefat" type="text" id="%s" name="%s" value="%s"></p>',
				esc_attr( $this->get_field_id( $key ) ),
				esc_html( $meta[0] ),
				esc_attr( $this->get_field_id( $key ) ),
				esc_attr( $this->get_field_name( $key ) ),
				esc_attr( $instance[ $key ] ?? '' )
			);
		}
		?>
		<p>
			<input class="checkbox" type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'rss' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'rss' ) ); ?>" <?php checked( ! empty( $instance['rss'] ) ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'rss' ) ); ?>"><?php esc_html_e( '显示订阅按钮', 'aurora' ); ?></label>
		</p>
		<p>
			<input class="checkbox" type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'mail' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'mail' ) ); ?>" <?php checked( ! empty( $instance['mail'] ) ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'mail' ) ); ?>"><?php esc_html_e( '显示联系按钮', 'aurora' ); ?></label>
		</p>
		<?php
	}

	/**
	 * Persist settings.
	 *
	 * @param array $new_instance New values.
	 * @param array $old_instance Old values.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title'      => sanitize_text_field( $new_instance['title'] ?? '' ),
			'bio'        => sanitize_text_field( $new_instance['bio'] ?? '' ),
			'link_label' => sanitize_text_field( $new_instance['link_label'] ?? '' ),
			'link_url'   => esc_url_raw( $new_instance['link_url'] ?? '' ),
			'rss'        => ! empty( $new_instance['rss'] ),
			'mail'       => ! empty( $new_instance['mail'] ),
		);
	}
}

/**
 * Ranked post list with thumbnails — the "热榜" card every local theme ships.
 */
class Aurora_Rank_Widget extends WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'aurora_rank', __( 'Aurora · 阅读榜', 'aurora' ), array(
			'description' => __( '带序号和缩略图的文章榜单，可按浏览量或评论数排序。', 'aurora' ),
		) );
	}

	/**
	 * Front-end output.
	 *
	 * @param array $args     Sidebar args.
	 * @param array $instance Widget settings.
	 */
	public function widget( $args, $instance ) {
		$number = max( 1, min( 20, (int) ( $instance['number'] ?? 6 ) ) );
		$by     = ! empty( $instance['by'] ) ? $instance['by'] : 'views';
		$range  = ! empty( $instance['range'] ) ? (int) $instance['range'] : 0;

		$query_args = array(
			'post_type'           => 'post',
			'posts_per_page'      => $number,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'post_status'         => 'publish',
		);

		if ( $range > 0 ) {
			$query_args['date_query'] = array( array( 'after' => $range . ' days ago' ) );
		}

		if ( 'comments' === $by ) {
			$query_args['orderby'] = 'comment_count';
		} else {
			$query_args['meta_key'] = 'aurora_views';
			$query_args['orderby']  = 'meta_value_num';
		}

		$query = new WP_Query( $query_args );

		if ( ! $query->have_posts() ) {
			return;
		}

		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( '阅读榜', 'aurora' );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<ol class="aurora-rankcard">';

		$i = 0;
		while ( $query->have_posts() ) {
			$query->the_post();
			$i++;

			echo '<li class="aurora-rankcard__item">';
			printf( '<span class="aurora-rankcard__no">%d</span>', $i );
			echo '<a class="aurora-rankcard__body" href="' . esc_url( get_permalink() ) . '">';
			if ( has_post_thumbnail() ) {
				echo '<span class="aurora-rankcard__thumb">';
				aurora_thumbnail( get_the_ID(), 'thumbnail' );
				echo '</span>';
			}
			echo '<span class="aurora-rankcard__info">';
			echo '<span class="aurora-rankcard__title">' . esc_html( get_the_title() ) . '</span>';
			echo '<span class="aurora-rankcard__meta">';
			if ( 'comments' === $by ) {
				printf( '%s %s', esc_html( number_format_i18n( get_comments_number() ) ), esc_html__( '评论', 'aurora' ) );
			} else {
				printf( '%s %s', esc_html( number_format_i18n( aurora_get_views() ) ), esc_html__( '阅读', 'aurora' ) );
			}
			echo '</span>';
			echo '</span>';
			echo '</a>';
			echo '</li>';
		}

		echo '</ol>';
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_reset_postdata();
	}

	/**
	 * Admin form.
	 *
	 * @param array $instance Widget settings.
	 */
	public function form( $instance ) {
		$title  = $instance['title'] ?? __( '阅读榜', 'aurora' );
		$number = (int) ( $instance['number'] ?? 6 );
		$by     = $instance['by'] ?? 'views';
		$range  = (int) ( $instance['range'] ?? 0 );
		?>
		<p><label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( '标题', 'aurora' ); ?></label>
			<input class="widefat" type="text" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" value="<?php echo esc_attr( $title ); ?>"></p>
		<p><label for="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>"><?php esc_html_e( '数量', 'aurora' ); ?></label>
			<input class="tiny-text" type="number" min="1" max="20" id="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'number' ) ); ?>" value="<?php echo esc_attr( $number ); ?>"></p>
		<p><label for="<?php echo esc_attr( $this->get_field_id( 'by' ) ); ?>"><?php esc_html_e( '排序', 'aurora' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'by' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'by' ) ); ?>">
				<option value="views" <?php selected( $by, 'views' ); ?>><?php esc_html_e( '按阅读量', 'aurora' ); ?></option>
				<option value="comments" <?php selected( $by, 'comments' ); ?>><?php esc_html_e( '按评论数', 'aurora' ); ?></option>
			</select></p>
		<p><label for="<?php echo esc_attr( $this->get_field_id( 'range' ) ); ?>"><?php esc_html_e( '仅统计最近 N 天（0 为不限）', 'aurora' ); ?></label>
			<input class="tiny-text" type="number" min="0" max="365" id="<?php echo esc_attr( $this->get_field_id( 'range' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'range' ) ); ?>" value="<?php echo esc_attr( $range ); ?>"></p>
		<?php
	}

	/**
	 * Persist settings.
	 *
	 * @param array $new_instance New values.
	 * @param array $old_instance Old values.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title'  => sanitize_text_field( $new_instance['title'] ?? '' ),
			'number' => max( 1, min( 20, (int) ( $new_instance['number'] ?? 6 ) ) ),
			'by'     => 'comments' === ( $new_instance['by'] ?? '' ) ? 'comments' : 'views',
			'range'  => max( 0, min( 365, (int) ( $new_instance['range'] ?? 0 ) ) ),
		);
	}
}

/**
 * Recent comments with faces.
 */
class Aurora_Comments_Widget extends WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'aurora_comments', __( 'Aurora · 最新评论', 'aurora' ), array(
			'description' => __( '带头像和出处的评论列表。', 'aurora' ),
		) );
	}

	/**
	 * Front-end output.
	 *
	 * @param array $args     Sidebar args.
	 * @param array $instance Widget settings.
	 */
	public function widget( $args, $instance ) {
		$number = max( 1, min( 20, (int) ( $instance['number'] ?? 5 ) ) );

		$comments = get_comments( array(
			'number'      => $number,
			'status'      => 'approve',
			'post_status' => 'publish',
			'type'        => 'comment',
		) );

		// Comments left on test scaffolding are scaffolding too: without this the
		// sidebar reads "被回复的文章" next to a stranger's comment mid-run.
		if ( $comments && function_exists( 'aurora_fixture_meta_keys' ) ) {
			$fixture_keys = aurora_fixture_meta_keys();

			$comments = array_values(
				array_filter(
					$comments,
					function ( $comment ) use ( $fixture_keys ) {
						foreach ( $fixture_keys as $key ) {
							if ( '' !== get_post_meta( $comment->comment_post_ID, $key, true ) ) {
								return false;
							}
						}

						return true;
					}
				)
			);
		}

		if ( ! $comments ) {
			return;
		}

		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( '最新评论', 'aurora' );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<ul class="aurora-commentcard">';

		foreach ( $comments as $comment ) {
			$text = wp_trim_words( wp_strip_all_tags( $comment->comment_content ), 18, '…' );

			echo '<li class="aurora-commentcard__item">';
			$aurora_cuid = (int) $comment->user_id;
			$aurora_face = $aurora_cuid
				? aurora_avatar( $aurora_cuid, 34 )
				: aurora_avatar_svg( $comment->comment_author_email, mb_substr( $comment->comment_author, 0, 1 ), 34 );
			echo '<span class="aurora-commentcard__face">' . $aurora_face . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<span class="aurora-commentcard__body">';
			printf( '<span class="aurora-commentcard__who">%s</span>', esc_html( $comment->comment_author ) );
			printf(
				'<a class="aurora-commentcard__text" href="%s">%s</a>',
				esc_url( get_comment_link( $comment ) ),
				esc_html( $text )
			);
			printf(
				'<span class="aurora-commentcard__on">%s</span>',
				esc_html( get_the_title( $comment->comment_post_ID ) )
			);
			echo '</span>';
			echo '</li>';
		}

		echo '</ul>';
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Admin form.
	 *
	 * @param array $instance Widget settings.
	 */
	public function form( $instance ) {
		?>
		<p><label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( '标题', 'aurora' ); ?></label>
			<input class="widefat" type="text" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" value="<?php echo esc_attr( $instance['title'] ?? __( '最新评论', 'aurora' ) ); ?>"></p>
		<p><label for="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>"><?php esc_html_e( '数量', 'aurora' ); ?></label>
			<input class="tiny-text" type="number" min="1" max="20" id="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'number' ) ); ?>" value="<?php echo esc_attr( (int) ( $instance['number'] ?? 5 ) ); ?>"></p>
		<?php
	}

	/**
	 * Persist settings.
	 *
	 * @param array $new_instance New values.
	 * @param array $old_instance Old values.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title'  => sanitize_text_field( $new_instance['title'] ?? '' ),
			'number' => max( 1, min( 20, (int) ( $new_instance['number'] ?? 5 ) ) ),
		);
	}
}

/**
 * Tag cloud as pills.
 */
class Aurora_Tags_Widget extends WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'aurora_tags', __( 'Aurora · 标签云', 'aurora' ), array(
			'description' => __( '以标签胶囊形式展示热门标签。', 'aurora' ),
		) );
	}

	/**
	 * Front-end output.
	 *
	 * @param array $args     Sidebar args.
	 * @param array $instance Widget settings.
	 */
	public function widget( $args, $instance ) {
		$number = max( 1, min( 60, (int) ( $instance['number'] ?? 20 ) ) );
		$tags   = get_tags( array( 'orderby' => 'count', 'order' => 'DESC', 'number' => $number ) );

		if ( ! $tags ) {
			return;
		}

		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( '热门标签', 'aurora' );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="aurora-tagcloud">';

		foreach ( $tags as $tag ) {
			printf(
				'<a class="aurora-tagcloud__tag" href="%s">%s<span>%s</span></a>',
				esc_url( get_tag_link( $tag ) ),
				esc_html( $tag->name ),
				esc_html( number_format_i18n( $tag->count ) )
			);
		}

		echo '</div>';
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Admin form.
	 *
	 * @param array $instance Widget settings.
	 */
	public function form( $instance ) {
		?>
		<p><label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( '标题', 'aurora' ); ?></label>
			<input class="widefat" type="text" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" value="<?php echo esc_attr( $instance['title'] ?? __( '热门标签', 'aurora' ) ); ?>"></p>
		<p><label for="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>"><?php esc_html_e( '数量', 'aurora' ); ?></label>
			<input class="tiny-text" type="number" min="1" max="60" id="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'number' ) ); ?>" value="<?php echo esc_attr( (int) ( $instance['number'] ?? 20 ) ); ?>"></p>
		<?php
	}

	/**
	 * Persist settings.
	 *
	 * @param array $new_instance New values.
	 * @param array $old_instance Old values.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title'  => sanitize_text_field( $new_instance['title'] ?? '' ),
			'number' => max( 1, min( 60, (int) ( $new_instance['number'] ?? 20 ) ) ),
		);
	}
}

/**
 * Site counters.
 */
class Aurora_Stats_Widget extends WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'aurora_stats', __( 'Aurora · 站点统计', 'aurora' ), array(
			'description' => __( '文章、评论、会员数量与建站天数。', 'aurora' ),
		) );
	}

	/**
	 * Front-end output.
	 *
	 * @param array $args     Sidebar args.
	 * @param array $instance Widget settings.
	 */
	public function widget( $args, $instance ) {
		$posts    = wp_count_posts( 'post' );
		$comments = wp_count_comments();
		$users    = count_users();
		$since    = get_option( 'aurora_site_started' );

		if ( ! $since ) {
			$first = get_posts( array( 'post_type' => 'post', 'numberposts' => 1, 'orderby' => 'date', 'order' => 'ASC', 'post_status' => 'publish' ) );
			$since = $first ? $first[0]->post_date : current_time( 'mysql' );
		}

		$days = max( 1, (int) ceil( ( time() - strtotime( $since ) ) / DAY_IN_SECONDS ) );

		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( '站点统计', 'aurora' );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="aurora-statgrid">';

		$rows = array(
			array( __( '文章', 'aurora' ), (int) $posts->publish ),
			array( __( '评论', 'aurora' ), (int) $comments->approved ),
			array( __( '会员', 'aurora' ), (int) $users['total_users'] ),
			array( __( '运行', 'aurora' ), $days ),
		);

		foreach ( $rows as $row ) {
			printf(
				'<span class="aurora-statgrid__cell"><strong>%s</strong>%s</span>',
				esc_html( number_format_i18n( $row[1] ) ),
				esc_html( $row[0] )
			);
		}

		echo '</div>';
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Admin form.
	 *
	 * @param array $instance Widget settings.
	 */
	public function form( $instance ) {
		?>
		<p><label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( '标题', 'aurora' ); ?></label>
			<input class="widefat" type="text" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" value="<?php echo esc_attr( $instance['title'] ?? __( '站点统计', 'aurora' ) ); ?>"></p>
		<?php
	}

	/**
	 * Persist settings.
	 *
	 * @param array $new_instance New values.
	 * @param array $old_instance Old values.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array( 'title' => sanitize_text_field( $new_instance['title'] ?? '' ) );
	}
}

/**
 * Register the sidebar cards.
 */
function aurora_register_sidebar_cards() {
	register_widget( 'Aurora_Author_Widget' );
	register_widget( 'Aurora_Rank_Widget' );
	register_widget( 'Aurora_Comments_Widget' );
	register_widget( 'Aurora_Tags_Widget' );
	register_widget( 'Aurora_Stats_Widget' );
}
add_action( 'widgets_init', 'aurora_register_sidebar_cards' );

/**
 * Give an unconfigured sidebar a usable set of cards.
 *
 * Only ever fills an empty area: an owner who has arranged their own widgets must
 * not have them rearranged by a theme update.
 */
function aurora_seed_sidebar_widgets() {
	$sidebars = get_option( 'sidebars_widgets', array() );
	$sidebars = is_array( $sidebars ) ? $sidebars : array();

	$current = isset( $sidebars['sidebar-main'] ) ? (array) $sidebars['sidebar-main'] : array();

	// Append, never rewrite: existing widgets keep their place. If one of these
	// cards is already placed, the owner has arranged this area by hand.
	foreach ( $current as $widget_id ) {
		if ( 0 === strpos( (string) $widget_id, 'aurora_' ) ) {
			return;
		}
	}

	$seed = array(
		'aurora_author'   => array( 'title' => __( '关于站长', 'aurora' ), 'rss' => 1, 'mail' => 1 ),
		'aurora_rank'     => array( 'title' => __( '阅读榜', 'aurora' ), 'number' => 5, 'by' => 'views', 'range' => 0 ),
		'aurora_comments' => array( 'title' => __( '最新评论', 'aurora' ), 'number' => 4 ),
		'aurora_tags'     => array( 'title' => __( '热门标签', 'aurora' ), 'number' => 18 ),
		'aurora_stats'    => array( 'title' => __( '站点统计', 'aurora' ) ),
	);

	$ids = array();

	foreach ( $seed as $base => $instance ) {
		$option   = 'widget_' . $base;
		$existing = get_option( $option, array() );

		// Index 1 is reserved by WordPress as the "no instance" template.
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			$numbers = array_filter( array_keys( $existing ), 'is_int' );
			$number  = $numbers ? max( $numbers ) : 2;
		} else {
			$existing = array( '_multiwidget' => 1 );
			$number   = 2;
		}

		$existing[ $number ] = $instance;
		update_option( $option, $existing );

		$ids[] = $base . '-' . $number;
	}

	$sidebars['sidebar-main'] = array_merge( $current, $ids );
	update_option( 'sidebars_widgets', $sidebars );
}
add_action( 'after_switch_theme', 'aurora_seed_sidebar_widgets' );
add_action( 'admin_init', 'aurora_seed_sidebar_widgets' );
