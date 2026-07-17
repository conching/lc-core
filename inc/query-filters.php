<?php
/**
 * LC Core — generic Bricks query-var augmentation from request params.
 *
 * DOCTRINE (read before extending this file):
 *   Per-loop constraints belong NATIVELY in the element's own Bricks query settings
 *   — post type, base tax filters, ordering, posts-per-page. This hook exists for
 *   ONE job: augmenting a loop from the current request's GET params (a "finder"
 *   form: ?search=, ?category=, ?sort=, …). If you find yourself hard-coding a
 *   specific element id, a page's editorial rules, or business logic here, it does
 *   not belong in lc-core — that is per-site product code (see how the predecessor plugin kept its
 *   urgent-pinning / conditional-state logic in its own modules, not in this generic helper).
 *
 * A site registers augmentation rules via the `lc_core_query_param_map` filter,
 * keyed by post type:
 *
 *   add_filter( 'lc_core_query_param_map', function ( $map ) {
 *       $map['mrfm_vendor'] = array(
 *           'search_params' => array( 'search', 's' ),               // GET keys -> WP_Query 's'
 *           'tax_params'    => array( 'category' => 'mrfm_category',  // GET key  -> taxonomy
 *                                     'day'      => 'mrfm_day' ),
 *           'sort_key'      => 'sort',                                // GET key for sort
 *           'sort_map'      => array( 'az'     => array( 'title', 'ASC' ),
 *                                     'newest' => array( 'date',  'DESC' ) ),
 *       );
 *       return $map;
 *   } );
 *
 * All taxonomy params accept comma-separated multi-values (term__in on slug),
 * are sanitized, and are combined with AND across taxonomies. Missing taxonomies
 * or empty params are skipped (no fatal, no empty clause). Any tax_query the Bricks
 * element already set is preserved and ANDed underneath.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registered param maps. Empty until a site hooks `lc_core_query_param_map`.
 *
 * @return array<string,array>
 */
function lc_core_query_param_map() {
	return (array) apply_filters( 'lc_core_query_param_map', array() );
}

/**
 * Split a raw GET value into sanitized term slugs. Comma-separated multi-values;
 * empties dropped; de-duplicated.
 *
 * @param mixed $raw Raw $_GET value (string expected).
 * @return string[] Sanitized slugs (may be empty).
 */
function lc_core_parse_slug_list( $raw ) {
	if ( ! is_string( $raw ) || '' === $raw ) {
		return array();
	}
	$slugs = array();
	foreach ( explode( ',', wp_unslash( $raw ) ) as $part ) {
		$slug = sanitize_key( $part ); // lowercases; strips to [a-z0-9_-]
		if ( '' !== $slug ) {
			$slugs[] = $slug;
		}
	}
	return array_values( array_unique( $slugs ) );
}

/**
 * Return the single post type a Bricks loop targets, or '' if it targets many/none.
 *
 * @param array $query_vars
 * @return string
 */
function lc_core_query_target_type( $query_vars ) {
	$pt = isset( $query_vars['post_type'] ) ? $query_vars['post_type'] : '';
	if ( is_string( $pt ) && '' !== $pt ) {
		return $pt;
	}
	if ( is_array( $pt ) && 1 === count( $pt ) ) {
		return (string) reset( $pt );
	}
	return '';
}

/**
 * Augment a query-vars array from the current request, per a rule set. Pulled out
 * so it can be reasoned about (and, in principle, exercised) independently of the
 * Bricks filter.
 *
 * @param array $query_vars The WP_Query args to augment.
 * @param array $rules      One post type's rule set (see file header).
 * @return array Augmented query vars.
 */
function lc_core_augment_query_vars_from_request( $query_vars, $rules ) {
	// --- keyword search: first non-empty of the configured GET keys ---
	$search_params = isset( $rules['search_params'] ) ? (array) $rules['search_params'] : array();
	foreach ( $search_params as $param ) {
		if ( isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) && '' !== $_GET[ $param ] ) {
			$query_vars['s'] = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
			break;
		}
	}

	// --- taxonomy filters (AND across taxonomies; slug match; multi-value) ---
	$tax_params  = isset( $rules['tax_params'] ) ? (array) $rules['tax_params'] : array();
	$tax_clauses = array();
	foreach ( $tax_params as $param => $taxonomy ) {
		if ( ! isset( $_GET[ $param ] ) || ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}
		$slugs = lc_core_parse_slug_list( $_GET[ $param ] );
		if ( empty( $slugs ) ) {
			continue;
		}
		$tax_clauses[] = array(
			'taxonomy' => $taxonomy,
			'field'    => 'slug',
			'terms'    => $slugs,
			'operator' => 'IN',
		);
	}
	if ( ! empty( $tax_clauses ) ) {
		$existing = ( isset( $query_vars['tax_query'] ) && is_array( $query_vars['tax_query'] ) )
			? $query_vars['tax_query']
			: array();
		$new_tax_query = array( 'relation' => 'AND' );
		if ( ! empty( $existing ) ) {
			$new_tax_query[] = $existing; // fold existing in as a nested group
		}
		foreach ( $tax_clauses as $clause ) {
			$new_tax_query[] = $clause;
		}
		$query_vars['tax_query'] = $new_tax_query;
	}

	// --- sort (?<sort_key>=<token>) ---
	$sort_key = isset( $rules['sort_key'] ) ? $rules['sort_key'] : '';
	$sort_map = isset( $rules['sort_map'] ) ? (array) $rules['sort_map'] : array();
	if ( $sort_key && $sort_map && isset( $_GET[ $sort_key ] ) && is_string( $_GET[ $sort_key ] ) ) {
		$token = sanitize_key( wp_unslash( $_GET[ $sort_key ] ) );
		if ( isset( $sort_map[ $token ] ) ) {
			list( $orderby, $order ) = array_pad( (array) $sort_map[ $token ], 2, 'DESC' );
			$query_vars['orderby']   = $orderby;
			$query_vars['order']     = $order;
		}
	}

	return $query_vars;
}

/**
 * Bricks hook: augment any loop whose (single) post type has a registered rule set.
 * Safe no-op when Bricks is absent (the filter simply never fires).
 *
 * @param array  $query_vars
 * @param array  $settings
 * @param string $element_id
 * @return array
 */
function lc_core_filter_query_vars( $query_vars, $settings = array(), $element_id = '' ) {
	$map  = lc_core_query_param_map();
	if ( empty( $map ) ) {
		return $query_vars;
	}
	$type = lc_core_query_target_type( $query_vars );
	if ( '' === $type || ! isset( $map[ $type ] ) ) {
		return $query_vars;
	}
	return lc_core_augment_query_vars_from_request( $query_vars, (array) $map[ $type ] );
}
add_filter( 'bricks/posts/query_vars', 'lc_core_filter_query_vars', 10, 3 );
