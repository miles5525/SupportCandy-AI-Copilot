<?php
/**
 * Plugin installer for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin installation tasks.
 *
 * This class runs database table creation and stores initial plugin options.
 * It should be called from the plugin activation hook.
 */
final class SCAI_Installer {

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
	 * Default conversation retention period in days.
	 *
	 * @var int
	 */
	const DEFAULT_CONVERSATION_RETENTION_DAYS = 30;

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}

	/**
	 * Run plugin installation.
	 *
	 * This method supports both single-site activation and multisite
	 * network activation.
	 *
	 * @param bool $network_wide Whether the plugin is being network activated.
	 * @return array<string, mixed> Installation result details.
	 */
	public static function install( $network_wide = false ) {
		$network_wide = (bool) $network_wide;

		if ( is_multisite() && $network_wide ) {
			return self::install_network();
		}

		return self::install_site();
	}

	/**
	 * Run installation for all sites in a multisite network.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function install_network() {
		$results = array();

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );

			$results[ (int) $site_id ] = self::install_site();

			restore_current_blog();
		}

		return $results;
	}

	/**
	 * Run installation for the current site.
	 *
	 * @return array<string, mixed>
	 */
	private static function install_site() {
		self::load_dependencies();

		$schema_results = self::create_tables();

		self::save_install_options();

		return array(
			'schema_version' => SCAI_Schema::get_schema_version(),
			'tables'         => self::get_table_statuses(),
			'dbdelta'        => $schema_results,
		);
	}

	/**
	 * Load installer dependencies.
	 *
	 * @return void
	 */
	private static function load_dependencies() {
		if ( class_exists( 'SCAI_Schema' ) ) {
			return;
		}

		$schema_file = dirname( __DIR__ ) . '/database/class-schema.php';

		if ( is_readable( $schema_file ) ) {
			require_once $schema_file;
		}
	}

	/**
	 * Create or update plugin database tables.
	 *
	 * @return array<string, array<int|string, string>> dbDelta results grouped by table key.
	 */
	private static function create_tables() {
		if ( ! class_exists( 'SCAI_Schema' ) ) {
			return array();
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$results    = array();
		$statements = SCAI_Schema::get_create_table_statements();

		foreach ( $statements as $table_key => $sql ) {
			$results[ $table_key ] = dbDelta( $sql );
		}

		return $results;
	}

	/**
	 * Save initial plugin options.
	 *
	 * Existing options are not overwritten unless they are version-tracking
	 * options that must reflect the current installed schema.
	 *
	 * @return void
	 */
	private static function save_install_options() {
		if ( ! class_exists( 'SCAI_Schema' ) ) {
			return;
		}

		update_option( self::OPTION_SCHEMA_VERSION, SCAI_Schema::get_schema_version(), false );

		if ( false === get_option( self::OPTION_INSTALLED_AT, false ) ) {
			add_option( self::OPTION_INSTALLED_AT, current_time( 'mysql', true ), '', false );
		}

		if ( false === get_option( self::OPTION_CONVERSATION_RETENTION_DAYS, false ) ) {
			add_option(
				self::OPTION_CONVERSATION_RETENTION_DAYS,
				self::DEFAULT_CONVERSATION_RETENTION_DAYS,
				'',
				false
			);
		}
	}

	/**
	 * Get plugin table existence statuses.
	 *
	 * @return array<string, bool>
	 */
	private static function get_table_statuses() {
		if ( ! class_exists( 'SCAI_Schema' ) ) {
			return array();
		}

		$statuses    = array();
		$table_names = SCAI_Schema::get_table_names();

		foreach ( $table_names as $table_key => $table_name ) {
			$statuses[ $table_key ] = self::table_exists( $table_name );
		}

		return $statuses;
	}

	/**
	 * Check whether a database table exists.
	 *
	 * @param string $table_name Full database table name.
	 * @return bool
	 */
	private static function table_exists( $table_name ) {
		global $wpdb;

		$table_name = (string) $table_name;

		if ( '' === $table_name ) {
			return false;
		}

		$like = $wpdb->esc_like( $table_name );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is compared as a prepared string value, not used as an SQL identifier.
		$found_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return $found_table === $table_name;
	}
}