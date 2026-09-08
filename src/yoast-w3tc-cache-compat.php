<?php
/**
 * Plugin Name: Yoast W3TC Cache Compatibility
 * Description: Avoids a whole W3 Total Cache Page Cache flush for Yoast's default SEO data reminder cron.
 * Version: 0.1.0
 * Requires at least: 6.1
 * Requires PHP: 7.4
 * License: MIT
 * License URI: https://opensource.org/license/mit
 */

defined( 'ABSPATH' ) || exit;

/* Prevent a second include from declaring or registering duplicate handlers. */
if ( ! function_exists( 'yoast_w3tc_cache_compat_begin_default_seo_data_cron' ) ) {

/**
 * Temporarily removes Yoast's W3TC cache callback for its reminder-data cron.
 *
 * @return void
 */
function yoast_w3tc_cache_compat_begin_default_seo_data_cron() {
	global $yoast_w3tc_cache_compat_suppression_active;

	if ( ! empty( $yoast_w3tc_cache_compat_suppression_active ) ) {
		return;
	}

	if (
		! function_exists( 'wp_doing_cron' )
		|| ! wp_doing_cron()
		|| ! function_exists( 'current_action' )
		|| 'wpseo_detect_default_seo_data' !== current_action()
		|| ! function_exists( 'w3tc_flush_posts' )
	) {
		return;
	}

	$callback = array( 'WPSEO_Utils', 'clear_cache' );

	if ( 10 !== has_action( 'update_option_wpseo', $callback ) ) {
		return;
	}

	$yoast_w3tc_cache_compat_suppression_active = remove_action(
		'update_option_wpseo',
		$callback,
		10
	);
}

/**
 * Restores Yoast's cache callback before another cron event can run.
 *
 * @return void
 */
function yoast_w3tc_cache_compat_end_default_seo_data_cron() {
	global $yoast_w3tc_cache_compat_suppression_active;

	if ( empty( $yoast_w3tc_cache_compat_suppression_active ) ) {
		return;
	}

	add_action(
		'update_option_wpseo',
		array( 'WPSEO_Utils', 'clear_cache' ),
		10,
		1
	);

	$yoast_w3tc_cache_compat_suppression_active = false;
}

add_action(
	'wpseo_detect_default_seo_data',
	'yoast_w3tc_cache_compat_begin_default_seo_data_cron',
	1,
	0
);

add_action(
	'wpseo_detect_default_seo_data',
	'yoast_w3tc_cache_compat_end_default_seo_data_cron',
	999,
	0
);
}
