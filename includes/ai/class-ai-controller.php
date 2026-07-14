<?php
/**
 * AI AJAX controller for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers admin AJAX actions for MVP ticket AI workflows.
 */
final class SCAI_AI_Controller {

	/**
	 * Required capability for MVP AI actions.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'scai_ai_action';

	/**
	 * Nonce request key.
	 *
	 * @var string
	 */
	const NONCE_KEY = 'nonce';

	/**
	 * Ticket AI service instance.
	 *
	 * @var SCAI_Ticket_AI_Service|null
	 */
	private $ticket_ai_service = null;

	/**
	 * Initialize AJAX actions.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_ajax_scai_generate_ticket_summary', array( $this, 'ajax_generate_summary' ) );
		add_action( 'wp_ajax_scai_generate_ticket_reply', array( $this, 'ajax_generate_reply' ) );
		add_action( 'wp_ajax_scai_improve_ticket_reply', array( $this, 'ajax_improve_reply' ) );
		add_action( 'wp_ajax_scai_merge_ticket_reply', array( $this, 'merge_ticket_reply' ) );
		add_action( 'wp_ajax_scai_get_ticket_conversation_history', array( $this, 'get_ticket_conversation_history' ) );
	}

	/**
	 * Handle ticket summary generation.
	 *
	 * @return void
	 */
	public function ajax_generate_summary() {
		$verified = $this->verify_request( 'ticket_summary' );

		if ( true !== $verified ) {
			return;
		}

		$ticket_id = $this->get_ticket_id_from_request();

		if ( 0 === $ticket_id ) {
			wp_send_json_error( $this->build_error_response_data( 'invalid_ticket_id', __( 'Invalid ticket ID.', 'supportcandy-ai' ), 'ticket_summary' ), 400 );
		}

		if ( ! $this->current_user_can_run_ai_action( $ticket_id, 'ticket_summary' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'permission_denied',
					'message' => __( 'You do not have permission to use AI for this ticket.', 'supportcandy-ai' ),
					'feature' => 'ticket_summary',
				),
				403
			);
		}

		$ticket_ai_service = $this->get_ticket_ai_service();

		if ( ! $ticket_ai_service ) {
			wp_send_json_error( $this->build_error_response_data( 'ticket_ai_service_unavailable', __( 'Ticket AI service is unavailable.', 'supportcandy-ai' ), 'ticket_summary' ), 500 );
		}

		$response_options = $this->get_response_options_from_request();

		$this->send_ai_response(
			$ticket_ai_service->generate_ticket_summary(
				$ticket_id,
				array(
					'length' => $response_options['length'],
				)
			),
			'ticket_summary'
		);
	}

	/**
	 * Handle suggested ticket reply generation.
	 *
	 * @return void
	 */
	public function ajax_generate_reply() {
		$verified = $this->verify_request( 'reply_generation' );

		if ( true !== $verified ) {
			return;
		}

		$ticket_id = $this->get_ticket_id_from_request();

		if ( 0 === $ticket_id ) {
			wp_send_json_error( $this->build_error_response_data( 'invalid_ticket_id', __( 'Invalid ticket ID.', 'supportcandy-ai' ), 'reply_generation' ), 400 );
		}

		if ( ! $this->current_user_can_run_ai_action( $ticket_id, 'reply_generation' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'permission_denied',
					'message' => __( 'You do not have permission to use AI for this ticket.', 'supportcandy-ai' ),
					'feature' => 'reply_generation',
				),
				403
			);
		}

		$ticket_ai_service = $this->get_ticket_ai_service();

		if ( ! $ticket_ai_service ) {
			wp_send_json_error( $this->build_error_response_data( 'ticket_ai_service_unavailable', __( 'Ticket AI service is unavailable.', 'supportcandy-ai' ), 'reply_generation' ), 500 );
		}

		$response_options = $this->get_response_options_from_request();

		$this->send_ai_response( $ticket_ai_service->generate_reply( $ticket_id, $response_options ), 'reply_generation' );
	}

	/**
	 * Handle draft reply improvement.
	 *
	 * @return void
	 */
	public function ajax_improve_reply() {
		$verified = $this->verify_request( 'reply_improvement' );

		if ( true !== $verified ) {
			return;
		}

		$ticket_id  = $this->get_ticket_id_from_request();
		$reply_text = isset( $_POST['reply_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reply_text'] ) ) : '';

		if ( 0 === $ticket_id ) {
			wp_send_json_error( $this->build_error_response_data( 'invalid_ticket_id', __( 'Invalid ticket ID.', 'supportcandy-ai' ), 'reply_improvement' ), 400 );
		}

		if ( ! $this->current_user_can_run_ai_action( $ticket_id, 'reply_improvement' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'permission_denied',
					'message' => __( 'You do not have permission to use AI for this ticket.', 'supportcandy-ai' ),
					'feature' => 'reply_improvement',
				),
				403
			);
		}

		if ( '' === $reply_text ) {
			wp_send_json_error( $this->build_error_response_data( 'reply_text_empty', __( 'Reply text is empty.', 'supportcandy-ai' ), 'reply_improvement' ), 400 );
		}

		$ticket_ai_service = $this->get_ticket_ai_service();

		if ( ! $ticket_ai_service ) {
			wp_send_json_error( $this->build_error_response_data( 'ticket_ai_service_unavailable', __( 'Ticket AI service is unavailable.', 'supportcandy-ai' ), 'reply_improvement' ), 500 );
		}

		$response_options = $this->get_response_options_from_request();

		$this->send_ai_response( $ticket_ai_service->improve_reply( $ticket_id, $reply_text, $response_options ), 'reply_improvement' );
	}

	/**
	 * Handle merging an agent draft with an AI suggestion.
	 *
	 * @return void
	 */
	public function merge_ticket_reply() {
		$feature  = 'reply_merge';
		$verified = $this->verify_request( $feature );

		if ( true !== $verified ) {
			return;
		}

		$ticket_id = $this->get_ticket_id_from_request();

		if ( 0 === $ticket_id ) {
			wp_send_json_error( $this->build_error_response_data( 'invalid_ticket_id', __( 'Invalid ticket ID.', 'supportcandy-ai' ), $feature ), 400 );
		}

		if ( ! $this->current_user_can_run_ai_action( $ticket_id, $feature ) ) {
			wp_send_json_error(
				array(
					'code'    => 'permission_denied',
					'message' => __( 'You do not have permission to use AI for this ticket.', 'supportcandy-ai' ),
					'feature' => $feature,
				),
				403
			);
		}

		$current_draft = isset( $_POST['current_draft'] ) && is_scalar( $_POST['current_draft'] )
			? sanitize_textarea_field( wp_unslash( (string) $_POST['current_draft'] ) )
			: '';
		$ai_suggestion = isset( $_POST['ai_suggestion'] ) && is_scalar( $_POST['ai_suggestion'] )
			? sanitize_textarea_field( wp_unslash( (string) $_POST['ai_suggestion'] ) )
			: '';

		if ( '' === $current_draft ) {
			wp_send_json_error(
				$this->build_error_response_data( 'empty_current_draft', __( 'Type a draft in the reply editor before using Merge with my draft.', 'supportcandy-ai' ), $feature ),
				400
			);
		}

		if ( '' === $ai_suggestion ) {
			wp_send_json_error(
				$this->build_error_response_data( 'empty_ai_suggestion', __( 'Generate an AI suggestion before using Merge with my draft.', 'supportcandy-ai' ), $feature ),
				400
			);
		}

		$ticket_ai_service = $this->get_ticket_ai_service();

		if ( ! $ticket_ai_service || ! method_exists( $ticket_ai_service, 'merge_reply' ) ) {
			wp_send_json_error( $this->build_error_response_data( 'ticket_ai_service_unavailable', __( 'Ticket AI service is unavailable.', 'supportcandy-ai' ), $feature ), 500 );
		}

		$response_options = $this->get_response_options_from_request();

		$this->send_ai_response(
			$ticket_ai_service->merge_reply( $ticket_id, $current_draft, $ai_suggestion, $response_options ),
			$feature,
			array(
				'input_lengths' => array(
					'current_draft' => $this->get_text_length( $current_draft ),
					'ai_suggestion' => $this->get_text_length( $ai_suggestion ),
				),
			)
		);
	}

	/**
	 * Get recent AI conversation history for a ticket.
	 *
	 * @return void
	 */
	public function get_ticket_conversation_history() {
		$feature  = 'conversation_history';
		$verified = $this->verify_request( $feature );

		if ( true !== $verified ) {
			return;
		}

		$ticket_id = $this->get_ticket_id_from_request();

		if ( 0 === $ticket_id ) {
			wp_send_json_error( $this->build_error_response_data( 'invalid_ticket_id', __( 'Invalid ticket ID.', 'supportcandy-ai' ), $feature ), 400 );
		}

		if ( ! $this->current_user_can_run_ai_action( $ticket_id, $feature ) ) {
			wp_send_json_error(
				array(
					'code'    => 'permission_denied',
					'message' => __( 'You do not have permission to view AI history for this ticket.', 'supportcandy-ai' ),
				),
				403
			);
		}

		$limit = isset( $_REQUEST['limit'] ) ? absint( wp_unslash( $_REQUEST['limit'] ) ) : 10;
		$limit = 0 < $limit ? min( 25, $limit ) : 10;

		if ( ! class_exists( 'SCAI_Conversation_Repository' ) ) {
			wp_send_json_error( $this->build_error_response_data( 'conversation_repository_unavailable', __( 'AI conversation history is unavailable.', 'supportcandy-ai' ), $feature ), 500 );
		}

		$records = array();

		try {
			$repository = new SCAI_Conversation_Repository();
			$records    = $repository->get_by_ticket( $ticket_id, array( 'limit' => $limit ) );
		} catch ( Throwable $exception ) {
			wp_send_json_error( $this->build_error_response_data( 'conversation_history_unavailable', __( 'AI conversation history is unavailable.', 'supportcandy-ai' ), $feature ), 500 );
		}

		$items = array();

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$items[] = $this->prepare_conversation_item_for_response( $record );
		}

		wp_send_json_success(
			array(
				'ticket_id' => $ticket_id,
				'items'     => $items,
				'count'     => count( $items ),
			)
		);
	}

	/**
	 * Get ticket AI service instance.
	 *
	 * @return SCAI_Ticket_AI_Service|null
	 */
	private function get_ticket_ai_service() {
		if ( $this->ticket_ai_service instanceof SCAI_Ticket_AI_Service ) {
			return $this->ticket_ai_service;
		}

		if ( ! class_exists( 'SCAI_Ticket_AI_Service' ) ) {
			return null;
		}

		$this->ticket_ai_service = new SCAI_Ticket_AI_Service();

		return $this->ticket_ai_service;
	}

	/**
	 * Verify nonce for AJAX request.
	 *
	 * @param string $feature Feature key.
	 * @return true
	 */
	private function verify_request( $feature ) {
		$feature = sanitize_key( $feature );

		$nonce = isset( $_REQUEST[ self::NONCE_KEY ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::NONCE_KEY ] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( $this->build_error_response_data( 'invalid_nonce', __( 'Security check failed.', 'supportcandy-ai' ), $feature ), 403 );
		}

		return true;
	}

	/**
	 * Check whether the current user can run an AI action for a ticket.
	 *
	 * @param int    $ticket_id Ticket ID.
	 * @param string $feature   Feature key.
	 * @return bool
	 */
	private function current_user_can_run_ai_action( $ticket_id, $feature ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$ticket_id = absint( $ticket_id );
		$feature   = sanitize_key( $feature );

		if ( class_exists( 'SCAI_Permissions' ) ) {
			$permissions = new SCAI_Permissions();

			return (bool) $permissions->current_user_can_use_ai( $ticket_id, $feature );
		}

		return current_user_can( self::CAPABILITY );
	}

	/**
	 * Get ticket ID from request.
	 *
	 * @return int
	 */
	private function get_ticket_id_from_request() {
		return isset( $_REQUEST['ticket_id'] ) ? absint( wp_unslash( $_REQUEST['ticket_id'] ) ) : 0;
	}

	/**
	 * Get sanitized response-writing options from the AJAX request.
	 *
	 * @return array{tone: string, length: string, format: string}
	 */
	private function get_response_options_from_request() {
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
			$value = isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] )
				? sanitize_key( wp_unslash( (string) $_POST[ $key ] ) )
				: '';

			$defaults[ $key ] = in_array( $value, $allowed[ $key ], true ) ? $value : $default;
		}

		return $defaults;
	}

	/**
	 * Prepare a safe, minimal conversation record for a JSON response.
	 *
	 * @param array<string, mixed> $record Conversation record.
	 * @return array<string, int|string>
	 */
	private function prepare_conversation_item_for_response( array $record ) {
		$feature = isset( $record['feature'] ) ? sanitize_key( $record['feature'] ) : '';

		return array(
			'id'              => isset( $record['id'] ) ? absint( $record['id'] ) : 0,
			'conversation_id' => isset( $record['conversation_id'] ) ? sanitize_text_field( $record['conversation_id'] ) : '',
			'role'            => isset( $record['role'] ) ? sanitize_key( $record['role'] ) : '',
			'feature'         => $feature,
			'feature_label'   => $this->get_feature_label( $feature ),
			'content'         => isset( $record['content'] ) ? wp_kses_post( $record['content'] ) : '',
			'provider'        => isset( $record['provider'] ) ? sanitize_text_field( $record['provider'] ) : '',
			'model'           => isset( $record['model'] ) ? sanitize_text_field( $record['model'] ) : '',
			'tokens'          => isset( $record['tokens'] ) ? absint( $record['tokens'] ) : 0,
			'created_at'      => isset( $record['created_at'] ) ? sanitize_text_field( $record['created_at'] ) : '',
		);
	}

	/**
	 * Get a human-readable AI feature label.
	 *
	 * @param string $feature Feature key.
	 * @return string
	 */
	private function get_feature_label( $feature ) {
		$feature = sanitize_key( $feature );
		$labels  = array(
			'ticket_summary'    => __( 'Ticket Summary', 'supportcandy-ai' ),
			'reply_generation'  => __( 'Reply Generation', 'supportcandy-ai' ),
			'reply_improvement' => __( 'Reply Improvement', 'supportcandy-ai' ),
			'reply_merge'       => __( 'Reply Merge', 'supportcandy-ai' ),
		);

		return isset( $labels[ $feature ] ) ? $labels[ $feature ] : $feature;
	}

	/**
	 * Send normalized AI response as JSON.
	 *
	 * @param mixed                $response   AI response.
	 * @param string               $feature    Feature key.
	 * @param array<string, mixed> $extra_data Additional safe response data.
	 * @return void
	 */
	private function send_ai_response( $response, $feature, array $extra_data = array() ) {
		$feature = sanitize_key( $feature );

		if ( ! $response instanceof SCAI_AI_Response ) {
			wp_send_json_error( $this->build_error_response_data( 'invalid_ai_response', __( 'AI service returned an invalid response.', 'supportcandy-ai' ), $feature ), 500 );
		}

		if ( ! $response->is_success() ) {
			$message = $response->get_error_message();
			$code    = $response->get_error_code();

			if ( '' === $message ) {
				$message = __( 'AI request failed.', 'supportcandy-ai' );
			}

			if ( '' === $code ) {
				$code = 'ai_request_failed';
			}

			wp_send_json_error( $this->build_error_response_data( $code, $message, $feature ), 400 );
		}

		wp_send_json_success(
			array_merge(
				array(
					'content'     => wp_kses_post( $response->get_content() ),
					'provider'    => sanitize_key( $response->get_provider() ),
					'model'       => sanitize_text_field( $response->get_model() ),
					'tokens'      => absint( $response->get_total_tokens() ),
					'duration_ms' => absint( $response->get_duration_ms() ),
					'feature'     => $feature,
				),
				$extra_data
			)
		);
	}

	/**
	 * Get a Unicode-aware input length for safe response metadata.
	 *
	 * @param string $text Sanitized input text.
	 * @return int
	 */
	private function get_text_length( $text ) {
		$text = (string) $text;

		return function_exists( 'mb_strlen' ) ? absint( mb_strlen( $text ) ) : absint( strlen( $text ) );
	}

	/**
	 * Build safe error response data.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param string $feature Feature key.
	 * @return array<string, string>
	 */
	private function build_error_response_data( $code, $message, $feature ) {
		return array(
			'message' => sanitize_text_field( $message ),
			'code'    => sanitize_key( $code ),
			'feature' => sanitize_key( $feature ),
		);
	}
}
