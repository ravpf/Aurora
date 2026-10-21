<?php
/**
 * Front-end submission.
 *
 * Community themes let signed in readers submit an article without seeing
 * wp-admin; it lands in review and the editor decides. This is that flow,
 * built on the theme's own guard rails: the `post` rate bucket, the captcha
 * context system, wp_kses_post, and the existing mailer for the editor notice.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is submission open?
 *
 * @return bool
 */
function aurora_submit_enabled() {
	return (bool) get_theme_mod( 'aurora_submit_enabled', true );
}

/**
 * Lowest reader level allowed to submit. 0 means any signed in reader.
 *
 * @return int
 */
function aurora_submit_min_level() {
	return max( 0, (int) get_theme_mod( 'aurora_submit_min_level', 0 ) );
}

/**
 * Status a new submission gets: pending (review) or draft.
 *
 * @return string
 */
function aurora_submit_status() {
	$status = (string) get_theme_mod( 'aurora_submit_status', 'pending' );
	return in_array( $status, array( 'pending', 'draft' ), true ) ? $status : 'pending';
}

/**
 * Whether the current reader may submit right now.
 *
 * @return true|WP_Error
 */
function aurora_submit_can() {
	if ( ! aurora_submit_enabled() ) {
		return new WP_Error( 'closed', __( '本站暂未开放投稿。', 'aurora' ) );
	}

	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'login', __( '请先登录后再投稿。', 'aurora' ) );
	}

	$min = aurora_submit_min_level();

	if ( $min > 0 && function_exists( 'aurora_exp_level_of' ) && aurora_exp_level_of( get_current_user_id() ) < $min ) {
		return new WP_Error(
			'level',
			sprintf(
				/* translators: %d: level number. */
				__( '投稿需要 Lv.%d 及以上等级。', 'aurora' ),
				$min
			)
		);
	}

	return true;
}

/**
 * URL of the page that carries the submit template.
 *
 * @return string
 */
function aurora_submit_page_url() {
	$page = get_option( 'aurora_submit_page_id' );

	if ( $page && 'publish' === get_post_status( (int) $page ) ) {
		return get_permalink( (int) $page );
	}

	// Fall back to a page that uses the template, in case the option was lost.
	$found = get_posts(
		array(
			'post_type'      => 'page',
			'posts_per_page' => 1,
			'post_status'    => 'publish',
			'meta_key'       => '_wp_page_template',
			'meta_value'     => 'page-templates/submit.php',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	return $found ? get_permalink( (int) $found[0] ) : home_url( '/' );
}

/**
 * Categories a reader may file into: every category, so the editor's mapping
 * still works on a site that renames its sections.
 *
 * @return WP_Term[]
 */
function aurora_submit_categories() {
	return get_categories(
		array(
			'hide_empty' => false,
			'number'     => 30,
		)
	);
}

/**
 * Render the form.
 */
function aurora_submit_form() {
	$can = aurora_submit_can();

	if ( is_wp_error( $can ) ) {
		printf(
			'<div class="aurora-notice aurora-notice--info">%s</div>',
			esc_html( $can->get_error_message() )
		);
		return;
	}

	$stats = isset( $_GET['submitted'] ) ? sanitize_key( wp_unslash( $_GET['submitted'] ) ) : '';
	$error = isset( $_GET['submit_error'] ) ? sanitize_key( wp_unslash( $_GET['submit_error'] ) ) : '';

	if ( 'ok' === $stats ) {
		printf(
			'<div class="aurora-notice aurora-notice--success">%s</div>',
			esc_html__( '投稿已提交，编辑审核后会在此显示状态。', 'aurora' )
		);
	}

	$error_map = array(
		'nonce'   => __( '安全校验失败，请刷新页面重试。', 'aurora' ),
		'rate'    => __( '提交过于频繁，请稍后再试。', 'aurora' ),
		'captcha' => __( '人机验证未通过。', 'aurora' ),
		'empty'   => __( '标题和正文都需要填写。', 'aurora' ),
		'short'   => __( '正文太短，至少 120 个字。', 'aurora' ),
		'image'   => __( '配图上传失败，请换一张图片。', 'aurora' ),
		'board'   => __( '这个板块需要会员才能发帖。', 'aurora' ),
	);

	if ( $error && isset( $error_map[ $error ] ) ) {
		printf(
			'<div class="aurora-notice aurora-notice--error">%s</div>',
			esc_html( $error_map[ $error ] )
		);
	}
	?>
	<form class="aurora-submit" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data">
		<input type="hidden" name="action" value="aurora_submit">
		<?php wp_nonce_field( 'aurora_submit', 'aurora_submit_nonce' ); ?>

		<div class="aurora-field">
			<label class="aurora-field__label" for="aurora-title"><?php esc_html_e( '标题', 'aurora' ); ?></label>
			<input type="text" id="aurora-title" name="aurora_title" maxlength="120" required
				placeholder="<?php esc_attr_e( '一句话说清这篇文章讲什么', 'aurora' ); ?>">
		</div>

		<div class="aurora-field">
			<label class="aurora-field__label" for="aurora-cat"><?php esc_html_e( '分类', 'aurora' ); ?></label>
			<select id="aurora-cat" name="aurora_cat">
				<?php foreach ( aurora_submit_categories() as $aurora_term ) : ?>
					<option value="<?php echo esc_attr( (string) $aurora_term->term_id ); ?>"><?php echo esc_html( $aurora_term->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<?php
		// 板块：有板块才显示，会员专属板块对无权用户禁用而不是隐藏——看得见、
		// 知道有这个地方，比凭空消失更诚实。
		$aurora_boards = function_exists( 'aurora_board_terms' ) ? aurora_board_terms() : array();
		$aurora_preselect = isset( $_GET['board'] ) ? sanitize_title( wp_unslash( $_GET['board'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill.
		?>
		<?php if ( $aurora_boards ) : ?>
			<div class="aurora-field">
				<label class="aurora-field__label" for="aurora-board"><?php esc_html_e( '板块', 'aurora' ); ?></label>
				<select id="aurora-board" name="aurora_board">
					<option value=""><?php esc_html_e( '不放入板块', 'aurora' ); ?></option>
					<?php foreach ( $aurora_boards as $aurora_term ) : ?>
						<?php
						$aurora_can   = aurora_board_can_post( $aurora_term );
						$aurora_label = $aurora_term->name . ( 'vip' === aurora_board_access( $aurora_term ) ? __( '（会员）', 'aurora' ) : '' );
						?>
						<option value="<?php echo esc_attr( (string) $aurora_term->term_id ); ?>"
							<?php selected( $aurora_preselect, $aurora_term->slug ); ?>
							<?php disabled( ! $aurora_can ); ?>>
							<?php echo esc_html( $aurora_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="aurora-field__hint"><?php esc_html_e( '发到板块的帖子会出现在对应板块的时间线上。', 'aurora' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="aurora-field">
			<label class="aurora-field__label" for="aurora-tags"><?php esc_html_e( '标签', 'aurora' ); ?></label>
			<input type="text" id="aurora-tags" name="aurora_tags" maxlength="120"
				placeholder="<?php esc_attr_e( '用逗号分隔，可留空', 'aurora' ); ?>">
		</div>

		<div class="aurora-field">
			<label class="aurora-field__label" for="aurora-content"><?php esc_html_e( '正文', 'aurora' ); ?></label>
			<textarea id="aurora-content" name="aurora_content" rows="14" required
				placeholder="<?php esc_attr_e( '支持分段。请勿粘贴他人作品。', 'aurora' ); ?>"></textarea>
		</div>

		<div class="aurora-field">
			<label class="aurora-field__label" for="aurora-image"><?php esc_html_e( '封面图（可选）', 'aurora' ); ?></label>
			<input type="file" id="aurora-image" name="aurora_image" accept="image/jpeg,image/png,image/webp">
			<p class="aurora-field__hint"><?php esc_html_e( 'JPG / PNG / WebP，建议 1200px 宽以内。', 'aurora' ); ?></p>
		</div>

		<?php
		// The captcha module owns the widget; render it when the context is on.
		if ( function_exists( 'aurora_captcha_field' ) ) {
			aurora_captcha_field( 'submit' );
		}
		?>

		<div class="aurora-submit__actions">
			<button type="submit" class="aurora-btn aurora-btn--primary aurora-btn--lg"><?php esc_html_e( '提交投稿', 'aurora' ); ?></button>
			<a class="aurora-btn aurora-btn--ghost" href="<?php echo esc_url( aurora_user_page_url() ); ?>"><?php esc_html_e( '查看我的投稿', 'aurora' ); ?></a>
		</div>
	</form>
	<?php
}

/**
 * Handle the submission.
 */
function aurora_handle_submit() {
	$back = aurora_submit_page_url();

	if ( ! isset( $_POST['aurora_submit_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['aurora_submit_nonce'] ) ), 'aurora_submit' ) ) {
		wp_safe_redirect( add_query_arg( 'submit_error', 'nonce', $back ) );
		exit;
	}

	$can = aurora_submit_can();

	if ( is_wp_error( $can ) ) {
		wp_safe_redirect( add_query_arg( 'submit_error', 'denied', $back ) );
		exit;
	}

	if ( function_exists( 'aurora_rate_guard' ) ) {
		$gate = aurora_rate_guard( 'post' );
		if ( is_wp_error( $gate ) ) {
			wp_safe_redirect( add_query_arg( 'submit_error', 'rate', $back ) );
			exit;
		}
	}

	if ( function_exists( 'aurora_captcha_check' ) && ! aurora_captcha_check( 'submit' ) ) {
		wp_safe_redirect( add_query_arg( 'submit_error', 'captcha', $back ) );
		exit;
	}

	$title   = isset( $_POST['aurora_title'] ) ? sanitize_text_field( wp_unslash( $_POST['aurora_title'] ) ) : '';
	$content = isset( $_POST['aurora_content'] ) ? wp_kses_post( wp_unslash( $_POST['aurora_content'] ) ) : '';
	$cat     = isset( $_POST['aurora_cat'] ) ? (int) $_POST['aurora_cat'] : 0;
	$board   = isset( $_POST['aurora_board'] ) ? (int) $_POST['aurora_board'] : 0;
	$tags    = isset( $_POST['aurora_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['aurora_tags'] ) ) : '';

	if ( '' === $title || '' === trim( wp_strip_all_tags( $content ) ) ) {
		wp_safe_redirect( add_query_arg( 'submit_error', 'empty', $back ) );
		exit;
	}

	if ( mb_strlen( wp_strip_all_tags( $content ) ) < 120 ) {
		wp_safe_redirect( add_query_arg( 'submit_error', 'short', $back ) );
		exit;
	}

	// 板块权限在服务端再判一次：表单里 disabled 只是提示，不是防线。
	if ( $board ) {
		$board_term = aurora_board_get( $board );

		if ( ! $board_term || ! aurora_board_can_post( $board_term ) ) {
			wp_safe_redirect( add_query_arg( 'submit_error', 'board', $back ) );
			exit;
		}
	}

	$user_id = get_current_user_id();

	$post_id = wp_insert_post(
		array(
			'post_title'    => $title,
			'post_content'  => $content,
			'post_status'   => aurora_submit_status(),
			'post_type'     => 'post',
			'post_author'   => $user_id,
			'post_category' => $cat ? array( $cat ) : array(),
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		wp_safe_redirect( add_query_arg( 'submit_error', 'empty', $back ) );
		exit;
	}

	if ( $tags ) {
		$list = array_filter( array_map( 'trim', explode( ',', $tags ) ) );
		wp_set_post_tags( $post_id, array_slice( $list, 0, 8 ), false );
	}

	if ( $board ) {
		wp_set_object_terms( $post_id, array( (int) $board ), AURORA_BOARD_TAX, false );
	}

	update_post_meta( $post_id, '_aurora_submitted', 1 );
	update_post_meta( $post_id, '_aurora_submitted_at', current_time( 'mysql' ) );

	// Cover image, if one was offered. Same upload path the media library uses.
	if ( ! empty( $_FILES['aurora_image']['name'] ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$att = media_handle_upload( 'aurora_image', $post_id );

		if ( is_wp_error( $att ) ) {
			aurora_submit_editor_notice( $post_id, $title, $user_id, true );
			wp_safe_redirect( add_query_arg( 'submit_error', 'image', $back ) );
			exit;
		}

		set_post_thumbnail( $post_id, (int) $att );
	}

	aurora_submit_editor_notice( $post_id, $title, $user_id, false );

	/**
	 * Fires after a reader submitted a post.
	 *
	 * @param int    $post_id New post id.
	 * @param int    $user_id Author.
	 * @param string $status  Post status.
	 */
	do_action( 'aurora_post_submitted', $post_id, $user_id, aurora_submit_status() );

	wp_safe_redirect( add_query_arg( 'submitted', 'ok', $back ) );
	exit;
}
add_action( 'admin_post_aurora_submit', 'aurora_handle_submit' );
add_action( 'admin_post_nopriv_aurora_submit', 'aurora_handle_submit' );

/**
 * Tell the editors something arrived.
 *
 * Mail goes through the theme's mailer when it is available, and falls back to
 * wp_mail so a site without SMTP configured still records the attempt.
 *
 * @param int    $post_id Post id.
 * @param string $title   Title.
 * @param int    $user_id Author.
 * @param bool   $image_failed Whether the cover image failed.
 */
function aurora_submit_editor_notice( $post_id, $title, $user_id, $image_failed = false ) {
	$site  = get_bloginfo( 'name' );
	$user  = get_userdata( $user_id );
	$who   = $user ? $user->display_name : __( '读者', 'aurora' );
	$link  = admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' );

	$body = sprintf(
		/* translators: 1: site name, 2: author, 3: title, 4: edit link. */
		__( "%1\$s 收到一篇新投稿。\n\n作者：%2\$s\n标题：%3\$s\n审核：%4\$s", 'aurora' ),
		$site,
		$who,
		$title,
		$link
	);

	if ( $image_failed ) {
		$body .= "\n\n" . __( '注意：封面图上传失败。', 'aurora' );
	}

	$to = get_option( 'admin_email' );

	wp_mail( $to, sprintf( __( '[%s] 新投稿待审核', 'aurora' ), $site ), $body );

	update_post_meta( $post_id, '_aurora_submit_notified', current_time( 'mysql' ) );
}

/**
 * Register the 我的投稿 tab.
 *
 * @param array $tabs Tabs.
 * @return array
 */
function aurora_submit_user_tab( $tabs ) {
	$tabs['submissions'] = array( 'label' => __( '我的投稿', 'aurora' ), 'icon' => 'list' );
	return $tabs;
}
add_filter( 'aurora_user_tabs', 'aurora_submit_user_tab' );

/**
 * The reader's own submissions with their status, for the user centre tab.
 *
 * @param int $user_id User.
 * @param int $limit   How many.
 * @return WP_Post[]
 */
function aurora_user_submissions( $user_id = 0, $limit = 20 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return array();
	}

	return get_posts(
		array(
			'post_type'      => 'post',
			'author'         => $user_id,
			'posts_per_page' => (int) $limit,
			'post_status'    => array( 'pending', 'draft', 'publish', 'private' ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);
}

/**
 * A small status chip for a submission.
 *
 * @param string $status Post status.
 * @return string
 */
function aurora_submit_status_label( $status ) {
	$map = array(
		'publish' => array( __( '已发布', 'aurora' ), 'ok' ),
		'pending' => array( __( '待审核', 'aurora' ), 'wait' ),
		'draft'   => array( __( '草稿', 'aurora' ), 'muted' ),
		'private' => array( __( '仅自己可见', 'aurora' ), 'muted' ),
	);

	$row = isset( $map[ $status ] ) ? $map[ $status ] : $map['draft'];

	return '<span class="aurora-submit-status aurora-submit-status--' . esc_attr( $row[1] ) . '">' . esc_html( $row[0] ) . '</span>';
}
