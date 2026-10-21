<?php
/**
 * Template Name: 板块索引
 *
 * Every board with its thread count, intro and the latest topic. This is the
 * map of the community: a reader picks a room before reading a thread.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();

$aurora_boards = aurora_board_terms();
?>

<div class="aurora-container">
	<header class="aurora-pagehead">
		<?php aurora_breadcrumbs(); ?>
		<h1 class="aurora-pagehead__title"><?php echo esc_html( get_the_title() ); ?></h1>
		<?php if ( trim( (string) get_the_content() ) ) : ?>
			<div class="aurora-pagehead__desc"><?php the_content(); ?></div>
		<?php else : ?>
			<p class="aurora-pagehead__desc"><?php esc_html_e( '按话题分区。进板块看主题，登录后可以直接发帖。', 'aurora' ); ?></p>
		<?php endif; ?>
		<p class="aurora-pagehead__meta">
			<?php
			printf(
				/* translators: %s: board count */
				esc_html__( '共 %s 个板块', 'aurora' ),
				esc_html( number_format_i18n( count( $aurora_boards ) ) )
			);
			?>
		</p>
	</header>

	<?php if ( ! $aurora_boards ) : ?>
		<div class="aurora-empty">
			<?php aurora_the_icon( 'grid', 32 ); ?>
			<h3><?php esc_html_e( '还没有板块', 'aurora' ); ?></h3>
			<p><?php esc_html_e( '后台「文章 → 板块」里建一个，这里就会出现。', 'aurora' ); ?></p>
		</div>
	<?php else : ?>
		<div class="aurora-boardgrid">
			<?php foreach ( $aurora_boards as $aurora_board ) : ?>
				<?php
				$aurora_latest = aurora_board_latest( $aurora_board );
				$aurora_color  = aurora_board_color( $aurora_board );
				?>
				<a class="aurora-boardcard" href="<?php echo esc_url( aurora_board_url( $aurora_board ) ); ?>"
					style="--board-color:<?php echo esc_attr( $aurora_color ); ?>">
					<span class="aurora-boardcard__icon"><?php aurora_the_icon( aurora_board_icon( $aurora_board ), 24 ); ?></span>
					<span class="aurora-boardcard__body">
						<span class="aurora-boardcard__name">
							<?php echo esc_html( $aurora_board->name ); ?>
							<?php if ( 'vip' === aurora_board_access( $aurora_board ) ) : ?>
								<span class="aurora-badge aurora-badge--feat"><?php esc_html_e( '会员', 'aurora' ); ?></span>
							<?php endif; ?>
						</span>
						<span class="aurora-boardcard__desc">
							<?php echo esc_html( wp_trim_words( wp_strip_all_tags( term_description( $aurora_board ) ), 26, '…' ) ); ?>
						</span>
						<span class="aurora-boardcard__meta">
							<?php
							printf(
								/* translators: %s: thread count */
								esc_html__( '%s 个主题', 'aurora' ),
								esc_html( number_format_i18n( (int) $aurora_board->count ) )
							);
							if ( $aurora_latest ) {
								echo ' · ' . esc_html( wp_trim_words( get_the_title( $aurora_latest ), 14, '…' ) );
							}
							?>
						</span>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>

<?php
get_footer();
