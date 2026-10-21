<?php
/**
 * Template Name: 写作
 *
 * The front-end editor. Deliberately one text column plus a small side panel:
 * the block editor's canvas, inspector and toolbar are what make writing on the
 * front end feel like assembly work.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

get_header();

$aurora_editing = function_exists( 'aurora_editor_current_post' ) ? aurora_editor_current_post() : null;
$aurora_can     = aurora_submit_can();
$aurora_title   = $aurora_editing ? $aurora_editing->post_title : '';
$aurora_body    = $aurora_editing ? $aurora_editing->post_content : '';
$aurora_terms   = wp_get_post_categories( $aurora_editing ? $aurora_editing->ID : 0 );
$aurora_tags    = $aurora_editing ? implode( ', ', wp_get_post_tags( $aurora_editing->ID, array( 'fields' => 'names' ) ) ) : '';
?>

<div class="aurora-container aurora-container--wide">
	<?php if ( is_wp_error( $aurora_can ) ) : ?>
		<div class="aurora-panel" style="margin-top:24px">
			<h1 class="aurora-section__title"><?php esc_html_e( '写作', 'aurora' ); ?></h1>
			<div class="aurora-notice aurora-notice--info"><?php echo esc_html( $aurora_can->get_error_message() ); ?></div>
			<p><a class="aurora-btn aurora-btn--primary" href="<?php echo esc_url( wp_login_url( aurora_editor_page_url() ) ); ?>"><?php esc_html_e( '去登录', 'aurora' ); ?></a></p>
		</div>
	<?php else : ?>

	<div class="aurora-editor"
		data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'aurora_nonce' ) ); ?>"
		data-post="<?php echo esc_attr( (string) ( $aurora_editing ? $aurora_editing->ID : 0 ) ); ?>"
		data-status="<?php echo esc_attr( $aurora_editing ? $aurora_editing->post_status : '' ); ?>">

		<header class="aurora-editor__bar">
			<div class="aurora-editor__bar-left">
				<a class="aurora-editor__back" href="<?php echo esc_url( aurora_user_page_url() ); ?>"><?php aurora_the_icon( 'chevron-left', 16 ); ?><?php esc_html_e( '用户中心', 'aurora' ); ?></a>
				<span class="aurora-editor__state" data-state>
					<?php echo $aurora_editing ? esc_html( 'publish' === $aurora_editing->post_status ? __( '编辑已发布文章', 'aurora' ) : __( '编辑草稿', 'aurora' ) ) : esc_html__( '新稿', 'aurora' ); ?>
				</span>
			</div>
			<div class="aurora-editor__bar-right">
				<span class="aurora-editor__count" data-count>0 <?php esc_html_e( '字', 'aurora' ); ?></span>
				<span class="aurora-editor__saved" data-saved><?php esc_html_e( '尚未保存', 'aurora' ); ?></span>
				<button type="button" class="aurora-btn aurora-btn--ghost" data-save><?php esc_html_e( '保存草稿', 'aurora' ); ?></button>
				<button type="button" class="aurora-btn aurora-btn--primary" data-submit><?php esc_html_e( '提交审核', 'aurora' ); ?></button>
			</div>
		</header>

		<div class="aurora-editor__grid">
			<main class="aurora-editor__main">
				<input class="aurora-editor__title" data-title type="text" maxlength="120"
					value="<?php echo esc_attr( $aurora_title ); ?>"
					placeholder="<?php esc_attr_e( '标题', 'aurora' ); ?>">

				<div class="aurora-editor__toolbar" role="toolbar">
					<button type="button" data-cmd="h2" title="小标题">H2</button>
					<button type="button" data-cmd="bold" title="加粗"><b>B</b></button>
					<button type="button" data-cmd="italic" title="斜体"><i>I</i></button>
					<button type="button" data-cmd="quote" title="引用">“”</button>
					<button type="button" data-cmd="code" title="代码块">&lt;/&gt;</button>
					<button type="button" data-cmd="ul" title="无序列表">•</button>
					<button type="button" data-cmd="ol" title="有序列表">1.</button>
					<button type="button" data-cmd="link" title="链接"><?php esc_html_e( '链接', 'aurora' ); ?></button>
					<button type="button" data-cmd="image" title="插入图片"><?php esc_html_e( '图片', 'aurora' ); ?></button>
					<span class="aurora-editor__spacer"></span>
					<button type="button" data-cmd="undo" title="撤销">↶</button>
					<button type="button" data-cmd="redo" title="重做">↷</button>
					<input type="file" accept="image/*" data-file hidden>
				</div>

				<div class="aurora-editor__canvas" contenteditable="true" spellcheck="true"
					data-canvas data-placeholder="<?php esc_attr_e( '从这里开始写……选中文字再点工具栏，可以加粗、引用、插入图片。', 'aurora' ); ?>"><?php echo wp_kses_post( $aurora_body ); ?></div>
			</main>

			<aside class="aurora-editor__side">
				<div class="aurora-editor__section">
					<label class="aurora-field__label" for="aurora-edit-cat"><?php esc_html_e( '分类', 'aurora' ); ?></label>
					<select id="aurora-edit-cat" data-cat>
						<?php foreach ( aurora_submit_categories() as $aurora_term ) : ?>
							<option value="<?php echo esc_attr( (string) $aurora_term->term_id ); ?>"
								<?php selected( in_array( (int) $aurora_term->term_id, array_map( 'intval', $aurora_terms ), true ) ); ?>>
								<?php echo esc_html( $aurora_term->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="aurora-editor__section">
					<label class="aurora-field__label" for="aurora-edit-tags"><?php esc_html_e( '标签', 'aurora' ); ?></label>
					<input id="aurora-edit-tags" type="text" data-tags value="<?php echo esc_attr( $aurora_tags ); ?>"
						placeholder="<?php esc_attr_e( '用逗号分隔', 'aurora' ); ?>">
				</div>

				<?php $aurora_drafts = aurora_editor_recent_drafts( 5 ); ?>
				<?php if ( $aurora_drafts ) : ?>
					<div class="aurora-editor__section">
						<span class="aurora-field__label"><?php esc_html_e( '最近的草稿', 'aurora' ); ?></span>
						<ul class="aurora-editor__drafts">
							<?php foreach ( $aurora_drafts as $aurora_draft ) : ?>
								<li>
									<a href="<?php echo esc_url( add_query_arg( 'post', $aurora_draft->ID, aurora_editor_page_url() ) ); ?>">
										<?php echo esc_html( $aurora_draft->post_title ? $aurora_draft->post_title : __( '（无标题）', 'aurora' ) ); ?>
									</a>
									<span><?php echo esc_html( get_the_date( 'm-d H:i', $aurora_draft ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<div class="aurora-editor__section aurora-editor__hints">
					<span class="aurora-field__label"><?php esc_html_e( '快捷键', 'aurora' ); ?></span>
					<p><?php esc_html_e( 'Ctrl / ⌘ + S 保存草稿', 'aurora' ); ?></p>
					<p><?php esc_html_e( 'Ctrl / ⌘ + Enter 提交审核', 'aurora' ); ?></p>
					<p><?php esc_html_e( '每 45 秒自动保存一次', 'aurora' ); ?></p>
				</div>
			</aside>
		</div>

		<p class="aurora-editor__message" data-message aria-live="polite"></p>
	</div>

	<?php endif; ?>
</div>

<?php
get_footer();
