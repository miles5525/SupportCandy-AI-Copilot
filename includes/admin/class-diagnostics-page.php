<?php
/**
 * Diagnostics page for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders read-only diagnostics for SupportCandy adapter access.
 */
final class SCAI_Diagnostics_Page {

	/**
	 * Required capability.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Future diagnostics page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'scai-diagnostics';

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'scai_check_ticket_context';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'scai_diagnostics_nonce';

	/**
	 * Submit button name.
	 *
	 * @var string
	 */
	const SUBMIT_NAME = 'scai_diagnostics_submit';

	/**
	 * Generate summary submit button name.
	 *
	 * @var string
	 */
	const SUMMARY_SUBMIT_NAME = 'scai_generate_summary_submit';

	/**
	 * Generate reply submit button name.
	 *
	 * @var string
	 */
	const REPLY_SUBMIT_NAME = 'scai_generate_reply_submit';

	/**
	 * Resolve ticket ID submit button name.
	 *
	 * @var string
	 */
	const RESOLVER_SUBMIT_NAME = 'scai_resolve_ticket_id_submit';

	/**
	 * Ticket identifier debug submit button name.
	 *
	 * @var string
	 */
	const IDENTIFIER_DEBUG_SUBMIT_NAME = 'scai_ticket_identifier_debug_submit';

	/**
	 * Ticket identifier database search submit button name.
	 *
	 * @var string
	 */
	const IDENTIFIER_SEARCH_SUBMIT_NAME = 'scai_ticket_identifier_search_submit';

	/**
	 * SupportCandy role/capability debug submit button name.
	 *
	 * @var string
	 */
	const ROLE_DEBUG_SUBMIT_NAME = 'scai_supportcandy_role_debug_submit';

	/**
	 * SupportCandy role definition debug submit button name.
	 *
	 * @var string
	 */
	const ROLE_DEFINITION_DEBUG_SUBMIT_NAME = 'scai_supportcandy_role_definition_debug_submit';

	/**
	 * Attachment debug submit button name.
	 *
	 * @var string
	 */
	const ATTACHMENT_DEBUG_SUBMIT_NAME = 'scai_attachment_debug_submit';

	/**
	 * Image understanding debug submit button name.
	 *
	 * @var string
	 */
	const IMAGE_DEBUG_SUBMIT_NAME = 'scai_image_understanding_debug_submit';

	/**
	 * Render diagnostics page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			$this->render_notice( __( 'You do not have permission to view diagnostics.', 'supportcandy-ai' ), 'error' );
			return;
		}

		$adapter = $this->get_adapter();
		$show_advanced_debug = $this->show_advanced_debug_tools();
		?>
		<div class="wrap scai-system-check-page">
			<h1><?php echo esc_html__( 'SupportCandy AI System Check', 'supportcandy-ai' ); ?></h1>
			<p><?php echo esc_html__( 'Use this page to verify SupportCandy AI Assistant connectivity, ticket context access, attachment readiness, and image understanding support.', 'supportcandy-ai' ); ?></p>

			<?php
			if ( ! $adapter ) {
				$this->render_notice( __( 'SupportCandy adapter class is unavailable.', 'supportcandy-ai' ), 'error' );
			} else {
				$this->render_status( $adapter );
				$this->render_ticket_form();
				$this->render_attachment_debug_form();
				$this->render_image_understanding_debug_form();

				if ( $show_advanced_debug ) {
					echo '<h2>' . esc_html__( 'Advanced Debug Tools', 'supportcandy-ai' ) . '</h2>';
					$this->render_notice( __( 'These tools are intended for development and troubleshooting only.', 'supportcandy-ai' ), 'warning' );
					$this->render_adapter_debug_details( $adapter );
					$this->render_resolver_test_form();
					$this->render_identifier_debug_form();
					$this->render_identifier_search_form();
					$this->render_role_capability_debug_form();
					$this->render_supportcandy_role_definition_debug();
				}

				if ( $this->is_image_understanding_debug_requested() ) {
					if ( ! $this->verify_request() ) {
						$this->render_notice( __( 'Security check failed. Please try again.', 'supportcandy-ai' ), 'error' );
					} else {
						$this->render_image_understanding_debug_result( $adapter );
					}
				} elseif ( $this->is_attachment_debug_requested() ) {
					if ( ! $this->verify_request() ) {
						$this->render_notice( __( 'Security check failed. Please try again.', 'supportcandy-ai' ), 'error' );
					} else {
						$this->render_attachment_debug_result( $adapter );
					}
				} elseif ( $show_advanced_debug && $this->is_role_definition_debug_requested() ) {
					if ( ! $this->verify_request() ) {
						$this->render_notice( __( 'Security check failed. Please try again.', 'supportcandy-ai' ), 'error' );
					} else {
						$this->render_supportcandy_role_definition_debug_result();
					}
				} elseif ( $show_advanced_debug && $this->is_role_capability_debug_requested() ) {
					if ( ! $this->verify_request() ) {
						$this->render_notice( __( 'Security check failed. Please try again.', 'supportcandy-ai' ), 'error' );
					} else {
						$this->render_role_capability_debug_result();
					}
				} elseif ( $show_advanced_debug && $this->is_identifier_search_requested() ) {
					if ( ! $this->verify_request() ) {
						$this->render_notice( __( 'Security check failed. Please try again.', 'supportcandy-ai' ), 'error' );
					} else {
						$this->render_identifier_search_result();
					}
				} elseif ( $show_advanced_debug && $this->is_identifier_debug_requested() ) {
					if ( ! $this->verify_request() ) {
						$this->render_notice( __( 'Security check failed. Please try again.', 'supportcandy-ai' ), 'error' );
					} else {
						$this->render_identifier_debug_result( $adapter );
					}
				} elseif ( $show_advanced_debug && $this->is_resolver_requested() ) {
					if ( ! $this->verify_request() ) {
						$this->render_notice( __( 'Security check failed. Please try again.', 'supportcandy-ai' ), 'error' );
					} else {
						$this->render_resolver_test_result( $adapter );
					}
				} elseif ( $this->is_form_submitted() ) {
					if ( ! $this->verify_request() ) {
						$this->render_notice( __( 'Security check failed. Please try again.', 'supportcandy-ai' ), 'error' );
					} else {
						$ticket_id = $this->get_requested_ticket_id();

						if ( 0 === $ticket_id ) {
							$this->render_notice( __( 'Please enter a valid ticket ID.', 'supportcandy-ai' ), 'warning' );
						} else {
							if ( $this->is_summary_requested() || $this->is_reply_requested() ) {
								$this->handle_ai_action( $ticket_id );
							} else {
								$context = $adapter->get_ticket_context( $ticket_id );

								if ( empty( $context ) ) {
									$this->render_notice( __( 'No ticket context was found for the submitted ticket ID.', 'supportcandy-ai' ), 'warning' );
								} else {
									$this->render_ticket_context_result( $context );
								}
							}
						}
					}
				}
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render adapter status.
	 *
	 * @param SCAI_SupportCandy_Adapter $adapter Adapter instance.
	 * @return void
	 */
	private function render_status( $adapter ) {
		$status       = $adapter->get_status();
		$is_available = $adapter->is_available();
		?>
		<div class="scai-diagnostic-section scai-system-status">
		<h2><?php echo esc_html__( 'SupportCandy Adapter Status', 'supportcandy-ai' ); ?></h2>

		<table class="widefat striped" style="max-width: 760px;">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'SupportCandy active', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( $this->format_bool( ! empty( $status['supportcandy_active'] ) ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Data source available', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( $this->format_bool( ! empty( $status['data_source_available'] ) ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Adapter available', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( $this->format_bool( $is_available ) ); ?></td>
				</tr>
			</tbody>
		</table>

		</div>
		<?php
	}

	/**
	 * Render adapter internals for advanced debugging.
	 *
	 * @param SCAI_SupportCandy_Adapter $adapter Adapter instance.
	 * @return void
	 */
	private function render_adapter_debug_details( $adapter ) {
		$status  = $adapter->get_status();
		$tables  = isset( $status['tables'] ) && is_array( $status['tables'] ) ? $status['tables'] : array();
		$classes = isset( $status['detected_classes'] ) && is_array( $status['detected_classes'] ) ? $status['detected_classes'] : array();
		?>
		<h3><?php echo esc_html__( 'Detected Tables', 'supportcandy-ai' ); ?></h3>
		<?php $this->render_key_value_table( $tables ); ?>

		<h3><?php echo esc_html__( 'Detected Classes', 'supportcandy-ai' ); ?></h3>
		<?php $this->render_key_value_table( $classes ); ?>
		<?php
	}

	/**
	 * Render ticket context lookup form.
	 *
	 * @return void
	 */
	private function render_ticket_form() {
		$ticket_id = $this->get_requested_ticket_id();
		?>
		<div class="scai-diagnostic-section scai-diagnostic-card">
		<h2><?php echo esc_html__( 'Ticket Context Test', 'supportcandy-ai' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" style="margin-top: 12px; max-width: 760px;">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="scai_ticket_id"><?php echo esc_html__( 'Ticket ID', 'supportcandy-ai' ); ?></label>
						</th>
						<td>
							<input
								type="number"
								id="scai_ticket_id"
								name="scai_ticket_id"
								value="<?php echo esc_attr( $ticket_id ); ?>"
								min="1"
								step="1"
								class="small-text"
							/>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Check Ticket Context', 'supportcandy-ai' ), 'secondary', self::SUBMIT_NAME ); ?>
			<h3><?php echo esc_html__( 'AI Ticket Test', 'supportcandy-ai' ); ?></h3>
			<?php submit_button( __( 'Generate Summary', 'supportcandy-ai' ), 'secondary', self::SUMMARY_SUBMIT_NAME, false ); ?>
			<?php submit_button( __( 'Generate Reply', 'supportcandy-ai' ), 'secondary', self::REPLY_SUBMIT_NAME, false ); ?>
		</form>
		</div>
		<?php
	}

	/**
	 * Render attachment diagnostics form.
	 *
	 * @return void
	 */
	private function render_attachment_debug_form() {
		$ticket_id = $this->get_requested_attachment_ticket_id();

		if ( 0 === $ticket_id ) {
			$ticket_id = $this->get_requested_ticket_id();
		}
		?>
		<div class="scai-diagnostic-section scai-diagnostic-card">
		<h2><?php echo esc_html__( 'Attachment Readiness Check', 'supportcandy-ai' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" style="margin-top: 12px; max-width: 760px;">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="scai_attachment_ticket_id"><?php echo esc_html__( 'Ticket ID', 'supportcandy-ai' ); ?></label></th>
						<td><input type="number" id="scai_attachment_ticket_id" name="scai_attachment_ticket_id" value="<?php echo esc_attr( $ticket_id ); ?>" min="1" step="1" class="small-text" /></td>
					</tr>
				</tbody>
			</table>

			<p><?php echo esc_html__( 'Inspect attachment metadata only. File contents are not opened or read.', 'supportcandy-ai' ); ?></p>
			<?php submit_button( __( 'Inspect Attachments', 'supportcandy-ai' ), 'secondary', self::ATTACHMENT_DEBUG_SUBMIT_NAME ); ?>
		</form>
		</div>
		<?php
	}

	/**
	 * Render attachment diagnostics results.
	 *
	 * @param SCAI_SupportCandy_Adapter $adapter Adapter instance.
	 * @return void
	 */
	private function render_attachment_debug_result( $adapter ) {
		$ticket_id = $this->get_requested_attachment_ticket_id();

		if ( 0 === $ticket_id ) {
			$this->render_notice( __( 'Please enter a valid ticket ID.', 'supportcandy-ai' ), 'warning' );
			return;
		}

		if ( ! method_exists( $adapter, 'get_ticket_attachments' ) || ! method_exists( $adapter, 'get_ticket_context' ) ) {
			$this->render_notice( __( 'Attachment diagnostics are unavailable in the current adapter.', 'supportcandy-ai' ), 'error' );
			return;
		}

		$direct_attachments = $adapter->get_ticket_attachments( $ticket_id );
		$context            = $adapter->get_ticket_context( $ticket_id );
		$context_attachments = isset( $context['attachments'] ) && is_array( $context['attachments'] ) ? $context['attachments'] : array();
		$direct_attachments = is_array( $direct_attachments ) ? $direct_attachments : array();
		?>
		<h2><?php echo esc_html__( 'Attachment Readiness Result', 'supportcandy-ai' ); ?></h2>

		<table class="widefat striped" style="max-width: 1120px;">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Ticket ID', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( (string) $ticket_id ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Direct attachment count', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( (string) count( $direct_attachments ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Context attachment count', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( (string) count( $context_attachments ) ); ?></td>
				</tr>
			</tbody>
		</table>

		<?php $this->render_attachment_debug_table( $direct_attachments ); ?>

		<?php if ( $this->show_advanced_debug_tools() ) : ?>
			<details style="max-width: 1120px; margin-top: 16px;">
				<summary><strong><?php echo esc_html__( 'Raw attachment data', 'supportcandy-ai' ); ?></strong></summary>
				<pre style="max-height: 520px; overflow: auto; padding: 12px; background: #fff; border: 1px solid #c3c4c7;"><?php echo esc_html( $this->format_attachment_debug_json( array( 'get_ticket_attachments' => $direct_attachments, 'get_ticket_context_attachments' => $context_attachments ) ) ); ?></pre>
			</details>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render normalized attachment metadata table.
	 *
	 * @param array<int, mixed> $attachments Attachments.
	 * @return void
	 */
	private function render_attachment_debug_table( array $attachments ) {
		?>
		<h3><?php echo esc_html__( 'Normalized Attachment Fields', 'supportcandy-ai' ); ?></h3>

		<?php if ( empty( $attachments ) ) : ?>
			<p><?php echo esc_html__( 'No attachments were returned for this ticket.', 'supportcandy-ai' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<div style="max-width: 1120px; overflow-x: auto;">
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Attachment ID', 'supportcandy-ai' ); ?></th>
						<th><?php echo esc_html__( 'Thread ID', 'supportcandy-ai' ); ?></th>
						<th><?php echo esc_html__( 'Filename / title', 'supportcandy-ai' ); ?></th>
						<th><?php echo esc_html__( 'MIME type', 'supportcandy-ai' ); ?></th>
						<th><?php echo esc_html__( 'Extension', 'supportcandy-ai' ); ?></th>
						<th><?php echo esc_html__( 'Type', 'supportcandy-ai' ); ?></th>
						<th><?php echo esc_html__( 'Text-readable candidate', 'supportcandy-ai' ); ?></th>
						<th><?php echo esc_html__( 'URL', 'supportcandy-ai' ); ?></th>
						<th><?php echo esc_html__( 'Local path present?', 'supportcandy-ai' ); ?></th>
						<th><?php echo esc_html__( 'Size', 'supportcandy-ai' ); ?></th>
						<th><?php echo esc_html__( 'Created', 'supportcandy-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $attachments as $attachment ) : ?>
						<?php $row = $this->prepare_attachment_debug_row( $attachment ); ?>
						<tr>
							<td><?php echo esc_html( (string) $row['id'] ); ?></td>
							<td><?php echo esc_html( (string) $row['thread_id'] ); ?></td>
							<td><?php echo esc_html( $row['filename'] ); ?></td>
							<td><?php echo esc_html( $row['mime_type'] ); ?></td>
							<td><?php echo esc_html( $row['extension'] ); ?></td>
							<td><?php echo esc_html( $row['type'] ); ?></td>
							<td><?php echo esc_html( $this->format_bool( $row['text_readable'] ) ); ?></td>
							<td><?php echo '' !== $row['url'] ? '<a href="' . esc_url( $row['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $row['url'] ) . '</a>' : esc_html__( 'Not available', 'supportcandy-ai' ); ?></td>
							<td><?php echo esc_html( $this->format_bool( $row['path_present'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['size'] ); ?></td>
							<td><?php echo esc_html( $row['created_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the image understanding diagnostics form.
	 *
	 * @return void
	 */
	private function render_image_understanding_debug_form() {
		$ticket_id = $this->get_requested_image_debug_ticket_id();

		if ( 0 === $ticket_id ) {
			$ticket_id = $this->get_requested_attachment_ticket_id();
		}

		if ( 0 === $ticket_id ) {
			$ticket_id = $this->get_requested_ticket_id();
		}
		?>
		<div class="scai-diagnostic-section scai-diagnostic-card">
		<h2><?php echo esc_html__( 'Image Understanding Check', 'supportcandy-ai' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" style="margin-top: 12px; max-width: 760px;">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="scai_image_debug_ticket_id"><?php echo esc_html__( 'Ticket ID', 'supportcandy-ai' ); ?></label></th>
						<td><input type="number" id="scai_image_debug_ticket_id" name="scai_image_debug_ticket_id" value="<?php echo esc_attr( $ticket_id ); ?>" min="1" step="1" class="small-text" /></td>
					</tr>
				</tbody>
			</table>

			<p><?php echo esc_html__( 'Check image readiness without sending an AI request. Local paths and prepared image data are never displayed.', 'supportcandy-ai' ); ?></p>
			<?php submit_button( __( 'Inspect Image Understanding', 'supportcandy-ai' ), 'secondary', self::IMAGE_DEBUG_SUBMIT_NAME ); ?>
		</form>
		</div>
		<?php
	}

	/**
	 * Render image understanding readiness results.
	 *
	 * @param SCAI_SupportCandy_Adapter $adapter Adapter instance.
	 * @return void
	 */
	private function render_image_understanding_debug_result( $adapter ) {
		$ticket_id = $this->get_requested_image_debug_ticket_id();

		if ( 0 === $ticket_id ) {
			$this->render_notice( __( 'Please enter a valid ticket ID.', 'supportcandy-ai' ), 'warning' );
			return;
		}

		if ( ! method_exists( $adapter, 'get_ticket_attachments' ) ) {
			$this->render_notice( __( 'SupportCandy attachment diagnostics are unavailable.', 'supportcandy-ai' ), 'error' );
			return;
		}

		$attachments = $adapter->get_ticket_attachments( $ticket_id );
		$attachments = is_array( $attachments ) ? $attachments : array();
		$provider    = $this->get_image_debug_provider_status();
		$enabled     = class_exists( 'SCAI_Settings' )
			? (bool) SCAI_Settings::get( 'image_understanding_enabled', false )
			: (bool) get_option( 'scai_image_understanding_enabled', false );
		$image_count = 0;

		foreach ( $attachments as $attachment ) {
			if ( is_array( $attachment ) && $this->is_image_attachment_for_debug( $attachment ) ) {
				++$image_count;
			}
		}
		?>
		<h2><?php echo esc_html__( 'Image Understanding Result', 'supportcandy-ai' ); ?></h2>
		<?php
		$this->render_key_value_table(
			array(
				__( 'Ticket ID', 'supportcandy-ai' )                         => $ticket_id,
				__( 'scai_image_understanding_enabled', 'supportcandy-ai' ) => $enabled,
				__( 'Active provider', 'supportcandy-ai' )                   => $provider['label'],
				__( 'Provider supports images', 'supportcandy-ai' )          => $provider['supports_images'],
				__( 'Total attachments found', 'supportcandy-ai' )           => count( $attachments ),
				__( 'Image attachments found', 'supportcandy-ai' )           => $image_count,
			)
		);

		if ( ! class_exists( 'SCAI_Image_Attachment_Preparer' ) ) {
			$this->render_notice( __( 'Image preparer class not loaded.', 'supportcandy-ai' ), 'warning' );
			return;
		}

		$diagnostics = $this->build_image_readiness_diagnostics( $attachments );
		$this->render_key_value_table(
			array(
				__( 'Prepared image count', 'supportcandy-ai' ) => $diagnostics['prepared_count'],
				__( 'Maximum images', 'supportcandy-ai' )       => $diagnostics['max_images'],
				__( 'Maximum image size', 'supportcandy-ai' )   => size_format( $diagnostics['max_image_size'] ),
			)
		);
		$this->render_image_readiness_table( $diagnostics['rows'] );
	}

	/**
	 * Build sanitized image readiness diagnostics.
	 *
	 * @param array<int, mixed> $attachments Ticket attachments.
	 * @return array{rows: array<int, array<string, mixed>>, prepared_count: int, max_images: int, max_image_size: int}
	 */
	private function build_image_readiness_diagnostics( array $attachments ) {
		$preparer       = new SCAI_Image_Attachment_Preparer();
		$max_images     = max( 1, absint( apply_filters( 'scai_image_attachment_max_images', SCAI_Image_Attachment_Preparer::DEFAULT_MAX_IMAGES, array() ) ) );
		$max_image_size = max( 1, absint( apply_filters( 'scai_image_attachment_max_file_size', SCAI_Image_Attachment_Preparer::DEFAULT_MAX_IMAGE_SIZE, array() ) ) );
		$prepared_count = 0;
		$rows           = array();

		foreach ( $attachments as $attachment ) {
			if ( ! is_array( $attachment ) || ! $this->is_image_attachment_for_debug( $attachment ) ) {
				continue;
			}

			$filename    = isset( $attachment['filename'] ) && is_scalar( $attachment['filename'] ) ? sanitize_file_name( (string) $attachment['filename'] ) : '';
			$mime_type   = isset( $attachment['mime_type'] ) && is_scalar( $attachment['mime_type'] ) ? sanitize_mime_type( (string) $attachment['mime_type'] ) : '';
			$extension   = $preparer->get_extension( $filename );
			$file_size   = isset( $attachment['file_size'] ) ? absint( $attachment['file_size'] ) : ( isset( $attachment['size'] ) ? absint( $attachment['size'] ) : 0 );
			$path_exists = ! empty( $attachment['local_path_exists'] ) && ! empty( $attachment['local_path'] );
			$eligible    = $preparer->can_prepare_image( $attachment );
			$included    = false;
			$reason      = '';

			if ( ! $eligible ) {
				$result = $preparer->prepare_image( $attachment );
				$reason = isset( $result['error_message'] ) && is_scalar( $result['error_message'] ) ? sanitize_text_field( (string) $result['error_message'] ) : __( 'The image is not eligible for preparation.', 'supportcandy-ai' );
			} elseif ( $prepared_count >= $max_images ) {
				$reason = __( 'Skipped because the maximum image count was reached.', 'supportcandy-ai' );
			} else {
				$result = $preparer->prepare_image( $attachment );
				$included = ! empty( $result['success'] );

				if ( $included ) {
					++$prepared_count;
				} else {
					$eligible = false;
					$reason   = isset( $result['error_message'] ) && is_scalar( $result['error_message'] ) ? sanitize_text_field( (string) $result['error_message'] ) : __( 'The image could not be prepared.', 'supportcandy-ai' );
				}
			}

			$rows[] = array(
				'filename'    => $filename,
				'mime_type'   => $mime_type,
				'extension'   => $extension,
				'file_size'   => $file_size,
				'path_exists' => $path_exists,
				'eligible'    => $eligible,
				'included'    => $included,
				'reason'      => $reason,
			);
		}

		return array(
			'rows'            => $rows,
			'prepared_count'  => $prepared_count,
			'max_images'      => $max_images,
			'max_image_size'  => $max_image_size,
		);
	}

	/**
	 * Render sanitized image readiness rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Image rows.
	 * @return void
	 */
	private function render_image_readiness_table( array $rows ) {
		?>
		<h3><?php echo esc_html__( 'Image Attachment Readiness', 'supportcandy-ai' ); ?></h3>
		<?php if ( empty( $rows ) ) : ?>
			<p><?php echo esc_html__( 'No image attachments were found for this ticket.', 'supportcandy-ai' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<div style="max-width: 1120px; overflow-x: auto;">
			<table class="widefat striped">
				<thead><tr>
					<th><?php echo esc_html__( 'Filename', 'supportcandy-ai' ); ?></th>
					<th><?php echo esc_html__( 'MIME type', 'supportcandy-ai' ); ?></th>
					<th><?php echo esc_html__( 'Extension', 'supportcandy-ai' ); ?></th>
					<th><?php echo esc_html__( 'File size', 'supportcandy-ai' ); ?></th>
					<th><?php echo esc_html__( 'Local path exists', 'supportcandy-ai' ); ?></th>
					<th><?php echo esc_html__( 'Eligible for image AI', 'supportcandy-ai' ); ?></th>
					<th><?php echo esc_html__( 'Would be included in AI request', 'supportcandy-ai' ); ?></th>
					<th><?php echo esc_html__( 'Skipped reason', 'supportcandy-ai' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $row['filename'] ); ?></td>
							<td><?php echo esc_html( (string) $row['mime_type'] ); ?></td>
							<td><?php echo esc_html( (string) $row['extension'] ); ?></td>
							<td><?php echo esc_html( size_format( absint( $row['file_size'] ) ) ); ?></td>
							<td><?php echo esc_html( $this->format_bool( ! empty( $row['path_exists'] ) ) ); ?></td>
							<td><?php echo esc_html( $this->format_bool( ! empty( $row['eligible'] ) ) ); ?></td>
							<td><?php echo esc_html( $this->format_bool( ! empty( $row['included'] ) ) ); ?></td>
							<td><?php echo esc_html( (string) $row['reason'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render ticket ID resolver diagnostics form.
	 *
	 * @return void
	 */
	private function render_resolver_test_form() {
		$identifier = $this->get_requested_ticket_identifier();
		?>
		<h2><?php echo esc_html__( 'Ticket ID Resolver Test', 'supportcandy-ai' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" style="margin-top: 12px; max-width: 760px;">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="scai_ticket_identifier"><?php echo esc_html__( 'Ticket Identifier', 'supportcandy-ai' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="scai_ticket_identifier"
								name="scai_ticket_identifier"
								value="<?php echo esc_attr( $identifier ); ?>"
								class="regular-text"
							/>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Resolve Ticket ID', 'supportcandy-ai' ), 'secondary', self::RESOLVER_SUBMIT_NAME ); ?>
		</form>
		<?php
	}

	/**
	 * Render ticket ID resolver result.
	 *
	 * @param SCAI_SupportCandy_Adapter $adapter Adapter instance.
	 * @return void
	 */
	private function render_resolver_test_result( $adapter ) {
		$identifier = $this->get_requested_ticket_identifier();

		if ( '' === $identifier ) {
			$this->render_notice( __( 'Please enter a ticket identifier.', 'supportcandy-ai' ), 'warning' );
			return;
		}

		if ( ! method_exists( $adapter, 'resolve_ticket_id' ) ) {
			$this->render_notice( __( 'Ticket ID resolver is unavailable.', 'supportcandy-ai' ), 'error' );
			return;
		}

		$resolved_ticket_id = absint( $adapter->resolve_ticket_id( $identifier ) );

		if ( 0 === $resolved_ticket_id ) {
			$this->render_notice( __( 'Could not resolve ticket identifier.', 'supportcandy-ai' ), 'warning' );
			return;
		}

		?>
		<h2><?php echo esc_html__( 'Ticket ID Resolver Result', 'supportcandy-ai' ); ?></h2>

		<table class="widefat striped" style="max-width: 760px;">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Ticket Identifier', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( $identifier ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Resolved internal ticket ID', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( (string) $resolved_ticket_id ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render ticket identifier debug form.
	 *
	 * @return void
	 */
	private function render_identifier_debug_form() {
		$ticket_id = $this->get_requested_debug_ticket_id();
		?>
		<h2><?php echo esc_html__( 'Ticket Identifier Debug', 'supportcandy-ai' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" style="margin-top: 12px; max-width: 760px;">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="scai_debug_ticket_id"><?php echo esc_html__( 'Internal Ticket ID', 'supportcandy-ai' ); ?></label>
						</th>
						<td>
							<input
								type="number"
								id="scai_debug_ticket_id"
								name="scai_debug_ticket_id"
								value="<?php echo esc_attr( $ticket_id ); ?>"
								min="1"
								step="1"
								class="small-text"
							/>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Inspect Ticket Identifier Data', 'supportcandy-ai' ), 'secondary', self::IDENTIFIER_DEBUG_SUBMIT_NAME ); ?>
		</form>
		<?php
	}

	/**
	 * Render ticket identifier debug result.
	 *
	 * @param SCAI_SupportCandy_Adapter $adapter Adapter instance.
	 * @return void
	 */
	private function render_identifier_debug_result( $adapter ) {
		$ticket_id = $this->get_requested_debug_ticket_id();

		if ( 0 === $ticket_id ) {
			$this->render_notice( __( 'Please enter a valid internal ticket ID.', 'supportcandy-ai' ), 'warning' );
			return;
		}

		$ticket = $adapter->get_ticket( $ticket_id );

		if ( empty( $ticket ) ) {
			$this->render_notice( __( 'No ticket data was found for the submitted internal ticket ID.', 'supportcandy-ai' ), 'warning' );
			return;
		}

		$debug_data = array(
			'internal_ticket_id' => $ticket_id,
			'normalized_ticket'  => $ticket,
			'raw_ticket'         => isset( $ticket['raw'] ) && is_array( $ticket['raw'] ) ? $ticket['raw'] : array(),
			'adapter_status'     => $adapter->get_status(),
		);

		?>
		<h2><?php echo esc_html__( 'Ticket Identifier Debug Result', 'supportcandy-ai' ); ?></h2>
		<textarea class="large-text code" rows="22" readonly><?php echo esc_textarea( $this->format_context_preview( $debug_data ) ); ?></textarea>
		<?php
	}

	/**
	 * Render ticket identifier database search form.
	 *
	 * @return void
	 */
	private function render_identifier_search_form() {
		$identifier = $this->get_requested_search_identifier();
		?>
		<h2><?php echo esc_html__( 'Search Ticket Identifier in SupportCandy Tables', 'supportcandy-ai' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" style="margin-top: 12px; max-width: 760px;">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="scai_search_identifier"><?php echo esc_html__( 'Identifier value', 'supportcandy-ai' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="scai_search_identifier"
								name="scai_search_identifier"
								value="<?php echo esc_attr( $identifier ); ?>"
								class="regular-text"
							/>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Search Identifier', 'supportcandy-ai' ), 'secondary', self::IDENTIFIER_SEARCH_SUBMIT_NAME ); ?>
		</form>
		<?php
	}

	/**
	 * Render ticket identifier database search result.
	 *
	 * @return void
	 */
	private function render_identifier_search_result() {
		$identifier = $this->get_requested_search_identifier();

		if ( '' === $identifier ) {
			$this->render_notice( __( 'Please enter an identifier value.', 'supportcandy-ai' ), 'warning' );
			return;
		}

		$results = $this->search_supportcandy_tables_for_identifier( $identifier );

		if ( empty( $results ) ) {
			$this->render_notice( __( 'No matching SupportCandy table data was found for the submitted identifier.', 'supportcandy-ai' ), 'warning' );
			return;
		}

		?>
		<h2><?php echo esc_html__( 'Ticket Identifier Search Result', 'supportcandy-ai' ); ?></h2>
		<textarea class="large-text code" rows="22" readonly><?php echo esc_textarea( $this->format_context_preview( $results ) ); ?></textarea>
		<?php
	}

	/**
	 * Render SupportCandy role/capability diagnostics form.
	 *
	 * @return void
	 */
	private function render_role_capability_debug_form() {
		?>
		<h2><?php echo esc_html__( 'SupportCandy Role / Capability Debug', 'supportcandy-ai' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" style="margin-top: 12px; max-width: 760px;">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

			<p>
				<?php
				echo esc_html__(
					'Inspect read-only SupportCandy tables and current-user role/capability data for permission mapping.',
					'supportcandy-ai'
				);
				?>
			</p>

			<?php submit_button( __( 'Inspect Roles and Capabilities', 'supportcandy-ai' ), 'secondary', self::ROLE_DEBUG_SUBMIT_NAME ); ?>
		</form>
		<?php
	}

	/**
	 * Render SupportCandy role/capability diagnostics result.
	 *
	 * @return void
	 */
	private function render_role_capability_debug_result() {
		$debug_data = array(
			'current_user'                 => $this->get_current_user_debug_data(),
			'supportcandy_related_tables' => $this->get_role_debug_tables(),
			'relevant_columns_by_table'   => $this->get_role_debug_columns_by_table(),
			'current_user_row_matches'    => $this->get_current_user_role_debug_matches(),
		);
		?>
		<h2><?php echo esc_html__( 'SupportCandy Role / Capability Debug Result', 'supportcandy-ai' ); ?></h2>
		<textarea class="large-text code" rows="24" readonly><?php echo esc_textarea( $this->format_context_preview( $debug_data ) ); ?></textarea>
		<?php
	}

	/**
	 * Render the SupportCandy role definition diagnostics form.
	 *
	 * @return void
	 */
	private function render_supportcandy_role_definition_debug() {
		$agent_row = $this->get_current_user_supportcandy_agent_row();
		$role_id   = $this->get_requested_role_id();

		if ( ! $role_id && ! empty( $agent_row['role'] ) ) {
			$role_id = absint( $agent_row['role'] );
		}
		?>
		<h2><?php echo esc_html__( 'SupportCandy Role Definition Debug', 'supportcandy-ai' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" style="margin-top: 12px; max-width: 760px;">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

			<p><?php echo esc_html__( 'Search read-only SupportCandy role, capability, permission, access, and agent storage.', 'supportcandy-ai' ); ?></p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="scai_role_id"><?php echo esc_html__( 'Role ID', 'supportcandy-ai' ); ?></label></th>
						<td><input type="number" id="scai_role_id" name="scai_role_id" value="<?php echo esc_attr( $role_id ); ?>" min="1" step="1" class="small-text" /></td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Inspect Role Definition', 'supportcandy-ai' ), 'secondary', self::ROLE_DEFINITION_DEBUG_SUBMIT_NAME ); ?>
		</form>
		<?php
	}

	/**
	 * Render SupportCandy role definition diagnostics results.
	 *
	 * @return void
	 */
	private function render_supportcandy_role_definition_debug_result() {
		$agent_row = $this->get_current_user_supportcandy_agent_row();
		$role_id   = $this->get_requested_role_id();

		if ( ! $role_id && ! empty( $agent_row['role'] ) ) {
			$role_id = absint( $agent_row['role'] );
		}

		$debug_data = array(
			'agent_assignment'         => $agent_row,
			'inspected_role_id'        => $role_id,
			'possible_schema_columns'  => $this->get_role_definition_columns_by_table(),
			'role_definition_matches' => $this->search_role_definition_rows( $role_id ),
		);
		?>
		<h2><?php echo esc_html__( 'SupportCandy Role Definition Debug Result', 'supportcandy-ai' ); ?></h2>
		<textarea class="large-text code" rows="28" readonly><?php echo esc_textarea( $this->format_context_preview( $debug_data ) ); ?></textarea>
		<?php
	}

	/**
	 * Render ticket context diagnostics result.
	 *
	 * @param array<string, mixed> $context Normalized ticket context.
	 * @return void
	 */
	private function render_ticket_context_result( array $context ) {
		$ticket           = isset( $context['ticket'] ) && is_array( $context['ticket'] ) ? $context['ticket'] : array();
		$threads          = isset( $context['threads'] ) && is_array( $context['threads'] ) ? $context['threads'] : array();
		$attachments      = isset( $context['attachments'] ) && is_array( $context['attachments'] ) ? $context['attachments'] : array();
		$context_preview  = $this->show_advanced_debug_tools() ? $this->format_context_preview( $context ) : '';
		$ticket_id        = isset( $ticket['id'] ) ? absint( $ticket['id'] ) : 0;
		$ticket_subject   = isset( $ticket['subject'] ) ? sanitize_text_field( $ticket['subject'] ) : '';
		$ticket_status    = isset( $ticket['status'] ) ? sanitize_text_field( $ticket['status'] ) : '';
		$ticket_customer  = isset( $ticket['customer_name'] ) ? sanitize_text_field( $ticket['customer_name'] ) : '';
		$customer_email   = isset( $ticket['customer_email'] ) ? sanitize_email( $ticket['customer_email'] ) : '';
		?>
		<h2><?php echo esc_html__( 'Ticket Context Result', 'supportcandy-ai' ); ?></h2>

		<table class="widefat striped" style="max-width: 760px;">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Ticket ID', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( (string) $ticket_id ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Subject', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( $ticket_subject ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Status', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( $ticket_status ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Customer', 'supportcandy-ai' ); ?></th>
					<td>
						<?php echo esc_html( $ticket_customer ); ?>
						<?php if ( '' !== $customer_email ) : ?>
							&lt;<?php echo esc_html( $customer_email ); ?>&gt;
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Thread count', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( (string) count( $threads ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Attachment count', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( (string) count( $attachments ) ); ?></td>
				</tr>
			</tbody>
		</table>

		<?php if ( $this->show_advanced_debug_tools() ) : ?>
			<h3><?php echo esc_html__( 'Normalized Context Preview', 'supportcandy-ai' ); ?></h3>
			<textarea class="large-text code" rows="18" readonly><?php echo esc_textarea( $context_preview ); ?></textarea>
		<?php endif; ?>
		<?php
	}

	/**
	 * Get SupportCandy adapter instance.
	 *
	 * @return SCAI_SupportCandy_Adapter|null
	 */
	private function get_adapter() {
		if ( ! class_exists( 'SCAI_SupportCandy_Adapter' ) ) {
			return null;
		}

		return new SCAI_SupportCandy_Adapter();
	}

	/**
	 * Determine whether developer-only diagnostic tools should be visible.
	 *
	 * @return bool
	 */
	private function show_advanced_debug_tools() {
		return defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( self::CAPABILITY );
	}

	/**
	 * Get ticket AI service instance.
	 *
	 * @return SCAI_Ticket_AI_Service|null
	 */
	private function get_ticket_ai_service() {
		if ( ! class_exists( 'SCAI_Ticket_AI_Service' ) ) {
			return null;
		}

		return new SCAI_Ticket_AI_Service();
	}

	/**
	 * Get requested ticket ID.
	 *
	 * @return int
	 */
	private function get_requested_ticket_id() {
		if ( ! isset( $_POST['scai_ticket_id'] ) ) {
			return 0;
		}

		return absint( wp_unslash( $_POST['scai_ticket_id'] ) );
	}

	/**
	 * Get requested ticket ID for attachment diagnostics.
	 *
	 * @return int
	 */
	private function get_requested_attachment_ticket_id() {
		if ( ! isset( $_POST['scai_attachment_ticket_id'] ) || ! is_scalar( $_POST['scai_attachment_ticket_id'] ) ) {
			return 0;
		}

		return absint( wp_unslash( $_POST['scai_attachment_ticket_id'] ) );
	}

	/**
	 * Get requested ticket ID for image understanding diagnostics.
	 *
	 * @return int
	 */
	private function get_requested_image_debug_ticket_id() {
		if ( ! isset( $_POST['scai_image_debug_ticket_id'] ) || ! is_scalar( $_POST['scai_image_debug_ticket_id'] ) ) {
			return 0;
		}

		return absint( wp_unslash( $_POST['scai_image_debug_ticket_id'] ) );
	}

	/**
	 * Get requested ticket identifier.
	 *
	 * @return string
	 */
	private function get_requested_ticket_identifier() {
		if ( ! isset( $_POST['scai_ticket_identifier'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_POST['scai_ticket_identifier'] ) );
	}

	/**
	 * Get requested ticket ID for identifier debugging.
	 *
	 * @return int
	 */
	private function get_requested_debug_ticket_id() {
		if ( ! isset( $_POST['scai_debug_ticket_id'] ) ) {
			return 0;
		}

		return absint( wp_unslash( $_POST['scai_debug_ticket_id'] ) );
	}

	/**
	 * Get requested identifier search value.
	 *
	 * @return string
	 */
	private function get_requested_search_identifier() {
		if ( ! isset( $_POST['scai_search_identifier'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_POST['scai_search_identifier'] ) );
	}

	/**
	 * Determine whether the diagnostics form was submitted.
	 *
	 * @return bool
	 */
	private function is_form_submitted() {
		return isset( $_POST[ self::SUBMIT_NAME ] )
			|| $this->is_summary_requested()
			|| $this->is_reply_requested();
	}

	/**
	 * Determine whether attachment debugging was requested.
	 *
	 * @return bool
	 */
	private function is_attachment_debug_requested() {
		return isset( $_POST[ self::ATTACHMENT_DEBUG_SUBMIT_NAME ] );
	}

	/**
	 * Determine whether image understanding debugging was requested.
	 *
	 * @return bool
	 */
	private function is_image_understanding_debug_requested() {
		return isset( $_POST[ self::IMAGE_DEBUG_SUBMIT_NAME ] );
	}

	/**
	 * Determine whether ticket ID resolver was requested.
	 *
	 * @return bool
	 */
	private function is_resolver_requested() {
		return isset( $_POST[ self::RESOLVER_SUBMIT_NAME ] );
	}

	/**
	 * Determine whether ticket identifier debug was requested.
	 *
	 * @return bool
	 */
	private function is_identifier_debug_requested() {
		return isset( $_POST[ self::IDENTIFIER_DEBUG_SUBMIT_NAME ] );
	}

	/**
	 * Determine whether ticket identifier search was requested.
	 *
	 * @return bool
	 */
	private function is_identifier_search_requested() {
		return isset( $_POST[ self::IDENTIFIER_SEARCH_SUBMIT_NAME ] );
	}

	/**
	 * Determine whether role/capability debugging was requested.
	 *
	 * @return bool
	 */
	private function is_role_capability_debug_requested() {
		return isset( $_POST[ self::ROLE_DEBUG_SUBMIT_NAME ] );
	}

	/**
	 * Determine whether role definition debugging was requested.
	 *
	 * @return bool
	 */
	private function is_role_definition_debug_requested() {
		return isset( $_POST[ self::ROLE_DEFINITION_DEBUG_SUBMIT_NAME ] );
	}

	/**
	 * Get the requested SupportCandy role ID.
	 *
	 * @return int
	 */
	private function get_requested_role_id() {
		if ( ! isset( $_POST['scai_role_id'] ) || ! is_scalar( $_POST['scai_role_id'] ) ) {
			return 0;
		}

		return absint( wp_unslash( $_POST['scai_role_id'] ) );
	}

	/**
	 * Determine whether summary generation was requested.
	 *
	 * @return bool
	 */
	private function is_summary_requested() {
		return isset( $_POST[ self::SUMMARY_SUBMIT_NAME ] );
	}

	/**
	 * Determine whether reply generation was requested.
	 *
	 * @return bool
	 */
	private function is_reply_requested() {
		return isset( $_POST[ self::REPLY_SUBMIT_NAME ] );
	}

	/**
	 * Handle AI diagnostics action.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return void
	 */
	private function handle_ai_action( $ticket_id ) {
		$ticket_ai_service = $this->get_ticket_ai_service();

		if ( ! $ticket_ai_service ) {
			$this->render_notice( __( 'Ticket AI service is unavailable.', 'supportcandy-ai' ), 'error' );
			return;
		}

		if ( $this->is_summary_requested() ) {
			$response = $ticket_ai_service->generate_ticket_summary( $ticket_id );
			$this->render_ai_result( __( 'Generated Ticket Summary', 'supportcandy-ai' ), $response );
			return;
		}

		if ( $this->is_reply_requested() ) {
			$response = $ticket_ai_service->generate_reply( $ticket_id );
			$this->render_ai_result( __( 'Generated Suggested Reply', 'supportcandy-ai' ), $response );
		}
	}

	/**
	 * Render AI response result.
	 *
	 * @param string           $title    Result title.
	 * @param SCAI_AI_Response $response AI response.
	 * @return void
	 */
	private function render_ai_result( $title, $response ) {
		if ( ! $response instanceof SCAI_AI_Response ) {
			$this->render_notice( __( 'AI service returned an invalid response.', 'supportcandy-ai' ), 'error' );
			return;
		}

		if ( ! $response->is_success() ) {
			$error_message = $response->get_error_message();

			if ( '' === $error_message ) {
				$error_message = __( 'AI request failed.', 'supportcandy-ai' );
			}

			$this->render_notice( $error_message, 'error' );
			return;
		}

		$content      = $response->get_content();
		$provider     = $response->get_provider();
		$model        = $response->get_model();
		$total_tokens = $response->get_total_tokens();
		$duration_ms  = $response->get_duration_ms();
		?>
		<h2><?php echo esc_html( $title ); ?></h2>

		<table class="widefat striped" style="max-width: 760px;">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Provider', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( '' !== $provider ? $provider : __( 'Unknown', 'supportcandy-ai' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Model', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( '' !== $model ? $model : __( 'Unknown', 'supportcandy-ai' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Tokens', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( (string) absint( $total_tokens ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Duration', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( sprintf( '%d ms', absint( $duration_ms ) ) ); ?></td>
				</tr>
			</tbody>
		</table>

		<h3><?php echo esc_html__( 'AI Output', 'supportcandy-ai' ); ?></h3>
		<textarea class="large-text code" rows="14" readonly><?php echo esc_textarea( $content ); ?></textarea>
		<?php
	}

	/**
	 * Verify submitted diagnostics request.
	 *
	 * @return bool
	 */
	private function verify_request() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return false;
		}

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		return (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION );
	}

	/**
	 * Format context preview as escaped JSON-ready text.
	 *
	 * @param array<string, mixed> $context Normalized ticket context.
	 * @return string
	 */
	private function format_context_preview( array $context ) {
		$context = $this->sanitize_preview_data( $context );
		$json    = wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Get safe active-provider image capability diagnostics.
	 *
	 * @return array{label: string, supports_images: string}
	 */
	private function get_image_debug_provider_status() {
		$status = array(
			'label'           => __( 'Not available', 'supportcandy-ai' ),
			'supports_images' => __( 'Unknown', 'supportcandy-ai' ),
		);

		if ( ! class_exists( 'SCAI_Provider_Manager' ) ) {
			return $status;
		}

		$manager      = new SCAI_Provider_Manager();
		$provider_key = sanitize_key( $manager->get_active_provider_key() );
		$provider     = $manager->get_active_provider();

		if ( '' !== $provider_key ) {
			$status['label'] = $provider_key;
		}

		if ( ! $provider ) {
			return $status;
		}

		if ( method_exists( $provider, 'get_name' ) ) {
			$name = sanitize_text_field( $provider->get_name() );

			if ( '' !== $name ) {
				$status['label'] = '' !== $provider_key ? sprintf( '%1$s (%2$s)', $name, $provider_key ) : $name;
			}
		}

		if ( method_exists( $provider, 'supports_images' ) ) {
			$status['supports_images'] = $this->format_bool( (bool) $provider->supports_images() );
		}

		return $status;
	}

	/**
	 * Determine whether attachment metadata describes an image.
	 *
	 * @param array<string, mixed> $attachment Attachment metadata.
	 * @return bool
	 */
	private function is_image_attachment_for_debug( array $attachment ) {
		$filename  = isset( $attachment['filename'] ) && is_scalar( $attachment['filename'] ) ? sanitize_file_name( (string) $attachment['filename'] ) : '';
		$mime_type = isset( $attachment['mime_type'] ) && is_scalar( $attachment['mime_type'] ) ? sanitize_mime_type( (string) $attachment['mime_type'] ) : '';
		$extension = sanitize_key( strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) ) );

		return 0 === strpos( $mime_type, 'image/' ) || in_array( $extension, array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'heic', 'tif', 'tiff', 'bmp' ), true );
	}

	/**
	 * Prepare one attachment row for diagnostics display.
	 *
	 * @param mixed $attachment Attachment data.
	 * @return array<string, mixed>
	 */
	private function prepare_attachment_debug_row( $attachment ) {
		$attachment = is_array( $attachment ) ? $attachment : array();
		$filename   = isset( $attachment['filename'] ) && is_scalar( $attachment['filename'] ) ? sanitize_file_name( $attachment['filename'] ) : '';
		$title      = isset( $attachment['title'] ) && is_scalar( $attachment['title'] ) ? sanitize_text_field( $attachment['title'] ) : '';
		$filename   = '' !== $filename ? $filename : $title;
		$extension  = sanitize_key( strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) ) );
		$mime_type  = isset( $attachment['mime_type'] ) && is_scalar( $attachment['mime_type'] ) ? sanitize_mime_type( $attachment['mime_type'] ) : '';
		$type       = $this->classify_attachment_for_debug( $mime_type, $extension );
		$path       = $this->find_attachment_path_value( $attachment );
		$url        = isset( $attachment['url'] ) && is_scalar( $attachment['url'] ) ? esc_url_raw( $attachment['url'], array( 'http', 'https' ) ) : '';

		return array(
			'id'            => isset( $attachment['id'] ) ? absint( $attachment['id'] ) : 0,
			'thread_id'     => isset( $attachment['thread_id'] ) ? absint( $attachment['thread_id'] ) : 0,
			'filename'      => $filename,
			'mime_type'     => $mime_type,
			'extension'     => $extension,
			'type'          => $type,
			'text_readable' => in_array( $extension, array( 'txt', 'log', 'csv', 'json', 'xml', 'html', 'md' ), true ),
			'url'           => $url,
			'path_present'  => '' !== $path,
			'path_preview'  => $this->mask_attachment_path( $path ),
			'size'          => isset( $attachment['size'] ) ? absint( $attachment['size'] ) : 0,
			'created_at'    => isset( $attachment['created_at'] ) && is_scalar( $attachment['created_at'] ) ? sanitize_text_field( $attachment['created_at'] ) : '',
		);
	}

	/**
	 * Classify an attachment for diagnostics.
	 *
	 * @param string $mime_type MIME type.
	 * @param string $extension File extension.
	 * @return string
	 */
	private function classify_attachment_for_debug( $mime_type, $extension ) {
		$mime_type = sanitize_mime_type( $mime_type );
		$extension = sanitize_key( $extension );

		if ( 0 === strpos( $mime_type, 'image/' ) || in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg' ), true ) ) {
			return 'image';
		}

		if ( 'application/pdf' === $mime_type || 'pdf' === $extension ) {
			return 'pdf';
		}

		if ( in_array( $extension, array( 'csv', 'xls', 'xlsx', 'ods' ), true ) ) {
			return 'spreadsheet';
		}

		if ( in_array( $extension, array( 'txt', 'log', 'json', 'xml', 'html', 'md' ), true ) || 0 === strpos( $mime_type, 'text/' ) ) {
			return 'text';
		}

		if ( in_array( $extension, array( 'doc', 'docx', 'odt', 'rtf' ), true ) ) {
			return 'document';
		}

		if ( in_array( $extension, array( 'zip', 'rar', '7z', 'tar', 'gz' ), true ) ) {
			return 'archive';
		}

		return 'other';
	}

	/**
	 * Find a path-like value without accessing the filesystem.
	 *
	 * @param array<string|int, mixed> $data Attachment data.
	 * @return string
	 */
	private function find_attachment_path_value( array $data ) {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && $this->is_attachment_path_key( $key ) && is_scalar( $value ) ) {
				return sanitize_text_field( (string) $value );
			}

			if ( is_array( $value ) ) {
				$path = $this->find_attachment_path_value( $value );

				if ( '' !== $path ) {
					return $path;
				}
			}
		}

		return '';
	}

	/**
	 * Mask an attachment path to its final directory segments.
	 *
	 * @param string $path Local path.
	 * @return string
	 */
	private function mask_attachment_path( $path ) {
		$path = str_replace( '\\', '/', sanitize_text_field( (string) $path ) );

		if ( '' === $path ) {
			return '';
		}

		$parts = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		$parts = array_slice( $parts, -3 );

		return '.../' . implode( '/', array_map( 'sanitize_file_name', $parts ) );
	}

	/**
	 * Format safe attachment debug data as pretty JSON.
	 *
	 * @param array<string, mixed> $data Debug data.
	 * @return string
	 */
	private function format_attachment_debug_json( array $data ) {
		$json = wp_json_encode( $this->sanitize_attachment_debug_data( $data ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Recursively sanitize attachment debug data and mask paths.
	 *
	 * @param mixed $data Debug data.
	 * @return mixed
	 */
	private function sanitize_attachment_debug_data( $data ) {
		if ( is_array( $data ) ) {
			$clean = array();

			foreach ( $data as $key => $value ) {
				$clean_key = is_string( $key ) ? sanitize_key( $key ) : absint( $key );

				if ( is_string( $clean_key ) && $this->is_attachment_path_key( $clean_key ) ) {
					$clean[ $clean_key ] = is_scalar( $value ) ? $this->mask_attachment_path( (string) $value ) : '';
					continue;
				}

				if ( is_string( $clean_key ) && $this->is_sensitive_preview_key( $clean_key ) ) {
					continue;
				}

				$clean[ $clean_key ] = $this->sanitize_attachment_debug_data( $value );
			}

			return $clean;
		}

		if ( is_bool( $data ) || is_int( $data ) || is_float( $data ) || null === $data ) {
			return $data;
		}

		return sanitize_textarea_field( (string) $data );
	}

	/**
	 * Determine whether a field contains a local path.
	 *
	 * @param string $key Field key.
	 * @return bool
	 */
	private function is_attachment_path_key( $key ) {
		$key = sanitize_key( $key );

		return in_array( $key, array( 'file_path', 'filepath', 'local_path', 'path', 'private_path' ), true );
	}

	/**
	 * Search SupportCandy-related tables for an identifier value.
	 *
	 * @param string $identifier Identifier value.
	 * @return array<string, mixed>
	 */
	private function search_supportcandy_tables_for_identifier( $identifier ) {
		global $wpdb;

		$identifier = trim( sanitize_text_field( (string) $identifier ) );

		if ( '' === $identifier ) {
			return array();
		}

		$tables       = $this->get_supportcandy_related_tables();
		$matches      = array();
		$total_limit  = 50;
		$column_limit = 5;

		foreach ( $tables as $table_name ) {
			$columns = $this->get_searchable_table_columns( $table_name );

			foreach ( $columns as $column ) {
				if ( count( $matches ) >= $total_limit ) {
					break 2;
				}

				$sql = "SELECT * FROM `{$table_name}` WHERE CAST(`{$column}` AS CHAR) LIKE %s LIMIT %d";

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names are sanitized from schema metadata.
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, '%' . $wpdb->esc_like( $identifier ) . '%', $column_limit ), ARRAY_A );

				if ( ! is_array( $rows ) || empty( $rows ) ) {
					continue;
				}

				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}

					$matches[] = array(
						'table'       => $table_name,
						'column'      => $column,
						'matched_row' => isset( $row['id'] ) ? absint( $row['id'] ) : 0,
						'row_preview' => $this->sanitize_preview_data( $row ),
					);

					if ( count( $matches ) >= $total_limit ) {
						break 3;
					}
				}
			}
		}

		if ( empty( $matches ) ) {
			return array();
		}

		return array(
			'identifier'      => $identifier,
			'tables_searched' => $tables,
			'result_count'    => count( $matches ),
			'results'         => $matches,
		);
	}

	/**
	 * Get current WordPress user diagnostics.
	 *
	 * @return array<string, mixed>
	 */
	private function get_current_user_debug_data() {
		$user = wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return array(
				'id'                   => 0,
				'username'             => '',
				'roles'                => array(),
				'capabilities_summary' => array(),
			);
		}

		$capabilities = array();
		$allcaps      = is_array( $user->allcaps ) ? $user->allcaps : array();

		foreach ( $allcaps as $capability => $enabled ) {
			if ( ! $enabled ) {
				continue;
			}

			$capability = sanitize_key( $capability );

			if ( '' !== $capability ) {
				$capabilities[] = $capability;
			}
		}

		sort( $capabilities );

		return array(
			'id'                   => absint( $user->ID ),
			'username'             => sanitize_user( $user->user_login, true ),
			'roles'                => array_map( 'sanitize_key', (array) $user->roles ),
			'capabilities_summary' => $capabilities,
		);
	}

	/**
	 * Get SupportCandy-related tables for role/capability diagnostics.
	 *
	 * @return array<int, string>
	 */
	private function get_role_debug_tables() {
		global $wpdb;

		$rows = $wpdb->get_col( 'SHOW TABLES' );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$tables = array();

		foreach ( $rows as $table_name ) {
			$table_name = $this->sanitize_database_identifier( $table_name );

			if ( '' === $table_name || ! $this->is_role_debug_table_name( $table_name ) ) {
				continue;
			}

			$tables[] = $table_name;
		}

		sort( $tables );
		$tables = array_values( array_unique( $tables ) );

		/**
		 * Filter SupportCandy tables inspected by role/capability diagnostics.
		 *
		 * @param array<int, string> $tables Sanitized table names.
		 */
		$tables = apply_filters( 'scai_diagnostics_supportcandy_role_debug_tables', $tables );

		if ( ! is_array( $tables ) ) {
			return array();
		}

		$sanitized_tables = array();

		foreach ( $tables as $table_name ) {
			$table_name = $this->sanitize_database_identifier( $table_name );

			if ( '' !== $table_name ) {
				$sanitized_tables[] = $table_name;
			}
		}

		sort( $sanitized_tables );

		return array_values( array_unique( $sanitized_tables ) );
	}

	/**
	 * Determine whether a table name should be included in role/capability debug.
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	private function is_role_debug_table_name( $table_name ) {
		$table_name = strtolower( $this->sanitize_database_identifier( $table_name ) );

		return false !== strpos( $table_name, 'wpsc' )
			|| false !== strpos( $table_name, 'supportcandy' )
			|| false !== strpos( $table_name, 'psmsc' );
	}

	/**
	 * Get role/capability-related columns grouped by SupportCandy table.
	 *
	 * @return array<string, mixed>
	 */
	private function get_role_debug_columns_by_table() {
		$columns_by_table = array();

		foreach ( $this->get_role_debug_tables() as $table_name ) {
			$matching_columns = array();

			foreach ( $this->get_table_columns_metadata( $table_name ) as $column ) {
				if ( empty( $column['field'] ) || ! $this->is_role_debug_column_name( $column['field'] ) ) {
					continue;
				}

				$matching_columns[] = $column;
			}

			$columns_by_table[ $table_name ] = $matching_columns;
		}

		return $columns_by_table;
	}

	/**
	 * Get current-user row matches from SupportCandy-related tables.
	 *
	 * @return array<string, mixed>
	 */
	private function get_current_user_role_debug_matches() {
		global $wpdb;

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return array(
				'user_id'      => 0,
				'result_count' => 0,
				'results'      => array(),
			);
		}

		$matches         = array();
		$total_limit     = 50;
		$per_table_limit = 20;

		foreach ( $this->get_role_debug_tables() as $table_name ) {
			$table_matches = 0;

			foreach ( $this->get_current_user_match_columns( $table_name ) as $column ) {
				if ( count( $matches ) >= $total_limit || $table_matches >= $per_table_limit ) {
					break;
				}

				$remaining_table_limit = min( $per_table_limit - $table_matches, $total_limit - count( $matches ) );
				$sql                   = "SELECT * FROM `{$table_name}` WHERE CAST(`{$column}` AS CHAR) = %s LIMIT %d";

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names are sanitized from schema metadata.
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, (string) absint( $user_id ), absint( $remaining_table_limit ) ), ARRAY_A );

				if ( ! is_array( $rows ) || empty( $rows ) ) {
					continue;
				}

				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}

					$preview = $this->sanitize_preview_data( $row );

					$matches[] = array(
						'table'            => $table_name,
						'matched_column'   => $column,
						'row_preview_json' => $this->encode_preview_json( $preview ),
					);

					$table_matches++;

					if ( count( $matches ) >= $total_limit || $table_matches >= $per_table_limit ) {
						break 2;
					}
				}
			}
		}

		return array(
			'user_id'      => absint( $user_id ),
			'result_count' => count( $matches ),
			'results'      => $matches,
		);
	}

	/**
	 * Get columns that may reference the current WordPress user.
	 *
	 * @param string $table_name Table name.
	 * @return array<int, string>
	 */
	private function get_current_user_match_columns( $table_name ) {
		$match_columns = array();

		foreach ( $this->get_table_columns_metadata( $table_name ) as $column ) {
			if ( empty( $column['field'] ) ) {
				continue;
			}

			if ( $this->is_current_user_match_column_name( $column['field'] ) ) {
				$match_columns[] = $column['field'];
			}
		}

		return array_values( array_unique( $match_columns ) );
	}

	/**
	 * Get table column metadata.
	 *
	 * @param string $table_name Table name.
	 * @return array<int, array<string, string>>
	 */
	private function get_table_columns_metadata( $table_name ) {
		global $wpdb;

		$table_name = $this->sanitize_database_identifier( $table_name );

		if ( '' === $table_name ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT COLUMN_NAME AS Field, COLUMN_TYPE AS Type, COLUMN_KEY AS `Key` FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
				$table_name
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$columns = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['Field'] ) ) {
				continue;
			}

			$columns[] = array(
				'field' => $this->sanitize_database_identifier( $row['Field'] ),
				'type'  => isset( $row['Type'] ) ? sanitize_text_field( (string) $row['Type'] ) : '',
				'key'   => isset( $row['Key'] ) ? sanitize_text_field( (string) $row['Key'] ) : '',
			);
		}

		return $columns;
	}

	/**
	 * Determine whether a column name is relevant for role/capability inspection.
	 *
	 * @param string $column_name Column name.
	 * @return bool
	 */
	private function is_role_debug_column_name( $column_name ) {
		$column_name = strtolower( $this->sanitize_database_identifier( $column_name ) );
		$keywords    = array(
			'agent',
			'role',
			'capability',
			'permission',
			'user',
			'customer',
			'access',
			'group',
		);

		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $column_name, $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether a column may reference the current WordPress user.
	 *
	 * @param string $column_name Column name.
	 * @return bool
	 */
	private function is_current_user_match_column_name( $column_name ) {
		$column_name = strtolower( $this->sanitize_database_identifier( $column_name ) );

		return in_array(
			$column_name,
			array(
				'user',
				'user_id',
				'agent',
				'agent_id',
				'wp_user_id',
				'customer_id',
				'id',
			),
			true
		);
	}

	/**
	 * Get the current user's SupportCandy agent assignment row.
	 *
	 * @return array<string, mixed>
	 */
	private function get_current_user_supportcandy_agent_row() {
		global $wpdb;

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return array();
		}

		$expected_table = $this->sanitize_database_identifier( $wpdb->prefix . 'psmsc_agents' );
		$agent_table    = '';

		foreach ( $this->get_role_debug_tables() as $table_name ) {
			if ( $table_name === $expected_table || 'psmsc_agents' === substr( $table_name, -13 ) ) {
				$agent_table = $table_name;
				break;
			}
		}

		if ( '' === $agent_table ) {
			return array(
				'table' => $expected_table,
				'found' => false,
			);
		}

		$sql = "SELECT `id`, `user`, `customer`, `role`, `is_agentgroup`, `is_active` FROM `{$agent_table}` WHERE `user` = %d LIMIT 1";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is sanitized and selected from database metadata.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, absint( $user_id ) ), ARRAY_A );

		return array(
			'table' => $agent_table,
			'found' => is_array( $row ) && ! empty( $row ),
			'row'   => is_array( $row ) ? $this->prepare_role_definition_preview( $row ) : array(),
			'role'  => is_array( $row ) && isset( $row['role'] ) ? absint( $row['role'] ) : 0,
		);
	}

	/**
	 * Get possible role-definition columns grouped by SupportCandy table.
	 *
	 * @return array<string, mixed>
	 */
	private function get_role_definition_columns_by_table() {
		$columns_by_table = array();

		foreach ( $this->get_role_debug_tables() as $table_name ) {
			$columns = array();

			foreach ( $this->get_table_columns_metadata( $table_name ) as $column ) {
				if ( ! empty( $column['field'] ) && $this->is_role_definition_column_name( $column['field'] ) ) {
					$columns[] = $column;
				}
			}

			if ( ! empty( $columns ) ) {
				$columns_by_table[ $table_name ] = $columns;
			}
		}

		return $columns_by_table;
	}

	/**
	 * Search possible SupportCandy role definition storage.
	 *
	 * @param int $role_id SupportCandy role ID.
	 * @return array<string, mixed>
	 */
	private function search_role_definition_rows( $role_id ) {
		global $wpdb;

		$role_id = absint( $role_id );
		$matches = array();
		$limit   = 50;

		foreach ( $this->get_role_debug_tables() as $table_name ) {
			$metadata       = $this->get_table_columns_metadata( $table_name );
			$search_columns = array();
			$has_role_data  = false;

			foreach ( $metadata as $column ) {
				if ( empty( $column['field'] ) || ! $this->is_searchable_column_type( $column['type'] ) ) {
					continue;
				}

				if ( $this->is_role_definition_column_name( $column['field'] ) ) {
					$search_columns[] = $column['field'];
					$has_role_data    = true;
				}
			}

			if ( $has_role_data || false !== strpos( strtolower( $table_name ), 'role' ) ) {
				foreach ( $metadata as $column ) {
					if ( ! empty( $column['field'] ) && in_array( strtolower( $column['field'] ), array( 'id', 'role_id' ), true ) ) {
						$search_columns[] = $column['field'];
					}
				}
			}

			if ( $this->is_psmsc_options_table( $table_name ) ) {
				$search_columns = array_merge( $search_columns, $this->get_option_search_columns( $metadata ) );
			}

			$search_columns = array_values( array_unique( $search_columns ) );

			if ( $role_id ) {
				foreach ( $search_columns as $column_name ) {
					if ( count( $matches ) >= $limit ) {
						break 2;
					}

					$sql = "SELECT * FROM `{$table_name}` WHERE CAST(`{$column_name}` AS CHAR) = %s OR CAST(`{$column_name}` AS CHAR) LIKE %s LIMIT %d";

					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column identifiers are sanitized from database metadata.
					$rows = $wpdb->get_results( $wpdb->prepare( $sql, (string) $role_id, '%' . $wpdb->esc_like( (string) $role_id ) . '%', 5 ), ARRAY_A );
					$this->append_role_definition_matches( $matches, $rows, $table_name, $column_name, $limit );
				}
			}

			if ( $this->is_psmsc_options_table( $table_name ) && count( $matches ) < $limit ) {
				$option_columns = $this->get_option_search_columns( $metadata );

				foreach ( $option_columns as $column_name ) {
					if ( count( $matches ) >= $limit ) {
						break 2;
					}

					$clauses = array();
					$args    = array();

					foreach ( array( 'role', 'roles', 'agent', 'capability', 'permission' ) as $keyword ) {
						$clauses[] = "CAST(`{$column_name}` AS CHAR) LIKE %s";
						$args[]    = '%' . $wpdb->esc_like( $keyword ) . '%';
					}

					$args[] = min( 10, $limit - count( $matches ) );
					$sql    = "SELECT * FROM `{$table_name}` WHERE " . implode( ' OR ', $clauses ) . ' LIMIT %d';

					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column identifiers are sanitized from database metadata.
					$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
					$this->append_role_definition_matches( $matches, $rows, $table_name, $column_name, $limit );
				}
			}
		}

		return array(
			'maximum_matches' => $limit,
			'result_count'    => count( $matches ),
			'results'         => $matches,
		);
	}

	/**
	 * Append sanitized role definition rows to a bounded result list.
	 *
	 * @param array<int, mixed> $matches     Result list.
	 * @param mixed             $rows        Database rows.
	 * @param string            $table_name  Database table name.
	 * @param string            $column_name Matched column name.
	 * @param int               $limit       Maximum total matches.
	 * @return void
	 */
	private function append_role_definition_matches( array &$matches, $rows, $table_name, $column_name, $limit ) {
		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || count( $matches ) >= $limit ) {
				break;
			}

			$decoded = array();

			foreach ( $row as $key => $value ) {
				if ( ! is_scalar( $value ) || $this->is_sensitive_preview_key( sanitize_key( $key ) ) ) {
					continue;
				}

				$decoded_value = $this->safe_decode_maybe_serialized_value( (string) $value );

				if ( null !== $decoded_value ) {
					$decoded[ sanitize_key( $key ) ] = $decoded_value;
				}
			}

			$matches[] = array(
				'table'                => $this->sanitize_database_identifier( $table_name ),
				'possible_column'      => $this->sanitize_database_identifier( $column_name ),
				'row_preview_json'     => $this->encode_limited_preview_json( $this->prepare_role_definition_preview( $row ) ),
				'decoded_safe_preview' => $this->prepare_role_definition_preview( $decoded ),
			);
		}
	}

	/**
	 * Safely decode a serialized or JSON value for diagnostics.
	 *
	 * @param string $value Stored value.
	 * @return mixed|null
	 */
	private function safe_decode_maybe_serialized_value( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		if ( is_serialized( $value ) ) {
			$decoded = maybe_unserialize( $value );
			return $this->prepare_role_definition_preview( is_object( $decoded ) ? (array) $decoded : $decoded );
		}

		$decoded = json_decode( $value, true );

		if ( JSON_ERROR_NONE === json_last_error() && ( is_array( $decoded ) || is_object( $decoded ) ) ) {
			return $this->prepare_role_definition_preview( (array) $decoded );
		}

		return null;
	}

	/**
	 * Prepare bounded, sanitized role-definition preview data.
	 *
	 * @param mixed $data Preview data.
	 * @return mixed
	 */
	private function prepare_role_definition_preview( $data ) {
		if ( is_object( $data ) ) {
			$data = (array) $data;
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->prepare_role_definition_preview( $value );
			}

			return $this->sanitize_preview_data( $data );
		}

		$data = $this->sanitize_preview_data( $data );

		if ( is_string( $data ) && strlen( $data ) > 1500 ) {
			return substr( $data, 0, 1500 ) . '...';
		}

		return $data;
	}

	/**
	 * Encode a bounded JSON row preview.
	 *
	 * @param mixed $preview Sanitized preview.
	 * @return string
	 */
	private function encode_limited_preview_json( $preview ) {
		$json = wp_json_encode( $preview, JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $json ) ) {
			return '{}';
		}

		return strlen( $json ) > 5000 ? substr( $json, 0, 5000 ) . '...' : $json;
	}

	/**
	 * Determine whether a column may contain role definition data.
	 *
	 * @param string $column_name Column name.
	 * @return bool
	 */
	private function is_role_definition_column_name( $column_name ) {
		$column_name = strtolower( $this->sanitize_database_identifier( $column_name ) );

		foreach ( array( 'role', 'roles', 'capability', 'capabilities', 'permission', 'permissions', 'access', 'agent' ) as $keyword ) {
			if ( false !== strpos( $column_name, $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether a table is the SupportCandy options table.
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	private function is_psmsc_options_table( $table_name ) {
		return 'psmsc_options' === substr( strtolower( $this->sanitize_database_identifier( $table_name ) ), -14 );
	}

	/**
	 * Get option-like searchable columns from table metadata.
	 *
	 * @param array<int, array<string, string>> $metadata Column metadata.
	 * @return array<int, string>
	 */
	private function get_option_search_columns( array $metadata ) {
		$columns = array();

		foreach ( $metadata as $column ) {
			if ( empty( $column['field'] ) || ! $this->is_searchable_column_type( $column['type'] ) ) {
				continue;
			}

			$field = strtolower( $this->sanitize_database_identifier( $column['field'] ) );

			foreach ( array( 'option', 'name', 'key', 'value' ) as $keyword ) {
				if ( false !== strpos( $field, $keyword ) ) {
					$columns[] = $field;
					break;
				}
			}
		}

		return array_values( array_unique( $columns ) );
	}

	/**
	 * Encode a row preview as JSON text.
	 *
	 * @param mixed $preview Sanitized preview data.
	 * @return string
	 */
	private function encode_preview_json( $preview ) {
		$json = wp_json_encode( $preview, JSON_UNESCAPED_SLASHES );

		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Get database tables that appear related to SupportCandy.
	 *
	 * @return array<int, string>
	 */
	private function get_supportcandy_related_tables() {
		global $wpdb;

		$rows = $wpdb->get_col( 'SHOW TABLES' );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$tables = array();

		foreach ( $rows as $table_name ) {
			$table_name = $this->sanitize_database_identifier( $table_name );

			if ( '' === $table_name || ! $this->is_supportcandy_related_table_name( $table_name ) ) {
				continue;
			}

			$tables[] = $table_name;
		}

		sort( $tables );

		return array_values( array_unique( $tables ) );
	}

	/**
	 * Determine whether a table name appears related to SupportCandy.
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	private function is_supportcandy_related_table_name( $table_name ) {
		$table_name = strtolower( $this->sanitize_database_identifier( $table_name ) );

		return false !== strpos( $table_name, 'wpsc' )
			|| false !== strpos( $table_name, 'supportcandy' )
			|| false !== strpos( $table_name, 'psmsc' );
	}

	/**
	 * Get searchable text/integer columns for a table.
	 *
	 * @param string $table_name Table name.
	 * @return array<int, string>
	 */
	private function get_searchable_table_columns( $table_name ) {
		global $wpdb;

		$table_name = $this->sanitize_database_identifier( $table_name );

		if ( '' === $table_name ) {
			return array();
		}

		$sql = "SHOW COLUMNS FROM `{$table_name}`";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is sanitized from schema metadata.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$columns = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['Field'] ) || empty( $row['Type'] ) ) {
				continue;
			}

			if ( ! $this->is_searchable_column_type( $row['Type'] ) ) {
				continue;
			}

			$column = $this->sanitize_database_identifier( $row['Field'] );

			if ( '' !== $column ) {
				$columns[] = $column;
			}
		}

		return array_values( array_unique( $columns ) );
	}

	/**
	 * Determine whether a column type is safe and useful to search.
	 *
	 * @param string $type Database column type.
	 * @return bool
	 */
	private function is_searchable_column_type( $type ) {
		$type = strtolower( sanitize_text_field( (string) $type ) );

		return 1 === preg_match( '/^(var)?char|text|tinytext|mediumtext|longtext|int|tinyint|smallint|mediumint|bigint/', $type );
	}

	/**
	 * Sanitize a database identifier.
	 *
	 * @param mixed $identifier Database identifier.
	 * @return string
	 */
	private function sanitize_database_identifier( $identifier ) {
		$identifier = (string) $identifier;
		$identifier = preg_replace( '/[^A-Za-z0-9_]/', '', $identifier );

		return is_string( $identifier ) ? $identifier : '';
	}

	/**
	 * Render a basic key/value table.
	 *
	 * @param array<string, mixed> $items Items to render.
	 * @return void
	 */
	private function render_key_value_table( array $items ) {
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'No data available.', 'supportcandy-ai' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped" style="max-width: 760px;">
			<tbody>
				<?php foreach ( $items as $key => $value ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( sanitize_text_field( (string) $key ) ); ?></th>
						<td><?php echo esc_html( is_bool( $value ) ? $this->format_bool( $value ) : sanitize_text_field( (string) $value ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render an admin notice.
	 *
	 * @param string $message Notice message.
	 * @param string $type    Notice type.
	 * @return void
	 */
	private function render_notice( $message, $type = 'info' ) {
		$type = sanitize_html_class( $type );

		if ( '' === $type ) {
			$type = 'info';
		}

		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Format boolean value for display.
	 *
	 * @param bool $value Boolean value.
	 * @return string
	 */
	private function format_bool( $value ) {
		return $value ? __( 'Yes', 'supportcandy-ai' ) : __( 'No', 'supportcandy-ai' );
	}

	/**
	 * Recursively sanitize preview data and remove sensitive path-like fields.
	 *
	 * @param mixed $data Preview data.
	 * @return mixed
	 */
	private function sanitize_preview_data( $data ) {
		if ( is_array( $data ) ) {
			$sanitized = array();

			foreach ( $data as $key => $value ) {
				$clean_key = is_string( $key ) ? sanitize_key( $key ) : absint( $key );

				if ( $this->is_sensitive_preview_key( $clean_key ) ) {
					continue;
				}

				$sanitized[ $clean_key ] = $this->sanitize_preview_data( $value );
			}

			return $sanitized;
		}

		if ( is_bool( $data ) || is_int( $data ) || is_float( $data ) || null === $data ) {
			return $data;
		}

		return sanitize_textarea_field( (string) $data );
	}

	/**
	 * Determine whether a preview key should be omitted.
	 *
	 * @param string $key Data key.
	 * @return bool
	 */
	private function is_sensitive_preview_key( $key ) {
		return in_array(
			sanitize_key( $key ),
			array(
				'auth_code',
				'api_key',
				'authorization',
				'bearer',
				'client_secret',
				'file_path',
				'filepath',
				'path',
				'password',
				'private_path',
				'provider_config',
				'secret',
				'local_path',
				'token',
			),
			true
		);
	}
}
