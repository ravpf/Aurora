<?php
/**
 * Capability matrix: who may do what.
 *
 * Until now every gate in the theme asked one of two questions — "is this an
 * administrator?" or "what VIP level is this?". That works for two rules and
 * collapses as soon as there are twenty: a shop needs to know who may upload a
 * product image, a forum needs to know who may post without review, and the
 * comment form needs a permission that is neither admin nor paid member.
 *
 * So permissions live here as a matrix: role x capability. A user's roles are
 * resolved into a set of segments (guest, logged-in, verified, site level,
 * membership level), and a capability is granted if any of that user's segments
 * grants it. Integer capabilities (upload size, daily quota) take the highest
 * value among the segments, which is what people expect from "会员福利叠加".
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * REST: no user list for visitors
 * ---------------------------------------------------------------------- */

/**
 * Hide /wp/v2/users from anonymous requests.
 *
 * The endpoint is on by default and answers with every author's id, slug and
 * display name — the first thing anyone runs before trying passwords. Logged-in
 * users keep it (the editor and the member centre both read it); visitors get a
 * 404 for the route instead of a roster.
 *
 * @param array $endpoints Registered REST routes.
 * @return array
 */
function aurora_rest_hide_user_routes( $endpoints ) {
	if ( is_user_logged_in() ) {
		return $endpoints;
	}

	foreach ( array_keys( $endpoints ) as $route ) {
		if ( 0 === strpos( (string) $route, '/wp/v2/users' ) ) {
			unset( $endpoints[ $route ] );
		}
	}

	return $endpoints;
}
add_filter( 'rest_endpoints', 'aurora_rest_hide_user_routes' );

/* -------------------------------------------------------------------------
 * Catalogue
 * ---------------------------------------------------------------------- */

/**
 * Every capability the theme gates on.
 *
 * `type` decides how conflicting segments combine: booleans are granted if any
 * segment grants them, numbers take the largest value.
 *
 * @return array
 */
function aurora_caps() {
	$caps = array(
		'comment'        => array(
			'label' => __( '发表评论', 'aurora' ),
			'type'  => 'bool',
		),
		'comment_skip'   => array(
			'label' => __( '评论免审核', 'aurora' ),
			'type'  => 'bool',
		),
		'view_comment'   => array(
			'label' => __( '查看评论', 'aurora' ),
			'type'  => 'bool',
		),
		'post_create'    => array(
			'label' => __( '前台投稿 / 发帖', 'aurora' ),
			'type'  => 'bool',
		),
		'post_skip'      => array(
			'label' => __( '投稿免审核', 'aurora' ),
			'type'  => 'bool',
		),
		'upload'         => array(
			'label' => __( '上传文件', 'aurora' ),
			'type'  => 'bool',
		),
		'upload_mb'      => array(
			'label' => __( '单文件上限', 'aurora' ),
			'type'  => 'int',
			'unit'  => 'MB',
		),
		'upload_types'   => array(
			'label' => __( '可上传格式', 'aurora' ),
			'type'  => 'list',
		),
		'download'       => array(
			'label' => __( '下载付费资源', 'aurora' ),
			'type'  => 'bool',
		),
		'download_daily' => array(
			'label' => __( '每日免费下载', 'aurora' ),
			'type'  => 'int',
			'unit'  => '次',
		),
	);

	return apply_filters( 'aurora_caps', $caps );
}

/**
 * The type of a capability, defaulting to a boolean.
 *
 * @param string $cap Capability key.
 * @return string
 */
function aurora_cap_type( $cap ) {
	$caps = aurora_caps();

	return isset( $caps[ $cap ]['type'] ) ? $caps[ $cap ]['type'] : 'bool';
}

/**
 * How many site levels exist (the experience-based ladder, not membership).
 *
 * @return int
 */
function aurora_level_max() {
	return max( 1, (int) apply_filters( 'aurora_level_max', 5 ) );
}

/**
 * Every role column of the matrix, in display order.
 *
 * @return array Keyed by role key, each with a label and a group.
 */
function aurora_permission_roles() {
	$roles = array(
		'guest'    => array(
			'label' => __( '游客（未登录）', 'aurora' ),
			'group' => 'base',
		),
		'user'     => array(
			'label' => __( '登录用户', 'aurora' ),
			'group' => 'base',
		),
		'verified' => array(
			'label' => __( '认证用户', 'aurora' ),
			'group' => 'base',
		),
	);

	for ( $i = 1; $i <= aurora_level_max(); $i++ ) {
		$roles[ 'level_' . $i ] = array(
			/* translators: %d: level number */
			'label' => sprintf( __( 'Lv.%d', 'aurora' ), $i ),
			'group' => 'level',
		);
	}

	$vip_levels = function_exists( 'aurora_vip_levels' ) ? aurora_vip_levels() : array();
	foreach ( $vip_levels as $index => $info ) {
		$level = (int) $index;
		if ( $level < 1 ) {
			continue;
		}
		$roles[ 'vip_' . $level ] = array(
			'label' => isset( $info['name'] ) ? $info['name'] : sprintf( 'VIP %d', $level ),
			'group' => 'vip',
		);
	}

	return apply_filters( 'aurora_permission_roles', $roles );
}

/**
 * Grouped role columns, for the settings table's header.
 *
 * @return array
 */
function aurora_permission_role_groups() {
	$groups = array(
		'base'  => __( '基础身份', 'aurora' ),
		'level' => __( '用户等级（经验值）', 'aurora' ),
		'vip'   => __( '会员等级', 'aurora' ),
	);

	$out = array();
	foreach ( aurora_permission_roles() as $key => $role ) {
		$group           = isset( $role['group'] ) ? $role['group'] : 'base';
		$out[ $group ][] = array(
			'key'   => $key,
			'label' => $role['label'],
		);
	}

	return array_intersect_key( $out, $groups );
}

/**
 * The stock matrix, used to fill anything the administrator has not set.
 *
 * Expressed as sparse overrides on top of a per-role baseline so the defaults
 * stay readable: `guest` gets the strictest baseline, and each higher segment
 * loosens specific capabilities.
 *
 * @return array role => cap => value
 */
function aurora_permission_defaults() {
	$baseline = array(
		// These defaults describe what the site already did: comment
		// registration is off and moderation is off, so anyone could comment
		// and nothing was queued. Closing that on upgrade would silently break
		// the comment area, so the matrix starts as a record of current
		// behaviour and the administrator tightens it from the settings screen.
		'comment'        => true,
		'comment_skip'   => true,
		'view_comment'   => true,
		'post_create'    => false,
		'post_skip'      => false,
		'upload'         => false,
		'upload_mb'      => 0,
		'upload_types'   => array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ),
		'download'       => false,
		'download_daily' => 0,
	);

	$overrides = array(
		'guest'    => array(),
		'user'     => array(
			'post_create'    => true,
			'upload'         => true,
			'download'       => true,
			'download_daily' => 3,
		),
		'verified' => array(
			'post_create'    => true,
			'post_skip'      => true,
			'upload'         => true,
			'upload_mb'      => 20,
			'upload_types'   => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'rar', '7z', 'pdf', 'mp4' ),
			'download'       => true,
			'download_daily' => 10,
		),
	);

	// Site levels: experience only, so the rewards are modest, and they start at
	// Lv.2 — Lv.1 is the plain member baseline, not a reward. Every registered
	// member is Lv.1 the moment they sign up, so giving Lv.1 more than the `user`
	// row would quietly raise the allowance for the whole membership and leave the
	// `user` row unreachable. The step up belongs one rung higher.
	for ( $i = 1; $i <= aurora_level_max(); $i++ ) {
		$overrides[ 'level_' . $i ] = array(
			'download'       => true,
			'download_daily' => 1 === $i ? 3 : 3 + $i,
		);
	}

	// Membership: pays for convenience (no review queue, bigger uploads).
	$vip_levels = function_exists( 'aurora_vip_levels' ) ? aurora_vip_levels() : array();
	foreach ( $vip_levels as $index => $info ) {
		$level = (int) $index;
		if ( $level < 1 ) {
			continue;
		}
		$overrides[ 'vip_' . $level ] = array(
			'comment'        => true,
			'comment_skip'   => true,
			'post_create'    => true,
			'post_skip'      => true,
			'upload'         => true,
			'upload_mb'      => 20 * $level,
			'upload_types'   => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'rar', '7z', 'pdf', 'mp4' ),
			'download'       => true,
			'download_daily' => 10 * $level,
		);
	}

	$matrix = array();
	foreach ( aurora_permission_roles() as $key => $role ) {
		$matrix[ $key ] = array_merge( $baseline, isset( $overrides[ $key ] ) ? $overrides[ $key ] : array() );
	}

	return apply_filters( 'aurora_permission_defaults', $matrix );
}

/* -------------------------------------------------------------------------
 * Storage
 * ---------------------------------------------------------------------- */

/**
 * Read the saved matrix, filled in against the defaults.
 *
 * Rows and cells are merged separately: a role added by a filter after the
 * administrator last saved must still answer every capability, and a capability
 * added by a later version must still answer for every role. Without the merge
 * both would read as "not set" and silently deny.
 *
 * @return array
 */
function aurora_permission_matrix() {
	$defaults = aurora_permission_defaults();
	$saved    = get_option( 'aurora_permission_matrix', array() );
	$saved    = is_array( $saved ) ? $saved : array();

	$matrix = array();
	foreach ( $defaults as $role => $caps ) {
		$matrix[ $role ] = array_merge( $caps, isset( $saved[ $role ] ) && is_array( $saved[ $role ] ) ? $saved[ $role ] : array() );
	}

	return apply_filters( 'aurora_permission_matrix', $matrix );
}

/**
 * Persist the matrix.
 *
 * @param array $matrix role => cap => value.
 * @return bool
 */
function aurora_permission_matrix_save( $matrix ) {
	return update_option( 'aurora_permission_matrix', $matrix, false );
}

/* -------------------------------------------------------------------------
 * Resolution
 * ---------------------------------------------------------------------- */

/**
 * The site level of a user, from the experience ladder.
 *
 * The levels module owns the ledger; until it is loaded we answer 0 rather
 * than guessing, so the matrix simply grants no level rows.
 *
 * @param int $user_id User ID, 0 for the current user.
 * @return int
 */
function aurora_user_site_level( $user_id = 0 ) {
	if ( function_exists( 'aurora_exp_level_of' ) ) {
		return (int) aurora_exp_level_of( $user_id );
	}

	return 0;
}

/**
 * Which matrix rows apply to a user.
 *
 * A user can occupy several rows at once — a verified member holding a VIP plan
 * is granted by `user`, `verified` and `vip_N` together. Order does not matter
 * because resolution is a union, and stating it that way keeps the matrix from
 * needing a precedence table.
 *
 * @param int|null $user_id User ID, null for the current user.
 * @return array Role keys.
 */
function aurora_user_segments( $user_id = null ) {
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
	$roles   = array();

	if ( ! $user_id ) {
		return array( 'guest' );
	}

	$roles[] = 'user';

	if ( get_user_meta( $user_id, 'aurora_verified', true ) ) {
		$roles[] = 'verified';
	}

	$level = aurora_user_site_level( $user_id );
	if ( $level > 0 ) {
		$roles[] = 'level_' . min( $level, aurora_level_max() );
	}

	if ( function_exists( 'aurora_get_user_level' ) ) {
		$vip = (int) aurora_get_user_level( $user_id );
		if ( $vip > 0 ) {
			$roles[] = 'vip_' . $vip;
		}
	}

	// Any role key the site does not define would silently contribute nothing,
	// so drop it rather than carry a row that answers no capability.
	$known = aurora_permission_roles();

	return array_values( array_filter( $roles, function ( $role ) use ( $known ) {
		return isset( $known[ $role ] );
	} ) );
}

/**
 * Raw stored value of a capability for one role.
 *
 * @param string $role Role key.
 * @param string $cap  Capability key.
 * @return mixed
 */
function aurora_role_cap( $role, $cap ) {
	$matrix = aurora_permission_matrix();

	return isset( $matrix[ $role ][ $cap ] ) ? $matrix[ $role ][ $cap ] : null;
}

/**
 * May the user do this?
 *
 * @param string $cap  Capability key.
 * @param array  $args {
 *     @type int|null $user_id User to test, null for the current user.
 *     @type int      $post_id Post the question is about, when relevant.
 * }
 * @return bool
 */
function aurora_user_can( $cap, $args = array() ) {
	$args    = wp_parse_args( $args, array(
		'user_id' => null,
		'post_id' => 0,
	) );
	$user_id = null === $args['user_id'] ? get_current_user_id() : (int) $args['user_id'];

	// Administrators and editors are never locked out of their own site; the
	// matrix is for visitors and members.
	if ( $user_id && user_can( $user_id, 'edit_others_posts' ) ) {
		return true;
	}

	if ( 'int' === aurora_cap_type( $cap ) ) {
		return aurora_user_cap_int( $cap, $args ) > 0;
	}

	$allowed = false;
	foreach ( aurora_user_segments( $user_id ) as $role ) {
		if ( aurora_role_cap( $role, $cap ) ) {
			$allowed = true;
			break;
		}
	}

	return (bool) apply_filters( 'aurora_user_can', $allowed, $cap, $args, $user_id );
}

/**
 * Numeric variant of aurora_user_can(): the highest value the user's roles grant.
 *
 * `0` consistently means "this role sets no ceiling", so a plain logged-in user
 * keeps whatever the site itself allows. A number is a ceiling the role
 * introduces — for a membership that reads as a benefit (bigger uploads, more
 * daily downloads), and the value that applies is the largest among the user's
 * roles, never the smallest. That is the only rule an administrator has to
 * remember: raising a number widens, lowering it narrows, `0` means unset.
 *
 * @param string $cap  Capability key.
 * @param array  $args Same as aurora_user_can().
 * @return int
 */
function aurora_user_cap_int( $cap, $args = array() ) {
	$args    = wp_parse_args( $args, array(
		'user_id' => null,
		'post_id' => 0,
	) );
	$user_id = null === $args['user_id'] ? get_current_user_id() : (int) $args['user_id'];
	$value   = 0;

	foreach ( aurora_user_segments( $user_id ) as $role ) {
		$value = max( $value, (int) aurora_role_cap( $role, $cap ) );
	}

	return (int) apply_filters( 'aurora_user_cap_int', $value, $cap, $args, $user_id );
}

/**
 * The file extensions a user may upload, as a lower-case list.
 *
 * @param int|null $user_id User ID, null for the current user.
 * @return array
 */
function aurora_user_upload_types( $user_id = null ) {
	$user_id  = null === $user_id ? get_current_user_id() : (int) $user_id;
	$types    = array();
	$segments = aurora_user_segments( $user_id );

	foreach ( $segments as $role ) {
		$value = aurora_role_cap( $role, 'upload_types' );
		if ( is_array( $value ) ) {
			$types = array_merge( $types, $value );
		}
	}

	$types = array_values( array_unique( array_map( 'strtolower', $types ) ) );

	return apply_filters( 'aurora_user_upload_types', $types, $user_id );
}

/**
 * Gate a request, returning a WP_Error instead of a bare boolean.
 *
 * Entry points (AJAX handlers, form processors) can hand this straight back to
 * the client, which keeps the "who is allowed" decision in one place.
 *
 * @param string $cap  Capability key.
 * @param array  $args Same as aurora_user_can().
 * @return true|WP_Error
 */
function aurora_require_cap( $cap, $args = array() ) {
	if ( aurora_user_can( $cap, $args ) ) {
		return true;
	}

	$caps  = aurora_caps();
	$label = isset( $caps[ $cap ]['label'] ) ? $caps[ $cap ]['label'] : $cap;

	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'aurora_login_required',
			__( '请先登录后再试。', 'aurora' ),
			array( 'status' => 401 )
		);
	}

	return new WP_Error(
		'aurora_cap_denied',
		sprintf(
			/* translators: %s: capability label */
			__( '你的账户暂无「%s」权限。', 'aurora' ),
			$label
		),
		array( 'status' => 403 )
	);
}

/* -------------------------------------------------------------------------
 * Enforcement
 * ---------------------------------------------------------------------- */

/**
 * Refuse a comment from someone without the capability.
 *
 * @param array $commentdata Comment data about to be inserted.
 * @return array
 */
function aurora_gate_comment( $commentdata ) {
	// Anonymous comments are allowed only when the matrix says so; the check
	// runs for logged-out visitors too, which is the point of `guest`.
	if ( ! aurora_user_can( 'comment' ) ) {
		wp_die(
			esc_html__( '你的账户暂无发表评论的权限。', 'aurora' ),
			esc_html__( '评论已关闭', 'aurora' ),
			array( 'response' => 403, 'back_link' => true )
		);
	}

	return $commentdata;
}
add_filter( 'preprocess_comment', 'aurora_gate_comment' );

/**
 * Send comments to the moderation queue when the author lacks `comment_skip`.
 *
 * @param int|string $approved 1, 0 or 'spam'.
 * @return int|string
 */
function aurora_gate_comment_approval( $approved ) {
	$user_id = get_current_user_id();

	if ( ! $user_id || user_can( $user_id, 'edit_others_posts' ) ) {
		return $approved;
	}
	if ( aurora_user_can( 'comment_skip', array( 'user_id' => $user_id ) ) ) {
		return $approved;
	}

	// Only downgrade an approval to pending; never resurrect a spam verdict.
	return ( 1 === $approved || '1' === $approved || true === $approved ) ? 0 : $approved;
}
add_filter( 'pre_comment_approved', 'aurora_gate_comment_approval' );

/**
 * Close the comment area for users who may not read comments.
 *
 * Hiding the list without closing the form would let someone comment on
 * something they cannot see, so both are handled here.
 *
 * @param bool $open Whether comments are open.
 * @return bool
 */
function aurora_gate_comment_visibility( $open ) {
	if ( ! aurora_user_can( 'view_comment' ) ) {
		return false;
	}

	return $open;
}
add_filter( 'comments_open', 'aurora_gate_comment_visibility' );

/**
 * Enforce the per-role upload size limit.
 *
 * @param array $file Entry from $_FILES.
 * @return array
 */
function aurora_gate_upload_size( $file ) {
	if ( ! aurora_user_can( 'upload' ) ) {
		$file['error'] = __( '你的账户暂无上传文件的权限。', 'aurora' );
		return $file;
	}

	$limit = aurora_user_cap_int( 'upload_mb' );

	// Unset ceiling: the site's own upload limit is the only one in force.
	if ( $limit > 0 && isset( $file['size'] ) && $file['size'] > $limit * MB_IN_BYTES ) {
		$file['error'] = sprintf(
			/* translators: %d: size limit in megabytes */
			__( '文件超出你的上传上限（%d MB）。', 'aurora' ),
			$limit
		);
	}

	return $file;
}
add_filter( 'wp_handle_upload_prefilter', 'aurora_gate_upload_size' );

/**
 * Enforce the per-role allowed extensions.
 *
 * Runs on the same filter as the size check so a rejected upload reports one
 * clear reason rather than two overlapping ones.
 *
 * @param array $file Entry from $_FILES.
 * @return array
 */
function aurora_gate_upload_types( $file ) {
	if ( ! empty( $file['error'] ) ) {
		return $file;
	}

	$allowed = aurora_user_upload_types();
	if ( ! $allowed ) {
		return $file;
	}

	$ext = strtolower( pathinfo( isset( $file['name'] ) ? $file['name'] : '', PATHINFO_EXTENSION ) );

	// The site's own upload_mimes list still applies on top of this; the matrix
	// can only narrow it, never widen it.
	if ( $ext && ! in_array( $ext, $allowed, true ) ) {
		$file['error'] = sprintf(
			/* translators: %s: comma separated list of file extensions */
			__( '你的账户不允许上传 .%s 格式的文件。', 'aurora' ),
			$ext
		);
	}

	return $file;
}
add_filter( 'wp_handle_upload_prefilter', 'aurora_gate_upload_types', 20 );

/**
 * Daily free-download allowance, from the matrix.
 *
 * The download module calls this when serving a file. `0` means the role sets
 * no ceiling, so there is nothing to enforce; the allowance is applied only to
 * files the user is entitled to as a role, never to ones they paid for.
 *
 * @param int|null $user_id User ID, null for the current user.
 * @return int
 */
function aurora_download_allowance( $user_id = null ) {
	return aurora_user_cap_int( 'download_daily', array( 'user_id' => $user_id ) );
}

/* -------------------------------------------------------------------------
 * Per-category view rules
 * ---------------------------------------------------------------------- */

/**
 * Meta key holding a category's view rule.
 *
 * @return string
 */
function aurora_cat_access_key() {
	return '_aurora_cat_access';
}

/**
 * Rules a category can carry, as value => label.
 *
 * @return array
 */
function aurora_cat_access_rules() {
	$rules = array(
		''               => __( '公开', 'aurora' ),
		'login'          => __( '登录后可见', 'aurora' ),
		'verified'       => __( '认证用户可见', 'aurora' ),
	);

	for ( $i = 1; $i <= aurora_level_max(); $i++ ) {
		$rules[ 'level:' . $i ] = sprintf(
			/* translators: %d: level number */
			__( 'Lv.%d 及以上', 'aurora' ),
			$i
		);
	}

	$vip_levels = function_exists( 'aurora_vip_levels' ) ? aurora_vip_levels() : array();
	foreach ( $vip_levels as $index => $info ) {
		$level = (int) $index;
		if ( $level < 1 ) {
			continue;
		}
		$rules[ 'vip:' . $level ] = sprintf(
			/* translators: %s: membership level name */
			__( '%s 及以上会员', 'aurora' ),
			isset( $info['name'] ) ? $info['name'] : 'VIP ' . $level
		);
	}

	return apply_filters( 'aurora_cat_access_rules', $rules );
}

/**
 * The rule in force for a term, walking up the hierarchy.
 *
 * Inheritance matters: a parent category marked "members only" should cover
 * the subcategories nobody remembered to mark, otherwise the restriction is
 * defeated by filing an article one level deeper.
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy.
 * @return string Rule, or '' for public.
 */
function aurora_term_access_rule( $term_id, $taxonomy = 'category' ) {
	$term_id = (int) $term_id;
	$seen    = array();

	while ( $term_id && ! isset( $seen[ $term_id ] ) ) {
		$seen[ $term_id ] = true;

		$rule = (string) get_term_meta( $term_id, aurora_cat_access_key(), true );
		if ( '' !== $rule ) {
			return apply_filters( 'aurora_term_access_rule', $rule, $term_id, $taxonomy );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			break;
		}
		$term_id = (int) $term->parent;
	}

	return '';
}

/**
 * Does a user satisfy a rule string?
 *
 * @param string   $rule    Rule from aurora_cat_access_rules().
 * @param int|null $user_id User ID, null for the current user.
 * @return bool
 */
function aurora_user_meets_rule( $rule, $user_id = null ) {
	$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

	if ( '' === $rule ) {
		return true;
	}
	if ( ! $user_id ) {
		return false;
	}
	if ( user_can( $user_id, 'edit_others_posts' ) ) {
		return true;
	}

	if ( 'login' === $rule ) {
		return true;
	}
	if ( 'verified' === $rule ) {
		return (bool) get_user_meta( $user_id, 'aurora_verified', true );
	}

	if ( 0 === strpos( $rule, 'level:' ) ) {
		return aurora_user_site_level( $user_id ) >= (int) substr( $rule, 6 );
	}
	if ( 0 === strpos( $rule, 'vip:' ) ) {
		$vip = function_exists( 'aurora_get_user_level' ) ? (int) aurora_get_user_level( $user_id ) : 0;

		return $vip >= (int) substr( $rule, 4 );
	}

	return (bool) apply_filters( 'aurora_user_meets_rule', true, $rule, $user_id );
}

/**
 * Is a post withheld because of the categories it sits in?
 *
 * @param int|null $post_id Post ID, null for the current post.
 * @param int|null $user_id User ID, null for the current user.
 * @return bool
 */
function aurora_post_category_locked( $post_id = null, $user_id = null ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	if ( ! $post_id ) {
		return false;
	}

	$taxonomies = get_object_taxonomies( get_post_type( $post_id ), 'names' );
	foreach ( $taxonomies as $taxonomy ) {
		if ( ! is_taxonomy_viewable( $taxonomy ) ) {
			continue;
		}

		$terms = wp_get_post_terms( $post_id, $taxonomy );
		if ( is_wp_error( $terms ) ) {
			continue;
		}

		foreach ( $terms as $term ) {
			$rule = aurora_term_access_rule( $term->term_id, $taxonomy );
			if ( '' === $rule ) {
				continue;
			}
			if ( ! aurora_user_meets_rule( $rule, $user_id ) ) {
				return (bool) apply_filters( 'aurora_post_category_locked', true, $post_id, $term, $user_id );
			}
		}
	}

	return (bool) apply_filters( 'aurora_post_category_locked', false, $post_id, null, $user_id );
}

/**
 * Keep restricted posts out of the queries a visitor is allowed to run.
 *
 * Without this the archive lists the title, excerpt and featured image of an
 * article the visitor may not read — which is the leak, not the article body.
 *
 * @param WP_Query $query Query to filter.
 */
function aurora_filter_restricted_query( $query ) {
	if ( is_admin() || ! $query instanceof WP_Query ) {
		return;
	}
	if ( aurora_user_can( 'view_comment' ) && current_user_can( 'edit_others_posts' ) ) {
		return;
	}

	$excluded = aurora_restricted_term_ids();
	if ( ! $excluded ) {
		return;
	}

	$tax_query = (array) $query->get( 'tax_query' );

	$tax_query[] = array(
		'taxonomy' => 'category',
		'field'    => 'term_id',
		'terms'    => $excluded,
		'operator' => 'NOT IN',
	);

	$query->set( 'tax_query', $tax_query );
}
add_action( 'pre_get_posts', 'aurora_filter_restricted_query' );

/**
 * Cached list of category IDs whose rule the current visitor fails.
 *
 * @return array
 */
function aurora_restricted_term_ids() {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$user_id = get_current_user_id();

	if ( $user_id && user_can( $user_id, 'edit_others_posts' ) ) {
		$cache = array();
		return $cache;
	}

	$cache = array();
	$terms = get_terms( array(
		'taxonomy'   => 'category',
		'hide_empty' => false,
		'meta_query' => array(
			array(
				'key'     => aurora_cat_access_key(),
				'compare' => 'EXISTS',
			),
		),
	) );

	if ( is_wp_error( $terms ) ) {
		return $cache;
	}

	foreach ( $terms as $term ) {
		$rule = (string) get_term_meta( $term->term_id, aurora_cat_access_key(), true );
		if ( '' === $rule ) {
			continue;
		}
		if ( ! aurora_user_meets_rule( $rule, $user_id ) ) {
			$cache[] = (int) $term->term_id;
		}
	}

	return $cache;
}

/* -------------------------------------------------------------------------
 * Category rule UI
 * ---------------------------------------------------------------------- */

/**
 * Render the rule selector when editing a term.
 *
 * @param WP_Term $term Term being edited.
 */
function aurora_cat_access_edit_field( $term ) {
	$rule  = (string) get_term_meta( $term->term_id, aurora_cat_access_key(), true );
	$rules = aurora_cat_access_rules();
	?>
	<tr class="form-field">
		<th scope="row">
			<label for="aurora-cat-access"><?php esc_html_e( '查看权限', 'aurora' ); ?></label>
		</th>
		<td>
			<select name="aurora_cat_access" id="aurora-cat-access">
				<?php foreach ( $rules as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $rule, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="description">
				<?php esc_html_e( '限制后，本分类及其所有子分类下的文章只对符合条件的访客显示；列表页也会隐藏这些文章。留空表示公开。', 'aurora' ); ?>
			</p>
		</td>
	</tr>
	<?php
}

/**
 * Render the rule selector when creating a term.
 */
function aurora_cat_access_add_field() {
	?>
	<div class="form-field">
		<label for="aurora-cat-access"><?php esc_html_e( '查看权限', 'aurora' ); ?></label>
		<select name="aurora_cat_access" id="aurora-cat-access">
			<?php foreach ( aurora_cat_access_rules() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p><?php esc_html_e( '留空表示公开。子分类会继承父分类的设置。', 'aurora' ); ?></p>
	</div>
	<?php
}

/**
 * Save the rule from a term form.
 *
 * @param int $term_id Term ID.
 */
function aurora_cat_access_save( $term_id ) {
	if ( ! current_user_can( 'manage_categories' ) ) {
		return;
	}
	if ( ! isset( $_POST['aurora_cat_access'] ) ) {
		return;
	}

	// Nonces are checked by WordPress itself on both term paths (edit-tags.php
	// verifies `update-tag_{id}` before `edited_*` fires, and the add form
	// before `created_*`), so re-checking here would only duplicate that work
	// with a second, easier-to-get-wrong nonce name.

	$rule  = sanitize_key( wp_unslash( $_POST['aurora_cat_access'] ) );
	$valid = aurora_cat_access_rules();

	// 'level:2' survives sanitize_key() as 'level:2' (colons are kept), but a
	// crafted value must still match a known rule before it is stored.
	if ( '' === $rule ) {
		delete_term_meta( $term_id, aurora_cat_access_key() );
		return;
	}
	if ( isset( $valid[ $rule ] ) ) {
		update_term_meta( $term_id, aurora_cat_access_key(), $rule );
	}
}

/**
 * Wire the rule fields onto every viewable taxonomy.
 */
function aurora_cat_access_hooks() {
	foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
		add_action( "{$taxonomy}_edit_form_fields", 'aurora_cat_access_edit_field' );
		add_action( "{$taxonomy}_add_form_fields", 'aurora_cat_access_add_field' );
		add_action( "edited_{$taxonomy}", 'aurora_cat_access_save' );
		add_action( "created_{$taxonomy}", 'aurora_cat_access_save' );
	}
}
add_action( 'init', 'aurora_cat_access_hooks', 20 );

/* -------------------------------------------------------------------------
 * Admin: the matrix table
 * ---------------------------------------------------------------------- */

/**
 * Add the matrix as a page under the theme settings menu.
 */
function aurora_permissions_admin_menu() {
	add_submenu_page(
		'aurora-settings',
		__( '权限管理', 'aurora' ),
		__( '权限管理', 'aurora' ),
		'manage_options',
		'aurora-permissions',
		'aurora_permissions_page'
	);
}
add_action( 'admin_menu', 'aurora_permissions_admin_menu', 20 );

/**
 * Render the role x capability grid.
 *
 * Checkboxes are the honest control for a boolean matrix, but a matrix with
 * roles on one axis and capabilities on the other needs its header repeated on
 * every group band — otherwise an eleven-column table is unreadable and the
 * administrator ticks the wrong column.
 */
function aurora_permissions_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '权限不足。', 'aurora' ), '', array( 'response' => 403 ) );
	}

	$matrix = aurora_permission_matrix();
	$caps   = aurora_caps();
	$groups = aurora_permission_role_groups();

	$role_count = count( aurora_permission_roles() );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( '权限管理', 'aurora' ); ?></h1>
		<p class="description" style="max-width:900px">
			<?php esc_html_e( '左侧每一行是一项权限，右侧每一个身份是一列。同一个用户可能同时属于多个身份（例如「认证用户 + 黄金会员 + Lv.4」），只要其中任意一个身份勾选了该权限，就视为拥有；数值类的权限取所有身份中的最大值。管理员不受此表限制。', 'aurora' ); ?>
		</p>

		<?php if ( isset( $_GET['aurora-saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '权限已保存。', 'aurora' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aurora_save_permissions">
			<?php wp_nonce_field( 'aurora_save_permissions' ); ?>

			<div style="overflow-x:auto;margin-top:14px">
				<table class="widefat striped" style="min-width:<?php echo (int) ( 240 + $role_count * 78 ); ?>px">
					<thead>
						<tr>
							<th style="min-width:200px"><?php esc_html_e( '权限', 'aurora' ); ?></th>
							<?php foreach ( $groups as $group_key => $roles ) : ?>
								<th colspan="<?php echo (int) count( $roles ); ?>" style="text-align:center;border-left:1px solid #dcdcde">
									<?php echo esc_html( aurora_permission_group_label( $group_key ) ); ?>
								</th>
							<?php endforeach; ?>
						</tr>
						<tr>
							<th></th>
							<?php foreach ( $groups as $roles ) : ?>
								<?php foreach ( $roles as $role ) : ?>
									<th style="text-align:center;font-weight:500"><?php echo esc_html( $role['label'] ); ?></th>
								<?php endforeach; ?>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $caps as $cap => $info ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $info['label'] ); ?></strong>
									<?php if ( ! empty( $info['unit'] ) ) : ?>
										<span style="color:#646970">（<?php echo esc_html( $info['unit'] ); ?>）</span>
									<?php endif; ?>
									<div style="color:#646970;font-size:11px"><?php echo esc_html( $cap ); ?></div>
								</td>
								<?php foreach ( $groups as $roles ) : ?>
									<?php foreach ( $roles as $role ) : ?>
										<?php $value = isset( $matrix[ $role['key'] ][ $cap ] ) ? $matrix[ $role['key'] ][ $cap ] : null; ?>
										<td style="text-align:center;vertical-align:middle">
											<?php aurora_permissions_cell( $role['key'], $cap, $info, $value ); ?>
										</td>
									<?php endforeach; ?>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<p style="margin-top:16px">
				<button type="submit" class="button button-primary"><?php esc_html_e( '保存权限', 'aurora' ); ?></button>
				<button type="submit" class="button" name="aurora_reset" value="1"
					onclick="return confirm('<?php echo esc_js( __( '恢复为默认权限？当前设置会被覆盖。', 'aurora' ) ); ?>')">
					<?php esc_html_e( '恢复默认', 'aurora' ); ?>
				</button>
			</p>
		</form>

		<h2><?php esc_html_e( '分类查看权限', 'aurora' ); ?></h2>
		<p class="description" style="max-width:900px">
			<?php esc_html_e( '在「文章 → 分类」里编辑任意分类，即可设置该分类的查看权限；子分类自动继承父分类的设置，列表页也会隐藏无权查看的文章。', 'aurora' ); ?>
			<?php if ( current_user_can( 'manage_categories' ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=category' ) ); ?>"><?php esc_html_e( '去设置分类权限 →', 'aurora' ); ?></a>
			<?php endif; ?>
		</p>
	</div>
	<?php
}

/**
 * Human label for a role group band.
 *
 * @param string $group Group key.
 * @return string
 */
function aurora_permission_group_label( $group ) {
	$labels = array(
		'base'  => __( '基础身份', 'aurora' ),
		'level' => __( '用户等级（经验值）', 'aurora' ),
		'vip'   => __( '会员等级', 'aurora' ),
	);

	return isset( $labels[ $group ] ) ? $labels[ $group ] : $group;
}

/**
 * One cell of the matrix, typed to the capability.
 *
 * @param string $role  Role key.
 * @param string $cap   Capability key.
 * @param array  $info  Capability definition.
 * @param mixed  $value Current value.
 */
function aurora_permissions_cell( $role, $cap, $info, $value ) {
	$name = 'aurora_perm[' . $role . '][' . $cap . ']';

	switch ( $info['type'] ) {
		case 'int':
			printf(
				'<input type="number" min="0" max="100000" step="1" style="width:70px" name="%s" value="%s">',
				esc_attr( $name ),
				esc_attr( (int) $value )
			);
			break;

		case 'list':
			$list = is_array( $value ) ? implode( ',', $value ) : (string) $value;
			printf(
				'<input type="text" style="width:190px" name="%s" value="%s" placeholder="jpg,png,zip">',
				esc_attr( $name ),
				esc_attr( $list )
			);
			break;

		default:
			printf(
				'<input type="checkbox" name="%s" value="1" %s>',
				esc_attr( $name ),
				checked( (bool) $value, true, false )
			);
	}
}

/**
 * Persist the matrix.
 *
 * Every role and capability is written on each save, including the ones the
 * browser did not submit: an unchecked checkbox is absent from $_POST, and
 * treating absence as "leave it" would make it impossible to revoke anything.
 */
function aurora_handle_permissions_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die(
			esc_html__( '权限不足。', 'aurora' ),
			esc_html__( '权限不足', 'aurora' ),
			array( 'response' => 403 )
		);
	}
	check_admin_referer( 'aurora_save_permissions' );

	if ( ! empty( $_POST['aurora_reset'] ) ) {
		delete_option( 'aurora_permission_matrix' );
		wp_safe_redirect( add_query_arg( 'aurora-saved', '1', admin_url( 'admin.php?page=aurora-permissions' ) ) );
		exit;
	}

	$posted = isset( $_POST['aurora_perm'] ) ? (array) wp_unslash( $_POST['aurora_perm'] ) : array();
	$caps   = aurora_caps();
	$matrix = array();

	foreach ( array_keys( aurora_permission_roles() ) as $role ) {
		foreach ( $caps as $cap => $info ) {
			$raw = isset( $posted[ $role ][ $cap ] ) ? $posted[ $role ][ $cap ] : null;

			switch ( $info['type'] ) {
				case 'int':
					$matrix[ $role ][ $cap ] = max( 0, min( 100000, (int) $raw ) );
					break;

				case 'list':
					$matrix[ $role ][ $cap ] = aurora_sanitize_ext_list( $raw );
					break;

				default:
					$matrix[ $role ][ $cap ] = ! empty( $raw );
			}
		}
	}

	aurora_permission_matrix_save( $matrix );

	wp_safe_redirect( add_query_arg( 'aurora-saved', '1', admin_url( 'admin.php?page=aurora-permissions' ) ) );
	exit;
}
add_action( 'admin_post_aurora_save_permissions', 'aurora_handle_permissions_save' );

/**
 * Normalise a comma separated extension list.
 *
 * The result ends up in a filter that decides what a role may upload, so a
 * token that is not a plain extension has no business being stored.
 *
 * @param mixed $raw Raw input.
 * @return array
 */
function aurora_sanitize_ext_list( $raw ) {
	$raw = is_array( $raw ) ? implode( ',', $raw ) : (string) $raw;
	$out = array();

	foreach ( preg_split( '/[^a-z0-9]+/i', $raw ) as $token ) {
		if ( '' === $token || strlen( $token ) > 10 ) {
			continue;
		}
		$out[] = strtolower( $token );
	}

	return array_values( array_unique( $out ) );
}
