<?php
/**
 * Shop: products, options, variants, stock and purchase limits.
 *
 * Until now a "product" was a priced post or a membership level, and an order
 * pointed at exactly one of them. That covers a blog with a paywall but not a
 * shop: no options, no stock, no per-customer limits, no basket.
 *
 * The pieces that need care here are the two that go wrong under concurrency:
 * stock must be claimed with a conditional UPDATE rather than read-then-write,
 * and money must never be taken from the browser — every price is recomputed
 * from this table at checkout time.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

const AURORA_PRODUCT_TTL_MOD = 'aurora_order_ttl';

/* -------------------------------------------------------------------------
   Product type
   ---------------------------------------------------------------------- */

/**
 * Register the product post type.
 *
 * `has_archive` stays false on purpose: an archive slug silently shadows a page
 * of the same name, and the shop front is a page template here.
 */
function aurora_register_product_type() {
	register_post_type(
		'aurora_product',
		array(
			'labels'        => array(
				'name'          => __( '商品', 'aurora' ),
				'singular_name' => __( '商品', 'aurora' ),
				'add_new_item'  => __( '添加商品', 'aurora' ),
				'edit_item'     => __( '编辑商品', 'aurora' ),
				'search_items'  => __( '搜索商品', 'aurora' ),
				'not_found'     => __( '还没有商品', 'aurora' ),
			),
			'public'        => true,
			'has_archive'   => false,
			'rewrite'       => array(
				'slug'       => 'product',
				'with_front' => false,
			),
			'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt', 'comments' ),
			'menu_icon'     => 'dashicons-cart',
			'show_in_rest'  => true,
		)
	);
}
add_action( 'init', 'aurora_register_product_type' );

/**
 * The two product kinds.
 *
 * @return array
 */
function aurora_product_kinds() {
	return apply_filters(
		'aurora_product_kinds',
		array(
			'virtual'  => __( '虚拟商品（无需收货）', 'aurora' ),
			'physical' => __( '实物商品（需要收货地址）', 'aurora' ),
		)
	);
}

/* -------------------------------------------------------------------------
   Reading a product
   ---------------------------------------------------------------------- */

/**
 * Every field of a product, normalised.
 *
 * @param int $product_id Product ID.
 * @return array
 */
function aurora_product_meta( $product_id ) {
	$product_id = (int) $product_id;

	$data = array(
		'id'            => $product_id,
		'price'         => round( (float) get_post_meta( $product_id, '_aurora_price', true ), 2 ),
		'compare_price' => round( (float) get_post_meta( $product_id, '_aurora_compare_price', true ), 2 ),
		'sku'           => (string) get_post_meta( $product_id, '_aurora_sku', true ),
		'kind'          => (string) get_post_meta( $product_id, '_aurora_kind', true ),
		'managed'       => (bool) get_post_meta( $product_id, '_aurora_stock_managed', true ),
		'stock'         => (int) get_post_meta( $product_id, '_aurora_stock', true ),
		'limit'         => (int) get_post_meta( $product_id, '_aurora_limit_per_user', true ),
		'options'       => aurora_product_options( $product_id ),
		'variants'      => aurora_product_variants( $product_id ),
	);

	if ( ! isset( aurora_product_kinds()[ $data['kind'] ] ) ) {
		$data['kind'] = 'virtual';
	}

	return apply_filters( 'aurora_product_meta', $data, $product_id );
}

/**
 * Option groups: name plus its values, in the order the owner entered them.
 *
 * @param int $product_id Product ID.
 * @return array
 */
function aurora_product_options( $product_id ) {
	$raw = get_post_meta( (int) $product_id, '_aurora_options', true );

	if ( ! is_array( $raw ) ) {
		return array();
	}

	$out = array();

	foreach ( $raw as $group ) {
		if ( ! is_array( $group ) ) {
			continue;
		}

		$name   = isset( $group['name'] ) ? trim( (string) $group['name'] ) : '';
		$values = isset( $group['values'] ) && is_array( $group['values'] ) ? $group['values'] : array();
		$values = array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', $values ) ), 'strlen' ) ) );

		if ( '' === $name || ! $values ) {
			continue;
		}

		$out[] = array(
			'name'   => $name,
			'values' => $values,
		);
	}

	return $out;
}

/**
 * Every variant row of a product.
 *
 * A variant's key is derived from the option values it stands for, never typed
 * in: a hand-written key is a second source of truth that drifts the moment a
 * value is renamed.
 *
 * @param int $product_id Product ID.
 * @return array
 */
function aurora_product_variants( $product_id ) {
	$raw = get_post_meta( (int) $product_id, '_aurora_variants', true );

	if ( ! is_array( $raw ) ) {
		return array();
	}

	$groups = aurora_product_options( $product_id );
	$out    = array();

	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$picked = array();

		foreach ( $groups as $group ) {
			$value = isset( $row['options'][ $group['name'] ] ) ? trim( (string) $row['options'][ $group['name'] ] ) : '';

			if ( '' === $value || ! in_array( $value, $group['values'], true ) ) {
				// A variant that no longer matches a declared value is stale:
				// drop it rather than selling something nobody can choose.
				continue 2;
			}

			$picked[ $group['name'] ] = $value;
		}

		if ( ! $picked ) {
			continue;
		}

		$key = aurora_variant_key( $picked );

		$out[ $key ] = array(
			'key'     => $key,
			'label'   => implode( ' / ', array_values( $picked ) ),
			'options' => $picked,
			'price'   => isset( $row['price'] ) && '' !== $row['price'] ? round( (float) $row['price'], 2 ) : null,
			'sku'     => isset( $row['sku'] ) ? trim( (string) $row['sku'] ) : '',
			'image'   => isset( $row['image'] ) ? (int) $row['image'] : 0,
			'enabled' => ! isset( $row['enabled'] ) || $row['enabled'],
		);
	}

	return apply_filters( 'aurora_product_variants', $out, $product_id );
}

/**
 * The key that identifies one combination of option values.
 *
 * Canonical order and a hash, so renaming an option *group* does not change a
 * key while changing its value does.
 *
 * @param array $picked Option name => value.
 * @return string
 */
function aurora_variant_key( $picked ) {
	return 'v' . substr( md5( aurora_variant_signature( $picked ) ), 0, 12 );
}

/**
 * The canonical, human-readable form of a combination.
 *
 * The key above is a hash of this string, and the product page ships the same
 * string to the browser so the script can match a selection to a variant without
 * reimplementing the hash. One definition, two consumers.
 *
 * @param array $picked Option name => value.
 * @return string
 */
function aurora_variant_signature( $picked ) {
	$pairs = array();

	foreach ( (array) $picked as $name => $value ) {
		$pairs[] = trim( (string) $name ) . '=' . trim( (string) $value );
	}

	sort( $pairs, SORT_STRING );

	return implode( '|', $pairs );
}

/**
 * One variant row, or null when the product has no such combination.
 *
 * @param int    $product_id Product ID.
 * @param string $key        Variant key.
 * @return array|null
 */
function aurora_product_variant( $product_id, $key ) {
	$variants = aurora_product_variants( $product_id );
	$key      = (string) $key;

	return isset( $variants[ $key ] ) ? $variants[ $key ] : null;
}

/**
 * Price for a product, or for one of its variants.
 *
 * A variant without its own price inherits the product's.
 *
 * @param int    $product_id Product ID.
 * @param string $key        Variant key.
 * @return float
 */
function aurora_product_price( $product_id, $key = '' ) {
	$meta = aurora_product_meta( $product_id );

	if ( '' !== (string) $key ) {
		$variant = aurora_product_variant( $product_id, $key );

		if ( $variant && null !== $variant['price'] ) {
			return (float) $variant['price'];
		}
	}

	return (float) $meta['price'];
}

/**
 * Whether a product's stock is tracked.
 *
 * @param int $product_id Product ID.
 * @return bool
 */
function aurora_product_stock_managed( $product_id ) {
	return (bool) get_post_meta( (int) $product_id, '_aurora_stock_managed', true );
}

/**
 * The meta key that holds the stock scalar for a variant (or the product).
 *
 * Stock is kept in its own scalar meta row per variant so a single conditional
 * UPDATE can claim it — a serialised array cannot be decremented atomically.
 *
 * @param int    $product_id Product ID.
 * @param string $key        Variant key.
 * @return string|null Null when the product has no variants but a key was given.
 */
function aurora_product_stock_key( $product_id, $key = '' ) {
	$key = (string) $key;

	if ( '' === $key ) {
		return '_aurora_stock';
	}

	if ( ! aurora_product_variant( $product_id, $key ) ) {
		return null;
	}

	return '_aurora_vstock_' . $key;
}

/**
 * Current stock for a product or variant. Null when stock is not tracked.
 *
 * @param int    $product_id Product ID.
 * @param string $key        Variant key.
 * @return int|null
 */
function aurora_product_stock( $product_id, $key = '' ) {
	if ( ! aurora_product_stock_managed( $product_id ) ) {
		return null;
	}

	$meta_key = aurora_product_stock_key( $product_id, $key );

	if ( ! $meta_key ) {
		return null;
	}

	$value = get_post_meta( (int) $product_id, $meta_key, true );

	return '' === $value ? 0 : (int) $value;
}

/**
 * Sets the stock of a product or variant (owner-side write).
 *
 * @param int    $product_id Product ID.
 * @param string $key        Variant key.
 * @param int    $qty        New quantity.
 */
function aurora_product_set_stock( $product_id, $key, $qty ) {
	$meta_key = aurora_product_stock_key( $product_id, $key );

	if ( $meta_key ) {
		update_post_meta( (int) $product_id, $meta_key, max( 0, (int) $qty ) );
	}
}

/* -------------------------------------------------------------------------
   Claiming and returning stock
   ---------------------------------------------------------------------- */

/**
 * Claim stock, atomically.
 *
 * Never read-then-write: two simultaneous checkouts both read "1 left" and both
 * succeed. The WHERE clause makes the database the arbiter, and rows_affected
 * tells us whether we won.
 *
 * @param int    $product_id Product ID.
 * @param string $key        Variant key.
 * @param int    $qty        Quantity.
 * @return true|WP_Error
 */
function aurora_product_reserve_stock( $product_id, $key, $qty ) {
	$qty = (int) $qty;

	if ( $qty < 1 ) {
		return new WP_Error( 'aurora_bad_qty', __( '数量不正确。', 'aurora' ) );
	}

	/**
	 * Refuse a reservation before it is attempted.
	 *
	 * A real extension point — stock kept in an external warehouse, a daily quota,
	 * a hold — and the only way to exercise the cart's rollback path deterministically,
	 * because a local stock shortfall is caught while the cart is being validated.
	 * Returning a WP_Error refuses the claim and nothing is decremented.
	 */
	$veto = apply_filters( 'aurora_product_pre_reserve_stock', null, $product_id, $key, $qty );

	if ( is_wp_error( $veto ) ) {
		return $veto;
	}

	$meta_key = aurora_product_stock_key( $product_id, $key );

	if ( ! $meta_key || ! aurora_product_stock_managed( $product_id ) ) {
		return true;
	}

	global $wpdb;

	$rows = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS SIGNED) - %d
			 WHERE post_id = %d AND meta_key = %s AND CAST(meta_value AS SIGNED) >= %d",
			$qty,
			(int) $product_id,
			$meta_key,
			$qty
		)
	);

	if ( 1 === (int) $rows ) {
		clean_post_cache( (int) $product_id );

		return true;
	}

	return new WP_Error(
		'aurora_out_of_stock',
		sprintf(
			/* translators: %s: product title */
			__( '「%s」库存不足。', 'aurora' ),
			get_the_title( $product_id )
		)
	);
}

/**
 * Return stock that was claimed but will not be sold.
 *
 * @param int    $product_id Product ID.
 * @param string $key        Variant key.
 * @param int    $qty        Quantity.
 * @return bool True when something was returned.
 */
function aurora_product_release_stock( $product_id, $key, $qty ) {
	$qty = (int) $qty;

	if ( $qty < 1 ) {
		return false;
	}

	$meta_key = aurora_product_stock_key( $product_id, $key );

	if ( ! $meta_key || ! aurora_product_stock_managed( $product_id ) ) {
		return false;
	}

	global $wpdb;

	$rows = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS SIGNED) + %d
			 WHERE post_id = %d AND meta_key = %s",
			$qty,
			(int) $product_id,
			$meta_key
		)
	);

	// The row can legitimately be missing when tracking was switched on after a
	// claim was made; create it rather than losing the return.
	if ( 0 === (int) $rows ) {
		$current = get_post_meta( (int) $product_id, $meta_key, true );
		update_post_meta( (int) $product_id, $meta_key, ( '' === $current ? 0 : (int) $current ) + $qty );
	}

	clean_post_cache( (int) $product_id );

	return true;
}

/* -------------------------------------------------------------------------
   Purchase limits
   ---------------------------------------------------------------------- */

/**
 * How many units of a product a user has already bought (paid orders only).
 *
 * @param int $user_id    User ID.
 * @param int $product_id Product ID.
 * @return int
 */
function aurora_product_purchased_qty( $user_id, $product_id ) {
	$user_id    = (int) $user_id;
	$product_id = (int) $product_id;

	if ( $user_id < 1 || $product_id < 1 ) {
		return 0;
	}

	$orders = get_posts(
		array(
			'post_type'      => 'aurora_order',
			'post_status'    => 'publish',
			'author'         => $user_id,
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_aurora_status',
					'value' => 'paid',
				),
			),
		)
	);

	$total = 0;

	foreach ( $orders as $order_id ) {
		// aurora_order_lines() already describes a pre-shop order as one line for
		// its object, so those are covered here without an extra unit.
		foreach ( aurora_order_lines( $order_id ) as $line ) {
			if ( (int) $line['product'] === $product_id ) {
				$total += (int) $line['qty'];
			}
		}
	}

	return $total;
}

/**
 * Whether a user may buy this many units right now.
 *
 * @param int    $user_id    User ID.
 * @param int    $product_id Product ID.
 * @param string $key        Variant key.
 * @param int    $qty        Quantity.
 * @return true|WP_Error
 */
function aurora_product_can_buy( $user_id, $product_id, $key = '', $qty = 1 ) {
	$product_id = (int) $product_id;
	$qty        = max( 1, (int) $qty );

	if ( 'publish' !== get_post_status( $product_id ) ) {
		return new WP_Error( 'aurora_not_for_sale', __( '该商品暂不可购买。', 'aurora' ) );
	}

	$meta = aurora_product_meta( $product_id );

	if ( $meta['variants'] ) {
		if ( '' === (string) $key ) {
			return new WP_Error( 'aurora_variant_required', __( '请先选择商品选项。', 'aurora' ) );
		}

		$variant = aurora_product_variant( $product_id, $key );

		if ( ! $variant || empty( $variant['enabled'] ) ) {
			return new WP_Error( 'aurora_bad_variant', __( '该规格不可购买。', 'aurora' ) );
		}
	}

	if ( $meta['managed'] ) {
		$stock = aurora_product_stock( $product_id, $key );

		if ( null !== $stock && $stock < $qty ) {
			return new WP_Error( 'aurora_out_of_stock', __( '库存不足。', 'aurora' ) );
		}
	}

	if ( $meta['limit'] > 0 ) {
		$bought = aurora_product_purchased_qty( $user_id, $product_id );

		if ( $bought + $qty > $meta['limit'] ) {
			return new WP_Error(
				'aurora_over_limit',
				sprintf(
					/* translators: %d: limit */
					__( '该商品每人限购 %d 件。', 'aurora' ),
					$meta['limit']
				)
			);
		}
	}

	return apply_filters( 'aurora_product_can_buy', true, $user_id, $product_id, $key, $qty );
}

/* -------------------------------------------------------------------------
   Order lines
   ---------------------------------------------------------------------- */

/**
 * The lines of an order.
 *
 * New orders store an array; orders written before the shop existed have none,
 * and are described by their legacy single-object fields instead.
 *
 * @param int $order_id Order ID.
 * @return array
 */
function aurora_order_lines( $order_id ) {
	$order_id = (int) $order_id;
	$raw      = get_post_meta( $order_id, '_aurora_lines', true );

	if ( is_array( $raw ) && $raw ) {
		$out = array();

		foreach ( $raw as $line ) {
			if ( ! is_array( $line ) || empty( $line['product'] ) ) {
				continue;
			}

			$out[] = array(
				'product' => (int) $line['product'],
				'variant' => isset( $line['variant'] ) ? (string) $line['variant'] : '',
				'label'   => isset( $line['label'] ) ? (string) $line['label'] : '',
				'qty'     => isset( $line['qty'] ) ? max( 1, (int) $line['qty'] ) : 1,
				'price'   => isset( $line['price'] ) ? round( (float) $line['price'], 2 ) : 0.0,
				'name'    => isset( $line['name'] ) ? (string) $line['name'] : '',
			);
		}

		return $out;
	}

	$legacy = aurora_legacy_order_line( $order_id );

	return $legacy ? array( $legacy ) : array();
}

/**
 * Describe an order written before line items existed.
 *
 * @param int $order_id Order ID.
 * @return array|null
 */
function aurora_legacy_order_line( $order_id ) {
	$order_id = (int) $order_id;
	$product  = (string) get_post_meta( $order_id, '_aurora_product', true );
	$object   = (int) get_post_meta( $order_id, '_aurora_object', true );

	if ( '' === $product ) {
		return null;
	}

	$name = '' !== $object ? get_the_title( $object ) : $product;

	return array(
		'product' => $object,
		'variant' => '',
		'label'   => '',
		'qty'     => 1,
		'price'   => round( (float) get_post_meta( $order_id, '_aurora_original_amount', true ), 2 ),
		'name'    => (string) $name,
		'legacy'  => $product,
	);
}

/**
 * Whether an order contains anything that has to be shipped.
 *
 * @param int $order_id Order ID.
 * @return bool
 */
function aurora_order_needs_shipping( $order_id ) {
	foreach ( aurora_order_lines( $order_id ) as $line ) {
		if ( ! empty( $line['product'] ) && 'physical' === aurora_product_meta( $line['product'] )['kind'] ) {
			return true;
		}
	}

	return (bool) apply_filters( 'aurora_order_needs_shipping', false, $order_id );
}

/**
 * Recording what an order actually sold, at the price it actually sold for.
 *
 * @param int   $order_id Order ID.
 * @param int   $product  Product ID.
 * @param array $line     Line data.
 */
function aurora_order_add_line( $order_id, $product, $line ) {
	// Read the raw meta, never aurora_order_lines(): that accessor synthesises a
	// line for orders written before the shop existed, and appending to a
	// synthesised line invents a sale worth the whole order total.
	$raw   = get_post_meta( (int) $order_id, '_aurora_lines', true );
	$lines = is_array( $raw ) ? $raw : array();
	$lines[] = array_merge(
		array(
			'product' => (int) $product,
			'variant' => '',
			'label'   => '',
			'qty'     => 1,
			'price'   => 0.0,
			'name'    => get_the_title( $product ),
		),
		(array) $line
	);

	update_post_meta( (int) $order_id, '_aurora_lines', $lines );
}

/* -------------------------------------------------------------------------
   Pending-order expiry
   ---------------------------------------------------------------------- */

/**
 * Minutes a pending order is held before its stock goes back on the shelf.
 *
 * @return int
 */
function aurora_order_ttl_minutes() {
	$ttl = (int) get_theme_mod( AURORA_PRODUCT_TTL_MOD, 30 );

	return $ttl > 0 ? $ttl : 30;
}

/**
 * Cancel pending orders that have gone stale and return their stock.
 *
 * Without this, every abandoned checkout holds its stock forever and the shop
 * sells out of things it still has.
 *
 * @param int $limit Maximum orders to process.
 * @return array Order IDs that were expired.
 */
function aurora_expire_pending_orders( $limit = 100 ) {
	$limit = max( 1, (int) $limit );
	$ttl   = aurora_order_ttl_minutes();

	$orders = get_posts(
		array(
			'post_type'      => 'aurora_order',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'date_query'     => array(
				array(
					'before'    => gmdate( 'Y-m-d H:i:s', time() - $ttl * MINUTE_IN_SECONDS ),
					'column'    => 'post_date_gmt',
					'inclusive' => true,
				),
			),
			'meta_query'     => array(
				array(
					'key'   => '_aurora_status',
					'value' => 'pending',
				),
			),
		)
	);

	$expired = array();

	foreach ( $orders as $order_id ) {
		aurora_void_order_stock( $order_id );

		update_post_meta( $order_id, '_aurora_status', 'cancelled' );
		update_post_meta( $order_id, '_aurora_cancelled_at', current_time( 'mysql' ) );
		update_post_meta( $order_id, '_aurora_cancel_reason', 'timeout' );

		$expired[] = $order_id;

		do_action( 'aurora_order_expired', $order_id );
	}

	return $expired;
}

/**
 * Give an order's claimed stock back, once.
 *
 * Guarded by a flag on the order: expiry, manual cancellation and a refund can
 * all reach this, and returning the same stock twice invents inventory.
 *
 * @param int  $order_id      Order ID.
 * @param bool $physical_only Restock only goods that physically come back.
 * @return bool True when stock was returned by this call.
 */
function aurora_void_order_stock( $order_id, $physical_only = false ) {
	$order_id = (int) $order_id;

	if ( get_post_meta( $order_id, '_aurora_stock_released', true ) ) {
		return false;
	}

	update_post_meta( $order_id, '_aurora_stock_released', 1 );

	foreach ( aurora_order_lines( $order_id ) as $line ) {
		if ( empty( $line['product'] ) ) {
			continue;
		}

		if ( $physical_only && 'physical' !== aurora_product_meta( $line['product'] )['kind'] ) {
			continue;
		}

		aurora_product_release_stock( $line['product'], $line['variant'], $line['qty'] );
	}

	do_action( 'aurora_order_stock_released', $order_id );

	return true;
}

/* -------------------------------------------------------------------------
   Checkout
   ---------------------------------------------------------------------- */

/**
 * Handle a product purchase posted from a product page.
 *
 * The price is recomputed here from the product table — the browser sends which
 * product, which variant and how many, never what it costs. Stock is claimed
 * before the order exists and released again if anything later in the flow
 * fails, so a rejected coupon cannot leave inventory stranded.
 */
function aurora_shop_handle_checkout() {
	$user_id    = get_current_user_id();
	$product_id = isset( $_POST['aurora_product_id'] ) ? (int) $_POST['aurora_product_id'] : 0;
	$variant    = isset( $_POST['aurora_variant'] ) ? sanitize_text_field( wp_unslash( $_POST['aurora_variant'] ) ) : '';
	$qty        = isset( $_POST['aurora_qty'] ) ? max( 1, (int) $_POST['aurora_qty'] ) : 1;
	$gw         = isset( $_POST['gateway'] ) ? sanitize_key( $_POST['gateway'] ) : 'manual';
	$coupon     = isset( $_POST['coupon'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon'] ) ) : '';
	$back       = $product_id ? get_permalink( $product_id ) : home_url( '/' );
	$ready      = aurora_available_gateways();

	if ( ! isset( $ready[ $gw ] ) ) {
		$gw = 'manual';
	}

	$can = aurora_product_can_buy( $user_id, $product_id, $variant, $qty );

	if ( is_wp_error( $can ) ) {
		aurora_shop_bail( $back, $can->get_error_message() );
	}

	// Claim the units first: whoever wins the claim owns them, and every exit
	// below this point has to give them back.
	$claimed = aurora_product_reserve_stock( $product_id, $variant, $qty );

	if ( is_wp_error( $claimed ) ) {
		aurora_shop_bail( $back, $claimed->get_error_message() );
	}

	$unit  = aurora_product_price( $product_id, $variant );
	$base  = round( $unit * $qty, 2 );
	$label = '';

	if ( '' !== $variant ) {
		$row   = aurora_product_variant( $product_id, $variant );
		$label = $row ? $row['label'] : '';
	}

	$order_id = aurora_create_order(
		array(
			'product'   => 'shop',
			'amount'    => $base,
			'gateway'   => $gw,
			'object_id' => $product_id,
			'coupon'    => $coupon,
		)
	);

	if ( is_wp_error( $order_id ) ) {
		aurora_product_release_stock( $product_id, $variant, $qty );
		aurora_shop_bail( $back, $order_id->get_error_message() );
	}

	aurora_order_add_line(
		$order_id,
		$product_id,
		array(
			'variant' => $variant,
			'label'   => $label,
			'qty'     => $qty,
			'price'   => $unit,
			'name'    => get_the_title( $product_id ),
		)
	);

	if ( aurora_order_needs_shipping( $order_id ) ) {
		update_post_meta( $order_id, '_aurora_needs_shipping', 1 );
	}

	do_action( 'aurora_checkout_created', $order_id, $gw );

	wp_safe_redirect(
		add_query_arg(
			array(
				'order'   => $order_id,
				'aurora_msg' => 'created',
			),
			aurora_user_page_url()
		)
	);
	exit;
}

/**
 * Send the buyer back with a message instead of a half-built order.
 *
 * @param string $where   Redirect target.
 * @param string $message Human-readable reason.
 */
function aurora_shop_bail( $where, $message ) {
	wp_safe_redirect( add_query_arg( 'aurora_msg', rawurlencode( $message ), $where ) );
	exit;
}

/**
 * Which products a user owns, as a set keyed by product ID.
 *
 * @param int $user_id User ID.
 * @return array Product ID => order ID.
 */
function aurora_user_owned_products( $user_id ) {
	$raw = get_user_meta( (int) $user_id, '_aurora_owned_products', true );

	return is_array( $raw ) ? $raw : array();
}

/**
 * What the product page needs to price a selection client-side.
 *
 * @param int $product_id Product ID.
 * @return array
 */
function aurora_product_script_data( $product_id ) {
	$meta     = aurora_product_meta( $product_id );
	$variants = array();

	foreach ( $meta['variants'] as $variant ) {
		$variants[ aurora_variant_signature( $variant['options'] ) ] = array(
			'key'     => $variant['key'],
			'price'   => (float) aurora_product_price( $product_id, $variant['key'] ),
			'stock'   => aurora_product_stock( $product_id, $variant['key'] ),
			'enabled' => (bool) $variant['enabled'],
		);
	}

	return array(
		'base'     => (float) $meta['price'],
		'managed'  => (bool) $meta['managed'],
		'stock'    => aurora_product_stock( $product_id ),
		'variants' => $variants,
		'groups'   => wp_list_pluck( $meta['options'], 'name' ),
	);
}

/**
 * Whether a user owns a product.
 *
 * @param int $user_id    User ID.
 * @param int $product_id Product ID.
 * @return bool
 */
function aurora_product_owned( $user_id, $product_id ) {
	$owned = aurora_user_owned_products( $user_id );

	return isset( $owned[ (int) $product_id ] );
}

/**
 * Record what a paid order delivered.
 *
 * @param int $order_id Order ID.
 */
function aurora_shop_grant_order( $order_id ) {
	$order_id = (int) $order_id;
	$user_id  = (int) get_post_meta( $order_id, '_aurora_user_id', true );

	if ( ! $user_id ) {
		$user_id = (int) get_post_field( 'post_author', $order_id );
	}

	if ( ! $user_id ) {
		return;
	}

	$owned = aurora_user_owned_products( $user_id );

	foreach ( aurora_order_lines( $order_id ) as $line ) {
		if ( empty( $line['product'] ) ) {
			continue;
		}

		$owned[ (int) $line['product'] ] = $order_id;

		do_action( 'aurora_product_purchased', $user_id, (int) $line['product'], $order_id, $line );
	}

	update_user_meta( $user_id, '_aurora_owned_products', $owned );
}
add_action( 'aurora_order_paid', 'aurora_shop_grant_order' );

/**
 * A fully refunded order puts physical goods back on the shelf.
 *
 * Digital goods stay with the buyer — they cannot be un-downloaded, so adding
 * them back to stock would be inventing inventory.
 *
 * @param int $refund_id Refund ID.
 * @param int $order_id  Order ID.
 */
function aurora_shop_restock_on_refund( $refund_id, $order_id ) {
	if ( 'refunded' !== get_post_meta( (int) $order_id, '_aurora_status', true ) ) {
		return;
	}

	aurora_void_order_stock( $order_id, true );
}
add_action( 'aurora_refund_settled', 'aurora_shop_restock_on_refund', 10, 2 );

/**
 * WP-CLI: expire stale orders.
 */
function aurora_cli_expire_orders() {
	$expired = aurora_expire_pending_orders( 500 );

	WP_CLI::success(
		sprintf(
			/* translators: %d: order count */
			__( '已取消超时未支付订单 %d 笔，库存已归还。', 'aurora' ),
			count( $expired )
		)
	);
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'aurora expire-orders', 'aurora_cli_expire_orders' );
}
