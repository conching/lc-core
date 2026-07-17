<?php
/**
 * LC Core — per-site config accessors.
 *
 * lc-core ships NO custom post types, taxonomies, ACF fields, or import mappings of
 * its own. A site declares those in its own config layer and feeds the importer
 * through the `lc_core_import_config` filter. See config/example-config.php for a
 * complete worked example (fictional "Maple Ridge Farmers Market" site), and
 * config/README.md for the schema.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The import config: an array keyed by post type. Each entry:
 *
 *   'signature'        => string|string[]  Header column(s) that uniquely identify
 *                                          this type in a CSV (for auto-detection).
 *   'required_columns' => string[]         Column titles that MUST be present, or the
 *                                          file is rejected (header-title assertion).
 *   'field_map'        => array            CSV column => target. Target is an ACF/meta
 *                                          field name, or '_tax:<taxonomy>' to route a
 *                                          free-text column into a taxonomy term.
 *   'bool_fields'      => string[]         CSV columns coerced to strict 1/0.
 *   'url_fields'       => string[]         CSV columns whose written values are checked
 *                                          against ^https?:// after the row is saved.
 *
 * Taxonomy columns named EXACTLY like a registered taxonomy of the post type are
 * handled automatically (pipe-separated slugs) — they need no field_map entry.
 *
 * @return array
 */
function lc_core_import_config() {
	return (array) apply_filters( 'lc_core_import_config', array() );
}

/**
 * Definition array for one post type (or empty array).
 *
 * @param string $type Post type slug.
 * @return array
 */
function lc_core_type_def( $type ) {
	$config = lc_core_import_config();
	return isset( $config[ $type ] ) ? (array) $config[ $type ] : array();
}

/** CSV column => field/taxonomy target map for a type. */
function lc_core_type_field_map( $type ) {
	$def = lc_core_type_def( $type );
	return isset( $def['field_map'] ) ? (array) $def['field_map'] : array();
}

/** Required column titles for a type (header assertion). */
function lc_core_type_required_columns( $type ) {
	$def = lc_core_type_def( $type );
	// 'post_title' is always required — identity depends on it.
	$req = isset( $def['required_columns'] ) ? (array) $def['required_columns'] : array();
	if ( ! in_array( 'post_title', $req, true ) ) {
		array_unshift( $req, 'post_title' );
	}
	return $req;
}

/** CSV columns to coerce to strict 1/0 for a type. */
function lc_core_type_bool_fields( $type ) {
	$def = lc_core_type_def( $type );
	return isset( $def['bool_fields'] ) ? (array) $def['bool_fields'] : array();
}

/** CSV columns whose written values must be URLs, for a type. */
function lc_core_type_url_fields( $type ) {
	$def = lc_core_type_def( $type );
	return isset( $def['url_fields'] ) ? (array) $def['url_fields'] : array();
}

/**
 * Meta key that stores a post's import key. Filterable so a site can namespace it,
 * but the default matches the plugin prefix.
 *
 * @return string
 */
function lc_core_import_key_meta() {
	return (string) apply_filters( 'lc_core_import_key_meta', '_lc_import_key' );
}

/** Meta key that stores per-row import notes. */
function lc_core_import_notes_meta() {
	return (string) apply_filters( 'lc_core_import_notes_meta', '_lc_import_notes' );
}

/**
 * Term meta key holding a JSON array of aliases, consulted when resolving a
 * free-text taxonomy value to an existing term. Default 'lc_aliases'.
 *
 * @return string
 */
function lc_core_term_alias_meta() {
	return (string) apply_filters( 'lc_core_term_alias_meta', 'lc_aliases' );
}

/**
 * Optional directory of bundled CSVs offered on the import admin screen and to
 * `wp lc import-dir`. Empty by default (a site opts in via the filter).
 *
 * @return string Absolute path, or ''.
 */
function lc_core_import_data_dir() {
	return (string) apply_filters( 'lc_core_import_data_dir', '' );
}
