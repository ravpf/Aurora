<?php
/**
 * Template Name: 会员中心
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="aurora-container">
	<header class="aurora-pagehead">
		<h1 class="aurora-pagehead__title"><?php echo esc_html( get_the_title() ); ?></h1>
		<p class="aurora-pagehead__desc"><?php esc_html_e( '选择适合你的会员等级，解锁对应的内容与资源。当前权益即时生效。', 'aurora' ); ?></p>
	</header>
</div>

<div class="aurora-container">
	<div class="aurora-layout">
		<main class="aurora-primary">
			<?php
			while ( have_posts() ) :
				the_post();
				if ( trim( (string) get_the_content() ) ) {
					echo '<div class="aurora-content" style="margin-bottom:32px">';
					the_content();
					echo '</div>';
				}
			endwhile;
			?>

			<?php get_template_part( 'template-parts/vip-plans' ); ?>

			<?php if ( is_user_logged_in() ) : ?>
				<div class="aurora-panel" style="margin-top:32px">
					<h3 class="aurora-panel__title"><?php aurora_the_icon( 'crown', 17 ); ?><?php esc_html_e( '我的会员状态', 'aurora' ); ?></h3>
					<?php
					$aurora_uid    = get_current_user_id();
					$aurora_expiry = aurora_get_user_expiry( $aurora_uid );
					?>
					<p style="font-size:.92rem">
						<?php aurora_vip_badge( $aurora_uid, true ); ?>
						<?php if ( $aurora_expiry ) : ?>
							<span style="color:var(--aurora-text-secondary);margin-left:10px">
								<?php
								printf(
									/* translators: %s: expiry date */
									esc_html__( '有效期至 %s', 'aurora' ),
									esc_html( $aurora_expiry )
								);
								?>
							</span>
						<?php endif; ?>
					</p>
					<a class="aurora-btn aurora-btn--sm" href="<?php echo esc_url( aurora_user_page_url() ); ?>"><?php esc_html_e( '进入用户中心', 'aurora' ); ?></a>
				</div>
			<?php endif; ?>

			<div class="aurora-section" style="margin-top:40px">
				<div class="aurora-section__head">
					<h2 class="aurora-section__title"><?php esc_html_e( '常见问题', 'aurora' ); ?></h2>
				</div>
				<div class="aurora-panel">
					<h3 class="aurora-panel__title"><?php esc_html_e( '开通后立刻生效吗？', 'aurora' ); ?></h3>
					<p style="font-size:.92rem;color:var(--aurora-text-secondary)"><?php esc_html_e( '是的。支付确认后权益会自动写入你的账户，无需等待人工处理。', 'aurora' ); ?></p>
					<h3 class="aurora-panel__title" style="margin-top:18px"><?php esc_html_e( '可以叠加时长吗？', 'aurora' ); ?></h3>
					<p style="font-size:.92rem;color:var(--aurora-text-secondary)"><?php esc_html_e( '可以。再次购买会在现有到期时间上顺延，不会覆盖。', 'aurora' ); ?></p>
					<h3 class="aurora-panel__title" style="margin-top:18px"><?php esc_html_e( '支持哪些支付方式？', 'aurora' ); ?></h3>
					<p style="font-size:.92rem;color:var(--aurora-text-secondary)"><?php esc_html_e( '取决于站长在后台启用的网关。未配置商户信息时仅提供人工确认通道。', 'aurora' ); ?></p>
				</div>
			</div>
		</main>

		<aside class="aurora-sidebar">
			<div class="aurora-panel">
				<h3 class="aurora-panel__title"><?php aurora_the_icon( 'crown', 17 ); ?><?php esc_html_e( '会员权益一览', 'aurora' ); ?></h3>
				<table class="aurora-table">
					<thead>
						<tr><th><?php esc_html_e( '等级', 'aurora' ); ?></th><th><?php esc_html_e( '价格', 'aurora' ); ?></th></tr>
					</thead>
					<tbody>
						<?php foreach ( aurora_vip_levels() as $aurora_i => $aurora_lv ) : ?>
							<?php if ( 0 === $aurora_i ) { continue; } ?>
							<tr>
								<td><span class="aurora-vip-badge <?php echo esc_attr( $aurora_lv['class'] ); ?>"><?php echo esc_html( $aurora_lv['name'] ); ?></span></td>
								<td>¥<?php echo esc_html( $aurora_lv['price'] ); ?>/<?php echo esc_html( $aurora_lv['period'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</aside>
	</div>
</div>

<?php
get_footer();
