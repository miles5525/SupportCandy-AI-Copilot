<?php
/**
 * Immutable provider preset metadata.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Holds normalized metadata for one OpenAI-compatible provider preset. */
final class SCAI_Provider_Preset {

	/** @var array<string, mixed> */
	private $data = array();

	/**
	 * Create a normalized preset.
	 *
	 * @param array<string, mixed> $data Preset metadata.
	 */
	public function __construct( array $data ) {
		$defaults = array(
			'key'                         => '',
			'label'                       => '',
			'description'                 => '',
			'default_base_url'            => '',
			'default_model'               => '',
			'model_suggestions'           => array(),
			'api_key_label'               => __( 'API Key', 'supportcandy-ai' ),
			'supports_images'             => false,
			'supports_streaming'          => false,
			'base_url_editable'           => true,
			'model_editable'              => true,
			'organization_project_fields' => true,
			'setup_help'                  => '',
			'warning_text'                => '',
			'endpoint_path'               => '/chat/completions',
			'legacy_keys'                 => array(),
		);

		$data = wp_parse_args( $data, $defaults );

		$this->data = array(
			'key'                         => sanitize_key( $data['key'] ),
			'label'                       => sanitize_text_field( (string) $data['label'] ),
			'description'                 => sanitize_text_field( (string) $data['description'] ),
			'default_base_url'            => esc_url_raw( (string) $data['default_base_url'], array( 'http', 'https' ) ),
			'default_model'               => sanitize_text_field( (string) $data['default_model'] ),
			'model_suggestions'           => $this->normalize_model_suggestions( $data['model_suggestions'] ),
			'api_key_label'               => sanitize_text_field( (string) $data['api_key_label'] ),
			'supports_images'             => (bool) $data['supports_images'],
			'supports_streaming'          => (bool) $data['supports_streaming'],
			'base_url_editable'           => (bool) $data['base_url_editable'],
			'model_editable'              => (bool) $data['model_editable'],
			'organization_project_fields' => (bool) $data['organization_project_fields'],
			'setup_help'                  => sanitize_text_field( (string) $data['setup_help'] ),
			'warning_text'                => sanitize_text_field( (string) $data['warning_text'] ),
			'endpoint_path'               => $this->normalize_endpoint_path( $data['endpoint_path'] ),
			'legacy_keys'                 => $this->normalize_legacy_keys( $data['legacy_keys'] ),
		);
	}

	/** Get one normalized preset value. */
	public function get( $key, $default = null ) {
		$key = sanitize_key( $key );
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : $default;
	}

	/** Get the complete normalized preset definition. */
	public function to_array() {
		return $this->data;
	}

	/** Normalize editable model suggestions. */
	private function normalize_model_suggestions( $models ) {
		if ( ! is_array( $models ) ) {
			return array();
		}

		$normalized = array();
		foreach ( array_slice( $models, 0, 100, true ) as $model => $label ) {
			$model = is_scalar( $model ) ? sanitize_text_field( (string) $model ) : '';
			$label = is_scalar( $label ) ? sanitize_text_field( (string) $label ) : '';
			if ( '' !== $model && '' !== $label ) {
				$normalized[ $model ] = $label;
			}
		}

		return $normalized;
	}

	/** Normalize a fixed relative endpoint path. */
	private function normalize_endpoint_path( $path ) {
		$path = is_scalar( $path ) ? '/' . ltrim( trim( (string) $path ), '/' ) : '';
		return 1 === preg_match( '#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]+$#', $path ) ? $path : '/chat/completions';
	}

	/** Normalize optional legacy provider keys. */
	private function normalize_legacy_keys( $keys ) {
		$normalized = array();
		foreach ( is_array( $keys ) ? array_slice( $keys, 0, 20 ) : array() as $key ) {
			$key = is_scalar( $key ) ? sanitize_key( (string) $key ) : '';
			if ( '' !== $key && ! in_array( $key, $normalized, true ) ) {
				$normalized[] = $key;
			}
		}

		return $normalized;
	}
}
