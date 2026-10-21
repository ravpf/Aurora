<?php
/**
 * User centre: the reader's own submissions and their review state.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'aurora_user_active_tab' ) || 'submissions' !== aurora_user_active_tab() ) {
	return;
}

$aurora_subs = aurora_user_submissions( get_current_user_id(), 30 );
?>
<section class="aurora-panel">
	<h3 class="aurora-panel__title"><?php aurora_the_icon( 'list', 17 ); ?><?php esc_html_e( '我的投稿', 'aurora' ); ?></h3>

	<?php if ( ! $aurora_subs ) : ?>
		<p style="font-size:.9rem;color:var(--aurora-text-tertiary)">
			<?php esc_html_e( '还没有投稿。', 'aurora' ); ?>
			<a href="<?php echo esc_url( aurora_submit_page_url() ); ?>"><?php esc_html_e( '去写一篇', 'aurora' ); ?></a>
		</p>
	<?php else : ?>
		<ul class="aurora-submit-list">
			<?php foreach ( $aurora_subs as $aurora_sub ) : ?>
				<li class="aurora-submit-list__item">
					<div class="aurora-submit-list__main">
						<a class="aurora-submit-list__title" href="<?php echo esc_url( get_permalink( $aurora_sub ) ); ?>">
							<?php echo esc_html( get_the_title( $aurora_sub ) ); ?>
						</a>
						<span class="aurora-submit-list__meta">
							<?php echo esc_html( get_the_date( '', $aurora_sub ) ); ?>
							<?php
							$aurora_cat = aurora_primary_category( $aurora_sub->ID );
							if ( $aurora_cat ) {
								echo ' · ' . esc_html( $aurora_cat->name );
							}
							?>
						</span>
					</div>
					<?php echo wp_kses_post( aurora_submit_status_label( $aurora_sub->post_status ) ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
