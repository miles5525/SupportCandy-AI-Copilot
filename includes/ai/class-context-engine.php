<?php
/**
 * Context engine for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts normalized ticket context into compact AI-ready context.
 */
final class SCAI_Context_Engine {

	/**
	 * Attachment reader instance.
	 *
	 * @var SCAI_Attachment_Reader|null
	 */
	private $attachment_reader = null;

	/**
	 * Default maximum ticket threads.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_THREADS = 20;

	/**
	 * Default maximum thread body length.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_THREAD_BODY_LENGTH = 2500;

	/**
	 * Default maximum total context length.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_TOTAL_CONTEXT_LENGTH = 12000;

	/**
	 * Maximum attachments included in AI context.
	 *
	 * @var int
	 */
	const MAX_CONTEXT_ATTACHMENTS = 10;

	/**
	 * Maximum BetterDocs articles included in context.
	 *
	 * @var int
	 */
	const MAX_KNOWLEDGE_DOCUMENTS = 3;

	/**
	 * Maximum combined BetterDocs content characters.
	 *
	 * @var int
	 */
	const MAX_KNOWLEDGE_CONTENT_LENGTH = 12000;

	/** Maximum custom knowledge articles included in context. */
	const MAX_CUSTOM_KNOWLEDGE_DOCUMENTS = 3;

	/** Maximum characters from one custom knowledge article. */
	const MAX_CUSTOM_KNOWLEDGE_DOCUMENT_LENGTH = 3000;

	/** Maximum combined custom knowledge content characters. */
	const MAX_CUSTOM_KNOWLEDGE_CONTENT_LENGTH = 8000;

	/**
	 * Build compact AI-ready ticket context.
	 *
	 * @param array<string, mixed> $ticket_context Normalized ticket context from adapter.
	 * @param array<string, mixed> $args           Context args.
	 * @return array<string, mixed>
	 */
	public function build_ticket_context( $ticket_context, array $args = array() ) {
		if ( ! is_array( $ticket_context ) || empty( $ticket_context['ticket'] ) || ! is_array( $ticket_context['ticket'] ) ) {
			return $this->get_empty_context();
		}

		$args        = $this->get_args( $args );
		$ticket      = $this->sanitize_ticket( $ticket_context['ticket'] );
		$threads     = isset( $ticket_context['threads'] ) && is_array( $ticket_context['threads'] )
			? $this->sanitize_threads( $ticket_context['threads'], $args )
			: array();
		$raw_attachments = isset( $ticket_context['attachments'] ) && is_array( $ticket_context['attachments'] ) ? $ticket_context['attachments'] : array();
		$enriched        = $this->enrich_attachments_with_text_excerpts( $raw_attachments, $args );
		$all_attachments = $this->sanitize_attachments( $enriched['attachments'] );
		$reported_attachment_count = isset( $ticket_context['stats']['attachment_count'] ) ? absint( $ticket_context['stats']['attachment_count'] ) : 0;
		$attachment_count          = max( count( $all_attachments ), $reported_attachment_count );
		$attachments               = array_slice( $all_attachments, 0, $args['max_attachments'] );
		$reported_text_omitted     = isset( $ticket_context['stats']['text_attachment_omitted_count'] ) ? absint( $ticket_context['stats']['text_attachment_omitted_count'] ) : 0;
		$text_attachment_omitted   = max( absint( $enriched['omitted_count'] ), $reported_text_omitted );

		if ( empty( $ticket['id'] ) ) {
			return $this->get_empty_context();
		}

		$context = array(
			'ticket'       => $ticket,
			'threads'      => $this->limit_context_size( $ticket, $threads, $attachments, $args ),
			'attachments'  => $attachments,
			'knowledge_base' => isset( $ticket_context['knowledge_base'] ) && is_array( $ticket_context['knowledge_base'] )
				? $this->sanitize_knowledge_base( $ticket_context['knowledge_base'] )
				: array(),
			'custom_knowledge_base' => isset( $ticket_context['custom_knowledge_base'] ) && is_array( $ticket_context['custom_knowledge_base'] )
				? $this->sanitize_custom_knowledge_base( $ticket_context['custom_knowledge_base'] )
				: array(),
			'stats'        => array(
				'thread_count'               => count( $threads ),
				'attachment_count'           => $attachment_count,
				'attachment_omitted_count'   => max( 0, $attachment_count - count( $attachments ) ),
				'text_attachment_omitted_count' => $text_attachment_omitted,
				'context_length'             => 0,
			),
			'generated_at' => $this->get_current_time(),
		);

		$context['stats']['context_length'] = $this->get_context_length( $context );

		/**
		 * Filter compact ticket context.
		 *
		 * @param array<string, mixed> $context        Compact context.
		 * @param array<string, mixed> $ticket_context Original normalized context.
		 * @param array<string, mixed> $args           Context args.
		 */
		$context = apply_filters( 'scai_context_engine_ticket_context', $context, $ticket_context, $args );

		return is_array( $context ) ? $this->sanitize_context_output( $context, $args ) : $this->get_empty_context();
	}

	/**
	 * Build readable ticket context text for future composition.
	 *
	 * @param array<string, mixed> $ticket_context Normalized ticket context from adapter.
	 * @param array<string, mixed> $args           Context args.
	 * @return string
	 */
	public function build_ticket_context_text( $ticket_context, array $args = array() ) {
		$args    = $this->get_args( $args );
		$context = $this->build_ticket_context( $ticket_context, $args );

		if ( empty( $context['ticket']['id'] ) ) {
			return '';
		}

		$text = $this->build_summary_text( $context, $args );
		$text = $this->truncate_text( $text, $args['max_total_context_length'] );

		/**
		 * Filter readable ticket context text.
		 *
		 * @param string               $text    Readable context text.
		 * @param array<string, mixed> $context Compact context.
		 * @param array<string, mixed> $args    Context args.
		 */
		$text = apply_filters( 'scai_context_engine_context_text', $text, $context, $args );

		return $this->truncate_text( $this->normalize_multiline_text( (string) $text ), $args['max_total_context_length'] );
	}

	/**
	 * Build compact context from a SupportCandy ticket ID.
	 *
	 * @param int                  $ticket_id Ticket ID.
	 * @param array<string, mixed> $args      Context args.
	 * @return array<string, mixed>
	 */
	public function build_from_ticket_id( $ticket_id, array $args = array() ) {
		$ticket_id       = absint( $ticket_id );
		$normalized_args = $this->get_args( $args );

		if ( 0 === $ticket_id || ! class_exists( 'SCAI_SupportCandy_Adapter' ) ) {
			return $this->get_empty_context();
		}

		$adapter = new SCAI_SupportCandy_Adapter();

		if ( ! method_exists( $adapter, 'get_ticket_context' ) ) {
			return $this->get_empty_context();
		}

		$adapter_args = array_merge(
			$args,
			array(
				'thread_limit' => $normalized_args['max_threads'],
			)
		);

		return $this->build_ticket_context( $adapter->get_ticket_context( $ticket_id, $adapter_args ), $normalized_args );
	}

	/**
	 * Sanitize ticket data.
	 *
	 * @param array<string, mixed> $ticket Ticket data.
	 * @return array<string, mixed>
	 */
	private function sanitize_ticket( array $ticket ) {
		return array(
			'id'             => isset( $ticket['id'] ) ? absint( $ticket['id'] ) : 0,
			'subject'        => isset( $ticket['subject'] ) ? $this->normalize_text( $ticket['subject'] ) : '',
			'status'         => isset( $ticket['status'] ) ? $this->normalize_text( $ticket['status'] ) : '',
			'priority'       => isset( $ticket['priority'] ) ? $this->normalize_text( $ticket['priority'] ) : '',
			'category'       => isset( $ticket['category'] ) ? $this->normalize_text( $ticket['category'] ) : '',
			'customer_name'  => isset( $ticket['customer_name'] ) ? $this->normalize_text( $ticket['customer_name'] ) : '',
			'customer_email' => isset( $ticket['customer_email'] ) ? sanitize_email( $ticket['customer_email'] ) : '',
			'created_at'     => isset( $ticket['created_at'] ) ? sanitize_text_field( $ticket['created_at'] ) : '',
			'updated_at'     => isset( $ticket['updated_at'] ) ? sanitize_text_field( $ticket['updated_at'] ) : '',
		);
	}

	/**
	 * Sanitize and compact ticket threads.
	 *
	 * @param array<int, array<string, mixed>> $threads Thread data.
	 * @param array<string, mixed>             $args    Context args.
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitize_threads( array $threads, array $args ) {
		$max_threads            = max( 1, absint( $args['max_threads'] ) );
		$max_thread_body_length = max( 1, absint( $args['max_thread_body_length'] ) );
		$threads                = array_slice( $threads, 0, $max_threads );
		$sanitized              = array();

		foreach ( $threads as $thread ) {
			if ( ! is_array( $thread ) ) {
				continue;
			}

			$attachments = isset( $thread['attachments'] ) && is_array( $thread['attachments'] ) ? $thread['attachments'] : array();
			$speaker_role = $this->get_thread_speaker_role( $thread );

			$sanitized[] = array(
				'id'               => isset( $thread['id'] ) ? absint( $thread['id'] ) : 0,
				'type'             => isset( $thread['type'] ) ? $this->normalize_thread_type( $thread['type'] ) : 'thread',
				'thread_type'      => isset( $thread['thread_type'] ) ? sanitize_key( $thread['thread_type'] ) : ( isset( $thread['type'] ) ? sanitize_key( $thread['type'] ) : '' ),
				'author_name'      => isset( $thread['author_name'] ) ? $this->normalize_text( $thread['author_name'] ) : '',
				'author_email'     => isset( $thread['author_email'] ) ? sanitize_email( $thread['author_email'] ) : '',
				'speaker_role'     => $speaker_role,
				'speaker_label'    => $this->get_thread_speaker_label( $thread, $speaker_role ),
				'speaker_name'     => isset( $thread['speaker_name'] ) ? $this->normalize_text( $thread['speaker_name'] ) : '',
				'speaker_email'    => isset( $thread['speaker_email'] ) ? sanitize_email( $thread['speaker_email'] ) : '',
				'speaker_user_id'  => isset( $thread['speaker_user_id'] ) ? absint( $thread['speaker_user_id'] ) : 0,
				'speaker_customer_id' => isset( $thread['speaker_customer_id'] ) ? absint( $thread['speaker_customer_id'] ) : 0,
				'is_customer_message' => 'customer' === $speaker_role,
				'is_agent_message'    => 'agent' === $speaker_role,
				'is_internal_note'    => 'internal_note' === $speaker_role,
				'visibility'       => isset( $thread['visibility'] ) ? $this->sanitize_thread_visibility( $thread['visibility'] ) : ( 'internal_note' === $speaker_role ? 'internal' : ( 'system' === $speaker_role ? 'system' : 'unknown' ) ),
				'body'             => isset( $thread['body'] ) ? $this->truncate_text( $this->normalize_text( $thread['body'] ), $max_thread_body_length ) : '',
				'created_at'       => isset( $thread['created_at'] ) ? sanitize_text_field( $thread['created_at'] ) : '',
				'attachment_count' => count( $attachments ),
			);
		}

		return $sanitized;
	}

	/**
	 * Sanitize attachment summary data.
	 *
	 * @param array<int, array<string, mixed>> $attachments Attachment data.
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitize_attachments( array $attachments ) {
		$sanitized = array();

		foreach ( $attachments as $attachment ) {
			$normalized = $this->normalize_attachment( $attachment );

			if ( empty( $normalized ) ) {
				continue;
			}

			$sanitized[] = $normalized;
		}

		return $sanitized;
	}

	/**
	 * Enrich safe text-like attachments with bounded excerpts.
	 *
	 * @param array<int, mixed>    $attachments Attachments from the adapter.
	 * @param array<string, mixed> $args        Context arguments.
	 * @return array{attachments: array<int, mixed>, omitted_count: int}
	 */
	private function enrich_attachments_with_text_excerpts( array $attachments, array $args = array() ) {
		$reader          = $this->get_attachment_reader();
		$read_enabled    = ! empty( $args['read_text_attachments'] );
		$max_attachments = isset( $args['max_text_attachments'] ) ? max( 1, min( 3, absint( $args['max_text_attachments'] ) ) ) : 3;
		$read_count      = 0;
		$omitted_count   = 0;
		$enriched        = array();

		foreach ( $attachments as $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}

			if ( ! empty( $attachment['content_inspected'] ) && ! empty( $attachment['content_excerpt'] ) ) {
				$read_count++;
				$enriched[] = $attachment;
				continue;
			}

			if ( ! $read_enabled || ! $reader || ! $reader->can_read_attachment( $attachment ) ) {
				$enriched[] = $attachment;
				continue;
			}

			if ( $read_count >= $max_attachments ) {
				$omitted_count++;
				$enriched[] = $attachment;
				continue;
			}

			$attachment = $this->maybe_read_attachment_excerpt( $attachment, $args );

			if ( ! empty( $attachment['content_inspected'] ) ) {
				$read_count++;
			}

			$enriched[] = $attachment;
		}

		return array(
			'attachments'  => $enriched,
			'omitted_count' => $omitted_count,
		);
	}

	/**
	 * Read and attach a safe text excerpt when available.
	 *
	 * @param array<string, mixed> $attachment Attachment metadata.
	 * @param array<string, mixed> $args       Context arguments.
	 * @return array<string, mixed>
	 */
	private function maybe_read_attachment_excerpt( array $attachment, array $args = array() ) {
		$reader = $this->get_attachment_reader();

		if ( ! $reader || ! $reader->can_read_attachment( $attachment ) ) {
			return $attachment;
		}

		$reader_args = array();

		if ( ! empty( $args['max_attachment_excerpt_chars'] ) ) {
			$reader_args['max_excerpt_chars'] = absint( $args['max_attachment_excerpt_chars'] );
		}

		$result = $reader->read_attachment_excerpt( $attachment, $reader_args );

		if ( empty( $result['success'] ) || empty( $result['excerpt'] ) ) {
			return $attachment;
		}

		$attachment['content_excerpt']           = $this->normalize_multiline_text( $result['excerpt'] );
		$attachment['content_inspected']         = '' !== $attachment['content_excerpt'];
		$attachment['content_excerpt_available'] = $attachment['content_inspected'];
		$attachment['content_truncated']         = ! empty( $result['truncated'] );
		$attachment['lines_read']                = isset( $result['lines_read'] ) ? absint( $result['lines_read'] ) : 0;

		return $attachment;
	}

	/**
	 * Get the attachment reader when available.
	 *
	 * @return SCAI_Attachment_Reader|null
	 */
	private function get_attachment_reader() {
		if ( $this->attachment_reader instanceof SCAI_Attachment_Reader ) {
			return $this->attachment_reader;
		}

		if ( ! class_exists( 'SCAI_Attachment_Reader' ) ) {
			return null;
		}

		$this->attachment_reader = new SCAI_Attachment_Reader();

		return $this->attachment_reader;
	}

	/**
	 * Normalize safe attachment metadata without inspecting file content.
	 *
	 * @param mixed $attachment Attachment data.
	 * @return array<string, mixed>
	 */
	private function normalize_attachment( $attachment ) {
		if ( ! is_array( $attachment ) ) {
			return array();
		}

		$filename  = isset( $attachment['filename'] ) ? sanitize_file_name( $attachment['filename'] ) : '';
		$filename  = '' === $filename && isset( $attachment['file_name'] ) ? sanitize_file_name( $attachment['file_name'] ) : $filename;
		$title     = isset( $attachment['title'] ) ? sanitize_text_field( $attachment['title'] ) : '';
		$mime_type = isset( $attachment['mime_type'] ) ? sanitize_mime_type( $attachment['mime_type'] ) : '';
		$mime_type = '' === $mime_type && isset( $attachment['mime'] ) ? sanitize_mime_type( $attachment['mime'] ) : $mime_type;
		$extension = $this->get_attachment_extension( $filename );
		$type      = $this->get_attachment_type( $mime_type, $extension );
		$url       = $this->get_safe_attachment_url( $attachment );
		$is_image  = 0 === strpos( $mime_type, 'image/' ) || in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg' ), true );
		$is_pdf    = 'application/pdf' === $mime_type || 'pdf' === $extension;
		$is_text   = 0 === strpos( $mime_type, 'text/' ) || in_array( $extension, array( 'txt', 'log', 'csv', 'json', 'xml', 'html', 'htm', 'md', 'ini', 'conf', 'yml', 'yaml' ), true );
		$excerpt   = isset( $attachment['content_excerpt'] ) ? $this->normalize_multiline_text( $attachment['content_excerpt'] ) : '';
		$inspected = ! empty( $attachment['content_inspected'] ) && '' !== $excerpt;
		$file_size = isset( $attachment['file_size'] ) ? absint( $attachment['file_size'] ) : 0;
		$file_size = 0 === $file_size && isset( $attachment['size'] ) ? absint( $attachment['size'] ) : $file_size;

		return array(
			'id'                         => isset( $attachment['id'] ) ? absint( $attachment['id'] ) : 0,
			'ticket_id'                  => isset( $attachment['ticket_id'] ) ? absint( $attachment['ticket_id'] ) : 0,
			'thread_id'                  => isset( $attachment['thread_id'] ) ? absint( $attachment['thread_id'] ) : 0,
			'filename'                   => $filename,
			'title'                      => $title,
			'mime_type'                  => $mime_type,
			'extension'                  => $extension,
			'type'                       => $type,
			'url'                        => $url,
			'size'                       => $file_size,
			'is_image'                   => $is_image,
			'is_pdf'                     => $is_pdf,
			'is_text'                    => $is_text,
			'is_supported_for_future_ai' => $is_image || $is_pdf || $is_text,
			'content_inspected'          => $inspected,
			'content_excerpt_available'  => $inspected,
			'content_excerpt'            => $inspected ? $excerpt : '',
			'content_truncated'          => $inspected && ! empty( $attachment['content_truncated'] ),
			'lines_read'                 => $inspected && isset( $attachment['lines_read'] ) ? absint( $attachment['lines_read'] ) : 0,
			'created_at'                 => isset( $attachment['created_at'] ) ? sanitize_text_field( $attachment['created_at'] ) : '',
		);
	}

	/**
	 * Get a normalized file extension.
	 *
	 * @param string $filename Attachment filename.
	 * @return string
	 */
	private function get_attachment_extension( $filename ) {
		$extension = pathinfo( sanitize_file_name( $filename ), PATHINFO_EXTENSION );

		return sanitize_key( strtolower( (string) $extension ) );
	}

	/**
	 * Classify an attachment from its MIME type and extension.
	 *
	 * @param string $mime_type Attachment MIME type.
	 * @param string $extension Attachment extension.
	 * @return string
	 */
	private function get_attachment_type( $mime_type, $extension ) {
		$mime_type = sanitize_mime_type( $mime_type );
		$extension = sanitize_key( $extension );

		if ( 0 === strpos( $mime_type, 'image/' ) || in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg' ), true ) ) {
			return 'image';
		}

		if ( 'application/pdf' === $mime_type || 'pdf' === $extension ) {
			return 'pdf';
		}

		if ( in_array( $extension, array( 'csv', 'xls', 'xlsx', 'ods' ), true ) ) {
			return 'spreadsheet';
		}

		if ( 0 === strpos( $mime_type, 'text/' ) || in_array( $extension, array( 'txt', 'log', 'json', 'xml' ), true ) ) {
			return 'text';
		}

		if ( in_array( $extension, array( 'doc', 'docx', 'odt', 'rtf' ), true ) ) {
			return 'document';
		}

		if ( 0 === strpos( $mime_type, 'audio/' ) ) {
			return 'audio';
		}

		if ( 0 === strpos( $mime_type, 'video/' ) ) {
			return 'video';
		}

		if ( in_array( $extension, array( 'zip', 'rar', '7z', 'tar', 'gz' ), true ) ) {
			return 'archive';
		}

		return 'other';
	}

	/**
	 * Get a safe public attachment URL, excluding local filesystem paths.
	 *
	 * @param array<string, mixed> $attachment Attachment data.
	 * @return string
	 */
	private function get_safe_attachment_url( array $attachment ) {
		foreach ( array( 'url', 'file_url', 'download_url' ) as $key ) {
			if ( empty( $attachment[ $key ] ) || ! is_scalar( $attachment[ $key ] ) ) {
				continue;
			}

			$url = esc_url_raw( (string) $attachment[ $key ], array( 'http', 'https' ) );

			if ( '' !== $url && wp_http_validate_url( $url ) ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Truncate text to a maximum character length.
	 *
	 * @param mixed $text       Text to truncate.
	 * @param int   $max_length Maximum length.
	 * @return string
	 */
	private function truncate_text( $text, $max_length ) {
		$text       = (string) $text;
		$max_length = absint( $max_length );

		if ( 0 === $max_length ) {
			return '';
		}

		if ( $this->strlen( $text ) <= $max_length ) {
			return $text;
		}

		if ( $max_length <= 3 ) {
			return $this->substr( $text, 0, $max_length );
		}

		return rtrim( $this->substr( $text, 0, $max_length - 3 ) ) . '...';
	}

	/**
	 * Normalize a thread type into a readable role.
	 *
	 * @param mixed $type Thread type.
	 * @return string
	 */
	private function normalize_thread_type( $type ) {
		$type = sanitize_key( $type );

		if ( in_array( $type, array( 'reply', 'customer_reply' ), true ) ) {
			return 'customer';
		}

		if ( in_array( $type, array( 'report', 'agent_reply', 'note' ), true ) ) {
			return 'agent';
		}

		if ( in_array( $type, array( 'log', 'private_note' ), true ) ) {
			return 'note';
		}

		return '' !== $type ? $type : 'thread';
	}

	/**
	 * Get a safe speaker role from normalized or legacy thread data.
	 *
	 * @param array<string, mixed> $thread Thread data.
	 * @return string
	 */
	private function get_thread_speaker_role( array $thread ) {
		$role    = isset( $thread['speaker_role'] ) ? sanitize_key( $thread['speaker_role'] ) : '';
		$allowed = array( 'customer', 'agent', 'internal_note', 'system', 'unknown' );

		if ( in_array( $role, $allowed, true ) ) {
			return $role;
		}

		if ( ! empty( $thread['is_internal_note'] ) ) {
			return 'internal_note';
		}

		if ( ! empty( $thread['is_customer_message'] ) ) {
			return 'customer';
		}

		if ( ! empty( $thread['is_agent_message'] ) ) {
			return 'agent';
		}

		$thread_type = isset( $thread['thread_type'] ) ? sanitize_key( $thread['thread_type'] ) : ( isset( $thread['type'] ) ? sanitize_key( $thread['type'] ) : '' );

		if ( in_array( $thread_type, array( 'note', 'internal_note', 'private_note', 'internal', 'private' ), true ) ) {
			return 'internal_note';
		}

		if ( in_array( $thread_type, array( 'log', 'system', 'activity', 'change', 'status' ), true ) ) {
			return 'system';
		}

		return 'unknown';
	}

	/**
	 * Get a readable, sanitized thread speaker label.
	 *
	 * @param array<string, mixed> $thread       Thread data.
	 * @param string               $speaker_role Normalized speaker role.
	 * @return string
	 */
	private function get_thread_speaker_label( array $thread, $speaker_role = '' ) {
		$label = isset( $thread['speaker_label'] ) ? $this->normalize_text( $thread['speaker_label'] ) : '';

		if ( '' !== $label ) {
			return $label;
		}

		return $this->get_thread_role_label( '' !== $speaker_role ? $speaker_role : $this->get_thread_speaker_role( $thread ) );
	}

	/**
	 * Get the fallback label for a speaker role.
	 *
	 * @param string $speaker_role Speaker role.
	 * @return string
	 */
	private function get_thread_role_label( $speaker_role ) {
		$labels = array(
			'customer'      => 'Customer',
			'agent'         => 'Support Agent',
			'internal_note' => 'Internal Note',
			'system'        => 'System',
			'unknown'       => 'Unknown Sender',
		);
		$speaker_role = sanitize_key( $speaker_role );

		return isset( $labels[ $speaker_role ] ) ? $labels[ $speaker_role ] : $labels['unknown'];
	}

	/**
	 * Sanitize thread visibility.
	 *
	 * @param mixed $visibility Visibility value.
	 * @return string
	 */
	private function sanitize_thread_visibility( $visibility ) {
		$visibility = sanitize_key( $visibility );

		return in_array( $visibility, array( 'public', 'internal', 'system', 'unknown' ), true ) ? $visibility : 'unknown';
	}

	/**
	 * Build a numbered conversation heading.
	 *
	 * @param array<string, mixed> $thread Thread data.
	 * @param int                  $index  Zero-based thread index.
	 * @return string
	 */
	private function build_thread_heading( array $thread, $index ) {
		$label      = $this->get_thread_speaker_label( $thread );
		$created_at = isset( $thread['created_at'] ) ? sanitize_text_field( $thread['created_at'] ) : '';
		$heading    = ( absint( $index ) + 1 ) . '. [' . $label . ']';

		if ( '' !== $created_at ) {
			$heading .= ' — ' . $created_at;
		}

		return $heading;
	}

	/**
	 * Determine whether a normalized thread is an internal note.
	 *
	 * @param array<string, mixed> $thread Thread data.
	 * @return bool
	 */
	private function is_internal_note_thread( array $thread ) {
		return 'internal_note' === $this->get_thread_speaker_role( $thread ) || ! empty( $thread['is_internal_note'] );
	}

	/**
	 * Build readable summary text from compact context.
	 *
	 * @param array<string, mixed> $context Compact context.
	 * @param array<string, mixed> $args    Context arguments.
	 * @return string
	 */
	private function build_summary_text( array $context, array $args ) {
		$ticket      = isset( $context['ticket'] ) && is_array( $context['ticket'] ) ? $context['ticket'] : array();
		$threads     = isset( $context['threads'] ) && is_array( $context['threads'] ) ? $context['threads'] : array();
		$attachments = isset( $context['attachments'] ) && is_array( $context['attachments'] ) ? $context['attachments'] : array();
		$lines       = array();

		$lines[] = 'Ticket:';
		$lines[] = 'ID: ' . ( isset( $ticket['id'] ) ? absint( $ticket['id'] ) : 0 );
		$lines[] = 'Subject: ' . ( isset( $ticket['subject'] ) ? $this->normalize_text( $ticket['subject'] ) : '' );
		$lines[] = 'Status: ' . ( isset( $ticket['status'] ) ? $this->normalize_text( $ticket['status'] ) : '' );
		$lines[] = 'Priority: ' . ( isset( $ticket['priority'] ) ? $this->normalize_text( $ticket['priority'] ) : '' );
		$lines[] = 'Category: ' . ( isset( $ticket['category'] ) ? $this->normalize_text( $ticket['category'] ) : '' );
		$lines[] = 'Customer: ' . ( isset( $ticket['customer_name'] ) ? $this->normalize_text( $ticket['customer_name'] ) : '' );
		$lines[] = 'Customer Email: ' . ( isset( $ticket['customer_email'] ) ? sanitize_email( $ticket['customer_email'] ) : '' );
		$lines[] = 'Created: ' . ( isset( $ticket['created_at'] ) ? sanitize_text_field( $ticket['created_at'] ) : '' );
		$lines[] = 'Updated: ' . ( isset( $ticket['updated_at'] ) ? sanitize_text_field( $ticket['updated_at'] ) : '' );

		if ( ! empty( $args['include_attachment_section'] ) ) {
			$omitted_count      = isset( $context['stats']['attachment_omitted_count'] ) ? absint( $context['stats']['attachment_omitted_count'] ) : 0;
			$text_omitted_count = isset( $context['stats']['text_attachment_omitted_count'] ) ? absint( $context['stats']['text_attachment_omitted_count'] ) : 0;
			$lines[]            = '';
			$lines[]            = $this->build_attachments_context_text( $attachments, $omitted_count, $text_omitted_count, $args['max_attachments'] );
		}

		$lines[] = '';
		$lines[] = 'Conversation:';

		foreach ( $threads as $index => $thread ) {
			if ( ! is_array( $thread ) ) {
				continue;
			}

			$body = isset( $thread['body'] ) ? $this->normalize_text( $thread['body'] ) : '';

			$lines[] = '';
			$lines[] = $this->build_thread_heading( $thread, $index );

			if ( $this->is_internal_note_thread( $thread ) ) {
				$lines[] = 'Internal support context. Do not quote this note directly to the customer.';
			}

			$lines[] = $body;
		}

		$knowledge_text = $this->build_knowledge_context_text(
			isset( $context['knowledge_base'] ) && is_array( $context['knowledge_base'] ) ? $context['knowledge_base'] : array()
		);

		if ( '' !== $knowledge_text ) {
			$lines[] = '';
			$lines[] = $knowledge_text;
		}

		$custom_knowledge_text = $this->build_custom_knowledge_context_text(
			isset( $context['custom_knowledge_base'] ) && is_array( $context['custom_knowledge_base'] ) ? $context['custom_knowledge_base'] : array()
		);

		if ( '' !== $custom_knowledge_text ) {
			$lines[] = '';
			$lines[] = $custom_knowledge_text;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Build a separate readable BetterDocs knowledge section.
	 *
	 * @param array<string, mixed> $knowledge Knowledge context.
	 * @return string
	 */
	private function build_knowledge_context_text( array $knowledge ) {
		$documents = isset( $knowledge['documents'] ) && is_array( $knowledge['documents'] ) ? $knowledge['documents'] : array();

		if ( empty( $documents ) ) {
			return '';
		}

		$lines = array( 'Knowledge Base Articles:' );

		foreach ( array_slice( $documents, 0, self::MAX_KNOWLEDGE_DOCUMENTS ) as $index => $document ) {
			if ( ! is_array( $document ) ) {
				continue;
			}

			$lines[] = '';
			$lines[] = ( $index + 1 ) . '. ' . ( isset( $document['title'] ) ? $this->normalize_text( $document['title'] ) : '' );
			$lines[] = 'Source: BetterDocs';
			$lines[] = 'URL: ' . ( isset( $document['url'] ) ? esc_url_raw( $document['url'] ) : '' );
			$lines[] = 'Categories: ' . $this->format_knowledge_list( isset( $document['categories'] ) ? $document['categories'] : array() );
			$lines[] = 'Relevance score: ' . ( isset( $document['score'] ) && is_numeric( $document['score'] ) ? (string) (float) $document['score'] : '0' );
			$lines[] = 'Matched terms: ' . $this->format_knowledge_list( isset( $document['matched_terms'] ) ? $document['matched_terms'] : array() );
			$lines[] = 'Content:';
			$lines[] = isset( $document['content'] ) ? $this->normalize_multiline_text( $document['content'] ) : '';
		}

		return trim( implode( "\n", $lines ) );
	}

	/**
	 * Sanitize and bound BetterDocs knowledge context.
	 *
	 * @param array<string, mixed> $knowledge Raw knowledge result.
	 * @return array<string, mixed>
	 */
	private function sanitize_knowledge_base( array $knowledge ) {
		$documents = isset( $knowledge['documents'] ) && is_array( $knowledge['documents'] ) ? array_slice( $knowledge['documents'], 0, self::MAX_KNOWLEDGE_DOCUMENTS ) : array();
		$sanitized = array();
		$remaining = self::MAX_KNOWLEDGE_CONTENT_LENGTH;

		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) || $remaining <= 0 ) {
				continue;
			}

			$content   = isset( $document['content'] ) ? $this->normalize_multiline_text( $document['content'] ) : '';
			$content   = $this->truncate_text( $content, min( 6000, $remaining ) );
			$remaining = max( 0, $remaining - $this->strlen( $content ) );

			$sanitized[] = array(
				'id'            => isset( $document['id'] ) ? absint( $document['id'] ) : 0,
				'title'         => isset( $document['title'] ) ? $this->truncate_text( $this->normalize_text( $document['title'] ), 500 ) : '',
				'url'           => isset( $document['url'] ) ? esc_url_raw( $document['url'] ) : '',
				'categories'    => $this->sanitize_knowledge_list( isset( $document['categories'] ) ? $document['categories'] : array() ),
				'score'         => isset( $document['score'] ) && is_numeric( $document['score'] ) ? (float) $document['score'] : 0.0,
				'matched_terms' => $this->sanitize_knowledge_list( isset( $document['matched_terms'] ) ? $document['matched_terms'] : array() ),
				'content'       => $content,
			);
		}

		return array(
			'source'    => 'betterdocs',
			'enabled'   => ! empty( $knowledge['enabled'] ),
			'available' => ! empty( $knowledge['available'] ),
			'query'     => isset( $knowledge['query'] ) ? $this->truncate_text( $this->normalize_text( $knowledge['query'] ), 200 ) : '',
			'documents' => $sanitized,
			'count'     => count( $sanitized ),
		);
	}

	/**
	 * Build a separate readable custom knowledge section.
	 *
	 * @param array<string, mixed> $knowledge Custom knowledge context.
	 * @return string
	 */
	private function build_custom_knowledge_context_text( array $knowledge ) {
		$documents = isset( $knowledge['documents'] ) && is_array( $knowledge['documents'] ) ? $knowledge['documents'] : array();
		if ( empty( $documents ) ) {
			return '';
		}

		$lines = array( 'Custom Knowledge Base Articles:' );
		foreach ( array_slice( $documents, 0, self::MAX_CUSTOM_KNOWLEDGE_DOCUMENTS ) as $index => $document ) {
			if ( ! is_array( $document ) || empty( $document['content'] ) ) {
				continue;
			}
			$lines[] = '';
			$lines[] = ( $index + 1 ) . '. ' . ( isset( $document['title'] ) ? $this->normalize_text( $document['title'] ) : '' );
			$lines[] = 'Source: Custom Knowledge Base';
			$source_labels = array( 'manual' => 'Manual', 'url' => 'URL', 'file' => 'File' );
			$source_type   = isset( $document['source_type'] ) ? sanitize_key( $document['source_type'] ) : '';
			$lines[] = 'Type: ' . ( isset( $source_labels[ $source_type ] ) ? $source_labels[ $source_type ] : 'Custom' );
			if ( 'url' === $source_type && ! empty( $document['url'] ) ) {
				$lines[] = 'URL: ' . esc_url_raw( $document['url'], array( 'http', 'https' ) );
			}
			$lines[] = 'Tags: ' . $this->format_knowledge_list( isset( $document['tags'] ) ? $document['tags'] : array() );
			$lines[] = 'Relevance score: ' . ( isset( $document['score'] ) && is_numeric( $document['score'] ) ? (string) (float) $document['score'] : '0' );
			$lines[] = 'Matched terms: ' . $this->format_knowledge_list( isset( $document['matched_terms'] ) ? $document['matched_terms'] : array() );
			$lines[] = 'Content:';
			$lines[] = $this->normalize_multiline_text( $document['content'] );
		}

		return count( $lines ) > 1 ? trim( implode( "\n", $lines ) ) : '';
	}

	/**
	 * Sanitize and bound custom knowledge without retaining raw metadata.
	 *
	 * @param array<string, mixed> $knowledge Raw custom knowledge result.
	 * @return array<string, mixed>
	 */
	private function sanitize_custom_knowledge_base( array $knowledge ) {
		$documents = isset( $knowledge['documents'] ) && is_array( $knowledge['documents'] ) ? array_slice( $knowledge['documents'], 0, self::MAX_CUSTOM_KNOWLEDGE_DOCUMENTS ) : array();
		$sanitized = array();
		$remaining = self::MAX_CUSTOM_KNOWLEDGE_CONTENT_LENGTH;

		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) || $remaining <= 0 ) {
				continue;
			}
			$content = isset( $document['content'] ) ? $this->normalize_multiline_text( $document['content'] ) : '';
			$content = $this->truncate_text( $content, min( self::MAX_CUSTOM_KNOWLEDGE_DOCUMENT_LENGTH, $remaining ) );
			if ( '' === $content ) {
				continue;
			}
			$remaining -= $this->strlen( $content );
			$metadata = isset( $document['metadata'] ) && is_array( $document['metadata'] ) ? $document['metadata'] : array();
			$tags = isset( $document['tags'] ) ? $document['tags'] : ( isset( $metadata['tags'] ) ? $metadata['tags'] : array() );
			$sanitized[] = array(
				'id'            => isset( $document['id'] ) ? absint( $document['id'] ) : 0,
				'source_type'   => isset( $document['source_type'] ) && in_array( sanitize_key( $document['source_type'] ), array( 'manual', 'url', 'file' ), true ) ? sanitize_key( $document['source_type'] ) : '',
				'title'         => isset( $document['title'] ) ? $this->truncate_text( $this->normalize_text( $document['title'] ), 500 ) : '',
				'url'           => isset( $document['source_url'] ) ? esc_url_raw( $document['source_url'], array( 'http', 'https' ) ) : '',
				'tags'          => $this->sanitize_knowledge_list( $tags ),
				'score'         => isset( $document['score'] ) && is_numeric( $document['score'] ) ? (float) $document['score'] : 0.0,
				'matched_terms' => $this->sanitize_knowledge_list( isset( $document['matched_terms'] ) ? $document['matched_terms'] : array() ),
				'content'       => $content,
			);
		}

		return array(
			'source'    => 'custom_knowledge',
			'enabled'   => ! empty( $knowledge['enabled'] ),
			'available' => ! empty( $knowledge['available'] ),
			'query'     => isset( $knowledge['query'] ) ? $this->truncate_text( $this->normalize_text( $knowledge['query'] ), 200 ) : '',
			'documents' => $sanitized,
			'count'     => count( $sanitized ),
		);
	}

	/**
	 * Sanitize a bounded list of knowledge labels.
	 *
	 * @param mixed $items Candidate list.
	 * @return array<int, string>
	 */
	private function sanitize_knowledge_list( $items ) {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( array_slice( $items, 0, 20 ) as $item ) {
			if ( is_scalar( $item ) ) {
				$sanitized[] = $this->truncate_text( $this->normalize_text( $item ), 100 );
			}
		}

		return array_values( array_unique( array_filter( $sanitized ) ) );
	}

	/**
	 * Format sanitized knowledge labels for readable context.
	 *
	 * @param mixed $items Candidate list.
	 * @return string
	 */
	private function format_knowledge_list( $items ) {
		return implode( ', ', $this->sanitize_knowledge_list( $items ) );
	}

	/**
	 * Build readable attachment metadata for AI context.
	 *
	 * @param array<int, array<string, mixed>> $attachments       Normalized attachments.
	 * @param int                              $omitted_count      Number omitted from context.
	 * @param int                              $text_omitted_count Readable text attachments omitted.
	 * @param int                              $max_attachments    Maximum attachments to render.
	 * @return string
	 */
	private function build_attachments_context_text( array $attachments, $omitted_count = 0, $text_omitted_count = 0, $max_attachments = self::MAX_CONTEXT_ATTACHMENTS ) {
		$lines = array( 'Ticket Attachments:' );

		if ( empty( $attachments ) ) {
			$lines[] = 'None';

			return implode( "\n", $lines );
		}

		$max_attachments = max( 1, min( self::MAX_CONTEXT_ATTACHMENTS, absint( $max_attachments ) ) );

		foreach ( array_slice( $attachments, 0, $max_attachments ) as $index => $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}

			$filename  = isset( $attachment['filename'] ) ? sanitize_file_name( $attachment['filename'] ) : '';
			$title     = isset( $attachment['title'] ) ? sanitize_text_field( $attachment['title'] ) : '';
			$mime_type = isset( $attachment['mime_type'] ) ? sanitize_mime_type( $attachment['mime_type'] ) : '';
			$type      = isset( $attachment['type'] ) ? sanitize_key( $attachment['type'] ) : 'other';
			$url       = isset( $attachment['url'] ) ? esc_url_raw( $attachment['url'], array( 'http', 'https' ) ) : '';
			$file_size = isset( $attachment['size'] ) ? absint( $attachment['size'] ) : 0;
			$thread_id = isset( $attachment['thread_id'] ) ? absint( $attachment['thread_id'] ) : 0;
			$label     = '' !== $filename ? $filename : ( '' !== $title ? $title : 'Unnamed attachment' );

			$lines[] = ( absint( $index ) + 1 ) . '. ' . $label;
			$lines[] = '   - Type: ' . $type;
			$lines[] = '   - MIME: ' . ( '' !== $mime_type ? $mime_type : 'unknown' );

			if ( 0 < $file_size ) {
				$lines[] = '   - File size: ' . $file_size . ' bytes';
			}

			if ( 0 < $thread_id ) {
				$lines[] = '   - Attached to thread ID: ' . $thread_id;
			}

			if ( '' !== $url && wp_http_validate_url( $url ) ) {
				$lines[] = '   - URL: ' . $url;
			}

			if ( ! empty( $attachment['content_inspected'] ) && ! empty( $attachment['content_excerpt'] ) ) {
				$lines[] = '   - Content inspected: yes';
				$lines[] = '   - Excerpt:';
				$lines[] = $this->build_attachment_excerpt_context_text( $attachment );
				$lines[] = '   - Truncated: ' . ( ! empty( $attachment['content_truncated'] ) ? 'yes' : 'no' );
			} else {
				$lines[] = '   - Content inspected: no';
				$lines[] = '   - Note: ' . $this->get_uninspected_attachment_note( $type );
			}

			$lines[] = '';
		}

		$omitted_count = absint( $omitted_count );

		if ( 0 < $omitted_count ) {
			$lines[] = 'Additional attachments omitted from AI context: ' . $omitted_count;
		}

		$text_omitted_count = absint( $text_omitted_count );

		if ( 0 < $text_omitted_count ) {
			$lines[] = 'Additional readable text attachments omitted from content extraction: ' . $text_omitted_count;
		}

		return rtrim( implode( "\n", $lines ) );
	}

	/**
	 * Format a safe attachment excerpt for readable context text.
	 *
	 * @param array<string, mixed> $attachment Normalized attachment.
	 * @return string
	 */
	private function build_attachment_excerpt_context_text( array $attachment ) {
		$excerpt = isset( $attachment['content_excerpt'] ) ? $this->normalize_multiline_text( $attachment['content_excerpt'] ) : '';
		$lines   = explode( "\n", $excerpt );

		foreach ( $lines as $index => $line ) {
			$lines[ $index ] = '     ' . $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Get an honest note for an attachment whose content was not inspected.
	 *
	 * @param string $type Attachment type.
	 * @return string
	 */
	private function get_uninspected_attachment_note( $type ) {
		$type = sanitize_key( $type );

		if ( 'image' === $type ) {
			return 'Image content has not been inspected by AI yet.';
		}

		if ( in_array( $type, array( 'pdf', 'document' ), true ) ) {
			return 'Document content has not been inspected by AI yet.';
		}

		return 'Attachment content has not been inspected by AI yet.';
	}

	/**
	 * Normalize context args.
	 *
	 * @param array<string, mixed> $args Context args.
	 * @return array<string, int|bool>
	 */
	private function get_args( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'max_threads'              => self::DEFAULT_MAX_THREADS,
				'max_thread_body_length'   => self::DEFAULT_MAX_THREAD_BODY_LENGTH,
				'max_total_context_length' => self::DEFAULT_MAX_TOTAL_CONTEXT_LENGTH,
				'read_text_attachments'    => true,
				'max_text_attachments'     => 3,
				'max_attachment_excerpt_chars' => 0,
				'max_attachments'          => self::MAX_CONTEXT_ATTACHMENTS,
				'include_attachment_section' => true,
			)
		);

		return array(
			'max_threads'              => max( 1, absint( $args['max_threads'] ) ),
			'max_thread_body_length'   => max( 1, absint( $args['max_thread_body_length'] ) ),
			'max_total_context_length' => max( 1, absint( $args['max_total_context_length'] ) ),
			'read_text_attachments'    => (bool) $args['read_text_attachments'],
			'max_text_attachments'     => max( 1, min( 3, absint( $args['max_text_attachments'] ) ) ),
			'max_attachment_excerpt_chars' => absint( $args['max_attachment_excerpt_chars'] ),
			'max_attachments'          => max( 1, min( self::MAX_CONTEXT_ATTACHMENTS, absint( $args['max_attachments'] ) ) ),
			'include_attachment_section' => (bool) $args['include_attachment_section'],
		);
	}

	/**
	 * Limit thread bodies to the total context budget.
	 *
	 * @param array<string, mixed>             $ticket      Ticket data.
	 * @param array<int, array<string, mixed>> $threads     Thread data.
	 * @param array<int, array<string, mixed>> $attachments Attachment data.
	 * @param array<string, int|bool>          $args        Context args.
	 * @return array<int, array<string, mixed>>
	 */
	private function limit_context_size( array $ticket, array $threads, array $attachments, array $args ) {
		$base_context = array(
			'ticket'      => $ticket,
			'threads'     => array(),
			'attachments' => $attachments,
		);
		$used_length  = $this->get_context_length( $base_context );
		$max_length   = absint( $args['max_total_context_length'] );
		$limited      = array();

		foreach ( $threads as $thread ) {
			$body             = isset( $thread['body'] ) ? (string) $thread['body'] : '';
			$thread_bodyless  = $thread;
			$thread_bodyless['body'] = '';
			$bodyless_context = array(
				'ticket'      => $ticket,
				'threads'     => array_merge( $limited, array( $thread_bodyless ) ),
				'attachments' => $attachments,
			);
			$bodyless_length  = $this->get_context_length( $bodyless_context );
			$available        = max( 0, $max_length - $bodyless_length );

			if ( 0 === $available ) {
				break;
			}

			$thread['body'] = $this->truncate_text( $body, $available );
			$limited[]      = $thread;
			$used_length    = $this->get_context_length(
				array(
					'ticket'      => $ticket,
					'threads'     => $limited,
					'attachments' => $attachments,
				)
			);

			if ( $used_length >= $max_length ) {
				break;
			}
		}

		return $limited;
	}

	/**
	 * Normalize text for AI context.
	 *
	 * @param mixed $text Text value.
	 * @return string
	 */
	private function normalize_text( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return sanitize_textarea_field( is_string( $text ) ? trim( $text ) : '' );
	}

	/**
	 * Normalize multiline text while preserving line structure.
	 *
	 * @param mixed $text Text value.
	 * @return string
	 */
	private function normalize_multiline_text( $text ) {
		$text  = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
		$text  = wp_strip_all_tags( $text );
		$lines = preg_split( '/\R/u', $text );

		if ( ! is_array( $lines ) ) {
			return '';
		}

		foreach ( $lines as $index => $line ) {
			$line            = preg_replace( '/[ \t]+/u', ' ', $line );
			$lines[ $index ] = sanitize_textarea_field( is_string( $line ) ? trim( $line ) : '' );
		}

		$text = implode( "\n", $lines );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );

		return is_string( $text ) ? trim( $text ) : '';
	}

	/**
	 * Get approximate context length.
	 *
	 * @param array<string, mixed> $context Context data.
	 * @return int
	 */
	private function get_context_length( array $context ) {
		$json = wp_json_encode( $context );

		return is_string( $json ) ? $this->strlen( $json ) : 0;
	}

	/**
	 * Sanitize context output after filters.
	 *
	 * @param array<string, mixed> $context Context data.
	 * @return array<string, mixed>
	 */
	private function sanitize_context_output( array $context, array $args ) {
		$attachments      = isset( $context['attachments'] ) && is_array( $context['attachments'] ) ? $this->sanitize_attachments( $context['attachments'] ) : array();
		$attachments      = array_slice( $attachments, 0, $args['max_attachments'] );
		$reported_count   = isset( $context['stats']['attachment_count'] ) ? absint( $context['stats']['attachment_count'] ) : count( $attachments );
		$attachment_count = max( count( $attachments ), $reported_count );
		$text_omitted_count = isset( $context['stats']['text_attachment_omitted_count'] ) ? absint( $context['stats']['text_attachment_omitted_count'] ) : 0;
		$output = array(
			'ticket'       => isset( $context['ticket'] ) && is_array( $context['ticket'] ) ? $this->sanitize_ticket( $context['ticket'] ) : array(),
			'threads'      => isset( $context['threads'] ) && is_array( $context['threads'] )
				? $this->sanitize_threads( $context['threads'], $args )
				: array(),
			'attachments'  => $attachments,
			'knowledge_base' => isset( $context['knowledge_base'] ) && is_array( $context['knowledge_base'] )
				? $this->sanitize_knowledge_base( $context['knowledge_base'] )
				: array(),
			'custom_knowledge_base' => isset( $context['custom_knowledge_base'] ) && is_array( $context['custom_knowledge_base'] )
				? $this->sanitize_custom_knowledge_base( $context['custom_knowledge_base'] )
				: array(),
			'stats'        => array(
				'thread_count'               => 0,
				'attachment_count'           => $attachment_count,
				'attachment_omitted_count'   => max( 0, $attachment_count - count( $attachments ) ),
				'text_attachment_omitted_count' => $text_omitted_count,
				'context_length'             => 0,
			),
			'generated_at' => isset( $context['generated_at'] ) ? sanitize_text_field( $context['generated_at'] ) : $this->get_current_time(),
		);

		$output['stats']['thread_count']     = count( $output['threads'] );
		$output['stats']['context_length']   = $this->get_context_length( $output );

		return $output;
	}

	/**
	 * Get a safe empty context.
	 *
	 * @return array<string, mixed>
	 */
	private function get_empty_context() {
		return array(
			'ticket'       => array(),
			'threads'      => array(),
			'attachments'  => array(),
			'knowledge_base' => array(),
			'custom_knowledge_base' => array(),
			'stats'        => array(
				'thread_count'               => 0,
				'attachment_count'           => 0,
				'attachment_omitted_count'   => 0,
				'text_attachment_omitted_count' => 0,
				'context_length'             => 0,
			),
			'generated_at' => $this->get_current_time(),
		);
	}

	/**
	 * Get current WordPress time.
	 *
	 * @return string
	 */
	private function get_current_time() {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Get string length.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private function strlen( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}

	/**
	 * Get substring.
	 *
	 * @param string $text   Text.
	 * @param int    $start  Start offset.
	 * @param int    $length Length.
	 * @return string
	 */
	private function substr( $text, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, $start, $length ) : substr( $text, $start, $length );
	}
}
