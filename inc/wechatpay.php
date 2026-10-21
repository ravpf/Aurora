<?php
/**
 * WeChat Pay v3 (微信支付 APIv3) gateway.
 *
 * Full request signing (SHA256withRSA), AES-256-GCM callback decryption,
 * platform-certificate verification and Native / H5 / JSAPI下单. The gateway
 * stays hidden until mchid + AppID + APIv3 key + merchant key are present.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

const AURORA_WXPAY_HOST   = 'https://api.mch.weixin.qq.com';
const AURORA_WXPAY_DIR    = 'aurora-wechatpay';

/**
 * Absolute path to the protected directory holding merchant credentials.
 *
 * @return string
 */
function aurora_wxpay_dir() {
	$uploads = wp_upload_dir( null, false );

	return trailingslashit( $uploads['basedir'] ) . AURORA_WXPAY_DIR;
}

/**
 * Read the gateway configuration from theme mods.
 *
 * @return array
 */
function aurora_wxpay_config() {
	return array(
		'mchid'      => trim( (string) get_theme_mod( 'aurora_wxpay_mchid', '' ) ),
		'appid'      => trim( (string) get_theme_mod( 'aurora_wxpay_appid', '' ) ),
		'apiv3_key'  => trim( (string) get_theme_mod( 'aurora_wxpay_apiv3key', '' ) ),
		'serial_no'  => trim( (string) get_theme_mod( 'aurora_wxpay_serial', '' ) ),
		'key_file'   => aurora_wxpay_dir() . '/apiclient_key.pem',
		'notify_url' => aurora_wxpay_notify_url(),
		'currency'   => 'CNY',
	);
}

/**
 * Public callback URL. Must be an https address reachable from the internet.
 *
 * @return string
 */
function aurora_wxpay_notify_url() {
	$override = trim( (string) get_theme_mod( 'aurora_wxpay_notify', '' ) );
	if ( $override ) {
		return $override;
	}

	return rest_url( 'aurora/v1/wechatpay/notify' );
}

/**
 * Is the gateway usable end to end?
 *
 * @return bool
 */
function aurora_wxpay_configured() {
	$c = aurora_wxpay_config();

	if ( ! $c['mchid'] || ! $c['appid'] || ! $c['serial_no'] ) {
		return false;
	}
	if ( strlen( $c['apiv3_key'] ) !== 32 ) {
		return false;
	}
	if ( ! file_exists( $c['key_file'] ) || ! is_readable( $c['key_file'] ) ) {
		return false;
	}

	return (bool) aurora_wxpay_private_key();
}

/**
 * Load the merchant private key, preferring the stored PEM file.
 *
 * @return OpenSSLAsymmetricKey|false
 */
function aurora_wxpay_private_key() {
	$is_pem = static function ( $value ) {
		return is_string( $value ) && false !== strpos( $value, 'PRIVATE KEY' );
	};

	// A pasted key (kept in a theme mod) takes priority so credentials can be set
	// from the admin screen without touching the filesystem.
	$inline = (string) get_theme_mod( 'aurora_wxpay_key_pem', '' );
	if ( $is_pem( $inline ) ) {
		$key = openssl_pkey_get_private( $inline );
		if ( $key ) {
			return $key;
		}
	}

	$file = aurora_wxpay_config()['key_file'];
	if ( file_exists( $file ) && is_readable( $file ) ) {
		$key = openssl_pkey_get_private( 'file://' . $file );
		if ( $key ) {
			return $key;
		}
	}

	return false;
}

/**
 * Persist a pasted apiclient_key.pem into the protected uploads folder.
 *
 * @param string $pem PEM contents.
 * @return true|WP_Error
 */
function aurora_wxpay_store_key( $pem ) {
	$pem = trim( $pem );
	if ( false === strpos( $pem, 'PRIVATE KEY' ) ) {
		return new WP_Error( 'aurora_wxpay_key', __( '私钥内容无效：需要 apiclient_key.pem 的完整文本。', 'aurora' ) );
	}

	$test = openssl_pkey_get_private( $pem );
	if ( ! $test ) {
		return new WP_Error( 'aurora_wxpay_key', __( '私钥无法解析，请确认没有缺行或多余空格。', 'aurora' ) );
	}

	if ( ! wp_mkdir_p( aurora_wxpay_dir() ) ) {
		return new WP_Error( 'aurora_wxpay_dir', __( '无法创建证书目录。', 'aurora' ) );
	}

	$path = aurora_wxpay_dir() . '/apiclient_key.pem';
	if ( false === file_put_contents( $path, $pem ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return new WP_Error( 'aurora_wxpay_write', __( '私钥写入失败，请检查目录权限。', 'aurora' ) );
	}
	@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	// Keep the .htaccess guard in place for Apache-style stacks.
	file_put_contents( aurora_wxpay_dir() . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	file_put_contents( aurora_wxpay_dir() . '/index.php', "<?php\n// Silence is golden.\n" );     // phpcs:ignore WordPress.WP.AlternativeFunctions

	return true;
}

/* -------------------------------------------------------------------------
 * Signing and transport
 * ---------------------------------------------------------------------- */

/**
 * Build the WECHATPAY2-SHA256-RSA2048 Authorization header.
 *
 * @param string $method HTTP method.
 * @param string $url_path Request path including query string.
 * @param string $body    Raw request body.
 * @return string|WP_Error
 */
function aurora_wxpay_authorization( $method, $url_path, $body = '' ) {
	$c   = aurora_wxpay_config();
	$key = aurora_wxpay_private_key();

	if ( ! $key ) {
		return new WP_Error( 'aurora_wxpay_key', __( '商户私钥不可用。', 'aurora' ) );
	}

	$timestamp = (string) time();
	$nonce     = wp_generate_password( 32, false, false );

	// WeChat signs method\nurl\ntimestamp\nnonce\nbody\n — trailing newline included.
	$message = $method . "\n" . $url_path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";

	if ( ! openssl_sign( $message, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
		return new WP_Error( 'aurora_wxpay_sign', __( '请求签名失败。', 'aurora' ) );
	}

	return sprintf(
		'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%s",serial_no="%s"',
		$c['mchid'],
		$nonce,
		base64_encode( $signature ),
		$timestamp,
		$c['serial_no']
	);
}

/**
 * Perform a signed APIv3 request.
 *
 * @param string $method   GET|POST.
 * @param string $url_path Path with leading slash (include query string if any).
 * @param array  $payload  Body payload, or empty array for GET.
 * @return array|WP_Error Decoded response.
 */
function aurora_wxpay_request( $method, $url_path, $payload = array() ) {
	$c = aurora_wxpay_config();

	if ( ! $c['mchid'] ) {
		return new WP_Error( 'aurora_wxpay_config', __( '未配置商户号。', 'aurora' ) );
	}

	$body = empty( $payload ) ? '' : wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	$auth = aurora_wxpay_authorization( $method, $url_path, $body );
	if ( is_wp_error( $auth ) ) {
		return $auth;
	}

	$args = array(
		'method'      => $method,
		'timeout'     => 25,
		'redirection' => 0,
		'headers'     => array(
			'Authorization' => $auth,
			'Accept'        => 'application/json',
			'User-Agent'    => 'Aurora-Theme/' . AURORA_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
		),
		'body'        => $body,
	);

	if ( '' !== $body ) {
		$args['headers']['Content-Type'] = 'application/json';
	}

	$response = wp_remote_request( AURORA_WXPAY_HOST . $url_path, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = wp_remote_retrieve_body( $response );
	$data = json_decode( $raw, true );

	if ( $code >= 200 && $code < 300 ) {
		return is_array( $data ) ? $data : array();
	}

	$message = isset( $data['message'] ) ? $data['message'] : substr( $raw, 0, 300 );
	$detail  = isset( $data['detail'] ) ? ( is_array( $data['detail'] ) ? wp_json_encode( $data['detail'], JSON_UNESCAPED_UNICODE ) : $data['detail'] ) : '';

	return new WP_Error(
		'aurora_wxpay_api',
		sprintf( 'HTTP %d: %s %s', $code, $message, $detail )
	);
}

/**
 * Build a query string that matches the signature byte-for-byte.
 *
 * @param array $params Key/value pairs.
 * @return string
 */
function aurora_wxpay_build_query( $params ) {
	$pairs = array();
	foreach ( $params as $k => $v ) {
		$pairs[] = rawurlencode( $k ) . '=' . rawurlencode( (string) $v );
	}

	return implode( '&', $pairs );
}

/* -------------------------------------------------------------------------
 * Refunds
 * ---------------------------------------------------------------------- */

/**
 * Ask WeChat to refund (part of) a settled order.
 *
 * Idempotent per refund record: `out_refund_no` is the local refund number, so
 * re-running this for the same record reuses the same key and WeChat will not
 * move money twice.
 *
 * @param int $refund_id Local refund post ID.
 * @return array|WP_Error Decoded response (contains refund_id, status).
 */
function aurora_wxpay_refund( $refund_id ) {
	$refund = aurora_get_refund( $refund_id );

	if ( ! $refund ) {
		return new WP_Error( 'aurora_wxpay_refund', __( '退款记录不存在。', 'aurora' ) );
	}

	$order = aurora_get_order( $refund['order'] );
	$c     = aurora_wxpay_config();

	if ( ! $order['no'] ) {
		return new WP_Error( 'aurora_wxpay_refund', __( '关联订单不存在。', 'aurora' ) );
	}

	$total    = (int) round( $order['amount'] * 100 );
	$amount   = (int) round( $refund['amount'] * 100 );
	$currency = $order['currency'] ?? 'CNY';

	if ( $amount < 1 ) {
		return new WP_Error( 'aurora_wxpay_refund', __( '退款金额小于 1 分，无法提交。', 'aurora' ) );
	}
	if ( $amount > $total ) {
		return new WP_Error( 'aurora_wxpay_refund', __( '退款金额大于订单金额。', 'aurora' ) );
	}

	$payload = array(
		'out_trade_no'  => $order['no'],
		'out_refund_no' => $refund['no'],
		'reason'        => $refund['reason'] ? mb_substr( $refund['reason'], 0, 80 ) : __( '用户申请退款', 'aurora' ),
		'notify_url'    => aurora_wxpay_notify_url(),
		'amount'        => array(
			'refund'   => $amount,
			'total'    => $total,
			'currency' => 'CNY',
		),
	);

	$result = aurora_wxpay_request( 'POST', '/v3/refund/domestic/refunds', $payload );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! empty( $result['refund_id'] ) ) {
		update_post_meta( $refund_id, '_aurora_refund_wxid', sanitize_text_field( $result['refund_id'] ) );
	}

	return $result;
}

/**
 * Translate a refund API/notification payload into local refund state.
 *
 * @param int   $refund_id Local refund post ID.
 * @param array $result    Decoded body with at least `status`.
 * @return string The gateway status that was applied.
 */
function aurora_wxpay_apply_refund_result( $refund_id, $result ) {
	$status = strtoupper( (string) ( $result['status'] ?? '' ) );

	if ( ! empty( $result['refund_id'] ) ) {
		update_post_meta( $refund_id, '_aurora_refund_wxid', sanitize_text_field( $result['refund_id'] ) );
	}

	switch ( $status ) {
		case 'SUCCESS':
			aurora_settle_refund( $refund_id, (string) ( $result['refund_id'] ?? '' ) );
			break;

		case 'CLOSED':
			update_post_meta( $refund_id, '_aurora_refund_status', 'closed' );
			aurora_refresh_order_after_refund( (int) get_post_meta( $refund_id, '_aurora_refund_order', true ) );
			break;

		case 'ABNORMAL':
			update_post_meta( $refund_id, '_aurora_refund_status', 'failed' );
			update_post_meta( $refund_id, '_aurora_refund_error', __( '微信侧退款异常，请到商户平台确认。', 'aurora' ) );
			break;

		case 'PROCESSING':
		default:
			// Stays pending; the REFUND.SUCCESS notification will settle it.
			break;
	}

	aurora_wxpay_log( sprintf( 'refund %d -> %s', $refund_id, $status ? $status : 'unknown' ) );

	return $status;
}

/**
 * Local refund ID from its trade number.
 *
 * @param string $refund_no Local refund number.
 * @return int
 */
function aurora_wxpay_find_refund_by_no( $refund_no ) {
	$found = get_posts( array(
		'post_type'      => 'aurora_refund',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			array(
				'key'   => '_aurora_refund_no',
				'value' => $refund_no,
			),
		),
	) );

	return $found ? (int) $found[0] : 0;
}

/* -------------------------------------------------------------------------
 * Platform certificates
 * ---------------------------------------------------------------------- */

/**
 * Download and decrypt the WeChat Pay platform certificates.
 *
 * @param bool $force Bypass the cache.
 * @return array|WP_Error List of [ serial => pem ].
 */
function aurora_wxpay_platform_certs( $force = false ) {
	$cached = get_option( 'aurora_wxpay_platform_certs' );

	if ( ! $force && is_array( $cached ) && ! empty( $cached['certs'] ) && ( $cached['time'] ?? 0 ) > time() - 12 * HOUR_IN_SECONDS ) {
		return $cached['certs'];
	}

	$response = aurora_wxpay_request( 'GET', '/v3/certificates' );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$certs = array();
	foreach ( (array) ( $response['data'] ?? array() ) as $entry ) {
		if ( empty( $entry['encrypt_certificate'] ) ) {
			continue;
		}
		$pem = aurora_wxpay_decrypt_resource( $entry['encrypt_certificate'] );
		if ( ! $pem ) {
			continue;
		}
		$parsed = openssl_x509_parse( $pem );
		if ( $parsed && ! empty( $parsed['serialNumberHex'] ) ) {
			$certs[ strtoupper( $parsed['serialNumberHex'] ) ] = $pem;
		}
	}

	if ( empty( $certs ) ) {
		return new WP_Error( 'aurora_wxpay_certs', __( '未获取到平台证书，请检查密钥与商户号。', 'aurora' ) );
	}

	update_option( 'aurora_wxpay_platform_certs', array(
		'time'  => time(),
		'certs' => $certs,
	), false );

	return $certs;
}

/**
 * Resolve a platform certificate by serial number.
 *
 * @param string $serial Serial number (hex).
 * @return string|WP_Error PEM contents.
 */
function aurora_wxpay_cert_by_serial( $serial ) {
	$serial = strtoupper( (string) $serial );
	$certs  = aurora_wxpay_platform_certs();

	if ( is_wp_error( $certs ) ) {
		return $certs;
	}
	if ( isset( $certs[ $serial ] ) ) {
		return $certs[ $serial ];
	}

	// Cached set is stale: refresh once.
	$certs = aurora_wxpay_platform_certs( true );
	if ( is_wp_error( $certs ) ) {
		return $certs;
	}

	return $certs[ $serial ] ?? new WP_Error( 'aurora_wxpay_cert', __( '找不到对应的平台证书。', 'aurora' ) );
}

/**
 * Decrypt an APIv3 resource object (AES-256-GCM).
 *
 * @param array $resource Resource with ciphertext/nonce/associated_data.
 * @return string|false Plaintext, or false on failure.
 */
function aurora_wxpay_decrypt_resource( $resource ) {
	$key    = aurora_wxpay_config()['apiv3_key'];
	$nonce  = $resource['nonce'] ?? '';
	$aad    = $resource['associated_data'] ?? '';
	$cipher = base64_decode( $resource['ciphertext'] ?? '', true );

	if ( strlen( $key ) !== 32 || ! $nonce || false === $cipher ) {
		return false;
	}

	// WeChat appends the 16-byte GCM tag to the ciphertext.
	$tag  = substr( $cipher, -16 );
	$data = substr( $cipher, 0, -16 );

	$plain = openssl_decrypt( $data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad );

	return is_string( $plain ) ? $plain : false;
}

/**
 * Verify the signature WeChat puts on callback / API responses.
 *
 * @param array  $headers Headers keyed by name.
 * @param string $body    Raw body.
 * @return true|WP_Error
 */
function aurora_wxpay_verify_signature( $headers, $body ) {
	// WP_REST_Request canonicalises header names to lowercase with underscores
	// (wechatpay_timestamp), while raw $_SERVER style arrays use dashes. Fold
	// both forms before comparing so the lookup works in either context.
	$norm = static function ( $value ) {
		return str_replace( '_', '-', strtolower( (string) $value ) );
	};

	$get = static function ( $key ) use ( $headers, $norm ) {
		$key = $norm( $key );
		foreach ( $headers as $name => $value ) {
			if ( $norm( $name ) === $key ) {
				return is_array( $value ) ? (string) $value[0] : (string) $value;
			}
		}
		return '';
	};

	$timestamp = $get( 'Wechatpay-Timestamp' );
	$nonce     = $get( 'Wechatpay-Nonce' );
	$signature = $get( 'Wechatpay-Signature' );
	$serial    = $get( 'Wechatpay-Serial' );

	if ( ! $timestamp || ! $nonce || ! $signature || ! $serial ) {
		return new WP_Error( 'aurora_wxpay_verify', __( '回调缺少签名头。', 'aurora' ) );
	}

	// Reject stale replays (WeChat allows ~5 minutes of clock drift).
	if ( abs( time() - (int) $timestamp ) > 300 ) {
		return new WP_Error( 'aurora_wxpay_verify', __( '回调时间戳超出允许范围。', 'aurora' ) );
	}

	$pem = aurora_wxpay_cert_by_serial( $serial );
	if ( is_wp_error( $pem ) ) {
		return $pem;
	}

	$message = $timestamp . "\n" . $nonce . "\n" . $body . "\n";
	$result  = openssl_verify( $message, base64_decode( $signature ), $pem, OPENSSL_ALGO_SHA256 );

	if ( 1 !== $result ) {
		return new WP_Error( 'aurora_wxpay_verify', __( '回调签名验证失败。', 'aurora' ) );
	}

	return true;
}

/* -------------------------------------------------------------------------
 * Orders
 * ---------------------------------------------------------------------- */

/**
 * Create a Native (扫码) prepay order and return the QR payload.
 *
 * @param int $order_id Local order post ID.
 * @return string|WP_Error code_url.
 */
function aurora_wxpay_native_prepay( $order_id ) {
	$order = aurora_get_order( $order_id );
	$c     = aurora_wxpay_config();

	if ( ! $order['no'] ) {
		return new WP_Error( 'aurora_wxpay_order', __( '订单不存在。', 'aurora' ) );
	}

	$path = '/v3/pay/transactions/native';

	$response = aurora_wxpay_request( 'POST', $path, array(
		'appid'        => $c['appid'],
		'mchid'        => $c['mchid'],
		'description'  => mb_substr( $order['product'], 0, 120 ),
		'out_trade_no' => $order['no'],
		'notify_url'   => $c['notify_url'],
		'amount'       => array(
			'total'    => (int) round( $order['amount'] * 100 ),
			'currency' => $c['currency'],
		),
		'attach'       => wp_json_encode( array( 'order_id' => (int) $order_id ) ),
		'time_expire'  => gmdate( 'c', time() + 15 * MINUTE_IN_SECONDS ),
	) );

	if ( is_wp_error( $response ) ) {
		return $response;
	}
	if ( empty( $response['code_url'] ) ) {
		return new WP_Error( 'aurora_wxpay_native', __( '未返回支付二维码。', 'aurora' ) );
	}

	update_post_meta( $order_id, '_aurora_wxpay_code_url', esc_url_raw( $response['code_url'] ) );

	return (string) $response['code_url'];
}

/**
 * Query the trade state for a local order.
 *
 * @param int $order_id Local order post ID.
 * @return array|WP_Error
 */
function aurora_wxpay_query( $order_id ) {
	$order = aurora_get_order( $order_id );
	$c     = aurora_wxpay_config();

	$path = sprintf(
		'/v3/pay/transactions/out-trade-no/%s?%s',
		rawurlencode( $order['no'] ),
		aurora_wxpay_build_query( array( 'mchid' => $c['mchid'] ) )
	);

	return aurora_wxpay_request( 'GET', $path );
}

/**
 * Close an unpaid order on WeChat's side.
 *
 * @param int $order_id Local order post ID.
 * @return array|WP_Error
 */
function aurora_wxpay_close( $order_id ) {
	$order = aurora_get_order( $order_id );
	$c     = aurora_wxpay_config();

	return aurora_wxpay_request( 'POST', sprintf(
		'/v3/pay/transactions/out-trade-no/%s/close',
		rawurlencode( $order['no'] )
	), array( 'mchid' => $c['mchid'] ) );
}

/**
 * Render a QR PNG for an arbitrary payload using the qrencode binary.
 *
 * Returns a URL, or an empty string when the tool is unavailable — callers must
 * fall back to showing the raw link rather than a broken image.
 *
 * @param string $payload Payload to encode.
 * @return string Public URL or ''.
 */
function aurora_wxpay_qr_url( $payload ) {
	$bin = trim( (string) shell_exec( 'command -v qrencode 2>/dev/null' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	if ( ! $bin ) {
		return '';
	}

	$uploads = wp_upload_dir( null, false );
	$dir     = trailingslashit( $uploads['basedir'] ) . 'aurora-qr';
	if ( ! wp_mkdir_p( $dir ) ) {
		return '';
	}

	$name = md5( $payload ) . '.png';
	$file = $dir . '/' . $name;

	if ( ! file_exists( $file ) ) {
		// escapeshellarg keeps the payload from ever reaching the shell unquoted.
		$cmd = sprintf(
			'%s -o %s -s 7 -m 2 -l M %s 2>/dev/null',
			escapeshellcmd( $bin ),
			escapeshellarg( $file ),
			escapeshellarg( $payload )
		);
		shell_exec( $cmd ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		@chmod( $file, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	if ( ! file_exists( $file ) ) {
		return '';
	}

	return trailingslashit( $uploads['baseurl'] ) . 'aurora-qr/' . $name;
}

/* -------------------------------------------------------------------------
 * REST callback
 * ---------------------------------------------------------------------- */

/**
 * Register the payment notification route.
 */
function aurora_wxpay_register_routes() {
	register_rest_route( 'aurora/v1', '/wechatpay/notify', array(
		'methods'             => 'POST',
		'callback'            => 'aurora_wxpay_handle_notify',
		'permission_callback' => '__return_true',
	) );
}
add_action( 'rest_api_init', 'aurora_wxpay_register_routes' );

/**
 * Handle a WeChat Pay payment notification.
 *
 * Must answer 200 with {code:SUCCESS} once accepted, otherwise WeChat retries.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response
 */
function aurora_wxpay_handle_notify( WP_REST_Request $request ) {
	$body    = $request->get_body();
	$headers = array();
	foreach ( $request->get_headers() as $name => $value ) {
		$headers[ $name ] = is_array( $value ) ? $value[0] : $value;
	}

	$verified = aurora_wxpay_verify_signature( $headers, $body );
	if ( is_wp_error( $verified ) ) {
		aurora_wxpay_log( 'notify rejected: ' . $verified->get_error_message() );
		return new WP_REST_Response( array(
			'code'    => 'FAIL',
			'message' => 'signature verification failed',
		), 401 );
	}

	$payload    = json_decode( $body, true );
	$event_type = strtoupper( (string) ( $payload['event_type'] ?? '' ) );
	$resource   = $payload['resource'] ?? array();
	$plain      = aurora_wxpay_decrypt_resource( $resource );

	if ( ! $plain ) {
		aurora_wxpay_log( 'notify decryption failed' );
		return new WP_REST_Response( array( 'code' => 'FAIL', 'message' => 'decrypt failed' ), 400 );
	}

	$data = json_decode( $plain, true );

	// Refund lifecycle notifications arrive on the same endpoint.
	if ( 0 === strpos( $event_type, 'REFUND.' ) ) {
		return aurora_wxpay_handle_refund_notify( $event_type, (array) $data );
	}

	$out   = $data['out_trade_no'] ?? '';
	$state = $data['trade_state'] ?? '';

	if ( 'SUCCESS' !== $state ) {
		aurora_wxpay_log( sprintf( 'notify %s state=%s (ignored)', $out, $state ) );
		return new WP_REST_Response( array( 'code' => 'SUCCESS', 'message' => 'ignored' ), 200 );
	}

	$order_id = aurora_wxpay_find_order_by_no( $out );
	if ( ! $order_id ) {
		aurora_wxpay_log( 'notify for unknown order ' . $out );
		// Still acknowledge: retrying will never help.
		return new WP_REST_Response( array( 'code' => 'SUCCESS', 'message' => 'unknown order' ), 200 );
	}

	// Cross-check the amount so a tampered callback cannot underpay.
	$local  = aurora_get_order( $order_id );
	$paid   = (int) ( $data['amount']['total'] ?? 0 );
	$expect = (int) round( $local['amount'] * 100 );

	if ( $paid !== $expect ) {
		aurora_wxpay_log( sprintf( 'amount mismatch on %s: paid %d expected %d', $out, $paid, $expect ) );
		return new WP_REST_Response( array( 'code' => 'FAIL', 'message' => 'amount mismatch' ), 400 );
	}

	aurora_mark_order_paid( $order_id, $data['transaction_id'] ?? '' );
	update_post_meta( $order_id, '_aurora_paid_amount', $paid );
	aurora_wxpay_log( sprintf( 'order %s paid via wechat (%s)', $out, $data['transaction_id'] ?? '-' ) );

	return new WP_REST_Response( array( 'code' => 'SUCCESS', 'message' => 'OK' ), 200 );
}

/**
 * Handle a REFUND.* notification.
 *
 * The gateway reports the refund state in `refund_status`, and the cross-check
 * that matters is against the *local refund record's* amount — never against
 * anything in the payload — so a forged refund callback cannot inflate a
 * refund beyond what was requested.
 *
 * @param string $event_type e.g. REFUND.SUCCESS.
 * @param array  $data       Decrypted resource.
 * @return WP_REST_Response
 */
function aurora_wxpay_handle_refund_notify( $event_type, $data ) {
	$refund_no = (string) ( $data['out_refund_no'] ?? '' );
	$state     = strtoupper( (string) ( $data['refund_status'] ?? '' ) );

	if ( ! $refund_no ) {
		aurora_wxpay_log( 'refund notify without out_refund_no' );
		return new WP_REST_Response( array( 'code' => 'SUCCESS', 'message' => 'ignored' ), 200 );
	}

	$refund_id = aurora_wxpay_find_refund_by_no( $refund_no );

	if ( ! $refund_id ) {
		aurora_wxpay_log( 'refund notify for unknown refund ' . $refund_no );
		// Retrying will never match; acknowledge so WeChat stops.
		return new WP_REST_Response( array( 'code' => 'SUCCESS', 'message' => 'unknown refund' ), 200 );
	}

	$local = aurora_get_refund( $refund_id );

	if ( ! $local ) {
		return new WP_REST_Response( array( 'code' => 'SUCCESS', 'message' => 'ignored' ), 200 );
	}

	// Only settle when the reported refund matches what we asked for.
	if ( 'SUCCESS' === $state ) {
		$reported = (int) ( $data['amount']['refund'] ?? 0 );
		$expected = (int) round( $local['amount'] * 100 );

		if ( $reported > 0 && $reported !== $expected ) {
			aurora_wxpay_log( sprintf( 'refund amount mismatch on %s: reported %d expected %d', $refund_no, $reported, $expected ) );
			return new WP_REST_Response( array( 'code' => 'FAIL', 'message' => 'amount mismatch' ), 400 );
		}
	}

	aurora_wxpay_apply_refund_result( $refund_id, array(
		'status'    => $state,
		'refund_id' => (string) ( $data['refund_id'] ?? '' ),
	) );

	return new WP_REST_Response( array( 'code' => 'SUCCESS', 'message' => 'OK' ), 200 );
}

/**
 * Look up a local order id from its trade number.
 *
 * @param string $order_no Trade number.
 * @return int
 */
function aurora_wxpay_find_order_by_no( $order_no ) {
	$found = get_posts( array(
		'post_type'        => 'aurora_order',
		'post_status'      => 'publish',
		'posts_per_page'   => 1,
		'fields'           => 'ids',
		'no_found_rows'    => true,
		'suppress_filters' => false,
		'meta_query'       => array(
			array(
				'key'   => '_aurora_order_no',
				'value' => $order_no,
			),
		),
	) );

	return $found ? (int) $found[0] : 0;
}

/**
 * Append to a dedicated log when WP_DEBUG logging is on.
 *
 * @param string $message Message.
 */
function aurora_wxpay_log( $message ) {
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
		return;
	}
	error_log( '[aurora-wechatpay] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
}

/* -------------------------------------------------------------------------
 * Checkout wiring
 * ---------------------------------------------------------------------- */

/**
 * Create the WeChat prepay as soon as a WeChat order is placed.
 *
 * @param int    $order_id Order post ID.
 * @param string $gateway  Chosen gateway id.
 */
function aurora_wxpay_after_checkout( $order_id, $gateway ) {
	if ( 'wechat' !== $gateway || ! aurora_wxpay_configured() ) {
		return;
	}

	$result = aurora_wxpay_native_prepay( $order_id );
	if ( is_wp_error( $result ) ) {
		update_post_meta( $order_id, '_aurora_wxpay_error', $result->get_error_message() );
		aurora_wxpay_log( 'prepay failed for #' . $order_id . ': ' . $result->get_error_message() );
	}
}
add_action( 'aurora_checkout_created', 'aurora_wxpay_after_checkout', 10, 2 );

/**
 * Render the QR / pay button inside the pending-order panel.
 *
 * @param array $order Order data.
 */
function aurora_wxpay_payment_ui( $order ) {
	if ( 'wechat' !== $order['gateway'] || 'pending' !== $order['status'] ) {
		return;
	}

	if ( ! aurora_wxpay_configured() ) {
		printf(
			'<p class="aurora-notice aurora-notice--warning" style="margin-top:14px">%s</p>',
			esc_html__( '微信支付未完成配置，请联系管理员。', 'aurora' )
		);
		return;
	}

	$error = get_post_meta( $order['id'], '_aurora_wxpay_error', true );

	if ( $error ) {
		printf(
			'<p class="aurora-notice aurora-notice--warning" style="margin-top:14px">%s<br><code style="font-size:.8rem">%s</code></p>',
			esc_html__( '下单失败：', 'aurora' ),
			esc_html( $error )
		);
		return;
	}

	$code_url = (string) get_post_meta( $order['id'], '_aurora_wxpay_code_url', true );

	if ( ! $code_url ) {
		$code_url = (string) aurora_wxpay_native_prepay( $order['id'] );
	}

	if ( ! $code_url ) {
		echo '<p class="aurora-notice aurora-notice--warning" style="margin-top:14px">' . esc_html__( '暂时无法获取支付二维码，请稍后重试。', 'aurora' ) . '</p>';
		return;
	}

	$qr = aurora_wxpay_qr_url( $code_url );
	?>
	<div class="aurora-wxpay" data-order="<?php echo esc_attr( $order['id'] ); ?>">
		<div class="aurora-wxpay__qr">
			<?php if ( $qr ) : ?>
				<img src="<?php echo esc_url( $qr ); ?>" alt="<?php esc_attr_e( '微信支付二维码', 'aurora' ); ?>" width="232" height="232">
			<?php else : ?>
				<p style="font-size:.86rem;color:var(--aurora-text-secondary)"><?php esc_html_e( '服务器未安装 qrencode，无法生成二维码。可复制下面的链接在微信中打开。', 'aurora' ); ?></p>
			<?php endif; ?>
			<button type="button" class="aurora-btn aurora-btn--sm aurora-copy" data-clipboard="<?php echo esc_attr( $code_url ); ?>">
				<?php aurora_the_icon( 'link', 15 ); ?><span data-label=""><?php esc_html_e( '复制支付链接', 'aurora' ); ?></span>
			</button>
		</div>
		<div class="aurora-wxpay__side">
			<div class="aurora-wxpay__brand"><?php esc_html_e( '微信扫码支付', 'aurora' ); ?></div>
			<ol class="aurora-wxpay__steps">
				<li><?php esc_html_e( '打开微信，点右上角「+」→ 扫一扫', 'aurora' ); ?></li>
				<li><?php esc_html_e( '扫描左侧二维码', 'aurora' ); ?></li>
				<li><?php esc_html_e( '确认金额并完成付款', 'aurora' ); ?></li>
			</ol>
			<p class="aurora-wxpay__status" data-status="pending">
				<?php aurora_the_icon( 'clock', 15 ); ?>
				<span><?php esc_html_e( '等待支付…', 'aurora' ); ?></span>
			</p>
			<button type="button" class="aurora-btn aurora-btn--sm aurora-wxpay__refresh" data-order="<?php echo esc_attr( $order['id'] ); ?>">
				<?php esc_html_e( '我已支付，刷新状态', 'aurora' ); ?>
			</button>
		</div>
	</div>
	<?php
}
add_action( 'aurora_order_payment_ui', 'aurora_wxpay_payment_ui' );

/**
 * AJAX: let the buyer poll their own order status.
 */
function aurora_wxpay_ajax_status() {
	aurora_check_nonce();

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( '请先登录。', 'aurora' ) ), 200 );
	}

	$order_id = isset( $_POST['order'] ) ? (int) $_POST['order'] : 0;
	$order    = aurora_get_order( $order_id );

	if ( (int) $order['user_id'] !== get_current_user_id() && ! current_user_can( 'edit_others_posts' ) ) {
		wp_send_json_error( array( 'message' => __( '无权查看该订单。', 'aurora' ) ), 200 );
	}

	// Ask WeChat directly: the callback may still be in flight.
	$result = aurora_wxpay_query( $order_id );
	$state  = is_wp_error( $result ) ? '' : ( $result['trade_state'] ?? '' );

	if ( 'SUCCESS' === $state && 'paid' !== $order['status'] ) {
		$paid   = (int) ( $result['amount']['total'] ?? 0 );
		$expect = (int) round( $order['amount'] * 100 );
		if ( $paid === $expect ) {
			aurora_mark_order_paid( $order_id, $result['transaction_id'] ?? '' );
		}
		$order = aurora_get_order( $order_id );
	}

	wp_send_json_success( array(
		'status'   => $order['status'],
		'trade'    => $state,
		'paidText' => __( '支付成功，权益已发放', 'aurora' ),
	) );
}
add_action( 'wp_ajax_aurora_wxpay_status', 'aurora_wxpay_ajax_status' );
