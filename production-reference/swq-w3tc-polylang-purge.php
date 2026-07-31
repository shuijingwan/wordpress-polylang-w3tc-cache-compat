<?php
/**
 * Plugin Name: SWQ W3TC Polylang Purge
 * Description: Completes W3 Total Cache Page Cache and Object Cache invalidation for a Polylang multi-domain site.
 * Version: 1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add the post language homepage to W3TC's current Page Cache purge queue.
 *
 * W3TC registers its native w3tc_flush_post callback at priority 1100.
 * This callback runs afterwards and appends the missing language homepage
 * before W3TC executes its delayed Page Cache cleanup.
 *
 * @param int        $post_id Post ID being flushed.
 * @param bool       $force   Whether W3TC forced the purge.
 * @param mixed|null $extras  Additional W3TC purge information.
 */
function swq_w3tc_polylang_queue_language_home(
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

    $language = pll_get_post_language(
        $post_id,
        'slug'
    );

    if ( ! is_string( $language ) || '' === $language ) {
        return;
    }

    $language_home = pll_home_url( $language );

    if (
        ! is_string( $language_home )
        || '' === $language_home
    ) {
        return;
    }

    w3tc_flush_url(
        trailingslashit( $language_home ),
        array(
            'swq_source' => 'polylang_language_home',
            'post_id'    => $post_id,
            'language'   => $language,
        )
    );
}

add_action(
    'w3tc_flush_post',
    'swq_w3tc_polylang_queue_language_home',
    1200,
    3
);

/**
 * Invalidate the shared W3TC Redis version for the WordPress posts group.
 *
 * W3TC places the request Host in individual Redis object keys. As a result,
 * saving a post through admin.shuijingwanwq.com normally invalidates only the
 * admin Host's posts:last_changed value, leaving www and en query caches stale.
 *
 * W3TC's Redis group-version key does not contain the Host. Flushing the posts
 * group therefore invalidates stale post queries across all three Hosts,
 * without flushing the entire Redis database or unrelated cache groups.
 *
 * @param int        $post_id Post ID being flushed.
 * @param bool       $force   Whether W3TC forced the purge.
 * @param mixed|null $extras  Additional W3TC purge information.
 */
function swq_w3tc_sync_posts_object_cache_group(
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

    /*
     * Prevent repeated group-version increments when W3TC triggers more than
     * one post purge during the same WordPress request.
     */
    $flushed = true;

    wp_cache_flush_group( 'posts' );
}

add_action(
    'w3tc_flush_post',
    'swq_w3tc_sync_posts_object_cache_group',
    1250,
    3
);

/**
 * Invalidate Host-separated options caches after W3TC Object Cache is flushed.
 *
 * W3TC includes the current Host in individual Object Cache keys. Its normal
 * Object Cache purge therefore invalidates only the current Host namespace.
 *
 * The Redis group-version key does not include the Host. Flushing the options
 * group makes admin, www and en reload options such as WPCode's
 * wpcode_snippets data from the shared database.
 *
 * This callback runs after either:
 *
 * - Purge Module: Object Cache
 * - Purge All Caches
 *
 * It does not initiate a Page Cache or opcode-cache purge.
 */
function swq_w3tc_sync_options_group_after_objectcache_flush() {
    if (
        ! function_exists( 'wp_cache_flush_group' )
        || ! function_exists( 'wp_cache_supports' )
        || ! wp_cache_supports( 'flush_group' )
    ) {
        return;
    }

    wp_cache_flush_group( 'options' );
}

add_action(
    'w3tc_flush_after_objectcache',
    'swq_w3tc_sync_options_group_after_objectcache_flush',
    10,
    0
);
