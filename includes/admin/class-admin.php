<?php
/**
 * Admin area foundation for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin menu registration and routes admin pages.
 */
final class SCAI_Admin {

	/**
	 * Admin menu slug.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'scai-settings';

	/**
	 * Required capability for accessing plugin admin pages.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Getting Started page instance.
	 *
	 * @var SCAI_Getting_Started_Page|null
	 */
	private $getting_started_page = null;

	/**
	 * Settings page instance.
	 *
	 * @var SCAI_Settings_Page|null
	 */
	private $settings_page = null;

	/**
	 * Provider settings page instance.
	 *
	 * @var SCAI_Provider_Settings_Page|null
	 */
	private $provider_settings_page = null;

	/**
	 * Permissions page instance.
	 *
	 * @var SCAI_Permissions_Page|null
	 */
	private $permissions_page = null;

	/**
	 * Diagnostics page instance.
	 *
	 * @var SCAI_Diagnostics_Page|null
	 */
	private $diagnostics_page = null;

	/**
	 * Usage logs page instance.
	 *
	 * @var SCAI_Usage_Logs_Page|null
	 */
	private $usage_logs_page = null;

	/**
	 * Initialize admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		$this->init_pages();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Initialize admin page controllers.
	 *
	 * @return void
	 */
	private function init_pages() {
		if ( class_exists( 'SCAI_Getting_Started_Page' ) ) {
			$this->getting_started_page = new SCAI_Getting_Started_Page();
		}

		if ( class_exists( 'SCAI_Settings_Page' ) ) {
			$this->settings_page = new SCAI_Settings_Page();
			$this->settings_page->init();
		}

		if ( class_exists( 'SCAI_Provider_Settings_Page' ) ) {
			$this->provider_settings_page = new SCAI_Provider_Settings_Page();
			$this->provider_settings_page->init();
		}

		if ( class_exists( 'SCAI_Permissions_Page' ) ) {
			$this->permissions_page = new SCAI_Permissions_Page();
			$this->permissions_page->init();
		}

		if ( class_exists( 'SCAI_Diagnostics_Page' ) ) {
			$this->diagnostics_page = new SCAI_Diagnostics_Page();

			if ( method_exists( $this->diagnostics_page, 'init' ) ) {
				$this->diagnostics_page->init();
			}
		}

		if ( class_exists( 'SCAI_Usage_Logs_Page' ) ) {
			$this->usage_logs_page = new SCAI_Usage_Logs_Page();

			if ( method_exists( $this->usage_logs_page, 'init' ) ) {
				$this->usage_logs_page->init();
			}
		}
	}

	/**
	 * Register plugin admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			esc_html__( 'SupportCandy AI', 'supportcandy-ai' ),
			esc_html__( 'SupportCandy AI', 'supportcandy-ai' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_settings_page' ),
			'dashicons-format-chat',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Getting Started', 'supportcandy-ai' ),
			esc_html__( 'Getting Started', 'supportcandy-ai' ),
			self::CAPABILITY,
			'scai-getting-started',
			array( $this, 'render_getting_started_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'SupportCandy AI Settings', 'supportcandy-ai' ),
			esc_html__( 'Settings', 'supportcandy-ai' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'AI Providers', 'supportcandy-ai' ),
			esc_html__( 'AI Providers', 'supportcandy-ai' ),
			self::CAPABILITY,
			'scai-providers',
			array( $this, 'render_provider_settings_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'AI Permissions', 'supportcandy-ai' ),
			esc_html__( 'AI Permissions', 'supportcandy-ai' ),
			self::CAPABILITY,
			'scai-permissions',
			array( $this, 'render_permissions_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'SupportCandy AI System Check', 'supportcandy-ai' ),
			esc_html__( 'System Check', 'supportcandy-ai' ),
			self::CAPABILITY,
			'scai-diagnostics',
			array( $this, 'render_diagnostics_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'AI Usage Logs', 'supportcandy-ai' ),
			esc_html__( 'Usage Logs', 'supportcandy-ai' ),
			self::CAPABILITY,
			'scai-usage-logs',
			array( $this, 'render_usage_logs_page' )
		);
	}

	/**
	 * Render Getting Started page.
	 *
	 * @return void
	 */
	public function render_getting_started_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'supportcandy-ai' ),
				esc_html__( 'Permission denied', 'supportcandy-ai' ),
				array( 'response' => 403 )
			);
		}

		if ( class_exists( 'SCAI_Getting_Started_Page' ) && $this->getting_started_page instanceof SCAI_Getting_Started_Page ) {
			$this->getting_started_page->render();
			return;
		}

		$this->render_missing_getting_started_page_notice();
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'supportcandy-ai' ),
				esc_html__( 'Permission denied', 'supportcandy-ai' ),
				array(
					'response' => 403,
				)
			);
		}

		if ( class_exists( 'SCAI_Settings_Page' ) && $this->settings_page instanceof SCAI_Settings_Page ) {
			$this->settings_page->render();
			return;
		}

		$this->render_missing_settings_page_notice();
	}

	/**
	 * Render provider settings page.
	 *
	 * @return void
	 */
	public function render_provider_settings_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'supportcandy-ai' ),
				esc_html__( 'Permission denied', 'supportcandy-ai' ),
				array(
					'response' => 403,
				)
			);
		}

		if ( class_exists( 'SCAI_Provider_Settings_Page' ) && $this->provider_settings_page instanceof SCAI_Provider_Settings_Page ) {
			$this->provider_settings_page->render();
			return;
		}

		$this->render_missing_provider_settings_page_notice();
	}

	/**
	 * Render permissions page.
	 *
	 * @return void
	 */
	public function render_permissions_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'supportcandy-ai' ),
				esc_html__( 'Permission denied', 'supportcandy-ai' ),
				array(
					'response' => 403,
				)
			);
		}

		if ( class_exists( 'SCAI_Permissions_Page' ) && $this->permissions_page instanceof SCAI_Permissions_Page ) {
			$this->permissions_page->render();
			return;
		}

		$this->render_missing_permissions_page_notice();
	}

	/**
	 * Render diagnostics page.
	 *
	 * @return void
	 */
	public function render_diagnostics_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'supportcandy-ai' ),
				esc_html__( 'Permission denied', 'supportcandy-ai' ),
				array(
					'response' => 403,
				)
			);
		}

		if ( class_exists( 'SCAI_Diagnostics_Page' ) && $this->diagnostics_page instanceof SCAI_Diagnostics_Page ) {
			$this->diagnostics_page->render();
			return;
		}

		$this->render_missing_diagnostics_page_notice();
	}

	/**
	 * Render usage logs page.
	 *
	 * @return void
	 */
	public function render_usage_logs_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'supportcandy-ai' ),
				esc_html__( 'Permission denied', 'supportcandy-ai' ),
				array(
					'response' => 403,
				)
			);
		}

		if ( class_exists( 'SCAI_Usage_Logs_Page' ) && $this->usage_logs_page instanceof SCAI_Usage_Logs_Page ) {
			$this->usage_logs_page->render();
			return;
		}

		$this->render_missing_usage_logs_page_notice();
	}

	/**
	 * Render fallback notice when settings page controller is unavailable.
	 *
	 * @return void
	 */
	private function render_missing_settings_page_notice() {
		?>
		<div class="wrap scai-admin-page">
			<h1><?php echo esc_html__( 'SupportCandy AI Assistant', 'supportcandy-ai' ); ?></h1>

			<div class="notice notice-error">
				<p>
					<?php
					echo esc_html__(
						'Settings page controller is unavailable. Please check the plugin installation.',
						'supportcandy-ai'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render fallback notice when Getting Started is unavailable.
	 *
	 * @return void
	 */
	private function render_missing_getting_started_page_notice() {
		?>
		<div class="wrap scai-admin-page">
			<h1><?php echo esc_html__( 'Getting Started', 'supportcandy-ai' ); ?></h1>
			<div class="notice notice-error">
				<p><?php echo esc_html__( 'Getting Started page is unavailable. Please check the plugin installation.', 'supportcandy-ai' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render fallback notice when provider settings page controller is unavailable.
	 *
	 * @return void
	 */
	private function render_missing_provider_settings_page_notice() {
		?>
		<div class="wrap scai-admin-page">
			<h1><?php echo esc_html__( 'AI Providers', 'supportcandy-ai' ); ?></h1>

			<div class="notice notice-error">
				<p>
					<?php
					echo esc_html__(
						'Provider settings page controller is unavailable. Please check the plugin installation.',
						'supportcandy-ai'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render fallback notice when permissions page controller is unavailable.
	 *
	 * @return void
	 */
	private function render_missing_permissions_page_notice() {
		?>
		<div class="wrap scai-admin-page">
			<h1><?php echo esc_html__( 'AI Permissions', 'supportcandy-ai' ); ?></h1>

			<div class="notice notice-error">
				<p>
					<?php
					echo esc_html__(
						'Permissions page controller is unavailable. Please check the plugin installation.',
						'supportcandy-ai'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render fallback notice when diagnostics page controller is unavailable.
	 *
	 * @return void
	 */
	private function render_missing_diagnostics_page_notice() {
		?>
		<div class="wrap scai-admin-page">
			<h1><?php echo esc_html__( 'SupportCandy AI System Check', 'supportcandy-ai' ); ?></h1>

			<div class="notice notice-error">
				<p>
					<?php
					echo esc_html__(
						'System Check page controller is unavailable. Please check the plugin installation.',
						'supportcandy-ai'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render fallback notice when usage logs page controller is unavailable.
	 *
	 * @return void
	 */
	private function render_missing_usage_logs_page_notice() {
		?>
		<div class="wrap scai-admin-page">
			<h1><?php echo esc_html__( 'AI Usage Logs', 'supportcandy-ai' ); ?></h1>

			<div class="notice notice-error">
				<p><?php echo esc_html__( 'Usage logs page is not available.', 'supportcandy-ai' ); ?></p>
			</div>
		</div>
		<?php
	}
}
