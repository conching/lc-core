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
