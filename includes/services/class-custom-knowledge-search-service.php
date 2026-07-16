<?php
/**
 * Deterministic Custom Knowledge Base search for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves relevant active custom knowledge without making AI calls.
 */
final class SCAI_Custom_Knowledge_Search_Service {

	/** Maximum ticket-derived text considered for retrieval. */
	const MAX_TICKET_TEXT_LENGTH = 8000;

	/** Maximum generated query length. */
	const MAX_QUERY_LENGTH = 200;

	/**
	 * Optional repository instance.
	 *
	 * @var SCAI_Custom_Knowledge_Repository|null
	 */
	private $repository = null;

	/**
	 * Constructor.
	 *
	 * @param SCAI_Custom_Knowledge_Repository|null $repository Optional repository.
	 */
	public function __construct( $repository = null ) {
		if ( $repository instanceof SCAI_Custom_Knowledge_Repository ) {
			$this->repository = $repository;
		}
	}

	/**
	 * Search custom knowledge for a ticket context.
	 *
	 * @param array<string, mixed> $ticket_context Ticket-like context.
	 * @param array<string, mixed> $args           Search options.
	 * @return array<string, mixed>
	 */
	public function search_for_ticket_context( array $ticket_context, array $args = array() ) {
		return $this->search( $this->extract_ticket_text( $ticket_context ), $args );
	}

	/**
	 * Search active custom knowledge using plain text.
	 *
	 * @param mixed                $text Search text.
	 * @param array<string, mixed> $args Search options.
	 * @return array<string, mixed>
	 */
	public function search( $text, array $args = array() ) {
		$result = array(
			'enabled'         => true,
			'available'       => false,
			'query'           => '',
			'candidate_count' => 0,
			'count'           => 0,
			'documents'       => array(),
		);

		try {
			$repository = $this->get_repository();

			if ( ! $repository ) {
				return $result;
			}

			$result['available'] = true;
			$query               = $this->build_search_query( $text );
			$result['query']      = $query;

			if ( '' === $query ) {
				return $result;
			}

			$options    = $this->normalize_search_args( $args );
			$terms      = $this->extract_terms( $query );
			$candidates = $this->get_candidates( $repository, $terms, $options['candidate_limit'] );
			$scored     = array();

			$result['candidate_count'] = count( $candidates );

			foreach ( $candidates as $candidate ) {
				if ( ! is_array( $candidate ) || 'active' !== ( isset( $candidate['status'] ) ? $candidate['status'] : '' ) ) {
					continue;
				}

				$score = $this->score_document( $candidate, $terms, $query );

				if ( $score['score'] < $options['min_score'] || empty( $score['relevant'] ) ) {
					continue;
				}

				$scored[] = $this->map_document(
					$candidate,
					$score['score'],
					$score['matched_terms'],
					$score['relevance_reason'],
					$options['content_limit']
				);
			}

			usort(
				$scored,
				static function ( $left, $right ) {
					$score_comparison = $right['score'] <=> $left['score'];

					return 0 !== $score_comparison ? $score_comparison : (int) $left['id'] <=> (int) $right['id'];
				}
			);

			$documents           = array_slice( $scored, 0, $options['limit'] );
			$result['documents'] = $this->apply_content_budget( $documents, $options['total_content_limit'] );
			$result['count']     = count( $result['documents'] );
		} catch ( Throwable $exception ) {
			$result['documents'] = array();
			$result['count']     = 0;
		}

		return $result;
	}

	/**
	 * Build a bounded, deterministic search query.
	 *
	 * @param mixed                $text Search text.
	 * @param array<string, mixed> $args Reserved query options.
	 * @return string
	 */
	public function build_search_query( $text, array $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Reserved for future query options.
		$text = $this->normalize_text( $text );

		if ( '' === $text ) {
			return '';
		}

		$terms = $this->extract_terms( $text );
		$query = '';

		foreach ( $terms as $term ) {
			$candidate = '' === $query ? $term : $query . ' ' . $term;

			if ( $this->string_length( $candidate ) > self::MAX_QUERY_LENGTH ) {
				break;
			}

			$query = $candidate;
		}

		return sanitize_text_field( $query );
	}

	/**
	 * Extract bounded searchable text from varying ticket shapes.
	 *
	 * @param array<string, mixed> $ticket_context Ticket context.
	 * @return string
	 */
	protected function extract_ticket_text( array $ticket_context ) {
		$parts     = array();
		$remaining = self::MAX_TICKET_TEXT_LENGTH;
		$ticket    = isset( $ticket_context['ticket'] ) && is_array( $ticket_context['ticket'] ) ? $ticket_context['ticket'] : $ticket_context;

		$this->append_fields( $parts, $ticket, array( 'subject', 'title', 'summary' ), $remaining );
		$this->append_fields( $parts, $ticket_context, array( 'subject', 'title', 'summary', 'latest_customer_message' ), $remaining );

		if ( isset( $ticket_context['latest_customer_message'] ) && is_array( $ticket_context['latest_customer_message'] ) ) {
			$this->append_fields( $parts, $ticket_context['latest_customer_message'], array( 'body', 'content', 'message', 'text' ), $remaining );
		}

		$threads = isset( $ticket_context['threads'] ) && is_array( $ticket_context['threads'] ) ? array_slice( $ticket_context['threads'], -10 ) : array();

		foreach ( $threads as $thread ) {
			if ( $remaining <= 0 ) {
				break;
			}

			if ( is_array( $thread ) ) {
				$this->append_fields( $parts, $thread, array( 'body', 'content', 'message', 'text' ), $remaining );
			}
		}

		$attachments = isset( $ticket_context['attachments'] ) && is_array( $ticket_context['attachments'] ) ? array_slice( $ticket_context['attachments'], 0, 10 ) : array();

		foreach ( $attachments as $attachment ) {
			if ( $remaining <= 0 ) {
				break;
			}

			if ( is_array( $attachment ) ) {
				$this->append_fields( $parts, $attachment, array( 'text_excerpt', 'content_excerpt', 'excerpt', 'extracted_text' ), $remaining );
			}
		}

		return $this->substring( $this->normalize_text( implode( ' ', $parts ) ), 0, self::MAX_TICKET_TEXT_LENGTH );
	}

	/**
	 * Score one repository document deterministically.
	 *
	 * @param array<string, mixed> $document Repository row.
	 * @param array<int, string>   $terms    Search terms and phrases.
	 * @param string               $query    Built query.
	 * @return array{score: float, matched_terms: array<int, string>, relevant: bool, relevance_reason: string}
	 */
	protected function score_document( array $document, array $terms, $query ) {
		$title    = $this->lowercase( $this->normalize_text( isset( $document['title'] ) ? $document['title'] : '' ) );
		$content  = $this->lowercase( $this->normalize_text( isset( $document['content'] ) ? $document['content'] : '' ) );
		$url      = $this->lowercase( $this->normalize_text( isset( $document['source_url'] ) ? $document['source_url'] : '' ) );
		$metadata = isset( $document['metadata'] ) && is_array( $document['metadata'] ) ? $document['metadata'] : array();
		$labels   = array_merge(
			isset( $metadata['tags'] ) && is_array( $metadata['tags'] ) ? $metadata['tags'] : array(),
			isset( $metadata['categories'] ) && is_array( $metadata['categories'] ) ? $metadata['categories'] : array()
		);
		$taxonomy = $this->lowercase( $this->normalize_text( implode( ' ', array_filter( $labels, 'is_scalar' ) ) ) );
		$filename = isset( $metadata['original_filename'] ) && is_scalar( $metadata['original_filename'] )
			? $this->lowercase( sanitize_file_name( (string) $metadata['original_filename'] ) )
			: '';
		$query   = $this->lowercase( $this->normalize_text( $query ) );
		$score   = 0.0;
		$matched = array();
		$strong_title_or_tag_matches = array();
		$non_generic_matches         = array();
		$meaningful_phrase_match     = false;

		if ( '' !== $query && false !== strpos( $title, $query ) && ( ! $this->is_generic_term( $query ) || $this->is_source_specific_generic_phrase( $query ) ) ) {
			$score += 12.0;
		}

		foreach ( array_values( array_unique( $terms ) ) as $term ) {
			$term = $this->lowercase( $this->normalize_text( $term ) );

			if ( '' === $term ) {
				continue;
			}

			$is_phrase    = false !== strpos( $term, ' ' );
			$is_generic   = $this->is_generic_term( $term );
			$is_specific_generic_phrase = $this->is_source_specific_generic_phrase( $term );
			$term_matched = false;
			$title_match  = false !== strpos( $title, $term );
			$tag_match    = false !== strpos( $taxonomy, $term );
			$content_match = false !== strpos( $content, $term );

			if ( $title_match ) {
				$score       += $is_specific_generic_phrase ? 6.0 : ( $is_generic ? 1.0 : ( $is_phrase ? 10.0 : 6.0 ) );
				$term_matched = true;
			}

			if ( $tag_match ) {
				$score       += $is_specific_generic_phrase ? 5.0 : ( $is_generic ? 1.0 : ( $is_phrase ? 8.0 : 5.0 ) );
				$term_matched = true;
			}

			if ( false !== strpos( $url, $term ) || false !== strpos( $filename, $term ) ) {
				$score       += $is_generic ? 0.5 : ( $is_phrase ? 5.0 : 3.0 );
				$term_matched = true;
			}

			if ( $content_match ) {
				$score       += $is_generic ? 0.25 : ( $is_phrase ? 3.0 : 1.0 );
				$term_matched = true;
			}

			if ( $term_matched ) {
				$matched[] = $term;

				if ( ! $is_generic ) {
					$non_generic_matches[] = $term;
				}
				if ( ! $is_generic && ( $title_match || $tag_match ) ) {
					$strong_title_or_tag_matches[] = $term;
				}
				if ( $is_phrase && ( ( ! $is_generic && $content_match ) || ( ( ! $is_generic || $is_specific_generic_phrase ) && ( $title_match || $tag_match ) ) ) ) {
					$meaningful_phrase_match = true;
				}
			}
		}

		$non_generic_matches         = array_values( array_unique( $non_generic_matches ) );
		$strong_title_or_tag_matches = array_values( array_unique( $strong_title_or_tag_matches ) );
		$relevance_reason            = '';

		if ( ! empty( $strong_title_or_tag_matches ) ) {
			$relevance_reason = 'strong_title_or_tag_match';
		} elseif ( count( $non_generic_matches ) >= 2 ) {
			$relevance_reason = 'multiple_domain_term_overlap';
		} elseif ( $meaningful_phrase_match ) {
			$relevance_reason = 'exact_source_specific_phrase';
		} elseif ( $score >= 8.0 && ! empty( $non_generic_matches ) ) {
			$relevance_reason = 'high_score_non_generic_match';
		}

		return array(
			'score'         => $score,
			'matched_terms' => array_values( array_unique( $matched ) ),
			'relevant'      => '' !== $relevance_reason,
			'relevance_reason' => $relevance_reason,
		);
	}

	/**
	 * Normalize arbitrary text to a safe single line.
	 *
	 * @param mixed $text Text.
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
	 * Tokenize normalized text.
	 *
	 * @param mixed $text Text.
	 * @return array<int, string>
	 */
	protected function tokenize( $text ) {
		$text   = $this->lowercase( $this->normalize_text( $text ) );
		$tokens = preg_split( '/[^\p{L}\p{N}_\.\:\/\\-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $tokens ) ? array_values( array_unique( array_filter( array_map( 'trim', $tokens ) ) ) ) : array();
	}

	/**
	 * Extract technical phrases and meaningful unique terms.
	 *
	 * @param mixed $text Text.
	 * @return array<int, string>
	 */
	protected function extract_terms( $text ) {
		$text       = $this->lowercase( $this->normalize_text( $text ) );
		$terms      = array();
		$stop_words = array_merge( array(
			'the', 'and', 'or', 'with', 'from', 'this', 'that', 'your',
			'please', 'hello', 'thanks', 'support', 'customer', 'user', 'agent', 'ticket',
			'reply', 'have', 'has', 'was', 'were', 'are', 'for', 'but', 'not', 'you',
			'our', 'can', 'could', 'would', 'should', 'into', 'when', 'then', 'they',
		), $this->get_generic_terms() );
		$covered_tokens = array();

		foreach ( $this->get_technical_phrases() as $phrase ) {
			if ( false !== strpos( $text, $phrase ) ) {
				$terms[] = $phrase;
				$covered_tokens = array_merge( $covered_tokens, explode( ' ', $phrase ) );
			}
		}
		$covered_tokens = array_values( array_unique( $covered_tokens ) );

		foreach ( $this->tokenize( $text ) as $token ) {
			$token = trim( $token, '.:-/\\' );

			if ( '' === $token || in_array( $token, $stop_words, true ) || in_array( $token, $covered_tokens, true ) ) {
				continue;
			}

			$is_technical = false !== strpos( $token, '_' ) || false !== strpos( $token, '\\' ) || 1 === preg_match( '/\.(php|js|css|log|txt)$/', $token );

			if ( ! $is_technical && $this->string_length( $token ) < 3 ) {
				continue;
			}

			if ( ! in_array( $token, $terms, true ) ) {
				$terms[] = $token;
			}

			if ( count( $terms ) >= 20 ) {
				break;
			}
		}

		return array_slice( array_values( array_unique( $terms ) ), 0, 20 );
	}

	/**
	 * Build a bounded excerpt around the first matched term.
	 *
	 * @param string             $content Content.
	 * @param array<int, string> $terms   Matched terms.
	 * @param int                $limit   Maximum characters.
	 * @return string
	 */
	protected function build_excerpt( $content, array $terms, $limit ) {
		$content = $this->normalize_text( $content );
		$limit   = max( 1, absint( $limit ) );

		if ( $this->string_length( $content ) <= $limit ) {
			return $content;
		}

		$lower    = $this->lowercase( $content );
		$position = false;

		foreach ( $terms as $term ) {
			$position = $this->string_position( $lower, $this->lowercase( $term ) );

			if ( false !== $position ) {
				break;
			}
		}

		$start   = false === $position ? 0 : max( 0, (int) $position - (int) floor( $limit / 3 ) );
		$excerpt = trim( $this->substring( $content, $start, $limit ) );

		$excerpt = ( 0 < $start ? '…' : '' ) . $excerpt . ( $start + $limit < $this->string_length( $content ) ? '…' : '' );

		return $this->substring( $excerpt, 0, $limit );
	}

	/**
	 * Enforce a combined content budget over ranked documents.
	 *
	 * @param array<int, array<string, mixed>> $documents Ranked documents.
	 * @param int                              $budget    Combined character budget.
	 * @return array<int, array<string, mixed>>
	 */
	protected function apply_content_budget( array $documents, $budget ) {
		$remaining = max( 0, absint( $budget ) );
		$bounded   = array();

		foreach ( $documents as $document ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$content             = isset( $document['content'] ) ? $this->substring( $document['content'], 0, $remaining ) : '';
			$document['content'] = $content;
			$document['excerpt'] = $this->substring( isset( $document['excerpt'] ) ? $document['excerpt'] : '', 0, min( 300, $remaining ) );
			$remaining          -= $this->string_length( $content );
			$bounded[]           = $document;
		}

		return $bounded;
	}

	/**
	 * Get an available repository.
	 *
	 * @return SCAI_Custom_Knowledge_Repository|null
	 */
	protected function get_repository() {
		if ( $this->repository instanceof SCAI_Custom_Knowledge_Repository ) {
			return $this->repository->table_exists() ? $this->repository : null;
		}

		if ( ! class_exists( 'SCAI_Custom_Knowledge_Repository' ) ) {
			return null;
		}

		$this->repository = new SCAI_Custom_Knowledge_Repository();

		return $this->repository->table_exists() ? $this->repository : null;
	}

	/**
	 * Query bounded candidate groups and deduplicate by source ID.
	 *
	 * @param SCAI_Custom_Knowledge_Repository $repository Repository.
	 * @param array<int, string>               $terms      Search terms.
	 * @param int                              $limit      Candidate limit.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_candidates( $repository, array $terms, $limit ) {
		$candidates = array();

		foreach ( array_slice( $terms, 0, 10 ) as $term ) {
			$rows = $repository->get_active_candidates( array( 'search_terms' => array( $term ), 'limit' => $limit ) );

			foreach ( $rows as $row ) {
				$id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;

				if ( $id && ! isset( $candidates[ $id ] ) ) {
					$candidates[ $id ] = $row;
				}
			}
		}

		if ( count( $candidates ) < $limit ) {
			$recent = $repository->get_active_candidates( array( 'limit' => $limit ) );

			foreach ( $recent as $row ) {
				$id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;

				if ( $id && ! isset( $candidates[ $id ] ) ) {
					$candidates[ $id ] = $row;
				}

				if ( count( $candidates ) >= $limit ) {
					break;
				}
			}
		}

		return array_slice( array_values( $candidates ), 0, $limit );
	}

	/**
	 * Map a repository row to the public retrieval result shape.
	 *
	 * @param array<string, mixed> $document     Repository row.
	 * @param float                $score        Relevance score.
	 * @param array<int, string>   $matched      Matched terms.
	 * @param string               $relevance_reason Safe relevance diagnostic.
	 * @param int                  $content_limit Per-document limit.
	 * @return array<string, mixed>
	 */
	private function map_document( array $document, $score, array $matched, $relevance_reason, $content_limit ) {
		$metadata   = isset( $document['metadata'] ) && is_array( $document['metadata'] ) ? $document['metadata'] : array();
		$safe_meta  = array(
			'tags'        => $this->sanitize_label_list( isset( $metadata['tags'] ) ? $metadata['tags'] : array() ),
			'categories'  => $this->sanitize_label_list( isset( $metadata['categories'] ) ? $metadata['categories'] : array() ),
			'source_kind' => isset( $metadata['source_kind'] ) && is_scalar( $metadata['source_kind'] ) ? sanitize_key( $metadata['source_kind'] ) : '',
		);
		$content    = $this->build_excerpt( isset( $document['content'] ) ? $document['content'] : '', $matched, $content_limit );

		return array(
			'id'            => isset( $document['id'] ) ? absint( $document['id'] ) : 0,
			'source_type'   => isset( $document['source_type'] ) ? sanitize_key( $document['source_type'] ) : '',
			'title'         => isset( $document['title'] ) ? sanitize_text_field( $document['title'] ) : '',
			'source_url'    => isset( $document['source_url'] ) ? esc_url_raw( $document['source_url'], array( 'http', 'https' ) ) : '',
			'mime_type'     => isset( $document['mime_type'] ) ? sanitize_text_field( $document['mime_type'] ) : '',
			'excerpt'       => $this->substring( $content, 0, min( 300, $content_limit ) ),
			'content'       => $content,
			'score'         => (float) $score,
			'matched_terms' => $this->sanitize_label_list( $matched ),
			'relevance_reason' => sanitize_key( $relevance_reason ),
			'metadata'      => $safe_meta,
			'source'        => 'custom_knowledge',
		);
	}

	/**
	 * Normalize and clamp search arguments.
	 *
	 * @param array<string, mixed> $args Search arguments.
	 * @return array<string, mixed>
	 */
	private function normalize_search_args( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'limit'               => 3,
				'candidate_limit'     => 20,
				'min_score'           => 4,
				'content_limit'       => 3000,
				'total_content_limit' => 8000,
			)
		);

		return array(
			'limit'               => min( 5, max( 1, absint( $args['limit'] ) ) ),
			'candidate_limit'     => min( 50, max( 1, absint( $args['candidate_limit'] ) ) ),
			'min_score'           => max( 0.0, is_numeric( $args['min_score'] ) ? (float) $args['min_score'] : 4.0 ),
			'content_limit'       => min( 10000, max( 1, absint( $args['content_limit'] ) ) ),
			'total_content_limit' => min( 30000, max( 1, absint( $args['total_content_limit'] ) ) ),
		);
	}

	/**
	 * Append selected scalar fields within a shared length budget.
	 *
	 * @param array<int, string>   $parts     Collected text.
	 * @param array<string, mixed> $source    Field source.
	 * @param array<int, string>   $fields    Field names.
	 * @param int                  $remaining Remaining characters, by reference.
	 * @return void
	 */
	private function append_fields( array &$parts, array $source, array $fields, &$remaining ) {
		foreach ( $fields as $field ) {
			if ( $remaining <= 0 || ! isset( $source[ $field ] ) || ! is_scalar( $source[ $field ] ) ) {
				continue;
			}

			$value = $this->substring( $this->normalize_text( $source[ $field ] ), 0, $remaining );

			if ( '' !== $value ) {
				$parts[]   = $value;
				$remaining = max( 0, $remaining - $this->string_length( $value ) );
			}
		}
	}

	/** Get recognized technical phrases in normalized lowercase form. */
	private function get_technical_phrases() {
		return array(
			'fatal error',
			'undefined method',
			'checkout not working',
			'payment gateway',
			'plugin conflict',
			'debug log',
			'javascript error',
			'woocommerce checkout',
		);
	}

	/** Get generic support terms that cannot establish relevance on their own. */
	private function get_generic_terms() {
		return array(
			'issue', 'problem', 'error', 'update', 'plugin', 'recent', 'customer', 'support',
			'troubleshoot', 'troubleshooting', 'conflict', 'debug', 'log', 'logs', 'fatal',
			'php', 'wordpress', 'website', 'site', 'admin', 'page', 'pages',
		);
	}

	/** Determine whether a term or every token in a phrase is generic. */
	private function is_generic_term( $term ) {
		$tokens = preg_split( '/\s+/u', $this->lowercase( $this->normalize_text( $term ) ), -1, PREG_SPLIT_NO_EMPTY );

		if ( empty( $tokens ) ) {
			return true;
		}

		foreach ( $tokens as $token ) {
			if ( ! in_array( $token, $this->get_generic_terms(), true ) ) {
				return false;
			}
		}

		return true;
	}

	/** Identify generic-token phrases that can be specific when present in a title or tag. */
	private function is_source_specific_generic_phrase( $term ) {
		return in_array( $this->lowercase( $this->normalize_text( $term ) ), array( 'fatal error', 'debug log' ), true );
	}

	/** Sanitize a small label list for returned metadata. */
	private function sanitize_label_list( $labels ) {
		if ( ! is_array( $labels ) ) {
			return array();
		}

		$safe = array();

		foreach ( array_slice( $labels, 0, 20 ) as $label ) {
			$label = is_scalar( $label ) ? $this->substring( sanitize_text_field( (string) $label ), 0, 100 ) : '';

			if ( '' !== $label && ! in_array( $label, $safe, true ) ) {
				$safe[] = $label;
			}
		}

		return $safe;
	}

	/** Lowercase a string with multibyte support. */
	private function lowercase( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value ) : strtolower( (string) $value );
	}

	/** Get a substring with multibyte support. */
	private function substring( $value, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, $start, $length ) : substr( (string) $value, $start, $length );
	}

	/** Get string length with multibyte support. */
	private function string_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value ) : strlen( (string) $value );
	}

	/** Find a substring position with multibyte support. */
	private function string_position( $haystack, $needle ) {
		return function_exists( 'mb_strpos' ) ? mb_strpos( (string) $haystack, (string) $needle ) : strpos( (string) $haystack, (string) $needle );
	}
}
