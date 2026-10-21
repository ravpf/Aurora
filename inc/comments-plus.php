<?php
/**
 * Comment enhancements.
 *
 * Borrowed in spirit from the community themes studied for this round (Zibll,
 * 酱茄 and the magazine themes): an emoji picker, honest floor numbers, a
 * reader level badge next to the name, and a newest/oldest toggle.
 *
 * IP 归属地 is deliberately not faked: it needs a geo database (MaxMind or a
 * CN range table), and inventing a province from an IP prefix would print
 * wrong information. The hook points are ready for the day a data file lands.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * The emoji offered under the comment box. Plain characters, no image assets.
 *
 * @return string[]
 */
function aurora_comment_emoji() {
	return apply_filters(
		'aurora_comment_emoji',
		array( '😀', '😄', '😂', '🤣', '😊', '😍', '🤔', '😅', '😭', '😡', '👍', '👎', '🙏', '🎉', '🔥', '❤️', '💯', '✅', '👀', '🤝', '😴', '🤯', '🥳', '🙌' )
	);
}

/**
 * Floor number for a top level comment.
 *
 * Nested replies are not numbered: the floor belongs to the conversation, not
 * to each answer inside it. The counter is seeded from the comment page so
 * page two starts at 21 instead of 1.
 *
 * @param WP_Comment $comment Comment.
 * @param int        $depth   Depth of the comment.
 * @return int
 */
function aurora_comment_floor( $comment, $depth ) {
	static $floor = null;

	if ( 1 !== (int) $depth ) {
		return 0;
	}

	if ( null === $floor ) {
		$per   = (int) get_option( 'comments_per_page', 50 );
		$page  = max( 1, (int) get_query_var( 'cpage' ) );
		$floor = ( $page - 1 ) * max( 1, $per );
	}

	$floor++;

	return $floor;
}

/**
 * Reader level badge, shown next to the name for signed in commenters.
 *
 * Reuses the level system's own badge so a level looks the same in the
 * comment list, the user centre and the author card.
 *
 * @param int $user_id Comment author user id.
 */
function aurora_comment_level_badge( $user_id ) {
	if ( ! $user_id || ! function_exists( 'aurora_level_badge' ) ) {
		return;
	}

	$badge = aurora_level_badge( (int) $user_id, true );

	if ( $badge ) {
		echo '<span class="aurora-comment__level">' . wp_kses_post( $badge ) . '</span>';
	}
}

/* -------------------------------------------------------------------------
 * Sort toggle
 * ---------------------------------------------------------------------- */

/**
 * Current comment order.
 *
 * @return string old|new
 */
function aurora_comment_sort() {
	$sort = isset( $_GET['csort'] ) ? sanitize_key( wp_unslash( $_GET['csort'] ) ) : '';
	return 'new' === $sort ? 'new' : 'old';
}

/**
 * Apply the chosen order to the comment query.
 *
 * @param array $args Query args.
 * @return array
 */
function aurora_comment_sort_args( $args ) {
	$args['order'] = 'new' === aurora_comment_sort() ? 'DESC' : 'ASC';
	return $args;
}
add_filter( 'comments_template_query_args', 'aurora_comment_sort_args' );

/**
 * Render the sort row above the list.
 */
function aurora_comment_sort_ui() {
	if ( ! get_comments_number() ) {
		return;
	}

	$current = aurora_comment_sort();
	$base    = get_permalink();

	printf(
		'<div class="aurora-comment-sort"><span>%s</span><a class="%s" href="%s">%s</a><a class="%s" href="%s">%s</a></div>',
		esc_html__( '排序', 'aurora' ),
		'old' === $current ? 'is-active' : '',
		esc_url( add_query_arg( 'csort', 'old', $base ) . '#comments' ),
		esc_html__( '最早在前', 'aurora' ),
		'new' === $current ? 'is-active' : '',
		esc_url( add_query_arg( 'csort', 'new', $base ) . '#comments' ),
		esc_html__( '最新在前', 'aurora' )
	);
}

/* -------------------------------------------------------------------------
 * Emoji picker
 * ---------------------------------------------------------------------- */

/**
 * The picker markup, ready to drop in front of the textarea.
 *
 * @return string
 */
function aurora_comment_emoji_toolbar() {
	$buttons = '';
	foreach ( aurora_comment_emoji() as $emoji ) {
		$buttons .= '<button type="button" class="aurora-emoji__item" data-emoji="' . esc_attr( $emoji ) . '">' . esc_html( $emoji ) . '</button>';
	}

	return '<div class="aurora-emoji">'
		. '<button type="button" class="aurora-emoji__toggle" aria-expanded="false">'
		. aurora_icon( 'heart', 15 )
		. '<span>' . esc_html__( '表情', 'aurora' ) . '</span>'
		. '</button>'
		. '<div class="aurora-emoji__panel" hidden>' . $buttons . '</div>'
		. '</div>';
}

/**
 * Add the picker to the default form, for any caller that does not build its
 * own comment_field. The theme's own form passes one explicitly (see
 * comments.php) because it also controls the label markup, so the same helper
 * is used there instead of relying on this filter.
 *
 * @param array $defaults Comment form defaults.
 * @return array
 */
function aurora_comment_emoji_form( $defaults ) {
	if ( false !== strpos( $defaults['comment_field'], 'aurora-emoji' ) ) {
		return $defaults;
	}

	$defaults['comment_field'] = aurora_comment_emoji_toolbar() . $defaults['comment_field'];

	return $defaults;
}
add_filter( 'comment_form_defaults', 'aurora_comment_emoji_form' );
