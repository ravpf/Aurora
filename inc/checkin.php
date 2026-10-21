<?php
/**
 * Check-in history, the calendar, and make-up days.
 *
 * Check-in itself already lives in the AJAX layer: it credits points, tracks the
 * streak and now fires `aurora_checkin`, which the levels module listens to. What
 * was missing is memory. The old implementation stored only "last day" and
 * "streak", which is enough to award today but cannot answer the two questions a
 * member actually asks — which days did I miss, and can I still fix one?
 *
 * This module keeps a dated record so both questions have an answer, and puts a
 * price on fixing a miss: a small points cost, a ceiling per month, and no
 * experience for a made-up day. A backfill that paid the same as showing up
 * would make showing up pointless.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/** Meta key holding the dated record. */
const AURORA_CHECKIN_META = 'aurora_checkin_days';

/** How a recorded day is labelled. */
const AURORA_CHECKIN_DONE   = 'done';
const AURORA_CHECKIN_MAKEUP = 'makeup';

/**
 * Is check-in running?
 *
 * @return bool
 */
function aurora_checkin_enabled() {
	return (bool) get_theme_mod( 'aurora_checkin_enable', true );
}

/**
 * Today, in the site's timezone.
 *
 * @return string Y-m-d.
 */
function aurora_checkin_today() {
	return current_time( 'Y-m-d' );
}

/**
 * The dated record, oldest first.
 *
 * @param int $user_id User ID.
 * @return array Date => 'done'|'makeup'.
 */
function aurora_checkin_days( $user_id ) {
	$days = get_user_meta( (int) $user_id, AURORA_CHECKIN_META, true );
	$days = is_array( $days ) ? $days : array();

	// Anything that is not a date/value pair is dropped rather than trusted:
	// this array is written from several code paths and rendered on a page.
	$clean = array();
	foreach ( $days as $date => $kind ) {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) && in_array( $kind, array( AURORA_CHECKIN_DONE, AURORA_CHECKIN_MAKEUP ), true ) ) {
			$clean[ (string) $date ] = $kind;
		}
	}

	ksort( $clean );

	return $clean;
}

/**
 * Record a day.
 *
 * @param int    $user_id User ID.
 * @param string $date    Y-m-d.
 * @param string $kind    done|makeup.
 * @return bool
 */
function aurora_checkin_record( $user_id, $date, $kind = AURORA_CHECKIN_DONE ) {
	$user_id = (int) $user_id;

	if ( ! $user_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
		return false;
	}

	$days          = aurora_checkin_days( $user_id );
	$days[ $date ] = in_array( $kind, array( AURORA_CHECKIN_DONE, AURORA_CHECKIN_MAKEUP ), true ) ? $kind : AURORA_CHECKIN_DONE;

	// Two years of history is far more than the calendar ever draws; the cap
	// keeps the meta row from growing without end on a long-lived account.
	if ( count( $days ) > 730 ) {
		$days = array_slice( $days, -730, null, true );
	}

	update_user_meta( $user_id, AURORA_CHECKIN_META, $days );

	return true;
}

/**
 * Record today's check-in.
 *
 * @param int $user_id User ID.
 * @param int $streak  Streak reported by the handler.
 */
function aurora_checkin_on_checkin( $user_id, $streak = 1 ) {
	aurora_checkin_record( $user_id, aurora_checkin_today(), AURORA_CHECKIN_DONE );
}
add_action( 'aurora_checkin', 'aurora_checkin_on_checkin', 5, 2 );

/**
 * Has the member checked in today?
 *
 * @param int $user_id User ID.
 * @return bool
 */
function aurora_checkin_done_today( $user_id ) {
	return isset( aurora_checkin_days( $user_id )[ aurora_checkin_today() ] );
}

/**
 * The streak as the record sees it, counting back from today.
 *
 * Recomputed from the dated record rather than read from the counter the AJAX
 * handler maintains, because the two can disagree — a make-up day fills a hole
 * the counter never saw. The record wins, and the counter is brought in line.
 *
 * @param int $user_id User ID.
 * @return int
 */
function aurora_checkin_streak( $user_id ) {
	$days = aurora_checkin_days( $user_id );

	if ( ! $days ) {
		return 0;
	}

	$today = aurora_checkin_today();
	$start = isset( $days[ $today ] ) ? $today : gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );

	if ( ! isset( $days[ $start ] ) ) {
		return 0;
	}

	$streak = 0;
	$cursor = $start;

	while ( isset( $days[ $cursor ] ) ) {
		$streak++;
		$cursor = gmdate( 'Y-m-d', strtotime( $cursor . ' -1 day' ) );

		if ( $streak > 730 ) {
			break;
		}
	}

	return $streak;
}

/**
 * A calendar grid for one month.
 *
 * @param int $user_id User ID.
 * @param int $year    Year.
 * @param int $month   Month.
 * @return array Day number => status.
 */
function aurora_checkin_month( $user_id, $year, $month ) {
	$days  = aurora_checkin_days( $user_id );
	$first = mktime( 0, 0, 0, (int) $month, 1, (int) $year );
	$total = (int) gmdate( 't', $first );
	$today = aurora_checkin_today();
	$grid  = array();

	for ( $day = 1; $day <= $total; $day++ ) {
		$date = gmdate( 'Y-m-d', mktime( 0, 0, 0, (int) $month, $day, (int) $year ) );

		if ( isset( $days[ $date ] ) ) {
			$grid[ $day ] = $days[ $date ];
		} elseif ( $date > $today ) {
			$grid[ $day ] = 'future';
		} elseif ( $date === $today ) {
			// Still theirs to claim.
			$grid[ $day ] = 'today';
		} else {
			$grid[ $day ] = 'missed';
		}
	}

	return $grid;
}

/**
 * How many make-up days are allowed a month.
 *
 * @return int
 */
function aurora_checkin_makeup_max() {
	return max( 0, (int) get_theme_mod( 'aurora_checkin_makeup_max', 3 ) );
}

/**
 * What a make-up day costs.
 *
 * @return int
 */
function aurora_checkin_makeup_cost() {
	return max( 0, (int) get_theme_mod( 'aurora_checkin_makeup_cost', 50 ) );
}

/**
 * How far back a make-up day may reach.
 *
 * @return int Days.
 */
function aurora_checkin_makeup_window() {
	return max( 1, (int) get_theme_mod( 'aurora_checkin_makeup_window', 30 ) );
}

/**
 * Make-up days already taken this month.
 *
 * @param int $user_id User ID.
 * @return int
 */
function aurora_checkin_makeup_used( $user_id ) {
	$prefix = current_time( 'Y-m' );
	$used   = 0;

	foreach ( aurora_checkin_days( $user_id ) as $date => $kind ) {
		if ( AURORA_CHECKIN_MAKEUP === $kind && 0 === strpos( $date, $prefix ) ) {
			$used++;
		}
	}

	return $used;
}

/**
 * Can this date be made up?
 *
 * @param int    $user_id User ID.
 * @param string $date    Y-m-d.
 * @return true|WP_Error
 */
function aurora_checkin_can_makeup( $user_id, $date ) {
	if ( ! aurora_checkin_enabled() ) {
		return new WP_Error( 'aurora_checkin_off', __( '签到功能未开启。', 'aurora' ) );
	}

	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
		return new WP_Error( 'aurora_checkin_bad_date', __( '日期无效。', 'aurora' ) );
	}

	$today = aurora_checkin_today();

	if ( $date >= $today ) {
		return new WP_Error( 'aurora_checkin_future', __( '只能补签过去的日子。', 'aurora' ) );
	}

	// Old enough that the streak it repairs no longer means anything.
	$earliest = gmdate( 'Y-m-d', strtotime( $today . ' -' . aurora_checkin_makeup_window() . ' day' ) );
	if ( $date < $earliest ) {
		return new WP_Error( 'aurora_checkin_too_old', __( '这一天太久远了，无法补签。', 'aurora' ) );
	}

	if ( isset( aurora_checkin_days( $user_id )[ $date ] ) ) {
		return new WP_Error( 'aurora_checkin_already', __( '这一天已经签过了。', 'aurora' ) );
	}

	$max = aurora_checkin_makeup_max();

	if ( $max < 1 ) {
		return new WP_Error( 'aurora_checkin_makeup_off', __( '本站未开放补签。', 'aurora' ) );
	}

	if ( aurora_checkin_makeup_used( $user_id ) >= $max ) {
		return new WP_Error(
			'aurora_checkin_makeup_limit',
			sprintf(
				/* translators: %d: how many make-up days are allowed */
				__( '本月补签次数已用完（每月 %d 次）。', 'aurora' ),
				$max
			)
		);
	}

	return true;
}

/**
 * Backfill one day, charging points for it.
 *
 * @param int    $user_id User ID.
 * @param string $date    Y-m-d.
 * @return array|WP_Error
 */
function aurora_checkin_makeup( $user_id, $date ) {
	$user_id = (int) $user_id;
	$allowed = aurora_checkin_can_makeup( $user_id, $date );

	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}

	$cost = aurora_checkin_makeup_cost();

	if ( $cost > 0 ) {
		if ( ! function_exists( 'aurora_points_spend' ) ) {
			return new WP_Error( 'aurora_checkin_no_points', __( '积分模块未启用，无法补签。', 'aurora' ) );
		}

		$paid = aurora_points_spend(
			$user_id,
			$cost,
			'checkin',
			array( 'note' => sprintf( __( '补签 %s', 'aurora' ), $date ) )
		);

		if ( is_wp_error( $paid ) ) {
			return $paid;
		}
	}

	aurora_checkin_record( $user_id, $date, AURORA_CHECKIN_MAKEUP );

	// The counter the AJAX handler keeps is corrected rather than trusted, so a
	// repair is reflected the moment the calendar is looked at again.
	update_user_meta( $user_id, 'aurora_checkin_streak', aurora_checkin_streak( $user_id ) );

	/**
	 * Fires after a make-up check-in.
	 *
	 * @param int    $user_id User ID.
	 * @param string $date    Backfilled date.
	 * @param int    $cost    Points paid.
	 */
	do_action( 'aurora_checkin_makeup', $user_id, $date, $cost );

	return array(
		'date'  => $date,
		'cost'  => $cost,
		'streak' => aurora_checkin_streak( $user_id ),
	);
}

/**
 * Handle a make-up request from the user centre.
 */
function aurora_ajax_checkin_makeup() {
	aurora_check_nonce();

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( '请先登录。', 'aurora' ) ), 403 );
	}

	// Same ceiling as the check-in itself: a repair is still a write a guest
	// script would happily fire in a loop.
	aurora_rate_json_guard( 'checkin' );

	$user_id = get_current_user_id();
	$date    = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
	$result  = aurora_checkin_makeup( $user_id, $date );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error(
			array(
				'message'     => wp_strip_all_tags( $result->get_error_message() ),
				'retry_after' => (int) ( $result->get_error_data()['retry_after'] ?? 0 ),
			),
			200
		);
	}

	wp_send_json_success( array(
		'message' => sprintf(
			/* translators: %s: backfilled date */
			__( '已补签 %s。', 'aurora' ),
			$result['date']
		),
		'date'   => $result['date'],
		'streak' => $result['streak'],
	) );
}
add_action( 'wp_ajax_aurora_checkin_makeup', 'aurora_ajax_checkin_makeup' );

/**
 * A bucket for the make-up endpoint.
 *
 * @param array $buckets Buckets.
 * @return array
 */
function aurora_checkin_rate_bucket( $buckets ) {
	$buckets['checkin'] = __( '签到与补签', 'aurora' );

	return $buckets;
}
add_filter( 'aurora_rate_buckets', 'aurora_checkin_rate_bucket' );

/**
 * Ceilings for that bucket.
 *
 * @param array $defaults Defaults.
 * @return array
 */
function aurora_checkin_rate_defaults( $defaults ) {
	$defaults['checkin'] = array( 'short' => 5, 'hour' => 20, 'day' => 60 );

	return $defaults;
}
add_filter( 'aurora_rate_defaults', 'aurora_checkin_rate_defaults' );
