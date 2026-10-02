<?php
/**
 * Uninstall removes data only when the stored opt-in is a clear yes.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use PHPUnit\Framework\TestCase;

defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'dragon-product-visibility/dragon-product-visibility.php' );

final class UninstallOptInTest extends TestCase {

	private Fake_Wpdb $wpdb;

	protected function setUp(): void {
		$this->wpdb = \dpv_test_reset();
	}

	public static function opt_in_values(): array {
		return array(
			'true'          => array( true, true ),
			'int 1'         => array( 1, true ),
			'string 1'      => array( '1', true ),
			'string true'   => array( 'true', true ),
			'upper TRUE'    => array( 'TRUE', true ),
			'padded yes'    => array( ' yes ', true ),
			'on'            => array( 'on', true ),
			'false'         => array( false, false ),
			'int 0'         => array( 0, false ),
			'empty string'  => array( '', false ),
			'string 0'      => array( '0', false ),
			'string false'  => array( 'false', false ),
			'no'            => array( 'no', false ),
			'off'           => array( 'off', false ),
			'capital No'    => array( 'No', false ),
			'random string' => array( 'random', false ),
			'null'          => array( null, false ),
			'empty array'   => array( array(), false ),
		);
	}

	/**
	 * @dataProvider opt_in_values
	 *
	 * @param mixed $stored  Stored option value.
	 * @param bool  $deletes Whether uninstall removes the data.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'opt_in_values' )]
	public function test_only_a_clear_yes_deletes_the_data( $stored, bool $deletes ): void {
		$GLOBALS['dpv_test_options'] = array( 'dpv_delete_data_on_uninstall' => $stored );

		include __DIR__ . '/../uninstall.php';

		if ( $deletes ) {
			$sql = implode( "\n", $this->wpdb->queries );
			$this->assertStringContainsString( 'DROP TABLE IF EXISTS `wp_dpv_customer_visibility`', $sql );
			$this->assertStringContainsString( "DELETE FROM wp_options WHERE option_name LIKE 'dragonproductvisibility", $sql );
			$this->assertStringContainsString( 'DELETE FROM wp_postmeta', $sql );
			$this->assertStringContainsString( 'DELETE FROM wp_usermeta', $sql );
		} else {
			$this->assertSame( array(), $this->wpdb->queries );
		}
	}

	public function test_a_missing_opt_in_keeps_the_data(): void {
		include __DIR__ . '/../uninstall.php';

		$this->assertSame( array(), $this->wpdb->queries );
	}
}
