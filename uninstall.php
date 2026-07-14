<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$plugin_file      = plugin_dir_path( __FILE__ ) . 'supportcandy-ai.php';
$uninstaller_file = plugin_dir_path( __FILE__ ) . 'includes/installers/class-uninstaller.php';

if ( is_readable( $uninstaller_file ) ) {
	require_once $uninstaller_file;
}

if ( ! class_exists( 'SCAI_Uninstaller' ) ) {
	return;
}

$network_wide = false;

if ( is_multisite() ) {
	$network_wide = is_network_admin();

	if ( ! $network_wide && function_exists( 'is_plugin_active_for_network' ) ) {
		$plugin_basename = plugin_basename( $plugin_file );
		$network_wide    = is_plugin_active_for_network( $plugin_basename );
	}
}

SCAI_Uninstaller::uninstall( $network_wide );