<?php
/**
 * Gallery admin: a separate pool with its own upload button.
 *
 * The reason this screen exists instead of "just use the media library": that
 * library is where article covers and inline shots live. Drawing the gallery
 * from it put a post's illustrations between gallery tiles — the same photo
 * appearing twice, in two sizes, in a row. Here an image is either in the
 * gallery pool or it is not, and articles are unaffected either way.
 *
 * The screen only flips a flag (`_aurora_gallery`); it never moves or copies a
 * file, so removing an item from the pool does not break a post that happens to
 * reference the same file.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menu entry under Media.
 */
function aurora_gallery_admin_menu() {
	add_media_page(
		__( '图库', 'aurora' ),
		__( '图库', 'aurora' ),
		'upload_files',
		'aurora-gallery',
		'aurora_gallery_admin_page'
	);
}
add_action( 'admin_menu', 'aurora_gallery_admin_menu' );

/**
 * Handle add / remove.
 */
function aurora_gallery_admin_save() {
	if ( ! current_user_can( 'upload_files' ) || ! check_admin_referer( 'aurora_gallery_pool' ) ) {
		wp_die( esc_html__( '请求已过期，请重新操作。', 'aurora' ), 403 );
	}

	$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
	$ids  = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
	$ids  = array_values( array_filter( $ids ) );

	if ( ! $ids ) {
		wp_safe_redirect( add_query_arg( 'aurora_gallery_msg', 'none', admin_url( 'upload.php?page=aurora-gallery' ) ) );
		exit;
	}

	$count = 'remove' === $mode ? aurora_gallery_remove( $ids ) : aurora_gallery_add( $ids );

	wp_safe_redirect(
		add_query_arg(
			array(
				'aurora_gallery_msg'   => 'remove' === $mode ? 'removed' : 'added',
				'aurora_gallery_count' => $count,
			),
			admin_url( 'upload.php?page=aurora-gallery' )
		)
	);
	exit;
}
add_action( 'admin_post_aurora_gallery_save', 'aurora_gallery_admin_save' );

/**
 * The screen.
 */
function aurora_gallery_admin_page() {
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( esc_html__( '没有权限。', 'aurora' ) );
	}

	$items = aurora_gallery_image_ids();
	$total = count( $items );
	$msg   = isset( $_GET['aurora_gallery_msg'] ) ? sanitize_key( wp_unslash( $_GET['aurora_gallery_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	$count = isset( $_GET['aurora_gallery_count'] ) ? absint( wp_unslash( $_GET['aurora_gallery_count'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$nonce = wp_create_nonce( 'aurora_gallery_pool' );

	wp_enqueue_media();
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( '图库', 'aurora' ); ?></h1>
		<button type="button" class="page-title-action" id="aurora-gallery-upload"><?php esc_html_e( '上传到图库', 'aurora' ); ?></button>
		<hr class="wp-header-end">

		<?php if ( 'added' === $msg ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php printf( esc_html__( '已加入图库：%d 张。', 'aurora' ), (int) $count ); ?></p></div>
		<?php elseif ( 'removed' === $msg ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php printf( esc_html__( '已移出图库：%d 张（文件仍在媒体库，文章不受影响）。', 'aurora' ), (int) $count ); ?></p></div>
		<?php elseif ( 'none' === $msg ) : ?>
			<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( '没有选中任何图片。', 'aurora' ); ?></p></div>
		<?php endif; ?>

		<p class="description" style="max-width:760px">
			<?php esc_html_e( '这里只放图库自己的图片。文章封面、正文配图属于文章，不会出现在图库；这里的图片也不会自动插进任何文章。前台「图库」页面只显示下面这些。', 'aurora' ); ?>
		</p>

		<p>
			<strong><?php printf( esc_html__( '当前图库：%d 张', 'aurora' ), (int) $total ); ?></strong>
			<?php if ( $total ) : ?>
				· <a href="<?php echo esc_url( get_permalink( get_page_by_path( 'gallery' ) ) ? get_permalink( get_page_by_path( 'gallery' ) ) : home_url( '/gallery' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '查看前台', 'aurora' ); ?></a>
			<?php endif; ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="aurora-gallery-form">
			<input type="hidden" name="action" value="aurora_gallery_save">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
			<input type="hidden" name="mode" value="remove" id="aurora-gallery-mode">

			<?php if ( $total ) : ?>
				<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px;margin:18px 0">
					<?php foreach ( $items as $id ) : ?>
						<?php
						$thumb = wp_get_attachment_image_src( $id, 'medium' );
						$file  = basename( (string) get_attached_file( $id ) );
						?>
						<label style="display:block;position:relative;border:1px solid #dcdcde;border-radius:10px;overflow:hidden;background:#fff">
							<span style="position:absolute;top:6px;left:6px;z-index:2;background:rgba(255,255,255,.9);border-radius:6px;padding:2px 6px">
								<input type="checkbox" name="ids[]" value="<?php echo (int) $id; ?>">
							</span>
							<?php if ( $thumb ) : ?>
								<img src="<?php echo esc_url( $thumb[0] ); ?>" alt="" style="display:block;width:100%;height:120px;object-fit:cover">
							<?php endif; ?>
							<span style="display:block;padding:6px 8px;font-size:12px;color:#50575e;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html( $file ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<button type="submit" class="button" onclick="return confirm('把选中的图片移出图库？文件不会删除')">
					<?php esc_html_e( '移出图库', 'aurora' ); ?>
				</button>
			<?php else : ?>
				<div style="border:1px dashed #c3c4c7;border-radius:12px;padding:32px;text-align:center;color:#646970;margin:18px 0">
					<p style="font-size:15px;margin:0 0 6px"><?php esc_html_e( '图库还没有图片。', 'aurora' ); ?></p>
					<p style="margin:0"><?php esc_html_e( '点右上角「上传到图库」，或点下面的按钮从媒体库挑选已有图片。', 'aurora' ); ?></p>
				</div>
			<?php endif; ?>

			<p>
				<button type="button" class="button button-secondary" id="aurora-gallery-pick"><?php esc_html_e( '从媒体库挑选', 'aurora' ); ?></button>
			</p>
		</form>

		<p class="description">
			<?php esc_html_e( '提示：图库图片按上传时间倒序排列。移出图库只取消标记，文件与文章引用都不动。', 'aurora' ); ?>
		</p>
	</div>

	<script>
	( function () {
		var form = document.getElementById( 'aurora-gallery-form' );
		var mode = document.getElementById( 'aurora-gallery-mode' );
		if ( ! form || ! window.wp || ! wp.media ) { return; }

		function open( uploader ) {
			var frame = wp.media( {
				title: uploader ? '上传到图库' : '从媒体库挑选图片',
				button: { text: '加入图库' },
				library: { type: 'image' },
				multiple: true
			} );

			frame.on( 'select', function () {
				var sel = frame.state().get( 'selection' ).map( function ( a ) { return a.id; } );
				if ( ! sel.length ) { return; }
				mode.value = 'add';
				sel.forEach( function ( id ) {
					var input = document.createElement( 'input' );
					input.type = 'hidden';
					input.name = 'ids[]';
					input.value = id;
					form.appendChild( input );
				} );
				form.submit();
			} );

			if ( uploader ) {
				frame.open();               // 媒体框默认停在上传页
			} else {
				frame.open();
			}
		}

		document.getElementById( 'aurora-gallery-upload' ).addEventListener( 'click', function () { open( true ); } );
		var pick = document.getElementById( 'aurora-gallery-pick' );
		if ( pick ) { pick.addEventListener( 'click', function () { open( false ); } ); }
	}() );
	</script>
	<?php
}
