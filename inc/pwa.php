<?php
/**
 * Progressive web app: manifest, service worker, offline shell.
 *
 * Both the manifest and the worker are generated in PHP instead of shipped as
 * static files, so the cache name can carry the theme version and a deploy
 * invalidates the old cache without anyone remembering to bump a string.
 *
 * The worker itself is deliberately conservative: network-first for documents
 * (a stale article is worse than no article), cache-first for immutable theme
 * assets, and a small offline shell for navigation failures.
 *
 * Registration only succeeds over HTTPS (or localhost) — that is a browser
 * rule, not something this code can work around.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is the PWA layer on?
 *
 * @return bool
 */
function aurora_pwa_enabled() {
	return (bool) get_theme_mod( 'aurora_pwa_enabled', true );
}

/**
 * Cache name, versioned with the theme so a deploy drops the old cache.
 *
 * @return string
 */
function aurora_pwa_cache_name() {
	return 'aurora-' . AURORA_VERSION;
}

/**
 * Routes: /manifest.webmanifest and /sw.js.
 */
function aurora_pwa_routes() {
	add_rewrite_rule( '^manifest\.webmanifest$', 'index.php?aurora_manifest=1', 'top' );
	add_rewrite_rule( '^sw\.js$', 'index.php?aurora_sw=1', 'top' );
}
add_action( 'init', 'aurora_pwa_routes', 8 );

/**
 * Register the query vars.
 *
 * @param array $vars Vars.
 * @return array
 */
function aurora_pwa_query_vars( $vars ) {
	$vars[] = 'aurora_manifest';
	$vars[] = 'aurora_sw';
	return $vars;
}
add_filter( 'query_vars', 'aurora_pwa_query_vars' );

/**
 * Icon URLs for the manifest: site icon when there is one, else the built-in
 * SVG.
 *
 * @return array<int,array{src:string,sizes:string,type:string,purpose:string}>
 */
function aurora_pwa_icons() {
	$svg = get_theme_file_uri( 'assets/img/app-icon.svg' );
	$png = get_site_icon_url( 512 );

	$icons = array();

	if ( $png ) {
		$icons[] = array(
			'src'     => $png,
			'sizes'   => '512x512',
			'type'    => 'image/png',
			'purpose' => 'any',
		);
	}

	$icons[] = array(
		'src'     => $svg,
		'sizes'   => 'any',
		'type'    => 'image/svg+xml',
		'purpose' => 'any maskable',
	);

	return $icons;
}

/**
 * The manifest body.
 *
 * @return array
 */
function aurora_pwa_manifest() {
	$name = get_bloginfo( 'name' );

	return array(
		'name'             => $name,
		'short_name'       => mb_substr( wp_strip_all_tags( $name ), 0, 12 ),
		'description'      => get_bloginfo( 'description' ),
		'start_url'        => home_url( '/' ),
		'scope'            => home_url( '/' ),
		'display'          => 'standalone',
		'background_color' => '#f5f5f7',
		'theme_color'      => '#f5f5f7',
		'lang'             => get_bloginfo( 'language' ),
		'dir'              => is_rtl() ? 'rtl' : 'ltr',
		'icons'            => aurora_pwa_icons(),
	);
}

/**
 * The offline shell: a self-contained page, no network, no theme CSS.
 *
 * @return string
 */
function aurora_pwa_offline_html() {
	$name = esc_html( get_bloginfo( 'name' ) );

	return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
		. '<meta name="viewport" content="width=device-width,initial-scale=1">'
		. '<title>离线 · ' . $name . '</title><style>'
		. 'html,body{height:100%;margin:0}'
		. 'body{display:grid;place-items:center;background:#f5f5f7;color:#1d1d1f;'
		. 'font:15px/1.7 -apple-system,BlinkMacSystemFont,"PingFang SC","Segoe UI",sans-serif}'
		. '.box{text-align:center;padding:32px;max-width:360px}'
		. '.dot{width:44px;height:44px;margin:0 auto 18px;border-radius:14px;'
		. 'background:linear-gradient(135deg,#0a84ff,#5e5ce6)}'
		. 'h1{margin:0 0 8px;font-size:19px;letter-spacing:-.01em}'
		. 'p{margin:0 0 18px;color:#6e6e73;font-size:13px}'
		. 'a{display:inline-block;padding:10px 18px;border-radius:999px;background:#0071e3;'
		. 'color:#fff;text-decoration:none;font-weight:600;font-size:13px}'
		. '</style></head><body><div class="box"><div class="dot"></div>'
		. '<h1>暂时连不上网络</h1><p>这篇内容没有缓存，等网络恢复后再试一次。</p>'
		. '<a href="/">回到首页</a></div></body></html>';
}

/**
 * Serve the two routes.
 */
function aurora_pwa_serve() {
	if ( ! get_query_var( 'aurora_manifest' ) && ! get_query_var( 'aurora_sw' ) ) {
		return;
	}

	// Disabled: answer 404 rather than falling through. A request that reaches
	// here has already been routed, and letting WordPress "canonicalise" it
	// sends the caller to the home page with a 200 — a silent lie that makes
	// the switch look broken in both directions.
	if ( ! aurora_pwa_enabled() ) {
		status_header( 404 );
		nocache_headers();
		$GLOBALS['wp_query']->is_404 = true;
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Not found';
		exit;
	}

	if ( get_query_var( 'aurora_manifest' ) ) {
		status_header( 200 );
		$GLOBALS['wp_query']->is_404 = false;
		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		echo wp_json_encode( aurora_pwa_manifest(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		exit;
	}

	if ( get_query_var( 'aurora_sw' ) ) {
		// wp() already decided this URL is a 404 (it is not a post, a page or an
		// archive), and that status is set before template_redirect runs — so
		// echo the worker and the browser receives 404 with a 200-looking body.
		// Reset both the status and the flag.
		status_header( 200 );
		$GLOBALS['wp_query']->is_404 = false;

		// A cached worker is a worker you cannot fix: never let this be stored.
		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		echo aurora_pwa_sw_js(); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}
add_action( 'template_redirect', 'aurora_pwa_serve', 0 );

/**
 * The worker source.
 *
 * @return string
 */
function aurora_pwa_sw_js() {
	$cache    = esc_js( aurora_pwa_cache_name() );
	$home     = esc_url( home_url( '/' ) );
	$offline  = wp_json_encode( aurora_pwa_offline_html() );
	$precache = wp_json_encode(
		array_values(
			array_filter(
				array(
					home_url( '/' ),
					get_theme_file_uri( 'assets/css/main.css' ),
					get_theme_file_uri( 'assets/js/main.js' ),
					get_theme_file_uri( 'assets/img/app-icon.svg' ),
				)
			)
		)
	);

	return <<<JS
/* Aurora service worker — generated, version {$cache} */
const CACHE = '{$cache}';
const OFFLINE_HTML = {$offline};
const PRECACHE = {$precache};

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE).then((c) => c.addAll(PRECACHE).catch(() => {})).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== location.origin) return;
  // Admin, ajax and login must never come from a cache.
  if (/\\/(wp-admin|wp-login\\.php|wp-json|admin-ajax\\.php)/.test(url.pathname)) return;

  if (req.mode === 'navigate') {
    e.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
          return res;
        })
        .catch(() =>
          caches.match(req).then((hit) => hit || new Response(OFFLINE_HTML, { headers: { 'Content-Type': 'text/html; charset=utf-8' } }))
        )
    );
    return;
  }

  const isAsset = /\\.(css|js|mjs|svg|png|jpe?g|webp|avif|gif|woff2?|ttf|ico)$/i.test(url.pathname);
  if (!isAsset) return;

  e.respondWith(
    caches.match(req).then((hit) => {
      if (hit) return hit;
      return fetch(req).then((res) => {
        if (res && res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
        }
        return res;
      });
    })
  );
});
JS;
}

/**
 * Register the worker in the footer.
 */
function aurora_pwa_register() {
	if ( ! aurora_pwa_enabled() || is_admin() ) {
		return;
	}
	?>
	<script>
	if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
		window.addEventListener('load', function () {
			navigator.serviceWorker.register('<?php echo esc_url( home_url( '/sw.js' ) ); ?>', { scope: '<?php echo esc_url( home_url( '/' ) ); ?>' })
				.catch(function () { /* https-only, and a failure is not worth breaking the page over */ });
		});
	}
	</script>
	<link rel="manifest" href="<?php echo esc_url( home_url( '/manifest.webmanifest' ) ); ?>">
	<?php
}
add_action( 'wp_footer', 'aurora_pwa_register', 30 );
