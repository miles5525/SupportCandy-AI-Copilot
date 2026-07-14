<?php
/**
 * AI request value object for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents a normalized AI request.
 *
 * This class does not call AI providers. It only stores sanitized request data
 * that can be passed from the AI engine to provider implementations.
 */
final class SCAI_AI_Request {

	/**
	 * Request feature key.
	 *
	 * Examples: summary, reply, improve, conversation, image_analysis.
	 *
	 * @var string
	 */
	private $feature = '';

	/**
	 * Requested model.
	 *
	 * @var string
	 */
	private $model = '';

	/**
	 * System instructions.
	 *
	 * @var string
	 */
	private $system_instructions = '';

	/**
	 * Main user prompt.
	 *
	 * @var string
	 */
	private $prompt = '';

	/**
	 * Conversation messages.
	 *
	 * @var array<int, array<string, string>>
	 */
	private $messages = array();

	/**
	 * Context payload prepared by context/prompt engines.
	 *
	 * @var array<string, mixed>
	 */
	private $context = array();

	/**
	 * Image references for multimodal providers.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $images = array();

	/**
	 * Whether streaming response is requested.
	 *
	 * @var bool
	 */
	private $stream = false;

	/**
	 * Temperature value.
	 *
	 * @var float|null
	 */
	private $temperature = null;

	/**
	 * Maximum output tokens.
	 *
	 * @var int|null
	 */
	private $max_tokens = null;

	/**
	 * Extra metadata for logging/tracing.
	 *
	 * @var array<string, mixed>
	 */
	private $metadata = array();

	/**
	 * Create AI request instance.
	 *
	 * @param array<string, mixed> $args Request arguments.
	 */
	public function __construct( array $args = array() ) {
		$defaults = array(
			'feature'             => '',
			'model'               => '',
			'system_instructions' => '',
			'prompt'              => '',
			'messages'            => array(),
			'context'             => array(),
			'images'              => array(),
			'stream'              => false,
			'temperature'         => null,
			'max_tokens'          => null,
			'metadata'            => array(),
		);

		$args = wp_parse_args( $args, $defaults );

		$this->feature             = sanitize_key( $args['feature'] );
		$this->model               = sanitize_text_field( (string) $args['model'] );
		$this->system_instructions = sanitize_textarea_field( (string) $args['system_instructions'] );
		$this->prompt              = sanitize_textarea_field( (string) $args['prompt'] );
		$this->messages            = $this->sanitize_messages( $args['messages'] );
		$this->context             = $this->sanitize_array_value( $args['context'] );
		$this->images              = $this->sanitize_images( $args['images'] );
		$this->stream              = $this->to_bool( $args['stream'] );
		$this->temperature         = $this->sanitize_temperature( $args['temperature'] );
		$this->max_tokens          = $this->sanitize_max_tokens( $args['max_tokens'] );
		$this->metadata            = $this->sanitize_array_value( $args['metadata'] );
	}

	/**
	 * Create request from array.
	 *
	 * @param array<string, mixed> $args Request arguments.
	 * @return self
	 */
	public static function from_array( array $args ) {
		return new self( $args );
	}

	/**
	 * Get feature key.
	 *
	 * @return string
	 */
	public function get_feature() {
		return $this->feature;
	}

	/**
	 * Get requested model.
	 *
	 * @return string
	 */
	public function get_model() {
		return $this->model;
	}

	/**
	 * Get system instructions.
	 *
	 * @return string
	 */
	public function get_system_instructions() {
		return $this->system_instructions;
	}

	/**
	 * Get main prompt.
	 *
	 * @return string
	 */
	public function get_prompt() {
		return $this->prompt;
	}

	/**
	 * Get messages.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_messages() {
		return $this->messages;
	}

	/**
	 * Get context payload.
	 *
	 * @return array<string, mixed>
	 */
	public function get_context() {
		return $this->context;
	}

	/**
	 * Get image references.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_images() {
		return $this->images;
	}

	/**
	 * Set transient image inputs for a multimodal provider request.
	 *
	 * @param array<int, mixed> $images Raw prepared images.
	 * @return void
	 */
	public function set_images( array $images ) {
		$this->images = $this->sanitize_images( $images );
	}

	/**
	 * Check whether request contains images.
	 *
	 * @return bool
	 */
	public function has_images() {
		return ! empty( $this->images );
	}

	/**
	 * Get a non-sensitive summary of transient request images.
	 *
	 * @return array{count: int, filenames: array<int, string>, mime_types: array<int, string>, sizes: array<int, int>}
	 */
	public function get_safe_image_summary() {
		$summary = array(
			'count'      => count( $this->images ),
			'filenames'  => array(),
			'mime_types' => array(),
			'sizes'      => array(),
		);

		foreach ( $this->images as $image ) {
			$summary['filenames'][]  = isset( $image['filename'] ) ? sanitize_file_name( $image['filename'] ) : '';
			$summary['mime_types'][] = isset( $image['mime_type'] ) ? sanitize_mime_type( $image['mime_type'] ) : '';
			$summary['sizes'][]      = isset( $image['size'] ) ? absint( $image['size'] ) : 0;
		}

		return $summary;
	}

	/**
	 * Check whether streaming is requested.
	 *
	 * @return bool
	 */
	public function should_stream() {
		return $this->stream;
	}

	/**
	 * Get streaming flag.
	 *
	 * @return bool
	 */
	public function get_stream() {
		return $this->stream;
	}

	/**
	 * Get temperature value.
	 *
	 * @return float|null
	 */
	public function get_temperature() {
		return $this->temperature;
	}

	/**
	 * Get maximum output tokens.
	 *
	 * @return int|null
	 */
	public function get_max_tokens() {
		return $this->max_tokens;
	}

	/**
	 * Get metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function get_metadata() {
		return $this->metadata;
	}

	/**
	 * Convert request to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'feature'             => $this->feature,
			'model'               => $this->model,
			'system_instructions' => $this->system_instructions,
			'prompt'              => $this->prompt,
			'messages'            => $this->messages,
			'context'             => $this->context,
			'images'              => $this->get_safe_images_for_serialization(),
			'stream'              => $this->stream,
			'temperature'         => $this->temperature,
			'max_tokens'          => $this->max_tokens,
			'metadata'            => $this->metadata,
		);
	}

	/**
	 * Check whether request has enough content to send to a provider.
	 *
	 * @return bool
	 */
	public function is_valid() {
		if ( '' !== $this->prompt ) {
			return true;
		}

		if ( ! empty( $this->messages ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Sanitize conversation messages.
	 *
	 * @param mixed $messages Raw messages.
	 * @return array<int, array<string, string>>
	 */
	private function sanitize_messages( $messages ) {
		if ( ! is_array( $messages ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role    = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : '';
			$content = isset( $message['content'] ) ? sanitize_textarea_field( (string) $message['content'] ) : '';

			if ( ! $this->is_allowed_role( $role ) || '' === $content ) {
				continue;
			}

			$sanitized[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		return $sanitized;
	}

	/**
	 * Sanitize image references.
	 *
	 * @param mixed $images Raw images.
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitize_images( $images ) {
		if ( ! is_array( $images ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( array_slice( $images, 0, 3 ) as $image ) {
			if ( ! is_array( $image ) ) {
				continue;
			}

			$data_url  = isset( $image['data_url'] ) && is_scalar( $image['data_url'] ) ? (string) $image['data_url'] : '';
			$data_url  = '' === $data_url && isset( $image['image_data_url'] ) && is_scalar( $image['image_data_url'] ) ? (string) $image['image_data_url'] : $data_url;
			$data_url  = '' === $data_url && isset( $image['url'] ) && is_scalar( $image['url'] ) ? (string) $image['url'] : $data_url;
			$mime_type = isset( $image['mime_type'] ) ? sanitize_mime_type( (string) $image['mime_type'] ) : '';

			if ( ! $this->is_valid_image_data_url( $data_url, $mime_type ) ) {
				continue;
			}

			$detail = isset( $image['detail'] ) && is_scalar( $image['detail'] ) ? sanitize_key( (string) $image['detail'] ) : 'low';
			$item   = array(
				'data_url'  => $data_url,
				'mime_type' => $mime_type,
				'filename'  => isset( $image['filename'] ) ? sanitize_file_name( (string) $image['filename'] ) : '',
				'size'      => isset( $image['size'] ) ? absint( $image['size'] ) : ( isset( $image['file_size'] ) ? absint( $image['file_size'] ) : 0 ),
				'detail'    => in_array( $detail, array( 'low', 'high', 'auto' ), true ) ? $detail : 'low',
			);

			$sanitized[] = $item;
		}

		return $sanitized;
	}

	/**
	 * Validate a local prepared image data URL without decoding it.
	 *
	 * @param string $data_url  Prepared image data URL.
	 * @param string $mime_type Claimed image MIME type.
	 * @return bool
	 */
	private function is_valid_image_data_url( $data_url, $mime_type ) {
		$mime_type    = sanitize_mime_type( (string) $mime_type );
		$allowed_mime = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );

		if ( ! in_array( $mime_type, $allowed_mime, true ) ) {
			return false;
		}

		return 1 === preg_match( '#^data:' . preg_quote( $mime_type, '#' ) . ';base64,[A-Za-z0-9+/]+={0,2}$#', (string) $data_url );
	}

	/**
	 * Get non-sensitive image descriptors for logging or serialization.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_safe_images_for_serialization() {
		$safe = array();

		foreach ( $this->images as $image ) {
			$safe[] = array(
				'filename'  => isset( $image['filename'] ) ? sanitize_file_name( $image['filename'] ) : '',
				'mime_type' => isset( $image['mime_type'] ) ? sanitize_mime_type( $image['mime_type'] ) : '',
				'size'      => isset( $image['size'] ) ? absint( $image['size'] ) : 0,
				'included'  => true,
			);
		}

		return $safe;
	}

	/**
	 * Check if a message role is supported.
	 *
	 * @param string $role Message role.
	 * @return bool
	 */
	private function is_allowed_role( $role ) {
		return in_array( $role, array( 'system', 'user', 'assistant', 'tool' ), true );
	}

	/**
	 * Sanitize temperature value.
	 *
	 * @param mixed $temperature Raw temperature.
	 * @return float|null
	 */
	private function sanitize_temperature( $temperature ) {
		if ( null === $temperature || '' === $temperature ) {
			return null;
		}

		$temperature = (float) $temperature;

		if ( $temperature < 0 ) {
			return 0.0;
		}

		if ( $temperature > 2 ) {
			return 2.0;
		}

		return $temperature;
	}

	/**
	 * Sanitize max tokens value.
	 *
	 * @param mixed $max_tokens Raw max tokens.
	 * @return int|null
	 */
	private function sanitize_max_tokens( $max_tokens ) {
		if ( null === $max_tokens || '' === $max_tokens ) {
			return null;
		}

		$max_tokens = absint( $max_tokens );

		if ( 0 === $max_tokens ) {
			return null;
		}

		return $max_tokens;
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
