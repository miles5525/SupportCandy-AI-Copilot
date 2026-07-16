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
 * Renders the read-only Knowledge Sources MVP placeholder.
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

		$repository_available = false;
		$counts               = array(
			'active'   => 0,
			'disabled' => 0,
			'error'    => 0,
		);

		if ( class_exists( 'SCAI_Custom_Knowledge_Repository' ) ) {
			try {
				$repository           = new SCAI_Custom_Knowledge_Repository();
				$repository_available = $repository->table_exists();

				if ( $repository_available ) {
					$repository_counts = $repository->count_by_status();
					$counts             = array_merge( $counts, is_array( $repository_counts ) ? $repository_counts : array() );
				}
			} catch ( Throwable $exception ) {
				$repository_available = false;
			}
		}

		?>
		<div class="wrap scai-admin-page scai-knowledge-sources-wrap">
			<section class="scai-knowledge-hero">
				<h1><?php echo esc_html__( 'Custom Knowledge Base', 'supportcandy-ai' ); ?></h1>
				<p><?php echo esc_html__( 'Add trusted knowledge sources that AI can use as supporting context when helping agents respond to tickets.', 'supportcandy-ai' ); ?></p>
				<p><strong><?php echo esc_html__( 'This does not train the AI model. It stores searchable knowledge that can be retrieved and sent as context when agents use AI.', 'supportcandy-ai' ); ?></strong></p>
			</section>

			<section class="scai-knowledge-card scai-knowledge-overview">
				<h2><?php echo esc_html__( 'Knowledge Sources', 'supportcandy-ai' ); ?></h2>
				<p><?php echo esc_html__( 'Use this page to manage custom knowledge sources for retrieval-augmented generation.', 'supportcandy-ai' ); ?></p>
				<ul class="scai-knowledge-counts">
					<li><strong><?php echo esc_html__( 'Active sources:', 'supportcandy-ai' ); ?></strong> <?php echo esc_html( (string) absint( $counts['active'] ) ); ?></li>
					<li><strong><?php echo esc_html__( 'Disabled sources:', 'supportcandy-ai' ); ?></strong> <?php echo esc_html( (string) absint( $counts['disabled'] ) ); ?></li>
					<li><strong><?php echo esc_html__( 'Errors:', 'supportcandy-ai' ); ?></strong> <?php echo esc_html( (string) absint( $counts['error'] ) ); ?></li>
				</ul>
				<?php if ( $repository_available ) : ?>
					<p class="scai-knowledge-status-note"><?php echo esc_html__( 'Source management will be enabled in the next step.', 'supportcandy-ai' ); ?></p>
				<?php else : ?>
					<p class="scai-knowledge-status-note"><?php echo esc_html__( 'Knowledge table is not available. Reactivate the plugin to recreate required tables.', 'supportcandy-ai' ); ?></p>
				<?php endif; ?>
			</section>

			<h2><?php echo esc_html__( 'Add Source', 'supportcandy-ai' ); ?></h2>
			<div class="scai-knowledge-grid">
				<?php
				$this->render_source_card(
					__( 'Manual Text', 'supportcandy-ai' ),
					__( 'Add FAQs, policies, troubleshooting notes, or internal instructions as text.', 'supportcandy-ai' ),
					__( 'Coming next', 'supportcandy-ai' ),
					'coming'
				);
				$this->render_source_card(
					__( 'URL', 'supportcandy-ai' ),
					__( 'Index a single public web page as a knowledge source.', 'supportcandy-ai' ),
					__( 'Coming next', 'supportcandy-ai' ),
					'coming'
				);
				$this->render_source_card(
					__( 'File Upload', 'supportcandy-ai' ),
					__( 'Upload safe text-like files such as TXT, Markdown, CSV, or logs.', 'supportcandy-ai' ),
					__( 'Coming next', 'supportcandy-ai' ),
					'coming'
				);
				$this->render_source_card(
					__( 'PDF', 'supportcandy-ai' ),
					__( 'PDF support will require a safe text extractor. Unsupported PDFs will not be indexed until extraction is available.', 'supportcandy-ai' ),
					__( 'Planned', 'supportcandy-ai' ),
					'planned'
				);
				?>
			</div>

			<section class="scai-knowledge-card scai-knowledge-existing">
				<h2><?php echo esc_html__( 'Existing Sources', 'supportcandy-ai' ); ?></h2>
				<div class="scai-knowledge-empty">
					<p><?php echo esc_html__( 'No custom knowledge sources have been added yet.', 'supportcandy-ai' ); ?></p>
				</div>
			</section>

			<section class="scai-knowledge-warning">
				<h2><?php echo esc_html__( 'RAG safety', 'supportcandy-ai' ); ?></h2>
				<ul>
					<li><?php echo esc_html__( 'Ticket facts remain the primary source of truth.', 'supportcandy-ai' ); ?></li>
					<li><?php echo esc_html__( 'Custom knowledge is used only as supporting context.', 'supportcandy-ai' ); ?></li>
					<li><?php echo esc_html__( 'AI replies must be reviewed before sending.', 'supportcandy-ai' ); ?></li>
					<li><?php echo esc_html__( 'Do not add secrets, API keys, passwords, or private customer data as knowledge sources.', 'supportcandy-ai' ); ?></li>
				</ul>
			</section>

			<p class="scai-knowledge-next"><strong><?php echo esc_html__( 'Next development step: repository and manual text source.', 'supportcandy-ai' ); ?></strong></p>
		</div>
		<?php
	}

	/**
	 * Render a placeholder source-type card.
	 *
	 * @param string $title       Source title.
	 * @param string $description Source description.
	 * @param string $status      Status label.
	 * @param string $status_type Status style key.
	 * @return void
	 */
	private function render_source_card( $title, $description, $status, $status_type ) {
		$status_type = in_array( $status_type, array( 'coming', 'planned' ), true ) ? $status_type : 'planned';
		?>
		<section class="scai-knowledge-card">
			<span class="scai-knowledge-status scai-knowledge-status-<?php echo esc_attr( $status_type ); ?>"><?php echo esc_html( $status ); ?></span>
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php echo esc_html( $description ); ?></p>
		</section>
		<?php
	}
}
