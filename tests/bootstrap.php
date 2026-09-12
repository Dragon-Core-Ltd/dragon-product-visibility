<?php
/**
 * PHPUnit bootstrap. The classes under test only touch a handful of core
 * helpers, stubbed here over small in-memory stores that can be told to fail.
 * Real table creation (dbDelta) and the WooCommerce save hooks are verified in
 * wp-env.
 *
 * @package DragonProductVisibility
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'DRAGONPRODUCTVISIBILITY_VERSION' ) || define( 'DRAGONPRODUCTVISIBILITY_VERSION', '9.9.9-test' );
defined( 'DRAGONPRODUCTVISIBILITY_PLUGIN_PATH' ) || define( 'DRAGONPRODUCTVISIBILITY_PLUGIN_PATH', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/class-fake-wpdb.php';

/**
 * Thrown by the wp_send_json_* stubs so a handler stops where WordPress would
 * have exited, carrying what it tried to send.
 */
final class Dpv_Test_Json_Sent extends \RuntimeException {
	public bool $success;
	public $data;

	public function __construct( bool $success, $data ) {
		parent::__construct( $success ? 'success' : 'error' );
		$this->success = $success;
		$this->data    = $data;
	}
}

/**
 * Reset every store and install a fresh fake $wpdb. Called from setUp().
 */
function dpv_test_reset(): \DragonProductVisibility\Tests\Fake_Wpdb {
	$GLOBALS['wpdb']                       = new \DragonProductVisibility\Tests\Fake_Wpdb();
	$GLOBALS['dpv_test_options']           = array();
	$GLOBALS['dpv_test_option_write_fail'] = false;
	$GLOBALS['dpv_test_meta']              = array();
	$GLOBALS['dpv_test_meta_write_fail']   = array();
	$GLOBALS['dpv_test_cache_deletes']     = array();
	$GLOBALS['dpv_test_transients']        = array();
	$GLOBALS['dpv_test_dbdelta_calls']     = 0;
	$GLOBALS['dpv_test_dbdelta_sql']       = '';
	$GLOBALS['dpv_test_dbdelta_creates']   = true;
	$GLOBALS['dpv_test_current_user']      = 1;
	$GLOBALS['dpv_test_can']               = null;
	$GLOBALS['dpv_test_posts']             = array();
	$GLOBALS['dpv_test_filters']           = array();
	$GLOBALS['dpv_test_get_post_meta']     = 0;
	$_POST                                 = array();
	$_GET                                  = array();
	return $GLOBALS['wpdb'];
}

function __( $text, $domain = 'default' ) {
	unset( $domain );
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	unset( $domain );
	return $text;
}

function esc_html_e( $text, $domain = 'default' ) {
	unset( $domain );
	echo $text;
}

function esc_html( $text ) {
	// Core runs wp_check_invalid_utf8() first, which returns an EMPTY STRING for
	// text that is not valid UTF-8. A pass-through stub hides every bug where bad
	// input silently blanks the output.
	$text = (string) $text;

	if ( '' !== $text && 1 !== preg_match( '//u', $text ) ) {
		return '';
	}

	return str_replace( array( '&', '<', '>', '"', "'" ), array( '&amp;', '&lt;', '&gt;', '&quot;', '&#039;' ), $text );
}

function absint( $maybeint ) {
	return abs( (int) $maybeint );
}

function sanitize_text_field( $str ) {
	/*
	 * Mirrors core's _sanitize_text_fields( $str, false ): invalid UTF-8 is
	 * dropped, tags are stripped whenever the value contains "<", runs of
	 * whitespace fold to one space, and percent-encoded sequences such as %20
	 * are REMOVED entirely. That last rule surprises people and matters for a
	 * fleet that handles URLs, so a stub that only folds whitespace hides it.
	 */
	$filtered = (string) $str;

	if ( '' !== $filtered && 1 !== preg_match( '//u', $filtered ) ) {
		$filtered = (string) preg_replace( '/[\x80-\xFF]/', '', $filtered );
	}

	if ( str_contains( $filtered, '<' ) ) {
		$filtered = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $filtered );
		$filtered = strip_tags( $filtered );
		$filtered = (string) preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
	}

	$filtered = trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $filtered ) );

	$found = false;
	while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
		$filtered = str_replace( $match[0], '', $filtered );
		$found    = true;
	}

	if ( $found ) {
		$filtered = trim( (string) preg_replace( '/ +/', ' ', $filtered ) );
	}

	return $filtered;
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function wp_unslash( $value ) {
	// Core strips one level of slashes; a pass-through hides every slash bug,
	// because core unslashes on write and callers must slash on the way in.
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}

	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function add_action( ...$args ) {
	unset( $args );
	return true;
}

function add_filter( $tag, $callback, ...$args ) {
	unset( $args );
	$GLOBALS['dpv_test_filters'][ $tag ][] = $callback;
	return true;
}

function add_query_arg( $key, $value, $url ) {
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . $value;
}

function get_post( $post = null ) {
	return $GLOBALS['dpv_test_posts'][ (int) $post ] ?? null;
}

function maybe_serialize( $data ) {
	return ( is_array( $data ) || is_object( $data ) ) ? serialize( $data ) : $data;
}

function maybe_unserialize( $data ) {
	if ( is_string( $data ) && preg_match( '/^[aOs]:/', $data ) ) {
		return unserialize( $data );
	}
	return $data;
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	unset( $action );
	return 'valid' === $nonce ? 1 : false;
}

function current_user_can( $cap, ...$args ) {
	$can = $GLOBALS['dpv_test_can'];
	return $can ? (bool) $can( $cap, $args ) : true;
}

function get_current_user_id() {
	return $GLOBALS['dpv_test_current_user'];
}

function wp_send_json_success( $data = null ) {
	throw new Dpv_Test_Json_Sent( true, $data );
}

function wp_send_json_error( $data = null ) {
	throw new Dpv_Test_Json_Sent( false, $data );
}

// Options.
function get_option( $name, $default_value = false ) {
	$options = (array) ( $GLOBALS['dpv_test_options'] ?? array() );
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default_value;
}

function update_option( $name, $value, $autoload = null ) {
	unset( $autoload );
	$options = (array) ( $GLOBALS['dpv_test_options'] ?? array() );
	if ( array_key_exists( $name, $options ) && $options[ $name ] === $value ) {
		return false; // Unchanged: WordPress returns false without writing.
	}
	if ( ! empty( $GLOBALS['dpv_test_option_write_fail'] ) ) {
		return false;
	}
	$GLOBALS['dpv_test_options'][ $name ] = $value;
	return true;
}

function add_option( $name, $value ) {
	// Core refuses and leaves the stored value alone when the option exists.
	if ( array_key_exists( $name, (array) ( $GLOBALS['dpv_test_options'] ?? array() ) ) ) {
		return false;
	}

	return update_option( $name, $value );
}

function delete_option( $name ) {
	unset( $GLOBALS['dpv_test_options'][ $name ] );
	return true;
}

// Post meta.
function get_post_meta( $post_id, $key = '', $single = false ) {
	++$GLOBALS['dpv_test_get_post_meta'];
	$value = $GLOBALS['dpv_test_meta'][ $post_id ][ $key ] ?? null;
	if ( null === $value ) {
		return $single ? '' : array();
	}
	return $single ? $value : array( $value );
}

function update_post_meta( $post_id, $key, $value, $prev_value = '' ) {
	$current = $GLOBALS['dpv_test_meta'][ $post_id ][ $key ] ?? null;
	// With $prev_value core updates only a row that still holds it, which is what
	// makes the write a compare-and-swap.
	if ( '' !== $prev_value && $current !== $prev_value ) {
		return false;
	}
	if ( null !== $current && $current === $value ) {
		return false; // Unchanged: WordPress returns false without writing.
	}
	if ( in_array( $key, (array) ( $GLOBALS['dpv_test_meta_write_fail'] ?? array() ), true ) ) {
		return false;
	}
	$GLOBALS['dpv_test_meta'][ $post_id ][ $key ] = $value;
	return true;
}

function wp_cache_delete( $key, $group = '' ) {
	$GLOBALS['dpv_test_cache_deletes'][] = $group . ':' . $key;
	return true;
}

function wp_cache_flush() {
	return true;
}

// Transients.
function set_transient( $name, $value, $expiration = 0 ) {
	unset( $expiration );
	$GLOBALS['dpv_test_transients'][ $name ] = $value;
	return true;
}

function get_transient( $name ) {
	return $GLOBALS['dpv_test_transients'][ $name ] ?? false;
}

function delete_transient( $name ) {
	unset( $GLOBALS['dpv_test_transients'][ $name ] );
	return true;
}

function wp_clear_scheduled_hook( ...$args ) {
	unset( $args );
	return 0;
}

// dbDelta: creates the visibility table in the fake $wpdb unless told not to.
function dbDelta( $queries ) {
	$GLOBALS['dpv_test_dbdelta_sql'] = is_array( $queries ) ? implode( "\n", $queries ) : (string) $queries;
	++$GLOBALS['dpv_test_dbdelta_calls'];
	if ( $GLOBALS['dpv_test_dbdelta_creates'] ) {
		$GLOBALS['wpdb']->tables[] = $GLOBALS['wpdb']->prefix . 'dpv_customer_visibility';
	}
	return array();
}

require_once dirname( __DIR__ ) . '/includes/class-install.php';
require_once dirname( __DIR__ ) . '/includes/class-bulk-rules.php';
if ( file_exists( dirname( __DIR__ ) . '/includes/class-customer-visibility.php' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-customer-visibility.php';
}
require_once dirname( __DIR__ ) . '/includes/class-ajax.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-product-metabox.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-admin.php';
