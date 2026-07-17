<?php
/**
 * OpenAI-compatible provider for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider implementation for OpenAI-compatible chat completion APIs.
 *
 * This provider can be used for OpenAI-style endpoints such as OpenAI,
 * OpenRouter, Groq, DeepSeek, and other compatible APIs when configured with
 * an API key, base URL, and model.
 */
final class SCAI_OpenAI_Compatible_Provider extends SCAI_Abstract_Provider {

	/**
	 * Provider key.
	 *
	 * @var string
	 */
	const PROVIDER_KEY = 'openai_compatible';

	/**
	 * Default chat completions path.
	 *
	 * @var string
	 */
	const CHAT_COMPLETIONS_PATH = '/chat/completions';

	/**
	 * HTTP client instance.
	 *
	 * @var SCAI_HTTP_Client
	 */
	private $http_client;

	/** @var SCAI_Provider_Preset */
	private $preset;

	/**
	 * Create provider instance.
	 *
	 * @param SCAI_HTTP_Client|null              $http_client Optional HTTP client.
	 * @param SCAI_Provider_Preset|array|null    $preset      Optional provider preset metadata.
	 */
	public function __construct( $http_client = null, $preset = null ) {
		$this->http_client = $http_client instanceof SCAI_HTTP_Client
			? $http_client
			: new SCAI_HTTP_Client();
		$this->preset      = $preset instanceof SCAI_Provider_Preset
			? $preset
			: new SCAI_Provider_Preset(
				is_array( $preset )
					? wp_parse_args( $preset, $this->get_default_preset_data() )
					: $this->get_default_preset_data()
			);
	}

	/**
	 * Get provider key.
	 *
	 * @return string
	 */
	public function get_key() {
		return $this->preset->get( 'key', self::PROVIDER_KEY );
	}

	/**
	 * Get provider display name.
	 *
	 * @return string
	 */
	public function get_name() {
		return $this->preset->get( 'label', __( 'OpenAI Compatible', 'supportcandy-ai' ) );
	}

	/**
	 * Get provider description.
	 *
	 * @return string
	 */
	public function get_description() {
		return $this->preset->get( 'description', '' );
	}

	/**
	 * Get default model.
	 *
	 * @return string
	 */
	public function get_default_model() {
		return $this->preset->get( 'default_model', 'gpt-4o-mini' );
	}

	/**
	 * Get available model suggestions.
	 *
	 * These are suggestions only. Administrators may configure a custom model
	 * depending on their selected compatible provider.
	 *
	 * @return array<string, string>
	 */
	public function get_available_models() {
		return $this->get_model_suggestions();
	}

	/** Get editable model suggestions supplied by the preset. */
	public function get_model_suggestions() {
		$models = $this->preset->get( 'model_suggestions', array() );
		return is_array( $models ) ? $models : array();
	}

	/** Get normalized preset metadata for future registry-driven UI. */
	public function get_preset() {
		return $this->preset;
	}

	/**
	 * Whether this provider implementation can send image inputs.
	 *
	 * Actual image support still depends on the configured endpoint and model.
	 *
	 * @return bool
	 */
	public function supports_images() {
		return (bool) $this->preset->get( 'supports_images', true );
	}

	/**
	 * Whether this provider supports streaming.
	 *
	 * Streaming will be handled in a later milestone.
	 *
	 * @return bool
	 */
	public function supports_streaming() {
		return (bool) $this->preset->get( 'supports_streaming', false );
	}

	/**
	 * Get required provider configuration fields.
	 *
	 * @return array<int, string>
	 */
	protected function get_required_config_fields() {
		return array(
			'api_key',
			'base_url',
		);
	}

	/**
	 * Validate provider configuration.
	 *
	 * @param array<string, mixed> $config Provider configuration.
	 * @return true|WP_Error
	 */
	public function validate_config( $config ) {
		$validation = parent::validate_config( $config );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$config = $this->sanitize_config( $config );

		if ( empty( $config['base_url'] ) || ! $this->is_valid_base_url( $config['base_url'] ) ) {
			return new WP_Error(
				'scai_provider_invalid_base_url',
				__( 'Provider base URL is invalid.', 'supportcandy-ai' ),
				array(
					'provider' => $this->get_key(),
				)
			);
		}

		return true;
	}

	/**
	 * Validate provider base URL.
	 *
	 * This intentionally mirrors the shared HTTP client validation so
	 * administrator-configured compatible endpoints can use custom ports.
	 *
	 * @param string $base_url Provider base URL.
	 * @return bool
	 */
	private function is_valid_base_url( $base_url ) {
		$base_url = esc_url_raw( (string) $base_url );

		if ( '' === $base_url ) {
			return false;
		}

		$scheme = wp_parse_url( $base_url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $base_url, PHP_URL_HOST );

		return in_array( $scheme, array( 'http', 'https' ), true ) && ! empty( $host );
	}

	/**
	 * Generate AI response.
	 *
	 * @param SCAI_AI_Request|array<string, mixed> $request AI request.
	 * @param array<string, mixed>                 $config  Provider configuration.
	 * @return SCAI_AI_Response
	 */
	public function generate_response( $request, $config ) {
		$validation = $this->validate_config( $config );

		if ( is_wp_error( $validation ) ) {
			return $this->build_error_response_from_wp_error( $validation );
		}

		$ai_request = $this->normalize_request( $request );

		if ( is_wp_error( $ai_request ) ) {
			return $this->build_error_response_from_wp_error( $ai_request );
		}

		$request_validation = $this->validate_request( $ai_request );

		if ( is_wp_error( $request_validation ) ) {
			return $this->build_error_response_from_wp_error( $request_validation );
		}

		$config   = $this->sanitize_config( $config );
		$model    = $this->resolve_model( $ai_request, $config );
		$endpoint = $this->build_chat_completions_endpoint( $config['base_url'] );
		$payload  = $this->build_payload( $ai_request, $model );
		$payload_image_metadata = $this->get_safe_payload_image_metadata( $payload );
		$headers  = $this->build_headers( $config );

		$http_response = $this->http_client->post_json(
			$endpoint,
			$payload,
			array(
				'headers' => $headers,
				'timeout' => isset( $config['timeout'] ) ? absint( $config['timeout'] ) : SCAI_HTTP_Client::DEFAULT_TIMEOUT,
			)
		);

		return $this->normalize_provider_response(
			$http_response,
			$model,
			isset( $http_response['duration_ms'] ) ? absint( $http_response['duration_ms'] ) : 0,
			$payload_image_metadata
		);
	}

	/**
	 * Resolve model from request/config/default.
	 *
	 * @param SCAI_AI_Request      $request AI request.
	 * @param array<string, mixed> $config  Provider config.
	 * @return string
	 */
	private function resolve_model( SCAI_AI_Request $request, array $config ) {
		$model = $request->get_model();

		if ( '' !== $model ) {
			return $model;
		}

		if ( ! empty( $config['model'] ) ) {
			return sanitize_text_field( (string) $config['model'] );
		}

		return $this->get_default_model();
	}

	/**
	 * Build full chat completions endpoint.
	 *
	 * @param string $base_url Provider base URL.
	 * @return string
	 */
	private function build_chat_completions_endpoint( $base_url ) {
		$base_url = untrailingslashit( esc_url_raw( (string) $base_url ) );
		$path     = (string) $this->preset->get( 'endpoint_path', self::CHAT_COMPLETIONS_PATH );

		if ( substr( $base_url, -strlen( $path ) ) === $path ) {
			return $base_url;
		}

		return $base_url . $path;
	}

	/** Get metadata matching the provider's pre-preset behavior exactly. */
	private function get_default_preset_data() {
		return array(
			'key'                         => self::PROVIDER_KEY,
			'label'                       => __( 'OpenAI Compatible', 'supportcandy-ai' ),
			'description'                 => __( 'Connect to OpenAI-compatible chat completion APIs such as OpenAI, OpenRouter, Groq, DeepSeek, and self-hosted compatible endpoints.', 'supportcandy-ai' ),
			'default_base_url'            => '',
			'default_model'               => 'gpt-4o-mini',
			'model_suggestions'           => array(
				'gpt-4o-mini'                    => 'GPT-4o mini',
				'gpt-4o'                         => 'GPT-4o',
				'openai/gpt-4o-mini'             => 'OpenRouter: GPT-4o mini',
				'openai/gpt-4o'                  => 'OpenRouter: GPT-4o',
				'meta-llama/llama-3.1-8b-instant' => 'Groq: Llama 3.1 8B Instant',
				'deepseek-chat'                  => 'DeepSeek Chat',
				'custom'                         => __( 'Custom model', 'supportcandy-ai' ),
			),
			'api_key_label'               => __( 'API Key', 'supportcandy-ai' ),
			'supports_images'             => true,
			'supports_streaming'          => false,
			'base_url_editable'           => true,
			'model_editable'              => true,
			'organization_project_fields' => true,
			'setup_help'                  => '',
			'warning_text'                => '',
			'endpoint_path'               => self::CHAT_COMPLETIONS_PATH,
			'legacy_keys'                 => array(),
		);
	}

	/**
	 * Build HTTP headers.
	 *
	 * @param array<string, mixed> $config Provider config.
	 * @return array<string, string>
	 */
	private function build_headers( array $config ) {
		$headers = array(
			'Authorization' => 'Bearer ' . $config['api_key'],
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);

		if ( ! empty( $config['organization'] ) ) {
			$headers['OpenAI-Organization'] = sanitize_text_field( (string) $config['organization'] );
		}

		if ( ! empty( $config['project'] ) ) {
			$headers['OpenAI-Project'] = sanitize_text_field( (string) $config['project'] );
		}

		return $headers;
	}

	/**
	 * Build provider payload.
	 *
	 * @param SCAI_AI_Request $request AI request.
	 * @param string          $model   Model.
	 * @return array<string, mixed>
	 */
	private function build_payload( SCAI_AI_Request $request, $model ) {
		$payload = array(
			'model'    => sanitize_text_field( (string) $model ),
			'messages' => $this->build_messages( $request ),
			'stream'   => false,
		);

		if ( null !== $request->get_temperature() ) {
			$payload['temperature'] = $request->get_temperature();
		}

		if ( null !== $request->get_max_tokens() ) {
			$payload['max_tokens'] = $request->get_max_tokens();
		}

		return $payload;
	}

	/**
	 * Build chat messages.
	 *
	 * @param SCAI_AI_Request $request AI request.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_messages( SCAI_AI_Request $request ) {
		$messages = array();

		if ( '' !== $request->get_system_instructions() ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => $request->get_system_instructions(),
			);
		}

		foreach ( $request->get_messages() as $message ) {
			if ( empty( $message['role'] ) || empty( $message['content'] ) ) {
				continue;
			}

			$messages[] = array(
				'role'    => sanitize_key( $message['role'] ),
				'content' => sanitize_textarea_field( $message['content'] ),
			);
		}

		if ( '' !== $request->get_prompt() ) {
			$messages[] = array(
				'role'    => 'user',
				'content' => $request->get_prompt(),
			);
		}

		return $this->attach_images_to_last_user_message( $messages, $request );
	}

	/**
	 * Attach transient images to the last user message in OpenAI format.
	 *
	 * @param array<int, array<string, mixed>> $messages Chat messages.
	 * @param SCAI_AI_Request                 $request  AI request.
	 * @return array<int, array<string, mixed>>
	 */
	private function attach_images_to_last_user_message( array $messages, SCAI_AI_Request $request ) {
		if ( ! $request->has_images() || ! $this->supports_images() ) {
			return $messages;
		}

		$last_user_index = null;

		for ( $index = count( $messages ) - 1; $index >= 0; $index-- ) {
			if ( isset( $messages[ $index ]['role'] ) && 'user' === $messages[ $index ]['role'] ) {
				$last_user_index = $index;
				break;
			}
		}

		if ( null === $last_user_index ) {
			return $messages;
		}

		$existing_user_text = isset( $messages[ $last_user_index ]['content'] ) && is_scalar( $messages[ $last_user_index ]['content'] )
			? sanitize_textarea_field( (string) $messages[ $last_user_index ]['content'] )
			: '';
		$content = array(
			array(
				'type' => 'text',
				'text' => $existing_user_text,
			),
		);

		foreach ( $request->get_images() as $image ) {
			$data_url = isset( $image['data_url'] ) && is_scalar( $image['data_url'] ) ? (string) $image['data_url'] : '';

			if ( '' === $data_url || 0 !== strpos( $data_url, 'data:image/' ) ) {
				continue;
			}

			$image_item = array(
				'type'      => 'image_url',
				'image_url' => array(
					'url' => $data_url,
				),
			);

			if ( isset( $image['detail'] ) && in_array( $image['detail'], array( 'low', 'high', 'auto' ), true ) ) {
				$image_item['image_url']['detail'] = $image['detail'];
			}

			$content[] = $image_item;
		}

		$messages[ $last_user_index ]['content'] = $content;

		return $messages;
	}

	/**
	 * Summarize image inclusion in a provider payload without retaining data.
	 *
	 * @param array<string, mixed> $payload Provider payload.
	 * @return array<string, mixed>
	 */
	private function get_safe_payload_image_metadata( array $payload ) {
		$image_count     = 0;
		$multimodal_user = false;
		$messages        = isset( $payload['messages'] ) && is_array( $payload['messages'] ) ? $payload['messages'] : array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || 'user' !== ( isset( $message['role'] ) ? $message['role'] : '' ) || ! isset( $message['content'] ) || ! is_array( $message['content'] ) ) {
				continue;
			}

			foreach ( $message['content'] as $part ) {
				if ( is_array( $part ) && isset( $part['type'] ) && 'image_url' === $part['type'] ) {
					$image_count++;
					$multimodal_user = true;
				}
			}
		}

		return array(
			'provider_payload_had_images'              => 0 < $image_count,
			'provider_payload_image_count'             => $image_count,
			'provider_payload_multimodal_user_message' => $multimodal_user,
		);
	}

	/**
	 * Normalize HTTP/provider response into SCAI_AI_Response.
	 *
	 * @param array<string, mixed> $http_response HTTP client response.
	 * @param string               $model         Model used.
	 * @param int                  $duration_ms   Duration in milliseconds.
	 * @param array<string, mixed> $payload_image_metadata Safe image payload metadata.
	 * @return SCAI_AI_Response
	 */
	private function normalize_provider_response( array $http_response, $model, $duration_ms, array $payload_image_metadata = array() ) {
		if ( empty( $http_response['success'] ) ) {
			$provider_error = $this->extract_provider_error( $http_response );

			return $this->build_error_response(
				$provider_error['code'],
				$provider_error['message'],
				array(
					'provider'     => $this->get_key(),
					'model'        => $model,
					'duration_ms'  => $duration_ms,
					'raw_response' => $this->get_safe_raw_response( $http_response ),
					'metadata'     => $payload_image_metadata,
				)
			);
		}

		$json = isset( $http_response['json'] ) && is_array( $http_response['json'] )
			? $http_response['json']
			: array();

		$content = $this->extract_content( $json );

		if ( '' === $content ) {
			return $this->build_error_response(
				'scai_provider_empty_response',
				__( 'Provider returned an empty response.', 'supportcandy-ai' ),
				array(
					'provider'     => $this->get_key(),
					'model'        => $model,
					'duration_ms'  => $duration_ms,
					'raw_response' => $this->get_safe_raw_response( $http_response ),
					'metadata'     => $payload_image_metadata,
				)
			);
		}

		$usage = isset( $json['usage'] ) && is_array( $json['usage'] )
			? $this->normalize_usage( $json['usage'] )
			: array();

		return $this->build_success_response(
			$content,
			array(
				'provider'          => $this->get_key(),
				'model'             => $this->extract_model( $json, $model ),
				'request_id'        => $this->extract_request_id( $json, $http_response ),
				'prompt_tokens'     => isset( $usage['prompt_tokens'] ) ? $usage['prompt_tokens'] : 0,
				'completion_tokens' => isset( $usage['completion_tokens'] ) ? $usage['completion_tokens'] : 0,
				'total_tokens'      => isset( $usage['total_tokens'] ) ? $usage['total_tokens'] : 0,
				'duration_ms'       => $duration_ms,
				'finish_reason'     => $this->extract_finish_reason( $json ),
				'raw_response'      => $this->get_safe_raw_response( $http_response ),
				'metadata'          => $payload_image_metadata,
			)
		);
	}

	/**
	 * Extract generated content from provider JSON.
	 *
	 * @param array<string, mixed> $json Provider JSON.
	 * @return string
	 */
	private function extract_content( array $json ) {
		if (
			isset( $json['choices'][0]['message']['content'] )
			&& is_string( $json['choices'][0]['message']['content'] )
		) {
			return wp_kses_post( $json['choices'][0]['message']['content'] );
		}

		if (
			isset( $json['choices'][0]['message']['content'] )
			&& is_array( $json['choices'][0]['message']['content'] )
		) {
			return $this->extract_content_parts( $json['choices'][0]['message']['content'] );
		}

		if (
			isset( $json['choices'][0]['text'] )
			&& is_string( $json['choices'][0]['text'] )
		) {
			return wp_kses_post( $json['choices'][0]['text'] );
		}

		return '';
	}

	/**
	 * Extract text from content parts.
	 *
	 * @param array<int, mixed> $content_parts Provider content parts.
	 * @return string
	 */
	private function extract_content_parts( array $content_parts ) {
		$text = '';

		foreach ( $content_parts as $part ) {
			if ( ! is_array( $part ) || empty( $part['text'] ) || ! is_string( $part['text'] ) ) {
				continue;
			}

			$text .= "\n" . $part['text'];
		}

		return wp_kses_post( trim( $text ) );
	}

	/**
	 * Extract normalized provider error details.
	 *
	 * @param array<string, mixed> $http_response HTTP response.
	 * @return array{code: string, message: string}
	 */
	private function extract_provider_error( array $http_response ) {
		$json = isset( $http_response['json'] ) && is_array( $http_response['json'] )
			? $http_response['json']
			: array();

		$error_code    = isset( $http_response['error_code'] ) && '' !== $http_response['error_code']
			? sanitize_key( (string) $http_response['error_code'] )
			: 'scai_provider_http_error';
		$error_message = isset( $http_response['error_message'] ) && '' !== $http_response['error_message']
			? sanitize_text_field( (string) $http_response['error_message'] )
			: __( 'Provider request failed.', 'supportcandy-ai' );

		if ( isset( $json['error'] ) && is_array( $json['error'] ) ) {
			if ( ! empty( $json['error']['code'] ) ) {
				$error_code = sanitize_key( (string) $json['error']['code'] );
			} elseif ( ! empty( $json['error']['type'] ) ) {
				$error_code = sanitize_key( (string) $json['error']['type'] );
			}

			if ( ! empty( $json['error']['message'] ) ) {
				$error_message = sanitize_text_field( (string) $json['error']['message'] );
			}
		} elseif ( isset( $json['error'] ) && is_string( $json['error'] ) ) {
			$error_message = sanitize_text_field( $json['error'] );
		}

		return array(
			'code'    => '' !== $error_code ? $error_code : 'scai_provider_http_error',
			'message' => $error_message,
		);
	}

	/**
	 * Extract response model.
	 *
	 * @param array<string, mixed> $json          Provider JSON.
	 * @param string               $default_model Fallback model.
	 * @return string
	 */
	private function extract_model( array $json, $default_model ) {
		if ( ! empty( $json['model'] ) ) {
			return sanitize_text_field( (string) $json['model'] );
		}

		return sanitize_text_field( (string) $default_model );
	}

	/**
	 * Extract request ID.
	 *
	 * @param array<string, mixed> $json          Provider JSON.
	 * @param array<string, mixed> $http_response HTTP response.
	 * @return string
	 */
	private function extract_request_id( array $json, array $http_response ) {
		if ( ! empty( $json['id'] ) ) {
			return sanitize_text_field( (string) $json['id'] );
		}

		if (
			isset( $http_response['headers']['x-request-id'] )
			&& is_string( $http_response['headers']['x-request-id'] )
		) {
			return sanitize_text_field( $http_response['headers']['x-request-id'] );
		}

		return '';
	}

	/**
	 * Extract finish reason.
	 *
	 * @param array<string, mixed> $json Provider JSON.
	 * @return string
	 */
	private function extract_finish_reason( array $json ) {
		if ( ! empty( $json['choices'][0]['finish_reason'] ) ) {
			return sanitize_key( (string) $json['choices'][0]['finish_reason'] );
		}

		return '';
	}

	/**
	 * Get safe raw response metadata.
	 *
	 * @param array<string, mixed> $http_response HTTP response.
	 * @return array<string, mixed>
	 */
	private function get_safe_raw_response( array $http_response ) {
		return array(
			'status_code' => isset( $http_response['status_code'] ) ? absint( $http_response['status_code'] ) : 0,
			'message'     => isset( $http_response['message'] ) ? sanitize_text_field( (string) $http_response['message'] ) : '',
			'headers'     => isset( $http_response['headers'] ) && is_array( $http_response['headers'] ) ? $this->redact_raw_response_value( $http_response['headers'] ) : array(),
			'json'        => isset( $http_response['json'] ) ? $this->redact_raw_response_value( $http_response['json'] ) : null,
			'request'     => isset( $http_response['request'] ) && is_array( $http_response['request'] ) ? $this->redact_raw_response_value( $http_response['request'] ) : array(),
		);
	}

	/**
	 * Redact sensitive values from raw response metadata.
	 *
	 * @param mixed $value Raw response value.
	 * @param mixed $key   Response key.
	 * @return mixed
	 */
	private function redact_raw_response_value( $value, $key = '' ) {
		if ( $this->is_sensitive_raw_response_key( $key ) ) {
			return '[redacted]';
		}

		if ( is_array( $value ) ) {
			$redacted = array();

			foreach ( $value as $item_key => $item_value ) {
				$redacted[ $item_key ] = $this->redact_raw_response_value( $item_value, $item_key );
			}

			return $redacted;
		}

		if ( is_bool( $value ) || null === $value ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return $value;
		}

		$value = preg_replace( '#data:image/[a-z0-9.+-]+;base64,[a-z0-9+/=]+#i', '[redacted-image-data]', (string) $value );

		return sanitize_textarea_field( (string) $value );
	}

	/**
	 * Check whether a raw response key is sensitive.
	 *
	 * @param mixed $key Raw response key.
	 * @return bool
	 */
	private function is_sensitive_raw_response_key( $key ) {
		$key = sanitize_key( (string) $key );

		return in_array(
			$key,
			array(
				'api_key',
				'apikey',
				'authorization',
				'access_token',
				'refresh_token',
				'secret',
				'password',
			),
			true
		);
	}
}
