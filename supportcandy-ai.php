<?php
/**
 * Plugin Name: SupportCandy AI Assistant
 * Plugin URI: https://github.com/
 * Description: A standalone AI Assistant plugin for SupportCandy with support for OpenAI, Gemini, and OpenAI-compatible providers.
 * Version: 0.9.2
 * Author: OmTech Systems
 * Author URI: https://omtechsystems.com
 * License: GPL v2 or later
 * Text Domain: supportcandy-ai
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin Version
 */
define( 'SCAI_VERSION', '0.9.2' );

/**
 * Plugin File
 */
define( 'SCAI_PLUGIN_FILE', __FILE__ );

/**
 * Plugin Path
 */
define( 'SCAI_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin URL
 */
define( 'SCAI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin Basename
 */
define( 'SCAI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load Plugin Installer
 */
require_once SCAI_PLUGIN_PATH . 'includes/installers/class-installer.php';

/**
 * Register Plugin Activation Hook
 */
register_activation_hook( SCAI_PLUGIN_FILE, array( 'SCAI_Installer', 'install' ) );

/**
 * Load Plugin Loader
 */
require_once SCAI_PLUGIN_PATH . 'includes/core/class-loader.php';

/**
 * Initialize Plugin
 */
SCAI_Loader::init();
