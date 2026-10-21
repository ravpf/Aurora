<?php
/**
 * Membership plan cards + checkout form.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

$aurora_levels   = aurora_vip_levels();
$aurora_gateways = aurora_available_gateways();
$aurora_current  = aurora_get_user_level();
?>
<div class="aurora-plans">
	<?php foreach ( $aurora_levels as $aurora_index => $aurora_info ) : ?>
		<?php
		if ( 0 === $aurora_index ) {
			continue;
		}
		$aurora_featured = ( 2 === $aurora_index );
		?>
		<div class="aurora-plan <?php echo $aurora_featured ? 'aurora-plan--featured' : ''; ?>">
			<div class="aurora-plan__name">
				<?php aurora_the_icon( 'crown', 18 ); ?>
				<?php echo esc_html( $aurora_info['name'] ); ?>
			</div>
			<div class="aurora-plan__price">
				<small>¥</small><?php echo esc_html( $aurora_info['price'] ); ?>
			</div>
			<div class="aurora-plan__period"><?php echo esc_html( sprintf( __( '每 %s', 'aurora' ), $aurora_info['period'] ) ); ?></div>

			<ul class="aurora-plan__features">
				<?php foreach ( $aurora_info['features'] as $aurora_feature ) : ?>
					<li><?php aurora_the_icon( 'check', 15 ); ?><?php echo esc_html( $aurora_feature ); ?></li>
				<?php endforeach; ?>
			</ul>

			<?php if ( $aurora_current >= $aurora_index ) : ?>
				<button class="aurora-btn aurora-btn--block" disabled><?php esc_html_e( '当前等级或更高', 'aurora' ); ?></button>
			<?php elseif ( ! is_user_logged_in() ) : ?>
				<a class="aurora-btn aurora-btn--primary aurora-btn--block" href="#aurora-auth" data-aurora-modal="aurora-auth"><?php esc_html_e( '登录后开通', 'aurora' ); ?></a>
			<?php elseif ( empty( $aurora_gateways ) ) : ?>
				<button class="aurora-btn aurora-btn--block" disabled><?php esc_html_e( '未配置支付方式', 'aurora' ); ?></button>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( aurora_vip_page_url() ); ?>">
					<?php wp_nonce_field( 'aurora_checkout', 'aurora_checkout_nonce' ); ?>
					<input type="hidden" name="level" value="<?php echo esc_attr( $aurora_index ); ?>">
					<div class="aurora-field">
						<select name="days" style="font-size:.86rem">
							<option value="30"><?php esc_html_e( '1 个月', 'aurora' ); ?></option>
							<option value="90"><?php esc_html_e( '3 个月', 'aurora' ); ?></option>
							<option value="365"><?php esc_html_e( '12 个月', 'aurora' ); ?></option>
						</select>
					</div>
					<div class="aurora-field">
						<select name="gateway" style="font-size:.86rem">
							<?php foreach ( $aurora_gateways as $aurora_gw ) : ?>
								<option value="<?php echo esc_attr( $aurora_gw['id'] ); ?>"><?php echo esc_html( $aurora_gw['title'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<?php get_template_part( 'template-parts/coupon-field', null, array( 'scope' => 'vip' ) ); ?>
					<button type="submit" class="aurora-btn aurora-btn--primary aurora-btn--block"><?php esc_html_e( '立即开通', 'aurora' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>

<?php if ( empty( $aurora_gateways ) ) : ?>
	<p class="aurora-notice aurora-notice--warning" style="margin-top:22px">
		<span class="aurora-notice__icon"><?php aurora_the_icon( 'clock', 18 ); ?></span>
		<?php esc_html_e( '尚未配置可用的支付方式。请到「外观 → 自定义 → Aurora 主题设置 → 会员与付费」中启用并填写商户信息。', 'aurora' ); ?>
	</p>
<?php endif; ?>
