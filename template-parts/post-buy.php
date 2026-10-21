<?php
/**
 * Per-article purchase panel, shown instead of the body when a post is sold
 * individually and the viewer has not bought it.
 *
 * @package Aurora
 *
 * @var array $args post_id.
 */

defined( 'ABSPATH' ) || exit;

$aurora_post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : get_the_ID();
$aurora_price   = aurora_post_price( $aurora_post_id );
$aurora_gws     = aurora_available_gateways();
$aurora_days    = (int) get_theme_mod( 'aurora_post_access_days', 0 );
$aurora_owned   = aurora_user_owns_post( null, $aurora_post_id );
$aurora_member  = aurora_post_vip_covers( $aurora_post_id );

if ( $aurora_price <= 0 || $aurora_owned || $aurora_member ) {
	return;
}
?>
<div class="aurora-postbuy">
	<div class="aurora-postbuy__head">
		<span class="aurora-postbuy__icon"><?php aurora_the_icon( 'unlock', 22 ); ?></span>
		<div>
			<div class="aurora-postbuy__title"><?php esc_html_e( '这是一篇单篇付费内容', 'aurora' ); ?></div>
			<div class="aurora-postbuy__sub">
				<?php
				if ( $aurora_days > 0 ) {
					printf(
						/* translators: %d: days */
						esc_html__( '购买后可阅读 %d 天', 'aurora' ),
						(int) $aurora_days
					);
				} else {
					esc_html_e( '购买后可永久阅读，无需开通会员', 'aurora' );
				}
				?>
			</div>
		</div>
	</div>

	<div class="aurora-postbuy__price">
		<span class="aurora-postbuy__amount"><small>¥</small><?php echo esc_html( rtrim( rtrim( number_format( $aurora_price, 2 ), '0' ), '.' ) ); ?></span>
	</div>

	<?php if ( ! is_user_logged_in() ) : ?>
		<a class="aurora-btn aurora-btn--primary aurora-btn--lg aurora-btn--block" href="#aurora-auth" data-aurora-modal="aurora-auth">
			<?php esc_html_e( '登录后购买', 'aurora' ); ?>
		</a>
	<?php elseif ( empty( $aurora_gws ) ) : ?>
		<button class="aurora-btn aurora-btn--block" disabled><?php esc_html_e( '未配置支付方式', 'aurora' ); ?></button>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( get_permalink( $aurora_post_id ) ); ?>" class="aurora-postbuy__form">
			<?php wp_nonce_field( 'aurora_buy_post', 'aurora_post_nonce' ); ?>
			<input type="hidden" name="post_id" value="<?php echo esc_attr( $aurora_post_id ); ?>">

			<?php if ( count( $aurora_gws ) > 1 ) : ?>
				<div class="aurora-field">
					<select name="gateway" aria-label="<?php esc_attr_e( '支付方式', 'aurora' ); ?>">
						<?php foreach ( $aurora_gws as $aurora_gw ) : ?>
							<option value="<?php echo esc_attr( $aurora_gw['id'] ); ?>"><?php echo esc_html( $aurora_gw['title'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php else : ?>
				<input type="hidden" name="gateway" value="<?php echo esc_attr( array_key_first( $aurora_gws ) ); ?>">
			<?php endif; ?>

			<?php
			get_template_part( 'template-parts/coupon-field', null, array(
				'scope'     => 'post',
				'object_id' => $aurora_post_id,
			) );
			?>

			<button type="submit" class="aurora-btn aurora-btn--primary aurora-btn--lg aurora-btn--block">
				<?php aurora_the_icon( 'unlock', 17 ); ?>
				<?php esc_html_e( '立即购买本文', 'aurora' ); ?>
			</button>
		</form>
	<?php endif; ?>

	<p class="aurora-postbuy__note">
		<?php
		if ( get_theme_mod( 'aurora_post_vip_free', false ) ) : ?>
			<?php esc_html_e( '开通会员可免费阅读本文及站内全部付费内容。', 'aurora' ); ?>
			<a href="<?php echo esc_url( aurora_vip_page_url() ); ?>"><?php esc_html_e( '了解会员 →', 'aurora' ); ?></a>
		<?php else : ?>
			<?php esc_html_e( '购买后立即解锁，无需等待人工确认。', 'aurora' ); ?>
		<?php endif; ?>
	</p>
</div>
