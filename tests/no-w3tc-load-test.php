<?php
/**
 * Confirms the MU plugin remains inert and fatal-free when W3TC is unavailable.
 *
 * Run with: php tests/no-w3tc-load-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$test_actions         = array();
$test_removed_actions = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	global $test_actions;
	$test_actions[] = array( $hook, $callback, $priority, $accepted_args );
}

function remove_action( $hook, $callback, $priority = 10 ) {
	global $test_removed_actions;
	$test_removed_actions[] = array( $hook, $callback, $priority );
}

require dirname( __DIR__ ) . '/src/polylang-w3tc-cache-compat.php';

polylang_w3tc_cache_compat_replace_w3tc_term_flushes();

if ( ! empty( $test_removed_actions ) ) {
	fwrite( STDERR, "FAIL: W3TC callbacks must not be removed when W3TC is unavailable.\n" );
	exit( 1 );
}

echo "OK\n";
