<?php
/**
 * LC Core — KSES allowlist additions (extracted from the predecessor plugin).
 *
 * Bricks raw-HTML blocks saved through the REST / application-password path run
 * through wp_kses_post(), whose default allowlist has NO form controls. CSS-only
 * tab/accordion patterns (radio + label) therefore lose their <input> on save,
 * silently breaking the component. This module re-allows a small set of INERT form
 * controls (radios/checkboxes are inert without JS — no script vectors added) plus
 * label[for].
 *
 * Everything is filterable so a site can widen or narrow the set:
 *   - `lc_core_kses_enable`        (bool)   master on/off. Default true.
 *   - `lc_core_kses_contexts`      (array)  KSES contexts to apply to. Default ['post'].
 *   - `lc_core_kses_input_atts`    (array)  allowed <input> attributes.
 *   - `lc_core_kses_label_atts`    (array)  allowed <label> attributes.
 *   - `lc_core_kses_allowed_html`  (array)  final chance to edit the merged tag set.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wp_kses_allowed_html', 'lc_core_kses_allow_form_controls', 10, 2 );
add_filter( 'pre_kses', 'lc_core_kses_restrict_input_types', 10, 3 );

function lc_core_kses_allow_form_controls( $tags, $context ) {
	if ( ! apply_filters( 'lc_core_kses_enable', true ) ) {
		return $tags;
	}

	$contexts = (array) apply_filters( 'lc_core_kses_contexts', array( 'post' ) );
	if ( ! in_array( $context, $contexts, true ) ) {
		return $tags;
	}

	$input_atts = (array) apply_filters(
		'lc_core_kses_input_atts',
		array(
			'type'         => true,
			'id'           => true,
			'name'         => true,
			'class'        => true,
			'checked'      => true,
			'value'        => true,
			'placeholder'  => true,
			'aria-label'   => true,
			'autocomplete' => true,
			'tabindex'     => true,
			'disabled'     => true,
		)
	);
	$tags['input'] = $input_atts;

	$label_atts = (array) apply_filters(
		'lc_core_kses_label_atts',
		array(
			'for'   => true,
			'class' => true,
			'id'    => true,
		)
	);
	if ( isset( $tags['label'] ) && is_array( $tags['label'] ) ) {
		$tags['label'] = array_merge( $tags['label'], $label_atts );
	} else {
		$tags['label'] = $label_atts;
	}

	return (array) apply_filters( 'lc_core_kses_allowed_html', $tags, $context );
}

/**
 * Remove input types outside the inert CSS-only contract before KSES runs.
 *
 * KSES allowlists attribute names, not an enum of allowed attribute values. The
 * post-context allowance therefore needs this value-level guard so it cannot be
 * reused for password, file, submit, or other interactive controls.
 *
 * @param string $content           Content before KSES filtering.
 * @param array  $allowed_html      Effective allowed tag/attribute map.
 * @param array  $allowed_protocols Allowed URL protocols (unused).
 * @return string Filtered content.
 */
function lc_core_kses_restrict_input_types( $content, $allowed_html, $allowed_protocols = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( ! isset( $allowed_html['input'] ) ) {
		return $content;
	}

	$allowed_types = (array) apply_filters( 'lc_core_kses_input_types', array( 'radio', 'checkbox' ) );
	return (string) preg_replace_callback(
		'/<input\b[^>]*>/i',
		function ( $match ) use ( $allowed_types ) {
			if ( 1 !== preg_match( '/\btype\s*=\s*(["\']?)([^\s"\'>]+)\1/i', $match[0], $type_match ) ) {
				return '';
			}
			$type = strtolower( $type_match[2] );
			return in_array( $type, $allowed_types, true ) ? $match[0] : '';
		},
		(string) $content
	);
}
