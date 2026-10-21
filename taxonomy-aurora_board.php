<?php
/**
 * Board archive (板块 / 圈子).
 *
 * Pinned threads get their own block above the list; the paged list excludes
 * them, so nothing appears twice and page numbers match what a reader counts.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();

$aurora_board = get_queried_object();
$aurora_paged = max( 1, (int) get_query_var( 'paged' ) );
// 置顶块只在第一页出现：翻到第二页还重复同一批置顶，读者会以为内容没变。
$aurora_pins  = 1 === $aurora_paged ? aurora_board_pinned( $aurora_board, 3 ) : array();
$aurora_q     = aurora_board_query( $aurora_board, $aurora_paged, 12 );
$aurora_submit = aurora_submit_page_url();
?>

<div class="aurora-container">
	<header class="aurora-pagehead aurora-boardhead" style="--board-color:<?php echo esc_attr( aurora_board_color( $aurora_board ) ); ?>">
		<?php aurora_breadcrumbs(); ?>

		<div class="aurora-boardhead__row">
			<span class="aurora-boardhead__icon"><?php aurora_the_icon( aurora_board_icon( $aurora_board ), 26 ); ?></span>
			<div>
				<h1 class="aurora-pagehead__title"><?php echo esc_html( single_term_title( '', false ) ); ?></h1>
				<p class="aurora-pagehead__meta">
					<?php
					printf(
						/* translators: %s: thread count */
						esc_html__( '%s 个主题', 'aurora' ),
						esc_html( number_format_i18n( (int) $aurora_board->count ) )
					);
					?>
					<?php if ( 'vip' === aurora_board_access( $aurora_board ) ) : ?>
						· <span class="aurora-badge aurora-badge--feat"><?php esc_html_e( '仅会员可发帖', 'aurora' ); ?></span>
					<?php endif; ?>
				</p>
			</div>

			<div class="aurora-boardhead__action">
				<?php if ( aurora_board_can_post( $aurora_board ) ) : ?>
					<a class="aurora-btn aurora-btn--primary" href="<?php echo esc_url( add_query_arg( 'board', $aurora_board->slug, $aurora_submit ) ); ?>">
						<?php esc_html_e( '发新帖', 'aurora' ); ?>
					</a>
				<?php elseif ( ! is_user_logged_in() ) : ?>
					<a class="aurora-btn" href="<?php echo esc_url( wp_login_url( get_term_link( $aurora_board ) ) ); ?>"><?php esc_html_e( '登录后发帖', 'aurora' ); ?></a>
				<?php else : ?>
					<a class="aurora-btn" href="<?php echo esc_url( aurora_vip_page_url() ); ?>"><?php esc_html_e( '开通会员后发帖', 'aurora' ); ?></a>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( term_description( $aurora_board ) ) : ?>
			<div class="aurora-pagehead__desc"><?php echo wp_kses_post( term_description( $aurora_board ) ); ?></div>
		<?php endif; ?>
	</header>
</div>

<div class="aurora-container aurora-board">
	<?php if ( $aurora_pins ) : ?>
		<section class="aurora-pins" aria-label="<?php esc_attr_e( '置顶主题', 'aurora' ); ?>">
			<h2 class="aurora-pins__title"><?php esc_html_e( '置顶', 'aurora' ); ?></h2>
			<div class="aurora-threadlist aurora-threadlist--pinned">
				<?php
				foreach ( $aurora_pins as $aurora_pin ) {
					aurora_board_row( $aurora_pin );
				}
				?>
			</div>
		</section>
	<?php endif; ?>

	<?php if ( $aurora_q->have_posts() ) : ?>
		<div class="aurora-threadlist">
			<?php
			while ( $aurora_q->have_posts() ) {
				$aurora_q->the_post();
				aurora_board_row( get_post() );
			}
			?>
		</div>

		<?php
		$a = (int) ceil( $aurora_q->found_posts / 12 );
		if ( $a > 1 ) :
			?>
			<nav class="aurora-pager" aria-label="<?php esc_attr_e( '板块翻页', 'aurora' ); ?>">
				<?php
				echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core output.
					array(
						'total'     => $a,
						'current'   => $aurora_paged,
						'prev_text' => esc_html__( '上一页', 'aurora' ),
						'next_text' => esc_html__( '下一页', 'aurora' ),
						'base'      => trailingslashit( get_term_link( $aurora_board ) ) . 'page/%#%/',
						'format'    => '',
					)
				);
				?>
			</nav>
			<?php
		endif;
		wp_reset_postdata();
		?>
	<?php else : ?>
		<?php if ( ! $aurora_pins ) : ?>
			<div class="aurora-empty">
				<?php aurora_the_icon( aurora_board_icon( $aurora_board ), 32 ); ?>
				<h3><?php esc_html_e( '这个板块还没有主题', 'aurora' ); ?></h3>
				<p><?php esc_html_e( '做第一个开口的人。', 'aurora' ); ?></p>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>

<?php
get_footer();
