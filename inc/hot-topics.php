<?php
/**
 * Live trending topics (抖音 / 微博 / 百度 / B站 热搜).
 *
 * Each provider is fetched server side and cached in a transient so a busy page
 * never hammers the upstream endpoints. Every provider is probed before use and
 * a failure in one never blanks the whole card.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

const AURORA_HOT_CACHE_KEY = 'aurora_hot_topics_cache';

/**
 * Provider registry.
 *
 * @return array
 */
function aurora_hot_providers() {
	$providers = array(
		'douyin'  => array(
			'label'   => __( '抖音', 'aurora' ),
			'accent'  => '#fe2c55',
			'url'     => 'https://www.douyin.com/aweme/v1/web/hot/search/list/',
			'referer' => 'https://www.douyin.com/',
			'link'    => 'https://www.douyin.com/hot/%s',
		),
		'weibo'   => array(
			'label'   => __( '微博', 'aurora' ),
			'accent'  => '#ff8200',
			'url'     => 'https://weibo.com/ajax/side/hotSearch',
			'referer' => 'https://weibo.com/',
			'link'    => 'https://s.weibo.com/weibo?q=%s',
		),
		'baidu'   => array(
			'label'   => __( '百度', 'aurora' ),
			'accent'  => '#2932e1',
			'url'     => 'https://top.baidu.com/api/board?platform=wise&tab=realtime',
			'referer' => 'https://top.baidu.com/',
			'link'    => '',
		),
		'bilibili' => array(
			'label'   => __( 'B站', 'aurora' ),
			'accent'  => '#fb7299',
			'url'     => 'https://api.bilibili.com/x/web-interface/search/square?limit=30',
			'referer' => 'https://www.bilibili.com/',
			'link'    => 'https://search.bilibili.com/all?keyword=%s',
		),
	);

	return apply_filters( 'aurora_hot_providers', $providers );
}

/**
 * Which providers the site owner switched on.
 *
 * @return array
 */
function aurora_hot_enabled() {
	$enabled = (array) get_theme_mod( 'aurora_hot_sources', array( 'douyin', 'weibo', 'baidu', 'bilibili' ) );
	$all     = aurora_hot_providers();

	return array_values( array_intersect( array_keys( $all ), array_map( 'sanitize_key', $enabled ) ) );
}

/**
 * Fetch a URL as JSON with browser-ish headers.
 *
 * @param string $url     Endpoint.
 * @param string $referer Referer to send.
 * @return array|WP_Error
 */
function aurora_hot_fetch_json( $url, $referer ) {
	$response = wp_remote_get( $url, array(
		'timeout'     => 12,
		'redirection' => 3,
		'headers'     => array(
			'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
			'Accept'          => 'application/json, text/plain, */*',
			'Accept-Language' => 'zh-CN,zh;q=0.9',
			'Referer'         => $referer,
		),
	) );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = wp_remote_retrieve_body( $response );

	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'aurora_hot_http', sprintf( 'HTTP %d', $code ) );
	}

	// Some providers emit raw control bytes inside strings, which strict JSON
	// parsing rejects — strip them before decoding.
	$raw = preg_replace( '/[\x00-\x1F\x7F]/u', '', $raw );
	$data = json_decode( $raw, true );

	if ( ! is_array( $data ) ) {
		return new WP_Error( 'aurora_hot_json', __( '返回内容不是有效 JSON。', 'aurora' ) );
	}

	return $data;
}

/**
 * Normalise one provider's payload into [ title, url, hot, tag ].
 *
 * @param string $key  Provider key.
 * @param array  $data Decoded payload.
 * @return array
 */
function aurora_hot_normalise( $key, $data ) {
	$providers = aurora_hot_providers();
	$link      = $providers[ $key ]['link'] ?? '';
	$items     = array();

	$make = static function ( $title, $url, $hot = 0, $tag = '' ) {
		$title = trim( wp_strip_all_tags( (string) $title ) );
		if ( '' === $title ) {
			return null;
		}
		return array(
			'title' => $title,
			'url'   => $url ? $url : '',
			'hot'   => (int) $hot,
			'tag'   => $tag ? trim( wp_strip_all_tags( (string) $tag ) ) : '',
		);
	};

	switch ( $key ) {
		case 'douyin':
			foreach ( (array) ( $data['data']['word_list'] ?? array() ) as $row ) {
				$sid = $row['sentence_id'] ?? '';
				$url = $sid ? sprintf( $link, rawurlencode( $sid ) ) : 'https://www.douyin.com/hot';
				$tag = ( isset( $row['label'] ) && (int) $row['label'] > 0 ) ? __( '热', 'aurora' ) : '';
				$item = $make( $row['word'] ?? '', $url, $row['hot_value'] ?? 0, $tag );
				if ( $item ) {
					$items[] = $item;
				}
			}
			// Fall back to the secondary list when the primary one is empty.
			if ( ! $items ) {
				foreach ( (array) ( $data['data']['trending_list'] ?? array() ) as $row ) {
					$item = $make( $row['word'] ?? '', 'https://www.douyin.com/hot', $row['hot_value'] ?? 0 );
					if ( $item ) {
						$items[] = $item;
					}
				}
			}
			break;

		case 'weibo':
			foreach ( (array) ( $data['data']['realtime'] ?? array() ) as $row ) {
				// `word_scheme` carries the exact search phrase including #topic#.
				$query = $row['word_scheme'] ?? ( $row['word'] ?? '' );
				$item  = $make(
					$row['word'] ?? '',
					sprintf( $link, rawurlencode( $query ) ),
					$row['num'] ?? 0,
					$row['label_name'] ?? ''
				);
				if ( $item ) {
					$items[] = $item;
				}
			}
			break;

		case 'baidu':
			$rows = $data['data']['cards'][0]['content'] ?? array();
			// The realtime board nests the entries one level deeper.
			if ( isset( $rows[0]['content'] ) ) {
				$rows = $rows[0]['content'];
			}
			foreach ( (array) $rows as $row ) {
				$item = $make(
					$row['word'] ?? '',
					$row['url'] ?? ( isset( $row['word'] ) ? 'https://www.baidu.com/s?wd=' . rawurlencode( $row['word'] ) : '' ),
					$row['hotScore'] ?? 0
				);
				if ( $item ) {
					$items[] = $item;
				}
			}
			break;

		case 'bilibili':
			foreach ( (array) ( $data['data']['trending']['list'] ?? array() ) as $row ) {
				$kw = $row['keyword'] ?? ( $row['show_name'] ?? '' );
				$item = $make(
					$kw,
					$kw ? sprintf( $link, rawurlencode( $kw ) ) : 'https://www.bilibili.com/',
					$row['heat_score'] ?? 0
				);
				if ( $item ) {
					$items[] = $item;
				}
			}
			break;
	}

	return array_slice( $items, 0, 12 );
}

/**
 * Cached trending topics, keyed by provider.
 *
 * @param bool $force Bypass the cache (used by the admin refresh button).
 * @return array
 */
function aurora_hot_topics( $force = false ) {
	$enabled = aurora_hot_enabled();
	$ttl     = max( 5, (int) get_theme_mod( 'aurora_hot_ttl', 30 ) ) * MINUTE_IN_SECONDS;
	$cache   = get_transient( AURORA_HOT_CACHE_KEY );

	if ( ! $force && is_array( $cache ) && ! empty( $cache['sources'] ) ) {
		// Serve whatever is fresh; only refetch providers whose slice expired.
		$out = array();
		foreach ( $enabled as $key ) {
			if ( isset( $cache['sources'][ $key ] ) && ( $cache['sources'][ $key ]['time'] ?? 0 ) > time() - $ttl ) {
				$out[ $key ] = $cache['sources'][ $key ]['items'];
			}
		}
		if ( count( $out ) === count( $enabled ) ) {
			return $out;
		}
		$cache['sources'] = is_array( $cache['sources'] ?? null ) ? $cache['sources'] : array();
	} else {
		$cache = array( 'sources' => array() );
	}

	$providers = aurora_hot_providers();

	foreach ( $enabled as $key ) {
		if ( ! $force && isset( $cache['sources'][ $key ]['time'] ) && $cache['sources'][ $key ]['time'] > time() - $ttl ) {
			continue;
		}

		$data  = aurora_hot_fetch_json( $providers[ $key ]['url'], $providers[ $key ]['referer'] );
		$items = is_wp_error( $data ) ? array() : aurora_hot_normalise( $key, $data );

		// Keep the previous good payload when a fetch fails, so the card rarely empties.
		if ( ! $items && ! empty( $cache['sources'][ $key ]['items'] ) ) {
			$items = $cache['sources'][ $key ]['items'];
		}

		$cache['sources'][ $key ] = array(
			'time'  => time(),
			'items' => $items,
			'error' => is_wp_error( $data ) ? $data->get_error_message() : '',
		);
	}

	set_transient( AURORA_HOT_CACHE_KEY, $cache, 6 * HOUR_IN_SECONDS );

	$out = array();
	foreach ( $enabled as $key ) {
		$out[ $key ] = $cache['sources'][ $key ]['items'] ?? array();
	}

	return $out;
}

/**
 * Shortcode: [aurora_hot_topics]
 *
 * @param array $atts Attributes.
 * @return string
 */
function aurora_hot_topics_shortcode( $atts ) {
	$atts = shortcode_atts( array(
		'sources' => '',
		'layout'  => 'card',
		'limit'   => 10,
	), $atts, 'aurora_hot_topics' );

	ob_start();
	get_template_part( 'template-parts/hot-topics', null, array(
		'sources' => $atts['sources'] ? array_map( 'sanitize_key', explode( ',', $atts['sources'] ) ) : null,
		'layout'  => sanitize_key( $atts['layout'] ),
		'limit'   => max( 3, min( 12, (int) $atts['limit'] ) ),
	) );

	return ob_get_clean();
}
add_shortcode( 'aurora_hot_topics', 'aurora_hot_topics_shortcode' );

/**
 * Human-readable hot value (12.5万).
 *
 * @param int $value Raw value.
 * @return string
 */
function aurora_hot_format( $value ) {
	$value = (int) $value;

	if ( $value >= 100000000 ) {
		return round( $value / 100000000, 1 ) . __( '亿', 'aurora' );
	}
	if ( $value >= 10000 ) {
		return round( $value / 10000, 1 ) . __( '万', 'aurora' );
	}

	return $value > 0 ? number_format_i18n( $value ) : '';
}

/**
 * Admin toolbar/menu helper: refresh the cache.
 */
function aurora_hot_admin_refresh() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! isset( $_GET['aurora_hot_refresh'] ) ) {
		return;
	}
	check_admin_referer( 'aurora_hot_refresh' );

	delete_transient( AURORA_HOT_CACHE_KEY );
	aurora_hot_topics( true );

	wp_safe_redirect( add_query_arg( 'aurora_hot_done', '1', remove_query_arg( array( 'aurora_hot_refresh', '_wpnonce' ) ) ) );
	exit;
}
add_action( 'admin_init', 'aurora_hot_admin_refresh' );
