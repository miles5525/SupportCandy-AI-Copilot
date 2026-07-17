<?php
/**
 * Custom Knowledge Base repository for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides scoped access to plugin-owned custom knowledge records.
 *
 * BetterDocs and other integration rows are intentionally outside this
 * repository's read and write boundary.
 */
final class SCAI_Custom_Knowledge_Repository {

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb|null
	 */
	private $wpdb = null;

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
	 * Check whether the custom knowledge table exists.
	 *
	 * @return bool
	 */
	public function table_exists() {
		$table_name = $this->get_table_name();

		if ( '' === $table_name || ! $this->wpdb ) {
			return false;
		}

		$like = $this->wpdb->esc_like( $table_name );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The table name is compared as a prepared value, not used as an identifier.
		$found = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return $found === $table_name;
	}

	/**
	 * Create a custom knowledge source.
	 *
	 * @param array<string, mixed> $data Source data.
	 * @return int|false Inserted row ID, or false on failure.
	 */
	public function create( array $data ) {
		if ( ! $this->table_exists() ) {
			return false;
		}

		$source_type = $this->sanitize_source_type( isset( $data['source_type'] ) ? $data['source_type'] : '' );
		$status      = $this->sanitize_status( isset( $data['status'] ) ? $data['status'] : 'active' );
		$title       = isset( $data['title'] ) && is_scalar( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';
		$content     = $this->sanitize_content( isset( $data['content'] ) ? $data['content'] : '' );

		if ( '' === $source_type || '' === $status || '' === $title ) {
			return false;
		}

		if ( '' === $content && ! in_array( $status, array( 'pending', 'error', 'unsupported' ), true ) ) {
			return false;
		}

		$now          = current_time( 'mysql', true );
		$source_key   = $this->sanitize_source_key( isset( $data['source_key'] ) ? $data['source_key'] : '' );
		$content_hash = $this->sanitize_content_hash( isset( $data['content_hash'] ) ? $data['content_hash'] : '' );
		$insert_data  = array(
			'source_type'   => $source_type,
			'source_key'    => '' !== $source_key ? $source_key : $this->generate_source_key(),
			'object_id'     => isset( $data['object_id'] ) ? absint( $data['object_id'] ) : 0,
			'title'         => $title,
			'source_url'    => $this->sanitize_source_url( isset( $data['source_url'] ) ? $data['source_url'] : '' ),
			'mime_type'     => $this->sanitize_mime_type( isset( $data['mime_type'] ) ? $data['mime_type'] : '' ),
			'content'       => $content,
			'content_hash'  => $content_hash,
			'metadata'      => $this->encode_metadata( isset( $data['metadata'] ) ? $data['metadata'] : array() ),
			'status'        => $status,
			'last_synced_at' => $this->sanitize_mysql_datetime( isset( $data['last_synced_at'] ) ? $data['last_synced_at'] : '', true ),
			'created_at'    => $now,
			'updated_at'    => $now,
		);

		$inserted = $this->wpdb->insert(
			$this->get_table_name(),
			$insert_data,
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false === $inserted ? false : (int) $this->wpdb->insert_id;
	}

	/**
	 * Update an existing custom knowledge source.
	 *
	 * @param int                  $id   Source ID.
	 * @param array<string, mixed> $data Source data.
	 * @return bool
	 */
	public function update( $id, array $data ) {
		$id = absint( $id );

		if ( 0 === $id || empty( $data ) || ! $this->table_exists() ) {
			return false;
		}

		$current = $this->get( $id );

		if ( ! $current ) {
			return false;
		}

		$fields  = array();
		$formats = array();

		if ( array_key_exists( 'title', $data ) ) {
			$title = is_scalar( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';

			if ( '' === $title ) {
				return false;
			}

			$fields['title'] = $title;
			$formats[]       = '%s';
		}

		if ( array_key_exists( 'source_url', $data ) ) {
			$fields['source_url'] = $this->sanitize_source_url( $data['source_url'] );
			$formats[]            = '%s';
		}

		if ( array_key_exists( 'mime_type', $data ) ) {
			$fields['mime_type'] = $this->sanitize_mime_type( $data['mime_type'] );
			$formats[]           = '%s';
		}

		if ( array_key_exists( 'content', $data ) ) {
			$fields['content'] = $this->sanitize_content( $data['content'] );
			$formats[]         = '%s';
		}

		if ( array_key_exists( 'content_hash', $data ) ) {
			$fields['content_hash'] = $this->sanitize_content_hash( $data['content_hash'] );
			$formats[]              = '%s';
		}

		if ( array_key_exists( 'metadata', $data ) ) {
			$fields['metadata'] = $this->encode_metadata( $data['metadata'] );
			$formats[]          = '%s';
		}

		if ( array_key_exists( 'status', $data ) ) {
			$status = $this->sanitize_status( $data['status'] );

			if ( '' === $status ) {
				return false;
			}

			$fields['status'] = $status;
			$formats[]        = '%s';
		}

		if ( array_key_exists( 'last_synced_at', $data ) ) {
			$fields['last_synced_at'] = $this->sanitize_mysql_datetime( $data['last_synced_at'], true );
			$formats[]                = '%s';
		}

		if ( empty( $fields ) ) {
			return false;
		}

		$effective_status  = isset( $fields['status'] ) ? $fields['status'] : $current['status'];
		$effective_content = isset( $fields['content'] ) ? $fields['content'] : $current['content'];

		if ( '' === $effective_content && in_array( $effective_status, array( 'active', 'disabled' ), true ) ) {
			return false;
		}

		$fields['updated_at'] = current_time( 'mysql', true );
		$formats[]             = '%s';
		$set_parts             = array();
		$values                = array();
		$index                 = 0;

		foreach ( $fields as $column => $value ) {
			$set_parts[] = "`{$column}` = " . $formats[ $index ];
			$values[]    = $value;
			++$index;
		}

		$type_placeholders = implode( ', ', array_fill( 0, count( $this->get_allowed_source_types() ), '%s' ) );
		$sql               = 'UPDATE `' . $this->get_table_name() . '` SET ' . implode( ', ', $set_parts ) . " WHERE `id` = %d AND `source_type` IN ({$type_placeholders})";
		$values[]          = $id;
		$values            = array_merge( $values, $this->get_allowed_source_types() );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table/columns and placeholders come from fixed repository allowlists; all values are prepared.
		$result = $this->wpdb->query( $this->wpdb->prepare( $sql, $values ) );

		return false !== $result;
	}

	/**
	 * Get one custom knowledge source.
	 *
	 * @param int $id Source ID.
	 * @return array<string, mixed>|null
	 */
	public function get( $id ) {
		$id = absint( $id );

		if ( 0 === $id || ! $this->table_exists() ) {
			return null;
		}

		$type_placeholders = implode( ', ', array_fill( 0, count( $this->get_allowed_source_types() ), '%s' ) );
		$sql               = 'SELECT * FROM `' . $this->get_table_name() . "` WHERE `id` = %d AND `source_type` IN ({$type_placeholders}) LIMIT 1";
		$values            = array_merge( array( $id ), $this->get_allowed_source_types() );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is schema-controlled; all values are prepared.
		$row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, $values ), ARRAY_A );

		return is_array( $row ) ? $this->normalize_row( $row ) : null;
	}

	/**
	 * Delete one custom knowledge source.
	 *
	 * @param int $id Source ID.
	 * @return bool
	 */
	public function delete( $id ) {
		$id = absint( $id );

		if ( 0 === $id || ! $this->table_exists() ) {
			return false;
		}

		$type_placeholders = implode( ', ', array_fill( 0, count( $this->get_allowed_source_types() ), '%s' ) );
		$sql               = 'DELETE FROM `' . $this->get_table_name() . "` WHERE `id` = %d AND `source_type` IN ({$type_placeholders})";
		$values            = array_merge( array( $id ), $this->get_allowed_source_types() );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is schema-controlled; all values are prepared.
		$result = $this->wpdb->query( $this->wpdb->prepare( $sql, $values ) );

		return false !== $result;
	}

	/**
	 * Update a custom source status and merge safe metadata.
	 *
	 * @param int                  $id               Source ID.
	 * @param string               $status           New status.
	 * @param array<string, mixed> $metadata_updates Metadata values to merge.
	 * @return bool
	 */
	public function update_status( $id, $status, array $metadata_updates = array() ) {
		$status = $this->sanitize_status( $status );
		$row    = $this->get( $id );

		if ( '' === $status || ! $row ) {
			return false;
		}

		$metadata = $this->normalize_metadata( array_merge( $row['metadata'], $metadata_updates ) );

		return $this->update(
			$id,
			array(
				'status'   => $status,
				'metadata' => $metadata,
			)
		);
	}

	/**
	 * List custom knowledge sources.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<int, array<string, mixed>>
	 */
	public function list( array $args = array() ) {
		if ( ! $this->table_exists() ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'status'      => '',
				'source_type' => '',
				'search'      => '',
				'per_page'    => 20,
				'page'        => 1,
				'orderby'     => 'updated_at',
				'order'       => 'DESC',
			)
		);

		$allowed_orderby = array( 'id', 'title', 'source_type', 'status', 'updated_at', 'created_at', 'last_synced_at' );
		$requested_orderby = is_scalar( $args['orderby'] ) ? (string) $args['orderby'] : '';
		$requested_order   = is_scalar( $args['order'] ) ? (string) $args['order'] : '';
		$orderby            = in_array( $requested_orderby, $allowed_orderby, true ) ? $requested_orderby : 'updated_at';
		$order              = 'ASC' === strtoupper( $requested_order ) ? 'ASC' : 'DESC';
		$per_page        = min( 100, max( 1, absint( $args['per_page'] ) ) );
		$page            = max( 1, absint( $args['page'] ) );
		$where           = array();
		$values          = array();
		$type_values     = $this->get_allowed_source_types();

		$raw_source_type = is_scalar( $args['source_type'] ) ? (string) $args['source_type'] : '';
		$source_type     = $this->sanitize_source_type( $raw_source_type );
		if ( '' !== $raw_source_type && '' === $source_type ) {
			return array();
		}
		if ( '' !== $source_type ) {
			$where[]   = '`source_type` = %s';
			$values[]  = $source_type;
		} else {
			$where[] = '`source_type` IN (' . implode( ', ', array_fill( 0, count( $type_values ), '%s' ) ) . ')';
			$values  = array_merge( $values, $type_values );
		}

		$raw_status = is_scalar( $args['status'] ) ? (string) $args['status'] : '';
		$status     = $this->sanitize_status( $raw_status );
		if ( '' !== $raw_status && '' === $status ) {
			return array();
		}
		if ( '' !== $status ) {
			$where[]  = '`status` = %s';
			$values[] = $status;
		}

		$search = is_scalar( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			$like     = '%' . $this->wpdb->esc_like( $search ) . '%';
			$where[]  = '(`title` LIKE %s OR `content` LIKE %s OR `source_url` LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$values[] = $per_page;
		$values[] = ( $page - 1 ) * $per_page;
		$sql      = 'SELECT * FROM `' . $this->get_table_name() . '` WHERE ' . implode( ' AND ', $where ) . " ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table/order identifiers are schema-controlled/allowlisted; all values are prepared.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $values ), ARRAY_A );

		return $this->normalize_rows( $rows );
	}

	/**
	 * Count custom sources grouped by status.
	 *
	 * @return array<string, int>
	 */
	public function count_by_status() {
		$counts = array(
			'active'      => 0,
			'disabled'    => 0,
			'pending'     => 0,
			'error'       => 0,
			'unsupported' => 0,
			'total'       => 0,
		);

		if ( ! $this->table_exists() ) {
			return $counts;
		}

		$types        = $this->get_allowed_source_types();
		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$sql          = 'SELECT `status`, COUNT(*) AS `source_count` FROM `' . $this->get_table_name() . "` WHERE `source_type` IN ({$placeholders}) GROUP BY `status`";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is schema-controlled and source types are prepared.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $types ), ARRAY_A );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$status = $this->sanitize_status( isset( $row['status'] ) ? $row['status'] : '' );

			if ( '' !== $status ) {
				$counts[ $status ] = absint( isset( $row['source_count'] ) ? $row['source_count'] : 0 );
			}
		}

		foreach ( $this->get_allowed_statuses() as $status ) {
			$counts['total'] += $counts[ $status ];
		}

		return $counts;
	}

	/**
	 * Count custom sources grouped by allow-listed source type.
	 *
	 * @return array<string, int>
	 */
	public function count_by_source_type() {
		$counts = array_fill_keys( $this->get_allowed_source_types(), 0 );

		if ( ! $this->table_exists() ) {
			return $counts;
		}

		$types        = $this->get_allowed_source_types();
		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$sql          = 'SELECT `source_type`, COUNT(*) AS `source_count` FROM `' . $this->get_table_name() . "` WHERE `source_type` IN ({$placeholders}) GROUP BY `source_type`";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is schema-controlled and source types are prepared.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $types ), ARRAY_A );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$source_type = $this->sanitize_source_type( isset( $row['source_type'] ) ? $row['source_type'] : '' );

			if ( '' !== $source_type ) {
				$counts[ $source_type ] = absint( isset( $row['source_count'] ) ? $row['source_count'] : 0 );
			}
		}

		return $counts;
	}

	/**
	 * Get a bounded set of active custom source candidates.
	 *
	 * @param array<string, mixed> $args Candidate query arguments.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_active_candidates( array $args = array() ) {
		if ( ! $this->table_exists() ) {
			return array();
		}

		$args  = wp_parse_args( $args, array( 'search_terms' => array(), 'limit' => 20 ) );
		$limit = min( 50, max( 1, absint( $args['limit'] ) ) );
		$types = $this->get_allowed_source_types();
		$where = array(
			'`source_type` IN (' . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ')',
			'`status` = %s',
		);
		$values = array_merge( $types, array( 'active' ) );
		$terms  = isset( $args['search_terms'] ) && is_array( $args['search_terms'] ) ? array_slice( $args['search_terms'], 0, 10 ) : array();

		foreach ( $terms as $term ) {
			$term = is_scalar( $term ) ? sanitize_text_field( (string) $term ) : '';
			$term = $this->substring( trim( $term ), 0, 100 );

			if ( '' === $term ) {
				continue;
			}

			$like     = '%' . $this->wpdb->esc_like( $term ) . '%';
			$where[]  = '(`title` LIKE %s OR `content` LIKE %s OR `source_url` LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$values[] = $limit;
		$sql      = 'SELECT * FROM `' . $this->get_table_name() . '` WHERE ' . implode( ' AND ', $where ) . ' ORDER BY `updated_at` DESC, `id` DESC LIMIT %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is schema-controlled; all query values are prepared.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $values ), ARRAY_A );

		return $this->normalize_rows( $rows );
	}

	/**
	 * Resolve the knowledge table name safely.
	 *
	 * @return string
	 */
	protected function get_table_name() {
		if ( class_exists( 'SCAI_Schema' ) && is_callable( array( 'SCAI_Schema', 'get_table_name' ) ) ) {
			$table_name = SCAI_Schema::get_table_name( 'knowledge' );
		} elseif ( $this->wpdb && isset( $this->wpdb->prefix ) ) {
			$table_name = $this->wpdb->prefix . 'scai_knowledge';
		} else {
			$table_name = '';
		}

		$table_name = is_string( $table_name ) ? preg_replace( '/[^A-Za-z0-9_]/', '', $table_name ) : '';

		return is_string( $table_name ) ? $table_name : '';
	}

	/**
	 * Normalize one repository row.
	 *
	 * @param mixed $row Database row.
	 * @return array<string, mixed>
	 */
	protected function normalize_row( $row ) {
		if ( is_object( $row ) ) {
			$row = get_object_vars( $row );
		}

		if ( ! is_array( $row ) ) {
			return array();
		}

		$source_type = $this->sanitize_source_type( isset( $row['source_type'] ) ? $row['source_type'] : '' );

		if ( '' === $source_type ) {
			return array();
		}

		return array(
			'id'             => isset( $row['id'] ) ? absint( $row['id'] ) : 0,
			'source_type'    => $source_type,
			'source_key'     => $this->sanitize_source_key( isset( $row['source_key'] ) ? $row['source_key'] : '' ),
			'object_id'      => isset( $row['object_id'] ) ? absint( $row['object_id'] ) : 0,
			'title'          => isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '',
			'source_url'     => $this->sanitize_source_url( isset( $row['source_url'] ) ? $row['source_url'] : '' ),
			'mime_type'      => $this->sanitize_mime_type( isset( $row['mime_type'] ) ? $row['mime_type'] : '' ),
			'content'        => $this->sanitize_content( isset( $row['content'] ) ? $row['content'] : '' ),
			'content_hash'   => $this->sanitize_content_hash( isset( $row['content_hash'] ) ? $row['content_hash'] : '' ),
			'metadata'       => $this->normalize_metadata( isset( $row['metadata'] ) ? $row['metadata'] : array() ),
			'status'         => $this->sanitize_status( isset( $row['status'] ) ? $row['status'] : '' ),
			'last_synced_at' => $this->sanitize_mysql_datetime( isset( $row['last_synced_at'] ) ? $row['last_synced_at'] : '', true ),
			'created_at'     => $this->sanitize_mysql_datetime( isset( $row['created_at'] ) ? $row['created_at'] : '', true ),
			'updated_at'     => $this->sanitize_mysql_datetime( isset( $row['updated_at'] ) ? $row['updated_at'] : '', true ),
		);
	}

	/**
	 * Decode and sanitize a metadata object.
	 *
	 * @param mixed $metadata Raw or encoded metadata.
	 * @return array<string, mixed>
	 */
	protected function normalize_metadata( $metadata ) {
		if ( is_string( $metadata ) && '' !== $metadata ) {
			$metadata = json_decode( $metadata, true );
		}

		if ( ! is_array( $metadata ) || $this->is_list_array( $metadata ) ) {
			return array();
		}

		return $this->sanitize_metadata_array( $metadata );
	}

	/**
	 * Encode a safe metadata object.
	 *
	 * @param mixed $metadata Metadata value.
	 * @return string
	 */
	protected function encode_metadata( $metadata ) {
		$metadata = $this->normalize_metadata( $metadata );

		if ( empty( $metadata ) ) {
			return '{}';
		}

		$encoded = wp_json_encode( $metadata );

		return is_string( $encoded ) ? $encoded : '{}';
	}

	/**
	 * Sanitize a custom source type.
	 *
	 * @param mixed $source_type Source type.
	 * @return string
	 */
	protected function sanitize_source_type( $source_type ) {
		$source_type = is_scalar( $source_type ) ? sanitize_key( (string) $source_type ) : '';

		return in_array( $source_type, $this->get_allowed_source_types(), true ) ? $source_type : '';
	}

	/**
	 * Sanitize a source status.
	 *
	 * @param mixed $status Status.
	 * @return string
	 */
	protected function sanitize_status( $status ) {
		$status = is_scalar( $status ) ? sanitize_key( (string) $status ) : '';

		return in_array( $status, $this->get_allowed_statuses(), true ) ? $status : '';
	}

	/**
	 * Normalize source content to plain text.
	 *
	 * @param mixed $content Source content.
	 * @return string
	 */
	protected function sanitize_content( $content ) {
		if ( ! is_scalar( $content ) ) {
			return '';
		}

		$content = strip_shortcodes( (string) $content );
		$content = wp_strip_all_tags( $content, true );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
		$content = str_replace( array( "\r\n", "\r" ), "\n", $content );
		$content = preg_replace( '/[^\P{C}\n\t]/u', '', $content );
		$content = preg_replace( '/[ \t]+/u', ' ', is_string( $content ) ? $content : '' );
		$content = preg_replace( "/\n{3,}/", "\n\n", is_string( $content ) ? $content : '' );

		return is_string( $content ) ? trim( $content ) : '';
	}

	/**
	 * Generate a source identifier.
	 *
	 * @return string
	 */
	protected function generate_source_key() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			$random = wp_generate_uuid4();
		} else {
			try {
				$random = bin2hex( random_bytes( 16 ) );
			} catch ( Throwable $exception ) {
				$random = hash( 'sha256', uniqid( 'scai_', true ) . microtime( true ) );
			}
		}

		$key    = 'scai_' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $random );

		return substr( $key, 0, 64 );
	}

	/**
	 * Get allowed custom source types.
	 *
	 * @return array<int, string>
	 */
	protected function get_allowed_source_types() {
		return array( 'manual', 'url', 'file' );
	}

	/**
	 * Get allowed custom source statuses.
	 *
	 * @return array<int, string>
	 */
	protected function get_allowed_statuses() {
		return array( 'active', 'disabled', 'pending', 'error', 'unsupported' );
	}

	/**
	 * Normalize multiple rows and exclude invalid records.
	 *
	 * @param mixed $rows Database rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_rows( $rows ) {
		$normalized = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row = $this->normalize_row( $row );

			if ( ! empty( $row ) ) {
				$normalized[] = $row;
			}
		}

		return $normalized;
	}

	/**
	 * Recursively sanitize metadata and remove sensitive/path values.
	 *
	 * @param array<mixed> $metadata Metadata values.
	 * @return array<mixed>
	 */
	private function sanitize_metadata_array( array $metadata ) {
		$clean = array();

		foreach ( $metadata as $key => $value ) {
			$clean_key = is_string( $key ) ? sanitize_key( $key ) : absint( $key );

			if ( is_string( $clean_key ) && ( '' === $clean_key || $this->is_sensitive_metadata_key( $clean_key ) ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $clean_key ] = $this->sanitize_metadata_array( $value );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$clean[ $clean_key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$value = sanitize_textarea_field( (string) $value );

				if ( ! $this->looks_like_local_path( $value ) ) {
					$clean[ $clean_key ] = $value;
				}
			}
		}

		return $clean;
	}

	/**
	 * Check whether a metadata key may contain secrets or local paths.
	 *
	 * @param string $key Metadata key.
	 * @return bool
	 */
	private function is_sensitive_metadata_key( $key ) {
		$key = sanitize_key( $key );

		foreach ( array( 'api_key', 'authorization', 'password', 'secret', 'token', 'access_token', 'refresh_token', 'provider_config', 'path' ) as $fragment ) {
			if ( false !== strpos( $key, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect common absolute local path shapes.
	 *
	 * @param string $value Metadata value.
	 * @return bool
	 */
	private function looks_like_local_path( $value ) {
		return 1 === preg_match( '#^[A-Za-z]:[\\\\/]#', $value )
			|| 1 === preg_match( '#^\\\\\\\\[^\\\\]+\\\\#', $value )
			|| ( 0 === strpos( $value, '/' ) && 0 !== strpos( $value, '//' ) );
	}

	/**
	 * Determine whether an array is a list.
	 *
	 * @param array<mixed> $value Array value.
	 * @return bool
	 */
	private function is_list_array( array $value ) {
		if ( empty( $value ) ) {
			return false;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Sanitize a source key.
	 *
	 * @param mixed $key Source key.
	 * @return string
	 */
	private function sanitize_source_key( $key ) {
		$key = is_scalar( $key ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $key ) : '';

		return is_string( $key ) ? substr( $key, 0, 64 ) : '';
	}

	/**
	 * Sanitize a source URL.
	 *
	 * @param mixed $url Source URL.
	 * @return string
	 */
	private function sanitize_source_url( $url ) {
		$url = is_scalar( $url ) ? esc_url_raw( (string) $url, array( 'http', 'https' ) ) : '';

		return $this->substring( $url, 0, 2048 );
	}

	/**
	 * Sanitize a MIME type.
	 *
	 * @param mixed $mime_type MIME type.
	 * @return string
	 */
	private function sanitize_mime_type( $mime_type ) {
		$mime_type = is_scalar( $mime_type ) ? strtolower( sanitize_text_field( (string) $mime_type ) ) : '';
		$mime_type = preg_replace( '~[^a-z0-9!#$&^_.+\-/]~', '', $mime_type );

		return is_string( $mime_type ) ? substr( $mime_type, 0, 100 ) : '';
	}

	/**
	 * Sanitize a content hash.
	 *
	 * @param mixed $hash Content hash.
	 * @return string
	 */
	private function sanitize_content_hash( $hash ) {
		$hash = is_scalar( $hash ) ? strtolower( (string) $hash ) : '';
		$hash = preg_replace( '/[^a-f0-9]/', '', $hash );

		return is_string( $hash ) ? substr( $hash, 0, 64 ) : '';
	}

	/**
	 * Sanitize a MySQL UTC datetime.
	 *
	 * @param mixed $date        Date value.
	 * @param bool  $allow_empty Whether empty is valid.
	 * @return string
	 */
	private function sanitize_mysql_datetime( $date, $allow_empty ) {
		$date = is_scalar( $date ) ? sanitize_text_field( (string) $date ) : '';

		if ( '' === $date ) {
			return $allow_empty ? '' : current_time( 'mysql', true );
		}

		$datetime = DateTime::createFromFormat( 'Y-m-d H:i:s', $date, new DateTimeZone( 'UTC' ) );

		if ( ! $datetime || $datetime->format( 'Y-m-d H:i:s' ) !== $date ) {
			return $allow_empty ? '' : current_time( 'mysql', true );
		}

		return $date;
	}

	/**
	 * Get a multibyte-safe substring.
	 *
	 * @param string $value  String value.
	 * @param int    $start  Start offset.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	private function substring( $value, $start, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( (string) $value, $start, $length );
		}

		return substr( (string) $value, $start, $length );
	}
}
