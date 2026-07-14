<?php
/**
 * Admin settings page for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles rendering and saving the main plugin settings page.
 */
final class SCAI_Settings_Page {

	/**
	 * Required capability for accessing and saving settings.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'scai-settings';

	/**
	 * Nonce action for saving settings.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'scai_save_settings';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'scai_settings_nonce';

	/**
	 * Initialize settings page hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'handle_request' ) );
	}

	/**
	 * Handle settings form submission.
	 *
	 * @return void
	 */
	public function handle_request() {
		if ( ! isset( $_POST['scai_settings_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to save these settings.', 'supportcandy-ai' ),
				esc_html__( 'Permission denied', 'supportcandy-ai' ),
				array(
					'response' => 403,
				)
			);
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		if ( ! class_exists( 'SCAI_Settings' ) ) {
			$this->redirect_with_message( 'settings_unavailable' );
		}

		$posted = wp_unslash( $_POST );

		$retention_days       = isset( $posted['scai_conversation_retention_days'] ) && is_scalar( $posted['scai_conversation_retention_days'] )
			? absint( $posted['scai_conversation_retention_days'] )
			: 30;
		$company_instructions = isset( $posted['scai_company_instructions'] ) && is_scalar( $posted['scai_company_instructions'] )
			? sanitize_textarea_field( $posted['scai_company_instructions'] )
			: '';

		SCAI_Settings::update(
			'conversation_retention_days',
			$retention_days
		);

		SCAI_Settings::update(
			'delete_data_on_uninstall',
			isset( $posted['scai_delete_data_on_uninstall'] ) ? 1 : 0
		);

		SCAI_Settings::update(
			'image_understanding_enabled',
			isset( $posted['scai_image_understanding_enabled'] ) ? 1 : 0
		);

		SCAI_Settings::update(
			'knowledge_sync_enabled',
			isset( $posted['scai_knowledge_sync_enabled'] ) ? 1 : 0
		);

		SCAI_Settings::update(
			'company_instructions',
			$company_instructions
		);

		$this->redirect_with_message( 'settings_saved' );
	}

	/**
	 * Render settings page.
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
			<h1><?php echo esc_html__( 'SupportCandy AI Assistant', 'supportcandy-ai' ); ?></h1>

			<p>
				<?php
				echo esc_html__(
					'Configure AI providers, company instructions, knowledge sources, and usage reports for SupportCandy AI Assistant.',
					'supportcandy-ai'
				);
				?>
			</p>

			<?php $this->render_notice(); ?>

			<?php $this->render_project_status(); ?>

			<hr />

			<?php $this->render_general_settings_form(); ?>

			<hr />

			<?php $this->render_next_steps(); ?>
		</div>
		<?php
	}

	/**
	 * Render admin notice.
	 *
	 * @return void
	 */
	private function render_notice() {
		$message = isset( $_GET['scai_message'] ) ? sanitize_key( wp_unslash( $_GET['scai_message'] ) ) : '';

		if ( '' === $message ) {
			return;
		}

		if ( 'settings_saved' === $message ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php echo esc_html__( 'Settings saved successfully.', 'supportcandy-ai' ); ?></p>
			</div>
			<?php
			return;
		}

		if ( 'settings_unavailable' === $message ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html__( 'Settings service is unavailable. Please check the plugin installation.', 'supportcandy-ai' ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Render project status area.
	 *
	 * @return void
	 */
	private function render_project_status() {
		?>
		<div class="scai-admin-card">
			<h2><?php echo esc_html__( 'Project Status', 'supportcandy-ai' ); ?></h2>

			<table class="widefat striped">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Database Schema', 'supportcandy-ai' ); ?></th>
						<td><?php echo esc_html( $this->get_schema_version_label() ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Conversation Retention', 'supportcandy-ai' ); ?></th>
						<td><?php echo esc_html( $this->get_conversation_retention_label() ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Delete Data on Uninstall', 'supportcandy-ai' ); ?></th>
						<td><?php echo esc_html( $this->get_delete_data_on_uninstall_label() ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Image Understanding', 'supportcandy-ai' ); ?></th>
						<td><?php echo esc_html( $this->get_enabled_label( 'image_understanding_enabled' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Knowledge Sync', 'supportcandy-ai' ); ?></th>
						<td><?php echo esc_html( $this->get_enabled_label( 'knowledge_sync_enabled' ) ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render general settings form.
	 *
	 * @return void
	 */
	private function render_general_settings_form() {
		$retention_days              = $this->get_setting( 'conversation_retention_days', 30 );
		$delete_data_on_uninstall    = $this->is_setting_enabled( 'delete_data_on_uninstall' );
		$image_understanding_enabled = $this->is_setting_enabled( 'image_understanding_enabled' );
		$knowledge_sync_enabled      = $this->is_setting_enabled( 'knowledge_sync_enabled' );
		$company_instructions        = $this->get_setting( 'company_instructions', '' );

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

			<h2><?php echo esc_html__( 'General Settings', 'supportcandy-ai' ); ?></h2>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="scai_conversation_retention_days">
								<?php echo esc_html__( 'Conversation Retention', 'supportcandy-ai' ); ?>
							</label>
						</th>
						<td>
							<input
								type="number"
								id="scai_conversation_retention_days"
								name="scai_conversation_retention_days"
								value="<?php echo esc_attr( absint( $retention_days ) ); ?>"
								min="1"
								max="365"
								step="1"
								class="small-text"
							/>
							<p class="description">
								<?php echo esc_html__( 'Number of days to retain AI conversation history. Default is 30 days.', 'supportcandy-ai' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__( 'Image Understanding', 'supportcandy-ai' ); ?></th>
						<td>
							<label for="scai_image_understanding_enabled">
								<input
									type="checkbox"
									id="scai_image_understanding_enabled"
									name="scai_image_understanding_enabled"
									value="1"
									<?php checked( $image_understanding_enabled ); ?>
								/>
								<?php echo esc_html__( 'Analyze recent ticket screenshots when generating AI context.', 'supportcandy-ai' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__( 'Knowledge Sync', 'supportcandy-ai' ); ?></th>
						<td>
							<label for="scai_knowledge_sync_enabled">
								<input
									type="checkbox"
									id="scai_knowledge_sync_enabled"
									name="scai_knowledge_sync_enabled"
									value="1"
									<?php checked( $knowledge_sync_enabled ); ?>
								/>
								<?php echo esc_html__( 'Enable scheduled knowledge base synchronization.', 'supportcandy-ai' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="scai_company_instructions">
								<?php echo esc_html__( 'Company Instructions', 'supportcandy-ai' ); ?>
							</label>
						</th>
						<td>
							<textarea
								id="scai_company_instructions"
								name="scai_company_instructions"
								rows="8"
								class="large-text"
							><?php echo esc_textarea( $company_instructions ); ?></textarea>
							<p class="description">
								<?php echo esc_html__( 'These instructions will be applied to every AI request. Keep them clear, specific, and company-wide.', 'supportcandy-ai' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__( 'Delete Data on Uninstall', 'supportcandy-ai' ); ?></th>
						<td>
							<label for="scai_delete_data_on_uninstall">
								<input
									type="checkbox"
									id="scai_delete_data_on_uninstall"
									name="scai_delete_data_on_uninstall"
									value="1"
									<?php checked( $delete_data_on_uninstall ); ?>
								/>
								<?php echo esc_html__( 'Delete plugin tables and settings when the plugin is uninstalled.', 'supportcandy-ai' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'Keep this disabled unless you intentionally want to remove all SupportCandy AI Assistant data during uninstall.', 'supportcandy-ai' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( esc_html__( 'Save Settings', 'supportcandy-ai' ), 'primary', 'scai_settings_submit' ); ?>
		</form>
		<?php
	}

	/**
	 * Render next steps area.
	 *
	 * @return void
	 */
	private function render_next_steps() {
		?>
		<div class="scai-admin-card">
			<h2><?php echo esc_html__( 'Next Setup Steps', 'supportcandy-ai' ); ?></h2>

			<ol>
				<li><?php echo esc_html__( 'Configure AI provider management.', 'supportcandy-ai' ); ?></li>
				<li><?php echo esc_html__( 'Add provider API credentials securely.', 'supportcandy-ai' ); ?></li>
				<li><?php echo esc_html__( 'Configure knowledge sources.', 'supportcandy-ai' ); ?></li>
				<li><?php echo esc_html__( 'Enable ticket sidebar features.', 'supportcandy-ai' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Redirect back to settings page with a message.
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

	/**
	 * Get a setting value with fallback support.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	private function get_setting( $key, $default = '' ) {
		if ( class_exists( 'SCAI_Settings' ) ) {
			return SCAI_Settings::get( $key, $default );
		}

		return $default;
	}

	/**
	 * Determine whether a setting is enabled.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	private function is_setting_enabled( $key ) {
		if ( class_exists( 'SCAI_Settings' ) ) {
			return SCAI_Settings::is_enabled( $key );
		}

		return false;
	}

	/**
	 * Get schema version display label.
	 *
	 * @return string
	 */
	private function get_schema_version_label() {
		$version = $this->get_setting( 'schema_version', '' );

		if ( '' === $version ) {
			return __( 'Not installed', 'supportcandy-ai' );
		}

		return sprintf(
			/* translators: %s: Database schema version. */
			__( 'Installed: %s', 'supportcandy-ai' ),
			$version
		);
	}

	/**
	 * Get conversation retention display label.
	 *
	 * @return string
	 */
	private function get_conversation_retention_label() {
		$retention_days = absint( $this->get_setting( 'conversation_retention_days', 30 ) );

		return sprintf(
			/* translators: %d: Number of days. */
			_n( '%d day', '%d days', $retention_days, 'supportcandy-ai' ),
			$retention_days
		);
	}

	/**
	 * Get delete-data-on-uninstall display label.
	 *
	 * @return string
	 */
	private function get_delete_data_on_uninstall_label() {
		return $this->is_setting_enabled( 'delete_data_on_uninstall' )
			? __( 'Enabled', 'supportcandy-ai' )
			: __( 'Disabled', 'supportcandy-ai' );
	}

	/**
	 * Get enabled/disabled label for a boolean setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private function get_enabled_label( $key ) {
		return $this->is_setting_enabled( $key )
			? __( 'Enabled', 'supportcandy-ai' )
			: __( 'Disabled', 'supportcandy-ai' );
	}
}
