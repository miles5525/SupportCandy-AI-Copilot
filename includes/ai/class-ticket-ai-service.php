<?php
/**
 * Ticket AI service for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates ticket AI workflows across context, prompt, and AI engines.
 */
final class SCAI_Ticket_AI_Service {

	/**
	 * Context engine instance.
	 *
	 * @var SCAI_Context_Engine|null
	 */
	private $context_engine = null;

	/**
	 * Prompt engine instance.
	 *
	 * @var SCAI_Prompt_Engine|null
	 */
	private $prompt_engine = null;

	/**
	 * AI engine instance.
	 *
	 * @var SCAI_AI_Engine|null
	 */
	private $ai_engine = null;

	/**
	 * Conversation repository instance.
	 *
	 * @var SCAI_Conversation_Repository|null
	 */
	private $conversation_repository = null;

	/**
	 * Constructor.
	 *
	 * @param SCAI_Context_Engine|null $context_engine Optional context engine.
	 * @param SCAI_Prompt_Engine|null  $prompt_engine  Optional prompt engine.
	 * @param SCAI_AI_Engine|null      $ai_engine      Optional AI engine.
	 */
	public function __construct( $context_engine = null, $prompt_engine = null, $ai_engine = null ) {
		if ( $context_engine instanceof SCAI_Context_Engine ) {
			$this->context_engine = $context_engine;
		}

		if ( $prompt_engine instanceof SCAI_Prompt_Engine ) {
			$this->prompt_engine = $prompt_engine;
		}

		if ( $ai_engine instanceof SCAI_AI_Engine ) {
			$this->ai_engine = $ai_engine;
		}

		if ( class_exists( 'SCAI_Conversation_Repository' ) ) {
			try {
				$this->conversation_repository = new SCAI_Conversation_Repository();
			} catch ( Throwable $exception ) {
				$this->conversation_repository = null;
				$this->maybe_log_conversation_error( 0, '' );
			}
		}
	}

	/**
	 * Generate a ticket summary.
	 *
	 * @param int                  $ticket_id Ticket ID.
	 * @param array<string, mixed> $args      Request args.
	 * @return SCAI_AI_Response
	 */
	public function generate_ticket_summary( $ticket_id, array $args = array() ) {
		$ticket_id = absint( $ticket_id );

		if ( 0 === $ticket_id ) {
			return $this->build_error_response( 'invalid_ticket_id', __( 'Invalid ticket ID.', 'supportcandy-ai' ) );
		}

		$package = $this->build_ticket_context_package( $ticket_id, $args );

		if ( $package instanceof SCAI_AI_Response ) {
			return $package;
		}

		$prompt_engine = $this->get_prompt_engine();

		if ( ! $prompt_engine ) {
			return $this->build_error_response( 'prompt_engine_unavailable', __( 'Prompt engine is unavailable.', 'supportcandy-ai' ), $this->build_metadata( $ticket_id, 'ticket_summary', $package ) );
		}

		return $this->send_request(
			$prompt_engine->build_ticket_summary_request(
				$package['context_text'],
				$package['context'],
				$this->with_ticket_metadata( $args, $ticket_id, 'ticket_summary', $package )
			),
			$ticket_id,
			'ticket_summary',
			$package
		);
	}

	/**
	 * Generate a suggested ticket reply.
	 *
	 * @param int                  $ticket_id Ticket ID.
	 * @param array<string, mixed> $args      Request args.
	 * @return SCAI_AI_Response
	 */
	public function generate_reply( $ticket_id, array $args = array() ) {
		$ticket_id = absint( $ticket_id );

		if ( 0 === $ticket_id ) {
			return $this->build_error_response( 'invalid_ticket_id', __( 'Invalid ticket ID.', 'supportcandy-ai' ) );
		}

		$package = $this->build_ticket_context_package( $ticket_id, $args );

		if ( $package instanceof SCAI_AI_Response ) {
			return $package;
		}

		$prompt_engine = $this->get_prompt_engine();

		if ( ! $prompt_engine ) {
			return $this->build_error_response( 'prompt_engine_unavailable', __( 'Prompt engine is unavailable.', 'supportcandy-ai' ), $this->build_metadata( $ticket_id, 'reply_generation', $package ) );
		}

		return $this->send_request(
			$prompt_engine->build_reply_generation_request(
				$package['context_text'],
				$package['context'],
				$this->with_ticket_metadata( $args, $ticket_id, 'reply_generation', $package )
			),
			$ticket_id,
			'reply_generation',
			$package
		);
	}

	/**
	 * Improve a draft reply for a ticket.
	 *
	 * @param int                  $ticket_id   Ticket ID.
	 * @param string               $reply_text  Draft reply text.
	 * @param array<string, mixed> $args        Request args.
	 * @return SCAI_AI_Response
	 */
	public function improve_reply( $ticket_id, $reply_text, array $args = array() ) {
		$ticket_id  = absint( $ticket_id );
		$reply_text = $this->sanitize_text( $reply_text );

		if ( 0 === $ticket_id ) {
			return $this->build_error_response( 'invalid_ticket_id', __( 'Invalid ticket ID.', 'supportcandy-ai' ) );
		}

		if ( '' === $reply_text ) {
			return $this->build_error_response(
				'reply_text_empty',
				__( 'Reply text is empty.', 'supportcandy-ai' ),
				array(
					'ticket_id' => $ticket_id,
					'feature'   => 'reply_improvement',
				)
			);
		}

		$package = $this->build_ticket_context_package( $ticket_id, $args );

		if ( $package instanceof SCAI_AI_Response ) {
			return $package;
		}

		$prompt_engine = $this->get_prompt_engine();

		if ( ! $prompt_engine ) {
			return $this->build_error_response( 'prompt_engine_unavailable', __( 'Prompt engine is unavailable.', 'supportcandy-ai' ), $this->build_metadata( $ticket_id, 'reply_improvement', $package ) );
		}

		return $this->send_request(
			$prompt_engine->build_reply_improvement_request(
				$reply_text,
				$package['context_text'],
				$package['context'],
				$this->with_ticket_metadata( $args, $ticket_id, 'reply_improvement', $package )
			),
			$ticket_id,
			'reply_improvement',
			$package
		);
	}

	/**
	 * Get context engine instance.
	 *
	 * @return SCAI_Context_Engine|null
	 */
	private function get_context_engine() {
		if ( $this->context_engine instanceof SCAI_Context_Engine ) {
			return $this->context_engine;
		}

		if ( ! class_exists( 'SCAI_Context_Engine' ) ) {
			return null;
		}

		$this->context_engine = new SCAI_Context_Engine();

		return $this->context_engine;
	}

	/**
	 * Get prompt engine instance.
	 *
	 * @return SCAI_Prompt_Engine|null
	 */
	private function get_prompt_engine() {
		if ( $this->prompt_engine instanceof SCAI_Prompt_Engine ) {
			return $this->prompt_engine;
		}

		if ( ! class_exists( 'SCAI_Prompt_Engine' ) ) {
			return null;
		}

		$this->prompt_engine = new SCAI_Prompt_Engine();

		return $this->prompt_engine;
	}

	/**
	 * Get AI engine instance.
	 *
	 * @return SCAI_AI_Engine|null
	 */
	private function get_ai_engine() {
		if ( $this->ai_engine instanceof SCAI_AI_Engine ) {
			return $this->ai_engine;
		}

		if ( ! class_exists( 'SCAI_AI_Engine' ) ) {
			return null;
		}

		$this->ai_engine = new SCAI_AI_Engine();

		return $this->ai_engine;
	}

	/**
	 * Build context and context text for a ticket.
	 *
	 * @param int                  $ticket_id Ticket ID.
	 * @param array<string, mixed> $args      Context args.
	 * @return array<string, mixed>|SCAI_AI_Response
	 */
	private function build_ticket_context_package( $ticket_id, array $args = array() ) {
		$context_engine = $this->get_context_engine();

		if ( ! $context_engine ) {
			return $this->build_error_response(
				'context_engine_unavailable',
				__( 'Context engine is unavailable.', 'supportcandy-ai' ),
				array(
					'ticket_id' => absint( $ticket_id ),
				)
			);
		}

		$context = $context_engine->build_from_ticket_id( $ticket_id, $args );

		if ( empty( $context['ticket'] ) || empty( $context['ticket']['id'] ) ) {
			return $this->build_error_response(
				'ticket_context_empty',
				__( 'Ticket context is empty or unavailable.', 'supportcandy-ai' ),
				array(
					'ticket_id' => absint( $ticket_id ),
				)
			);
		}

		$context_text = $context_engine->build_ticket_context_text( $context, $args );

		if ( '' === $context_text ) {
			return $this->build_error_response(
				'ticket_context_empty',
				__( 'Ticket context text is empty.', 'supportcandy-ai' ),
				array(
					'ticket_id' => absint( $ticket_id ),
				)
			);
		}

		return array(
			'context'      => $context,
			'context_text' => $context_text,
		);
	}

	/**
	 * Send request through the AI engine.
	 *
	 * @param array<string, mixed> $request_args Request args.
	 * @param int                  $ticket_id    Ticket ID.
	 * @param string               $feature      Feature key.
	 * @param array<string, mixed> $package      Context package.
	 * @return SCAI_AI_Response
	 */
	private function send_request( array $request_args, $ticket_id, $feature, array $package ) {
		$ai_engine = $this->get_ai_engine();

		if ( ! $ai_engine ) {
			return $this->build_error_response( 'ai_engine_unavailable', __( 'AI engine is unavailable.', 'supportcandy-ai' ), $this->build_metadata( $ticket_id, $feature, $package ) );
		}

		if ( ! class_exists( 'SCAI_AI_Request' ) ) {
			return $this->build_error_response( 'ai_engine_unavailable', __( 'AI request class is unavailable.', 'supportcandy-ai' ), $this->build_metadata( $ticket_id, $feature, $package ) );
		}

		$request_args['metadata'] = isset( $request_args['metadata'] ) && is_array( $request_args['metadata'] )
			? array_merge( $request_args['metadata'], $this->build_metadata( $ticket_id, $feature, $package ) )
			: $this->build_metadata( $ticket_id, $feature, $package );

		$request = SCAI_AI_Request::from_array( $request_args );

		$response = $ai_engine->generate_response( $request );

		if ( $response instanceof SCAI_AI_Response ) {
			$this->maybe_save_conversation( $ticket_id, $feature, $response, $package );

			return $response;
		}

		return $this->build_error_response( 'ai_engine_unavailable', __( 'AI engine returned an invalid response.', 'supportcandy-ai' ), $this->build_metadata( $ticket_id, $feature, $package ) );
	}

	/**
	 * Save a successful AI response as ticket conversation history.
	 *
	 * Conversation persistence is best-effort and must never interrupt the AI
	 * response returned to the caller.
	 *
	 * @param int                  $ticket_id Ticket ID.
	 * @param string               $feature   Feature key.
	 * @param mixed                $response  AI response.
	 * @param array<string, mixed> $context   Ticket context package.
	 * @return void
	 */
	private function maybe_save_conversation( $ticket_id, $feature, $response, array $context = array() ) {
		if ( ! class_exists( 'SCAI_Conversation_Repository' ) || ! $this->conversation_repository instanceof SCAI_Conversation_Repository ) {
			return;
		}

		if ( ! $response instanceof SCAI_AI_Response || ! $response->is_success() ) {
			return;
		}

		$content = trim( (string) $response->get_content() );

		if ( '' === $content ) {
			return;
		}

		$ticket_context   = isset( $context['context'] ) && is_array( $context['context'] ) ? $context['context'] : array();
		$stats            = isset( $ticket_context['stats'] ) && is_array( $ticket_context['stats'] ) ? $ticket_context['stats'] : array();
		$context_hash     = isset( $context['context_hash'] ) ? $context['context_hash'] : '';
		$context_hash     = '' === $context_hash && isset( $ticket_context['context_hash'] ) ? $ticket_context['context_hash'] : $context_hash;
		$conversation_args = array(
			'provider'          => $response->get_provider(),
			'model'             => $response->get_model(),
			'tokens'            => $response->get_total_tokens(),
			'prompt_tokens'     => $response->get_prompt_tokens(),
			'completion_tokens' => $response->get_completion_tokens(),
			'context_hash'      => $context_hash,
			'metadata'          => array(
				'request_id'       => $response->get_request_id(),
				'duration_ms'      => $response->get_duration_ms(),
				'finish_reason'    => $response->get_finish_reason(),
				'thread_count'     => isset( $stats['thread_count'] ) ? absint( $stats['thread_count'] ) : 0,
				'attachment_count' => isset( $stats['attachment_count'] ) ? absint( $stats['attachment_count'] ) : 0,
			),
		);

		try {
			$saved = $this->conversation_repository->create_assistant_message(
				absint( $ticket_id ),
				get_current_user_id(),
				sanitize_key( $feature ),
				$content,
				$conversation_args
			);

			if ( false === $saved ) {
				$this->maybe_log_conversation_error( $ticket_id, $feature );
			}
		} catch ( Throwable $exception ) {
			$this->maybe_log_conversation_error( $ticket_id, $feature );
		}
	}

	/**
	 * Log a non-sensitive conversation persistence failure in debug mode.
	 *
	 * @param int    $ticket_id Ticket ID.
	 * @param string $feature   Feature key.
	 * @return void
	 */
	private function maybe_log_conversation_error( $ticket_id, $feature ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only, non-sensitive persistence notice.
			sprintf(
				'SupportCandy AI conversation save failed for ticket %d and feature %s.',
				absint( $ticket_id ),
				sanitize_key( $feature )
			)
		);
	}

	/**
	 * Add ticket metadata to downstream prompt args.
	 *
	 * @param array<string, mixed> $args      Original args.
	 * @param int                  $ticket_id Ticket ID.
	 * @param string               $feature   Feature key.
	 * @param array<string, mixed> $package   Context package.
	 * @return array<string, mixed>
	 */
	private function with_ticket_metadata( array $args, $ticket_id, $feature, array $package ) {
		$metadata = isset( $args['metadata'] ) && is_array( $args['metadata'] ) ? $this->sanitize_metadata( $args['metadata'] ) : array();

		$args['metadata'] = array_merge(
			$metadata,
			$this->build_metadata( $ticket_id, $feature, $package )
		);

		return $args;
	}

	/**
	 * Build safe metadata for request logging.
	 *
	 * @param int                  $ticket_id Ticket ID.
	 * @param string               $feature   Feature key.
	 * @param array<string, mixed> $package   Context package.
	 * @return array<string, mixed>
	 */
	private function build_metadata( $ticket_id, $feature, array $package = array() ) {
		$context     = isset( $package['context'] ) && is_array( $package['context'] ) ? $package['context'] : array();
		$stats       = isset( $context['stats'] ) && is_array( $context['stats'] ) ? $context['stats'] : array();
		$thread_count = isset( $stats['thread_count'] ) ? absint( $stats['thread_count'] ) : 0;
		$attachment_count = isset( $stats['attachment_count'] ) ? absint( $stats['attachment_count'] ) : 0;

		return array(
			'ticket_id'                => absint( $ticket_id ),
			'feature'                  => sanitize_key( $feature ),
			'context_thread_count'     => $thread_count,
			'context_attachment_count' => $attachment_count,
		);
	}

	/**
	 * Build a normalized error response.
	 *
	 * @param string               $code     Error code.
	 * @param string               $message  Error message.
	 * @param array<string, mixed> $metadata Safe metadata.
	 * @return SCAI_AI_Response
	 */
	private function build_error_response( $code, $message, array $metadata = array() ) {
		return SCAI_AI_Response::error(
			$code,
			$message,
			array(
				'metadata' => $this->sanitize_metadata( $metadata ),
			)
		);
	}

	/**
	 * Sanitize text while preserving paragraphs.
	 *
	 * @param mixed $text Raw text.
	 * @return string
	 */
	private function sanitize_text( $text ) {
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
	 * Sanitize safe metadata recursively.
	 *
	 * @param array<string, mixed> $metadata Raw metadata.
	 * @return array<string, mixed>
	 */
	private function sanitize_metadata( array $metadata ) {
		$clean = array();

		foreach ( $metadata as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || $this->is_sensitive_key( $key ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = $this->sanitize_metadata( $value );
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
				continue;
			}

			if ( is_numeric( $value ) ) {
				$clean[ $key ] = 0 + $value;
				continue;
			}

			$clean[ $key ] = sanitize_text_field( (string) $value );
		}

		return $clean;
	}

	/**
	 * Check whether metadata key may contain sensitive data.
	 *
	 * @param string $key Metadata key.
	 * @return bool
	 */
	private function is_sensitive_key( $key ) {
		$key = sanitize_key( $key );

		foreach ( array( 'key', 'token', 'secret', 'password', 'authorization', 'provider_config' ) as $fragment ) {
			if ( false !== strpos( $key, $fragment ) ) {
				return true;
			}
		}

		return false;
	}
}
