<?php
/**
 * The "AI" tab in Aurora 主题设置.
 *
 * Lives next to the other setting tabs rather than in its own screen: the key,
 * the model and the three feature switches are configuration, and configuration
 * in this theme has exactly one home.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add the tab to the settings schema.
 *
 * @param array $schema Tabs.
 * @return array
 */
function aurora_ai_schema_tab( $schema ) {
	$schema['ai'] = array(
		'label'  => __( 'AI', 'aurora' ),
		'icon'   => 'dashicons-superhero-alt',
		'desc'   => __( '文章摘要、站内问答、后台写作助手共用这一套接口。支持任何 OpenAI 兼容服务：DeepSeek、OpenAI、Moonshot、硅基流动，或本地 vLLM。没填密钥时前台不会报错，只是按钮置灰。', 'aurora' ),
		'fields' => array(
			'aurora_ai_base'    => array(
				'label'   => __( '接口地址', 'aurora' ),
				'type'    => 'url',
				'default' => 'https://api.deepseek.com/v1',
				'help'    => __( '以 /v1 结尾，程序会自动拼 /chat/completions。DeepSeek 填 https://api.deepseek.com/v1，OpenAI 填 https://api.openai.com/v1。', 'aurora' ),
			),
			'aurora_ai_key'     => array(
				'label'   => __( 'API Key', 'aurora' ),
				'type'    => 'secret',
				'default' => '',
				'help'    => __( '只存在本站数据库里，页面永远不回显完整密钥。换服务商时直接粘贴新的即可。', 'aurora' ),
			),
			'aurora_ai_model'   => array(
				'label'   => __( '模型', 'aurora' ),
				'type'    => 'text',
				'default' => 'deepseek-chat',
				'help'    => __( '按服务商给的名称填，例如 deepseek-chat、gpt-4o-mini、moonshot-v1-8k。', 'aurora' ),
			),
			'aurora_ai_test'    => array(
				'label'  => __( '连接测试', 'aurora' ),
				'type'   => 'html',
				'render' => 'aurora_ai_render_test_row',
			),
			'aurora_ai_summary' => array(
				'label'   => __( '文章 AI 摘要', 'aurora' ),
				'type'    => 'checkbox',
				'default' => true,
				'help'    => __( '文章页标题下方显示一张摘要卡。第一次访问时生成并缓存到文章上，同一篇不会重复花钱；作者在编辑页也能手动重新生成。', 'aurora' ),
			),
			'aurora_ai_length'  => array(
				'label'   => __( '摘要字数', 'aurora' ),
				'type'    => 'number',
				'default' => 120,
				'min'     => 40,
				'max'     => 400,
				'help'    => __( '写 120 就是 120 字上下。中文字数按字符算。', 'aurora' ),
			),
			'aurora_ai_qa'      => array(
				'label'   => __( '站内内容问答', 'aurora' ),
				'type'    => 'checkbox',
				'default' => true,
				'help'    => __( '开启后 /ai-ask 页面可用：提问时先检索本站文章，再让模型基于检索到的段落回答，并在回答下面列出出处。', 'aurora' ),
			),
			'aurora_ai_writer'  => array(
				'label'   => __( '后台写作助手', 'aurora' ),
				'type'    => 'checkbox',
				'default' => true,
				'help'    => __( '文章编辑页右下角多一个面板：润色、扩写、缩写、起标题。结果先给你看，点「替换正文」才写回去。', 'aurora' ),
			),
			'aurora_ai_temp'    => array(
				'label'   => __( '温度', 'aurora' ),
				'type'    => 'number',
				'float'   => true,
				'default' => 0.6,
				'min'     => 0,
				'max'     => 1.5,
				'step'    => 0.1,
				'help'    => __( '0 最稳、1.5 最发散。摘要和问答建议 0.2–0.5，起标题可以 0.8。', 'aurora' ),
			),
			'aurora_ai_tokens'  => array(
				'label'   => __( '单次最多输出', 'aurora' ),
				'type'    => 'number',
				'default' => 900,
				'min'     => 128,
				'max'     => 4000,
				'help'    => __( '按 token 计，中文大约一个字一个多 token。900 足够写 300 字回答。', 'aurora' ),
			),
			'aurora_ai_cap'     => array(
				'label'   => __( '每天最多调用', 'aurora' ),
				'type'    => 'number',
				'default' => 200,
				'min'     => 0,
				'max'     => 100000,
				'help'    => __( '到了就停，前台显示「今日调用额度已用完」。填 0 表示不限——只在你确认账单可控时这么做。', 'aurora' ),
			),
		),
	);

	return $schema;
}
add_filter( 'aurora_setting_schema', 'aurora_ai_schema_tab' );

/**
 * Status line plus the test button.
 *
 * @param array $field Field definition.
 */
function aurora_ai_render_test_row( $field ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	$ready = aurora_ai_ready();
	$used  = aurora_ai_used_today();
	$cap   = (int) aurora_ai_cfg( 'cap' );
	$note  = isset( $_GET['aurora_ai_test'] ) ? sanitize_key( $_GET['aurora_ai_test'] ) : '';

	$class = $ready ? 'notice-success' : 'notice-warning';
	$state = $ready
		? __( '配置完整，功能可用。', 'aurora' )
		: aurora_ai_blocked_reason();
	?>
	<div class="notice <?php echo esc_attr( $class ); ?> inline" style="margin:0 0 10px">
		<p>
			<strong><?php echo esc_html( $state ); ?></strong>
			· <?php
			printf(
				/* translators: 1: used today, 2: daily cap */
				esc_html__( '今日已调用 %1$d 次，上限 %2$s', 'aurora' ),
				(int) $used,
				$cap > 0 ? (string) $cap : esc_html__( '不限', 'aurora' )
			);
			?>
			<?php if ( aurora_ai_cfg( 'model' ) ) : ?>
				· <?php echo esc_html( sprintf( __( '模型：%s', 'aurora' ), aurora_ai_cfg( 'model' ) ) ); ?>
			<?php endif; ?>
		</p>
		<?php if ( $note ) : ?>
			<p>
				<?php
				$map = array(
					'ok'    => __( '测试通过：模型正常回答。', 'aurora' ),
					'fail'  => __( '测试失败，原因见下方。', 'aurora' ),
					'nokey' => __( '还没填 API Key。', 'aurora' ),
				);
				echo esc_html( $map[ $note ] ?? '' );
				?>
			</p>
		<?php endif; ?>
	</div>

	<?php
	/*
	 * 这里以前是一个嵌套在设置表单里的 <form>。浏览器不允许表单嵌套，会把里面的
	 * <form ...> 开始标签丢掉，留下它自己的 action 字段——于是整页出现两个
	 * action，PHP 取最后一个，点「保存设置」实际提交的是 aurora_ai_test，
	 * 这一页填的所有东西一个字都没存下来。
	 * 现在它是一个普通提交按钮：走同一次保存，存完再顺手测一下。
	 */
	?>
	<p style="margin:0 0 10px">
		<button type="submit" class="button" name="aurora_do" value="aurora_ai_test"><?php esc_html_e( '保存并测试连接', 'aurora' ); ?></button>
		<span class="description"><?php esc_html_e( '先保存这一页的配置，再发一条测试请求；只改配置就点页面底部的「保存设置」。', 'aurora' ); ?></span>
	</p>

	<?php
	if ( $note && 'fail' === $note ) {
		$last = get_transient( 'aurora_ai_test_error_' . get_current_user_id() );
		if ( $last ) {
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $last ) );
		}
	}
}

/**
 * Send one cheap request and report whether the saved configuration works.
 *
 * Kept separate from the redirect so the save handler can run it right after
 * saving the tab — one round trip instead of "save, then find the button".
 *
 * @return array{flag:string,error:string}
 */
function aurora_ai_run_test() {
	$result = aurora_ai_chat(
		array(
			array(
				'role'    => 'system',
				'content' => '你是连通性测试。只回四个字：连接正常。',
			),
			array(
				'role'    => 'user',
				'content' => '测试',
			),
		),
		array( 'max_tokens' => 16, 'temperature' => 0, 'timeout' => 25 )
	);

	if ( ! $result['ok'] ) {
		$flag  = 'no_key' === $result['code'] ? 'nokey' : 'fail';
		$error = (string) ( $result['error'] ?? '' );
		set_transient( 'aurora_ai_test_error_' . get_current_user_id(), $error, 120 );

		return array( 'flag' => $flag, 'error' => $error );
	}

	delete_transient( 'aurora_ai_test_error_' . get_current_user_id() );

	return array( 'flag' => 'ok', 'error' => '' );
}

/**
 * Handle the standalone test endpoint (kept for direct links).
 */
function aurora_ai_handle_test() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '权限不足。', 'aurora' ) );
	}

	check_admin_referer( 'aurora_ai_test' );

	$result = aurora_ai_chat(
		array(
			array(
				'role'    => 'system',
				'content' => '你是连通性测试。只回四个字：连接正常。',
			),
			array(
				'role'    => 'user',
				'content' => '测试',
			),
		),
		array( 'max_tokens' => 16, 'temperature' => 0, 'timeout' => 25 )
	);

	$flag = 'ok';

	if ( ! $result['ok'] ) {
		$flag = 'no_key' === $result['code'] ? 'nokey' : 'fail';
		set_transient( 'aurora_ai_test_error_' . get_current_user_id(), $result['error'], 120 );
	} else {
		delete_transient( 'aurora_ai_test_error_' . get_current_user_id() );
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'          => 'aurora-settings',
				'tab'           => 'ai',
				'aurora_ai_test' => $flag,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_post_aurora_ai_test', 'aurora_ai_handle_test' );
