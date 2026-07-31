<?php
/**
 * Plugin Name: Polylang W3TC Cache Compatibility
 * Description: Completes W3 Total Cache invalidation for posts in a Polylang multi-domain setup.
 * Version: 0.1.0
 * Requires at least: 6.1
 * Requires PHP: 7.4
 * License: MIT
 * License URI: https://opensource.org/license/mit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add the post language homepage to W3TC's Page Cache purge queue.
 *
 * @param int        $post_id Post ID being flushed.
 * @param bool       $force   Whether W3TC forced the purge.
 * @param mixed|null $extras  Additional W3TC purge information.
 */
function polylang_w3tc_cache_compat_flush_language_home(
	$post_id,
	$force = false,
	$extras = null
) {
	$post_id = (int) $post_id;

	if (
		$post_id < 1
		|| ! function_exists( 'w3tc_flush_url' )
		|| ! function_exists( 'pll_get_post_language' )
		|| ! function_exists( 'pll_home_url' )
	) {
		return;
	}

	$post = get_post( $post_id );

	if ( ! $post || 'post' !== $post->post_type ) {
		return;
	}

	$language = pll_get_post_language( $post_id, 'slug' );

	if ( ! is_string( $language ) || '' === $language ) {
		return;
	}

	$language_home = pll_home_url( $language );

	if ( ! is_string( $language_home ) || '' === $language_home ) {
		return;
	}

	w3tc_flush_url( trailingslashit( $language_home ) );
}

add_action(
	'w3tc_flush_post',
	'polylang_w3tc_cache_compat_flush_language_home',
	1200,
	3
);

/**
 * Invalidate the shared W3TC Object Cache generation for the posts group.
 *
 * @param int        $post_id Post ID being flushed.
 * @param bool       $force   Whether W3TC forced the purge.
 * @param mixed|null $extras  Additional W3TC purge information.
 */
function polylang_w3tc_cache_compat_flush_posts_group(
	$post_id,
	$force = false,
	$extras = null
) {
	static $flushed = false;

	if ( $flushed ) {
		return;
	}

	$post_id = (int) $post_id;

	if (
		$post_id < 1
		|| ! function_exists( 'wp_cache_flush_group' )
		|| ! function_exists( 'wp_cache_supports' )
		|| ! wp_cache_supports( 'flush_group' )
	) {
		return;
	}

	$post = get_post( $post_id );

	if ( ! $post || 'post' !== $post->post_type ) {
		return;
	}

	$flushed = true;

	wp_cache_flush_group( 'posts' );
}

add_action(
	'w3tc_flush_post',
	'polylang_w3tc_cache_compat_flush_posts_group',
	1250,
	3
);
