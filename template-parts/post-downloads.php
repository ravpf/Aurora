<?php
/**
 * Download box for a post that carries files.
 *
 * Shown on the article whether or not the body itself is behind a paywall, so a
 * buyer can always find the thing they paid for.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

$aurora_dlb_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : get_the_ID();

if ( ! $aurora_dlb_id || ! function_exists( 'aurora_dl_files' ) ) {
	return;
}

$aurora_dlb_files = aurora_dl_files( $aurora_dlb_id );

if ( ! $aurora_dlb_files ) {
	return;
}

$aurora_dlb_uid     = get_current_user_id();
$aurora_dlb_allowed = aurora_user_can_download( $aurora_dlb_id, $aurora_dlb_uid );
$aurora_dlb_points  = aurora_dl_points_price( $aurora_dlb_id );
$aurora_dlb_vip     = aurora_dl_vip_level( $aurora_dlb_id );
$aurora_dlb_paid    = aurora_post_is_paid( $aurora_dlb_id );
$aurora_dlb_balance = aurora_points_balance( $aurora_dlb_uid );
$aurora_dlb_note    = (string) get_theme_mod( 'aurora_dl_note', '' );
$aurora_dlb_total   = 0;

foreach ( $aurora_dlb_files as $aurora_dlb_f ) {
	$aurora_dlb_total += (int) $aurora_dlb_f['size'];
}
?>
<section class="aurora-downloads" id="aurora-downloads">
	<h3 class="aurora-downloads__title">
		<?php aurora_the_icon( 'download', 18 ); ?>
		<?php esc_html_e( '资源下载', 'aurora' ); ?>
		<span class="aurora-downloads__meta">
			<?php
			printf(
				/* translators: 1: file count, 2: total size */
				esc_html__( '%1$d 个文件 · %2$s', 'aurora' ),
				count( $aurora_dlb_files ),
				esc_html( aurora_dl_size( $aurora_dlb_total ) )
			);
			?>
		</span>
	</h3>

	<?php if ( $aurora_dlb_allowed ) : ?>

		<ul class="aurora-downloads__list">
			<?php foreach ( $aurora_dlb_files as $aurora_dlb_i => $aurora_dlb_file ) : ?>
				<li class="aurora-downloads__item">
					<span class="aurora-downloads__icon"><?php aurora_the_icon( 'download', 16 ); ?></span>
					<span class="aurora-downloads__name"><?php echo esc_html( $aurora_dlb_file['name'] ); ?></span>
					<span class="aurora-downloads__size"><?php echo esc_html( aurora_dl_size( $aurora_dlb_file['size'] ) ); ?></span>
					<?php if ( $aurora_dlb_file['exists'] ) : ?>
						<a class="aurora-btn aurora-btn--primary aurora-btn--sm"
							href="<?php echo esc_url( aurora_dl_url( $aurora_dlb_id, $aurora_dlb_i ) ); ?>" rel="nofollow">
							<?php esc_html_e( '下载', 'aurora' ); ?>
						</a>
					<?php else : ?>
						<span class="aurora-downloads__missing"><?php esc_html_e( '文件缺失', 'aurora' ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $aurora_dlb_note ) : ?>
			<p class="aurora-downloads__note"><?php echo esc_html( $aurora_dlb_note ); ?></p>
		<?php endif; ?>

	<?php else : ?>

		<div class="aurora-downloads__locked">
			<p class="aurora-downloads__locked-text">
				<?php aurora_the_icon( 'lock', 16 ); ?>
				<?php
				if ( $aurora_dlb_paid ) {
					esc_html_e( '购买本文后即可下载全部文件。', 'aurora' );
				} elseif ( $aurora_dlb_vip > 0 ) {
					printf(
						/* translators: %s: level name */
						esc_html__( '该资源仅 %s 及以上会员可下载。', 'aurora' ),
						esc_html( aurora_vip_level_name( $aurora_dlb_vip ) )
					);
				} else {
					esc_html_e( '该资源需要兑换后下载。', 'aurora' );
				}
				?>
			</p>

			<?php if ( ! $aurora_dlb_uid ) : ?>

				<a class="aurora-btn aurora-btn--primary" href="#aurora-auth" data-aurora-modal="aurora-auth">
					<?php esc_html_e( '登录后下载', 'aurora' ); ?>
				</a>

			<?php elseif ( $aurora_dlb_points > 0 ) : ?>

				<div class="aurora-downloads__unlock" data-post="<?php echo esc_attr( $aurora_dlb_id ); ?>">
					<p class="aurora-downloads__price">
						<?php
						printf(
							/* translators: 1: points price, 2: current balance */
							esc_html__( '需要 %1$s 积分，你有 %2$s 分。', 'aurora' ),
							esc_html( number_format_i18n( $aurora_dlb_points ) ),
							esc_html( number_format_i18n( $aurora_dlb_balance ) )
						);
						?>
					</p>
					<button type="button" class="aurora-btn aurora-btn--primary aurora-downloads__buy"
						<?php disabled( $aurora_dlb_balance < $aurora_dlb_points ); ?>>
						<?php aurora_the_icon( 'fire', 16 ); ?>
						<?php esc_html_e( '用积分兑换', 'aurora' ); ?>
					</button>
					<p class="aurora-auth-form__msg" role="status" aria-live="polite"></p>
					<?php if ( $aurora_dlb_balance < $aurora_dlb_points ) : ?>
						<p class="aurora-downloads__hint">
							<?php esc_html_e( '积分不足，可以先去签到赚积分。', 'aurora' ); ?>
							<a href="<?php echo esc_url( aurora_user_page_url( 'points' ) ); ?>"><?php esc_html_e( '查看我的积分', 'aurora' ); ?></a>
						</p>
					<?php endif; ?>
				</div>

			<?php elseif ( $aurora_dlb_vip > 0 ) : ?>

				<a class="aurora-btn aurora-btn--primary" href="<?php echo esc_url( aurora_vip_page_url() ); ?>">
					<?php aurora_the_icon( 'crown', 16 ); ?>
					<?php esc_html_e( '开通会员', 'aurora' ); ?>
				</a>

			<?php endif; ?>
		</div>

	<?php endif; ?>
</section>
