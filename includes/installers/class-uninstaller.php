<?php
/**
 * Plugin uninstaller for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin uninstall cleanup tasks.
 *
 * This class should be called from the root uninstall.php file.
 * It must not run during normal plugin deactivation.
 */
final class SCAI_Uninstaller {

	/**
	 * Delete data on uninstall option name.
	 *
	 * When this option is truthy, plugin-owned tables and persistent options
	 * may be removed during uninstall.
	 *
	 * @var string
	 */
	const OPTION_DELETE_DATA_ON_UNINSTALL = 'scai_delete_data_on_uninstall';

	/**
	 * Schema version option name.
	 *
	 * @var string
	 */
	const OPTION_SCHEMA_VERSION = 'scai_schema_version';

	/**
	 * Installed timestamp option name.
	 *
	 * @var string
	 */
	const OPTION_INSTALLED_AT = 'scai_installed_at';

	/**
	 * Conversation retention option name.
	 *
	 * @var string
	 */
	const OPTION_CONVERSATION_RETENTION_DAYS = 'scai_conversation_retention_days';

	/**
	 * Known plugin cron hook names.
	 *
	 * Some of these hooks may be introduced in later milestones. Clearing
	 * non-existing scheduled hooks is safe.
	 *
	 * @var array<int, string>
	 */
	private static $cron_hooks = array(
		'scai_daily_knowledge_sync',
		'scai_daily_conversation_cleanup',
		'scai_usage_log_cleanup',
	);

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}

	/**
	 * Run plugin uninstall cleanup.
	 *
	 * Data is preserved by default. Full data deletion only runs when the
	 * scai_delete_data_on_uninstall option is enabled.
	 *
	 * @param bool $network_wide Whether to uninstall across all sites in multisite.
	 * @return array<string|int, mixed> Cleanup result details.
	 */
	public static function uninstall( $network_wide = false ) {
		$network_wide = (bool) $network_wide;

		if ( is_multisite() && $network_wide ) {
			return self::uninstall_network();
		}

		return self::uninstall_site();
	}

	/**
	 * Run uninstall cleanup for all sites in a multisite network.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function uninstall_network() {
		$results = array();

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			$site_id = (int) $site_id;

			switch_to_blog( $site_id );

			try {
				$results[ $site_id ] = self::uninstall_site();
			} finally {
				restore_current_blog();
			}
		}

		return $results;
	}

	/**
	 * Run uninstall cleanup for the current site.
	 *
	 * @return array<string, mixed>
	 */
	private static function uninstall_site() {
		self::load_dependencies();

		$delete_data = self::should_delete_data();

		$result = array(
			'delete_data'        => $delete_data,
			'cron_hooks_cleared' => self::clear_scheduled_hooks(),
			'tables_dropped'     => array(),
			'options_deleted'    => array(),
		);

		if ( ! $delete_data ) {
			return $result;
		}

		$result['tables_dropped']  = self::drop_tables();
		$result['options_deleted'] = self::delete_options();

		return $result;
	}

	/**
	 * Load uninstaller dependencies.
	 *
	 * @return void
	 */
	private static function load_dependencies() {
		$schema_file = dirname( __DIR__ ) . '/database/class-schema.php';
		$settings_file = dirname( __DIR__ ) . '/services/class-settings.php';

		if ( ! class_exists( 'SCAI_Schema' ) && is_readable( $schema_file ) ) {
			require_once $schema_file;
		}

		if ( ! class_exists( 'SCAI_Settings' ) && is_readable( $settings_file ) ) {
			require_once $settings_file;
		}
	}

	/**
	 * Determine whether persistent plugin data should be deleted.
	 *
	 * @return bool
	 */
	private static function should_delete_data() {
		$value = get_option( self::OPTION_DELETE_DATA_ON_UNINSTALL, false );

		return self::is_truthy_value( $value );
	}

	/**
	 * Check whether a stored option value should be treated as enabled.
	 *
	 * @param mixed $value Option value.
	 * @return bool
	 */
	private static function is_truthy_value( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return 1 === (int) $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'yes', 'true', 'on' ), true );
		}

		return false;
	}

	/**
	 * Clear plugin scheduled hooks.
	 *
	 * @return array<string, bool>
	 */
	private static function clear_scheduled_hooks() {
		$results = array();

		foreach ( self::$cron_hooks as $hook ) {
			$hook              = sanitize_key( $hook );
			$results[ $hook ] = (bool) wp_clear_scheduled_hook( $hook );
		}

		return $results;
	}

	/**
	 * Drop plugin-owned database tables.
	 *
	 * @return array<string, bool>
	 */
	private static function drop_tables() {
		global $wpdb;

		if ( ! class_exists( 'SCAI_Schema' ) ) {
			return array();
		}

		$results     = array();
		$table_names = SCAI_Schema::get_table_names();

		foreach ( $table_names as $table_key => $table_name ) {
			$table_key  = sanitize_key( $table_key );
			$table_name = self::sanitize_table_name( $table_name );

			if ( '' === $table_name ) {
				$results[ $table_key ] = false;
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table names cannot be passed as placeholders. The identifier is restricted to plugin-owned schema names and sanitized before use.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );

			$results[ $table_key ] = ! self::table_exists( $table_name );
		}

		return $results;
	}

	/**
	 * Delete plugin-owned persistent options.
	 *
	 * @return array<string, bool>
	 */
	private static function delete_options() {
		$results = array();

		foreach ( self::get_option_keys_to_delete() as $option_name ) {
			$option_name             = sanitize_key( $option_name );
			$results[ $option_name ] = delete_option( $option_name );
		}

		// Clear the known plugin migration lock without scanning unrelated transients.
		delete_transient( 'scai_database_migration_lock' );

		return $results;
	}

	/**
	 * Get the explicit list of plugin-owned options removed during destructive uninstall.
	 *
	 * Provider configurations are intentionally included because they may contain
	 * API credentials. This list never includes SupportCandy or WordPress options.
	 *
	 * @return array<int, string>
	 */
	private static function get_option_keys_to_delete() {
		/*
		 * Keep a complete fallback for uninstall contexts where optional runtime
		 * services cannot be loaded.
		 */
		$option_keys = array(
			self::OPTION_SCHEMA_VERSION,
			self::OPTION_INSTALLED_AT,
			self::OPTION_CONVERSATION_RETENTION_DAYS,
			self::OPTION_DELETE_DATA_ON_UNINSTALL,
			'scai_active_provider',
			'scai_company_instructions',
			'scai_image_understanding_enabled',
			'scai_knowledge_sync_enabled',
			'scai_last_knowledge_sync_at',
			'scai_provider_configs',
			'scai_allowed_supportcandy_role_ids',
		);

		if ( class_exists( 'SCAI_Settings' ) && method_exists( 'SCAI_Settings', 'get_option_names' ) ) {
			$registered_options = SCAI_Settings::get_option_names();

			if ( is_array( $registered_options ) ) {
				$option_keys = array_merge( $option_keys, array_values( $registered_options ) );
			}
		}

		$option_keys = array_filter(
			array_map( 'sanitize_key', $option_keys ),
			static function ( $option_key ) {
				return is_string( $option_key ) && 0 === strpos( $option_key, 'scai_' );
			}
		);

		return array_values( array_unique( $option_keys ) );
	}

	/**
	 * Sanitize a database table name for identifier usage.
	 *
	 * WordPress does not support preparing table identifiers as placeholders,
	 * so this method restricts table names to safe identifier characters.
	 *
	 * @param string $table_name Full database table name.
	 * @return string
	 */
	private static function sanitize_table_name( $table_name ) {
		$table_name = (string) $table_name;

		if ( '' === $table_name ) {
			return '';
		}

		return preg_replace( '/[^A-Za-z0-9_]/', '', $table_name );
	}

	/**
	 * Check whether a database table exists.
	 *
	 * @param string $table_name Full database table name.
	 * @return bool
	 */
	private static function table_exists( $table_name ) {
		global $wpdb;

		$table_name = self::sanitize_table_name( $table_name );

		if ( '' === $table_name ) {
			return false;
		}

		$like = $wpdb->esc_like( $table_name );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is compared as a prepared string value, not used as an SQL identifier.
		$found_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return $found_table === $table_name;
	}
}
