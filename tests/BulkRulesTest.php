<?php
/**
 * Category/tag rule storage reports whether the option write actually landed.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use DragonProductVisibility\Bulk_Rules;
use PHPUnit\Framework\TestCase;

final class BulkRulesTest extends TestCase {

	protected function setUp(): void {
		\dpv_test_reset();
	}

	private function rule( int $term_id ): array {
		return array(
			'taxonomy' => 'product_cat',
			'term_id'  => $term_id,
			'mode'     => 'whitelist',
			'roles'    => array( 'customer' ),
		);
	}

	public function test_save_returns_true_and_persists(): void {
		$this->assertTrue( Bulk_Rules::save( array( $this->rule( 3 ) ) ) );
		$this->assertCount( 1, Bulk_Rules::all() );
	}

	public function test_save_returns_true_when_rules_are_unchanged(): void {
		Bulk_Rules::save( array( $this->rule( 3 ) ) );
		$this->assertTrue( Bulk_Rules::save( array( $this->rule( 3 ) ) ) );
	}

	public function test_save_returns_false_when_option_write_fails(): void {
		Bulk_Rules::save( array( $this->rule( 3 ) ) );
		$GLOBALS['dpv_test_option_write_fail'] = true;

		$this->assertFalse( Bulk_Rules::save( array( $this->rule( 3 ), $this->rule( 4 ) ) ) );
		$this->assertCount( 1, Bulk_Rules::all(), 'a failed write leaves the previous rules in place' );
	}

	public function test_delete_returns_false_when_the_removal_could_not_be_saved(): void {
		Bulk_Rules::save( array( $this->rule( 3 ) ) );
		$id = Bulk_Rules::all()[0]['id'];
		$GLOBALS['dpv_test_option_write_fail'] = true;

		$this->assertFalse( Bulk_Rules::delete( $id ) );
		$this->assertCount( 1, Bulk_Rules::all() );
	}

	public function test_delete_returns_false_for_unknown_id(): void {
		$this->assertFalse( Bulk_Rules::delete( 'rdoesnotexist' ) );
	}
}
