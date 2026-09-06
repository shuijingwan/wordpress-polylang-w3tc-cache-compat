<?php
/**
 * Plugin Name: W3TC Full Flush Tracer
 * Description: Temporary, passive tracing for W3 Total Cache whole Page Cache flush requests.
 * Version: 0.1.0
 * Requires at least: 6.1
 * Requires PHP: 7.4
 * License: MIT
 * License URI: https://opensource.org/license/mit
 */

defined( 'ABSPATH' ) || exit;

/**
 * The log is deliberately independent from WordPress and PHP error logs.
 *
 * Define W3TC_FULL_FLUSH_TRACER_LOG_FILE before this MU plugin loads to use a
 * different path in a non-production test environment.
 */
defined( 'W3TC_FULL_FLUSH_TRACER_LOG_FILE' ) || define(
	'W3TC_FULL_FLUSH_TRACER_LOG_FILE',
	'/var/log/w3tc-full-flush-tracer.log'
);

/**
 * Stop appending rather than allowing a temporary diagnostic log to grow
 * indefinitely. This tracer never rotates, renames, truncates, or deletes a
 * log file.
 */
defined( 'W3TC_FULL_FLUSH_TRACER_MAX_BYTES' ) || define(
	'W3TC_FULL_FLUSH_TRACER_MAX_BYTES',
	5 * 1024 * 1024
);

/**
 * Converts a value that may originate in request metadata into one log line.
 *
 * @param mixed $value Value to normalize.
 * @return string Normalized value.
 */
function w3tc_full_flush_tracer_log_value( $value ) {
	if ( ! is_scalar( $value ) && null !== $value ) {
		return '';
	}

	return str_replace( array( "\r", "\n" ), '', (string) $value );
}

/**
 * Creates a compact, argument-free backtrace suitable for a diagnostic log.
 *
 * @return array<int, array<string, int|string>>
 */
function w3tc_full_flush_tracer_backtrace() {
	$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 );
	$result = array();

	foreach ( $frames as $frame ) {
		$result[] = array(
			'file'     => isset( $frame['file'] ) ? (string) $frame['file'] : '',
			'line'     => isset( $frame['line'] ) ? (int) $frame['line'] : 0,
			'callable' => ( isset( $frame['class'] ) ? (string) $frame['class'] . ( $frame['type'] ?? '' ) : '' ) . ( $frame['function'] ?? '' ),
		);
	}

	return $result;
}

/**
 * Records a W3TC whole-Page-Cache flush action without changing its behavior.
 *
 * The callback intentionally accepts no action arguments, changes no return
 * value, and runs before W3TC's Page Cache callback at priority 1100. The
 * current filter stack retains the parent WordPress cron hook when a cron
 * callback invokes w3tc_flush_posts() or w3tc_flush_all().
 *
 * @param string $event W3TC action name.
 * @return void
 */
function w3tc_full_flush_tracer_record( $event ) {
	$log_file = W3TC_FULL_FLUSH_TRACER_LOG_FILE;

	clearstatcache( true, $log_file );
	if ( is_file( $log_file ) && filesize( $log_file ) >= W3TC_FULL_FLUSH_TRACER_MAX_BYTES ) {
		return;
	}

	global $wp_current_filter;

	$now = microtime( true );
	$seconds = (int) $now;
	$microseconds = (int) round( ( $now - $seconds ) * 1000000 );
	if ( 1000000 === $microseconds ) {
		$seconds++;
		$microseconds = 0;
	}

	$record = array(
		'marker'            => 'W3TC_FULL_FLUSH_TRACER',
		'timestamp_utc'     => gmdate( 'Y-m-d\\TH:i:s', $seconds ) . sprintf( '.%06dZ', $microseconds ),
		'event'             => $event,
		'pid'               => getmypid(),
		'php_sapi'          => PHP_SAPI,
		'wp_doing_cron'     => function_exists( 'wp_doing_cron' ) ? (bool) wp_doing_cron() : false,
		'doing_cron'        => defined( 'DOING_CRON' ) && DOING_CRON,
		'wp_cli'            => defined( 'WP_CLI' ) && WP_CLI,
		'request_method'    => w3tc_full_flush_tracer_log_value( $_SERVER['REQUEST_METHOD'] ?? '' ),
		'request_uri'       => w3tc_full_flush_tracer_log_value( $_SERVER['REQUEST_URI'] ?? '' ),
		'http_host'         => w3tc_full_flush_tracer_log_value( $_SERVER['HTTP_HOST'] ?? '' ),
		'current_user_id'   => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
		'wp_current_filter' => is_array( $wp_current_filter ) ? array_values( $wp_current_filter ) : array(),
		'backtrace'         => w3tc_full_flush_tracer_backtrace(),
	);

	$encoded = wp_json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === $encoded ) {
		return;
	}

	// FILE_APPEND|LOCK_EX only appends this observation; it never touches cache files or W3TC state.
	@file_put_contents( $log_file, $encoded . "\n", FILE_APPEND | LOCK_EX );
}

/**
 * @return void
 */
function w3tc_full_flush_tracer_record_posts() {
	w3tc_full_flush_tracer_record( 'w3tc_flush_posts' );
}

/**
 * @return void
 */
function w3tc_full_flush_tracer_record_all() {
	w3tc_full_flush_tracer_record( 'w3tc_flush_all' );
}

add_action( 'w3tc_flush_posts', 'w3tc_full_flush_tracer_record_posts', 1, 0 );
add_action( 'w3tc_flush_all', 'w3tc_full_flush_tracer_record_all', 1, 0 );
