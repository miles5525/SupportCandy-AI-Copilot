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
		$attachments = isset( $ticket_context['attachments'] ) && is_array( $ticket_context['attachments'] )
			? $this->sanitize_attachments( $ticket_context['attachments'] )
			: array();

		if ( empty( $ticket['id'] ) ) {
			return $this->get_empty_context();
		}

		$context = array(
			'ticket'       => $ticket,
			'threads'      => $this->limit_context_size( $ticket, $threads, $attachments, $args ),
			'attachments'  => $attachments,
			'stats'        => array(
				'thread_count'     => count( $threads ),
				'attachment_count' => count( $attachments ),
				'context_length'   => 0,
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

		$text = $this->build_summary_text( $context );
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

			$sanitized[] = array(
				'id'               => isset( $thread['id'] ) ? absint( $thread['id'] ) : 0,
				'type'             => isset( $thread['type'] ) ? $this->normalize_thread_type( $thread['type'] ) : 'thread',
				'author_name'      => isset( $thread['author_name'] ) ? $this->normalize_text( $thread['author_name'] ) : '',
				'author_email'     => isset( $thread['author_email'] ) ? sanitize_email( $thread['author_email'] ) : '',
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
			if ( ! is_array( $attachment ) ) {
				continue;
			}

			$sanitized[] = array(
				'id'         => isset( $attachment['id'] ) ? absint( $attachment['id'] ) : 0,
				'ticket_id'  => isset( $attachment['ticket_id'] ) ? absint( $attachment['ticket_id'] ) : 0,
				'thread_id'  => isset( $attachment['thread_id'] ) ? absint( $attachment['thread_id'] ) : 0,
				'filename'   => isset( $attachment['filename'] ) ? sanitize_file_name( $attachment['filename'] ) : '',
				'mime_type'  => isset( $attachment['mime_type'] ) ? sanitize_mime_type( $attachment['mime_type'] ) : '',
				'size'       => isset( $attachment['size'] ) ? absint( $attachment['size'] ) : 0,
				'created_at' => isset( $attachment['created_at'] ) ? sanitize_text_field( $attachment['created_at'] ) : '',
			);
		}

		return $sanitized;
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
	 * Build readable summary text from compact context.
	 *
	 * @param array<string, mixed> $context Compact context.
	 * @return string
	 */
	private function build_summary_text( array $context ) {
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
		$lines[] = '';
		$lines[] = 'Conversation:';

		foreach ( $threads as $thread ) {
			if ( ! is_array( $thread ) ) {
				continue;
			}

			$type       = isset( $thread['type'] ) ? ucfirst( $this->normalize_text( $thread['type'] ) ) : 'Thread';
			$created_at = isset( $thread['created_at'] ) ? sanitize_text_field( $thread['created_at'] ) : '';
			$body       = isset( $thread['body'] ) ? $this->normalize_text( $thread['body'] ) : '';

			$lines[] = '';
			$lines[] = '[' . $type . ' | ' . $created_at . ']';
			$lines[] = $body;
		}

		$lines[] = '';
		$lines[] = 'Attachments:';

		if ( empty( $attachments ) ) {
			$lines[] = '- None';
		} else {
			foreach ( $attachments as $attachment ) {
				if ( ! is_array( $attachment ) ) {
					continue;
				}

				$filename  = isset( $attachment['filename'] ) ? sanitize_file_name( $attachment['filename'] ) : '';
				$mime_type = isset( $attachment['mime_type'] ) ? sanitize_mime_type( $attachment['mime_type'] ) : '';
				$lines[]   = '- ' . trim( $filename . ' ' . $mime_type );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Normalize context args.
	 *
	 * @param array<string, mixed> $args Context args.
	 * @return array<string, int>
	 */
	private function get_args( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'max_threads'              => self::DEFAULT_MAX_THREADS,
				'max_thread_body_length'   => self::DEFAULT_MAX_THREAD_BODY_LENGTH,
				'max_total_context_length' => self::DEFAULT_MAX_TOTAL_CONTEXT_LENGTH,
			)
		);

		return array(
			'max_threads'              => max( 1, absint( $args['max_threads'] ) ),
			'max_thread_body_length'   => max( 1, absint( $args['max_thread_body_length'] ) ),
			'max_total_context_length' => max( 1, absint( $args['max_total_context_length'] ) ),
		);
	}

	/**
	 * Limit thread bodies to the total context budget.
	 *
	 * @param array<string, mixed>             $ticket      Ticket data.
	 * @param array<int, array<string, mixed>> $threads     Thread data.
	 * @param array<int, array<string, mixed>> $attachments Attachment data.
	 * @param array<string, int>               $args        Context args.
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
		$output = array(
			'ticket'       => isset( $context['ticket'] ) && is_array( $context['ticket'] ) ? $this->sanitize_ticket( $context['ticket'] ) : array(),
			'threads'      => isset( $context['threads'] ) && is_array( $context['threads'] )
				? $this->sanitize_threads( $context['threads'], $args )
				: array(),
			'attachments'  => isset( $context['attachments'] ) && is_array( $context['attachments'] ) ? $this->sanitize_attachments( $context['attachments'] ) : array(),
			'stats'        => array(
				'thread_count'     => 0,
				'attachment_count' => 0,
				'context_length'   => 0,
			),
			'generated_at' => isset( $context['generated_at'] ) ? sanitize_text_field( $context['generated_at'] ) : $this->get_current_time(),
		);

		$output['stats']['thread_count']     = count( $output['threads'] );
		$output['stats']['attachment_count'] = count( $output['attachments'] );
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
			'stats'        => array(
				'thread_count'     => 0,
				'attachment_count' => 0,
				'context_length'   => 0,
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
 