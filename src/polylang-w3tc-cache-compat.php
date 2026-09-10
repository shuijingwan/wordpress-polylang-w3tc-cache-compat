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

/**
 * Determines whether a term taxonomy accepts eventual Page Cache consistency.
 *
 * W3TC 2.10.6 attaches its unscoped w3tc_flush_posts() callback to both
 * edited_term and delete_term. That callback flushes the complete Disk
 * Enhanced Page Cache, including every language host. Category and post tag
 * pages on this site may remain stale for the Page Cache TTL (about four
 * days), so they must not cause that site-wide flush.
 *
 * Polylang owns its translation taxonomy name. Ask its runtime term model
 * instead of hard-coding the name, so this remains correct if Polylang changes
 * the implementation detail in a future version.
 *
 * @param string $taxonomy Taxonomy supplied by WordPress' term hook.
 * @return bool Whether W3TC's whole Page Cache flush must be suppressed.
 */
function polylang_w3tc_cache_compat_is_eventually_consistent_term_taxonomy( $taxonomy ) {
	if ( ! is_string( $taxonomy ) ) {
		return false;
	}

	$taxonomies = array( 'category', 'post_tag', 'series' );

	global $polylang;

	if (
		is_object( $polylang )
		&& isset( $polylang->model )
		&& is_object( $polylang->model )
		&& isset( $polylang->model->term )
		&& is_object( $polylang->model->term )
		&& method_exists( $polylang->model->term, 'get_tax_translations' )
	) {
		$translation_taxonomy = $polylang->model->term->get_tax_translations();

		if ( is_string( $translation_taxonomy ) && '' !== $translation_taxonomy ) {
			$taxonomies[] = $translation_taxonomy;
		}
	}

	return in_array( $taxonomy, $taxonomies, true );
}

/**
 * Preserves W3TC's term-flush behavior except for selected taxonomies.
 *
 * Both edited_term and delete_term provide taxonomy as their third argument.
 * The extra optional arguments make this one wrapper compatible with both Core
 * hooks while retaining their complete argument lists for future maintenance.
 *
 * @param int          $term_id      Term ID.
 * @param int          $tt_id        Term taxonomy ID.
 * @param string       $taxonomy     Taxonomy name.
 * @param mixed|null   $deleted_term Deleted term on delete_term; absent on edited_term.
 * @param array<mixed> $object_ids   Object IDs on delete_term; absent on edited_term.
 * @return void
 */
function polylang_w3tc_cache_compat_flush_posts_for_term(
	$term_id = 0,
	$tt_id = 0,
	$taxonomy = '',
	$deleted_term = null,
	$object_ids = array()
) {
	if ( polylang_w3tc_cache_compat_is_eventually_consistent_term_taxonomy( $taxonomy ) ) {
		return;
	}

	if ( function_exists( 'w3tc_flush_posts' ) ) {
		w3tc_flush_posts();
	}
}

/**
 * Replaces W3TC's taxonomy-blind term callbacks after normal plugins load.
 *
 * In W3TC 2.10.6, Root_Loader is instantiated and run while the W3TC plugin
 * file is loaded. PgCache_Plugin::run() therefore registers these callbacks
 * before WordPress fires plugins_loaded. Replacing them here is later than the
 * W3TC registration, but still before any normal term edit or delete request.
 *
 * @return void
 */
function polylang_w3tc_cache_compat_replace_w3tc_term_flushes() {
	static $registered = false;

	if ( $registered || ! function_exists( 'w3tc_flush_posts' ) ) {
		return;
	}

	$registered = true;

	remove_action( 'edited_term', 'w3tc_flush_posts', 0 );
	remove_action( 'delete_term', 'w3tc_flush_posts', 0 );

	add_action(
		'edited_term',
		'polylang_w3tc_cache_compat_flush_posts_for_term',
		0,
		5
	);
	add_action(
		'delete_term',
		'polylang_w3tc_cache_compat_flush_posts_for_term',
		0,
		5
	);
}

add_action(
	'plugins_loaded',
	'polylang_w3tc_cache_compat_replace_w3tc_term_flushes',
	0
);
