<?php
/**
 * Single post template.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();

aurora_count_view( get_the_ID() );

// The breadcrumb helper existed but was never called; a singular view needs it
// for orientation now that posts and pages read as one immersive column.
aurora_breadcrumbs();
?>

<div class="aurora-container">
	<?php while ( have_posts() ) : the_post(); ?>
		<?php
		$aurora_required = (int) get_post_meta( get_the_ID(), 'aurora_vip_level', true );
		$aurora_locked   = function_exists( 'aurora_content_locked' ) && aurora_content_locked();
		$aurora_likes    = (int) get_post_meta( get_the_ID(), 'aurora_likes', true );
		$aurora_liked    = isset( $_COOKIE[ 'aurora_liked_' . get_the_ID() ] ) && '1' === $_COOKIE[ 'aurora_liked_' . get_the_ID() ];
		$aurora_bm       = is_user_logged_in() ? array_map( 'intval', (array) get_user_meta( get_current_user_id(), 'aurora_bookmarks', true ) ) : array();
		?>

		<div class="aurora-layout">
			<main id="aurora-primary" class="aurora-primary">

				<article id="post-<?php the_ID(); ?>" <?php post_class( 'aurora-article' ); ?>>
					<header class="aurora-article__header">
						<?php
						$aurora_cat = aurora_primary_category();
						if ( $aurora_cat ) :
							?>
							<div class="aurora-card__cats" style="margin-bottom:14px">
								<a class="aurora-card__cat" href="<?php echo esc_url( get_category_link( $aurora_cat ) ); ?>"><?php echo esc_html( $aurora_cat->name ); ?></a>
							</div>
						<?php endif; ?>

						<h1 class="aurora-article__title"><?php the_title(); ?><?php do_action( 'aurora_after_article_title', get_the_ID() ); ?></h1>

						<?php if ( has_excerpt() ) : ?>
							<p class="aurora-article__lead"><?php echo esc_html( get_the_excerpt() ); ?></p>
						<?php endif; ?>

						<div class="aurora-article__meta">
							<span class="aurora-article__author">
								<?php echo get_avatar( get_the_author_meta( 'ID' ), 64, '', '', array( 'class' => 'aurora-avatar' ) ); ?>
								<?php echo esc_html( get_the_author() ); ?>
								<?php aurora_vip_badge( get_the_author_meta( 'ID' ) ); ?>
							</span>
							<span><?php aurora_the_icon( 'clock', 15 ); ?><?php echo esc_html( get_the_date() ); ?></span>
							<span><?php aurora_the_icon( 'eye', 15 ); ?><?php echo esc_html( number_format_i18n( aurora_get_views() ) ); ?></span>
							<span><?php aurora_the_icon( 'fire', 15 ); ?><?php echo esc_html( aurora_reading_time() ); ?><?php esc_html_e( ' 分钟', 'aurora' ); ?></span>
							<?php if ( $aurora_required > 0 ) : ?>
								<?php $aurora_level_info = aurora_get_vip_level( $aurora_required ); ?>
								<span class="aurora-vip-badge <?php echo esc_attr( $aurora_level_info['class'] ); ?>">
									<?php aurora_the_icon( 'crown', 12 ); ?><?php echo esc_html( $aurora_level_info['name'] ); ?>
								</span>
							<?php endif; ?>
						</div>
					</header>

					<?php if ( has_post_thumbnail() && ! $aurora_locked ) : ?>
						<figure class="aurora-hero">
							<?php the_post_thumbnail( 'aurora-card-wide', array( 'loading' => 'eager' ) ); ?>
						</figure>
					<?php endif; ?>

					<?php do_action( 'aurora_before_content' ); ?>

				<div class="aurora-content">
						<?php
						if ( $aurora_locked ) {
							// Deliberately not the_excerpt(): that would leak the
							// whole body for a short protected article.
							echo '<p class="aurora-teaser">' . esc_html( aurora_safe_excerpt( get_the_ID(), 40 ) ) . '</p>';

							// A priced article gets its own purchase panel; a
							// level-gated one keeps the membership upsell.
							if ( function_exists( 'aurora_post_is_paid' ) && aurora_post_is_paid( get_the_ID() ) ) {
								get_template_part( 'template-parts/post-buy', null, array( 'post_id' => get_the_ID() ) );
							} else {
								get_template_part( 'template-parts/paywall', null, array( 'level' => $aurora_required ) );
							}
						} else {
							the_content();

							wp_link_pages( array(
								'before' => '<nav class="aurora-pagination">',
								'after'  => '</nav>',
							) );
						}
						?>
					</div>

					<?php
					// Files are a separate entitlement from the body: a member
					// may read the article but still need to buy the resource.
					get_template_part( 'template-parts/post', 'downloads', array( 'post_id' => get_the_ID() ) );
					?>

					<?php
					$aurora_tags = get_the_tags();
					if ( $aurora_tags && ! $aurora_locked ) :
						?>
						<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:24px">
							<?php foreach ( $aurora_tags as $aurora_tag ) : ?>
								<a class="aurora-tag" href="<?php echo esc_url( get_tag_link( $aurora_tag ) ); ?>">#<?php echo esc_html( $aurora_tag->name ); ?></a>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<div class="aurora-actions">
						<button type="button"
							class="aurora-action aurora-like <?php echo $aurora_liked ? 'is-active' : ''; ?>"
							data-id="<?php the_ID(); ?>">
							<?php aurora_the_icon( 'heart', 17 ); ?>
							<?php esc_html_e( '点赞', 'aurora' ); ?>
							<span class="aurora-like-count"><?php echo esc_html( $aurora_likes ); ?></span>
						</button>

						<?php if ( is_user_logged_in() ) : ?>
							<button type="button"
								class="aurora-action aurora-bookmark <?php echo in_array( get_the_ID(), $aurora_bm, true ) ? 'is-active' : ''; ?>"
								data-id="<?php the_ID(); ?>">
								<?php aurora_the_icon( 'star', 17 ); ?>
								<?php esc_html_e( '收藏', 'aurora' ); ?>
							</button>
						<?php else : ?>
							<a class="aurora-action aurora-need-login" href="#">
								<?php aurora_the_icon( 'star', 17 ); ?>
								<?php esc_html_e( '收藏', 'aurora' ); ?>
							</a>
						<?php endif; ?>

						<a class="aurora-action aurora-action--primary" href="#comments">
							<?php aurora_the_icon( 'comment', 17 ); ?>
							<?php esc_html_e( '评论', 'aurora' ); ?>
							<span><?php echo esc_html( get_comments_number() ); ?></span>
						</a>
					</div>
				</article>

				<?php aurora_series_nav(); ?>
				<?php aurora_share_bar(); ?>
				<?php aurora_rating_block(); ?>
				<?php aurora_tip_block(); ?>
				<?php aurora_author_box(); ?>
				<?php aurora_copyright_box(); ?>
				<?php aurora_related_block(); ?>

				<?php aurora_post_nav(); ?>

				<?php
				if ( comments_open() || get_comments_number() ) {
					comments_template();
				}
				?>
			</main>

		<?php aurora_article_rail(); ?>
		</div>
	<?php endwhile; ?>
</div>

<?php
get_footer();
