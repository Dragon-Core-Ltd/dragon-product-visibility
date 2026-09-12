<?php
/**
 * Schema install: the db version is only stamped once the table is confirmed
 * present, so a failed creation is retried on the next load.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use DragonProductVisibility\Install;
use PHPUnit\Framework\TestCase;

final class InstallTest extends TestCase {

	private Fake_Wpdb $wpdb;

	protected function setUp(): void {
		$this->wpdb = \dpv_test_reset();
	}

	public function test_create_tables_stamps_version_when_table_exists_afterwards(): void {
		$this->assertTrue( Install::create_tables() );
		$this->assertSame( DRAGONPRODUCTVISIBILITY_VERSION, get_option( 'dragonproductvisibility_db_version' ) );
	}

	public function test_schema_handed_to_dbdelta_is_in_the_form_dbdelta_can_diff(): void {
		Install::create_tables();
		$sql = $GLOBALS['dpv_test_dbdelta_sql'];

		$this->assertStringContainsString( 'CREATE TABLE wp_dpv_customer_visibility (', $sql );
		$this->assertStringNotContainsStringIgnoringCase( 'IF NOT EXISTS', $sql, 'dbDelta reads the word after CREATE TABLE as the table name' );
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql, 'dbDelta expects two spaces before the primary key column list' );
	}

	public function test_create_tables_does_not_stamp_version_when_table_is_still_missing(): void {
		$GLOBALS['dpv_test_dbdelta_creates'] = false;

		$this->assertFalse( Install::create_tables() );
		$this->assertFalse( get_option( 'dragonproductvisibility_db_version' ) );
	}

	public function test_create_tables_returns_false_when_version_write_fails(): void {
		$GLOBALS['dpv_test_option_write_fail'] = true;

		$this->assertFalse( Install::create_tables() );
		$this->assertContains( 'wp_dpv_customer_visibility', $this->wpdb->tables );
	}

	public function test_maybe_upgrade_recreates_a_dropped_table_when_version_is_current(): void {
		Install::create_tables();
		$this->wpdb->tables = array();

		Install::maybe_upgrade();

		$this->assertSame( 2, $GLOBALS['dpv_test_dbdelta_calls'] );
		$this->assertContains( 'wp_dpv_customer_visibility', $this->wpdb->tables );
	}

	public function test_maybe_upgrade_checks_the_table_at_most_once_per_window(): void {
		Install::create_tables();
		$before = $this->wpdb->show_tables_calls;

		Install::maybe_upgrade();
		Install::maybe_upgrade();
		Install::maybe_upgrade();

		$this->assertSame( $before + 1, $this->wpdb->show_tables_calls, 'SHOW TABLES must not run on every admin load' );
		$this->assertSame( 1, $GLOBALS['dpv_test_dbdelta_calls'] );
	}

	public function test_maybe_upgrade_retries_creation_while_version_is_stale(): void {
		$GLOBALS['dpv_test_dbdelta_creates'] = false;
		Install::maybe_upgrade();
		$this->assertSame( 1, $GLOBALS['dpv_test_dbdelta_calls'] );

		$GLOBALS['dpv_test_dbdelta_creates'] = true;
		Install::maybe_upgrade();
		$this->assertSame( 2, $GLOBALS['dpv_test_dbdelta_calls'] );
		$this->assertSame( DRAGONPRODUCTVISIBILITY_VERSION, get_option( 'dragonproductvisibility_db_version' ) );

		Install::maybe_upgrade();
		$this->assertSame( 2, $GLOBALS['dpv_test_dbdelta_calls'], 'a current db version must not re-run dbDelta' );
	}
}
