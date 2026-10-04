<?php
/**
 * Customer search and the saved-customer labels only show accounts of the
 * current site, and keep the response shape the picker reads.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use DragonProductVisibility\Ajax;
use DragonProductVisibility\Product_Metabox;
use PHPUnit\Framework\TestCase;

final class CustomerSearchScopeTest extends TestCase {

	protected function setUp(): void {
		\dpv_test_reset();
		$GLOBALS['dpv_test_users'] = array(
			5 => array(
				'user_login'   => 'ann',
				'user_email'   => 'ann@example.test',
				'display_name' => 'Ann Shopper',
				'blogs'        => array( 1 ),
			),
			7 => array(
				'user_login'   => 'annette',
				'user_email'   => 'annette@other.test',
				'display_name' => 'Annette Elsewhere',
				'blogs'        => array( 2 ),
			),
			9 => array(
				'user_login'   => 'bob',
				'user_email'   => 'bob@example.test',
				'display_name' => 'Aardvark Bob',
				'blogs'        => array( 1, 2 ),
			),
		);
	}

	protected function tearDown(): void {
		$_REQUEST = array();
	}

	private function search( string $term ): \Dpv_Test_Json_Sent {
		$_REQUEST = array(
			'nonce'       => \dpv_test_nonce( 'dragonproductvisibility_admin_nonce' ),
			'search_term' => $term,
		);
		try {
			Ajax::instance()->search_customers();
		} catch ( \Dpv_Test_Json_Sent $sent ) {
			return $sent;
		}
		$this->fail( 'handler did not send a JSON response' );
	}

	public function test_search_returns_id_and_label_ordered_by_name(): void {
		$sent = $this->search( 'example.test' );

		$this->assertTrue( $sent->success );
		$this->assertSame(
			'[{"id":"9","text":"Aardvark Bob (bob@example.test)"},{"id":"5","text":"Ann Shopper (ann@example.test)"}]',
			json_encode( $sent->data )
		);
	}

	public function test_search_matches_login_email_and_display_name_up_to_fifty(): void {
		$this->search( 'ann' );

		$query = $GLOBALS['dpv_test_user_queries'][0];
		$this->assertSame( '*ann*', $query['search'] );
		$this->assertSame( array( 'user_login', 'user_email', 'display_name' ), $query['search_columns'] );
		$this->assertSame( 50, $query['number'] );
		$this->assertSame( 'display_name', $query['orderby'] );
		$this->assertArrayNotHasKey( 'blog_id', $query, 'the current site is the default scope' );
	}

	public function test_multisite_search_lists_only_members_of_this_site(): void {
		$GLOBALS['dpv_test_multisite'] = true;

		$sent = $this->search( 'ann' );

		$this->assertSame( '[{"id":"5","text":"Ann Shopper (ann@example.test)"}]', json_encode( $sent->data ) );
	}

	public function test_multisite_labels_skip_accounts_of_other_sites(): void {
		$GLOBALS['dpv_test_multisite'] = true;

		$this->assertSame( array( 5, 9 ), array_keys( Product_Metabox::selected_customer_labels( array( 5, 7, 9 ) ) ) );
	}

	public function test_single_site_labels_are_unchanged(): void {
		$this->assertSame( array( 5, 7, 9 ), array_keys( Product_Metabox::selected_customer_labels( array( 5, 7, 9 ) ) ) );
	}
}
