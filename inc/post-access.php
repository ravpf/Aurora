<?php
/**
 * Single-article purchases.
 *
 * A post can carry a price. When it does, the paywall stops asking "what level
 * are you" and starts asking "did you buy this one". Access is recorded against
 * the user, keyed by post ID, so it survives refunds and re-purchases cleanly.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Price of a single article. 0 means "not sold individually".
 *
 * @param int $post_id Post ID.
 * @return float
 */
function aurora_post_price( $post_id ) {
	$price = (float) get_post_meta( $post_id, '_aurora_post_price', true );

	return $price > 0 ? round( $price, 2 ) : 0.0;
}

/**
 * Is this article sold individually?
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function aurora_post_is_paid( $post_id ) {
	return aurora_post_price( $post_id ) > 0;
}

/**
 * Everything a user has bought, as post_id => record.
 *
 * @param int $user_id User ID.
 * @return array
 */
function aurora_user_post_access_list( $user_id ) {
	$list = get_user_meta( (int) $user_id, 'aurora_post_access', true );

	return is_array( $list ) ? $list : array();
}

/**
 * Does the user own this article right now?
 *
 * @param int $user_id User ID. 0 = current user.
 * @param int $post_id Post ID.
 * @return bool
 */
function aurora_user_owns_post( $user_id, $post_id ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	$post_id = (int) $post_id;

	if ( ! $user_id || ! $post_id ) {
		return false;
	}

	$list = aurora_user_post_access_list( $user_id );
	if ( empty( $list[ $post_id ] ) ) {
		return false;
	}

	$record  = $list[ $post_id ];
	$expires = (int) ( $record['e'] ?? 0 );

	// 0 means a permanent purchase.
	return 0 === $expires || $expires > time();
}

/**
 * Grant access to an article.
 *
 * @param int    $user_id  User ID.
 * @param int    $post_id  Post ID.
 * @param string $order_no Order number, for the audit trail.
 * @param int    $days     Days of access. 0 = permanent.
 * @return bool
 */
function aurora_grant_post_access( $user_id, $post_id, $order_no = '', $days = 0 ) {
	$user_id = (int) $user_id;
	$post_id = (int) $post_id;

	if ( ! $user_id || ! $post_id ) {
		return false;
	}

	$list = aurora_user_post_access_list( $user_id );

	// Re-buying extends a timed purchase rather than resetting it.
	$existing = (int) ( $list[ $post_id ]['e'] ?? 0 );
	if ( $days > 0 && $existing > time() ) {
		$expires = max( $existing, time() ) + ( $days * DAY_IN_SECONDS );
	} elseif ( $days > 0 ) {
		$expires = time() + ( $days * DAY_IN_SECONDS );
	} else {
		$expires = 0;
	}

	$list[ $post_id ] = array(
		't' => isset( $list[ $post_id ]['t'] ) ? $list[ $post_id ]['t'] : time(),
		'e' => $expires,
		'o' => $order_no,
	);

	update_user_meta( $user_id, 'aurora_post_access', $list );

	do_action( 'aurora_post_access_granted', $user_id, $post_id, $order_no, $expires );

	return true;
}

/**
 * Take access away (refund).
 *
 * @param int $user_id User ID.
 * @param int $post_id Post ID.
 */
function aurora_revoke_post_access( $user_id, $post_id ) {
	$user_id = (int) $user_id;
	$post_id = (int) $post_id;
	$list    = aurora_user_post_access_list( $user_id );

	if ( ! isset( $list[ $post_id ] ) ) {
		return;
	}

	unset( $list[ $post_id ] );
	update_user_meta( $user_id, 'aurora_post_access', $list );

	do_action( 'aurora_post_access_revoked', $user_id, $post_id );
}

/**
 * Does the current viewer get priced articles for free because they are a member?
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function aurora_post_vip_covers( $post_id ) {
	if ( ! get_theme_mod( 'aurora_post_vip_free', false ) ) {
		return false;
	}

	$required = max( 1, (int) get_theme_mod( 'aurora_post_free_level', 1 ) );

	return aurora_get_user_level() >= $required;
}

/**
 * Is this article locked behind its own price tag?
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function aurora_post_locked( $post_id ) {
	if ( ! get_theme_mod( 'aurora_enable_paywall', true ) ) {
		return false;
	}
	if ( ! aurora_post_is_paid( $post_id ) ) {
		return false;
	}

	if ( aurora_user_owns_post( null, $post_id ) ) {
		return false;
	}
	if ( aurora_post_vip_covers( $post_id ) ) {
		return false;
	}

	// Anyone who may edit the post can read it, otherwise the owner could not
	// proofread a paid article on their own site. This mirrors the way
	// aurora_get_user_level() already hands administrators full access.
	if ( is_user_logged_in() && current_user_can( 'edit_post', $post_id ) ) {
		return false;
	}

	return true;
}

/* -------------------------------------------------------------------------
 * Purchase flow
 * ---------------------------------------------------------------------- */

/**
 * Handle a "buy this article" submission.
 */
function aurora_handle_post_purchase() {
	if ( ! isset( $_POST['aurora_post_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_key( $_POST['aurora_post_nonce'] ), 'aurora_buy_post' ) ) {
		return;
	}

	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
	$gw      = isset( $_POST['gateway'] ) ? sanitize_key( $_POST['gateway'] ) : 'manual';
	$coupon  = isset( $_POST['coupon'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon'] ) ) : '';
	$back    = $post_id ? get_permalink( $post_id ) : home_url( '/' );

	if ( ! $post_id || ! aurora_post_is_paid( $post_id ) ) {
		wp_safe_redirect( add_query_arg( 'aurora_msg', 'invalid', $back ) );
		exit;
	}
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( $back ) );
		exit;
	}

	$ready = aurora_available_gateways();
	if ( ! isset( $ready[ $gw ] ) ) {
		wp_safe_redirect( add_query_arg( 'aurora_msg', 'invalid', $back ) );
		exit;
	}

	if ( aurora_user_owns_post( null, $post_id ) ) {
		wp_safe_redirect( add_query_arg( 'aurora_msg', 'already', $back ) );
		exit;
	}

	$price    = aurora_post_price( $post_id );
	$access_days = (int) get_theme_mod( 'aurora_post_access_days', 0 );
	$order_id = aurora_create_order( array(
		'product'   => sprintf(
			/* translators: %s: post title */
			__( '单篇购买：%s', 'aurora' ),
			get_the_title( $post_id )
		),
		'level'     => 0,
		'days'      => $access_days,
		'amount'    => $price,
		'gateway'   => $gw,
		'object_id' => $post_id,
		'coupon'    => $coupon,
	) );

	if ( is_wp_error( $order_id ) ) {
		wp_safe_redirect( add_query_arg(
			'aurora_msg',
			rawurlencode( $order_id->get_error_message() ),
			$back
		) );
		exit;
	}

	do_action( 'aurora_checkout_created', $order_id, $gw );

	wp_safe_redirect( add_query_arg( array( 'order' => $order_id ), aurora_user_page_url() ) );
	exit;
}
add_action( 'template_redirect', 'aurora_handle_post_purchase' );

/**
 * Grant access when an article order is paid.
 *
 * @param int   $order_id Order post ID.
 * @param array $order    Order data snapshot.
 */
function aurora_post_access_on_paid( $order_id, $order ) {
	$post_id = (int) get_post_meta( $order_id, '_aurora_object', true );

	// A membership order has no object; only article orders do.
	if ( ! $post_id || $order['level'] > 0 ) {
		return;
	}
	if ( ! aurora_post_is_paid( $post_id ) ) {
		return;
	}

	aurora_grant_post_access(
		$order['user_id'],
		$post_id,
		$order['no'],
		(int) get_theme_mod( 'aurora_post_access_days', 0 )
	);
}
add_action( 'aurora_order_paid', 'aurora_post_access_on_paid', 10, 2 );

/* -------------------------------------------------------------------------
 * Admin
 * ---------------------------------------------------------------------- */

/**
 * Price box on the post editor.
 */
function aurora_add_price_metabox() {
	add_meta_box(
		'aurora-post-price',
		__( 'Aurora · 单篇付费', 'aurora' ),
		'aurora_price_metabox_html',
		array( 'post' ),
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes', 'aurora_add_price_metabox' );

/**
 * Price box markup.
 *
 * @param WP_Post $post Post being edited.
 */
function aurora_price_metabox_html( $post ) {
	wp_nonce_field( 'aurora_save_price', 'aurora_price_nonce' );
	$price = aurora_post_price( $post->ID );
	$days  = (int) get_theme_mod( 'aurora_post_access_days', 0 );
	?>
	<p>
		<label for="aurora_post_price"><strong><?php esc_html_e( '单篇售价（元）', 'aurora' ); ?></strong></label>
	</p>
	<p>
		<input type="number" step="0.01" min="0" name="aurora_post_price" id="aurora_post_price"
			value="<?php echo esc_attr( $price ? $price : '' ); ?>" style="width:100%" placeholder="0">
	</p>
	<p class="description">
		<?php esc_html_e( '填 0 或不填 = 不单独售卖，按上面的会员等级控制。', 'aurora' ); ?>
	</p>
	<?php if ( $price > 0 ) : ?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: access duration description */
				esc_html__( '购买后可阅读：%s', 'aurora' ),
				esc_html( $days > 0 ? sprintf( __( '%d 天', 'aurora' ), $days ) : __( '永久', 'aurora' ) )
			);
			?>
			<br><?php esc_html_e( '（在「主题设置 → 会员」里统一调整时长）', 'aurora' ); ?>
		</p>
	<?php endif; ?>
	<?php
}

/**
 * Save the price.
 *
 * @param int $post_id Post ID.
 */
function aurora_save_price_metabox( $post_id ) {
	if ( ! isset( $_POST['aurora_price_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['aurora_price_nonce'] ), 'aurora_save_price' ) ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$price = isset( $_POST['aurora_post_price'] ) ? (float) $_POST['aurora_post_price'] : 0;
	$price = max( 0, round( $price, 2 ) );

	if ( $price > 0 ) {
		update_post_meta( $post_id, '_aurora_post_price', $price );
	} else {
		delete_post_meta( $post_id, '_aurora_post_price' );
	}
}
add_action( 'save_post_post', 'aurora_save_price_metabox' );

/**
 * Show in the post list whether an article is for sale, and how many bought it.
 *
 * @param array $columns Columns.
 * @return array
 */
function aurora_post_price_column( $columns ) {
	$columns['aurora_price'] = __( '单篇付费', 'aurora' );

	return $columns;
}
add_filter( 'manage_post_posts_columns', 'aurora_post_price_column' );

/**
 * Render the price column.
 *
 * @param string $column  Column key.
 * @param int    $post_id Post ID.
 */
function aurora_post_price_column_content( $column, $post_id ) {
	if ( 'aurora_price' !== $column ) {
		return;
	}

	$price = aurora_post_price( $post_id );
	if ( ! $price ) {
		echo '—';
		return;
	}

	$count = 0;
	$paid  = get_posts( array(
		'post_type'      => 'aurora_order',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			'relation' => 'AND',
			array( 'key' => '_aurora_object', 'value' => $post_id ),
			array( 'key' => '_aurora_status', 'value' => 'paid' ),
		),
	) );
	$count = count( $paid );

	echo '<strong>¥' . esc_html( number_format( $price, 2 ) ) . '</strong>';
	echo '<br><small>' . esc_html( sprintf( __( '已售 %d 份', 'aurora' ), $count ) ) . '</small>';
}
add_action( 'manage_post_posts_custom_column', 'aurora_post_price_column_content', 10, 2 );

/**
 * Warn in the editor when a post is both priced and level-restricted, because
 * that combination has surprising semantics (price wins).
 *
 * @param WP_Post $post Post being edited.
 */
function aurora_price_level_conflict( $post ) {
	if ( 'post' !== $post->post_type ) {
		return;
	}

	$price = aurora_post_price( $post->ID );
	$level = (int) get_post_meta( $post->ID, 'aurora_vip_level', true );

	if ( $price > 0 && $level > 0 ) {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( '这篇文章同时设置了「单篇售价」和「最低会员等级」。单篇售价优先：没买过的用户会看到购买按钮，而不是会员升级提示。建议二选一。', 'aurora' )
		);
	}
}
add_action( 'admin_notices', 'aurora_price_level_conflict' );
