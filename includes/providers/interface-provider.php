<?php
/**
 * AI provider interface for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the common contract for all AI provider implementations.
 */
interface SCAI_Provider_Interface {

	/**
	 * Get the unique provider key.
	 *
	 * Example keys may include openai, gemini, openrouter, ollama, groq,
	 * or deepseek. Implementations must return a stable plugin-owned key.
	 *
	 * @return string Provider key.
	 */
	public function get_key();

	/**
	 * Get the human-readable provider name.
	 *
	 * @return string Provider name.
	 */
	public function get_name();

	/**
	 * Get a short provider description.
	 *
	 * @return string Provider description.
	 */
	public function get_description();

	/**
	 * Get the default model identifier for this provider.
	 *
	 * @return string Default model identifier.
	 */
	public function get_default_model();

	/**
	 * Get available model definitions for this provider.
	 *
	 * Implementations should return a list keyed by model identifier when
	 * practical. Each model definition may include label, capabilities, limits,
	 * or other provider-specific metadata.
	 *
	 * @return array<string, mixed> Available model definitions.
	 */
	public function get_available_models();

	/**
	 * Determine whether this provider supports image inputs.
	 *
	 * @return bool True when image inputs are supported.
	 */
	public function supports_images();

	/**
	 * Determine whether this provider supports streamed responses.
	 *
	 * @return bool True when streamed responses are supported.
	 */
	public function supports_streaming();

	/**
	 * Validate provider configuration.
	 *
	 * Implementations should validate required credentials, endpoint settings,
	 * model names, and provider-specific options without exposing sensitive
	 * values in returned errors.
	 *
	 * @param array<string, mixed> $config Provider configuration.
	 * @return true|WP_Error True when valid, WP_Error when invalid.
	 */
	public function validate_config( $config );

	/**
	 * Generate an AI response.
	 *
	 * The request array is intentionally generic so the AI engine can pass
	 * prompts, messages, model, feature context, image references, and future
	 * provider-neutral options without changing this interface.
	 *
	 * @param array<string, mixed> $request Provider-neutral request data.
	 * @param array<string, mixed> $config  Provider configuration.
	 * @return array<string, mixed>|WP_Error Provider-neutral response data or error.
	 */
	public function generate_response( $request, $config );
}
