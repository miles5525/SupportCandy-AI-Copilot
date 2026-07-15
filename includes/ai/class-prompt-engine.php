<?php
/**
 * Prompt engine for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds provider-neutral AI request payloads from prepared context.
 */
final class SCAI_Prompt_Engine {

	/**
	 * Build base system instructions.
	 *
	 * @param array<string, mixed> $args Instruction args.
	 * @return string
	 */
	public function build_system_instructions( array $args = array() ) {
		$attachment_instructions = $this->build_attachment_handling_instructions( $args );
		$speaker_instructions    = $this->build_speaker_role_instructions( $args );
		$instructions = implode(
			"\n",
			array(
				'You are an assistant for support agents.',
				'Help support agents understand tickets and draft useful responses.',
				'Do not pretend to be the customer.',
				'Rely only on the provided ticket context.',
				'Do not invent order IDs, refund promises, technical steps, policy details, or facts not present in the context.',
				'Keep output practical, clear, concise, and ready for support use.',
			)
		);

		$company_instructions = $this->get_company_instructions();

		if ( '' !== $company_instructions ) {
			$instructions .= "\n\nCompany instructions:\n" . $company_instructions;
		}

		if ( '' !== $attachment_instructions ) {
			$instructions .= "\n\nAttachment handling:\n" . $attachment_instructions;
		}

		if ( '' !== $speaker_instructions ) {
			$instructions .= "\n\nConversation roles:\n" . $speaker_instructions;
		}

		if ( ! empty( $args['knowledge_count'] ) ) {
			$instructions .= "\n\nKnowledge base handling:\n" . $this->build_knowledge_handling_instructions();
		}

		/**
		 * Filter prompt engine system instructions.
		 *
		 * @param string               $instructions System instructions.
		 * @param array<string, mixed> $args         Instruction args.
		 */
		$instructions = apply_filters( 'scai_prompt_engine_system_instructions', $instructions, $args );

		return $this->normalize_multiline_text( $instructions );
	}

	/**
	 * Build ticket summary request args.
	 *
	 * @param string               $context_text Readable ticket context.
	 * @param array<string, mixed> $context      Compact context.
	 * @param array<string, mixed> $args         Request args.
	 * @return array<string, mixed>
	 */
	public function build_ticket_summary_request( $context_text, array $context = array(), array $args = array() ) {
		$context_text        = $this->normalize_multiline_text( $context_text );
		$options             = $this->normalize_response_options( $args );
		$length_instructions = array(
			'short'    => 'Keep the internal summary compact and focused on the most important facts.',
			'standard' => 'Use a normal internal support-summary length.',
			'detailed' => 'Provide a fuller internal summary while remaining factual and relevant.',
		);
		$has_knowledge          = $this->has_knowledge_documents( $context );
		$summary_headings       = $has_knowledge
			? array(
				'1. Issue Summary',
				'2. Customer Sentiment',
				'3. Important Details',
				'4. Attachments',
				'5. **Suggested Knowledge References**',
				'6. Suggested Next Action',
			)
			: array(
				'1. Issue Summary',
				'2. Customer Sentiment',
				'3. Important Details',
				'4. Attachments',
				'5. Suggested Next Action',
			);
		$knowledge_instruction = $has_knowledge
			? $this->build_summary_knowledge_reference_instruction( $context )
			: '';
		$prompt                = implode(
			"\n",
			array_merge(
				array(
					'Review the ticket context and return a factual support-agent summary.',
					$length_instructions[ $options['length'] ],
					'Use these section headings in this order:',
				),
				$summary_headings,
				array(
					'When ticket attachments exist, always include the Attachments section and list each attachment separately by filename and type.',
					'For every listed attachment, state whether its content was inspected.',
					'For an inspected text or log attachment, summarize useful facts supported by its provided excerpt.',
					'For an image explicitly included in the current AI request, visually inspect it and describe relevant visible details instead of relying on its metadata-only content_inspected value.',
					'For an uninspected image not included in the current AI request, or an uninspected PDF or other attachment, clearly state that its content was not inspected.',
					'Do not claim an image was inspected unless the request explicitly indicates that image content was provided to the model.',
					'Do not place attachment details only under Suggested Next Action; keep the dedicated Attachments section.',
					'If there are no ticket attachments, the Attachments section may be omitted or say "None mentioned."',
					'Base Customer Sentiment mainly on messages labelled Customer, not on previous Support Agent messages.',
					'Summarize previous agent replies separately only when relevant, and treat Internal Notes as private support context.',
					'Use only the ticket context below.',
					$knowledge_instruction,
					'',
					'Ticket context:',
					$context_text,
				)
			)
		);

		/**
		 * Filter ticket summary prompt.
		 *
		 * @param string               $prompt       Prompt text.
		 * @param string               $context_text Readable context.
		 * @param array<string, mixed> $context      Compact context.
		 * @param array<string, mixed> $args         Request args.
		 */
		$prompt = apply_filters( 'scai_prompt_engine_ticket_summary_prompt', $prompt, $context_text, $context, $args );

		return $this->build_request_args(
			'ticket_summary',
			$prompt,
			$context,
			wp_parse_args(
				$args,
				array(
					'temperature' => 0.2,
					'max_tokens'  => 500,
				)
			)
		);
	}

	/**
	 * Build support reply generation request args.
	 *
	 * @param string               $context_text Readable ticket context.
	 * @param array<string, mixed> $context      Compact context.
	 * @param array<string, mixed> $args         Request args.
	 * @return array<string, mixed>
	 */
	public function build_reply_generation_request( $context_text, array $context = array(), array $args = array() ) {
		$context_text          = $this->normalize_multiline_text( $context_text );
		$style_instructions    = $this->build_response_style_instructions( $args );
		$knowledge_instruction = $this->has_knowledge_documents( $context )
			? 'Use included BetterDocs guidance only when it directly helps; adapt useful troubleshooting naturally and include a raw article URL only when useful.'
			: '';
		$format_instructions  = $this->build_customer_reply_formatting_instructions( false );
		$prompt               = implode(
			"\n",
			array(
				'Write a support reply for the ticket.',
				'Be clear and helpful.',
				'Response style:',
				$style_instructions,
				'Avoid making unsupported promises.',
				'Ask for missing information if needed.',
				'Use relevant facts from inspected text excerpts; do not ask for the same error or tell the agent to review the file unless the excerpt is insufficient.',
				'Do not claim to have seen or read an attachment unless the context says its content was inspected or the image is explicitly included in the current AI request.',
				'Address the latest unresolved Customer message or request; do not reply to a Support Agent message as though it came from the customer.',
				'Use Internal Notes only as private guidance and never reveal or quote them in the customer-facing reply.',
				'If Customer messages show repeated follow-ups or frustration about a delay, acknowledge that politely.',
				'Use ticket context only.',
				$knowledge_instruction,
				'Customer reply formatting:',
				$format_instructions,
				'Return only the reply text.',
				'',
				'Ticket context:',
				$context_text,
			)
		);

		/**
		 * Filter reply generation prompt.
		 *
		 * @param string               $prompt       Prompt text.
		 * @param string               $context_text Readable context.
		 * @param array<string, mixed> $context      Compact context.
		 * @param array<string, mixed> $args         Request args.
		 */
		$prompt = apply_filters( 'scai_prompt_engine_reply_generation_prompt', $prompt, $context_text, $context, $args );

		return $this->build_request_args(
			'reply_generation',
			$prompt,
			$context,
			wp_parse_args(
				$args,
				array(
					'temperature' => 0.4,
					'max_tokens'  => 800,
				)
			)
		);
	}

	/**
	 * Build reply improvement request args.
	 *
	 * @param string               $reply_text   Draft reply text.
	 * @param string               $context_text Optional readable ticket context.
	 * @param array<string, mixed> $context      Compact context.
	 * @param array<string, mixed> $args         Request args.
	 * @return array<string, mixed>
	 */
	public function build_reply_improvement_request( $reply_text, $context_text = '', array $context = array(), array $args = array() ) {
		$reply_text            = $this->normalize_multiline_text( $reply_text );
		$context_text          = $this->normalize_multiline_text( $context_text );
		$style_instructions    = $this->build_response_style_instructions( $args );
		$knowledge_instruction = $this->has_knowledge_documents( $context )
			? 'Blend directly relevant BetterDocs guidance into the draft without changing or overwhelming the draft intent.'
			: '';
		$format_instructions  = $this->build_customer_reply_formatting_instructions( true );
		$prompt_parts         = array(
			'Improve the provided draft reply.',
			'Keep the original meaning.',
			'Make it clear and useful for support.',
			'Response style:',
			$style_instructions,
			'Avoid adding unsupported facts.',
			'Do not add unsupported promises or actions.',
			'Facts in inspected text excerpts and visible content from images explicitly included in the current AI request are supported context; do not add other attachment-content claims.',
			'Use speaker labels to preserve the agent draft intent and distinguish Customer requests from previous Support Agent replies.',
			'Use Internal Notes only as private guidance; do not add or quote their content directly in the customer-facing reply.',
			'If essential information is missing, ask for it rather than guessing.',
			$knowledge_instruction,
			'Customer reply formatting:',
			$format_instructions,
			'Return only the improved reply text.',
			'',
			'Draft reply:',
			$reply_text,
		);

		if ( '' !== $context_text ) {
			$prompt_parts[] = '';
			$prompt_parts[] = 'Ticket context:';
			$prompt_parts[] = $context_text;
		}

		$prompt = implode( "\n", $prompt_parts );

		/**
		 * Filter reply improvement prompt.
		 *
		 * @param string               $prompt       Prompt text.
		 * @param string               $reply_text   Draft reply text.
		 * @param string               $context_text Readable context.
		 * @param array<string, mixed> $context      Compact context.
		 * @param array<string, mixed> $args         Request args.
		 */
		$prompt = apply_filters( 'scai_prompt_engine_reply_improvement_prompt', $prompt, $reply_text, $context_text, $context, $args );

		return $this->build_request_args(
			'reply_improvement',
			$prompt,
			$context,
			wp_parse_args(
				$args,
				array(
					'temperature' => 0.3,
					'max_tokens'  => 700,
				)
			)
		);
	}

	/**
	 * Build a request that merges an agent draft with an AI suggestion.
	 *
	 * @param string               $current_draft Current agent draft.
	 * @param string               $ai_suggestion Generated AI suggestion.
	 * @param string               $context_text  Optional readable ticket context.
	 * @param array<string, mixed> $context       Compact context.
	 * @param array<string, mixed> $args          Request args.
	 * @return array<string, mixed>
	 */
	public function build_reply_merge_request( $current_draft, $ai_suggestion, $context_text = '', array $context = array(), array $args = array() ) {
		$current_draft         = $this->normalize_multiline_text( $current_draft );
		$ai_suggestion         = $this->normalize_multiline_text( $ai_suggestion );
		$context_text          = $this->normalize_multiline_text( $context_text );
		$style_instructions    = $this->build_response_style_instructions( $args );
		$knowledge_instruction = $this->has_knowledge_documents( $context )
			? 'Use directly relevant BetterDocs guidance only as supporting material while preserving the Current Agent Draft intent.'
			: '';
		$format_instructions  = $this->build_customer_reply_formatting_instructions( true );

		if ( '' === $current_draft || '' === $ai_suggestion ) {
			return array();
		}

		$prompt_parts = array(
			'Current Agent Draft is the base reply. AI Suggestion is supporting material only.',
			'',
			'Response style:',
			$style_instructions,
			'',
			'Current Agent Draft:',
			'<<<CURRENT_DRAFT',
			$current_draft,
			'CURRENT_DRAFT',
			'',
			'AI Suggestion:',
			'<<<AI_SUGGESTION',
			$ai_suggestion,
			'AI_SUGGESTION',
			'',
			'Ticket Context:',
			'<<<TICKET_CONTEXT',
			'' !== $context_text ? $context_text : '(No additional ticket context provided.)',
			'TICKET_CONTEXT',
			'',
			'Task:',
			'Create one final polished customer-facing reply by merging the Current Agent Draft with the AI Suggestion.',
			'',
			'Mandatory rules:',
			'1. Use the Current Agent Draft as the base of the final reply.',
			'2. Preserve the meaning of every sentence in the Current Agent Draft unless it is factually unsafe.',
			'3. If the Current Agent Draft contains an apology, delay acknowledgement, promise, next action, or specific wording, keep that intent in the final reply.',
			'4. Use the AI Suggestion only to add useful, accurate details.',
			'5. Do not ignore the Current Agent Draft.',
			'6. Do not return the AI Suggestion alone.',
			'7. Do not simply append both texts.',
			'8. Remove duplication.',
			'9. Keep the reply customer-facing.',
			'10. Do not reveal Internal Notes.',
			'11. Do not add unsupported claims or promises.',
			$knowledge_instruction,
			'12. Follow these customer reply formatting rules:',
			$format_instructions,
			'13. If the Current Agent Draft says "Hi, sorry for the delay. We are checking this issue.", the final reply must retain that delay acknowledgement and checking action in natural wording.',
			'',
			'Silent self-check before finalizing:',
			'- Ensure the final reply contains the key intent from the Current Agent Draft.',
			'- Ensure the final reply contains useful, non-duplicative details from the AI Suggestion.',
			'Return only the final merged reply.',
		);

		$request_args = $this->build_request_args(
			'reply_merge',
			implode( "\n", $prompt_parts ),
			$context,
			wp_parse_args(
				$args,
				array(
					'temperature' => 0.3,
					'max_tokens'  => 800,
				)
			)
		);

		/**
		 * Filter the dedicated reply-merge request.
		 *
		 * @param array<string, mixed> $request_args  Merge request arguments.
		 * @param string               $current_draft Sanitized current draft.
		 * @param string               $ai_suggestion Sanitized AI suggestion.
		 * @param string               $context_text  Sanitized readable context.
		 * @param array<string, mixed> $context       Compact context.
		 * @param array<string, mixed> $args          Source args.
		 */
		$request_args = apply_filters( 'scai_prompt_reply_merge_request', $request_args, $current_draft, $ai_suggestion, $context_text, $context, $args );

		return is_array( $request_args ) ? $this->sanitize_request_args( $request_args ) : array();
	}

	/**
	 * Build concise instructions for interpreting conversation speaker labels.
	 *
	 * @param array<string, mixed> $args Instruction args.
	 * @return string
	 */
	private function build_speaker_role_instructions( array $args = array() ) {
		$instructions = implode(
			"\n",
			array(
				'Messages labelled Customer describe the customer issue, sentiment, follow-ups, and requests.',
				'Messages labelled Support Agent are previous support responses, not customer complaints or follow-ups.',
				'Internal Notes are private support context: use them for reasoning but never quote or reveal them to the customer.',
				'Use System and Unknown Sender messages cautiously and do not infer an unsupported speaker or intent.',
			)
		);

		/**
		 * Filter conversation speaker-role instructions.
		 *
		 * @param string               $instructions Speaker-role instructions.
		 * @param array<string, mixed> $args         Instruction args.
		 */
		$instructions = apply_filters( 'scai_prompt_speaker_role_instructions', $instructions, $args );

		return $this->normalize_multiline_text( $instructions );
	}

	/**
	 * Build strict body-only formatting rules for customer-facing replies.
	 *
	 * @param bool $preserve_draft_signature Whether an existing draft signature may be retained.
	 * @return string
	 */
	private function build_customer_reply_formatting_instructions( $preserve_draft_signature = false ) {
		$signature_rule = $preserve_draft_signature
			? 'Preserve a real closing or signature only when it already appears in the Current Agent Draft; otherwise add no closing or signature.'
			: 'Do not add a closing or signature.';

		return implode(
			"\n",
			array(
				'- Return the customer-facing reply body only, suitable for direct insertion into the SupportCandy reply editor.',
				'- Start directly with the customer-facing message; a natural greeting is allowed.',
				'- Do not include an email subject line or any line beginning with "Subject:" or "Re:".',
				'- Do not add placeholder names or signatures, including "[Your Name]" or "Support Team".',
				'- Do not add closings such as "Best regards", "Regards", or "Thanks and regards".',
				'- ' . $signature_rule,
				'- Use BetterDocs only as supporting context when relevant; do not dump article content or mention BetterDocs unless naturally useful.',
				'- Prefer "we will check", "we will review", or "we will verify" for technical actions the support team should perform.',
				'- Ask the customer to perform technical steps only when necessary or when the ticket clearly requires customer action.',
				'- Do not ask the customer to inspect plugin code unless requesting access, logs, or details is necessary.',
			)
		);
	}

	/**
	 * Build rules for grounding responses in included BetterDocs articles.
	 *
	 * @return string
	 */
	private function build_knowledge_handling_instructions() {
		return implode(
			"\n",
			array(
				'The Knowledge Base Articles section contains public BetterDocs documentation selected as relevant.',
				'Use it only when it directly helps answer the ticket; ticket and customer facts remain the primary source of truth.',
				'Treat BetterDocs articles as supporting guidance and adapt relevant troubleshooting steps to the ticket.',
				'Do not invent documentation or cite or mention articles that are not included.',
				'Do not reveal Internal Notes or claim the customer tried a documentation step unless the ticket says so.',
				'For customer replies, do not include raw article URLs unless they are useful or references are explicitly requested.',
			)
		);
	}

	/**
	 * Build mandatory summary reference instructions from included documents.
	 *
	 * @param array<string, mixed> $context Compact context.
	 * @return string
	 */
	private function build_summary_knowledge_reference_instruction( array $context ) {
		$documents = isset( $context['knowledge_base']['documents'] ) && is_array( $context['knowledge_base']['documents'] )
			? array_slice( $context['knowledge_base']['documents'], 0, 3 )
			: array();
		$titles    = array();

		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) || ! isset( $document['title'] ) || ! is_scalar( $document['title'] ) ) {
				continue;
			}

			$title = sanitize_text_field( (string) $document['title'] );

			if ( '' !== $title ) {
				$titles[] = $title;
			}
		}

		if ( empty( $titles ) ) {
			return '';
		}

		$lines = array(
			'Knowledge Base Articles are included, so the summary MUST contain the "5. **Suggested Knowledge References**" section.',
			'Under that heading, list the following included BetterDocs titles as short bullet points and do not invent, rename, or add references:',
		);

		foreach ( array_values( array_unique( $titles ) ) as $title ) {
			$lines[] = '- ' . $title;
		}

		$lines[] = 'Do not copy article bodies into the reference section; use the included guidance only to support Suggested Next Action.';

		return implode( "\n", $lines );
	}

	/**
	 * Determine whether compact context includes BetterDocs documents.
	 *
	 * @param array<string, mixed> $context Compact context.
	 * @return bool
	 */
	private function has_knowledge_documents( array $context ) {
		return ! empty( $context['knowledge_base']['documents'] ) && is_array( $context['knowledge_base']['documents'] );
	}

	/**
	 * Build instructions for honest handling of attachment metadata.
	 *
	 * @param array<string, mixed> $args Instruction args.
	 * @return string
	 */
	private function build_attachment_handling_instructions( array $args = array() ) {
		$instructions = implode(
			"\n",
			array(
				'Ticket attachments may be listed as metadata, including filename, type, MIME type, URL, and content_inspected.',
				'When content_inspected is true and an excerpt is provided, treat that excerpt as inspected ticket context and use its relevant facts.',
				'Refer to such evidence as "the attached log/text file includes" or "the provided excerpt shows".',
				'Do not tell the agent or customer to review or provide information already present in an inspected excerpt.',
				'If an inspected excerpt is truncated or insufficient, use only what it shows and note that the full file may contain more details.',
				'When content_inspected is false and the attachment is not an image explicitly included in the current AI request, do not claim to have seen, read, analyzed, or understood its content.',
				'For an uninspected attachment, you may mention that it exists and ask for relevant details when needed.',
				'For screenshots or images not included in the current AI request, say the file is attached but its image content has not been inspected.',
				'For PDFs or documents, say the file is attached but its document content has not been inspected.',
				'For logs or text files, use only an inspected excerpt or actual text present in the ticket thread or context.',
				'Do not say "I can see from your screenshot" unless content_inspected is true or that screenshot is explicitly included in the current AI request.',
			)
		);

		/**
		 * Filter attachment-handling prompt instructions.
		 *
		 * @param string               $instructions Attachment instructions.
		 * @param array<string, mixed> $args         Instruction args.
		 */
		$instructions = apply_filters( 'scai_prompt_attachment_instructions', $instructions, $args );

		return $this->normalize_multiline_text( $instructions );
	}

	/**
	 * Build instructions for images included in the current provider request.
	 *
	 * @param array<string, mixed> $args Safe prepared-image metadata.
	 * @return string
	 */
	public function build_image_request_instructions( array $args ) {
		$count     = isset( $args['included_image_count'] ) ? absint( $args['included_image_count'] ) : ( isset( $args['prepared_image_count'] ) ? absint( $args['prepared_image_count'] ) : 0 );
		$filenames = isset( $args['included_image_filenames'] ) && is_array( $args['included_image_filenames'] )
			? array_values( array_filter( array_map( 'sanitize_file_name', $args['included_image_filenames'] ) ) )
			: ( isset( $args['prepared_image_filenames'] ) && is_array( $args['prepared_image_filenames'] )
				? array_values( array_filter( array_map( 'sanitize_file_name', $args['prepared_image_filenames'] ) ) )
				: array() );
		$included = isset( $args['included_images'] ) && is_array( $args['included_images'] )
			? $args['included_images']
			: array();

		if ( empty( $filenames ) && ! empty( $included ) ) {
			foreach ( $included as $image ) {
				if ( is_array( $image ) && ! empty( $image['filename'] ) ) {
					$filenames[] = sanitize_file_name( $image['filename'] );
				}
			}

			$filenames = array_values( array_unique( array_filter( $filenames ) ) );
		}

		if ( 0 === $count || empty( $filenames ) || ( empty( $args['images_attached_to_request'] ) && empty( $args['image_content_provided_to_model'] ) ) ) {
			return '';
		}

		$instructions = sprintf(
			/* translators: %s: comma-separated image filenames. */
			__( 'The following image attachments are included in this AI request for visual inspection: %s.', 'supportcandy-ai' ),
			implode( ', ', $filenames )
		);
		$instructions .= ' ' . __( 'Inspect their visible content and include relevant observations when useful.', 'supportcandy-ai' );
		$instructions .= ' ' . __( 'Attachment metadata may say image content was not previously inspected. For the images listed here, you can visually inspect them now; do not say their content was not inspected unless you genuinely cannot access the image.', 'supportcandy-ai' );
		$instructions .= ' ' . __( 'In a summary Attachments section, mark each included image as "Content inspected: yes, visual inspection included in this AI request" and briefly summarize relevant visible content.', 'supportcandy-ai' );
		$instructions .= ' ' . __( 'Do not claim visual inspection for an image that is not listed here.', 'supportcandy-ai' );
		$instructions .= ' ' . __( 'Mention only details you can actually see, do not invent image details, and do not mention data URLs, base64 data, or local paths.', 'supportcandy-ai' );

		/**
		 * Filter instructions for images attached to the current AI request.
		 *
		 * @param string               $instructions Image request instructions.
		 * @param array<string, mixed> $args         Safe prepared-image metadata.
		 */
		$instructions = apply_filters( 'scai_prompt_image_request_instructions', $instructions, $args );

		return $this->normalize_multiline_text( $instructions );
	}

	/**
	 * Normalize response-writing options.
	 *
	 * @param array<string, mixed> $args Request arguments.
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

		$options = $this->sanitize_response_options( $source );

		/**
		 * Filter normalized AI response-writing options.
		 *
		 * @param array{tone: string, length: string, format: string} $options Normalized options.
		 * @param array<string, mixed>                               $args    Original request arguments.
		 */
		$options = apply_filters( 'scai_prompt_response_options', $options, $args );

		return $this->sanitize_response_options( is_array( $options ) ? $options : array() );
	}

	/**
	 * Build prompt instructions for response-writing options.
	 *
	 * @param array<string, mixed> $args Request arguments.
	 * @return string
	 */
	private function build_response_style_instructions( array $args ) {
		$options = $this->normalize_response_options( $args );
		$tone    = array(
			'professional' => 'Use a professional and clear support tone.',
			'friendly'     => 'Use a friendly, warm, and helpful tone.',
			'empathetic'   => 'Acknowledge the customer\'s frustration and use an empathetic tone.',
			'concise'      => 'Be concise and direct while still being helpful.',
		);
		$length  = array(
			'short'    => 'Keep the reply short, around 2–4 sentences.',
			'standard' => 'Use a normal support reply length.',
			'detailed' => 'Provide a more detailed explanation with useful next steps.',
		);
		$format  = array(
			'plain'        => 'Write a normal customer support reply.',
			'step_by_step' => 'Use clear steps or bullet points when helpful.',
			'technical'    => 'Include technical details when useful, but keep them understandable for the customer.',
		);
		$instructions = implode(
			"\n",
			array(
				'- ' . $tone[ $options['tone'] ],
				'- ' . $length[ $options['length'] ],
				'- ' . $format[ $options['format'] ],
			)
		);

		/**
		 * Filter response style instructions before prompt composition.
		 *
		 * @param string                                                    $instructions Style instructions.
		 * @param array{tone: string, length: string, format: string}       $options      Normalized options.
		 * @param array<string, mixed>                                      $args         Original request arguments.
		 */
		$instructions = apply_filters( 'scai_prompt_style_instructions', $instructions, $options, $args );

		return $this->normalize_multiline_text( $instructions );
	}

	/**
	 * Sanitize response option values against their allowlists.
	 *
	 * @param array<string, mixed> $options Raw response options.
	 * @return array{tone: string, length: string, format: string}
	 */
	private function sanitize_response_options( array $options ) {
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
			$value           = isset( $options[ $key ] ) && is_scalar( $options[ $key ] ) ? sanitize_key( (string) $options[ $key ] ) : '';
			$defaults[ $key ] = in_array( $value, $allowed[ $key ], true ) ? $value : $default;
		}

		return $defaults;
	}

	/**
	 * Build request args compatible with SCAI_AI_Request::from_array().
	 *
	 * @param string               $feature Request feature.
	 * @param string               $prompt  Prompt text.
	 * @param array<string, mixed> $context Compact context.
	 * @param array<string, mixed> $args    Request args.
	 * @return array<string, mixed>
	 */
	private function build_request_args( $feature, $prompt, array $context, array $args ) {
		$feature = sanitize_key( $feature );
		$context = $this->sanitize_context( $context );

		$request_args = array(
			'feature'             => $feature,
			'system_instructions' => $this->build_system_instructions( $args ),
			'prompt'              => $this->normalize_multiline_text( $prompt ),
			'temperature'         => $this->sanitize_temperature( isset( $args['temperature'] ) ? $args['temperature'] : null ),
			'max_tokens'          => $this->sanitize_max_tokens( isset( $args['max_tokens'] ) ? $args['max_tokens'] : null ),
			'context'             => $context,
			'metadata'            => $this->build_metadata( $feature, $context, $args ),
		);

		/**
		 * Filter prompt engine request args.
		 *
		 * @param array<string, mixed> $request_args Request args.
		 * @param string               $feature      Request feature.
		 * @param array<string, mixed> $context      Compact context.
		 * @param array<string, mixed> $args         Source args.
		 */
		$request_args = apply_filters( 'scai_prompt_engine_request_args', $request_args, $feature, $context, $args );

		return is_array( $request_args ) ? $this->sanitize_request_args( $request_args ) : array();
	}

	/**
	 * Get company instructions from settings.
	 *
	 * @return string
	 */
	private function get_company_instructions() {
		if ( ! class_exists( 'SCAI_Settings' ) || ! is_callable( array( 'SCAI_Settings', 'get' ) ) ) {
			return '';
		}

		return $this->normalize_multiline_text( SCAI_Settings::get( 'company_instructions', '' ) );
	}

	/**
	 * Build safe metadata.
	 *
	 * @param string               $feature Request feature.
	 * @param array<string, mixed> $context Compact context.
	 * @param array<string, mixed> $args    Request args.
	 * @return array<string, mixed>
	 */
	private function build_metadata( $feature, array $context, array $args ) {
		$metadata = array(
			'feature' => sanitize_key( $feature ),
		);

		if ( isset( $context['ticket'] ) && is_array( $context['ticket'] ) && isset( $context['ticket']['id'] ) ) {
			$metadata['ticket_id'] = absint( $context['ticket']['id'] );
		}

		if ( isset( $args['metadata'] ) && is_array( $args['metadata'] ) ) {
			$metadata = array_merge( $metadata, $this->sanitize_metadata( $args['metadata'] ) );
		}

		return $this->remove_secret_values( $metadata );
	}

	/**
	 * Sanitize request args after filters.
	 *
	 * @param array<string, mixed> $request_args Request args.
	 * @return array<string, mixed>
	 */
	private function sanitize_request_args( array $request_args ) {
		return array(
			'feature'             => isset( $request_args['feature'] ) ? sanitize_key( $request_args['feature'] ) : '',
			'system_instructions' => isset( $request_args['system_instructions'] ) ? $this->normalize_multiline_text( $request_args['system_instructions'] ) : '',
			'prompt'              => isset( $request_args['prompt'] ) ? $this->normalize_multiline_text( $request_args['prompt'] ) : '',
			'temperature'         => isset( $request_args['temperature'] ) ? $this->sanitize_temperature( $request_args['temperature'] ) : null,
			'max_tokens'          => isset( $request_args['max_tokens'] ) ? $this->sanitize_max_tokens( $request_args['max_tokens'] ) : null,
			'context'             => isset( $request_args['context'] ) && is_array( $request_args['context'] ) ? $this->sanitize_context( $request_args['context'] ) : array(),
			'metadata'            => isset( $request_args['metadata'] ) && is_array( $request_args['metadata'] ) ? $this->sanitize_metadata( $request_args['metadata'] ) : array(),
		);
	}

	/**
	 * Sanitize compact context recursively.
	 *
	 * @param array<string, mixed> $context Context.
	 * @return array<string, mixed>
	 */
	private function sanitize_context( array $context ) {
		return $this->remove_secret_values( $this->sanitize_array_value( $context ) );
	}

	/**
	 * Sanitize metadata recursively.
	 *
	 * @param array<string, mixed> $metadata Metadata.
	 * @return array<string, mixed>
	 */
	private function sanitize_metadata( array $metadata ) {
		return $this->remove_secret_values( $this->sanitize_array_value( $metadata ) );
	}

	/**
	 * Sanitize array values recursively.
	 *
	 * @param array<mixed> $value Raw array.
	 * @return array<string|int, mixed>
	 */
	private function sanitize_array_value( array $value ) {
		$sanitized = array();

		foreach ( $value as $key => $item ) {
			$clean_key = is_string( $key ) ? sanitize_key( $key ) : absint( $key );

			if ( '' === (string) $clean_key ) {
				continue;
			}

			if ( is_array( $item ) ) {
				$sanitized[ $clean_key ] = $this->sanitize_array_value( $item );
				continue;
			}

			if ( is_bool( $item ) || is_int( $item ) || is_float( $item ) || null === $item ) {
				$sanitized[ $clean_key ] = $item;
				continue;
			}

			if ( is_numeric( $item ) ) {
				$sanitized[ $clean_key ] = 0 + $item;
				continue;
			}

			if ( false !== strpos( (string) $clean_key, 'email' ) ) {
				$sanitized[ $clean_key ] = sanitize_email( (string) $item );
				continue;
			}

			$sanitized[ $clean_key ] = $this->normalize_multiline_text( $item );
		}

		return $sanitized;
	}

	/**
	 * Remove secret-looking values from arrays.
	 *
	 * @param array<string|int, mixed> $value Array value.
	 * @return array<string|int, mixed>
	 */
	private function remove_secret_values( array $value ) {
		$clean = array();

		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && $this->is_secret_key( $key ) ) {
				continue;
			}

			$clean[ $key ] = is_array( $item ) ? $this->remove_secret_values( $item ) : $item;
		}

		return $clean;
	}

	/**
	 * Determine whether a key could expose secrets.
	 *
	 * @param string $key Array key.
	 * @return bool
	 */
	private function is_secret_key( $key ) {
		$key = sanitize_key( $key );

		return false !== strpos( $key, 'api_key' )
			|| false !== strpos( $key, 'authorization' )
			|| false !== strpos( $key, 'auth_token' )
			|| false !== strpos( $key, 'secret' )
			|| false !== strpos( $key, 'password' )
			|| false !== strpos( $key, 'provider_config' );
	}

	/**
	 * Normalize multiline text safely.
	 *
	 * @param mixed $text Text value.
	 * @return string
	 */
	private function normalize_multiline_text( $text ) {
		$delimiter_token = '__SCAI_PROMPT_TRIPLE_LESS_THAN__';
		$text            = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
		$text            = str_replace( '<<<', $delimiter_token, $text );
		$text            = wp_strip_all_tags( $text );
		$text            = str_replace( $delimiter_token, '<<<', $text );
		$lines           = preg_split( '/\R/u', $text );

		if ( ! is_array( $lines ) ) {
			return '';
		}

		foreach ( $lines as $index => $line ) {
			$line            = preg_replace( '/[ \t]+/u', ' ', $line );
			$lines[ $index ] = sanitize_textarea_field( is_string( $line ) ? trim( $line ) : '' );
		}

		$text = implode( "\n", $lines );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		$text = str_replace( '&lt;&lt;&lt;', '<<<', $text );

		return is_string( $text ) ? trim( $text ) : '';
	}

	/**
	 * Sanitize temperature.
	 *
	 * @param mixed $value Raw temperature.
	 * @return float|null
	 */
	private function sanitize_temperature( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = (float) $value;

		return max( 0, min( 2, $value ) );
	}

	/**
	 * Sanitize max tokens.
	 *
	 * @param mixed $value Raw max tokens.
	 * @return int|null
	 */
	private function sanitize_max_tokens( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return max( 1, absint( $value ) );
	}
}
