<?php

$test_log = tempnam( sys_get_temp_dir(), 'w3tc-full-flush-tracer-' );
if ( false === $test_log ) {
	fwrite( STDERR, "Could not create test log.\n" );
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'W3TC_FULL_FLUSH_TRACER_LOG_FILE', $test_log );

$registered_actions = array();
$wp_current_filter = array( 'example_cron_hook', 'w3tc_flush_posts' );

function add_action( $hook, $callback, $priority, $accepted_args ) {
	global $registered_actions;
	$registered_actions[ $hook ] = array( $callback, $priority, $accepted_args );
}

function wp_doing_cron() {
	return true;
}

function get_current_user_id() {
	return 0;
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

require dirname( __DIR__ ) . '/src/w3tc-full-flush-tracer.php';

if (
	! isset( $registered_actions['w3tc_flush_posts'], $registered_actions['w3tc_flush_all'] )
	|| 1 !== $registered_actions['w3tc_flush_posts'][1]
	|| 0 !== $registered_actions['w3tc_flush_posts'][2]
) {
	fwrite( STDERR, "Expected passive priority-1 hook registrations.\n" );
	exit( 1 );
}

w3tc_full_flush_tracer_record_posts();

$line = file_get_contents( $test_log );
$record = json_decode( $line, true );
@unlink( $test_log );

if (
	! is_array( $record )
	|| 'W3TC_FULL_FLUSH_TRACER' !== $record['marker']
	|| 'w3tc_flush_posts' !== $record['event']
	|| true !== $record['wp_doing_cron']
	|| array( 'example_cron_hook', 'w3tc_flush_posts' ) !== $record['wp_current_filter']
	|| empty( $record['backtrace'] )
) {
	fwrite( STDERR, "Unexpected trace record.\n" );
	exit( 1 );
}

echo "W3TC full flush tracer test passed.\n";
