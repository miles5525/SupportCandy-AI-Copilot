<?php
/**
 * Database migrator for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin database schema upgrades.
 *
 * This class compares the installed schema version with the current schema
 * version and runs dbDelta() when an upgrade is required.
 */
final class SCAI_Migrator {

	/**
	 * Schema version option name.
	 *
	 * @var string
	 */
	const OPTION_SCHEMA_VERSION = 'scai_schema_version';

	/**
	 * Migration lock transient name.
	 *
	 * @var string
	 */
	const LOCK_TRANSIENT = 'scai_database_migration_lock';

	/**
	 * Migration lock lifetime in seconds.
	 *
	 * @var int
	 */
	const LOCK_TTL = 300;

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}

	/**
	 * Run migration only when required.
	 *
	 * @return array<string, mixed> Migration result details.
	 */
	public static function maybe_migrate() {
		self::load_dependencies();

		if ( ! class_exists( 'SCAI_Schema' ) ) {
			return array(
				'migrated' => false,
				'reason'   => 'schema_class_missing',
			);
		}

		$installed_version = self::get_installed_schema_version();
		$current_version   = SCAI_Schema::get_schema_version();

		if ( '' === $current_version ) {
			return array(
				'migrated'          => false,
				'reason'            => 'current_schema_version_missing',
				'installed_version' => $installed_version,
				'current_version'   => $current_version,
			);
		}

		if ( ! self::is_migration_required( $installed_version, $current_version ) ) {
			return array(
				'migrated'          => false,
				'reason'            => 'schema_up_to_date',
				'installed_version' => $installed_version,
				'current_version'   => $current_version,
			);
		}

		return self::migrate();
	}

	/**
	 * Run database migration.
	 *
	 * @return array<string, mixed> Migration result details.
	 */
	public static function migrate() {
		self::load_dependencies();

		if ( ! class_exists( 'SCAI_Schema' ) ) {
			return array(
				'migrated' => false,
				'reason'   => 'schema_class_missing',
			);
		}

		$installed_version = self::get_installed_schema_version();
		$current_version   = SCAI_Schema::get_schema_version();

		if ( ! self::is_migration_required( $installed_version, $current_version ) ) {
			return array(
				'migrated'          => false,
				'reason'            => 'schema_up_to_date',
				'installed_version' => $installed_version,
				'current_version'   => $current_version,
			);
		}

		$lock_acquired = self::acquire_lock();

		if ( ! $lock_acquired ) {
			return array(
				'migrated'          => false,
				'reason'            => 'migration_locked',
				'installed_version' => $installed_version,
				'current_version'   => $current_version,
			);
		}

		$dbdelta_results = array();

		try {
			$dbdelta_results = self::run_dbdelta();

			update_option( self::OPTION_SCHEMA_VERSION, $current_version, false );

			return array(
				'migrated'          => true,
				'reason'            => 'migration_completed',
				'installed_version' => $installed_version,
				'current_version'   => $current_version,
				'tables'            => self::get_table_statuses(),
				'dbdelta'           => $dbdelta_results,
			);
		} finally {
			if ( $lock_acquired ) {
				self::release_lock();
			}
		}
	}

	/**
	 * Check whether database migration is required.
	 *
	 * @param string $installed_version Installed schema version.
	 * @param string $current_version   Current schema version.
	 * @return bool
	 */
	public static function is_migration_required( $installed_version = '', $current_version = '' ) {
		self::load_dependencies();

		if ( '' === $current_version && class_exists( 'SCAI_Schema' ) ) {
			$current_version = SCAI_Schema::get_schema_version();
		}

		$installed_version = (string) $installed_version;
		$current_version   = (string) $current_version;

		if ( '' === $current_version ) {
			return false;
		}

		if ( '' === $installed_version ) {
			return true;
		}

		return version_compare( $installed_version, $current_version, '<' );
	}

	/**
	 * Get installed schema version.
	 *
	 * @return string
	 */
	public static function get_installed_schema_version() {
		return (string) get_option( self::OPTION_SCHEMA_VERSION, '' );
	}

	/**
	 * Load required dependencies.
	 *
	 * @return void
	 */
	private static function load_dependencies() {
		if ( class_exists( 'SCAI_Schema' ) ) {
			return;
		}

		$schema_file = __DIR__ . '/class-schema.php';

		if ( is_readable( $schema_file ) ) {
			require_once $schema_file;
		}
	}

	/**
	 * Run dbDelta() against current schema statements.
	 *
	 * @return array<string, array<int|string, string>> dbDelta results grouped by table key.
	 */
	private static function run_dbdelta() {
		if ( ! class_exists( 'SCAI_Schema' ) ) {
			return array();
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$results    = array();
		$statements = SCAI_Schema::get_create_table_statements();

		foreach ( $statements as $table_key => $sql ) {
			$results[ sanitize_key( $table_key ) ] = dbDelta( $sql );
		}

		return $results;
	}

	/**
	 * Acquire a short-lived migration lock.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}

		set_transient( self::LOCK_TRANSIENT, time(), self::LOCK_TTL );

		return true;
	}

	/**
	 * Release migration lock.
	 *
	 * @return void
	 */
	private static function release_lock() {
		delete_transient( self::LOCK_TRANSIENT );
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
			$statuses[ sanitize_key( $table_key ) ] = self::table_exists( $table_name );
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

		$table_name = self::sanitize_table_name( $table_name );

		if ( '' === $table_name ) {
			return false;
		}

		$like = $wpdb->esc_like( $table_name );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is compared as a prepared string value, not used as an SQL identifier.
		$found_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return $found_table === $table_name;
	}

	/**
	 * Sanitize a database table name for safe identifier usage.
	 *
	 * @param string $table_name Full database table name.
	 * @return string
	 */
	private static function sanitize_table_name( $table_name ) {
		$table_name = (string) $table_name;

		if ( '' === $table_name ) {
			return '';
		}

		$sanitized = preg_replace( '/[^A-Za-z0-9_]/', '', $table_name );

		return is_string( $sanitized ) ? $sanitized : '';
	}
}