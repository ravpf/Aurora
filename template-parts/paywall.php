<?php
/**
 * Paywall block for members-only posts.
 *
 * @package Aurora
 *
 * @var array $args Passed by get_template_part(): level.
 */

defined( 'ABSPATH' ) || exit;

$aurora_level      = isset( $args['level'] ) ? (int) $args['level'] : 1;
$aurora_level_info = aurora_get_vip_level( $aurora_level );
?>
<div class="aurora-paywall">
	<div class="aurora-paywall__title">
		<?php aurora_the_icon( 'crown', 20 ); ?>
		<?php
		printf(
			/* translators: %s: membership level name */
			esc_html__( '该内容仅限「%s」阅读', 'aurora' ),
			esc_html( $aurora_level_info ? $aurora_level_info['name'] : __( '会员', 'aurora' ) )
		);
		?>
	</div>
	<p class="aurora-paywall__desc"><?php esc_html_e( '开通会员后即可查看完整内容，同时解锁站内全部会员资源。', 'aurora' ); ?></p>
	<div class="aurora-paywall__actions">
		<a class="aurora-btn aurora-btn--primary aurora-btn--lg" href="<?php echo esc_url( aurora_vip_page_url() ); ?>">
			<?php aurora_the_icon( 'crown', 17 ); ?>
			<?php esc_html_e( '开通会员', 'aurora' ); ?>
		</a>
		<?php if ( ! is_user_logged_in() ) : ?>
			<a class="aurora-btn aurora-btn--ghost aurora-btn--lg" href="#aurora-auth" data-aurora-modal="aurora-auth">
				<?php esc_html_e( '已有账号，登录', 'aurora' ); ?>
			</a>
		<?php endif; ?>
	</div>
</div>
