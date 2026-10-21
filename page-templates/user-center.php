<?php
/**
 * Template Name: 用户中心
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();

if ( ! is_user_logged_in() ) {
	?>
	<div class="aurora-container">
		<div class="aurora-empty" style="padding:90px 20px;max-width:420px;margin-inline:auto">
			<h2 style="margin-bottom:10px"><?php esc_html_e( '请先登录', 'aurora' ); ?></h2>
			<p style="margin-bottom:24px"><?php esc_html_e( '登录后即可查看订单、收藏和会员状态。', 'aurora' ); ?></p>
			<?php aurora_login_form( get_permalink() ); ?>
		</div>
	</div>
	<?php
	get_footer();
	return;
}

$aurora_uid    = get_current_user_id();
$aurora_user   = wp_get_current_user();
$aurora_stats  = aurora_user_stats( $aurora_uid );
$aurora_level  = aurora_get_user_level();
$aurora_info   = aurora_get_vip_level( $aurora_level );
$aurora_expiry = aurora_get_user_expiry();
$aurora_tabs   = aurora_user_tabs();
$aurora_active = aurora_user_active_tab();
$aurora_order_id = isset( $_GET['order'] ) ? (int) $_GET['order'] : 0;

// No explicit order in the URL: surface this user's most recent unpaid order so
// they always see the payment instructions straight after ordering.
if ( ! $aurora_order_id ) {
	$aurora_pending = new WP_Query( array(
		'post_type'      => 'aurora_order',
		'posts_per_page' => 1,
		'author'         => $aurora_uid,
		'post_status'    => 'publish',
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
		'meta_query'     => array(
			array(
				'key'   => '_aurora_status',
				'value' => 'pending',
			),
		),
	) );
	if ( $aurora_pending->have_posts() ) {
		$aurora_order_id = (int) $aurora_pending->posts[0]->ID;
	}
	wp_reset_postdata();
}
?>

<div class="aurora-container">
	<div style="padding-block:30px 0">
		<div class="aurora-profile">
			<div class="aurora-profile__avatar"><?php echo aurora_avatar( $aurora_uid, 156 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div style="flex:1 1 auto;position:relative;z-index:1">
				<h1 class="aurora-profile__name">
					<?php echo esc_html( $aurora_user->display_name ); ?>
					<?php aurora_vip_badge( $aurora_uid, true ); ?>
					<?php echo aurora_level_badge( $aurora_uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the module. ?>
				</h1>
				<p class="aurora-profile__bio">
					<?php
					echo $aurora_user->description
						? esc_html( $aurora_user->description )
						: esc_html__( '这个人很懒，什么都没写。', 'aurora' );
					?>
				</p>
				<div class="aurora-profile__stats">
					<span><b><?php echo esc_html( number_format_i18n( $aurora_stats['posts'] ) ); ?></b><?php esc_html_e( '文章', 'aurora' ); ?></span>
					<span><b><?php echo esc_html( number_format_i18n( $aurora_stats['comments'] ) ); ?></b><?php esc_html_e( '评论', 'aurora' ); ?></span>
					<span><b><?php echo esc_html( number_format_i18n( $aurora_stats['bookmarks'] ) ); ?></b><?php esc_html_e( '收藏', 'aurora' ); ?></span>
					<span><b><?php echo esc_html( number_format_i18n( $aurora_stats['points'] ) ); ?></b><?php esc_html_e( '积分', 'aurora' ); ?></span>
				</div>
			</div>
		</div>
	</div>
</div>

<div class="aurora-container">
	<div class="aurora-layout">
		<main class="aurora-primary">

			<?php if ( 'invalid' === ( $_GET['aurora_msg'] ?? '' ) ) : ?>
				<div class="aurora-notice aurora-notice--warning"><?php esc_html_e( '下单失败：请选择有效的会员等级与支付方式。', 'aurora' ); ?></div>
			<?php elseif ( 'error' === ( $_GET['aurora_msg'] ?? '' ) ) : ?>
				<div class="aurora-notice aurora-notice--warning"><?php esc_html_e( '下单失败，请稍后重试。', 'aurora' ); ?></div>
			<?php endif; ?>

			<?php if ( $aurora_order_id ) : ?>
				<?php aurora_render_order_payment( $aurora_order_id ); ?>
			<?php endif; ?>

			<nav class="aurora-tabs" data-scope="user">
				<?php foreach ( $aurora_tabs as $aurora_key => $aurora_tab ) : ?>
					<button type="button"
						class="aurora-tab <?php echo $aurora_active === $aurora_key ? 'is-active' : ''; ?>"
						data-tab="<?php echo esc_attr( $aurora_key ); ?>"
						aria-selected="<?php echo $aurora_active === $aurora_key ? 'true' : 'false'; ?>">
						<?php aurora_the_icon( $aurora_tab['icon'], 15 ); ?>
						<?php echo esc_html( $aurora_tab['label'] ); ?>
						<?php if ( ! empty( $aurora_tab['badge'] ) ) : ?>
							<span class="aurora-tab__badge"><?php echo (int) $aurora_tab['badge']; ?></span>
						<?php endif; ?>
					</button>
				<?php endforeach; ?>
			</nav>

			<!-- Overview -->
			<div class="aurora-tabpane <?php echo 'overview' === $aurora_active ? 'is-active' : ''; ?>" data-pane="overview" data-scope="user">
				<div class="aurora-panel">
					<h3 class="aurora-panel__title"><?php aurora_the_icon( 'crown', 17 ); ?><?php esc_html_e( '会员状态', 'aurora' ); ?></h3>
					<table class="aurora-table">
						<tbody>
							<tr><th><?php esc_html_e( '当前等级', 'aurora' ); ?></th><td><?php aurora_vip_badge( $aurora_uid, true ); ?></td></tr>
							<?php if ( $aurora_level > 0 && $aurora_expiry ) : ?>
								<tr><th><?php esc_html_e( '到期时间', 'aurora' ); ?></th><td><?php echo esc_html( $aurora_expiry ); ?></td></tr>
							<?php endif; ?>
							<tr><th><?php esc_html_e( '账户', 'aurora' ); ?></th><td><?php echo esc_html( $aurora_user->user_login ); ?></td></tr>
							<tr><th><?php esc_html_e( '邮箱', 'aurora' ); ?></th><td><?php echo esc_html( $aurora_user->user_email ); ?></td></tr>
							<tr><th><?php esc_html_e( '注册时间', 'aurora' ); ?></th><td><?php echo esc_html( mysql2date( 'Y-m-d', $aurora_user->user_registered ) ); ?></td></tr>
						</tbody>
					</table>
					<div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap">
						<a class="aurora-btn aurora-btn--primary" href="<?php echo esc_url( aurora_vip_page_url() ); ?>"><?php esc_html_e( '会员中心', 'aurora' ); ?></a>
						<button type="button" class="aurora-btn" id="aurora-checkin"><?php esc_html_e( '每日签到 +10', 'aurora' ); ?></button>
						<a class="aurora-btn aurora-btn--ghost" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( '退出登录', 'aurora' ); ?></a>
					</div>
				</div>

				<div class="aurora-panel">
					<h3 class="aurora-panel__title"><?php aurora_the_icon( 'star', 17 ); ?><?php esc_html_e( '最近收藏', 'aurora' ); ?></h3>
					<?php
					$aurora_recent_bm = aurora_user_bookmarks( $aurora_uid, 4 );
					if ( $aurora_recent_bm->have_posts() ) :
						echo '<div class="aurora-grid aurora-grid--two">';
						while ( $aurora_recent_bm->have_posts() ) :
							$aurora_recent_bm->the_post();
							aurora_render_card( get_the_ID(), 'grid' );
						endwhile;
						echo '</div>';
						wp_reset_postdata();
					else :
						?>
						<p style="font-size:.9rem;color:var(--aurora-text-tertiary)"><?php esc_html_e( '还没有收藏任何内容。', 'aurora' ); ?></p>
						<?php
					endif;
					?>
				</div>
			</div>

			<!-- Orders -->
			<div class="aurora-tabpane <?php echo 'orders' === $aurora_active ? 'is-active' : ''; ?>" data-pane="orders" data-scope="user">
				<div class="aurora-panel">
					<h3 class="aurora-panel__title"><?php aurora_the_icon( 'download', 17 ); ?><?php esc_html_e( '我的订单', 'aurora' ); ?></h3>
					<?php
					$aurora_orders   = aurora_user_orders( $aurora_uid );
					$aurora_statuses = aurora_order_statuses();
					$aurora_gateways = aurora_payment_gateways();

					if ( $aurora_orders->have_posts() ) :
						?>
						<table class="aurora-table">
							<thead>
								<tr>
									<th><?php esc_html_e( '订单号', 'aurora' ); ?></th>
									<th><?php esc_html_e( '商品', 'aurora' ); ?></th>
									<th><?php esc_html_e( '金额', 'aurora' ); ?></th>
									<th><?php esc_html_e( '状态', 'aurora' ); ?></th>
									<th><?php esc_html_e( '时间', 'aurora' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								while ( $aurora_orders->have_posts() ) :
									$aurora_orders->the_post();
									$aurora_order = aurora_get_order( get_the_ID() );
									$aurora_st    = isset( $aurora_statuses[ $aurora_order['status'] ] ) ? $aurora_order['status'] : 'pending';
									?>
									<tr>
										<td><code style="font-size:.8rem"><?php echo esc_html( $aurora_order['no'] ); ?></code></td>
										<td><?php echo esc_html( $aurora_order['product'] ); ?></td>
										<td>¥<?php echo esc_html( number_format( $aurora_order['amount'], 2 ) ); ?></td>
										<td><span class="aurora-status aurora-status--<?php echo esc_attr( $aurora_st ); ?>"><?php echo esc_html( $aurora_statuses[ $aurora_st ] ); ?></span></td>
										<td><?php echo esc_html( $aurora_order['created'] ); ?></td>
									</tr>
								<?php endwhile; ?>
							</tbody>
						</table>
						<?php
						wp_reset_postdata();
					else :
						?>
						<p style="font-size:.9rem;color:var(--aurora-text-tertiary)"><?php esc_html_e( '还没有订单记录。', 'aurora' ); ?></p>
						<?php
					endif;
					?>
				</div>
			</div>

			<!-- Bookmarks -->
			<div class="aurora-tabpane <?php echo 'bookmarks' === $aurora_active ? 'is-active' : ''; ?>" data-pane="bookmarks" data-scope="user">
				<?php
				$aurora_bm = aurora_user_bookmarks( $aurora_uid, 12 );
				if ( $aurora_bm->have_posts() ) {
					echo '<div class="aurora-grid aurora-grid--two">';
					while ( $aurora_bm->have_posts() ) {
						$aurora_bm->the_post();
						aurora_render_card( get_the_ID(), 'grid' );
					}
					echo '</div>';
					wp_reset_postdata();
				} else {
					echo '<div class="aurora-empty"><h3>' . esc_html__( '收藏夹是空的', 'aurora' ) . '</h3><p>' . esc_html__( '在文章页点击「收藏」就会出现在这里。', 'aurora' ) . '</p></div>';
				}
				?>
			</div>

			<!-- Posts -->
			<div class="aurora-tabpane <?php echo 'posts' === $aurora_active ? 'is-active' : ''; ?>" data-pane="posts" data-scope="user">
				<?php
				$aurora_mine = new WP_Query( array(
					'author'         => $aurora_uid,
					'post_type'      => 'post',
					'posts_per_page' => 12,
					'post_status'    => array( 'publish', 'draft', 'pending' ),
					'no_found_rows'  => true,
				) );
				if ( $aurora_mine->have_posts() ) {
					echo '<div class="aurora-grid aurora-grid--two">';
					while ( $aurora_mine->have_posts() ) {
						$aurora_mine->the_post();
						aurora_render_card( get_the_ID(), 'grid' );
					}
					echo '</div>';
					wp_reset_postdata();
				} else {
					echo '<div class="aurora-empty"><h3>' . esc_html__( '还没有发布文章', 'aurora' ) . '</h3>';
					if ( current_user_can( 'edit_posts' ) ) {
						echo '<p><a class="aurora-btn aurora-btn--primary" href="' . esc_url( admin_url( 'post-new.php' ) ) . '">' . esc_html__( '去写一篇', 'aurora' ) . '</a></p>';
					}
					echo '</div>';
				}
				?>
			</div>

			<!-- Profile -->
			<div class="aurora-tabpane <?php echo 'profile' === $aurora_active ? 'is-active' : ''; ?>" data-pane="profile" data-scope="user">
				<div class="aurora-panel">
					<h3 class="aurora-panel__title"><?php aurora_the_icon( 'user', 17 ); ?><?php esc_html_e( '编辑资料', 'aurora' ); ?></h3>
					<form id="aurora-profile-form" method="post">
						<div class="aurora-field">
							<label class="aurora-field__label" for="aurora-display"><?php esc_html_e( '昵称', 'aurora' ); ?></label>
							<input type="text" id="aurora-display" name="display_name" value="<?php echo esc_attr( $aurora_user->display_name ); ?>">
						</div>
						<div class="aurora-field">
							<label class="aurora-field__label" for="aurora-url"><?php esc_html_e( '个人网站', 'aurora' ); ?></label>
							<input type="url" id="aurora-url" name="user_url" value="<?php echo esc_attr( $aurora_user->user_url ); ?>">
						</div>
						<div class="aurora-field">
							<label class="aurora-field__label" for="aurora-bio"><?php esc_html_e( '个人简介', 'aurora' ); ?></label>
							<textarea id="aurora-bio" name="description" rows="4"><?php echo esc_textarea( $aurora_user->description ); ?></textarea>
						</div>
						<button type="submit" class="aurora-btn aurora-btn--primary"><?php esc_html_e( '保存修改', 'aurora' ); ?></button>
						<p class="aurora-auth-form__msg" role="status" aria-live="polite"></p>
					</form>
					<p style="margin-top:16px;font-size:.85rem;color:var(--aurora-text-tertiary)">
						<a href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"><?php esc_html_e( '修改密码 / 头像（进入后台）', 'aurora' ); ?></a>
					</p>
				</div>
			</div>

			<?php
			// Points, cards, downloads, inbox: each lives in its own part so a
			// module can be removed without editing this template.
			get_template_part( 'template-parts/user', 'notify' );
			get_template_part( 'template-parts/user', 'points' );
			get_template_part( 'template-parts/user', 'level' );
			get_template_part( 'template-parts/user', 'redeem' );
			get_template_part( 'template-parts/user', 'downloads' );
			get_template_part( 'template-parts/user', 'submissions' );
			get_template_part( 'template-parts/user', 'income' );
			?>
		</main>

		<aside class="aurora-sidebar">
			<?php if ( is_active_sidebar( 'sidebar-user' ) ) : ?>
				<?php dynamic_sidebar( 'sidebar-user' ); ?>
			<?php endif; ?>
			<div class="aurora-panel">
				<h3 class="aurora-panel__title"><?php aurora_the_icon( 'clock', 17 ); ?><?php esc_html_e( '快捷入口', 'aurora' ); ?></h3>
				<ul style="font-size:.9rem">
					<?php
					/**
					 * Extra quick links in the user sidebar.
					 *
					 * @param array $links Rows of url => label.
					 */
					$aurora_quick = apply_filters(
						'aurora_user_quick_links',
						array(
							aurora_vip_page_url()            => __( '开通 / 续费会员', 'aurora' ),
							home_url( '/' )                  => __( '回到首页', 'aurora' ),
							get_edit_user_link( $aurora_uid ) => __( '后台个人设置', 'aurora' ),
						)
					);

					foreach ( $aurora_quick as $aurora_qurl => $aurora_qlabel ) :
						?>
						<li><a href="<?php echo esc_url( $aurora_qurl ); ?>"><?php echo esc_html( $aurora_qlabel ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</aside>
	</div>
</div>

<?php
get_footer();
