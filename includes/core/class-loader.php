<?php
/**
 * Plugin Loader
 *
 * Responsible for loading all required plugin files
 * and starting the main plugin class.
 *
 * @package SupportCandyAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SCAI_Loader' ) ) {

	class SCAI_Loader {

		/**
		 * Initialize plugin.
		 *
		 * @return void
		 */
		public static function init() {

			self::load_files();

			if ( class_exists( 'SCAI_Plugin' ) ) {
				SCAI_Plugin::instance();
			}
		}

		/**
		 * Load required plugin files.
		 *
		 * @return void
		 */
		private static function load_files() {

			$files = array(

				// Database.
				SCAI_PLUGIN_PATH . 'includes/database/class-database.php',
				SCAI_PLUGIN_PATH . 'includes/database/class-migrator.php',

				// Services.
				SCAI_PLUGIN_PATH . 'includes/services/class-settings.php',
				SCAI_PLUGIN_PATH . 'includes/services/class-permissions.php',
				SCAI_PLUGIN_PATH . 'includes/services/class-provider-config.php',
				SCAI_PLUGIN_PATH . 'includes/services/class-http-client.php',
				SCAI_PLUGIN_PATH . 'includes/services/class-attachment-reader.php',
				SCAI_PLUGIN_PATH . 'includes/services/class-image-attachment-preparer.php',
				SCAI_PLUGIN_PATH . 'includes/services/class-custom-knowledge-repository.php',

				// Admin.
				SCAI_PLUGIN_PATH . 'includes/admin/class-admin.php',
				SCAI_PLUGIN_PATH . 'includes/admin/class-assets.php',
				SCAI_PLUGIN_PATH . 'includes/frontend/class-assets.php',
				SCAI_PLUGIN_PATH . 'includes/admin/class-getting-started-page.php',
				SCAI_PLUGIN_PATH . 'includes/admin/class-settings-page.php',
				SCAI_PLUGIN_PATH . 'includes/admin/class-knowledge-sources-page.php',

				// AI.
				SCAI_PLUGIN_PATH . 'includes/ai/class-ai-request.php',
				SCAI_PLUGIN_PATH . 'includes/ai/class-ai-response.php',

				// Usage logging.
				SCAI_PLUGIN_PATH . 'includes/services/class-usage-logger.php',
				SCAI_PLUGIN_PATH . 'includes/services/class-conversation-repository.php',

				// SupportCandy adapter.
				SCAI_PLUGIN_PATH . 'includes/adapter/class-supportcandy-adapter.php',

				// BetterDocs integration.
				SCAI_PLUGIN_PATH . 'includes/integrations/class-betterdocs-adapter.php',
				SCAI_PLUGIN_PATH . 'includes/services/class-knowledge-search-service.php',

				// Context engine.
				SCAI_PLUGIN_PATH . 'includes/ai/class-context-engine.php',
				SCAI_PLUGIN_PATH . 'includes/ai/class-prompt-engine.php',

				// Providers.
				SCAI_PLUGIN_PATH . 'includes/providers/interface-provider.php',
				SCAI_PLUGIN_PATH . 'includes/providers/abstract-provider.php',
				SCAI_PLUGIN_PATH . 'includes/providers/class-openai-compatible-provider.php',
				SCAI_PLUGIN_PATH . 'includes/providers/class-provider-manager.php',
				SCAI_PLUGIN_PATH . 'includes/services/class-provider-registry.php',

				// AI engine.
				SCAI_PLUGIN_PATH . 'includes/ai/class-ai-engine.php',
				SCAI_PLUGIN_PATH . 'includes/ai/class-ticket-ai-service.php',
				SCAI_PLUGIN_PATH . 'includes/ai/class-ai-controller.php',

				// Admin provider pages.
				SCAI_PLUGIN_PATH . 'includes/admin/class-provider-settings-page.php',
				SCAI_PLUGIN_PATH . 'includes/admin/class-permissions-page.php',
				SCAI_PLUGIN_PATH . 'includes/admin/class-diagnostics-page.php',
				SCAI_PLUGIN_PATH . 'includes/admin/class-usage-logs-page.php',

				// Core.
				SCAI_PLUGIN_PATH . 'includes/core/class-plugin.php',

			);

			foreach ( $files as $file ) {

				if ( file_exists( $file ) ) {
					require_once $file;
				}
			}
		}
	}
}
