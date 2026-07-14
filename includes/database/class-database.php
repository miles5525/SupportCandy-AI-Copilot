<?php
/**
 * Database access layer for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides safe access to plugin-owned database tables.
 *
 * This class is intentionally generic. It does not contain business-specific
 * logic for conversations, knowledge, usage logs, providers, or SupportCandy.
 */
final class SCAI_Database {

	/**
	 * WordPress database object.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Create database access instance.
	 *
	 * @param wpdb|null $wpdb_instance Optional WordPress database instance.
	 */
	public function __construct( $wpdb_instance = null ) {
		global $wpdb;

		$this->wpdb = $wpdb_instance ? $wpdb_instance : $wpdb;

		self::load_dependencies();
	}

	/**
	 * Load required dependencies.
	 *
	 * @return void
	 */
	private static function load_dependencies() {
		if ( class_exists( 'SCAI_Schema' ) ) {
			return;
		}

		$schema_file = __DIR__ . '/class-schema.php';

		if ( is_readable( $schema_file ) ) {
			require_once $schema_file;
		}
	}

	/**
	 * Get a plugin-owned table name by logical key.
	 *
	 * @param string $table_key Logical table key.
	 * @return string Table name with WordPress prefix, or empty string if invalid.
	 */
	public function get_table_name( $table_key ) {
		self::load_dependencies();

		$table_key = sanitize_key( (string) $table_key );

		if ( '' === $table_key || ! class_exists( 'SCAI_Schema' ) ) {
			return '';
		}

		return SCAI_Schema::get_table_name( $table_key );
	}

	/**
	 * Check whether a plugin-owned table exists.
	 *
	 * @param string $table_key Logical table key.
	 * @return bool
	 */
	public function table_exists( $table_key ) {
		$table_name = $this->get_table_name( $table_key );

		if ( '' === $table_name ) {
			return false;
		}

		$like = $this->wpdb->esc_like( $table_name );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is compared as a prepared string value, not used as an SQL identifier.
		$found_table = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return $found_table === $table_name;
	}

	/**
	 * Insert a row into a plugin-owned table.
	 *
	 * @param string               $table_key Logical table key.
	 * @param array<string, mixed> $data      Data to insert.
	 * @param array<int, string>   $format    Optional value formats.
	 * @return int|false Inserted row ID on success, false on failure.
	 */
	public function insert( $table_key, array $data, array $format = array() ) {
		$table_name = $this->get_table_name( $table_key );

		if ( '' === $table_name || empty( $data ) ) {
			return false;
		}

		$data = $this->sanitize_data_keys( $data );

		if ( empty( $data ) ) {
			return false;
		}

		$inserted = $this->wpdb->insert(
			$table_name,
			$data,
			$this->normalize_formats( $data, $format )
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Update rows in a plugin-owned table.
	 *
	 * @param string               $table_key    Logical table key.
	 * @param array<string, mixed> $data         Data to update.
	 * @param array<string, mixed> $where        Where conditions.
	 * @param array<int, string>   $format       Optional data formats.
	 * @param array<int, string>   $where_format Optional where formats.
	 * @return int|false Number of rows updated, or false on failure.
	 */
	public function update( $table_key, array $data, array $where, array $format = array(), array $where_format = array() ) {
		$table_name = $this->get_table_name( $table_key );

		if ( '' === $table_name || empty( $data ) || empty( $where ) ) {
			return false;
		}

		$data  = $this->sanitize_data_keys( $data );
		$where = $this->sanitize_data_keys( $where );

		if ( empty( $data ) || empty( $where ) ) {
			return false;
		}

		return $this->wpdb->update(
			$table_name,
			$data,
			$where,
			$this->normalize_formats( $data, $format ),
			$this->normalize_formats( $where, $where_format )
		);
	}

	/**
	 * Delete rows from a plugin-owned table.
	 *
	 * @param string               $table_key    Logical table key.
	 * @param array<string, mixed> $where        Where conditions.
	 * @param array<int, string>   $where_format Optional where formats.
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public function delete( $table_key, array $where, array $where_format = array() ) {
		$table_name = $this->get_table_name( $table_key );

		if ( '' === $table_name || empty( $where ) ) {
			return false;
		}

		$where = $this->sanitize_data_keys( $where );

		if ( empty( $where ) ) {
			return false;
		}

		return $this->wpdb->delete(
			$table_name,
			$where,
			$this->normalize_formats( $where, $where_format )
		);
	}

	/**
	 * Get a single row by ID from a plugin-owned table.
	 *
	 * @param string $table_key Logical table key.
	 * @param int    $id        Row ID.
	 * @param string $output    Output type. ARRAY_A, ARRAY_N, or OBJECT.
	 * @return array<string, mixed>|array<int, mixed>|object|null
	 */
	public function get_row_by_id( $table_key, $id, $output = ARRAY_A ) {
		$id = absint( $id );

		if ( 0 === $id ) {
			return null;
		}

		return $this->get_row(
			$table_key,
			array(
				'id' => $id,
			),
			$output
		);
	}

	/**
	 * Get a single row from a plugin-owned table.
	 *
	 * Only equality-based where conditions are supported by this generic method.
	 *
	 * @param string               $table_key Logical table key.
	 * @param array<string, mixed> $where     Where conditions.
	 * @param string               $output    Output type. ARRAY_A, ARRAY_N, or OBJECT.
	 * @return array<string, mixed>|array<int, mixed>|object|null
	 */
	public function get_row( $table_key, array $where, $output = ARRAY_A ) {
		$table_name = $this->get_table_name( $table_key );

		if ( '' === $table_name || empty( $where ) ) {
			return null;
		}

		$where_sql = $this->build_where_sql( $where );

		if ( '' === $where_sql ) {
			return null;
		}

		$output = $this->normalize_output_type( $output );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is schema-controlled and WHERE clause is prepared by build_where_sql().
		return $this->wpdb->get_row( "SELECT * FROM `{$table_name}` {$where_sql} LIMIT 1", $output );
	}

	/**
	 * Get rows from a plugin-owned table.
	 *
	 * Supported args:
	 * - where   array<string, mixed> Equality-based conditions.
	 * - orderby string Column name.
	 * - order   string ASC or DESC.
	 * - limit   int Maximum rows.
	 * - offset  int Rows to skip.
	 *
	 * @param string              $table_key Logical table key.
	 * @param array<string,mixed> $args      Query arguments.
	 * @param string              $output    Output type. ARRAY_A, ARRAY_N, or OBJECT.
	 * @return array<int, mixed>
	 */
	public function get_results( $table_key, array $args = array(), $output = ARRAY_A ) {
		$table_name = $this->get_table_name( $table_key );

		if ( '' === $table_name ) {
			return array();
		}

		$defaults = array(
			'where'   => array(),
			'orderby' => 'id',
			'order'   => 'DESC',
			'limit'   => 50,
			'offset'  => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		$where_sql = $this->build_where_sql( is_array( $args['where'] ) ? $args['where'] : array() );
		$orderby   = $this->sanitize_identifier( $args['orderby'] );
		$order     = $this->normalize_order( $args['order'] );
		$limit     = absint( $args['limit'] );
		$offset    = absint( $args['offset'] );
		$output    = $this->normalize_output_type( $output );

		if ( '' === $orderby ) {
			$orderby = 'id';
		}

		if ( 0 === $limit ) {
			$limit = 50;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column identifiers are schema-controlled/sanitized. LIMIT/OFFSET are prepared.
		$sql = "SELECT * FROM `{$table_name}` {$where_sql} ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d";

		return $this->wpdb->get_results(
			$this->wpdb->prepare( $sql, $limit, $offset ),
			$output
		);
	}

	/**
	 * Count rows in a plugin-owned table.
	 *
	 * @param string               $table_key Logical table key.
	 * @param array<string, mixed> $where     Optional where conditions.
	 * @return int
	 */
	public function get_count( $table_key, array $where = array() ) {
		$table_name = $this->get_table_name( $table_key );

		if ( '' === $table_name ) {
			return 0;
		}

		$where_sql = $this->build_where_sql( $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is schema-controlled and WHERE clause is prepared by build_where_sql().
		$count = $this->wpdb->get_var( "SELECT COUNT(*) FROM `{$table_name}` {$where_sql}" );

		return absint( $count );
	}

	/**
	 * Get last database error.
	 *
	 * @return string
	 */
	public function get_last_error() {
		return (string) $this->wpdb->last_error;
	}

	/**
	 * Get last inserted ID.
	 *
	 * @return int
	 */
	public function get_insert_id() {
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Sanitize array keys intended to be database column names.
	 *
	 * @param array<string, mixed> $data Input data.
	 * @return array<string, mixed>
	 */
	private function sanitize_data_keys( array $data ) {
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$column = $this->sanitize_identifier( $key );

			if ( '' === $column ) {
				continue;
			}

			$sanitized[ $column ] = $value;
		}

		return $sanitized;
	}

	/**
	 * Normalize value formats for wpdb insert/update/delete methods.
	 *
	 * @param array<string, mixed> $data    Data array.
	 * @param array<int, string>   $formats Provided formats.
	 * @return array<int, string>
	 */
	private function normalize_formats( array $data, array $formats = array() ) {
		$normalized = array_values( $formats );

		if ( count( $normalized ) === count( $data ) ) {
			return $normalized;
		}

		$normalized = array();

		foreach ( $data as $value ) {
			$normalized[] = $this->get_value_format( $value );
		}

		return $normalized;
	}

	/**
	 * Build a prepared WHERE SQL clause.
	 *
	 * Only equality and NULL checks are supported.
	 *
	 * @param array<string, mixed> $where Where conditions.
	 * @return string Prepared WHERE SQL clause, or empty string.
	 */
	private function build_where_sql( array $where ) {
		$where = $this->sanitize_data_keys( $where );

		if ( empty( $where ) ) {
			return '';
		}

		$clauses = array();
		$values  = array();

		foreach ( $where as $column => $value ) {
			if ( null === $value ) {
				$clauses[] = "`{$column}` IS NULL";
				continue;
			}

			$clauses[] = "`{$column}` = " . $this->get_value_format( $value );
			$values[]  = $value;
		}

		if ( empty( $clauses ) ) {
			return '';
		}

		$sql = 'WHERE ' . implode( ' AND ', $clauses );

		if ( empty( $values ) ) {
			return $sql;
		}

		return $this->wpdb->prepare( $sql, array_values( $values ) );
	}

	/**
	 * Get wpdb placeholder format for a value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function get_value_format( $value ) {
		if ( is_int( $value ) || is_bool( $value ) ) {
			return '%d';
		}

		if ( is_float( $value ) ) {
			return '%f';
		}

		return '%s';
	}

	/**
	 * Sanitize a database identifier such as a column name.
	 *
	 * @param mixed $identifier Identifier.
	 * @return string
	 */
	private function sanitize_identifier( $identifier ) {
		$identifier = (string) $identifier;

		if ( '' === $identifier ) {
			return '';
		}

		$identifier = preg_replace( '/[^A-Za-z0-9_]/', '', $identifier );

		return is_string( $identifier ) ? $identifier : '';
	}

	/**
	 * Normalize SQL order direction.
	 *
	 * @param mixed $order Order direction.
	 * @return string
	 */
	private function normalize_order( $order ) {
		$order = strtoupper( sanitize_key( (string) $order ) );

		return 'ASC' === $order ? 'ASC' : 'DESC';
	}

	/**
	 * Normalize wpdb output type.
	 *
	 * @param mixed $output Output type.
	 * @return string
	 */
	private function normalize_output_type( $output ) {
		if ( ARRAY_N === $output ) {
			return ARRAY_N;
		}

		if ( OBJECT === $output ) {
			return OBJECT;
		}

		return ARRAY_A;
	}
}