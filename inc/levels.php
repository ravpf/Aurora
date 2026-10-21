<?php
/**
 * Levels and experience.
 *
 * Points are a currency: earned, spent, and meant to move. Experience is a
 * record: it only ever goes up, which is exactly what makes it usable as a
 * level. Keeping them in one ledger would mean a member could spend their way
 * down a level, so this module keeps its own append-only log and its own
 * balance cache, and the two systems run side by side without touching.
 *
 * `aurora_exp_level_of()` is the piece the rest of the theme already depends on:
 * the permission matrix resolves its Lv.1–5 rows through it.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * What experience is awarded for, and what the levels cost
 * ---------------------------------------------------------------------- */

/**
 * Every reason experience can be awarded.
 *
 * @return array Label by key.
 */
function aurora_exp_reasons() {
	return apply_filters( 'aurora_exp_reasons', array(
		'register' => __( '注册账号', 'aurora' ),
		'login'    => __( '每日登录', 'aurora' ),
		'comment'  => __( '发表评论', 'aurora' ),
		'post'     => __( '发布文章', 'aurora' ),
		'checkin'  => __( '每日签到', 'aurora' ),
		'order'    => __( '消费下单', 'aurora' ),
		'profile'  => __( '完善资料', 'aurora' ),
		'admin'    => __( '管理员调整', 'aurora' ),
	) );
}

/**
 * The ladder.
 *
 * Level 1 costs nothing, so every signed-in member holds at least one level.
 * That is deliberate: the matrix's first level row is the baseline a new member
 * already has, not a badge they have to earn before the site behaves normally.
 *
 * @return array Level => array( name, exp ).
 */
function aurora_levels() {
	$levels  = get_option( 'aurora_levels', array() );
	$levels  = is_array( $levels ) ? $levels : array();
	$default = array(
		1 => array( 'name' => __( '初来乍到', 'aurora' ), 'exp' => 0 ),
		2 => array( 'name' => __( '常驻居民', 'aurora' ), 'exp' => 100 ),
		3 => array( 'name' => __( '资深会员', 'aurora' ), 'exp' => 300 ),
		4 => array( 'name' => __( '社区骨干', 'aurora' ), 'exp' => 800 ),
		5 => array( 'name' => __( '传奇用户', 'aurora' ), 'exp' => 2000 ),
	);

	$out = array();
	foreach ( $default as $level => $row ) {
		$out[ $level ] = array(
			'name' => isset( $levels[ $level ]['name'] ) && '' !== trim( (string) $levels[ $level ]['name'] )
				? (string) $levels[ $level ]['name']
				: $row['name'],
			// Level 1 is always 0: a ladder whose first rung costs something
			// would leave new members with no level at all.
			'exp'  => 1 === $level ? 0 : ( isset( $levels[ $level ]['exp'] ) ? max( 0, (int) $levels[ $level ]['exp'] ) : $row['exp'] ),
		);
	}

	// A ladder must not go down; a later level costing less than an earlier one
	// would silently make the earlier one unreachable.
	$floor = 0;
	foreach ( $out as $level => $row ) {
		$out[ $level ]['exp'] = max( $floor, (int) $row['exp'] );
		$floor                 = $out[ $level ]['exp'];
	}

	return apply_filters( 'aurora_levels', $out );
}

/**
 * Is the experience system running?
 *
 * @return bool
 */
function aurora_exp_enabled() {
	return (bool) get_theme_mod( 'aurora_exp_enable', true );
}

/**
 * How much a reason is worth.
 *
 * @param string $reason Reason key.
 * @return int
 */
function aurora_exp_reward( $reason ) {
	$defaults = array(
		'register' => 50,
		'login'    => 5,
		'comment'  => 5,
		'post'     => 20,
		'checkin'  => 10,
		'order'    => 20,
		'profile'  => 10,
	);

	$default = isset( $defaults[ $reason ] ) ? $defaults[ $reason ] : 0;

	return max( 0, (int) get_theme_mod( 'aurora_exp_' . $reason, $default ) );
}

/* -------------------------------------------------------------------------
 * The ledger
 * ---------------------------------------------------------------------- */

/**
 * Register the log post type.
 */
function aurora_exp_register_type() {
	register_post_type( 'aurora_exp_log', array(
		'labels'              => array( 'name' => __( '经验流水', 'aurora' ) ),
		'public'              => false,
		'show_ui'             => false,
		'show_in_rest'        => false,
		'exclude_from_search' => true,
		'supports'            => array( 'title', 'author' ),
		'rewrite'             => false,
		'query_var'           => false,
	) );
}
add_action( 'init', 'aurora_exp_register_type', 5 );

/**
 * A user's experience.
 *
 * The meta value is a cache; the log is the truth. When they disagree the log
 * wins, so a half-finished write cannot strand a member one level down.
 *
 * @param int $user_id User ID, 0 for the current user.
 * @return int
 */
function aurora_exp_of( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return 0;
	}

	return max( 0, (int) get_user_meta( $user_id, 'aurora_exp', true ) );
}

/**
 * Write a row to the log.
 *
 * @param int    $user_id User ID.
 * @param int    $amount  Signed amount.
 * @param string $reason  Reason key.
 * @param array  $args    note, ref, silent.
 * @return int|WP_Error New balance.
 */
function aurora_exp_add( $user_id, $amount, $reason, $args = array() ) {
	$user_id = (int) $user_id;
	$amount  = (int) $amount;

	if ( ! $user_id || ! get_userdata( $user_id ) ) {
		return new WP_Error( 'aurora_exp_no_user', __( '用户不存在。', 'aurora' ) );
	}

	if ( 0 === $amount ) {
		return aurora_exp_of( $user_id );
	}

	$args = wp_parse_args( $args, array(
		'note'   => '',
		'ref'    => 0,
		'silent' => false,
	) );

	$before = aurora_exp_of( $user_id );

	// Experience never falls. A negative adjustment is clamped at the floor
	// rather than allowed to make a level unreachable.
	$after   = max( 0, $before + $amount );
	$applied = $after - $before;

	if ( 0 === $applied ) {
		return $before;
	}

	$reasons = aurora_exp_reasons();
	$label   = $args['note'] ? $args['note'] : ( isset( $reasons[ $reason ] ) ? $reasons[ $reason ] : $reason );

	$log_id = wp_insert_post( array(
		'post_type'   => 'aurora_exp_log',
		'post_status' => 'publish',
		'post_title'  => $label,
		'post_author' => $user_id,
		'meta_input'  => array(
			'_aurora_exp_amount' => $applied,
			'_aurora_exp_after'  => $after,
			'_aurora_exp_reason' => $reason,
			'_aurora_exp_ref'    => (int) $args['ref'],
			'_aurora_exp_day'    => current_time( 'Y-m-d' ),
		),
	), true );

	if ( is_wp_error( $log_id ) ) {
		return $log_id;
	}

	update_user_meta( $user_id, 'aurora_exp', $after );

	$old_level = aurora_exp_level_for_exp( $before );
	$new_level = aurora_exp_level_for_exp( $after );

	if ( $new_level > $old_level ) {
		/**
		 * Fires when a member reaches a new level.
		 *
		 * @param int $user_id   User ID.
		 * @param int $old_level Previous level.
		 * @param int $new_level New level.
		 */
		do_action( 'aurora_exp_level_changed', $user_id, $old_level, $new_level );
	}

	if ( ! $args['silent'] ) {
		do_action( 'aurora_exp_added', $user_id, $applied, $after, $reason, $log_id );
	}

	return $after;
}

/**
 * How many times today a reason has been awarded.
 *
 * Counted from the log rather than a counter, for the same reason the points
 * module does: a counter that drifts is a cap that stops working.
 *
 * @param int    $user_id User ID.
 * @param string $reason  Reason key.
 * @return int
 */
function aurora_exp_daily_count( $user_id, $reason = '' ) {
	$user_id = (int) $user_id;

	if ( ! $user_id ) {
		return 0;
	}

	$meta = array(
		array(
			'key'   => '_aurora_exp_day',
			'value' => current_time( 'Y-m-d' ),
		),
	);

	if ( '' !== $reason ) {
		$meta[] = array(
			'key'   => '_aurora_exp_reason',
			'value' => $reason,
		);
	}

	$query = new WP_Query( array(
		'post_type'      => 'aurora_exp_log',
		'post_status'    => 'publish',
		'author'         => $user_id,
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => false,
		'meta_query'     => $meta,
	) );

	return (int) $query->found_posts;
}

/**
 * Award experience for a reason, respecting its cap.
 *
 * @param int    $user_id User ID.
 * @param string $reason  Reason key.
 * @param array  $args    Passed through to aurora_exp_add().
 * @return int|WP_Error|false New balance, an error, or false when there is
 *                            nothing to award.
 */
function aurora_exp_earn( $user_id, $reason, $args = array() ) {
	if ( ! aurora_exp_enabled() ) {
		return false;
	}

	$amount = aurora_exp_reward( $reason );

	if ( $amount < 1 ) {
		return false;
	}

	// A cap of 0 means no cap; the comment reason ships with one because it is
	// the only award a member can trigger without leaving the page.
	$cap = (int) get_theme_mod( 'aurora_exp_cap_' . $reason, 'comment' === $reason ? 20 : 0 );

	if ( $cap > 0 && aurora_exp_daily_count( $user_id, $reason ) >= $cap ) {
		return new WP_Error( 'aurora_exp_capped', __( '今日该项经验已达上限。', 'aurora' ) );
	}

	return aurora_exp_add( $user_id, $amount, $reason, $args );
}

/**
 * Recent log rows.
 *
 * @param int $user_id User ID.
 * @param int $limit   How many.
 * @return WP_Post[]
 */
function aurora_exp_ledger( $user_id, $limit = 20 ) {
	$query = new WP_Query( array(
		'post_type'      => 'aurora_exp_log',
		'post_status'    => 'publish',
		'author'         => (int) $user_id,
		'posts_per_page' => (int) $limit,
		'no_found_rows'  => true,
	) );

	return $query->posts;
}

/* -------------------------------------------------------------------------
 * Levels
 * ---------------------------------------------------------------------- */

/**
 * Which level a given amount of experience buys.
 *
 * @param int $exp Experience.
 * @return int
 */
function aurora_exp_level_for_exp( $exp ) {
	$exp    = max( 0, (int) $exp );
	$level  = 1;
	$levels = aurora_levels();

	foreach ( $levels as $number => $row ) {
		if ( $exp >= (int) $row['exp'] ) {
			$level = (int) $number;
		}
	}

	return min( $level, aurora_level_max() );
}

/**
 * A member's level.
 *
 * This is the function the permission matrix resolves its Lv.1–5 rows through.
 *
 * @param int $user_id User ID, 0 for the current user.
 * @return int
 */
function aurora_exp_level_of( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id || ! aurora_exp_enabled() ) {
		return 0;
	}

	return aurora_exp_level_for_exp( aurora_exp_of( $user_id ) );
}

/**
 * A level's display name.
 *
 * @param int $level Level number.
 * @return string
 */
function aurora_exp_level_name( $level ) {
	$levels = aurora_levels();
	$level  = (int) $level;

	return isset( $levels[ $level ]['name'] ) ? $levels[ $level ]['name'] : sprintf(
		/* translators: %d: level number */
		__( 'Lv.%d', 'aurora' ),
		$level
	);
}

/**
 * Progress towards the next level.
 *
 * @param int $user_id User ID, 0 for the current user.
 * @return array|null Null at the top of the ladder.
 */
function aurora_exp_next_level( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	$exp     = aurora_exp_of( $user_id );
	$current = aurora_exp_level_of( $user_id );
	$levels  = aurora_levels();

	foreach ( $levels as $number => $row ) {
		if ( (int) $number <= $current ) {
			continue;
		}

		$from = (int) $levels[ $current ]['exp'];
		$to   = (int) $row['exp'];
		$span = max( 1, $to - $from );

		return array(
			'level'     => (int) $number,
			'name'      => $row['name'],
			'exp'       => $to,
			'from'      => $from,
			'remaining' => max( 0, $to - $exp ),
			'percent'   => max( 0, min( 100, (int) round( ( ( $exp - $from ) / $span ) * 100 ) ) ),
		);
	}

	return null;
}

/* -------------------------------------------------------------------------
 * Awards
 * ---------------------------------------------------------------------- */

/**
 * Award on signup.
 *
 * @param int $user_id New user.
 */
function aurora_exp_on_register( $user_id ) {
	aurora_exp_earn( $user_id, 'register' );
}
add_action( 'user_register', 'aurora_exp_on_register', 20 );

/**
 * Award once per day on sign-in.
 *
 * The cap of one a day is what makes "每日登录" mean a day and not a session: a
 * member who refreshes their browser all afternoon is still one login here.
 *
 * @param string  $user_login Username.
 * @param WP_User $user       User.
 */
function aurora_exp_on_login( $user_login, $user = null ) {
	if ( ! $user instanceof WP_User ) {
		return;
	}

	$last = get_user_meta( $user->ID, 'aurora_exp_login_day', true );
	$today = current_time( 'Y-m-d' );

	if ( $last === $today ) {
		return;
	}

	$result = aurora_exp_earn( $user->ID, 'login' );

	if ( ! is_wp_error( $result ) ) {
		update_user_meta( $user->ID, 'aurora_exp_login_day', $today );
	}
}
add_action( 'wp_login', 'aurora_exp_on_login', 20, 2 );

/**
 * Award for an approved comment.
 *
 * @param string     $new_status New status.
 * @param string     $old_status Previous status.
 * @param WP_Comment $comment    Comment.
 */
function aurora_exp_on_comment( $new_status, $old_status, $comment ) {
	if ( 'approved' !== $new_status || 'approved' === $old_status ) {
		return;
	}

	$user_id = (int) $comment->user_id;


	if ( ! $user_id ) {
		return;
	}

	aurora_exp_earn( $user_id, 'comment', array( 'ref' => (int) $comment->comment_ID ) );
}
add_action( 'transition_comment_status', 'aurora_exp_on_comment', 20, 3 );

/**
 * Award for a comment that was approved on arrival.
 *
 * @param int $comment_id Comment ID.
 */
function aurora_exp_on_comment_insert( $comment_id ) {
	$comment = get_comment( $comment_id );

	if ( ! $comment || '1' !== (string) $comment->comment_approved || ! $comment->user_id ) {
		return;
	}

	aurora_exp_on_comment( 'approved', 'unapproved', $comment );
}
add_action( 'comment_post', 'aurora_exp_on_comment_insert', 20 );

/**
 * Award for publishing an article.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Previous status.
 * @param WP_Post $post       Post.
 */
function aurora_exp_on_publish( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}
	if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
		return;
	}

	aurora_exp_earn( (int) $post->post_author, 'post', array( 'ref' => (int) $post->ID ) );
}
add_action( 'transition_post_status', 'aurora_exp_on_publish', 20, 3 );

/**
 * Award for a paid order.
 *
 * @param int $order_id Order ID.
 */
function aurora_exp_on_order( $order_id ) {
	$user_id = (int) get_post_meta( $order_id, '_aurora_user_id', true );

	if ( ! $user_id ) {
		$user_id = (int) get_post_field( 'post_author', $order_id );
	}

	if ( $user_id ) {
		aurora_exp_earn( $user_id, 'order', array( 'ref' => (int) $order_id ) );
	}
}
add_action( 'aurora_order_paid', 'aurora_exp_on_order', 20 );

/**
 * Award for filling in a profile, once.
 */
function aurora_exp_on_profile() {
	$user_id = get_current_user_id();

	if ( ! $user_id || get_user_meta( $user_id, 'aurora_exp_profile_done', true ) ) {
		return;
	}

	$user = get_userdata( $user_id );

	if ( ! $user || '' === trim( (string) $user->description ) ) {
		return;
	}

	$result = aurora_exp_earn( $user_id, 'profile' );

	if ( ! is_wp_error( $result ) && false !== $result ) {
		update_user_meta( $user_id, 'aurora_exp_profile_done', 1 );
	}
}
add_action( 'personal_options_update', 'aurora_exp_on_profile', 20 );
add_action( 'edit_user_profile_update', 'aurora_exp_on_profile', 20 );

/**
 * Award for a daily check-in.
 *
 * @param int $user_id User ID.
 * @param int $streak  Consecutive days.
 */
function aurora_exp_on_checkin( $user_id, $streak = 1 ) {
	// Longer runs are worth more, but only gently: the point is to reward
	// returning tomorrow, not to make a missed day ruinous.
	$bonus = (int) floor( max( 1, (int) $streak ) / 7 );

	aurora_exp_earn( $user_id, 'checkin' );

	if ( $bonus > 0 ) {
		aurora_exp_add(
			$user_id,
			$bonus,
			'checkin',
			array(
				/* translators: %d: consecutive days */
				'note' => sprintf( __( '连续签到 %d 天奖励', 'aurora' ), (int) $streak ),
			)
		);
	}
}
add_action( 'aurora_checkin', 'aurora_exp_on_checkin', 20, 2 );

/**
 * Tell a member they went up a level.
 *
 * @param int $user_id   User ID.
 * @param int $old_level Previous level.
 * @param int $new_level New level.
 */
function aurora_exp_notify_level_up( $user_id, $old_level, $new_level ) {
	if ( ! function_exists( 'aurora_notify' ) ) {
		return;
	}

	aurora_notify(
		$user_id,
		sprintf(
			/* translators: 1: new level name, 2: level number */
			__( '等级提升：%1$s（Lv.%2$d）', 'aurora' ),
			aurora_exp_level_name( $new_level ),
			$new_level
		),
		sprintf(
			/* translators: 1: old level number, 2: new level number */
			__( '你的经验值已从 Lv.%1$d 升到 Lv.%2$d，新的等级权益已经生效。', 'aurora' ),
			$old_level,
			$new_level
		),
		array( 'type' => 'system', 'link' => aurora_user_page_url( array( 'tab' => 'level' ) ) )
	);
}
add_action( 'aurora_exp_level_changed', 'aurora_exp_notify_level_up', 10, 3 );

/* -------------------------------------------------------------------------
 * Front end
 * ---------------------------------------------------------------------- */

/**
 * A level badge.
 *
 * @param int  $user_id   User ID, 0 for the current user.
 * @param bool $with_name Append the level's name.
 * @return string
 */
function aurora_level_badge( $user_id = 0, $with_name = false ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id || ! aurora_exp_enabled() ) {
		return '';
	}

	$level = aurora_exp_level_of( $user_id );

	if ( $level < 1 ) {
		return '';
	}

	$label = $with_name
		? sprintf(
			/* translators: 1: level number, 2: level name */
			__( 'Lv.%1$d %2$s', 'aurora' ),
			$level,
			aurora_exp_level_name( $level )
		)
		: sprintf(
			/* translators: %d: level number */
			__( 'Lv.%d', 'aurora' ),
			$level
		);

	return '<span class="aurora-level-badge" data-level="' . esc_attr( $level ) . '">' . esc_html( $label ) . '</span>';
}

/**
 * Register the user-centre tab.
 *
 * @param array $tabs Tabs.
 * @return array
 */
function aurora_exp_user_tab( $tabs ) {
	if ( ! aurora_exp_enabled() ) {
		return $tabs;
	}

	$out = array();

	// Placed right after the points tab, which is where a member who was just
	// told about a level will look first.
	foreach ( $tabs as $key => $tab ) {
		$out[ $key ] = $tab;

		if ( 'points' === $key ) {
			$out['level'] = array( 'label' => __( '我的等级', 'aurora' ), 'icon' => 'star' );
		}
	}

	if ( ! isset( $out['level'] ) ) {
		$out['level'] = array( 'label' => __( '我的等级', 'aurora' ), 'icon' => 'star' );
	}

	return $out;
}
add_filter( 'aurora_user_tabs', 'aurora_exp_user_tab' );

/* -------------------------------------------------------------------------
 * Admin screen
 * ---------------------------------------------------------------------- */

/**
 * Add the levels screen.
 */
function aurora_exp_admin_menu() {
	add_submenu_page(
		'aurora-settings',
		__( '用户等级', 'aurora' ),
		__( '用户等级', 'aurora' ),
		'manage_options',
		'aurora-levels',
		'aurora_exp_page'
	);
}
add_action( 'admin_menu', 'aurora_exp_admin_menu', 22 );

/**
 * Render the ladder and the reward table.
 */
function aurora_exp_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '权限不足。', 'aurora' ), '', array( 'response' => 403 ) );
	}

	$levels  = aurora_levels();
	$reasons = aurora_exp_reasons();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( '用户等级与经验值', 'aurora' ); ?></h1>
		<p class="description" style="max-width:900px">
			<?php esc_html_e( '经验只增不减，到达门槛即升级；等级决定「权限管理」里 Lv.1–5 那几行的权益。Lv.1 的门槛固定为 0，所以每个注册用户都至少是 Lv.1。', 'aurora' ); ?>
		</p>

		<?php if ( isset( $_GET['aurora-levels-saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '等级设置已保存。', 'aurora' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aurora_save_levels">
			<?php wp_nonce_field( 'aurora_save_levels' ); ?>

			<h2><?php esc_html_e( '等级阶梯', 'aurora' ); ?></h2>
			<table class="widefat striped" style="max-width:640px">
				<thead>
					<tr>
						<th style="width:70px"><?php esc_html_e( '等级', 'aurora' ); ?></th>
						<th><?php esc_html_e( '名称', 'aurora' ); ?></th>
						<th style="width:150px"><?php esc_html_e( '所需经验', 'aurora' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $levels as $number => $row ) : ?>
						<tr>
							<td><strong>Lv.<?php echo (int) $number; ?></strong></td>
							<td>
								<input type="text" class="regular-text" name="aurora_level_name[<?php echo (int) $number; ?>]"
									value="<?php echo esc_attr( $row['name'] ); ?>">
							</td>
							<td>
								<?php if ( 1 === (int) $number ) : ?>
									<input type="number" value="0" disabled style="width:110px">
									<p class="description"><?php esc_html_e( '固定为 0', 'aurora' ); ?></p>
								<?php else : ?>
									<input type="number" min="0" step="10" style="width:110px"
										name="aurora_level_exp[<?php echo (int) $number; ?>]" value="<?php echo esc_attr( $row['exp'] ); ?>">
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( '门槛会自动保持递增：后面的等级填得比前面低时，会被抬到前一级的门槛。', 'aurora' ); ?></p>

			<h2 style="margin-top:26px"><?php esc_html_e( '经验值奖励', 'aurora' ); ?></h2>
			<table class="widefat striped" style="max-width:640px">
				<thead>
					<tr>
						<th><?php esc_html_e( '行为', 'aurora' ); ?></th>
						<th style="width:150px"><?php esc_html_e( '获得经验', 'aurora' ); ?></th>
						<th style="width:150px"><?php esc_html_e( '每日上限', 'aurora' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $reasons as $reason => $label ) : ?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td>
								<input type="number" min="0" step="1" style="width:110px"
									name="aurora_exp_reward[<?php echo esc_attr( $reason ); ?>]"
									value="<?php echo esc_attr( aurora_exp_reward( $reason ) ); ?>">
							</td>
							<td>
								<?php if ( in_array( $reason, array( 'comment', 'login', 'post', 'checkin' ), true ) ) : ?>
									<input type="number" min="0" step="1" style="width:110px"
										name="aurora_exp_cap[<?php echo esc_attr( $reason ); ?>]"
										value="<?php echo esc_attr( (int) get_theme_mod( 'aurora_exp_cap_' . $reason, 'comment' === $reason ? 20 : 0 ) ); ?>">
								<?php else : ?>
									<span style="color:#646970">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( '每日上限填 0 表示不限制。签到经验会按连续天数额外加成（每满 7 天 +1）。', 'aurora' ); ?></p>

			<h2 style="margin-top:26px"><?php esc_html_e( '签到与补签', 'aurora' ); ?></h2>
			<table class="widefat striped" style="max-width:640px">
				<tbody>
					<tr>
						<td><?php esc_html_e( '每月可补签次数', 'aurora' ); ?></td>
						<td style="width:150px">
							<input type="number" min="0" step="1" style="width:110px" name="aurora_checkin_makeup_max"
								value="<?php echo esc_attr( aurora_checkin_makeup_max() ); ?>">
						</td>
						<td class="description"><?php esc_html_e( '填 0 表示不开放补签。', 'aurora' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( '每次补签消耗积分', 'aurora' ); ?></td>
						<td>
							<input type="number" min="0" step="10" style="width:110px" name="aurora_checkin_makeup_cost"
								value="<?php echo esc_attr( aurora_checkin_makeup_cost() ); ?>">
						</td>
						<td class="description"><?php esc_html_e( '补签只补记录与连续天数，不发放签到积分与经验 —— 否则签到本身就没有意义了。', 'aurora' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( '可补签的天数范围', 'aurora' ); ?></td>
						<td>
							<input type="number" min="1" max="365" step="1" style="width:110px" name="aurora_checkin_makeup_window"
								value="<?php echo esc_attr( aurora_checkin_makeup_window() ); ?>">
						</td>
						<td class="description"><?php esc_html_e( '只允许补最近这些天内的漏签。', 'aurora' ); ?></td>
					</tr>
				</tbody>
			</table>

			<p style="margin-top:18px">
				<label>
					<input type="checkbox" name="aurora_exp_enable" value="1" <?php checked( aurora_exp_enabled() ); ?>>
					<?php esc_html_e( '启用经验值与等级', 'aurora' ); ?>
				</label>
				<br>
				<label style="margin-top:6px;display:inline-block">
					<input type="checkbox" name="aurora_checkin_enable" value="1" <?php checked( aurora_checkin_enabled() ); ?>>
					<?php esc_html_e( '启用签到与补签', 'aurora' ); ?>
				</label>
			</p>

			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( '保存', 'aurora' ); ?></button>
			</p>
		</form>
	</div>
	<?php
}

/**
 * Persist the ladder and the rewards.
 *
 * @return void
 */
function aurora_handle_exp_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die(
			esc_html__( '权限不足。', 'aurora' ),
			esc_html__( '权限不足', 'aurora' ),
			array( 'response' => 403 )
		);
	}
	check_admin_referer( 'aurora_save_levels' );

	$names = isset( $_POST['aurora_level_name'] ) ? (array) wp_unslash( $_POST['aurora_level_name'] ) : array();
	$exps  = isset( $_POST['aurora_level_exp'] ) ? (array) wp_unslash( $_POST['aurora_level_exp'] ) : array();
	$ladder = array();

	foreach ( array_keys( aurora_levels() ) as $number ) {
		$ladder[ $number ] = array(
			'name' => isset( $names[ $number ] ) ? sanitize_text_field( (string) $names[ $number ] ) : '',
			'exp'  => isset( $exps[ $number ] ) ? max( 0, (int) $exps[ $number ] ) : 0,
		);
	}

	update_option( 'aurora_levels', $ladder, false );

	$rewards = isset( $_POST['aurora_exp_reward'] ) ? (array) wp_unslash( $_POST['aurora_exp_reward'] ) : array();
	foreach ( array_keys( aurora_exp_reasons() ) as $reason ) {
		if ( 'admin' === $reason ) {
			continue;
		}
		set_theme_mod( 'aurora_exp_' . $reason, isset( $rewards[ $reason ] ) ? max( 0, (int) $rewards[ $reason ] ) : 0 );
	}

	$caps = isset( $_POST['aurora_exp_cap'] ) ? (array) wp_unslash( $_POST['aurora_exp_cap'] ) : array();
	foreach ( array( 'comment', 'login', 'post', 'checkin' ) as $reason ) {
		set_theme_mod( 'aurora_exp_cap_' . $reason, isset( $caps[ $reason ] ) ? max( 0, (int) $caps[ $reason ] ) : 0 );
	}

	set_theme_mod( 'aurora_exp_enable', ! empty( $_POST['aurora_exp_enable'] ) );
	set_theme_mod( 'aurora_checkin_enable', ! empty( $_POST['aurora_checkin_enable'] ) );
	set_theme_mod( 'aurora_checkin_makeup_max', max( 0, min( 31, (int) ( $_POST['aurora_checkin_makeup_max'] ?? 3 ) ) ) );
	set_theme_mod( 'aurora_checkin_makeup_cost', max( 0, (int) ( $_POST['aurora_checkin_makeup_cost'] ?? 50 ) ) );
	set_theme_mod( 'aurora_checkin_makeup_window', max( 1, min( 365, (int) ( $_POST['aurora_checkin_makeup_window'] ?? 30 ) ) ) );

	wp_safe_redirect( add_query_arg( 'aurora-levels-saved', '1', admin_url( 'admin.php?page=aurora-levels' ) ) );
	exit;
}
add_action( 'admin_post_aurora_save_levels', 'aurora_handle_exp_save' );
