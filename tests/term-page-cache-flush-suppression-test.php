<?php
/**
 * Lightweight regression test for taxonomy-aware W3TC term flushing.
 *
 * Run with: php tests/term-page-cache-flush-suppression-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$test_actions         = array();
$test_removed_actions = array();
$test_flush_count     = 0;

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	global $test_actions;
	$test_actions[] = array( $hook, $callback, $priority, $accepted_args );
}

function remove_action( $hook, $callback, $priority = 10 ) {
	global $test_removed_actions;
	$test_removed_actions[] = array( $hook, $callback, $priority );
}

function w3tc_flush_posts() {
	global $test_flush_count;
	++$test_flush_count;
}

require dirname( __DIR__ ) . '/src/polylang-w3tc-cache-compat.php';

class Polylang_W3TC_Compat_Test_Term_Model {
	public function get_tax_translations() {
		return 'term_translations';
	}
}

class Polylang_W3TC_Compat_Test_Model {
	public $term;

	public function __construct() {
		$this->term = new Polylang_W3TC_Compat_Test_Term_Model();
	}
}

class Polylang_W3TC_Compat_Test_Polylang {
	public $model;

	public function __construct() {
		$this->model = new Polylang_W3TC_Compat_Test_Model();
	}
}

function polylang_w3tc_cache_compat_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$polylang = new Polylang_W3TC_Compat_Test_Polylang();

polylang_w3tc_cache_compat_replace_w3tc_term_flushes();
polylang_w3tc_cache_compat_replace_w3tc_term_flushes();

polylang_w3tc_cache_compat_test_assert(
	2 === count( $test_removed_actions ),
	'W3TC callbacks are removed once from both Core term hooks.'
);
polylang_w3tc_cache_compat_test_assert(
	5 === count( $test_actions ),
	'Plugin registrations plus two replacement callbacks are not duplicated.'
);
polylang_w3tc_cache_compat_test_assert(
	array(
		array( 'edited_term', 'polylang_w3tc_cache_compat_flush_posts_for_term', 0, 5 ),
		array( 'delete_term', 'polylang_w3tc_cache_compat_flush_posts_for_term', 0, 5 ),
	) === array_slice( $test_actions, -2 ),
	'Replacement callbacks retain Core taxonomy arguments on both hooks.'
);

foreach ( array( 'category', 'post_tag', 'term_translations' ) as $taxonomy ) {
	polylang_w3tc_cache_compat_flush_posts_for_term( 1, 1, $taxonomy );
}

polylang_w3tc_cache_compat_test_assert(
	0 === $test_flush_count,
	'Edited category, post_tag, and Polylang translation taxonomy do not flush all pages.'
);

polylang_w3tc_cache_compat_flush_posts_for_term( 1, 1, 'category', (object) array(), array() );
polylang_w3tc_cache_compat_flush_posts_for_term( 1, 1, 'post_tag', (object) array(), array() );

polylang_w3tc_cache_compat_test_assert(
	0 === $test_flush_count,
	'Deleted category and post_tag do not flush all pages.'
);

polylang_w3tc_cache_compat_flush_posts_for_term( 1, 1, 'custom_taxonomy' );

polylang_w3tc_cache_compat_test_assert(
	1 === $test_flush_count,
	'Other taxonomies preserve W3TC full flush behavior.'
);

echo "OK\n";
