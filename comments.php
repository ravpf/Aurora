<?php
/**
 * Comments template.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

if ( post_password_required() ) {
	return;
}
?>
<section id="comments" class="aurora-comments">
	<h2 class="aurora-comments__title">
		<?php
		$aurora_count = get_comments_number();
		if ( $aurora_count > 0 ) {
			printf(
				/* translators: %s: comment count */
				esc_html__( '%s 条评论', 'aurora' ),
				esc_html( number_format_i18n( $aurora_count ) )
			);
		} else {
			esc_html_e( '评论', 'aurora' );
		}
		?>
	</h2>

	<?php if ( have_comments() ) : ?>
		<?php aurora_comment_sort_ui(); ?>
		<ol id="commentlist" class="aurora-comment-list">
			<?php
			wp_list_comments( array(
				'style'       => 'ol',
				'short_ping'  => true,
				'avatar_size' => 80,
				'callback'    => 'aurora_comment_template',
			) );
			?>
		</ol>

		<?php
		the_comments_pagination( array(
			'prev_text' => aurora_icon( 'chevron', 16 ),
			'next_text' => aurora_icon( 'chevron', 16 ),
			'class'     => 'aurora-pagination',
		) );
		?>

		<?php if ( ! comments_open() ) : ?>
			<p class="aurora-notice"><?php esc_html_e( '评论已关闭。', 'aurora' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php
	comment_form( array(
		'class_form'           => 'aurora-comment-form',
		'title_reply'          => __( '发表评论', 'aurora' ),
		'title_reply_before'   => '<h3 id="reply-title" class="aurora-comments__title">',
		'title_reply_after'    => '</h3>',
		'comment_notes_before' => '<p style="font-size:.86rem;color:var(--aurora-text-tertiary)">' . esc_html__( '邮箱不会被公开。必填项已用 * 标记。', 'aurora' ) . '</p>',
		'comment_field'        => aurora_comment_emoji_toolbar() . '<p class="aurora-field"><label class="aurora-field__label" for="comment">' . esc_html__( '评论内容', 'aurora' ) . '</label><textarea id="comment" name="comment" rows="5" required></textarea></p>',
		'class_submit'         => 'aurora-btn aurora-btn--primary aurora-btn--lg',
		'label_submit'         => __( '发布评论', 'aurora' ),
	) );
	?>
</section>
<?php
/**
 * Single comment renderer.
 *
 * @param WP_Comment $comment Comment object.
 * @param array      $args    Args.
 * @param int        $depth   Depth.
 */
function aurora_comment_template( $comment, $args, $depth ) {
	?>
	<li id="comment-<?php comment_ID(); ?>" <?php comment_class( 'aurora-comment-item' ); ?>>
		<article class="aurora-comment">
			<div class="aurora-comment__avatar">
				<?php echo get_avatar( $comment, 80 ); ?>
			</div>
			<div class="aurora-comment__main">
				<header class="aurora-comment__head">
					<?php
					$aurora_floor = aurora_comment_floor( $comment, (int) $depth );
					if ( $aurora_floor ) :
						?>
						<span class="aurora-comment__floor">#<?php echo esc_html( (string) $aurora_floor ); ?></span>
					<?php endif; ?>
					<span class="aurora-comment__author"><?php echo esc_html( get_comment_author( $comment ) ); ?></span>
					<?php if ( function_exists( 'aurora_vip_badge' ) && $comment->user_id ) : ?>
						<?php aurora_vip_badge( $comment->user_id ); ?>
					<?php endif; ?>
					<?php aurora_comment_level_badge( (int) $comment->user_id ); ?>
					<time class="aurora-comment__date" datetime="<?php echo esc_attr( get_comment_date( 'c', $comment ) ); ?>">
						<?php echo esc_html( get_comment_date( '', $comment ) ); ?>
					</time>
					<?php if ( '0' === $comment->comment_approved ) : ?>
						<span class="aurora-tag"><?php esc_html_e( '待审核', 'aurora' ); ?></span>
					<?php endif; ?>
				</header>

				<div class="aurora-comment__body">
					<?php comment_text( $comment ); ?>
				</div>

				<footer class="aurora-comment__reply">
					<?php
					comment_reply_link( array_merge( $args, array(
						'depth'     => $depth,
						'max_depth' => $args['max_depth'],
						'reply_text' => __( '回复', 'aurora' ),
					) ), $comment );
					?>
					<?php edit_comment_link( __( '编辑', 'aurora' ), ' · ' ); ?>
				</footer>
			</div>
		</article>
	<?php
	// No closing </li>: WordPress closes it.
}
