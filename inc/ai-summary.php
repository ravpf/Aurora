<?php
/**
 * AI summary card on the article page.
 *
 * Generated once per article and stored on the post, so a popular piece costs
 * one call no matter how many people read it. The card is deliberately quiet:
 * a label, two or three lines, and nothing that competes with the title.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

const AURORA_AI_SUMMARY_META = '_aurora_ai_summary';
const AURORA_AI_SUMMARY_AT   = '_aurora_ai_summary_at';

/**
 * Stored summary for a post.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function aurora_ai_summary_get( $post_id ) {
	return (string) get_post_meta( (int) $post_id, AURORA_AI_SUMMARY_META, true );
}

/**
 * Plain text of an article, ready to be summarised.
 *
 * Gutenberg comments and shortcodes have to go: a model reading raw block markup
 * spends its budget on `<!-- wp:paragraph -->` and starts answering about markup.
 *
 * @param int $post_id Post ID.
 * @param int $limit   Character budget.
 * @return string
 */
function aurora_ai_post_plaintext( $post_id, $limit = 6000 ) {
	$post = get_post( $post_id );

	if ( ! $post ) {
		return '';
	}

	$text = (string) $post->post_content;
	$text = preg_replace( '#<!--.*?-->#s', ' ', $text );
	$text = strip_shortcodes( $text );
	$text = wp_strip_all_tags( str_replace( array( '</p>', '</li>', '<br />', '<br>' ), "\n", $text ) );
	$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	$text = preg_replace( "/[ \t]+/", ' ', $text );
	$text = preg_replace( "/\n{3,}/", "\n\n", $text );
	$text = trim( (string) $text );

	if ( mb_strlen( $text ) > $limit ) {
		$text = mb_substr( $text, 0, $limit );
	}

	return $text;
}

/**
 * Generate and store a summary.
 *
 * @param int  $post_id Post ID.
 * @param bool $force   Regenerate even when one exists.
 * @return array{ok:bool,summary:string,error:string}
 */
function aurora_ai_summary_generate( $post_id, $force = false ) {
	$post_id = (int) $post_id;
	$post    = get_post( $post_id );

	if ( ! $post || 'publish' !== $post->post_status ) {
		return array(
			'ok'      => false,
			'summary' => '',
			'error'   => __( '文章不存在或未发布。', 'aurora' ),
		);
	}

	$existing = aurora_ai_summary_get( $post_id );

	if ( $existing && ! $force ) {
		return array(
			'ok'      => true,
			'summary' => $existing,
			'error'   => '',
		);
	}

	$body = aurora_ai_post_plaintext( $post_id );

	if ( mb_strlen( $body ) < 120 ) {
		return array(
			'ok'      => false,
			'summary' => '',
			'error'   => __( '正文太短，不值得做摘要。', 'aurora' ),
		);
	}

	$length = (int) aurora_ai_cfg( 'length' );

	$result = aurora_ai_chat(
		array(
			array(
				'role'    => 'system',
				'content' => sprintf(
					'你是中文编辑。请为下面这篇文章写一段摘要，%1$d 字左右（%2$d–%3$d 字）。要求：只说文章讲了什么，用陈述句；保留关键的人名、公司名、数字；不加「本文」「这篇文章」开头，不写评价和导购口吻；输出纯文本，一段，不要小标题、不要分点、不要引号。',
					$length,
					(int) round( $length * 0.85 ),
					(int) round( $length * 1.15 )
				),
			),
			array(
				'role'    => 'user',
				'content' => '标题：' . get_the_title( $post_id ) . "\n\n" . $body,
			),
		),
		array( 'temperature' => 0.3 )
	);

	if ( ! $result['ok'] ) {
		return array(
			'ok'      => false,
			'summary' => '',
			'error'   => $result['error'],
		);
	}

	$summary = trim( preg_replace( '/\s+/', ' ', $result['text'] ) );

	update_post_meta( $post_id, AURORA_AI_SUMMARY_META, $summary );
	update_post_meta( $post_id, AURORA_AI_SUMMARY_AT, time() );

	return array(
		'ok'      => true,
		'summary' => $summary,
		'error'   => '',
	);
}

/**
 * REST: return or create the summary for one article.
 */
function aurora_ai_register_summary_route() {
	register_rest_route(
		'aurora/v1',
		'/ai/summary',
		array(
			'methods'             => 'POST',
			'callback'            => 'aurora_ai_rest_summary',
			'permission_callback' => '__return_true',
			'args'                => array(
				'post_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'force'   => array(
					'required' => false,
					'type'     => 'boolean',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'aurora_ai_register_summary_route' );

/**
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function aurora_ai_rest_summary( $request ) {
	$post_id = (int) $request['post_id'];
	$post    = get_post( $post_id );

	if ( ! $post || 'publish' !== $post->post_status || 'post' !== $post->post_type ) {
		return new WP_Error( 'aurora_ai_no_post', __( '文章不存在。', 'aurora' ), array( 'status' => 404 ) );
	}

	if ( ! aurora_ai_ready( 'summary' ) ) {
		return new WP_Error( 'aurora_ai_off', aurora_ai_blocked_reason() ?: __( '摘要功能未启用。', 'aurora' ), array( 'status' => 503 ) );
	}

	$force = (bool) $request['force'];

	if ( $force && ! current_user_can( 'edit_post', $post_id ) ) {
		$force = false;
	}

	// Reading a summary that already exists costs nothing, so it must not spend a
	// throttle slot: a popular article would otherwise hit the limit from people
	// simply loading it.
	if ( ! $force && aurora_ai_summary_get( $post_id ) ) {
		return rest_ensure_response(
			array(
				'summary' => aurora_ai_summary_get( $post_id ),
				'cached'  => true,
			)
		);
	}

	if ( ! $force && ! aurora_ai_throttle( 'summary', 20, HOUR_IN_SECONDS ) ) {
		return new WP_Error( 'aurora_ai_busy', __( '请求太频繁，稍后再试。', 'aurora' ), array( 'status' => 429 ) );
	}

	$done = aurora_ai_summary_generate( $post_id, $force );

	if ( ! $done['ok'] ) {
		return new WP_Error( 'aurora_ai_failed', $done['error'], array( 'status' => 500 ) );
	}

	return rest_ensure_response(
		array(
			'summary' => $done['summary'],
			'cached'  => ! $force && (bool) aurora_ai_summary_get( $post_id ),
		)
	);
}

/**
 * Render the card under the article title.
 *
 * @param int $post_id Post ID.
 */
function aurora_ai_render_summary( $post_id ) {
	if ( ! is_singular( 'post' ) || ! aurora_ai_cfg( 'summary' ) ) {
		return;
	}

	if ( function_exists( 'aurora_post_is_paid' ) && aurora_post_is_paid( $post_id ) && ! current_user_can( 'read_post', $post_id ) ) {
		return; // Paid body is not readable yet; a summary of it would leak the point.
	}

	$summary = aurora_ai_summary_get( $post_id );
	$ready   = aurora_ai_ready( 'summary' );

	$can_regenerate = current_user_can( 'edit_post', $post_id );

	/*
	 * 没有摘要就不出卡片。以前只要配了接口就渲染一张「只有按钮」的白卡，
	 * 读者看到的是一块占位而不是内容——空模块一律不出现。
	 * 有编辑权限的人例外，他们需要那个「生成」入口。
	 */
	if ( '' === $summary && ! $can_regenerate ) {
		return;
	}

	if ( '' === $summary && ! $ready ) {
		return;
	}
	?>
	<div class="aurora-aisum" data-aurora-aisum="<?php echo esc_attr( $post_id ); ?>">
		<div class="aurora-aisum__head">
			<span class="aurora-aisum__badge"><?php aurora_the_icon( 'sparkles', 15 ); ?> <?php esc_html_e( 'AI 摘要', 'aurora' ); ?></span>
			<?php if ( $can_regenerate ) : ?>
				<button type="button" class="aurora-aisum__redo" data-aurora-aisum-redo><?php esc_html_e( '重新生成', 'aurora' ); ?></button>
			<?php endif; ?>
		</div>
		<p class="aurora-aisum__text" data-aurora-aisum-text<?php echo '' === $summary ? ' hidden' : ''; ?>><?php echo esc_html( $summary ); ?></p>
		<span class="aurora-aisum__note" data-aurora-aisum-note<?php echo '' === $summary ? ' hidden' : ''; ?>><?php esc_html_e( '由 AI 依据正文生成 · 仅供参考', 'aurora' ); ?></span>
		<?php if ( '' === $summary ) : ?>
			<button type="button" class="aurora-aisum__cta" data-aurora-aisum-make><?php esc_html_e( '生成本文摘要', 'aurora' ); ?></button>
			<span class="aurora-aisum__hint"><?php esc_html_e( '按文章正文生成，只调用一次接口。', 'aurora' ); ?></span>
		<?php endif; ?>
	</div>
	<?php
}
add_action( 'aurora_after_article_title', 'aurora_ai_render_summary' );

/**
 * Front-end script for the card.
 */
function aurora_ai_summary_assets() {
	if ( ! is_singular( 'post' ) || ! aurora_ai_cfg( 'summary' ) ) {
		return;
	}

	wp_enqueue_script(
		'aurora-ai',
		AURORA_URI . '/assets/js/ai.js',
		array(),
		AURORA_VERSION,
		true
	);

	wp_localize_script(
		'aurora-ai',
		'AURORA_AI',
		array(
			'root'  => esc_url_raw( rest_url( 'aurora/v1/' ) ),
			'nonce' => aurora_ai_nonce(),
			'i18n'  => array(
				'working' => __( '正在生成…', 'aurora' ),
				'failed'  => __( '生成失败：', 'aurora' ),
				'retry'   => __( '重试', 'aurora' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'aurora_ai_summary_assets', 20 );

/**
 * Keep the summary honest when the article changes.
 *
 * @param int $post_id Post ID.
 */
function aurora_ai_summary_invalidate( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( aurora_ai_summary_get( $post_id ) ) {
		delete_post_meta( $post_id, AURORA_AI_SUMMARY_META );
		delete_post_meta( $post_id, AURORA_AI_SUMMARY_AT );
	}
}
add_action( 'post_updated', 'aurora_ai_summary_invalidate' );
