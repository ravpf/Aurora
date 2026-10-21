<?php
/**
 * Redeem cards.
 *
 * A card is a one-time code worth either a membership period or a lump of
 * points. It is stored as a private post whose title *is* the code, so the
 * admin list is the inventory.
 *
 * The important property is single use. Redemption claims the card with one
 * conditional UPDATE that only matches while the row is still unused, so two
 * requests racing on the same code cannot both win — checking the status and
 * then writing it in two steps would let both through.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Storage
 * ---------------------------------------------------------------------- */

/**
 * Register the card post type.
 *
 * post_status doubles as the lock: 'publish' means unused, 'private' means
 * redeemed.
 */
function aurora_register_card_type() {
	register_post_type( 'aurora_card', array(
		'labels'          => array(
			'name'          => __( '卡密', 'aurora' ),
			'singular_name' => __( '卡密', 'aurora' ),
			'all_items'     => __( '卡密库存', 'aurora' ),
			'edit_item'     => __( '查看卡密', 'aurora' ),
		),
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => true,
		'menu_icon'       => 'dashicons-tickets-alt',
		'menu_position'   => 23,
		'capability_type' => 'post',
		'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap'    => true,
		'supports'        => array( 'title' ),
		'show_in_rest'    => false,
	) );
}
add_action( 'init', 'aurora_register_card_type' );

/**
 * What a card can carry.
 *
 * @return array
 */
function aurora_card_types() {
	return apply_filters( 'aurora_card_types', array(
		'vip'    => __( '会员时长', 'aurora' ),
		'points' => __( '积分', 'aurora' ),
	) );
}

/* -------------------------------------------------------------------------
 * Codes
 * ---------------------------------------------------------------------- */

/**
 * Normalise a code the way it is stored and compared.
 *
 * @param string $code Raw input.
 * @return string
 */
function aurora_card_normalize( $code ) {
	$code = strtoupper( (string) $code );
	$code = preg_replace( '/[^A-Z0-9]/', '', $code );

	return (string) $code;
}

/**
 * Make a code that reads well when typed by hand: groups of four.
 *
 * @param string $prefix Optional prefix.
 * @return string
 */
function aurora_card_generate_code( $prefix = '' ) {
	$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no I/O/0/1: read aloud safely
	$prefix   = aurora_card_normalize( $prefix );

	$body = '';
	for ( $i = 0; $i < 4; $i++ ) {
		$group = '';
		for ( $j = 0; $j < 4; $j++ ) {
			$group .= $alphabet[ wp_rand( 0, strlen( $alphabet ) - 1 ) ];
		}
		$body .= ( '' === $body ? '' : '-' ) . $group;
	}

	return ( $prefix ? $prefix . '-' : '' ) . $body;
}

/**
 * Find a card by code, regardless of how it was typed.
 *
 * @param string $code Raw code.
 * @return WP_Post|null
 */
function aurora_find_card( $code ) {
	$normalized = aurora_card_normalize( $code );

	if ( '' === $normalized ) {
		return null;
	}

	// Cards are stored grouped ("ABCD-EFGH-JKLM-NPQR"); strip separators the
	// same way on both sides so "abcdef ghjk lmnpqr" still matches.
	global $wpdb;
	$id = $wpdb->get_var( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_type = 'aurora_card'
		   AND REPLACE(UPPER(post_title), '-', '') = %s
		 LIMIT 1",
		$normalized
	) );

	return $id ? get_post( (int) $id ) : null;
}

/**
 * A card's payload.
 *
 * @param int $card_id Card ID.
 * @return array
 */
function aurora_get_card( $card_id ) {
	$card_id = (int) $card_id;

	return array(
		'id'       => $card_id,
		'code'     => (string) get_the_title( $card_id ),
		'type'     => (string) get_post_meta( $card_id, '_aurora_card_type', true ),
		'level'    => (int) get_post_meta( $card_id, '_aurora_card_level', true ),
		'days'     => (int) get_post_meta( $card_id, '_aurora_card_days', true ),
		'points'   => (int) get_post_meta( $card_id, '_aurora_card_points', true ),
		'batch'    => (string) get_post_meta( $card_id, '_aurora_card_batch', true ),
		'expire'   => (string) get_post_meta( $card_id, '_aurora_card_expire', true ),
		'used_by'  => (int) get_post_meta( $card_id, '_aurora_card_used_by', true ),
		'used_at'  => (string) get_post_meta( $card_id, '_aurora_card_used_at', true ),
		'used'     => 'publish' !== get_post_status( $card_id ),
	);
}

/**
 * Describe what a card is worth, for lists and messages.
 *
 * @param array $card Card array.
 * @return string
 */
function aurora_card_label( $card ) {
	if ( 'vip' === $card['type'] ) {
		$level = function_exists( 'aurora_get_vip_level' ) ? aurora_get_vip_level( $card['level'] ) : null;
		$name  = $level ? $level['name'] : sprintf( __( 'Lv%d', 'aurora' ), $card['level'] );

		return sprintf( __( '%1$s · %2$d 天', 'aurora' ), $name, $card['days'] );
	}

	return sprintf( __( '%d 积分', 'aurora' ), $card['points'] );
}

/* -------------------------------------------------------------------------
 * Redemption
 * ---------------------------------------------------------------------- */

/**
 * Redeem a code for a user.
 *
 * @param string $code    Raw code.
 * @param int    $user_id User ID.
 * @return array|WP_Error {card, label} on success.
 */
function aurora_redeem_card( $code, $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return new WP_Error( 'aurora_card_no_user', __( '请先登录后再兑换。', 'aurora' ) );
	}

	$card_post = aurora_find_card( $code );

	if ( ! $card_post ) {
		return new WP_Error( 'aurora_card_missing', __( '卡密不存在，请检查是否输错。', 'aurora' ) );
	}

	$card = aurora_get_card( $card_post->ID );

	if ( $card['used'] ) {
		return new WP_Error( 'aurora_card_used', __( '该卡密已被使用。', 'aurora' ) );
	}

	if ( $card['expire'] && strtotime( $card['expire'] . ' 23:59:59' ) < current_time( 'timestamp' ) ) {
		return new WP_Error( 'aurora_card_expired', __( '该卡密已过期。', 'aurora' ) );
	}

	if ( ! isset( aurora_card_types()[ $card['type'] ] ) ) {
		return new WP_Error( 'aurora_card_bad', __( '卡密类型无效，请联系管理员。', 'aurora' ) );
	}

	// Claim it. One conditional UPDATE: the row only changes while it is still
	// unused, so concurrent redemptions cannot both succeed.
	global $wpdb;
	$claimed = $wpdb->update(
		$wpdb->posts,
		array( 'post_status' => 'private' ),
		array( 'ID' => $card_post->ID, 'post_status' => 'publish' ),
		array( '%s' ),
		array( '%d', '%s' )
	);

	if ( 1 !== (int) $claimed ) {
		return new WP_Error( 'aurora_card_used', __( '该卡密已被使用。', 'aurora' ) );
	}

	clean_post_cache( $card_post->ID );

	// From here on the card is ours. Record who took it before granting, so an
	// interrupted grant still leaves a used card rather than a silent freebie.
	update_post_meta( $card_post->ID, '_aurora_card_used_by', $user_id );
	update_post_meta( $card_post->ID, '_aurora_card_used_at', current_time( 'mysql' ) );

	$label = aurora_card_label( $card );

	if ( 'vip' === $card['type'] && function_exists( 'aurora_grant_vip' ) ) {
		$result = aurora_grant_vip( $user_id, max( 1, $card['level'] ), max( 1, $card['days'] ), 'card' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	} elseif ( 'points' === $card['type'] ) {
		$result = aurora_points_add(
			$user_id,
			max( 1, $card['points'] ),
			'redeem',
			array( 'ref' => $card_post->ID, 'note' => sprintf( __( '卡密兑换：%s', 'aurora' ), $card['code'] ) )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	do_action( 'aurora_card_redeemed', $card_post->ID, $user_id, $card );

	return array( 'card' => $card, 'label' => $label );
}

/* -------------------------------------------------------------------------
 * Brute-force guard
 * ---------------------------------------------------------------------- */

/**
 * Allow at most N failed attempts per user per hour.
 *
 * Codes are 16 characters from a 32-symbol alphabet, so guessing is hopeless,
 * but an unthrottled endpoint is still free CPU for anyone who wants it.
 *
 * @param int $user_id User ID.
 * @return bool True when the user may try again.
 */
function aurora_card_attempts_left( $user_id ) {
	$key   = 'aurora_card_tries_' . (int) $user_id;
	$tries = (int) get_transient( $key );
	$limit = (int) get_theme_mod( 'aurora_card_try_limit', 10 );

	return $tries < $limit;
}

/**
 * Count a failed attempt.
 *
 * @param int $user_id User ID.
 */
function aurora_card_note_failure( $user_id ) {
	$key   = 'aurora_card_tries_' . (int) $user_id;
	$tries = (int) get_transient( $key );

	set_transient( $key, $tries + 1, HOUR_IN_SECONDS );
}

/**
 * Clear the counter after a success.
 *
 * @param int $user_id User ID.
 */
function aurora_card_clear_failures( $user_id ) {
	delete_transient( 'aurora_card_tries_' . (int) $user_id );
}

/* -------------------------------------------------------------------------
 * AJAX
 * ---------------------------------------------------------------------- */

/**
 * Redeem from the front end.
 */
function aurora_ajax_redeem() {
	aurora_check_nonce();

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( '请先登录。', 'aurora' ) ), 200 );
	}

	$user_id = get_current_user_id();

	if ( ! aurora_card_attempts_left( $user_id ) ) {
		wp_send_json_error(
			array( 'message' => __( '尝试次数过多，请一小时后再试。', 'aurora' ) ),
			429
		);
	}

	$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
	$out  = aurora_redeem_card( $code, $user_id );

	if ( is_wp_error( $out ) ) {
		// Only a genuine mismatch counts against the throttle.
		if ( in_array( $out->get_error_code(), array( 'aurora_card_missing', 'aurora_card_used' ), true ) ) {
			aurora_card_note_failure( $user_id );
		}

		wp_send_json_error( array( 'message' => $out->get_error_message() ), 200 );
	}

	aurora_card_clear_failures( $user_id );

	wp_send_json_success( array(
		'message' => sprintf( __( '兑换成功：%s', 'aurora' ), $out['label'] ),
		'label'   => $out['label'],
		'points'  => aurora_points_balance( $user_id ),
	) );
}
add_action( 'wp_ajax_aurora_redeem', 'aurora_ajax_redeem' );

/* -------------------------------------------------------------------------
 * Admin: generate a batch
 * ---------------------------------------------------------------------- */

/**
 * Add the generator screen under the card menu.
 */
function aurora_card_admin_menu() {
	add_submenu_page(
		'edit.php?post_type=aurora_card',
		__( '批量生成卡密', 'aurora' ),
		__( '批量生成', 'aurora' ),
		'manage_options',
		'aurora-cards-generate',
		'aurora_card_generator_page'
	);
}
add_action( 'admin_menu', 'aurora_card_admin_menu' );

/**
 * The generator form and its result.
 */
function aurora_card_generator_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '权限不足。', 'aurora' ), '', array( 'response' => 403 ) );
	}

	$batch = isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : '';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( '批量生成卡密', 'aurora' ); ?></h1>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aurora_generate_cards">
			<?php wp_nonce_field( 'aurora_generate_cards' ); ?>

			<table class="form-table">
				<tr>
					<th><label for="aurora_card_kind"><?php esc_html_e( '卡密类型', 'aurora' ); ?></label></th>
					<td>
						<select name="kind" id="aurora_card_kind">
							<?php foreach ( aurora_card_types() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="aurora_card_level"><?php esc_html_e( '会员等级', 'aurora' ); ?></label></th>
					<td>
						<select name="level" id="aurora_card_level">
							<?php foreach ( aurora_vip_levels() as $index => $info ) : ?>
								<option value="<?php echo esc_attr( $index ); ?>"><?php echo esc_html( $info['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( '仅「会员时长」类型使用。', 'aurora' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="aurora_card_days"><?php esc_html_e( '时长（天）', 'aurora' ); ?></label></th>
					<td><input type="number" min="1" step="1" name="days" id="aurora_card_days" value="30" class="small-text"></td>
				</tr>
				<tr>
					<th><label for="aurora_card_points"><?php esc_html_e( '积分数额', 'aurora' ); ?></label></th>
					<td>
						<input type="number" min="1" step="1" name="points" id="aurora_card_points" value="500" class="small-text">
						<p class="description"><?php esc_html_e( '仅「积分」类型使用。', 'aurora' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="aurora_card_count"><?php esc_html_e( '生成数量', 'aurora' ); ?></label></th>
					<td>
						<input type="number" min="1" max="500" step="1" name="count" id="aurora_card_count" value="10" class="small-text">
						<p class="description"><?php esc_html_e( '一次最多 500 张。', 'aurora' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="aurora_card_prefix"><?php esc_html_e( '前缀', 'aurora' ); ?></label></th>
					<td><input type="text" name="prefix" id="aurora_card_prefix" value="" class="regular-text"
						placeholder="<?php esc_attr_e( '例如 VIP（可留空）', 'aurora' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="aurora_card_expire"><?php esc_html_e( '有效天数', 'aurora' ); ?></label></th>
					<td>
						<input type="number" min="0" step="1" name="expire_days" id="aurora_card_expire" value="0" class="small-text">
						<p class="description"><?php esc_html_e( '从今天起多少天内可兑换，0 表示永不过期。', 'aurora' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="aurora_card_note"><?php esc_html_e( '批次备注', 'aurora' ); ?></label></th>
					<td><input type="text" name="note" id="aurora_card_note" value="" class="regular-text"
						placeholder="<?php esc_attr_e( '例如：双十一活动', 'aurora' ); ?>"></td>
				</tr>
			</table>

			<?php submit_button( __( '生成', 'aurora' ) ); ?>
		</form>

		<?php if ( $batch ) : ?>
			<?php aurora_card_render_batch( $batch ); ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Show a freshly generated batch with copy/export affordances.
 *
 * @param string $batch Batch tag.
 */
function aurora_card_render_batch( $batch ) {
	$cards = get_posts( array(
		'post_type'      => 'aurora_card',
		'post_status'    => 'any',
		'posts_per_page' => 500,
		'meta_key'       => '_aurora_card_batch',
		'meta_value'     => $batch,
	) );

	if ( ! $cards ) {
		return;
	}

	$export = wp_nonce_url(
		admin_url( 'admin-post.php?action=aurora_export_cards&batch=' . rawurlencode( $batch ) ),
		'aurora_export_cards'
	);
	?>
	<hr>
	<h2>
		<?php
		printf(
			/* translators: %d: number of cards */
			esc_html__( '本批生成 %d 张', 'aurora' ),
			count( $cards )
		);
		?>
	</h2>
	<p>
		<a class="button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( '导出 CSV', 'aurora' ); ?></a>
		<span class="description">
			<?php esc_html_e( '卡密等同于现金：导出后请通过安全渠道发放，不要公开到页面上。', 'aurora' ); ?>
		</span>
	</p>

	<textarea readonly rows="14" style="width:100%;max-width:760px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace"><?php
		foreach ( $cards as $card ) {
			echo esc_textarea( get_the_title( $card ) . "\t" . aurora_card_label( aurora_get_card( $card->ID ) ) . "\n" );
		}
	?></textarea>
	<?php
}

/**
 * Create a batch.
 */
function aurora_handle_generate_cards() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '权限不足。', 'aurora' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'aurora_generate_cards' );

	$kind = isset( $_POST['kind'] ) ? sanitize_key( $_POST['kind'] ) : 'vip';
	if ( ! isset( aurora_card_types()[ $kind ] ) ) {
		$kind = 'vip';
	}

	$count = isset( $_POST['count'] ) ? (int) $_POST['count'] : 1;
	$count = max( 1, min( 500, $count ) );

	$level     = isset( $_POST['level'] ) ? max( 1, (int) $_POST['level'] ) : 1;
	$days      = isset( $_POST['days'] ) ? max( 1, (int) $_POST['days'] ) : 30;
	$points    = isset( $_POST['points'] ) ? max( 1, (int) $_POST['points'] ) : 100;
	$prefix    = isset( $_POST['prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['prefix'] ) ) : '';
	$expire_in = isset( $_POST['expire_days'] ) ? max( 0, (int) $_POST['expire_days'] ) : 0;
	$note      = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

	$batch  = 'B' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 4, false, false );
	$expire = $expire_in > 0
		? gmdate( 'Y-m-d', current_time( 'timestamp' ) + $expire_in * DAY_IN_SECONDS )
		: '';

	$made = 0;
	for ( $i = 0; $i < $count; $i++ ) {
		// Retry on the vanishingly unlikely collision rather than trusting luck.
		$code = '';
		for ( $try = 0; $try < 5; $try++ ) {
			$candidate = aurora_card_generate_code( $prefix );
			if ( ! aurora_find_card( $candidate ) ) {
				$code = $candidate;
				break;
			}
		}
		if ( '' === $code ) {
			continue;
		}

		$id = wp_insert_post( array(
			'post_type'   => 'aurora_card',
			'post_status' => 'publish',   // publish = unused
			'post_title'  => $code,
			'meta_input'  => array(
				'_aurora_card_type'   => $kind,
				'_aurora_card_level'  => 'vip' === $kind ? $level : 0,
				'_aurora_card_days'   => 'vip' === $kind ? $days : 0,
				'_aurora_card_points' => 'points' === $kind ? $points : 0,
				'_aurora_card_batch'  => $batch,
				'_aurora_card_expire' => $expire,
				'_aurora_card_note'   => $note,
			),
		) );

		if ( $id && ! is_wp_error( $id ) ) {
			$made++;
		}
	}

	wp_safe_redirect( admin_url(
		'edit.php?post_type=aurora_card&page=aurora-cards-generate&batch=' . rawurlencode( $batch ) . '&made=' . $made
	) );
	exit;
}
add_action( 'admin_post_aurora_generate_cards', 'aurora_handle_generate_cards' );

/**
 * Stream a batch as CSV.
 */
function aurora_handle_export_cards() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '权限不足。' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'aurora_export_cards' );

	$batch = isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : '';
	$meta  = $batch
		? array( array( 'key' => '_aurora_card_batch', 'value' => $batch ) )
		: array();

	$cards = get_posts( array(
		'post_type'      => 'aurora_card',
		'post_status'    => 'any',
		'posts_per_page' => 1000,
		'meta_query'     => $meta,
	) );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="aurora-cards-' . ( $batch ? $batch : 'all' ) . '.csv"' );

	$out = fopen( 'php://output', 'w' );

	// BOM so Excel opens the Chinese columns correctly.
	fwrite( $out, "\xEF\xBB\xBF" );
	fputcsv( $out, array( '卡密', '类型', '面值', '批次', '状态', '使用者', '使用时间', '过期日' ) );

	foreach ( $cards as $card ) {
		$c     = aurora_get_card( $card->ID );
		$user  = $c['used_by'] ? get_userdata( $c['used_by'] ) : null;

		fputcsv( $out, array(
			$c['code'],
			isset( aurora_card_types()[ $c['type'] ] ) ? aurora_card_types()[ $c['type'] ] : $c['type'],
			aurora_card_label( $c ),
			$c['batch'],
			$c['used'] ? '已使用' : '未使用',
			$user ? $user->user_login : '',
			$c['used_at'],
			$c['expire'],
		) );
	}

	fclose( $out );
	exit;
}
add_action( 'admin_post_aurora_export_cards', 'aurora_handle_export_cards' );

/* -------------------------------------------------------------------------
 * Admin list
 * ---------------------------------------------------------------------- */

/**
 * Card list columns.
 *
 * @param array $columns Columns.
 * @return array
 */
function aurora_card_columns( $columns ) {
	return array(
		'cb'          => $columns['cb'],
		'title'       => __( '卡密', 'aurora' ),
		'aurora_worth' => __( '面值', 'aurora' ),
		'aurora_state' => __( '状态', 'aurora' ),
		'aurora_who'   => __( '使用者', 'aurora' ),
		'aurora_batch' => __( '批次', 'aurora' ),
		'aurora_exp'   => __( '过期日', 'aurora' ),
		'date'        => __( '生成时间', 'aurora' ),
	);
}
add_filter( 'manage_aurora_card_posts_columns', 'aurora_card_columns' );

/**
 * Card column values.
 *
 * @param string $column  Column key.
 * @param int    $post_id Card.
 */
function aurora_card_column( $column, $post_id ) {
	$card = aurora_get_card( $post_id );

	switch ( $column ) {
		case 'aurora_worth':
			echo esc_html( aurora_card_label( $card ) );
			break;

		case 'aurora_state':
			echo $card['used']
				? '<span style="color:#b32d2e">' . esc_html__( '已使用', 'aurora' ) . '</span>'
				: '<span style="color:#1a7f37">' . esc_html__( '未使用', 'aurora' ) . '</span>';
			break;

		case 'aurora_who':
			$user = $card['used_by'] ? get_userdata( $card['used_by'] ) : null;
			echo $user ? esc_html( $user->user_login ) : '—';
			break;

		case 'aurora_batch':
			$url = admin_url( 'edit.php?post_type=aurora_card&page=aurora-cards-generate&batch=' . rawurlencode( $card['batch'] ) );
			printf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $card['batch'] ) );
			break;

		case 'aurora_exp':
			echo $card['expire'] ? esc_html( $card['expire'] ) : '—';
			break;
	}
}
add_action( 'manage_aurora_card_posts_custom_column', 'aurora_card_column', 10, 2 );

/**
 * Filter the inventory by state, which is otherwise invisible in the list.
 */
function aurora_card_views( $views ) {
	$current = isset( $_GET['aurora_state'] ) ? sanitize_key( $_GET['aurora_state'] ) : '';

	foreach ( array( 'unused' => __( '未使用', 'aurora' ), 'used' => __( '已使用', 'aurora' ) ) as $key => $label ) {
		$q = new WP_Query( array(
			'post_type'      => 'aurora_card',
			'post_status'    => 'unused' === $key ? 'publish' : 'private',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) );

		$url = add_query_arg(
			array( 'post_type' => 'aurora_card', 'aurora_state' => $key ),
			admin_url( 'edit.php' )
		);

		$views[ 'aurora_' . $key ] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $url ),
			$current === $key ? ' class="current"' : '',
			esc_html( $label ),
			(int) $q->found_posts
		);
	}

	return $views;
}
add_filter( 'views_edit-aurora_card', 'aurora_card_views' );

/**
 * Honour the state filter on the list query.
 *
 * @param WP_Query $query Query.
 */
function aurora_card_filter_query( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( 'aurora_card' !== $query->get( 'post_type' ) ) {
		return;
	}
	if ( ! isset( $_GET['aurora_state'] ) ) {
		return;
	}

	$state = sanitize_key( $_GET['aurora_state'] );

	if ( 'unused' === $state ) {
		$query->set( 'post_status', 'publish' );
	} elseif ( 'used' === $state ) {
		$query->set( 'post_status', 'private' );
	}
}
add_action( 'pre_get_posts', 'aurora_card_filter_query' );

/**
 * Cards are an inventory, not content: no Add New.
 */
function aurora_card_admin_css() {
	$screen = get_current_screen();
	if ( ! $screen || 'aurora_card' !== $screen->post_type ) {
		return;
	}
	echo '<style>.page-title-action{display:none}</style>';
}
add_action( 'admin_head', 'aurora_card_admin_css' );
