<?php
/**
 * Front-end editor.
 *
 * The block editor is built for site builders, not for someone who wants to
 * type a post. This is the writing surface instead: a single text column with a
 * formatting toolbar, a side panel for the boring parts, autosave, and one
 * button that either saves a draft or sends the piece to review.
 *
 * Content is stored as ordinary post HTML (the toolbar only ever inserts tags
 * wp_kses_post accepts), so nothing downstream has to know this editor exists.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * URL of the writing page.
 *
 * @return string
 */
function aurora_editor_page_url() {
	$page = (int) get_option( 'aurora_editor_page_id' );

	if ( $page && 'publish' === get_post_status( $page ) ) {
		return get_permalink( $page );
	}

	$found = get_posts(
		array(
			'post_type'      => 'page',
			'posts_per_page' => 1,
			'post_status'    => 'publish',
			'meta_key'       => '_wp_page_template',
			'meta_value'     => 'page-templates/write.php',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	return $found ? get_permalink( (int) $found[0] ) : home_url( '/' );
}

/**
 * The post the current reader is editing, if any.
 *
 * @return WP_Post|null
 */
function aurora_editor_current_post() {
	$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

	if ( ! $id ) {
		return null;
	}

	$post = get_post( $id );

	if ( ! $post || 'post' !== $post->post_type ) {
		return null;
	}

	if ( ! aurora_editor_can_edit( $post ) ) {
		return null;
	}

	return $post;
}

/**
 * May this reader edit that post from the front end?
 *
 * @param WP_Post $post Post.
 * @return bool
 */
function aurora_editor_can_edit( $post ) {
	if ( ! is_user_logged_in() ) {
		return false;
	}

	$user_id = get_current_user_id();

	if ( (int) $post->post_author === $user_id && in_array( $post->post_status, array( 'draft', 'pending', 'publish' ), true ) ) {
		return true;
	}

	return user_can( $user_id, 'edit_post', $post->ID );
}

/**
 * Minimum characters for a piece to be sendable for review.
 *
 * @param string $content HTML.
 * @return int
 */
function aurora_editor_length( $content ) {
	return mb_strlen( trim( wp_strip_all_tags( (string) $content ) ) );
}

/**
 * Save (create or update) a draft. Shared by the save and submit endpoints so
 * both go through exactly one write path.
 *
 * @param int    $post_id Existing post or 0.
 * @param string $title   Title.
 * @param string $content Content.
 * @param int    $cat     Category id.
 * @param string $tags    Comma separated tags.
 * @param string $status  Target status.
 * @return int|WP_Error
 */
function aurora_editor_persist( $post_id, $title, $content, $cat, $tags, $status ) {
	$post_id = (int) $post_id;
	$user_id = get_current_user_id();

	if ( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || ! aurora_editor_can_edit( $post ) ) {
			return new WP_Error( 'forbidden', __( '无权编辑这篇内容。', 'aurora' ) );
		}

		// A published piece keeps its status; only drafts move through review.
		if ( 'publish' === $post->post_status ) {
			$status = 'publish';
		}
	}

	$args = array(
		'post_title'    => $title,
		'post_content'  => $content,
		'post_status'   => $status,
		'post_type'     => 'post',
		'post_category' => $cat ? array( $cat ) : array(),
	);

	if ( $post_id ) {
		$args['ID'] = $post_id;
		$result     = wp_update_post( $args, true );
	} else {
		$args['post_author'] = $user_id;
		$result              = wp_insert_post( $args, true );
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( $tags ) {
		$list = array_filter( array_map( 'trim', explode( ',', $tags ) ) );
		wp_set_post_tags( $result, array_slice( $list, 0, 8 ), false );
	}

	update_post_meta( $result, '_aurora_submitted', 1 );
	update_post_meta( $result, '_aurora_editor_saved', current_time( 'mysql' ) );

	return (int) $result;
}

/**
 * AJAX: save a draft.
 */
function aurora_ajax_editor_save() {
	aurora_check_nonce();

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( '请先登录。', 'aurora' ) ), 401 );
	}

	$gate = aurora_rate_guard( 'editor' );
	if ( is_wp_error( $gate ) ) {
		wp_send_json_error( array( 'message' => $gate->get_error_message() ), 429 );
	}

	$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
	$content = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';
	$cat     = isset( $_POST['cat'] ) ? (int) $_POST['cat'] : 0;
	$tags    = isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '';
	$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

	// An empty autosave would litter the drafts list; refuse silently-ish.
	if ( '' === trim( $title ) && '' === trim( wp_strip_all_tags( $content ) ) ) {
		wp_send_json_error( array( 'message' => __( '还没有内容可保存。', 'aurora' ) ), 400 );
	}

	$saved = aurora_editor_persist( $id, $title, $content, $cat, $tags, 'draft' );

	if ( is_wp_error( $saved ) ) {
		wp_send_json_error( array( 'message' => $saved->get_error_message() ), 400 );
	}

	wp_send_json_success(
		array(
			'id'       => $saved,
			'status'   => get_post_status( $saved ),
			'saved_at' => current_time( 'H:i:s' ),
			'link'     => get_permalink( $saved ),
			'message'  => __( '已保存草稿', 'aurora' ),
		)
	);
}
add_action( 'wp_ajax_aurora_editor_save', 'aurora_ajax_editor_save' );

/**
 * AJAX: send to review.
 */
function aurora_ajax_editor_submit() {
	aurora_check_nonce();

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( '请先登录。', 'aurora' ) ), 401 );
	}

	$can = aurora_submit_can();

	if ( is_wp_error( $can ) ) {
		wp_send_json_error( array( 'message' => $can->get_error_message() ), 403 );
	}

	$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
	$content = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';
	$cat     = isset( $_POST['cat'] ) ? (int) $_POST['cat'] : 0;
	$tags    = isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '';
	$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

	if ( '' === $title ) {
		wp_send_json_error( array( 'message' => __( '还需要一个标题。', 'aurora' ) ), 400 );
	}

	if ( aurora_editor_length( $content ) < 120 ) {
		wp_send_json_error( array( 'message' => __( '正文太短，至少 120 个字。', 'aurora' ) ), 400 );
	}

	$gate = aurora_rate_guard( 'post' );
	if ( is_wp_error( $gate ) ) {
		wp_send_json_error( array( 'message' => $gate->get_error_message() ), 429 );
	}

	$saved = aurora_editor_persist( $id, $title, $content, $cat, $tags, aurora_submit_status() );

	if ( is_wp_error( $saved ) ) {
		wp_send_json_error( array( 'message' => $saved->get_error_message() ), 400 );
	}

	$status = get_post_status( $saved );

	aurora_submit_editor_notice( $saved, $title, get_current_user_id(), false );

	/**
	 * Fires after a front-end editor submission.
	 *
	 * @param int    $post_id Post.
	 * @param int    $user_id Author.
	 * @param string $status  New status.
	 */
	do_action( 'aurora_post_submitted', $saved, get_current_user_id(), $status );

	wp_send_json_success(
		array(
			'id'      => $saved,
			'status'  => $status,
			'message' => 'publish' === $status
				? __( '已发布。', 'aurora' )
				: __( '已提交，等待审核。', 'aurora' ),
			'link'    => get_permalink( $saved ),
		)
	);
}
add_action( 'wp_ajax_aurora_editor_submit', 'aurora_ajax_editor_submit' );

/**
 * AJAX: insert an image into the draft.
 *
 * Uses the same upload path (and therefore the same size/type gates) as the
 * media library, and returns the URL the editor inserts.
 */
function aurora_ajax_editor_upload() {
	aurora_check_nonce();

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( '请先登录。', 'aurora' ) ), 401 );
	}

	if ( ! aurora_user_can( 'upload' ) ) {
		wp_send_json_error( array( 'message' => __( '当前账号没有上传权限。', 'aurora' ) ), 403 );
	}

	if ( empty( $_FILES['file']['name'] ) ) {
		wp_send_json_error( array( 'message' => __( '没有收到文件。', 'aurora' ) ), 400 );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$att = media_handle_upload( 'file', 0 );

	if ( is_wp_error( $att ) ) {
		wp_send_json_error( array( 'message' => $att->get_error_message() ), 400 );
	}

	wp_send_json_success(
		array(
			'id'    => (int) $att,
			'url'   => wp_get_attachment_url( $att ),
			'title' => get_the_title( $att ),
		)
	);
}
add_action( 'wp_ajax_aurora_editor_upload', 'aurora_ajax_editor_upload' );
add_action(
	'wp_ajax_nopriv_aurora_editor_upload',
	function () {
		wp_send_json_error( array( 'message' => __( '请先登录。', 'aurora' ) ), 401 );
	}
);

/**
 * The reader's drafts, newest first, for the editor sidebar.
 *
 * @param int $limit How many.
 * @return WP_Post[]
 */
function aurora_editor_recent_drafts( $limit = 5 ) {
	if ( ! is_user_logged_in() ) {
		return array();
	}

	return get_posts(
		array(
			'post_type'      => 'post',
			'author'         => get_current_user_id(),
			'post_status'    => array( 'draft', 'pending' ),
			'posts_per_page' => (int) $limit,
			'no_found_rows'  => true,
		)
	);
}
