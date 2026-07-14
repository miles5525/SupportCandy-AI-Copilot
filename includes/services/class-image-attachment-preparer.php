<?php
/**
 * Local image attachment preparer for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and prepares local image attachments for future vision requests.
 */
final class SCAI_Image_Attachment_Preparer {

	/**
	 * Default maximum image size in bytes.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_IMAGE_SIZE = 5242880;

	/**
	 * Default maximum images prepared per request.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_IMAGES = 3;

	/**
	 * Determine whether an attachment can be prepared as an image.
	 *
	 * @param array<string, mixed> $attachment Attachment metadata.
	 * @return bool
	 */
	public function can_prepare_image( array $attachment ) {
		return $this->validate_image_attachment( $attachment, array() )['valid'];
	}

	/**
	 * Prepare one local image as a data URL.
	 *
	 * @param array<string, mixed> $attachment Attachment metadata.
	 * @param array<string, mixed> $args       Preparation arguments.
	 * @return array<string, mixed>
	 */
	public function prepare_image( array $attachment, array $args = array() ) {
		$filename  = isset( $attachment['filename'] ) && is_scalar( $attachment['filename'] ) ? sanitize_file_name( (string) $attachment['filename'] ) : '';
		$mime_type = isset( $attachment['mime_type'] ) && is_scalar( $attachment['mime_type'] ) ? sanitize_mime_type( (string) $attachment['mime_type'] ) : '';
		$extension = $this->get_extension( $filename );
		$result    = $this->get_result_template( $filename, $mime_type, $extension );
		$validation = $this->validate_image_attachment( $attachment, $args );

		if ( ! $validation['valid'] ) {
			$result['file_size'] = $validation['file_size'];
			return $this->with_error( $result, $validation['error_code'], $validation['error_message'] );
		}

		$result['mime_type'] = $validation['mime_type'];
		$result['file_size'] = $validation['file_size'];
		$result['detail']    = $this->get_detail( $args );
		$data_url            = $this->build_data_url( $validation['path'], $validation['mime_type'] );

		if ( '' === $data_url ) {
			return $this->with_error( $result, 'image_encoding_failed', __( 'The image could not be prepared.', 'supportcandy-ai' ) );
		}

		$result['success']        = true;
		$result['image_data_url'] = $data_url;

		/**
		 * Filter prepared image data.
		 *
		 * @param array<string, mixed> $result     Prepared image result.
		 * @param array<string, mixed> $attachment Attachment metadata.
		 * @param array<string, mixed> $args       Preparation arguments.
		 */
		$result = apply_filters( 'scai_image_attachment_prepared_image', $result, $attachment, $args );

		return $this->sanitize_prepared_result( is_array( $result ) ? $result : array(), $filename, $validation['mime_type'], $extension );
	}

	/**
	 * Prepare a bounded number of supported images.
	 *
	 * @param array<int, mixed>    $attachments Attachments.
	 * @param array<string, mixed> $args        Preparation arguments.
	 * @return array<int, array<string, mixed>>
	 */
	public function prepare_multiple( array $attachments, array $args = array() ) {
		$max_images = isset( $args['max_images'] ) ? absint( $args['max_images'] ) : self::DEFAULT_MAX_IMAGES;
		$max_images = apply_filters( 'scai_image_attachment_max_images', $max_images, $args );
		$max_images = max( 1, absint( $max_images ) );
		$prepared   = array();

		foreach ( $attachments as $attachment ) {
			if ( ! is_array( $attachment ) || ! $this->validate_image_attachment( $attachment, $args )['valid'] ) {
				continue;
			}

			$result = $this->prepare_image( $attachment, $args );

			if ( ! empty( $result['success'] ) ) {
				$prepared[] = $result;
			}

			if ( count( $prepared ) >= $max_images ) {
				break;
			}
		}

		return $prepared;
	}

	/**
	 * Get a sanitized lowercase extension.
	 *
	 * @param string $filename Filename.
	 * @return string
	 */
	public function get_extension( $filename ) {
		$filename = sanitize_file_name( (string) $filename );

		return sanitize_key( strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) ) );
	}

	/**
	 * Determine whether MIME type and extension are supported.
	 *
	 * @param string $mime_type MIME type.
	 * @param string $extension Extension.
	 * @return bool
	 */
	public function is_supported_image( $mime_type, $extension ) {
		$mime_type = sanitize_mime_type( (string) $mime_type );
		$extension = sanitize_key( (string) $extension );
		$mime_types = apply_filters(
			'scai_image_attachment_allowed_mime_types',
			array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' )
		);
		$extensions = apply_filters(
			'scai_image_attachment_allowed_extensions',
			array( 'jpg', 'jpeg', 'png', 'webp', 'gif' )
		);
		$mime_types = is_array( $mime_types ) ? array_map( 'sanitize_mime_type', $mime_types ) : array();
		$extensions = is_array( $extensions ) ? array_map( 'sanitize_key', $extensions ) : array();

		return in_array( $mime_type, $mime_types, true ) && in_array( $extension, $extensions, true );
	}

	/**
	 * Determine whether a path is inside an allowed local base directory.
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
		$allowed_bases = apply_filters( 'scai_image_attachment_allowed_base_dirs', $allowed_bases, $upload_dir );

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
	 * Build a data URL for a validated local image.
	 *
	 * @param string $path      Local path.
	 * @param string $mime_type MIME type.
	 * @return string
	 */
	public function build_data_url( $path, $mime_type ) {
		$path      = wp_normalize_path( (string) $path );
		$mime_type = sanitize_mime_type( (string) $mime_type );

		if ( ! $this->is_safe_path( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$file_size = $this->get_file_size( $path );

		if ( 0 >= $file_size || $file_size > $this->get_max_file_size() ) {
			return '';
		}

		$actual_mime = $this->get_actual_image_mime( $path );
		$extension   = $this->get_extension( basename( $path ) );

		if ( $actual_mime !== $mime_type || ! $this->is_supported_image( $actual_mime, $extension ) ) {
			return '';
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Required to base64 encode a validated bounded local image.

		if ( false === $contents || strlen( $contents ) !== $file_size ) {
			return '';
		}

		return 'data:' . $actual_mime . ';base64,' . base64_encode( $contents );
	}

	/**
	 * Validate image metadata and its local file.
	 *
	 * @param array<string, mixed> $attachment Attachment metadata.
	 * @param array<string, mixed> $args       Preparation arguments.
	 * @return array{valid: bool, path: string, mime_type: string, file_size: int, error_code: string, error_message: string}
	 */
	private function validate_image_attachment( array $attachment, array $args ) {
		$failure = array(
			'valid'         => false,
			'path'          => '',
			'mime_type'     => '',
			'file_size'     => 0,
			'error_code'    => 'image_not_supported',
			'error_message' => __( 'The attachment is not a supported local image.', 'supportcandy-ai' ),
		);

		if ( empty( $attachment['local_path_exists'] ) || empty( $attachment['local_path'] ) || ! is_scalar( $attachment['local_path'] ) ) {
			return $failure;
		}

		$filename  = isset( $attachment['filename'] ) && is_scalar( $attachment['filename'] ) ? sanitize_file_name( (string) $attachment['filename'] ) : '';
		$mime_type = isset( $attachment['mime_type'] ) && is_scalar( $attachment['mime_type'] ) ? sanitize_mime_type( (string) $attachment['mime_type'] ) : '';
		$extension = $this->get_extension( $filename );

		if ( ! $this->is_supported_image( $mime_type, $extension ) ) {
			return $failure;
		}

		$path = wp_normalize_path( (string) $attachment['local_path'] );

		if ( ! $this->is_safe_path( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			$failure['error_code']    = 'unsafe_image_path';
			$failure['error_message'] = __( 'The image path is missing, unreadable, or not allowed.', 'supportcandy-ai' );

			return $failure;
		}

		$real_path   = realpath( $path );
		$path        = false !== $real_path ? wp_normalize_path( $real_path ) : '';
		$file_size   = '' !== $path ? $this->get_file_size( $path ) : 0;
		$max_size    = $this->get_max_file_size( $args );
		$actual_mime = '' !== $path ? $this->get_actual_image_mime( $path ) : '';

		if ( 0 >= $file_size || $file_size > $max_size ) {
			$failure['file_size']     = $file_size;
			$failure['error_code']    = 'image_too_large';
			$failure['error_message'] = __( 'The image exceeds the allowed file size.', 'supportcandy-ai' );

			return $failure;
		}

		if ( $actual_mime !== $mime_type || ! $this->is_supported_image( $actual_mime, $extension ) ) {
			$failure['file_size']     = $file_size;
			$failure['error_code']    = 'image_type_mismatch';
			$failure['error_message'] = __( 'The image file type does not match its attachment metadata.', 'supportcandy-ai' );

			return $failure;
		}

		return array(
			'valid'         => true,
			'path'          => $path,
			'mime_type'     => $actual_mime,
			'file_size'     => $file_size,
			'error_code'    => '',
			'error_message' => '',
		);
	}

	/**
	 * Get filtered maximum image size.
	 *
	 * @param array<string, mixed> $args Preparation arguments.
	 * @return int
	 */
	private function get_max_file_size( array $args = array() ) {
		$value = isset( $args['max_file_size'] ) ? absint( $args['max_file_size'] ) : self::DEFAULT_MAX_IMAGE_SIZE;
		$value = apply_filters( 'scai_image_attachment_max_file_size', $value, $args );

		return max( 1, absint( $value ) );
	}

	/**
	 * Get normalized vision detail.
	 *
	 * @param array<string, mixed> $args Preparation arguments.
	 * @return string
	 */
	private function get_detail( array $args ) {
		$detail = isset( $args['detail'] ) ? sanitize_key( $args['detail'] ) : 'low';
		$detail = apply_filters( 'scai_image_attachment_detail', $detail, $args );
		$detail = sanitize_key( is_scalar( $detail ) ? (string) $detail : '' );

		return in_array( $detail, array( 'low', 'high', 'auto' ), true ) ? $detail : 'low';
	}

	/**
	 * Get actual image MIME type from local file headers.
	 *
	 * @param string $path Safe local path.
	 * @return string
	 */
	private function get_actual_image_mime( $path ) {
		$mime_type = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $path ) : false;

		return is_string( $mime_type ) ? sanitize_mime_type( $mime_type ) : '';
	}

	/**
	 * Get local file size without reading its contents.
	 *
	 * @param string $path Safe local path.
	 * @return int
	 */
	private function get_file_size( $path ) {
		$size = filesize( $path );

		return false === $size ? 0 : absint( $size );
	}

	/**
	 * Build a normalized preparation result.
	 *
	 * @param string $filename  Filename.
	 * @param string $mime_type MIME type.
	 * @param string $extension Extension.
	 * @return array<string, mixed>
	 */
	private function get_result_template( $filename, $mime_type, $extension ) {
		return array(
			'success'        => false,
			'filename'       => sanitize_file_name( $filename ),
			'mime_type'      => sanitize_mime_type( $mime_type ),
			'extension'      => sanitize_key( $extension ),
			'file_size'      => 0,
			'image_data_url' => '',
			'detail'         => 'low',
			'error_code'     => '',
			'error_message'  => '',
		);
	}

	/**
	 * Add a safe error to a result.
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
	 * Sanitize a filtered prepared result without exposing local paths.
	 *
	 * @param array<string, mixed> $result     Filtered result.
	 * @param string               $filename   Fallback filename.
	 * @param string               $mime_type  Fallback MIME type.
	 * @param string               $extension  Fallback extension.
	 * @return array<string, mixed>
	 */
	private function sanitize_prepared_result( array $result, $filename, $mime_type, $extension ) {
		$data_url = isset( $result['image_data_url'] ) && is_scalar( $result['image_data_url'] ) ? (string) $result['image_data_url'] : '';
		$prefix   = 'data:' . sanitize_mime_type( $mime_type ) . ';base64,';
		$detail   = isset( $result['detail'] ) && is_scalar( $result['detail'] ) ? sanitize_key( (string) $result['detail'] ) : 'low';

		if ( 0 !== strpos( $data_url, $prefix ) ) {
			$data_url = '';
		}

		return array(
			'success'        => ! empty( $result['success'] ) && '' !== $data_url,
			'filename'       => isset( $result['filename'] ) && is_scalar( $result['filename'] ) ? sanitize_file_name( (string) $result['filename'] ) : sanitize_file_name( $filename ),
			'mime_type'      => sanitize_mime_type( $mime_type ),
			'extension'      => sanitize_key( $extension ),
			'file_size'      => isset( $result['file_size'] ) && is_scalar( $result['file_size'] ) ? absint( $result['file_size'] ) : 0,
			'image_data_url' => $data_url,
			'detail'         => in_array( $detail, array( 'low', 'high', 'auto' ), true ) ? $detail : 'low',
			'error_code'     => isset( $result['error_code'] ) && is_scalar( $result['error_code'] ) ? sanitize_key( (string) $result['error_code'] ) : '',
			'error_message'  => isset( $result['error_message'] ) && is_scalar( $result['error_message'] ) ? sanitize_text_field( (string) $result['error_message'] ) : '',
		);
	}
}
