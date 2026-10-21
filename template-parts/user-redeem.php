<?php
/**
 * User centre: redeem a card.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

// Included via get_template_part(), so the page template's locals are not in
// scope here: ask for the active tab rather than expecting it.
$aurora_active = aurora_user_active_tab();

$aurora_card_uid   = get_current_user_id();
$aurora_card_tries = aurora_card_attempts_left( $aurora_card_uid );

// Recently redeemed by this user, read off the cards themselves.
$aurora_my_cards = get_posts( array(
	'post_type'      => 'aurora_card',
	'post_status'    => 'private',
	'posts_per_page' => 10,
	'meta_query'     => array(
		array( 'key' => '_aurora_card_used_by', 'value' => $aurora_card_uid ),
	),
) );
?>
<div class="aurora-tabpane <?php echo 'redeem' === $aurora_active ? 'is-active' : ''; ?>" data-pane="redeem" data-scope="user">

	<div class="aurora-panel">
		<h3 class="aurora-panel__title"><?php aurora_the_icon( 'gift', 17 ); ?><?php esc_html_e( '卡密兑换', 'aurora' ); ?></h3>

		<p class="aurora-muted" style="margin-bottom:14px">
			<?php esc_html_e( '输入卡密即可兑换会员时长或积分，每张卡只能使用一次。', 'aurora' ); ?>
		</p>

		<form id="aurora-redeem-form" method="post">
			<div class="aurora-field">
				<label class="aurora-field__label" for="aurora-card-code"><?php esc_html_e( '卡密', 'aurora' ); ?></label>
				<input type="text" id="aurora-card-code" name="code" placeholder="ABCD-EFGH-JKLM-NPQR"
					autocomplete="off" spellcheck="false" inputmode="latin"
					class="aurora-code-input" <?php disabled( ! $aurora_card_tries ); ?>>
			</div>
			<button type="submit" class="aurora-btn aurora-btn--primary" <?php disabled( ! $aurora_card_tries ); ?>>
				<?php esc_html_e( '立即兑换', 'aurora' ); ?>
			</button>
			<p class="aurora-auth-form__msg" role="status" aria-live="polite"></p>
		</form>

		<?php if ( ! $aurora_card_tries ) : ?>
			<p class="aurora-muted" style="margin-top:12px;color:var(--aurora-danger,#b32d2e)">
				<?php esc_html_e( '尝试次数过多，请一小时后再试。', 'aurora' ); ?>
			</p>
		<?php endif; ?>
	</div>

	<?php if ( $aurora_my_cards ) : ?>
		<div class="aurora-panel">
			<h3 class="aurora-panel__title"><?php aurora_the_icon( 'receipt', 17 ); ?><?php esc_html_e( '我的兑换记录', 'aurora' ); ?></h3>
			<table class="aurora-table">
				<thead>
					<tr>
						<th><?php esc_html_e( '卡密', 'aurora' ); ?></th>
						<th style="width:150px"><?php esc_html_e( '内容', 'aurora' ); ?></th>
						<th style="width:140px"><?php esc_html_e( '时间', 'aurora' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $aurora_my_cards as $aurora_card_post ) : ?>
					<?php $aurora_card = aurora_get_card( $aurora_card_post->ID ); ?>
					<tr>
						<td><code><?php echo esc_html( $aurora_card['code'] ); ?></code></td>
						<td><?php echo esc_html( aurora_card_label( $aurora_card ) ); ?></td>
						<td><?php echo esc_html( $aurora_card['used_at'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
