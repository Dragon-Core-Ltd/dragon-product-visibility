<?php
/**
 * The per-product rule pickers show only for a mode that uses them, and bulk
 * rules can target every role, not just the ones a shop manager may assign.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use DragonProductVisibility\Bulk_Rules_Admin;
use DragonProductVisibility\Product_Metabox;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/admin/class-bulk-rules-admin.php';

final class RolePickersTest extends TestCase {

	protected function setUp(): void {
		\dpv_test_reset();
		$GLOBALS['dpv_test_roles'] = array(
			'administrator' => array( 'name' => 'Administrator' ),
			'shop_manager'  => array( 'name' => 'Shop manager' ),
			'customer'      => array( 'name' => 'Customer' ),
			'wholesale'     => array( 'name' => 'Wholesale' ),
		);
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function modes(): array {
		return array(
			'never saved' => array( '', false ),
			'none'        => array( 'none', false ),
			'unknown'     => array( 'other', false ),
			'whitelist'   => array( 'whitelist', true ),
			'blacklist'   => array( 'blacklist', true ),
		);
	}

	/**
	 * @dataProvider modes
	 * @param mixed $mode Stored mode.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'modes' )]
	public function test_rule_pickers_show_only_for_a_restricting_mode( $mode, bool $expected ): void {
		$this->assertSame( $expected, Product_Metabox::shows_rule_pickers( $mode ) );
	}

	public function test_bulk_rules_accept_roles_a_shop_manager_cannot_assign(): void {
		// WooCommerce cuts editable_roles to "customer" for shop managers.
		$this->assertSame(
			array( 'customer', 'wholesale' ),
			Bulk_Rules_Admin::allowed_roles( array( 'customer', 'wholesale', 'made_up' ) )
		);
	}
}
