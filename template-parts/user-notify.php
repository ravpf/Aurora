<?php
/**
 * User centre: inbox.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

// Included via get_template_part(), so the page template's locals are not in
// scope here: ask for the active tab rather than expecting it.
$aurora_active = aurora_user_active_tab();

$aurora_nf_uid    = get_current_user_id();
$aurora_nf_rows   = aurora_notify_list( $aurora_nf_uid, 50 );
$aurora_nf_unread = aurora_notify_unread( $aurora_nf_uid );
?>
<div class="aurora-tabpane <?php echo 'notify' === $aurora_active ? 'is-active' : ''; ?>" data-pane="notify" data-scope="user">

	<div class="aurora-panel">
		<h3 class="aurora-panel__title">
			<?php aurora_the_icon( 'bell', 17 ); ?>
			<?php esc_html_e( '消息中心', 'aurora' ); ?>
			<?php if ( $aurora_nf_unread ) : ?>
				<span class="aurora-notify-count" id="aurora-notify-count"><?php echo (int) $aurora_nf_unread; ?></span>
			<?php endif; ?>
		</h3>

		<?php if ( $aurora_nf_rows ) : ?>
			<div class="aurora-notify-actions">
				<button type="button" class="aurora-btn aurora-btn--ghost aurora-btn--sm" id="aurora-notify-read-all">
					<?php esc_html_e( '全部标为已读', 'aurora' ); ?>
				</button>
				<span class="aurora-auth-form__msg" role="status" aria-live="polite"></span>
			</div>

			<ul class="aurora-notify-list" id="aurora-notify-list">
				<?php foreach ( $aurora_nf_rows as $aurora_nf ) : ?>
					<li class="aurora-notify-item <?php echo $aurora_nf['read'] ? 'is-read' : 'is-unread'; ?>"
						data-id="<?php echo esc_attr( $aurora_nf['id'] ); ?>">
						<span class="aurora-notify-item__icon"><?php aurora_the_icon( $aurora_nf['icon'], 16 ); ?></span>
						<div class="aurora-notify-item__main">
							<div class="aurora-notify-item__head">
								<span class="aurora-notify-item__title"><?php echo esc_html( $aurora_nf['title'] ); ?></span>
								<span class="aurora-notify-item__type"><?php echo esc_html( $aurora_nf['label'] ); ?></span>
								<span class="aurora-notify-item__time"><?php echo esc_html( $aurora_nf['created'] ); ?></span>
							</div>
							<?php if ( $aurora_nf['body'] ) : ?>
								<p class="aurora-notify-item__body"><?php echo esc_html( $aurora_nf['body'] ); ?></p>
							<?php endif; ?>
							<?php if ( $aurora_nf['link'] ) : ?>
								<a class="aurora-notify-item__link" href="<?php echo esc_url( $aurora_nf['link'] ); ?>">
									<?php esc_html_e( '查看详情', 'aurora' ); ?>
								</a>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="aurora-muted"><?php esc_html_e( '暂时没有消息。', 'aurora' ); ?></p>
		<?php endif; ?>
	</div>
</div>
