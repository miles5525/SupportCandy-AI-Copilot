<?php
/**
 * Main Plugin Class
 *
 * Responsible for bootstrapping the plugin.
 *
 * @package SupportCandyAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SCAI_Plugin' ) ) {

	final class SCAI_Plugin {

		/**
		 * Plugin instance.
		 *
		 * @var SCAI_Plugin|null
		 */
		private static $instance = null;

		/**
		 * Get plugin instance.
		 *
		 * @return SCAI_Plugin
		 */
		public static function instance() {

			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor.
		 */
		private function __construct() {

			$this->init_hooks();
		}

		/**
		 * Register WordPress hooks.
		 *
		 * @return void
		 */
		private function init_hooks() {

			add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );

		}

		/**
		 * Plugins Loaded.
		 *
		 * @return void
		 */
		public function plugins_loaded() {

			load_plugin_textdomain(
				'supportcandy-ai',
				false,
				dirname( SCAI_PLUGIN_BASENAME ) . '/languages'
			);

			$this->maybe_migrate_database();
			$this->load_components();
		}

		/**
		 * Run database migrations when required.
		 *
		 * @return void
		 */
		private function maybe_migrate_database() {

			if ( ! class_exists( 'SCAI_Migrator' ) ) {
				return;
			}

			SCAI_Migrator::maybe_migrate();
		}

		/**
		 * Load plugin components.
		 *
		 * @return void
		 */
		private function load_components() {

			$this->init_provider_registry();
			$this->init_ai_controller();
			$this->init_admin_assets();
			$this->init_frontend_assets();
			$this->init_admin();

			/**
			 * Future Components
			 *
			 * Providers
			 * AI Engine
			 * Adapter
			 * Services
			 */
		}

		/**
		 * Initialize built-in provider registration.
		 *
		 * @return void
		 */
		private function init_provider_registry() {

			if ( ! class_exists( 'SCAI_Provider_Registry' ) ) {
				return;
			}

			$provider_registry = new SCAI_Provider_Registry();
			$provider_registry->init();
		}

		/**
		 * Initialize AI AJAX controller.
		 *
		 * @return void
		 */
		private function init_ai_controller() {

			if ( ! class_exists( 'SCAI_AI_Controller' ) ) {
				return;
			}

			$ai_controller = new SCAI_AI_Controller();
			$ai_controller->init();
		}

		/**
		 * Initialize admin assets.
		 *
		 * @return void
		 */
		private function init_admin_assets() {

			if ( ! class_exists( 'SCAI_Admin_Assets' ) ) {
				return;
			}

			$admin_assets = new SCAI_Admin_Assets();
			$admin_assets->init();
		}

		/**
		 * Initialize frontend assets.
		 *
		 * @return void
		 */
		private function init_frontend_assets() {

			if ( ! class_exists( 'SCAI_Frontend_Assets' ) ) {
				return;
			}

			$frontend_assets = new SCAI_Frontend_Assets();
			$frontend_assets->init();
		}

		/**
		 * Initialize admin components.
		 *
		 * @return void
		 */
		private function init_admin() {

			if ( ! is_admin() || ! class_exists( 'SCAI_Admin' ) ) {
				return;
			}

			$admin = new SCAI_Admin();
			$admin->init();
		}

		/**
		 * Prevent cloning.
		 */
		private function __clone() {}

		/**
		 * Prevent unserializing.
		 */
		public function __wakeup() {

			wp_die(
				esc_html__( 'Cheating?', 'supportcandy-ai' )
			);

		}

	}
}
