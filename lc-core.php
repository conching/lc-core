<?php
/**
 * Plugin Name:       LC Core
 * Description:       Site-agnostic starter framework for Library Creative WordPress sites — a config-driven content importer, a KSES allowlist module, and generic Bricks query-var helpers. Per-site CPTs / taxonomies / ACF field groups / import mappings live in a separate config layer (see config/example-config.php). Deploy alongside lc-bricks-mcp.
 * Version:           0.3.0
 * Requires PHP:      7.4
 * Author:            Library Creative
 * License:           GPL-2.0-or-later
 * Text Domain:       lc-core
 *
 * Provenance: extracted from a private predecessor site plugin in July 2026.
 * The importer's identity/upsert semantics are carried over verbatim — they encode
 * several production bug fixes (see inc/importer.php and CHANGELOG.md). Do not "clean
 * them up" without reading the version-history notes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LC_CORE_VERSION', '0.3.0' );
define( 'LC_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'LC_CORE_URL', plugin_dir_url( __FILE__ ) );

/*
 * Module includes. Pure logic (no WordPress calls) lives in inc/import-lib.php so it
 * can be unit-tested standalone; the WordPress glue lives in inc/importer.php.
 */
require_once LC_CORE_DIR . 'inc/import-lib.php';   // pure helpers: key derivation, header assertion, type detect, url check
require_once LC_CORE_DIR . 'inc/config.php';       // per-site config accessors (filter-driven)
require_once LC_CORE_DIR . 'inc/importer.php';     // config-driven CSV upsert importer (the crown jewel)
require_once LC_CORE_DIR . 'inc/kses.php';         // filterable wp_kses_allowed_html additions
require_once LC_CORE_DIR . 'inc/query-filters.php'; // generic GET-param -> Bricks query-var augmenter

/*
 * Optional: load the bundled example config (fictional "Maple Ridge Farmers Market"
 * site) for local demos / smoke tests. OFF by default so core ships site-agnostic.
 * A real site copies config/example-config.php into its own mu-plugin and edits it —
 * it does NOT enable this constant in production.
 */
if ( defined( 'LC_CORE_LOAD_EXAMPLE_CONFIG' ) && LC_CORE_LOAD_EXAMPLE_CONFIG ) {
	require_once LC_CORE_DIR . 'config/example-config.php';
}

/*
 * Per-site config layer. A deployment may ship config/site-config.php (kept out
 * of the generic repo — see .gitignore) with its CPTs / taxonomies / ACF field
 * groups / import mappings / modules. Loaded automatically when present, so a
 * site deploy is a single zip with no separate mu-plugin to install.
 */
if ( file_exists( LC_CORE_DIR . 'config/site-config.php' ) ) {
	require_once LC_CORE_DIR . 'config/site-config.php';
}

/**
 * Activation. lc-core itself registers nothing — CPTs/taxonomies/fields are the
 * site's job. We fire an action so the per-site config layer can seed terms, flush
 * rewrites, etc., and we flush rewrite rules so any CPTs the site registered on
 * `init` take effect immediately.
 */
function lc_core_activate() {
	/**
	 * Fires on plugin activation. Hook this from the per-site config layer to seed
	 * taxonomy terms, register default options, etc.
	 */
	do_action( 'lc_core_activate' );
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'lc_core_activate' );

/**
 * Zip-replace updates don't re-fire activation — run once per version bump so a
 * deploy that ships new config can re-seed. Mirrors the predecessor plugin's maybe_upgrade pattern.
 */
function lc_core_maybe_upgrade() {
	if ( get_option( 'lc_core_version' ) === LC_CORE_VERSION ) {
		return;
	}
	/**
	 * Fires once after the plugin version changes. Passes the previous version
	 * string (or '' on first run) so the site can run migrations.
	 */
	do_action( 'lc_core_upgrade', (string) get_option( 'lc_core_version', '' ) );
	update_option( 'lc_core_version', LC_CORE_VERSION );
	flush_rewrite_rules();
}
add_action( 'admin_init', 'lc_core_maybe_upgrade' );

/**
 * WP-CLI: `wp lc import <file> [--dry-run] [--assert-idempotent]`
 *         `wp lc import-dir <dir> [--dry-run] [--assert-idempotent]`
 *
 * --dry-run           detect + match, write nothing, report what WOULD happen.
 * --assert-idempotent after import, fail (exit non-zero) if created > 0. Run this
 *                     as a second pass to prove the import is safe to re-run.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'lc import', function ( $args, $assoc ) {
		$opts = array(
			'dry_run'           => isset( $assoc['dry-run'] ),
			'assert_idempotent' => isset( $assoc['assert-idempotent'] ),
		);
		$report = lc_core_import_csv_file( $args[0], $opts );
		WP_CLI::log( wp_json_encode( $report ) );
		if ( ! empty( $report['error'] ) ) {
			WP_CLI::error( 'Import failed: ' . $report['error'] );
		}
		if ( isset( $report['idempotency_ok'] ) && false === $report['idempotency_ok'] ) {
			WP_CLI::error( "Idempotency assertion FAILED: re-run created {$report['created']} post(s); expected 0." );
		}
		WP_CLI::success( 'Import complete.' );
	} );

	WP_CLI::add_command( 'lc import-dir', function ( $args, $assoc ) {
		$opts = array(
			'dry_run'           => isset( $assoc['dry-run'] ),
			'assert_idempotent' => isset( $assoc['assert-idempotent'] ),
		);
		$reports = lc_core_import_dir( $args[0], $opts );
		WP_CLI::log( wp_json_encode( $reports ) );
		foreach ( $reports as $r ) {
			if ( isset( $r['idempotency_ok'] ) && false === $r['idempotency_ok'] ) {
				WP_CLI::error( "Idempotency assertion FAILED on {$r['file']}." );
			}
		}
		WP_CLI::success( 'Directory import complete.' );
	} );
}
