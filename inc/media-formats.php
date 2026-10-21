<?php
/**
 * Video and audio posts.
 *
 * The theme has declared post-formats support for a while, but a "video" post
 * was only a label: nothing rendered a player, so the format was decoration.
 * This adds one field (a media URL) and one player block.
 *
 * Two kinds of URL are accepted:
 *   - a file on this site or a CDN (.mp4/.webm/.ogg/.mp3/.m4a) → native player
 *   - a page from a service WordPress can oEmbed (YouTube, Vimeo, Bilibili…)
 *     → the service iframe
 *
 * Anything else renders nothing: a broken player is worse than no player.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

const AURORA_MEDIA_META = '_aurora_media_url';

/**
 * Formats that get a player.
 *
 * @return string[]
 */
function aurora_media_formats() {
	return array( 'video', 'audio' );
}

/**
 * Is this post a media post?
 *
 * @param int $post_id Post.
 * @return string 'video'|'audio'|''
 */
function aurora_media_kind( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : get_the_ID();
	$format  = get_post_format( $post_id );

	return in_array( $format, aurora_media_formats(), true ) ? $format : '';
}

/**
 * The stored media URL, cleaned.
 *
 * @param int $post_id Post.
 * @return string
 */
function aurora_media_url( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : get_the_ID();
	$url     = (string) get_post_meta( $post_id, AURORA_MEDIA_META, true );

	return esc_url_raw( $url, array( 'http', 'https' ) );
}

/**
 * Is the URL a file we can hand to <video>/<audio>?
 *
 * @param string $url URL.
 * @param string $kind video|audio.
 * @return bool
 */
function aurora_media_is_file( $url, $kind ) {
	$path = strtok( (string) wp_parse_url( $url, PHP_URL_PATH ), '?' );
	$ext  = strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );

	$video = array( 'mp4', 'm4v', 'webm', 'ogv', 'mov' );
	$audio = array( 'mp3', 'm4a', 'aac', 'wav', 'ogg', 'oga', 'flac' );

	return in_array( $ext, 'video' === $kind ? $video : $audio, true );
}

/**
 * MIME type for a file URL.
 *
 * @param string $url URL.
 * @return string
 */
function aurora_media_mime( $url ) {
	$map = array(
		'mp4'  => 'video/mp4',
		'm4v'  => 'video/mp4',
		'mov'  => 'video/quicktime',
		'webm' => 'video/webm',
		'ogv'  => 'video/ogg',
		'mp3'  => 'audio/mpeg',
		'm4a'  => 'audio/mp4',
		'aac'  => 'audio/aac',
		'wav'  => 'audio/wav',
		'ogg'  => 'audio/ogg',
		'oga'  => 'audio/ogg',
		'flac' => 'audio/flac',
	);

	$path = strtok( (string) wp_parse_url( $url, PHP_URL_PATH ), '?' );
	$ext  = strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );

	return isset( $map[ $ext ] ) ? $map[ $ext ] : '';
}

/**
 * The player block, above the article body.
 */
function aurora_media_player() {
	if ( ! is_singular( 'post' ) ) {
		return;
	}

	$post_id = get_the_ID();
	$kind    = aurora_media_kind( $post_id );
	$url     = aurora_media_url( $post_id );

	if ( ! $kind || ! $url ) {
		return;
	}

	// A locked article must not ship its media either: the player is the
	// content for these posts.
	if ( function_exists( 'aurora_content_locked' ) && aurora_content_locked( $post_id ) ) {
		return;
	}

	$poster = get_the_post_thumbnail_url( $post_id, 'aurora-card-wide' );
	?>
	<figure class="aurora-media aurora-media--<?php echo esc_attr( $kind ); ?>">
		<?php
		if ( aurora_media_is_file( $url, $kind ) ) {
			$mime = aurora_media_mime( $url );

			if ( 'video' === $kind ) {
				printf(
					'<video class="aurora-media__player" controls preload="metadata" playsinline%s>',
					$poster ? ' poster="' . esc_url( $poster ) . '"' : ''
				);
				printf( '<source src="%s"%s>', esc_url( $url ), $mime ? ' type="' . esc_attr( $mime ) . '"' : '' );
				esc_html_e( '你的浏览器不支持内嵌视频，可以下载后观看。', 'aurora' );
				echo '</video>';
			} else {
				echo '<audio class="aurora-media__player" controls preload="metadata">';
				printf( '<source src="%s"%s>', esc_url( $url ), $mime ? ' type="' . esc_attr( $mime ) . '"' : '' );
				esc_html_e( '你的浏览器不支持内嵌音频。', 'aurora' );
				echo '</audio>';
			}
		} else {
			$embed = wp_oembed_get( $url, array( 'width' => 960 ) );

			if ( ! $embed ) {
				// Unknown host or a page that refuses embedding: render nothing
				// rather than an empty frame.
				return;
			}

			echo '<div class="aurora-media__embed">' . $embed . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		?>
		<?php if ( has_post_thumbnail() && 'video' !== $kind ) : ?>
			<figcaption class="aurora-media__caption"><?php echo esc_html( get_the_post_thumbnail_caption() ); ?></figcaption>
		<?php endif; ?>
	</figure>
	<?php
}
add_action( 'aurora_before_content', 'aurora_media_player' );

/**
 * A small badge so list cards say what they are.
 *
 * @param int $post_id Post.
 */
function aurora_media_badge( $post_id = 0 ) {
	$kind = aurora_media_kind( $post_id );

	if ( ! $kind ) {
		return;
	}

	printf(
		'<span class="aurora-format-badge aurora-format-badge--%1$s">%2$s</span>',
		esc_attr( $kind ),
		esc_html( 'video' === $kind ? __( '视频', 'aurora' ) : __( '音频', 'aurora' ) )
	);
}

/**
 * Metabox: media URL + a hint about what is accepted.
 */
function aurora_media_metabox() {
	add_meta_box(
		'aurora-media',
		__( '媒体地址', 'aurora' ),
		'aurora_media_metabox_html',
		'post',
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes', 'aurora_media_metabox' );

/**
 * Metabox body.
 *
 * @param WP_Post $post Post.
 */
function aurora_media_metabox_html( $post ) {
	wp_nonce_field( 'aurora_media_save', 'aurora_media_nonce' );

	$value = (string) get_post_meta( $post->ID, AURORA_MEDIA_META, true );
	$kind  = get_post_format( $post->ID );
	?>
	<p>
		<label for="aurora-media-url" class="screen-reader-text"><?php esc_html_e( '媒体地址', 'aurora' ); ?></label>
		<input type="url" id="aurora-media-url" name="aurora_media_url" class="widefat"
			value="<?php echo esc_attr( $value ); ?>" placeholder="https://…">
	</p>
	<p class="description">
		<?php esc_html_e( '视频或音频文件地址（mp4 / webm / mp3 / m4a…），或 YouTube、Bilibili、Vimeo 等可嵌入页面的链接。只在把文章格式设为「视频」或「音频」时显示播放器。', 'aurora' ); ?>
	</p>
	<?php if ( ! in_array( $kind, aurora_media_formats(), true ) ) : ?>
		<p class="description">
			<strong><?php esc_html_e( '当前格式不是视频/音频，保存后不会显示播放器。', 'aurora' ); ?></strong>
		</p>
	<?php endif; ?>
	<?php
}

/**
 * Save the metabox.
 *
 * @param int $post_id Post.
 */
function aurora_media_save( $post_id ) {
	if ( ! isset( $_POST['aurora_media_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['aurora_media_nonce'] ) ), 'aurora_media_save' ) ) {
		return;
	}

	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$raw = isset( $_POST['aurora_media_url'] ) ? trim( (string) wp_unslash( $_POST['aurora_media_url'] ) ) : '';

	// esc_url_raw() drops javascript:, data: and anything else not http(s).
	$url = $raw ? esc_url_raw( $raw, array( 'http', 'https' ) ) : '';

	if ( $url ) {
		update_post_meta( $post_id, AURORA_MEDIA_META, $url );
	} else {
		delete_post_meta( $post_id, AURORA_MEDIA_META );
	}
}
add_action( 'save_post_post', 'aurora_media_save' );
