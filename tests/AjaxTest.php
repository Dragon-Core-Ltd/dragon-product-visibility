<?php
/**
 * The AJAX save reports failure instead of "saved" when the write does not land.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use DragonProductVisibility\Ajax;
use PHPUnit\Framework\TestCase;

final class AjaxTest extends TestCase {

	private Fake_Wpdb $wpdb;

	protected function setUp(): void {
		$this->wpdb           = \dpv_test_reset();
		$this->wpdb->tables[] = 'wp_dpv_customer_visibility';
		$GLOBALS['dpv_test_posts'][10] = (object) array( 'ID' => 10, 'post_type' => 'product' );
		$GLOBALS['dpv_test_posts'][20] = (object) array( 'ID' => 20, 'post_type' => 'post' );
		$_POST                = array(
			'nonce'            => 'valid',
			'product_id'       => '10',
			'restriction_mode' => 'whitelist',
			'customer_ids'     => array( '5', '7' ),
			'role_ids'         => array( 'customer' ),
		);
	}

	private function run_save(): \Dpv_Test_Json_Sent {
		try {
			Ajax::instance()->save_visibility_rules();
		} catch ( \Dpv_Test_Json_Sent $sent ) {
			return $sent;
		}
		$this->fail( 'handler did not send a JSON response' );
	}

	public function test_success_response_after_rows_written(): void {
		$sent = $this->run_save();

		$this->assertTrue( $sent->success );
		$this->assertCount( 2, $this->wpdb->rows_in( 'wp_dpv_customer_visibility' ) );
	}

	public function test_rejects_a_product_id_that_does_not_exist(): void {
		$_POST['product_id'] = '999';

		$sent = $this->run_save();

		$this->assertFalse( $sent->success );
		$this->assertSame( array(), $this->wpdb->queries );
	}

	public function test_rejects_a_post_that_is_not_a_product(): void {
		$_POST['product_id'] = '20';

		$sent = $this->run_save();

		$this->assertFalse( $sent->success );
		$this->assertSame( array(), $this->wpdb->queries );
	}

	public function test_rejects_a_user_who_cannot_edit_that_product(): void {
		$checked                 = array();
		$GLOBALS['dpv_test_can'] = function ( $cap, $args ) use ( &$checked ) {
			$checked[] = array( $cap, $args );
			return ! ( 'edit_post' === $cap && array( 10 ) === $args );
		};

		$sent = $this->run_save();

		$this->assertFalse( $sent->success, 'a generic products capability must not allow editing any product id' );
		$this->assertContains( array( 'edit_post', array( 10 ) ), $checked );
		$this->assertSame( array(), $this->wpdb->queries );
		$this->assertSame( '', get_post_meta( 10, '_dpv_restriction_mode', true ) );
	}

	public function test_error_response_when_an_insert_fails(): void {
		$this->wpdb->fail_insert_at = 2;

		$sent = $this->run_save();

		$this->assertFalse( $sent->success, 'a failed save must not be reported as saved' );
		$this->assertNotEmpty( $sent->data['message'] );
		$this->assertContains( 'ROLLBACK', $this->wpdb->queries );
		$this->assertTrue( $sent->data['restored'] );
		$this->assertStringContainsString( 'keeps its previous rules', $sent->data['message'], 'the rolled-back save really does keep the previous rules' );
	}

	public function test_error_response_describes_the_stored_rules_when_they_could_not_be_restored(): void {
		$this->wpdb->use_non_transactional_storage();
		update_post_meta( 10, '_dpv_restriction_mode', 'blacklist' );
		$this->wpdb->rows['wp_dpv_customer_visibility'] = array(
			array( 'product_id' => 10, 'customer_id' => 3 ),
			array( 'product_id' => 10, 'customer_id' => 5 ),
			array( 'product_id' => 10, 'customer_id' => 7 ),
		);
		$_POST['restriction_mode'] = 'blacklist';
		$_POST['customer_ids']     = array( '7' );
		$_POST['role_ids']         = array();
		$this->wpdb->fail_delete_at = 2;
		$this->wpdb->fail_insert_at = 1;

		$sent = $this->run_save();

		$this->assertFalse( $sent->success );
		$this->assertFalse( $sent->data['restored'] );
		$this->assertStringNotContainsString( 'keeps its previous rules', $sent->data['message'] );
		$this->assertStringContainsString( '2 listed customer(s)', $sent->data['message'] );
		$this->assertSame( array( 5, 7 ), $sent->data['stored']['customers'] );
	}
}
