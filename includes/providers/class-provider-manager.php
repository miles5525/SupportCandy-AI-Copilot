<?php
/**
 * AI provider manager for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages AI provider registration, lookup, and active provider selection.
 *
 * This class does not implement provider-specific API logic. It only manages
 * provider instances that implement SCAI_Provider_Interface.
 */
final class SCAI_Provider_Manager {

	/**
	 * Registered providers.
	 *
	 * @var array<string, SCAI_Provider_Interface>
	 */
	private $providers = array();

	/**
	 * Create provider manager instance.
	 *
	 * @param array<int|string, mixed> $providers Optional provider instances.
	 */
	public function __construct( array $providers = array() ) {
		$this->register_providers( $providers );
		$this->register_filtered_providers();
	}

	/**
	 * Register multiple providers.
	 *
	 * @param array<int|string, mixed> $providers Provider instances.
	 * @return void
	 */
	public function register_providers( array $providers ) {
		foreach ( $providers as $provider ) {
			$this->register_provider( $provider );
		}
	}

	/**
	 * Register providers supplied through filters.
	 *
	 * @return void
	 */
	private function register_filtered_providers() {
		/**
		 * Filter AI providers registered with SupportCandy AI Assistant.
		 *
		 * Concrete provider classes will be added through this filter in future
		 * milestones or directly by the plugin when provider classes exist.
		 *
		 * @param array<int, mixed> $providers Provider instances.
		 */
		$providers = apply_filters( 'scai_registered_providers', array() );

		if ( ! is_array( $providers ) ) {
			return;
		}

		$this->register_providers( $providers );
	}

	/**
	 * Register one provider.
	 *
	 * @param mixed $provider Provider instance.
	 * @return bool True when provider is registered.
	 */
	public function register_provider( $provider ) {
		if ( ! $provider instanceof SCAI_Provider_Interface ) {
			return false;
		}

		$key = sanitize_key( $provider->get_key() );

		if ( '' === $key ) {
			return false;
		}

		$this->providers[ $key ] = $provider;

		return true;
	}

	/**
	 * Get all registered providers.
	 *
	 * @return array<string, SCAI_Provider_Interface>
	 */
	public function get_providers() {
		return $this->providers;
	}

	/**
	 * Get provider by key.
	 *
	 * @param string $provider_key Provider key.
	 * @return SCAI_Provider_Interface|null
	 */
	public function get_provider( $provider_key ) {
		$provider_key = sanitize_key( $provider_key );

		if ( '' === $provider_key ) {
			return null;
		}

		return isset( $this->providers[ $provider_key ] ) ? $this->providers[ $provider_key ] : null;
	}

	/**
	 * Check whether a provider exists.
	 *
	 * @param string $provider_key Provider key.
	 * @return bool
	 */
	public function has_provider( $provider_key ) {
		return $this->get_provider( $provider_key ) instanceof SCAI_Provider_Interface;
	}

	/**
	 * Get provider choices for admin UI.
	 *
	 * @return array<string, string>
	 */
	public function get_provider_choices() {
		$choices = array();

		foreach ( $this->providers as $key => $provider ) {
			$choices[ $key ] = $provider->get_name();
		}

		return $choices;
	}

	/**
	 * Get registered provider details.
	 *
	 * This is safe for display because it does not include API keys or secrets.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_provider_details() {
		$details = array();

		foreach ( $this->providers as $key => $provider ) {
			$details[ $key ] = array(
				'key'             => $provider->get_key(),
				'name'            => $provider->get_name(),
				'description'     => $provider->get_description(),
				'default_model'   => $provider->get_default_model(),
				'models'          => $provider->get_available_models(),
				'supports_images' => (bool) $provider->supports_images(),
				'supports_stream' => (bool) $provider->supports_streaming(),
			);
		}

		return $details;
	}

	/**
	 * Get active provider key.
	 *
	 * @return string
	 */
	public function get_active_provider_key() {
		if ( class_exists( 'SCAI_Settings' ) ) {
			return sanitize_key( SCAI_Settings::get( 'active_provider', '' ) );
		}

		return sanitize_key( get_option( 'scai_active_provider', '' ) );
	}

	/**
	 * Set active provider.
	 *
	 * @param string $provider_key Provider key.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function set_active_provider( $provider_key ) {
		$provider_key = sanitize_key( $provider_key );

		if ( '' === $provider_key ) {
			return new WP_Error(
				'scai_provider_key_missing',
				__( 'Provider key is missing.', 'supportcandy-ai' )
			);
		}

		if ( ! $this->has_provider( $provider_key ) ) {
			return new WP_Error(
				'scai_provider_not_registered',
				__( 'Selected AI provider is not registered.', 'supportcandy-ai' ),
				array(
					'provider' => $provider_key,
				)
			);
		}

		if ( class_exists( 'SCAI_Settings' ) ) {
			SCAI_Settings::update( 'active_provider', $provider_key );
		} else {
			update_option( 'scai_active_provider', $provider_key, false );
		}

		return true;
	}

	/**
	 * Get active provider instance.
	 *
	 * @return SCAI_Provider_Interface|null
	 */
	public function get_active_provider() {
		$provider_key = $this->get_active_provider_key();

		if ( '' === $provider_key ) {
			return null;
		}

		return $this->get_provider( $provider_key );
	}

	/**
	 * Check whether an active provider is available.
	 *
	 * @return bool
	 */
	public function has_active_provider() {
		return $this->get_active_provider() instanceof SCAI_Provider_Interface;
	}

	/**
	 * Validate provider configuration.
	 *
	 * @param string               $provider_key Provider key.
	 * @param array<string, mixed> $config       Provider config.
	 * @return true|WP_Error
	 */
	public function validate_provider_config( $provider_key, array $config ) {
		$provider = $this->get_provider( $provider_key );

		if ( ! $provider instanceof SCAI_Provider_Interface ) {
			return new WP_Error(
				'scai_provider_not_registered',
				__( 'AI provider is not registered.', 'supportcandy-ai' ),
				array(
					'provider' => sanitize_key( $provider_key ),
				)
			);
		}

		return $provider->validate_config( $config );
	}

	/**
	 * Generate a response using the selected provider.
	 *
	 * If no provider key is provided, the active provider is used.
	 *
	 * @param SCAI_AI_Request|array<string, mixed> $request      AI request.
	 * @param array<string, mixed>                 $config       Provider config.
	 * @param string                               $provider_key Optional provider key.
	 * @return SCAI_AI_Response
	 */
	public function generate_response( $request, array $config = array(), $provider_key = '' ) {
		$provider = '' !== $provider_key
			? $this->get_provider( $provider_key )
			: $this->get_active_provider();

		if ( ! $provider instanceof SCAI_Provider_Interface ) {
			return $this->build_error_response(
				'scai_provider_unavailable',
				__( 'No active AI provider is available.', 'supportcandy-ai' ),
				array(
					'provider' => sanitize_key( $provider_key ),
				)
			);
		}

		$validation = $provider->validate_config( $config );

		if ( is_wp_error( $validation ) ) {
			return $this->build_error_response(
				$validation->get_error_code(),
				$validation->get_error_message(),
				array(
					'provider' => $provider->get_key(),
				)
			);
		}

		$normalized_request = $this->normalize_request( $request );

		if ( is_wp_error( $normalized_request ) ) {
			return $this->build_error_response(
				$normalized_request->get_error_code(),
				$normalized_request->get_error_message(),
				array(
					'provider' => $provider->get_key(),
				)
			);
		}

		$response = $provider->generate_response( $normalized_request, $config );

		if ( $response instanceof SCAI_AI_Response ) {
			return $response;
		}

		if ( is_array( $response ) ) {
			$response = wp_parse_args(
				$response,
				array(
					'provider' => $provider->get_key(),
					'model'    => $normalized_request->get_model(),
				)
			);

			return SCAI_AI_Response::from_array( $response );
		}

		if ( is_wp_error( $response ) ) {
			return $this->build_error_response(
				$response->get_error_code(),
				$response->get_error_message(),
				array(
					'provider' => $provider->get_key(),
				)
			);
		}

		return $this->build_error_response(
			'scai_provider_invalid_response',
			__( 'AI provider returned an invalid response.', 'supportcandy-ai' ),
			array(
				'provider' => $provider->get_key(),
			)
		);
	}

	/**
	 * Normalize request into SCAI_AI_Request.
	 *
	 * @param mixed $request Raw request.
	 * @return SCAI_AI_Request|WP_Error
	 */
	private function normalize_request( $request ) {
		if ( $request instanceof SCAI_AI_Request ) {
			return $request;
		}

		if ( is_array( $request ) ) {
			$request = new SCAI_AI_Request( $request );
		}

		if ( $request instanceof SCAI_AI_Request ) {
			if ( $request->is_valid() ) {
				return $request;
			}

			return new WP_Error(
				'scai_ai_request_invalid',
				__( 'AI request does not contain a prompt or messages.', 'supportcandy-ai' )
			);
		}

		return new WP_Error(
			'scai_ai_request_invalid',
			__( 'Invalid AI request.', 'supportcandy-ai' )
		);
	}

	/**
	 * Build normalized error response.
	 *
	 * @param string               $error_code    Error code.
	 * @param string               $error_message Error message.
	 * @param array<string, mixed> $args          Additional response args.
	 * @return SCAI_AI_Response
	 */
	private function build_error_response( $error_code, $error_message, array $args = array() ) {
		if ( class_exists( 'SCAI_AI_Response' ) ) {
			return SCAI_AI_Response::error( $error_code, $error_message, $args );
		}

		return new SCAI_AI_Response(
			array(
				'success'       => false,
				'error_code'    => $error_code,
				'error_message' => $error_message,
				'metadata'      => $args,
			)
		);
	}
}
