<?php
/**
 * Template Name: 投稿
 *
 * Front-end submission page. The form itself lives in inc/submission.php so the
 * guard rails (level, rate bucket, captcha) stay with the handler.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="aurora-container">
	<div class="aurora-layout aurora-layout--submit">
		<main id="aurora-primary" class="aurora-primary">
			<?php aurora_breadcrumbs(); ?>

			<section class="aurora-panel aurora-submit-panel">
				<h1 class="aurora-section__title"><?php esc_html_e( '投稿', 'aurora' ); ?></h1>
				<p class="aurora-submit-panel__lead">
					<?php esc_html_e( '写下你想分享的内容，编辑审核通过后会署你的名字发布。请勿提交他人作品或含广告的内容。', 'aurora' ); ?>
				</p>

				<?php
				if ( have_posts() ) {
					while ( have_posts() ) {
						the_post();
						the_content();
					}
				}

				?>
				<p class="aurora-submit-panel__lead">
					<?php esc_html_e( '想要更顺手的写作界面？', 'aurora' ); ?>
					<a href="<?php echo esc_url( aurora_editor_page_url() ); ?>"><?php esc_html_e( '用前台编辑器写作（支持自动保存、插图、草稿）', 'aurora' ); ?></a>
				</p>
				<?php

				aurora_submit_form();
				?>
			</section>
		</main>

		<aside class="aurora-sidebar">
			<div class="aurora-panel">
				<h3 class="aurora-panel__title"><?php aurora_the_icon( 'check', 17 ); ?><?php esc_html_e( '投稿须知', 'aurora' ); ?></h3>
				<ul class="aurora-submit-rules">
					<li><?php esc_html_e( '正文至少 120 字，标题不超过 120 字。', 'aurora' ); ?></li>
					<li><?php esc_html_e( '一人一天最多 3 篇，避免刷量。', 'aurora' ); ?></li>
					<li><?php esc_html_e( '审核通过后可在「用户中心 → 我的投稿」看到状态。', 'aurora' ); ?></li>
					<li><?php esc_html_e( '封面图可选，建议 1200px 宽以内。', 'aurora' ); ?></li>
				</ul>
			</div>
		</aside>
	</div>
</div>

<?php
get_footer();
