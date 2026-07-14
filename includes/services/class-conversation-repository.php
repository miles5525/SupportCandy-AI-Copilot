<?php
/**
 * Conversation repository for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes plugin-owned ticket AI conversation records.
 */
final class SCAI_Conversation_Repository {

	/**
	 * Database table key.
	 *
	 * @var string
	 */
	const TABLE_KEY = 'conversations';

	/**
	 * Default retention period in days.
	 *
	 * @var int
	 */
	const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Database instance.
	 *
	 * @var SCAI_Database|null
	 */
	private $database = null;

	/**
	 * Constructor.
	 *
	 * @param SCAI_Database|null $database Optional database instance.
	 */
	public function __construct( $database = null ) {
		if ( $database instanceof SCAI_Database ) {
			$this->database = $database;
		} elseif ( class_exists( 'SCAI_Database' ) ) {
			$this->database = new SCAI_Database();
		}
	}

	/**
	 * Insert one conversation record.
	 *
	 * @param array<string, mixed> $data Conversation data.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public function create( array $data ) {
		$database = $this->get_database();

		if ( ! $database ) {
			return false;
		}

		$data = $this->prepare_insert_data( $data );

		if ( 0 === $data['ticket_id'] || '' === $data['content'] ) {
			return false;
		}

		return $database->insert( self::TABLE_KEY, $data );
	}

	/**
	 * Save an AI-generated assistant message.
	 *
	 * @param int                  $ticket_id Ticket ID.
	 * @param int                  $agent_id  Agent ID.
	 * @param string               $feature   Feature key.
	 * @param string               $content   Assistant content.
	 * @param array<string, mixed> $args      Additional record data.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public function create_assistant_message( $ticket_id, $agent_id, $feature, $content, array $args = array() ) {
		$data = array_merge(
			$args,
			array(
				'ticket_id' => absint( $ticket_id ),
				'agent_id'  => absint( $agent_id ),
				'role'      => 'assistant',
				'feature'   => sanitize_key( $feature ),
				'content'   => $content,
			)
		);

		return $this->create( $data );
	}

	/**
	 * Get recent conversation records for a ticket.
	 *
	 * Supported arguments are agent_id, feature, conversation_id, limit, and
	 * offset. Results are returned newest first.
	 *
	 * @param int                  $ticket_id Ticket ID.
	 * @param array<string, mixed> $args      Query arguments.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_by_ticket( $ticket_id, array $args = array() ) {
		$database  = $this->get_database();
		$ticket_id = absint( $ticket_id );

		if ( ! $database || 0 === $ticket_id ) {
			return array();
		}

		$args  = wp_parse_args(
			$args,
			array(
				'agent_id'       => 0,
				'feature'        => '',
				'conversation_id' => '',
				'limit'          => 50,
				'offset'         => 0,
			)
		);
		$where = array( 'ticket_id' => $ticket_id );

		if ( 0 < absint( $args['agent_id'] ) ) {
			$where['agent_id'] = absint( $args['agent_id'] );
		}

		if ( '' !== sanitize_key( $args['feature'] ) ) {
			$where['feature'] = sanitize_key( $args['feature'] );
		}

		$conversation_id = $this->sanitize_conversation_id( $args['conversation_id'], false );

		if ( '' !== $conversation_id ) {
			$where['conversation_id'] = $conversation_id;
		}

		$rows = $database->get_results(
			self::TABLE_KEY,
			array(
				'where'   => $where,
				'orderby' => 'id',
				'order'   => 'DESC',
				'limit'   => min( 100, max( 1, absint( $args['limit'] ) ) ),
				'offset'  => absint( $args['offset'] ),
			),
			ARRAY_A
		);

		$records = array();

		foreach ( $rows as $row ) {
			$record = $this->normalize_record( $row );

			if ( ! empty( $record ) ) {
				$records[] = $record;
			}
		}

		return $records;
	}

	/**
	 * Get the latest record for a ticket and feature.
	 *
	 * @param int    $ticket_id Ticket ID.
	 * @param string $feature   Feature key.
	 * @return array<string, mixed>|null
	 */
	public function get_latest_by_ticket_and_feature( $ticket_id, $feature ) {
		$feature = sanitize_key( $feature );

		if ( '' === $feature ) {
			return null;
		}

		$records = $this->get_by_ticket(
			$ticket_id,
			array(
				'feature' => $feature,
				'limit'   => 1,
			)
		);

		return isset( $records[0] ) ? $records[0] : null;
	}

	/**
	 * Count conversation records for a ticket.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return int
	 */
	public function get_count_by_ticket( $ticket_id ) {
		$database  = $this->get_database();
		$ticket_id = absint( $ticket_id );

		if ( ! $database || 0 === $ticket_id ) {
			return 0;
		}

		return $database->get_count( self::TABLE_KEY, array( 'ticket_id' => $ticket_id ) );
	}

	/**
	 * Delete expired conversation records.
	 *
	 * @return int|false Number of deleted rows, or false on failure.
	 */
	public function delete_expired() {
		global $wpdb;

		$database = $this->get_database();

		if ( ! $database ) {
			return false;
		}

		$table_name = $database->get_table_name( self::TABLE_KEY );

		if ( '' === $table_name ) {
			return false;
		}

		$current_time = current_time( 'mysql', true );
		$sql          = "DELETE FROM `{$table_name}` WHERE `expires_at` IS NOT NULL AND `expires_at` < %s";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is supplied by SCAI_Schema through SCAI_Database; date is prepared.
		return $wpdb->query( $wpdb->prepare( $sql, $current_time ) );
	}

	/**
	 * Normalize a database record into a safe array.
	 *
	 * @param mixed $record Database row.
	 * @return array<string, mixed>
	 */
	public function normalize_record( $record ) {
		if ( is_object( $record ) ) {
			$record = get_object_vars( $record );
		}

		if ( ! is_array( $record ) ) {
			return array();
		}

		$prompt_tokens     = isset( $record['prompt_tokens'] ) ? absint( $record['prompt_tokens'] ) : 0;
		$completion_tokens = isset( $record['completion_tokens'] ) ? absint( $record['completion_tokens'] ) : 0;

		return array(
			'id'                => isset( $record['id'] ) ? absint( $record['id'] ) : 0,
			'conversation_id'   => isset( $record['conversation_id'] ) ? $this->sanitize_conversation_id( $record['conversation_id'], false ) : '',
			'ticket_id'         => isset( $record['ticket_id'] ) ? absint( $record['ticket_id'] ) : 0,
			'agent_id'          => isset( $record['agent_id'] ) ? absint( $record['agent_id'] ) : 0,
			'role'              => $this->sanitize_role( isset( $record['role'] ) ? $record['role'] : 'assistant' ),
			'feature'           => isset( $record['feature'] ) ? sanitize_key( $record['feature'] ) : '',
			'content'           => isset( $record['content'] ) ? wp_kses_post( $record['content'] ) : '',
			'context_hash'      => isset( $record['context_hash'] ) ? $this->sanitize_context_hash( $record['context_hash'] ) : '',
			'provider'          => isset( $record['provider'] ) ? sanitize_text_field( $record['provider'] ) : '',
			'model'             => isset( $record['model'] ) ? sanitize_text_field( $record['model'] ) : '',
			'prompt_tokens'     => $prompt_tokens,
			'completion_tokens' => $completion_tokens,
			'tokens'            => $prompt_tokens + $completion_tokens,
			'metadata'          => $this->decode_metadata( isset( $record['metadata'] ) ? $record['metadata'] : '' ),
			'expires_at'        => $this->sanitize_mysql_datetime( isset( $record['expires_at'] ) ? $record['expires_at'] : '', true ),
			'created_at'        => $this->sanitize_mysql_datetime( isset( $record['created_at'] ) ? $record['created_at'] : '', false ),
			'updated_at'        => $this->sanitize_mysql_datetime( isset( $record['updated_at'] ) ? $record['updated_at'] : '', true ),
		);
	}

	/**
	 * Sanitize and normalize conversation insert data.
	 *
	 * @param array<string, mixed> $data Raw insert data.
	 * @return array<string, mixed>
	 */
	public function prepare_insert_data( array $data ) {
		$data = $this->sanitize_insert_data( $data, true );

		/**
		 * Filter conversation data before it is inserted.
		 *
		 * @param array<string, mixed> $data Sanitized conversation data.
		 */
		$data = apply_filters( 'scai_conversation_insert_data', $data );

		return $this->sanitize_insert_data( is_array( $data ) ? $data : array(), false );
	}

	/**
	 * Get or create the database instance.
	 *
	 * @return SCAI_Database|null
	 */
	private function get_database() {
		if ( $this->database instanceof SCAI_Database ) {
			return $this->database;
		}

		if ( ! class_exists( 'SCAI_Database' ) ) {
			return null;
		}

		$this->database = new SCAI_Database();

		return $this->database;
	}

	/**
	 * Sanitize insert data without applying filters.
	 *
	 * @param array<string, mixed> $data                  Raw insert data.
	 * @param bool                 $apply_metadata_filter Whether to apply the metadata filter.
	 * @return array<string, mixed>
	 */
	private function sanitize_insert_data( array $data, $apply_metadata_filter ) {
		$now               = current_time( 'mysql', true );
		$prompt_tokens     = isset( $data['prompt_tokens'] ) ? absint( $data['prompt_tokens'] ) : 0;
		$completion_tokens = isset( $data['completion_tokens'] ) ? absint( $data['completion_tokens'] ) : 0;

		if ( 0 === $completion_tokens && isset( $data['tokens'] ) ) {
			$completion_tokens = absint( $data['tokens'] );
		}

		$metadata = isset( $data['metadata'] ) ? $data['metadata'] : array();

		if ( is_string( $metadata ) && '' !== $metadata ) {
			$metadata = json_decode( $metadata, true );
		}

		$metadata = is_array( $metadata ) ? $metadata : array();

		if ( $apply_metadata_filter ) {
			/**
			 * Filter safe conversation metadata before storage.
			 *
			 * @param array<string, mixed> $metadata Conversation metadata.
			 * @param array<string, mixed> $data     Raw conversation data.
			 */
			$metadata = apply_filters( 'scai_conversation_metadata', $metadata, $data );
		}
		$metadata = is_array( $metadata ) ? $this->sanitize_metadata( $metadata ) : array();
		$metadata = wp_json_encode( $metadata );

		return array(
			'conversation_id'   => $this->sanitize_conversation_id( isset( $data['conversation_id'] ) ? $data['conversation_id'] : '', true ),
			'ticket_id'         => isset( $data['ticket_id'] ) ? absint( $data['ticket_id'] ) : 0,
			'agent_id'          => isset( $data['agent_id'] ) ? absint( $data['agent_id'] ) : 0,
			'role'              => $this->sanitize_role( isset( $data['role'] ) ? $data['role'] : 'assistant' ),
			'feature'           => isset( $data['feature'] ) ? sanitize_key( $data['feature'] ) : '',
			'content'           => isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : '',
			'context_hash'      => isset( $data['context_hash'] ) ? $this->sanitize_context_hash( $data['context_hash'] ) : '',
			'provider'          => isset( $data['provider'] ) ? sanitize_text_field( $data['provider'] ) : '',
			'model'             => isset( $data['model'] ) ? sanitize_text_field( $data['model'] ) : '',
			'prompt_tokens'     => $prompt_tokens,
			'completion_tokens' => $completion_tokens,
			'metadata'          => is_string( $metadata ) ? $metadata : '{}',
			'expires_at'        => $this->get_expiration_date( isset( $data['expires_at'] ) ? $data['expires_at'] : '' ),
			'created_at'        => $now,
			'updated_at'        => $now,
		);
	}

	/**
	 * Get an expiration date in UTC.
	 *
	 * @param mixed $expires_at Optional supplied expiration date.
	 * @return string
	 */
	private function get_expiration_date( $expires_at ) {
		$expires_at = $this->sanitize_mysql_datetime( $expires_at, true );

		if ( '' !== $expires_at ) {
			return $expires_at;
		}

		$retention_days = self::DEFAULT_RETENTION_DAYS;

		if ( class_exists( 'SCAI_Settings' ) ) {
			$retention_days = absint( SCAI_Settings::get( 'conversation_retention_days', self::DEFAULT_RETENTION_DAYS ) );
		} else {
			$retention_days = absint( get_option( 'scai_conversation_retention_days', self::DEFAULT_RETENTION_DAYS ) );
		}

		$retention_days = max( 1, $retention_days );

		/**
		 * Filter the conversation retention period.
		 *
		 * @param int $retention_days Retention period in days.
		 */
		$retention_days = absint( apply_filters( 'scai_conversation_retention_days', $retention_days ) );
		$retention_days = max( 1, $retention_days );

		return gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) + ( DAY_IN_SECONDS * $retention_days ) );
	}

	/**
	 * Generate or sanitize a conversation identifier.
	 *
	 * @param mixed $conversation_id Raw conversation ID.
	 * @param bool  $generate        Whether to generate an ID when empty.
	 * @return string
	 */
	private function sanitize_conversation_id( $conversation_id, $generate ) {
		$conversation_id = is_scalar( $conversation_id ) ? sanitize_text_field( (string) $conversation_id ) : '';
		$conversation_id = preg_replace( '/[^A-Za-z0-9_-]/', '', $conversation_id );
		$conversation_id = is_string( $conversation_id ) ? $conversation_id : '';

		if ( '' === $conversation_id && $generate ) {
			$unique_id       = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true );
			$conversation_id = 'scai_' . preg_replace( '/[^A-Za-z0-9_-]/', '', $unique_id );
		} elseif ( '' !== $conversation_id && 0 !== strpos( $conversation_id, 'scai_' ) ) {
			$conversation_id = 'scai_' . $conversation_id;
		}

		return substr( $conversation_id, 0, 64 );
	}

	/**
	 * Sanitize a conversation role.
	 *
	 * @param mixed $role Raw role.
	 * @return string
	 */
	private function sanitize_role( $role ) {
		$role = is_scalar( $role ) ? sanitize_key( (string) $role ) : '';

		return in_array( $role, array( 'system', 'user', 'assistant', 'tool' ), true ) ? $role : 'assistant';
	}

	/**
	 * Sanitize a context hash.
	 *
	 * @param mixed $context_hash Raw hash.
	 * @return string
	 */
	private function sanitize_context_hash( $context_hash ) {
		$context_hash = is_scalar( $context_hash ) ? strtolower( (string) $context_hash ) : '';
		$context_hash = preg_replace( '/[^a-f0-9]/', '', $context_hash );

		return is_string( $context_hash ) ? substr( $context_hash, 0, 64 ) : '';
	}

	/**
	 * Sanitize a MySQL datetime.
	 *
	 * @param mixed $date        Raw date.
	 * @param bool  $allow_empty Whether an empty value is allowed.
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
	 * Decode and sanitize stored metadata.
	 *
	 * @param mixed $metadata Stored metadata.
	 * @return array<string, mixed>
	 */
	private function decode_metadata( $metadata ) {
		if ( is_string( $metadata ) && '' !== $metadata ) {
			$metadata = json_decode( $metadata, true );
		}

		return is_array( $metadata ) ? $this->sanitize_metadata( $metadata ) : array();
	}

	/**
	 * Recursively sanitize metadata and remove sensitive keys.
	 *
	 * @param array<mixed> $metadata Raw metadata.
	 * @return array<mixed>
	 */
	private function sanitize_metadata( array $metadata ) {
		$clean = array();

		foreach ( $metadata as $key => $value ) {
			$clean_key = is_string( $key ) ? sanitize_key( $key ) : absint( $key );

			if ( is_string( $clean_key ) && ( '' === $clean_key || $this->is_sensitive_metadata_key( $clean_key ) ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $clean_key ] = $this->sanitize_metadata( $value );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$clean[ $clean_key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$clean[ $clean_key ] = sanitize_textarea_field( (string) $value );
			}
		}

		return $clean;
	}

	/**
	 * Check whether a metadata key may contain secrets.
	 *
	 * @param string $key Metadata key.
	 * @return bool
	 */
	private function is_sensitive_metadata_key( $key ) {
		$key = sanitize_key( $key );

		foreach ( array( 'api_key', 'authorization', 'password', 'secret', 'token', 'access_token', 'refresh_token', 'provider_config' ) as $fragment ) {
			if ( false !== strpos( $key, $fragment ) ) {
				return true;
			}
		}

		return false;
	}
}
