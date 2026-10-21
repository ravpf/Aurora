<?php
/**
 * Rewards — readers tip the author with points.
 *
 * Cash tipping would need its own order flow, refunds and settlement; points
 * already have a ledger, a balance and a spend path, so a tip is a transfer
 * between two accounts. That keeps the feature honest: every tip is two ledger
 * rows, and the article keeps a bounded record of who tipped.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is tipping open?
 *
 * @return bool
 */
function aurora_reward_enabled() {
	return (bool) get_theme_mod( 'aurora_reward_enabled', true ) && ( ! function_exists( 'aurora_points_enabled' ) || aurora_points_enabled() );
}

/**
 * The preset amounts, lowest first.
 *
 * @return int[]
 */
function aurora_reward_amounts() {
	$raw = (string) get_theme_mod( 'aurora_reward_amounts', '5,10,20,50' );
	$out = array();

	foreach ( explode( ',', $raw ) as $piece ) {
		$n = (int) trim( $piece );
		if ( $n > 0 && $n <= 5000 ) {
			$out[] = $n;
		}
	}

	$out = array_values( array_unique( $out ) );
	sort( $out );

	if ( ! $out ) {
		$out = array( 5, 10, 20, 50 );
	}

	return apply_filters( 'aurora_reward_amounts', $out );
}

/**
 * Totals for one article.
 *
 * @param int $post_id Post.
 * @return array{total:int,count:int,recent:array}
 */
function aurora_reward_stats( $post_id ) {
	$post_id = (int) $post_id;

	return array(
		'total'  => (int) get_post_meta( $post_id, '_aurora_tip_total', true ),
		'count'  => (int) get_post_meta( $post_id, '_aurora_tip_count', true ),
		'recent' => (array) get_post_meta( $post_id, '_aurora_tip_recent', true ),
	);
}

/**
 * The reward block under an article.
 */
function aurora_tip_block() {
	if ( ! is_singular( 'post' ) || ! aurora_reward_enabled() ) {
		return;
	}

	$post    = get_post();
	$author  = (int) $post->post_author;
	$stats   = aurora_reward_stats( $post->ID );
	$logged  = is_user_logged_in();
	$is_self = $logged && get_current_user_id() === $author;
	?>
	<section class="aurora-tip" data-post="<?php echo esc_attr( (string) $post->ID ); ?>"
		data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'aurora_nonce' ) ); ?>">
		<div class="aurora-tip__head">
			<h3 class="aurora-tip__title"><?php aurora_the_icon( 'gift', 18 ); ?><?php esc_html_e( '打赏作者', 'aurora' ); ?></h3>
			<?php // 还没人打赏时这行只会念出「0 人打赏 · 0 积分」，不如不显示。 ?>
			<p class="aurora-tip__meta"<?php echo (int) $stats['count'] > 0 ? '' : ' hidden'; ?>>
				<span data-tip-count><?php echo esc_html( (string) $stats['count'] ); ?></span> <?php esc_html_e( '人打赏', 'aurora' ); ?>
				·
				<span data-tip-total><?php echo esc_html( (string) $stats['total'] ); ?></span> <?php esc_html_e( '积分', 'aurora' ); ?>
			</p>
		</div>

		<?php if ( $stats['recent'] ) : ?>
			<div class="aurora-tip__faces">
				<?php foreach ( array_slice( $stats['recent'], 0, 8 ) as $aurora_tip ) : ?>
					<?php
					$aurora_uid = isset( $aurora_tip['user'] ) ? (int) $aurora_tip['user'] : 0;
					if ( ! $aurora_uid ) {
						continue;
					}
					?>
					<span class="aurora-tip__face" title="<?php echo esc_attr( (string) ( $aurora_tip['amount'] ?? 0 ) ); ?>">
						<?php echo get_avatar( $aurora_uid, 28, '', '', array( 'class' => 'avatar avatar-28 photo' ) ); ?>
					</span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( $is_self ) : ?>
			<p class="aurora-tip__hint"><?php esc_html_e( '这是你自己的文章。', 'aurora' ); ?></p>
		<?php else : ?>
			<div class="aurora-tip__actions">
				<?php foreach ( aurora_reward_amounts() as $aurora_amount ) : ?>
					<?php if ( $logged ) : ?>
						<button type="button" class="aurora-tip__btn" data-amount="<?php echo esc_attr( (string) $aurora_amount ); ?>">
							<?php echo esc_html( (string) $aurora_amount ); ?><small><?php esc_html_e( '积分', 'aurora' ); ?></small>
						</button>
					<?php else : ?>
						<a class="aurora-tip__btn aurora-need-login" href="#">
							<?php echo esc_html( (string) $aurora_amount ); ?><small><?php esc_html_e( '积分', 'aurora' ); ?></small>
						</a>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<p class="aurora-tip__msg" aria-live="polite"></p>
			<p class="aurora-tip__hint">
				<?php
				if ( $logged ) {
					printf(
						/* translators: %s: points balance. */
						esc_html__( '当前余额 %s 积分。打赏从余额扣除，全额转给作者。', 'aurora' ),
						esc_html( (string) aurora_points_balance( get_current_user_id() ) )
					);
				} else {
					esc_html_e( '登录后可以用积分打赏作者。', 'aurora' );
				}
				?>
			</p>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * AJAX: send a tip.
 */
function aurora_ajax_tip() {
	aurora_check_nonce();

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( '请先登录。', 'aurora' ) ), 401 );
	}

	if ( ! aurora_reward_enabled() ) {
		wp_send_json_error( array( 'message' => __( '打赏未开放。', 'aurora' ) ), 403 );
	}

	$post_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	$amount  = isset( $_POST['amount'] ) ? (int) $_POST['amount'] : 0;
	$post    = $post_id ? get_post( $post_id ) : null;

	if ( ! $post || 'publish' !== $post->post_status || 'post' !== $post->post_type ) {
		wp_send_json_error( array( 'message' => __( '文章不存在。', 'aurora' ) ), 404 );
	}

	$user_id = get_current_user_id();
	$author  = (int) $post->post_author;

	if ( $author === $user_id ) {
		wp_send_json_error( array( 'message' => __( '不能打赏自己。', 'aurora' ) ), 400 );
	}

	if ( ! $author ) {
		wp_send_json_error( array( 'message' => __( '这篇文章没有作者账号。', 'aurora' ) ), 400 );
	}

	// Amount must match a preset the server itself publishes: the browser never
	// decides how much a tip is worth.
	if ( ! in_array( $amount, aurora_reward_amounts(), true ) ) {
		wp_send_json_error( array( 'message' => __( '打赏金额无效。', 'aurora' ) ), 400 );
	}

	if ( function_exists( 'aurora_rate_guard' ) ) {
		$gate = aurora_rate_guard( 'tip' );
		if ( is_wp_error( $gate ) ) {
			wp_send_json_error( array( 'message' => $gate->get_error_message() ), 429 );
		}
	}

	$out = aurora_points_spend(
		$user_id,
		$amount,
		'tip_out',
		array(
			'object_id' => $post_id,
			'meta'      => array( 'to' => $author ),
		)
	);

	if ( is_wp_error( $out ) ) {
		wp_send_json_error( array( 'message' => $out->get_error_message() ), 400 );
	}

	// Credit the author. If that half fails the reader has already been debited,
	// so put the points back rather than leaving the ledger one-sided.
	$in = aurora_points_add(
		$author,
		$amount,
		'tip_in',
		array(
			'object_id' => $post_id,
			'meta'      => array( 'from' => $user_id ),
		)
	);

	if ( is_wp_error( $in ) ) {
		aurora_points_add( $user_id, $amount, 'tip_refund', array( 'object_id' => $post_id, 'silent' => true ) );
		wp_send_json_error( array( 'message' => __( '打赏失败，积分已退回。', 'aurora' ) ), 500 );
	}

	$stats = aurora_reward_stats( $post_id );
	$entry = array(
		'user'   => $user_id,
		'amount' => $amount,
		'time'   => current_time( 'mysql' ),
	);

	$recent = $stats['recent'];
	array_unshift( $recent, $entry );
	$recent = array_slice( $recent, 0, 8 );

	update_post_meta( $post_id, '_aurora_tip_total', $stats['total'] + $amount );
	update_post_meta( $post_id, '_aurora_tip_count', $stats['count'] + 1 );
	update_post_meta( $post_id, '_aurora_tip_recent', $recent );

	update_user_meta( $author, 'aurora_tip_received', (int) get_user_meta( $author, 'aurora_tip_received', true ) + $amount );
	update_user_meta( $user_id, 'aurora_tip_given', (int) get_user_meta( $user_id, 'aurora_tip_given', true ) + $amount );

	if ( function_exists( 'aurora_notify' ) ) {
		$reader = wp_get_current_user();
		aurora_notify(
			$author,
			sprintf(
				/* translators: 1: reader name, 2: amount. */
				__( '%1$s 打赏了你 %2$s 积分', 'aurora' ),
				$reader->display_name,
				$amount
			),
			sprintf( __( '来自文章《%s》', 'aurora' ), get_the_title( $post ) ),
			array(
				'type' => 'tip',
				'link' => get_permalink( $post ),
			)
		);
	}

	/**
	 * Fires after a tip was settled.
	 *
	 * @param int $post_id Article.
	 * @param int $user_id Reader.
	 * @param int $author  Author.
	 * @param int $amount  Points.
	 */
	do_action( 'aurora_tip_sent', $post_id, $user_id, $author, $amount );

	wp_send_json_success(
		array(
			'message' => sprintf(
				/* translators: %s: amount. */
				__( '已打赏 %s 积分，谢谢支持。', 'aurora' ),
				$amount
			),
			'balance' => aurora_points_balance( $user_id ),
			'count'   => $stats['count'] + 1,
			'total'   => $stats['total'] + $amount,
		)
	);
}
add_action( 'wp_ajax_aurora_tip', 'aurora_ajax_tip' );

/**
 * What the current reader has received / given.
 *
 * @param int $user_id User.
 * @return array
 */
function aurora_reward_user_summary( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return array( 'received' => 0, 'given' => 0, 'recent' => array() );
	}

	// Recent tips received: newest articles first, then their recent lists.
	$recent = array();
	$posts  = get_posts(
		array(
			'post_type'      => 'post',
			'author'         => $user_id,
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'no_found_rows'  => true,
			'fields'         => 'ids',
		)
	);

	foreach ( $posts as $pid ) {
		foreach ( aurora_reward_stats( $pid )['recent'] as $row ) {
			$row['post'] = $pid;
			$recent[]    = $row;
		}
	}

	usort(
		$recent,
		function ( $a, $b ) {
			return strcmp( (string) ( $b['time'] ?? '' ), (string) ( $a['time'] ?? '' ) );
		}
	);

	return array(
		'received' => (int) get_user_meta( $user_id, 'aurora_tip_received', true ),
		'given'    => (int) get_user_meta( $user_id, 'aurora_tip_given', true ),
		'recent'   => array_slice( $recent, 0, 12 ),
	);
}

/**
 * User centre tab: 我的收益.
 *
 * @param array $tabs Tabs.
 * @return array
 */
function aurora_reward_user_tab( $tabs ) {
	$tabs['income'] = array( 'label' => __( '我的收益', 'aurora' ), 'icon' => 'gift' );
	return $tabs;
}
add_filter( 'aurora_user_tabs', 'aurora_reward_user_tab' );
