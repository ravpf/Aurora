<?php
/**
 * AI core: one client, one settings surface, one usage counter.
 *
 * Every feature in this theme that talks to a model goes through aurora_ai_chat():
 * the article summary, the site Q&A and the editor assistant. Keeping the HTTP
 * call in one place means the key, the base URL and the daily budget are read
 * once, and a model outage has a single shape to handle.
 *
 * Nothing here assumes a vendor. The request is the OpenAI chat-completions
 * shape, so DeepSeek, OpenAI, Moonshot, SiliconFlow or a local vLLM all work by
 * changing the base URL.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Defaults for every AI setting.
 *
 * @return array
 */
function aurora_ai_defaults() {
	return array(
		'base'    => 'https://api.deepseek.com/v1',
		'key'     => '',
		'model'   => 'deepseek-chat',
		'temp'    => 0.6,
		'tokens'  => 900,
		'cap'     => 200,
		'summary' => true,
		'qa'      => true,
		'writer'  => true,
		'length'  => 120,
	);
}

/**
 * Read one setting, or all of them.
 *
 * @param string|null $key Setting key.
 * @return mixed
 */
function aurora_ai_cfg( $key = null ) {
	// Each setting is its own theme mod: that is how the settings screen saves
	// every field in this theme, and one grouped array would need a second save
	// path just for AI.
	$map = array(
		'base'    => 'aurora_ai_base',
		'key'     => 'aurora_ai_key',
		'model'   => 'aurora_ai_model',
		'temp'    => 'aurora_ai_temp',
		'tokens'  => 'aurora_ai_tokens',
		'cap'     => 'aurora_ai_cap',
		'summary' => 'aurora_ai_summary',
		'qa'      => 'aurora_ai_qa',
		'writer'  => 'aurora_ai_writer',
		'length'  => 'aurora_ai_length',
	);

	$cfg = array();

	foreach ( $map as $short => $mod ) {
		$cfg[ $short ] = get_theme_mod( $mod, aurora_ai_defaults()[ $short ] );
	}

	$cfg['base']    = trim( (string) $cfg['base'] );
	$cfg['key']     = trim( (string) $cfg['key'] );
	$cfg['model']   = trim( (string) $cfg['model'] );
	$cfg['temp']    = max( 0, min( 1.5, (float) $cfg['temp'] ) );
	$cfg['tokens']  = max( 128, min( 4000, (int) $cfg['tokens'] ) );
	$cfg['cap']     = max( 0, (int) $cfg['cap'] );
	$cfg['length']  = max( 40, min( 400, (int) $cfg['length'] ) );
	$cfg['summary'] = (bool) $cfg['summary'];
	$cfg['qa']      = (bool) $cfg['qa'];
	$cfg['writer']  = (bool) $cfg['writer'];

	if ( null === $key ) {
		return $cfg;
	}

	return $cfg[ $key ] ?? null;
}

/**
 * Whether a feature can run right now.
 *
 * @param string $feature summary|qa|writer.
 * @return bool
 */
function aurora_ai_ready( $feature = '' ) {
	$cfg = aurora_ai_cfg();

	if ( '' === $cfg['key'] || '' === $cfg['base'] || '' === $cfg['model'] ) {
		return false;
	}

	if ( '' !== $feature && empty( $cfg[ $feature ] ) ) {
		return false;
	}

	return true;
}

/**
 * Why a feature is unavailable, in the words the UI will show.
 *
 * @return string Empty when everything is configured.
 */
function aurora_ai_blocked_reason() {
	$cfg = aurora_ai_cfg();

	if ( '' === $cfg['key'] ) {
		return __( '未配置 API Key', 'aurora' );
	}

	if ( '' === $cfg['base'] || '' === $cfg['model'] ) {
		return __( '接口地址或模型名称为空', 'aurora' );
	}

	if ( ! aurora_ai_usage_ok() ) {
		return __( '今日调用额度已用完', 'aurora' );
	}

	return '';
}

/**
 * Masked form of the stored key, for the settings screen.
 *
 * @return string
 */
function aurora_ai_key_hint() {
	$key = (string) aurora_ai_cfg( 'key' );

	if ( '' === $key ) {
		return '';
	}

	$tail = substr( $key, -4 );

	return str_repeat( '•', 8 ) . $tail;
}

/**
 * Today's usage record.
 *
 * @return array{day:string,count:int}
 */
function aurora_ai_usage() {
	$stored = get_option( 'aurora_ai_usage', array() );
	$today  = gmdate( 'Ymd' );

	if ( ! is_array( $stored ) || ( $stored['day'] ?? '' ) !== $today ) {
		return array(
			'day'   => $today,
			'count' => 0,
		);
	}

	return array(
		'day'   => $today,
		'count' => (int) ( $stored['count'] ?? 0 ),
	);
}

/**
 * @return bool
 */
function aurora_ai_usage_ok() {
	$cap = (int) aurora_ai_cfg( 'cap' );

	if ( $cap <= 0 ) {
		return true; // 0 means "no cap".
	}

	return aurora_ai_usage()['count'] < $cap;
}

/**
 * @return int
 */
function aurora_ai_usage_left() {
	$cap = (int) aurora_ai_cfg( 'cap' );

	if ( $cap <= 0 ) {
		return PHP_INT_MAX;
	}

	return max( 0, $cap - aurora_ai_usage()['count'] );
}

/**
 * Count one provider round trip.
 *
 * Counted on every response, including 4xx: a wrong key retried in a loop costs
 * requests just the same, and the cap exists to bound that.
 */
function aurora_ai_usage_bump() {
	$usage = aurora_ai_usage();

	update_option(
		'aurora_ai_usage',
		array(
			'day'   => gmdate( 'Ymd' ),
			'count' => $usage['count'] + 1,
		),
		false
	);
}

/**
 * Ask the model. The only place that performs an HTTP request.
 *
 * @param array $messages OpenAI-style messages.
 * @param array $opts     max_tokens, temperature, timeout.
 * @return array{ok:bool,text:string,error:string,code:string,ms:int}
 */
function aurora_ai_chat( $messages, $opts = array() ) {
	$cfg   = aurora_ai_cfg();
	$start = microtime( true );

	$fail = static function ( $code, $message ) use ( $start ) {
		return array(
			'ok'    => false,
			'text'  => '',
			'error' => $message,
			'code'  => $code,
			'ms'    => (int) round( ( microtime( true ) - $start ) * 1000 ),
		);
	};

	if ( '' === $cfg['key'] ) {
		return $fail( 'no_key', __( '未配置 API Key。', 'aurora' ) );
	}

	if ( ! aurora_ai_usage_ok() ) {
		return $fail( 'cap', __( '今日调用额度已用完，请在「Aurora 主题 → AI」里调高或等明天。', 'aurora' ) );
	}

	$body = array(
		'model'       => $cfg['model'],
		'messages'    => $messages,
		'temperature' => isset( $opts['temperature'] ) ? (float) $opts['temperature'] : (float) $cfg['temp'],
		'max_tokens'  => isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : (int) $cfg['tokens'],
		'stream'      => false,
	);

	$response = wp_remote_post(
		rtrim( $cfg['base'], '/' ) . '/chat/completions',
		array(
			'timeout' => isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 45,
			'headers' => array(
				'Authorization' => 'Bearer ' . $cfg['key'],
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $fail( 'network', $response->get_error_message() );
	}

	aurora_ai_usage_bump();

	$status = (int) wp_remote_retrieve_response_code( $response );
	$raw    = (string) wp_remote_retrieve_body( $response );
	$data   = json_decode( $raw, true );

	if ( 200 !== $status ) {
		// Show the provider's own sentence: "401 invalid api key" is actionable,
		// "请求失败" is not.
		$detail = '';

		if ( is_array( $data ) && isset( $data['error'] ) ) {
			$detail = is_array( $data['error'] ) ? ( $data['error']['message'] ?? '' ) : (string) $data['error'];
		}

		if ( '' === $detail ) {
			$detail = wp_strip_all_tags( substr( $raw, 0, 200 ) );
		}

		$hint = match ( $status ) {
			401, 403 => __( '密钥无效、过期或没有权限', 'aurora' ),
			404       => __( '接口地址不对（应以 /v1 结尾）', 'aurora' ),
			429       => __( '调用太频繁或余额不足', 'aurora' ),
			default   => __( '接口返回错误', 'aurora' ),
		};

		return $fail(
			'http_' . $status,
			sprintf( '%s：HTTP %d %s', $hint, $status, $detail )
		);
	}

	$text = '';

	if ( is_array( $data ) ) {
		$text = (string) ( $data['choices'][0]['message']['content'] ?? '' );
	}

	if ( '' === trim( $text ) ) {
		return $fail( 'empty', __( '模型返回了空内容。', 'aurora' ) );
	}

	return array(
		'ok'    => true,
		'text'  => trim( $text ),
		'error' => '',
		'code'  => 'ok',
		'ms'    => (int) round( ( microtime( true ) - $start ) * 1000 ),
	);
}

/**
 * Pull the first JSON object out of a model reply.
 *
 * Models wrap JSON in ```json fences, or add a sentence before it, often both.
 * json_decode() on the raw string then fails and the caller loses a good answer.
 *
 * @param string $raw Model text.
 * @return array|null
 */
function aurora_ai_json( $raw ) {
	$raw = trim( (string) $raw );

	if ( '' === $raw ) {
		return null;
	}

	$decoded = json_decode( $raw, true );

	if ( is_array( $decoded ) ) {
		return $decoded;
	}

	$start = strpos( $raw, '{' );
	$end   = strrpos( $raw, '}' );

	if ( false !== $start && false !== $end && $end > $start ) {
		$decoded = json_decode( substr( $raw, $start, $end - $start + 1 ), true );

		if ( is_array( $decoded ) ) {
			return $decoded;
		}
	}

	return null;
}

/**
 * Caller identity for per-visitor budgets.
 *
 * Logged-in users are counted by ID so sharing an IP (office, campus, CGNAT)
 * does not throttle a whole building; guests fall back to the address.
 *
 * @return string
 */
function aurora_ai_caller() {
	$uid = get_current_user_id();

	if ( $uid ) {
		return 'u' . $uid;
	}

	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

	return 'g' . md5( $ip );
}

/**
 * Short-window throttle for the public endpoints.
 *
 * @param string $action Action name.
 * @param int    $limit  Allowed calls per window.
 * @param int    $window Window in seconds.
 * @return bool True when the caller still has room.
 */
function aurora_ai_throttle( $action, $limit = 10, $window = 600 ) {
	$key   = 'aurora_ai_thr_' . md5( $action . '|' . aurora_ai_caller() );
	$count = (int) get_transient( $key );

	if ( $count >= $limit ) {
		return false;
	}

	set_transient( $key, $count + 1, $window );

	return true;
}

/**
 * REST nonce for the AI endpoints.
 *
 * @return string
 */
function aurora_ai_nonce() {
	return wp_create_nonce( 'wp_rest' );
}

/**
 * Feature availability as the front end needs it.
 *
 * @return array
 */
function aurora_ai_public_state() {
	$cfg = aurora_ai_cfg();

	return array(
		'summary' => aurora_ai_ready( 'summary' ),
		'qa'      => aurora_ai_ready( 'qa' ),
		'writer'  => aurora_ai_ready( 'writer' ),
		'reason'  => aurora_ai_blocked_reason(),
		'model'   => $cfg['model'],
	);
}

/**
 * Registers a call to the AI tab, used by the settings screen badge.
 *
 * @return int Calls made today.
 */
function aurora_ai_used_today() {
	return aurora_ai_usage()['count'];
}
