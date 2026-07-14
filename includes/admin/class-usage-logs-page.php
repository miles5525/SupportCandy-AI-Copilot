<?php
/**
 * Usage logs admin page for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders recent AI usage logs for administrators.
 */
final class SCAI_Usage_Logs_Page {

	/**
	 * Required fallback capability.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'scai-usage-logs';

	/**
	 * Usage logs table key.
	 *
	 * @var string
	 */
	const TABLE_KEY = 'usage_logs';

	/**
	 * Initialize page hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'maybe_handle_actions' ) );
	}

	/**
	 * Render the usage logs page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! $this->current_user_can_view_logs() ) {
			wp_die(
				esc_html__( 'You do not have permission to view AI usage logs.', 'supportcandy-ai' ),
				esc_html__( 'Permission denied', 'supportcandy-ai' ),
				array( 'response' => 403 )
			);
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'AI Usage Logs', 'supportcandy-ai' ); ?></h1>
			<p><?php echo esc_html__( 'View recent SupportCandy AI requests, token usage, status, and errors.', 'supportcandy-ai' ); ?></p>

			<?php
			$this->render_summary_cards();
			$this->render_filters();
			$this->render_table();
			?>
		</div>
		<?php
	}

	/**
	 * Handle page actions.
	 *
	 * The MVP page is read-only and currently has no state-changing actions.
	 *
	 * @return void
	 */
	public function maybe_handle_actions() {
		return;
	}

	/**
	 * Get filtered usage logs for the current page.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_logs() {
		global $wpdb;

		if ( ! $this->current_user_can_view_logs() ) {
			return array();
		}

		$table_name = $this->get_table_name();

		if ( '' === $table_name ) {
			return array();
		}

		$filters  = $this->get_filters();
		$where    = $this->build_where_clause( $filters );
		$per_page = $filters['per_page'];
		$offset   = ( $filters['paged'] - 1 ) * $per_page;
		$sql      = "SELECT `id`, `request_id`, `ticket_id`, `agent_id`, `feature`, `provider`, `model`, `total_tokens`, `estimated_cost`, `duration_ms`, `status`, `error_code`, `error_message`, `created_at` FROM `{$table_name}` {$where['sql']} ORDER BY `id` DESC LIMIT %d OFFSET %d";
		$values   = array_merge( $where['values'], array( $per_page, $offset ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is supplied by SCAI_Schema; filters, limit, and offset are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get the number of logs matching the current filters.
	 *
	 * @return int
	 */
	public function get_total_logs() {
		global $wpdb;

		if ( ! $this->current_user_can_view_logs() ) {
			return 0;
		}

		$table_name = $this->get_table_name();

		if ( '' === $table_name ) {
			return 0;
		}

		$where = $this->build_where_clause( $this->get_filters() );
		$sql   = "SELECT COUNT(*) FROM `{$table_name}` {$where['sql']}";

		if ( ! empty( $where['values'] ) ) {
			$sql = $wpdb->prepare( $sql, $where['values'] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is supplied by SCAI_Schema and filter values are prepared above.
		return absint( $wpdb->get_var( $sql ) );
	}

	/**
	 * Get aggregate status and token counts across all usage logs.
	 *
	 * @return array<string, int>
	 */
	public function get_status_counts() {
		global $wpdb;

		$counts = array(
			'total'        => 0,
			'success'      => 0,
			'error'        => 0,
			'total_tokens' => 0,
		);

		if ( ! $this->current_user_can_view_logs() ) {
			return $counts;
		}

		$table_name = $this->get_table_name();

		if ( '' === $table_name ) {
			return $counts;
		}

		$sql = "SELECT COUNT(*) AS `total`, SUM(CASE WHEN `status` = 'success' THEN 1 ELSE 0 END) AS `success`, SUM(CASE WHEN `status` = 'error' THEN 1 ELSE 0 END) AS `error`, COALESCE(SUM(`total_tokens`), 0) AS `total_tokens` FROM `{$table_name}`";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is supplied by SCAI_Schema and the query contains no external values.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return $counts;
		}

		foreach ( array_keys( $counts ) as $key ) {
			$counts[ $key ] = isset( $row[ $key ] ) ? absint( $row[ $key ] ) : 0;
		}

		return $counts;
	}

	/**
	 * Get a human-readable feature label.
	 *
	 * @param string $feature Feature key.
	 * @return string
	 */
	public function get_feature_label( $feature ) {
		$labels = array(
			'ticket_summary'    => __( 'Ticket summary', 'supportcandy-ai' ),
			'reply_generation'  => __( 'Reply generation', 'supportcandy-ai' ),
			'reply_improvement' => __( 'Reply improvement', 'supportcandy-ai' ),
		);
		$feature = sanitize_key( $feature );

		return isset( $labels[ $feature ] ) ? $labels[ $feature ] : $feature;
	}

	/**
	 * Get a human-readable status label.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public function get_status_label( $status ) {
		$status = sanitize_key( $status );

		if ( 'success' === $status ) {
			return __( 'Success', 'supportcandy-ai' );
		}

		if ( 'error' === $status ) {
			return __( 'Error', 'supportcandy-ai' );
		}

		return $status;
	}

	/**
	 * Format a token count.
	 *
	 * @param int $tokens Token count.
	 * @return string
	 */
	public function format_tokens( $tokens ) {
		return number_format_i18n( absint( $tokens ) );
	}

	/**
	 * Format a duration in milliseconds.
	 *
	 * @param int $duration_ms Duration in milliseconds.
	 * @return string
	 */
	public function format_duration( $duration_ms ) {
		$duration_ms = absint( $duration_ms );

		if ( 1000 <= $duration_ms ) {
			return sprintf( /* translators: %s: duration in seconds. */ __( '%s s', 'supportcandy-ai' ), number_format_i18n( $duration_ms / 1000, 2 ) );
		}

		return sprintf( /* translators: %s: duration in milliseconds. */ __( '%s ms', 'supportcandy-ai' ), number_format_i18n( $duration_ms ) );
	}

	/**
	 * Format an estimated cost.
	 *
	 * @param mixed $estimated_cost Estimated cost.
	 * @return string
	 */
	public function format_cost( $estimated_cost ) {
		$estimated_cost = is_numeric( $estimated_cost ) ? max( 0, (float) $estimated_cost ) : 0;

		return '$' . number_format_i18n( $estimated_cost, 8 );
	}

	/**
	 * Format a stored UTC date in the site's timezone.
	 *
	 * @param string $date MySQL UTC date.
	 * @return string
	 */
	public function format_date( $date ) {
		$date = sanitize_text_field( $date );

		if ( '' === $date ) {
			return '';
		}

		$local_date = get_date_from_gmt( $date, 'Y-m-d H:i:s' );
		$timestamp  = strtotime( $local_date );

		return $timestamp ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : '';
	}

	/**
	 * Render log filters.
	 *
	 * @return void
	 */
	public function render_filters() {
		$filters = $this->get_filters();
		?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<label for="scai-status"><?php echo esc_html__( 'Status', 'supportcandy-ai' ); ?></label>
			<select id="scai-status" name="status">
				<option value=""><?php echo esc_html__( 'All', 'supportcandy-ai' ); ?></option>
				<option value="success" <?php selected( $filters['status'], 'success' ); ?>><?php echo esc_html__( 'Success', 'supportcandy-ai' ); ?></option>
				<option value="error" <?php selected( $filters['status'], 'error' ); ?>><?php echo esc_html__( 'Error', 'supportcandy-ai' ); ?></option>
			</select>

			<label for="scai-feature"><?php echo esc_html__( 'Feature', 'supportcandy-ai' ); ?></label>
			<select id="scai-feature" name="feature">
				<option value=""><?php echo esc_html__( 'All', 'supportcandy-ai' ); ?></option>
				<?php foreach ( $this->get_allowed_features() as $feature ) : ?>
					<option value="<?php echo esc_attr( $feature ); ?>" <?php selected( $filters['feature'], $feature ); ?>><?php echo esc_html( $this->get_feature_label( $feature ) ); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="scai-ticket-id"><?php echo esc_html__( 'Ticket ID', 'supportcandy-ai' ); ?></label>
			<input id="scai-ticket-id" name="ticket_id" type="number" min="1" value="<?php echo esc_attr( $filters['ticket_id'] ? $filters['ticket_id'] : '' ); ?>">

			<label for="scai-agent-id"><?php echo esc_html__( 'Agent ID', 'supportcandy-ai' ); ?></label>
			<input id="scai-agent-id" name="agent_id" type="number" min="1" value="<?php echo esc_attr( $filters['agent_id'] ? $filters['agent_id'] : '' ); ?>">

			<label for="scai-per-page"><?php echo esc_html__( 'Per page', 'supportcandy-ai' ); ?></label>
			<select id="scai-per-page" name="per_page">
				<?php foreach ( array( 20, 50, 100 ) as $per_page ) : ?>
					<option value="<?php echo esc_attr( $per_page ); ?>" <?php selected( $filters['per_page'], $per_page ); ?>><?php echo esc_html( $per_page ); ?></option>
				<?php endforeach; ?>
			</select>

			<?php submit_button( __( 'Filter', 'supportcandy-ai' ), 'secondary', 'filter_action', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render usage summary cards.
	 *
	 * @return void
	 */
	public function render_summary_cards() {
		$counts = $this->get_status_counts();
		$cards  = array(
			__( 'Total logs', 'supportcandy-ai' )          => number_format_i18n( $counts['total'] ),
			__( 'Successful requests', 'supportcandy-ai' ) => number_format_i18n( $counts['success'] ),
			__( 'Failed requests', 'supportcandy-ai' )     => number_format_i18n( $counts['error'] ),
			__( 'Total tokens', 'supportcandy-ai' )        => $this->format_tokens( $counts['total_tokens'] ),
		);
		?>
		<div style="display:flex; flex-wrap:wrap; gap:12px; margin:16px 0;">
			<?php foreach ( $cards as $label => $value ) : ?>
				<div class="postbox" style="min-width:180px; margin:0; padding:16px;">
					<strong><?php echo esc_html( $label ); ?></strong><br>
					<span style="font-size:24px;"><?php echo esc_html( $value ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render the usage log table and pagination.
	 *
	 * @return void
	 */
	public function render_table() {
		$logs = $this->get_logs();

		if ( empty( $logs ) ) {
			$this->render_empty_state();
			return;
		}

		?>
		<table class="widefat striped" style="margin-top:16px;">
			<thead>
				<tr>
					<?php foreach ( array( __( 'Date', 'supportcandy-ai' ), __( 'Status', 'supportcandy-ai' ), __( 'Feature', 'supportcandy-ai' ), __( 'Ticket ID', 'supportcandy-ai' ), __( 'Agent ID', 'supportcandy-ai' ), __( 'Provider', 'supportcandy-ai' ), __( 'Model', 'supportcandy-ai' ), __( 'Tokens', 'supportcandy-ai' ), __( 'Duration', 'supportcandy-ai' ), __( 'Error', 'supportcandy-ai' ), __( 'Request ID', 'supportcandy-ai' ) ) as $heading ) : ?>
						<th scope="col"><?php echo esc_html( $heading ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) : ?>
					<?php
					$error_code    = isset( $log['error_code'] ) ? sanitize_key( $log['error_code'] ) : '';
					$error_message = isset( $log['error_message'] ) ? sanitize_text_field( $log['error_message'] ) : '';
					$error_message = wp_html_excerpt( $error_message, 120, '…' );
					?>
					<tr>
						<td><?php echo esc_html( $this->format_date( isset( $log['created_at'] ) ? $log['created_at'] : '' ) ); ?></td>
						<td><?php echo esc_html( $this->get_status_label( isset( $log['status'] ) ? $log['status'] : '' ) ); ?></td>
						<td><?php echo esc_html( $this->get_feature_label( isset( $log['feature'] ) ? $log['feature'] : '' ) ); ?></td>
						<td><?php echo esc_html( absint( isset( $log['ticket_id'] ) ? $log['ticket_id'] : 0 ) ); ?></td>
						<td><?php echo esc_html( absint( isset( $log['agent_id'] ) ? $log['agent_id'] : 0 ) ); ?></td>
						<td><?php echo esc_html( sanitize_text_field( isset( $log['provider'] ) ? $log['provider'] : '' ) ); ?></td>
						<td><?php echo esc_html( sanitize_text_field( isset( $log['model'] ) ? $log['model'] : '' ) ); ?></td>
						<td>
							<?php echo esc_html( $this->format_tokens( isset( $log['total_tokens'] ) ? $log['total_tokens'] : 0 ) ); ?>
							<?php if ( ! empty( $log['estimated_cost'] ) ) : ?>
								<br><small><?php echo esc_html( $this->format_cost( $log['estimated_cost'] ) ); ?></small>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $this->format_duration( isset( $log['duration_ms'] ) ? $log['duration_ms'] : 0 ) ); ?></td>
						<td>
							<?php if ( '' !== $error_code ) : ?><code><?php echo esc_html( $error_code ); ?></code><?php endif; ?>
							<?php if ( '' !== $error_message ) : ?><br><?php echo esc_html( $error_message ); ?><?php endif; ?>
						</td>
						<td><code><?php echo esc_html( sanitize_text_field( isset( $log['request_id'] ) ? $log['request_id'] : '' ) ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php $this->render_pagination(); ?>
		<?php
	}

	/**
	 * Render the no-results state.
	 *
	 * @return void
	 */
	public function render_empty_state() {
		?>
		<div class="notice notice-info inline" style="margin:16px 0 0;">
			<p><?php echo esc_html__( 'No AI usage logs found yet. Generate a ticket summary or reply to create the first log.', 'supportcandy-ai' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Check whether the current user can view usage logs.
	 *
	 * @return bool
	 */
	private function current_user_can_view_logs() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( class_exists( 'SCAI_Permissions' ) ) {
			$permissions = new SCAI_Permissions();

			return (bool) $permissions->current_user_can_view_diagnostics();
		}

		return current_user_can( self::CAPABILITY );
	}

	/**
	 * Get the schema-controlled table name.
	 *
	 * @return string
	 */
	private function get_table_name() {
		if ( ! class_exists( 'SCAI_Schema' ) ) {
			return '';
		}

		return SCAI_Schema::get_table_name( self::TABLE_KEY );
	}

	/**
	 * Get sanitized filters from the request.
	 *
	 * @return array<string, int|string>
	 */
	private function get_filters() {
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$feature  = isset( $_GET['feature'] ) ? sanitize_key( wp_unslash( $_GET['feature'] ) ) : '';
		$per_page = isset( $_GET['per_page'] ) ? absint( wp_unslash( $_GET['per_page'] ) ) : 20;

		if ( ! in_array( $status, array( 'success', 'error' ), true ) ) {
			$status = '';
		}

		if ( ! in_array( $feature, $this->get_allowed_features(), true ) ) {
			$feature = '';
		}

		if ( ! in_array( $per_page, array( 20, 50, 100 ), true ) ) {
			$per_page = 20;
		}

		return array(
			'status'    => $status,
			'feature'   => $feature,
			'ticket_id' => isset( $_GET['ticket_id'] ) ? absint( wp_unslash( $_GET['ticket_id'] ) ) : 0,
			'agent_id'  => isset( $_GET['agent_id'] ) ? absint( wp_unslash( $_GET['agent_id'] ) ) : 0,
			'per_page'  => min( 100, $per_page ),
			'paged'     => max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 ),
		);
	}

	/**
	 * Build a prepared-query-compatible WHERE clause.
	 *
	 * @param array<string, int|string> $filters Sanitized filters.
	 * @return array{sql: string, values: array<int, int|string>}
	 */
	private function build_where_clause( array $filters ) {
		$clauses = array();
		$values  = array();

		if ( '' !== $filters['status'] ) {
			$clauses[] = '`status` = %s';
			$values[]  = $filters['status'];
		}

		if ( '' !== $filters['feature'] ) {
			$clauses[] = '`feature` = %s';
			$values[]  = $filters['feature'];
		}

		if ( 0 < $filters['ticket_id'] ) {
			$clauses[] = '`ticket_id` = %d';
			$values[]  = $filters['ticket_id'];
		}

		if ( 0 < $filters['agent_id'] ) {
			$clauses[] = '`agent_id` = %d';
			$values[]  = $filters['agent_id'];
		}

		return array(
			'sql'    => empty( $clauses ) ? '' : 'WHERE ' . implode( ' AND ', $clauses ),
			'values' => $values,
		);
	}

	/**
	 * Get the supported feature filters.
	 *
	 * @return array<int, string>
	 */
	private function get_allowed_features() {
		return array( 'ticket_summary', 'reply_generation', 'reply_improvement' );
	}

	/**
	 * Render pagination links.
	 *
	 * @return void
	 */
	private function render_pagination() {
		$filters     = $this->get_filters();
		$total_pages = (int) ceil( $this->get_total_logs() / $filters['per_page'] );

		if ( 1 >= $total_pages ) {
			return;
		}

		$base_url = add_query_arg(
			array_filter(
				array(
					'page'      => self::PAGE_SLUG,
					'status'    => $filters['status'],
					'feature'   => $filters['feature'],
					'ticket_id' => $filters['ticket_id'],
					'agent_id'  => $filters['agent_id'],
					'per_page'  => $filters['per_page'],
				)
			),
			admin_url( 'admin.php' )
		);
		$links    = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%', $base_url ),
				'format'    => '',
				'current'   => $filters['paged'],
				'total'     => $total_pages,
				'prev_text' => __( '&laquo; Previous', 'supportcandy-ai' ),
				'next_text' => __( 'Next &raquo;', 'supportcandy-ai' ),
			)
		);

		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
	}
}
