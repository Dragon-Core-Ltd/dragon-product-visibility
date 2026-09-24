<?php
/**
 * Per-product visibility rule storage
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes a product's restriction mode, role list and customer list as a single
 * unit. Shared by the AJAX and product-edit save paths so both behave the same.
 */
class Customer_Visibility {

	/**
	 * Meta key for the restriction mode.
	 */
	const MODE_META = '_dpv_restriction_mode';

	/**
	 * Meta key for the role list.
	 */
	const ROLES_META = '_dpv_visible_roles';

	/**
	 * Storage engines that honour START TRANSACTION and ROLLBACK. Anything else,
	 * including an engine that cannot be read, is treated as non-transactional.
	 */
	const TRANSACTIONAL_ENGINES = array( 'innodb', 'xtradb' );

	/**
	 * Customer visibility table name (with prefix).
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'dpv_customer_visibility';
	}

	/**
	 * Save a product's rules and report what is stored afterwards.
	 *
	 * Getting this wrong in one direction is a security bug: a save that fails
	 * half way must never leave the product visible to someone the stored rules
	 * blocked before. Which write does that depends on the mode in force while
	 * the save runs - under a whitelist an extra customer row widens access,
	 * under a blacklist a missing one does - so the writes are ordered per mode
	 * and, where the storage cannot roll back, undone again on failure.
	 *
	 * @param int      $product_id   Product ID.
	 * @param string   $mode         Restriction mode.
	 * @param string[] $roles        Role keys.
	 * @param int[]    $customer_ids Customer user IDs.
	 * @return array{saved: bool, restored: bool, message: string, stored: ?array} Outcome of the
	 *               save. 'restored' and 'stored' describe what is in the database after a failure
	 *               ('stored' is null when it could not be read); both are meaningless when 'saved'
	 *               is true, in which case the requested rules are stored.
	 */
	public static function save_rules( int $product_id, string $mode, array $roles, array $customer_ids ): array {
		$roles        = array_values( array_map( 'strval', $roles ) );
		$customer_ids = array_values( array_unique( array_filter( array_map( 'absint', $customer_ids ) ) ) );

		if ( ! Install::tables_exist() && ! Install::create_tables() ) {
			return self::unchanged(
				__( 'Visibility rules could not be saved because the customer visibility table is missing. The product keeps its previous rules.', 'dragon-product-visibility' )
			);
		}

		// The rules already stored, read before anything is written: the write
		// order depends on the stored mode, and a failure is reported against
		// this state rather than against what the save was asked to store.
		$previous = self::read_state( $product_id );

		if ( null === $previous ) {
			return self::unchanged(
				__( 'Visibility rules could not be saved because the product\'s current rules could not be read. The product keeps its previous rules.', 'dragon-product-visibility' )
			);
		}

		return self::apply(
			$product_id,
			self::plan( $previous, $mode, $roles, $customer_ids ),
			$previous,
			self::storage_is_transactional()
		);
	}

	/**
	 * The writes needed to turn the stored rules into the requested ones, in an
	 * order that only ever narrows visibility until the last possible moment.
	 *
	 * Until the mode meta is written the stored mode is still in force, so it is
	 * written last and the role list just before it. Among the customer rows,
	 * under a blacklist an insert blocks someone and a delete unblocks them,
	 * under a whitelist it is the other way round, so the narrowing direction
	 * goes first: a failure before the first widening write leaves the product no
	 * more visible than it was even if the compensation cannot run either. With
	 * no stored mode the rows do not affect visibility at all and either order is
	 * safe.
	 *
	 * @param array    $previous     Stored state from read_state().
	 * @param string   $mode         Requested mode.
	 * @param string[] $roles        Requested role keys.
	 * @param int[]    $customer_ids Requested customer IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private static function plan( array $previous, string $mode, array $roles, array $customer_ids ): array {
		$deletes = array();
		foreach ( array_diff( $previous['customers'], $customer_ids ) as $customer_id ) {
			$deletes[] = array(
				'type'        => 'delete',
				'customer_id' => (int) $customer_id,
			);
		}

		$inserts = array();
		foreach ( array_diff( $customer_ids, $previous['customers'] ) as $customer_id ) {
			$inserts[] = array(
				'type'        => 'insert',
				'customer_id' => (int) $customer_id,
			);
		}

		$ops = 'blacklist' === $previous['mode']
			? array_merge( $inserts, $deletes )
			: array_merge( $deletes, $inserts );

		$ops[] = array(
			'type'    => 'meta',
			'key'     => self::ROLES_META,
			'value'   => $roles,
			'restore' => $previous['roles'],
		);

		$ops[] = array(
			'type'    => 'meta',
			'key'     => self::MODE_META,
			'value'   => $mode,
			'restore' => $previous['mode'],
		);

		return $ops;
	}

	/**
	 * Run the planned writes, inside a transaction where the storage supports
	 * one, and report honestly when they do not all land.
	 *
	 * @param int                             $product_id    Product ID.
	 * @param array<int, array<string, mixed>> $plan          Writes from plan().
	 * @param array                           $previous      Stored state before the writes.
	 * @param bool                            $transactional Whether the storage can roll back.
	 * @return array{saved: bool, restored: bool, message: string, stored: ?array}
	 */
	private static function apply( int $product_id, array $plan, array $previous, bool $transactional ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control around the plugin's own writes.
		if ( $transactional && false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::failure( $product_id, $previous );
		}

		$applied = array();
		$written = self::run_ops( $product_id, $plan, $applied );

		if ( $written && ( ! $transactional || false !== $wpdb->query( 'COMMIT' ) ) ) {
			wp_cache_delete( $product_id, 'post_meta' );

			return array(
				'saved'    => true,
				'restored' => true,
				'message'  => __( 'Visibility rules saved', 'dragon-product-visibility' ),
				'stored'   => null,
			);
		}

		if ( $transactional ) {
			$wpdb->query( 'ROLLBACK' );
		}
		// phpcs:enable

		// Meta written inside the transaction primed nothing, but a rolled-back
		// value must not linger in the object cache either way.
		wp_cache_delete( $product_id, 'post_meta' );

		return self::failure( $product_id, $previous, $applied );
	}

	/**
	 * Run the planned writes in order, recording the ones that landed so they
	 * can be undone.
	 *
	 * @param int                             $product_id Product ID.
	 * @param array<int, array<string, mixed>> $plan      Writes from plan().
	 * @param array<int, array<string, mixed>> $applied   Receives the writes that landed, in order.
	 * @return bool True when every write landed.
	 */
	private static function run_ops( int $product_id, array $plan, array &$applied ): bool {
		foreach ( $plan as $op ) {
			if ( 'meta' === $op['type'] ) {
				if ( ! self::write_meta( $product_id, $op['key'], $op['value'] ) ) {
					return false;
				}
			} elseif ( 'insert' === $op['type'] ) {
				if ( ! self::insert_row( $product_id, $op['customer_id'] ) ) {
					return false;
				}
			} else {
				$removed = self::delete_row( $product_id, $op['customer_id'] );

				if ( false === $removed ) {
					return false;
				}

				// Only a row that really went away is put back by the undo; a
				// delete that matched nothing must not become an insert.
				$op['removed'] = $removed;
			}

			$applied[] = $op;
		}

		return true;
	}

	/**
	 * Undo the writes that landed, in reverse order. Each undo is a single-row
	 * write; whether they worked is established by reading the rules back
	 * afterwards rather than by trusting the return values here.
	 *
	 * @param int                             $product_id Product ID.
	 * @param array<int, array<string, mixed>> $applied   Writes that landed, in order.
	 */
	private static function undo( int $product_id, array $applied ): void {
		foreach ( array_reverse( $applied ) as $op ) {
			if ( 'insert' === $op['type'] ) {
				self::delete_row( $product_id, $op['customer_id'] );
				continue;
			}

			if ( 'delete' === $op['type'] ) {
				if ( $op['removed'] > 0 ) {
					self::insert_row( $product_id, $op['customer_id'] );
				}
				continue;
			}

			// A product that had no stored mode gets an empty one back. The
			// visibility filter treats that exactly like a missing row: neither
			// whitelist nor blacklist, so the bulk rules decide again.
			self::write_meta( $product_id, $op['key'], $op['restore'] );
		}
	}

	/**
	 * Report a failed save in terms of what is actually stored, compensating
	 * first if the storage did not revert the writes itself.
	 *
	 * @param int                             $product_id Product ID.
	 * @param array                           $previous   Stored state before the writes.
	 * @param array<int, array<string, mixed>> $applied   Writes that landed, in order.
	 * @return array{saved: bool, restored: bool, message: string, stored: ?array}
	 */
	private static function failure( int $product_id, array $previous, array $applied = array() ): array {
		$stored = self::read_state( $product_id );

		if ( array() !== $applied && ( null === $stored || $stored !== $previous ) ) {
			self::undo( $product_id, $applied );
			wp_cache_delete( $product_id, 'post_meta' );
			$stored = self::read_state( $product_id );
		}

		if ( null === $stored ) {
			return array(
				'saved'    => false,
				'restored' => false,
				'message'  => __( 'Visibility rules could not be saved, and the rules now stored could not be read back. Check this product\'s visibility restrictions before relying on them.', 'dragon-product-visibility' ),
				'stored'   => null,
			);
		}

		if ( $stored === $previous ) {
			return array(
				'saved'    => false,
				'restored' => true,
				'message'  => __( 'Visibility rules could not be saved. The product keeps its previous rules.', 'dragon-product-visibility' ),
				'stored'   => $stored,
			);
		}

		return array(
			'saved'    => false,
			'restored' => false,
			'message'  => sprintf(
				/* translators: 1: restriction mode now stored, 2: listed customer count (e.g. "2 listed customers"), 3: listed role count (e.g. "1 listed role"). */
				__( 'Visibility rules could not be saved, and the previous rules could not be fully restored. The product is now stored with restriction mode %1$s, %2$s and %3$s. Check its visibility restrictions before relying on them.', 'dragon-product-visibility' ),
				self::mode_label( $stored['mode'] ),
				sprintf(
					/* translators: %s: number of customers listed on the product. */
					_n( '%s listed customer', '%s listed customers', count( $stored['customers'] ), 'dragon-product-visibility' ),
					number_format_i18n( count( $stored['customers'] ) )
				),
				sprintf(
					/* translators: %s: number of roles listed on the product. */
					_n( '%s listed role', '%s listed roles', count( $stored['roles'] ), 'dragon-product-visibility' ),
					number_format_i18n( count( $stored['roles'] ) )
				)
			),
			'stored'   => $stored,
		);
	}

	/**
	 * Outcome for a save that gave up before writing anything.
	 *
	 * @param string $message Reason, in the admin's terms.
	 * @return array{saved: bool, restored: bool, message: string, stored: ?array}
	 */
	private static function unchanged( string $message ): array {
		return array(
			'saved'    => false,
			'restored' => true,
			'message'  => $message,
			'stored'   => null,
		);
	}

	/**
	 * Plain-language name for a stored restriction mode.
	 *
	 * @param string $mode Stored mode.
	 * @return string
	 */
	private static function mode_label( string $mode ): string {
		if ( 'whitelist' === $mode ) {
			return __( 'whitelist (only listed customers and roles can see it)', 'dragon-product-visibility' );
		}

		if ( 'blacklist' === $mode ) {
			return __( 'blacklist (listed customers and roles cannot see it)', 'dragon-product-visibility' );
		}

		return __( 'none (no per-product restriction)', 'dragon-product-visibility' );
	}

	/**
	 * Whether both tables a save writes to can roll back. Checked rather than
	 * assumed: the plugin's own table may be MyISAM on an older install, and
	 * postmeta can be too, in which case START TRANSACTION is accepted and the
	 * ROLLBACK silently keeps the half-applied rules.
	 *
	 * The two lookups run on every save rather than being cached: a save is a
	 * rare admin action, and a cached answer is a safety decision that could go
	 * stale against the database it describes.
	 *
	 * @return bool
	 */
	private static function storage_is_transactional(): bool {
		global $wpdb;

		return self::engine_is_transactional( self::table_name() )
			&& self::engine_is_transactional( $wpdb->postmeta );
	}

	/**
	 * Whether one table's storage engine honours a rollback.
	 *
	 * @param string $table Table name.
	 * @return bool False when the engine is non-transactional or cannot be read.
	 */
	private static function engine_is_transactional( string $table ): bool {
		global $wpdb;

		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check before the rules are written; no WordPress API exposes it.
		$engine = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table
			)
		);

		// Restricted grants on shared hosting can hide information_schema. An
		// unknown engine counts as non-transactional, so the save compensates
		// instead of trusting a rollback that may never happen.
		if ( ! is_string( $engine ) || '' !== (string) $wpdb->last_error ) {
			return false;
		}

		return in_array( strtolower( $engine ), self::TRANSACTIONAL_ENGINES, true );
	}

	/**
	 * The rules currently stored for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array{mode: string, roles: string[], customers: int[]}|null Null when a read failed,
	 *               which is not the same answer as a product with no rules.
	 */
	private static function read_state( int $product_id ): ?array {
		global $wpdb;

		$failed = false;
		$mode   = self::read_meta( $product_id, self::MODE_META, $failed );

		if ( $failed ) {
			return null;
		}

		$roles = self::read_meta( $product_id, self::ROLES_META, $failed );

		if ( $failed ) {
			return null;
		}

		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read, uncached so it reflects the writes just made.
		$rows = $wpdb->get_col(
			$wpdb->prepare( 'SELECT customer_id FROM %i WHERE product_id = %d', self::table_name(), $product_id )
		);

		/*
		 * A failed read returns an empty array, which is the same answer as a
		 * product with no customer rules. Taken as "no rows" the plan above finds
		 * nothing to remove and the save commits as a success while the stored
		 * rows are left in place, so the error is checked before the result is
		 * used.
		 */
		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}

		$customers = array_map( 'intval', (array) $rows );
		sort( $customers );

		return array(
			'mode'      => is_string( $mode ) ? $mode : '',
			'roles'     => is_array( $roles ) ? array_values( array_map( 'strval', $roles ) ) : array(),
			'customers' => $customers,
		);
	}

	/**
	 * Read one meta value straight from the table. get_post_meta() would answer
	 * from the object cache, which can hold a value that is not in the database.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $key        Meta key.
	 * @param bool   $failed     Set to true when the read itself failed.
	 * @return mixed Stored value, or null when there is no row.
	 */
	private static function read_meta( int $product_id, string $key, bool &$failed ) {
		global $wpdb;

		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uncached read of the plugin's own meta.
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
				$product_id,
				$key
			)
		);

		$failed = '' !== (string) $wpdb->last_error;

		return null === $stored ? null : maybe_unserialize( $stored );
	}

	/**
	 * Write one meta value and confirm it from the database. update_post_meta()
	 * returns false for an unchanged value as well as for a failed write, so the
	 * row is read back instead.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $key        Meta key.
	 * @param mixed  $value      Value to store.
	 * @return bool
	 */
	private static function write_meta( int $product_id, string $key, $value ): bool {
		update_post_meta( $product_id, $key, $value );

		$failed = false;
		$stored = self::read_meta( $product_id, $key, $failed );

		return ! $failed && null !== $stored && $stored === $value;
	}

	/**
	 * Add one customer row.
	 *
	 * @param int $product_id  Product ID.
	 * @param int $customer_id Customer user ID.
	 * @return bool
	 */
	private static function insert_row( int $product_id, int $customer_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Adding a selected customer.
		return false !== $wpdb->insert(
			self::table_name(),
			array(
				'product_id'  => $product_id,
				'customer_id' => $customer_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Remove one customer row, matched on both IDs so no other product's rules
	 * are touched.
	 *
	 * @param int $product_id  Product ID.
	 * @param int $customer_id Customer user ID.
	 * @return int|false Rows removed, or false when the delete failed.
	 */
	private static function delete_row( int $product_id, int $customer_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removing a deselected customer.
		$deleted = $wpdb->delete(
			self::table_name(),
			array(
				'product_id'  => $product_id,
				'customer_id' => $customer_id,
			),
			array( '%d', '%d' )
		);

		return false === $deleted ? false : (int) $deleted;
	}
}
