<?php
/**
 * Reusable coupon input with live server-side validation.
 *
 * @package Aurora
 *
 * @var array $args scope ('vip'|'post'), object_id, label.
 */

defined( 'ABSPATH' ) || exit;

$aurora_scope = isset( $args['scope'] ) && 'post' === $args['scope'] ? 'post' : 'vip';
$aurora_obj   = isset( $args['object_id'] ) ? (int) $args['object_id'] : 0;
?>
<div class="aurora-couponbox" data-scope="<?php echo esc_attr( $aurora_scope ); ?>" data-object="<?php echo esc_attr( $aurora_obj ); ?>">
	<div class="aurora-couponbox__row">
		<input
			type="text"
			name="coupon"
			class="aurora-couponbox__input"
			placeholder="<?php esc_attr_e( '优惠码（选填）', 'aurora' ); ?>"
			autocomplete="off"
			autocapitalize="characters"
			spellcheck="false"
			maxlength="32"
			aria-label="<?php esc_attr_e( '优惠码', 'aurora' ); ?>"
		>
		<button type="button" class="aurora-btn aurora-btn--ghost aurora-couponbox__btn">
			<?php esc_html_e( '应用', 'aurora' ); ?>
		</button>
	</div>
	<p class="aurora-couponbox__msg" aria-live="polite"></p>
</div>
