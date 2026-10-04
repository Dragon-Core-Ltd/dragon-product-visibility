<?php
/**
 * The AJAX handlers verify their own nonce and capability before anything is
 * read, and store the submitted role and customer lists as they were sent.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use DragonProductVisibility\Ajax;
use PHPUnit\Framework\TestCase;

final class WporgScanAjaxTest extends TestCase {

	private const ACTION = 'dragonproductvisibility_admin_nonce';

	private const TABLE = 'wp_dpv_customer_visibility';

	private Fake_Wpdb $wpdb;

	protected function setUp(): void {
		$this->wpdb                    = \dpv_test_reset();
		$this->wpdb->tables[]          = self::TABLE;
		$GLOBALS['dpv_test_posts'][10] = (object) array(
			'ID'        => 10,
			'post_type' => 'product',
		);
		$_POST                         = array(
			'nonce'            => \dpv_test_nonce( self::ACTION ),
			'product_id'       => '10',
			'restriction_mode' => 'whitelist',
			'customer_ids'     => array( '5', '7' ),
			'role_ids'         => array( 'customer' ),
		);
	}

	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
	}

	private function save(): \Dpv_Test_Json_Sent {
		try {
			Ajax::instance()->save_visibility_rules();
		} catch ( \Dpv_Test_Json_Sent $sent ) {
			return $sent;
		}
		$this->fail( 'handler did not send a JSON response' );
	}

	private function search( array $request ): \Dpv_Test_Json_Sent {
		$_REQUEST = $request;
		try {
			Ajax::instance()->search_customers();
		} catch ( \Dpv_Test_Json_Sent $sent ) {
			return $sent;
		}
		$this->fail( 'handler did not send a JSON response' );
	}

	private function stored_roles() {
		return $GLOBALS['dpv_test_meta'][10]['_dpv_visible_roles'] ?? null;
	}

	private function stored_customers(): array {
		return array_map( 'intval', array_column( $this->wpdb->rows_in( self::TABLE ), 'customer_id' ) );
	}

	private function assert_nothing_written(): void {
		$this->assertSame( array(), $this->wpdb->queries, 'no query may run' );
		$this->assertSame( 0, $this->wpdb->insert_calls );
		$this->assertSame( array(), $GLOBALS['dpv_test_meta'], 'no meta may be written' );
	}

	// --- save: nonce and capability ---------------------------------------------

	public function test_save_accepts_the_nonce_of_its_own_action(): void {
		$sent = $this->save();

		$this->assertTrue( $sent->success );
		$this->assertSame( 'whitelist', $GLOBALS['dpv_test_meta'][10]['_dpv_restriction_mode'] );
		$this->assertSame( array( 'customer' ), $this->stored_roles() );
		$this->assertSame( array( 5, 7 ), $this->stored_customers() );
	}

	public function test_save_without_a_nonce_is_refused_and_writes_nothing(): void {
		unset( $_POST['nonce'] );

		$sent = $this->save();

		$this->assertFalse( $sent->success );
		$this->assertSame( 'Security check failed', $sent->data['message'] );
		$this->assert_nothing_written();
	}

	public function test_save_with_another_actions_nonce_is_refused(): void {
		$_POST['nonce'] = \dpv_test_nonce( 'dragonproductvisibility_save_visibility' );

		$sent = $this->save();

		$this->assertFalse( $sent->success );
		$this->assertSame( 'Security check failed', $sent->data['message'] );
		$this->assert_nothing_written();
	}

	public function test_save_with_the_nonce_sent_as_an_array_is_refused(): void {
		$_POST['nonce'] = array( \dpv_test_nonce( self::ACTION ) );

		$sent = $this->save();

		$this->assertFalse( $sent->success );
		$this->assert_nothing_written();
	}

	public function test_save_is_refused_for_a_user_who_cannot_edit_products(): void {
		// A subscriber or a shop customer: read only.
		$GLOBALS['dpv_test_can'] = static function ( $cap ) {
			return 'read' === $cap;
		};

		$sent = $this->save();

		$this->assertFalse( $sent->success );
		$this->assertSame( 'Permission denied', $sent->data['message'] );
		$this->assert_nothing_written();
	}

	public function test_save_checks_the_nonce_before_the_capability(): void {
		$asked                   = array();
		$GLOBALS['dpv_test_can'] = static function ( $cap ) use ( &$asked ) {
			$asked[] = $cap;
			return true;
		};
		unset( $_POST['nonce'] );

		$this->save();

		$this->assertSame( array(), $asked, 'a request with no nonce is refused before anything else is looked at' );
	}

	// --- save: the role list ------------------------------------------------------

	/**
	 * Role ids as other plugins register them. Each must be stored exactly.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function role_ids(): array {
		return array(
			'lowercase'        => array( 'customer' ),
			'underscore'       => array( 'shop_manager' ),
			'mixed case'       => array( 'WholesaleBuyer' ),
			'upper case'       => array( 'VIP' ),
			'space'            => array( 'Shop Manager' ),
			'hyphen'           => array( 'wholesale-buyer' ),
			'dot'              => array( 'b2b.vip' ),
			'all of them'      => array( 'DPV-Scn Whole.Sale' ),
			'numeric'          => array( '123' ),
			'multibyte'        => array( 'Größe 日本' ),
			'apostrophe'       => array( "O'Brien" ),
			'double quote'     => array( 'The "Trade" tier' ),
			'ampersand'        => array( 'Trade & Co' ),
			'plus and bracket' => array( 'tier+1 [gold]' ),
		);
	}

	/**
	 * @dataProvider role_ids
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'role_ids' )]
	public function test_save_stores_a_role_id_exactly_as_registered( string $role ): void {
		// WordPress adds slashes to every request value before a plugin sees it.
		$_POST['role_ids'] = array( addslashes( $role ) );

		$sent = $this->save();

		$this->assertTrue( $sent->success );
		$this->assertSame( array( $role ), $this->stored_roles() );
	}

	/**
	 * Raw role_ids values, legitimate and not. Each element must come out as
	 * sanitize_text_field() over the unslashed element, which is what every
	 * earlier release stored.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function raw_role_lists(): array {
		return array(
			'one role'                 => array( array( 'customer' ) ),
			'several roles'            => array( array( 'Shop Manager', 'wholesale-buyer', 'b2b.vip', 'UPPER_lower', '123' ) ),
			'slashed quotes'           => array( array( "O\\'Brien \\\"Trade\\\" & Co" ) ),
			'padding and inner spaces' => array( array( '  padded  ', 'two  spaces', "tab\there", "line\nbreak" ) ),
			'markup and octets'        => array( array( '<b>bold</b>', 'a%41b', 'less < than' ) ),
			'a nested list'            => array( array( array( 'nested' ), 'customer' ) ),
			'a single string'          => array( 'customer' ),
			'an empty string'          => array( '' ),
			'an empty list'            => array( array() ),
			'keyed entries'            => array(
				array(
					3   => 'a',
					'x' => 'b',
				),
			),
		);
	}

	/**
	 * @dataProvider raw_role_lists
	 * @param mixed $raw Value of $_POST['role_ids'].
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'raw_role_lists' )]
	public function test_save_sanitizes_each_role_as_before( $raw ): void {
		$_POST['role_ids'] = $raw;
		$expected          = array_values( array_map( 'sanitize_text_field', (array) wp_unslash( $raw ) ) );

		$sent = $this->save();

		$this->assertTrue( $sent->success );
		$this->assertSame( $expected, $this->stored_roles() );
	}

	public function test_save_turns_a_nested_role_entry_into_an_empty_role(): void {
		$_POST['role_ids'] = array( array( 'nested' ), 'customer' );

		$this->save();

		$this->assertSame( array( '', 'customer' ), $this->stored_roles() );
	}

	public function test_save_without_a_role_list_stores_none(): void {
		unset( $_POST['role_ids'] );

		$this->save();

		$this->assertSame( array(), $this->stored_roles() );
	}

	// --- save: the customer list ----------------------------------------------------

	/**
	 * @return array<string, array{0: mixed, 1: int[]}>
	 */
	public static function raw_customer_lists(): array {
		return array(
			'two ids'                 => array( array( '5', '7' ), array( 5, 7 ) ),
			'a repeated id'           => array( array( '7', '5', '7' ), array( 7, 5 ) ),
			'a single string'         => array( '5', array( 5 ) ),
			'an empty string'         => array( '', array() ),
			'an empty list'           => array( array(), array() ),
			'zero, negative and junk' => array( array( '0', '-3', 'abc', '4x', ' 9' ), array( 3, 4, 9 ) ),
			'a comma list in one'     => array( '5,7', array( 5 ) ),
			'keyed entries'           => array(
				array(
					3   => '8',
					'x' => '6',
				),
				array( 8, 6 ),
			),
		);
	}

	/**
	 * @dataProvider raw_customer_lists
	 * @param mixed $raw      Value of $_POST['customer_ids'].
	 * @param int[] $expected Customer rows stored, in order.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'raw_customer_lists' )]
	public function test_save_stores_the_customer_ids_as_before( $raw, array $expected ): void {
		$_POST['customer_ids'] = $raw;
		$before                = array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $raw ) ) ) ) );

		$sent = $this->save();

		$this->assertTrue( $sent->success );
		$this->assertSame( $expected, $this->stored_customers() );
		$this->assertSame( $before, $this->stored_customers(), 'the same ids every earlier release stored' );
	}

	public function test_save_drops_a_nested_customer_entry_instead_of_reading_it_as_an_id(): void {
		$_POST['customer_ids'] = array( array( '5' ), '7' );

		$sent = $this->save();

		$this->assertTrue( $sent->success );
		$this->assertSame( array( 7 ), $this->stored_customers(), 'a list where an id belongs is not user 1' );
	}

	public function test_save_without_a_customer_list_stores_none(): void {
		unset( $_POST['customer_ids'] );

		$this->save();

		$this->assertSame( array(), $this->stored_customers() );
	}

	// --- search ---------------------------------------------------------------------

	public function test_search_accepts_the_nonce_of_its_own_action(): void {
		$sent = $this->search(
			array(
				'nonce'       => \dpv_test_nonce( self::ACTION ),
				'search_term' => 'ann',
			)
		);

		$this->assertTrue( $sent->success );
		$this->assertSame( 1, count( $GLOBALS['dpv_test_user_queries'] ) );
	}

	public function test_search_without_a_nonce_is_refused_before_any_lookup(): void {
		$sent = $this->search( array( 'search_term' => 'ann' ) );

		$this->assertFalse( $sent->success );
		$this->assertSame( 'Security check failed', $sent->data['message'] );
		$this->assertSame( 0, count( $GLOBALS['dpv_test_user_queries'] ) );
	}

	public function test_search_with_another_actions_nonce_is_refused(): void {
		$sent = $this->search(
			array(
				'nonce'       => \dpv_test_nonce( 'dragonproductvisibility_save_visibility' ),
				'search_term' => 'ann',
			)
		);

		$this->assertFalse( $sent->success );
		$this->assertSame( 0, count( $GLOBALS['dpv_test_user_queries'] ) );
	}

	public function test_search_is_refused_for_a_user_who_can_only_read(): void {
		$GLOBALS['dpv_test_can'] = static function ( $cap ) {
			return 'read' === $cap;
		};

		$sent = $this->search(
			array(
				'nonce'       => \dpv_test_nonce( self::ACTION ),
				'search_term' => 'ann',
			)
		);

		$this->assertFalse( $sent->success );
		$this->assertSame( 'Permission denied', $sent->data['message'] );
		$this->assertSame( 0, count( $GLOBALS['dpv_test_user_queries'] ) );
	}

	public function test_search_with_the_term_sent_as_an_array_searches_for_nothing(): void {
		$sent = $this->search(
			array(
				'nonce'       => \dpv_test_nonce( self::ACTION ),
				'search_term' => array( 'ann' ),
			)
		);

		$this->assertTrue( $sent->success );
	}
}
