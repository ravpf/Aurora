<?php
/**
 * Q&A: answers, votes and the accepted answer.
 *
 * Answers are comments — not a second content type. That keeps moderation,
 * spam filtering, threading and the existing reply UI working, and it means an
 * answer can be replied to like anything else.
 *
 * Three rules make the model honest:
 *
 * 1. One vote per person per answer, and it can be switched or withdrawn. The
 *    vote is stored as the voter's own comment meta, so two people visiting at
 *    the same time never overwrite each other's choice.
 * 2. You cannot vote for your own answer. Self-upvotes are how a Q&A turns into
 *    a scoreboard.
 * 3. Only the asker (or an editor) can accept, and only one answer per question
 *    can be accepted. Accepting moves the answer to the top of the list.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is this post a question?
 *
 * A board is a question board when its term meta says so; the post simply has to
 * belong to that board. No extra post type, no per-post switch to forget.
 *
 * @param int $post_id Post.
 * @return bool
 */
function aurora_qa_is_question( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : get_the_ID();

	if ( ! $post_id || 'post' !== get_post_type( $post_id ) ) {
		return false;
	}

	$terms = get_the_terms( $post_id, 'aurora_board' );

	if ( ! $terms || is_wp_error( $terms ) ) {
		return false;
	}

	foreach ( $terms as $term ) {
		if ( 'qa' === get_term_meta( $term->term_id, '_board_type', true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Vote tallies for a comment.
 *
 * @param int $comment_id Comment.
 * @return array{up:int,down:int,score:int}
 */
function aurora_qa_votes( $comment_id ) {
	$up   = (int) get_comment_meta( $comment_id, '_qa_up', true );
	$down = (int) get_comment_meta( $comment_id, '_qa_down', true );

	return array(
		'up'    => $up,
		'down'  => $down,
		'score' => $up - $down,
	);
}

/**
 * How a user voted: 1, -1 or 0.
 *
 * @param int $comment_id Comment.
 * @param int $user_id    User.
 * @return int
 */
function aurora_qa_my_vote( $comment_id, $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return 0;
	}

	return (int) get_comment_meta( $comment_id, '_qa_vote_' . $user_id, true );
}

/**
 * Bump a counter atomically.
 *
 * `$wpdb` because there is no atomic "increment a meta value" in the meta API:
 * read-modify-write loses votes whenever two people click at the same moment.
 *
 * @param int    $comment_id Comment.
 * @param string $key        Meta key.
 * @param int    $delta      Amount.
 */
function aurora_qa_bump( $comment_id, $key, $delta ) {
	global $wpdb;

	$comment_id = (int) $comment_id;
	$delta      = (int) $delta;

	if ( ! $comment_id || ! $delta ) {
		return;
	}

	$exists = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->commentmeta} WHERE comment_id = %d AND meta_key = %s LIMIT 1",
			$comment_id,
			$key
		)
	);

	if ( $exists ) {
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->commentmeta} SET meta_value = GREATEST(CAST(meta_value AS SIGNED) + %d, 0) WHERE comment_id = %d AND meta_key = %s",
				$delta,
				$comment_id,
				$key
			)
		);
	} elseif ( $delta > 0 ) {
		add_comment_meta( $comment_id, $key, (string) $delta, true );
	}
}

/**
 * Cast, switch or withdraw a vote.
 *
 * @param int $comment_id Comment.
 * @param int $user_id    Voter.
 * @param int $dir        1 up, -1 down, 0 withdraw.
 * @return array|WP_Error Tallies on success.
 */
function aurora_qa_vote( $comment_id, $user_id, $dir ) {
	$comment_id = (int) $comment_id;
	$user_id    = (int) $user_id;
	$dir        = (int) $dir;

	if ( ! $user_id ) {
		return new WP_Error( 'qa_login', __( '登录后才能投票。', 'aurora' ), array( 'status' => 401 ) );
	}

	if ( ! in_array( $dir, array( 1, -1, 0 ), true ) ) {
		return new WP_Error( 'qa_bad_vote', __( '投票方向无效。', 'aurora' ), array( 'status' => 400 ) );
	}

	$comment = get_comment( $comment_id );

	if ( ! $comment || '1' !== (string) $comment->comment_approved ) {
		return new WP_Error( 'qa_no_comment', __( '回答不存在。', 'aurora' ), array( 'status' => 404 ) );
	}

	if ( ! aurora_qa_is_question( (int) $comment->comment_post_ID ) ) {
		return new WP_Error( 'qa_not_question', __( '这里不是问答，不能投票。', 'aurora' ), array( 'status' => 400 ) );
	}

	if ( (int) $comment->user_id && (int) $comment->user_id === $user_id ) {
		return new WP_Error( 'qa_self_vote', __( '不能给自己的回答投票。', 'aurora' ), array( 'status' => 403 ) );
	}

	$key     = '_qa_vote_' . $user_id;
	$current = (int) get_comment_meta( $comment_id, $key, true );

	if ( $current === $dir ) {
		return new WP_Error( 'qa_same_vote', __( '你已经这样投过了。', 'aurora' ), array( 'status' => 409 ) );
	}

	// 先撤旧票再记新票，两边计数各自加减，账才对得上。
	if ( 1 === $current ) {
		aurora_qa_bump( $comment_id, '_qa_up', -1 );
	} elseif ( -1 === $current ) {
		aurora_qa_bump( $comment_id, '_qa_down', -1 );
	}

	if ( 0 === $dir ) {
		delete_comment_meta( $comment_id, $key );
	} else {
		update_comment_meta( $comment_id, $key, (string) $dir );

		if ( 1 === $dir ) {
			aurora_qa_bump( $comment_id, '_qa_up', 1 );
		} else {
			aurora_qa_bump( $comment_id, '_qa_down', 1 );
		}
	}

	clean_comment_cache( $comment_id );

	do_action( 'aurora_qa_voted', $comment_id, $user_id, $dir );

	$votes = aurora_qa_votes( $comment_id );
	$votes['my'] = $dir;

	return $votes;
}

/**
 * Accepted answer id for a question.
 *
 * @param int $post_id Post.
 * @return int
 */
function aurora_qa_accepted( $post_id ) {
	return (int) get_post_meta( (int) $post_id, '_qa_accepted', true );
}

/**
 * Accept an answer. Only the asker or an editor, only one per question.
 *
 * @param int $post_id    Question.
 * @param int $comment_id Answer.
 * @param int $user_id    Actor.
 * @return true|WP_Error
 */
function aurora_qa_accept( $post_id, $comment_id, $user_id = 0 ) {
	$post_id    = (int) $post_id;
	$comment_id = (int) $comment_id;
	$user_id    = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! aurora_qa_is_question( $post_id ) ) {
		return new WP_Error( 'qa_not_question', __( '这里不是问答。', 'aurora' ), array( 'status' => 400 ) );
	}

	$comment = get_comment( $comment_id );

	if ( ! $comment || (int) $comment->comment_post_ID !== $post_id ) {
		return new WP_Error( 'qa_no_answer', __( '这条回答不属于该问题。', 'aurora' ), array( 'status' => 404 ) );
	}

	$is_asker  = (int) get_post_field( 'post_author', $post_id ) === $user_id;
	$is_editor = user_can( $user_id, 'edit_others_posts' );

	if ( ! $is_asker && ! $is_editor ) {
		return new WP_Error( 'qa_forbidden', __( '只有提问者可以采纳答案。', 'aurora' ), array( 'status' => 403 ) );
	}

	update_post_meta( $post_id, '_qa_accepted', $comment_id );
	update_comment_meta( $comment_id, '_qa_is_accepted', '1' );

	// 取消采纳要连带清掉标记，否则旧回答还挂着"已采纳"。
	$previous = (int) get_post_meta( $post_id, '_qa_previous_accepted', true );
	if ( $previous && $previous !== $comment_id ) {
		delete_comment_meta( $previous, '_qa_is_accepted' );
	}

	update_post_meta( $post_id, '_qa_previous_accepted', $comment_id );
	clean_post_cache( $post_id );

	do_action( 'aurora_qa_accepted', $post_id, $comment_id, $user_id );

	return true;
}

/**
 * Order answers: accepted first, then score, then oldest.
 *
 * @param WP_Comment[] $comments Comments.
 * @return WP_Comment[]
 */
function aurora_qa_sort_comments( $comments ) {
	$comments = array_values( (array) $comments );
	$post_id  = $comments ? (int) $comments[0]->comment_post_ID : 0;
	$accepted = $post_id ? aurora_qa_accepted( $post_id ) : 0;

	usort(
		$comments,
		static function ( $a, $b ) use ( $accepted ) {
			$a_acc = (int) $a->comment_ID === $accepted;
			$b_acc = (int) $b->comment_ID === $accepted;

			if ( $a_acc !== $b_acc ) {
				return $a_acc ? -1 : 1;
			}

			$sa = aurora_qa_votes( $a->comment_ID )['score'];
			$sb = aurora_qa_votes( $b->comment_ID )['score'];

			if ( $sa !== $sb ) {
				return $sb <=> $sa;
			}

			return strcmp( $a->comment_date_gmt, $b->comment_date_gmt );
		}
	);

	return $comments;
}

/**
 * Keep the accepted answer and the best answers at the top on question pages.
 *
 * @param WP_Comment[] $comments Comments for the post.
 * @param int          $post_id  Post.
 * @return WP_Comment[]
 */
function aurora_qa_filter_comments( $comments, $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();

	if ( ! $post_id || ! aurora_qa_is_question( $post_id ) ) {
		return $comments;
	}

	return aurora_qa_sort_comments( $comments );
}
add_filter( 'comments_array', 'aurora_qa_filter_comments', 10, 2 );

/**
 * Vote controls appended to each answer.
 *
 * @param string $text Comment text.
 * @return string
 */
function aurora_qa_comment_actions( $text ) {
	$comment = get_comment();

	if ( ! $comment || ! aurora_qa_is_question( (int) $comment->comment_post_ID ) ) {
		return $text;
	}

	$votes    = aurora_qa_votes( $comment->comment_ID );
	$mine     = aurora_qa_my_vote( $comment->comment_ID );
	$accepted = (int) aurora_qa_accepted( (int) $comment->comment_post_ID ) === (int) $comment->comment_ID;
	$asker    = get_current_user_id() && (int) get_post_field( 'post_author', $comment->comment_post_ID ) === get_current_user_id();
	$can_vote = is_user_logged_in() && (int) $comment->user_id !== get_current_user_id();

	$html  = $text;
	$html .= '<div class="aurora-qa" data-comment="' . (int) $comment->comment_ID . '">';

	if ( $accepted ) {
		$html .= '<span class="aurora-qa__accepted">' . aurora_icon( 'check', 14 ) . esc_html__( '已采纳', 'aurora' ) . '</span>';
	}

	$html .= '<span class="aurora-qa__score" data-score>' . esc_html( (string) $votes['score'] ) . '</span>';

	$html .= sprintf(
		'<button type="button" class="aurora-qa__btn%s" data-qa-vote="1" %s aria-label="%s" %s>%s</button>',
		$mine > 0 ? ' is-on' : '',
		$can_vote ? '' : 'disabled',
		esc_attr__( '有帮助', 'aurora' ),
		$can_vote ? '' : 'aria-disabled="true"',
		aurora_icon( 'up', 15 )
	);

	$html .= sprintf(
		'<button type="button" class="aurora-qa__btn%s" data-qa-vote="-1" %s aria-label="%s" %s>%s</button>',
		$mine < 0 ? ' is-on' : '',
		$can_vote ? '' : 'disabled',
		esc_attr__( '没帮助', 'aurora' ),
		$can_vote ? '' : 'aria-disabled="true"',
		aurora_icon( 'down', 15 )
	);

	if ( $asker && ! $accepted && (int) $comment->comment_type === 0 ) {
		$html .= sprintf(
			'<button type="button" class="aurora-qa__accept" data-qa-accept="%d">%s</button>',
			(int) $comment->comment_ID,
			esc_html__( '采纳这条', 'aurora' )
		);
	}

	$html .= '</div>';

	return $html;
}
add_filter( 'comment_text', 'aurora_qa_comment_actions', 20 );

/**
 * "已解决" mark after the title of a question that has an accepted answer.
 *
 * Rendered by the template rather than through the_title: that filter also runs
 * inside widgets and related-post lists, which put the badge on six different
 * titles on one page.
 *
 * @param int $post_id Post.
 */
function aurora_qa_solved_mark( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : get_the_ID();

	if ( ! $post_id || ! aurora_qa_is_question( $post_id ) || ! aurora_qa_accepted( $post_id ) ) {
		return;
	}

	echo '<span class="aurora-qa__solved">' . esc_html__( '已解决', 'aurora' ) . '</span>';
}
add_action( 'aurora_after_article_title', 'aurora_qa_solved_mark' );


/**
 * AJAX: vote.
 */
function aurora_qa_ajax_vote() {
	$comment_id = isset( $_POST['comment'] ) ? (int) $_POST['comment'] : 0;
	$dir        = isset( $_POST['dir'] ) ? (int) $_POST['dir'] : 0;

	check_ajax_referer( 'aurora_qa', 'nonce' );

	$result = aurora_qa_vote( $comment_id, get_current_user_id(), $dir );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error(
			array(
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			),
			(int) ( $result->get_error_data()['status'] ?? 400 )
		);
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_aurora_qa_vote', 'aurora_qa_ajax_vote' );
add_action( 'wp_ajax_nopriv_aurora_qa_vote', 'aurora_qa_ajax_vote' );

/**
 * AJAX: accept.
 */
function aurora_qa_ajax_accept() {
	$post_id    = isset( $_POST['post'] ) ? (int) $_POST['post'] : 0;
	$comment_id = isset( $_POST['comment'] ) ? (int) $_POST['comment'] : 0;

	check_ajax_referer( 'aurora_qa', 'nonce' );

	$result = aurora_qa_accept( $post_id, $comment_id );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error(
			array(
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			),
			(int) ( $result->get_error_data()['status'] ?? 400 )
		);
	}

	wp_send_json_success(
		array(
			'post'    => $post_id,
			'comment' => $comment_id,
		)
	);
}
add_action( 'wp_ajax_aurora_qa_accept', 'aurora_qa_ajax_accept' );
add_action( 'wp_ajax_nopriv_aurora_qa_accept', 'aurora_qa_ajax_accept' );

/**
 * Vote script + config on question pages.
 */
function aurora_qa_enqueue() {
	if ( ! is_singular( 'post' ) || ! aurora_qa_is_question( get_queried_object_id() ) ) {
		return;
	}

	wp_enqueue_script(
		'aurora-qa',
		AURORA_URI . '/assets/js/qa.js',
		array(),
		AURORA_VERSION,
		true
	);

	wp_localize_script(
		'aurora-qa',
		'AuroraQA',
		array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'aurora_qa' ),
			'post'  => get_queried_object_id(),
			'i18n'  => array(
				'login' => __( '登录后才能投票。', 'aurora' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'aurora_qa_enqueue', 30 );
