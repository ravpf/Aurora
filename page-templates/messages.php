<?php
/**
 * Template Name: 消息中心
 *
 * The full notification list: filter by unread, page through everything, mark
 * one or all as read. The header bell stays a quick preview.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();

$aurora_user   = get_current_user_id();
$aurora_unread = isset( $_GET['filter'] ) && 'unread' === $_GET['filter'];
$aurora_paged  = max( 1, (int) ( isset( $_GET['mpage'] ) ? $_GET['mpage'] : 1 ) );
$aurora_per    = aurora_inbox_per_page();
$aurora_total  = $aurora_user ? aurora_notify_total( $aurora_user, $aurora_unread ) : 0;
$aurora_pages  = (int) ceil( $aurora_total / $aurora_per );
$aurora_rows   = $aurora_user ? aurora_notify_list( $aurora_user, $aurora_per, $aurora_unread, ( $aurora_paged - 1 ) * $aurora_per ) : array();
$aurora_all    = $aurora_user ? aurora_notify_total( $aurora_user, false ) : 0;
$aurora_new    = $aurora_user ? aurora_notify_unread() : 0;
?>

<div class="aurora-container">
	<header class="aurora-pagehead">
		<h1 class="aurora-pagehead__title"><?php the_title(); ?></h1>
		<div class="aurora-pagehead__meta">
			<?php
			printf(
				/* translators: 1: total, 2: unread. */
				esc_html__( '共 %1$s 条消息，%2$s 条未读', 'aurora' ),
				esc_html( number_format_i18n( $aurora_all ) ),
				esc_html( number_format_i18n( $aurora_new ) )
			);
			?>
		</div>
	</header>
</div>

<div class="aurora-container">
	<div class="aurora-layout">
		<main id="aurora-primary" class="aurora-primary">
			<?php if ( ! $aurora_user ) : ?>
				<div class="aurora-empty">
					<p><?php esc_html_e( '登录后才能查看你的消息。', 'aurora' ); ?></p>
					<a class="aurora-btn aurora-btn--primary" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">
						<?php esc_html_e( '登录', 'aurora' ); ?>
					</a>
				</div>
			<?php else : ?>
				<div class="aurora-inbox-tabs">
					<a class="aurora-inbox-tab<?php echo $aurora_unread ? '' : ' is-active'; ?>"
						href="<?php echo esc_url( aurora_inbox_url() ); ?>"><?php esc_html_e( '全部', 'aurora' ); ?></a>
					<a class="aurora-inbox-tab<?php echo $aurora_unread ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( add_query_arg( 'filter', 'unread', aurora_inbox_url() ) ); ?>">
						<?php esc_html_e( '未读', 'aurora' ); ?><span data-inbox-unread><?php echo esc_html( (string) $aurora_new ); ?></span>
					</a>

					<?php if ( $aurora_new > 0 ) : ?>
						<button type="button" class="aurora-btn aurora-btn--ghost aurora-inbox-readall" id="aurora-inbox-readall"
							data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'aurora_nonce' ) ); ?>">
							<?php esc_html_e( '全部标为已读', 'aurora' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<?php if ( $aurora_rows ) : ?>
					<ul class="aurora-inbox" id="aurora-inbox">
						<?php foreach ( $aurora_rows as $aurora_row ) : ?>
							<li class="aurora-inbox__item<?php echo $aurora_row['read'] ? ' is-read' : ' is-unread'; ?>"
								data-id="<?php echo esc_attr( (string) $aurora_row['id'] ); ?>">
								<a class="aurora-inbox__link" href="<?php echo esc_url( aurora_inbox_row_link( $aurora_row ) ); ?>">
									<span class="aurora-inbox__dot" aria-hidden="true"></span>
									<span class="aurora-inbox__body">
										<span class="aurora-inbox__top">
											<span class="aurora-inbox__label"><?php echo esc_html( $aurora_row['label'] ); ?></span>
											<span class="aurora-inbox__time"><?php echo esc_html( $aurora_row['created'] ); ?></span>
										</span>
										<span class="aurora-inbox__title"><?php echo esc_html( $aurora_row['title'] ); ?></span>
										<?php if ( $aurora_row['body'] ) : ?>
											<span class="aurora-inbox__text"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $aurora_row['body'] ), 28 ) ); ?></span>
										<?php endif; ?>
									</span>
								</a>
								<?php if ( ! $aurora_row['read'] ) : ?>
									<button type="button" class="aurora-inbox__mark" data-id="<?php echo esc_attr( (string) $aurora_row['id'] ); ?>"
										aria-label="<?php esc_attr_e( '标为已读', 'aurora' ); ?>"><?php esc_html_e( '标为已读', 'aurora' ); ?></button>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>

					<?php echo aurora_inbox_pager( $aurora_paged, $aurora_pages ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php else : ?>
					<div class="aurora-empty">
						<p><?php echo $aurora_unread ? esc_html__( '没有未读消息。', 'aurora' ) : esc_html__( '还没有收到消息。', 'aurora' ); ?></p>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</main>

		<?php get_sidebar(); ?>
	</div>
</div>

<?php
get_footer();
