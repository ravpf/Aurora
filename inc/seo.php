<?php
/**
 * SEO: meta description, Open Graph, Twitter cards, JSON-LD, sitemap hints.
 *
 * @package Aurora
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build a description for the current view.
 *
 * @return string
 */
function aurora_meta_description() {
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post ) {
			$locked = function_exists( 'aurora_content_locked' ) && aurora_content_locked( $post->ID );

			if ( has_excerpt( $post ) ) {
				return wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post ) ), 40, '' );
			}
			// An auto-derived excerpt would publish the protected text in the
			// meta tags, where anyone can read it without paying.
			if ( ! $locked ) {
				return wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ), 40, '' );
			}
			return get_bloginfo( 'description' );
		}
	}
	if ( is_category() || is_tag() || is_tax() ) {
		$desc = term_description();
		if ( $desc ) {
			return wp_trim_words( wp_strip_all_tags( $desc ), 40, '' );
		}
		return sprintf( __( '%1$s 分类下的全部文章 — %2$s', 'aurora' ), single_term_title( '', false ), get_bloginfo( 'name' ) );
	}
	if ( is_search() ) {
		return sprintf( __( '“%1$s”的搜索结果 — %2$s', 'aurora' ), get_search_query(), get_bloginfo( 'name' ) );
	}

	return get_bloginfo( 'description' );
}

/**
 * Print meta tags in the head.
 */
function aurora_seo_head() {
	if ( is_404() ) {
		return;
	}

	$title       = wp_get_document_title();
	$description = aurora_meta_description();
	$url         = is_singular() ? get_permalink() : home_url( add_query_arg( array() ) );
	$image       = '';

	if ( is_singular() && has_post_thumbnail() ) {
		$image = (string) get_the_post_thumbnail_url( get_the_ID(), 'aurora-card-wide' );
	}

	printf( '<meta name="description" content="%s">' . "\n", esc_attr( $description ) );
	printf( '<meta property="og:type" content="%s">' . "\n", is_singular( 'post' ) ? 'article' : 'website' );
	printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $title ) );
	printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $description ) );
	printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );
	printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
	printf( '<meta property="og:locale" content="%s">' . "\n", esc_attr( get_locale() ) );
	if ( $image ) {
		printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $image ) );
	}
	printf( '<meta name="twitter:card" content="%s">' . "\n", $image ? 'summary_large_image' : 'summary' );

	if ( is_singular( 'post' ) ) {
		printf( '<meta property="article:published_time" content="%s">' . "\n", esc_attr( get_the_date( 'c' ) ) );
		printf( '<meta property="article:modified_time" content="%s">' . "\n", esc_attr( get_the_modified_date( 'c' ) ) );
		$cats = get_the_category();
		if ( $cats ) {
			printf( '<meta property="article:section" content="%s">' . "\n", esc_attr( $cats[0]->name ) );
		}
	}

	$canonical = is_singular() ? get_permalink() : $url;
	printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $canonical ) );

	// Paginated archive hints.
	if ( is_archive() || is_home() || is_search() ) {
		global $wp_query;
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		if ( $paged > 1 ) {
			printf( '<link rel="prev" href="%s">' . "\n", esc_url( get_pagenum_link( $paged - 1 ) ) );
		}
		if ( $paged < (int) $wp_query->max_num_pages ) {
			printf( '<link rel="next" href="%s">' . "\n", esc_url( get_pagenum_link( $paged + 1 ) ) );
		}
	}

	aurora_schema();
}
add_action( 'wp_head', 'aurora_seo_head', 5 );

/**
 * A favicon, even before anyone sets a site icon.
 *
 * Without this, every browser asks for /favicon.ico, which the server does not
 * have, and the site ships a 404 on its most-visited page. The icon is the same
 * blue-violet tile as the header logo, inline as SVG so it costs no request.
 */
function aurora_favicon() {
	if ( function_exists( 'has_site_icon' ) && has_site_icon() ) {
		return; // WordPress is emitting its own <link rel="icon"> already.
	}

	$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
		. '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
		. '<stop offset="0" stop-color="#6d8bfd"/><stop offset="1" stop-color="#9a6bfd"/>'
		. '</linearGradient></defs>'
		. '<rect width="64" height="64" rx="16" fill="url(#g)"/>'
		. '<path d="M32 14 18 50h7.6l2.9-7.6h7l2.9 7.6H46L32 14zm0 12.4 2.4 6.4h-4.8l2.4-6.4z" fill="#fff"/>'
		. '</svg>';

	printf( '<link rel="icon" href="data:image/svg+xml,%s">' . "\n", rawurlencode( $svg ) );
}
add_action( 'wp_head', 'aurora_favicon', 6 );

/**
 * Emit JSON-LD structured data.
 */
function aurora_schema() {
	$graph = array();

	$graph[] = array(
		'@type'       => 'WebSite',
		'@id'         => home_url( '/#website' ),
		'url'         => home_url( '/' ),
		'name'        => get_bloginfo( 'name' ),
		'description' => get_bloginfo( 'description' ),
		'potentialAction' => array(
			'@type'       => 'SearchAction',
			'target'      => home_url( '/?s={search_term_string}' ),
			'query-input' => 'required name=search_term_string',
		),
	);

	if ( is_singular( 'post' ) ) {
		$post   = get_queried_object();
		$locked = function_exists( 'aurora_content_locked' ) && aurora_content_locked( $post->ID );

		$article = array(
			'@type'            => 'Article',
			'headline'         => get_the_title( $post ),
			// Same rule as the meta description: never summarise protected
			// content, and do not publish its word count either.
			'description'      => ( $locked && ! has_excerpt( $post ) )
				? get_bloginfo( 'description' )
				: wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post ) ), 40, '' ),
			'datePublished'    => get_the_date( 'c', $post ),
			'dateModified'     => get_the_modified_date( 'c', $post ),
			'author'           => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', $post->post_author ),
				'url'   => get_author_posts_url( $post->post_author ),
			),
			'publisher'        => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
			),
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => get_permalink( $post ),
			),
		);

		if ( ! $locked ) {
			$article['wordCount'] = str_word_count( wp_strip_all_tags( $post->post_content ) );
		}

		$graph[] = $article;

		if ( has_post_thumbnail( $post ) ) {
			$img = wp_get_attachment_image_src( get_post_thumbnail_id( $post ), 'aurora-card-wide' );
			if ( $img ) {
				$graph[1]['image'] = array( $img[0] );
			}
		}
	}

	if ( is_singular() && comments_open() ) {
		$graph[] = array(
			'@type'      => 'BreadcrumbList',
			'itemListElement' => array(
				array( '@type' => 'ListItem', 'position' => 1, 'name' => __( '首页', 'aurora' ), 'item' => home_url( '/' ) ),
				array( '@type' => 'ListItem', 'position' => 2, 'name' => get_the_title(), 'item' => get_permalink() ),
			),
		);
	}

	printf(
		'<script type="application/ld+json">%s</script>' . "\n",
		wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
	);
}

/**
 * Skip the WordPress default canonical tag: the theme prints its own.
 */
function aurora_disable_core_canonical() {
	remove_action( 'wp_head', 'rel_canonical' );
}
add_action( 'init', 'aurora_disable_core_canonical' );
