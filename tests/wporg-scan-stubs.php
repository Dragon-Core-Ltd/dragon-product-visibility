<?php
/**
 * Core stand-ins for the request handlers: each mirrors the WordPress core
 * function it replaces and is defined only when nothing else has defined it.
 * Functions that end the request in core throw here instead.
 *
 * @package DragonProductVisibility
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO

/**
 * Thrown by the wp_die() stand-in: core prints the message and ends the request.
 */
final class Dpv_Test_Die extends \RuntimeException {
	public int $status;

	public function __construct( string $message, int $status ) {
		parent::__construct( $message );
		$this->status = $status;
	}
}

/**
 * Thrown by the wp_safe_redirect() stand-in, standing for the handler's exit.
 */
final class Dpv_Test_Redirect extends \RuntimeException {
	public string $location;

	public function __construct( string $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}

if ( ! function_exists( 'map_deep' ) ) {
	// Core: applies the callback to every leaf that is not an array or object, keeping keys.
	function map_deep( $value, $callback ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $index => $item ) {
				$value[ $index ] = map_deep( $item, $callback );
			}
		} elseif ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $property_name => $property_value ) {
				$value->$property_name = map_deep( $property_value, $callback );
			}
		} else {
			$value = call_user_func( $callback, $value );
		}

		return $value;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	// Core: the wp-admin URL with the path appended.
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ( is_string( $path ) ? ltrim( $path, '/' ) : '' );
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	// Core prints the message and ends the request (HTTP 500 unless told otherwise).
	function wp_die( $message = '', $title = '', $args = array() ) {
		unset( $title );
		$status = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 500;
		throw new Dpv_Test_Die( (string) $message, $status );
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	// Core verifies $_REQUEST[ $query_arg ] and ends the request with a 403 page when it fails.
	function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
		$result = isset( $_REQUEST[ $query_arg ] ) ? wp_verify_nonce( $_REQUEST[ $query_arg ], $action ) : false;
		if ( ! $result ) {
			throw new Dpv_Test_Die( 'The link you followed has expired.', 403 );
		}

		return $result;
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	// Core sends the Location header and returns true; the caller then exits.
	function wp_safe_redirect( $location, $status = 302 ) {
		unset( $status );
		throw new Dpv_Test_Redirect( (string) $location );
	}
}

if ( ! function_exists( 'term_exists' ) ) {
	// Core: the term's ids when it exists in the taxonomy, null otherwise.
	function term_exists( $term, $taxonomy = '' ) {
		if ( in_array( (int) $term, (array) ( $GLOBALS['dpv_test_terms'][ $taxonomy ] ?? array() ), true ) ) {
			return array(
				'term_id'          => (string) (int) $term,
				'term_taxonomy_id' => (string) (int) $term,
			);
		}

		return null;
	}
}
