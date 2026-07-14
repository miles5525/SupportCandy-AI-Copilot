<?php
/**
 * AI response value object for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents a normalized AI provider response.
 *
 * This class does not call AI providers. It only stores normalized response
 * data returned by provider implementations.
 */
final class SCAI_AI_Response {

	/**
	 * Whether the AI request was successful.
	 *
	 * @var bool
	 */
	private $success = false;

	/**
	 * Generated AI content.
	 *
	 * @var string
	 */
	private $content = '';

	/**
	 * Provider key.
	 *
	 * @var string
	 */
	private $provider = '';

	/**
	 * Model used by the provider.
	 *
	 * @var string
	 */
	private $model = '';

	/**
	 * Request ID if available.
	 *
	 * @var string
	 */
	private $request_id = '';

	/**
	 * Prompt/input tokens.
	 *
	 * @var int
	 */
	private $prompt_tokens = 0;

	/**
	 * Completion/output tokens.
	 *
	 * @var int
	 */
	private $completion_tokens = 0;

	/**
	 * Total tokens.
	 *
	 * @var int
	 */
	private $total_tokens = 0;

	/**
	 * Response duration in milliseconds.
	 *
	 * @var int
	 */
	private $duration_ms = 0;

	/**
	 * Provider finish reason.
	 *
	 * @var string
	 */
	private $finish_reason = '';

	/**
	 * Error code.
	 *
	 * @var string
	 */
	private $error_code = '';

	/**
	 * Error message.
	 *
	 * @var string
	 */
	private $error_message = '';

	/**
	 * Knowledge/source references used for the response.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $references = array();

	/**
	 * Raw provider response metadata.
	 *
	 * @var array<string, mixed>
	 */
	private $raw_response = array();

	/**
	 * Extra metadata for logging/tracing.
	 *
	 * @var array<string, mixed>
	 */
	private $metadata = array();

	/**
	 * Create AI response instance.
	 *
	 * @param array<string, mixed> $args Response arguments.
	 */
	public function __construct( array $args = array() ) {
		$defaults = array(
			'success'           => false,
			'content'           => '',
			'provider'          => '',
			'model'             => '',
			'request_id'        => '',
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'total_tokens'      => 0,
			'duration_ms'       => 0,
			'finish_reason'     => '',
			'error_code'        => '',
			'error_message'     => '',
			'references'        => array(),
			'raw_response'      => array(),
			'metadata'          => array(),
		);

		$args = wp_parse_args( $args, $defaults );

		$this->success           = $this->to_bool( $args['success'] );
		$this->content           = wp_kses_post( (string) $args['content'] );
		$this->provider          = sanitize_key( (string) $args['provider'] );
		$this->model             = sanitize_text_field( (string) $args['model'] );
		$this->request_id        = sanitize_text_field( (string) $args['request_id'] );
		$this->prompt_tokens     = absint( $args['prompt_tokens'] );
		$this->completion_tokens = absint( $args['completion_tokens'] );
		$this->total_tokens      = absint( $args['total_tokens'] );
		$this->duration_ms       = absint( $args['duration_ms'] );
		$this->finish_reason     = sanitize_key( (string) $args['finish_reason'] );
		$this->error_code        = sanitize_key( (string) $args['error_code'] );
		$this->error_message     = sanitize_text_field( (string) $args['error_message'] );
		$this->references        = $this->sanitize_references( $args['references'] );
		$this->raw_response      = $this->sanitize_array_value( $args['raw_response'] );
		$this->metadata          = $this->sanitize_array_value( $args['metadata'] );

		if ( 0 === $this->total_tokens ) {
			$this->total_tokens = $this->prompt_tokens + $this->completion_tokens;
		}
	}

	/**
	 * Create response from array.
	 *
	 * @param array<string, mixed> $args Response arguments.
	 * @return self
	 */
	public static function from_array( array $args ) {
		return new self( $args );
	}

	/**
	 * Create a successful AI response.
	 *
	 * @param string               $content Generated content.
	 * @param array<string, mixed> $args    Additional response arguments.
	 * @return self
	 */
	public static function success( $content, array $args = array() ) {
		$args['success'] = true;
		$args['content'] = $content;

		return new self( $args );
	}

	/**
	 * Create a failed AI response.
	 *
	 * @param string               $error_code    Error code.
	 * @param string               $error_message Error message.
	 * @param array<string, mixed> $args          Additional response arguments.
	 * @return self
	 */
	public static function error( $error_code, $error_message, array $args = array() ) {
		$args['success']       = false;
		$args['error_code']    = $error_code;
		$args['error_message'] = $error_message;

		return new self( $args );
	}

	/**
	 * Check whether the response is successful.
	 *
	 * @return bool
	 */
	public function is_success() {
		return $this->success;
	}

	/**
	 * Get success flag.
	 *
	 * @return bool
	 */
	public function get_success() {
		return $this->success;
	}

	/**
	 * Check whether the response failed.
	 *
	 * @return bool
	 */
	public function is_error() {
		return ! $this->success;
	}

	/**
	 * Get generated content.
	 *
	 * @return string
	 */
	public function get_content() {
		return $this->content;
	}

	/**
	 * Get provider key.
	 *
	 * @return string
	 */
	public function get_provider() {
		return $this->provider;
	}

	/**
	 * Get model.
	 *
	 * @return string
	 */
	public function get_model() {
		return $this->model;
	}

	/**
	 * Get request ID.
	 *
	 * @return string
	 */
	public function get_request_id() {
		return $this->request_id;
	}

	/**
	 * Get prompt/input token count.
	 *
	 * @return int
	 */
	public function get_prompt_tokens() {
		return $this->prompt_tokens;
	}

	/**
	 * Get completion/output token count.
	 *
	 * @return int
	 */
	public function get_completion_tokens() {
		return $this->completion_tokens;
	}

	/**
	 * Get total token count.
	 *
	 * @return int
	 */
	public function get_total_tokens() {
		return $this->total_tokens;
	}

	/**
	 * Get response duration in milliseconds.
	 *
	 * @return int
	 */
	public function get_duration_ms() {
		return $this->duration_ms;
	}

	/**
	 * Get finish reason.
	 *
	 * @return string
	 */
	public function get_finish_reason() {
		return $this->finish_reason;
	}

	/**
	 * Get error code.
	 *
	 * @return string
	 */
	public function get_error_code() {
		return $this->error_code;
	}

	/**
	 * Get error message.
	 *
	 * @return string
	 */
	public function get_error_message() {
		return $this->error_message;
	}

	/**
	 * Get knowledge/source references.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_references() {
		return $this->references;
	}

	/**
	 * Check whether response has source references.
	 *
	 * @return bool
	 */
	public function has_references() {
		return ! empty( $this->references );
	}

	/**
	 * Get raw provider response metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function get_raw_response() {
		return $this->raw_response;
	}

	/**
	 * Get extra metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function get_metadata() {
		return $this->metadata;
	}

	/**
	 * Convert response to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'success'           => $this->success,
			'content'           => $this->content,
			'provider'          => $this->provider,
			'model'             => $this->model,
			'request_id'        => $this->request_id,
			'prompt_tokens'     => $this->prompt_tokens,
			'completion_tokens' => $this->completion_tokens,
			'total_tokens'      => $this->total_tokens,
			'duration_ms'       => $this->duration_ms,
			'finish_reason'     => $this->finish_reason,
			'error_code'        => $this->error_code,
			'error_message'     => $this->error_message,
			'references'        => $this->references,
			'raw_response'      => $this->raw_response,
			'metadata'          => $this->metadata,
		);
	}

	/**
	 * Sanitize source references.
	 *
	 * @param mixed $references Raw references.
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitize_references( $references ) {
		if ( ! is_array( $references ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $references as $reference ) {
			if ( ! is_array( $reference ) ) {
				continue;
			}

			$item = array(
				'type'      => isset( $reference['type'] ) ? sanitize_key( (string) $reference['type'] ) : '',
				'id'        => isset( $reference['id'] ) ? absint( $reference['id'] ) : 0,
				'title'     => isset( $reference['title'] ) ? sanitize_text_field( (string) $reference['title'] ) : '',
				'url'       => isset( $reference['url'] ) ? esc_url_raw( (string) $reference['url'] ) : '',
				'score'     => isset( $reference['score'] ) ? (float) $reference['score'] : 0.0,
				'excerpt'   => isset( $reference['excerpt'] ) ? sanitize_textarea_field( (string) $reference['excerpt'] ) : '',
				'source_id' => isset( $reference['source_id'] ) ? sanitize_text_field( (string) $reference['source_id'] ) : '',
			);

			if ( '' === $item['type'] && '' === $item['title'] && '' === $item['url'] ) {
				continue;
			}

			$sanitized[] = $item;
		}

		return $sanitized;
	}

	/**
	 * Sanitize an array recursively.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, mixed>
	 */
	private function sanitize_array_value( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $value as $array_key => $array_value ) {
			$array_key = sanitize_key( (string) $array_key );

			if ( '' === $array_key ) {
				continue;
			}

			if ( is_array( $array_value ) ) {
				$sanitized[ $array_key ] = $this->sanitize_array_value( $array_value );
				continue;
			}

			if ( is_bool( $array_value ) ) {
				$sanitized[ $array_key ] = $array_value;
				continue;
			}

			if ( is_int( $array_value ) || is_float( $array_value ) ) {
				$sanitized[ $array_key ] = $array_value;
				continue;
			}

			$sanitized[ $array_key ] = sanitize_textarea_field( (string) $array_value );
		}

		return $sanitized;
	}

	/**
	 * Convert value to boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return 1 === (int) $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'yes', 'true', 'on' ), true );
		}

		return false;
	}
}
