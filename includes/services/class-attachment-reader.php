<?php
/**
 * Safe text attachment reader for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads bounded excerpts from local text-like ticket attachments.
 */
final class SCAI_Attachment_Reader {

	/**
	 * Default maximum readable file size in bytes.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_FILE_SIZE = 1048576;

	/**
	 * Default maximum excerpt length in characters.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_EXCERPT_CHARS = 6000;

	/**
	 * Default maximum number of lines.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_LINES = 200;

	/**
	 * Determine whether an attachment can be safely read as text.
	 *
	 * @param array<string, mixed> $attachment Attachment metadata.
	 * @return bool
	 */
	public function can_read_attachment( array $attachment ) {
		if ( empty( $attachment['local_path_exists'] ) || empty( $attachment['local_path'] ) || ! is_scalar( $attachment['local_path'] ) ) {
			return false;
		}

		$path = wp_normalize_path( (string) $attachment['local_path'] );

		if ( ! $this->is_safe_path( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		$filename  = isset( $attachment['filename'] ) && is_scalar( $attachment['filename'] ) ? sanitize_file_name( (string) $attachment['filename'] ) : '';
		$mime_type = isset( $attachment['mime_type'] ) && is_scalar( $attachment['mime_type'] ) ? sanitize_mime_type( (string) $attachment['mime_type'] ) : '';

		if ( ! $this->is_text_like( $mime_type, $this->get_extension( $filename ) ) ) {
			return false;
		}

		$max_file_size = $this->get_max_file_size();
		$file_size     = $this->get_file_size( $path );

		if ( $file_size > $max_file_size ) {
			return false;
		}

		$reported_size = isset( $attachment['file_size'] ) ? absint( $attachment['file_size'] ) : 0;
		$reported_size = 0 === $reported_size && isset( $attachment['size'] ) ? absint( $attachment['size'] ) : $reported_size;

		return 0 === $reported_size || $reported_size <= $max_file_size;
	}

	/**
	 * Read a safe, bounded excerpt from an attachment.
	 *
	 * @param array<string, mixed> $attachment Attachment metadata.
	 * @param array<string, mixed> $args       Reader arguments.
	 * @return array<string, mixed>
	 */
	public function read_attachment_excerpt( array $attachment, array $args = array() ) {
		$filename  = isset( $attachment['filename'] ) && is_scalar( $attachment['filename'] ) ? sanitize_file_name( (string) $attachment['filename'] ) : '';
		$mime_type = isset( $attachment['mime_type'] ) && is_scalar( $attachment['mime_type'] ) ? sanitize_mime_type( (string) $attachment['mime_type'] ) : '';
		$extension = $this->get_extension( $filename );
		$result    = $this->get_result_template( $filename, $mime_type, $extension );

		if ( ! $this->can_read_attachment( $attachment ) ) {
			return $this->with_error( $result, 'attachment_not_readable', __( 'The attachment is not a safe, readable text file.', 'supportcandy-ai' ) );
		}

		$path      = wp_normalize_path( (string) $attachment['local_path'] );
		$real_path = realpath( $path );

		if ( false === $real_path || ! $this->is_safe_path( $real_path ) ) {
			return $this->with_error( $result, 'unsafe_attachment_path', __( 'The attachment path is not allowed.', 'supportcandy-ai' ) );
		}

		$path                = wp_normalize_path( $real_path );
		$result['file_size'] = $this->get_file_size( $path );
		$max_file_size       = $this->get_max_file_size( $args );

		if ( $result['file_size'] > $max_file_size ) {
			return $this->with_error( $result, 'attachment_too_large', __( 'The attachment exceeds the allowed text file size.', 'supportcandy-ai' ) );
		}

		$max_chars = $this->get_max_excerpt_chars( $args );
		$max_lines = isset( $args['max_lines'] ) ? max( 1, absint( $args['max_lines'] ) ) : self::DEFAULT_MAX_LINES;
		$handle    = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded local streaming read is required.

		if ( false === $handle ) {
			return $this->with_error( $result, 'attachment_open_failed', __( 'The attachment could not be opened for reading.', 'supportcandy-ai' ) );
		}

		$content   = '';
		$truncated = false;
		$lines     = 0;

		while ( $lines < $max_lines && ! feof( $handle ) ) {
			$line = fgets( $handle, 8192 );

			if ( false === $line ) {
				break;
			}

			$lines++;
			$remaining = $max_chars - $this->string_length( $content );

			if ( 0 >= $remaining ) {
				$truncated = true;
				break;
			}

			if ( $this->string_length( $line ) > $remaining ) {
				$content  .= $this->substring( $line, 0, $remaining );
				$truncated = true;
				break;
			}

			$content .= $line;
		}

		if ( ! feof( $handle ) ) {
			$truncated = true;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the bounded local stream.

		if ( false !== strpos( $content, "\0" ) ) {
			return $this->with_error( $result, 'binary_content_detected', __( 'The attachment appears to contain binary data.', 'supportcandy-ai' ) );
		}

		$excerpt = $this->sanitize_excerpt( $content );

		/**
		 * Filter a safe attachment excerpt.
		 *
		 * @param string               $excerpt    Sanitized excerpt.
		 * @param array<string, mixed> $attachment Attachment metadata.
		 * @param array<string, mixed> $args       Reader arguments.
		 */
		$excerpt = apply_filters( 'scai_attachment_reader_excerpt', $excerpt, $attachment, $args );
		$excerpt = $this->sanitize_excerpt( is_scalar( $excerpt ) ? (string) $excerpt : '' );

		if ( '' === $excerpt ) {
			return $this->with_error( $result, 'attachment_excerpt_empty', __( 'The attachment did not contain readable text.', 'supportcandy-ai' ) );
		}

		$result['success']    = true;
		$result['excerpt']    = $this->substring( $excerpt, 0, $max_chars );
		$result['truncated']  = $truncated || $this->string_length( $excerpt ) > $max_chars;
		$result['lines_read'] = $lines;

		return $result;
	}

	/**
	 * Read a bounded number of safe text attachments.
	 *
	 * @param array<int, mixed>    $attachments Attachments.
	 * @param array<string, mixed> $args        Reader arguments.
	 * @return array<int, array<string, mixed>>
	 */
	public function read_multiple( array $attachments, array $args = array() ) {
		$max_attachments = isset( $args['max_attachments'] ) ? max( 1, min( 20, absint( $args['max_attachments'] ) ) ) : 3;
		$results         = array();

		foreach ( $attachments as $attachment ) {
			if ( ! is_array( $attachment ) || ! $this->can_read_attachment( $attachment ) ) {
				continue;
			}

			$results[] = $this->read_attachment_excerpt( $attachment, $args );

			if ( count( $results ) >= $max_attachments ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Get a sanitized lowercase extension.
	 *
	 * @param string $filename Filename.
	 * @return string
	 */
	public function get_extension( $filename ) {
		return sanitize_key( strtolower( (string) pathinfo( sanitize_file_name( (string) $filename ), PATHINFO_EXTENSION ) ) );
	}

	/**
	 * Determine whether a MIME type or extension is text-like.
	 *
	 * @param string $mime_type MIME type.
	 * @param string $extension Extension.
	 * @return bool
	 */
	public function is_text_like( $mime_type, $extension ) {
		$mime_type = strtolower( sanitize_mime_type( (string) $mime_type ) );
		$extension = sanitize_key( (string) $extension );
		$extensions = apply_filters(
			'scai_attachment_reader_allowed_extensions',
			array( 'txt', 'log', 'csv', 'json', 'xml', 'html', 'htm', 'md', 'ini', 'conf', 'yml', 'yaml' )
		);
		$mime_types = apply_filters(
			'scai_attachment_reader_allowed_mime_types',
			array( 'application/json', 'application/xml', 'text/xml', 'application/csv', 'text/csv' )
		);
		$extensions = is_array( $extensions ) ? array_map( 'sanitize_key', $extensions ) : array();
		$mime_types = is_array( $mime_types ) ? array_map( 'sanitize_mime_type', $mime_types ) : array();

		return in_array( $extension, $extensions, true ) || 0 === strpos( $mime_type, 'text/' ) || in_array( $mime_type, $mime_types, true );
	}

	/**
	 * Determine whether a local path is inside an allowed base directory.
	 *
	 * @param string $path Local path.
	 * @return bool
	 */
	public function is_safe_path( $path ) {
		$path = wp_normalize_path( (string) $path );

		if ( '' === $path || false !== strpos( $path, '../' ) || false !== strpos( $path, "\0" ) || wp_is_stream( $path ) ) {
			return false;
		}

		$real_path = realpath( $path );

		if ( false === $real_path ) {
			return false;
		}

		$path          = wp_normalize_path( $real_path );
		$upload_dir    = wp_upload_dir();
		$allowed_bases = ! empty( $upload_dir['basedir'] ) ? array( $upload_dir['basedir'] ) : array();
		$allowed_bases = apply_filters( 'scai_attachment_reader_allowed_base_dirs', $allowed_bases, $upload_dir );

		if ( ! is_array( $allowed_bases ) ) {
			return false;
		}

		foreach ( $allowed_bases as $base ) {
			if ( ! is_scalar( $base ) || '' === (string) $base ) {
				continue;
			}

			$base_real = realpath( (string) $base );

			if ( false === $base_real ) {
				continue;
			}

			$base            = untrailingslashit( wp_normalize_path( $base_real ) );
			$path_comparison = DIRECTORY_SEPARATOR === '\\' ? strtolower( $path ) : $path;
			$base_comparison = DIRECTORY_SEPARATOR === '\\' ? strtolower( $base ) : $base;

			if ( $path_comparison === $base_comparison || 0 === strpos( $path_comparison, trailingslashit( $base_comparison ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sanitize text excerpt content.
	 *
	 * @param string $content Raw excerpt.
	 * @return string
	 */
	public function sanitize_excerpt( $content ) {
		$content = wp_check_invalid_utf8( (string) $content, true );
		$content = str_replace( array( "\r\n", "\r" ), "\n", $content );
		$content = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content );
		$content = preg_replace( "/\n{4,}/", "\n\n\n", is_string( $content ) ? $content : '' );

		return is_string( $content ) ? trim( $content ) : '';
	}

	/**
	 * Get filtered maximum file size.
	 *
	 * @param array<string, mixed> $args Reader arguments.
	 * @return int
	 */
	private function get_max_file_size( array $args = array() ) {
		$value = isset( $args['max_file_size'] ) ? absint( $args['max_file_size'] ) : self::DEFAULT_MAX_FILE_SIZE;
		$value = apply_filters( 'scai_attachment_reader_max_file_size', $value, $args );

		return max( 1, absint( $value ) );
	}

	/**
	 * Get filtered maximum excerpt length.
	 *
	 * @param array<string, mixed> $args Reader arguments.
	 * @return int
	 */
	private function get_max_excerpt_chars( array $args = array() ) {
		$value = isset( $args['max_excerpt_chars'] ) ? absint( $args['max_excerpt_chars'] ) : self::DEFAULT_MAX_EXCERPT_CHARS;
		$value = apply_filters( 'scai_attachment_reader_max_excerpt_chars', $value, $args );

		return max( 1, absint( $value ) );
	}

	/**
	 * Get file size without reading its contents.
	 *
	 * @param string $path Safe local path.
	 * @return int
	 */
	private function get_file_size( $path ) {
		$size = filesize( $path );

		return false === $size ? 0 : absint( $size );
	}

	/**
	 * Build a normalized reader result.
	 *
	 * @param string $filename  Filename.
	 * @param string $mime_type MIME type.
	 * @param string $extension Extension.
	 * @return array<string, mixed>
	 */
	private function get_result_template( $filename, $mime_type, $extension ) {
		return array(
			'success'       => false,
			'filename'      => sanitize_file_name( $filename ),
			'mime_type'     => sanitize_mime_type( $mime_type ),
			'extension'     => sanitize_key( $extension ),
			'file_size'     => 0,
			'excerpt'       => '',
			'truncated'     => false,
			'lines_read'    => 0,
			'error_code'    => '',
			'error_message' => '',
		);
	}

	/**
	 * Add a safe error to a reader result.
	 *
	 * @param array<string, mixed> $result  Result.
	 * @param string               $code    Error code.
	 * @param string               $message Error message.
	 * @return array<string, mixed>
	 */
	private function with_error( array $result, $code, $message ) {
		$result['error_code']    = sanitize_key( $code );
		$result['error_message'] = sanitize_text_field( $message );

		return $result;
	}

	/**
	 * Get multibyte-safe string length.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private function string_length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}

	/**
	 * Get a multibyte-safe substring.
	 *
	 * @param string $text   Text.
	 * @param int    $start  Start.
	 * @param int    $length Length.
	 * @return string
	 */
	private function substring( $text, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, $start, $length ) : substr( $text, $start, $length );
	}
}
