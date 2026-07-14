<?php
/**
 * Provider configuration service for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles storage and retrieval of AI provider configurations.
 *
 * This class does not call AI providers and does not render admin UI.
 * It only manages provider configuration stored in WordPress options.
 */
final class SCAI_Provider_Config {

	/**
	 * Provider configurations option name.
	 *
	 * @var string
	 */
	const OPTION_PROVIDER_CONFIGS = 'scai_provider_configs';

	/**
	 * Active provider option name.
	 *
	 * @var string
	 */
	const OPTION_ACTIVE_PROVIDER = 'scai_active_provider';

	/**
	 * Default provider timeout in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 30;

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}

	/**
	 * Get all provider configurations.
	 *
	 * @param bool $include_secrets Whether to include sensitive values.
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_all( $include_secrets = true ) {
		$configs = get_option( self::OPTION_PROVIDER_CONFIGS, array() );

		if ( ! is_array( $configs ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $configs as $provider_key => $config ) {
			$provider_key = sanitize_key( (string) $provider_key );

			if ( '' === $provider_key || ! is_array( $config ) ) {
				continue;
			}

			$sanitized[ $provider_key ] = self::sanitize_config( $config );
		}

		return $include_secrets ? $sanitized : self::redact_all( $sanitized );
	}

	/**
	 * Get one provider configuration.
	 *
	 * @param string $provider_key    Provider key.
	 * @param bool   $include_secrets Whether to include sensitive values.
	 * @return array<string, mixed>
	 */
	public static function get( $provider_key, $include_secrets = true ) {
		$provider_key = sanitize_key( $provider_key );

		if ( '' === $provider_key ) {
			return array();
		}

		$configs = self::get_all( true );

		if ( empty( $configs[ $provider_key ] ) || ! is_array( $configs[ $provider_key ] ) ) {
			return array();
		}

		return $include_secrets ? $configs[ $provider_key ] : self::redact_config( $configs[ $provider_key ] );
	}

	/**
	 * Update one provider configuration.
	 *
	 * By default, existing sensitive values are preserved when the incoming
	 * value is empty. This prevents accidentally clearing API keys from forms
	 * that display masked secrets.
	 *
	 * @param string               $provider_key              Provider key.
	 * @param array<string, mixed> $config                    Provider config.
	 * @param bool                 $preserve_existing_secrets Whether to preserve existing secrets when empty.
	 * @return bool
	 */
	public static function update( $provider_key, array $config, $preserve_existing_secrets = true ) {
		$provider_key = sanitize_key( $provider_key );

		if ( '' === $provider_key ) {
			return false;
		}

		$configs         = self::get_all( true );
		$existing_config = isset( $configs[ $provider_key ] ) && is_array( $configs[ $provider_key ] )
			? $configs[ $provider_key ]
			: array();

		$config = self::sanitize_config( $config );

		if ( $preserve_existing_secrets ) {
			$config = self::preserve_existing_secrets( $config, $existing_config );
		}

		$configs[ $provider_key ] = wp_parse_args( $config, self::get_default_config() );

		return update_option( self::OPTION_PROVIDER_CONFIGS, $configs, false );
	}

	/**
	 * Replace one provider configuration completely.
	 *
	 * Unlike update(), this method does not preserve existing secrets.
	 *
	 * @param string               $provider_key Provider key.
	 * @param array<string, mixed> $config       Provider config.
	 * @return bool
	 */
	public static function replace( $provider_key, array $config ) {
		return self::update( $provider_key, $config, false );
	}

	/**
	 * Delete one provider configuration.
	 *
	 * @param string $provider_key Provider key.
	 * @return bool
	 */
	public static function delete( $provider_key ) {
		$provider_key = sanitize_key( $provider_key );

		if ( '' === $provider_key ) {
			return false;
		}

		$configs = self::get_all( true );

		if ( ! isset( $configs[ $provider_key ] ) ) {
			return false;
		}

		unset( $configs[ $provider_key ] );

		return update_option( self::OPTION_PROVIDER_CONFIGS, $configs, false );
	}

	/**
	 * Delete all provider configurations.
	 *
	 * @return bool
	 */
	public static function delete_all() {
		return delete_option( self::OPTION_PROVIDER_CONFIGS );
	}

	/**
	 * Check whether a provider has stored configuration.
	 *
	 * @param string $provider_key Provider key.
	 * @return bool
	 */
	public static function has( $provider_key ) {
		return ! empty( self::get( $provider_key, true ) );
	}

	/**
	 * Get active provider configuration.
	 *
	 * @param bool $include_secrets Whether to include sensitive values.
	 * @return array<string, mixed>
	 */
	public static function get_active_config( $include_secrets = true ) {
		$provider_key = self::get_active_provider_key();

		if ( '' === $provider_key ) {
			return array();
		}

		return self::get( $provider_key, $include_secrets );
	}

	/**
	 * Get active provider key.
	 *
	 * @return string
	 */
	public static function get_active_provider_key() {
		if ( class_exists( 'SCAI_Settings' ) ) {
			return sanitize_key( SCAI_Settings::get( 'active_provider', '' ) );
		}

		return sanitize_key( get_option( self::OPTION_ACTIVE_PROVIDER, '' ) );
	}

	/**
	 * Get default provider config.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_default_config() {
		return array(
			'enabled'      => 1,
			'api_key'      => '',
			'base_url'     => '',
			'model'        => '',
			'organization' => '',
			'project'      => '',
			'timeout'      => self::DEFAULT_TIMEOUT,
			'extra'        => array(),
		);
	}

	/**
	 * Redact all provider configurations.
	 *
	 * @param array<string, array<string, mixed>> $configs Provider configs.
	 * @return array<string, array<string, mixed>>
	 */
	public static function redact_all( array $configs ) {
		$redacted = array();

		foreach ( $configs as $provider_key => $config ) {
			$provider_key = sanitize_key( $provider_key );

			if ( '' === $provider_key || ! is_array( $config ) ) {
				continue;
			}

			$redacted[ $provider_key ] = self::redact_config( $config );
		}

		return $redacted;
	}

	/**
	 * Redact sensitive values from one provider config.
	 *
	 * @param array<string, mixed> $config Provider config.
	 * @return array<string, mixed>
	 */
	public static function redact_config( array $config ) {
		$redacted = array();

		foreach ( $config as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( self::is_sensitive_key( $key ) ) {
				$redacted[ $key ] = self::mask_secret( (string) $value );
				continue;
			}

			if ( is_array( $value ) ) {
				$redacted[ $key ] = self::redact_config( $value );
				continue;
			}

			$redacted[ $key ] = $value;
		}

		return $redacted;
	}

	/**
	 * Sanitize provider configuration.
	 *
	 * Sensitive values are sanitized but not redacted here, because this method
	 * is used before storage. Never log the returned value directly.
	 *
	 * @param mixed $config Raw provider config.
	 * @return array<string, mixed>
	 */
	private static function sanitize_config( $config ) {
		if ( ! is_array( $config ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $config as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = self::sanitize_nested_array( $value );
				continue;
			}

			if ( 'enabled' === $key ) {
				$sanitized[ $key ] = self::to_bool( $value ) ? 1 : 0;
				continue;
			}

			if ( 'timeout' === $key ) {
				$sanitized[ $key ] = self::sanitize_timeout( $value );
				continue;
			}

			if ( 'base_url' === $key || false !== strpos( $key, 'url' ) ) {
				$sanitized[ $key ] = esc_url_raw( (string) $value );
				continue;
			}

			if ( self::is_sensitive_key( $key ) ) {
				$sanitized[ $key ] = self::sanitize_secret( $value );
				continue;
			}

			$sanitized[ $key ] = sanitize_text_field( (string) $value );
		}

		return $sanitized;
	}

	/**
	 * Sanitize nested config array.
	 *
	 * @param array<mixed> $value Raw array.
	 * @return array<string, mixed>
	 */
	private static function sanitize_nested_array( array $value ) {
		$sanitized = array();

		foreach ( $value as $nested_key => $nested_value ) {
			$nested_key = sanitize_key( (string) $nested_key );

			if ( '' === $nested_key ) {
				continue;
			}

			if ( is_array( $nested_value ) ) {
				$sanitized[ $nested_key ] = self::sanitize_nested_array( $nested_value );
				continue;
			}

			if ( is_bool( $nested_value ) ) {
				$sanitized[ $nested_key ] = $nested_value ? 1 : 0;
				continue;
			}

			if ( is_int( $nested_value ) || is_float( $nested_value ) ) {
				$sanitized[ $nested_key ] = $nested_value;
				continue;
			}

			if ( self::is_sensitive_key( $nested_key ) ) {
				$sanitized[ $nested_key ] = self::sanitize_secret( $nested_value );
				continue;
			}

			$sanitized[ $nested_key ] = sanitize_text_field( (string) $nested_value );
		}

		return $sanitized;
	}

	/**
	 * Preserve existing secret values when incoming values are empty.
	 *
	 * @param array<string, mixed> $config          Incoming config.
	 * @param array<string, mixed> $existing_config Existing config.
	 * @return array<string, mixed>
	 */
	private static function preserve_existing_secrets( array $config, array $existing_config ) {
		foreach ( $existing_config as $key => $existing_value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $existing_value ) ) {
				$config[ $key ] = self::preserve_existing_secrets(
					isset( $config[ $key ] ) && is_array( $config[ $key ] ) ? $config[ $key ] : array(),
					$existing_value
				);
				continue;
			}

			if ( ! self::is_sensitive_key( $key ) ) {
				continue;
			}

			if ( ! isset( $config[ $key ] ) || '' === trim( (string) $config[ $key ] ) ) {
				$config[ $key ] = $existing_value;
			}
		}

		return $config;
	}

	/**
	 * Sanitize a secret value.
	 *
	 * This keeps API keys usable while removing unsafe text characters.
	 *
	 * @param mixed $value Raw secret.
	 * @return string
	 */
	private static function sanitize_secret( $value ) {
		return sanitize_text_field( trim( (string) $value ) );
	}

	/**
	 * Sanitize timeout value.
	 *
	 * @param mixed $value Raw timeout.
	 * @return int
	 */
	private static function sanitize_timeout( $value ) {
		$timeout = absint( $value );

		if ( 0 === $timeout ) {
			return self::DEFAULT_TIMEOUT;
		}

		return min( 120, max( 1, $timeout ) );
	}

	/**
	 * Check whether config key is sensitive.
	 *
	 * @param string $key Config key.
	 * @return bool
	 */
	private static function is_sensitive_key( $key ) {
		$key = sanitize_key( $key );

		$sensitive_fragments = array(
			'api_key',
			'apikey',
			'key',
			'token',
			'secret',
			'password',
			'authorization',
		);

		foreach ( $sensitive_fragments as $fragment ) {
			if ( false !== strpos( $key, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Mask a secret value for display.
	 *
	 * @param string $secret Secret value.
	 * @return string
	 */
	private static function mask_secret( $secret ) {
		$secret = trim( (string) $secret );

		if ( '' === $secret ) {
			return '';
		}

		$length = strlen( $secret );

		if ( $length <= 8 ) {
			return '[redacted]';
		}

		return substr( $secret, 0, 4 ) . str_repeat( '*', max( 4, $length - 8 ) ) . substr( $secret, -4 );
	}

	/**
	 * Convert value to boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function to_bool( $value ) {
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
