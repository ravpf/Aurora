<?php
/**
 * User centre: tips received.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'aurora_user_active_tab' ) || 'income' !== aurora_user_active_tab() ) {
	return;
}

$aurora_sum = aurora_reward_user_summary( get_current_user_id() );
?>
<section class="aurora-panel">
	<h3 class="aurora-panel__title"><?php aurora_the_icon( 'gift', 17 ); ?><?php esc_html_e( '我的收益', 'aurora' ); ?></h3>

	<div class="aurora-income__stats">
		<div class="aurora-income__stat">
			<strong><?php echo esc_html( (string) $aurora_sum['received'] ); ?></strong>
			<span><?php esc_html_e( '收到积分', 'aurora' ); ?></span>
		</div>
		<div class="aurora-income__stat">
			<strong><?php echo esc_html( (string) $aurora_sum['given'] ); ?></strong>
			<span><?php esc_html_e( '送出积分', 'aurora' ); ?></span>
		</div>
	</div>

	<?php if ( ! $aurora_sum['recent'] ) : ?>
		<p style="font-size:.9rem;color:var(--aurora-text-tertiary);margin:0">
			<?php esc_html_e( '还没有收到打赏。', 'aurora' ); ?>
		</p>
	<?php else : ?>
		<ul class="aurora-submit-list">
			<?php foreach ( $aurora_sum['recent'] as $aurora_row ) : ?>
				<?php $aurora_uid = isset( $aurora_row['user'] ) ? (int) $aurora_row['user'] : 0; ?>
				<li class="aurora-submit-list__item">
					<div class="aurora-submit-list__main">
						<span class="aurora-submit-list__title">
							<?php echo esc_html( $aurora_uid ? get_the_author_meta( 'display_name', $aurora_uid ) : __( '匿名', 'aurora' ) ); ?>
							· <?php echo esc_html( (string) (int) ( $aurora_row['amount'] ?? 0 ) ); ?> <?php esc_html_e( '积分', 'aurora' ); ?>
						</span>
						<span class="aurora-submit-list__meta">
							<?php echo esc_html( (string) ( $aurora_row['time'] ?? '' ) ); ?>
							<?php if ( ! empty( $aurora_row['post'] ) ) : ?>
								· <a href="<?php echo esc_url( get_permalink( (int) $aurora_row['post'] ) ); ?>"><?php echo esc_html( get_the_title( (int) $aurora_row['post'] ) ); ?></a>
							<?php endif; ?>
						</span>
					</div>
					<span class="aurora-submit-status aurora-submit-status--ok">+<?php echo esc_html( (string) (int) ( $aurora_row['amount'] ?? 0 ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
