<?php
/**
 * Provider settings page for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles rendering and saving AI provider settings.
 */
final class SCAI_Provider_Settings_Page {

	/**
	 * Required capability.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'scai-providers';

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'scai_save_provider_settings';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'scai_provider_settings_nonce';

	/**
	 * Provider manager instance.
	 *
	 * @var SCAI_Provider_Manager|null
	 */
	private $provider_manager = null;

	/**
	 * Initialize provider settings page hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'handle_request' ) );
	}

	/**
	 * Handle provider settings form submission.
	 *
	 * @return void
	 */
	public function handle_request() {
		$is_save_request = isset( $_POST['scai_provider_settings_submit'] );
		$is_test_request = isset( $_POST['scai_provider_test_submit'] );

		if ( ! $is_save_request && ! $is_test_request ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to save provider settings.', 'supportcandy-ai' ),
				esc_html__( 'Permission denied', 'supportcandy-ai' ),
				array(
					'response' => 403,
				)
			);
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$this->save_provider_settings_from_request();

		if ( $is_test_request ) {
			$this->handle_test_connection();
		}

		$this->redirect_with_message( 'provider_settings_saved' );
	}

	/**
	 * Save provider settings from the submitted form.
	 *
	 * @return void
	 */
	private function save_provider_settings_from_request() {
		if ( ! class_exists( 'SCAI_Provider_Config' ) || ! class_exists( 'SCAI_Settings' ) || ! class_exists( 'SCAI_Provider_Manager' ) ) {
			$this->redirect_with_message( 'provider_services_unavailable' );
		}

		$posted        = wp_unslash( $_POST );
		$provider_key  = isset( $posted['scai_active_provider'] ) ? sanitize_key( $posted['scai_active_provider'] ) : '';
		$provider_data = isset( $posted['scai_provider_config'] ) && is_array( $posted['scai_provider_config'] )
			? $posted['scai_provider_config']
			: array();

		if ( '' === $provider_key ) {
			$this->redirect_with_message( 'provider_missing' );
		}

		$provider_manager = $this->get_provider_manager();

		if ( ! $provider_manager || ! $provider_manager->has_provider( $provider_key ) ) {
			$this->redirect_with_message( 'provider_invalid' );
		}

		SCAI_Settings::update( 'active_provider', $provider_key );

		SCAI_Provider_Config::update(
			'openai_compatible',
			$this->sanitize_openai_compatible_config( $provider_data ),
			true
		);
	}

	/**
	 * Handle provider test connection request.
	 *
	 * @return void
	 */
	private function handle_test_connection() {
		if ( ! class_exists( 'SCAI_AI_Engine' ) || ! class_exists( 'SCAI_AI_Response' ) ) {
			$this->redirect_with_message( 'provider_test_unavailable' );
		}

		$ai_engine = new SCAI_AI_Engine();
		$response  = $ai_engine->test_connection();

		if ( $response instanceof SCAI_AI_Response && $response->is_success() ) {
			$this->redirect_with_message( 'provider_test_success' );
		}

		$error_message = $response instanceof SCAI_AI_Response
			? $response->get_error_message()
			: __( 'Provider connection test failed.', 'supportcandy-ai' );

		$this->store_provider_test_error_message( $error_message );
		$this->redirect_with_message( 'provider_test_failed' );
	}

	/**
	 * Render provider settings page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'supportcandy-ai' ),
				esc_html__( 'Permission denied', 'supportcandy-ai' ),
				array(
					'response' => 403,
				)
			);
		}

		?>
		<div class="wrap scai-admin-page">
			<h1><?php echo esc_html__( 'AI Providers', 'supportcandy-ai' ); ?></h1>

			<p>
				<?php
				echo esc_html__(
					'Configure the AI provider used by SupportCandy AI Assistant. Only one provider is active at a time.',
					'supportcandy-ai'
				);
				?>
			</p>

			<?php $this->render_notice(); ?>

			<?php $this->render_registered_providers_status(); ?>

			<hr />

			<?php $this->render_provider_form(); ?>
		</div>
		<?php
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	private function render_notice() {
		$message = isset( $_GET['scai_message'] ) ? sanitize_key( wp_unslash( $_GET['scai_message'] ) ) : '';

		if ( '' === $message ) {
			return;
		}

		$test_error_message = 'provider_test_failed' === $message ? $this->get_provider_test_error_message() : '';

		$notices = array(
			'provider_settings_saved'       => array(
				'type' => 'success',
				'text' => __( 'Provider settings saved successfully.', 'supportcandy-ai' ),
			),
			'provider_test_success'         => array(
				'type' => 'success',
				'text' => __( 'Provider connection successful.', 'supportcandy-ai' ),
			),
			'provider_test_failed'          => array(
				'type' => 'error',
				'text' => '' !== $test_error_message
					? sprintf(
						/* translators: %s: Safe provider test error message. */
						__( 'Provider connection failed: %s', 'supportcandy-ai' ),
						$test_error_message
					)
					: __( 'Provider connection failed.', 'supportcandy-ai' ),
			),
			'provider_test_unavailable'     => array(
				'type' => 'error',
				'text' => __( 'Provider connection test is unavailable. Please check the plugin installation.', 'supportcandy-ai' ),
			),
			'provider_services_unavailable' => array(
				'type' => 'error',
				'text' => __( 'Provider services are unavailable. Please check the plugin installation.', 'supportcandy-ai' ),
			),
			'provider_missing'              => array(
				'type' => 'error',
				'text' => __( 'Please select an active provider.', 'supportcandy-ai' ),
			),
			'provider_invalid'              => array(
				'type' => 'error',
				'text' => __( 'Selected provider is not registered.', 'supportcandy-ai' ),
			),
		);

		if ( empty( $notices[ $message ] ) ) {
			return;
		}

		$notice = $notices[ $message ];
		$type   = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';

		?>
		<div class="notice <?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['text'] ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render registered providers status table.
	 *
	 * @return void
	 */
	private function render_registered_providers_status() {
		$provider_manager = $this->get_provider_manager();
		$providers        = $provider_manager ? $provider_manager->get_provider_details() : array();

		?>
		<div class="scai-admin-card">
			<h2><?php echo esc_html__( 'Registered Providers', 'supportcandy-ai' ); ?></h2>

			<?php if ( empty( $providers ) ) : ?>
				<p><?php echo esc_html__( 'No AI providers are currently registered.', 'supportcandy-ai' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Provider', 'supportcandy-ai' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Key', 'supportcandy-ai' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Default Model', 'supportcandy-ai' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Images', 'supportcandy-ai' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Streaming', 'supportcandy-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $providers as $provider ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $provider['name'] ); ?></strong>
									<?php if ( ! empty( $provider['description'] ) ) : ?>
										<p class="description"><?php echo esc_html( $provider['description'] ); ?></p>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $provider['key'] ); ?></code></td>
								<td><code><?php echo esc_html( $provider['default_model'] ); ?></code></td>
								<td><?php echo esc_html( ! empty( $provider['supports_images'] ) ? __( 'Yes', 'supportcandy-ai' ) : __( 'No', 'supportcandy-ai' ) ); ?></td>
								<td><?php echo esc_html( ! empty( $provider['supports_stream'] ) ? __( 'Yes', 'supportcandy-ai' ) : __( 'No', 'supportcandy-ai' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render provider settings form.
	 *
	 * @return void
	 */
	private function render_provider_form() {
		$provider_manager = $this->get_provider_manager();
		$provider_choices = $provider_manager ? $provider_manager->get_provider_choices() : array();
		$active_provider  = $this->get_active_provider_key();

		if ( '' === $active_provider && ! empty( $provider_choices ) ) {
			$provider_keys    = array_keys( $provider_choices );
			$active_provider  = isset( $provider_keys[0] ) ? sanitize_key( $provider_keys[0] ) : '';
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

			<h2><?php echo esc_html__( 'Provider Configuration', 'supportcandy-ai' ); ?></h2>

			<?php if ( empty( $provider_choices ) ) : ?>
				<p><?php echo esc_html__( 'No providers are available to configure.', 'supportcandy-ai' ); ?></p>
			<?php else : ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="scai_active_provider">
									<?php echo esc_html__( 'Active Provider', 'supportcandy-ai' ); ?>
								</label>
							</th>
							<td>
								<select id="scai_active_provider" name="scai_active_provider">
									<?php foreach ( $provider_choices as $provider_key => $provider_name ) : ?>
										<option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $active_provider, $provider_key ); ?>>
											<?php echo esc_html( $provider_name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php echo esc_html__( 'Only one AI provider can be active at a time.', 'supportcandy-ai' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php $this->render_openai_compatible_fields(); ?>

				<p class="submit">
					<?php submit_button( esc_html__( 'Save Provider Settings', 'supportcandy-ai' ), 'primary', 'scai_provider_settings_submit', false ); ?>
					<?php submit_button( esc_html__( 'Test Connection', 'supportcandy-ai' ), 'secondary', 'scai_provider_test_submit', false ); ?>
				</p>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Render OpenAI-compatible provider fields.
	 *
	 * @return void
	 */
	private function render_openai_compatible_fields() {
		$provider_key = 'openai_compatible';
		$config       = $this->get_provider_config( $provider_key, false );
		$models       = $this->get_provider_models( $provider_key );

		$base_url     = isset( $config['base_url'] ) ? $config['base_url'] : '';
		$model        = isset( $config['model'] ) ? $config['model'] : '';
		$api_key      = isset( $config['api_key'] ) ? $config['api_key'] : '';
		$organization = isset( $config['organization'] ) ? $config['organization'] : '';
		$project      = isset( $config['project'] ) ? $config['project'] : '';
		$timeout      = isset( $config['timeout'] ) ? absint( $config['timeout'] ) : 30;

		?>
		<h3><?php echo esc_html__( 'OpenAI-Compatible Settings', 'supportcandy-ai' ); ?></h3>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="scai_openai_compatible_base_url">
							<?php echo esc_html__( 'Base URL', 'supportcandy-ai' ); ?>
						</label>
					</th>
					<td>
						<input
							type="url"
							id="scai_openai_compatible_base_url"
							name="scai_provider_config[openai_compatible][base_url]"
							value="<?php echo esc_attr( $base_url ); ?>"
							class="regular-text"
							placeholder="https://api.openai.com/v1"
						/>
						<p class="description">
							<?php echo esc_html__( 'Example: https://api.openai.com/v1, https://openrouter.ai/api/v1, https://api.groq.com/openai/v1, or another compatible endpoint.', 'supportcandy-ai' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="scai_openai_compatible_api_key">
							<?php echo esc_html__( 'API Key', 'supportcandy-ai' ); ?>
						</label>
					</th>
					<td>
						<input
							type="password"
							id="scai_openai_compatible_api_key"
							name="scai_provider_config[openai_compatible][api_key]"
							value=""
							class="regular-text"
							autocomplete="new-password"
							placeholder="<?php echo esc_attr( '' !== $api_key ? __( 'Existing API key is saved. Leave blank to keep it.', 'supportcandy-ai' ) : '' ); ?>"
						/>
						<?php if ( '' !== $api_key ) : ?>
							<p class="description">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: Masked API key. */
										__( 'Saved key: %s. Leave blank to keep the existing key.', 'supportcandy-ai' ),
										'<code>' . esc_html( $api_key ) . '</code>'
									),
									array(
										'code' => array(),
									)
								);
								?>
							</p>
						<?php else : ?>
							<p class="description">
								<?php echo esc_html__( 'Enter the API key for your selected OpenAI-compatible provider.', 'supportcandy-ai' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="scai_openai_compatible_model">
							<?php echo esc_html__( 'Model', 'supportcandy-ai' ); ?>
						</label>
					</th>
					<td>
						<input
							type="text"
							id="scai_openai_compatible_model"
							name="scai_provider_config[openai_compatible][model]"
							value="<?php echo esc_attr( $model ); ?>"
							class="regular-text"
							list="scai_openai_compatible_models"
							placeholder="gpt-4o-mini"
						/>
						<datalist id="scai_openai_compatible_models">
							<?php foreach ( $models as $model_key => $model_label ) : ?>
								<option value="<?php echo esc_attr( $model_key ); ?>"><?php echo esc_html( $model_label ); ?></option>
							<?php endforeach; ?>
						</datalist>
						<p class="description">
							<?php echo esc_html__( 'Use a model supported by your configured provider.', 'supportcandy-ai' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="scai_openai_compatible_organization">
							<?php echo esc_html__( 'Organization', 'supportcandy-ai' ); ?>
						</label>
					</th>
					<td>
						<input
							type="text"
							id="scai_openai_compatible_organization"
							name="scai_provider_config[openai_compatible][organization]"
							value="<?php echo esc_attr( $organization ); ?>"
							class="regular-text"
						/>
						<p class="description">
							<?php echo esc_html__( 'Optional. Used by some OpenAI-compatible providers.', 'supportcandy-ai' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="scai_openai_compatible_project">
							<?php echo esc_html__( 'Project', 'supportcandy-ai' ); ?>
						</label>
					</th>
					<td>
						<input
							type="text"
							id="scai_openai_compatible_project"
							name="scai_provider_config[openai_compatible][project]"
							value="<?php echo esc_attr( $project ); ?>"
							class="regular-text"
						/>
						<p class="description">
							<?php echo esc_html__( 'Optional. Used by some OpenAI-compatible providers.', 'supportcandy-ai' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="scai_openai_compatible_timeout">
							<?php echo esc_html__( 'Timeout', 'supportcandy-ai' ); ?>
						</label>
					</th>
					<td>
						<input
							type="number"
							id="scai_openai_compatible_timeout"
							name="scai_provider_config[openai_compatible][timeout]"
							value="<?php echo esc_attr( $timeout ); ?>"
							min="1"
							max="120"
							step="1"
							class="small-text"
						/>
						<p class="description">
							<?php echo esc_html__( 'Request timeout in seconds. Default is 30.', 'supportcandy-ai' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
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
	 * Get active provider key.
	 *
	 * @return string
	 */
	private function get_active_provider_key() {
		if ( class_exists( 'SCAI_Settings' ) ) {
			return sanitize_key( SCAI_Settings::get( 'active_provider', '' ) );
		}

		return sanitize_key( get_option( 'scai_active_provider', '' ) );
	}

	/**
	 * Get provider configuration.
	 *
	 * @param string $provider_key    Provider key.
	 * @param bool   $include_secrets Whether to include secrets.
	 * @return array<string, mixed>
	 */
	private function get_provider_config( $provider_key, $include_secrets = false ) {
		if ( ! class_exists( 'SCAI_Provider_Config' ) ) {
			return array();
		}

		return SCAI_Provider_Config::get( $provider_key, $include_secrets );
	}

	/**
	 * Get provider model choices.
	 *
	 * @param string $provider_key Provider key.
	 * @return array<string, string>
	 */
	private function get_provider_models( $provider_key ) {
		$provider_manager = $this->get_provider_manager();

		if ( ! $provider_manager ) {
			return array();
		}

		$provider = $provider_manager->get_provider( $provider_key );

		if ( ! $provider instanceof SCAI_Provider_Interface ) {
			return array();
		}

		return $provider->get_available_models();
	}

	/**
	 * Sanitize OpenAI-compatible provider configuration from posted data.
	 *
	 * @param array<string, mixed> $provider_data Posted provider config data.
	 * @return array<string, mixed>
	 */
	private function sanitize_openai_compatible_config( array $provider_data ) {
		$config = isset( $provider_data['openai_compatible'] ) && is_array( $provider_data['openai_compatible'] )
			? $provider_data['openai_compatible']
			: array();

		return array(
			'base_url'     => isset( $config['base_url'] ) ? esc_url_raw( (string) $config['base_url'] ) : '',
			'api_key'      => isset( $config['api_key'] ) ? sanitize_text_field( (string) $config['api_key'] ) : '',
			'model'        => isset( $config['model'] ) ? sanitize_text_field( (string) $config['model'] ) : '',
			'organization' => isset( $config['organization'] ) ? sanitize_text_field( (string) $config['organization'] ) : '',
			'project'      => isset( $config['project'] ) ? sanitize_text_field( (string) $config['project'] ) : '',
			'timeout'      => isset( $config['timeout'] ) ? absint( $config['timeout'] ) : SCAI_Provider_Config::DEFAULT_TIMEOUT,
		);
	}

	/**
	 * Store temporary provider test error message for redirect notice.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private function store_provider_test_error_message( $message ) {
		set_transient(
			$this->get_provider_test_error_transient_key(),
			$this->sanitize_provider_test_error_message( $message ),
			2 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Get and delete temporary provider test error message.
	 *
	 * @return string
	 */
	private function get_provider_test_error_message() {
		$transient_key = $this->get_provider_test_error_transient_key();
		$message       = get_transient( $transient_key );

		delete_transient( $transient_key );

		return is_string( $message ) ? sanitize_text_field( $message ) : '';
	}

	/**
	 * Get provider test error transient key.
	 *
	 * @return string
	 */
	private function get_provider_test_error_transient_key() {
		return 'scai_provider_test_error_' . absint( get_current_user_id() );
	}

	/**
	 * Sanitize and redact provider test error message.
	 *
	 * @param string $message Error message.
	 * @return string
	 */
	private function sanitize_provider_test_error_message( $message ) {
		$message = sanitize_text_field( (string) $message );

		if ( class_exists( 'SCAI_Provider_Config' ) ) {
			$config  = SCAI_Provider_Config::get_active_config( true );
			$api_key = isset( $config['api_key'] ) ? sanitize_text_field( (string) $config['api_key'] ) : '';

			if ( '' !== $api_key ) {
				$message = str_replace( $api_key, '[redacted]', $message );
			}
		}

		return $message;
	}

	/**
	 * Redirect back to provider settings page with a message.
	 *
	 * @param string $message Message key.
	 * @return void
	 */
	private function redirect_with_message( $message ) {
		$url = add_query_arg(
			array(
				'page'         => self::PAGE_SLUG,
				'scai_message' => sanitize_key( $message ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
