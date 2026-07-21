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
	/** SupportCandy top-level admin menu slug. */
	const SUPPORTCANDY_MENU_SLUG = 'wpsc-tickets';

	/**
	 * Admin menu slug.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'scai-getting-started';

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
	 * Knowledge Sources page instance.
	 *
	 * @var SCAI_Knowledge_Sources_Page|null
	 */
	private $knowledge_sources_page = null;

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

	/** Whether the visible SupportCandy child menu was registered. */
	private $supportcandy_menu_registered = false;

	/**
	 * Initialize admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		$this->init_pages();

		add_action( 'admin_menu', array( $this, 'register_admin_routes' ), 99 );
		add_action( 'in_admin_header', array( $this, 'render_internal_navigation' ) );
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

		if ( class_exists( 'SCAI_Knowledge_Sources_Page' ) ) {
			$this->knowledge_sources_page = new SCAI_Knowledge_Sources_Page();
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
	public function register_admin_routes() {
		if ( $this->supportcandy_parent_menu_exists() ) {
			$this->register_supportcandy_menu();
			$this->register_hidden_compatibility_pages();
			$this->move_supportcandy_menu_after_settings();
			return;
		}

		$this->register_fallback_menu();
	}

	/**
	 * Register the single visible plugin page beneath SupportCandy.
	 *
	 * @return void
	 */
	public function register_supportcandy_menu() {
		if ( $this->supportcandy_menu_registered ) {
			return;
		}

		$hook_suffix = add_submenu_page(
			self::SUPPORTCANDY_MENU_SLUG,
			esc_html__( 'AI Assistant', 'supportcandy-ai' ),
			esc_html__( 'AI Assistant', 'supportcandy-ai' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_getting_started_page' )
		);

		$this->supportcandy_menu_registered = false !== $hook_suffix;
	}

	/**
	 * Check whether SupportCandy registered its top-level admin menu.
	 *
	 * @return bool
	 */
	private function supportcandy_parent_menu_exists() {
		global $menu;

		if ( ! is_array( $menu ) ) {
			return false;
		}

		foreach ( $menu as $menu_item ) {
			if ( isset( $menu_item[2] ) && self::SUPPORTCANDY_MENU_SLUG === $menu_item[2] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Move only AI Assistant immediately after SupportCandy Settings.
	 *
	 * @return void
	 */
	private function move_supportcandy_menu_after_settings() {
		global $submenu;

		if ( ! isset( $submenu[ self::SUPPORTCANDY_MENU_SLUG ] ) || ! is_array( $submenu[ self::SUPPORTCANDY_MENU_SLUG ] ) ) {
			return;
		}

		$items             = array_values( $submenu[ self::SUPPORTCANDY_MENU_SLUG ] );
		$assistant_position = null;
		$settings_position  = null;

		foreach ( $items as $position => $item ) {
			if ( ! isset( $item[2] ) ) {
				continue;
			}

			if ( self::MENU_SLUG === $item[2] ) {
				$assistant_position = $position;
			} elseif ( 'wpsc-settings' === $item[2] ) {
				$settings_position = $position;
			}
		}

		if ( null === $assistant_position || null === $settings_position || $assistant_position === $settings_position + 1 ) {
			return;
		}

		$assistant_item = $items[ $assistant_position ];
		array_splice( $items, $assistant_position, 1 );

		if ( $assistant_position < $settings_position ) {
			--$settings_position;
		}

		array_splice( $items, $settings_position + 1, 0, array( $assistant_item ) );
		$submenu[ self::SUPPORTCANDY_MENU_SLUG ] = $items;
	}

	/** Register the original top-level menu when SupportCandy integration is unavailable. */
	private function register_fallback_menu() {
		add_menu_page(
			esc_html__( 'SupportCandy AI', 'supportcandy-ai' ),
			esc_html__( 'SupportCandy AI', 'supportcandy-ai' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_getting_started_page' ),
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
			'scai-settings',
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
			esc_html__( 'Knowledge Sources', 'supportcandy-ai' ),
			esc_html__( 'Knowledge Sources', 'supportcandy-ai' ),
			self::CAPABILITY,
			'scai-knowledge-sources',
			array( $this, 'render_knowledge_sources_page' )
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

		add_submenu_page(
			null,
			esc_html__( 'AI Providers', 'supportcandy-ai' ),
			esc_html__( 'AI Providers', 'supportcandy-ai' ),
			self::CAPABILITY,
			'scai-provider-settings',
			array( $this, 'render_provider_settings_page' )
		);
	}

	/** Register non-visible routes so bookmarked and internal URLs keep working. */
	private function register_hidden_compatibility_pages() {
		$pages = array(
			array( __( 'SupportCandy AI Settings', 'supportcandy-ai' ), 'scai-settings', array( $this, 'render_settings_page' ) ),
			array( __( 'AI Providers', 'supportcandy-ai' ), 'scai-providers', array( $this, 'render_provider_settings_page' ) ),
			array( __( 'AI Providers', 'supportcandy-ai' ), 'scai-provider-settings', array( $this, 'render_provider_settings_page' ) ),
			array( __( 'AI Permissions', 'supportcandy-ai' ), 'scai-permissions', array( $this, 'render_permissions_page' ) ),
			array( __( 'Knowledge Sources', 'supportcandy-ai' ), 'scai-knowledge-sources', array( $this, 'render_knowledge_sources_page' ) ),
			array( __( 'SupportCandy AI System Check', 'supportcandy-ai' ), 'scai-diagnostics', array( $this, 'render_diagnostics_page' ) ),
			array( __( 'AI Usage Logs', 'supportcandy-ai' ), 'scai-usage-logs', array( $this, 'render_usage_logs_page' ) ),
		);

		foreach ( $pages as $page ) {
			add_submenu_page(
				null,
				esc_html( $page[0] ),
				esc_html( $page[0] ),
				self::CAPABILITY,
				$page[1],
				$page[2]
			);
		}
	}

	/** Render shared navigation on plugin-owned admin routes. */
	public function render_internal_navigation() {
		if ( ! current_user_can( self::CAPABILITY ) || ! isset( $_GET['page'] ) || ! is_scalar( $_GET['page'] ) ) {
			return;
		}

		$current_page = sanitize_key( wp_unslash( $_GET['page'] ) );
		$plugin_pages = array(
			'scai-getting-started'  => __( 'Getting Started', 'supportcandy-ai' ),
			'scai-settings'         => __( 'Settings', 'supportcandy-ai' ),
			'scai-providers'        => __( 'AI Providers', 'supportcandy-ai' ),
			'scai-permissions'      => __( 'AI Permissions', 'supportcandy-ai' ),
			'scai-knowledge-sources' => __( 'Knowledge Sources', 'supportcandy-ai' ),
			'scai-diagnostics'      => __( 'System Check', 'supportcandy-ai' ),
			'scai-usage-logs'       => __( 'Usage Logs', 'supportcandy-ai' ),
		);

		if ( 'scai-provider-settings' === $current_page ) {
			$current_page = 'scai-providers';
		}

		if ( ! isset( $plugin_pages[ $current_page ] ) ) {
			return;
		}

		?>
		<nav class="scai-admin-navigation" aria-label="<?php echo esc_attr__( 'AI Assistant administration', 'supportcandy-ai' ); ?>">
			<?php foreach ( $plugin_pages as $page_slug => $label ) : ?>
				<a class="scai-admin-navigation__link<?php echo $current_page === $page_slug ? ' is-current' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page_slug ) ); ?>"<?php echo $current_page === $page_slug ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
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
	 * Render Knowledge Sources page.
	 *
	 * @return void
	 */
	public function render_knowledge_sources_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'supportcandy-ai' ),
				esc_html__( 'Permission denied', 'supportcandy-ai' ),
				array(
					'response' => 403,
				)
			);
		}

		if ( class_exists( 'SCAI_Knowledge_Sources_Page' ) && $this->knowledge_sources_page instanceof SCAI_Knowledge_Sources_Page ) {
			$this->knowledge_sources_page->render();
			return;
		}

		$this->render_missing_knowledge_sources_page_notice();
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
	 * Render fallback notice when Knowledge Sources is unavailable.
	 *
	 * @return void
	 */
	private function render_missing_knowledge_sources_page_notice() {
		?>
		<div class="wrap scai-admin-page">
			<h1><?php echo esc_html__( 'Knowledge Sources', 'supportcandy-ai' ); ?></h1>

			<div class="notice notice-error">
				<p><?php echo esc_html__( 'Knowledge Sources page is unavailable. Please check the plugin installation.', 'supportcandy-ai' ); ?></p>
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
