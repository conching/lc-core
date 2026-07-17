<?php
/**
 * LC Core — config-driven CSV content importer (extracted from the predecessor plugin).
 *
 * Upsert semantics (DO NOT "simplify" — each line is a fixed production bug):
 *   Identity  : import_key from the CSV, else derived from TITLE ONLY. Never from a
 *               correctable field like URL (a hard-won lesson from the predecessor
 *               plugin: URL repairs spawned 26 duplicates once identity depended on the URL).
 *   Match     : (1) by import-key meta, then (2) by EXACT post_title via get_posts,
 *               post_status => any, skipping trashed posts and posts already claimed
 *               by an earlier row of THIS run. NEVER get_page_by_path — slug lookups
 *               miss drafts (empty post_name) and "-2"-suffixed historical dupes
 *               (this is a fix from the predecessor plugin).
 *   Adopt     : an exact-title match is adopted even if it carries a stale key from
 *               an older keying scheme (orphaned-key adoption), but never a post
 *               another row already claimed this run.
 *
 * Per-type config (CSV column => field/taxonomy target, required columns, url/bool
 * fields, detection signature) comes from inc/config.php.
 *
 * CSV shape: post_title, post_status, taxonomy columns (named like a taxonomy,
 * pipe-separated slugs), mapped field columns, image_filename, import_key,
 * import_notes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import one CSV file.
 *
 * @param string $path Absolute path to the CSV.
 * @param array  $opts {
 *     @type bool $dry_run           Detect + match, write nothing, report what WOULD happen.
 *     @type bool $assert_idempotent After the run, set idempotency_ok=false if created > 0.
 * }
 * @return array Report.
 */
function lc_core_import_csv_file( $path, $opts = array() ) {
	$dry_run = ! empty( $opts['dry_run'] );
	$report  = array(
		'file'          => basename( $path ),
		'type'          => '',
		'created'       => 0,
		'updated'       => 0,
		'skipped'       => 0,
		'terms_created' => 0,
		'dry_run'       => $dry_run,
		'url_warnings'  => array(),
		'notes'         => array(),
		'error'         => '',
	);

	if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
		$report['error'] = 'File not readable.';
		return $report;
	}

	$config = lc_core_import_config();
	if ( empty( $config ) ) {
		$report['error'] = 'No import config registered. Hook the `lc_core_import_config` filter (see config/example-config.php).';
		return $report;
	}

	$fh      = fopen( $path, 'r' );
	$headers = fgetcsv( $fh );
	if ( ! $headers ) {
		fclose( $fh );
		$report['error'] = 'Empty CSV.';
		return $report;
	}
	$headers = array_map(
		function ( $x ) {
			return trim( (string) $x, "\xEF\xBB\xBF \t" ); // strip UTF-8 BOM + surrounding space
		},
		$headers
	);

	$type = lc_core_detect_type( $headers, $config );
	$report['type'] = $type;
	if ( ! $type ) {
		fclose( $fh );
		$report['error'] = 'Could not detect post type from headers. Check the `signature` columns in your import config. Header: ' . implode( ', ', $headers );
		return $report;
	}
	if ( ! post_type_exists( $type ) ) {
		fclose( $fh );
		$report['error'] = "Detected type '{$type}' is not a registered post type. Register it in your site config.";
		return $report;
	}

	// --- header-title assertion: fail loudly if a required column is missing ---
	$missing = lc_core_assert_headers( $headers, lc_core_type_required_columns( $type ) );
	if ( ! empty( $missing ) ) {
		fclose( $fh );
		$report['error'] = "Header validation failed for '{$type}': missing column(s): " . implode( ', ', $missing )
			. '. Refusing to import (positional drift corrupts data). Full header: ' . implode( ', ', $headers );
		return $report;
	}

	$fmap     = lc_core_type_field_map( $type );
	$bools    = lc_core_type_bool_fields( $type );
	$url_cols = lc_core_type_url_fields( $type );
	$taxes    = get_object_taxonomies( $type );
	$key_meta = lc_core_import_key_meta();

	$claimed      = array(); // post IDs matched/created by THIS file's rows (same-run guard).
	$seen_titles  = array(); // dry-run only: normalized titles already processed this run.

	while ( ( $raw = fgetcsv( $fh ) ) !== false ) {
		if ( count( $raw ) === 1 && ! trim( (string) $raw[0] ) ) {
			continue; // blank line
		}
		$row = array();
		foreach ( $headers as $i => $col ) {
			$row[ $col ] = isset( $raw[ $i ] ) ? trim( (string) $raw[ $i ] ) : '';
		}

		$title = lc_core_normalize_title( isset( $row['post_title'] ) ? $row['post_title'] : '' );
		if ( '' === $title ) {
			$report['skipped']++;
			continue;
		}

		// import_key from the CSV, else derived from the TITLE ONLY.
		$key = ( isset( $row['import_key'] ) && '' !== $row['import_key'] )
			? $row['import_key']
			: lc_core_derive_import_key( $title );

		$status = ( isset( $row['post_status'] ) && in_array( $row['post_status'], array( 'publish', 'draft' ), true ) )
			? $row['post_status']
			: 'publish';

		// --- find existing: import-key meta, then EXACT title ---
		$existing = 0;
		if ( $key ) {
			$found = get_posts( array(
				'post_type'        => $type,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => $key_meta,
				'meta_value'       => $key,
				'suppress_filters' => false,
			) );
			if ( $found ) {
				$existing = (int) $found[0];
			}
		}
		if ( ! $existing ) {
			// Title IS identity. Adopt an exact-title match even if it carries a
			// stale key. EXACT TITLE, not slug: slug lookups miss drafts (empty
			// post_name) and "-2"-suffixed posts. Only refuse posts another row of
			// THIS run already claimed, and never a trashed post.
			$found_by_title = get_posts( array(
				'post_type'        => $type,
				'post_status'      => 'any',
				'numberposts'      => 5,
				'fields'           => 'ids',
				'title'            => $title,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			) );
			foreach ( $found_by_title as $candidate_id ) {
				if ( ! in_array( (int) $candidate_id, $claimed, true ) && 'trash' !== get_post_status( $candidate_id ) ) {
					$existing = (int) $candidate_id;
					break;
				}
			}
		}

		// Dry-run: a duplicate title within this same file counts as an update, since
		// the first occurrence would already exist by the time the second is processed.
		if ( $dry_run && ! $existing && isset( $seen_titles[ $title ] ) ) {
			$existing = -1; // sentinel: "would match the row we just would-have-created"
		}

		$postarr = array( 'post_type' => $type, 'post_title' => $title, 'post_status' => $status );

		if ( $dry_run ) {
			if ( $existing ) {
				$report['updated']++;
			} else {
				$report['created']++;
			}
			$seen_titles[ $title ] = true;
			// No writes; move on. (Field/term/url checks require a real post ID.)
			continue;
		}

		if ( $existing ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( $postarr, true );
			$report['updated']++;
		} else {
			$post_id = wp_insert_post( $postarr, true );
			$report['created']++;
		}
		if ( is_wp_error( $post_id ) ) {
			$report['skipped']++;
			$report['notes'][] = "Error on '{$title}': " . $post_id->get_error_message();
			continue;
		}
		$claimed[] = (int) $post_id;
		if ( $key ) {
			update_post_meta( $post_id, $key_meta, $key );
		}

		// --- fields ---
		foreach ( $fmap as $col => $field ) {
			if ( ! isset( $row[ $col ] ) ) {
				continue;
			}
			$val = $row[ $col ];

			if ( 0 === strpos( $field, '_tax:' ) ) { // free-vocab taxonomy from a text column
				$tax = substr( $field, 5 );
				if ( taxonomy_exists( $tax ) ) {
					if ( '' !== $val ) {
						$term = lc_core_ensure_term( $val, $tax, $report );
						wp_set_object_terms( $post_id, $term ? array( $term ) : array(), $tax, false );
					} else {
						wp_set_object_terms( $post_id, array(), $tax, false ); // blank = clear stale terms
					}
				}
				continue;
			}

			if ( in_array( $col, $bools, true ) ) {
				$val = lc_core_coerce_bool( $val );
			}

			if ( function_exists( 'update_field' ) ) {
				update_field( $field, $val, $post_id );
			} else {
				update_post_meta( $post_id, $field, $val );
			}

			// --- post-run URL sanity check ---
			if ( in_array( $col, $url_cols, true ) && ! lc_core_is_url( (string) $val ) ) {
				$report['url_warnings'][] = "Non-URL value in '{$col}' on '{$title}': " . (string) $val;
			}
		}

		// --- taxonomies (columns named exactly like a registered taxonomy, pipe-separated slugs) ---
		foreach ( $taxes as $tax ) {
			if ( ! isset( $row[ $tax ] ) ) {
				continue;
			}
			$slugs = array_filter( array_map( 'trim', explode( '|', $row[ $tax ] ) ) );
			$ids   = array();
			foreach ( $slugs as $slug ) {
				$term = get_term_by( 'slug', $slug, $tax );
				if ( ! $term ) {
					$tid = lc_core_ensure_term( $slug, $tax, $report, $slug );
					if ( $tid ) {
						$ids[] = $tid;
					}
				} else {
					$ids[] = (int) $term->term_id;
				}
			}
			wp_set_object_terms( $post_id, $ids, $tax, false );
		}

		// --- featured image by filename (no-op until media is uploaded; re-run to attach) ---
		if ( ! empty( $row['image_filename'] ) ) {
			$att = lc_core_find_attachment( $row['image_filename'] );
			if ( $att ) {
				set_post_thumbnail( $post_id, $att );
			} else {
				$report['notes'][] = "Image not in Media Library yet: {$row['image_filename']} ('{$title}')";
			}
		}
		if ( ! empty( $row['import_notes'] ) ) {
			update_post_meta( $post_id, lc_core_import_notes_meta(), $row['import_notes'] );
		}
	}
	fclose( $fh );

	// --- idempotency assertion (run this as a SECOND, identical pass) ---
	if ( ! empty( $opts['assert_idempotent'] ) ) {
		$report['idempotency_ok'] = ( 0 === (int) $report['created'] );
		if ( ! $report['idempotency_ok'] ) {
			$report['notes'][] = "IDEMPOTENCY FAILURE: this run created {$report['created']} post(s); a re-run must create 0.";
		}
	}

	return $report;
}

/**
 * Import every *.csv in a directory (alphabetical).
 *
 * @param string $dir  Absolute directory path.
 * @param array  $opts See lc_core_import_csv_file().
 * @return array[] One report per file.
 */
function lc_core_import_dir( $dir, $opts = array() ) {
	$reports = array();
	$dir     = rtrim( (string) $dir, '/' );
	foreach ( (array) glob( $dir . '/*.csv' ) as $file ) {
		$reports[] = lc_core_import_csv_file( $file, $opts );
	}
	return $reports;
}

/**
 * Create a term if missing; return its term_id. Resolves through slug, then name,
 * then the alias meta (a JSON array on each term; key from lc_core_term_alias_meta()).
 *
 * @param string $name_or_slug Raw value from the CSV.
 * @param string $tax          Taxonomy.
 * @param array  $report       Report (by ref) — collects terms_created + notes.
 * @param string $slug         Optional explicit slug (used when the column IS slugs).
 * @return int term_id, or 0 on failure.
 */
function lc_core_ensure_term( $name_or_slug, $tax, &$report, $slug = '' ) {
	$alias_meta = lc_core_term_alias_meta();

	$term = get_term_by( 'slug', $slug ? $slug : sanitize_title( $name_or_slug ), $tax );
	if ( ! $term ) {
		$term = get_term_by( 'name', $name_or_slug, $tax );
	}
	if ( ! $term ) {
		$needle = strtolower( trim( $name_or_slug ) );
		$all    = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
		foreach ( is_wp_error( $all ) ? array() : $all as $t ) {
			$aliases = json_decode( (string) get_term_meta( $t->term_id, $alias_meta, true ), true );
			if ( is_array( $aliases ) && in_array( $needle, array_map( 'strtolower', $aliases ), true ) ) {
				$term = $t;
				break;
			}
		}
	}
	if ( $term ) {
		return (int) $term->term_id;
	}

	$res = wp_insert_term( $name_or_slug, $tax, $slug ? array( 'slug' => $slug ) : array() );
	if ( is_wp_error( $res ) ) {
		$report['notes'][] = "Term create failed '{$name_or_slug}' ({$tax}): " . $res->get_error_message();
		return 0;
	}
	$report['terms_created']++;
	$report['notes'][] = "Created missing term '{$name_or_slug}' in {$tax} — review label.";
	return (int) $res['term_id'];
}

/**
 * Find an attachment ID by original filename.
 *
 * @param string $filename
 * @return int attachment ID, or 0.
 */
function lc_core_find_attachment( $filename ) {
	global $wpdb;
	$name = pathinfo( $filename, PATHINFO_FILENAME );
	$id   = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
			'%' . $wpdb->esc_like( $name ) . '%'
		)
	);
	return $id ? (int) $id : 0;
}

/* -------------------------------------------------------------------------
 * Admin page — Tools ▸ LC Import
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'lc_core_import_admin_menu' );
function lc_core_import_admin_menu() {
	add_management_page( 'LC Import', 'LC Import', 'manage_options', 'lc-import', 'lc_core_import_page' );
}

function lc_core_import_page() {
	echo '<div class="wrap"><h1>LC Core — Content Import</h1>';

	if ( empty( lc_core_import_config() ) ) {
		echo '<div class="notice notice-warning"><p>No import config is registered. Hook the <code>lc_core_import_config</code> filter from your site config layer (see <code>config/example-config.php</code>).</p></div></div>';
		return;
	}

	$last = get_option( 'lc_core_last_import' );
	if ( $last ) {
		echo '<h2>Last run — ' . esc_html( $last['when'] ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>File</th><th>Type</th><th>Created</th><th>Updated</th><th>Skipped</th><th>Terms+</th><th>URL warns</th></tr></thead><tbody>';
		foreach ( $last['reports'] as $r ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td></tr>',
				esc_html( $r['file'] ),
				esc_html( $r['type'] ),
				(int) $r['created'],
				(int) $r['updated'],
				(int) $r['skipped'],
				(int) $r['terms_created'],
				count( isset( $r['url_warnings'] ) ? $r['url_warnings'] : array() )
			);
		}
		echo '</tbody></table>';
	}

	$data_dir = lc_core_import_data_dir();
	if ( $data_dir && is_dir( $data_dir ) ) {
		$bundled = glob( rtrim( $data_dir, '/' ) . '/*.csv' );
		echo '<h2>Bundled data (' . count( $bundled ) . ' files)</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:24px">';
		wp_nonce_field( 'lc_core_import' );
		echo '<input type="hidden" name="action" value="lc_core_import_bundled">';
		echo '<label><input type="checkbox" name="dry_run" value="1"> Dry run (report only, write nothing)</label><br><br>';
		submit_button( 'Run bundled import (safe to re-run)', 'primary', 'submit', false );
		echo '</form>';
	}

	echo '<h2>Upload a CSV</h2>';
	echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'lc_core_import' );
	echo '<input type="hidden" name="action" value="lc_core_import_upload">';
	echo '<input type="file" name="lc_csv" accept=".csv" required> ';
	echo '<label style="margin-left:8px"><input type="checkbox" name="dry_run" value="1"> Dry run</label> ';
	submit_button( 'Import uploaded CSV', 'secondary', 'submit', false );
	echo '</form></div>';
}

add_action( 'admin_post_lc_core_import_bundled', 'lc_core_handle_import_bundled' );
function lc_core_handle_import_bundled() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'lc_core_import' );
	$data_dir = lc_core_import_data_dir();
	$opts     = array( 'dry_run' => ! empty( $_POST['dry_run'] ) );
	$reports  = $data_dir ? lc_core_import_dir( $data_dir, $opts ) : array();
	update_option( 'lc_core_last_import', array( 'when' => gmdate( 'Y-m-d H:i' ) . ' UTC', 'reports' => $reports ), false );
	wp_safe_redirect( admin_url( 'tools.php?page=lc-import' ) );
	exit;
}

add_action( 'admin_post_lc_core_import_upload', 'lc_core_handle_import_upload' );
function lc_core_handle_import_upload() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'lc_core_import' );
	if ( empty( $_FILES['lc_csv']['tmp_name'] ) ) {
		wp_die( 'No file.' );
	}
	$name = isset( $_FILES['lc_csv']['name'] ) ? sanitize_file_name( $_FILES['lc_csv']['name'] ) : 'upload.csv';
	if ( 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
		wp_die( 'CSV only.' );
	}
	$opts           = array( 'dry_run' => ! empty( $_POST['dry_run'] ) );
	$report         = lc_core_import_csv_file( $_FILES['lc_csv']['tmp_name'], $opts );
	$report['file'] = $name;
	update_option( 'lc_core_last_import', array( 'when' => gmdate( 'Y-m-d H:i' ) . ' UTC', 'reports' => array( $report ) ), false );
	wp_safe_redirect( admin_url( 'tools.php?page=lc-import' ) );
	exit;
}
