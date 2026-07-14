<?php
/**
 * Frontend assets for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles frontend asset registration and enqueueing.
 */
final class SCAI_Frontend_Assets {

	/**
	 * Ticket AI script handle.
	 *
	 * @var string
	 */
	const TICKET_AI_SCRIPT_HANDLE = 'scai-frontend-ticket-ai';

	/**
	 * Ticket AI style handle.
	 *
	 * @var string
	 */
	const TICKET_AI_STYLE_HANDLE = 'scai-frontend-ticket-ai';

	/**
	 * Initialize frontend asset hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Enqueue frontend assets.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets() {
		if ( ! $this->should_enqueue_ticket_ai_assets() ) {
			return;
		}

		wp_enqueue_style(
			self::TICKET_AI_STYLE_HANDLE,
			$this->get_asset_url( 'assets/css/admin-ticket-ai.css' ),
			array(),
			$this->get_asset_version()
		);

		wp_enqueue_script(
			self::TICKET_AI_SCRIPT_HANDLE,
			$this->get_asset_url( 'assets/js/admin-ticket-ai.js' ),
			array( 'jquery' ),
			$this->get_asset_version(),
			true
		);

		$script_data = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'scai_ai_action' ),
			'strings' => $this->get_ticket_ai_strings(),
		);

		/**
		 * Filter frontend ticket AI script data.
		 *
		 * @param array<string, mixed> $script_data Localized script data.
		 */
		$script_data = apply_filters( 'scai_frontend_ticket_ai_script_data', $script_data );

		if ( ! is_array( $script_data ) ) {
			$script_data = array();
		}

		wp_localize_script(
			self::TICKET_AI_SCRIPT_HANDLE,
			'scaiTicketAI',
			$this->sanitize_script_data( $script_data )
		);
	}

	/**
	 * Determine whether ticket AI frontend assets should be enqueued.
	 *
	 * @return bool
	 */
	private function should_enqueue_ticket_ai_assets() {
		$ticket_id = $this->get_frontend_ticket_id();
		$should    = $this->has_ticket_query_param() || $this->has_supportcandy_content_marker();

		if ( ! $this->current_user_can_load_ticket_ai( $ticket_id ) ) {
			return false;
		}

		/**
		 * Filter whether ticket AI frontend assets should be enqueued.
		 *
		 * @param bool $should Whether to enqueue assets.
		 */
		return (bool) apply_filters( 'scai_should_enqueue_frontend_ticket_ai_assets', $should );
	}

	/**
	 * Check whether the current user can load frontend ticket AI assets.
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

			return $permissions->current_user_can_use_ai( $ticket_id, 'frontend_ticket_ai' );
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Check for ticket-related frontend query parameters.
	 *
	 * @return bool
	 */
	private function has_ticket_query_param() {
		$query_keys = array(
			'ticket-id',
			'ticket_id',
			'id',
			'ticket',
			'wpsc_ticket_id',
		);

		foreach ( $query_keys as $query_key ) {
			if ( ! isset( $_GET[ $query_key ] ) ) {
				continue;
			}

			$value = wp_unslash( $_GET[ $query_key ] );

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) $value );

			if ( '' !== $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get a SupportCandy ticket ID from known frontend query parameters.
	 *
	 * @return int
	 */
	private function get_frontend_ticket_id() {
		$query_keys = array(
			'ticket-id',
			'ticket_id',
			'wpsc_ticket_id',
		);

		foreach ( $query_keys as $query_key ) {
			if ( ! isset( $_GET[ $query_key ] ) || ! is_scalar( $_GET[ $query_key ] ) ) {
				continue;
			}

			$ticket_id = absint( wp_unslash( $_GET[ $query_key ] ) );

			if ( $ticket_id ) {
				return $ticket_id;
			}
		}

		return 0;
	}

	/**
	 * Check current page content for common SupportCandy markers.
	 *
	 * @return bool
	 */
	private function has_supportcandy_content_marker() {
		global $post;

		if ( ! $post || empty( $post->post_content ) ) {
			return false;
		}

		$content = strtolower( sanitize_textarea_field( $post->post_content ) );
		$markers = array(
			'supportcandy',
			'wpsc',
			'wpsc_tickets',
		);

		foreach ( $markers as $marker ) {
			if ( false !== strpos( $content, $marker ) ) {
				return true;
			}
		}

		return false;
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
	 * @return string
	 */
	private function get_asset_version() {
		return defined( 'SCAI_VERSION' ) ? SCAI_VERSION : '1.0.0';
	}

	/**
	 * Get localized strings for ticket AI frontend script.
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

	/**
	 * Sanitize localized script data.
	 *
	 * @param array<string, mixed> $script_data Localized script data.
	 * @return array<string, mixed>
	 */
	private function sanitize_script_data( array $script_data ) {
		$sanitized = array(
			'ajaxUrl' => isset( $script_data['ajaxUrl'] ) ? esc_url_raw( $script_data['ajaxUrl'] ) : admin_url( 'admin-ajax.php' ),
			'nonce'   => isset( $script_data['nonce'] ) ? sanitize_text_field( $script_data['nonce'] ) : wp_create_nonce( 'scai_ai_action' ),
			'strings' => array(),
		);

		if ( isset( $script_data['strings'] ) && is_array( $script_data['strings'] ) ) {
			foreach ( $script_data['strings'] as $key => $value ) {
				if ( ! is_scalar( $value ) ) {
					continue;
				}

				$sanitized['strings'][ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
			}
		}

		return $sanitized;
	}
}
