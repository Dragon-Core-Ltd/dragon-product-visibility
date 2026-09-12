<?php
/**
 * Bulk Rules - category/tag based visibility rules
 *
 * Per-product rules live in post meta; these apply to every product in a
 * WooCommerce category or tag (and, for categories, its descendants) by role.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage, validation and (pure) evaluation for category/tag visibility rules.
 *
 * A rule is stored as:
 *   [ 'id' => string, 'taxonomy' => 'product_cat'|'product_tag',
 *     'term_id' => int, 'mode' => 'whitelist'|'blacklist', 'roles' => string[] ]
 *
 * Rules are role-based (the common "hide this category from this role" case);
 * customer-specific targeting stays a per-product feature.
 */
class Bulk_Rules {

	/**
	 * Option storing the rule list.
	 */
	const OPTION = 'dragonproductvisibility_bulk_rules';

	/**
	 * Supported taxonomies.
	 *
	 * @var string[]
	 */
	const TAXONOMIES = array( 'product_cat', 'product_tag' );

	/**
	 * Supported modes.
	 *
	 * @var string[]
	 */
	const MODES = array( 'whitelist', 'blacklist' );

	/**
	 * All valid rules.
	 *
	 * @return array<int, array{id:string,taxonomy:string,term_id:int,mode:string,roles:string[]}>
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$rules = array();
		foreach ( $raw as $rule ) {
			$valid = self::sanitize( $rule );
			if ( null !== $valid ) {
				$rules[] = $valid;
			}
		}

		return $rules;
	}

	/**
	 * Persist a set of rules (each validated; invalid entries dropped).
	 * update_option() returns false for an unchanged value as well as a failed
	 * write, so the stored value is read back to confirm.
	 *
	 * @param array $rules Rules to store.
	 * @return bool True when the stored rules match what was requested.
	 */
	public static function save( array $rules ): bool {
		$clean = array();
		foreach ( $rules as $rule ) {
			$valid = self::sanitize( $rule );
			if ( null !== $valid ) {
				$clean[ $valid['id'] ] = $valid; // Keyed by id de-duplicates identical rules.
			}
		}

		$clean = array_values( $clean );
		update_option( self::OPTION, $clean, false );

		return get_option( self::OPTION ) === $clean;
	}

	/**
	 * Delete a rule by its id. Returns true if a rule was removed and the
	 * remaining rules were saved.
	 *
	 * @param string $id Rule id.
	 * @return bool
	 */
	public static function delete( string $id ): bool {
		$rules   = self::all();
		$kept    = array();
		$removed = false;

		foreach ( $rules as $rule ) {
			if ( $rule['id'] === $id ) {
				$removed = true;
				continue;
			}
			$kept[] = $rule;
		}

		return $removed && self::save( $kept );
	}

	/**
	 * Validate and normalise a single rule, or null if invalid.
	 *
	 * @param mixed $rule Raw rule.
	 * @return array{id:string,taxonomy:string,term_id:int,mode:string,roles:string[]}|null
	 */
	public static function sanitize( $rule ): ?array {
		if ( ! is_array( $rule ) ) {
			return null;
		}

		$taxonomy = isset( $rule['taxonomy'] ) ? (string) $rule['taxonomy'] : '';
		$term_id  = isset( $rule['term_id'] ) ? (int) $rule['term_id'] : 0;
		$mode     = isset( $rule['mode'] ) ? (string) $rule['mode'] : '';
		$roles    = ( isset( $rule['roles'] ) && is_array( $rule['roles'] ) )
			? array_values( array_unique( array_map( 'sanitize_key', $rule['roles'] ) ) )
			: array();

		if ( ! in_array( $taxonomy, self::TAXONOMIES, true ) ) {
			return null;
		}
		if ( $term_id <= 0 ) {
			return null;
		}
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return null;
		}

		return array(
			'id'       => self::make_id( $taxonomy, $term_id, $mode, $roles ),
			'taxonomy' => $taxonomy,
			'term_id'  => $term_id,
			'mode'     => $mode,
			'roles'    => $roles,
		);
	}

	/**
	 * Deterministic id for a rule, so the same rule always has the same id
	 * (stable across reads and safe to delete by id) and duplicates collapse.
	 *
	 * @param string   $taxonomy Taxonomy.
	 * @param int      $term_id  Term id.
	 * @param string   $mode     Mode.
	 * @param string[] $roles    Role list.
	 * @return string
	 */
	private static function make_id( string $taxonomy, int $term_id, string $mode, array $roles ): string {
		sort( $roles );
		return 'r' . substr( md5( $taxonomy . ':' . $term_id . ':' . $mode . ':' . implode( ',', $roles ) ), 0, 16 );
	}

	/**
	 * Whether a rule denies a user who holds the given roles. Pure — no WP calls.
	 *
	 * whitelist: only the listed roles may see the term; everyone else (including
	 * guests, who hold no roles) is denied. blacklist: the listed roles are denied.
	 *
	 * @param array    $rule       Rule.
	 * @param string[] $user_roles Roles held by the user.
	 * @return bool
	 */
	public static function rule_denies_roles( array $rule, array $user_roles ): bool {
		$in_list = ! empty( array_intersect( $user_roles, $rule['roles'] ) );

		if ( 'whitelist' === $rule['mode'] ) {
			return ! $in_list;
		}

		return $in_list;
	}
}
