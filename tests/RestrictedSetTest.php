<?php
/**
 * The restricted set loads meta only for products that carry their own rule,
 * and on a mixed catalogue still holds exactly the products the per-product
 * decision denies.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

use DragonProductVisibility\Bulk_Rules;
use DragonProductVisibility\Visibility_Filter;
use PHPUnit\Framework\TestCase;

final class RestrictedSetTest extends TestCase {

	private const PARENT_CAT = 10;
	private const CHILD_CAT  = 11;
	private const OTHER_CAT  = 12;
	private const TAG        = 20;

	protected function setUp(): void {
		\dpv_test_reset();
		$GLOBALS['dpv_test_user_roles'] = array( 'customer' );
		$GLOBALS['dpv_test_can']        = static function ( $cap ) {
			return 'manage_woocommerce' !== $cap;
		};

		// Products 1-40, each in one or more terms, some with their own rule.
		for ( $id = 1; $id <= 40; $id++ ) {
			$GLOBALS['dpv_test_posts'][ $id ] = (object) array(
				'ID'          => $id,
				'post_type'   => 'product',
				'post_parent' => 0,
				'post_name'   => 'p' . $id,
			);
		}
		$GLOBALS['dpv_test_term_parents']['product_cat'] = array( self::CHILD_CAT => self::PARENT_CAT );
		$GLOBALS['dpv_test_term_products']               = array(
			'product_cat' => array(
				self::PARENT_CAT => range( 1, 10 ),
				self::CHILD_CAT  => range( 11, 20 ),
				self::OTHER_CAT  => range( 21, 30 ),
			),
			'product_tag' => array(
				self::TAG => array( 5, 15, 25, 35 ),
			),
		);

		// Hidden from customers: the parent category (and so its child); shown
		// only to wholesale: the tag.
		$GLOBALS['dpv_test_options'][ Bulk_Rules::OPTION ] = array(
			array(
				'taxonomy' => 'product_cat',
				'term_id'  => self::PARENT_CAT,
				'mode'     => 'blacklist',
				'roles'    => array( 'customer' ),
			),
			array(
				'taxonomy' => 'product_tag',
				'term_id'  => self::TAG,
				'mode'     => 'whitelist',
				'roles'    => array( 'wholesale' ),
			),
		);

		$this->rule( 3, 'whitelist', array( 'customer' ) );   // In a hidden category, allowed by its own rule.
		$this->rule( 12, 'blacklist', array( 'wholesale' ) ); // In a hidden child category, not blocked by its own rule.
		$this->rule( 15, 'blacklist', array( 'customer' ) );  // Hidden by both.
		$this->rule( 22, 'whitelist', array( 'wholesale' ) ); // Visible category, hidden by its own rule.
		$this->rule( 23, 'blacklist', array( 'wholesale' ) ); // Visible category, visible by its own rule.
		$this->rule( 24, 'WHITELIST', array( 'wholesale' ) ); // A mode in other case: not a rule, so the bulk rules decide.
		$this->rule( 25, 'Whitelist', array() );              // Same, and the tag rule hides it.
		$this->rule( 36, 'whitelist', array() );              // Not in any term, hidden by its own rule.
		$GLOBALS['wpdb']->rows['wp_dpv_customer_visibility'] = array(
			array( 'product_id' => 22, 'customer_id' => 1 ), // The visitor is listed on 22.
		);
	}

	private function rule( int $id, string $mode, array $roles ): void {
		$GLOBALS['dpv_test_meta'][ $id ]['_dpv_restriction_mode'] = $mode;
		$GLOBALS['dpv_test_meta'][ $id ]['_dpv_visible_roles']    = $roles;
	}

	private function filter(): Visibility_Filter {
		return ( new \ReflectionClass( Visibility_Filter::class ) )->newInstanceWithoutConstructor();
	}

	public function test_the_restricted_set_matches_the_per_product_decision_on_a_mixed_catalogue(): void {
		// The SQL behind the rule lookup compares case-insensitively.
		$GLOBALS['wpdb']->case_insensitive_mode_lookup = true;
		$restricted = $this->filter()->get_restricted_product_ids();

		$oracle = $this->filter();
		foreach ( array_keys( $GLOBALS['dpv_test_posts'] ) as $id ) {
			$this->assertSame( ! $oracle->user_can_view_product( $id ), in_array( $id, $restricted, true ), "product $id" );
		}

		sort( $restricted );
		$this->assertSame( array_merge( array( 1, 2 ), range( 4, 11 ), range( 13, 20 ), array( 25, 35, 36 ) ), $restricted );
	}

	public function test_meta_is_loaded_only_for_products_with_their_own_rule(): void {
		$GLOBALS['wpdb']->case_insensitive_mode_lookup = true;
		$this->filter()->get_restricted_product_ids();

		$primed = array();
		foreach ( $GLOBALS['dpv_test_meta_primed'] as $call ) {
			$this->assertSame( 'post', $call[0] );
			$primed = array_merge( $primed, $call[1] );
		}
		sort( $primed );

		$this->assertSame( array( 3, 12, 15, 22, 23, 24, 25, 36 ), $primed, 'products hidden by a category or tag rule alone must not have their meta loaded' );
	}

	public function test_a_visitor_with_no_denying_rule_and_no_product_rules_loads_nothing(): void {
		$GLOBALS['dpv_test_meta']                          = array();
		$GLOBALS['dpv_test_options'][ Bulk_Rules::OPTION ] = array();

		$this->assertSame( array(), $this->filter()->get_restricted_product_ids() );
		$this->assertSame( array(), $GLOBALS['dpv_test_meta_primed'] );
	}

	public function test_bulk_hidden_products_stay_hidden_without_any_product_rules(): void {
		$GLOBALS['dpv_test_meta'] = array();

		$restricted = $this->filter()->get_restricted_product_ids();
		sort( $restricted );

		$this->assertSame( array_merge( range( 1, 20 ), array( 25, 35 ) ), $restricted );
		$this->assertSame( array(), $GLOBALS['dpv_test_meta_primed'] );
	}
}
