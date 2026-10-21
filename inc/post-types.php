<?php
/**
 * Custom post types: announcements and orders.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the announcement post type.
 */
function aurora_register_post_types() {
	register_post_type( 'aurora_notice', array(
		'labels'          => array(
			'name'          => __( '公告', 'aurora' ),
			'singular_name' => __( '公告', 'aurora' ),
			'add_new_item'  => __( '发布新公告', 'aurora' ),
			'edit_item'     => __( '编辑公告', 'aurora' ),
			'all_items'     => __( '所有公告', 'aurora' ),
		),
		'public'          => true,
		'has_archive'     => true,
		'menu_icon'       => 'dashicons-megaphone',
		'menu_position'   => 21,
		'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ),
		'rewrite'         => array( 'slug' => 'notice' ),
		'show_in_rest'    => true,
	) );

	// The order ledger is intentionally private: no front-end single views.
	register_post_type( 'aurora_order', array(
		'labels'          => array(
			'name'          => __( '订单', 'aurora' ),
			'singular_name' => __( '订单', 'aurora' ),
			'all_items'     => __( '所有订单', 'aurora' ),
		),
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => true,
		'menu_icon'       => 'dashicons-cart',
		'menu_position'   => 22,
		'capability_type' => 'post',
		'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap'    => true,
		'supports'        => array( 'title' ),
		'show_in_rest'    => false,
	) );
}
add_action( 'init', 'aurora_register_post_types' );

/**
 * Announcements shown in the front-end notice bar / modal.
 *
 * @param int $limit How many to fetch.
 * @return WP_Query
 */
function aurora_get_notices( $limit = 3 ) {
	return new WP_Query( array(
		'post_type'           => 'aurora_notice',
		'posts_per_page'      => $limit,
		'post_status'         => 'publish',
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	) );
}

/**
 * Order statuses used across the theme.
 *
 * @return array
 */
function aurora_order_statuses() {
	return array(
		'pending'  => __( '待支付', 'aurora' ),
		'paid'     => __( '已支付', 'aurora' ),
		'partial'  => __( '部分退款', 'aurora' ),
		'closed'   => __( '已关闭', 'aurora' ),
		'refunded' => __( '已全额退款', 'aurora' ),
	);
}
