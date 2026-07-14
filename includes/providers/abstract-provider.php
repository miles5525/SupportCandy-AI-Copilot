<?php
/**
 * Abstract AI provider for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for AI provider implementations.
 *
 * Concrete providers should extend this class and implement provider-specific
 * request/response handling without duplicating common validation and response
 * helpers.
 */
abstract class SCAI_Abstract_Provider implements SCAI_Provider_Interface {

	/**
	 * Get the unique provider key.
	 *
	 * Concrete providers must return a stable plugin-owned key.
	 *
	 * @return string Provider key.
	 */
	abstract public function get_key();

	/**
	 * Get the human-readable provider name.
	 *
	 * @return string Provider name.
	 */
	abstract public function get_name();

	/**
	 * Generate an AI response.
	 *
	 * Concrete providers own provider-specific request/response handling.
	 *
	 * @param array<string, mixed>|SCAI_AI_Request $request Provider-neutral request data.
	 * @param array<string, mixed>                 $config  Provider configuration.
	 * @return array<string, mixed>|SCAI_AI_Response|WP_Error Provider-neutral response data, response object, or error.
	 */
	abstract public function generate_response( $request, $config );

	/**
	 * Get provider description.
	 *
	 * Concrete providers may override this.
	 *
	 * @return string
	 */
	public function get_description() {
		return '';
	}

	/**
	 * Get default model.
	 *
	 * Concrete providers may override this. By default, this returns the first
	 * available model key when available.
	 *
	 * @return string
	 */
	public function get_default_model() {
		$models = $this->get_available_models();

		if ( empty( $models ) || ! is_array( $models ) ) {
			return '';
		}

		$keys = array_keys( $models );
		$key  = isset( $keys[0] ) ? $keys[0] : '';

		if ( is_string( $key ) && '' !== $key ) {
			return sanitize_text_field( $key );
		}

		$first_model = reset( $models );

		if ( is_string( $first_model ) ) {
			return sanitize_text_field( $first_model );
		}

		if ( is_array( $first_model ) ) {
			if ( isset( $first_model['id'] ) ) {
				return sanitize_text_field( (string) $first_model['id'] );
			}

			if ( isset( $first_model['key'] ) ) {
				return sanitize_text_field( (string) $first_model['key'] );
			}
		}

		return '';
	}

	/**
	 * Get available provider models.
	 *
	 * Concrete providers should override this.
	 *
	 * Expected format:
	 *
	 * array(
	 *     'model-key' => 'Human readable model name',
	 * )
	 *
	 * @return array<string, mixed>
	 */
	public function get_available_models() {
		return array();
	}

	/**
	 * Whether this provider supports image input.
	 *
	 * Concrete providers may override this.
	 *
	 * @return bool
	 */
	public function supports_images() {
		return false;
	}

	/**
	 * Whether this provider supports response streaming.
	 *
	 * Concrete providers may override this.
	 *
	 * @return bool
	 */
	public function supports_streaming() {
		return false;
	}

	/**
	 * Validate provider configuration.
	 *
	 * Concrete providers can override this when validation is more complex.
	 *
	 * @param array<string, mixed> $config Provider configuration.
	 * @return true|WP_Error True when valid, WP_Error when invalid.
	 */
	public function validate_config( $config ) {
		$config = $this->sanitize_config( $config );

		foreach ( $this->get_required_config_fields() as $field ) {
			$field = sanitize_key( $field );

			if ( '' === $field ) {
				continue;
			}

			if ( ! isset( $config[ $field ] ) || '' === trim( (string) $config[ $field ] ) ) {
				return new WP_Error(
					'scai_provider_config_missing_field',
					sprintf(
						/* translators: %s: Provider configuration field name. */
						__( 'Missing required provider configuration field: %s', 'supportcandy-ai' ),
						$field
					),
					array(
						'provider' => $this->get_key(),
						'field'    => $field,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Get required provider configuration fields.
	 *
	 * Concrete providers should override this. For example:
	 *
	 * array( 'api_key' )
	 *
	 * @return array<int, string>
	 */
	protected function get_required_config_fields() {
		return array();
	}

	/**
	 * Sanitize provider configuration.
	 *
	 * This method intentionally preserves secret values as strings because API
	 * keys must not be damaged by overly aggressive sanitization. Do not log the
	 * returned config directly.
	 *
	 * @param mixed $config Raw provider configuration.
	 * @return array<string, mixed>
	 */
	protected function sanitize_config( $config ) {
		if ( ! is_array( $config ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $config as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_nested_array( $value );
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$sanitized[ $key ] = $value;
				continue;
			}

			if ( 'base_url' === $key || false !== strpos( $key, 'url' ) ) {
				$sanitized[ $key ] = esc_url_raw( (string) $value );
				continue;
			}

			$sanitized[ $key ] = sanitize_text_field( (string) $value );
		}

		return $sanitized;
	}

	/**
	 * Sanitize nested provider configuration arrays.
	 *
	 * @param array<mixed> $value Raw nested array.
	 * @return array<string, mixed>
	 */
	protected function sanitize_nested_array( array $value ) {
		$sanitized = array();

		foreach ( $value as $nested_key => $nested_value ) {
			$nested_key = sanitize_key( (string) $nested_key );

			if ( '' === $nested_key ) {
				continue;
			}

			if ( is_array( $nested_value ) ) {
				$sanitized[ $nested_key ] = $this->sanitize_nested_array( $nested_value );
				continue;
			}

			if ( is_bool( $nested_value ) || is_int( $nested_value ) || is_float( $nested_value ) ) {
				$sanitized[ $nested_key ] = $nested_value;
				continue;
			}

			$sanitized[ $nested_key ] = sanitize_text_field( (string) $nested_value );
		}

		return $sanitized;
	}

	/**
	 * Get model for a request.
	 *
	 * If the request does not specify a model, provider default model is used.
	 *
	 * @param mixed $request AI request object or compatible array.
	 * @return string
	 */
	protected function get_model_for_request( $request ) {
		$model = '';

		if ( $request instanceof SCAI_AI_Request ) {
			$model = $request->get_model();
		} elseif ( is_array( $request ) && isset( $request['model'] ) ) {
			$model = sanitize_text_field( (string) $request['model'] );
		}

		if ( '' !== $model ) {
			return $model;
		}

		return $this->get_default_model();
	}

	/**
	 * Get feature key for a request.
	 *
	 * @param mixed $request AI request object or compatible array.
	 * @return string
	 */
	protected function get_feature_for_request( $request ) {
		if ( $request instanceof SCAI_AI_Request ) {
			return $request->get_feature();
		}

		if ( is_array( $request ) && isset( $request['feature'] ) ) {
			return sanitize_key( (string) $request['feature'] );
		}

		return '';
	}

	/**
	 * Validate a normalized AI request.
	 *
	 * @param mixed $request AI request object or compatible array.
	 * @return true|WP_Error True when valid, WP_Error when invalid.
	 */
	protected function validate_request( $request ) {
		if ( $request instanceof SCAI_AI_Request ) {
			if ( $request->is_valid() ) {
				return true;
			}

			return new WP_Error(
				'scai_ai_request_invalid',
				__( 'AI request does not contain a prompt or messages.', 'supportcandy-ai' ),
				array(
					'provider' => $this->get_key(),
				)
			);
		}

		if ( is_array( $request ) ) {
			$ai_request = new SCAI_AI_Request( $request );

			if ( $ai_request->is_valid() ) {
				return true;
			}
		}

		return new WP_Error(
			'scai_ai_request_invalid',
			__( 'Invalid AI request object.', 'supportcandy-ai' ),
			array(
				'provider' => $this->get_key(),
			)
		);
	}

	/**
	 * Normalize request into SCAI_AI_Request.
	 *
	 * @param mixed $request AI request object or compatible array.
	 * @return SCAI_AI_Request|WP_Error
	 */
	protected function normalize_request( $request ) {
		if ( $request instanceof SCAI_AI_Request ) {
			return $request;
		}

		if ( is_array( $request ) ) {
			return new SCAI_AI_Request( $request );
		}

		return new WP_Error(
			'scai_ai_request_invalid',
			__( 'Invalid AI request object.', 'supportcandy-ai' ),
			array(
				'provider' => $this->get_key(),
			)
		);
	}

	/**
	 * Build a normalized successful provider response.
	 *
	 * @param string               $content Generated content.
	 * @param array<string, mixed> $args    Additional response arguments.
	 * @return SCAI_AI_Response
	 */
	protected function build_success_response( $content, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'provider' => $this->get_key(),
				'model'    => '',
			)
		);

		return SCAI_AI_Response::success( $content, $args );
	}

	/**
	 * Build a normalized failed provider response.
	 *
	 * @param string               $error_code    Error code.
	 * @param string               $error_message Error message.
	 * @param array<string, mixed> $args          Additional response arguments.
	 * @return SCAI_AI_Response
	 */
	protected function build_error_response( $error_code, $error_message, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'provider' => $this->get_key(),
				'model'    => '',
			)
		);

		return SCAI_AI_Response::error( $error_code, $error_message, $args );
	}

	/**
	 * Convert WP_Error into normalized AI response.
	 *
	 * @param WP_Error             $error Error object.
	 * @param array<string, mixed> $args  Additional response arguments.
	 * @return SCAI_AI_Response
	 */
	protected function build_error_response_from_wp_error( WP_Error $error, array $args = array() ) {
		return $this->build_error_response(
			$error->get_error_code(),
			$error->get_error_message(),
			$args
		);
	}

	/**
	 * Get safe request metadata for logging.
	 *
	 * This intentionally avoids provider config and API keys.
	 *
	 * @param mixed $request AI request object or compatible array.
	 * @return array<string, mixed>
	 */
	protected function get_safe_request_metadata( $request ) {
		$ai_request = $this->normalize_request( $request );

		if ( is_wp_error( $ai_request ) ) {
			return array();
		}

		return array(
			'feature'     => $ai_request->get_feature(),
			'model'       => $this->get_model_for_request( $ai_request ),
			'has_images'  => $ai_request->has_images(),
			'stream'      => $ai_request->should_stream(),
			'max_tokens'  => $ai_request->get_max_tokens(),
			'temperature' => $ai_request->get_temperature(),
		);
	}

	/**
	 * Convert a value to boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	protected function to_bool( $value ) {
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

	/**
	 * Normalize token usage data.
	 *
	 * @param array<string, mixed> $usage Raw usage data.
	 * @return array<string, int>
	 */
	protected function normalize_usage( array $usage ) {
		$prompt_tokens     = isset( $usage['prompt_tokens'] ) ? absint( $usage['prompt_tokens'] ) : 0;
		$completion_tokens = isset( $usage['completion_tokens'] ) ? absint( $usage['completion_tokens'] ) : 0;
		$total_tokens      = isset( $usage['total_tokens'] ) ? absint( $usage['total_tokens'] ) : 0;

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
	 * Remove sensitive values from provider config.
	 *
	 * Useful for debug metadata, never for authentication.
	 *
	 * @param array<string, mixed> $config Provider config.
	 * @return array<string, mixed>
	 */
	protected function redact_sensitive_config( array $config ) {
		$redacted = array();

		foreach ( $config as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( $this->is_sensitive_config_key( $key ) ) {
				$redacted[ $key ] = '[redacted]';
				continue;
			}

			if ( is_array( $value ) ) {
				$redacted[ $key ] = $this->redact_sensitive_config( $value );
				continue;
			}

			$redacted[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}

		return $redacted;
	}

	/**
	 * Check whether a config key is sensitive.
	 *
	 * @param string $key Config key.
	 * @return bool
	 */
	protected function is_sensitive_config_key( $key ) {
		$key = sanitize_key( $key );

		$sensitive_fragments = array(
			'key',
			'token',
			'secret',
			'password',
			'authorization',
		);

		foreach ( $sensitive_fragments as $fragment ) {
			if ( false !== strpos( $key, $fragment ) ) {
				return true;
			}
		}

		return false;
	}
}
