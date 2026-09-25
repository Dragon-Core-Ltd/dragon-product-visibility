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
defined( 'OBJECT' ) || define( 'OBJECT', 'OBJECT' );
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
 * WC_Session_Handler double: whether the visitor already has a session cookie,
 * and whether one was asked for.
 */
final class Dpv_Test_Wc_Session {
	public bool $cookie     = false;
	public bool $cookie_set = false;

	public function has_session() {
		return $this->cookie || $this->cookie_set;
	}

	public function set_customer_session_cookie( $set ) {
		if ( $set ) {
			$this->cookie_set = true;
		}
	}
}

function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- WooCommerce's own function name.
	return $GLOBALS['dpv_test_wc'];
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
	$GLOBALS['dpv_test_actions']           = array();
	$GLOBALS['dpv_test_is_admin']          = false;
	$GLOBALS['dpv_test_doing_ajax']        = false;
	$GLOBALS['dpv_test_user_roles']        = array( 'customer' );
	$GLOBALS['dpv_test_notices']           = array();
	$GLOBALS['dpv_test_wc']                = (object) array( 'session' => new Dpv_Test_Wc_Session() );
	$GLOBALS['dpv_test_comments']          = array();
	$GLOBALS['dpv_test_skus']              = array();
	$GLOBALS['dpv_test_is_product']        = false;
	$GLOBALS['dpv_test_is_attachment']     = false;
	$GLOBALS['dpv_test_status_header']     = null;
	$GLOBALS['shortcode_tags']             = array();
	$GLOBALS['post']                       = null;
	$GLOBALS['wp_query']                   = null;
	$_POST                                 = array();
	$_GET                                  = array();
	return $GLOBALS['wpdb'];
}

function __( $text, $domain = 'default' ) {
	unset( $domain );
	return $text;
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	unset( $domain );
	return 1 === (int) $number ? $single : $plural;
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, absint( $decimals ) );
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

function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['dpv_test_actions'][] = array( $tag, $callback, $priority, $accepted_args );
	return true;
}

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['dpv_test_filters'][ $tag ][] = $callback;
	$GLOBALS['dpv_test_actions'][]         = array( $tag, $callback, $priority, $accepted_args );
	return true;
}

function apply_filters( $tag, $value, ...$args ) {
	unset( $tag, $args );
	return $value;
}

function is_admin() {
	return (bool) $GLOBALS['dpv_test_is_admin'];
}

function wp_doing_ajax() {
	return (bool) ( $GLOBALS['dpv_test_doing_ajax'] ?? false );
}

function wc_add_notice( $message, $type = 'success' ) {
	$GLOBALS['dpv_test_notices'][] = array( $message, $type );
}

function get_userdata( $user_id ) {
	return $user_id ? (object) array(
		'ID'           => (int) $user_id,
		'roles'        => $GLOBALS['dpv_test_user_roles'],
		'display_name' => 'Customer ' . (int) $user_id,
		'user_email'   => 'customer' . (int) $user_id . '@example.com',
	) : false;
}

function wp_get_current_user() {
	$user = get_userdata( get_current_user_id() );
	return $user ? $user : (object) array(
		'ID'    => 0,
		'roles' => array(),
	);
}

function get_the_terms( $post, $taxonomy ) {
	unset( $post, $taxonomy );
	return false;
}

function get_ancestors( $object_id = 0, $object_type = '', $resource_type = '' ) {
	unset( $object_id, $object_type, $resource_type );
	return array();
}

function update_meta_cache( $meta_type, $object_ids ) {
	unset( $meta_type, $object_ids );
	return array();
}

function update_object_term_cache( $object_ids, $object_type ) {
	unset( $object_ids, $object_type );
	return null;
}

function get_posts( $args = null ) {
	unset( $args );
	return array();
}

/**
 * Mirrors core's sanitize_title_with_dashes() for ASCII input: disallowed
 * characters (a slash included) are dropped, whitespace becomes a dash, and
 * dash runs collapse.
 */
function sanitize_title( $title ) {
	$title = strtolower( (string) $title );
	$title = (string) preg_replace( '/[^%a-z0-9 _-]/', '', $title );
	$title = (string) preg_replace( '/\s+/', '-', $title );
	$title = (string) preg_replace( '|-+|', '-', $title );
	return trim( $title, '-' );
}

/**
 * Mirrors core: the post of the given type whose post_name is the path.
 */
function get_page_by_path( $page_path, $output = 'OBJECT', $post_type = 'page' ) {
	unset( $output );
	foreach ( $GLOBALS['dpv_test_posts'] as $post ) {
		if ( ( $post->post_name ?? '' ) === $page_path && $post->post_type === $post_type ) {
			return $post;
		}
	}
	return null;
}

/**
 * Mirrors core: a list from an array or a comma/space separated string.
 */
function wp_parse_list( $input_list ): array {
	if ( ! is_array( $input_list ) ) {
		$parsed_list = preg_split( '/[\s,]+/', (string) $input_list, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $parsed_list ) ? $parsed_list : array();
	}
	return array_filter( $input_list, 'is_scalar' );
}

/**
 * Mirrors core: unique absint IDs (keys preserved, as array_unique does).
 */
function wp_parse_id_list( $input_list ): array {
	return array_unique( array_map( 'absint', wp_parse_list( $input_list ) ) );
}

function is_wp_error( $thing ) {
	return $thing instanceof \WP_Error;
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

/**
 * WP_Error double.
 */
class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
}

/**
 * WP_REST_Request double: a route plus URL and query-string params, read in
 * core's default parameter order (GET before URL).
 */
class WP_REST_Request {
	private string $route;
	private string $method;
	private array $params = array(
		'GET' => array(),
		'URL' => array(),
	);

	public function __construct( $method = '', $route = '' ) {
		$this->method = strtoupper( (string) $method );
		$this->route  = (string) $route;
	}

	public function get_route() {
		return $this->route;
	}

	public function get_method() {
		return $this->method;
	}

	public function set_url_params( $params ) {
		$this->params['URL'] = (array) $params;
	}

	public function set_query_params( $params ) {
		$this->params['GET'] = (array) $params;
	}

	public function get_param( $key ) {
		foreach ( array( 'GET', 'URL' ) as $type ) {
			if ( isset( $this->params[ $type ][ $key ] ) ) {
				return $this->params[ $type ][ $key ];
			}
		}
		return null;
	}
}

/**
 * Stand-in for core's posts controller, so a handler can name it.
 */
class WP_REST_Posts_Controller {
	public function get_item( $request ) {
		return $request;
	}
}

/**
 * Stand-in for core's attachments controller (a posts controller subclass).
 */
class WP_REST_Attachments_Controller extends WP_REST_Posts_Controller {
}

/**
 * Stand-in for core's comments controller.
 */
class WP_REST_Comments_Controller {
	public function get_item( $request ) {
		return $request;
	}
}

/**
 * WC_Product double: just an ID.
 */
class WC_Product {
	private int $id;

	public function __construct( $id = 0 ) {
		$this->id = (int) $id;
	}

	public function get_id() {
		return $this->id;
	}
}

/**
 * WP_Comment_Query double: only the query vars pre_get_comments sees.
 */
class WP_Comment_Query {
	public array $query_vars = array();

	public function __construct( array $vars = array() ) {
		$this->query_vars = $vars;
	}
}

/**
 * WP_Query double: query vars plus the flags parse_query() would have set.
 */
class WP_Query {
	public array $query_vars = array();
	public bool $main        = false;
	public bool $is_singular = false;
	public bool $is_search   = false;
	public bool $is_404      = false;

	public function __construct( array $vars = array() ) {
		// parse_query() runs fill_query_vars() before pre_get_posts, which turns
		// every missing list var into an empty array.
		$lists = array( 'post__in', 'post__not_in', 'post_parent__in', 'post_parent__not_in', 'post_name__in', 'author__in', 'author__not_in', 'category__in', 'category__not_in', 'tag__in', 'tag__not_in' );
		foreach ( $lists as $key ) {
			$vars[ $key ] = $vars[ $key ] ?? array();
		}
		$this->query_vars = $vars;
	}

	public function get( $key, $default_value = '' ) {
		return $this->query_vars[ $key ] ?? $default_value;
	}

	public function set( $key, $value ) {
		$this->query_vars[ $key ] = $value;
	}

	public function is_main_query() {
		return $this->main;
	}

	public function is_singular( $post_types = '' ) {
		unset( $post_types );
		return $this->is_singular;
	}

	public function is_search() {
		return $this->is_search;
	}

	public function set_404() {
		$this->is_404      = true;
		$this->is_singular = false;
	}
}

function add_query_arg( $key, $value, $url ) {
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . $value;
}

/**
 * Mirrors core: the comment object, or null when there is none.
 */
function get_comment( $comment = null, $output = 'OBJECT' ) {
	unset( $output );
	if ( is_object( $comment ) ) {
		return $comment;
	}
	return $GLOBALS['dpv_test_comments'][ (int) $comment ] ?? null;
}

/**
 * Mirrors WooCommerce: the product or variation ID for a SKU, 0 when none.
 */
function wc_get_product_id_by_sku( $sku ) {
	return (int) ( $GLOBALS['dpv_test_skus'][ (string) $sku ] ?? 0 );
}

function is_product() {
	return (bool) $GLOBALS['dpv_test_is_product'];
}

function is_attachment( $attachment = '' ) {
	unset( $attachment );
	return (bool) $GLOBALS['dpv_test_is_attachment'];
}

function status_header( $code, $description = '' ) {
	unset( $description );
	$GLOBALS['dpv_test_status_header'] = (int) $code;
}

function nocache_headers() {
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

function wp_send_json( $response = null ) {
	throw new Dpv_Test_Json_Sent( true, $response );
}

function get_user_meta( $user_id, $key = '', $single = false ) {
	unset( $user_id, $key );
	return $single ? '' : array();
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

require_once __DIR__ . '/stubs-store-api.php';
require_once dirname( __DIR__ ) . '/includes/class-install.php';
require_once dirname( __DIR__ ) . '/includes/class-bulk-rules.php';
if ( file_exists( dirname( __DIR__ ) . '/includes/class-customer-visibility.php' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-customer-visibility.php';
}
require_once dirname( __DIR__ ) . '/includes/class-visibility-filter.php';
require_once dirname( __DIR__ ) . '/includes/class-ajax.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-product-metabox.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-admin.php';
