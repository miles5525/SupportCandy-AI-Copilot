<?php
/**
 * Admin assets for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin asset registration and enqueueing.
 */
final class SCAI_Admin_Assets {

	/**
	 * Ticket AI script handle.
	 *
	 * @var string
	 */
	const TICKET_AI_SCRIPT_HANDLE = 'scai-admin-ticket-ai';

	/**
	 * Ticket AI style handle.
	 *
	 * @var string
	 */
	const TICKET_AI_STYLE_HANDLE = 'scai-admin-ticket-ai';

	/**
	 * Plugin admin style handle.
	 *
	 * @var string
	 */
	const ADMIN_STYLE_HANDLE = 'scai-admin';

	/**
	 * Initialize admin asset hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( $this->should_enqueue_plugin_admin_style() ) {
			wp_enqueue_style(
				self::ADMIN_STYLE_HANDLE,
				$this->get_asset_url( 'assets/css/admin.css' ),
				array(),
				$this->get_asset_version( 'assets/css/admin.css' )
			);
		}

		if ( ! $this->should_enqueue_ticket_ai_assets( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style(
			self::TICKET_AI_STYLE_HANDLE,
			$this->get_asset_url( 'assets/css/admin-ticket-ai.css' ),
			array(),
			$this->get_asset_version( 'assets/css/admin-ticket-ai.css' )
		);

		wp_enqueue_script(
			self::TICKET_AI_SCRIPT_HANDLE,
			$this->get_asset_url( 'assets/js/admin-ticket-ai.js' ),
			array( 'jquery' ),
			$this->get_asset_version( 'assets/js/admin-ticket-ai.js' ),
			true
		);

		wp_localize_script(
			self::TICKET_AI_SCRIPT_HANDLE,
			'scaiTicketAI',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'scai_ai_action' ),
				'strings' => $this->get_ticket_ai_strings(),
			)
		);
	}

	/**
	 * Determine whether the current screen is owned by this plugin.
	 *
	 * @return bool
	 */
	private function should_enqueue_plugin_admin_style() {
		$page = $this->get_admin_query_string( 'page' );

		return 0 === strpos( $page, 'scai-' );
	}

	/**
	 * Determine whether ticket AI assets should be enqueued.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return bool
	 */
	private function should_enqueue_ticket_ai_assets( $hook_suffix ) {
		$page       = $this->get_admin_query_string( 'page' );
		$section    = $this->get_admin_query_string( 'section' );
		$ticket_id  = $this->get_ticket_id();

		if ( 'wpsc-tickets' !== $page ) {
			return false;
		}

		$can_use_ai = $this->current_user_can_load_ticket_ai( $ticket_id );

		if ( ! $can_use_ai ) {
			return false;
		}

		$context = array(
			'page'       => $page,
			'section'    => $section,
			'ticket_id'  => $ticket_id,
			'can_use_ai' => $can_use_ai,
			'screen_id'  => $this->get_current_screen_id(),
		);

		/**
		 * Filter whether ticket AI admin assets should be enqueued.
		 *
		 * @param bool                 $should      Whether to enqueue assets.
		 * @param string               $hook_suffix Current admin page hook suffix.
		 * @param string               $page        Sanitized page query value.
		 * @param array<string, mixed> $context     Sanitized admin screen context.
		 */
		return (bool) apply_filters( 'scai_should_enqueue_ticket_ai_assets', true, $hook_suffix, $page, $context );
	}

	/**
	 * Get a sanitized admin query string value.
	 *
	 * @param string $key Query parameter key.
	 * @return string
	 */
	private function get_admin_query_string( $key ) {
		$key = sanitize_key( $key );

		if ( '' === $key || ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) {
			return '';
		}

		return sanitize_key( wp_unslash( $_GET[ $key ] ) );
	}

	/**
	 * Get the requested SupportCandy ticket ID.
	 *
	 * @return int
	 */
	private function get_ticket_id() {
		if ( ! isset( $_GET['id'] ) || ! is_scalar( $_GET['id'] ) ) {
			return 0;
		}

		return absint( wp_unslash( $_GET['id'] ) );
	}

	/**
	 * Check whether the current user can load ticket AI assets.
	 *
	 * @param int $ticket_id Optional SupportCandy ticket ID.
	 * @return bool
	 */
	private function current_user_can_load_ticket_ai( $ticket_id = 0 ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$ticket_id = absint( $ticket_id );

		if ( class_exists( 'SCAI_Permissions' ) ) {
			$permissions = new SCAI_Permissions();

			return $permissions->current_user_can_use_ai( $ticket_id, 'backend_ticket_ai' );
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Get current admin screen ID.
	 *
	 * @return string
	 */
	private function get_current_screen_id() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return '';
		}

		$screen = get_current_screen();

		if ( ! $screen || empty( $screen->id ) ) {
			return '';
		}

		return strtolower( sanitize_text_field( $screen->id ) );
	}

	/**
	 * Get plugin asset URL.
	 *
	 * @param string $relative_path Relative asset path.
	 * @return string
	 */
	private function get_asset_url( $relative_path ) {
		$relative_path = ltrim( sanitize_text_field( (string) $relative_path ), '/' );

		if ( defined( 'SCAI_PLUGIN_URL' ) ) {
			return SCAI_PLUGIN_URL . $relative_path;
		}

		return plugins_url( $relative_path, dirname( dirname( __DIR__ ) ) . '/supportcandy-ai.php' );
	}

	/**
	 * Get plugin asset version.
	 *
	 * @param string $relative_path Optional asset path for cache busting.
	 * @return string
	 */
	private function get_asset_version( $relative_path = '' ) {
		$version       = defined( 'SCAI_VERSION' ) ? SCAI_VERSION : '0.9.1';
		$relative_path = ltrim( sanitize_text_field( (string) $relative_path ), '/' );

		if ( '' !== $relative_path && defined( 'SCAI_PLUGIN_PATH' ) ) {
			$file = SCAI_PLUGIN_PATH . $relative_path;

			if ( is_file( $file ) ) {
				return $version . '.' . (string) filemtime( $file );
			}
		}

		return $version;
	}

	/**
	 * Get localized strings for ticket AI admin script.
	 *
	 * @return array<string, string>
	 */
	private function get_ticket_ai_strings() {
		return array(
			'panelTitle'      => __( 'SupportCandy AI', 'supportcandy-ai' ),
			'generateSummary' => __( 'Generate Summary', 'supportcandy-ai' ),
			'generateReply'   => __( 'Generate Reply', 'supportcandy-ai' ),
			'draftLabel'      => __( 'Draft Reply', 'supportcandy-ai' ),
			'improveDraft'    => __( 'Improve Draft', 'supportcandy-ai' ),
			'loading'         => __( 'Working...', 'supportcandy-ai' ),
			'missingConfig'   => __( 'AI actions are not available on this screen.', 'supportcandy-ai' ),
			'missingDraft'    => __( 'Enter a draft reply before improving it.', 'supportcandy-ai' ),
			'requestFailed'   => __( 'AI request failed. Please try again.', 'supportcandy-ai' ),
			'error'           => __( 'Something went wrong.', 'supportcandy-ai' ),
		);
	}
}
