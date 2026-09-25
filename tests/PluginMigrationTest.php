<?php
/**
 * The legacy dpv_ option migration runs once, not on every request.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use DragonProductVisibility\Plugin;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-plugin.php';

final class PluginMigrationTest extends TestCase {

	protected function setUp(): void {
		\dpv_test_reset();
	}

	private function migrate(): void {
		$method = new \ReflectionMethod( Plugin::class, 'migrate_legacy_prefix' );
		$method->setAccessible( true );
		$method->invoke( null );
	}

	public function test_legacy_options_are_carried_across_and_the_run_is_recorded(): void {
		$GLOBALS['dpv_test_options']['dpv_restriction_mode'] = 'whitelist';

		$this->migrate();

		$this->assertSame( 'whitelist', get_option( 'dragonproductvisibility_restriction_mode' ) );
		$this->assertFalse( get_option( 'dpv_restriction_mode' ) );
		$this->assertNotEmpty( get_option( 'dragonproductvisibility_legacy_prefix_migrated' ) );
	}

	public function test_later_requests_skip_the_legacy_lookups(): void {
		$this->migrate();
		$GLOBALS['dpv_test_options']['dpv_version'] = '1.0.0';

		$this->migrate();

		$this->assertSame( '1.0.0', get_option( 'dpv_version' ), 'the second run must not touch the legacy options' );
		$this->assertFalse( get_option( 'dragonproductvisibility_version' ) );
	}
}
