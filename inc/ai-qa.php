<?php
/**
 * "问本站" — retrieval-augmented answers over this site's own articles.
 *
 * The model never answers from memory alone: the question is first turned into a
 * WordPress search, the top hits are cut down to plain text, and only those
 * passages are handed to the model. If the search finds nothing, the page says so
 * instead of letting the model invent an answer.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the /ai-ask route.
 */
function aurora_ai_qa_route() {
	add_rewrite_rule( '^ai-ask/?$', 'index.php?aurora_ai_ask=1', 'top' );
}
add_action( 'init', 'aurora_ai_qa_route' );

/**
 * @param array $vars Public query vars.
 * @return array
 */
function aurora_ai_qa_query_var( $vars ) {
	$vars[] = 'aurora_ai_ask';

	return $vars;
}
add_filter( 'query_vars', 'aurora_ai_qa_query_var' );

/**
 * Flush once per theme version, otherwise the rule never reaches the rewrite
 * table and /ai-ask 404s until someone re-saves permalinks by hand.
 */
function aurora_ai_qa_maybe_flush() {
	$stamp = get_option( 'aurora_ai_qa_flushed' );

	if ( AURORA_VERSION === $stamp ) {
		return;
	}

	flush_rewrite_rules( false );
	update_option( 'aurora_ai_qa_flushed', AURORA_VERSION, false );
}
add_action( 'init', 'aurora_ai_qa_maybe_flush', 99 );

/**
 * Serve the template for /ai-ask.
 *
 * @param string $template Template path.
 * @return string
 */
function aurora_ai_qa_template( $template ) {
	if ( ! get_query_var( 'aurora_ai_ask' ) ) {
		return $template;
	}

	$custom = locate_template( 'ai-ask.php' );

	return $custom ? $custom : $template;
}
add_filter( 'template_include', 'aurora_ai_qa_template' );

/**
 * Is the current request the ask page?
 *
 * @return bool
 */
function aurora_is_ai_ask() {
	return (bool) get_query_var( 'aurora_ai_ask' );
}

/**
 * Find the passages a question should be answered from.
 *
 * Search first, because WordPress already matches title, excerpt and body. Then
 * fall back to the newest posts in the best-matching tag, which catches "你们讲
 * 过哪些关于部署的东西" where the literal words never appear.
 *
 * @param string $question Question.
 * @param int    $count    How many passages.
 * @return array<int,array{id:int,title:string,url:string,text:string,date:string}>
 */
function aurora_ai_qa_sources( $question, $count = 5 ) {
	$question = trim( (string) $question );

	if ( '' === $question ) {
		return array();
	}

	// Both queries go through the listing filter. Without it the fallback returns
	// the newest rows in wp_posts — board threads and reply records included — and
	// the model gets asked to answer from a one-line thread title.
	$query = new WP_Query(
		apply_filters(
			'aurora_listing_query_args',
			array(
				's'                   => $question,
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => $count,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		)
	);

	$posts = $query->posts;

	if ( ! $posts ) {
		$posts = get_posts(
			apply_filters(
				'aurora_listing_query_args',
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => $count,
					'no_found_rows'  => true,
				)
			)
		);
	}

	$sources = array();

	foreach ( $posts as $post ) {
		$text = aurora_ai_post_plaintext( $post->ID, 1200 );

		if ( mb_strlen( $text ) < 40 ) {
			$text = wp_strip_all_tags( (string) $post->post_excerpt );

			if ( '' === $text ) {
				continue;
			}
		}

		$sources[] = array(
			'id'    => (int) $post->ID,
			'title' => get_the_title( $post ),
			'url'   => get_permalink( $post ),
			'text'  => $text,
			'date'  => get_the_date( 'Y-m-d', $post ),
		);
	}

	return $sources;
}

/**
 * REST: answer a question from site content.
 */
function aurora_ai_register_qa_route() {
	register_rest_route(
		'aurora/v1',
		'/ai/ask',
		array(
			'methods'             => 'POST',
			'callback'            => 'aurora_ai_rest_ask',
			'permission_callback' => '__return_true',
			'args'                => array(
				'question' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'aurora_ai_register_qa_route' );

/**
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function aurora_ai_rest_ask( $request ) {
	$question = trim( (string) $request['question'] );

	if ( mb_strlen( $question ) < 2 ) {
		return new WP_Error( 'aurora_ai_short', __( '问题太短。', 'aurora' ), array( 'status' => 400 ) );
	}

	if ( mb_strlen( $question ) > 200 ) {
		$question = mb_substr( $question, 0, 200 );
	}

	if ( ! aurora_ai_ready( 'qa' ) ) {
		return new WP_Error( 'aurora_ai_off', aurora_ai_blocked_reason() ?: __( '问答功能未启用。', 'aurora' ), array( 'status' => 503 ) );
	}

	if ( ! aurora_ai_throttle( 'ask', 8, 10 * MINUTE_IN_SECONDS ) ) {
		return new WP_Error( 'aurora_ai_busy', __( '问得有点快，歇一分钟再试。', 'aurora' ), array( 'status' => 429 ) );
	}

	$sources = aurora_ai_qa_sources( $question, 5 );

	if ( ! $sources ) {
		return rest_ensure_response(
			array(
				'answer'  => __( '站内还没有和这个问题相关的内容。换个说法，或者先用上面的搜索找找看。', 'aurora' ),
				'sources' => array(),
			)
		);
	}

	$context = '';

	foreach ( $sources as $i => $source ) {
		$context .= sprintf(
			"[%d] 《%s》（%s，%s）\n%s\n\n",
			$i + 1,
			$source['title'],
			$source['date'],
			$source['url'],
			$source['text']
		);
	}

	$result = aurora_ai_chat(
		array(
			array(
				'role'    => 'system',
				'content' => "你在回答读者关于本站内容的问题。规则：\n1. 只能用下面给出的资料回答，不要用你自己的知识补充事实、数字、日期或人名。\n2. 资料里没有的，直接说「站内文章没有提到这一点」。\n3. 每个结论后面用 [1] [2] 标注它来自第几篇资料。\n4. 用中文，300 字以内，直接回答，不要复述问题、不要写「根据资料」这类套话。\n5. 输出纯文本，可以用短段落，不要用 Markdown 标记。",
			),
			array(
				'role'    => 'user',
				'content' => "资料：\n" . $context . "\n问题：" . $question,
			),
		),
		array( 'temperature' => 0.3, 'max_tokens' => 700 )
	);

	if ( ! $result['ok'] ) {
		return new WP_Error( 'aurora_ai_failed', $result['error'], array( 'status' => 500 ) );
	}

	return rest_ensure_response(
		array(
			'answer'  => $result['text'],
			'sources' => array_map(
				static function ( $s ) {
					return array(
						'id'    => $s['id'],
						'title' => $s['title'],
						'url'   => $s['url'],
						'date'  => $s['date'],
					);
				},
				$sources
			),
		)
	);
}

/**
 * Assets for the ask page.
 *
 * Enqueued from the template so the script only loads where the form exists.
 */
function aurora_ai_qa_assets() {
	wp_enqueue_script( 'aurora-ai', AURORA_URI . '/assets/js/ai.js', array(), AURORA_VERSION, true );
	wp_localize_script(
		'aurora-ai',
		'AURORA_AI',
		array(
			'root'  => esc_url_raw( rest_url( 'aurora/v1/' ) ),
			'nonce' => aurora_ai_nonce(),
			'i18n'  => array(
				'thinking' => __( '正在读站内的文章…', 'aurora' ),
				'failed'   => __( '没能回答：', 'aurora' ),
				'sources'  => __( '出处', 'aurora' ),
			),
		)
	);
}
