<?php
/**
 * Search: keyword suggestions and hot words.
 *
 * Live post results already existed. What was missing is the part that helps
 * someone who does not yet know what to type: when the box opens, show what
 * the site is actually about (its busiest tags); while typing, offer matching
 * keywords so a two-character fragment finds the right term.
 *
 * Keywords come from tags the site already uses, not from a third-party API, so
 * this never adds an outbound request or a tracking hook.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * The site's busiest tags.
 *
 * @param int $limit How many.
 * @return array<int,array{label:string,count:int,link:string}>
 */
function aurora_search_hot_words( $limit = 8 ) {
	$terms = get_tags(
		array(
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => max( 1, (int) $limit ),
			'hide_empty' => true,
		)
	);

	if ( is_wp_error( $terms ) ) {
		return array();
	}

	$out = array();

	foreach ( $terms as $term ) {
		$out[] = array(
			'label' => $term->name,
			'count' => (int) $term->count,
			'link'  => get_tag_link( $term ),
		);
	}

	/**
	 * Filter the hot word list.
	 *
	 * @param array $out   Words.
	 * @param int   $limit Limit.
	 */
	return apply_filters( 'aurora_search_hot_words', $out, $limit );
}

/**
 * Keywords matching a fragment, by tag name.
 *
 * @param string $term  Fragment.
 * @param int    $limit How many.
 * @return array<int,array{label:string,count:int,link:string}>
 */
function aurora_search_suggest_words( $term, $limit = 6 ) {
	$term = trim( (string) $term );

	if ( mb_strlen( $term ) < 1 ) {
		return array();
	}

	$terms = get_terms(
		array(
			'taxonomy'   => 'post_tag',
			'name__like' => $term,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => max( 1, (int) $limit ),
			'hide_empty' => true,
		)
	);

	if ( is_wp_error( $terms ) ) {
		return array();
	}

	// Also match tags whose slug or description carries the fragment, which
	// name__like alone misses (translated labels with latin slugs).
	if ( count( $terms ) < $limit ) {
		$extra = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'search'     => $term,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => $limit,
				'hide_empty' => true,
			)
		);

		if ( ! is_wp_error( $extra ) ) {
			$seen = wp_list_pluck( $terms, 'term_id' );
			foreach ( $extra as $term_obj ) {
				if ( ! in_array( $term_obj->term_id, $seen, true ) ) {
					$terms[] = $term_obj;
				}
			}
		}
	}

	$out = array();

	foreach ( array_slice( $terms, 0, $limit ) as $term_obj ) {
		$out[] = array(
			'label' => $term_obj->name,
			'count' => (int) $term_obj->count,
			'link'  => get_tag_link( $term_obj ),
		);
	}

	return $out;
}

/**
 * AJAX: hot words (no term) or keyword suggestions (with a term).
 */
function aurora_ajax_suggest() {
	aurora_check_nonce();

	$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';

	if ( function_exists( 'aurora_rate_guard' ) ) {
		$gate = aurora_rate_guard( 'search' );
		if ( is_wp_error( $gate ) ) {
			wp_send_json_error( array( 'message' => $gate->get_error_message() ), 429 );
		}
	}

	wp_send_json_success(
		array(
			'term'  => $term,
			'words' => '' === $term ? aurora_search_hot_words( 8 ) : aurora_search_suggest_words( $term, 6 ),
		)
	);
}
add_action( 'wp_ajax_aurora_suggest', 'aurora_ajax_suggest' );
add_action( 'wp_ajax_nopriv_aurora_suggest', 'aurora_ajax_suggest' );
