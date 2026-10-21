<?php
/**
 * Boards (板块 / 圈子).
 *
 * A board is a taxonomy on posts, not a second post type. That is the whole
 * point: a thread is still an article, so SEO, comments, quoting, the paywall
 * and the existing submission flow keep working, and nothing has to be synced
 * between two tables.
 *
 * Moderation lives on post meta (`_aurora_pinned`, `_aurora_featured`) and is
 * only writable by editors. Pinned threads are rendered as their own block on
 * the board page, above the normal paged list — they are not injected into the
 * query, so a pinned thread cannot appear twice and pagination stays honest.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

const AURORA_BOARD_TAX = 'aurora_board';

/**
 * Register the taxonomy and its term fields.
 */
function aurora_board_register() {
	register_taxonomy(
		AURORA_BOARD_TAX,
		array( 'post' ),
		array(
			'labels'            => array(
				'name'          => __( '板块', 'aurora' ),
				'singular_name' => __( '板块', 'aurora' ),
				'menu_name'     => __( '板块', 'aurora' ),
				'all_items'     => __( '全部板块', 'aurora' ),
				'add_new_item'  => __( '新建板块', 'aurora' ),
				'edit_item'     => __( '编辑板块', 'aurora' ),
				'search_items'  => __( '搜索板块', 'aurora' ),
			),
			'public'            => true,
			'hierarchical'      => false,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => 'board', 'with_front' => false ),
		)
	);
}
add_action( 'init', 'aurora_board_register', 6 );

/**
 * Boards in display order.
 *
 * @return WP_Term[]
 */
function aurora_board_terms() {
	$terms = get_terms(
		array(
			'taxonomy'   => AURORA_BOARD_TAX,
			'hide_empty' => false,
			'orderby'    => 'meta_value_num',
			'meta_key'   => '_board_order', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'      => 'ASC',
		)
	);

	if ( is_wp_error( $terms ) ) {
		return array();
	}

	return $terms;
}

/**
 * One board by slug or id.
 *
 * @param string|int $key Slug or term id.
 * @return WP_Term|null
 */
function aurora_board_get( $key ) {
	$term = is_numeric( $key )
		? get_term( (int) $key, AURORA_BOARD_TAX )
		: get_term_by( 'slug', (string) $key, AURORA_BOARD_TAX );

	return ( $term && ! is_wp_error( $term ) ) ? $term : null;
}

/**
 * Board icon key. Falls back to a generic one.
 *
 * @param WP_Term $term Board.
 * @return string
 */
function aurora_board_icon( $term ) {
	$icon = (string) get_term_meta( $term->term_id, '_board_icon', true );

	return $icon ? $icon : 'message';
}

/**
 * Board accent colour, sanitised to a hex value.
 *
 * @param WP_Term $term Board.
 * @return string
 */
function aurora_board_color( $term ) {
	$color = (string) get_term_meta( $term->term_id, '_board_color', true );

	return preg_match( '/^#[0-9a-f]{6}$/i', $color ) ? $color : '#0071e3';
}

/**
 * Who may start a thread here.
 *
 * @param WP_Term $term Board.
 * @return string 'all' or 'vip'.
 */
function aurora_board_access( $term ) {
	return 'vip' === get_term_meta( $term->term_id, '_board_access', true ) ? 'vip' : 'all';
}

/**
 * Is the current user allowed to post in this board?
 *
 * @param WP_Term|null $term Board.
 * @return bool
 */
/**
 * Board type: 'discussion' or 'qa'.
 *
 * A QA board is not a different content type — it is a board whose answers can
 * be voted on and accepted. The switch lives on the term so existing posts keep
 * working when a board is switched over.
 *
 * @param WP_Term $term Term.
 * @return string
 */
function aurora_board_type( $term ) {
	return 'qa' === get_term_meta( $term->term_id, '_board_type', true ) ? 'qa' : 'discussion';
}

function aurora_board_can_post( $term ) {
	if ( ! $term || ! is_user_logged_in() ) {
		return false;
	}

	if ( 'vip' !== aurora_board_access( $term ) ) {
		return true;
	}

	return function_exists( 'aurora_level' ) ? (int) aurora_level() > 0 : current_user_can( 'edit_posts' );
}

/**
 * Pin / feature flags.
 *
 * @param int    $post_id Post.
 * @param string $flag    'pinned' or 'featured'.
 * @return bool
 */
function aurora_board_flag( $post_id, $flag ) {
	return (bool) get_post_meta( (int) $post_id, '_aurora_' . $flag, true );
}

/**
 * Set a flag. Editors only.
 *
 * @param int    $post_id Post.
 * @param string $flag    'pinned' or 'featured'.
 * @param bool   $on      Value.
 * @return bool
 */
function aurora_board_set_flag( $post_id, $flag, $on ) {
	if ( ! in_array( $flag, array( 'pinned', 'featured' ), true ) ) {
		return false;
	}

	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return false;
	}

	if ( $on ) {
		update_post_meta( (int) $post_id, '_aurora_' . $flag, 1 );
	} else {
		delete_post_meta( (int) $post_id, '_aurora_' . $flag );
	}

	do_action( 'aurora_board_flag_changed', (int) $post_id, $flag, (bool) $on );

	return true;
}

/**
 * Meta conditions shared by every board query.
 *
 * Reply records (`_aurora_reply_of`) are not threads: they are comment copies
 * that live in the posts table for other modules. Without this filter a reply
 * would show up in the board timeline as a topic of its own.
 *
 * @param bool $exclude_pinned Skip pinned threads (the list already renders them).
 * @return array
 */
function aurora_board_meta_conditions( $exclude_pinned = false ) {
	$meta = array(
		'relation' => 'AND',
		array(
			'key'     => '_aurora_reply_of',
			'compare' => 'NOT EXISTS',
		),
	);

	if ( $exclude_pinned ) {
		$meta[] = array(
			'key'     => '_aurora_pinned',
			'compare' => 'NOT EXISTS',
		);
	}

	return $meta;
}

/**
 * Threads of one board.
 *
 * Pinned threads are excluded: they are rendered separately above the list, so
 * a page of 10 threads never contains the same thread twice.
 *
 * @param WP_Term $term  Board.
 * @param int     $paged Page.
 * @param int     $per   Per page.
 * @return WP_Query
 */
function aurora_board_query( $term, $paged = 1, $per = 12 ) {
	return new WP_Query(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => $per,
			'paged'          => max( 1, (int) $paged ),
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => AURORA_BOARD_TAX,
					'field'    => 'term_id',
					'terms'    => (int) $term->term_id,
				),
			),
			'meta_query'     => aurora_board_meta_conditions( true ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			// 同一秒批量建/导入的帖子 date 相等，单按 date 排序会让 OFFSET 分页
			// 出现重复行；用 ID 兜底成确定顺序。
			'orderby'        => array( 'date' => 'DESC', 'ID' => 'DESC' ),
			'ignore_sticky_posts' => true,
			// This *is* the board listing: the front-end rule that keeps board
			// threads out of article lists must not empty it.
			'aurora_allow_boards' => true,
		)
	);
}

/**
 * Pinned threads of a board, newest first, capped.
 *
 * @param WP_Term $term  Board.
 * @param int     $limit Max.
 * @return WP_Post[]
 */
function aurora_board_pinned( $term, $limit = 3 ) {
	return get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'numberposts'    => (int) $limit,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => AURORA_BOARD_TAX,
					'field'    => 'term_id',
					'terms'    => (int) $term->term_id,
				),
			),
			// 置顶用 EXISTS 条件而不是 meta_key：同时带 meta_key 会再开一个
			// INNER JOIN，和 reply_of 的 NOT EXISTS 条件叠在一起容易出重复行。
			'meta_query'     => array_merge(
				aurora_board_meta_conditions( false ),
				array(
					array(
						'key'     => '_aurora_pinned',
						'compare' => 'EXISTS',
					),
				)
			), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'ignore_sticky_posts' => true,
			'aurora_allow_boards' => true,
		)
	);
}

/**
 * Latest thread of a board, for the index cards.
 *
 * @param WP_Term $term Board.
 * @return WP_Post|null
 */
function aurora_board_latest( $term ) {
	$rows = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'numberposts'    => 1,
			'meta_query'     => aurora_board_meta_conditions( false ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => AURORA_BOARD_TAX,
					'field'    => 'term_id',
					'terms'    => (int) $term->term_id,
				),
			),
			'aurora_allow_boards' => true,
		)
	);

	return $rows ? $rows[0] : null;
}

/**
 * Board page URL.
 *
 * @param WP_Term $term Board.
 * @return string
 */
function aurora_board_url( $term ) {
	return (string) get_term_link( $term );
}

/**
 * Index page URL (the template page, if one exists).
 *
 * @return string
 */
function aurora_boards_page_url() {
	$page = get_page_by_path( 'boards' );

	return $page ? get_permalink( $page ) : home_url( '/boards' );
}

/**
 * Term fields: add.
 */
function aurora_board_fields_add() {
	wp_nonce_field( 'aurora_board_meta', 'aurora_board_nonce' );
	?>
	<div class="form-field">
		<label for="board-icon"><?php esc_html_e( '图标', 'aurora' ); ?></label>
		<select name="board_icon" id="board-icon">
			<?php foreach ( aurora_board_icons() as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<div class="form-field">
		<label for="board-color"><?php esc_html_e( '主题色', 'aurora' ); ?></label>
		<input type="color" name="board_color" id="board-color" value="#0071e3">
	</div>
	<div class="form-field">
		<label for="board-access"><?php esc_html_e( '发帖权限', 'aurora' ); ?></label>
		<select name="board_access" id="board-access">
			<option value="all"><?php esc_html_e( '登录用户', 'aurora' ); ?></option>
			<option value="vip"><?php esc_html_e( '仅会员', 'aurora' ); ?></option>
		</select>
	</div>
	<div class="form-field">
		<label for="board-type"><?php esc_html_e( '板块类型', 'aurora' ); ?></label>
		<select name="board_type" id="board-type">
			<option value="discussion"><?php esc_html_e( '讨论', 'aurora' ); ?></option>
			<option value="qa"><?php esc_html_e( '问答（回答可投票、可采纳）', 'aurora' ); ?></option>
		</select>
		<p><?php esc_html_e( '问答板块里评论按「已采纳 → 票数」排序。', 'aurora' ); ?></p>
	</div>
	<div class="form-field">
		<label for="board-order"><?php esc_html_e( '排序', 'aurora' ); ?></label>
		<input type="number" name="board_order" id="board-order" value="10" step="1">
		<p><?php esc_html_e( '数字小的排在前面。', 'aurora' ); ?></p>
	</div>
	<?php
}
add_action( 'aurora_board_add_form_fields', 'aurora_board_fields_add' );

/**
 * Term fields: edit.
 *
 * @param WP_Term $term Term.
 */
function aurora_board_fields_edit( $term ) {
	$icon   = aurora_board_icon( $term );
	$color  = aurora_board_color( $term );
	$access = aurora_board_access( $term );
	$type   = aurora_board_type( $term );
	$order  = (int) get_term_meta( $term->term_id, '_board_order', true );
	wp_nonce_field( 'aurora_board_meta', 'aurora_board_nonce' );
	?>
	<tr class="form-field">
		<th scope="row"><label for="board-icon"><?php esc_html_e( '图标', 'aurora' ); ?></label></th>
		<td>
			<select name="board_icon" id="board-icon">
				<?php foreach ( aurora_board_icons() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $icon, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
	<tr class="form-field">
		<th scope="row"><label for="board-color"><?php esc_html_e( '主题色', 'aurora' ); ?></label></th>
		<td><input type="color" name="board_color" id="board-color" value="<?php echo esc_attr( $color ); ?>"></td>
	</tr>
	<tr class="form-field">
		<th scope="row"><label for="board-access"><?php esc_html_e( '发帖权限', 'aurora' ); ?></label></th>
		<td>
			<select name="board_access" id="board-access">
				<option value="all" <?php selected( $access, 'all' ); ?>><?php esc_html_e( '登录用户', 'aurora' ); ?></option>
				<option value="vip" <?php selected( $access, 'vip' ); ?>><?php esc_html_e( '仅会员', 'aurora' ); ?></option>
			</select>
		</td>
	</tr>
	<tr class="form-field">
		<th scope="row"><label for="board-type"><?php esc_html_e( '板块类型', 'aurora' ); ?></label></th>
		<td>
			<select name="board_type" id="board-type">
				<option value="discussion" <?php selected( $type, 'discussion' ); ?>><?php esc_html_e( '讨论', 'aurora' ); ?></option>
				<option value="qa" <?php selected( $type, 'qa' ); ?>><?php esc_html_e( '问答（回答可投票、可采纳）', 'aurora' ); ?></option>
			</select>
		</td>
	</tr>
	<tr class="form-field">
		<th scope="row"><label for="board-order"><?php esc_html_e( '排序', 'aurora' ); ?></label></th>
		<td><input type="number" name="board_order" id="board-order" value="<?php echo esc_attr( (string) $order ); ?>" step="1"></td>
	</tr>
	<?php
}
add_action( 'aurora_board_edit_form_fields', 'aurora_board_fields_edit' );

/**
 * Available icons (keys the theme's icon set actually has).
 *
 * @return array<string,string>
 */
function aurora_board_icons() {
	return array(
		'message'  => __( '对话', 'aurora' ),
		'grid'     => __( '网格', 'aurora' ),
		'fire'     => __( '热门', 'aurora' ),
		'book'     => __( '阅读', 'aurora' ),
		'bolt'     => __( '闪电', 'aurora' ),
		'gift'     => __( '礼物', 'aurora' ),
		'heart'    => __( '爱心', 'aurora' ),
		'clock'    => __( '时钟', 'aurora' ),
	);
}

/**
 * Save term fields.
 *
 * @param int $term_id Term.
 */
function aurora_board_save_fields( $term_id ) {
	if ( ! isset( $_POST['aurora_board_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['aurora_board_nonce'] ) ), 'aurora_board_meta' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_categories' ) ) {
		return;
	}

	$icons  = aurora_board_icons();
	$icon   = sanitize_key( $_POST['board_icon'] ?? 'message' );
	$color  = sanitize_hex_color( wp_unslash( $_POST['board_color'] ?? '' ) );
	$access = 'vip' === ( $_POST['board_access'] ?? '' ) ? 'vip' : 'all';
	$type   = 'qa' === ( $_POST['board_type'] ?? '' ) ? 'qa' : 'discussion';
	$order  = (int) ( $_POST['board_order'] ?? 10 );

	update_term_meta( $term_id, '_board_icon', isset( $icons[ $icon ] ) ? $icon : 'message' );
	update_term_meta( $term_id, '_board_color', $color ? $color : '#0071e3' );
	update_term_meta( $term_id, '_board_access', $access );
	update_term_meta( $term_id, '_board_type', $type );
	update_term_meta( $term_id, '_board_order', $order );
}
add_action( 'created_' . AURORA_BOARD_TAX, 'aurora_board_save_fields' );
add_action( 'edited_' . AURORA_BOARD_TAX, 'aurora_board_save_fields' );

/**
 * Editor box on the post screen: pin and feature.
 */
function aurora_board_metabox() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}

	add_meta_box(
		'aurora-board-flags',
		__( '板块展示', 'aurora' ),
		'aurora_board_metabox_render',
		'post',
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes', 'aurora_board_metabox' );

/**
 * Metabox body.
 *
 * @param WP_Post $post Post.
 */
function aurora_board_metabox_render( $post ) {
	wp_nonce_field( 'aurora_board_flags', 'aurora_board_flags_nonce' );
	?>
	<p>
		<label>
			<input type="checkbox" name="aurora_pinned" value="1" <?php checked( aurora_board_flag( $post->ID, 'pinned' ) ); ?>>
			<?php esc_html_e( '置顶（板块页顶部单独一块）', 'aurora' ); ?>
		</label>
	</p>
	<p>
		<label>
			<input type="checkbox" name="aurora_featured" value="1" <?php checked( aurora_board_flag( $post->ID, 'featured' ) ); ?>>
			<?php esc_html_e( '精华', 'aurora' ); ?>
		</label>
	</p>
	<?php
}

/**
 * Save the metabox.
 *
 * @param int $post_id Post.
 */
function aurora_board_metabox_save( $post_id ) {
	if ( ! isset( $_POST['aurora_board_flags_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['aurora_board_flags_nonce'] ) ), 'aurora_board_flags' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}

	aurora_board_set_flag( $post_id, 'pinned', ! empty( $_POST['aurora_pinned'] ) );
	aurora_board_set_flag( $post_id, 'featured', ! empty( $_POST['aurora_featured'] ) );
}
add_action( 'save_post_post', 'aurora_board_metabox_save' );

/**
 * Badge markup for a thread row.
 *
 * @param int $post_id Post.
 * @return string
 */
function aurora_board_badges( $post_id ) {
	$out = '';

	if ( aurora_board_flag( $post_id, 'pinned' ) ) {
		$out .= '<span class="aurora-badge aurora-badge--pin">' . esc_html__( '置顶', 'aurora' ) . '</span>';
	}

	if ( aurora_board_flag( $post_id, 'featured' ) ) {
		$out .= '<span class="aurora-badge aurora-badge--feat">' . esc_html__( '精华', 'aurora' ) . '</span>';
	}

	return $out;
}

/**
 * One thread row, shared by the pinned block and the list.
 *
 * @param WP_Post|int $post Post.
 */
function aurora_board_row( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return;
	}

	$author = get_the_author_meta( 'display_name', (int) $post->post_author );
	$replies = (int) get_comments_number( $post );
	?>
	<article class="aurora-thread">
		<?php if ( has_post_thumbnail( $post ) ) : ?>
			<a class="aurora-thread__thumb" href="<?php echo esc_url( get_permalink( $post ) ); ?>" tabindex="-1" aria-hidden="true">
				<?php echo get_the_post_thumbnail( $post, 'thumbnail', array( 'loading' => 'lazy', 'alt' => '' ) ); ?>
			</a>
		<?php endif; ?>

		<div class="aurora-thread__body">
			<h3 class="aurora-thread__title">
				<a href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a>
				<?php echo aurora_board_badges( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup. ?>
			</h3>
			<p class="aurora-thread__meta">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: author, 2: date, 3: replies. */
						__( '%1$s · %2$s · %3$d 条回复', 'aurora' ),
						$author,
						get_the_date( 'm-d H:i', $post ),
						$replies
					)
				);
				?>
			</p>
		</div>
	</article>
	<?php
}
