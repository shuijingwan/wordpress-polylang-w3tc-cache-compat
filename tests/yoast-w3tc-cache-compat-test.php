<?php
/**
 * Regression test for the Yoast default-SEO-data W3TC flush suppression.
 *
 * Run with: php tests/yoast-w3tc-cache-compat-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$test_actions       = array();
$test_current_filter = array();
$test_doing_cron    = false;
$test_utils_calls   = 0;
$test_options_calls = 0;
$test_watcher_calls = 0;

function w3tc_flush_posts() {}

function test_callback_id( $callback ) {
	if ( is_string( $callback ) ) {
		return $callback;
	}
	if ( $callback instanceof Closure ) {
		return spl_object_hash( $callback );
	}

	return $callback[0] . '::' . $callback[1];
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	global $test_actions;
	$test_actions[ $hook ][ $priority ][ test_callback_id( $callback ) ] = array(
		'callback'      => $callback,
		'accepted_args' => $accepted_args,
	);
}

function has_action( $hook, $callback = false ) {
	global $test_actions;
	$id = test_callback_id( $callback );
	foreach ( $test_actions[ $hook ] ?? array() as $priority => $callbacks ) {
		if ( isset( $callbacks[ $id ] ) ) {
			return $priority;
		}
	}
	return false;
}

function remove_action( $hook, $callback, $priority = 10 ) {
	global $test_actions;
	$id = test_callback_id( $callback );
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

function test_do_action( $hook ) {
	global $test_actions, $test_current_filter;
	$test_current_filter[] = $hook;
	$priorities = array_keys( $test_actions[ $hook ] ?? array() );
	sort( $priorities, SORT_NUMERIC );
	foreach ( $priorities as $priority ) {
		$callbacks = $test_actions[ $hook ][ $priority ] ?? array();
		foreach ( $callbacks as $item ) {
			call_user_func( $item['callback'] );
		}
	}
	array_pop( $test_current_filter );
}

function yoast_w3tc_cache_compat_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

require dirname( __DIR__ ) . '/src/yoast-w3tc-cache-compat.php';
require dirname( __DIR__ ) . '/src/yoast-w3tc-cache-compat.php';

yoast_w3tc_cache_compat_test_assert(
	2 === count( $test_actions['wpseo_detect_default_seo_data'] ),
	'Repeated MU Plugin includes do not duplicate handlers.'
);

/* Yoast absent: its compatibility handlers can load without a fatal. */
yoast_w3tc_cache_compat_begin_default_seo_data_cron();
yoast_w3tc_cache_compat_end_default_seo_data_cron();

class WPSEO_Utils {
	public static function clear_cache() {
		global $test_utils_calls;
		++$test_utils_calls;
	}
}

class WPSEO_Options {
	public static function clear_cache() {
		global $test_options_calls;
		++$test_options_calls;
	}
}

function yoast_w3tc_cache_compat_test_watcher() {
	global $test_watcher_calls;
	++$test_watcher_calls;
}

$utils_callback   = array( 'WPSEO_Utils', 'clear_cache' );
$options_callback = array( 'WPSEO_Options', 'clear_cache' );

add_action( 'update_option_wpseo', $options_callback, 10, 1 );
add_action( 'update_option_wpseo', $utils_callback, 10, 1 );
add_action( 'update_option_wpseo', 'yoast_w3tc_cache_compat_test_watcher', 10, 2 );

/* Non-cron execution never suppresses the callback. */
test_do_action( 'wpseo_detect_default_seo_data' );
yoast_w3tc_cache_compat_test_assert(
	10 === has_action( 'update_option_wpseo', $utils_callback ),
	'Non-cron execution preserves WPSEO_Utils::clear_cache.'
);

/* A callback at another priority must not be changed or recreated. */
remove_action( 'update_option_wpseo', $utils_callback, 10 );
add_action( 'update_option_wpseo', $utils_callback, 9, 1 );
$test_doing_cron = true;
test_do_action( 'wpseo_detect_default_seo_data' );
yoast_w3tc_cache_compat_test_assert(
	9 === has_action( 'update_option_wpseo', $utils_callback ),
	'Non-priority-10 callback is neither removed nor recreated.'
);
remove_action( 'update_option_wpseo', $utils_callback, 9 );

/* No original callback means neither begin nor end adds one. */
test_do_action( 'wpseo_detect_default_seo_data' );
yoast_w3tc_cache_compat_test_assert(
	false === has_action( 'update_option_wpseo', $utils_callback ),
	'Missing original callback is not added.'
);

add_action( 'update_option_wpseo', $utils_callback, 10, 1 );
add_action(
	'wpseo_detect_default_seo_data',
	function () use ( $utils_callback, $options_callback ) {
		yoast_w3tc_cache_compat_test_assert(
			false === has_action( 'update_option_wpseo', $utils_callback ),
			'Cron suppression removes only WPSEO_Utils::clear_cache during the callback.'
		);
		yoast_w3tc_cache_compat_test_assert(
			10 === has_action( 'update_option_wpseo', $options_callback ),
			'WPSEO_Options::clear_cache remains registered during suppression.'
		);
		yoast_w3tc_cache_compat_test_assert(
			10 === has_action( 'update_option_wpseo', 'yoast_w3tc_cache_compat_test_watcher' ),
			'Other Yoast watchers remain registered during suppression.'
		);
		yoast_w3tc_cache_compat_begin_default_seo_data_cron();
		test_do_action( 'update_option_wpseo' );
	},
	5,
	0
);

test_do_action( 'wpseo_detect_default_seo_data' );

yoast_w3tc_cache_compat_test_assert(
	0 === $test_utils_calls,
	'WPSEO_Utils::clear_cache does not run for the suppressed cron option update.'
);
yoast_w3tc_cache_compat_test_assert(
	1 === $test_options_calls && 1 === $test_watcher_calls,
	'WPSEO option cache and watcher callbacks run during suppression.'
);
yoast_w3tc_cache_compat_test_assert(
	10 === has_action( 'update_option_wpseo', $utils_callback ),
	'WPSEO_Utils::clear_cache is restored at priority 10.'
);
yoast_w3tc_cache_compat_test_assert(
	1 === $test_actions['update_option_wpseo'][10][ test_callback_id( $utils_callback ) ]['accepted_args'],
	'Restored callback accepts one argument.'
);

test_do_action( 'update_option_wpseo' );
yoast_w3tc_cache_compat_test_assert(
	1 === $test_utils_calls,
	'After restoration, a later ordinary update executes WPSEO_Utils::clear_cache.'
);

echo "Yoast W3TC cache compatibility test passed.\n";
