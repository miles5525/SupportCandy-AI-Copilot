<?php
/**
 * Database schema definitions for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines plugin-owned database table names and create-table SQL.
 *
 * This class is intentionally responsible only for schema definition.
 * It does not create, update, migrate, or delete tables.
 *
 * @since 1.0.0
 */
final class SCAI_Schema {

	/**
	 * Current database schema version.
	 *
	 * This value should be updated whenever the table structure changes.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const SCHEMA_VERSION = '1.0.0';

	/**
	 * Conversations table suffix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TABLE_CONVERSATIONS = 'scai_conversations';

	/**
	 * Knowledge table suffix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TABLE_KNOWLEDGE = 'scai_knowledge';

	/**
	 * Usage logs table suffix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TABLE_USAGE_LOGS = 'scai_usage_logs';

	/**
	 * Prevent instantiation.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
	}

	/**
	 * Get the current schema version.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_schema_version() {
		return self::SCHEMA_VERSION;
	}

	/**
	 * Get plugin table suffixes without WordPress database prefix.
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	public static function get_table_suffixes() {
		return array(
			'conversations' => self::TABLE_CONVERSATIONS,
			'knowledge'     => self::TABLE_KNOWLEDGE,
			'usage_logs'    => self::TABLE_USAGE_LOGS,
		);
	}

	/**
	 * Get plugin table names with WordPress database prefix.
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	public static function get_table_names() {
		global $wpdb;

		$table_names = array();

		foreach ( self::get_table_suffixes() as $key => $suffix ) {
			$table_names[ $key ] = $wpdb->prefix . $suffix;
		}

		return $table_names;
	}

	/**
	 * Get a single plugin table name by logical key.
	 *
	 * Valid keys:
	 * - conversations
	 * - knowledge
	 * - usage_logs
	 *
	 * @since 1.0.0
	 * @param string $key Logical table key.
	 * @return string Table name with WordPress database prefix, or empty string if not found.
	 */
	public static function get_table_name( $key ) {
		$key         = sanitize_key( $key );
		$table_names = self::get_table_names();

		return isset( $table_names[ $key ] ) ? $table_names[ $key ] : '';
	}

	/**
	 * Get dbDelta-compatible CREATE TABLE statements.
	 *
	 * The returned SQL is intentionally formatted for WordPress dbDelta().
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	public static function get_create_table_statements() {
		global $wpdb;

		$table_names     = self::get_table_names();
		$charset_collate = $wpdb->get_charset_collate();

		$conversations_table = $table_names['conversations'];
		$knowledge_table     = $table_names['knowledge'];
		$usage_logs_table    = $table_names['usage_logs'];

		return array(
			'conversations' => self::get_conversations_table_sql( $conversations_table, $charset_collate ),
			'knowledge'     => self::get_knowledge_table_sql( $knowledge_table, $charset_collate ),
			'usage_logs'    => self::get_usage_logs_table_sql( $usage_logs_table, $charset_collate ),
		);
	}

	/**
	 * Get CREATE TABLE SQL for conversations.
	 *
	 * One row represents one AI conversation message or AI-generated interaction.
	 * Rows are grouped by conversation_id, ticket_id, and agent_id.
	 *
	 * @since 1.0.0
	 * @param string $table_name      Full database table name.
	 * @param string $charset_collate Database charset and collation.
	 * @return string
	 */
	private static function get_conversations_table_sql( $table_name, $charset_collate ) {
		return "CREATE TABLE {$table_name} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
conversation_id varchar(64) NOT NULL DEFAULT '',
ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
agent_id bigint(20) unsigned NOT NULL DEFAULT 0,
role varchar(20) NOT NULL DEFAULT '',
feature varchar(50) NOT NULL DEFAULT 'conversation',
content longtext NOT NULL,
context_hash varchar(64) NOT NULL DEFAULT '',
provider varchar(50) NOT NULL DEFAULT '',
model varchar(100) NOT NULL DEFAULT '',
prompt_tokens int(11) unsigned NOT NULL DEFAULT 0,
completion_tokens int(11) unsigned NOT NULL DEFAULT 0,
metadata longtext NULL,
expires_at datetime NULL,
created_at datetime NOT NULL,
updated_at datetime NULL,
PRIMARY KEY  (id),
KEY conversation_id (conversation_id),
KEY ticket_agent (ticket_id, agent_id),
KEY agent_id (agent_id),
KEY ticket_id (ticket_id),
KEY feature (feature),
KEY expires_at (expires_at),
KEY created_at (created_at)
) {$charset_collate};";
	}

	/**
	 * Get CREATE TABLE SQL for knowledge records.
	 *
	 * This table stores global knowledge records and chunks from sources such as
	 * PDFs, TXT files, URLs, BetterDocs, and previous tickets.
	 *
	 * Embeddings are intentionally not stored in this table.
	 *
	 * @since 1.0.0
	 * @param string $table_name      Full database table name.
	 * @param string $charset_collate Database charset and collation.
	 * @return string
	 */
	private static function get_knowledge_table_sql( $table_name, $charset_collate ) {
		return "CREATE TABLE {$table_name} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
source_type varchar(30) NOT NULL DEFAULT '',
source_key varchar(64) NOT NULL DEFAULT '',
object_id bigint(20) unsigned NOT NULL DEFAULT 0,
title text NULL,
source_url text NULL,
mime_type varchar(100) NOT NULL DEFAULT '',
content longtext NOT NULL,
content_hash varchar(64) NOT NULL DEFAULT '',
metadata longtext NULL,
status varchar(20) NOT NULL DEFAULT 'active',
last_synced_at datetime NULL,
created_at datetime NOT NULL,
updated_at datetime NULL,
PRIMARY KEY  (id),
KEY source_type (source_type),
KEY source_key (source_key),
KEY source_lookup (source_type, source_key),
KEY object_id (object_id),
KEY content_hash (content_hash),
KEY status (status),
KEY last_synced_at (last_synced_at),
KEY created_at (created_at)
) {$charset_collate};";
	}

	/**
	 * Get CREATE TABLE SQL for AI usage logs.
	 *
	 * This table powers administrator reports for requests, tokens, provider usage,
	 * feature usage, errors, and performance tracking.
	 *
	 * @since 1.0.0
	 * @param string $table_name      Full database table name.
	 * @param string $charset_collate Database charset and collation.
	 * @return string
	 */
	private static function get_usage_logs_table_sql( $table_name, $charset_collate ) {
		return "CREATE TABLE {$table_name} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
request_id varchar(64) NOT NULL DEFAULT '',
ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
agent_id bigint(20) unsigned NOT NULL DEFAULT 0,
feature varchar(50) NOT NULL DEFAULT '',
provider varchar(50) NOT NULL DEFAULT '',
model varchar(100) NOT NULL DEFAULT '',
prompt_tokens int(11) unsigned NOT NULL DEFAULT 0,
completion_tokens int(11) unsigned NOT NULL DEFAULT 0,
total_tokens int(11) unsigned NOT NULL DEFAULT 0,
estimated_cost decimal(18,8) NOT NULL DEFAULT 0.00000000,
duration_ms int(11) unsigned NOT NULL DEFAULT 0,
status varchar(20) NOT NULL DEFAULT 'success',
error_code varchar(100) NOT NULL DEFAULT '',
error_message text NULL,
metadata longtext NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY request_id (request_id),
KEY ticket_id (ticket_id),
KEY agent_id (agent_id),
KEY feature (feature),
KEY provider (provider),
KEY model (model),
KEY status (status),
KEY created_at (created_at)
) {$charset_collate};";
	}
}
