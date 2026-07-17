<?php
/**
 * Safe file content extraction for Custom Knowledge Base sources.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Extracts bounded plain text from an authenticated WordPress upload. */
final class SCAI_File_Content_Extractor {

	const MAX_FILE_SIZE = 2097152;
	const MAX_CONTENT_LENGTH = 100000;
	const MAX_CSV_ROWS = 1000;
	const MAX_CSV_COLUMNS = 50;
	const MAX_JSON_ITEMS = 5000;

	/**
	 * Validate, extract, and remove one temporary upload.
	 *
	 * @param array<string, mixed> $file Uploaded file item.
	 * @param array<string, mixed> $args Reserved extraction arguments.
	 * @return array<string, mixed>
	 */
	public function extract( array $file, array $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$result  = $this->error_result( 'invalid_file', __( 'Select a valid supported file.', 'supportcandy-ai' ) );
		$tmp     = isset( $file['tmp_name'] ) && is_scalar( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$cleanup = '' !== $tmp && is_uploaded_file( $tmp );

		try {
			$error = isset( $file['error'] ) ? absint( $file['error'] ) : UPLOAD_ERR_NO_FILE;
			$size  = isset( $file['size'] ) ? absint( $file['size'] ) : 0;
			$name  = isset( $file['name'] ) && is_scalar( $file['name'] ) ? sanitize_file_name( wp_basename( (string) $file['name'] ) ) : '';
			$actual_size = $cleanup && file_exists( $tmp ) ? filesize( $tmp ) : false;

			if ( UPLOAD_ERR_OK !== $error || ! $cleanup || '' === $name || false === $actual_size || $actual_size < 1 || $actual_size > self::MAX_FILE_SIZE || $size !== (int) $actual_size || ! is_readable( $tmp ) ) {
				return $result;
			}

			$allowed = $this->get_allowed_mimes();
			$checked = wp_check_filetype_and_ext( $tmp, $name, $this->get_wordpress_mimes() );
			$ext     = isset( $checked['ext'] ) ? sanitize_key( $checked['ext'] ) : '';
			$mime    = isset( $checked['type'] ) ? sanitize_mime_type( $checked['type'] ) : '';
			$detected_mime = $this->detect_mime_type( $tmp );

			if ( '' === $ext || ! isset( $allowed[ $ext ] ) || ! in_array( $mime, (array) $allowed[ $ext ], true ) || ! in_array( $detected_mime, (array) $allowed[ $ext ], true ) ) {
				return $this->error_result( 'unsupported_file_type', __( 'This file type is not supported.', 'supportcandy-ai' ) );
			}
			$mime = $detected_mime;

			if ( 'pdf' === $ext ) {
				$signature = file_get_contents( $tmp, false, null, 0, 5 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Validated local PHP upload only.
				if ( '%PDF-' !== $signature ) {
					return $this->error_result( 'unsupported_file_type', __( 'This file type is not supported.', 'supportcandy-ai' ) );
				}
			}

			$metadata = array(
				'original_filename' => $name,
				'extension'         => $ext,
				'mime_type'        => $mime,
				'file_size'         => $size,
				'warnings'          => array(),
			);

			if ( 'pdf' === $ext ) {
				return $this->extract_pdf( $tmp, $name, $mime, $metadata );
			}

			$bytes = file_get_contents( $tmp, false, null, 0, self::MAX_FILE_SIZE + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Validated local PHP upload only.
			if ( ! is_string( $bytes ) || '' === $bytes || strlen( $bytes ) > self::MAX_FILE_SIZE || $this->looks_binary( $bytes ) ) {
				return $this->error_result( 'unsafe_file_content', __( 'The uploaded file does not contain safe readable text.', 'supportcandy-ai' ) );
			}

			if ( 'csv' === $ext ) {
				$content = $this->extract_csv( $tmp );
				$extractor = 'csv_parser';
			} elseif ( 'json' === $ext ) {
				$content = $this->extract_json( $bytes );
				$extractor = 'json_parser';
				if ( null === $content ) {
					return $this->error_result( 'invalid_json', __( 'The JSON file could not be safely decoded.', 'supportcandy-ai' ) );
				}
			} else {
				$content = $this->normalize_text( $bytes );
				$extractor = 'plain_text';
			}

			if ( '' === $content ) {
				return $this->error_result( 'no_readable_text', __( 'No readable text was found in the file.', 'supportcandy-ai' ) );
			}

			$metadata['extractor']      = $extractor;
			$metadata['content_length'] = $this->string_length( $content );

			return array(
				'success'    => true,
				'title'      => sanitize_text_field( pathinfo( $name, PATHINFO_FILENAME ) ),
				'filename'   => $name,
				'extension'  => $ext,
				'mime_type'  => $mime,
				'content'    => $content,
				'metadata'   => $metadata,
				'status'     => 'active',
				'error_code' => '',
				'message'    => __( 'File text extracted.', 'supportcandy-ai' ),
			);
		} catch ( Throwable $exception ) {
			return $this->error_result( 'file_extraction_failed', __( 'The file could not be safely processed.', 'supportcandy-ai' ) );
		} finally {
			if ( $cleanup && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/** Use only an explicitly configured PDF extractor filter. */
	private function extract_pdf( $tmp, $name, $mime, array $metadata ) {
		$metadata['extractor'] = 'scai_extract_pdf_text';

		if ( ! has_filter( 'scai_extract_pdf_text' ) ) {
			$metadata['unsupported_reason'] = 'pdf_extractor_unavailable';
			return array(
				'success' => false, 'title' => sanitize_text_field( pathinfo( $name, PATHINFO_FILENAME ) ),
				'filename' => $name, 'extension' => 'pdf', 'mime_type' => $mime, 'content' => '',
				'metadata' => $metadata, 'status' => 'unsupported', 'error_code' => 'pdf_unsupported',
				'message' => __( 'PDF text extraction is not available in this build. Upload a TXT/Markdown version or configure an approved extractor.', 'supportcandy-ai' ),
			);
		}

		$text = apply_filters( 'scai_extract_pdf_text', '', $tmp, $metadata );
		$text = is_scalar( $text ) ? $this->normalize_text( (string) $text ) : '';

		if ( '' === $text ) {
			$metadata['unsupported_reason'] = 'pdf_extractor_unavailable';
			return array(
				'success' => false, 'title' => sanitize_text_field( pathinfo( $name, PATHINFO_FILENAME ) ),
				'filename' => $name, 'extension' => 'pdf', 'mime_type' => $mime, 'content' => '',
				'metadata' => $metadata, 'status' => 'unsupported', 'error_code' => 'pdf_unsupported',
				'message' => __( 'PDF text extraction is not available in this build. Upload a TXT/Markdown version or configure an approved extractor.', 'supportcandy-ai' ),
			);
		}

		$metadata['content_length'] = $this->string_length( $text );
		return array(
			'success' => true, 'title' => sanitize_text_field( pathinfo( $name, PATHINFO_FILENAME ) ),
			'filename' => $name, 'extension' => 'pdf', 'mime_type' => $mime, 'content' => $text,
			'metadata' => $metadata, 'status' => 'active', 'error_code' => '',
			'message' => __( 'PDF text extracted.', 'supportcandy-ai' ),
		);
	}

	/** Parse bounded CSV into readable, formula-neutralized rows. */
	private function extract_csv( $tmp ) {
		$handle = fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Validated local upload.
		if ( false === $handle ) {
			return '';
		}
		$lines = array();
		try {
			for ( $row = 0; $row < self::MAX_CSV_ROWS && false !== ( $cells = fgetcsv( $handle ) ); ++$row ) {
				$safe = array();
				foreach ( array_slice( $cells, 0, self::MAX_CSV_COLUMNS ) as $cell ) {
					$cell = $this->substring( $this->normalize_text( $cell ), 0, 1000 );
					if ( 1 === preg_match( '/^[=+\-@]/', ltrim( $cell ) ) ) {
						$cell = "'" . $cell;
					}
					$safe[] = $cell;
				}
				$lines[] = implode( ' | ', $safe );
				if ( $this->string_length( implode( "\n", $lines ) ) >= self::MAX_CONTENT_LENGTH ) {
					break;
				}
			}
		} finally {
			fclose( $handle );
		}
		return $this->substring( trim( implode( "\n", $lines ) ), 0, self::MAX_CONTENT_LENGTH );
	}

	/** Decode and flatten bounded JSON while redacting secret-looking keys. */
	private function extract_json( $bytes ) {
		$data = json_decode( $this->to_utf8( $bytes ), true, 20 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return null;
		}
		$lines = array();
		$count = 0;
		$this->flatten_json( $data, '', $lines, $count, 0 );
		return $this->substring( trim( implode( "\n", $lines ) ), 0, self::MAX_CONTENT_LENGTH );
	}

	private function flatten_json( $value, $path, array &$lines, &$count, $depth ) {
		if ( $depth > 12 || $count >= self::MAX_JSON_ITEMS || $this->string_length( implode( "\n", $lines ) ) >= self::MAX_CONTENT_LENGTH ) {
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$key = is_scalar( $key ) ? sanitize_text_field( (string) $key ) : '';
				$next = '' === $path ? $key : $path . '.' . $key;
				if ( $this->is_secret_key( $key ) ) {
					$lines[] = $next . ': [REDACTED]';
					++$count;
					continue;
				}
				$this->flatten_json( $item, $next, $lines, $count, $depth + 1 );
			}
			return;
		}
		if ( is_scalar( $value ) || null === $value ) {
			$scalar = null === $value ? 'null' : ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value );
			$lines[] = $path . ': ' . $this->substring( $this->normalize_text( $scalar ), 0, 1000 );
			++$count;
		}
	}

	private function is_secret_key( $key ) {
		$key = strtolower( str_replace( array( '-', ' ' ), '_', $key ) );

		foreach ( array( 'password', 'passwd', 'passphrase', 'token', 'secret', 'api_key', 'apikey', 'authorization', 'auth', 'bearer', 'private_key', 'credential' ) as $fragment ) {
			if ( false !== strpos( $key, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	private function looks_binary( $bytes ) {
		if ( false !== strpos( $bytes, "\0" ) ) {
			return true;
		}
		$sample = substr( $bytes, 0, 8192 );
		$controls = preg_match_all( '/[\x01-\x08\x0B\x0C\x0E-\x1F]/', $sample );
		return false !== $controls && $controls > max( 4, strlen( $sample ) * 0.02 );
	}

	private function normalize_text( $value ) {
		$text = $this->to_utf8( is_scalar( $value ) ? (string) $value : '' );
		$text = strip_shortcodes( $text );
		$text = wp_strip_all_tags( $text, true );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/[^\P{C}\n\t]/u', '', $text );
		$text = preg_replace( '/[ \t]+/u', ' ', is_string( $text ) ? $text : '' );
		$text = preg_replace( "/\n{3,}/", "\n\n", is_string( $text ) ? $text : '' );
		return $this->substring( is_string( $text ) ? trim( $text ) : '', 0, self::MAX_CONTENT_LENGTH );
	}

	private function to_utf8( $value ) {
		if ( function_exists( 'mb_detect_encoding' ) && function_exists( 'mb_convert_encoding' ) ) {
			$encoding = mb_detect_encoding( $value, array( 'UTF-8', 'Windows-1252', 'ISO-8859-1' ), true );
			if ( $encoding && 'UTF-8' !== $encoding ) {
				$value = mb_convert_encoding( $value, 'UTF-8', $encoding );
			}
		}
		return wp_check_invalid_utf8( $value, true );
	}

	private function get_allowed_mimes() {
		return array(
			'txt' => 'text/plain', 'md' => array( 'text/plain', 'text/markdown' ),
			'markdown' => array( 'text/plain', 'text/markdown' ), 'csv' => array( 'text/csv', 'text/plain', 'application/csv' ),
			'log' => 'text/plain', 'json' => array( 'application/json', 'text/plain' ), 'pdf' => 'application/pdf',
		);
	}

	private function get_wordpress_mimes() {
		return array(
			'txt' => 'text/plain', 'md|markdown' => 'text/markdown', 'csv' => 'text/csv',
			'log' => 'text/plain', 'json' => 'application/json', 'pdf' => 'application/pdf',
		);
	}

	/** Detect the actual local upload MIME without trusting the client value. */
	private function detect_mime_type( $tmp ) {
		$mime = '';
		if ( class_exists( 'finfo' ) ) {
			$finfo = new finfo( FILEINFO_MIME_TYPE );
			$mime  = $finfo->file( $tmp );
		} elseif ( function_exists( 'mime_content_type' ) ) {
			$mime = mime_content_type( $tmp );
		}
		return is_string( $mime ) ? sanitize_mime_type( strtolower( trim( $mime ) ) ) : '';
	}

	private function error_result( $code, $message ) {
		return array( 'success' => false, 'title' => '', 'filename' => '', 'extension' => '', 'mime_type' => '', 'content' => '', 'metadata' => array(), 'status' => 'error', 'error_code' => sanitize_key( $code ), 'message' => sanitize_text_field( $message ) );
	}

	private function substring( $value, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, $start, $length ) : substr( (string) $value, $start, $length );
	}

	private function string_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value ) : strlen( (string) $value );
	}
}
