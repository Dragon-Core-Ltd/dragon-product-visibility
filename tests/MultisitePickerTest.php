<?php
/**
 * The product screen's customer picker, rendered and saved back unchanged,
 * keeps every stored customer, including accounts of other sites on
 * multisite, whose name and email are not shown.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use DragonProductVisibility\Customer_Visibility;
use DragonProductVisibility\Product_Metabox;
use PHPUnit\Framework\TestCase;

final class MultisitePickerTest extends TestCase {

	private const TABLE = 'wp_dpv_customer_visibility';

	private Fake_Wpdb $wpdb;

	protected function setUp(): void {
		foreach ( array( 'saved_this_request', 'failed_saves' ) as $static_property ) {
			( new \ReflectionProperty( Product_Metabox::class, $static_property ) )->setValue( null, array() );
		}

		$this->wpdb                = \dpv_test_reset();
		$this->wpdb->tables[]      = self::TABLE;
		$GLOBALS['dpv_test_roles'] = array( 'customer' => array( 'name' => 'Customer' ) );
		$GLOBALS['dpv_test_users'] = array(
			5 => array( 'blogs' => array( 1 ) ),
			7 => array( 'blogs' => array( 2 ) ),
			9 => array( 'blogs' => array( 1, 2 ) ),
		);
		$GLOBALS['post']           = (object) array( 'ID' => 10 );

		$this->assertTrue( Customer_Visibility::save_rules( 10, 'blacklist', array( 'customer' ), array( 5, 7, 9 ) )['saved'] );
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	private function render(): string {
		ob_start();
		Product_Metabox::instance()->add_tab_content();
		return (string) ob_get_clean();
	}

	/**
	 * The customer IDs a browser would submit from the rendered tab: the
	 * selected options of the picker and the hidden inputs.
	 *
	 * @return string[]
	 */
	private function submitted_customers( string $html ): array {
		$doc = new \DOMDocument();
		@$doc->loadHTML( '<?xml encoding="UTF-8">' . $html ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$xpath = new \DOMXPath( $doc );

		$values = array();
		foreach ( $xpath->query( '//select[@name="dragonproductvisibility_customers[]"]/option[@selected]' ) as $option ) {
			$values[] = $option->getAttribute( 'value' );
		}
		foreach ( $xpath->query( '//input[@name="dragonproductvisibility_customers[]"]' ) as $input ) {
			$values[] = $input->getAttribute( 'value' );
		}
		return $values;
	}

	private function save_back( string $html ): void {
		$_POST = array(
			'dragonproductvisibility_visibility_nonce' => \dpv_test_nonce( 'dragonproductvisibility_save_visibility' ),
			'dragonproductvisibility_restriction_mode' => 'blacklist',
			'dragonproductvisibility_roles'            => array( 'customer' ),
			'dragonproductvisibility_customers'        => $this->submitted_customers( $html ),
		);
		Product_Metabox::instance()->save_product_data( 10 );
	}

	private function stored(): array {
		$ids = array_map( 'intval', array_column( $this->wpdb->rows_in( self::TABLE ), 'customer_id' ) );
		sort( $ids );
		return $ids;
	}

	public function test_multisite_save_back_keeps_a_customer_from_another_site(): void {
		$GLOBALS['dpv_test_multisite'] = true;

		$html = $this->render();
		$this->save_back( $html );

		$this->assertSame( array( 5, 7, 9 ), $this->stored(), 'saving the screen unchanged must not unblock anyone' );
	}

	public function test_multisite_picker_does_not_show_the_other_sites_account(): void {
		$GLOBALS['dpv_test_multisite'] = true;

		$html = $this->render();

		$this->assertStringContainsString( 'customer5@example.com', $html );
		$this->assertStringContainsString( 'customer9@example.com', $html );
		$this->assertStringNotContainsString( 'customer7@example.com', $html );
		$this->assertStringNotContainsString( 'Customer 7', $html );
		$this->assertSame( array( '5', '9', '7' ), $this->submitted_customers( $html ) );
	}

	public function test_single_site_save_back_is_unchanged(): void {
		$html = $this->render();
		$this->save_back( $html );

		$this->assertSame( array( '5', '7', '9' ), $this->submitted_customers( $html ) );
		$this->assertSame( array( 5, 7, 9 ), $this->stored() );
	}

	public function test_save_back_without_email_access_is_unchanged(): void {
		$GLOBALS['dpv_test_multisite'] = true;
		$GLOBALS['dpv_test_can']       = static function ( $cap ) {
			return ! in_array( $cap, array( 'manage_woocommerce', 'list_users' ), true );
		};

		$html = $this->render();
		$this->save_back( $html );

		$this->assertStringNotContainsString( '@example.com', $html );
		$this->assertSame( array( 5, 7, 9 ), $this->stored() );
	}
}
