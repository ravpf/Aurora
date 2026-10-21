<?php
/**
 * Front-end gallery: an image waterfall built from its own pool.
 *
 * The pool is a flag on the attachment (`_aurora_gallery`), not the whole media
 * library. Article images — covers, inline shots, editor uploads — carry no
 * flag, so they can never turn up between gallery tiles. The reverse matters
 * too: gallery uploads are not inserted into posts, so an article never
 * alternates its own pictures with gallery art.
 *
 * Only images this site serves are listed. Harvesting <img> sources out of post
 * content would put third-party hosts back into the page, and a host that
 * cannot be reached hangs the load event rather than failing — which is exactly
 * the bug this theme had to fix earlier.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Meta key marking an attachment as a gallery item.
 */
const AURORA_GALLERY_META = '_aurora_gallery';

/**
 * How many images one gallery page holds.
 *
 * @return int
 */
function aurora_gallery_per_page() {
	return max( 1, (int) apply_filters( 'aurora_gallery_per_page', 24 ) );
}

/**
 * Which page of the gallery is being viewed.
 *
 * Uses its own query argument rather than `page`, which WordPress reserves for
 * paginating a static page's own content.
 *
 * @return int
 */
function aurora_gallery_page() {
	if ( ! isset( $_GET['aurora_pg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view offset.
		return 1;
	}

	return max( 1, absint( wp_unslash( $_GET['aurora_pg'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/**
 * Is this attachment in the gallery pool?
 *
 * @param int $id Attachment ID.
 * @return bool
 */
function aurora_gallery_is_item( $id ) {
	return (bool) get_post_meta( (int) $id, AURORA_GALLERY_META, true );
}

/**
 * Put attachments into the pool. Used by the upload screen.
 *
 * @param int[] $ids Attachment IDs.
 * @return int Number of newly added items.
 */
function aurora_gallery_add( $ids ) {
	$added = 0;

	foreach ( (array) $ids as $id ) {
		$id = (int) $id;

		if ( ! $id || 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
			continue;
		}

		if ( aurora_gallery_is_item( $id ) ) {
			continue;
		}

		update_post_meta( $id, AURORA_GALLERY_META, '1' );
		$added++;
	}

	if ( $added ) {
		do_action( 'aurora_gallery_pool_added', $ids, $added );
	}

	return $added;
}

/**
 * Take attachments out of the pool. The file is left alone.
 *
 * @param int[] $ids Attachment IDs.
 * @return int Number removed.
 */
function aurora_gallery_remove( $ids ) {
	$removed = 0;

	foreach ( (array) $ids as $id ) {
		$id = (int) $id;

		if ( $id && aurora_gallery_is_item( $id ) ) {
			delete_post_meta( $id, AURORA_GALLERY_META );
			$removed++;
		}
	}

	if ( $removed ) {
		do_action( 'aurora_gallery_pool_removed', $ids, $removed );
	}

	return $removed;
}

/**
 * Gallery pool size.
 *
 * @return int
 */
function aurora_gallery_item_count() {
	return count( aurora_gallery_image_ids() );
}

/**
 * Gallery items, newest first.
 *
 * @return int[] Attachment IDs.
 */
function aurora_gallery_image_ids() {
	$ids = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => AURORA_GALLERY_META,
					'value'   => '1',
					'compare' => '=',
				),
			),
		)
	);

	$ids = array_map( 'intval', (array) apply_filters( 'aurora_gallery_image_ids', $ids ) );

	return array_values( array_filter( $ids ) );
}

/**
 * Total number of gallery pages.
 *
 * @return int
 */
function aurora_gallery_max_pages() {
	$total = count( aurora_gallery_image_ids() );

	return (int) max( 1, ceil( $total / aurora_gallery_per_page() ) );
}

/**
 * The attachments belonging to the page currently being viewed.
 *
 * @return int[]
 */
function aurora_gallery_page_ids() {
	$per  = aurora_gallery_per_page();
	$page = min( aurora_gallery_page(), aurora_gallery_max_pages() );

	return array_slice( aurora_gallery_image_ids(), ( $page - 1 ) * $per, $per );
}

/**
 * Is the gallery template being rendered?
 *
 * @return bool
 */
function aurora_is_gallery() {
	return is_page_template( 'page-templates/gallery.php' );
}

/**
 * Publish <link rel="next"> for the gallery.
 *
 * main.js follows the next link found in a fetched document, and WordPress only
 * emits one for paged archives — so a paginated template has to print its own.
 */
function aurora_gallery_rel_next() {
	if ( ! aurora_is_gallery() ) {
		return;
	}

	$page = min( aurora_gallery_page(), aurora_gallery_max_pages() );

	if ( $page >= aurora_gallery_max_pages() ) {
		return;
	}

	printf(
		'<link rel="next" href="%s">' . "\n",
		esc_url( add_query_arg( 'aurora_pg', $page + 1, get_permalink() ) )
	);
}
add_action( 'wp_head', 'aurora_gallery_rel_next' );

/**
 * Render one tile.
 *
 * Width and height come from the resized file so the browser reserves the right
 * space before a lazy image loads; without them a column reflows as images
 * arrive and the waterfall visibly jumps.
 *
 * @param int $id Attachment ID.
 */
function aurora_gallery_tile( $id ) {
	// Deliberately not aurora-card: that size is a hard crop, so every tile would
	// come out at the same aspect ratio and the waterfall would collapse into a
	// uniform grid. medium_large keeps each image's own proportions.
	$thumb = wp_get_attachment_image_src( $id, apply_filters( 'aurora_gallery_image_size', 'medium_large' ) );

	if ( ! $thumb ) {
		return;
	}

	$full   = wp_get_attachment_image_src( $id, 'large' );
	$parent = (int) get_post_field( 'post_parent', $id );
	$link   = ( $parent && 'publish' === get_post_status( $parent ) ) ? get_permalink( $parent ) : '';
	$title  = (string) get_the_title( $id );
	$alt    = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
	?>
	<figure class="aurora-gallery__item">
		<img src="<?php echo esc_url( $thumb[0] ); ?>"
			width="<?php echo (int) $thumb[1]; ?>"
			height="<?php echo (int) $thumb[2]; ?>"
			alt="<?php echo esc_attr( $alt ? $alt : $title ); ?>"
			loading="lazy" decoding="async"
			data-full="<?php echo esc_url( $full ? $full[0] : $thumb[0] ); ?>"
			data-caption="<?php echo esc_attr( $title ); ?>"
			<?php echo $link ? 'data-post="' . esc_url( $link ) . '"' : ''; ?>>
	</figure>
	<?php
}
