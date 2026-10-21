<?php
/**
 * Mega menu.
 *
 * A top-level item with enough children stops being a single-column dropdown
 * and becomes a panel: two columns of links plus one featured article. Items
 * with a handful of children keep the plain dropdown, because a two-column
 * panel holding three links is worse than a list.
 *
 * Nothing here replaces the menu system: it is a Walker on top of
 * wp_nav_menu(), so menu editing in wp-admin is unchanged.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * How many children a top-level item needs before it becomes a panel.
 *
 * @return int
 */
function aurora_mega_threshold() {
	// Three is the floor that still reads as a panel: a two-column layout holding
	// three links plus the featured card is fine, two is not.
	return (int) apply_filters( 'aurora_mega_threshold', (int) get_theme_mod( 'aurora_mega_threshold', 3 ) );
}

/**
 * Is the mega menu on?
 *
 * @return bool
 */
function aurora_mega_enabled() {
	return (bool) get_theme_mod( 'aurora_mega_menu', true );
}

/**
 * Annotate menu items: which top-level items get a panel, and what to feature.
 *
 * @param WP_Post[] $items Menu items.
 * @param stdClass  $args  Menu args.
 * @return WP_Post[]
 */
function aurora_mega_annotate( $items, $args ) {
	if ( ! aurora_mega_enabled() ) {
		return $items;
	}

	$counts = array();

	foreach ( $items as $item ) {
		$parent = (int) $item->menu_item_parent;
		$counts[ $parent ] = isset( $counts[ $parent ] ) ? $counts[ $parent ] + 1 : 1;
	}

	$threshold = aurora_mega_threshold();

	foreach ( $items as $item ) {
		$item->aurora_mega = ( 0 === (int) $item->menu_item_parent && isset( $counts[ $item->ID ] ) && $counts[ $item->ID ] >= $threshold );

		if ( $item->aurora_mega ) {
			$item->classes   = is_array( $item->classes ) ? $item->classes : array();
			$item->classes[] = 'aurora-mega';
		}
	}

	return $items;
}
add_filter( 'wp_nav_menu_objects', 'aurora_mega_annotate', 10, 2 );

/**
 * Walker that renders the panel.
 */
class Aurora_Mega_Walker extends Walker_Nav_Menu {

	/**
	 * Whether the item whose children are being rendered is a panel.
	 *
	 * @var bool
	 */
	private $current_mega = false;

	/**
	 * Start one item.
	 *
	 * @param string   $output Output.
	 * @param WP_Post  $item   Item.
	 * @param int      $depth  Depth.
	 * @param stdClass $args   Args.
	 * @param int      $id     Id.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		$offset = strlen( $output );

		if ( 0 === $depth ) {
			$this->current_mega = ! empty( $item->aurora_mega );
		}

		parent::start_el( $output, $item, $depth, $args, $id );

		// A panel is a region, so say so. Only this item's own segment is
		// touched: running the replacement over the whole buffer rewrites the
		// first link in the menu, which is somebody else's item.
		if ( 0 === $depth && $this->current_mega ) {
			$segment = substr( $output, $offset );
			$segment = preg_replace( '/<a /', '<a aria-haspopup="true" aria-expanded="false" ', $segment, 1 );
			$output  = substr( $output, 0, $offset ) . $segment;
		}
	}

	/**
	 * Start a sub level.
	 *
	 * @param string   $output Output.
	 * @param int      $depth  Depth.
	 * @param stdClass $args   Args.
	 */
	public function start_lvl( &$output, $depth = 0, $args = null ) {
		if ( 0 === $depth && $this->current_mega ) {
			// A grid, not CSS columns: with three or four children a column
			// layout splits vertically and leaves the panel half empty.
			$output .= '<div class="sub-menu aurora-mega__panel">';
			$output .= '<ul class="aurora-mega__links">';
			return;
		}

		parent::start_lvl( $output, $depth, $args );
	}

	/**
	 * Close a sub level.
	 *
	 * @param string   $output Output.
	 * @param int      $depth  Depth.
	 * @param stdClass $args   Args.
	 */
	public function end_lvl( &$output, $depth = 0, $args = null ) {
		if ( 0 === $depth && $this->current_mega ) {
			$output .= '</ul>';
			$output .= '<div class="aurora-mega__featured">' . $this->featured() . '</div>';
			$output .= '</div>';
			return;
		}

		parent::end_lvl( $output, $depth, $args );
	}

	/**
	 * The featured article shown inside a panel.
	 *
	 * @return string
	 */
	private function featured() {
		$post = get_posts(
			apply_filters(
				'aurora_listing_query_args',
				array(
					'posts_per_page' => 1,
					'post_status'    => 'publish',
					'no_found_rows'  => true,
				)
			)
		);

		if ( ! $post ) {
			return '';
		}

		$post = $post[0];
		$img  = has_post_thumbnail( $post ) ? get_the_post_thumbnail( $post, 'medium', array( 'loading' => 'lazy' ) ) : '';

		return sprintf(
			'<a class="aurora-mega__card" href="%1$s">%2$s<span class="aurora-mega__card-title">%3$s</span><span class="aurora-mega__card-meta">%4$s</span></a>',
			esc_url( get_permalink( $post ) ),
			$img,
			esc_html( get_the_title( $post ) ),
			esc_html( get_the_date( '', $post ) )
		);
	}
}
