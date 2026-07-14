<?php
/**
 * AI engine service for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates provider-neutral AI requests.
 *
 * This class does not implement provider-specific logic, read SupportCandy
 * data, render UI, store credentials, or expose raw provider configuration.
 */
final class SCAI_AI_Engine {

	/**
	 * Default provider test prompt.
	 *
	 * @var string
	 */
	const DEFAULT_TEST_PROMPT = 'Reply with exactly: SupportCandy AI connected';

	/**
	 * Provider manager instance.
	 *
	 * @var SCAI_Provider_Manager|null
	 */
	private $provider_manager = null;

	/**
	 * Usage logger instance.
	 *
	 * @var SCAI_Usage_Logger|null
	 */
	private $usage_logger = null;

	/**
	 * Generate an AI response with the active provider.
	 *
	 * @param SCAI_AI_Request|array<string, mixed> $request AI request.
	 * @return SCAI_AI_Response
	 */
	public function generate_response( $request ) {
		$dependency_check = $this->check_dependencies();

		if ( $dependency_check instanceof SCAI_AI_Response ) {
			return $this->log_and_return_response( $request, $dependency_check );
		}

		$ai_request = $this->normalize_request( $request );

		if ( $ai_request instanceof SCAI_AI_Response ) {
			return $this->log_and_return_response( $request, $ai_request );
		}

		if ( ! $ai_request->is_valid() ) {
			$response = $this->build_error_response(
				'scai_ai_request_invalid',
				__( 'AI request does not contain a prompt or messages.', 'supportcandy-ai' )
			);

			return $this->log_and_return_response( $ai_request, $response );
		}

		$provider_manager = $this->get_provider_manager();

		if ( ! $provider_manager ) {
			$response = $this->build_error_response(
				'scai_provider_manager_unavailable',
				__( 'AI provider manager is unavailable.', 'supportcandy-ai' )
			);

			return $this->log_and_return_response( $ai_request, $response );
		}

		$provider_key = $this->get_active_provider_key( $provider_manager );

		if ( '' === $provider_key ) {
			$response = $this->build_error_response(
				'scai_active_provider_missing',
				__( 'No active AI provider is configured.', 'supportcandy-ai' )
			);

			return $this->log_and_return_response( $ai_request, $response );
		}

		if ( ! $provider_manager->has_provider( $provider_key ) ) {
			$response = $this->build_error_response(
				'scai_provider_not_registered',
				__( 'The active AI provider is not registered.', 'supportcandy-ai' ),
				array(
					'provider' => $provider_key,
				)
			);

			return $this->log_and_return_response(
				$ai_request,
				$response,
				array(
					'provider' => $provider_key,
				)
			);
		}

		$config = SCAI_Provider_Config::get_active_config( true );

		if ( empty( $config ) ) {
			$response = $this->build_error_response(
				'scai_provider_config_missing',
				__( 'Active AI provider configuration is missing.', 'supportcandy-ai' ),
				array(
					'provider' => $provider_key,
				)
			);

			return $this->log_and_return_response(
				$ai_request,
				$response,
				array(
					'provider' => $provider_key,
				)
			);
		}

		$validation = $provider_manager->validate_provider_config( $provider_key, $config );

		if ( is_wp_error( $validation ) ) {
			$response = $this->build_error_response(
				$validation->get_error_code(),
				$validation->get_error_message(),
				array(
					'provider' => $provider_key,
				)
			);

			return $this->log_and_return_response(
				$ai_request,
				$response,
				array(
					'provider' => $provider_key,
				)
			);
		}

		$response = $provider_manager->generate_response( $ai_request, $config, $provider_key );

		if ( $response instanceof SCAI_AI_Response ) {
			return $this->log_and_return_response(
				$ai_request,
				$response,
				array(
					'provider' => $provider_key,
				)
			);
		}

		$response = $this->build_error_response(
			'scai_ai_invalid_response',
			__( 'AI provider returned an invalid response.', 'supportcandy-ai' ),
			array(
				'provider' => $provider_key,
			)
		);

		return $this->log_and_return_response(
			$ai_request,
			$response,
			array(
				'provider' => $provider_key,
			)
		);
	}

	/**
	 * Send a simple provider connection test prompt.
	 *
	 * @param string $prompt Optional test prompt.
	 * @return SCAI_AI_Response
	 */
	public function test_connection( $prompt = '' ) {
		$prompt = sanitize_textarea_field( (string) $prompt );

		if ( '' === $prompt ) {
			$prompt = self::DEFAULT_TEST_PROMPT;
		}

		return $this->generate_response(
			array(
				'feature'     => 'provider_test',
				'prompt'      => $prompt,
				'max_tokens'  => 20,
				'temperature' => 0,
				'metadata'    => array(
					'source' => 'ai_engine_test_connection',
				),
			)
		);
	}

	/**
	 * Check required runtime dependencies.
	 *
	 * @return true|SCAI_AI_Response
	 */
	private function check_dependencies() {
		$required_classes = array(
			'SCAI_AI_Request',
			'SCAI_Provider_Manager',
			'SCAI_Provider_Config',
		);

		foreach ( $required_classes as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				return $this->build_error_response(
					'scai_ai_dependency_missing',
					__( 'A required AI service is unavailable.', 'supportcandy-ai' ),
					array(
						'missing_class' => sanitize_text_field( $class_name ),
					)
				);
			}
		}

		return true;
	}

	/**
	 * Normalize incoming request data.
	 *
	 * @param mixed $request Raw request.
	 * @return SCAI_AI_Request|SCAI_AI_Response
	 */
	private function normalize_request( $request ) {
		if ( $request instanceof SCAI_AI_Request ) {
			return $request;
		}

		if ( is_array( $request ) ) {
			return new SCAI_AI_Request( $request );
		}

		return $this->build_error_response(
			'scai_ai_request_invalid',
			__( 'Invalid AI request.', 'supportcandy-ai' )
		);
	}

	/**
	 * Get provider manager instance.
	 *
	 * @return SCAI_Provider_Manager|null
	 */
	private function get_provider_manager() {
		if ( $this->provider_manager instanceof SCAI_Provider_Manager ) {
			return $this->provider_manager;
		}

		if ( ! class_exists( 'SCAI_Provider_Manager' ) ) {
			return null;
		}

		$this->provider_manager = new SCAI_Provider_Manager();

		return $this->provider_manager;
	}

	/**
	 * Get usage logger instance.
	 *
	 * @return SCAI_Usage_Logger|null
	 */
	private function get_usage_logger() {
		if ( $this->usage_logger instanceof SCAI_Usage_Logger ) {
			return $this->usage_logger;
		}

		if ( ! class_exists( 'SCAI_Usage_Logger' ) ) {
			return null;
		}

		$this->usage_logger = new SCAI_Usage_Logger();

		return $this->usage_logger;
	}

	/**
	 * Log usage and return the original response object.
	 *
	 * Logging failures must not interrupt AI response flow.
	 *
	 * @param mixed                $request  AI request.
	 * @param SCAI_AI_Response     $response AI response.
	 * @param array<string, mixed> $context  Safe logging context.
	 * @return SCAI_AI_Response
	 */
	private function log_and_return_response( $request, SCAI_AI_Response $response, array $context = array() ) {
		$this->log_usage( $request, $response, $context );

		return $response;
	}

	/**
	 * Log AI request usage when the logger is available.
	 *
	 * @param mixed                $request  AI request.
	 * @param SCAI_AI_Response     $response AI response.
	 * @param array<string, mixed> $context  Safe logging context.
	 * @return void
	 */
	private function log_usage( $request, SCAI_AI_Response $response, array $context = array() ) {
		$usage_logger = $this->get_usage_logger();

		if ( ! $usage_logger ) {
			return;
		}

		$context = $this->build_usage_log_context( $request, $response, $context );

		try {
			$usage_logger->log( $request, $response, $context );
		} catch ( Throwable $exception ) {
			return;
		}
	}

	/**
	 * Build safe usage logging context.
	 *
	 * @param mixed                $request  AI request.
	 * @param SCAI_AI_Response     $response AI response.
	 * @param array<string, mixed> $context  Existing context.
	 * @return array<string, mixed>
	 */
	private function build_usage_log_context( $request, SCAI_AI_Response $response, array $context ) {
		$context['feature'] = isset( $context['feature'] ) ? sanitize_key( $context['feature'] ) : $this->get_request_feature( $request );
		$context['provider'] = isset( $context['provider'] ) ? sanitize_key( $context['provider'] ) : $response->get_provider();
		$context['model']    = isset( $context['model'] ) ? sanitize_text_field( $context['model'] ) : $this->get_usage_log_model( $request, $response );

		return $this->sanitize_safe_metadata( $context );
	}

	/**
	 * Get request feature for usage logging.
	 *
	 * @param mixed $request AI request.
	 * @return string
	 */
	private function get_request_feature( $request ) {
		if ( $request instanceof SCAI_AI_Request ) {
			return $request->get_feature();
		}

		if ( is_array( $request ) && isset( $request['feature'] ) ) {
			return sanitize_key( $request['feature'] );
		}

		return '';
	}

	/**
	 * Get model for usage logging.
	 *
	 * @param mixed            $request  AI request.
	 * @param SCAI_AI_Response $response AI response.
	 * @return string
	 */
	private function get_usage_log_model( $request, SCAI_AI_Response $response ) {
		$model = $response->get_model();

		if ( '' !== $model ) {
			return $model;
		}

		if ( $request instanceof SCAI_AI_Request ) {
			return $request->get_model();
		}

		if ( is_array( $request ) && isset( $request['model'] ) ) {
			return sanitize_text_field( (string) $request['model'] );
		}

		return '';
	}

	/**
	 * Get active provider key.
	 *
	 * @param SCAI_Provider_Manager $provider_manager Provider manager.
	 * @return string
	 */
	private function get_active_provider_key( SCAI_Provider_Manager $provider_manager ) {
		$provider_key = '';

		if ( class_exists( 'SCAI_Provider_Config' ) ) {
			$provider_key = SCAI_Provider_Config::get_active_provider_key();
		}

		if ( '' === $provider_key ) {
			$provider_key = $provider_manager->get_active_provider_key();
		}

		return sanitize_key( $provider_key );
	}

	/**
	 * Build a normalized error response.
	 *
	 * @param string               $error_code    Error code.
	 * @param string               $error_message Error message.
	 * @param array<string, mixed> $metadata      Safe response metadata.
	 * @return SCAI_AI_Response
	 */
	private function build_error_response( $error_code, $error_message, array $metadata = array() ) {
		return SCAI_AI_Response::error(
			$error_code,
			$error_message,
			array(
				'metadata' => $this->sanitize_safe_metadata( $metadata ),
			)
		);
	}

	/**
	 * Sanitize non-secret metadata for error responses.
	 *
	 * @param array<string, mixed> $metadata Raw metadata.
	 * @return array<string, mixed>
	 */
	private function sanitize_safe_metadata( array $metadata ) {
		$safe_metadata = array();

		foreach ( $metadata as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || $this->is_sensitive_key( $key ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$safe_metadata[ $key ] = $this->sanitize_safe_metadata( $value );
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$safe_metadata[ $key ] = $value;
				continue;
			}

			$safe_metadata[ $key ] = sanitize_text_field( (string) $value );
		}

		return $safe_metadata;
	}

	/**
	 * Check whether a metadata key may contain sensitive data.
	 *
	 * @param string $key Metadata key.
	 * @return bool
	 */
	private function is_sensitive_key( $key ) {
		$key = sanitize_key( $key );

		foreach ( array( 'key', 'token', 'secret', 'password', 'authorization' ) as $fragment ) {
			if ( false !== strpos( $key, $fragment ) ) {
				return true;
			}
		}

		return false;
	}
}
