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
		$context_text = $this->normalize_multiline_text( $context_text );
		$prompt       = implode(
			"\n",
			array(
				'Review the ticket context and return a concise support-agent summary.',
				'Include:',
				'- Short issue summary',
				'- Customer sentiment',
				'- Important details',
				'- Suggested next action',
				'Use only the ticket context below.',
				'',
				'Ticket context:',
				$context_text,
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
		$context_text = $this->normalize_multiline_text( $context_text );
		$prompt       = implode(
			"\n",
			array(
				'Write a professional support reply for the ticket.',
				'Be clear, helpful, and concise.',
				'Avoid making unsupported promises.',
				'Ask for missing information if needed.',
				'Use ticket context only.',
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
		$reply_text   = $this->normalize_multiline_text( $reply_text );
		$context_text = $this->normalize_multiline_text( $context_text );
		$prompt_parts = array(
			'Improve the provided draft reply.',
			'Keep the original meaning.',
			'Make it professional, clear, and useful for support.',
			'Avoid adding unsupported facts.',
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
