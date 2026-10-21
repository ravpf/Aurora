<?php
/**
 * Template for /ai-ask.
 *
 * A standalone shell rather than a page template: the route exists as soon as the
 * theme is active, without asking the owner to create a page and remember to
 * assign a template to it.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();
aurora_ai_qa_assets();

$aurora_ai_ready = aurora_ai_ready( 'qa' );
$aurora_ai_why   = aurora_ai_blocked_reason();
?>

<div class="aurora-container">
	<div class="aurora-layout">
		<main id="aurora-primary" class="aurora-primary">
			<article class="aurora-article aurora-aiqa">
				<header class="aurora-article__header">
					<h1 class="aurora-article__title"><?php esc_html_e( '问本站', 'aurora' ); ?></h1>
					<p class="aurora-article__lead"><?php esc_html_e( '提一个问题，先翻遍站内文章，再基于找到的内容回答，并列出出处。', 'aurora' ); ?></p>
				</header>

				<form class="aurora-aiqa__form" data-aurora-qa-form>
					<label class="screen-reader-text" for="aurora-aiqa-q"><?php esc_html_e( '你的问题', 'aurora' ); ?></label>
					<input
						id="aurora-aiqa-q"
						class="aurora-aiqa__input"
						type="text"
						name="question"
						maxlength="200"
						autocomplete="off"
						placeholder="<?php esc_attr_e( '例如：这个站的主题是怎么做暗色模式的？', 'aurora' ); ?>"
						<?php disabled( ! $aurora_ai_ready ); ?>
					>
					<button class="aurora-aiqa__send" type="submit" <?php disabled( ! $aurora_ai_ready ); ?>>
						<?php esc_html_e( '提问', 'aurora' ); ?>
					</button>
				</form>

				<?php if ( ! $aurora_ai_ready ) : ?>
					<p class="aurora-aiqa__note">
						<?php echo esc_html( $aurora_ai_why ?: __( '问答功能未启用。', 'aurora' ) ); ?>
					</p>
				<?php endif; ?>

				<div class="aurora-aiqa__examples">
					<span><?php esc_html_e( '试试：', 'aurora' ); ?></span>
					<?php
					$examples = array(
						__( '这个站有哪些主题功能？', 'aurora' ),
						__( '怎么开通会员？', 'aurora' ),
						__( '板块和普通文章有什么区别？', 'aurora' ),
					);

					foreach ( $examples as $example ) :
						?>
						<button type="button" class="aurora-aiqa__chip" data-aurora-qa-example="<?php echo esc_attr( $example ); ?>"><?php echo esc_html( $example ); ?></button>
					<?php endforeach; ?>
				</div>

				<div class="aurora-aiqa__result" data-aurora-qa-result hidden>
					<div class="aurora-aiqa__answer" data-aurora-qa-answer></div>
					<div class="aurora-aiqa__sources" data-aurora-qa-sources></div>
				</div>
			</article>
		</main>

		<?php get_sidebar(); ?>
	</div>
</div>

<?php
get_footer();
