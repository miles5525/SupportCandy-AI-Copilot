<?php
/**
 * Settings service for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides centralized access to plugin settings.
 *
 * This class owns option names, defaults, sanitization rules, and common
 * helpers for reading and updating plugin settings.
 */
final class SCAI_Settings {

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
	 * Delete data on uninstall option name.
	 *
	 * @var string
	 */
	const OPTION_DELETE_DATA_ON_UNINSTALL = 'scai_delete_data_on_uninstall';

	/**
	 * Active provider option name.
	 *
	 * @var string
	 */
	const OPTION_ACTIVE_PROVIDER = 'scai_active_provider';

	/**
	 * Company instructions option name.
	 *
	 * @var string
	 */
	const OPTION_COMPANY_INSTRUCTIONS = 'scai_company_instructions';

	/**
	 * Image understanding enabled option name.
	 *
	 * @var string
	 */
	const OPTION_IMAGE_UNDERSTANDING_ENABLED = 'scai_image_understanding_enabled';

	/**
	 * Knowledge sync enabled option name.
	 *
	 * @var string
	 */
	const OPTION_KNOWLEDGE_SYNC_ENABLED = 'scai_knowledge_sync_enabled';

	/**
	 * Last knowledge sync timestamp option name.
	 *
	 * @var string
	 */
	const OPTION_LAST_KNOWLEDGE_SYNC_AT = 'scai_last_knowledge_sync_at';

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}

	/**
	 * Get all registered setting definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_definitions() {
		return array(
			'schema_version'                  => array(
				'option'   => self::OPTION_SCHEMA_VERSION,
				'default'  => '',
				'type'     => 'string',
				'autoload' => false,
			),
			'installed_at'                    => array(
				'option'   => self::OPTION_INSTALLED_AT,
				'default'  => '',
				'type'     => 'datetime',
				'autoload' => false,
			),
			'conversation_retention_days'     => array(
				'option'   => self::OPTION_CONVERSATION_RETENTION_DAYS,
				'default'  => 30,
				'type'     => 'integer',
				'min'      => 1,
				'max'      => 365,
				'autoload' => false,
			),
			'delete_data_on_uninstall'        => array(
				'option'   => self::OPTION_DELETE_DATA_ON_UNINSTALL,
				'default'  => 0,
				'type'     => 'boolean',
				'autoload' => false,
			),
			'active_provider'                 => array(
				'option'   => self::OPTION_ACTIVE_PROVIDER,
				'default'  => '',
				'type'     => 'key',
				'autoload' => false,
			),
			'company_instructions'            => array(
				'option'   => self::OPTION_COMPANY_INSTRUCTIONS,
				'default'  => '',
				'type'     => 'textarea',
				'autoload' => false,
			),
			'image_understanding_enabled'     => array(
				'option'   => self::OPTION_IMAGE_UNDERSTANDING_ENABLED,
				'default'  => 1,
				'type'     => 'boolean',
				'autoload' => false,
			),
			'knowledge_sync_enabled'          => array(
				'option'   => self::OPTION_KNOWLEDGE_SYNC_ENABLED,
				'default'  => 1,
				'type'     => 'boolean',
				'autoload' => false,
			),
			'last_knowledge_sync_at'          => array(
				'option'   => self::OPTION_LAST_KNOWLEDGE_SYNC_AT,
				'default'  => '',
				'type'     => 'datetime',
				'autoload' => false,
			),
		);
	}

	/**
	 * Get registered setting keys.
	 *
	 * @return array<int, string>
	 */
	public static function get_registered_keys() {
		return array_keys( self::get_definitions() );
	}

	/**
	 * Get registered option names.
	 *
	 * @return array<string, string>
	 */
	public static function get_option_names() {
		$option_names = array();

		foreach ( self::get_definitions() as $key => $definition ) {
			$option_names[ $key ] = $definition['option'];
		}

		return $option_names;
	}

	/**
	 * Get all default setting values.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults() {
		$defaults = array();

		foreach ( self::get_definitions() as $key => $definition ) {
			$defaults[ $key ] = $definition['default'];
		}

		return $defaults;
	}

	/**
	 * Get one setting value.
	 *
	 * The key may be a logical key such as "company_instructions" or a full
	 * option name such as "scai_company_instructions".
	 *
	 * @param string $key     Setting key or option name.
	 * @param mixed  $default Optional fallback value.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$key        = self::normalize_key( $key );
		$definition = self::get_definition( $key );

		if ( empty( $definition ) ) {
			return $default;
		}

		$fallback = null === $default ? $definition['default'] : $default;
		$value    = get_option( $definition['option'], $fallback );

		if ( false === $value && isset( $definition['default'] ) ) {
			$value = $definition['default'];
		}

		return self::sanitize_value( $key, $value );
	}

	/**
	 * Update one setting value.
	 *
	 * The key may be a logical key or a full option name.
	 *
	 * @param string $key   Setting key or option name.
	 * @param mixed  $value Setting value.
	 * @return bool
	 */
	public static function update( $key, $value ) {
		$key        = self::normalize_key( $key );
		$definition = self::get_definition( $key );

		if ( empty( $definition ) ) {
			return false;
		}

		$autoload = isset( $definition['autoload'] ) ? (bool) $definition['autoload'] : false;
		$value    = self::sanitize_value( $key, $value );

		return update_option( $definition['option'], $value, $autoload );
	}

	/**
	 * Delete one setting.
	 *
	 * The key may be a logical key or a full option name.
	 *
	 * @param string $key Setting key or option name.
	 * @return bool
	 */
	public static function delete( $key ) {
		$key        = self::normalize_key( $key );
		$definition = self::get_definition( $key );

		if ( empty( $definition ) ) {
			return false;
		}

		return delete_option( $definition['option'] );
	}

	/**
	 * Add default options if they do not already exist.
	 *
	 * Existing values are preserved unless $force is true.
	 *
	 * @param bool $force Whether to overwrite existing values.
	 * @return array<string, bool>
	 */
	public static function add_defaults( $force = false ) {
		$results = array();

		foreach ( self::get_definitions() as $key => $definition ) {
			$option_name = $definition['option'];
			$autoload    = isset( $definition['autoload'] ) ? (bool) $definition['autoload'] : false;
			$value       = self::sanitize_value( $key, $definition['default'] );

			if ( $force ) {
				$results[ $key ] = update_option( $option_name, $value, $autoload );
				continue;
			}

			$existing_value = get_option( $option_name, null );

			if ( null === $existing_value ) {
				$results[ $key ] = add_option( $option_name, $value, '', $autoload );
				continue;
			}

			$results[ $key ] = false;
		}

		return $results;
	}

	/**
	 * Get all registered settings with sanitized values.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_all() {
		$settings = array();

		foreach ( self::get_registered_keys() as $key ) {
			$settings[ $key ] = self::get( $key );
		}

		return $settings;
	}

	/**
	 * Determine whether a boolean setting is enabled.
	 *
	 * @param string $key Setting key or option name.
	 * @return bool
	 */
	public static function is_enabled( $key ) {
		return self::is_truthy_value( self::get( $key ) );
	}

	/**
	 * Get a registered option name by setting key.
	 *
	 * @param string $key Setting key or option name.
	 * @return string
	 */
	public static function get_option_name( $key ) {
		$key        = self::normalize_key( $key );
		$definition = self::get_definition( $key );

		return empty( $definition ) ? '' : $definition['option'];
	}

	/**
	 * Get a single setting definition.
	 *
	 * @param string $key Setting key.
	 * @return array<string, mixed>
	 */
	private static function get_definition( $key ) {
		$key         = sanitize_key( $key );
		$definitions = self::get_definitions();

		return isset( $definitions[ $key ] ) ? $definitions[ $key ] : array();
	}

	/**
	 * Normalize a logical setting key or option name to the logical key.
	 *
	 * @param string $key Setting key or option name.
	 * @return string
	 */
	private static function normalize_key( $key ) {
		$key = sanitize_key( (string) $key );

		if ( '' === $key ) {
			return '';
		}

		$definitions = self::get_definitions();

		if ( isset( $definitions[ $key ] ) ) {
			return $key;
		}

		foreach ( $definitions as $setting_key => $definition ) {
			if ( $key === $definition['option'] ) {
				return $setting_key;
			}
		}

		return $key;
	}

	/**
	 * Sanitize a setting value based on its registered type.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	private static function sanitize_value( $key, $value ) {
		$definition = self::get_definition( $key );
		$type       = isset( $definition['type'] ) ? $definition['type'] : 'string';

		switch ( $type ) {
			case 'boolean':
				return self::is_truthy_value( $value ) ? 1 : 0;

			case 'integer':
				return self::sanitize_integer_value( $value, $definition );

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'key':
				return sanitize_key( (string) $value );

			case 'datetime':
				return sanitize_text_field( (string) $value );

			case 'array':
				return self::sanitize_array_value( $value );

			case 'string':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Sanitize an integer setting value.
	 *
	 * @param mixed                $value      Raw value.
	 * @param array<string,mixed>  $definition Setting definition.
	 * @return int
	 */
	private static function sanitize_integer_value( $value, array $definition ) {
		$value = absint( $value );

		if ( isset( $definition['min'] ) ) {
			$value = max( absint( $definition['min'] ), $value );
		}

		if ( isset( $definition['max'] ) ) {
			$value = min( absint( $definition['max'] ), $value );
		}

		return $value;
	}

	/**
	 * Sanitize an array setting value recursively.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, mixed>
	 */
	private static function sanitize_array_value( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $value as $array_key => $array_value ) {
			$array_key = sanitize_key( (string) $array_key );

			if ( '' === $array_key ) {
				continue;
			}

			if ( is_array( $array_value ) ) {
				$sanitized[ $array_key ] = self::sanitize_array_value( $array_value );
				continue;
			}

			if ( is_bool( $array_value ) || is_numeric( $array_value ) ) {
				$sanitized[ $array_key ] = $array_value;
				continue;
			}

			$sanitized[ $array_key ] = sanitize_text_field( (string) $array_value );
		}

		return $sanitized;
	}

	/**
	 * Check whether a value should be treated as enabled.
	 *
	 * @param mixed $value Value to check.
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
}