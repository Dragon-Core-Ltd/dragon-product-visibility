<?php
/**
 * Minimal in-memory stand-in for $wpdb. Records every query, honours
 * START TRANSACTION / COMMIT / ROLLBACK against its row store, and can be told
 * to fail a given insert, the delete, or any query matching a substring.
 *
 * @package DragonProductVisibility
 */

namespace DragonProductVisibility\Tests;

final class Fake_Wpdb {

	public string $prefix = 'wp_';

	public string $postmeta = 'wp_postmeta';

	public string $posts = 'wp_posts';

	public string $users = 'wp_users';

	/** When false, START TRANSACTION / ROLLBACK are accepted but do nothing (MyISAM). */
	public bool $transactions_supported = true;

	/** Engine reported for any table with no entry in $engines. Null = information_schema unreadable. */
	public ?string $default_engine = 'InnoDB';

	/** @var array<string, string|null> Engine per table name, as information_schema reports it. */
	public array $engines = array();

	/** @var array<int, array<string, mixed>> Every delete() call: table + where. */
	public array $deletes = array();

	public int $insert_calls = 0;

	public int $show_tables_calls = 0;

	public string $last_error = '';

	/** @var string[] Every SQL string passed to query(). */
	public array $queries = array();

	/** @var string[] Table names that "exist". */
	public array $tables = array();

	/** @var array<string, array<int, array<string, mixed>>> Rows per table. */
	public array $rows = array();

	/** 1-based index of the insert() call that should fail (0 = never). */
	public int $fail_insert_at = 0;

	public bool $fail_delete = false;

	/** 1-based index of the delete() call that should fail (0 = never). */
	public int $fail_delete_at = 0;

	public int $delete_calls = 0;

	/**
	 * When true, get_col() behaves like a failed read: an empty array plus an
	 * error, which is indistinguishable from "no rows" unless the error is read.
	 *
	 * @var bool
	 */
	public bool $fail_get_col = false;

	/** @var string[] query() fails when the SQL contains any of these. */
	public array $fail_query_containing = array();

	private ?array $snapshot = null;

	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[isd]/',
			function ( $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ] ?? '';
				if ( '%i' === $m[0] ) {
					return '`' . $value . '`';
				}
				if ( '%d' === $m[0] ) {
					return (string) (int) $value;
				}
				return "'" . $value . "'";
			},
			$query
		);
	}

	public function query( string $sql ) {
		$this->queries[] = $sql;
		foreach ( $this->fail_query_containing as $needle ) {
			if ( false !== stripos( $sql, $needle ) ) {
				$this->last_error = 'forced failure: ' . $needle;
				return false;
			}
		}
		if ( ! $this->transactions_supported ) {
			return 0;
		}
		if ( 'START TRANSACTION' === $sql ) {
			$this->snapshot = $this->rows;
		} elseif ( 'ROLLBACK' === $sql ) {
			if ( null !== $this->snapshot ) {
				$this->rows = $this->snapshot;
			}
			$this->snapshot = null;
		} elseif ( 'COMMIT' === $sql ) {
			$this->snapshot = null;
		}
		return 0;
	}

	public function delete( string $table, array $where, $format = null ) {
		unset( $format );
		++$this->delete_calls;
		$this->deletes[] = array( 'table' => $table, 'where' => $where );
		if ( $this->fail_delete || $this->delete_calls === $this->fail_delete_at ) {
			$this->last_error = 'forced delete failure';
			return false;
		}
		$kept  = array();
		$count = 0;
		foreach ( $this->rows[ $table ] ?? array() as $row ) {
			if ( array_intersect_assoc( $where, $row ) === $where ) {
				++$count;
				continue;
			}
			$kept[] = $row;
		}
		$this->rows[ $table ] = $kept;
		return $count;
	}

	public function insert( string $table, array $data, $format = null ) {
		unset( $format );
		++$this->insert_calls;
		if ( $this->insert_calls === $this->fail_insert_at ) {
			$this->last_error = 'forced insert failure';
			return false;
		}
		$this->rows[ $table ][] = $data;
		return 1;
	}

	public function get_var( string $sql ) {
		if ( preg_match( "/FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE\\(\\) AND TABLE_NAME = '([^']+)'/", $sql, $m ) ) {
			return array_key_exists( $m[1], $this->engines ) ? $this->engines[ $m[1] ] : $this->default_engine;
		}
		if ( preg_match( "/SELECT ID FROM wp_posts WHERE post_name = '([^']*)' AND post_type = 'product_variation'/", $sql, $m ) ) {
			foreach ( $GLOBALS['dpv_test_posts'] as $post ) {
				if ( ( $post->post_name ?? '' ) === $m[1] && 'product_variation' === $post->post_type ) {
					return (string) $post->ID;
				}
			}
			return null;
		}
		if ( preg_match( "/FROM wp_postmeta WHERE post_id = (\\d+) AND meta_key = '([^']+)'/", $sql, $m ) ) {
			$value = $GLOBALS['dpv_test_meta'][ (int) $m[1] ][ $m[2] ] ?? null;
			return null === $value ? null : \maybe_serialize( $value );
		}
		++$this->show_tables_calls;
		foreach ( $this->tables as $table ) {
			if ( false !== strpos( $sql, "'" . $table . "'" ) ) {
				return $table;
			}
		}
		return null;
	}

	public function get_col( string $sql ): array {
		if ( $this->fail_get_col ) {
			$this->last_error = 'forced read failure';
			return array();
		}
		if ( preg_match( '/SELECT customer_id FROM `([^`]+)` WHERE product_id = (\\d+)/', $sql, $m ) ) {
			$out = array();
			foreach ( $this->rows[ $m[1] ] ?? array() as $row ) {
				if ( (int) $row['product_id'] === (int) $m[2] ) {
					$out[] = (string) $row['customer_id'];
				}
			}
			return $out;
		}
		if ( preg_match( '/SELECT product_id FROM `([^`]+)` WHERE customer_id = (\\d+)/', $sql, $m ) ) {
			$out = array();
			foreach ( $this->rows[ $m[1] ] ?? array() as $row ) {
				if ( (int) $row['customer_id'] === (int) $m[2] ) {
					$out[] = (string) $row['product_id'];
				}
			}
			return $out;
		}
		if ( false !== strpos( $sql, "SELECT DISTINCT post_id FROM wp_postmeta" ) && false !== strpos( $sql, '_dpv_restriction_mode' ) ) {
			$out = array();
			foreach ( $GLOBALS['dpv_test_meta'] as $post_id => $meta ) {
				if ( in_array( $meta['_dpv_restriction_mode'] ?? null, array( 'whitelist', 'blacklist' ), true ) ) {
					$out[] = (string) $post_id;
				}
			}
			return $out;
		}
		return array();
	}

	public int $get_results_calls = 0;

	public function get_results( string $sql ): array {
		unset( $sql );
		++$this->get_results_calls;
		return array();
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function get_charset_collate(): string {
		return '';
	}

	/**
	 * Put the fake on storage that cannot roll back: MyISAM tables, and
	 * transaction statements that are accepted but do nothing.
	 */
	public function use_non_transactional_storage(): void {
		$this->default_engine         = 'MyISAM';
		$this->transactions_supported = false;
	}

	/** Rows stored for a table, in insertion order. */
	public function rows_in( string $table ): array {
		return $this->rows[ $table ] ?? array();
	}
}
