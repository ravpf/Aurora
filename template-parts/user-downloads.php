<?php
/**
 * User centre: download history and unlocked resources.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

// Included via get_template_part(), so the page template's locals are not in
// scope here: ask for the active tab rather than expecting it.
$aurora_active = aurora_user_active_tab();

$aurora_dl_uid     = get_current_user_id();
$aurora_dl_history = (bool) get_theme_mod( 'aurora_dl_show_history', true )
	? aurora_dl_history( $aurora_dl_uid, 30 )
	: array();

// Everything this user has unlocked with points, so they can always find the
// file again even if the original page is hard to track down.
$aurora_dl_map = get_user_meta( $aurora_dl_uid, AURORA_DL_UNLOCKED, true );
$aurora_dl_map = is_array( $aurora_dl_map ) ? $aurora_dl_map : array();
?>
<div class="aurora-tabpane <?php echo 'downloads' === $aurora_active ? 'is-active' : ''; ?>" data-pane="downloads" data-scope="user">

	<?php if ( $aurora_dl_map ) : ?>
		<div class="aurora-panel">
			<h3 class="aurora-panel__title"><?php aurora_the_icon( 'download', 17 ); ?><?php esc_html_e( '我兑换过的资源', 'aurora' ); ?></h3>
			<table class="aurora-table">
				<thead>
					<tr>
						<th><?php esc_html_e( '资源', 'aurora' ); ?></th>
						<th style="width:120px"><?php esc_html_e( '兑换时间', 'aurora' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $aurora_dl_map as $aurora_dl_pid => $aurora_dl_at ) : ?>
					<?php
					$aurora_dl_files = aurora_dl_files( $aurora_dl_pid );
					$aurora_dl_link  = get_permalink( $aurora_dl_pid );
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $aurora_dl_link ); ?>"><?php echo esc_html( get_the_title( $aurora_dl_pid ) ); ?></a>
							<?php if ( $aurora_dl_files ) : ?>
								<div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap">
									<?php foreach ( $aurora_dl_files as $aurora_dl_i => $aurora_dl_f ) : ?>
										<a class="aurora-btn aurora-btn--ghost aurora-btn--sm"
											href="<?php echo esc_url( aurora_dl_url( $aurora_dl_pid, $aurora_dl_i ) ); ?>">
											<?php aurora_the_icon( 'download', 14 ); ?>
											<?php echo esc_html( $aurora_dl_f['name'] ); ?>
										</a>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $aurora_dl_at ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<div class="aurora-panel">
		<h3 class="aurora-panel__title"><?php aurora_the_icon( 'clock', 17 ); ?><?php esc_html_e( '下载记录', 'aurora' ); ?></h3>

		<?php if ( $aurora_dl_history ) : ?>
			<table class="aurora-table">
				<thead>
					<tr>
						<th><?php esc_html_e( '文件', 'aurora' ); ?></th>
						<th style="width:130px"><?php esc_html_e( '所属内容', 'aurora' ); ?></th>
						<th style="width:90px"><?php esc_html_e( '大小', 'aurora' ); ?></th>
						<th style="width:140px"><?php esc_html_e( '时间', 'aurora' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $aurora_dl_history as $aurora_dl_row ) : ?>
					<tr>
						<td><?php echo esc_html( $aurora_dl_row['file'] ); ?></td>
						<td>
							<?php if ( $aurora_dl_row['link'] ) : ?>
								<a href="<?php echo esc_url( $aurora_dl_row['link'] ); ?>"><?php echo esc_html( $aurora_dl_row['title'] ); ?></a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( aurora_dl_size( $aurora_dl_row['size'] ) ); ?></td>
						<td><?php echo esc_html( $aurora_dl_row['date'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="aurora-muted"><?php esc_html_e( '还没有下载记录。', 'aurora' ); ?></p>
		<?php endif; ?>
	</div>
</div>
