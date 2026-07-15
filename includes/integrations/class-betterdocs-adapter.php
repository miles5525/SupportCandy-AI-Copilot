<?php
/**
 * Read-only BetterDocs integration for SupportCandy AI Assistant.
 *
 * This adapter intentionally uses WordPress core APIs instead of BetterDocs
 * internals. Only published, non-password-protected documents are returned.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides safe, read-only access to BetterDocs documents.
 */
final class SCAI_BetterDocs_Adapter {

	/**
	 * BetterDocs document post type.
	 *
	 * @var string
	 */
	const POST_TYPE = 'docs';

	/**
	 * BetterDocs category taxonomy.
	 *
	 * @var string
	 */
	const CATEGORY_TAXONOMY = 'doc_category';

	/**
	 * BetterDocs tag taxonomy.
	 *
	 * @var string
	 */
	const TAG_TAXONOMY = 'doc_tag';

	/**
	 * Optional BetterDocs knowledge base taxonomy.
	 *
	 * @var string
	 */
	const KNOWLEDGE_BASE_TAXONOMY = 'knowledge_base';

	/**
	 * Determine whether the BetterDocs runtime is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		$has_marker = function_exists( 'betterdocs' )
			|| defined( 'BETTERDOCS_PLUGIN_FILE' )
			|| class_exists( 'WPDeveloper\\BetterDocs\\Plugin' );

		return $has_marker
			&& post_type_exists( self::POST_TYPE )
			&& taxonomy_exists( self::CATEGORY_TAXONOMY );
	}

	/**
	 * Return non-sensitive BetterDocs runtime status information.
	 *
	 * @return array<string, bool>
	 */
	public function get_status() {
		return array(
			'available'                      => $this->is_available(),
			'betterdocs_function_exists'     => function_exists( 'betterdocs' ),
			'betterdocs_constant_defined'    => defined( 'BETTERDOCS_PLUGIN_FILE' ),
			'plugin_class_exists'             => class_exists( 'WPDeveloper\\BetterDocs\\Plugin' ),
			'post_type_exists'                => post_type_exists( self::POST_TYPE ),
			'category_taxonomy_exists'        => taxonomy_exists( self::CATEGORY_TAXONOMY ),
			'tag_taxonomy_exists'             => taxonomy_exists( self::TAG_TAXONOMY ),
			'knowledge_base_taxonomy_exists' => taxonomy_exists( self::KNOWLEDGE_BASE_TAXONOMY ),
		);
	}

	/**
	 * Search published, public BetterDocs documents.
	 *
	 * No BetterDocs search logging, REST endpoints, or internal APIs are used.
	 *
	 * @param string $query Search text.
	 * @param array  $args  Search and document mapping options.
	 * @return array<int, array<string, mixed>>
	 */
	public function search_docs( $query, array $args = array() ) {
		if ( ! $this->is_available() ) {
			return array();
		}

		$query = $this->normalize_search_query( $query );

		if ( '' === $query ) {
			return array();
		}

		$limit      = isset( $args['limit'] ) ? absint( $args['limit'] ) : 5;
		$limit      = min( 20, max( 1, $limit ) );
		$tax_query  = $this->build_tax_query( $args );
		$query_args = array(
			'post_type'              => self::POST_TYPE,
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => $limit,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => true,
			'suppress_filters'       => false,
			's'                      => $query,
			'orderby'                => array(
				'relevance' => 'DESC',
				'date'      => 'DESC',
			),
		);

		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$documents  = array();
		$docs_query = new WP_Query( $query_args );

		foreach ( $docs_query->posts as $post ) {
			if ( $this->is_public_doc( $post ) ) {
				$documents[] = $this->map_post_to_document( $post, $args );
			}
		}

		return $documents;
	}

	/**
	 * Retrieve one published, public BetterDocs document.
	 *
	 * @param int   $post_id Document post ID.
	 * @param array $args    Document mapping options.
	 * @return array<string, mixed>|null
	 */
	public function get_doc( $post_id, array $args = array() ) {
		if ( ! $this->is_available() ) {
			return null;
		}

		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return null;
		}

		$post = get_post( $post_id );

		if ( ! $this->is_public_doc( $post ) ) {
			return null;
		}

		return $this->map_post_to_document( $post, $args );
	}

	/**
	 * Retrieve bounded recent public BetterDocs documents for local scoring.
	 *
	 * This read-only fallback does not use BetterDocs internals or search logs.
	 *
	 * @param array $args Document mapping and limit options.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_public_docs( array $args = array() ) {
		if ( ! $this->is_available() ) {
			return array();
		}

		$limit      = isset( $args['limit'] ) ? absint( $args['limit'] ) : 20;
		$limit      = min( 50, max( 1, $limit ) );
		$query_args = array(
			'post_type'              => self::POST_TYPE,
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => $limit,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => true,
			'suppress_filters'       => false,
			'orderby'                => array(
				'modified' => 'DESC',
				'date'     => 'DESC',
			),
		);
		$documents  = array();
		$docs_query = new WP_Query( $query_args );

		foreach ( $docs_query->posts as $post ) {
			if ( $this->is_public_doc( $post ) ) {
				$documents[] = $this->map_post_to_document( $post, $args );
			}
		}

		return $documents;
	}

	/**
	 * Map a document post to an explicit, safe data array.
	 *
	 * Raw post metadata is intentionally excluded.
	 *
	 * @param WP_Post $post Document post.
	 * @param array   $args Mapping options.
	 * @return array<string, mixed>
	 */
	protected function map_post_to_document( WP_Post $post, array $args = array() ) {
		$include_content = ! isset( $args['include_content'] ) || (bool) $args['include_content'];
		$content_limit   = isset( $args['content_limit'] ) ? absint( $args['content_limit'] ) : 6000;
		$content_limit   = min( 12000, max( 1, $content_limit ) );
		$categories      = $this->get_terms_for_document( $post->ID, self::CATEGORY_TAXONOMY );
		$tags            = $this->get_terms_for_document( $post->ID, self::TAG_TAXONOMY );
		$knowledge_bases = $this->get_terms_for_document( $post->ID, self::KNOWLEDGE_BASE_TAXONOMY );
		$url             = get_permalink( $post );

		return array(
			'id'                 => (int) $post->ID,
			'title'              => $this->clean_content( get_the_title( $post ), 1000 ),
			'url'                => is_string( $url ) ? $url : '',
			'excerpt'            => $this->clean_content( $post->post_excerpt, 2000 ),
			'content'            => $include_content ? $this->clean_content( $post->post_content, $content_limit ) : '',
			'categories'         => $categories['names'],
			'category_ids'       => $categories['ids'],
			'tags'               => $tags['names'],
			'tag_ids'            => $tags['ids'],
			'knowledge_bases'    => $knowledge_bases['names'],
			'knowledge_base_ids' => $knowledge_bases['ids'],
			'modified_gmt'       => (string) $post->post_modified_gmt,
		);
	}

	/**
	 * Check whether a post is a public BetterDocs document.
	 *
	 * @param mixed $post Candidate post.
	 * @return bool
	 */
	protected function is_public_doc( $post ) {
		if ( ! $post instanceof WP_Post
			|| self::POST_TYPE !== $post->post_type
			|| 'publish' !== $post->post_status
			|| '' !== $post->post_password
		) {
			return false;
		}

		return ! function_exists( 'is_post_publicly_viewable' ) || is_post_publicly_viewable( $post );
	}

	/**
	 * Convert document content to bounded plain text without content filters.
	 *
	 * @param string $content Content to clean.
	 * @param int    $limit   Maximum character count.
	 * @return string
	 */
	protected function clean_content( $content, $limit = 6000 ) {
		$content = is_string( $content ) ? $content : '';
		$limit   = max( 1, absint( $limit ) );
		$content = strip_shortcodes( $content );
		$content = preg_replace( '/<!--\s*\/?wp:[\s\S]*?-->/', ' ', $content );
		$content = wp_strip_all_tags( $content, true );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
		$content = preg_replace( '/\s+/u', ' ', $content );
		$content = is_string( $content ) ? trim( $content ) : '';

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $content, 0, $limit );
		}

		return substr( $content, 0, $limit );
	}

	/**
	 * Get document term IDs and names for a registered taxonomy.
	 *
	 * @param int    $post_id  Document post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return array{ids: array<int, int>, names: array<int, string>}
	 */
	protected function get_terms_for_document( $post_id, $taxonomy ) {
		$result = array(
			'ids'   => array(),
			'names' => array(),
		);

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $result;
		}

		$terms = wp_get_post_terms( absint( $post_id ), $taxonomy );

		if ( is_wp_error( $terms ) ) {
			return $result;
		}

		foreach ( $terms as $term ) {
			$result['ids'][]   = (int) $term->term_id;
			$result['names'][] = (string) $term->name;
		}

		return $result;
	}

	/**
	 * Normalize ticket-derived search text.
	 *
	 * The service supplies an unslashed string, so wp_unslash() is intentionally
	 * not applied here to avoid corrupting legitimate backslashes.
	 *
	 * @param mixed $query Search input.
	 * @return string
	 */
	protected function normalize_search_query( $query ) {
		if ( ! is_scalar( $query ) ) {
			return '';
		}

		$query = sanitize_text_field( wp_strip_all_tags( (string) $query ) );
		$query = preg_replace( '/\s+/u', ' ', $query );
		$query = is_string( $query ) ? trim( $query ) : '';

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $query, 0, 300 );
		}

		return substr( $query, 0, 300 );
	}

	/**
	 * Build taxonomy constraints from sanitized term IDs.
	 *
	 * @param array $args Search arguments.
	 * @return array<int|string, mixed>
	 */
	private function build_tax_query( array $args ) {
		$tax_query = array();
		$filters   = array(
			'category_ids'       => self::CATEGORY_TAXONOMY,
			'knowledge_base_ids' => self::KNOWLEDGE_BASE_TAXONOMY,
		);

		foreach ( $filters as $argument => $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) || empty( $args[ $argument ] ) || ! is_array( $args[ $argument ] ) ) {
				continue;
			}

			$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $args[ $argument ] ) ) ) );

			if ( ! empty( $term_ids ) ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_ids,
					'operator' => 'IN',
				);
			}
		}

		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		return $tax_query;
	}
}
