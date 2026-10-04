<?php
/**
 * The category/tag rule handlers verify their own nonce and capability before
 * anything is read, and a rule keeps the role ids exactly as the site
 * registers them.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use DragonProductVisibility\Bulk_Rules;
use DragonProductVisibility\Bulk_Rules_Admin;
use PHPUnit\Framework\TestCase;

final class WporgScanBulkRulesTest extends TestCase {

	private const ADD = 'dragonproductvisibility_add_bulk_rule';

	private const DELETE = 'dragonproductvisibility_delete_bulk_rule';

	protected function setUp(): void {
		\dpv_test_reset();
		$GLOBALS['dpv_test_roles'] = array(
			'administrator'      => array( 'name' => 'Administrator' ),
			'customer'           => array( 'name' => 'Customer' ),
			'Customer'           => array( 'name' => 'Customer (legacy import)' ),
			'DPV-Scn Whole.Sale' => array( 'name' => 'Wholesale' ),
			'Größe 日本'           => array( 'name' => 'Multibyte' ),
			"O'Brien & Co"       => array( 'name' => 'Quoted' ),
		);
		$GLOBALS['dpv_test_terms'] = array(
			'product_cat' => array( 3 ),
			'product_tag' => array( 8 ),
		);
	}

	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Run a handler as admin-post.php would, returning what ended the request.
	 */
	private function run_handler( string $method, array $post ): \RuntimeException {
		$_POST    = $post;
		$_REQUEST = $post;
		try {
			Bulk_Rules_Admin::instance()->$method();
		} catch ( \Dpv_Test_Die | \Dpv_Test_Redirect $end ) {
			return $end;
		}
		$this->fail( 'handler did not end the request' );
	}

	private function add_fields( array $overrides = array() ): array {
		return array_merge(
			array(
				'_wpnonce'  => \dpv_test_nonce( self::ADD ),
				'action'    => self::ADD,
				'dpv_term'  => 'product_cat:3',
				'dpv_mode'  => 'blacklist',
				'dpv_roles' => array( 'customer' ),
			),
			$overrides
		);
	}

	private function stored() {
		return $GLOBALS['dpv_test_options'][ Bulk_Rules::OPTION ] ?? null;
	}

	// --- add: nonce and capability ---------------------------------------------------

	public function test_add_stores_the_rule_and_reports_it_added(): void {
		$end = $this->run_handler( 'handle_add', $this->add_fields() );

		$this->assertInstanceOf( \Dpv_Test_Redirect::class, $end );
		$this->assertSame( 'https://example.test/wp-admin/edit.php?post_type=product&page=dragonproductvisibility-rules&dpv_msg=added', $end->location );
		$this->assertSame(
			array(
				array(
					'id'       => 'r' . substr( md5( 'product_cat:3:blacklist:customer' ), 0, 16 ),
					'taxonomy' => 'product_cat',
					'term_id'  => 3,
					'mode'     => 'blacklist',
					'roles'    => array( 'customer' ),
				),
			),
			$this->stored()
		);
	}

	public function test_add_without_a_nonce_is_refused_and_stores_nothing(): void {
		$fields = $this->add_fields();
		unset( $fields['_wpnonce'] );

		$end = $this->run_handler( 'handle_add', $fields );

		$this->assertInstanceOf( \Dpv_Test_Die::class, $end );
		$this->assertSame( 403, $end->status );
		$this->assertNull( $this->stored() );
	}

	public function test_add_with_the_delete_nonce_is_refused(): void {
		$end = $this->run_handler( 'handle_add', $this->add_fields( array( '_wpnonce' => \dpv_test_nonce( self::DELETE ) ) ) );

		$this->assertInstanceOf( \Dpv_Test_Die::class, $end );
		$this->assertSame( 403, $end->status );
		$this->assertNull( $this->stored() );
	}

	public function test_add_with_the_nonce_sent_as_an_array_is_refused(): void {
		$end = $this->run_handler( 'handle_add', $this->add_fields( array( '_wpnonce' => array( \dpv_test_nonce( self::ADD ) ) ) ) );

		$this->assertInstanceOf( \Dpv_Test_Die::class, $end );
		$this->assertNull( $this->stored() );
	}

	public function test_add_is_refused_for_a_user_who_cannot_manage_the_shop(): void {
		$asked                   = array();
		$GLOBALS['dpv_test_can'] = static function ( $cap ) use ( &$asked ) {
			$asked[] = $cap;
			return 'read' === $cap;
		};

		$end = $this->run_handler( 'handle_add', $this->add_fields() );

		$this->assertInstanceOf( \Dpv_Test_Die::class, $end );
		$this->assertSame( 'Permission denied.', $end->getMessage() );
		$this->assertSame( array( 'manage_woocommerce' ), $asked );
		$this->assertNull( $this->stored() );
	}

	// --- add: validation ----------------------------------------------------------------

	/**
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function refused_rules(): array {
		return array(
			'no term'                  => array( array( 'dpv_term' => '' ), 'term' ),
			'term missing'             => array( array( 'dpv_term' => null ), 'term' ),
			'term as an array'         => array( array( 'dpv_term' => array( 'product_cat:3' ) ), 'term' ),
			'unknown taxonomy'         => array( array( 'dpv_term' => 'category:3' ), 'term' ),
			'term that does not exist' => array( array( 'dpv_term' => 'product_cat:999' ), 'term' ),
			'term of the other kind'   => array( array( 'dpv_term' => 'product_tag:3' ), 'term' ),
			'unknown mode'             => array( array( 'dpv_mode' => 'sideways' ), 'mode' ),
			'mode as an array'         => array( array( 'dpv_mode' => array( 'blacklist' ) ), 'mode' ),
			'no roles'                 => array( array( 'dpv_roles' => null ), 'roles' ),
			'empty role list'          => array( array( 'dpv_roles' => array() ), 'roles' ),
			'roles as a string'        => array( array( 'dpv_roles' => 'customer' ), 'roles' ),
			'only an unknown role'     => array( array( 'dpv_roles' => array( 'made_up' ) ), 'roles' ),
			'only a nested entry'      => array( array( 'dpv_roles' => array( array( 'customer' ) ) ), 'roles' ),
		);
	}

	/**
	 * @dataProvider refused_rules
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'refused_rules' )]
	public function test_add_refuses_an_incomplete_rule( array $overrides, string $error ): void {
		$fields = array_filter(
			$this->add_fields( $overrides ),
			static function ( $value ) {
				return null !== $value;
			}
		);

		$end = $this->run_handler( 'handle_add', $fields );

		$this->assertInstanceOf( \Dpv_Test_Redirect::class, $end );
		$this->assertStringEndsWith( '&dpv_error=' . $error, $end->location );
		$this->assertNull( $this->stored() );
	}

	public function test_add_keeps_real_roles_and_drops_the_rest(): void {
		$end = $this->run_handler(
			'handle_add',
			$this->add_fields( array( 'dpv_roles' => array( 'customer', 'made_up', array( 'administrator' ), 'administrator' ) ) )
		);

		$this->assertStringEndsWith( '&dpv_msg=added', $end->location );
		$this->assertSame( array( 'customer', 'administrator' ), $this->stored()[0]['roles'] );
	}

	public function test_add_reports_a_save_that_did_not_land(): void {
		$GLOBALS['dpv_test_option_write_fail'] = true;

		$end = $this->run_handler( 'handle_add', $this->add_fields() );

		$this->assertStringEndsWith( '&dpv_error=save', $end->location );
	}

	// --- add: role ids are kept exactly ---------------------------------------------------

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function registered_role_ids(): array {
		return array(
			'lowercase'                 => array( 'customer' ),
			'mixed case, space, hyphen' => array( 'DPV-Scn Whole.Sale' ),
			'multibyte'                 => array( 'Größe 日本' ),
			'apostrophe and ampersand'  => array( "O'Brien & Co" ),
		);
	}

	/**
	 * @dataProvider registered_role_ids
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'registered_role_ids' )]
	public function test_add_stores_a_role_id_exactly_as_registered( string $role ): void {
		// WordPress adds slashes to every request value before a plugin sees it.
		$end = $this->run_handler( 'handle_add', $this->add_fields( array( 'dpv_roles' => array( addslashes( $role ) ) ) ) );

		$this->assertStringEndsWith( '&dpv_msg=added', $end->location, 'every registered role can be targeted' );
		$this->assertSame( array( $role ), $this->stored()[0]['roles'] );
		$this->assertSame( array( $role ), Bulk_Rules::all()[0]['roles'], 'and it reads back unchanged' );
	}

	public function test_add_does_not_fold_a_role_onto_another_role_with_a_similar_id(): void {
		$this->run_handler( 'handle_add', $this->add_fields( array( 'dpv_roles' => array( 'Customer' ) ) ) );

		$this->assertSame( array( 'Customer' ), $this->stored()[0]['roles'], 'the rule names the role that was ticked, not "customer"' );
	}

	public function test_a_rule_on_a_mixed_case_role_decides_for_that_role(): void {
		$this->run_handler( 'handle_add', $this->add_fields( array( 'dpv_mode' => 'whitelist', 'dpv_roles' => array( 'DPV-Scn Whole.Sale' ) ) ) );
		$rule = Bulk_Rules::all()[0];

		$this->assertFalse( Bulk_Rules::rule_denies_roles( $rule, array( 'DPV-Scn Whole.Sale' ) ) );
		$this->assertTrue( Bulk_Rules::rule_denies_roles( $rule, array( 'customer' ) ) );
		$this->assertTrue( Bulk_Rules::rule_denies_roles( $rule, array() ) );
	}

	public function test_a_stored_lowercase_rule_still_decides_for_its_role(): void {
		$GLOBALS['dpv_test_options'][ Bulk_Rules::OPTION ] = array(
			array(
				'id'       => 'r' . substr( md5( 'product_cat:3:whitelist:customer' ), 0, 16 ),
				'taxonomy' => 'product_cat',
				'term_id'  => 3,
				'mode'     => 'whitelist',
				'roles'    => array( 'customer' ),
			),
		);
		$rule = Bulk_Rules::all()[0];

		$this->assertFalse( Bulk_Rules::rule_denies_roles( $rule, array( 'customer' ) ) );
		$this->assertTrue( Bulk_Rules::rule_denies_roles( $rule, array( 'Customer' ) ), 'role ids are compared exactly' );
	}

	public function test_a_stored_rule_naming_an_unregistered_role_survives_an_unrelated_add(): void {
		// A membership plugin removed its role while deactivated; the rule keeps it.
		$kept = array(
			'id'       => 'r' . substr( md5( 'product_tag:8:blacklist:Gone_Role,customer' ), 0, 16 ),
			'taxonomy' => 'product_tag',
			'term_id'  => 8,
			'mode'     => 'blacklist',
			'roles'    => array( 'Gone_Role', 'customer' ),
		);
		$GLOBALS['dpv_test_options'][ Bulk_Rules::OPTION ] = array( $kept );

		$end = $this->run_handler( 'handle_add', $this->add_fields() );

		$this->assertStringEndsWith( '&dpv_msg=added', $end->location );
		$this->assertSame( $kept, $this->stored()[0] );
		$this->assertCount( 2, $this->stored() );
	}

	public function test_add_refuses_a_role_that_is_not_registered_even_when_it_folds_onto_one(): void {
		$end = $this->run_handler( 'handle_add', $this->add_fields( array( 'dpv_roles' => array( 'ADMINISTRATOR', 'dpv-scn whole.sale' ) ) ) );

		$this->assertStringEndsWith( '&dpv_error=roles', $end->location );
		$this->assertNull( $this->stored() );
	}

	// --- stored rules ------------------------------------------------------------------------

	public function test_a_rule_stored_by_an_earlier_release_keeps_its_id_and_roles(): void {
		$stored = array(
			'id'       => 'r' . substr( md5( 'product_tag:8:whitelist:customer,shop_manager' ), 0, 16 ),
			'taxonomy' => 'product_tag',
			'term_id'  => 8,
			'mode'     => 'whitelist',
			'roles'    => array( 'shop_manager', 'customer' ),
		);
		$GLOBALS['dpv_test_options'][ Bulk_Rules::OPTION ] = array( $stored );

		$this->assertSame( array( $stored ), Bulk_Rules::all() );
	}

	public function test_a_stored_role_entry_that_is_not_a_string_reads_as_an_empty_role(): void {
		$rule = Bulk_Rules::sanitize(
			array(
				'taxonomy' => 'product_cat',
				'term_id'  => 3,
				'mode'     => 'blacklist',
				'roles'    => array( array( 'customer' ), 'customer', 'customer', 7 ),
			)
		);

		$this->assertSame( array( '', 'customer', '7' ), $rule['roles'] );
	}

	// --- delete ------------------------------------------------------------------------------

	private function seed_rule(): string {
		Bulk_Rules::save(
			array(
				array(
					'taxonomy' => 'product_cat',
					'term_id'  => 3,
					'mode'     => 'blacklist',
					'roles'    => array( 'customer' ),
				),
			)
		);
		return Bulk_Rules::all()[0]['id'];
	}

	private function delete_fields( string $id, array $overrides = array() ): array {
		return array_merge(
			array(
				'_wpnonce'    => \dpv_test_nonce( self::DELETE ),
				'action'      => self::DELETE,
				'dpv_rule_id' => $id,
			),
			$overrides
		);
	}

	public function test_delete_removes_the_rule_and_reports_it_deleted(): void {
		$id = $this->seed_rule();

		$end = $this->run_handler( 'handle_delete', $this->delete_fields( $id ) );

		$this->assertInstanceOf( \Dpv_Test_Redirect::class, $end );
		$this->assertStringEndsWith( '&dpv_msg=deleted', $end->location );
		$this->assertSame( array(), Bulk_Rules::all() );
	}

	public function test_delete_without_a_nonce_is_refused_and_keeps_the_rule(): void {
		$id     = $this->seed_rule();
		$fields = $this->delete_fields( $id );
		unset( $fields['_wpnonce'] );

		$end = $this->run_handler( 'handle_delete', $fields );

		$this->assertInstanceOf( \Dpv_Test_Die::class, $end );
		$this->assertSame( 403, $end->status );
		$this->assertCount( 1, Bulk_Rules::all() );
	}

	public function test_delete_with_the_add_nonce_is_refused(): void {
		$id = $this->seed_rule();

		$end = $this->run_handler( 'handle_delete', $this->delete_fields( $id, array( '_wpnonce' => \dpv_test_nonce( self::ADD ) ) ) );

		$this->assertInstanceOf( \Dpv_Test_Die::class, $end );
		$this->assertCount( 1, Bulk_Rules::all() );
	}

	public function test_delete_is_refused_for_a_user_who_cannot_manage_the_shop(): void {
		$id                      = $this->seed_rule();
		$GLOBALS['dpv_test_can'] = static function ( $cap ) {
			return 'read' === $cap;
		};

		$end = $this->run_handler( 'handle_delete', $this->delete_fields( $id ) );

		$this->assertInstanceOf( \Dpv_Test_Die::class, $end );
		$this->assertSame( 'Permission denied.', $end->getMessage() );
		$this->assertCount( 1, Bulk_Rules::all() );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function bad_rule_ids(): array {
		return array(
			'unknown id'     => array( 'rdoesnotexist' ),
			'empty id'       => array( '' ),
			'id as an array' => array( array( 'x' ) ),
			'missing id'     => array( null ),
		);
	}

	/**
	 * @dataProvider bad_rule_ids
	 * @param mixed $id Submitted rule id.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'bad_rule_ids' )]
	public function test_delete_reports_a_rule_it_could_not_delete( $id ): void {
		$this->seed_rule();
		$fields = $this->delete_fields( 'x' );
		if ( null === $id ) {
			unset( $fields['dpv_rule_id'] );
		} else {
			$fields['dpv_rule_id'] = $id;
		}

		$end = $this->run_handler( 'handle_delete', $fields );

		$this->assertInstanceOf( \Dpv_Test_Redirect::class, $end );
		$this->assertStringEndsWith( '&dpv_error=delete', $end->location );
		$this->assertCount( 1, Bulk_Rules::all() );
	}
}
