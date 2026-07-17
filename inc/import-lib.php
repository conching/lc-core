<?php
/**
 * LC Core — pure import helpers (NO WordPress dependency).
 *
 * This file defines functions only; it has no top-level side effects and produces
 * no output, so it is safe to include from anywhere (and is deliberately NOT guarded
 * by an ABSPATH check, so the standalone test runner can include it without booting
 * WordPress). All WordPress glue lives in inc/importer.php.
 *
 * These helpers encode the identity + validation doctrine the predecessor plugin's
 * importer evolved through production incidents. See CHANGELOG.md / the extraction report.
 */

if ( ! function_exists( 'lc_core_normalize_title' ) ) :
	/**
	 * Normalize a title for identity + matching.
	 *
	 * Mirrors normalize_workbook.py::norm(): NFC-normalize (when the intl
	 * Normalizer is available), fold curly quotes to the ʻokina, collapse all
	 * internal whitespace to single spaces, and trim. The predecessor plugin added the
	 * whitespace collapse after a stray double-space pasted from a spreadsheet
	 * spawned a duplicate.
	 *
	 * @param string $title Raw title.
	 * @return string Normalized title (original case preserved).
	 */
	function lc_core_normalize_title( $title ) {
		$title = (string) $title;
		if ( class_exists( 'Normalizer' ) ) {
			$n = Normalizer::normalize( $title, Normalizer::FORM_C );
			if ( is_string( $n ) ) {
				$title = $n;
			}
		}
		// Fold typographic single quotes to the ʻokina so curly-apostrophe and ʻokina spellings of a title match.
		$title = str_replace( array( "\xE2\x80\x98", "\xE2\x80\x99" ), "\xCA\xBB", $title );
		$title = preg_replace( '/\s+/u', ' ', $title );
		return trim( (string) $title );
	}
endif;

if ( ! function_exists( 'lc_core_derive_import_key' ) ) :
	/**
	 * Derive a stable import key from the TITLE ONLY.
	 *
	 * Identity must never depend on a correctable field (URL, phone, summary):
	 * the predecessor plugin learned this on 2026-07-09 when keys derived from title|url spawned
	 * 26 duplicates the moment an import legitimately corrected a URL. The algorithm
	 * matches normalize_workbook.py::hkey(): md5 of the lowercased, normalized title,
	 * truncated to 12 hex chars.
	 *
	 * @param string $title Raw title.
	 * @return string 12-char hex key ('' if the title is empty).
	 */
	function lc_core_derive_import_key( $title ) {
		$norm = lc_core_normalize_title( $title );
		if ( '' === $norm ) {
			return '';
		}
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $norm, 'UTF-8' ) : strtolower( $norm );
		return substr( md5( $lower ), 0, 12 );
	}
endif;

if ( ! function_exists( 'lc_core_assert_headers' ) ) :
	/**
	 * Header-row validation: return the list of REQUIRED column titles that are
	 * missing from a CSV's actual header row. An empty array means the header is OK.
	 *
	 * Callers fail loudly on a non-empty result. This is the name-based analogue of
	 * the normalizer's positional header guard (added after the client inserted two
	 * columns and positional mapping silently corrupted two imports). Column titles,
	 * never positions.
	 *
	 * @param string[] $headers  Actual header cells (already BOM/space-trimmed).
	 * @param string[] $required Required column titles.
	 * @return string[] Missing column titles (subset of $required, in order).
	 */
	function lc_core_assert_headers( $headers, $required ) {
		$have    = array();
		foreach ( (array) $headers as $h ) {
			$have[ (string) $h ] = true;
		}
		$missing = array();
		foreach ( (array) $required as $col ) {
			if ( ! isset( $have[ (string) $col ] ) ) {
				$missing[] = (string) $col;
			}
		}
		return $missing;
	}
endif;

if ( ! function_exists( 'lc_core_detect_types' ) ) :
	/**
	 * Return every configured post type whose signature matches the headers.
	 *
	 * @param string[] $headers Header cells.
	 * @param array    $config  Import config keyed by post type.
	 * @return string[] Matching post type slugs.
	 */
	function lc_core_detect_types( $headers, $config ) {
		$have = array();
		foreach ( (array) $headers as $h ) {
			$have[ (string) $h ] = true;
		}

		$matches = array();
		foreach ( (array) $config as $type => $def ) {
			if ( empty( $def['signature'] ) ) {
				continue;
			}
			$sig     = (array) $def['signature'];
			$matched = ! empty( $sig );
			foreach ( $sig as $col ) {
				if ( ! isset( $have[ (string) $col ] ) ) {
					$matched = false;
					break;
				}
			}
			if ( $matched ) {
				$matches[] = (string) $type;
			}
		}

		return $matches;
	}
endif;

if ( ! function_exists( 'lc_core_detect_type' ) ) :
	/**
	 * Detect a post type from a CSV header row using per-type signature columns.
	 *
	 * $config is the import config (see inc/config.php): keyed by post type, each
	 * entry may carry a 'signature' => string|string[] of header names that uniquely
	 * identify that type. Exactly one type must match; ambiguous signatures fail closed.
	 *
	 * @param string[] $headers Header cells.
	 * @param array    $config  Import config keyed by post type.
	 * @return string Post type slug, or '' if none matched.
	 */
	function lc_core_detect_type( $headers, $config ) {
		$matches = lc_core_detect_types( $headers, $config );
		return 1 === count( $matches ) ? $matches[0] : '';
	}
endif;

if ( ! function_exists( 'lc_core_is_url' ) ) :
	/**
	 * Post-run type check: does a value look like an http(s) URL?
	 *
	 * Empty is treated as valid (an optional URL field left blank is fine); a
	 * non-empty value must start with http:// or https://. Mirrors the normalizer's
	 * malformed-URL sweep.
	 *
	 * @param string $val Value to check.
	 * @return bool
	 */
	function lc_core_is_url( $val ) {
		$val = trim( (string) $val );
		if ( '' === $val ) {
			return true;
		}
		return (bool) preg_match( '#^https?://#i', $val );
	}
endif;

if ( ! function_exists( 'lc_core_coerce_bool' ) ) :
	/**
	 * Coerce a CSV cell to a strict 1/0 for boolean ACF/meta fields.
	 * Only the literal string "1" (or int 1 / true) is truthy — matches the predecessor plugin.
	 *
	 * @param mixed $val
	 * @return int 1 or 0
	 */
	function lc_core_coerce_bool( $val ) {
		return ( '1' === (string) $val ) ? 1 : 0;
	}
endif;
