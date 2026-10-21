<?php
/**
 * Article rail: the table of contents and the sidebar a single post gets.
 *
 * The theme has always shipped a "显示文章目录" switch that nothing consumed, and
 * a single post was forced to one full-width column — which on a 1320px container
 * means a line of about 70 CJK characters, well past comfortable reading. The rail
 * gives the extra width a job (contents + widget cards) and keeps the prose narrow.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Headings in a post that belong in the contents.
 *
 * @param int $post_id Post ID.
 * @return array List of level, text and anchor.
 */
function aurora_toc_items( $post_id = null ) {
	$post_id = $post_id ? (int) $post_id : get_the_ID();
	$post    = get_post( $post_id );

	if ( ! $post ) {
		return array();
	}

	$content = apply_filters( 'aurora_toc_content', $post->post_content, $post );

	if ( false === stripos( $content, '<h2' ) && false === stripos( $content, '<h3' ) ) {
		return array();
	}

	preg_match_all( '/<h([23])\b[^>]*>(.*?)<\/h\1>/is', $content, $matches, PREG_SET_ORDER );

	$items = array();

	foreach ( $matches as $index => $match ) {
		$text = trim( wp_strip_all_tags( $match[2] ) );

		if ( '' === $text ) {
			continue;
		}

		$items[] = array(
			'level'  => (int) $match[1],
			'text'   => $text,
			'anchor' => aurora_toc_anchor( $index ),
		);
	}

	if ( ! $items ) {
		return $items;
	}

	$min = min( array_column( $items, 'level' ) );

	foreach ( $items as $index => $item ) {
		$items[ $index ]['level'] = ( (int) $item['level'] === (int) $min ) ? 2 : 3;
	}

	return $items;
}

/**
 * Anchor for the nth heading of a post.
 *
 * Index-based on purpose: it is stable for a given revision of the content, and
 * the same counter drives the id injection below, so the two can never disagree.
 *
 * @param int $index Zero-based heading index.
 * @return string
 */
function aurora_toc_anchor( $index ) {
	return 'aurora-toc-' . ( (int) $index + 1 );
}

/**
 * Give the headings those anchors, in the same order the contents counted them.
 *
 * @param string $content Post content.
 * @return string
 */
function aurora_inject_heading_ids( $content ) {
	if ( ! is_singular( 'post' ) || is_admin() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	if ( ! get_theme_mod( 'aurora_show_toc', true ) ) {
		return $content;
	}

	$index = 0;

	return preg_replace_callback(
		'/<h([23])\b([^>]*)>/i',
		function ( $match ) use ( &$index ) {
			$anchor = aurora_toc_anchor( $index );
			$index++;

			// Never clobber an id the author set by hand.
			if ( preg_match( '/\sid\s*=/i', $match[2] ) ) {
				return $match[0];
			}

			return '<h' . $match[1] . $match[2] . ' id="' . esc_attr( $anchor ) . '">';
		},
		$content
	);
}
add_filter( 'the_content', 'aurora_inject_heading_ids', 9 );

/**
 * Does this view get a rail?
 *
 * A post gets one when there are contents to show or widgets to show — otherwise
 * a short post with an empty sidebar reads better full width.
 *
 * @return bool
 */
function aurora_rail_needed() {
	if ( ! is_singular( 'post' ) ) {
		return false;
	}

	if ( aurora_wants_toc() ) {
		return true;
	}

	return is_active_sidebar( 'sidebar-main' );
}

/**
 * Is the contents card wanted on this view?
 *
 * @return bool
 */
function aurora_wants_toc() {
	if ( ! is_singular( 'post' ) || ! get_theme_mod( 'aurora_show_toc', true ) ) {
		return false;
	}

	return count( aurora_toc_items() ) >= 2;
}

/**
 * The contents card.
 *
 * Also available as a widget so the owner can place it deliberately.
 *
 * @param string $title Card heading.
 */
function aurora_toc_card( $title = '' ) {
	$items = aurora_toc_items();

	if ( count( $items ) < 2 ) {
		return;
	}

	$title = $title ? $title : __( '本文目录', 'aurora' );

	echo '<section class="aurora-panel aurora-toc">';
	echo '<h3 class="aurora-widget__title">' . esc_html( $title ) . '</h3>';
	echo '<ul class="aurora-toc__list">';

	foreach ( $items as $item ) {
		printf(
			'<li class="aurora-toc__item aurora-toc__item--h%1$d"><a href="#%2$s">%3$s</a></li>',
			(int) $item['level'],
			esc_attr( $item['anchor'] ),
			esc_html( $item['text'] )
		);
	}

	echo '</ul></section>';
}

/**
 * Render the whole rail for a single post.
 */
function aurora_article_rail() {
	if ( ! aurora_rail_needed() ) {
		return;
	}

	echo '<aside id="aurora-rail" class="aurora-sidebar aurora-rail">';

	if ( aurora_wants_toc() ) {
		aurora_toc_card();
	}

	if ( is_active_sidebar( 'sidebar-main' ) ) {
		dynamic_sidebar( 'sidebar-main' );
	}

	echo '</aside>';
}

/**
 * A contents widget, for owners who want it somewhere specific.
 */
class Aurora_Toc_Widget extends WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'aurora_toc', __( 'Aurora · 文章目录', 'aurora' ), array(
			'description' => __( '当前文章的标题目录，自动生成并跳转。', 'aurora' ),
		) );
	}

	/**
	 * Front-end output.
	 *
	 * @param array $args     Sidebar args.
	 * @param array $instance Widget settings.
	 */
	public function widget( $args, $instance ) {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		aurora_toc_card( ! empty( $instance['title'] ) ? $instance['title'] : __( '本文目录', 'aurora' ) );
	}

	/**
	 * Admin form.
	 *
	 * @param array $instance Widget settings.
	 */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : __( '本文目录', 'aurora' );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( '标题', 'aurora' ); ?></label>
			<input class="widefat" type="text" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" value="<?php echo esc_attr( $title ); ?>">
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
		return array( 'title' => sanitize_text_field( $new_instance['title'] ?? '' ) );
	}
}
