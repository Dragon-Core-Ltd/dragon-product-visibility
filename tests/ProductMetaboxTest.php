<?php
/**
 * The product edit screen save surfaces a failed write to the admin.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use DragonProductVisibility\Product_Metabox;
use PHPUnit\Framework\TestCase;

final class ProductMetaboxTest extends TestCase {

	private Fake_Wpdb $wpdb;

	protected function setUp(): void {
		// The once-per-request guard is a static; each test is its own request.
		foreach ( array( 'saved_this_request', 'failed_saves' ) as $static_property ) {
			( new \ReflectionProperty( Product_Metabox::class, $static_property ) )->setValue( null, array() );
		}

		$this->wpdb           = \dpv_test_reset();
		$this->wpdb->tables[] = 'wp_dpv_customer_visibility';
		$_POST                = array(
			'dragonproductvisibility_visibility_nonce'   => 'valid',
			'dragonproductvisibility_restriction_mode' => 'whitelist',
			'dragonproductvisibility_roles'            => array( 'customer' ),
			'dragonproductvisibility_customers'        => array( '5', '7' ),
		);
	}

	private function redirect_location( string $url ): string {
		foreach ( $GLOBALS['dpv_test_filters']['redirect_post_location'] ?? array() as $callback ) {
			$url = $callback( $url, 10 );
		}
		return $url;
	}

	public function test_successful_save_leaves_no_error_flag(): void {
		Product_Metabox::instance()->save_product_data( 10 );

		$this->assertCount( 2, $this->wpdb->rows_in( 'wp_dpv_customer_visibility' ) );
		$this->assertSame( 'post.php?post=10', $this->redirect_location( 'post.php?post=10' ) );
	}

	public function test_failed_save_flags_the_error_on_the_redirect_url(): void {
		$this->wpdb->fail_insert_at = 1;

		Product_Metabox::instance()->save_product_data( 10 );

		$this->assertContains( 'ROLLBACK', $this->wpdb->queries );
		$this->assertStringContainsString( 'dragonproductvisibility_save_error=1', $this->redirect_location( 'post.php?post=10' ), 'the admin must be told the restrictions were not saved, without depending on a database write' );
	}

	public function test_a_failed_save_that_changed_the_stored_rules_is_flagged_as_a_partial_save(): void {
		$this->wpdb->use_non_transactional_storage();
		update_post_meta( 10, '_dpv_restriction_mode', 'blacklist' );
		$this->wpdb->rows['wp_dpv_customer_visibility'] = array(
			array( 'product_id' => 10, 'customer_id' => 3 ),
			array( 'product_id' => 10, 'customer_id' => 5 ),
			array( 'product_id' => 10, 'customer_id' => 7 ),
		);
		$_POST['dragonproductvisibility_restriction_mode'] = 'blacklist';
		$_POST['dragonproductvisibility_roles']            = array();
		$_POST['dragonproductvisibility_customers']        = array( '7' );
		$this->wpdb->fail_delete_at = 2;
		$this->wpdb->fail_insert_at = 1;

		Product_Metabox::instance()->save_product_data( 10 );

		$this->assertStringContainsString(
			'dragonproductvisibility_save_error=2',
			$this->redirect_location( 'post.php?post=10' ),
			'a save that left the stored rules changed must not be flagged as one that kept them'
		);
	}

	public function test_saves_once_per_product_per_request(): void {
		$this->wpdb->fail_insert_at = 1;

		Product_Metabox::instance()->save_product_data( 10 );
		Product_Metabox::instance()->save_product_data( 10 );

		$this->assertSame( 1, count( array_keys( $this->wpdb->queries, 'START TRANSACTION', true ) ), 'the generic and type-specific WooCommerce hooks must not save twice' );
		$this->assertStringContainsString( 'dragonproductvisibility_save_error=1', $this->redirect_location( 'post.php?post=10' ) );
	}

	public function test_customer_emails_are_shown_to_users_who_can_list_users(): void {
		$GLOBALS['dpv_test_can'] = static function ( $cap ) {
			return in_array( $cap, array( 'edit_products', 'list_users' ), true );
		};

		$labels = Product_Metabox::selected_customer_labels( array( 5 ) );

		$this->assertSame( array( 5 => 'Customer 5 (customer5@example.com)' ), $labels );
	}

	public function test_customer_emails_are_withheld_from_users_who_can_only_edit_products(): void {
		// The customer search AJAX already refuses these users; the saved list on
		// the product screen must not hand them the same addresses.
		$GLOBALS['dpv_test_can'] = static function ( $cap ) {
			return 'edit_products' === $cap;
		};

		$this->assertNull( Product_Metabox::selected_customer_labels( array( 5 ) ) );
	}
}
