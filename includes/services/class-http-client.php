<?php
/**
 * HTTP client service for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a small wrapper around the WordPress HTTP API.
 *
 * This class centralizes JSON requests, response normalization, timeout
 * handling, and safe metadata generation for provider implementations.
 */
final class SCAI_HTTP_Client {

	/**
	 * Default request timeout in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 30;

	/**
	 * Maximum request timeout in seconds.
	 *
	 * @var int
	 */
	const MAX_TIMEOUT = 120;

	/**
	 * Supported HTTP methods.
	 *
	 * @var array<int, string>
	 */
	private $allowed_methods = array(
		'GET',
		'POST',
		'PUT',
		'PATCH',
		'DELETE',
	);

	/**
	 * Send a JSON POST request.
	 *
	 * @param string               $url     Request URL.
	 * @param array<string, mixed> $payload JSON payload.
	 * @param array<string, mixed> $args    Request arguments.
	 * @return array<string, mixed>
	 */
	public function post_json( $url, array $payload = array(), array $args = array() ) {
		$args['json'] = $payload;

		return $this->request( 'POST', $url, $args );
	}

	/**
	 * Send a JSON GET request.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args Request arguments.
	 * @return array<string, mixed>
	 */
	public function get_json( $url, array $args = array() ) {
		return $this->request( 'GET', $url, $args );
	}

	/**
	 * Send an HTTP request.
	 *
	 * Supported args:
	 * - headers array<string, string>
	 * - body string|array
	 * - json array<string, mixed>
	 * - timeout int
	 *
	 * @param string               $method HTTP method.
	 * @param string               $url    Request URL.
	 * @param array<string, mixed> $args   Request arguments.
	 * @return array<string, mixed>
	 */
	public function request( $method, $url, array $args = array() ) {
		$started_at = microtime( true );
		$method     = $this->normalize_method( $method );
		$url        = $this->validate_url( $url );

		if ( '' === $url ) {
			return $this->build_error_response(
				'scai_http_invalid_url',
				__( 'HTTP request URL is invalid.', 'supportcandy-ai' ),
				0,
				$this->calculate_duration_ms( $started_at )
			);
		}

		if ( '' === $method ) {
			return $this->build_error_response(
				'scai_http_invalid_method',
				__( 'HTTP request method is invalid.', 'supportcandy-ai' ),
				0,
				$this->calculate_duration_ms( $started_at )
			);
		}

		$request_args = $this->build_request_args( $method, $args );
		$response     = wp_remote_request( $url, $request_args );
		$duration_ms  = $this->calculate_duration_ms( $started_at );

		if ( is_wp_error( $response ) ) {
			return $this->build_error_response(
				$response->get_error_code(),
				$response->get_error_message(),
				0,
				$duration_ms,
				array(
					'request' => $this->get_safe_request_metadata( $method, $url, $request_args ),
				)
			);
		}

		return $this->normalize_response( $response, $duration_ms, $method, $url, $request_args );
	}

	/**
	 * Build WordPress HTTP API request arguments.
	 *
	 * @param string               $method HTTP method.
	 * @param array<string, mixed> $args   Raw request args.
	 * @return array<string, mixed>
	 */
	private function build_request_args( $method, array $args ) {
		$headers = isset( $args['headers'] ) && is_array( $args['headers'] )
			? $this->sanitize_headers( $args['headers'] )
			: array();

		$request_args = array(
			'method'      => $method,
			'timeout'     => $this->sanitize_timeout( isset( $args['timeout'] ) ? $args['timeout'] : self::DEFAULT_TIMEOUT ),
			'redirection' => 3,
			'blocking'    => true,
			'headers'     => $headers,
		);

		if ( isset( $args['json'] ) && is_array( $args['json'] ) ) {
			$request_args['headers'] = $this->merge_headers(
				array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				$request_args['headers']
			);

			$request_args['body'] = wp_json_encode( $args['json'] );

			if ( ! is_string( $request_args['body'] ) ) {
				$request_args['body'] = '';
			}

			return $request_args;
		}

		if ( isset( $args['body'] ) ) {
			$request_args['body'] = $this->sanitize_body( $args['body'] );
		}

		if ( empty( $request_args['headers']['Accept'] ) && empty( $request_args['headers']['accept'] ) ) {
			$request_args['headers']['Accept'] = 'application/json';
		}

		return $request_args;
	}

	/**
	 * Normalize WordPress HTTP API response.
	 *
	 * @param array<string, mixed> $response     Raw WordPress HTTP response.
	 * @param int                  $duration_ms  Request duration.
	 * @param string               $method       HTTP method.
	 * @param string               $url          Request URL.
	 * @param array<string, mixed> $request_args Request args.
	 * @return array<string, mixed>
	 */
	private function normalize_response( array $response, $duration_ms, $method, $url, array $request_args ) {
		$status_code = absint( wp_remote_retrieve_response_code( $response ) );
		$message     = sanitize_text_field( (string) wp_remote_retrieve_response_message( $response ) );
		$body        = (string) wp_remote_retrieve_body( $response );
		$headers     = wp_remote_retrieve_headers( $response );
		$json        = $this->decode_json_body( $body );
		$success     = $status_code >= 200 && $status_code < 300;

		return array(
			'success'       => $success,
			'status_code'   => $status_code,
			'message'       => $message,
			'body'          => $body,
			'json'          => $json,
			'headers'       => $this->normalize_response_headers( $headers ),
			'duration_ms'   => absint( $duration_ms ),
			'error_code'    => $success ? '' : 'scai_http_error',
			'error_message' => $success ? '' : $this->get_http_error_message( $status_code, $message, $body ),
			'request'       => $this->get_safe_request_metadata( $method, $url, $request_args ),
		);
	}

	/**
	 * Build normalized error response.
	 *
	 * @param string               $error_code    Error code.
	 * @param string               $error_message Error message.
	 * @param int                  $status_code   HTTP status code.
	 * @param int                  $duration_ms   Request duration.
	 * @param array<string, mixed> $extra         Extra response data.
	 * @return array<string, mixed>
	 */
	private function build_error_response( $error_code, $error_message, $status_code = 0, $duration_ms = 0, array $extra = array() ) {
		return wp_parse_args(
			$extra,
			array(
				'success'       => false,
				'status_code'   => absint( $status_code ),
				'message'       => '',
				'body'          => '',
				'json'          => null,
				'headers'       => array(),
				'duration_ms'   => absint( $duration_ms ),
				'error_code'    => sanitize_key( (string) $error_code ),
				'error_message' => sanitize_text_field( (string) $error_message ),
				'request'       => array(),
			)
		);
	}

	/**
	 * Normalize HTTP method.
	 *
	 * @param string $method HTTP method.
	 * @return string
	 */
	private function normalize_method( $method ) {
		$method = strtoupper( sanitize_key( (string) $method ) );

		return in_array( $method, $this->allowed_methods, true ) ? $method : '';
	}

	/**
	 * Validate and sanitize request URL.
	 *
	 * @param mixed $url Request URL.
	 * @return string Valid URL, or empty string when invalid.
	 */
	private function validate_url( $url ) {
		$url = esc_url_raw( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || empty( $host ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Sanitize request timeout.
	 *
	 * @param mixed $timeout Timeout value.
	 * @return int
	 */
	private function sanitize_timeout( $timeout ) {
		$timeout = absint( $timeout );

		if ( 0 === $timeout ) {
			return self::DEFAULT_TIMEOUT;
		}

		return min( self::MAX_TIMEOUT, max( 1, $timeout ) );
	}

	/**
	 * Sanitize request headers.
	 *
	 * Header values are sanitized but not redacted because they are needed for
	 * the actual HTTP request. Use get_safe_request_metadata() for logging.
	 *
	 * @param array<mixed> $headers Raw headers.
	 * @return array<string, string>
	 */
	private function sanitize_headers( array $headers ) {
		$sanitized = array();

		foreach ( $headers as $key => $value ) {
			$key = $this->sanitize_header_name( $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				continue;
			}

			$sanitized[ $key ] = sanitize_text_field( (string) $value );
		}

		return $sanitized;
	}

	/**
	 * Merge default headers with custom headers.
	 *
	 * Custom headers override defaults.
	 *
	 * @param array<string, string> $defaults Default headers.
	 * @param array<string, string> $custom   Custom headers.
	 * @return array<string, string>
	 */
	private function merge_headers( array $defaults, array $custom ) {
		return array_merge( $defaults, $custom );
	}

	/**
	 * Sanitize a header name.
	 *
	 * @param mixed $header Header name.
	 * @return string
	 */
	private function sanitize_header_name( $header ) {
		$header = (string) $header;
		$header = preg_replace( '/[^A-Za-z0-9_-]/', '', $header );

		return is_string( $header ) ? $header : '';
	}

	/**
	 * Sanitize request body.
	 *
	 * @param mixed $body Raw body.
	 * @return string|array<string, mixed>
	 */
	private function sanitize_body( $body ) {
		if ( is_array( $body ) ) {
			return $this->sanitize_array_value( $body );
		}

		return (string) $body;
	}

	/**
	 * Decode JSON body when possible.
	 *
	 * @param string $body Response body.
	 * @return mixed|null
	 */
	private function decode_json_body( $body ) {
		$body = trim( (string) $body );

		if ( '' === $body ) {
			return null;
		}

		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Normalize response headers.
	 *
	 * @param mixed $headers Raw headers.
	 * @return array<string, string>
	 */
	private function normalize_response_headers( $headers ) {
		if ( ! is_array( $headers ) && ! $headers instanceof Traversable ) {
			return array();
		}

		$normalized = array();

		foreach ( $headers as $key => $value ) {
			$key = $this->sanitize_header_name( $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'sanitize_text_field', array_map( 'strval', $value ) ) );
			}

			$normalized[ $key ] = sanitize_text_field( (string) $value );
		}

		return $normalized;
	}

	/**
	 * Build safe request metadata for logging/debugging.
	 *
	 * Sensitive headers are redacted.
	 *
	 * @param string               $method       HTTP method.
	 * @param string               $url          Request URL.
	 * @param array<string, mixed> $request_args Request args.
	 * @return array<string, mixed>
	 */
	private function get_safe_request_metadata( $method, $url, array $request_args ) {
		$headers = isset( $request_args['headers'] ) && is_array( $request_args['headers'] )
			? $this->redact_headers( $request_args['headers'] )
			: array();

		return array(
			'method'  => $this->normalize_method( $method ),
			'url'     => esc_url_raw( (string) $url ),
			'timeout' => isset( $request_args['timeout'] ) ? absint( $request_args['timeout'] ) : self::DEFAULT_TIMEOUT,
			'headers' => $headers,
		);
	}

	/**
	 * Redact sensitive request headers.
	 *
	 * @param array<string, string> $headers Request headers.
	 * @return array<string, string>
	 */
	private function redact_headers( array $headers ) {
		$redacted = array();

		foreach ( $headers as $key => $value ) {
			$key = $this->sanitize_header_name( $key );

			if ( '' === $key ) {
				continue;
			}

			$redacted[ $key ] = $this->is_sensitive_header( $key ) ? '[redacted]' : sanitize_text_field( (string) $value );
		}

		return $redacted;
	}

	/**
	 * Check whether a header is sensitive.
	 *
	 * @param string $header Header name.
	 * @return bool
	 */
	private function is_sensitive_header( $header ) {
		$header = strtolower( (string) $header );

		$sensitive_headers = array(
			'authorization',
			'proxy-authorization',
			'x-api-key',
			'api-key',
			'openai-organization',
			'openai-project',
		);

		return in_array( $header, $sensitive_headers, true );
	}

	/**
	 * Build readable HTTP error message.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param string $message     HTTP message.
	 * @param string $body        Response body.
	 * @return string
	 */
	private function get_http_error_message( $status_code, $message, $body ) {
		$status_code = absint( $status_code );
		$message     = sanitize_text_field( (string) $message );

		if ( '' !== $message ) {
			return sprintf(
				/* translators: 1: HTTP status code, 2: HTTP status message. */
				__( 'HTTP request failed with status %1$d: %2$s', 'supportcandy-ai' ),
				$status_code,
				$message
			);
		}

		$decoded = $this->decode_json_body( $body );

		if ( is_array( $decoded ) ) {
			$provider_message = $this->extract_error_message_from_json( $decoded );

			if ( '' !== $provider_message ) {
				return sprintf(
					/* translators: 1: HTTP status code, 2: Provider error message. */
					__( 'HTTP request failed with status %1$d: %2$s', 'supportcandy-ai' ),
					$status_code,
					$provider_message
				);
			}
		}

		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'HTTP request failed with status %d.', 'supportcandy-ai' ),
			$status_code
		);
	}

	/**
	 * Extract common provider error message from JSON response.
	 *
	 * @param array<string, mixed> $json JSON response.
	 * @return string
	 */
	private function extract_error_message_from_json( array $json ) {
		if ( isset( $json['error'] ) && is_array( $json['error'] ) && isset( $json['error']['message'] ) ) {
			return sanitize_text_field( (string) $json['error']['message'] );
		}

		if ( isset( $json['error'] ) && is_string( $json['error'] ) ) {
			return sanitize_text_field( $json['error'] );
		}

		if ( isset( $json['message'] ) && is_string( $json['message'] ) ) {
			return sanitize_text_field( $json['message'] );
		}

		return '';
	}

	/**
	 * Calculate request duration in milliseconds.
	 *
	 * @param float $started_at Start time from microtime(true).
	 * @return int
	 */
	private function calculate_duration_ms( $started_at ) {
		return absint( round( ( microtime( true ) - (float) $started_at ) * 1000 ) );
	}

	/**
	 * Sanitize an array recursively.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, mixed>
	 */
	private function sanitize_array_value( $value ) {
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
				$sanitized[ $array_key ] = $this->sanitize_array_value( $array_value );
				continue;
			}

			if ( is_bool( $array_value ) ) {
				$sanitized[ $array_key ] = $array_value;
				continue;
			}

			if ( is_int( $array_value ) || is_float( $array_value ) ) {
				$sanitized[ $array_key ] = $array_value;
				continue;
			}

			$sanitized[ $array_key ] = sanitize_text_field( (string) $array_value );
		}

		return $sanitized;
	}
}
