<?php
/**
 * Post ratings.
 *
 * One vote per visitor per article, five stars. The aggregate is kept in two
 * post-meta counters (sum and count) and each voter gets one meta key of their
 * own, so "have they voted" is a single meta lookup rather than a scan of an
 * ever-growing array.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is rating open?
 *
 * @return bool
 */
function aurora_rating_enabled() {
	return (bool) get_theme_mod( 'aurora_rating_enabled', true );
}

/**
 * Identity used to stop double voting: the user, else a hashed IP.
 *
 * The raw address is never stored.
 *
 * @return string
 */
function aurora_rating_voter_key() {
	if ( is_user_logged_in() ) {
		return 'u' . get_current_user_id();
	}

	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

	return 'g' . substr( md5( $ip . wp_salt( 'nonce' ) ), 0, 20 );
}

/**
 * Aggregate for one post.
 *
 * @param int $post_id Post.
 * @return array{avg:float,count:int,sum:int}
 */
function aurora_rating_stats( $post_id ) {
	$sum   = (int) get_post_meta( $post_id, '_aurora_rating_sum', true );
	$count = (int) get_post_meta( $post_id, '_aurora_rating_count', true );

	return array(
		'sum'   => $sum,
		'count' => $count,
		'avg'   => $count > 0 ? round( $sum / $count, 1 ) : 0.0,
	);
}

/**
 * Has this visitor already voted on that post?
 *
 * @param int $post_id Post.
 * @return int 0 when they have not, else their score.
 */
function aurora_rating_mine( $post_id ) {
	return (int) get_post_meta( $post_id, '_aurora_rating_' . aurora_rating_voter_key(), true );
}

/**
 * The stars block.
 */
function aurora_rating_block() {
	if ( ! is_singular( 'post' ) || ! aurora_rating_enabled() ) {
		return;
	}

	$post  = get_post();
	$stats = aurora_rating_stats( $post->ID );
	$mine  = aurora_rating_mine( $post->ID );
	?>
	<section class="aurora-rating" data-post="<?php echo esc_attr( (string) $post->ID ); ?>"
		data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'aurora_nonce' ) ); ?>">
		<div class="aurora-rating__summary"<?php echo 0 === (int) $stats['count'] ? ' hidden' : ''; ?>>
			<span class="aurora-rating__score" data-avg><?php echo esc_html( number_format_i18n( $stats['avg'], 1 ) ); ?></span>
			<span class="aurora-rating__outof">/ 5</span>
			<span class="aurora-rating__count"><span data-count><?php echo esc_html( (string) $stats['count'] ); ?></span> <?php esc_html_e( '人评分', 'aurora' ); ?></span>
		</div>

		<div class="aurora-rating__stars" role="radiogroup" aria-label="<?php esc_attr_e( '给这篇文章打分', 'aurora' ); ?>">
			<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
				<button type="button" class="aurora-rating__star<?php echo $mine >= $i ? ' is-on' : ''; ?>"
					data-value="<?php echo esc_attr( (string) $i ); ?>"
					role="radio" aria-checked="<?php echo $mine === $i ? 'true' : 'false'; ?>"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %d: stars. */ __( '%d 星', 'aurora' ), $i ) ); ?>">★</button>
			<?php endfor; ?>
		</div>

		<p class="aurora-rating__msg" data-msg aria-live="polite">
			<?php
			echo $mine
				? esc_html( sprintf( /* translators: %d: stars. */ __( '你打了 %d 星。', 'aurora' ), $mine ) )
				: esc_html__( '点星星给个分。', 'aurora' );
			?>
		</p>
	</section>
	<?php
}

/**
 * JSON-LD aggregateRating, for search results.
 */
function aurora_rating_jsonld() {
	if ( ! is_singular( 'post' ) || ! aurora_rating_enabled() ) {
		return;
	}

	$stats = aurora_rating_stats( get_the_ID() );

	if ( $stats['count'] < 1 ) {
		return;
	}

	$data = array(
		'@context'      => 'https://schema.org',
		'@type'         => 'Article',
		'name'          => get_the_title(),
		'aggregateRating' => array(
			'@type'       => 'AggregateRating',
			'ratingValue' => (string) $stats['avg'],
			'ratingCount' => (string) $stats['count'],
			'bestRating'  => '5',
			'worstRating' => '1',
		),
	);

	printf(
		'<script type="application/ld+json">%s</script>' . "\n",
		wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
	);
}
add_action( 'wp_head', 'aurora_rating_jsonld', 20 );

/**
 * AJAX: cast a vote.
 */
function aurora_ajax_rate() {
	aurora_check_nonce();

	if ( ! aurora_rating_enabled() ) {
		wp_send_json_error( array( 'message' => __( '评分未开放。', 'aurora' ) ), 403 );
	}

	$post_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	$score   = isset( $_POST['score'] ) ? (int) $_POST['score'] : 0;
	$post    = $post_id ? get_post( $post_id ) : null;

	if ( ! $post || 'publish' !== $post->post_status || 'post' !== $post->post_type ) {
		wp_send_json_error( array( 'message' => __( '文章不存在。', 'aurora' ) ), 404 );
	}

	if ( $score < 1 || $score > 5 ) {
		wp_send_json_error( array( 'message' => __( '分数需要在 1 到 5 之间。', 'aurora' ) ), 400 );
	}

	if ( aurora_rating_mine( $post_id ) ) {
		wp_send_json_error( array( 'message' => __( '你已经评过分了。', 'aurora' ) ), 409 );
	}

	if ( function_exists( 'aurora_rate_guard' ) ) {
		$gate = aurora_rate_guard( 'rate' );
		if ( is_wp_error( $gate ) ) {
			wp_send_json_error( array( 'message' => $gate->get_error_message() ), 429 );
		}
	}

	$stats = aurora_rating_stats( $post_id );

	update_post_meta( $post_id, '_aurora_rating_' . aurora_rating_voter_key(), $score );
	update_post_meta( $post_id, '_aurora_rating_sum', $stats['sum'] + $score );
	update_post_meta( $post_id, '_aurora_rating_count', $stats['count'] + 1 );

	$new = aurora_rating_stats( $post_id );

	/**
	 * Fires after a rating was recorded.
	 *
	 * @param int $post_id Post.
	 * @param int $score   Stars.
	 * @param array $new   New aggregate.
	 */
	do_action( 'aurora_rated', $post_id, $score, $new );

	wp_send_json_success(
		array(
			'message' => sprintf(
				/* translators: %d: stars. */
				__( '已记录 %d 星，谢谢。', 'aurora' ),
				$score
			),
			'avg'   => $new['avg'],
			'count' => $new['count'],
			'mine'  => $score,
		)
	);
}
add_action( 'wp_ajax_aurora_rate', 'aurora_ajax_rate' );
add_action( 'wp_ajax_nopriv_aurora_rate', 'aurora_ajax_rate' );
