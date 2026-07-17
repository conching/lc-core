<?php
/**
 * ============================================================================
 * LC CORE — EXAMPLE PER-SITE CONFIG LAYER
 * ============================================================================
 *
 * THIS FILE IS A TEMPLATE. It configures lc-core for a FICTIONAL site — the
 * "Maple Ridge Farmers Market" — to show every seam a real site plugs into.
 *
 * HOW TO USE ON A REAL SITE:
 *   1. Copy this file into your site's own plugin or mu-plugin
 *      (e.g. wp-content/mu-plugins/mysite-config.php). Do NOT edit it inside
 *      lc-core — lc-core stays site-agnostic and zip-replaceable.
 *   2. Rename the prefix (mrfm_) to your site's prefix everywhere.
 *   3. Replace the CPTs, taxonomies, fields, and mappings with your site's.
 *
 * lc-core itself is NOT loaded with this file unless you define
 * LC_CORE_LOAD_EXAMPLE_CONFIG (for local demos only).
 *
 * The real-world reference for what a mature config layer grows into is
 * the predecessor plugin — see the extraction report.
 * ============================================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* --------------------------------------------------------------------------
 * 1. CUSTOM POST TYPES
 * Owned here in code (not a UI plugin) so definitions are versioned and
 * portable. Pattern from the predecessor plugin: private CPTs, title+thumbnail only,
 * show_in_rest for Bricks query loops.
 * ------------------------------------------------------------------------ */

function mrfm_post_type_defs() {
	return array(
		'mrfm_vendor' => array( 'Vendors', 'Vendor', 'dashicons-store' ),
		'mrfm_event'  => array( 'Market Events', 'Market Event', 'dashicons-calendar-alt' ),
		'mrfm_recipe' => array( 'Recipes', 'Recipe', 'dashicons-carrot' ),
	);
}

add_action( 'init', 'mrfm_register_post_types', 4 ); // before taxonomies (5) so object types exist
function mrfm_register_post_types() {
	foreach ( mrfm_post_type_defs() as $slug => $def ) {
		if ( post_type_exists( $slug ) ) {
			continue;
		}
		register_post_type( $slug, array(
			'labels'              => array(
				'name'          => $def[0],
				'singular_name' => $def[1],
				'add_new_item'  => 'Add New ' . $def[1],
				'edit_item'     => 'Edit ' . $def[1],
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => true, // Bricks query loops + block editor meta
			'menu_icon'           => $def[2],
			'menu_position'       => 25,
			'supports'            => array( 'title', 'thumbnail' ),
			'has_archive'         => false,
			'rewrite'             => false,
		) );
	}
}

/* --------------------------------------------------------------------------
 * 2. TAXONOMIES + IDEMPOTENT TERM SEEDING
 * Terms are seeded with an alias list (JSON in term meta, key from
 * lc_core_term_alias_meta()) so the importer can resolve messy client vocab
 * ("Veggies", "vegetables" -> produce).
 * ------------------------------------------------------------------------ */

function mrfm_taxonomy_defs() {
	// slug => [ plural label, singular label, hierarchical, [post types] ]
	return array(
		'mrfm_category' => array( 'Vendor Categories', 'Vendor Category', false, array( 'mrfm_vendor' ) ),
		'mrfm_day'      => array( 'Market Days', 'Market Day', false, array( 'mrfm_vendor', 'mrfm_event' ) ),
		'mrfm_season'   => array( 'Seasons', 'Season', false, array( 'mrfm_recipe', 'mrfm_event' ) ),
	);
}

add_action( 'init', 'mrfm_register_taxonomies', 5 );
function mrfm_register_taxonomies() {
	foreach ( mrfm_taxonomy_defs() as $slug => $def ) {
		if ( taxonomy_exists( $slug ) ) {
			continue;
		}
		register_taxonomy( $slug, $def[3], array(
			'labels'             => array(
				'name'          => $def[0],
				'singular_name' => $def[1],
			),
			'hierarchical'       => $def[2],
			'public'             => true,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_rest'       => true,
			'show_admin_column'  => true,
			'show_in_menu'       => true,
		) );
	}
}

function mrfm_term_map() {
	// taxonomy => [ [ name, slug, parent_slug|null, [aliases] ], ... ]
	return array(
		'mrfm_category' => array(
			array( 'Produce', 'produce', null, array( 'Vegetables', 'Veggies', 'Fruit & Veg' ) ),
			array( 'Baked Goods', 'baked-goods', null, array( 'Bakery', 'Bread & Pastry' ) ),
			array( 'Dairy & Eggs', 'dairy-eggs', null, array( 'Dairy', 'Eggs' ) ),
			array( 'Crafts', 'crafts', null, array( 'Artisan Goods', 'Handmade' ) ),
			array( 'Prepared Food', 'prepared-food', null, array( 'Food Trucks', 'Ready to Eat' ) ),
		),
		'mrfm_day'      => array(
			array( 'Saturday', 'saturday', null, array( 'Sat' ) ),
			array( 'Wednesday', 'wednesday', null, array( 'Wed', 'Midweek' ) ),
		),
		'mrfm_season'   => array(
			array( 'Spring', 'spring', null, array() ),
			array( 'Summer', 'summer', null, array() ),
			array( 'Fall', 'fall', null, array( 'Autumn' ) ),
			array( 'Winter', 'winter', null, array( 'Holiday' ) ),
		),
	);
}

/** Idempotent: safe to re-run on every activation/upgrade. */
function mrfm_seed_terms() {
	$created = 0;
	$updated = 0;
	foreach ( mrfm_term_map() as $tax => $terms ) {
		if ( ! taxonomy_exists( $tax ) ) {
			continue;
		}
		foreach ( $terms as $t ) {
			list( $name, $slug, $parent_slug ) = array( $t[0], $t[1], $t[2] );
			$aliases   = isset( $t[3] ) ? $t[3] : array();
			$parent_id = 0;
			if ( $parent_slug ) {
				$parent    = get_term_by( 'slug', $parent_slug, $tax );
				$parent_id = $parent ? (int) $parent->term_id : 0;
			}
			$existing = get_term_by( 'slug', $slug, $tax );
			if ( $existing ) {
				wp_update_term( $existing->term_id, $tax, array( 'name' => $name, 'parent' => $parent_id ) );
				$term_id = $existing->term_id;
				$updated++;
			} else {
				$result = wp_insert_term( $name, $tax, array( 'slug' => $slug, 'parent' => $parent_id ) );
				if ( is_wp_error( $result ) ) {
					continue;
				}
				$term_id = $result['term_id'];
				$created++;
			}
			update_term_meta( $term_id, lc_core_term_alias_meta(), wp_json_encode( $aliases, JSON_UNESCAPED_UNICODE ) );
		}
	}
	return array( $created, $updated );
}

// Seed on lc-core activation and on version bumps (zip-replace deploys).
add_action( 'lc_core_activate', 'mrfm_seed_terms' );
add_action( 'lc_core_upgrade', 'mrfm_seed_terms' );

/* --------------------------------------------------------------------------
 * 3. ACF FIELD GROUPS (code-registered, versioned; requires ACF Pro)
 * The importer writes these same field names. Without ACF it falls back to
 * plain post meta with the same keys.
 * ------------------------------------------------------------------------ */

add_action( 'acf/init', 'mrfm_register_fields' );
function mrfm_register_fields() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	$t = function ( $key, $name, $label, $type = 'text', $extra = array() ) {
		return array_merge( array( 'key' => $key, 'name' => $name, 'label' => $label, 'type' => $type ), $extra );
	};

	acf_add_local_field_group( array(
		'key'      => 'group_mrfm_vendor',
		'title'    => 'Vendor Details',
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'mrfm_vendor' ) ) ),
		'position' => 'acf_after_title',
		'fields'   => array(
			$t( 'field_mrfm_v_url', 'mrfm_url', 'Vendor website', 'url' ),
			$t( 'field_mrfm_v_summary', 'mrfm_summary', 'Summary (public card text)', 'textarea', array( 'rows' => 3 ) ),
			$t( 'field_mrfm_v_stall', 'mrfm_stall', 'Stall / location label (e.g. "Row B, Stall 12")' ),
			$t( 'field_mrfm_v_featured', 'mrfm_featured', 'Feature on homepage', 'true_false', array( 'ui' => 1 ) ),
		),
	) );

	acf_add_local_field_group( array(
		'key'      => 'group_mrfm_event',
		'title'    => 'Event Details',
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'mrfm_event' ) ) ),
		'position' => 'acf_after_title',
		'fields'   => array(
			$t( 'field_mrfm_e_summary', 'mrfm_summary', 'Description', 'textarea', array( 'rows' => 3 ) ),
			$t( 'field_mrfm_e_date', 'mrfm_event_date', 'Event date', 'date_picker', array( 'return_format' => 'Y-m-d', 'display_format' => 'M j, Y' ) ),
			$t( 'field_mrfm_e_url', 'mrfm_url', 'Tickets / info URL', 'url' ),
		),
	) );
}

/* --------------------------------------------------------------------------
 * 4. IMPORT CONFIG — the contract with lc-core's importer
 * Keyed by post type:
 *   signature        header column(s) that uniquely identify this type
 *   required_columns header-title assertion — file rejected if any missing
 *   field_map        CSV column => ACF/meta field, or '_tax:<taxonomy>'
 *   bool_fields      columns coerced to strict 1/0
 *   url_fields       columns checked against ^https?:// after write
 * Columns named exactly like a taxonomy (mrfm_day, mrfm_season, ...) are
 * handled automatically as pipe-separated slug lists.
 * ------------------------------------------------------------------------ */

add_filter( 'lc_core_import_config', 'mrfm_import_config' );
function mrfm_import_config( $config ) {
	$config['mrfm_vendor'] = array(
		'signature'        => array( 'vendor_category' ),
		'required_columns' => array( 'post_title', 'vendor_category', 'url' ),
		'field_map'        => array(
			'url'             => 'mrfm_url',
			'summary'         => 'mrfm_summary',
			'stall'           => 'mrfm_stall',
			'featured'        => 'mrfm_featured',
			'vendor_category' => '_tax:mrfm_category', // free-text column -> term (alias-resolved)
		),
		'bool_fields'      => array( 'featured' ),
		'url_fields'       => array( 'url' ),
	);

	$config['mrfm_event'] = array(
		'signature'        => array( 'event_date' ),
		'required_columns' => array( 'post_title', 'event_date' ),
		'field_map'        => array(
			'summary'    => 'mrfm_summary',
			'event_date' => 'mrfm_event_date',
			'url'        => 'mrfm_url',
		),
		'url_fields'       => array( 'url' ),
	);

	return $config;
}

/* --------------------------------------------------------------------------
 * 5. QUERY PARAM MAP — finder GET params for Bricks loops
 * Doctrine reminder: base constraints (post type, base filters, per-page)
 * belong in the Bricks element's own query settings. This map ONLY augments
 * from request params.
 * ------------------------------------------------------------------------ */

add_filter( 'lc_core_query_param_map', 'mrfm_query_param_map' );
function mrfm_query_param_map( $map ) {
	$map['mrfm_vendor'] = array(
		'search_params' => array( 'search', 's' ),
		'tax_params'    => array(
			'category' => 'mrfm_category',
			'day'      => 'mrfm_day',
		),
		'sort_key'      => 'sort',
		'sort_map'      => array(
			'az'     => array( 'title', 'ASC' ),
			'newest' => array( 'date', 'DESC' ),
		),
	);
	return $map;
}

/* --------------------------------------------------------------------------
 * 6. OPTIONAL — bundled import data directory
 * Point at a data/ dir of generated CSVs to enable the one-click
 * "Run bundled import" button and `wp lc import-dir`.
 * ------------------------------------------------------------------------ */

// add_filter( 'lc_core_import_data_dir', function () {
// 	return WP_CONTENT_DIR . '/uploads/mrfm-import-data';
// } );
