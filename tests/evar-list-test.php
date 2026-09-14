#!/usr/bin/env php
<?php
/**
 * mrmurphy-apps-001 harness: LIST evar read path.
 *
 * Proves get_app_evars() no longer depends on the all-keys
 * get_post_meta() object-cache shape. Stubs $wpdb with the shapes a
 * persistent object cache serves on WP.com Atomic (raw serialized
 * strings, double-serialized, flat strings, multi-value keys) and
 * asserts the marker evar appears in the LIST output — where the old
 * all-keys path returned nothing.
 *
 * The fake get_results() actually parses the WHERE clause (post_id +
 * escaped-LIKE), so fixtures that a real MySQL would not return never
 * reach the code under test, and escaped-wildcard behavior is observable.
 *
 * No WordPress bootstrap. Run: php tests/evar-list-test.php  (exit 0 = pass)
 * Non-secret markers only.
 */

define( 'ABSPATH', '/tmp/' ); // class-rest.php guards: defined('ABSPATH') || exit

/* ---------------------------------------------------------------- shims */

// Faithful-enough maybe_unserialize for the fixtures below (mirrors WP:
// only attempts unserialize when the payload looks serialized).
function maybe_unserialize( $data ) {
	if ( is_string( $data ) && preg_match( '/^[adObis]:/', $data ) ) {
		$u = @unserialize( $data );
		if ( false !== $u ) {
			return $u;
		}
	}
	return $data;
}

class Fake_WPDB {
	public $postmeta = 'wp_postmeta';
	public $last_query = '';
	private $rows = array();

	public function set_rows( array $rows ) {
		$this->rows = $rows;
	}

	public function esc_like( $s ) {
		return addcslashes( $s, '_%\\' );
	}

	// Test-only rendering: quotes args raw (no addslashes dance) so the
	// escaped LIKE pattern is observable verbatim in $last_query.
	public function prepare( $query, ...$args ) {
		$sql = $query;
		foreach ( $args as $arg ) {
			if ( is_int( $arg ) ) {
				$sql = preg_replace( '/%d/', (string) $arg, $sql, 1 );
			} else {
				$sql = preg_replace( '/%s/', "'" . str_replace( "'", "\\'", (string) $arg ) . "'", $sql, 1 );
			}
		}
		$this->last_query = $sql;
		return $sql;
	}

	// Emulates MySQL for our exact query: post_id equality + LIKE pattern.
	public function get_results( $sql ) {
		if ( ! preg_match( '/post_id = (\d+)/', $sql, $m ) ) {
			return array();
		}
		$post_id = (int) $m[1];
		if ( ! preg_match( "/LIKE '((?:[^'\\\\]|\\\\.)+)'/", $sql, $m2 ) ) {
			return array();
		}
		// Decode the LIKE pattern to a regex, honoring backslash escapes.
		$pattern = '';
		$raw = $m2[1];
		$len = strlen( $raw );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $raw[ $i ];
			if ( '\\' === $c && $i + 1 < $len ) {
				$pattern .= preg_quote( $raw[ ++$i ], '/' );
			} elseif ( '%' === $c ) {
				$pattern .= '.*';
			} else {
				$pattern .= preg_quote( $c, '/' );
			}
		}
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( $row->post_id !== $post_id ) {
				continue;
			}
			if ( preg_match( '/^' . $pattern . '$/', $row->meta_key ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}
}

require __DIR__ . '/../inc/class-rest.php';

/* ------------------------------------------------------------- fixtures */

function meta_row( $meta_id, $key, $value, $post_id = 1372 ) {
	$r = new stdClass();
	$r->meta_key   = $key;
	$r->meta_value = $value;
	$r->meta_id    = $meta_id;
	$r->post_id    = $post_id;
	return $r;
}

function payload( $v ) {
	return serialize( array( 'value' => $v, 'updated_at' => '2026-09-14T00:00:00Z' ) );
}

function invoke_private( MRMurphy_Apps_REST $rest, $method, ...$args ) {
	$m = new ReflectionMethod( $rest, $method );
	return $m->invoke( $rest, ...$args );
}

// The pre-fix read, verbatim from the old get_app_evars(), for contrast:
// it consumes the all-keys get_post_meta() shape (key => array of values).
function legacy_list_shape( array $all_meta ) {
	$evars  = array();
	$prefix = '_mrmurphy_app_evar_';
	foreach ( $all_meta as $key => $meta_values ) {
		if ( 0 !== strpos( $key, $prefix ) ) {
			continue;
		}
		$name = substr( $key, strlen( $prefix ) );
		$data = $meta_values[0];
		if ( is_array( $data ) ) {
			$evars[ $name ] = $data;
		}
	}
	return $evars;
}

/* -------------------------------------------------------------- runner */

$failures = 0;
function check( $label, $cond ) {
	global $failures;
	if ( $cond ) {
		echo "PASS  $label\n";
	} else {
		echo "FAIL  $label\n";
		$failures++;
	}
}

// Constructor is never run (would build Storage); get_app_evars/format_evars_list
// touch only $wpdb, so reflection on a constructor-less instance is faithful.
$rest = ( new ReflectionClass( 'MRMurphy_Apps_REST' ) )->newInstanceWithoutConstructor();
$GLOBALS['wpdb'] = new Fake_WPDB();
$wpdb = $GLOBALS['wpdb'];

/* S1: Atomic "empty all-keys" shape — get_post_meta($id) returned
   array() on prod while single-key reads worked. The direct-SQL path
   reads the table the writes actually landed in, so it must see the
   marker; the legacy path sees nothing (reproduces the prod symptom). */
$wpdb->set_rows(
	array(
		meta_row( 1, '_mrmurphy_app_evar_LOOPTEST_MARKER', payload( 'ping' ), 1373 ),
		meta_row( 2, '_mrmurphy_app_entry', 'index.html', 1373 ),
	)
);
$legacy = legacy_list_shape( array() ); // what the broken object cache returned
$new    = invoke_private( $rest, 'get_app_evars', 1373 );
$list   = invoke_private( $rest, 'format_evars_list', $new );
check( 'S1 legacy all-keys path is empty (prod symptom reproduced)', empty( $legacy ) );
check( 'S1 new path finds LOOPTEST_MARKER', isset( $new['LOOPTEST_MARKER'] ) && 'ping' === $new['LOOPTEST_MARKER']['value'] );
check( 'S1 LIST output contains LOOPTEST_MARKER', in_array( 'LOOPTEST_MARKER', array_column( $list['evars'], 'name' ), true ) );

/* S2: persistent-cache "flat shape" — all-keys returns raw string-encoded
   values; the is_array filter dropped them. New path unserializes first. */
$flat_all_keys = array(
	'_mrmurphy_app_evar_LOOPTEST_MARKER' => array( payload( 'ping' ) ), // string, not array
);
$legacy = legacy_list_shape( $flat_all_keys );
$wpdb->set_rows( array( meta_row( 1, '_mrmurphy_app_evar_LOOPTEST_MARKER', payload( 'ping' ) ) ) );
$new = invoke_private( $rest, 'get_app_evars', 1372 );
check( 'S2 legacy flat-shape path is empty (second broken shape)', empty( $legacy ) );
check( 'S2 new path still finds LOOPTEST_MARKER', isset( $new['LOOPTEST_MARKER'] ) );

/* S3: value-shape and key-shape edge cases. */
$wpdb->set_rows(
	array(
		meta_row( 1, '_mrmurphy_app_evar_OK_ONE', payload( 'one' ) ),
		meta_row( 2, '_mrmurphy_app_evar_DOUBLE', serialize( payload( 'nested' ) ) ), // one unserialize pass yields a string, not array
		meta_row( 3, '_mrmurphy_app_evar_FLAT_STR', 'plain text value' ),
		meta_row( 4, '_mrmurphy_app_evar_MULTI', payload( 'first' ) ),
		meta_row( 5, '_mrmurphy_app_evar_MULTI', payload( 'second' ) ),
		meta_row( 6, '_mrmurphy_app_evar_', payload( 'empty-name' ) ),
		meta_row( 7, '_mrmurphyXappYevarWILD', payload( 'nope' ) ), // only matches an UNescaped LIKE — must not be returned
		meta_row( 8, '_wp_body_open', 'x' ),
		meta_row( 9, '_mrmurphy_app_evar_OTHER_POST', payload( 'nope' ), 9999 ),
	)
);
$got_rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s ORDER BY meta_id ASC",
	1372,
	$wpdb->esc_like( '_mrmurphy_app_evar_' ) . '%'
) );
check( 'S3 fake SQL honors escaped LIKE (WILD + other-post rows excluded)', 6 === count( $got_rows ) );
$new = invoke_private( $rest, 'get_app_evars', 1372 );
check( 'S3 normal evar present', isset( $new['OK_ONE'] ) );
check( 'S3 double-serialized excluded (single-key parity)', ! isset( $new['DOUBLE'] ) );
check( 'S3 flat string excluded', ! isset( $new['FLAT_STR'] ) );
check( 'S3 multi-value key takes FIRST row', isset( $new['MULTI'] ) && 'first' === $new['MULTI']['value'] );
check( 'S3 bare-prefix key skipped, total evars = 2', 2 === count( $new ) );
check( 'S3 query escapes underscores (no raw %/_ wildcards)', false !== strpos( $GLOBALS['wpdb']->last_query, "'\\_mrmurphy\\_app\\_evar\\_%'" ) );
check( 'S3 query pins post_id', false !== strpos( $GLOBALS['wpdb']->last_query, 'post_id = 1372' ) );

/* S4: masked output — format_evars_list must never leak values. */
$list = invoke_private( $rest, 'format_evars_list', $new );
$json = json_encode( $list );
$flat = implode( ' ', array_column( $list['evars'], 'masked_value' ) );
check( 'S4 LIST entries all masked', 2 === count( $list['evars'] ) && '•••••••• ••••••••' === $flat );
check( 'S4 LIST json leaks no values', false === strpos( $json, 'first' ) && false === strpos( $json, 'one' ) && false === strpos( $json, 'second' ) );

echo $failures ? "\n$failures FAILURE(S)\n" : "\nALL PASS\n";
exit( $failures ? 1 : 0 );
