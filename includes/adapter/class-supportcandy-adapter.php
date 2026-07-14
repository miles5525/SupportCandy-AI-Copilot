<?php
/**
 * SupportCandy adapter for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only adapter for normal SupportCandy ticket data.
 */
final class SCAI_SupportCandy_Adapter {

	/**
	 * Default maximum ticket threads returned.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_THREADS = 20;

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null $wpdb_instance Optional database instance.
	 */
	public function __construct( $wpdb_instance = null ) {
		global $wpdb;

		$this->wpdb = $wpdb_instance ? $wpdb_instance : $wpdb;
	}

	/**
	 * Determine whether SupportCandy ticket data is readable.
	 *
	 * @return bool
	 */
	public function is_available() {
		$status = $this->get_status();

		return ! empty( $status['supportcandy_active'] ) && ! empty( $status['data_source_available'] );
	}

	/**
	 * Resolve an internal SupportCandy ticket ID from an internal or public identifier.
	 *
	 * @param mixed $ticket_identifier Internal ticket ID or public/display ticket identifier.
	 * @return int
	 */
	public function resolve_ticket_id( $ticket_identifier ) {
		if ( is_array( $ticket_identifier ) || is_object( $ticket_identifier ) ) {
			return 0;
		}

		$identifier = trim( sanitize_text_field( (string) $ticket_identifier ) );

		if ( '' === $identifier || ! $this->is_available() ) {
			return 0;
		}

		$direct_ticket_id = absint( $identifier );
		$resolved_id      = 0;

		if ( $direct_ticket_id > 0 && $this->ticket_exists( $direct_ticket_id ) ) {
			$resolved_id = $direct_ticket_id;
		}

		if ( 0 === $resolved_id ) {
			$resolved_id = $this->resolve_ticket_id_by_public_identifier( $identifier );
		}

		/**
		 * Filter the resolved internal SupportCandy ticket ID.
		 *
		 * @param int    $resolved_id Internal ticket ID, or 0 if unresolved.
		 * @param string $identifier  Sanitized input identifier.
		 */
		$resolved_id = absint( apply_filters( 'scai_supportcandy_resolved_ticket_id', $resolved_id, $identifier ) );

		return $resolved_id > 0 && $this->ticket_exists( $resolved_id ) ? $resolved_id : 0;
	}

	/**
	 * Get adapter and data-source status.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status() {
		$table_names      = $this->get_table_names();
		$table_status     = array();
		$required_tables  = array( 'tickets', 'threads' );
		$available_tables = 0;

		foreach ( $table_names as $key => $table_name ) {
			$table_status[ $key ] = $this->table_exists( $table_name );

			if ( in_array( $key, $required_tables, true ) && $this->is_required_table_readable( $key, $table_name ) ) {
				$available_tables++;
			}
		}

		return $this->sanitize_output_data(
			array(
				'supportcandy_active'   => $this->is_supportcandy_active(),
				'data_source_available' => count( $required_tables ) === $available_tables,
				'tables'                => $table_status,
				'detected_classes'      => array(
					'WPSC_Ticket'     => class_exists( 'WPSC_Ticket' ),
					'WPSC_Thread'     => class_exists( 'WPSC_Thread' ),
					'WPSC_Attachment' => class_exists( 'WPSC_Attachment' ),
				),
			)
		);
	}

	/**
	 * Get normalized ticket data.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return array<string, mixed>
	 */
	public function get_ticket( $ticket_id ) {
		$ticket_id = absint( $ticket_id );

		if ( 0 === $ticket_id || ! $this->is_available() ) {
			return array();
		}

		$tickets_table = $this->get_table_name( 'tickets' );

		if ( '' === $tickets_table || ! $this->table_exists( $tickets_table ) ) {
			return array();
		}

		if ( ! $this->table_has_column( $tickets_table, 'id' ) ) {
			return array();
		}

		$customers_table  = $this->get_table_name( 'customers' );
		$statuses_table   = $this->get_table_name( 'statuses' );
		$categories_table = $this->get_table_name( 'categories' );
		$priorities_table = $this->get_table_name( 'priorities' );

		$has_customer_column  = $this->table_has_column( $tickets_table, 'customer' );
		$has_status_column    = $this->table_has_column( $tickets_table, 'status' );
		$has_category_column  = $this->table_has_column( $tickets_table, 'category' );
		$has_priority_column  = $this->table_has_column( $tickets_table, 'priority' );
		$has_customers_table  = $this->table_exists( $customers_table ) && $this->table_has_column( $customers_table, 'id' );
		$has_statuses_table   = $this->table_exists( $statuses_table ) && $this->table_has_column( $statuses_table, 'id' );
		$has_categories_table = $this->table_exists( $categories_table ) && $this->table_has_column( $categories_table, 'id' );
		$has_priorities_table = $this->table_exists( $priorities_table ) && $this->table_has_column( $priorities_table, 'id' );

		$select = array(
			't.*',
			$has_customer_column && $has_customers_table && $this->table_has_column( $customers_table, 'name' ) ? 'c.name AS customer_name' : "'' AS customer_name",
			$has_customer_column && $has_customers_table && $this->table_has_column( $customers_table, 'email' ) ? 'c.email AS customer_email' : "'' AS customer_email",
			$has_status_column && $has_statuses_table && $this->table_has_column( $statuses_table, 'name' ) ? 's.name AS status_name' : ( $has_status_column ? 't.status AS status_name' : "'' AS status_name" ),
			$has_category_column && $has_categories_table && $this->table_has_column( $categories_table, 'name' ) ? 'cat.name AS category_name' : ( $has_category_column ? 't.category AS category_name' : "'' AS category_name" ),
			$has_priority_column && $has_priorities_table && $this->table_has_column( $priorities_table, 'name' ) ? 'p.name AS priority_name' : ( $has_priority_column ? 't.priority AS priority_name' : "'' AS priority_name" ),
		);
		$joins  = array();

		if ( $has_customer_column && $has_customers_table ) {
			$joins[] = "LEFT JOIN `{$customers_table}` c ON c.id = t.customer";
		}

		if ( $has_status_column && $has_statuses_table ) {
			$joins[] = "LEFT JOIN `{$statuses_table}` s ON s.id = t.status";
		}

		if ( $has_category_column && $has_categories_table ) {
			$joins[] = "LEFT JOIN `{$categories_table}` cat ON cat.id = t.category";
		}

		if ( $has_priority_column && $has_priorities_table ) {
			$joins[] = "LEFT JOIN `{$priorities_table}` p ON p.id = t.priority";
		}

		$sql = "
			SELECT " . implode( ', ', $select ) . "
			FROM `{$tickets_table}` t
			" . implode( "\n", $joins ) . "
			WHERE t.id = %d
			LIMIT 1
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are sanitized and schema-controlled.
		$row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, $ticket_id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return array();
		}

		$ticket = $this->normalize_ticket_row( $row );

		/**
		 * Filter normalized SupportCandy ticket data.
		 *
		 * @param array<string, mixed> $ticket    Normalized ticket.
		 * @param array<string, mixed> $row       Raw sanitized row.
		 * @param int                  $ticket_id Ticket ID.
		 */
		$ticket = apply_filters( 'scai_supportcandy_ticket_data', $ticket, $this->sanitize_raw_row( $row ), $ticket_id );

		return is_array( $ticket ) ? $this->sanitize_output_data( $ticket ) : array();
	}

	/**
	 * Get normalized ticket threads.
	 *
	 * @param int                  $ticket_id Ticket ID.
	 * @param array<string, mixed> $args      Query args.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_ticket_threads( $ticket_id, array $args = array() ) {
		$ticket_id = absint( $ticket_id );

		if ( 0 === $ticket_id || ! $this->is_available() ) {
			return array();
		}

		$threads_table  = $this->get_table_name( 'threads' );
		$customers_table = $this->get_table_name( 'customers' );

		if ( '' === $threads_table || ! $this->table_exists( $threads_table ) ) {
			return array();
		}

		if ( ! $this->table_has_column( $threads_table, 'id' ) || ! $this->table_has_column( $threads_table, 'ticket' ) ) {
			return array();
		}

		$args  = wp_parse_args(
			$args,
			array(
				'limit'  => self::DEFAULT_MAX_THREADS,
				'order'  => 'ASC',
				'active' => true,
			)
		);
		$limit = min( 100, max( 1, absint( $args['limit'] ) ) );
		$order = 'DESC' === strtoupper( sanitize_key( $args['order'] ) ) ? 'DESC' : 'ASC';
		$where = 'WHERE th.ticket = %d';

		if ( ! empty( $args['active'] ) && $this->table_has_column( $threads_table, 'is_active' ) ) {
			$where .= ' AND th.is_active = 1';
		}

		$has_thread_customer_column = $this->table_has_column( $threads_table, 'customer' );
		$has_customers_table        = $this->table_exists( $customers_table ) && $this->table_has_column( $customers_table, 'id' );
		$order_column               = $this->table_has_column( $threads_table, 'date_created' ) ? 'th.date_created' : 'th.id';
		$select = array(
			'th.*',
			$has_thread_customer_column && $has_customers_table && $this->table_has_column( $customers_table, 'name' ) ? 'c.name AS author_name' : "'' AS author_name",
			$has_thread_customer_column && $has_customers_table && $this->table_has_column( $customers_table, 'email' ) ? 'c.email AS author_email' : "'' AS author_email",
		);
		$join = $has_thread_customer_column && $has_customers_table ? "LEFT JOIN `{$customers_table}` c ON c.id = th.customer" : '';

		$sql = "
			SELECT " . implode( ', ', $select ) . "
			FROM `{$threads_table}` th
			{$join}
			{$where}
			ORDER BY {$order_column} {$order}
			LIMIT %d
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names/order are sanitized and schema-controlled.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $ticket_id, $limit ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$threads = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$threads[] = $this->normalize_thread_row( $row );
			}
		}

		/**
		 * Filter normalized SupportCandy ticket threads.
		 *
		 * @param array<int, array<string, mixed>> $threads   Normalized threads.
		 * @param int                             $ticket_id  Ticket ID.
		 * @param array<string, mixed>            $args       Query args.
		 */
		$threads = apply_filters( 'scai_supportcandy_ticket_threads', $threads, $ticket_id, $args );

		return is_array( $threads ) ? $this->sanitize_output_data( $threads ) : array();
	}

	/**
	 * Get normalized ticket attachments.
	 *
	 * @param int                  $ticket_id Ticket ID.
	 * @param array<string, mixed> $args      Query args.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_ticket_attachments( $ticket_id, array $args = array() ) {
		$ticket_id = absint( $ticket_id );

		if ( 0 === $ticket_id || ! $this->is_available() ) {
			return array();
		}

		$attachments_table = $this->get_table_name( 'attachments' );

		if ( '' === $attachments_table || ! $this->table_exists( $attachments_table ) ) {
			return array();
		}

		if ( ! $this->table_has_column( $attachments_table, 'id' ) || ! $this->table_has_column( $attachments_table, 'ticket_id' ) ) {
			return array();
		}

		$args      = wp_parse_args(
			$args,
			array(
				'limit'     => 50,
				'thread_id' => 0,
				'active'    => true,
			)
		);
		$limit     = min( 100, max( 1, absint( $args['limit'] ) ) );
		$thread_id = absint( $args['thread_id'] );
		$where     = 'WHERE a.ticket_id = %d';
		$values    = array( $ticket_id );

		if ( $thread_id > 0 && $this->table_has_column( $attachments_table, 'source_id' ) ) {
			$where   .= ' AND a.source_id = %d';
			$values[] = $thread_id;
		}

		if ( ! empty( $args['active'] ) && $this->table_has_column( $attachments_table, 'is_active' ) ) {
			$where .= ' AND a.is_active = 1';
		}

		$values[] = $limit;
		$order_by = $this->table_has_column( $attachments_table, 'date_created' ) ? 'a.date_created ASC, a.id ASC' : 'a.id ASC';

		$sql = "
			SELECT a.*
			FROM `{$attachments_table}` a
			{$where}
			ORDER BY {$order_by}
			LIMIT %d
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is sanitized and schema-controlled.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $values ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$attachments = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$attachments[] = $this->normalize_attachment_row( $row );
			}
		}

		/**
		 * Filter normalized SupportCandy ticket attachments.
		 *
		 * @param array<int, array<string, mixed>> $attachments Normalized attachments.
		 * @param int                             $ticket_id    Ticket ID.
		 * @param array<string, mixed>            $args         Query args.
		 */
		$attachments = apply_filters( 'scai_supportcandy_ticket_attachments', $attachments, $ticket_id, $args );

		return is_array( $attachments ) ? $this->sanitize_output_data( $attachments ) : array();
	}

	/**
	 * Get normalized ticket context.
	 *
	 * @param int                  $ticket_id Ticket ID.
	 * @param array<string, mixed> $args      Context args.
	 * @return array<string, mixed>
	 */
	public function get_ticket_context( $ticket_id, array $args = array() ) {
		$ticket_id = absint( $ticket_id );

		if ( 0 === $ticket_id ) {
			return array();
		}

		$ticket = $this->get_ticket( $ticket_id );

		if ( empty( $ticket ) ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'include_attachments' => true,
				'thread_limit'        => self::DEFAULT_MAX_THREADS,
			)
		);

		$context = array(
			'ticket'      => $ticket,
			'threads'     => $this->get_ticket_threads(
				$ticket_id,
				array(
					'limit' => absint( $args['thread_limit'] ),
				)
			),
			'attachments' => ! empty( $args['include_attachments'] ) ? $this->get_ticket_attachments( $ticket_id ) : array(),
		);

		/**
		 * Filter normalized SupportCandy ticket context.
		 *
		 * @param array<string, mixed> $context   Normalized context.
		 * @param int                  $ticket_id Ticket ID.
		 * @param array<string, mixed> $args      Context args.
		 */
		$context = apply_filters( 'scai_supportcandy_ticket_context', $context, $ticket_id, $args );

		return is_array( $context ) ? $this->sanitize_output_data( $context ) : array();
	}

	/**
	 * Detect whether SupportCandy appears active.
	 *
	 * @return bool
	 */
	private function is_supportcandy_active() {
		if ( defined( 'WPSC_VERSION' ) || class_exists( 'WPSC_Ticket' ) || class_exists( 'WPSC_Installation' ) ) {
			return true;
		}

		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'supportcandy/supportcandy.php' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get SupportCandy table names.
	 *
	 * @return array<string, string>
	 */
	private function get_table_names() {
		$tables = array(
			'tickets'          => $this->wpdb->prefix . 'psmsc_tickets',
			'archived_tickets' => $this->wpdb->prefix . 'psmsc_archived_tickets',
			'threads'          => $this->wpdb->prefix . 'psmsc_threads',
			'archived_threads' => $this->wpdb->prefix . 'psmsc_archived_threads',
			'customers'        => $this->wpdb->prefix . 'psmsc_customers',
			'statuses'         => $this->wpdb->prefix . 'psmsc_statuses',
			'categories'       => $this->wpdb->prefix . 'psmsc_categories',
			'priorities'       => $this->wpdb->prefix . 'psmsc_priorities',
			'attachments'      => $this->wpdb->prefix . 'psmsc_attachments',
		);

		/**
		 * Filter SupportCandy table names used by the adapter.
		 *
		 * @param array<string, string> $tables Table names.
		 */
		$tables = apply_filters( 'scai_supportcandy_table_names', $tables );

		if ( ! is_array( $tables ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $tables as $key => $table_name ) {
			$key = sanitize_key( $key );

			if ( '' === $key ) {
				continue;
			}

			$sanitized[ $key ] = $this->sanitize_identifier( $table_name );
		}

		return $sanitized;
	}

	/**
	 * Get one SupportCandy table name.
	 *
	 * @param string $key Table key.
	 * @return string
	 */
	private function get_table_name( $key ) {
		$key    = sanitize_key( $key );
		$tables = $this->get_table_names();

		return isset( $tables[ $key ] ) ? $tables[ $key ] : '';
	}

	/**
	 * Check whether a table exists.
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	private function table_exists( $table_name ) {
		$table_name = $this->sanitize_identifier( $table_name );

		if ( '' === $table_name ) {
			return false;
		}

		$like = $this->wpdb->esc_like( $table_name );

		return $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) === $table_name;
	}

	/**
	 * Check whether a table column exists.
	 *
	 * @param string $table_name  Table name.
	 * @param string $column_name Column name.
	 * @return bool
	 */
	private function table_has_column( $table_name, $column_name ) {
		$table_name  = $this->sanitize_identifier( $table_name );
		$column_name = $this->sanitize_identifier( $column_name );

		if ( '' === $table_name || '' === $column_name || ! $this->table_exists( $table_name ) ) {
			return false;
		}

		$sql = "SHOW COLUMNS FROM `{$table_name}` LIKE %s";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is sanitized before interpolation.
		return $this->wpdb->get_var( $this->wpdb->prepare( $sql, $column_name ) ) === $column_name;
	}

	/**
	 * Check whether a table column exists.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private function column_exists( $table, $column ) {
		return $this->table_has_column( $table, $column );
	}

	/**
	 * Check whether a ticket exists by internal ID.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return bool
	 */
	private function ticket_exists( $ticket_id ) {
		$ticket_id = absint( $ticket_id );

		if ( 0 === $ticket_id ) {
			return false;
		}

		$tickets_table = $this->get_table_name( 'tickets' );

		if ( '' === $tickets_table || ! $this->table_exists( $tickets_table ) || ! $this->column_exists( $tickets_table, 'id' ) ) {
			return false;
		}

		$sql = "SELECT id FROM `{$tickets_table}` WHERE id = %d LIMIT 1";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is sanitized and schema-controlled.
		return absint( $this->wpdb->get_var( $this->wpdb->prepare( $sql, $ticket_id ) ) ) === $ticket_id;
	}

	/**
	 * Get ticket table columns.
	 *
	 * @return array<int, string>
	 */
	private function get_ticket_table_columns() {
		$tickets_table = $this->get_table_name( 'tickets' );

		if ( '' === $tickets_table || ! $this->table_exists( $tickets_table ) ) {
			return array();
		}

		$sql = "SHOW COLUMNS FROM `{$tickets_table}`";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is sanitized and schema-controlled.
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$columns = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['Field'] ) ) {
				continue;
			}

			$column = $this->sanitize_identifier( $row['Field'] );

			if ( '' !== $column ) {
				$columns[] = $column;
			}
		}

		return array_values( array_unique( $columns ) );
	}

	/**
	 * Resolve a public/display identifier to an internal ticket ID.
	 *
	 * @param string $identifier Public/display identifier.
	 * @return int
	 */
	private function resolve_ticket_id_by_public_identifier( $identifier ) {
		$identifier = trim( sanitize_text_field( (string) $identifier ) );

		if ( '' === $identifier ) {
			return 0;
		}

		$resolved_id = $this->resolve_ticket_id_by_identifier_columns( $identifier );

		if ( $resolved_id > 0 ) {
			return $resolved_id;
		}

		return $this->resolve_ticket_id_by_misc_identifier( $identifier );
	}

	/**
	 * Resolve a ticket ID by configured identifier columns.
	 *
	 * @param string $identifier Public/display identifier.
	 * @return int
	 */
	private function resolve_ticket_id_by_identifier_columns( $identifier ) {
		$tickets_table = $this->get_table_name( 'tickets' );

		if ( '' === $tickets_table || ! $this->table_exists( $tickets_table ) || ! $this->column_exists( $tickets_table, 'id' ) ) {
			return 0;
		}

		$columns = $this->get_existing_ticket_identifier_columns();
		$values  = $this->get_identifier_lookup_values( $identifier );

		if ( empty( $columns ) || empty( $values ) ) {
			return 0;
		}

		foreach ( $columns as $column ) {
			foreach ( $values as $value ) {
				$sql = "SELECT id FROM `{$tickets_table}` WHERE `{$column}` = %s LIMIT 1";

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names are sanitized and schema-checked.
				$ticket_id = absint( $this->wpdb->get_var( $this->wpdb->prepare( $sql, $value ) ) );

				if ( $ticket_id > 0 && $this->ticket_exists( $ticket_id ) ) {
					return $ticket_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Resolve a ticket ID by identifier values stored in the misc JSON column.
	 *
	 * @param string $identifier Public/display identifier.
	 * @return int
	 */
	private function resolve_ticket_id_by_misc_identifier( $identifier ) {
		$tickets_table = $this->get_table_name( 'tickets' );

		if ( '' === $tickets_table || ! $this->table_exists( $tickets_table ) || ! $this->column_exists( $tickets_table, 'id' ) || ! $this->column_exists( $tickets_table, 'misc' ) ) {
			return 0;
		}

		$values = $this->get_identifier_lookup_values( $identifier );

		if ( empty( $values ) ) {
			return 0;
		}

		foreach ( $values as $value ) {
			$like = '%' . $this->wpdb->esc_like( $value ) . '%';
			$sql  = "SELECT id, misc FROM `{$tickets_table}` WHERE misc LIKE %s LIMIT 25";

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is sanitized and schema-controlled.
			$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $like ), ARRAY_A );

			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || empty( $row['misc'] ) ) {
					continue;
				}

				$ticket_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;

				if ( $ticket_id > 0 && $this->misc_contains_identifier( $row['misc'], $values ) && $this->ticket_exists( $ticket_id ) ) {
					return $ticket_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Get existing ticket identifier columns.
	 *
	 * @return array<int, string>
	 */
	private function get_existing_ticket_identifier_columns() {
		$tickets_table = $this->get_table_name( 'tickets' );
		$columns       = $this->get_ticket_table_columns();

		if ( empty( $columns ) ) {
			return array();
		}

		$candidate_columns = array(
			'ticket',
			'ticket_id',
			'ticket_number',
			'number',
			'uid',
			'auth_code',
			'customer_ticket_id',
			'reference',
		);

		/**
		 * Filter ticket table columns that may contain public/display identifiers.
		 *
		 * @param array<int, string> $candidate_columns Candidate column names.
		 */
		$candidate_columns = apply_filters( 'scai_supportcandy_ticket_identifier_columns', $candidate_columns );

		if ( ! is_array( $candidate_columns ) ) {
			return array();
		}

		$existing_columns = array();

		foreach ( $candidate_columns as $column ) {
			$column = $this->sanitize_identifier( $column );

			if ( '' === $column || 'id' === $column || ! in_array( $column, $columns, true ) || ! $this->column_exists( $tickets_table, $column ) ) {
				continue;
			}

			$existing_columns[] = $column;
		}

		return array_values( array_unique( $existing_columns ) );
	}

	/**
	 * Build lookup values for an identifier.
	 *
	 * @param string $identifier Raw sanitized identifier.
	 * @return array<int, string>
	 */
	private function get_identifier_lookup_values( $identifier ) {
		$identifier = trim( sanitize_text_field( (string) $identifier ) );

		if ( '' === $identifier ) {
			return array();
		}

		$values = array( $identifier );
		$digits = preg_replace( '/\D+/', '', $identifier );

		if ( is_string( $digits ) && '' !== $digits ) {
			$values[] = $digits;
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $values ) ) ) );
	}

	/**
	 * Check whether misc JSON contains one of the identifier values.
	 *
	 * @param mixed              $misc   Raw misc value.
	 * @param array<int, string> $values Lookup values.
	 * @return bool
	 */
	private function misc_contains_identifier( $misc, array $values ) {
		$decoded = json_decode( (string) $misc, true );

		if ( ! is_array( $decoded ) ) {
			return false;
		}

		return $this->array_contains_identifier_value( $decoded, $values );
	}

	/**
	 * Recursively check an array for identifier values.
	 *
	 * @param array<mixed>       $data   Data to inspect.
	 * @param array<int, string> $values Lookup values.
	 * @return bool
	 */
	private function array_contains_identifier_value( array $data, array $values ) {
		foreach ( $data as $value ) {
			if ( is_array( $value ) && $this->array_contains_identifier_value( $value, $values ) ) {
				return true;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			if ( in_array( sanitize_text_field( (string) $value ), $values, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a required table has the columns needed for reads.
	 *
	 * @param string $key        Table key.
	 * @param string $table_name Table name.
	 * @return bool
	 */
	private function is_required_table_readable( $key, $table_name ) {
		$key = sanitize_key( $key );

		if ( ! $this->table_exists( $table_name ) ) {
			return false;
		}

		if ( 'tickets' === $key ) {
			return $this->table_has_column( $table_name, 'id' );
		}

		if ( 'threads' === $key ) {
			return $this->table_has_column( $table_name, 'id' ) && $this->table_has_column( $table_name, 'ticket' );
		}

		return true;
	}

	/**
	 * Normalize a ticket row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function normalize_ticket_row( array $row ) {
		return array(
			'id'             => isset( $row['id'] ) ? absint( $row['id'] ) : 0,
			'subject'        => isset( $row['subject'] ) ? sanitize_text_field( $row['subject'] ) : '',
			'status'         => isset( $row['status_name'] ) ? sanitize_text_field( $row['status_name'] ) : '',
			'category'       => isset( $row['category_name'] ) ? sanitize_text_field( $row['category_name'] ) : '',
			'priority'       => isset( $row['priority_name'] ) ? sanitize_text_field( $row['priority_name'] ) : '',
			'customer_name'  => isset( $row['customer_name'] ) ? sanitize_text_field( $row['customer_name'] ) : '',
			'customer_email' => isset( $row['customer_email'] ) ? sanitize_email( $row['customer_email'] ) : '',
			'created_at'     => isset( $row['date_created'] ) ? sanitize_text_field( $row['date_created'] ) : '',
			'updated_at'     => isset( $row['date_updated'] ) ? sanitize_text_field( $row['date_updated'] ) : '',
			'raw'            => $this->sanitize_raw_row( $row ),
		);
	}

	/**
	 * Normalize a thread row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function normalize_thread_row( array $row ) {
		$attachment_ids = $this->parse_attachment_ids( isset( $row['attachments'] ) ? $row['attachments'] : '' );

		return array(
			'id'           => isset( $row['id'] ) ? absint( $row['id'] ) : 0,
			'ticket_id'    => isset( $row['ticket'] ) ? absint( $row['ticket'] ) : 0,
			'type'         => isset( $row['type'] ) ? sanitize_key( $row['type'] ) : '',
			'author_name'  => isset( $row['author_name'] ) ? sanitize_text_field( $row['author_name'] ) : '',
			'author_email' => isset( $row['author_email'] ) ? sanitize_email( $row['author_email'] ) : '',
			'body'         => isset( $row['body'] ) ? wp_kses_post( $row['body'] ) : '',
			'created_at'   => isset( $row['date_created'] ) ? sanitize_text_field( $row['date_created'] ) : '',
			'attachments'  => $this->get_attachments_by_ids( $attachment_ids ),
			'raw'          => $this->sanitize_raw_row( $row ),
		);
	}

	/**
	 * Normalize an attachment row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function normalize_attachment_row( array $row ) {
		$filename = isset( $row['name'] ) ? sanitize_file_name( $row['name'] ) : '';
		$filetype = wp_check_filetype( $filename );

		return array(
			'id'         => isset( $row['id'] ) ? absint( $row['id'] ) : 0,
			'ticket_id'  => isset( $row['ticket_id'] ) ? absint( $row['ticket_id'] ) : 0,
			'thread_id'  => isset( $row['source_id'] ) ? absint( $row['source_id'] ) : 0,
			'filename'   => $filename,
			'mime_type'  => ! empty( $filetype['type'] ) ? sanitize_mime_type( $filetype['type'] ) : '',
			'url'        => esc_url_raw(
				add_query_arg(
					array(
						'wpsc_attachment' => isset( $row['id'] ) ? absint( $row['id'] ) : 0,
					),
					home_url( '/' )
				)
			),
			'size'       => 0,
			'created_at' => isset( $row['date_created'] ) ? sanitize_text_field( $row['date_created'] ) : '',
			'raw'        => $this->sanitize_raw_row( $row ),
		);
	}

	/**
	 * Get attachment rows by IDs.
	 *
	 * @param array<int, int> $attachment_ids Attachment IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_attachments_by_ids( array $attachment_ids ) {
		$attachment_ids = array_values( array_filter( array_map( 'absint', $attachment_ids ) ) );

		if ( empty( $attachment_ids ) ) {
			return array();
		}

		$attachments_table = $this->get_table_name( 'attachments' );

		if ( '' === $attachments_table || ! $this->table_exists( $attachments_table ) ) {
			return array();
		}

		if ( ! $this->table_has_column( $attachments_table, 'id' ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $attachment_ids ), '%d' ) );
		$where        = "WHERE id IN ({$placeholders})";
		$order_by     = 'id ASC';

		if ( $this->table_has_column( $attachments_table, 'is_active' ) ) {
			$where .= ' AND is_active = 1';
		}

		if ( $this->table_has_column( $attachments_table, 'date_created' ) ) {
			$order_by = 'date_created ASC, id ASC';
		}

		$sql = "SELECT * FROM `{$attachments_table}` {$where} ORDER BY {$order_by}";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is sanitized and placeholders are generated for IDs.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $attachment_ids ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$attachments = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$attachments[] = $this->normalize_attachment_row( $row );
			}
		}

		return $attachments;
	}

	/**
	 * Parse pipe-separated attachment IDs.
	 *
	 * @param mixed $value Raw attachment value.
	 * @return array<int, int>
	 */
	private function parse_attachment_ids( $value ) {
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'absint', $value ) ) );
		}

		$value = sanitize_text_field( (string) $value );

		if ( '' === $value ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', explode( '|', $value ) ) ) );
	}

	/**
	 * Sanitize a raw database row for diagnostics.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function sanitize_raw_row( array $row ) {
		$safe = array();

		foreach ( $row as $key => $value ) {
			$key = sanitize_key( $key );

			if ( '' === $key || $this->is_sensitive_raw_key( $key ) ) {
				continue;
			}

			if ( is_numeric( $value ) ) {
				$safe[ $key ] = 0 + $value;
				continue;
			}

			$safe[ $key ] = is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
		}

		return $safe;
	}

	/**
	 * Recursively sanitize public output data.
	 *
	 * @param array<mixed> $data Output data.
	 * @return array<mixed>
	 */
	private function sanitize_output_data( array $data ) {
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$clean_key = is_string( $key ) ? sanitize_key( $key ) : absint( $key );

			if ( is_array( $value ) ) {
				$sanitized[ $clean_key ] = $this->sanitize_output_data( $value );
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$sanitized[ $clean_key ] = $value;
				continue;
			}

			if ( 'email' === $clean_key || false !== strpos( $clean_key, 'email' ) ) {
				$sanitized[ $clean_key ] = sanitize_email( (string) $value );
				continue;
			}

			if ( 'url' === $clean_key || false !== strpos( $clean_key, 'url' ) ) {
				$sanitized[ $clean_key ] = esc_url_raw( (string) $value );
				continue;
			}

			if ( 'body' === $clean_key || 'content' === $clean_key ) {
				$sanitized[ $clean_key ] = wp_kses_post( (string) $value );
				continue;
			}

			$sanitized[ $clean_key ] = sanitize_textarea_field( (string) $value );
		}

		return $sanitized;
	}

	/**
	 * Determine whether a raw key should be omitted.
	 *
	 * @param string $key Row key.
	 * @return bool
	 */
	private function is_sensitive_raw_key( $key ) {
		return in_array(
			sanitize_key( $key ),
			array(
				'auth_code',
				'file_path',
				'ip_address',
			),
			true
		);
	}

	/**
	 * Sanitize a database identifier.
	 *
	 * @param mixed $identifier Identifier.
	 * @return string
	 */
	private function sanitize_identifier( $identifier ) {
		$identifier = (string) $identifier;
		$identifier = preg_replace( '/[^A-Za-z0-9_]/', '', $identifier );

		return is_string( $identifier ) ? $identifier : '';
	}
}
