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
	 * Image attachment preparer instance.
	 *
	 * @var SCAI_Image_Attachment_Preparer|null
	 */
	private $image_attachment_preparer = null;

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

		if ( class_exists( 'SCAI_Image_Attachment_Preparer' ) ) {
			try {
				$this->image_attachment_preparer = new SCAI_Image_Attachment_Preparer();
			} catch ( Throwable $exception ) {
				$this->image_attachment_preparer = null;
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

		$package['response_options'] = $this->normalize_response_options( $args );

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

		$package['response_options'] = $this->normalize_response_options( $args );

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

		$package['response_options'] = $this->normalize_response_options( $args );

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
	 * Merge an agent draft with an AI suggestion for a ticket.
	 *
	 * @param int                  $ticket_id     Ticket ID.
	 * @param string               $current_draft Current agent draft.
	 * @param string               $ai_suggestion Generated AI suggestion.
	 * @param array<string, mixed> $args          Request args.
	 * @return SCAI_AI_Response
	 */
	public function merge_reply( $ticket_id, $current_draft, $ai_suggestion, array $args = array() ) {
		$ticket_id     = absint( $ticket_id );
		$current_draft = $this->sanitize_text( $current_draft );
		$ai_suggestion = $this->sanitize_text( $ai_suggestion );

		if ( 0 === $ticket_id ) {
			return $this->build_error_response( 'invalid_ticket_id', __( 'Invalid ticket ID.', 'supportcandy-ai' ) );
		}

		if ( '' === $current_draft ) {
			return $this->build_error_response(
				'empty_current_draft',
				__( 'Type a draft in the reply editor before using Merge with my draft.', 'supportcandy-ai' ),
				array(
					'ticket_id' => $ticket_id,
					'feature'   => 'reply_merge',
				)
			);
		}

		if ( '' === $ai_suggestion ) {
			return $this->build_error_response(
				'empty_ai_suggestion',
				__( 'Generate an AI suggestion before using Merge with my draft.', 'supportcandy-ai' ),
				array(
					'ticket_id' => $ticket_id,
					'feature'   => 'reply_merge',
				)
			);
		}

		$package = $this->build_ticket_context_package( $ticket_id, $args );

		if ( $package instanceof SCAI_AI_Response ) {
			return $package;
		}

		$package['response_options']  = $this->normalize_response_options( $args );
		$package['workflow_metadata'] = array(
			'current_draft_length'  => $this->get_text_length( $current_draft ),
			'ai_suggestion_length' => $this->get_text_length( $ai_suggestion ),
			'tone'                 => $package['response_options']['tone'],
			'length'               => $package['response_options']['length'],
			'format'               => $package['response_options']['format'],
		);

		$prompt_engine = $this->get_prompt_engine();

		if ( ! $prompt_engine || ! method_exists( $prompt_engine, 'build_reply_merge_request' ) ) {
			return $this->build_error_response( 'prompt_engine_unavailable', __( 'Prompt engine is unavailable.', 'supportcandy-ai' ), $this->build_metadata( $ticket_id, 'reply_merge', $package ) );
		}

		return $this->send_request(
			$prompt_engine->build_reply_merge_request(
				$current_draft,
				$ai_suggestion,
				$package['context_text'],
				$package['context'],
				$this->with_ticket_metadata( $args, $ticket_id, 'reply_merge', $package )
			),
			$ticket_id,
			'reply_merge',
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
	 * Determine whether image understanding is explicitly enabled.
	 *
	 * @return bool
	 */
	private function is_image_understanding_enabled() {
		if ( ! class_exists( 'SCAI_Settings' ) || ! is_callable( array( 'SCAI_Settings', 'get' ) ) ) {
			return false;
		}

		$option_name = defined( 'SCAI_Settings::OPTION_IMAGE_UNDERSTANDING_ENABLED' )
			? SCAI_Settings::OPTION_IMAGE_UNDERSTANDING_ENABLED
			: 'scai_image_understanding_enabled';

		if ( null === get_option( $option_name, null ) ) {
			return false;
		}

		return (bool) SCAI_Settings::get( 'image_understanding_enabled', false );
	}

	/**
	 * Prepare safe ticket images for an AI request.
	 *
	 * Base64 image data is returned only in the request image list. Metadata is
	 * deliberately limited to counts, filenames, and the enabled flag.
	 *
	 * @param array<string, mixed> $package Ticket context package.
	 * @param array<string, mixed> $args    Preparation arguments.
	 * @return array{images: array<int, array<string, mixed>>, metadata: array<string, mixed>}
	 */
	private function prepare_ticket_images_for_ai( array $package, array $args = array() ) {
		$enabled     = $this->is_image_understanding_enabled();
		$supported   = $this->active_provider_supports_images();
		$context     = isset( $package['context'] ) && is_array( $package['context'] ) ? $package['context'] : array();
		$attachments = isset( $context['attachments'] ) && is_array( $context['attachments'] ) ? $context['attachments'] : array();
		$metadata    = array_merge(
			$this->get_safe_attachment_metadata_for_request( $attachments ),
			array(
				'prepared_image_count'             => 0,
				'prepared_image_filenames'         => array(),
				'prepared_images'                   => array(),
				'included_image_count'             => 0,
				'included_image_filenames'         => array(),
				'included_images'                   => array(),
				'images_prepared_for_request'      => false,
				'images_attached_to_request'       => false,
				'image_understanding_enabled'      => $enabled,
				'provider_supports_images'          => $supported,
				'image_content_provided_to_model' => false,
			)
		);

		if ( ! $enabled || ! $supported || ! $this->image_attachment_preparer instanceof SCAI_Image_Attachment_Preparer ) {
			return array(
				'images'   => array(),
				'metadata' => $metadata,
			);
		}

		$ticket_id = isset( $context['ticket']['id'] ) ? absint( $context['ticket']['id'] ) : 0;

		if ( 0 === $ticket_id || ! class_exists( 'SCAI_SupportCandy_Adapter' ) ) {
			return array(
				'images'   => array(),
				'metadata' => $metadata,
			);
		}

		try {
			$adapter = new SCAI_SupportCandy_Adapter();

			if ( ! method_exists( $adapter, 'get_ticket_attachments' ) ) {
				return array(
					'images'   => array(),
					'metadata' => $metadata,
				);
			}

			$attachments = $adapter->get_ticket_attachments( $ticket_id );
			$attachments = is_array( $attachments ) ? $attachments : array();

			$prepared = $this->image_attachment_preparer->prepare_multiple(
				$attachments,
				array(
					'max_images' => isset( $args['max_images'] ) ? absint( $args['max_images'] ) : SCAI_Image_Attachment_Preparer::DEFAULT_MAX_IMAGES,
					'detail'     => isset( $args['detail'] ) ? sanitize_key( $args['detail'] ) : 'low',
				)
			);
		} catch ( Throwable $exception ) {
			$this->maybe_log_image_preparation_error( $ticket_id );

			return array(
				'images'   => array(),
				'metadata' => $metadata,
			);
		}

		$images = array();

		foreach ( $prepared as $image ) {
			if ( ! is_array( $image ) || empty( $image['success'] ) || empty( $image['image_data_url'] ) ) {
				continue;
			}

			$filename  = isset( $image['filename'] ) && is_scalar( $image['filename'] ) ? sanitize_file_name( (string) $image['filename'] ) : '';
			$mime_type = isset( $image['mime_type'] ) && is_scalar( $image['mime_type'] ) ? sanitize_mime_type( (string) $image['mime_type'] ) : '';

			$images[] = array(
				'data_url'  => (string) $image['image_data_url'],
				'mime_type' => $mime_type,
				'filename'  => $filename,
				'size'      => isset( $image['file_size'] ) ? absint( $image['file_size'] ) : 0,
				'detail'    => isset( $image['detail'] ) ? sanitize_key( $image['detail'] ) : 'low',
			);
			$metadata['prepared_images'][] = array(
				'filename'               => $filename,
				'mime_type'              => $mime_type,
				'size'                   => isset( $image['file_size'] ) ? absint( $image['file_size'] ) : 0,
				'included_in_ai_request' => true,
			);

			if ( '' !== $filename ) {
				$metadata['prepared_image_filenames'][] = $filename;
			}
		}

		$metadata['prepared_image_filenames']         = array_values( array_unique( $metadata['prepared_image_filenames'] ) );
		$metadata['prepared_image_count']             = count( $images );
		$metadata['included_images']                   = $metadata['prepared_images'];
		$metadata['included_image_count']              = $metadata['prepared_image_count'];
		$metadata['included_image_filenames']          = $metadata['prepared_image_filenames'];
		$metadata['images_prepared_for_request']       = 0 < $metadata['prepared_image_count'];
		$metadata['images_attached_to_request']        = 0 < $metadata['included_image_count'];
		$metadata['image_content_provided_to_model'] = 0 < $metadata['prepared_image_count'];

		return array(
			'images'   => $images,
			'metadata' => $metadata,
		);
	}

	/**
	 * Determine whether the active provider can accept image inputs.
	 *
	 * @return bool
	 */
	private function active_provider_supports_images() {
		if ( ! class_exists( 'SCAI_Provider_Manager' ) ) {
			return false;
		}

		$manager  = new SCAI_Provider_Manager();
		$provider = $manager->get_active_provider();

		return $provider && method_exists( $provider, 'supports_images' ) && (bool) $provider->supports_images();
	}

	/**
	 * Build non-sensitive attachment metadata for requests and persistence.
	 *
	 * @param array<int, mixed> $attachments Normalized context attachments.
	 * @return array<string, int>
	 */
	private function get_safe_attachment_metadata_for_request( array $attachments ) {
		$metadata = array(
			'attachment_count'              => count( $attachments ),
			'text_attachment_excerpt_count' => 0,
			'image_attachment_count'        => 0,
		);

		foreach ( $attachments as $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}

			if ( ! empty( $attachment['content_inspected'] ) && ! empty( $attachment['content_excerpt'] ) ) {
				$metadata['text_attachment_excerpt_count']++;
			}

			if ( $this->is_image_attachment_metadata( $attachment ) ) {
				$metadata['image_attachment_count']++;
			}
		}

		return $metadata;
	}

	/**
	 * Add a safe attachment handoff note to the prompt.
	 *
	 * @param array<string, mixed> $request_args Request arguments.
	 * @param array<string, mixed> $metadata     Safe attachment metadata.
	 * @return array<string, mixed>
	 */
	private function add_attachment_handoff_note( array $request_args, array $metadata, $feature = '' ) {
		$attachment_count = isset( $metadata['attachment_count'] ) ? absint( $metadata['attachment_count'] ) : 0;

		if ( 0 === $attachment_count ) {
			return $request_args;
		}

		$note = sprintf(
			/* translators: 1: attachment count, 2: inspected text excerpt count, 3: images supplied to the model. */
			__( 'Attachment handoff: %1$d attachment(s) are represented in the ticket context; %2$d inspected text excerpt(s) are available; %3$d image(s) are supplied to the model.', 'supportcandy-ai' ),
			$attachment_count,
			isset( $metadata['text_attachment_excerpt_count'] ) ? absint( $metadata['text_attachment_excerpt_count'] ) : 0,
			! empty( $metadata['image_content_provided_to_model'] ) && isset( $metadata['prepared_image_count'] ) ? absint( $metadata['prepared_image_count'] ) : 0
		);

		$note .= ' ' . __( 'Use inspected excerpts as evidence. Mention metadata-only attachments honestly, and do not claim unprovided image content was inspected.', 'supportcandy-ai' );

		if ( ! empty( $metadata['images_attached_to_request'] ) && ! empty( $metadata['included_image_filenames'] ) && is_array( $metadata['included_image_filenames'] ) ) {
			$filenames = array();

			foreach ( $metadata['included_image_filenames'] as $filename ) {
				$filename = sanitize_file_name( $filename );

				if ( '' !== $filename ) {
					$filenames[] = $filename;
				}
			}

			if ( ! empty( $filenames ) ) {
				$note .= "\n\n" . sprintf(
					/* translators: %s: comma-separated image filenames. */
					__( 'Runtime vision status: image attachments are included in this AI request for visual inspection: %s.', 'supportcandy-ai' ),
					implode( ', ', $filenames )
				);

				if ( 'ticket_summary' === sanitize_key( $feature ) ) {
					$note .= ' ' . __( "At least one image is attached to this AI request. In the Attachments section, for included image attachments, inspect the image visually. If you can see it, say 'Content inspected: yes' and describe one visible detail. If you cannot access the image, say 'Image was attached but could not be inspected.'", 'supportcandy-ai' );
				}
			}
		}

		$prompt = isset( $request_args['prompt'] ) && is_scalar( $request_args['prompt'] ) ? sanitize_textarea_field( (string) $request_args['prompt'] ) : '';
		$request_args['prompt'] = trim( $prompt . "\n\n" . $note );

		return $request_args;
	}

	/**
	 * Add per-request visual inspection instructions without image payload data.
	 *
	 * @param array<string, mixed> $request_args Request arguments.
	 * @param array<string, mixed> $metadata     Safe image metadata.
	 * @return array<string, mixed>
	 */
	private function add_image_request_instructions( array $request_args, array $metadata ) {
		$prompt_engine = $this->get_prompt_engine();

		if ( ! $prompt_engine || ! method_exists( $prompt_engine, 'build_image_request_instructions' ) ) {
			return $request_args;
		}

		$instructions = $prompt_engine->build_image_request_instructions( $metadata );

		if ( '' === $instructions ) {
			return $request_args;
		}

		$system = isset( $request_args['system_instructions'] ) && is_scalar( $request_args['system_instructions'] )
			? sanitize_textarea_field( (string) $request_args['system_instructions'] )
			: '';
		$request_args['system_instructions'] = trim( $system . "\n\nImage inputs for this request:\n" . $instructions );

		return $request_args;
	}

	/**
	 * Check whether attachment metadata describes a supported image type.
	 *
	 * @param array<string, mixed> $attachment Attachment metadata.
	 * @return bool
	 */
	private function is_image_attachment_metadata( array $attachment ) {
		$filename  = isset( $attachment['filename'] ) && is_scalar( $attachment['filename'] ) ? sanitize_file_name( (string) $attachment['filename'] ) : '';
		$mime_type = isset( $attachment['mime_type'] ) && is_scalar( $attachment['mime_type'] ) ? sanitize_mime_type( (string) $attachment['mime_type'] ) : '';
		$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );

		return 0 === strpos( $mime_type, 'image/' )
			|| in_array( $extension, array( 'jpg', 'jpeg', 'png', 'webp', 'gif' ), true );
	}

	/**
	 * Log a safe image preparation failure in debug mode.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return void
	 */
	private function maybe_log_image_preparation_error( $ticket_id ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only, path-free failure notice.
			sprintf( 'SupportCandy AI image preparation failed for ticket %d.', absint( $ticket_id ) )
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

		$image_preparation = $this->prepare_ticket_images_for_ai( $package );
		$image_validator   = SCAI_AI_Request::from_array( array() );
		$image_validator->set_images( $image_preparation['images'] );
		$runtime_images = $image_validator->get_images();
		$image_preparation['metadata']['prepared_image_count']             = count( $runtime_images );
		$image_preparation['metadata']['included_image_count']              = count( $runtime_images );
		$image_preparation['metadata']['images_prepared_for_request']       = ! empty( $image_preparation['images'] );
		$image_preparation['metadata']['images_attached_to_request']        = $image_validator->has_images();
		$image_preparation['metadata']['image_content_provided_to_model'] = $image_validator->has_images();
		$package['image_metadata']                                         = $image_preparation['metadata'];

		$request_args = $this->add_image_request_instructions( $request_args, $image_preparation['metadata'] );
		$request_args = $this->add_attachment_handoff_note( $request_args, $image_preparation['metadata'], $feature );

		$request_args['metadata'] = isset( $request_args['metadata'] ) && is_array( $request_args['metadata'] )
			? array_merge( $request_args['metadata'], $this->build_metadata( $ticket_id, $feature, $package ) )
			: $this->build_metadata( $ticket_id, $feature, $package );

		$request = SCAI_AI_Request::from_array( $request_args );
		$request->set_images( $runtime_images );
		$image_preparation['metadata']['images_attached_to_request'] = $request->has_images();
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

		// Conversation agent_id consistently stores the WordPress user ID.
		$current_agent_id = get_current_user_id();
		if ( ! $current_agent_id ) {
			return;
		}

		$ticket_context   = isset( $context['context'] ) && is_array( $context['context'] ) ? $context['context'] : array();
		$stats            = isset( $ticket_context['stats'] ) && is_array( $ticket_context['stats'] ) ? $ticket_context['stats'] : array();
		$context_hash     = isset( $context['context_hash'] ) ? $context['context_hash'] : '';
		$context_hash     = '' === $context_hash && isset( $ticket_context['context_hash'] ) ? $ticket_context['context_hash'] : $context_hash;
		$response_options = isset( $context['response_options'] ) && is_array( $context['response_options'] )
			? $this->normalize_response_options( $context['response_options'] )
			: $this->normalize_response_options( array() );
		$image_metadata    = isset( $context['image_metadata'] ) && is_array( $context['image_metadata'] ) ? $this->sanitize_metadata( $context['image_metadata'] ) : array();
		$workflow_metadata = isset( $context['workflow_metadata'] ) && is_array( $context['workflow_metadata'] ) ? $this->sanitize_metadata( $context['workflow_metadata'] ) : array();
		$conversation_args = array(
			'provider'          => $response->get_provider(),
			'model'             => $response->get_model(),
			'tokens'            => $response->get_total_tokens(),
			'prompt_tokens'     => $response->get_prompt_tokens(),
			'completion_tokens' => $response->get_completion_tokens(),
			'context_hash'      => $context_hash,
			'metadata'          => array_merge(
				array(
					'request_id'                    => $response->get_request_id(),
					'duration_ms'                   => $response->get_duration_ms(),
					'finish_reason'                 => $response->get_finish_reason(),
					'thread_count'                  => isset( $stats['thread_count'] ) ? absint( $stats['thread_count'] ) : 0,
					'attachment_count'              => isset( $stats['attachment_count'] ) ? absint( $stats['attachment_count'] ) : 0,
					'text_attachment_excerpt_count' => isset( $image_metadata['text_attachment_excerpt_count'] ) ? absint( $image_metadata['text_attachment_excerpt_count'] ) : 0,
					'image_attachment_count'        => isset( $image_metadata['image_attachment_count'] ) ? absint( $image_metadata['image_attachment_count'] ) : 0,
					'tone'                          => $response_options['tone'],
					'length'                        => $response_options['length'],
					'format'                        => $response_options['format'],
					'prepared_image_count'          => isset( $image_metadata['prepared_image_count'] ) ? absint( $image_metadata['prepared_image_count'] ) : 0,
					'prepared_image_filenames'      => isset( $image_metadata['prepared_image_filenames'] ) && is_array( $image_metadata['prepared_image_filenames'] ) ? $image_metadata['prepared_image_filenames'] : array(),
					'images_attached_to_request'    => ! empty( $image_metadata['images_attached_to_request'] ),
					'included_image_count'          => isset( $image_metadata['included_image_count'] ) ? absint( $image_metadata['included_image_count'] ) : 0,
					'included_image_filenames'      => isset( $image_metadata['included_image_filenames'] ) && is_array( $image_metadata['included_image_filenames'] ) ? $image_metadata['included_image_filenames'] : array(),
				),
				$workflow_metadata
			),
		);

		try {
			$saved = $this->conversation_repository->create_assistant_message(
				absint( $ticket_id ),
				$current_agent_id,
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
		$options  = $this->normalize_response_options( $args );

		$args['tone']   = $options['tone'];
		$args['length'] = $options['length'];
		$args['format'] = $options['format'];

		$args['metadata'] = array_merge(
			$metadata,
			$this->build_metadata( $ticket_id, $feature, $package )
		);

		return $args;
	}

	/**
	 * Normalize response-writing options.
	 *
	 * @param array<string, mixed> $args Request arguments or response options.
	 * @return array{tone: string, length: string, format: string}
	 */
	private function normalize_response_options( array $args ) {
		$source = isset( $args['response_options'] ) && is_array( $args['response_options'] )
			? $args['response_options']
			: array();

		foreach ( array( 'tone', 'length', 'format' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$source[ $key ] = $args[ $key ];
			}
		}

		$defaults = array(
			'tone'   => 'professional',
			'length' => 'standard',
			'format' => 'plain',
		);
		$allowed  = array(
			'tone'   => array( 'professional', 'friendly', 'empathetic', 'concise' ),
			'length' => array( 'short', 'standard', 'detailed' ),
			'format' => array( 'plain', 'step_by_step', 'technical' ),
		);

		foreach ( $defaults as $key => $default ) {
			$value = isset( $source[ $key ] ) && is_scalar( $source[ $key ] )
				? sanitize_key( (string) $source[ $key ] )
				: '';

			$defaults[ $key ] = in_array( $value, $allowed[ $key ], true ) ? $value : $default;
		}

		return $defaults;
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
		$context          = isset( $package['context'] ) && is_array( $package['context'] ) ? $package['context'] : array();
		$stats            = isset( $context['stats'] ) && is_array( $context['stats'] ) ? $context['stats'] : array();
		$image_metadata    = isset( $package['image_metadata'] ) && is_array( $package['image_metadata'] ) ? $this->sanitize_metadata( $package['image_metadata'] ) : array();
		$workflow_metadata = isset( $package['workflow_metadata'] ) && is_array( $package['workflow_metadata'] ) ? $this->sanitize_metadata( $package['workflow_metadata'] ) : array();
		$thread_count     = isset( $stats['thread_count'] ) ? absint( $stats['thread_count'] ) : 0;
		$attachment_count = isset( $stats['attachment_count'] ) ? absint( $stats['attachment_count'] ) : 0;

		return array_merge(
			array(
				'ticket_id'                => absint( $ticket_id ),
				'feature'                  => sanitize_key( $feature ),
				'context_thread_count'     => $thread_count,
				'context_attachment_count' => $attachment_count,
			),
			$workflow_metadata,
			$image_metadata
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
	 * Get a Unicode-aware text length for safe request metadata.
	 *
	 * @param string $text Sanitized text.
	 * @return int
	 */
	private function get_text_length( $text ) {
		$text = (string) $text;

		return function_exists( 'mb_strlen' ) ? absint( mb_strlen( $text ) ) : absint( strlen( $text ) );
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
