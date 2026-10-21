<?php
/**
 * Shopping cart.
 *
 * A cart is a promise, not an order: it holds no stock and reserves nothing, so
 * it can sit there indefinitely. Everything is therefore re-checked at checkout,
 * and the claim happens line by line with a rollback — if the third of five lines
 * has sold out in the meantime, the first two must give their units back rather
 * than leaving half an order's worth of inventory stranded.
 *
 * Line identity is product + variant, so adding the same combination again
 * increases the quantity while a different variant stays its own line.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

const AURORA_CART_META = '_aurora_cart';

/**
 * Distinct lines a cart may hold.
 *
 * A cap the owner can lower, and a real bound on the user meta a single account
 * can write.
 *
 * @return int
 */
function aurora_cart_max_lines() {
	return max( 1, (int) apply_filters( 'aurora_cart_max_lines', 20 ) );
}

/**
 * Units of one combination a cart may hold.
 *
 * @return int
 */
function aurora_cart_max_qty() {
	return max( 1, (int) apply_filters( 'aurora_cart_max_qty', 99 ) );
}

/**
 * The key that identifies one cart line.
 *
 * @param int    $product_id Product ID.
 * @param string $variant    Variant key.
 * @return string
 */
function aurora_cart_key( $product_id, $variant = '' ) {
	return (int) $product_id . ':' . (string) $variant;
}

/**
 * The stored rows, untouched.
 *
 * @param int $user_id User ID.
 * @return array
 */
function aurora_cart_raw( $user_id ) {
	$raw = get_user_meta( (int) $user_id, AURORA_CART_META, true );

	return is_array( $raw ) ? $raw : array();
}

/**
 * The cart, resolved against the live product table.
 *
 * Prices always come from the product, never from what was stored when the item
 * was added: a cart is not a quote. Each line also carries the reason it cannot
 * be bought, so the page can say what is wrong instead of failing at checkout.
 *
 * @param int $user_id User ID.
 * @return array
 */
function aurora_cart_items( $user_id ) {
	$items = array();

	foreach ( aurora_cart_raw( $user_id ) as $key => $row ) {
		$product_id = isset( $row['product'] ) ? (int) $row['product'] : 0;
		$variant    = isset( $row['variant'] ) ? (string) $row['variant'] : '';
		$qty        = isset( $row['qty'] ) ? max( 1, (int) $row['qty'] ) : 1;

		$item = array(
			'key'      => (string) $key,
			'product'  => $product_id,
			'variant'  => $variant,
			'qty'      => $qty,
			'name'     => '',
			'label'    => '',
			'price'    => 0.0,
			'subtotal' => 0.0,
			'stock'    => null,
			'url'      => '',
			'thumb'    => '',
			'problem'  => '',
		);

		$product = get_post( $product_id );

		if ( ! $product || 'aurora_product' !== $product->post_type ) {
			$item['problem'] = __( '商品已不存在', 'aurora' );
			$items[ $key ]   = $item;

			continue;
		}

		$item['name']  = get_the_title( $product_id );
		$item['url']   = get_permalink( $product_id );
		$item['thumb'] = get_the_post_thumbnail_url( $product_id, 'thumbnail' );

		if ( 'publish' !== $product->post_status ) {
			$item['problem'] = __( '商品已下架', 'aurora' );
			$items[ $key ]   = $item;

			continue;
		}

		$meta        = aurora_product_meta( $product_id );
		$variant_row = '' !== $variant ? aurora_product_variant( $product_id, $variant ) : null;

		if ( $meta['variants'] && ! $variant_row ) {
			$item['problem'] = __( '所选规格已不存在', 'aurora' );
			$items[ $key ]   = $item;

			continue;
		}

		if ( $variant_row && empty( $variant_row['enabled'] ) ) {
			$item['problem'] = __( '所选规格已下架', 'aurora' );
			$items[ $key ]   = $item;

			continue;
		}

		$item['label']    = $variant_row ? $variant_row['label'] : '';
		$item['price']    = aurora_product_price( $product_id, $variant );
		$item['subtotal'] = round( $item['price'] * $qty, 2 );
		$item['stock']    = aurora_product_stock( $product_id, $variant );

		$can = aurora_product_can_buy( $user_id, $product_id, $variant, $qty );

		if ( is_wp_error( $can ) ) {
			$item['problem'] = $can->get_error_message();
		}

		$items[ $key ] = $item;
	}

	return apply_filters( 'aurora_cart_items', $items, $user_id );
}

/**
 * Totals for a cart: units, lines, what it costs, and whether anything is wrong.
 *
 * Lines with a problem are excluded from the money, so the total shown is the
 * total that could actually be paid.
 *
 * @param int $user_id User ID.
 * @return array
 */
function aurora_cart_totals( $user_id ) {
	$count     = 0;
	$lines     = 0;
	$subtotal  = 0.0;
	$problems  = 0;
	$blocked   = 0;

	foreach ( aurora_cart_items( $user_id ) as $item ) {
		$count += (int) $item['qty'];
		$lines++;

		if ( $item['problem'] ) {
			$problems++;
			$blocked += (int) $item['qty'];

			continue;
		}

		$subtotal += (float) $item['subtotal'];
	}

	return array(
		'count'     => $count,
		'lines'     => $lines,
		'sellable'  => $count - $blocked,
		'subtotal'  => round( $subtotal, 2 ),
		'problems'  => $problems,
		'checkout'  => ( $count - $blocked ) > 0 && 0 === $problems,
	);
}

/**
 * Write the cart back.
 *
 * @param int   $user_id User ID.
 * @param array $rows    Rows keyed by line key.
 */
function aurora_cart_store( $user_id, $rows ) {
	if ( $rows ) {
		update_user_meta( (int) $user_id, AURORA_CART_META, $rows );
	} else {
		delete_user_meta( (int) $user_id, AURORA_CART_META );
	}

	do_action( 'aurora_cart_updated', $user_id );
}

/**
 * How many units of a product a user's cart holds across all its variants.
 *
 * The per-customer limit has to count what is already in the cart, or a customer
 * can fill the cart past the limit and only find out at checkout.
 *
 * @param int $user_id    User ID.
 * @param int $product_id Product ID.
 * @return int
 */
function aurora_cart_product_qty( $user_id, $product_id ) {
	$total = 0;

	foreach ( aurora_cart_raw( $user_id ) as $row ) {
		if ( (int) ( $row['product'] ?? 0 ) === (int) $product_id ) {
			$total += max( 1, (int) ( $row['qty'] ?? 1 ) );
		}
	}

	return $total;
}

/**
 * Add a combination to the cart, merging with the line that is already there.
 *
 * @param int    $user_id    User ID.
 * @param int    $product_id Product ID.
 * @param string $variant    Variant key.
 * @param int    $qty        Units to add.
 * @return array|WP_Error New totals.
 */
function aurora_cart_add( $user_id, $product_id, $variant = '', $qty = 1 ) {
	$user_id    = (int) $user_id;
	$product_id = (int) $product_id;
	$variant    = (string) $variant;
	$qty        = max( 1, (int) $qty );
	$rows       = aurora_cart_raw( $user_id );
	$key        = aurora_cart_key( $product_id, $variant );
	$existing   = isset( $rows[ $key ]['qty'] ) ? max( 0, (int) $rows[ $key ]['qty'] ) : 0;
	$wanted     = $existing + $qty;

	if ( $wanted > aurora_cart_max_qty() ) {
		return new WP_Error(
			'aurora_cart_qty',
			sprintf(
				/* translators: %d: maximum units per line */
				__( '同一规格最多 %d 件。', 'aurora' ),
				aurora_cart_max_qty()
			)
		);
	}

	if ( ! isset( $rows[ $key ] ) && count( $rows ) >= aurora_cart_max_lines() ) {
		return new WP_Error(
			'aurora_cart_full',
			sprintf(
				/* translators: %d: maximum distinct lines */
				__( '购物车最多放 %d 种商品。', 'aurora' ),
				aurora_cart_max_lines()
			)
		);
	}

	// Availability including what the cart already holds.
	$can = aurora_product_can_buy( $user_id, $product_id, $variant, $wanted );

	if ( is_wp_error( $can ) ) {
		return $can;
	}

	$meta  = aurora_product_meta( $product_id );
	$limit = (int) $meta['limit'];

	if ( $limit > 0 ) {
		$counted = aurora_product_purchased_qty( $user_id, $product_id ) + aurora_cart_product_qty( $user_id, $product_id ) + $qty;

		if ( $counted > $limit ) {
			return new WP_Error(
				'aurora_over_limit',
				sprintf(
					/* translators: %d: limit */
					__( '该商品每人限购 %d 件，购物车里已经放满。', 'aurora' ),
					$limit
				)
			);
		}
	}

	$rows[ $key ] = array(
		'product' => $product_id,
		'variant' => $variant,
		'qty'     => $wanted,
	);

	aurora_cart_store( $user_id, $rows );

	return aurora_cart_totals( $user_id );
}

/**
 * Set the quantity of a line. Zero or less removes it.
 *
 * @param int    $user_id User ID.
 * @param string $key     Line key.
 * @param int    $qty     New quantity.
 * @return array|WP_Error New totals.
 */
function aurora_cart_set_qty( $user_id, $key, $qty ) {
	$user_id = (int) $user_id;
	$key     = (string) $key;
	$rows    = aurora_cart_raw( $user_id );

	if ( ! isset( $rows[ $key ] ) ) {
		return new WP_Error( 'aurora_cart_missing', __( '购物车里没有这一项。', 'aurora' ) );
	}

	$qty = (int) $qty;

	if ( $qty < 1 ) {
		unset( $rows[ $key ] );
		aurora_cart_store( $user_id, $rows );

		return aurora_cart_totals( $user_id );
	}

	$qty = min( $qty, aurora_cart_max_qty() );

	$can = aurora_product_can_buy( $user_id, (int) $rows[ $key ]['product'], (string) $rows[ $key ]['variant'], $qty );

	if ( is_wp_error( $can ) ) {
		return $can;
	}

	$rows[ $key ]['qty'] = $qty;

	aurora_cart_store( $user_id, $rows );

	return aurora_cart_totals( $user_id );
}

/**
 * Remove a line.
 *
 * @param int    $user_id User ID.
 * @param string $key     Line key.
 * @return array New totals.
 */
function aurora_cart_remove( $user_id, $key ) {
	$rows = aurora_cart_raw( $user_id );
	unset( $rows[ (string) $key ] );

	aurora_cart_store( $user_id, $rows );

	return aurora_cart_totals( $user_id );
}

/**
 * Empty the cart.
 *
 * @param int $user_id User ID.
 */
function aurora_cart_clear( $user_id ) {
	aurora_cart_store( (int) $user_id, array() );
}

/**
 * Turn the cart into one order.
 *
 * Claims every line, and gives back everything it claimed if any line fails, so
 * a cart never half-reserves. The amount is computed here from the product table.
 *
 * @param int    $user_id User ID.
 * @param string $coupon  Coupon code.
 * @param string $gateway Gateway slug.
 * @return int|WP_Error Order ID.
 */
function aurora_cart_checkout( $user_id, $coupon = '', $gateway = 'manual' ) {
	$user_id = (int) $user_id;
	$items   = aurora_cart_items( $user_id );
	$totals  = aurora_cart_totals( $user_id );

	if ( ! $items ) {
		return new WP_Error( 'aurora_cart_empty', __( '购物车是空的。', 'aurora' ) );
	}

	foreach ( $items as $item ) {
		if ( $item['problem'] ) {
			return new WP_Error( 'aurora_cart_blocked', $item['problem'] );
		}
	}

	$claimed = array();

	foreach ( $items as $item ) {
		$ok = aurora_product_reserve_stock( $item['product'], $item['variant'], $item['qty'] );

		if ( is_wp_error( $ok ) ) {
			foreach ( $claimed as $line ) {
				aurora_product_release_stock( $line['product'], $line['variant'], $line['qty'] );
			}

			return $ok;
		}

		$claimed[] = $item;
	}

	$base = $totals['subtotal'];

	$order_id = aurora_create_order(
		array(
			'product'   => 'shop',
			'amount'    => $base,
			'gateway'   => $gateway,
			'object_id' => 0,
			'coupon'    => $coupon,
			'user_id'   => $user_id,
		)
	);

	if ( is_wp_error( $order_id ) ) {
		foreach ( $claimed as $line ) {
			aurora_product_release_stock( $line['product'], $line['variant'], $line['qty'] );
		}

		return $order_id;
	}

	foreach ( $claimed as $line ) {
		aurora_order_add_line(
			$order_id,
			$line['product'],
			array(
				'variant' => $line['variant'],
				'label'   => $line['label'],
				'qty'     => $line['qty'],
				'price'   => $line['price'],
				'name'    => $line['name'],
			)
		);
	}

	if ( aurora_order_needs_shipping( $order_id ) ) {
		update_post_meta( $order_id, '_aurora_needs_shipping', 1 );
	}

	aurora_cart_clear( $user_id );

	do_action( 'aurora_cart_checked_out', $order_id, $items );

	return $order_id;
}

/**
 * URL of the cart page.
 *
 * @return string
 */
function aurora_cart_page_url() {
	$page_id = (int) get_option( 'aurora_cart_page_id' );
	$url     = $page_id ? get_permalink( $page_id ) : home_url( '/cart/' );

	return $url;
}

/**
 * Create the cart page if it is not there yet.
 *
 * Called on activation, and by the admin-post that repairs pages, so the shop is
 * usable without the owner hand-building a page with the right template.
 */
function aurora_cart_ensure_page() {
	if ( (int) get_option( 'aurora_cart_page_id' ) ) {
		return;
	}

	$page_id = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => __( '购物车', 'aurora' ),
			'post_name'   => 'cart',
			'post_content' => '',
			'meta_input'  => array( '_wp_page_template' => 'page-templates/cart.php' ),
		)
	);

	if ( $page_id && ! is_wp_error( $page_id ) ) {
		update_option( 'aurora_cart_page_id', (int) $page_id );
	}
}

/* -------------------------------------------------------------------------
   AJAX
   ---------------------------------------------------------------------- */

/**
 * Cart actions are authenticated and cheap, but unbounded: they write user meta.
 *
 * @param array $buckets Buckets.
 * @return array
 */
function aurora_cart_rate_bucket( $buckets ) {
	$buckets['cart'] = __( '购物车操作', 'aurora' );

	return $buckets;
}
add_filter( 'aurora_rate_buckets', 'aurora_cart_rate_bucket' );

/**
 * Default ceilings for the cart bucket.
 *
 * @param array $defaults Defaults.
 * @return array
 */
function aurora_cart_rate_defaults( $defaults ) {
	$defaults['cart'] = array(
		'short' => 30,
		'hour'  => 200,
		'day'   => 800,
	);

	return $defaults;
}
add_filter( 'aurora_rate_defaults', 'aurora_cart_rate_defaults' );

/**
 * Shared preamble for the cart endpoints.
 *
 * @return int User ID.
 */
function aurora_cart_ajax_user() {
	aurora_check_nonce();

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( '请先登录。', 'aurora' ) ), 401 );
	}

	$user_id = get_current_user_id();

	if ( function_exists( 'aurora_rate_check' ) ) {
		$gate = aurora_rate_check( 'cart' );

		if ( is_wp_error( $gate ) ) {
			wp_send_json_error(
				array(
					'message'     => $gate->get_error_message(),
					'retry_after' => $gate->get_error_data(),
				),
				429
			);
		}
	}

	return $user_id;
}

/**
 * Reply with the new cart state.
 *
 * @param array  $totals Totals.
 * @param string $message Human-readable outcome.
 */
function aurora_cart_ajax_reply( $totals, $message ) {
	wp_send_json_success(
		array(
			'message' => $message,
			'totals'  => $totals,
		)
	);
}

/**
 * Add to cart.
 */
function aurora_ajax_cart_add() {
	$user_id = aurora_cart_ajax_user();

	$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
	$variant    = isset( $_POST['variant'] ) ? sanitize_text_field( wp_unslash( $_POST['variant'] ) ) : '';
	$qty        = isset( $_POST['qty'] ) ? max( 1, (int) $_POST['qty'] ) : 1;

	$result = aurora_cart_add( $user_id, $product_id, $variant, $qty );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error(
			array(
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			),
			200
		);
	}

	aurora_cart_ajax_reply( $result, __( '已加入购物车。', 'aurora' ) );
}
add_action( 'wp_ajax_aurora_cart_add', 'aurora_ajax_cart_add' );
add_action( 'wp_ajax_nopriv_aurora_cart_add', 'aurora_ajax_cart_add' );   // guests get the same guard rails as JSON, not a bare 0.

/**
 * Change a line's quantity.
 */
function aurora_ajax_cart_qty() {
	$user_id = aurora_cart_ajax_user();

	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	$qty = isset( $_POST['qty'] ) ? (int) $_POST['qty'] : 1;

	$result = aurora_cart_set_qty( $user_id, $key, $qty );

	if ( is_wp_error( $result ) ) {
		// Send back the quantity the cart actually holds: a refused change must not
		// leave the field showing a number the server never accepted.
		$rows = aurora_cart_raw( $user_id );
		$have = isset( $rows[ $key ]['qty'] ) ? (int) $rows[ $key ]['qty'] : 0;

		wp_send_json_error(
			array(
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
				'qty'     => $have,
			),
			200
		);
	}

	aurora_cart_ajax_reply( $result, __( '数量已更新。', 'aurora' ) );
}
add_action( 'wp_ajax_aurora_cart_qty', 'aurora_ajax_cart_qty' );
add_action( 'wp_ajax_nopriv_aurora_cart_qty', 'aurora_ajax_cart_qty' );   // guests get the same guard rails as JSON, not a bare 0.

/**
 * Remove a line.
 */
function aurora_ajax_cart_remove() {
	$user_id = aurora_cart_ajax_user();

	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';

	aurora_cart_ajax_reply( aurora_cart_remove( $user_id, $key ), __( '已移出购物车。', 'aurora' ) );
}
add_action( 'wp_ajax_aurora_cart_remove', 'aurora_ajax_cart_remove' );
add_action( 'wp_ajax_nopriv_aurora_cart_remove', 'aurora_ajax_cart_remove' );   // guests get the same guard rails as JSON, not a bare 0.

/**
 * Check out the whole cart from the cart page.
 */
function aurora_handle_cart_checkout() {
	if ( ! isset( $_POST['aurora_cart_checkout_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['aurora_cart_checkout_nonce'] ) ), 'aurora_cart_checkout' ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( aurora_cart_page_url() ) );
		exit;
	}

	$user_id  = get_current_user_id();
	$gateways = aurora_available_gateways();
	$gateway  = isset( $_POST['gateway'] ) ? sanitize_key( $_POST['gateway'] ) : 'manual';

	if ( ! isset( $gateways[ $gateway ] ) ) {
		$gateway = 'manual';
	}

	$coupon = isset( $_POST['coupon'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon'] ) ) : '';
	$order  = aurora_cart_checkout( $user_id, $coupon, $gateway );

	if ( is_wp_error( $order ) ) {
		wp_safe_redirect(
			add_query_arg( 'aurora_msg', rawurlencode( $order->get_error_message() ), aurora_cart_page_url() )
		);
		exit;
	}

	do_action( 'aurora_checkout_created', $order, $gateway );

	wp_safe_redirect( add_query_arg( array( 'order' => $order ), aurora_user_page_url() ) );
	exit;
}
add_action( 'template_redirect', 'aurora_handle_cart_checkout' );

/**
 * Cart total for the header badge.
 *
 * @return int
 */
function aurora_cart_badge_count() {
	if ( ! is_user_logged_in() ) {
		return 0;
	}

	// Straight off the stored rows: the header runs on every page view, and
	// resolving every line's product just to show a number would cost a query per
	// line on every request. The cart page does the full resolution.
	$count = 0;

	foreach ( aurora_cart_raw( get_current_user_id() ) as $row ) {
		$count += max( 1, (int) ( $row['qty'] ?? 1 ) );
	}

	return (int) apply_filters( 'aurora_cart_badge_count', $count );
}
