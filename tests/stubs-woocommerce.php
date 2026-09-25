<?php
/**
 * WooCommerce doubles: the functions and classes the plugin calls, loaded
 * only when a test run stands for a request with WooCommerce active.
 *
 * @package DragonProductVisibility
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

/**
 * WooCommerce main class double: only its presence is checked.
 */
final class WooCommerce {
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

function wc_add_notice( $message, $type = 'success' ) {
	$GLOBALS['dpv_test_notices'][] = array( $message, $type );
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
 * Mirrors WooCommerce: the product or variation ID for a SKU, 0 when none.
 */
function wc_get_product_id_by_sku( $sku ) {
	return (int) ( $GLOBALS['dpv_test_skus'][ (string) $sku ] ?? 0 );
}

function is_product() {
	return (bool) $GLOBALS['dpv_test_is_product'];
}
