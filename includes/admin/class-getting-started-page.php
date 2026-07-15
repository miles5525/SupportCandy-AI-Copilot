<?php
/**
 * Getting Started page for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a read-only setup checklist and recommended first test flow.
 */
final class SCAI_Getting_Started_Page {

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
	const PAGE_SLUG = 'scai-getting-started';

	/**
	 * Render the Getting Started page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'supportcandy-ai' ),
				esc_html__( 'Permission denied', 'supportcandy-ai' ),
				array( 'response' => 403 )
			);
		}

		$supportcandy_ready = $this->is_supportcandy_available();
		$provider_ready     = $this->is_provider_configured();
		$permissions        = $this->get_permissions_status();
		$required_complete  = (int) $supportcandy_ready + (int) $provider_ready + (int) $permissions['ready'];
		$betterdocs         = $this->get_betterdocs_status();
		$image_enabled      = $this->get_setting( 'image_understanding_enabled', false );
		?>
		<div class="wrap scai-admin-page scai-getting-started-wrap">
			<div class="scai-setup-hero">
				<h1><?php echo esc_html__( 'Welcome to SupportCandy AI Assistant', 'supportcandy-ai' ); ?></h1>
				<p><?php echo esc_html__( 'Configure your AI provider, permissions, and optional knowledge sources before using AI on tickets.', 'supportcandy-ai' ); ?></p>
				<strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: Completed required steps. 2: Total required steps. */
							__( 'Setup progress: %1$d of %2$d required steps complete', 'supportcandy-ai' ),
							$required_complete,
							4
						)
					);
					?>
				</strong>
			</div>

			<h2><?php echo esc_html__( 'Required Setup', 'supportcandy-ai' ); ?></h2>
			<div class="scai-setup-grid">
				<?php
				$this->render_card(
					$supportcandy_ready ? 'complete' : 'warning',
					$supportcandy_ready ? __( 'Complete', 'supportcandy-ai' ) : __( 'Needs attention', 'supportcandy-ai' ),
					__( 'SupportCandy detected', 'supportcandy-ai' ),
					$supportcandy_ready
						? __( 'SupportCandy is available for ticket context and access checks.', 'supportcandy-ai' )
						: __( 'SupportCandy is missing or its required data source is unavailable.', 'supportcandy-ai' ),
					array(
						array(
							'label' => __( 'Open SupportCandy Tickets', 'supportcandy-ai' ),
							'url'   => admin_url( 'admin.php?page=wpsc-tickets' ),
						),
					)
				);

				$this->render_card(
					$provider_ready ? 'complete' : 'warning',
					$provider_ready ? __( 'Complete', 'supportcandy-ai' ) : __( 'Needs attention', 'supportcandy-ai' ),
					__( 'AI provider configured', 'supportcandy-ai' ),
					$provider_ready
						? __( 'An active provider has the required configuration. This page does not test the connection.', 'supportcandy-ai' )
						: __( 'Choose an active provider and complete its required configuration.', 'supportcandy-ai' ),
					array(
						array(
							'label' => __( 'Configure AI Providers', 'supportcandy-ai' ),
							'url'   => admin_url( 'admin.php?page=scai-providers' ),
						),
					)
				);

				$this->render_card(
					$permissions['ready'] ? 'complete' : 'warning',
					$permissions['ready'] ? __( 'Complete', 'supportcandy-ai' ) : __( 'Needs attention', 'supportcandy-ai' ),
					__( 'AI permissions', 'supportcandy-ai' ),
					$permissions['message'],
					array(
						array(
							'label' => __( 'Review AI Permissions', 'supportcandy-ai' ),
							'url'   => admin_url( 'admin.php?page=scai-permissions' ),
						),
					)
				);

				$this->render_card(
					'instructional',
					__( 'Manual step', 'supportcandy-ai' ),
					__( 'Open a SupportCandy ticket', 'supportcandy-ai' ),
					__( 'Open a ticket and use AI Assist from the ticket page. This step is not tracked automatically.', 'supportcandy-ai' ),
					array(
						array(
							'label' => __( 'Open SupportCandy Tickets', 'supportcandy-ai' ),
							'url'   => admin_url( 'admin.php?page=wpsc-tickets' ),
						),
					)
				);
				?>
			</div>

			<h2><?php echo esc_html__( 'Optional Enhancements', 'supportcandy-ai' ); ?></h2>
			<div class="scai-setup-grid">
				<?php
				$this->render_card(
					$betterdocs['type'],
					$betterdocs['label'],
					__( 'BetterDocs Knowledge Base', 'supportcandy-ai' ),
					$betterdocs['message'],
					array(
						array(
							'label' => __( 'Open Settings', 'supportcandy-ai' ),
							'url'   => admin_url( 'admin.php?page=scai-settings' ),
						),
						array(
							'label' => __( 'Run System Check', 'supportcandy-ai' ),
							'url'   => admin_url( 'admin.php?page=scai-diagnostics' ),
						),
					)
				);

				$this->render_card(
					$image_enabled ? 'complete' : 'optional',
					$image_enabled ? __( 'Enabled', 'supportcandy-ai' ) : __( 'Disabled', 'supportcandy-ai' ),
					__( 'Image Understanding', 'supportcandy-ai' ),
					$image_enabled
						? __( 'Image understanding is enabled and also depends on provider and model image support.', 'supportcandy-ai' )
						: __( 'Optional image understanding is disabled. Enable it only with a supported provider and model.', 'supportcandy-ai' ),
					array(
						array(
							'label' => __( 'Open Settings', 'supportcandy-ai' ),
							'url'   => admin_url( 'admin.php?page=scai-settings' ),
						),
					)
				);

				$this->render_card(
					'optional',
					__( 'Recommended', 'supportcandy-ai' ),
					__( 'System Check', 'supportcandy-ai' ),
					__( 'Use System Check to verify SupportCandy, BetterDocs, attachments, and image readiness.', 'supportcandy-ai' ),
					array(
						array(
							'label' => __( 'Open System Check', 'supportcandy-ai' ),
							'url'   => admin_url( 'admin.php?page=scai-diagnostics' ),
						),
					)
				);

				$this->render_card(
					'optional',
					__( 'Recommended', 'supportcandy-ai' ),
					__( 'Usage Logs', 'supportcandy-ai' ),
					__( 'Use Usage Logs to review AI requests and provider errors.', 'supportcandy-ai' ),
					array(
						array(
							'label' => __( 'Open Usage Logs', 'supportcandy-ai' ),
							'url'   => admin_url( 'admin.php?page=scai-usage-logs' ),
						),
					)
				);
				?>
			</div>

			<div class="scai-setup-steps">
				<h2><?php echo esc_html__( 'Recommended First Test', 'supportcandy-ai' ); ?></h2>
				<ol>
					<li><?php echo esc_html__( 'Open or create a SupportCandy ticket.', 'supportcandy-ai' ); ?></li>
					<li><?php echo esc_html__( 'Click AI Assist.', 'supportcandy-ai' ); ?></li>
					<li><?php echo esc_html__( 'Generate Summary.', 'supportcandy-ai' ); ?></li>
					<li><?php echo esc_html__( 'Generate Reply.', 'supportcandy-ai' ); ?></li>
					<li><?php echo esc_html__( 'Try Improve Current Draft.', 'supportcandy-ai' ); ?></li>
					<li><?php echo esc_html__( 'Try Merge with my draft.', 'supportcandy-ai' ); ?></li>
					<li><?php echo esc_html__( 'Review Usage Logs.', 'supportcandy-ai' ); ?></li>
				</ol>
			</div>

			<div class="scai-privacy-note">
				<strong><?php echo esc_html__( 'Privacy reminder', 'supportcandy-ai' ); ?></strong>
				<p><?php echo esc_html__( 'Ticket content may be sent to the configured AI provider when an agent uses AI actions. Always review AI-generated replies before sending.', 'supportcandy-ai' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one setup card.
	 *
	 * @param string $type        Status type.
	 * @param string $label       Status label.
	 * @param string $title       Card title.
	 * @param string $description Card description.
	 * @param array  $actions     Action links.
	 * @return void
	 */
	private function render_card( $type, $label, $title, $description, array $actions = array() ) {
		$type = in_array( $type, array( 'complete', 'warning', 'optional', 'instructional' ), true ) ? $type : 'optional';
		?>
		<section class="scai-setup-card">
			<span class="scai-status-badge scai-status-<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></span>
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php echo esc_html( $description ); ?></p>
			<?php if ( ! empty( $actions ) ) : ?>
				<div class="scai-setup-actions">
					<?php foreach ( $actions as $action ) : ?>
						<?php if ( ! empty( $action['url'] ) && ! empty( $action['label'] ) ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( $action['url'] ); ?>"><?php echo esc_html( $action['label'] ); ?></a>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Check SupportCandy adapter availability.
	 *
	 * @return bool
	 */
	private function is_supportcandy_available() {
		if ( ! class_exists( 'SCAI_SupportCandy_Adapter' ) ) {
			return false;
		}

		try {
			$adapter = new SCAI_SupportCandy_Adapter();
			return $adapter->is_available();
		} catch ( Throwable $exception ) {
			return false;
		}
	}

	/**
	 * Check whether required provider configuration appears present.
	 *
	 * No provider connection or API request is made.
	 *
	 * @return bool
	 */
	private function is_provider_configured() {
		if ( ! class_exists( 'SCAI_Provider_Config' ) ) {
			return false;
		}

		try {
			$provider_key = SCAI_Provider_Config::get_active_provider_key();

			if ( '' === $provider_key ) {
				return false;
			}

			$config = SCAI_Provider_Config::get( $provider_key, true );

			return ! empty( $config['api_key'] ) && ! empty( $config['base_url'] ) && ! empty( $config['model'] );
		} catch ( Throwable $exception ) {
			return false;
		}
	}

	/**
	 * Get permissions readiness and selection summary.
	 *
	 * @return array{ready: bool, message: string}
	 */
	private function get_permissions_status() {
		if ( ! class_exists( 'SCAI_Permissions' ) ) {
			return array(
				'ready'   => false,
				'message' => __( 'The AI permissions service is unavailable.', 'supportcandy-ai' ),
			);
		}

		try {
			$permissions = new SCAI_Permissions();
			$role_ids    = $permissions->get_allowed_supportcandy_role_ids();

			return array(
				'ready'   => true,
				'message' => empty( $role_ids )
					? __( 'All active SupportCandy agents can use AI.', 'supportcandy-ai' )
					: __( 'AI access is restricted to selected SupportCandy roles.', 'supportcandy-ai' ),
			);
		} catch ( Throwable $exception ) {
			return array(
				'ready'   => false,
				'message' => __( 'AI permissions could not be checked.', 'supportcandy-ai' ),
			);
		}
	}

	/**
	 * Get safe BetterDocs setup status.
	 *
	 * @return array{type: string, label: string, message: string}
	 */
	private function get_betterdocs_status() {
		$unavailable = array(
			'type'    => 'optional',
			'label'   => __( 'Optional / Not detected', 'supportcandy-ai' ),
			'message' => __( 'BetterDocs was not detected. Ticket AI features work without it.', 'supportcandy-ai' ),
		);

		if ( ! class_exists( 'SCAI_BetterDocs_Adapter' ) ) {
			return $unavailable;
		}

		try {
			$adapter = new SCAI_BetterDocs_Adapter();

			if ( ! $adapter->is_available() ) {
				return $unavailable;
			}

			$enabled = $this->get_setting( 'enable_betterdocs_kb', false );
			$count   = $this->get_public_betterdocs_count();
			$message = sprintf(
				/* translators: %d: Number of published public BetterDocs documents. */
				_n( '%d published public document detected.', '%d published public documents detected.', $count, 'supportcandy-ai' ),
				$count
			);

			if ( $enabled ) {
				return array(
					'type'    => 'complete',
					'label'   => __( 'Complete', 'supportcandy-ai' ),
					'message' => $message,
				);
			}

			return array(
				'type'    => 'optional',
				'label'   => __( 'Available but disabled', 'supportcandy-ai' ),
				'message' => $message,
			);
		} catch ( Throwable $exception ) {
			return $unavailable;
		}
	}

	/**
	 * Count published non-password BetterDocs documents without reading content.
	 *
	 * @return int
	 */
	private function get_public_betterdocs_count() {
		if ( function_exists( 'is_post_type_viewable' ) ) {
			$post_type = get_post_type_object( SCAI_BetterDocs_Adapter::POST_TYPE );

			if ( ! $post_type || ! is_post_type_viewable( $post_type ) ) {
				return 0;
			}
		}

		$query = new WP_Query(
			array(
				'post_type'              => SCAI_BetterDocs_Adapter::POST_TYPE,
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);

		return max( 0, (int) $query->found_posts );
	}

	/**
	 * Read a registered plugin setting defensively.
	 *
	 * @param string $key     Logical setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	private function get_setting( $key, $default = null ) {
		if ( class_exists( 'SCAI_Settings' ) && is_callable( array( 'SCAI_Settings', 'get' ) ) ) {
			return SCAI_Settings::get( $key, $default );
		}

		return $default;
	}
}
