<?php
/**
 * Runs the plugin's request paths in a process where WooCommerce's functions
 * and classes are undefined, as when WooCommerce is inactive or switched off
 * for one request. Prints "OK" when nothing fataled or raised a notice.
 *
 * Run by NoWooCommerceTest with DPV_TEST_WITHOUT_WOOCOMMERCE=1.
 *
 * @package DragonProductVisibility
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

use DragonProductVisibility\Plugin;
use DragonProductVisibility\Visibility_Filter;

set_error_handler(
	static function ( int $severity, string $message, string $file, int $line ): bool {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

try {
	require dirname( __DIR__ ) . '/bootstrap.php';
	require_once dirname( __DIR__, 2 ) . '/includes/class-plugin.php';

	foreach ( array( 'WC', 'wc_add_notice', 'wc_get_product', 'wc_get_product_id_by_sku', 'is_product' ) as $fn ) {
		if ( function_exists( $fn ) ) {
			throw new RuntimeException( "$fn is defined; the probe must run without WooCommerce" );
		}
	}
	if ( class_exists( 'WooCommerce', false ) || class_exists( 'WC_Product', false ) ) {
		throw new RuntimeException( 'WooCommerce classes are defined; the probe must run without WooCommerce' );
	}

	dpv_test_reset();

	// A shopper, and a product whitelisted for nobody they match.
	$GLOBALS['dpv_test_can']   = static fn( $cap ) => 'manage_woocommerce' !== $cap;
	$GLOBALS['dpv_test_posts'] = array(
		10 => (object) array(
			'ID'          => 10,
			'post_type'   => 'product',
			'post_parent' => 0,
			'post_name'   => 'secret-widget',
		),
	);
	$GLOBALS['dpv_test_meta'][10]['_dpv_restriction_mode'] = 'whitelist';

	// Plugin bootstrap in a front-end, an AJAX and an admin request registers
	// no WooCommerce-dependent hooks.
	$plugin = Plugin::instance();
	foreach ( array( array( false, false ), array( true, true ), array( true, false ) ) as $context ) {
		list( $GLOBALS['dpv_test_is_admin'], $GLOBALS['dpv_test_doing_ajax'] ) = $context;
		$GLOBALS['dpv_test_actions'] = array();
		$plugin->init();
		foreach ( $GLOBALS['dpv_test_actions'] as $hook ) {
			throw new RuntimeException( 'init() hooked ' . $hook[0] . ' without WooCommerce' );
		}
	}
	$GLOBALS['dpv_test_is_admin']   = false;
	$GLOBALS['dpv_test_doing_ajax'] = false;

	// The filter's own entry points, should they be reached anyway.
	$filter = ( new ReflectionClass( Visibility_Filter::class ) )->newInstanceWithoutConstructor();

	$GLOBALS['post'] = $GLOBALS['dpv_test_posts'][10];
	$filter->check_single_product_access();

	if ( false !== $filter->validate_add_to_cart( true, 10, 1 ) ) {
		throw new RuntimeException( 'validate_add_to_cart() let a restricted product through' );
	}

	$filter->validate_cart_items();

	$notice = new ReflectionMethod( Visibility_Filter::class, 'add_restricted_notice' );
	$notice->invoke( $filter );

	$filter->filter_pre_do_shortcode_tag( false, 'product', array( 'sku' => 'ABC' ) );

	echo 'OK';
} catch ( Throwable $e ) {
	echo get_class( $e ) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
	exit( 1 );
}
