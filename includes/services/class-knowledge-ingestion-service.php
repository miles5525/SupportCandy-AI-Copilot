<?php
/** URL ingestion orchestration for the Custom Knowledge Base. @package SupportCandy_AI */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Converts safe fetched URL text into a custom knowledge repository row. */
final class SCAI_Knowledge_Ingestion_Service {
	private $repository;
	private $fetcher;

	public function __construct( $repository = null, $fetcher = null ) {
		$this->repository = $repository instanceof SCAI_Custom_Knowledge_Repository ? $repository : null;
		$this->fetcher    = $fetcher instanceof SCAI_URL_Content_Fetcher ? $fetcher : null;
	}

	/** Ingest one URL without crawling or making an AI call. */
	public function ingest_url( $url, array $args = array() ) {
		$result = array( 'success' => false, 'id' => 0, 'message' => __( 'The URL source could not be saved.', 'supportcandy-ai' ), 'error_code' => 'save_failed' );
		try {
			$repository = $this->get_repository();
			$fetcher    = $this->get_fetcher();
			if ( ! $repository || ! $repository->table_exists() ) {
				$result['error_code'] = 'repository_unavailable';
				return $result;
			}
			$fetch = $fetcher->fetch( $url );
			if ( empty( $fetch['success'] ) ) {
				$result['error_code'] = isset( $fetch['error_code'] ) ? sanitize_key( $fetch['error_code'] ) : 'fetch_failed';
				$result['message']    = isset( $fetch['message'] ) ? sanitize_text_field( $fetch['message'] ) : $result['message'];
				return $result;
			}

			$args       = wp_parse_args( $args, array( 'title' => '', 'tags' => array(), 'enabled' => true, 'existing_id' => 0 ) );
			$title      = $this->resolve_title( $args['title'], $fetch['title'], $fetch['url'] );
			$tags       = $this->normalize_tags( $args['tags'] );
			$user_id    = get_current_user_id();
			$existing_id= absint( $args['existing_id'] );
			$metadata   = array_merge(
				array( 'schema_version' => 1, 'source_kind' => 'url', 'tags' => $tags, 'extractor' => 'url_content_fetcher', 'updated_by' => $user_id ),
				isset( $fetch['metadata'] ) && is_array( $fetch['metadata'] ) ? $fetch['metadata'] : array()
			);
			$data = array(
				'title' => $title, 'source_url' => $fetch['url'], 'mime_type' => $fetch['mime_type'],
				'content' => $fetch['content'], 'content_hash' => hash( 'sha256', $fetch['content'] ),
				'metadata' => $metadata, 'status' => ! empty( $args['enabled'] ) ? 'active' : 'disabled',
				'last_synced_at' => current_time( 'mysql', true ),
			);

			if ( $existing_id ) {
				$existing = $repository->get( $existing_id );
				if ( ! $existing || 'url' !== $existing['source_type'] ) {
					$result['error_code'] = 'source_not_found';
					return $result;
				}
				$metadata['created_by'] = isset( $existing['metadata']['created_by'] ) ? absint( $existing['metadata']['created_by'] ) : $user_id;
				$data['metadata']       = $metadata;
				$saved = $repository->update( $existing_id, $data );
				$id    = $saved ? $existing_id : 0;
			} else {
				$data['source_type']       = 'url';
				$data['metadata']['created_by'] = $user_id;
				$id = $repository->create( $data );
			}

			return $id ? array( 'success' => true, 'id' => absint( $id ), 'message' => __( 'URL source added.', 'supportcandy-ai' ), 'error_code' => '' ) : $result;
		} catch ( Throwable $exception ) {
			return $result;
		}
	}

	private function get_repository() {
		if ( ! $this->repository && class_exists( 'SCAI_Custom_Knowledge_Repository' ) ) {
			$this->repository = new SCAI_Custom_Knowledge_Repository();
		}
		return $this->repository;
	}

	private function get_fetcher() {
		if ( ! $this->fetcher ) {
			$this->fetcher = new SCAI_URL_Content_Fetcher();
		}
		return $this->fetcher;
	}

	private function resolve_title( $override, $fetched, $url ) {
		$title = is_scalar( $override ) ? sanitize_text_field( (string) $override ) : '';
		if ( '' === $title ) {
			$title = is_scalar( $fetched ) ? sanitize_text_field( (string) $fetched ) : '';
		}
		if ( '' === $title ) {
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
			$title = sanitize_text_field( $host . ( '' !== $path ? ' / ' . $path : '' ) );
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 200 ) : substr( $title, 0, 200 );
	}

	private function normalize_tags( $value ) {
		$items = is_array( $value ) ? $value : explode( ',', is_scalar( $value ) ? (string) $value : '' );
		$tags  = array();
		foreach ( array_slice( $items, 0, 20 ) as $item ) {
			$tag = is_scalar( $item ) ? sanitize_text_field( (string) $item ) : '';
			$tag = function_exists( 'mb_substr' ) ? mb_substr( trim( $tag ), 0, 50 ) : substr( trim( $tag ), 0, 50 );
			if ( '' !== $tag && ! in_array( $tag, $tags, true ) ) {
				$tags[] = $tag;
			}
		}
		return $tags;
	}
}
