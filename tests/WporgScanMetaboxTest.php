<?php
/**
 * The product edit screen save verifies its own nonce and capability before
 * anything is read, and stores the submitted role and customer lists as they
 * were sent.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use DragonProductVisibility\Product_Metabox;
use PHPUnit\Framework\TestCase;

final class WporgScanMetaboxTest extends TestCase {

	private const ACTION = 'dragonproductvisibility_save_visibility';

	private const TABLE = 'wp_dpv_customer_visibility';

	private Fake_Wpdb $wpdb;

	protected function setUp(): void {
		// The once-per-request guard is a static; each test is its own request.
		foreach ( array( 'saved_this_request', 'failed_saves' ) as $static_property ) {
			( new \ReflectionProperty( Product_Metabox::class, $static_property ) )->setValue( null, array() );
		}

		$this->wpdb           = \dpv_test_reset();
		$this->wpdb->tables[] = self::TABLE;
		$_POST                = array(
			'dragonproductvisibility_visibility_nonce' => \dpv_test_nonce( self::ACTION ),
			'dragonproductvisibility_restriction_mode' => 'blacklist',
			'dragonproductvisibility_roles'            => array( 'customer' ),
			'dragonproductvisibility_customers'        => array( '5', '7' ),
		);
	}

	protected function tearDown(): void {
		$_POST = array();
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
		$this->assertArrayNotHasKey( 'redirect_post_location', $GLOBALS['dpv_test_filters'], 'a refused save is not a failed save' );
	}

	// --- nonce and capability ----------------------------------------------------

	public function test_accepts_the_nonce_of_its_own_action(): void {
		Product_Metabox::instance()->save_product_data( 10 );

		$this->assertSame( 'blacklist', $GLOBALS['dpv_test_meta'][10]['_dpv_restriction_mode'] );
		$this->assertSame( array( 'customer' ), $this->stored_roles() );
		$this->assertSame( array( 5, 7 ), $this->stored_customers() );
	}

	public function test_without_the_nonce_field_nothing_is_written(): void {
		unset( $_POST['dragonproductvisibility_visibility_nonce'] );

		Product_Metabox::instance()->save_product_data( 10 );

		$this->assert_nothing_written();
	}

	public function test_with_another_actions_nonce_nothing_is_written(): void {
		$_POST['dragonproductvisibility_visibility_nonce'] = \dpv_test_nonce( 'dragonproductvisibility_admin_nonce' );

		Product_Metabox::instance()->save_product_data( 10 );

		$this->assert_nothing_written();
	}

	public function test_with_the_nonce_sent_as_an_array_nothing_is_written(): void {
		$_POST['dragonproductvisibility_visibility_nonce'] = array( \dpv_test_nonce( self::ACTION ) );

		Product_Metabox::instance()->save_product_data( 10 );

		$this->assert_nothing_written();
	}

	public function test_a_user_who_cannot_edit_this_product_writes_nothing(): void {
		$asked                   = array();
		$GLOBALS['dpv_test_can'] = static function ( $cap, $args ) use ( &$asked ) {
			$asked[] = array( $cap, $args );
			return 'read' === $cap;
		};

		Product_Metabox::instance()->save_product_data( 10 );

		$this->assertSame( array( array( 'edit_product', array( 10 ) ) ), $asked, 'the capability is asked for the product being saved' );
		$this->assert_nothing_written();
	}

	public function test_a_refused_save_does_not_use_up_the_once_per_request_guard(): void {
		unset( $_POST['dragonproductvisibility_visibility_nonce'] );
		Product_Metabox::instance()->save_product_data( 10 );

		$_POST['dragonproductvisibility_visibility_nonce'] = \dpv_test_nonce( self::ACTION );
		Product_Metabox::instance()->save_product_data( 10 );

		$this->assertSame( array( 'customer' ), $this->stored_roles() );
	}

	// --- the role list ---------------------------------------------------------------

	/**
	 * Role ids as other plugins register them. Each must be stored exactly.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function role_ids(): array {
		return WporgScanAjaxTest::role_ids();
	}

	/**
	 * @dataProvider role_ids
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'role_ids' )]
	public function test_stores_a_role_id_exactly_as_registered( string $role ): void {
		// WordPress adds slashes to every request value before a plugin sees it.
		$_POST['dragonproductvisibility_roles'] = array( addslashes( $role ) );

		Product_Metabox::instance()->save_product_data( 10 );

		$this->assertSame( array( $role ), $this->stored_roles() );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function raw_role_lists(): array {
		return WporgScanAjaxTest::raw_role_lists();
	}

	/**
	 * @dataProvider raw_role_lists
	 * @param mixed $raw Value of $_POST['dragonproductvisibility_roles'].
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'raw_role_lists' )]
	public function test_sanitizes_each_role_as_before( $raw ): void {
		$_POST['dragonproductvisibility_roles'] = $raw;
		$expected                               = array_values( array_map( 'sanitize_text_field', (array) wp_unslash( $raw ) ) );

		Product_Metabox::instance()->save_product_data( 10 );

		$this->assertSame( $expected, $this->stored_roles() );
	}

	public function test_without_a_role_list_stores_none(): void {
		unset( $_POST['dragonproductvisibility_roles'] );

		Product_Metabox::instance()->save_product_data( 10 );

		$this->assertSame( array(), $this->stored_roles() );
	}

	// --- the customer list -------------------------------------------------------------

	/**
	 * @return array<string, array{0: mixed, 1: int[]}>
	 */
	public static function raw_customer_lists(): array {
		return WporgScanAjaxTest::raw_customer_lists();
	}

	/**
	 * @dataProvider raw_customer_lists
	 * @param mixed $raw      Value of $_POST['dragonproductvisibility_customers'].
	 * @param int[] $expected Customer rows stored, in order.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'raw_customer_lists' )]
	public function test_stores_the_customer_ids_as_before( $raw, array $expected ): void {
		$_POST['dragonproductvisibility_customers'] = $raw;
		$before                                     = array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $raw ) ) ) ) );

		Product_Metabox::instance()->save_product_data( 10 );

		$this->assertSame( $expected, $this->stored_customers() );
		$this->assertSame( $before, $this->stored_customers(), 'the same ids every earlier release stored' );
	}

	public function test_drops_a_nested_customer_entry_instead_of_reading_it_as_an_id(): void {
		$_POST['dragonproductvisibility_customers'] = array( array( '5' ), '7' );

		Product_Metabox::instance()->save_product_data( 10 );

		$this->assertSame( array( 7 ), $this->stored_customers(), 'a list where an id belongs is not user 1' );
	}

	public function test_without_a_customer_list_stores_none(): void {
		unset( $_POST['dragonproductvisibility_customers'] );

		Product_Metabox::instance()->save_product_data( 10 );

		$this->assertSame( array(), $this->stored_customers() );
	}

	public function test_without_a_mode_field_stores_none(): void {
		unset( $_POST['dragonproductvisibility_restriction_mode'] );

		Product_Metabox::instance()->save_product_data( 10 );

		$this->assertSame( 'none', $GLOBALS['dpv_test_meta'][10]['_dpv_restriction_mode'] );
	}
}
