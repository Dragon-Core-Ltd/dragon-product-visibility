<?php
/**
 * The post-save notice only claims the previous rules survived when the save
 * path said they did.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use DragonProductVisibility\Admin;
use DragonProductVisibility\Product_Metabox;
use PHPUnit\Framework\TestCase;

final class AdminNoticeTest extends TestCase {

	protected function setUp(): void {
		\dpv_test_reset();
	}

	private function notice(): string {
		ob_start();
		Admin::instance()->save_error_notice();
		return (string) ob_get_clean();
	}

	public function test_no_notice_without_the_flag(): void {
		$this->assertSame( '', $this->notice() );
	}

	public function test_restored_outcome_says_the_previous_rules_are_kept(): void {
		$_GET[ Product_Metabox::ERROR_FLAG ] = Product_Metabox::ERROR_RESTORED;

		$this->assertStringContainsString( 'keeps its previous rules', $this->notice() );
	}

	public function test_partial_outcome_does_not_claim_the_previous_rules_are_kept(): void {
		$_GET[ Product_Metabox::ERROR_FLAG ] = Product_Metabox::ERROR_PARTIAL;

		$notice = $this->notice();

		$this->assertStringNotContainsString( 'keeps its previous rules', $notice );
		$this->assertStringContainsString( 'could not be fully restored', $notice );
		$this->assertStringContainsString( 'Visibility Restrictions tab', $notice, 'the admin is pointed at the rules that are actually stored' );
	}
}
