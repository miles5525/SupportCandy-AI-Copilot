<?php
/**
 * Deterministic knowledge search service for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Searches read-only BetterDocs knowledge for relevant ticket documentation.
 *
 * The service does not call AI, write search logs, or use BetterDocs internals.
 * Only documents validated by SCAI_BetterDocs_Adapter can be returned.
 */
final class SCAI_Knowledge_Search_Service {

	/**
	 * Maximum ticket text considered for query extraction.
	 *
	 * @var int
	 */
	const MAX_TICKET_TEXT_LENGTH = 8000;

	/**
	 * Maximum generated search query length.
	 *
	 * @var int
	 */
	const MAX_QUERY_LENGTH = 200;

	/**
	 * Search BetterDocs for documents relevant to ticket context.
	 *
	 * @param array $ticket_context Normalized or adapter ticket context.
	 * @param array $args           Search limits and thresholds.
	 * @return array<string, mixed>
	 */
	public function search_for_ticket_context( array $ticket_context, array $args = array() ) {
		$enabled = $this->is_enabled();
		$result  = array(
			'enabled'         => $enabled,
			'available'       => false,
			'query'           => '',
			'candidate_count' => 0,
			'documents'       => array(),
			'count'           => 0,
		);

		if ( ! $enabled ) {
			return $result;
		}

		try {
			$adapter = $this->get_betterdocs_adapter();

			if ( ! $adapter || ! $adapter->is_available() ) {
				return $result;
			}

			$result['available'] = true;
			$query               = $this->build_search_query( $this->extract_ticket_text( $ticket_context ) );
			$result['query']      = $query;

			if ( '' === $query ) {
				return $result;
			}

			$limit               = isset( $args['limit'] ) ? absint( $args['limit'] ) : 3;
			$candidate_limit     = isset( $args['candidate_limit'] ) ? absint( $args['candidate_limit'] ) : 15;
			$content_limit       = isset( $args['content_limit'] ) ? absint( $args['content_limit'] ) : 6000;
			$total_content_limit = isset( $args['total_content_limit'] ) ? absint( $args['total_content_limit'] ) : 12000;
			$min_score           = isset( $args['min_score'] ) && is_numeric( $args['min_score'] ) ? (float) $args['min_score'] : 2.0;

			$limit               = max( 1, min( 5, $limit ) );
			$candidate_limit     = max( $limit, min( 20, max( 1, $candidate_limit ) ) );
			$content_limit       = max( 1, min( 12000, $content_limit ) );
			$total_content_limit = max( 1, min( 30000, $total_content_limit ) );
			$min_score           = max( 0.0, $min_score );
			$terms               = $this->tokenize( $query );
			$adapter_args        = array(
				'limit'           => $candidate_limit,
				'include_content' => true,
				'content_limit'   => $content_limit,
			);
			$documents           = $adapter->search_docs(
				$query,
				$adapter_args
			);

			if ( ! is_array( $documents ) ) {
				$documents = array();
			}

			$documents = $this->deduplicate_documents( $documents, $candidate_limit );

			if ( count( $documents ) < $limit ) {
				foreach ( $this->extract_search_phrases( $query ) as $phrase ) {
					$phrase_documents = $adapter->search_docs( $phrase, $adapter_args );

					if ( is_array( $phrase_documents ) ) {
						$documents = $this->deduplicate_documents( array_merge( $documents, $phrase_documents ), $candidate_limit );
					}

					if ( count( $documents ) >= $candidate_limit ) {
						break;
					}
				}
			}

			if ( empty( $documents ) && method_exists( $adapter, 'get_public_docs' ) ) {
				$public_documents = $adapter->get_public_docs( $adapter_args );

				if ( is_array( $public_documents ) ) {
					$documents = $this->deduplicate_documents( $public_documents, $candidate_limit );
				}
			}

			$result['candidate_count'] = count( $documents );

			$scored = array();

			foreach ( $documents as $document ) {
				if ( ! is_array( $document ) ) {
					continue;
				}

				$score = $this->score_document( $document, $terms, $query );

				if ( $score['score'] < $min_score ) {
					continue;
				}

				$document['score']         = $score['score'];
				$document['matched_terms'] = $score['matched_terms'];
				$scored[]                  = $document;
			}

			usort(
				$scored,
				static function ( $left, $right ) {
					$score_compare = $right['score'] <=> $left['score'];

					if ( 0 !== $score_compare ) {
						return $score_compare;
					}

					return (int) $left['id'] <=> (int) $right['id'];
				}
			);

			$documents          = array_slice( $scored, 0, $limit );
			$result['documents'] = $this->truncate_document_content_budget( $documents, $total_content_limit );
			$result['count']     = count( $result['documents'] );
		} catch ( Throwable $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Knowledge search must fail closed.
			$result['documents'] = array();
			$result['count']     = 0;
		}

		return $result;
	}

	/**
	 * Build a bounded WordPress search query from ticket-derived text.
	 *
	 * Technical phrases, class names, method names, and file names are retained
	 * while common conversational stop words are removed.
	 *
	 * @param mixed $text Ticket-derived text.
	 * @param array $args Reserved query-building options.
	 * @return string
	 */
	public function build_search_query( $text, array $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Reserved for query options.
		$text = $this->normalize_text( $text );

		if ( '' === $text ) {
			return '';
		}

		$lower_text = $this->lowercase( $text );
		$selected   = array();

		$stop_words = array(
			'the', 'and', 'or', 'with', 'from', 'this', 'that', 'your', 'site',
			'issue', 'please', 'hello', 'thanks', 'support', 'customer', 'user',
			'agent', 'ticket', 'reply', 'have', 'has', 'was', 'were', 'are', 'for',
			'but', 'not', 'you', 'our', 'can', 'could', 'would', 'should', 'into',
		);
		$tokens     = preg_split( '/[^\p{L}\p{N}_\\.\\:\/\\-]+/u', $lower_text, -1, PREG_SPLIT_NO_EMPTY );
		$tokens     = is_array( $tokens ) ? $tokens : array();

		foreach ( $tokens as $token ) {
			$token = trim( $token, '.:-/' );

			if ( '' === $token || in_array( $token, $stop_words, true ) ) {
				continue;
			}

			$is_technical = false !== strpos( $token, '_' )
				|| false !== strpos( $token, '\\' )
				|| 1 === preg_match( '/\.(php|js|css|log|txt)$/', $token );

			if ( ! $is_technical && $this->string_length( $token ) < 3 ) {
				continue;
			}

			if ( ! in_array( $token, $selected, true ) ) {
				$selected[] = $token;
			}

			if ( count( $selected ) >= 20 ) {
				break;
			}
		}

		$query = sanitize_text_field( implode( ' ', $selected ) );

		return $this->substring( $query, 0, self::MAX_QUERY_LENGTH );
	}

	/**
	 * Extract bounded searchable text from varying ticket context shapes.
	 *
	 * @param array $ticket_context Ticket context.
	 * @return string
	 */
	protected function extract_ticket_text( array $ticket_context ) {
		$parts     = array();
		$remaining = self::MAX_TICKET_TEXT_LENGTH;
		$ticket    = isset( $ticket_context['ticket'] ) && is_array( $ticket_context['ticket'] ) ? $ticket_context['ticket'] : $ticket_context;

		$this->append_text_fields( $parts, $ticket, array( 'subject', 'title', 'summary' ), $remaining );
		$this->append_text_fields( $parts, $ticket_context, array( 'subject', 'title', 'summary', 'latest_customer_message' ), $remaining );

		$threads = isset( $ticket_context['threads'] ) && is_array( $ticket_context['threads'] ) ? $ticket_context['threads'] : array();

		foreach ( array_slice( $threads, -10 ) as $thread ) {
			if ( $remaining <= 0 ) {
				break;
			}

			if ( is_array( $thread ) ) {
				$this->append_text_fields( $parts, $thread, array( 'body', 'content', 'message', 'text' ), $remaining );
			}
		}

		$attachments = isset( $ticket_context['attachments'] ) && is_array( $ticket_context['attachments'] ) ? $ticket_context['attachments'] : array();

		foreach ( array_slice( $attachments, 0, 10 ) as $attachment ) {
			if ( $remaining <= 0 ) {
				break;
			}

			if ( is_array( $attachment ) ) {
				$this->append_text_fields( $parts, $attachment, array( 'text_excerpt', 'content_excerpt', 'excerpt', 'extracted_text' ), $remaining );
			}
		}

		return $this->substring( $this->normalize_text( implode( ' ', $parts ) ), 0, self::MAX_TICKET_TEXT_LENGTH );
	}

	/**
	 * Score one adapter document deterministically.
	 *
	 * @param array  $document Adapter document.
	 * @param array  $terms    Normalized query terms.
	 * @param string $query    Search query.
	 * @return array{score: float, matched_terms: array<int, string>}
	 */
	protected function score_document( array $document, array $terms, $query ) {
		$title      = $this->lowercase( $this->normalize_text( isset( $document['title'] ) ? $document['title'] : '' ) );
		$excerpt    = $this->lowercase( $this->normalize_text( isset( $document['excerpt'] ) ? $document['excerpt'] : '' ) );
		$content    = $this->lowercase( $this->normalize_text( isset( $document['content'] ) ? $document['content'] : '' ) );
		$taxonomy   = $this->normalize_text( implode( ' ', $this->get_document_term_names( $document ) ) );
		$taxonomy   = $this->lowercase( $taxonomy );
		$query      = $this->lowercase( $this->normalize_text( $query ) );
		$score      = 0.0;
		$matched    = array();

		if ( '' !== $query && false !== strpos( $title, $query ) ) {
			$score += 12.0;
		} elseif ( '' !== $query && ( false !== strpos( $excerpt, $query ) || false !== strpos( $content, $query ) ) ) {
			$score += 4.0;
		}

		foreach ( $this->extract_search_phrases( $query ) as $phrase ) {
			if ( false !== strpos( $title, $phrase ) ) {
				$score    += 8.0;
				$matched[] = $phrase;
			} elseif ( false !== strpos( $excerpt, $phrase ) ) {
				$score    += 4.0;
				$matched[] = $phrase;
			} elseif ( false !== strpos( $content, $phrase ) ) {
				$score    += 2.0;
				$matched[] = $phrase;
			}
		}

		foreach ( array_values( array_unique( $terms ) ) as $term ) {
			if ( '' === $term ) {
				continue;
			}

			$term_matched = false;

			if ( false !== strpos( $title, $term ) ) {
				$score       += 4.0;
				$term_matched = true;
			}

			if ( false !== strpos( $taxonomy, $term ) ) {
				$score       += 3.0;
				$term_matched = true;
			}

			if ( false !== strpos( $excerpt, $term ) ) {
				$score       += 2.0;
				$term_matched = true;
			}

			if ( false !== strpos( $content, $term ) ) {
				$score       += 1.0;
				$term_matched = true;
			}

			if ( $term_matched ) {
				$matched[] = $term;
			}
		}

		return array(
			'score'         => $score,
			'matched_terms' => array_values( array_unique( $matched ) ),
		);
	}

	/**
	 * Tokenize normalized text into unique score terms.
	 *
	 * @param mixed $text Text to tokenize.
	 * @return array<int, string>
	 */
	protected function tokenize( $text ) {
		$text   = $this->lowercase( $this->normalize_text( $text ) );
		$tokens = preg_split( '/[^\p{L}\p{N}_\\.\\:\/\\-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $tokens ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'trim', $tokens ) ) ) );
	}

	/**
	 * Normalize arbitrary scalar text to plain, single-line text.
	 *
	 * @param mixed $text Text to normalize.
	 * @return string
	 */
	protected function normalize_text( $text ) {
		if ( ! is_scalar( $text ) ) {
			return '';
		}

		$text = strip_shortcodes( (string) $text );
		$text = wp_strip_all_tags( $text, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return is_string( $text ) ? trim( $text ) : '';
	}

	/**
	 * Enforce the total content budget across ranked documents.
	 *
	 * @param array $documents          Ranked documents.
	 * @param int   $total_content_limit Total content character budget.
	 * @return array<int, array<string, mixed>>
	 */
	protected function truncate_document_content_budget( array $documents, $total_content_limit ) {
		$remaining = max( 0, absint( $total_content_limit ) );

		foreach ( $documents as &$document ) {
			$content = isset( $document['content'] ) && is_string( $document['content'] ) ? $document['content'] : '';
			$content = $this->substring( $content, 0, $remaining );
			$remaining -= $this->string_length( $content );
			$document['content'] = $content;
		}
		unset( $document );

		return $documents;
	}

	/**
	 * Determine whether BetterDocs knowledge search is enabled.
	 *
	 * @return bool
	 */
	protected function is_enabled() {
		if ( class_exists( 'SCAI_Settings' ) ) {
			return (bool) SCAI_Settings::get( 'enable_betterdocs_kb', false );
		}

		return (bool) get_option( 'scai_enable_betterdocs_kb', 0 );
	}

	/**
	 * Create the BetterDocs adapter when its class is loaded.
	 *
	 * @return SCAI_BetterDocs_Adapter|null
	 */
	protected function get_betterdocs_adapter() {
		if ( ! class_exists( 'SCAI_BetterDocs_Adapter' ) ) {
			return null;
		}

		return new SCAI_BetterDocs_Adapter();
	}

	/**
	 * Extract recognized technical phrases for focused retrieval and scoring.
	 *
	 * @param mixed $text Search text.
	 * @return array<int, string>
	 */
	private function extract_search_phrases( $text ) {
		$text       = $this->lowercase( $this->normalize_text( $text ) );
		$catalog    = array(
			'call to undefined method',
			'fatal error',
			'undefined method',
			'undefined function',
			'debug log',
			'plugin conflict',
		);
		$recognized = array();

		foreach ( $catalog as $phrase ) {
			if ( false !== strpos( $text, $phrase ) ) {
				$recognized[] = $phrase;
			}
		}

		return $recognized;
	}

	/**
	 * Deduplicate adapter documents by post ID and enforce a candidate limit.
	 *
	 * @param array $documents Candidate documents.
	 * @param int   $limit     Maximum candidates.
	 * @return array<int, array<string, mixed>>
	 */
	private function deduplicate_documents( array $documents, $limit ) {
		$deduplicated = array();
		$seen_ids     = array();
		$limit        = max( 1, absint( $limit ) );

		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) || empty( $document['id'] ) ) {
				continue;
			}

			$document_id = absint( $document['id'] );

			if ( ! $document_id || isset( $seen_ids[ $document_id ] ) ) {
				continue;
			}

			$seen_ids[ $document_id ] = true;
			$deduplicated[]            = $document;

			if ( count( $deduplicated ) >= $limit ) {
				break;
			}
		}

		return $deduplicated;
	}

	/**
	 * Append scalar values for known text fields.
	 *
	 * @param array $parts     Extracted text parts.
	 * @param array $source    Source data.
	 * @param array $fields    Candidate field names.
	 * @param int   $remaining Remaining character budget.
	 * @return void
	 */
	private function append_text_fields( array &$parts, array $source, array $fields, &$remaining ) {
		foreach ( $fields as $field ) {
			if ( $remaining <= 0 ) {
				break;
			}

			if ( isset( $source[ $field ] ) && is_scalar( $source[ $field ] ) ) {
				$value = $this->substring( (string) $source[ $field ], 0, $remaining );

				if ( '' !== $value ) {
					$parts[]   = $value;
					$remaining = max( 0, $remaining - $this->string_length( $value ) );
				}
			}
		}
	}

	/**
	 * Get document category, tag, and knowledge base names.
	 *
	 * @param array $document Adapter document.
	 * @return array<int, string>
	 */
	private function get_document_term_names( array $document ) {
		$names = array();

		foreach ( array( 'categories', 'tags', 'knowledge_bases' ) as $key ) {
			if ( isset( $document[ $key ] ) && is_array( $document[ $key ] ) ) {
				$names = array_merge( $names, array_filter( $document[ $key ], 'is_scalar' ) );
			}
		}

		return array_map( 'strval', $names );
	}

	/**
	 * Convert text to lowercase safely.
	 *
	 * @param string $text Text to lowercase.
	 * @return string
	 */
	private function lowercase( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Get a multibyte-safe string length.
	 *
	 * @param string $text Text to measure.
	 * @return int
	 */
	private function string_length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Take a multibyte-safe substring.
	 *
	 * @param string $text   Text to truncate.
	 * @param int    $start  Starting offset.
	 * @param int    $length Maximum character count.
	 * @return string
	 */
	private function substring( $text, $start, $length ) {
		$length = max( 0, absint( $length ) );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, $start, $length, 'UTF-8' );
		}

		return substr( $text, $start, $length );
	}
}
