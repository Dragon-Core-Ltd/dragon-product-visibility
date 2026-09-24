<?php
/**
 * Per-product rule saving: mode + roles meta and the customer rows are written
 * as one unit, and any failed write leaves nothing behind and reports false.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use DragonProductVisibility\Customer_Visibility;
use PHPUnit\Framework\TestCase;

final class CustomerVisibilityTest extends TestCase {

	private Fake_Wpdb $wpdb;

	protected function setUp(): void {
		$this->wpdb           = \dpv_test_reset();
		$this->wpdb->tables[] = 'wp_dpv_customer_visibility';
	}

	public function test_successful_save_writes_meta_and_rows_inside_one_transaction(): void {
		$this->assertTrue( Customer_Visibility::save_rules( 10, 'whitelist', array( 'customer' ), array( 5, 7 ) )['saved'] );

		$this->assertSame( 'whitelist', get_post_meta( 10, '_dpv_restriction_mode', true ) );
		$this->assertSame( array( 'customer' ), get_post_meta( 10, '_dpv_visible_roles', true ) );
		$this->assertSame(
			array(
				array( 'product_id' => 10, 'customer_id' => 5 ),
				array( 'product_id' => 10, 'customer_id' => 7 ),
			),
			$this->wpdb->rows_in( 'wp_dpv_customer_visibility' )
		);
		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $this->wpdb->queries );
	}

	public function test_refuses_to_write_when_the_transaction_does_not_start(): void {
		$this->wpdb->fail_query_containing = array( 'START TRANSACTION' );

		$this->assertFalse( Customer_Visibility::save_rules( 10, 'whitelist', array(), array( 5 ) )['saved'] );

		$this->assertSame( array( 'START TRANSACTION' ), $this->wpdb->queries, 'nothing may be rolled back or committed when no transaction started' );
		$this->assertSame( 0, $this->wpdb->insert_calls );
		$this->assertSame( array(), $this->wpdb->deletes );
		$this->assertSame( '', get_post_meta( 10, '_dpv_restriction_mode', true ) );
	}

	public function test_only_removed_ids_are_deleted_and_only_added_ids_inserted(): void {
		$this->wpdb->rows['wp_dpv_customer_visibility'] = array(
			array( 'product_id' => 10, 'customer_id' => 3 ),
			array( 'product_id' => 10, 'customer_id' => 5 ),
			array( 'product_id' => 11, 'customer_id' => 3 ),
		);

		$this->assertTrue( Customer_Visibility::save_rules( 10, 'whitelist', array(), array( 5, 7 ) )['saved'] );

		$this->assertSame(
			array( array( 'table' => 'wp_dpv_customer_visibility', 'where' => array( 'product_id' => 10, 'customer_id' => 3 ) ) ),
			$this->wpdb->deletes,
			'only the deselected customer may be deleted, and only for this product'
		);
		$this->assertSame( 1, $this->wpdb->insert_calls, 'a customer that is already stored must not be re-inserted' );
		$this->assertSame(
			array(
				array( 'product_id' => 10, 'customer_id' => 5 ),
				array( 'product_id' => 11, 'customer_id' => 3 ),
				array( 'product_id' => 10, 'customer_id' => 7 ),
			),
			$this->wpdb->rows_in( 'wp_dpv_customer_visibility' )
		);
	}

	public function test_failed_insert_without_transaction_support_keeps_existing_customers(): void {
		$this->wpdb->use_non_transactional_storage();
		$this->wpdb->rows['wp_dpv_customer_visibility'] = array(
			array( 'product_id' => 10, 'customer_id' => 3 ),
		);
		$this->wpdb->fail_insert_at = 1;

		$this->assertFalse( Customer_Visibility::save_rules( 10, 'whitelist', array(), array( 3, 5 ) )['saved'] );

		$this->assertContains( array( 'product_id' => 10, 'customer_id' => 3 ), $this->wpdb->rows_in( 'wp_dpv_customer_visibility' ), 'existing rows survive a failed save even when ROLLBACK is a no-op' );
	}

	public function test_meta_is_verified_without_the_object_cache_and_cache_is_purged_after_commit(): void {
		$this->assertTrue( Customer_Visibility::save_rules( 10, 'whitelist', array( 'customer' ), array( 5 ) )['saved'] );

		$this->assertSame( 0, $GLOBALS['dpv_test_get_post_meta'], 'verification must read postmeta directly, not through get_post_meta()' );
		$this->assertContains( 'post_meta:10', $GLOBALS['dpv_test_cache_deletes'], 'the post_meta cache is purged after COMMIT' );
	}

	public function test_failed_insert_rolls_back_and_keeps_previous_customers(): void {
		$this->wpdb->rows['wp_dpv_customer_visibility'] = array(
			array( 'product_id' => 10, 'customer_id' => 3 ),
		);
		$this->wpdb->fail_insert_at = 2;

		$this->assertFalse( Customer_Visibility::save_rules( 10, 'whitelist', array(), array( 5, 7, 9 ) )['saved'] );

		$this->assertContains( 'ROLLBACK', $this->wpdb->queries );
		$this->assertNotContains( 'COMMIT', $this->wpdb->queries );
		$this->assertSame(
			array( array( 'product_id' => 10, 'customer_id' => 3 ) ),
			$this->wpdb->rows_in( 'wp_dpv_customer_visibility' ),
			'the product must keep its previous restrictions when the save fails'
		);
		$this->assertContains( 'post_meta:10', $GLOBALS['dpv_test_cache_deletes'], 'rolled-back meta must not linger in the object cache' );
	}

	public function test_failed_delete_rolls_back_and_returns_false(): void {
		$this->wpdb->rows['wp_dpv_customer_visibility'] = array(
			array( 'product_id' => 10, 'customer_id' => 3 ),
		);
		$this->wpdb->fail_delete = true;

		$this->assertFalse( Customer_Visibility::save_rules( 10, 'blacklist', array(), array( 5 ) )['saved'] );

		$this->assertContains( 'ROLLBACK', $this->wpdb->queries );
		$this->assertSame( 0, $this->wpdb->insert_calls, 'no insert may follow a failed delete' );
		$this->assertSame( array( array( 'product_id' => 10, 'customer_id' => 3 ) ), $this->wpdb->rows_in( 'wp_dpv_customer_visibility' ) );
	}

	public function test_failed_meta_write_rolls_back_and_returns_false(): void {
		$GLOBALS['dpv_test_meta_write_fail'] = array( '_dpv_visible_roles' );

		$this->assertFalse( Customer_Visibility::save_rules( 10, 'whitelist', array( 'customer' ), array( 5 ) )['saved'] );

		$this->assertContains( 'ROLLBACK', $this->wpdb->queries );
		$this->assertSame( array(), $this->wpdb->rows_in( 'wp_dpv_customer_visibility' ) );
	}

	public function test_unchanged_meta_is_not_mistaken_for_a_failure(): void {
		update_post_meta( 10, '_dpv_restriction_mode', 'whitelist' );
		update_post_meta( 10, '_dpv_visible_roles', array( 'customer' ) );

		$this->assertTrue( Customer_Visibility::save_rules( 10, 'whitelist', array( 'customer' ), array( 5 ) )['saved'] );
		$this->assertContains( 'COMMIT', $this->wpdb->queries );
	}

	public function test_failed_commit_rolls_back_and_returns_false(): void {
		$this->wpdb->fail_query_containing = array( 'COMMIT' );

		$this->assertFalse( Customer_Visibility::save_rules( 10, 'whitelist', array(), array( 5 ) )['saved'] );
		$this->assertContains( 'ROLLBACK', $this->wpdb->queries );
	}

	public function test_customer_ids_are_deduplicated_and_zero_dropped(): void {
		$this->assertTrue( Customer_Visibility::save_rules( 10, 'whitelist', array(), array( 5, 0, 5, 7 ) )['saved'] );

		$this->assertSame(
			array(
				array( 'product_id' => 10, 'customer_id' => 5 ),
				array( 'product_id' => 10, 'customer_id' => 7 ),
			),
			$this->wpdb->rows_in( 'wp_dpv_customer_visibility' )
		);
	}

	public function test_missing_table_is_created_before_saving(): void {
		$this->wpdb->tables = array();

		$this->assertTrue( Customer_Visibility::save_rules( 10, 'whitelist', array(), array( 5 ) )['saved'] );
		$this->assertSame( 1, $GLOBALS['dpv_test_dbdelta_calls'] );
		$this->assertCount( 1, $this->wpdb->rows_in( 'wp_dpv_customer_visibility' ) );
	}

	public function test_save_fails_cleanly_when_table_cannot_be_created(): void {
		$this->wpdb->tables                  = array();
		$GLOBALS['dpv_test_dbdelta_creates'] = false;

		$this->assertFalse( Customer_Visibility::save_rules( 10, 'whitelist', array( 'customer' ), array( 5 ) )['saved'] );
		$this->assertSame( '', get_post_meta( 10, '_dpv_restriction_mode', true ), 'no meta may be written when the customer table is unavailable' );
		$this->assertSame( array(), $this->wpdb->queries );
	}

	public function test_a_failed_customer_read_does_not_commit_as_a_successful_save(): void {
		// A failing SELECT returns an empty array, exactly like a product with no
		// customer rules, so without checking the error the plan finds nothing to
		// remove and the save commits while the stored rows are untouched.
		$this->wpdb->rows['wp_dpv_customer_visibility'] = array(
			array( 'product_id' => 10, 'customer_id' => 3 ),
		);
		$this->wpdb->fail_get_col = true;

		$result = Customer_Visibility::save_rules( 10, 'whitelist', array(), array() );

		$this->assertFalse( $result['saved'] );
		$this->assertTrue( $result['restored'], 'nothing can have changed when the save never wrote anything' );
		$this->assertSame( array(), $this->wpdb->queries, 'a save that cannot read the current rules must not open a transaction' );
		$this->assertSame( array( array( 'product_id' => 10, 'customer_id' => 3 ) ), $this->wpdb->rows_in( 'wp_dpv_customer_visibility' ) );
	}

	public function test_blacklist_insert_failure_on_non_transactional_storage_keeps_the_blocked_customer(): void {
		$this->wpdb->use_non_transactional_storage();
		update_post_meta( 10, '_dpv_restriction_mode', 'blacklist' );
		$this->wpdb->rows['wp_dpv_customer_visibility'] = array(
			array( 'product_id' => 10, 'customer_id' => 3 ),
		);
		$this->wpdb->fail_insert_at = 1;

		$result = Customer_Visibility::save_rules( 10, 'blacklist', array(), array( 5 ) );

		$this->assertFalse( $result['saved'] );
		$this->assertSame(
			array( 3 ),
			$this->stored_customers(),
			'a blacklisted customer may not lose the row that blocks them because a later insert failed'
		);
		$this->assertSame( array(), $this->wpdb->deletes, 'under a blacklist the row that blocks someone is added before any row is removed' );
		$this->assertTrue( $result['restored'] );
		$this->assertStringContainsString( 'keeps its previous rules', $result['message'] );
	}

	public function test_whitelist_insert_failure_on_non_transactional_storage_does_not_grant_a_new_customer(): void {
		$this->wpdb->use_non_transactional_storage();
		update_post_meta( 10, '_dpv_restriction_mode', 'whitelist' );
		$this->wpdb->rows['wp_dpv_customer_visibility'] = array(
			array( 'product_id' => 10, 'customer_id' => 3 ),
		);
		$this->wpdb->fail_insert_at = 2;

		$result = Customer_Visibility::save_rules( 10, 'whitelist', array(), array( 3, 5, 7 ) );

		$this->assertFalse( $result['saved'] );
		$this->assertSame(
			array( 3 ),
			$this->stored_customers(),
			'a half-applied whitelist may not leave a customer able to see the product when the save reported failure'
		);
		$this->assertTrue( $result['restored'] );
		$this->assertStringContainsString( 'keeps its previous rules', $result['message'] );
	}

	public function test_blacklist_delete_failure_on_non_transactional_storage_restores_the_blocked_customer(): void {
		$this->wpdb->use_non_transactional_storage();
		update_post_meta( 10, '_dpv_restriction_mode', 'blacklist' );
		$this->wpdb->rows['wp_dpv_customer_visibility'] = array(
			array( 'product_id' => 10, 'customer_id' => 3 ),
			array( 'product_id' => 10, 'customer_id' => 5 ),
			array( 'product_id' => 10, 'customer_id' => 7 ),
		);
		$this->wpdb->fail_delete_at = 2;

		$result = Customer_Visibility::save_rules( 10, 'blacklist', array(), array( 7 ) );

		$this->assertFalse( $result['saved'] );
		$this->assertSame(
			array( 3, 5, 7 ),
			$this->stored_customers(),
			'a failed delete must not leave one blacklisted customer unblocked'
		);
		$this->assertTrue( $result['restored'] );
		$this->assertStringContainsString( 'keeps its previous rules', $result['message'] );
	}

	public function test_a_failure_whose_compensation_also_fails_reports_the_rules_that_are_stored(): void {
		$this->wpdb->use_non_transactional_storage();
		update_post_meta( 10, '_dpv_restriction_mode', 'blacklist' );
		$this->wpdb->rows['wp_dpv_customer_visibility'] = array(
			array( 'product_id' => 10, 'customer_id' => 3 ),
			array( 'product_id' => 10, 'customer_id' => 5 ),
			array( 'product_id' => 10, 'customer_id' => 7 ),
		);
		$this->wpdb->fail_delete_at = 2;
		// The only insert in this save is the one putting the first deletion back.
		$this->wpdb->fail_insert_at = 1;

		$result = Customer_Visibility::save_rules( 10, 'blacklist', array(), array( 7 ) );

		$this->assertFalse( $result['saved'] );
		$this->assertFalse( $result['restored'], 'customer 3 is no longer blocked, so the previous rules did not survive' );
		$this->assertSame( array( 5, 7 ), $this->stored_customers() );
		$this->assertSame( array( 5, 7 ), $result['stored']['customers'], 'the outcome must carry the rules that were read back' );
		$this->assertStringNotContainsString( 'keeps its previous rules', $result['message'], 'the admin must not be told the previous rules survived when they did not' );
		$this->assertStringContainsString( 'blacklist', $result['message'] );
		$this->assertStringContainsString( '2 listed customers', $result['message'] );
		$this->assertStringContainsString( '0 listed roles', $result['message'] );
	}

	public function test_the_previous_roles_are_put_back_when_the_mode_write_fails(): void {
		$this->wpdb->use_non_transactional_storage();
		update_post_meta( 10, '_dpv_restriction_mode', 'whitelist' );
		update_post_meta( 10, '_dpv_visible_roles', array( 'customer' ) );
		$GLOBALS['dpv_test_meta_write_fail'] = array( '_dpv_restriction_mode' );

		$result = Customer_Visibility::save_rules( 10, 'blacklist', array(), array() );

		$this->assertFalse( $result['saved'] );
		$this->assertTrue( $result['restored'] );
		$this->assertSame( 'whitelist', get_post_meta( 10, '_dpv_restriction_mode', true ), 'the mode may not be left flipped when the rest of the save did not land' );
		$this->assertSame( array( 'customer' ), get_post_meta( 10, '_dpv_visible_roles', true ), 'the role list must be put back with it' );
	}

	public function test_storage_that_cannot_roll_back_is_not_wrapped_in_a_transaction(): void {
		$this->wpdb->use_non_transactional_storage();

		$this->assertTrue( Customer_Visibility::save_rules( 10, 'whitelist', array(), array( 5 ) )['saved'] );
		$this->assertSame( array(), $this->wpdb->queries, 'a MyISAM table must not be handed a transaction that cannot roll it back' );
		$this->assertSame( array( 5 ), $this->stored_customers() );
	}

	public function test_a_transaction_is_not_trusted_when_postmeta_cannot_roll_back(): void {
		// The custom table is InnoDB here; postmeta is not, so the save still
		// spans a table a ROLLBACK would not revert.
		$this->wpdb->engines = array( 'wp_postmeta' => 'MyISAM' );

		$this->assertTrue( Customer_Visibility::save_rules( 10, 'whitelist', array(), array( 5 ) )['saved'] );
		$this->assertSame( array(), $this->wpdb->queries );
	}

	public function test_an_unreadable_storage_engine_counts_as_non_transactional(): void {
		// Restricted grants hide information_schema on some hosts.
		$this->wpdb->default_engine = null;
		update_post_meta( 10, '_dpv_restriction_mode', 'blacklist' );
		$this->wpdb->rows['wp_dpv_customer_visibility'] = array(
			array( 'product_id' => 10, 'customer_id' => 3 ),
		);
		$this->wpdb->fail_insert_at = 1;

		$result = Customer_Visibility::save_rules( 10, 'blacklist', array(), array( 5 ) );

		$this->assertFalse( $result['saved'] );
		$this->assertSame( array(), $this->wpdb->queries, 'an engine that cannot be read must not be assumed to roll back' );
		$this->assertSame( array( 3 ), $this->stored_customers() );
	}

	/** Customer IDs stored for product 10, sorted. */
	private function stored_customers(): array {
		$ids = array();
		foreach ( $this->wpdb->rows_in( 'wp_dpv_customer_visibility' ) as $row ) {
			if ( 10 === (int) $row['product_id'] ) {
				$ids[] = (int) $row['customer_id'];
			}
		}
		sort( $ids );
		return $ids;
	}
}
