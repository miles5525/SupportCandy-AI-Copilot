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

		return new SCAI_OpenAI_Compatible_Provider( new SCAI_HTTP_Client() );
	}
}
