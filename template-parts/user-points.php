<?php
/**
 * User centre: points balance and ledger.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

// Included via get_template_part(), so the page template's locals are not in
// scope here: ask for the active tab rather than expecting it.
$aurora_active = aurora_user_active_tab();

$aurora_pts_uid  = get_current_user_id();
$aurora_pts_bal  = aurora_points_balance( $aurora_pts_uid );
$aurora_pts_rows = aurora_points_ledger( $aurora_pts_uid, 30 );
$aurora_pt_rank  = aurora_points_top( 10 );
$aurora_my_rank  = array_search( $aurora_pts_uid, array_keys( $aurora_pt_rank ), true );

$aurora_rules = array();
if ( (int) get_theme_mod( 'aurora_points_checkin', 10 ) > 0 ) {
	$aurora_rules[] = sprintf(
		__( '每日签到 +%d（连续签到每天再加 %d，上限 %d 天）', 'aurora' ),
		(int) get_theme_mod( 'aurora_points_checkin', 10 ),
		(int) get_theme_mod( 'aurora_points_checkin_streak', 2 ),
		(int) get_theme_mod( 'aurora_points_checkin_streak_cap', 10 )
	);
}
if ( (int) get_theme_mod( 'aurora_points_comment', 5 ) > 0 ) {
	$cap = (int) get_theme_mod( 'aurora_points_cap_comment', 3 );
	$aurora_rules[] = $cap > 0
		? sprintf( __( '发表评论 +%1$d（每天最多 %2$d 次）', 'aurora' ), (int) get_theme_mod( 'aurora_points_comment', 5 ), $cap )
		: sprintf( __( '发表评论 +%d', 'aurora' ), (int) get_theme_mod( 'aurora_points_comment', 5 ) );
}
if ( (float) get_theme_mod( 'aurora_points_per_currency', 1 ) > 0 ) {
	$aurora_rules[] = sprintf(
		__( '消费每 1 元返 %s 分', 'aurora' ),
		rtrim( rtrim( number_format( (float) get_theme_mod( 'aurora_points_per_currency', 1 ), 2 ), '0' ), '.' )
	);
}
if ( (int) get_theme_mod( 'aurora_points_register', 50 ) > 0 ) {
	$aurora_rules[] = sprintf( __( '注册赠送 +%d', 'aurora' ), (int) get_theme_mod( 'aurora_points_register', 50 ) );
}

$aurora_bonus = aurora_points_member_bonus_pct( 'checkin' );
?>
<div class="aurora-tabpane <?php echo 'points' === $aurora_active ? 'is-active' : ''; ?>" data-pane="points" data-scope="user">

	<div class="aurora-panel">
		<h3 class="aurora-panel__title"><?php aurora_the_icon( 'fire', 17 ); ?><?php esc_html_e( '我的积分', 'aurora' ); ?></h3>

		<div class="aurora-points-hero">
			<div class="aurora-points-hero__num"><?php echo esc_html( number_format_i18n( $aurora_pts_bal ) ); ?></div>
			<div class="aurora-points-hero__label"><?php esc_html_e( '可用积分', 'aurora' ); ?></div>
			<?php if ( false !== $aurora_my_rank ) : ?>
				<div class="aurora-points-hero__rank">
					<?php
					printf(
						/* translators: %d: rank */
						esc_html__( '积分榜第 %d 名', 'aurora' ),
						(int) $aurora_my_rank + 1
					);
					?>
				</div>
			<?php endif; ?>
			<?php if ( $aurora_bonus > 0 ) : ?>
				<div class="aurora-points-hero__bonus">
					<?php
					printf(
						/* translators: %d: bonus percent */
						esc_html__( '会员签到/评论加成 +%d%%', 'aurora' ),
						(int) $aurora_bonus
					);
					?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $aurora_rules ) : ?>
			<details class="aurora-points-rules">
				<summary><?php esc_html_e( '积分怎么赚？', 'aurora' ); ?></summary>
				<ul>
					<?php foreach ( $aurora_rules as $aurora_rule ) : ?>
						<li><?php echo esc_html( $aurora_rule ); ?></li>
					<?php endforeach; ?>
					<li><?php esc_html_e( '积分可以在支持兑换的资源页直接抵扣，兑换后立即生效。', 'aurora' ); ?></li>
				</ul>
			</details>
		<?php endif; ?>
	</div>

	<div class="aurora-panel">
		<h3 class="aurora-panel__title"><?php aurora_the_icon( 'list', 17 ); ?><?php esc_html_e( '积分明细', 'aurora' ); ?></h3>

		<?php if ( $aurora_pts_rows ) : ?>
			<table class="aurora-table aurora-table--stack">
				<thead>
					<tr>
						<th><?php esc_html_e( '说明', 'aurora' ); ?></th>
						<th style="width:90px"><?php esc_html_e( '变动', 'aurora' ); ?></th>
						<th style="width:90px"><?php esc_html_e( '余额', 'aurora' ); ?></th>
						<th style="width:140px"><?php esc_html_e( '时间', 'aurora' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $aurora_pts_rows as $aurora_row ) : ?>
					<tr>
						<td data-label="<?php esc_attr_e( '说明', 'aurora' ); ?>"><?php echo esc_html( $aurora_row['label'] ); ?></td>
						<td data-label="<?php esc_attr_e( '变动', 'aurora' ); ?>">
							<span class="aurora-points-delta <?php echo $aurora_row['amount'] >= 0 ? 'is-plus' : 'is-minus'; ?>">
								<?php echo esc_html( ( $aurora_row['amount'] >= 0 ? '+' : '' ) . $aurora_row['amount'] ); ?>
							</span>
						</td>
						<td data-label="<?php esc_attr_e( '余额', 'aurora' ); ?>"><?php echo esc_html( number_format_i18n( $aurora_row['after'] ) ); ?></td>
						<td data-label="<?php esc_attr_e( '时间', 'aurora' ); ?>"><?php echo esc_html( $aurora_row['created'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="aurora-muted"><?php esc_html_e( '还没有积分记录，去签到试试。', 'aurora' ); ?></p>
		<?php endif; ?>
	</div>

	<?php if ( $aurora_pt_rank ) : ?>
		<div class="aurora-panel">
			<h3 class="aurora-panel__title"><?php aurora_the_icon( 'star', 17 ); ?><?php esc_html_e( '积分排行', 'aurora' ); ?></h3>
			<ol class="aurora-points-rank">
				<?php $aurora_i = 0; foreach ( $aurora_pt_rank as $aurora_rid => $aurora_rbal ) : $aurora_i++; ?>
					<?php $aurora_ru = get_userdata( $aurora_rid ); ?>
					<li class="<?php echo (int) $aurora_rid === (int) $aurora_pts_uid ? 'is-me' : ''; ?>">
						<span class="aurora-points-rank__pos"><?php echo (int) $aurora_i; ?></span>
						<span class="aurora-points-rank__name"><?php echo esc_html( $aurora_ru ? $aurora_ru->display_name : '#' . $aurora_rid ); ?></span>
						<span class="aurora-points-rank__num"><?php echo esc_html( number_format_i18n( $aurora_rbal ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
	<?php endif; ?>
</div>
