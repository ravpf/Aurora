<?php
/**
 * Message centre backend: paging, filters and marking things read.
 *
 * The header bell is a preview — it shows a handful of recent items. This is
 * the full list, where a user can go back through everything, page by page,
 * and read what they missed.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rows per page.
 *
 * @return int
 */
function aurora_inbox_per_page() {
	return (int) apply_filters( 'aurora_inbox_per_page', 15 );
}

/**
 * URL of the message centre page (created on demand if the theme is switched
 * in without the installer having run).
 *
 * @return string
 */
function aurora_inbox_url() {
	$page = get_page_by_path( 'messages' );

	if ( ! $page ) {
		return admin_url( 'admin-ajax.php' );
	}

	return get_permalink( $page );
}

/**
 * The link a notification points at, falling back to the centre itself.
 *
 * @param array $row Notification row.
 * @return string
 */
function aurora_inbox_row_link( $row ) {
	return ! empty( $row['link'] ) ? $row['link'] : aurora_inbox_url();
}

/**
 * Mark one row read (AJAX). Guests get a 401, not a silent success.
 */
function aurora_ajax_inbox_read() {
	aurora_check_nonce();

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( '请先登录。', 'aurora' ) ), 401 );
	}

	$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

	// aurora_notify_mark_read() returns a count, not a WP_Error: it silently
	// skips rows owned by somebody else. A zero for a specific id therefore
	// means "not yours or not there", which deserves a 404 rather than a
	// cheerful success.
	$marked = (int) aurora_notify_mark_read( get_current_user_id(), $id );

	if ( $id && 0 === $marked ) {
		wp_send_json_error( array( 'message' => __( '这条消息不在了。', 'aurora' ) ), 404 );
	}

	wp_send_json_success(
		array(
			'message' => $id ? __( '已标记为已读。', 'aurora' ) : __( '全部标为已读。', 'aurora' ),
			'unread'  => aurora_notify_unread(),
		)
	);
}
add_action( 'wp_ajax_aurora_inbox_read', 'aurora_ajax_inbox_read' );
add_action( 'wp_ajax_nopriv_aurora_inbox_read', 'aurora_ajax_inbox_read' );

/**
 * Page links for the centre.
 *
 * @param int $current Current page.
 * @param int $total   Total pages.
 * @return string
 */
function aurora_inbox_pager( $current, $total ) {
	if ( $total < 2 ) {
		return '';
	}

	$unread = isset( $_GET['filter'] ) && 'unread' === $_GET['filter'];
	$base   = $unread ? add_query_arg( 'filter', 'unread', aurora_inbox_url() ) : aurora_inbox_url();

	$out = '<nav class="aurora-pager" aria-label="' . esc_attr__( '消息分页', 'aurora' ) . '">';

	if ( $current > 1 ) {
		$out .= sprintf(
			'<a class="aurora-pager__step" href="%s">%s</a>',
			esc_url( add_query_arg( 'mpage', $current - 1, $base ) ),
			esc_html__( '上一页', 'aurora' )
		);
	}

	$from = max( 1, $current - 2 );
	$to   = min( $total, $from + 4 );
	$from = max( 1, $to - 4 );

	for ( $i = $from; $i <= $to; $i++ ) {
		$out .= $i === $current
			? sprintf( '<span class="aurora-pager__num is-current" aria-current="page">%d</span>', $i )
			: sprintf( '<a class="aurora-pager__num" href="%s">%d</a>', esc_url( add_query_arg( 'mpage', $i, $base ) ), $i );
	}

	if ( $current < $total ) {
		$out .= sprintf(
			'<a class="aurora-pager__step" href="%s">%s</a>',
			esc_url( add_query_arg( 'mpage', $current + 1, $base ) ),
			esc_html__( '下一页', 'aurora' )
		);
	}

	return $out . '</nav>';
}
