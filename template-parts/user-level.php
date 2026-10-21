<?php
/**
 * User centre: level, progress towards the next one, and the experience log.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

// Included via get_template_part(), so the page template's locals are not in
// scope here: ask for the active tab rather than expecting it.
$aurora_active = aurora_user_active_tab();

$aurora_lv_uid   = get_current_user_id();
$aurora_lv_exp   = aurora_exp_of( $aurora_lv_uid );
$aurora_lv_level = aurora_exp_level_of( $aurora_lv_uid );
$aurora_lv_next  = aurora_exp_next_level( $aurora_lv_uid );
$aurora_lv_rows  = aurora_exp_ledger( $aurora_lv_uid, 20 );
$aurora_lv_table = aurora_levels();

// Current month, laid out Monday-first, with each day classified from the dated
// record rather than from the streak counter.
$aurora_ck_year  = (int) current_time( 'Y' );
$aurora_ck_month = (int) current_time( 'n' );
$aurora_ck_grid  = aurora_checkin_enabled() ? aurora_checkin_month( $aurora_lv_uid, $aurora_ck_year, $aurora_ck_month ) : array();
$aurora_ck_streak = aurora_checkin_enabled() ? aurora_checkin_streak( $aurora_lv_uid ) : 0;
$aurora_ck_max   = aurora_checkin_makeup_max();
$aurora_ck_used  = aurora_checkin_makeup_used( $aurora_lv_uid );
$aurora_ck_cost  = aurora_checkin_makeup_cost();
$aurora_ck_from  = gmdate( 'Y-m-d', strtotime( aurora_checkin_today() . ' -' . aurora_checkin_makeup_window() . ' day' ) );
$aurora_ck_lead  = (int) gmdate( 'N', mktime( 0, 0, 0, $aurora_ck_month, 1, $aurora_ck_year ) ) - 1;
$aurora_ck_names = array( '一', '二', '三', '四', '五', '六', '日' );
?>
<div class="aurora-tabpane <?php echo 'level' === $aurora_active ? 'is-active' : ''; ?>" data-pane="level" data-scope="user">

	<div class="aurora-panel">
		<h3 class="aurora-panel__title"><?php aurora_the_icon( 'star', 17 ); ?><?php esc_html_e( '我的等级', 'aurora' ); ?></h3>

		<div class="aurora-level-head">
			<div class="aurora-level-head__badge">
				<span class="aurora-level-head__num">Lv.<?php echo (int) $aurora_lv_level; ?></span>
				<span class="aurora-level-head__name"><?php echo esc_html( aurora_exp_level_name( $aurora_lv_level ) ); ?></span>
			</div>
			<div class="aurora-level-head__exp">
				<strong><?php echo (int) $aurora_lv_exp; ?></strong>
				<span><?php esc_html_e( '经验值', 'aurora' ); ?></span>
			</div>
		</div>

		<?php if ( $aurora_lv_next ) : ?>
			<div class="aurora-level-progress">
				<div class="aurora-level-progress__label">
					<span>
						<?php
						printf(
							/* translators: 1: next level number, 2: next level name */
							esc_html__( '距离 Lv.%1$d %2$s', 'aurora' ),
							(int) $aurora_lv_next['level'],
							esc_html( $aurora_lv_next['name'] )
						);
						?>
					</span>
					<span><?php printf( esc_html__( '还差 %d 经验', 'aurora' ), (int) $aurora_lv_next['remaining'] ); ?></span>
				</div>
				<div class="aurora-level-progress__track"
					role="progressbar"
					aria-valuenow="<?php echo (int) $aurora_lv_next['percent']; ?>"
					aria-valuemin="0" aria-valuemax="100">
					<span class="aurora-level-progress__fill" style="width:<?php echo (int) $aurora_lv_next['percent']; ?>%"></span>
				</div>
			</div>
		<?php else : ?>
			<p class="aurora-notice"><?php esc_html_e( '你已经在等级阶梯的最顶端了。', 'aurora' ); ?></p>
		<?php endif; ?>

		<details style="margin-top:14px">
			<summary style="cursor:pointer;font-size:.88rem;color:var(--aurora-text-tertiary)">
				<?php esc_html_e( '查看完整等级阶梯', 'aurora' ); ?>
			</summary>
			<table class="aurora-table" style="margin-top:10px">
				<tbody>
					<?php foreach ( $aurora_lv_table as $aurora_lv_num => $aurora_lv_row ) : ?>
						<tr <?php echo (int) $aurora_lv_num === (int) $aurora_lv_level ? 'style="font-weight:600"' : ''; ?>>
							<td>Lv.<?php echo (int) $aurora_lv_num; ?></td>
							<td><?php echo esc_html( $aurora_lv_row['name'] ); ?></td>
							<td style="text-align:right"><?php echo (int) $aurora_lv_row['exp']; ?> <?php esc_html_e( '经验', 'aurora' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</details>
	</div>

	<?php if ( aurora_checkin_enabled() ) : ?>
	<div class="aurora-panel">
		<h3 class="aurora-panel__title"><?php aurora_the_icon( 'fire', 17 ); ?><?php esc_html_e( '签到记录', 'aurora' ); ?></h3>

		<p class="aurora-checkin__summary">
			<?php
			printf(
				/* translators: %d: consecutive days */
				esc_html__( '当前连续签到 %d 天', 'aurora' ),
				(int) $aurora_ck_streak
			);
			?>
			<?php if ( $aurora_ck_max > 0 ) : ?>
				<span class="aurora-checkin__quota">
					<?php
					printf(
						/* translators: 1: make-up days used, 2: allowed per month, 3: points cost */
						esc_html__( '本月补签 %1$d/%2$d，每次 %3$d 积分', 'aurora' ),
						(int) $aurora_ck_used,
						(int) $aurora_ck_max,
						(int) $aurora_ck_cost
					);
					?>
				</span>
			<?php endif; ?>
		</p>

		<p class="aurora-checkin__month"><?php echo esc_html( $aurora_ck_year . ' 年 ' . $aurora_ck_month . ' 月' ); ?></p>

		<div class="aurora-checkin" role="grid" aria-label="<?php esc_attr_e( '签到日历', 'aurora' ); ?>">
			<?php foreach ( $aurora_ck_names as $aurora_ck_name ) : ?>
				<span class="aurora-checkin__head"><?php echo esc_html( $aurora_ck_name ); ?></span>
			<?php endforeach; ?>

			<?php for ( $aurora_ck_i = 0; $aurora_ck_i < $aurora_ck_lead; $aurora_ck_i++ ) : ?>
				<span class="aurora-checkin__cell is-blank"></span>
			<?php endfor; ?>

			<?php foreach ( $aurora_ck_grid as $aurora_ck_day => $aurora_ck_state ) : ?>
				<?php
				$aurora_ck_date    = sprintf( '%04d-%02d-%02d', $aurora_ck_year, $aurora_ck_month, $aurora_ck_day );
				$aurora_ck_can_fix = 'missed' === $aurora_ck_state
					&& $aurora_ck_max > 0
					&& $aurora_ck_used < $aurora_ck_max
					&& $aurora_ck_date >= $aurora_ck_from;
				?>
				<?php if ( $aurora_ck_can_fix ) : ?>
					<button type="button" class="aurora-checkin__cell is-missed is-clickable" data-date="<?php echo esc_attr( $aurora_ck_date ); ?>"
						title="<?php esc_attr_e( '点击补签', 'aurora' ); ?>"><?php echo (int) $aurora_ck_day; ?></button>
				<?php else : ?>
					<span class="aurora-checkin__cell is-<?php echo esc_attr( $aurora_ck_state ); ?>"><?php echo (int) $aurora_ck_day; ?></span>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>

		<p class="aurora-checkin__legend">
			<span><i class="is-done"></i><?php esc_html_e( '已签到', 'aurora' ); ?></span>
			<span><i class="is-makeup"></i><?php esc_html_e( '补签', 'aurora' ); ?></span>
			<span><i class="is-missed"></i><?php esc_html_e( '漏签', 'aurora' ); ?></span>
		</p>
		<p class="aurora-checkin__msg" role="status" aria-live="polite"></p>
	</div>

	<script>
	( function () {
		var box = document.querySelector( '.aurora-checkin' );
		if ( ! box ) { return; }

		box.addEventListener( 'click', function ( event ) {
			var cell = event.target.closest( '.aurora-checkin__cell.is-clickable' );
			if ( ! cell ) { return; }

			var msg = document.querySelector( '.aurora-checkin__msg' );
			var cost = <?php echo (int) $aurora_ck_cost; ?>;
			var ask = cost > 0
				? <?php echo wp_json_encode( sprintf( __( '补签需要消耗 %d 积分，确定继续？', 'aurora' ), (int) $aurora_ck_cost ) ); ?>
				: '';

			if ( ask && ! window.confirm( ask ) ) { return; }

			var body = new URLSearchParams();
			body.set( 'action', 'aurora_checkin_makeup' );
			body.set( 'nonce', Aurora.nonce );
			body.set( 'date', cell.getAttribute( 'data-date' ) );

			cell.disabled = true;

			fetch( Aurora.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					var text = data && data.data && data.data.message ? data.data.message : '';
					if ( msg ) { msg.textContent = text; }

					if ( data && data.success ) {
						// A repaired day is a make-up, not a check-in: shown in its
						// own colour so the calendar stays honest about which is which.
						cell.classList.remove( 'is-missed', 'is-clickable' );
						cell.classList.add( 'is-makeup' );
						cell.disabled = false;
					} else {
						cell.disabled = false;
					}
				} )
				.catch( function () { cell.disabled = false; } );
		} );
	}() );
	</script>
	<?php endif; ?>

	<div class="aurora-panel">
		<h3 class="aurora-panel__title"><?php aurora_the_icon( 'clock', 17 ); ?><?php esc_html_e( '经验明细', 'aurora' ); ?></h3>

		<?php if ( $aurora_lv_rows ) : ?>
			<ul class="aurora-exp-log">
				<?php foreach ( $aurora_lv_rows as $aurora_lv_row ) : ?>
					<?php
					$aurora_lv_delta = (int) get_post_meta( $aurora_lv_row->ID, '_aurora_exp_amount', true );
					$aurora_lv_after = (int) get_post_meta( $aurora_lv_row->ID, '_aurora_exp_after', true );
					?>
					<li class="aurora-exp-log__row">
						<span class="aurora-exp-log__label"><?php echo esc_html( $aurora_lv_row->post_title ); ?></span>
						<span class="aurora-exp-log__meta"><?php echo esc_html( get_date_from_gmt( $aurora_lv_row->post_date_gmt, 'Y-m-d H:i' ) ); ?></span>
						<span class="aurora-exp-log__delta"><?php echo esc_html( ( $aurora_lv_delta >= 0 ? '+' : '' ) . $aurora_lv_delta ); ?></span>
						<span class="aurora-exp-log__after"><?php echo (int) $aurora_lv_after; ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p><?php esc_html_e( '还没有经验记录。发表评论、签到、发布文章都会涨经验。', 'aurora' ); ?></p>
		<?php endif; ?>
	</div>

</div>
