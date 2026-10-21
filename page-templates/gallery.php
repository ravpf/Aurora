<?php
/**
 * Template Name: 图库
 *
 * Waterfall gallery of the gallery pool (its own uploads), with a lightbox
 * viewer and "load more" pagination. Article images are deliberately absent.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();

aurora_breadcrumbs();

$aurora_ids   = aurora_gallery_page_ids();
$aurora_total = count( aurora_gallery_image_ids() );
$aurora_page  = min( aurora_gallery_page(), aurora_gallery_max_pages() );
$aurora_max   = aurora_gallery_max_pages();
?>

<div class="aurora-container">
	<header class="aurora-pagehead">
		<h1 class="aurora-pagehead__title"><?php echo esc_html( get_the_title() ); ?></h1>

		<?php if ( trim( (string) get_the_content() ) ) : ?>
			<div class="aurora-pagehead__desc"><?php the_content(); ?></div>
		<?php else : ?>
			<p class="aurora-pagehead__desc"><?php esc_html_e( '本站图库作品，瀑布流排布。点开放大，左右方向键翻看。', 'aurora' ); ?></p>
		<?php endif; ?>

		<?php if ( $aurora_total ) : ?>
			<p class="aurora-pagehead__meta">
				<?php
				printf(
					/* translators: 1: number of images, 2: current page, 3: total pages. */
					esc_html__( '共 %1$s 张图片 · 第 %2$s / %3$s 页', 'aurora' ),
					esc_html( number_format_i18n( $aurora_total ) ),
					esc_html( number_format_i18n( $aurora_page ) ),
					esc_html( number_format_i18n( $aurora_max ) )
				);
				?>
			</p>
		<?php endif; ?>
	</header>

	<?php if ( ! $aurora_ids ) : ?>
		<div class="aurora-empty">
			<?php aurora_the_icon( 'grid', 32 ); ?>
			<h3><?php esc_html_e( '图库还是空的', 'aurora' ); ?></h3>
			<p><?php esc_html_e( '图库有独立的图片池：后台「媒体 → 图库」上传的图片才会显示在这里，文章配图不会混进来。', 'aurora' ); ?></p>
			<?php if ( current_user_can( 'upload_files' ) ) : ?>
				<p><a class="aurora-btn aurora-btn--primary" href="<?php echo esc_url( admin_url( 'upload.php?page=aurora-gallery' ) ); ?>"><?php esc_html_e( '去上传图库图片', 'aurora' ); ?></a></p>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<div class="aurora-gallery" id="aurora-gallery" data-count="<?php echo (int) $aurora_total; ?>">
			<?php
			foreach ( $aurora_ids as $aurora_id ) {
				aurora_gallery_tile( $aurora_id );
			}
			?>
		</div>

		<div class="aurora-loadmore">
			<?php if ( $aurora_page < $aurora_max ) : ?>
				<button type="button" class="aurora-btn aurora-btn--primary aurora-btn--lg"
					data-next="<?php echo esc_url( add_query_arg( 'aurora_pg', $aurora_page + 1, get_permalink() ) ); ?>"
					data-target="#aurora-gallery"
					data-item=".aurora-gallery__item">
					<?php esc_html_e( '加载更多', 'aurora' ); ?>
				</button>
			<?php else : ?>
				<button type="button" class="aurora-btn aurora-btn--lg" disabled>
					<?php esc_html_e( '没有更多了', 'aurora' ); ?>
				</button>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>

<?php
get_footer();
