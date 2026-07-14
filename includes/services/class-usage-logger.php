<?php
/**
 * AI usage logger service for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs AI usage records into the plugin-owned usage log table.
 */
final class SCAI_Usage_Logger {

	/**
	 * Database table key.
	 *
	 * @var string
	 */
	const TABLE_KEY = 'usage_logs';

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
		}
	}

	/**
	 * Log an AI request/response pair.
	 *
	 * @param SCAI_AI_Request|array<string, mixed>|null  $request  AI request object or array.
	 * @param SCAI_AI_Response|array<string, mixed>|null $response AI response object or array.
	 * @param array<string, mixed>                       $context  Additional context.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public function log( $request = null, $response = null, array $context = array() ) {
		$database = $this->get_database();

		if ( ! $database ) {
			return false;
		}

		$data = $this->build_log_data( $request, $response, $context );

		return $database->insert( self::TABLE_KEY, $data );
	}

	/**
	 * Log a successful AI action manually.
	 *
	 * @param SCAI_AI_Request|array<string, mixed>|null  $request  AI request object or array.
	 * @param SCAI_AI_Response|array<string, mixed>|null $response AI response object or array.
	 * @param array<string, mixed>                       $context  Additional context.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public function log_success( $request = null, $response = null, array $context = array() ) {
		$context['status'] = 'success';

		return $this->log( $request, $response, $context );
	}

	/**
	 * Log a failed AI action manually.
	 *
	 * @param SCAI_AI_Request|array<string, mixed>|null  $request  AI request object or array.
	 * @param SCAI_AI_Response|array<string, mixed>|null $response AI response object or array.
	 * @param array<string, mixed>                       $context  Additional context.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public function log_error( $request = null, $response = null, array $context = array() ) {
		$context['status'] = 'error';

		return $this->log( $request, $response, $context );
	}

	/**
	 * Build usage log data.
	 *
	 * @param mixed                $request  Request object or array.
	 * @param mixed                $response Response object or array.
	 * @param array<string, mixed> $context  Additional context.
	 * @return array<string, mixed>
	 */
	private function build_log_data( $request, $response, array $context ) {
		$request_data  = $this->normalize_request_data( $request );
		$response_data = $this->normalize_response_data( $response );
		$metadata      = $this->build_metadata( $request_data, $response_data, $context );

		$status = $this->get_context_value( $context, 'status', '' );

		if ( '' === $status ) {
			$status = ! empty( $response_data['success'] ) ? 'success' : 'error';
		}

		$request_id = $this->get_context_value( $context, 'request_id', '' );

		if ( '' === $request_id ) {
			$request_id = isset( $response_data['request_id'] ) ? $response_data['request_id'] : '';
		}

		if ( '' === $request_id && function_exists( 'wp_generate_uuid4' ) ) {
			$request_id = wp_generate_uuid4();
		}

		$token_usage = $this->get_token_usage( $response_data, $context );
		$metadata    = wp_json_encode( $metadata );

		return array(
			'request_id'        => sanitize_text_field( $request_id ),
			'ticket_id'         => $this->extract_ticket_id( $request, $context ),
			'agent_id'          => $this->extract_agent_id( $request, $context ),
			'feature'           => $this->extract_feature( $request, $context ),
			'provider'          => sanitize_key( $this->get_provider( $response_data, $context ) ),
			'model'             => sanitize_text_field( $this->get_model( $request_data, $response_data, $context ) ),
			'prompt_tokens'     => $token_usage['prompt_tokens'],
			'completion_tokens' => $token_usage['completion_tokens'],
			'total_tokens'      => $token_usage['total_tokens'],
			'estimated_cost'    => $this->sanitize_cost( $this->get_context_value( $context, 'estimated_cost', 0 ) ),
			'duration_ms'       => absint( $this->get_duration_ms( $response_data, $context ) ),
			'status'            => sanitize_key( $status ),
			'error_code'        => sanitize_key( $this->get_error_code( $response_data, $context ) ),
			'error_message'     => sanitize_textarea_field( $this->get_error_message( $response_data, $context ) ),
			'metadata'          => is_string( $metadata ) ? $metadata : '{}',
			'created_at'        => current_time( 'mysql', true ),
		);
	}

	/**
	 * Normalize AI request data.
	 *
	 * @param mixed $request Request object or array.
	 * @return array<string, mixed>
	 */
	private function normalize_request_data( $request ) {
		if ( $request instanceof SCAI_AI_Request ) {
			return $request->to_array();
		}

		if ( is_array( $request ) ) {
			return $this->sanitize_array( $request );
		}

		return array();
	}

	/**
	 * Normalize AI response data.
	 *
	 * @param mixed $response Response object or array.
	 * @return array<string, mixed>
	 */
	private function normalize_response_data( $response ) {
		if ( $response instanceof SCAI_AI_Response ) {
			return $response->to_array();
		}

		if ( is_array( $response ) ) {
			return $this->sanitize_array( $response );
		}

		return array();
	}

	/**
	 * Build safe metadata for the log record.
	 *
	 * @param array<string, mixed> $request_data  Request data.
	 * @param array<string, mixed> $response_data Response data.
	 * @param array<string, mixed> $context       Additional context.
	 * @return array<string, mixed>
	 */
	private function build_metadata( array $request_data, array $response_data, array $context ) {
		$metadata = array(
			'request'  => $this->get_safe_request_metadata( $request_data ),
			'response' => $this->get_safe_response_metadata( $response_data ),
			'context'  => $this->get_safe_context_metadata( $context ),
		);

		/**
		 * Filter usage log metadata before storage.
		 *
		 * @param array<string, mixed> $metadata      Metadata.
		 * @param array<string, mixed> $request_data  Request data.
		 * @param array<string, mixed> $response_data Response data.
		 * @param array<string, mixed> $context       Context.
		 */
		$metadata = apply_filters( 'scai_usage_log_metadata', $metadata, $request_data, $response_data, $context );

		return is_array( $metadata ) ? $this->sanitize_safe_array( $metadata ) : array();
	}

	/**
	 * Get safe request metadata.
	 *
	 * @param array<string, mixed> $request_data Request data.
	 * @return array<string, mixed>
	 */
	private function get_safe_request_metadata( array $request_data ) {
		return array(
			'feature'      => isset( $request_data['feature'] ) ? sanitize_key( $request_data['feature'] ) : '',
			'has_prompt'   => ! empty( $request_data['prompt'] ),
			'has_messages' => ! empty( $request_data['messages'] ),
			'has_images'   => ! empty( $request_data['images'] ),
			'stream'       => ! empty( $request_data['stream'] ),
			'temperature'  => isset( $request_data['temperature'] ) ? (float) $request_data['temperature'] : null,
			'max_tokens'   => isset( $request_data['max_tokens'] ) ? absint( $request_data['max_tokens'] ) : null,
		);
	}

	/**
	 * Get safe response metadata.
	 *
	 * @param array<string, mixed> $response_data Response data.
	 * @return array<string, mixed>
	 */
	private function get_safe_response_metadata( array $response_data ) {
		return array(
			'finish_reason'     => isset( $response_data['finish_reason'] ) ? sanitize_text_field( $response_data['finish_reason'] ) : '',
			'prompt_tokens'     => isset( $response_data['prompt_tokens'] ) ? absint( $response_data['prompt_tokens'] ) : 0,
			'completion_tokens' => isset( $response_data['completion_tokens'] ) ? absint( $response_data['completion_tokens'] ) : 0,
			'total_tokens'      => isset( $response_data['total_tokens'] ) ? absint( $response_data['total_tokens'] ) : 0,
			'has_references'    => ! empty( $response_data['references'] ),
		);
	}

	/**
	 * Get safe context metadata.
	 *
	 * @param array<string, mixed> $context Context data.
	 * @return array<string, mixed>
	 */
	private function get_safe_context_metadata( array $context ) {
		$safe = array();

		foreach ( $context as $key => $value ) {
			$key = is_string( $key ) ? sanitize_key( $key ) : '';

			if ( '' === $key || $this->is_sensitive_key( $key ) ) {
				continue;
			}

			$safe[ $key ] = $value;
		}

		return $this->sanitize_safe_array( $safe );
	}

	/**
	 * Get request metadata.
	 *
	 * @param mixed $request Request object or array.
	 * @return array<string, mixed>
	 */
	private function get_request_metadata( $request ) {
		if ( $request instanceof SCAI_AI_Request ) {
			$metadata = $request->get_metadata();

			return is_array( $metadata ) ? $metadata : array();
		}

		if ( is_array( $request ) && isset( $request['metadata'] ) && is_array( $request['metadata'] ) ) {
			return $request['metadata'];
		}

		return array();
	}

	/**
	 * Get request context.
	 *
	 * @param mixed $request Request object or array.
	 * @return array<string, mixed>
	 */
	private function get_request_context( $request ) {
		if ( $request instanceof SCAI_AI_Request ) {
			$request_context = $request->get_context();

			return is_array( $request_context ) ? $request_context : array();
		}

		if ( is_array( $request ) && isset( $request['context'] ) && is_array( $request['context'] ) ) {
			return $request['context'];
		}

		return array();
	}

	/**
	 * Get the feature directly from a request.
	 *
	 * @param mixed $request Request object or array.
	 * @return string
	 */
	private function get_request_feature( $request ) {
		if ( $request instanceof SCAI_AI_Request ) {
			$feature = $request->get_feature();

			return is_scalar( $feature ) ? sanitize_key( (string) $feature ) : '';
		}

		if ( is_array( $request ) && isset( $request['feature'] ) ) {
			return is_scalar( $request['feature'] ) ? sanitize_key( (string) $request['feature'] ) : '';
		}

		return '';
	}

	/**
	 * Extract a ticket ID from explicit and request context values.
	 *
	 * @param mixed                $request Request object or array.
	 * @param array<string, mixed> $context Explicit logging context.
	 * @return int
	 */
	private function extract_ticket_id( $request, array $context ) {
		if ( array_key_exists( 'ticket_id', $context ) ) {
			return absint( $context['ticket_id'] );
		}

		$metadata = $this->get_request_metadata( $request );

		if ( array_key_exists( 'ticket_id', $metadata ) ) {
			return absint( $metadata['ticket_id'] );
		}

		$request_context = $this->get_request_context( $request );

		if ( array_key_exists( 'ticket_id', $request_context ) ) {
			return absint( $request_context['ticket_id'] );
		}

		if ( isset( $request_context['ticket'] ) && is_array( $request_context['ticket'] ) && array_key_exists( 'id', $request_context['ticket'] ) ) {
			return absint( $request_context['ticket']['id'] );
		}

		return 0;
	}

	/**
	 * Extract an agent ID from explicit and request metadata values.
	 *
	 * @param mixed                $request Request object or array.
	 * @param array<string, mixed> $context Explicit logging context.
	 * @return int
	 */
	private function extract_agent_id( $request, array $context ) {
		if ( array_key_exists( 'agent_id', $context ) ) {
			return absint( $context['agent_id'] );
		}

		$metadata = $this->get_request_metadata( $request );

		if ( array_key_exists( 'agent_id', $metadata ) ) {
			return absint( $metadata['agent_id'] );
		}

		return function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0;
	}

	/**
	 * Extract a feature from explicit and request values.
	 *
	 * @param mixed                $request Request object or array.
	 * @param array<string, mixed> $context Explicit logging context.
	 * @return string
	 */
	private function extract_feature( $request, array $context ) {
		$feature = isset( $context['feature'] ) && is_scalar( $context['feature'] ) ? sanitize_key( (string) $context['feature'] ) : '';

		if ( '' !== $feature ) {
			return $feature;
		}

		$feature = $this->get_request_feature( $request );

		if ( '' !== $feature ) {
			return $feature;
		}

		$metadata = $this->get_request_metadata( $request );

		return isset( $metadata['feature'] ) && is_scalar( $metadata['feature'] ) ? sanitize_key( (string) $metadata['feature'] ) : '';
	}

	/**
	 * Get provider value.
	 *
	 * @param array<string, mixed> $response_data Response data.
	 * @param array<string, mixed> $context       Context data.
	 * @return string
	 */
	private function get_provider( array $response_data, array $context ) {
		$provider = $this->get_context_value( $context, 'provider', '' );

		if ( '' !== $provider ) {
			return $provider;
		}

		return isset( $response_data['provider'] ) ? $response_data['provider'] : '';
	}

	/**
	 * Get model value.
	 *
	 * @param array<string, mixed> $request_data  Request data.
	 * @param array<string, mixed> $response_data Response data.
	 * @param array<string, mixed> $context       Context data.
	 * @return string
	 */
	private function get_model( array $request_data, array $response_data, array $context ) {
		$model = $this->get_context_value( $context, 'model', '' );

		if ( '' !== $model ) {
			return $model;
		}

		if ( ! empty( $response_data['model'] ) ) {
			return $response_data['model'];
		}

		return isset( $request_data['model'] ) ? $request_data['model'] : '';
	}

	/**
	 * Get token usage.
	 *
	 * @param array<string, mixed> $response_data Response data.
	 * @param array<string, mixed> $context       Context data.
	 * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
	 */
	private function get_token_usage( array $response_data, array $context ) {
		$prompt_tokens     = absint( $this->get_context_value( $context, 'prompt_tokens', 0 ) );
		$completion_tokens = absint( $this->get_context_value( $context, 'completion_tokens', 0 ) );
		$total_tokens      = absint( $this->get_context_value( $context, 'total_tokens', 0 ) );

		if ( 0 === $total_tokens ) {
			$total_tokens = absint( $this->get_context_value( $context, 'tokens', 0 ) );
		}

		if ( 0 === $prompt_tokens && isset( $response_data['prompt_tokens'] ) ) {
			$prompt_tokens = absint( $response_data['prompt_tokens'] );
		}

		if ( 0 === $completion_tokens && isset( $response_data['completion_tokens'] ) ) {
			$completion_tokens = absint( $response_data['completion_tokens'] );
		}

		if ( 0 === $total_tokens && isset( $response_data['total_tokens'] ) ) {
			$total_tokens = absint( $response_data['total_tokens'] );
		}

		if ( 0 === $total_tokens ) {
			$total_tokens = $prompt_tokens + $completion_tokens;
		}

		return array(
			'prompt_tokens'     => $prompt_tokens,
			'completion_tokens' => $completion_tokens,
			'total_tokens'      => $total_tokens,
		);
	}

	/**
	 * Get duration in milliseconds.
	 *
	 * @param array<string, mixed> $response_data Response data.
	 * @param array<string, mixed> $context       Context data.
	 * @return int
	 */
	private function get_duration_ms( array $response_data, array $context ) {
		$duration = absint( $this->get_context_value( $context, 'duration_ms', 0 ) );

		if ( $duration > 0 ) {
			return $duration;
		}

		return isset( $response_data['duration_ms'] ) ? absint( $response_data['duration_ms'] ) : 0;
	}

	/**
	 * Get error code.
	 *
	 * @param array<string, mixed> $response_data Response data.
	 * @param array<string, mixed> $context       Context data.
	 * @return string
	 */
	private function get_error_code( array $response_data, array $context ) {
		$error_code = $this->get_context_value( $context, 'error_code', '' );

		if ( '' !== $error_code ) {
			return $error_code;
		}

		return isset( $response_data['error_code'] ) ? $response_data['error_code'] : '';
	}

	/**
	 * Get error message.
	 *
	 * @param array<string, mixed> $response_data Response data.
	 * @param array<string, mixed> $context       Context data.
	 * @return string
	 */
	private function get_error_message( array $response_data, array $context ) {
		$error_message = $this->get_context_value( $context, 'error_message', '' );

		if ( '' !== $error_message ) {
			return $error_message;
		}

		return isset( $response_data['error_message'] ) ? $response_data['error_message'] : '';
	}

	/**
	 * Get a context value.
	 *
	 * @param array<string, mixed> $context Context data.
	 * @param string              $key     Context key.
	 * @param mixed               $default Default value.
	 * @return mixed
	 */
	private function get_context_value( array $context, $key, $default = '' ) {
		return array_key_exists( $key, $context ) ? $context[ $key ] : $default;
	}

	/**
	 * Sanitize estimated cost.
	 *
	 * @param mixed $cost Estimated cost.
	 * @return float
	 */
	private function sanitize_cost( $cost ) {
		$cost = is_numeric( $cost ) ? (float) $cost : 0.0;

		return $cost < 0 ? 0.0 : $cost;
	}

	/**
	 * Check whether a metadata key is sensitive.
	 *
	 * @param string $key Metadata key.
	 * @return bool
	 */
	private function is_sensitive_key( $key ) {
		$key = sanitize_key( (string) $key );

		if ( '' === $key ) {
			return true;
		}

		$sensitive_fragments = array(
			'api_key',
			'apikey',
			'key',
			'token',
			'secret',
			'password',
			'authorization',
			'auth',
			'credential',
			'raw_response',
			'raw_request',
			'headers',
		);

		foreach ( $sensitive_fragments as $fragment ) {
			if ( false !== strpos( $key, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get database instance.
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
	 * Recursively sanitize an array.
	 *
	 * @param array<mixed> $data Data.
	 * @return array<mixed>
	 */
	private function sanitize_array( array $data ) {
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$key = is_string( $key ) ? sanitize_key( $key ) : absint( $key );

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_array( $value );
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$sanitized[ $key ] = $value;
				continue;
			}

			$sanitized[ $key ] = sanitize_textarea_field( (string) $value );
		}

		return $sanitized;
	}

	/**
	 * Recursively sanitize an array and remove sensitive keys.
	 *
	 * @param array<mixed> $data Data.
	 * @return array<mixed>
	 */
	private function sanitize_safe_array( array $data ) {
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$key = is_string( $key ) ? sanitize_key( $key ) : absint( $key );

			if ( is_string( $key ) && $this->is_sensitive_key( $key ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_safe_array( $value );
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$sanitized[ $key ] = $value;
				continue;
			}

			$sanitized[ $key ] = sanitize_textarea_field( (string) $value );
		}

		return $sanitized;
	}
}
