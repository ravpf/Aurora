<?php
/**
 * Curated directory of outbound links.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the link post type and its category taxonomy.
 */
function aurora_register_link_post_type() {
	register_post_type( 'aurora_link', array(
		'labels'        => array(
			'name'          => __( '网址导航', 'aurora' ),
			'singular_name' => __( '网址', 'aurora' ),
			'add_new_item'  => __( '添加网址', 'aurora' ),
			'edit_item'     => __( '编辑网址', 'aurora' ),
			'all_items'     => __( '全部网址', 'aurora' ),
			'search_items'  => __( '搜索网址', 'aurora' ),
		),
		'public'        => true,
		// The archive slug would shadow the /nav page template, so individual
		// entries live under /link/ and the directory is the page.
		'has_archive'   => false,
		'menu_icon'     => 'dashicons-admin-links',
		'menu_position' => 23,
		'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ),
		'rewrite'       => array( 'slug' => 'link', 'with_front' => false ),
		'show_in_rest'  => true,
	) );

	register_taxonomy( 'aurora_link_cat', 'aurora_link', array(
		'labels'            => array(
			'name'          => __( '网址分类', 'aurora' ),
			'singular_name' => __( '网址分类', 'aurora' ),
		),
		'hierarchical'      => true,
		'public'            => true,
		'show_admin_column' => true,
		'show_in_rest'      => true,
		'rewrite'           => array( 'slug' => 'nav-cat' ),
	) );
}
add_action( 'init', 'aurora_register_link_post_type' );

/**
 * Meta box fields for a link.
 */
function aurora_link_metabox() {
	add_meta_box(
		'aurora-link-meta',
		__( 'Aurora · 网址信息', 'aurora' ),
		'aurora_link_metabox_html',
		'aurora_link',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'aurora_link_metabox' );

/**
 * Render the link meta box.
 *
 * @param WP_Post $post Post being edited.
 */
function aurora_link_metabox_html( $post ) {
	wp_nonce_field( 'aurora_save_link', 'aurora_link_nonce' );

	$url   = get_post_meta( $post->ID, '_aurora_link_url', true );
	$icon  = get_post_meta( $post->ID, '_aurora_link_icon', true );
	$color = get_post_meta( $post->ID, '_aurora_link_color', true );
	$note  = get_post_meta( $post->ID, '_aurora_link_note', true );
	?>
	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><label for="aurora_link_url"><?php esc_html_e( '目标网址', 'aurora' ); ?></label></th>
				<td>
					<input type="url" class="large-text" id="aurora_link_url" name="aurora_link_url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://">
					<p class="description"><?php esc_html_e( '必填。留空则该条目不会出现在导航页。', 'aurora' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="aurora_link_icon"><?php esc_html_e( '图标图片地址 / 首字母', 'aurora' ); ?></label></th>
				<td>
					<input type="text" class="large-text" id="aurora_link_icon" name="aurora_link_icon" value="<?php echo esc_attr( $icon ); ?>" placeholder="https://… 或留空">
					<p class="description"><?php esc_html_e( '留空会自动抓取站点 favicon，抓不到则用首字母色块。', 'aurora' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="aurora_link_color"><?php esc_html_e( '主题色', 'aurora' ); ?></label></th>
				<td>
					<input type="text" id="aurora_link_color" name="aurora_link_color" value="<?php echo esc_attr( $color ); ?>" placeholder="#0071e3">
					<p class="description"><?php esc_html_e( '用于首字母色块背景，留空自动生成。', 'aurora' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="aurora_link_note"><?php esc_html_e( '一句话说明', 'aurora' ); ?></label></th>
				<td><input type="text" class="large-text" id="aurora_link_note" name="aurora_link_note" value="<?php echo esc_attr( $note ); ?>"></td>
			</tr>
		</tbody>
	</table>
	<?php
}

/**
 * Persist the link meta box.
 *
 * @param int $post_id Post ID.
 */
function aurora_save_link_metabox( $post_id ) {
	if ( ! isset( $_POST['aurora_link_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['aurora_link_nonce'] ), 'aurora_save_link' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	update_post_meta( $post_id, '_aurora_link_url', esc_url_raw( wp_unslash( $_POST['aurora_link_url'] ?? '' ) ) );
	update_post_meta( $post_id, '_aurora_link_icon', esc_url_raw( wp_unslash( $_POST['aurora_link_icon'] ?? '' ) ) );
	update_post_meta( $post_id, '_aurora_link_color', sanitize_hex_color( wp_unslash( $_POST['aurora_link_color'] ?? '' ) ) );
	update_post_meta( $post_id, '_aurora_link_note', sanitize_text_field( wp_unslash( $_POST['aurora_link_note'] ?? '' ) ) );
}
add_action( 'save_post_aurora_link', 'aurora_save_link_metabox' );

/**
 * Normalise a URL for favicon lookups and display.
 *
 * @param string $url Raw URL.
 * @return string
 */
function aurora_link_host( $url ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );

	return $host ? preg_replace( '/^www\./i', '', $host ) : '';
}

/**
 * Deterministic accent colour for a link's letter tile.
 *
 * @param string $seed Seed string.
 * @return string Hue in degrees.
 */
function aurora_link_hue( $seed ) {
	return (int) ( hexdec( substr( md5( $seed ), 0, 4 ) ) % 360 );
}

/**
 * Fetch and cache a site's favicon. Returns a URL or ''.
 *
 * Never performs a network request during page render: a cold cache yields ''
 * and the caller falls back to the letter tile. Icons are warmed when the link
 * is saved and by the admin "抓取全部图标" action, so the front end stays fast.
 *
 * @param int  $post_id   Link post ID.
 * @param bool $allow_net Permit an outbound request (save/admin contexts only).
 * @return string
 */
function aurora_link_favicon( $post_id, $allow_net = false ) {
	$manual = (string) get_post_meta( $post_id, '_aurora_link_icon', true );
	if ( $manual && aurora_link_icon_is_local( $manual ) ) {
		return $manual;
	}

	$cached = (string) get_post_meta( $post_id, '_aurora_link_favicon', true );
	if ( 'none' === $cached ) {
		return '';
	}
	// Only a copy we serve ourselves ever goes into an <img>: a remote URL
	// makes every visitor's browser call a third-party host, and one that
	// cannot be reached hangs the load event instead of failing. Anything
	// stored remotely is treated as a miss so the card shows its letter tile.
	if ( $cached ) {
		return aurora_link_icon_is_local( $cached ) ? $cached : '';
	}

	if ( ! $allow_net || ! get_theme_mod( 'aurora_nav_fetch_favicon', true ) ) {
		return '';
	}

	return aurora_link_warm_favicon( $post_id );
}

/**
 * Is this icon served from this site?
 *
 * @param string $url URL.
 * @return bool
 */
function aurora_link_icon_is_local( $url ) {
	$url = (string) $url;
	if ( '' === $url ) {
		return false;
	}
	if ( 0 === strpos( $url, '/' ) ) {
		return true;
	}

	$host = wp_parse_url( $url, PHP_URL_HOST );
	$home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

	return (bool) ( $host && $home && strtolower( $host ) === strtolower( $home ) );
}

/**
 * Download an icon and keep a copy on this site.
 *
 * @param int    $post_id Link post ID.
 * @param string $url     Remote icon URL.
 * @return string Local URL, or '' when it could not be kept.
 */
/**
 * Does this response body actually look like an image?
 *
 * Checked by magic bytes, because the URL extension and the Content-Type both
 * lie in practice: an HTML error page served at /favicon.ico is stored happily
 * and renders as nothing.
 *
 * @param string $body Raw response body.
 * @return bool
 */
function aurora_link_body_is_image( $body ) {
	if ( strlen( $body ) < 4 ) {
		return false;
	}

	// ICO and CUR.
	if ( "\x00\x00\x01\x00" === substr( $body, 0, 4 ) || "\x00\x00\x02\x00" === substr( $body, 0, 4 ) ) {
		return true;
	}
	if ( "\x89PNG\r\n\x1a\n" === substr( $body, 0, 8 ) ) {
		return true;
	}
	if ( 'GIF87a' === substr( $body, 0, 6 ) || 'GIF89a' === substr( $body, 0, 6 ) ) {
		return true;
	}
	if ( "\xFF\xD8\xFF" === substr( $body, 0, 3 ) ) {
		return true;
	}
	if ( 'BM' === substr( $body, 0, 2 ) ) {
		return true;
	}
	if ( 'RIFF' === substr( $body, 0, 4 ) && 'WEBP' === substr( $body, 8, 4 ) ) {
		return true;
	}

	// SVG is deliberately absent: a third party's SVG is markup on our origin.
	return false;
}

function aurora_link_store_icon( $post_id, $url ) {
	$upload = wp_upload_dir();
	if ( ! empty( $upload['error'] ) ) {
		return '';
	}

	$name = 'aurora-link-' . (int) $post_id . '-' . substr( md5( $url ), 0, 10 );

	// Already downloaded on an earlier pass; avoid the round trip.
	foreach ( array( 'ico', 'png', 'jpg', 'gif', 'webp', 'bmp' ) as $maybe ) {
		if ( file_exists( trailingslashit( $upload['path'] ) . $name . '.' . $maybe ) ) {
			return trailingslashit( $upload['url'] ) . $name . '.' . $maybe;
		}
	}

	$res = wp_remote_get( $url, array(
		'timeout'     => 8,
		'redirection' => 3,
		'headers'     => array( 'User-Agent' => 'Mozilla/5.0 (compatible; Aurora/1.0)' ),
	) );
	if ( is_wp_error( $res ) ) {
		return '';
	}

	$code = (int) wp_remote_retrieve_response_code( $res );
	$type = strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) );
	$body = (string) wp_remote_retrieve_body( $res );

	if ( $code < 200 || $code >= 400 || '' === $body ) {
		return '';
	}

	// An SVG fetched from a third party would run as our own origin's markup.
	if ( false !== strpos( $type, 'svg' ) || preg_match( '/\.svgz?($|\?)/i', $url ) ) {
		return '';
	}

	$map = array(
		'image/png'                => 'png',
		'image/jpeg'               => 'jpg',
		'image/jpg'                => 'jpg',
		'image/gif'                => 'gif',
		'image/webp'               => 'webp',
		'image/bmp'                => 'bmp',
		'image/x-icon'             => 'ico',
		'image/vnd.microsoft.icon' => 'ico',
	);

	$mime = trim( strtok( $type, ';' ) );
	$ext  = isset( $map[ $mime ] ) ? $map[ $mime ] : '';

	if ( ! $ext ) {
		if ( preg_match( '/\.(ico|png|jpe?g|gif|webp|bmp)($|\?)/i', $url, $m ) ) {
			$ext = strtolower( $m[1] );
		} elseif ( false === stripos( $type, 'image' ) ) {
			return '';
		} else {
			$ext = 'ico';
		}
	}
	if ( 'jpeg' === $ext ) {
		$ext = 'jpg';
	}

	if ( strlen( $body ) > 512 * 1024 ) {
		return '';
	}

	// The extension follows the URL and the content-type, but neither proves the
	// body is an image: plenty of hosts answer /favicon.ico with an HTML error
	// page or a JSON stub, and storing that yields a card icon that never
	// renders (the browser reports it complete with naturalWidth 0).
	if ( ! aurora_link_body_is_image( $body ) ) {
		return '';
	}

	$stored = wp_upload_bits( $name . '.' . $ext, null, $body );
	if ( ! empty( $stored['error'] ) || empty( $stored['url'] ) ) {
		return '';
	}

	return $stored['url'];
}

/**
 * Probe a site for its icon and remember the answer (including a miss).
 *
 * @param int $post_id Link post ID.
 * @return string Icon URL, or '' when nothing usable was found.
 */
function aurora_link_warm_favicon( $post_id ) {
	$url = (string) get_post_meta( $post_id, '_aurora_link_url', true );
	if ( ! $url ) {
		return '';
	}

	$host = aurora_link_host( $url );
	if ( ! $host ) {
		update_post_meta( $post_id, '_aurora_link_favicon', 'none' );
		return '';
	}

	// A manually supplied remote icon is downloaded here once, so visitors'
	// browsers never have to reach that host themselves.
	$manual = (string) get_post_meta( $post_id, '_aurora_link_icon', true );
	if ( $manual && ! aurora_link_icon_is_local( $manual ) ) {
		$local = aurora_link_store_icon( $post_id, $manual );
		if ( $local ) {
			update_post_meta( $post_id, '_aurora_link_favicon', esc_url_raw( $local ) );
			return $local;
		}
	}

	$candidates = array();

	$page = wp_remote_get( $url, array(
		'timeout'     => 6,
		'redirection' => 3,
		'headers'     => array( 'User-Agent' => 'Mozilla/5.0 (compatible; Aurora/1.0)' ),
	) );
	if ( ! is_wp_error( $page ) ) {
		$html = wp_remote_retrieve_body( $page );
		if ( preg_match( '/<link[^>]+rel=["\'][^"\']*(?:icon|shortcut icon)["\'][^>]*>/i', $html, $m ) ) {
			if ( preg_match( '/href=["\']([^"\']+)["\']/i', $m[0], $h ) ) {
				$href = html_entity_decode( trim( $h[1] ), ENT_QUOTES, 'UTF-8' );
				if ( 0 === strpos( $href, '//' ) ) {
					$href = 'https:' . $href;
				}
				if ( preg_match( '#^https?://#i', $href ) ) {
					$candidates[] = $href;
				} elseif ( 0 === strpos( $href, '/' ) ) {
					$candidates[] = 'https://' . $host . $href;
				}
			}
		}
	}

	$candidates[] = 'https://' . $host . '/favicon.ico';

	foreach ( array_unique( $candidates ) as $candidate ) {
		$head = wp_remote_head( $candidate, array( 'timeout' => 5, 'redirection' => 3 ) );
		if ( is_wp_error( $head ) ) {
			continue;
		}
		$code = (int) wp_remote_retrieve_response_code( $head );
		$type = (string) wp_remote_retrieve_header( $head, 'content-type' );
		$ok   = $code > 0 && $code < 400 && ( false !== stripos( $type, 'image' ) || false !== stripos( $candidate, '.ico' ) );

		// Some hosts refuse HEAD but serve GET fine.
		if ( ! $ok ) {
			$get = wp_remote_get( $candidate, array( 'timeout' => 5, 'redirection' => 3 ) );
			if ( ! is_wp_error( $get ) ) {
				$gcode = (int) wp_remote_retrieve_response_code( $get );
				$gtype = (string) wp_remote_retrieve_header( $get, 'content-type' );
				$ok    = $gcode > 0 && $gcode < 400 && false !== stripos( $gtype, 'image' );
			}
		}

		if ( $ok ) {
			$local = aurora_link_store_icon( $post_id, $candidate );
			if ( $local ) {
				update_post_meta( $post_id, '_aurora_link_favicon', esc_url_raw( $local ) );
				return $local;
			}
		}
	}

	// Cache the miss so the admin action does not re-probe forever.
	update_post_meta( $post_id, '_aurora_link_favicon', 'none' );

	return '';
}

/**
 * Warm the icon as soon as a link is saved, so the directory is ready to serve.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an update.
 */
function aurora_link_warm_on_save( $post_id, $post, $update ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		return;
	}

	delete_post_meta( $post_id, '_aurora_link_favicon' );
	aurora_link_warm_favicon( $post_id );
}
add_action( 'save_post_aurora_link', 'aurora_link_warm_on_save', 20, 3 );

/**
 * Admin action: warm every link's icon at once.
 */
function aurora_link_bulk_favicons() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! isset( $_GET['aurora_nav_favicons'] ) ) {
		return;
	}
	check_admin_referer( 'aurora_nav_favicons' );

	$ids   = get_posts( array(
		'post_type'      => 'aurora_link',
		'posts_per_page' => 200,
		'post_status'    => 'publish',
		'fields'         => 'ids',
	) );
	$found = 0;

	foreach ( $ids as $id ) {
		delete_post_meta( $id, '_aurora_link_favicon' );
		if ( aurora_link_warm_favicon( $id ) ) {
			$found++;
		}
	}

	wp_safe_redirect( add_query_arg( array(
		'post_type'         => 'aurora_link',
		'aurora_fav_done'   => $found,
		'aurora_fav_total'  => count( $ids ),
	), admin_url( 'edit.php' ) ) );
	exit;
}
add_action( 'admin_init', 'aurora_link_bulk_favicons' );

/**
 * "Warm icons" button above the link list.
 */
function aurora_link_favicon_button() {
	$screen = get_current_screen();
	if ( ! $screen || 'edit-aurora_link' !== $screen->id ) {
		return;
	}

	$url = wp_nonce_url( admin_url( 'edit.php?post_type=aurora_link&aurora_nav_favicons=1' ), 'aurora_nav_favicons' );

	printf(
		'<div class="notice notice-info" style="padding:10px 14px"><p style="margin:0">%s <a class="button button-small" style="margin-left:8px" href="%s">%s</a>',
		esc_html__( '图标在保存网址时自动抓取。批量重抓或首次导入后可点：', 'aurora' ),
		esc_url( $url ),
		esc_html__( '抓取全部图标', 'aurora' )
	);
	echo '</p></div>';

	if ( isset( $_GET['aurora_fav_done'] ) ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf(
				/* translators: 1: found count, 2: total count */
				__( '已抓取 %1$d / %2$d 个图标。未抓到的会显示首字母色块。', 'aurora' ),
				(int) $_GET['aurora_fav_done'],
				(int) ( $_GET['aurora_fav_total'] ?? 0 )
			) )
		);
	}
}
add_action( 'admin_notices', 'aurora_link_favicon_button' );

/**
 * Render one navigation card.
 *
 * @param int $post_id Link post ID.
 */
function aurora_render_link_card( $post_id ) {
	$url  = (string) get_post_meta( $post_id, '_aurora_link_url', true );
	if ( ! $url ) {
		return;
	}

	$note  = (string) get_post_meta( $post_id, '_aurora_link_note', true );
	$color = (string) get_post_meta( $post_id, '_aurora_link_color', true );
	$icon  = aurora_link_favicon( $post_id );
	$host  = aurora_link_host( $url );
	$title = get_the_title( $post_id );

	$cats = wp_get_post_terms( $post_id, 'aurora_link_cat', array( 'fields' => 'names' ) );
	$cat_slug = '';
	$terms = wp_get_post_terms( $post_id, 'aurora_link_cat', array( 'fields' => 'slugs' ) );
	if ( $terms ) {
		$cat_slug = $terms[0];
	}

	$style = $color
		? 'background:' . $color
		: sprintf( 'background:hsl(%d 72%% 55%%)', aurora_link_hue( $host ) );
	?>
	<a class="aurora-link-card aurora-reveal"
		href="<?php echo esc_url( $url ); ?>"
		target="_blank" rel="noopener nofollow"
		data-name="<?php echo esc_attr( $title . ' ' . $host . ' ' . $note . ' ' . implode( ' ', (array) $cats ) ); ?>"
		data-cat="<?php echo esc_attr( $cat_slug ); ?>">
		<span class="aurora-link-card__icon" style="<?php echo esc_attr( $style ); ?>">
			<?php if ( $icon && 'none' !== $icon ) : ?>
				<img src="<?php echo esc_url( $icon ); ?>" alt="" loading="lazy" onerror="this.style.display='none';this.parentNode.classList.add('is-fallback')">
			<?php else : ?>
				<span><?php echo esc_html( mb_strtoupper( mb_substr( $title, 0, 1 ) ) ); ?></span>
			<?php endif; ?>
			<span class="aurora-link-card__letter"><?php echo esc_html( mb_strtoupper( mb_substr( $title, 0, 1 ) ) ); ?></span>
		</span>
		<span class="aurora-link-card__body">
			<span class="aurora-link-card__title"><?php echo esc_html( $title ); ?></span>
			<span class="aurora-link-card__host"><?php echo esc_html( $host ); ?></span>
			<?php if ( $note ) : ?>
				<span class="aurora-link-card__note"><?php echo esc_html( $note ); ?></span>
			<?php else : ?>
				<span class="aurora-link-card__note"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ), 14 ) ); ?></span>
			<?php endif; ?>
		</span>
	</a>
	<?php
}

/**
 * All link categories that actually contain entries.
 *
 * @return array
 */
function aurora_link_categories() {
	$terms = get_terms( array(
		'taxonomy'   => 'aurora_link_cat',
		'hide_empty' => true,
	) );

	if ( is_wp_error( $terms ) ) {
		return array();
	}

	// Sort the sections deliberately. The default is by name, which for Chinese
	// names means code-point order and scatters related groups across the page.
	// A category without an explicit order falls to the end, alphabetically.
	usort(
		$terms,
		function ( $a, $b ) {
			$oa = get_term_meta( $a->term_id, '_aurora_link_order', true );
			$ob = get_term_meta( $b->term_id, '_aurora_link_order', true );
			$oa = '' === $oa ? 9999 : (int) $oa;
			$ob = '' === $ob ? 9999 : (int) $ob;

			if ( $oa !== $ob ) {
				return $oa - $ob;
			}

			return strcmp( $a->name, $b->name );
		}
	);

	return $terms;
}
