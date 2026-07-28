<?php
/** Standalone tests for the LC Core KSES value-level guard. */

define( 'ABSPATH', __DIR__ . '/' );

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return true;
}

function apply_filters( $hook, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return $value;
}

require __DIR__ . '/../inc/kses.php';

$failures  = 0;
$assertions = 0;

function kses_check( $label, $actual, $expected ) {
	global $failures, $assertions;
	++$assertions;
	if ( $actual === $expected ) {
		echo "  ok    {$label}\n";
		return;
	}
	++$failures;
	echo "  FAIL  {$label}\n";
}

$allowed = array( 'input' => array( 'type' => true, 'id' => true ) );
kses_check(
	'radio input survives',
	lc_core_kses_restrict_input_types( '<input type="radio" id="a">', $allowed ),
	'<input type="radio" id="a">'
);
kses_check(
	'checkbox input survives',
	lc_core_kses_restrict_input_types( "<input type='checkbox'>", $allowed ),
	"<input type='checkbox'>"
);
kses_check(
	'password input is removed',
	lc_core_kses_restrict_input_types( '<p>A</p><input type="password"><p>B</p>', $allowed ),
	'<p>A</p><p>B</p>'
);
kses_check(
	'missing type is removed',
	lc_core_kses_restrict_input_types( '<input name="x">', $allowed ),
	''
);
kses_check(
	'other KSES contexts remain untouched',
	lc_core_kses_restrict_input_types( '<input type="password">', array() ),
	'<input type="password">'
);

echo "\n{$assertions} assertions, {$failures} failure(s)\n";
exit( $failures ? 1 : 0 );
