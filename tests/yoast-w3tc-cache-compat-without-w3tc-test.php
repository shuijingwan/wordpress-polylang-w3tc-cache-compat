<?php
/**
 * Regression test for preserving Yoast's cache fallback when W3TC is absent.
 *
 * Run with: php tests/yoast-w3tc-cache-compat-without-w3tc-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$test_actions       = array();
$test_current_filter = array();
$test_doing_cron    = true;
$test_utils_calls   = 0;

function yoast_w3tc_cache_compat_without_w3tc_test_id( $callback ) {
	if ( is_string( $callback ) ) {
		return $callback;
	}

	return $callback[0] . '::' . $callback[1];
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	global $test_actions;
	$test_actions[ $hook ][ $priority ][ yoast_w3tc_cache_compat_without_w3tc_test_id( $callback ) ] = array(
		'callback'      => $callback,
		'accepted_args' => $accepted_args,
	);
}

function has_action( $hook, $callback = false ) {
	global $test_actions;
	$id = yoast_w3tc_cache_compat_without_w3tc_test_id( $callback );
	foreach ( $test_actions[ $hook ] ?? array() as $priority => $callbacks ) {
		if ( isset( $callbacks[ $id ] ) ) {
			return $priority;
		}
	}
	return false;
}

function remove_action( $hook, $callback, $priority = 10 ) {
	global $test_actions;
	$id = yoast_w3tc_cache_compat_without_w3tc_test_id( $callback );
	if ( ! isset( $test_actions[ $hook ][ $priority ][ $id ] ) ) {
		return false;
	}
	unset( $test_actions[ $hook ][ $priority ][ $id ] );
	return true;
}

function current_action() {
	global $test_current_filter;
	return end( $test_current_filter );
}

function wp_doing_cron() {
	global $test_doing_cron;
	return $test_doing_cron;
}

function yoast_w3tc_cache_compat_without_w3tc_test_do_action( $hook ) {
	global $test_actions, $test_current_filter;
	$test_current_filter[] = $hook;
	$priorities = array_keys( $test_actions[ $hook ] ?? array() );
	sort( $priorities, SORT_NUMERIC );
	foreach ( $priorities as $priority ) {
		foreach ( $test_actions[ $hook ][ $priority ] ?? array() as $item ) {
			call_user_func( $item['callback'] );
		}
	}
	array_pop( $test_current_filter );
}

function yoast_w3tc_cache_compat_without_w3tc_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

class WPSEO_Utils {
	public static function clear_cache() {
		global $test_utils_calls;
		++$test_utils_calls;
	}
}

require dirname( __DIR__ ) . '/src/yoast-w3tc-cache-compat.php';

$callback = array( 'WPSEO_Utils', 'clear_cache' );
add_action( 'update_option_wpseo', $callback, 10, 1 );

yoast_w3tc_cache_compat_without_w3tc_test_assert(
	! function_exists( 'w3tc_flush_posts' ),
	'This independent process deliberately has no W3TC flush function.'
);

yoast_w3tc_cache_compat_without_w3tc_test_do_action( 'wpseo_detect_default_seo_data' );

yoast_w3tc_cache_compat_without_w3tc_test_assert(
	10 === has_action( 'update_option_wpseo', $callback ),
	'W3TC absence leaves WPSEO_Utils::clear_cache registered at priority 10.'
);
yoast_w3tc_cache_compat_without_w3tc_test_assert(
	empty( $yoast_w3tc_cache_compat_suppression_active ),
	'W3TC absence does not set suppression state.'
);
yoast_w3tc_cache_compat_without_w3tc_test_assert(
	1 === count( $test_actions['update_option_wpseo'][10] ),
	'End handler does not duplicate the original callback.'
);

yoast_w3tc_cache_compat_without_w3tc_test_do_action( 'update_option_wpseo' );
yoast_w3tc_cache_compat_without_w3tc_test_assert(
	1 === $test_utils_calls,
	'Yoast cache callback still executes so its non-W3TC fallback is preserved.'
);

echo "Yoast W3TC cache compatibility without-W3TC test passed.\n";
