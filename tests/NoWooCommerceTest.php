<?php
/**
 * Without WooCommerce loaded the plugin does nothing: no fatal, no notice.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use PHPUnit\Framework\TestCase;

final class NoWooCommerceTest extends TestCase {

	public function test_request_paths_run_cleanly_when_woocommerce_is_not_loaded(): void {
		// A separate process: this suite's bootstrap defines WooCommerce's
		// functions, and PHP cannot undefine them.
		$command = 'DPV_TEST_WITHOUT_WOOCOMMERCE=1 ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/no-woocommerce-probe.php' ) . ' 2>&1';

		$output = array();
		$status = 0;
		exec( $command, $output, $status );

		$this->assertSame( 'OK', implode( "\n", $output ) );
		$this->assertSame( 0, $status );
	}
}
