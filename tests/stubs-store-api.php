<?php
/**
 * Stand-ins for the WooCommerce Store API single-product route classes, so a
 * test handler can carry the controller WooCommerce would have matched.
 *
 * @package DragonProductVisibility
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

namespace Automattic\WooCommerce\StoreApi\Routes\V1;

/**
 * Store API products/{id}.
 */
class ProductsById {
	public function get_response( $request ) {
		return $request;
	}
}

/**
 * Store API products/{slug}.
 */
class ProductsBySlug {
	public function get_response( $request ) {
		return $request;
	}
}

/**
 * Store API products/reviews.
 */
class ProductReviews {
	public function get_response( $request ) {
		return $request;
	}
}

/**
 * Store API cart/add-item.
 */
class CartAddItem {
	public function get_response( $request ) {
		return $request;
	}
}

/**
 * Store API cart/items.
 */
class CartItems {
	public function get_response( $request ) {
		return $request;
	}
}

/**
 * Store API shopper-lists/{slug}/items.
 */
class ShopperListItems {
	public function get_response( $request ) {
		return $request;
	}
}
