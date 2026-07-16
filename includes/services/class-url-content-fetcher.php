<?php
/**
 * Safe single-page URL content fetcher.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Fetches readable text from one public HTTP(S) page without crawling. */
final class SCAI_URL_Content_Fetcher {

	const MAX_RESPONSE_BYTES = 2097152;
	const MAX_CONTENT_LENGTH = 100000;
	const MIN_CONTENT_LENGTH = 20;

	/**
	 * Fetch and extract one public page.
	 *
	 * @param mixed                $url  Candidate URL.
	 * @param array<string, mixed> $args Optional fetch arguments.
	 * @return array<string, mixed>
	 */
	public function fetch( $url, array $args = array() ) {
		$result = $this->error_result( 'invalid_url', __( 'Enter a valid public HTTP or HTTPS URL.', 'supportcandy-ai' ) );

		try {
			$url = $this->validate_url( $url );
			if ( '' === $url ) {
				return $result;
			}
			if ( ! $this->is_public_host( $url ) ) {
				return $this->error_result( 'url_rejected', __( 'The URL must point to a public website.', 'supportcandy-ai' ) );
			}
			if ( ! function_exists( 'wp_safe_remote_get' ) ) {
				return $this->error_result( 'fetch_failed', __( 'The URL could not be fetched.', 'supportcandy-ai' ) );
			}

			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 10,
					'redirection'         => 3,
					'limit_response_size' => self::MAX_RESPONSE_BYTES,
					'reject_unsafe_urls'  => true,
					'headers'             => array(
						'Accept'     => 'text/html, application/xhtml+xml, text/plain, text/markdown;q=0.9',
						'User-Agent' => 'SupportCandy-AI-Knowledge/1.0; ' . home_url( '/' ),
					),
					'cookies'             => array(),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $this->error_result( 'fetch_failed', __( 'The public page could not be fetched.', 'supportcandy-ai' ) );
			}

			$status = absint( wp_remote_retrieve_response_code( $response ) );
			if ( $status < 200 || $status >= 300 ) {
				return $this->error_result( 'fetch_failed', __( 'The public page did not return a successful response.', 'supportcandy-ai' ) );
			}

			$content_type = $this->normalize_content_type( wp_remote_retrieve_header( $response, 'content-type' ) );
			if ( ! in_array( $content_type, $this->get_allowed_content_types(), true ) ) {
				return $this->error_result( 'unsupported_content_type', __( 'The URL did not return a supported text page.', 'supportcandy-ai' ) );
			}

			$body = wp_remote_retrieve_body( $response );
			if ( ! is_string( $body ) || strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
				return $this->error_result( 'fetch_failed', __( 'The page response was too large or invalid.', 'supportcandy-ai' ) );
			}

			$title   = $this->extract_title( $body, $content_type );
			$content = $this->extract_text( $body, $content_type );
			if ( $this->string_length( $content ) < self::MIN_CONTENT_LENGTH ) {
				return $this->error_result( 'no_readable_text', __( 'No readable page text was found.', 'supportcandy-ai' ) );
			}

			$header_length = wp_remote_retrieve_header( $response, 'content-length' );
			$content_length = is_numeric( $header_length ) ? min( self::MAX_RESPONSE_BYTES, absint( $header_length ) ) : strlen( $body );

			return array(
				'success'    => true,
				'url'        => $url,
				'title'      => $title,
				'mime_type'  => $content_type,
				'content'    => $content,
				'metadata'   => array(
					'http_status'   => $status,
					'content_type'  => $content_type,
					'content_length' => $content_length,
					'fetched_at'    => current_time( 'mysql', true ),
				),
				'error_code' => '',
				'message'    => __( 'URL content fetched.', 'supportcandy-ai' ),
			);
		} catch ( Throwable $exception ) {
			return $this->error_result( 'fetch_failed', __( 'The public page could not be fetched.', 'supportcandy-ai' ) );
		}
	}

	/** Validate and canonicalize a URL without credentials or unusual ports. */
	private function validate_url( $url ) {
		$url = is_scalar( $url ) ? trim( (string) $url ) : '';
		if ( '' === $url || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		if ( isset( $parts['port'] ) && ! in_array( absint( $parts['port'] ), array( 80, 443 ), true ) ) {
			return '';
		}
		$url = remove_query_arg( array(), $url );
		$url = preg_replace( '/#.*$/', '', $url );

		return esc_url_raw( is_string( $url ) ? $url : '', array( 'http', 'https' ) );
	}

	/** Reject local names and IPs resolving to non-public ranges. */
	private function is_public_host( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || 'localhost' === $host || '.local' === substr( $host, -6 ) || '.internal' === substr( $host, -9 ) ) {
			return false;
		}
		$ips = array();
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$ipv4 = function_exists( 'gethostbynamel' ) ? gethostbynamel( $host ) : false;
			$ips  = is_array( $ipv4 ) ? $ipv4 : array();
			if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
				$records = dns_get_record( $host, DNS_AAAA );
				foreach ( is_array( $records ) ? $records : array() as $record ) {
					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}
		if ( empty( $ips ) ) {
			return false;
		}
		foreach ( array_unique( $ips ) as $ip ) {
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}
		return true;
	}

	/** Extract readable text and discard active/non-content HTML elements. */
	private function extract_text( $body, $content_type ) {
		$text = ( 'text/html' === $content_type || 'application/xhtml+xml' === $content_type )
			? preg_replace( '#<(script|style|noscript|template|form)\b[^>]*>.*?</\1>#is', ' ', $body )
			: $body;
		$text = preg_replace( '/<!--.*?-->/s', ' ', is_string( $text ) ? $text : '' );
		$text = preg_replace( '#</?(?:address|article|aside|blockquote|br|dd|div|dl|dt|footer|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|table|td|th|tr|ul)\b[^>]*>#i', "\n", is_string( $text ) ? $text : '' );
		$text = wp_strip_all_tags( is_string( $text ) ? $text : '', true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
		$text = preg_replace( '/[^\P{C}\n\t]/u', '', $text );
		$text = preg_replace( '/[ \t]+/u', ' ', is_string( $text ) ? $text : '' );
		$text = preg_replace( "/\n{3,}/", "\n\n", is_string( $text ) ? $text : '' );
		$text = is_string( $text ) ? trim( $text ) : '';

		return $this->substring( $text, 0, self::MAX_CONTENT_LENGTH );
	}

	/** Extract a bounded HTML title when present. */
	private function extract_title( $body, $content_type ) {
		if ( ! in_array( $content_type, array( 'text/html', 'application/xhtml+xml' ), true ) || 1 !== preg_match( '/<title\b[^>]*>(.*?)<\/title>/is', $body, $matches ) ) {
			return '';
		}
		$title = html_entity_decode( wp_strip_all_tags( $matches[1], true ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );

		return $this->substring( sanitize_text_field( $title ), 0, 200 );
	}

	private function normalize_content_type( $value ) {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return trim( (string) strtok( $value, ';' ) );
	}

	private function get_allowed_content_types() {
		return array( 'text/html', 'text/plain', 'text/markdown', 'application/xhtml+xml' );
	}

	private function error_result( $code, $message ) {
		return array( 'success' => false, 'url' => '', 'title' => '', 'mime_type' => '', 'content' => '', 'metadata' => array(), 'error_code' => sanitize_key( $code ), 'message' => sanitize_text_field( $message ) );
	}

	private function substring( $value, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, $start, $length ) : substr( (string) $value, $start, $length );
	}

	private function string_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value ) : strlen( (string) $value );
	}
}
