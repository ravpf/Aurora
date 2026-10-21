<?php
/**
 * Editor assistant: polish, expand, shorten, titles.
 *
 * Deliberately not an auto-writer. Every action puts text in a panel first and
 * waits for a click, because the failure mode of an editor-side AI is publishing
 * something nobody read.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add the panel below the editor.
 */
function aurora_ai_writer_metabox() {
	if ( ! aurora_ai_cfg( 'writer' ) ) {
		return;
	}

	foreach ( array( 'post', 'page' ) as $type ) {
		add_meta_box(
			'aurora-ai-writer',
			__( 'AI 写作助手', 'aurora' ),
			'aurora_ai_writer_box',
			$type,
			'normal',
			'default'
		);
	}
}
add_action( 'add_meta_boxes', 'aurora_ai_writer_metabox' );

/**
 * Panel markup.
 *
 * @param WP_Post $post Post being edited.
 */
function aurora_ai_writer_box( $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	$ready = aurora_ai_ready( 'writer' );
	?>
	<div class="aurora-aiw" data-aurora-aiw>
		<div class="aurora-aiw__bar">
			<?php
			$actions = array(
				'polish'  => __( '润色', 'aurora' ),
				'expand'  => __( '扩写', 'aurora' ),
				'shorten' => __( '缩写', 'aurora' ),
				'titles'  => __( '起标题', 'aurora' ),
				'summary' => __( '摘要', 'aurora' ),
			);

			foreach ( $actions as $key => $label ) {
				printf(
					'<button type="button" class="button" data-aurora-aiw-run="%s" %s>%s</button>',
					esc_attr( $key ),
					disabled( ! $ready, true, false ),
					esc_html( $label )
				);
			}
			?>
			<span class="aurora-aiw__state" data-aurora-aiw-state></span>
		</div>

		<?php if ( ! $ready ) : ?>
			<p class="aurora-aiw__off">
				<?php
				printf(
					/* translators: %s: reason */
					esc_html__( '不可用：%s。到「Aurora 主题 → AI」里配置。', 'aurora' ),
					esc_html( aurora_ai_blocked_reason() )
				);
				?>
			</p>
		<?php endif; ?>

		<div class="aurora-aiw__out" data-aurora-aiw-out hidden>
			<div class="aurora-aiw__text" data-aurora-aiw-text></div>
			<div class="aurora-aiw__ops">
				<button type="button" class="button button-primary" data-aurora-aiw-replace><?php esc_html_e( '替换正文', 'aurora' ); ?></button>
				<button type="button" class="button" data-aurora-aiw-append><?php esc_html_e( '追加到正文末尾', 'aurora' ); ?></button>
				<button type="button" class="button-link" data-aurora-aiw-copy><?php esc_html_e( '复制', 'aurora' ); ?></button>
				<button type="button" class="button-link" data-aurora-aiw-close><?php esc_html_e( '收起', 'aurora' ); ?></button>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Script for the panel.
 *
 * @param string $hook Current admin page.
 */
function aurora_ai_writer_assets( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	if ( ! aurora_ai_cfg( 'writer' ) ) {
		return;
	}

	wp_enqueue_script(
		'aurora-ai-editor',
		AURORA_URI . '/assets/js/ai-editor.js',
		array( 'wp-data', 'wp-blocks', 'wp-editor' ),
		AURORA_VERSION,
		true
	);

	wp_localize_script(
		'aurora-ai-editor',
		'AURORA_AI_EDITOR',
		array(
			'root'  => esc_url_raw( rest_url( 'aurora/v1/' ) ),
			'nonce' => aurora_ai_nonce(),
			'i18n'  => array(
				'working' => __( '正在处理…', 'aurora' ),
				'empty'   => __( '正文是空的，先写点东西。', 'aurora' ),
				'failed'  => __( '失败：', 'aurora' ),
				'replaced' => __( '已替换正文。', 'aurora' ),
				'copied'  => __( '已复制。', 'aurora' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'aurora_ai_writer_assets' );

/**
 * REST: run one editor action.
 */
function aurora_ai_register_write_route() {
	register_rest_route(
		'aurora/v1',
		'/ai/write',
		array(
			'methods'             => 'POST',
			'callback'            => 'aurora_ai_rest_write',
			'permission_callback' => static function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'mode'  => array(
					'required' => true,
					'type'     => 'string',
					'enum'     => array( 'polish', 'expand', 'shorten', 'titles', 'summary' ),
				),
				'text'  => array(
					'required' => true,
					'type'     => 'string',
				),
				'title' => array(
					'required' => false,
					'type'     => 'string',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'aurora_ai_register_write_route' );

/**
 * Prompts per action.
 *
 * @param string $mode  Action.
 * @param string $title Post title.
 * @return array{system:string,user:string,tokens:int,temp:float}
 */
function aurora_ai_writer_prompt( $mode, $title = '' ) {
	$base = '你是中文编辑，服务一个技术内容站点。文风要求：短句、具体、不用「首先/其次/总之」这类过渡词，不用感叹号，不写营销腔。只输出结果本身，不要解释你做了什么。';

	$prompts = array(
		'polish'  => array(
			'system' => $base . ' 任务：润色。保持原意和段落结构，改掉病句、重复和口水话。',
			'user'   => "原文：\n",
			'tokens' => 1500,
			'temp'   => 0.4,
		),
		'expand'  => array(
			'system' => $base . ' 任务：扩写。把原文里每个要点展开成具体说明，可以补例子，但不要编造具体数据、人名或引用来源。',
			'user'   => "原文：\n",
			'tokens' => 1800,
			'temp'   => 0.6,
		),
		'shorten' => array(
			'system' => $base . ' 任务：压缩。保留所有事实和数字，删掉铺垫和重复，压到原文一半左右。',
			'user'   => "原文：\n",
			'tokens' => 1200,
			'temp'   => 0.4,
		),
		'titles'  => array(
			'system' => $base . ' 任务：起标题。给 5 个不同角度的标题，每个不超过 24 个字，不用问句和感叹号。每行一个，行首用「1. 2. 3.」编号，不要其他文字。',
			'user'   => '正文：',
			'tokens' => 300,
			'temp'   => 0.85,
		),
		'summary' => array(
			'system' => $base . ' 任务：写一段导语，120 字左右，陈述句，不上价值。',
			'user'   => '正文：',
			'tokens' => 400,
			'temp'   => 0.4,
		),
	);

	$prompt = $prompts[ $mode ] ?? $prompts['polish'];

	if ( '' !== $title && 'titles' !== $mode ) {
		$prompt['user'] = '标题：' . $title . "\n" . $prompt['user'];
	}

	return $prompt;
}

/**
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function aurora_ai_rest_write( $request ) {
	if ( ! aurora_ai_ready( 'writer' ) ) {
		return new WP_Error( 'aurora_ai_off', aurora_ai_blocked_reason() ?: __( '写作助手未启用。', 'aurora' ), array( 'status' => 503 ) );
	}

	if ( ! aurora_ai_throttle( 'write', 30, 10 * MINUTE_IN_SECONDS ) ) {
		return new WP_Error( 'aurora_ai_busy', __( '操作太频繁，等一下再试。', 'aurora' ), array( 'status' => 429 ) );
	}

	$mode  = (string) $request['mode'];
	$text  = (string) $request['text'];
	$title = (string) $request['title'];
	$text  = trim( wp_strip_all_tags( $text ) );

	if ( mb_strlen( $text ) < 10 ) {
		return new WP_Error( 'aurora_ai_short', __( '正文太短，先写几句。', 'aurora' ), array( 'status' => 400 ) );
	}

	if ( mb_strlen( $text ) > 8000 ) {
		$text = mb_substr( $text, 0, 8000 );
	}

	$prompt = aurora_ai_writer_prompt( $mode, $title );

	$result = aurora_ai_chat(
		array(
			array(
				'role'    => 'system',
				'content' => $prompt['system'],
			),
			array(
				'role'    => 'user',
				'content' => $prompt['user'] . $text,
			),
		),
		array(
			'max_tokens'  => $prompt['tokens'],
			'temperature' => $prompt['temp'],
		)
	);

	if ( ! $result['ok'] ) {
		return new WP_Error( 'aurora_ai_failed', $result['error'], array( 'status' => 500 ) );
	}

	return rest_ensure_response(
		array(
			'mode' => $mode,
			'text' => $result['text'],
			'ms'   => $result['ms'],
		)
	);
}
