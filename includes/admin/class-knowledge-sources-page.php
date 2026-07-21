<?php
/**
 * Custom Knowledge Base admin page for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages plugin-owned Custom Knowledge Base sources.
 */
final class SCAI_Knowledge_Sources_Page {

	/**
	 * Required capability.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'scai-knowledge-sources';

	/**
	 * Maximum manual source title length.
	 *
	 * @var int
	 */
	const MAX_TITLE_LENGTH = 200;

	/**
	 * Maximum manual source content length.
	 *
	 * @var int
	 */
	const MAX_CONTENT_LENGTH = 100000;

	/**
	 * Maximum number of source tags.
	 *
	 * @var int
	 */
	const MAX_TAGS = 20;

	/**
	 * Maximum source tag length.
	 *
	 * @var int
	 */
	const MAX_TAG_LENGTH = 50;

	/** Sources shown per Existing Sources page. */
	const SOURCES_PER_PAGE = 10;

	/** Maximum Existing Sources search length. */
	const MAX_LIST_SEARCH_LENGTH = 200;

	/**
	 * Register authenticated admin POST handlers.
	 */
	public function __construct() {
		add_action( 'admin_post_scai_add_manual_knowledge_source', array( $this, 'handle_add_manual_source' ) );
		add_action( 'admin_post_scai_add_url_knowledge_source', array( $this, 'handle_add_url_source' ) );
		add_action( 'admin_post_scai_add_file_knowledge_source', array( $this, 'handle_add_file_source' ) );
		add_action( 'admin_post_scai_update_manual_knowledge_source', array( $this, 'handle_update_manual_source' ) );
		add_action( 'admin_post_scai_toggle_knowledge_source_status', array( $this, 'handle_toggle_source_status' ) );
		add_action( 'admin_post_scai_delete_knowledge_source', array( $this, 'handle_delete_source' ) );
	}

	/** Add one uploaded file source. */
	public function handle_add_file_source() {
		$this->authorize_post_action( 'scai_add_file_knowledge_source', 'scai_add_file_source_nonce' );

		if ( ! class_exists( 'SCAI_Knowledge_Ingestion_Service' ) || empty( $_FILES['source_file'] ) || ! is_array( $_FILES['source_file'] ) ) {
			$this->redirect_with_notice( 'invalid_file' );
		}

		$posted = wp_unslash( $_POST );
		$title  = isset( $posted['title'] ) && is_scalar( $posted['title'] ) ? sanitize_text_field( (string) $posted['title'] ) : '';
		$tags   = $this->normalize_tags( isset( $posted['tags'] ) ? $posted['tags'] : '' );

		if ( $this->string_length( $title ) > self::MAX_TITLE_LENGTH || null === $tags ) {
			$this->redirect_with_notice( 'invalid_file' );
		}

		$service = new SCAI_Knowledge_Ingestion_Service();
		$result  = $service->ingest_file(
			$_FILES['source_file'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as a PHP upload by the extractor.
			array(
				'title'   => $title,
				'tags'    => $tags,
				'enabled' => isset( $posted['enabled'] ),
			)
		);

		if ( ! empty( $result['success'] ) ) {
			$this->redirect_with_notice( 'file_source_added' );
		}
		if ( ! empty( $result['id'] ) && isset( $result['status'] ) && 'unsupported' === $result['status'] ) {
			$this->redirect_with_notice( 'pdf_unsupported' );
		}

		$allowed_errors = array( 'invalid_file', 'unsupported_file_type', 'unsafe_file_content', 'invalid_json', 'no_readable_text', 'file_extraction_failed', 'repository_unavailable', 'save_failed' );
		$error_code     = isset( $result['error_code'] ) ? sanitize_key( $result['error_code'] ) : 'file_extraction_failed';
		$this->redirect_with_notice( in_array( $error_code, $allowed_errors, true ) ? $error_code : 'file_extraction_failed' );
	}

	/** Add one public URL source. */
	public function handle_add_url_source() {
		$this->authorize_post_action( 'scai_add_url_knowledge_source', 'scai_add_url_source_nonce' );

		if ( ! class_exists( 'SCAI_Knowledge_Ingestion_Service' ) ) {
			$this->redirect_with_notice( 'repository_unavailable' );
		}

		$posted = wp_unslash( $_POST );
		$url    = isset( $posted['source_url'] ) && is_scalar( $posted['source_url'] ) ? esc_url_raw( trim( (string) $posted['source_url'] ), array( 'http', 'https' ) ) : '';
		$title  = isset( $posted['title'] ) && is_scalar( $posted['title'] ) ? sanitize_text_field( (string) $posted['title'] ) : '';
		$tags   = $this->normalize_tags( isset( $posted['tags'] ) ? $posted['tags'] : '' );

		if ( '' === $url || $this->string_length( $title ) > self::MAX_TITLE_LENGTH || null === $tags ) {
			$this->redirect_with_notice( 'url_rejected' );
		}

		$service = new SCAI_Knowledge_Ingestion_Service();
		$result  = $service->ingest_url(
			$url,
			array(
				'title'   => $title,
				'tags'    => $tags,
				'enabled' => isset( $posted['enabled'] ),
			)
		);

		if ( ! empty( $result['success'] ) ) {
			$this->redirect_with_notice( 'url_source_added' );
		}

		$allowed_errors = array( 'invalid_url', 'url_rejected', 'fetch_failed', 'unsupported_content_type', 'no_readable_text', 'repository_unavailable', 'save_failed' );
		$error_code     = isset( $result['error_code'] ) ? sanitize_key( $result['error_code'] ) : 'fetch_failed';
		$this->redirect_with_notice( in_array( $error_code, $allowed_errors, true ) ? $error_code : 'fetch_failed' );
	}

	/**
	 * Add one manual text source.
	 *
	 * @return void
	 */
	public function handle_add_manual_source() {
		$this->authorize_post_action( 'scai_add_manual_knowledge_source', 'scai_add_manual_source_nonce' );

		$repository = $this->get_available_repository();

		if ( ! $repository ) {
			$this->redirect_with_notice( 'repository_unavailable' );
		}

		$input = $this->get_manual_source_input();

		if ( ! $input ) {
			$this->redirect_with_notice( 'validation_failed' );
		}

		$user_id = get_current_user_id();
		$now     = current_time( 'mysql', true );
		$id      = $repository->create(
			array(
				'source_type'    => 'manual',
				'title'          => $input['title'],
				'content'        => $input['content'],
				'content_hash'   => hash( 'sha256', $input['content'] ),
				'metadata'       => $this->build_manual_metadata( $input['tags'], $user_id, $user_id, strlen( $input['content'] ) ),
				'status'         => $input['status'],
				'last_synced_at' => $now,
			)
		);

		$this->redirect_with_notice( $id ? 'source_added' : 'save_failed' );
	}

	/**
	 * Update one manual text source.
	 *
	 * @return void
	 */
	public function handle_update_manual_source() {
		$source_id = $this->get_posted_source_id();
		$this->authorize_post_action( 'scai_update_manual_knowledge_source_' . $source_id, 'scai_update_manual_source_nonce' );

		$repository = $this->get_available_repository();

		if ( ! $repository ) {
			$this->redirect_with_notice( 'repository_unavailable' );
		}

		$source    = $source_id ? $repository->get( $source_id ) : null;

		if ( ! $source || 'manual' !== $source['source_type'] ) {
			$this->redirect_with_notice( 'source_not_found' );
		}

		$input = $this->get_manual_source_input();

		if ( ! $input ) {
			$this->redirect_with_notice( 'validation_failed', array( 'source_id' => $source_id, 'action' => 'edit' ) );
		}

		$created_by = isset( $source['metadata']['created_by'] ) ? absint( $source['metadata']['created_by'] ) : get_current_user_id();
		$updated    = $repository->update(
			$source_id,
			array(
				'title'          => $input['title'],
				'content'        => $input['content'],
				'content_hash'   => hash( 'sha256', $input['content'] ),
				'metadata'       => $this->build_manual_metadata(
					$input['tags'],
					$created_by,
					get_current_user_id(),
					strlen( $input['content'] )
				),
				'status'         => $input['status'],
				'last_synced_at' => current_time( 'mysql', true ),
			)
		);

		$this->redirect_with_notice( $updated ? 'source_updated' : 'save_failed' );
	}

	/**
	 * Toggle one custom source between active and disabled.
	 *
	 * @return void
	 */
	public function handle_toggle_source_status() {
		$source_id = $this->get_posted_source_id();
		$this->authorize_post_action( 'scai_toggle_knowledge_source_status_' . $source_id, 'scai_toggle_source_status_nonce' );

		$repository = $this->get_available_repository();

		if ( ! $repository ) {
			$this->redirect_with_notice( 'repository_unavailable' );
		}

		$source    = $source_id ? $repository->get( $source_id ) : null;

		if ( ! $source || ! in_array( $source['status'], array( 'active', 'disabled' ), true ) ) {
			$this->redirect_with_notice( 'source_not_found' );
		}

		$new_status = 'active' === $source['status'] ? 'disabled' : 'active';

		if ( 'active' === $new_status && '' === trim( $source['content'] ) ) {
			$this->redirect_with_notice( 'validation_failed' );
		}

		$updated = $repository->update_status(
			$source_id,
			$new_status,
			array( 'updated_by' => get_current_user_id() )
		);

		if ( ! $updated ) {
			$this->redirect_with_notice( 'save_failed' );
		}

		$this->redirect_with_notice( 'active' === $new_status ? 'source_enabled' : 'source_disabled' );
	}

	/**
	 * Delete one custom source.
	 *
	 * @return void
	 */
	public function handle_delete_source() {
		$source_id = $this->get_posted_source_id();
		$this->authorize_post_action( 'scai_delete_knowledge_source_' . $source_id, 'scai_delete_source_nonce' );

		$repository = $this->get_available_repository();

		if ( ! $repository ) {
			$this->redirect_with_notice( 'repository_unavailable' );
		}

		$source    = $source_id ? $repository->get( $source_id ) : null;

		if ( ! $source ) {
			$this->redirect_with_notice( 'source_not_found' );
		}

		$this->redirect_with_notice( $repository->delete( $source_id ) ? 'source_deleted' : 'delete_failed' );
	}

	/**
	 * Render the Knowledge Sources page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'supportcandy-ai' ),
				esc_html__( 'Permission denied', 'supportcandy-ai' ),
				array( 'response' => 403 )
			);
		}

		$repository    = $this->get_available_repository();
		$counts        = $this->get_empty_counts();
		$sources       = array();
		$editing       = null;
		$deleting      = null;
		$source_type   = $this->get_list_source_type();
		$list_search   = $this->get_list_search();
		$current_page  = $this->get_list_page();
		$total_sources = 0;
		$total_pages   = 1;

		if ( $repository ) {
			try {
				$counts        = array_merge( $counts, $repository->count_by_status() );
				$list_args     = array( 'source_type' => $source_type, 'search' => $list_search );
				$total_sources = $repository->count( $list_args );
				$total_pages   = max( 1, (int) ceil( $total_sources / self::SOURCES_PER_PAGE ) );
				$current_page  = min( $current_page, $total_pages );
				$sources       = $repository->list(
					array_merge(
						$list_args,
						array(
							'per_page' => self::SOURCES_PER_PAGE,
							'page'     => $current_page,
							'orderby'  => 'updated_at',
							'order'    => 'DESC',
						)
					)
				);

				$requested_action = $this->get_query_action();
				$requested_id     = $this->get_query_source_id();

				if ( $requested_id && in_array( $requested_action, array( 'edit', 'delete' ), true ) ) {
					$requested_source = $repository->get( $requested_id );

					if ( $requested_source && 'edit' === $requested_action && 'manual' === $requested_source['source_type'] ) {
						$editing = $requested_source;
					} elseif ( $requested_source && 'delete' === $requested_action ) {
						$deleting = $requested_source;
					}
				}
			} catch ( Throwable $exception ) {
				$repository    = null;
				$counts        = $this->get_empty_counts();
				$sources       = array();
				$total_sources = 0;
				$total_pages   = 1;
			}
		}

		?>
		<div class="wrap scai-admin-page scai-knowledge-sources-wrap">
			<section class="scai-knowledge-hero">
				<h1><?php echo esc_html__( 'Custom Knowledge Base', 'supportcandy-ai' ); ?></h1>
				<p><?php echo esc_html__( 'Add trusted knowledge sources that AI can use as supporting context when helping agents respond to tickets.', 'supportcandy-ai' ); ?></p>
				<p><strong><?php echo esc_html__( 'This does not train the AI model. It stores searchable knowledge that can be retrieved and sent as context when agents use AI.', 'supportcandy-ai' ); ?></strong></p>
			</section>

			<?php $this->render_notice(); ?>

			<?php if ( $this->has_invalid_requested_source( $repository, $editing, $deleting ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html__( 'The requested source was not found or cannot be managed with that action.', 'supportcandy-ai' ); ?></p></div>
			<?php endif; ?>

			<section class="scai-knowledge-card scai-knowledge-overview">
				<h2><?php echo esc_html__( 'Knowledge Sources', 'supportcandy-ai' ); ?></h2>
				<p><?php echo esc_html__( 'Manage custom searchable knowledge for retrieval-augmented generation.', 'supportcandy-ai' ); ?></p>
				<ul class="scai-knowledge-counts">
					<li><strong><?php echo esc_html__( 'Active:', 'supportcandy-ai' ); ?></strong> <?php echo esc_html( (string) absint( $counts['active'] ) ); ?></li>
					<li><strong><?php echo esc_html__( 'Disabled:', 'supportcandy-ai' ); ?></strong> <?php echo esc_html( (string) absint( $counts['disabled'] ) ); ?></li>
					<li><strong><?php echo esc_html__( 'Pending:', 'supportcandy-ai' ); ?></strong> <?php echo esc_html( (string) absint( $counts['pending'] ) ); ?></li>
					<li><strong><?php echo esc_html__( 'Errors:', 'supportcandy-ai' ); ?></strong> <?php echo esc_html( (string) absint( $counts['error'] ) ); ?></li>
					<li><strong><?php echo esc_html__( 'Unsupported:', 'supportcandy-ai' ); ?></strong> <?php echo esc_html( (string) absint( $counts['unsupported'] ) ); ?></li>
					<li><strong><?php echo esc_html__( 'Total:', 'supportcandy-ai' ); ?></strong> <?php echo esc_html( (string) absint( $counts['total'] ) ); ?></li>
				</ul>
				<?php if ( ! $repository ) : ?>
					<p class="scai-knowledge-status-note"><?php echo esc_html__( 'Knowledge table is not available. Reactivate the plugin to recreate required tables.', 'supportcandy-ai' ); ?></p>
				<?php endif; ?>
			</section>

			<?php if ( $deleting ) : ?>
				<?php $this->render_delete_confirmation( $deleting ); ?>
			<?php elseif ( $editing ) : ?>
				<?php $this->render_manual_source_form( $editing, (bool) $repository ); ?>
			<?php else : ?>
				<?php $this->render_add_source_section( (bool) $repository ); ?>
			<?php endif; ?>

			<?php $this->render_sources_list( $sources, $source_type, $list_search, $current_page, $total_pages, $total_sources ); ?>

			<section class="scai-knowledge-warning">
				<h2><?php echo esc_html__( 'RAG safety', 'supportcandy-ai' ); ?></h2>
				<ul>
					<li><?php echo esc_html__( 'Ticket facts remain the primary source of truth.', 'supportcandy-ai' ); ?></li>
					<li><?php echo esc_html__( 'Custom knowledge is used only as supporting context.', 'supportcandy-ai' ); ?></li>
					<li><?php echo esc_html__( 'AI replies must be reviewed before sending.', 'supportcandy-ai' ); ?></li>
					<li><?php echo esc_html__( 'Do not add secrets, API keys, passwords, or private customer data as knowledge sources.', 'supportcandy-ai' ); ?></li>
				</ul>
			</section>
		</div>
		<?php
	}

	/** Render the unified source-type selector and add forms. */
	private function render_add_source_section( $repository_available ) {
		?>
		<section class="scai-knowledge-card scai-knowledge-form-card scai-knowledge-add-source">
			<h2><?php echo esc_html__( 'Add Knowledge Source', 'supportcandy-ai' ); ?></h2>
			<p class="scai-knowledge-source-selector">
				<label for="scai-knowledge-source-type"><strong><?php echo esc_html__( 'Source Type', 'supportcandy-ai' ); ?></strong></label>
				<select id="scai-knowledge-source-type">
					<option value="manual"><?php echo esc_html__( 'Manual Text', 'supportcandy-ai' ); ?></option>
					<option value="url"><?php echo esc_html__( 'URL', 'supportcandy-ai' ); ?></option>
					<option value="file"><?php echo esc_html__( 'File Upload', 'supportcandy-ai' ); ?></option>
				</select>
			</p>

			<?php $this->render_manual_source_form( null, $repository_available, true ); ?>
			<?php $this->render_url_source_form( $repository_available ); ?>
			<?php $this->render_file_source_form( $repository_available ); ?>
		</section>
		<script>
			(function () {
				var selector = document.getElementById('scai-knowledge-source-type');
				var container = selector ? selector.closest('.scai-knowledge-add-source') : null;

				if (!selector || !container) {
					return;
				}

				function showSelectedSourceType() {
					var panels = container.querySelectorAll('[data-scai-source-type]');

					for (var index = 0; index < panels.length; index++) {
						panels[index].hidden = panels[index].getAttribute('data-scai-source-type') !== selector.value;
					}
				}

				selector.addEventListener('change', showSelectedSourceType);
				showSelectedSourceType();
			}());
		</script>
		<?php
	}

	/** Render the bounded File Source upload form. */
	private function render_file_source_form( $repository_available ) {
		?>
		<div class="scai-knowledge-source-panel" data-scai-source-type="file">
			<p><?php echo esc_html__( 'Add a safe text-like file as searchable knowledge. Supported now: TXT, Markdown, CSV, LOG, JSON. PDF upload is accepted only when a safe text extractor is available.', 'supportcandy-ai' ); ?></p>
			<form class="scai-knowledge-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="scai_add_file_knowledge_source" />
				<input type="hidden" name="MAX_FILE_SIZE" value="2097152" />
				<?php wp_nonce_field( 'scai_add_file_knowledge_source', 'scai_add_file_source_nonce' ); ?>
				<p>
					<label for="scai-file-source-file"><strong><?php echo esc_html__( 'File upload', 'supportcandy-ai' ); ?></strong></label>
					<input id="scai-file-source-file" type="file" name="source_file" accept=".txt,.md,.markdown,.csv,.log,.json,.pdf,text/plain,text/markdown,text/csv,application/json,application/pdf" required <?php disabled( ! $repository_available ); ?> />
					<span class="description"><?php echo esc_html__( 'Maximum file size: 2 MB.', 'supportcandy-ai' ); ?></span>
				</p>
				<p>
					<label for="scai-file-source-title"><strong><?php echo esc_html__( 'Title override', 'supportcandy-ai' ); ?></strong></label>
					<input id="scai-file-source-title" class="regular-text" type="text" name="title" maxlength="<?php echo esc_attr( (string) self::MAX_TITLE_LENGTH ); ?>" <?php disabled( ! $repository_available ); ?> />
					<span class="description"><?php echo esc_html__( 'Optional. The safe filename is used when this is empty.', 'supportcandy-ai' ); ?></span>
				</p>
				<p>
					<label for="scai-file-source-tags"><strong><?php echo esc_html__( 'Tags/categories', 'supportcandy-ai' ); ?></strong></label>
					<input id="scai-file-source-tags" class="regular-text" type="text" name="tags" maxlength="1100" <?php disabled( ! $repository_available ); ?> />
					<span class="description"><?php echo esc_html__( 'Optional. Enter up to 20 comma-separated tags.', 'supportcandy-ai' ); ?></span>
				</p>
				<p><label><input type="checkbox" name="enabled" value="1" checked <?php disabled( ! $repository_available ); ?> /> <?php echo esc_html__( 'Enable this source for AI retrieval', 'supportcandy-ai' ); ?></label></p>
				<?php submit_button( __( 'Add File Source', 'supportcandy-ai' ), 'primary', 'submit', false, $repository_available ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
		</div>
		<?php
	}

	/** Render the single-page URL Source form. */
	private function render_url_source_form( $repository_available ) {
		?>
		<div class="scai-knowledge-source-panel" data-scai-source-type="url">
			<p><?php echo esc_html__( 'Add one public page as searchable knowledge. Only one public page is indexed. This does not crawl the full website.', 'supportcandy-ai' ); ?></p>
			<form class="scai-knowledge-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="scai_add_url_knowledge_source" />
				<?php wp_nonce_field( 'scai_add_url_knowledge_source', 'scai_add_url_source_nonce' ); ?>
				<p>
					<label for="scai-url-source-url"><strong><?php echo esc_html__( 'URL', 'supportcandy-ai' ); ?></strong></label>
					<input id="scai-url-source-url" class="large-text" type="url" name="source_url" required placeholder="https://example.com/help/article" <?php disabled( ! $repository_available ); ?> />
				</p>
				<p>
					<label for="scai-url-source-title"><strong><?php echo esc_html__( 'Title override', 'supportcandy-ai' ); ?></strong></label>
					<input id="scai-url-source-title" class="regular-text" type="text" name="title" maxlength="<?php echo esc_attr( (string) self::MAX_TITLE_LENGTH ); ?>" <?php disabled( ! $repository_available ); ?> />
					<span class="description"><?php echo esc_html__( 'Optional. The page title is used when this is empty.', 'supportcandy-ai' ); ?></span>
				</p>
				<p>
					<label for="scai-url-source-tags"><strong><?php echo esc_html__( 'Tags/categories', 'supportcandy-ai' ); ?></strong></label>
					<input id="scai-url-source-tags" class="regular-text" type="text" name="tags" maxlength="1100" placeholder="billing, refunds, troubleshooting" <?php disabled( ! $repository_available ); ?> />
					<span class="description"><?php echo esc_html__( 'Optional. Enter up to 20 comma-separated tags.', 'supportcandy-ai' ); ?></span>
				</p>
				<p><label><input type="checkbox" name="enabled" value="1" checked <?php disabled( ! $repository_available ); ?> /> <?php echo esc_html__( 'Enable this source for AI retrieval', 'supportcandy-ai' ); ?></label></p>
				<?php submit_button( __( 'Add URL Source', 'supportcandy-ai' ), 'primary', 'submit', false, $repository_available ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the manual source add/edit form.
	 *
	 * @param array<string, mixed>|null $source               Source being edited.
	 * @param bool                      $repository_available Whether storage is available.
	 * @param bool                      $as_panel             Whether to render inside the unified add section.
	 * @return void
	 */
	private function render_manual_source_form( $source, $repository_available, $as_panel = false ) {
		$is_editing = is_array( $source );
		$title      = $is_editing ? $source['title'] : '';
		$content    = $is_editing ? $source['content'] : '';
		$tags       = $is_editing && isset( $source['metadata']['tags'] ) && is_array( $source['metadata']['tags'] )
			? implode( ', ', $source['metadata']['tags'] )
			: '';
		$enabled    = ! $is_editing || 'active' === $source['status'];
		$action     = $is_editing ? 'scai_update_manual_knowledge_source' : 'scai_add_manual_knowledge_source';
		?>
		<?php if ( $as_panel ) : ?>
			<div class="scai-knowledge-source-panel" data-scai-source-type="manual">
		<?php else : ?>
			<section class="scai-knowledge-card scai-knowledge-form-card">
				<h2><?php echo esc_html__( 'Edit Manual Source', 'supportcandy-ai' ); ?></h2>
		<?php endif; ?>
			<p><?php echo esc_html__( 'This does not train the AI model. It stores searchable text that can be retrieved and sent as supporting context when agents use AI.', 'supportcandy-ai' ); ?></p>

			<form class="scai-knowledge-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
				<?php if ( $is_editing ) : ?>
					<input type="hidden" name="source_id" value="<?php echo esc_attr( (string) absint( $source['id'] ) ); ?>" />
					<?php $this->render_current_list_hidden_fields(); ?>
					<?php wp_nonce_field( 'scai_update_manual_knowledge_source_' . absint( $source['id'] ), 'scai_update_manual_source_nonce' ); ?>
				<?php else : ?>
					<?php wp_nonce_field( 'scai_add_manual_knowledge_source', 'scai_add_manual_source_nonce' ); ?>
				<?php endif; ?>

				<p>
					<label for="scai-manual-source-title"><strong><?php echo esc_html__( 'Title', 'supportcandy-ai' ); ?></strong></label>
					<input id="scai-manual-source-title" class="regular-text" type="text" name="title" value="<?php echo esc_attr( $title ); ?>" maxlength="<?php echo esc_attr( (string) self::MAX_TITLE_LENGTH ); ?>" required <?php disabled( ! $repository_available ); ?> />
				</p>

				<p>
					<label for="scai-manual-source-content"><strong><?php echo esc_html__( 'Content', 'supportcandy-ai' ); ?></strong></label>
					<textarea id="scai-manual-source-content" class="large-text" name="content" rows="12" maxlength="<?php echo esc_attr( (string) self::MAX_CONTENT_LENGTH ); ?>" required <?php disabled( ! $repository_available ); ?>><?php echo esc_textarea( $content ); ?></textarea>
				</p>

				<p>
					<label for="scai-manual-source-tags"><strong><?php echo esc_html__( 'Tags/categories', 'supportcandy-ai' ); ?></strong></label>
					<input id="scai-manual-source-tags" class="regular-text" type="text" name="tags" value="<?php echo esc_attr( $tags ); ?>" maxlength="1100" placeholder="<?php echo esc_attr__( 'billing, refunds, troubleshooting', 'supportcandy-ai' ); ?>" <?php disabled( ! $repository_available ); ?> />
					<span class="description"><?php echo esc_html__( 'Optional. Enter up to 20 comma-separated tags.', 'supportcandy-ai' ); ?></span>
				</p>

				<p>
					<label>
						<input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?> <?php disabled( ! $repository_available ); ?> />
						<?php echo esc_html__( 'Enable this source for AI retrieval', 'supportcandy-ai' ); ?>
					</label>
				</p>

				<div class="scai-knowledge-actions">
					<?php submit_button( $is_editing ? __( 'Save Manual Source', 'supportcandy-ai' ) : __( 'Add Manual Source', 'supportcandy-ai' ), 'primary', 'submit', false, $repository_available ? array() : array( 'disabled' => 'disabled' ) ); ?>
					<?php if ( $is_editing ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( $this->get_current_list_url() ); ?>"><?php echo esc_html__( 'Cancel', 'supportcandy-ai' ); ?></a>
					<?php endif; ?>
				</div>
			</form>
		<?php if ( $as_panel ) : ?>
			</div>
		<?php else : ?>
			</section>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a delete confirmation form.
	 *
	 * @param array<string, mixed> $source Source to delete.
	 * @return void
	 */
	private function render_delete_confirmation( array $source ) {
		?>
		<section class="scai-knowledge-card scai-knowledge-delete-confirmation">
			<h2><?php echo esc_html__( 'Delete Knowledge Source', 'supportcandy-ai' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: Knowledge source title. */
						__( 'Permanently delete “%s”? This action cannot be undone.', 'supportcandy-ai' ),
						$source['title']
					)
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="scai_delete_knowledge_source" />
				<input type="hidden" name="source_id" value="<?php echo esc_attr( (string) absint( $source['id'] ) ); ?>" />
				<?php $this->render_current_list_hidden_fields(); ?>
				<?php wp_nonce_field( 'scai_delete_knowledge_source_' . absint( $source['id'] ), 'scai_delete_source_nonce' ); ?>
				<div class="scai-knowledge-actions">
					<?php submit_button( __( 'Delete Source', 'supportcandy-ai' ), 'delete scai-knowledge-danger', 'submit', false ); ?>
					<a class="button button-secondary" href="<?php echo esc_url( $this->get_current_list_url() ); ?>"><?php echo esc_html__( 'Cancel', 'supportcandy-ai' ); ?></a>
				</div>
			</form>
		</section>
		<?php
	}

	/**
	 * Render the custom source list.
	 *
	 * @param array<int, array<string, mixed>> $sources       Sources.
	 * @param string                          $source_type   Active source-type filter.
	 * @param string                          $search        Active search query.
	 * @param int                             $current_page Current page.
	 * @param int                             $total_pages  Total pages.
	 * @param int                             $total_sources Total matching sources.
	 * @return void
	 */
	private function render_sources_list( array $sources, $source_type, $search, $current_page, $total_pages, $total_sources ) {
		?>
		<section class="scai-knowledge-card scai-knowledge-existing">
			<h2><?php echo esc_html__( 'Existing Sources', 'supportcandy-ai' ); ?></h2>
			<form class="scai-knowledge-list-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<label for="scai-source-type-filter" class="screen-reader-text"><?php echo esc_html__( 'Filter by source type', 'supportcandy-ai' ); ?></label>
				<select id="scai-source-type-filter" name="source_type">
					<option value=""><?php echo esc_html__( 'All', 'supportcandy-ai' ); ?></option>
					<option value="manual" <?php selected( $source_type, 'manual' ); ?>><?php echo esc_html__( 'Manual', 'supportcandy-ai' ); ?></option>
					<option value="url" <?php selected( $source_type, 'url' ); ?>><?php echo esc_html__( 'URL', 'supportcandy-ai' ); ?></option>
					<option value="file" <?php selected( $source_type, 'file' ); ?>><?php echo esc_html__( 'File', 'supportcandy-ai' ); ?></option>
				</select>
				<label for="scai-source-search" class="screen-reader-text"><?php echo esc_html__( 'Search knowledge sources', 'supportcandy-ai' ); ?></label>
				<input id="scai-source-search" type="search" name="source_search" value="<?php echo esc_attr( $search ); ?>" maxlength="<?php echo esc_attr( (string) self::MAX_LIST_SEARCH_LENGTH ); ?>" placeholder="<?php echo esc_attr__( 'Search title, URL, or content', 'supportcandy-ai' ); ?>" />
				<button class="button" type="submit"><?php echo esc_html__( 'Filter', 'supportcandy-ai' ); ?></button>
				<?php if ( '' !== $source_type || '' !== $search ) : ?>
					<a class="button" href="<?php echo esc_url( $this->get_page_url() ); ?>"><?php echo esc_html__( 'Clear', 'supportcandy-ai' ); ?></a>
				<?php endif; ?>
			</form>

			<p class="scai-knowledge-list-summary">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: Number of matching knowledge sources. */
						_n( '%d matching source', '%d matching sources', absint( $total_sources ), 'supportcandy-ai' ),
						absint( $total_sources )
					)
				);
				?>
			</p>

			<?php if ( empty( $sources ) ) : ?>
				<div class="scai-knowledge-empty">
					<p><?php echo esc_html__( 'No knowledge sources match the current filters.', 'supportcandy-ai' ); ?></p>
				</div>
			<?php else : ?>
				<div class="scai-knowledge-list-wrap">
					<table class="widefat striped scai-knowledge-list">
						<thead>
							<tr>
								<th scope="col"><?php echo esc_html__( 'Title', 'supportcandy-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Type', 'supportcandy-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Status', 'supportcandy-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Tags', 'supportcandy-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Content size', 'supportcandy-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Last updated', 'supportcandy-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Actions', 'supportcandy-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $sources as $source ) : ?>
								<?php $this->render_source_row( $source ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php $this->render_sources_pagination( $source_type, $search, $current_page, $total_pages ); ?>
		</section>
		<?php
	}

	/** Render server-side Existing Sources pagination. */
	private function render_sources_pagination( $source_type, $search, $current_page, $total_pages ) {
		if ( $total_pages < 2 ) {
			return;
		}

		$base_args = array( 'page' => self::PAGE_SLUG );

		if ( '' !== $source_type ) {
			$base_args['source_type'] = $source_type;
		}

		if ( '' !== $search ) {
			$base_args['source_search'] = $search;
		}

		$pagination_placeholder   = 999999999;
		$base_args['source_page'] = $pagination_placeholder;
		$pagination_base          = str_replace(
			(string) $pagination_placeholder,
			'%#%',
			add_query_arg( $base_args, admin_url( 'admin.php' ) )
		);
		$links = paginate_links(
			array(
				'base'      => $pagination_base,
				'format'    => '',
				'current'   => max( 1, absint( $current_page ) ),
				'total'     => max( 1, absint( $total_pages ) ),
				'mid_size'  => 2,
				'prev_text' => __( '&laquo; Previous', 'supportcandy-ai' ),
				'next_text' => __( 'Next &raquo;', 'supportcandy-ai' ),
				'type'      => 'list',
			)
		);

		if ( is_string( $links ) && '' !== $links ) {
			?>
			<nav class="scai-knowledge-pagination" aria-label="<?php echo esc_attr__( 'Knowledge source pages', 'supportcandy-ai' ); ?>">
				<?php echo wp_kses_post( $links ); ?>
			</nav>
			<?php
		}
	}

	/**
	 * Render one safe source-list row.
	 *
	 * @param array<string, mixed> $source Source row.
	 * @return void
	 */
	private function render_source_row( array $source ) {
		$tags       = isset( $source['metadata']['tags'] ) && is_array( $source['metadata']['tags'] ) ? array_slice( $source['metadata']['tags'], 0, self::MAX_TAGS ) : array();
		$excerpt    = preg_replace( '/\s+/u', ' ', $source['content'] );
		$excerpt    = $this->substring( is_string( $excerpt ) ? trim( $excerpt ) : '', 0, 160 );
		$list_args  = $this->get_current_list_query_args();
		$edit_url   = add_query_arg( array_merge( $list_args, array( 'source_id' => absint( $source['id'] ), 'action' => 'edit' ) ), admin_url( 'admin.php' ) );
		$delete_url = add_query_arg( array_merge( $list_args, array( 'source_id' => absint( $source['id'] ), 'action' => 'delete' ) ), admin_url( 'admin.php' ) );
		?>
		<tr>
			<td>
				<strong><?php echo esc_html( $source['title'] ); ?></strong>
				<?php if ( 'url' === $source['source_type'] && ! empty( $source['source_url'] ) ) : ?>
					<div><a href="<?php echo esc_url( $source['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $source['source_url'] ); ?></a></div>
				<?php endif; ?>
				<?php if ( 'file' === $source['source_type'] && ! empty( $source['metadata']['original_filename'] ) ) : ?>
					<div class="scai-knowledge-muted"><?php echo esc_html( sanitize_file_name( $source['metadata']['original_filename'] ) ); ?></div>
				<?php endif; ?>
				<?php if ( '' !== $excerpt ) : ?>
					<div class="scai-knowledge-excerpt"><?php echo esc_html( $excerpt ); ?><?php echo $this->string_length( $source['content'] ) > 160 ? esc_html( '…' ) : ''; ?></div>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $this->get_source_type_label( $source['source_type'] ) ); ?></td>
			<td><span class="scai-knowledge-status scai-knowledge-status-<?php echo esc_attr( $source['status'] ); ?>"><?php echo esc_html( ucfirst( $source['status'] ) ); ?></span></td>
			<td>
				<?php if ( empty( $tags ) ) : ?>
					<span class="scai-knowledge-muted"><?php echo esc_html__( 'None', 'supportcandy-ai' ); ?></span>
				<?php else : ?>
					<?php foreach ( $tags as $tag ) : ?>
						<span class="scai-knowledge-tag"><?php echo esc_html( $tag ); ?></span>
					<?php endforeach; ?>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( size_format( strlen( $source['content'] ) ) ); ?></td>
			<td><?php echo esc_html( $this->format_utc_datetime( $source['updated_at'] ) ); ?></td>
			<td>
				<div class="scai-knowledge-actions">
					<?php if ( 'manual' === $source['source_type'] ) : ?>
						<a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html__( 'Edit', 'supportcandy-ai' ); ?></a>
					<?php elseif ( 'url' === $source['source_type'] ) : ?>
						<span class="scai-knowledge-muted"><?php echo esc_html__( 'Edit/re-index coming next', 'supportcandy-ai' ); ?></span>
					<?php endif; ?>
					<?php if ( in_array( $source['status'], array( 'active', 'disabled' ), true ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="scai_toggle_knowledge_source_status" />
							<input type="hidden" name="source_id" value="<?php echo esc_attr( (string) absint( $source['id'] ) ); ?>" />
							<?php $this->render_current_list_hidden_fields(); ?>
							<?php wp_nonce_field( 'scai_toggle_knowledge_source_status_' . absint( $source['id'] ), 'scai_toggle_source_status_nonce' ); ?>
							<button class="button button-small" type="submit"><?php echo 'active' === $source['status'] ? esc_html__( 'Disable', 'supportcandy-ai' ) : esc_html__( 'Enable', 'supportcandy-ai' ); ?></button>
						</form>
					<?php endif; ?>
					<a class="button button-small scai-knowledge-danger" href="<?php echo esc_url( $delete_url ); ?>"><?php echo esc_html__( 'Delete', 'supportcandy-ai' ); ?></a>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a safe notice selected by query key.
	 *
	 * @return void
	 */
	private function render_notice() {
		$key = isset( $_GET['scai_notice'] ) && is_scalar( $_GET['scai_notice'] ) ? sanitize_key( wp_unslash( $_GET['scai_notice'] ) ) : '';
		$map = array(
			'source_added'           => array( 'success', __( 'Manual text source added.', 'supportcandy-ai' ) ),
			'source_updated'         => array( 'success', __( 'Manual text source updated.', 'supportcandy-ai' ) ),
			'url_source_added'       => array( 'success', __( 'URL source added.', 'supportcandy-ai' ) ),
			'file_source_added'      => array( 'success', __( 'File source added.', 'supportcandy-ai' ) ),
			'pdf_unsupported'        => array( 'warning', __( 'The PDF was recorded as unsupported because no approved text extractor is available. Upload a TXT or Markdown version instead.', 'supportcandy-ai' ) ),
			'invalid_file'           => array( 'error', __( 'Select a valid non-empty file no larger than 2 MB.', 'supportcandy-ai' ) ),
			'unsupported_file_type' => array( 'error', __( 'This file type is not supported.', 'supportcandy-ai' ) ),
			'unsafe_file_content'   => array( 'error', __( 'The file appears binary or does not contain safe readable text.', 'supportcandy-ai' ) ),
			'invalid_json'          => array( 'error', __( 'The JSON file could not be safely decoded.', 'supportcandy-ai' ) ),
			'file_extraction_failed'=> array( 'error', __( 'The file could not be safely processed.', 'supportcandy-ai' ) ),
			'invalid_url'            => array( 'error', __( 'Enter a valid public HTTP or HTTPS URL.', 'supportcandy-ai' ) ),
			'url_rejected'           => array( 'error', __( 'The URL was rejected because it is invalid, private, or local.', 'supportcandy-ai' ) ),
			'fetch_failed'           => array( 'error', __( 'The public page could not be fetched.', 'supportcandy-ai' ) ),
			'unsupported_content_type' => array( 'error', __( 'The URL returned an unsupported content type.', 'supportcandy-ai' ) ),
			'no_readable_text'       => array( 'error', __( 'No readable text was found on the page.', 'supportcandy-ai' ) ),
			'source_enabled'         => array( 'success', __( 'Knowledge source enabled.', 'supportcandy-ai' ) ),
			'source_disabled'        => array( 'success', __( 'Knowledge source disabled.', 'supportcandy-ai' ) ),
			'source_deleted'         => array( 'success', __( 'Knowledge source deleted.', 'supportcandy-ai' ) ),
			'validation_failed'      => array( 'error', __( 'Validation failed. Check the title, content, tags, and allowed lengths.', 'supportcandy-ai' ) ),
			'permission_denied'      => array( 'error', __( 'You do not have permission to manage knowledge sources.', 'supportcandy-ai' ) ),
			'source_not_found'       => array( 'error', __( 'The requested knowledge source was not found.', 'supportcandy-ai' ) ),
			'repository_unavailable' => array( 'error', __( 'Knowledge storage is unavailable. Reactivate the plugin to recreate required tables.', 'supportcandy-ai' ) ),
			'save_failed'            => array( 'error', __( 'The knowledge source could not be saved.', 'supportcandy-ai' ) ),
			'delete_failed'          => array( 'error', __( 'The knowledge source could not be deleted.', 'supportcandy-ai' ) ),
		);

		if ( ! isset( $map[ $key ] ) ) {
			return;
		}

		?>
		<div class="notice notice-<?php echo esc_attr( $map[ $key ][0] ); ?> is-dismissible"><p><?php echo esc_html( $map[ $key ][1] ); ?></p></div>
		<?php
	}

	/**
	 * Verify capability, POST request method, and action-specific nonce.
	 *
	 * @param string $nonce_action Nonce action.
	 * @param string $nonce_name   Nonce field name.
	 * @return void
	 */
	private function authorize_post_action( $nonce_action, $nonce_name ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage knowledge sources.', 'supportcandy-ai' ),
				esc_html__( 'Permission denied', 'supportcandy-ai' ),
				array( 'response' => 403 )
			);
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'POST' !== $request_method ) {
			wp_die(
				esc_html__( 'This action requires a POST request.', 'supportcandy-ai' ),
				esc_html__( 'Invalid request', 'supportcandy-ai' ),
				array( 'response' => 405 )
			);
		}

		check_admin_referer( $nonce_action, $nonce_name );
	}

	/**
	 * Read and validate manual source form input.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_manual_source_input() {
		$posted      = wp_unslash( $_POST );
		$raw_title   = isset( $posted['title'] ) && is_scalar( $posted['title'] ) ? (string) $posted['title'] : '';
		$raw_content = isset( $posted['content'] ) && is_scalar( $posted['content'] ) ? (string) $posted['content'] : '';
		$title       = sanitize_text_field( $raw_title );
		$content     = $this->normalize_plain_text( $raw_content );
		$tags        = $this->normalize_tags( isset( $posted['tags'] ) ? $posted['tags'] : '' );

		if ( '' === $title || '' === $content || null === $tags ) {
			return null;
		}

		if ( $this->string_length( $title ) > self::MAX_TITLE_LENGTH || $this->string_length( $content ) > self::MAX_CONTENT_LENGTH ) {
			return null;
		}

		return array(
			'title'   => $title,
			'content' => $content,
			'tags'    => $tags,
			'status'  => isset( $posted['enabled'] ) ? 'active' : 'disabled',
		);
	}

	/**
	 * Normalize submitted content to bounded plain text.
	 *
	 * @param mixed $content Raw content.
	 * @return string
	 */
	private function normalize_plain_text( $content ) {
		if ( ! is_scalar( $content ) ) {
			return '';
		}

		$content = strip_shortcodes( (string) $content );
		$content = wp_strip_all_tags( $content, true );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
		$content = str_replace( array( "\r\n", "\r" ), "\n", $content );
		$content = preg_replace( '/[^\P{C}\n\t]/u', '', $content );
		$content = preg_replace( '/[ \t]+/u', ' ', is_string( $content ) ? $content : '' );
		$content = preg_replace( "/\n{3,}/", "\n\n", is_string( $content ) ? $content : '' );

		return is_string( $content ) ? trim( $content ) : '';
	}

	/**
	 * Normalize a comma-separated tag list.
	 *
	 * @param mixed $value Raw tag input.
	 * @return array<int, string>|null
	 */
	private function normalize_tags( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$tags = array();

		$raw_tags = explode( ',', (string) $value );

		foreach ( $raw_tags as $tag ) {
			$tag = sanitize_text_field( $tag );
			$tag = trim( $tag );

			if ( $this->string_length( $tag ) > self::MAX_TAG_LENGTH ) {
				return null;
			}

			if ( '' !== $tag && ! in_array( $tag, $tags, true ) ) {
				$tags[] = $tag;
			}

			if ( count( $tags ) > self::MAX_TAGS ) {
				return null;
			}
		}

		return $tags;
	}

	/**
	 * Build the allow-listed manual source metadata object.
	 *
	 * @param array<int, string> $tags           Tags.
	 * @param int                $created_by     Creator user ID.
	 * @param int                $updated_by     Updater user ID.
	 * @param int                $content_length Content byte length.
	 * @return array<string, mixed>
	 */
	private function build_manual_metadata( array $tags, $created_by, $updated_by, $content_length ) {
		return array(
			'schema_version' => 1,
			'tags'           => array_slice( $tags, 0, self::MAX_TAGS ),
			'source_kind'    => 'manual_text',
			'created_by'     => absint( $created_by ),
			'updated_by'     => absint( $updated_by ),
			'content_length' => absint( $content_length ),
		);
	}

	/**
	 * Get an available repository instance.
	 *
	 * @return SCAI_Custom_Knowledge_Repository|null
	 */
	private function get_available_repository() {
		if ( ! class_exists( 'SCAI_Custom_Knowledge_Repository' ) ) {
			return null;
		}

		try {
			$repository = new SCAI_Custom_Knowledge_Repository();

			return $repository->table_exists() ? $repository : null;
		} catch ( Throwable $exception ) {
			return null;
		}
	}

	/**
	 * Get a submitted source ID.
	 *
	 * @return int
	 */
	private function get_posted_source_id() {
		return isset( $_POST['source_id'] ) && is_scalar( $_POST['source_id'] ) ? absint( wp_unslash( $_POST['source_id'] ) ) : 0;
	}

	/**
	 * Get a requested source ID.
	 *
	 * @return int
	 */
	private function get_query_source_id() {
		return isset( $_GET['source_id'] ) && is_scalar( $_GET['source_id'] ) ? absint( wp_unslash( $_GET['source_id'] ) ) : 0;
	}

	/**
	 * Get a requested page action.
	 *
	 * @return string
	 */
	private function get_query_action() {
		return isset( $_GET['action'] ) && is_scalar( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	}

	/** Get the allow-listed Existing Sources type filter. */
	private function get_list_source_type() {
		$source_type = isset( $_GET['source_type'] ) && is_scalar( $_GET['source_type'] ) ? sanitize_key( wp_unslash( $_GET['source_type'] ) ) : '';

		return in_array( $source_type, array( 'manual', 'url', 'file' ), true ) ? $source_type : '';
	}

	/** Get the bounded Existing Sources search query. */
	private function get_list_search() {
		$search = isset( $_GET['source_search'] ) && is_scalar( $_GET['source_search'] )
			? sanitize_text_field( wp_unslash( $_GET['source_search'] ) )
			: '';

		return $this->substring( trim( $search ), 0, self::MAX_LIST_SEARCH_LENGTH );
	}

	/** Get the sanitized Existing Sources page number. */
	private function get_list_page() {
		return isset( $_GET['source_page'] ) && is_scalar( $_GET['source_page'] ) ? max( 1, absint( wp_unslash( $_GET['source_page'] ) ) ) : 1;
	}

	/** Get current allow-listed Existing Sources query arguments. */
	private function get_current_list_query_args() {
		$args        = array( 'page' => self::PAGE_SLUG );
		$source_type = $this->get_list_source_type();
		$search      = $this->get_list_search();
		$page        = $this->get_list_page();

		if ( '' !== $source_type ) {
			$args['source_type'] = $source_type;
		}

		if ( '' !== $search ) {
			$args['source_search'] = $search;
		}

		if ( $page > 1 ) {
			$args['source_page'] = $page;
		}

		return $args;
	}

	/** Get a URL preserving current Existing Sources filters and pagination. */
	private function get_current_list_url() {
		return add_query_arg( $this->get_current_list_query_args(), admin_url( 'admin.php' ) );
	}

	/** Render hidden allow-listed list state for row actions. */
	private function render_current_list_hidden_fields() {
		foreach ( $this->get_current_list_query_args() as $key => $value ) {
			if ( 'page' === $key ) {
				continue;
			}
			?>
			<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" />
			<?php
		}
	}

	/** Get sanitized list state submitted by a row action. */
	private function get_posted_list_query_args() {
		$args = array();
		$type = isset( $_POST['source_type'] ) && is_scalar( $_POST['source_type'] ) ? sanitize_key( wp_unslash( $_POST['source_type'] ) ) : '';

		if ( in_array( $type, array( 'manual', 'url', 'file' ), true ) ) {
			$args['source_type'] = $type;
		}

		$search = isset( $_POST['source_search'] ) && is_scalar( $_POST['source_search'] )
			? sanitize_text_field( wp_unslash( $_POST['source_search'] ) )
			: '';
		$search = $this->substring( trim( $search ), 0, self::MAX_LIST_SEARCH_LENGTH );

		if ( '' !== $search ) {
			$args['source_search'] = $search;
		}

		$page = isset( $_POST['source_page'] ) && is_scalar( $_POST['source_page'] ) ? absint( wp_unslash( $_POST['source_page'] ) ) : 0;

		if ( $page > 1 ) {
			$args['source_page'] = $page;
		}

		return $args;
	}

	/**
	 * Determine whether a requested edit/delete target was invalid.
	 *
	 * @param SCAI_Custom_Knowledge_Repository|null $repository Repository.
	 * @param array<string, mixed>|null             $editing    Edit source.
	 * @param array<string, mixed>|null             $deleting   Delete source.
	 * @return bool
	 */
	private function has_invalid_requested_source( $repository, $editing, $deleting ) {
		$action = $this->get_query_action();

		return $repository
			&& in_array( $action, array( 'edit', 'delete' ), true )
			&& ! $editing
			&& ! $deleting;
	}

	/**
	 * Redirect to the page with a safe notice key.
	 *
	 * @param string               $notice     Notice key.
	 * @param array<string, mixed> $extra_args Extra safe query arguments.
	 * @return void
	 */
	private function redirect_with_notice( $notice, array $extra_args = array() ) {
		$extra_args = array_merge( $this->get_posted_list_query_args(), $extra_args );
		$args = array_merge(
			array( 'page' => self::PAGE_SLUG, 'scai_notice' => sanitize_key( $notice ) ),
			$extra_args
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Get the Knowledge Sources page URL.
	 *
	 * @return string
	 */
	private function get_page_url() {
		return add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );
	}

	/**
	 * Get zeroed status counts.
	 *
	 * @return array<string, int>
	 */
	private function get_empty_counts() {
		return array(
			'active'      => 0,
			'disabled'    => 0,
			'pending'     => 0,
			'error'       => 0,
			'unsupported' => 0,
			'total'       => 0,
		);
	}

	/**
	 * Get a display label for a custom source type.
	 *
	 * @param string $source_type Source type.
	 * @return string
	 */
	private function get_source_type_label( $source_type ) {
		$labels = array(
			'manual' => __( 'Manual Text', 'supportcandy-ai' ),
			'url'    => __( 'URL', 'supportcandy-ai' ),
			'file'   => __( 'File', 'supportcandy-ai' ),
		);

		return isset( $labels[ $source_type ] ) ? $labels[ $source_type ] : __( 'Custom', 'supportcandy-ai' );
	}

	/**
	 * Format a stored UTC datetime for display.
	 *
	 * @param string $datetime UTC MySQL datetime.
	 * @return string
	 */
	private function format_utc_datetime( $datetime ) {
		if ( ! is_string( $datetime ) || '' === $datetime ) {
			return __( 'Not available', 'supportcandy-ai' );
		}

		$formatted = get_date_from_gmt( $datetime, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

		return is_string( $formatted ) && '' !== $formatted ? $formatted : __( 'Not available', 'supportcandy-ai' );
	}

	/**
	 * Get a multibyte-safe substring.
	 *
	 * @param string $value  Value.
	 * @param int    $start  Start offset.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	private function substring( $value, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, $start, $length ) : substr( (string) $value, $start, $length );
	}

	/**
	 * Get a multibyte-safe string length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function string_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value ) : strlen( (string) $value );
	}
}
