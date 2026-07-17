<?php
/**
 * Provider registry service for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers built-in AI providers.
 *
 * This class does not manage active provider selection, provider configuration,
 * or API requests. It only registers built-in provider instances with the
 * provider manager through filters.
 */
final class SCAI_Provider_Registry {

	/**
	 * Whether registry hooks have been initialized.
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Initialize registry hooks.
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->initialized ) {
			return;
		}

		add_filter( 'scai_registered_providers', array( $this, 'register_builtin_providers' ) );

		$this->initialized = true;
	}

	/**
	 * Register built-in providers.
	 *
	 * @param array<int, mixed> $providers Existing provider instances.
	 * @return array<int, mixed>
	 */
	public function register_builtin_providers( $providers ) {
		if ( ! is_array( $providers ) ) {
			$providers = array();
		}

		$provider = $this->create_openai_compatible_provider();

		if ( $provider instanceof SCAI_Provider_Interface ) {
			$providers[] = $provider;
		}

		return $providers;
	}

	/**
	 * Create OpenAI-compatible provider instance.
	 *
	 * @return SCAI_OpenAI_Compatible_Provider|null
	 */
	private function create_openai_compatible_provider() {
		if ( ! class_exists( 'SCAI_OpenAI_Compatible_Provider' ) ) {
			return null;
		}

		if ( ! class_exists( 'SCAI_HTTP_Client' ) ) {
			return null;
		}

		if ( ! class_exists( 'SCAI_Provider_Preset' ) ) {
			return null;
		}

		$preset = new SCAI_Provider_Preset(
			array(
				'key'                         => SCAI_OpenAI_Compatible_Provider::PROVIDER_KEY,
				'label'                       => __( 'OpenAI Compatible', 'supportcandy-ai' ),
				'description'                 => __( 'Connect to OpenAI-compatible chat completion APIs such as OpenAI, OpenRouter, Groq, DeepSeek, and self-hosted compatible endpoints.', 'supportcandy-ai' ),
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
				'endpoint_path'               => SCAI_OpenAI_Compatible_Provider::CHAT_COMPLETIONS_PATH,
			)
		);

		return new SCAI_OpenAI_Compatible_Provider( new SCAI_HTTP_Client(), $preset );
	}
}
