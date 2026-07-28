<?php
/**
 * LC Core — standalone unit tests for inc/import-lib.php (NO WordPress).
 *
 * Run: php tests/test-import-lib.php   (exit 0 = pass, 1 = fail)
 *
 * These pin the identity + validation doctrine. If a change breaks one of these,
 * it is probably reintroducing a fixed production incident — read the test name
 * and CHANGELOG.md before "fixing" the test.
 */

error_reporting( E_ALL );

require __DIR__ . '/../inc/import-lib.php';

$failures = 0;
$assertions = 0;

function check( $label, $actual, $expected ) {
	global $failures, $assertions;
	$assertions++;
	if ( $actual === $expected ) {
		echo "  ok    {$label}\n";
	} else {
		$failures++;
		echo "  FAIL  {$label}\n";
		echo '        expected: ' . var_export( $expected, true ) . "\n";
		echo '        actual:   ' . var_export( $actual, true ) . "\n";
	}
}

echo "== lc_core_normalize_title ==\n";
check( 'collapses internal whitespace (0.6.6 dupe fix)', lc_core_normalize_title( "Cr\xC3\xA8me  Br\xC3\xBBl\xC3\xA9e\tCo " ), "Cr\xC3\xA8me Br\xC3\xBBl\xC3\xA9e Co" );
check( 'trims', lc_core_normalize_title( '  Maple Ridge Market  ' ), 'Maple Ridge Market' );
check( 'newlines collapse to space', lc_core_normalize_title( "Red\nCross" ), 'Red Cross' );
check( 'curly apostrophes fold to okina', lc_core_normalize_title( "Br\xC3\xBBl\xC3\xA9e\xE2\x80\x99s" ), "Br\xC3\xBBl\xC3\xA9e\xCA\xBBs" );
check( 'empty stays empty', lc_core_normalize_title( '' ), '' );

echo "== lc_core_derive_import_key ==\n";
// Identity = TITLE ONLY. These digests are the SPEC (must match Python hkey()).
check( '12 hex chars', strlen( lc_core_derive_import_key( 'Maple Ridge Market' ) ), 12 );
check( 'known digest ("Maple Ridge Market")', lc_core_derive_import_key( 'Maple Ridge Market' ), substr( md5( 'maple ridge market' ), 0, 12 ) );
check( 'case-insensitive', lc_core_derive_import_key( 'MAPLE RIDGE MARKET' ), lc_core_derive_import_key( 'maple ridge market' ) );
check( 'whitespace-drift-insensitive', lc_core_derive_import_key( "Maple  Ridge\tMarket" ), lc_core_derive_import_key( 'Maple Ridge Market' ) );
check( 'empty title -> empty key', lc_core_derive_import_key( '   ' ), '' );
// The load-bearing property: a corrected URL must NOT change identity — the key
// function does not even accept a URL. (Compile-time guarantee; assert arity.)
$ref = new ReflectionFunction( 'lc_core_derive_import_key' );
check( 'key derivation takes TITLE ONLY (no url param)', $ref->getNumberOfParameters(), 1 );

echo "== lc_core_assert_headers ==\n";
check( 'all present -> empty', lc_core_assert_headers( array( 'post_title', 'url', 'summary' ), array( 'post_title', 'url' ) ), array() );
check( 'missing column reported', lc_core_assert_headers( array( 'post_title', 'summary' ), array( 'post_title', 'url' ) ), array( 'url' ) );
check( 'order of required preserved', lc_core_assert_headers( array(), array( 'a', 'b' ) ), array( 'a', 'b' ) );
check( 'exact titles, not positions', lc_core_assert_headers( array( 'url', 'post_title' ), array( 'post_title', 'url' ) ), array() );

echo "== lc_core_detect_type ==\n";
$config = array(
	'mrfm_vendor' => array( 'signature' => array( 'vendor_category' ) ),
	'mrfm_event'  => array( 'signature' => array( 'event_date' ) ),
	'mrfm_both'   => array( 'signature' => array( 'vendor_category', 'event_date' ) ),
);
check( 'single signature match', lc_core_detect_type( array( 'post_title', 'vendor_category' ), $config ), 'mrfm_vendor' );
check( 'second type', lc_core_detect_type( array( 'post_title', 'event_date' ), $config ), 'mrfm_event' );
check( 'ambiguous signatures fail closed', lc_core_detect_type( array( 'vendor_category', 'event_date' ), $config ), '' );
check( 'all ambiguous matches are reported', lc_core_detect_types( array( 'vendor_category', 'event_date' ), $config ), array( 'mrfm_vendor', 'mrfm_event', 'mrfm_both' ) );
check( 'no match -> empty', lc_core_detect_type( array( 'post_title' ), $config ), '' );
check( 'multi-column signature needs ALL', lc_core_detect_type( array( 'event_date' ), array( 'x' => array( 'signature' => array( 'event_date', 'venue' ) ) ) ), '' );
check( 'empty config -> empty', lc_core_detect_type( array( 'post_title' ), array() ), '' );

echo "== lc_core_is_url ==\n";
check( 'https ok', lc_core_is_url( 'https://example.org/x' ), true );
check( 'http ok', lc_core_is_url( 'http://example.org' ), true );
check( 'empty ok (optional field)', lc_core_is_url( '' ), true );
check( 'bare domain rejected', lc_core_is_url( 'example.org' ), false );
check( 'email rejected', lc_core_is_url( 'help@example.org' ), false );
check( 'case-insensitive scheme', lc_core_is_url( 'HTTPS://example.org' ), true );

echo "== lc_core_coerce_bool ==\n";
check( '"1" -> 1', lc_core_coerce_bool( '1' ), 1 );
check( '"0" -> 0', lc_core_coerce_bool( '0' ), 0 );
check( '"yes" -> 0 (strict, matches predecessor)', lc_core_coerce_bool( 'yes' ), 0 );
check( 'empty -> 0', lc_core_coerce_bool( '' ), 0 );

echo "\n{$assertions} assertions, {$failures} failure(s)\n";
exit( $failures ? 1 : 0 );
