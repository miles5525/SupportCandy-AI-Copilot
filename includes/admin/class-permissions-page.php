<?php
/**
 * AI permissions page for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles rendering and saving SupportCandy AI role permissions.
 */
final class SCAI_Permissions_Page {

	/**
	 * Cached SupportCandy role labels.
	 *
	 * @var array<int, string>|null
	 */
	private $role_labels = null;

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
	const PAGE_SLUG = 'scai-permissions';

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'scai_save_permissions';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'scai_permissions_nonce';

	/**
	 * Option containing allowed SupportCandy role IDs.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'scai_allowed_supportcandy_role_ids';

	/**
	 * Initialize permissions page hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'handle_request' ) );
	}

	/**
	 * Handle permissions form submission.
	 *
	 * @return void
	 */
	public function handle_request() {
		if ( ! isset( $_POST['scai_permissions_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to save AI permissions.', 'supportcandy-ai' ),
				esc_html__( 'Permission denied', 'supportcandy-ai' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );
		$this->save_allowed_role_ids_from_request();
		$this->redirect_with_message( 'permissions_saved' );
	}

	/**
	 * Render the AI permissions page.
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

		$role_choices     = $this->get_supportcandy_role_choices();
		$allowed_role_ids = $this->get_allowed_role_ids();
		$permission_mode  = empty( $allowed_role_ids ) ? 'all' : 'selected';
		$role_labels      = $this->get_supportcandy_role_labels();
		?>
		<div class="wrap scai-admin-page scai-permissions-page">
			<h1><?php echo esc_html__( 'AI Permissions', 'supportcandy-ai' ); ?></h1>

			<p><?php echo esc_html__( 'Choose which SupportCandy agent roles can use AI features on ticket pages.', 'supportcandy-ai' ); ?></p>
			<p><?php echo esc_html__( 'WordPress administrators do not bypass this setting. AI access is based on the user’s active SupportCandy agent role.', 'supportcandy-ai' ); ?></p>

			<?php if ( empty( $role_labels ) ) : ?>
				<div class="notice notice-info inline">
					<p><?php echo esc_html__( 'SupportCandy role names could not be detected, so numeric role labels are shown.', 'supportcandy-ai' ); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->render_notice(); ?>
			<?php $this->render_permission_summary( $allowed_role_ids, $role_labels ); ?>

			<hr />

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Agent access', 'supportcandy-ai' ); ?></th>
							<td>
								<label style="display: block; margin-bottom: 8px;">
									<input type="radio" name="scai_permission_mode" value="all" <?php checked( 'all', $permission_mode ); ?> />
									<?php echo esc_html__( 'Allow all active SupportCandy agents', 'supportcandy-ai' ); ?>
								</label>
								<label style="display: block;">
									<input type="radio" name="scai_permission_mode" value="selected" <?php checked( 'selected', $permission_mode ); ?> />
									<?php echo esc_html__( 'Allow selected SupportCandy roles only', 'supportcandy-ai' ); ?>
								</label>
								<p class="description"><?php echo esc_html__( 'Choose selected mode to enforce the role checkboxes below.', 'supportcandy-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Allowed SupportCandy roles', 'supportcandy-ai' ); ?></th>
							<td>
								<?php if ( empty( $role_choices ) ) : ?>
									<p><?php echo esc_html__( 'No SupportCandy agent role IDs were detected.', 'supportcandy-ai' ); ?></p>
								<?php else : ?>
									<fieldset class="scai-role-options">
										<?php foreach ( $role_choices as $role ) : ?>
											<label class="scai-role-option">
												<input type="checkbox" name="scai_allowed_supportcandy_role_ids[]" value="<?php echo esc_attr( $role['role_id'] ); ?>" <?php checked( in_array( $role['role_id'], $allowed_role_ids, true ) ); ?> />
												<span class="scai-role-content">
													<strong class="scai-role-title"><?php echo esc_html( $this->get_role_title( $role ) ); ?></strong>
													<span class="scai-role-meta scai-muted">
														<?php echo esc_html( sprintf( /* translators: %d: SupportCandy role ID. */ __( 'Role ID: %d', 'supportcandy-ai' ), absint( $role['role_id'] ) ) ); ?>
													</span>
													<span class="scai-role-meta">
														<?php echo esc_html( $this->get_active_agents_text( $role ) ); ?>
													</span>
												</span>
											</label>
										<?php endforeach; ?>
									</fieldset>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Save AI Permissions', 'supportcandy-ai' ), 'primary', 'scai_permissions_submit' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Get the SupportCandy agents table name.
	 *
	 * @return string
	 */
	private function get_agents_table_name() {
		global $wpdb;

		$table_name = apply_filters( 'scai_supportcandy_agents_table', $wpdb->prefix . 'psmsc_agents' );
		$table_name = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $table_name );

		return is_string( $table_name ) ? $table_name : '';
	}

	/**
	 * Determine whether a database table exists.
	 *
	 * @param string $table_name Database table name.
	 * @return bool
	 */
	private function table_exists( $table_name ) {
		global $wpdb;

		$table_name = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $table_name );

		if ( ! is_string( $table_name ) || '' === $table_name ) {
			return false;
		}

		$found_table = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table_name
			)
		);

		return is_string( $found_table ) && $found_table === $table_name;
	}

	/**
	 * Get distinct SupportCandy agent role choices.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_supportcandy_role_choices() {
		global $wpdb;

		$table_name = $this->get_agents_table_name();

		if ( '' === $table_name || ! $this->table_exists( $table_name ) ) {
			return array();
		}

		$sql = "SELECT `role`, `name`, `is_active` FROM `{$table_name}` WHERE `is_agentgroup` = 0 ORDER BY `role` ASC, `is_active` DESC, `name` ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table identifier is sanitized and filterable through this plugin only.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$choices = array();

		foreach ( $rows as $row ) {
			$role_id = isset( $row['role'] ) ? absint( $row['role'] ) : 0;

			if ( ! $role_id ) {
				continue;
			}

			if ( ! isset( $choices[ $role_id ] ) ) {
				$choices[ $role_id ] = array(
					'role_id'        => $role_id,
					'id'             => $role_id,
					'role_name'      => $this->get_detected_role_name( $role_id ),
					'active_count'   => 0,
					'inactive_count' => 0,
					'agent_names'    => array(),
				);
			}

			if ( ! empty( $row['is_active'] ) ) {
				$choices[ $role_id ]['active_count']++;

				$agent_name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';

				if ( '' !== $agent_name && count( $choices[ $role_id ]['agent_names'] ) < 5 ) {
					$choices[ $role_id ]['agent_names'][] = $agent_name;
				}
			} else {
				$choices[ $role_id ]['inactive_count']++;
			}
		}

		foreach ( $choices as $role_id => $role ) {
			$choices[ $role_id ]['label'] = $this->get_role_choice_label( $role );
		}

		return $choices;
	}

	/**
	 * Generate a readable SupportCandy role choice label.
	 *
	 * @param array<string, mixed> $role Role choice data.
	 * @return string
	 */
	private function get_role_choice_label( $role ) {
		$role_id        = isset( $role['role_id'] ) ? absint( $role['role_id'] ) : 0;
		$role_name      = isset( $role['role_name'] ) ? sanitize_text_field( (string) $role['role_name'] ) : '';
		$active_count   = isset( $role['active_count'] ) ? absint( $role['active_count'] ) : 0;
		$inactive_count = isset( $role['inactive_count'] ) ? absint( $role['inactive_count'] ) : 0;
		$agent_names    = isset( $role['agent_names'] ) && is_array( $role['agent_names'] ) ? $role['agent_names'] : array();
		$agent_names    = array_slice( array_filter( array_map( 'sanitize_text_field', $agent_names ) ), 0, 5 );
		$role_title     = '' !== $role_name
			? sprintf(
				/* translators: 1: Detected SupportCandy role name, 2: Role ID. */
				__( '%1$s (Role #%2$d)', 'supportcandy-ai' ),
				$role_name,
				$role_id
			)
			: sprintf(
				/* translators: %d: SupportCandy role ID. */
				__( 'Role #%d', 'supportcandy-ai' ),
				$role_id
			);
		$active_text    = sprintf(
			/* translators: %d: Number of active SupportCandy agents. */
			_n( '%d active agent', '%d active agents', $active_count, 'supportcandy-ai' ),
			$active_count
		);
		$label          = $role_title . ' — ' . $active_text;

		if ( ! empty( $agent_names ) ) {
			$label .= ': ' . implode( ', ', $agent_names );

			if ( $active_count > count( $agent_names ) ) {
				$label .= sprintf(
					/* translators: %d: Number of additional active agents not listed by name. */
					__( ' +%d more', 'supportcandy-ai' ),
					$active_count - count( $agent_names )
				);
			}
		}

		if ( $inactive_count ) {
			$label .= '; ' . sprintf(
				/* translators: %d: Number of inactive SupportCandy agents. */
				_n( '%d inactive agent', '%d inactive agents', $inactive_count, 'supportcandy-ai' ),
				$inactive_count
			);
		}

		/**
		 * Filter the readable SupportCandy role label shown on the permissions page.
		 *
		 * @param string               $label   Generated role label.
		 * @param array<string, mixed> $role    Sanitized role choice data.
		 * @param int                  $role_id SupportCandy role ID.
		 */
		$label = apply_filters( 'scai_supportcandy_role_label', $label, $role, $role_id );

		return sanitize_text_field( (string) $label );
	}

	/**
	 * Get a concise title for a SupportCandy role.
	 *
	 * @param array<string, mixed> $role Role choice data.
	 * @return string
	 */
	private function get_role_title( array $role ) {
		$role_id   = isset( $role['role_id'] ) ? absint( $role['role_id'] ) : 0;
		$role_name = isset( $role['role_name'] ) ? sanitize_text_field( (string) $role['role_name'] ) : '';

		return '' !== $role_name
			? $role_name
			: sprintf(
				/* translators: %d: SupportCandy role ID. */
				__( 'Role #%d', 'supportcandy-ai' ),
				$role_id
			);
	}

	/**
	 * Build readable active-agent information for a role card.
	 *
	 * @param array<string, mixed> $role Role choice data.
	 * @return string
	 */
	private function get_active_agents_text( array $role ) {
		$active_count = isset( $role['active_count'] ) ? absint( $role['active_count'] ) : 0;
		$agent_names  = isset( $role['agent_names'] ) && is_array( $role['agent_names'] )
			? array_slice( array_filter( array_map( 'sanitize_text_field', $role['agent_names'] ) ), 0, 5 )
			: array();
		$text         = sprintf(
			/* translators: %d: Number of active SupportCandy agents. */
			_n( 'Active agent: %d', 'Active agents: %d', $active_count, 'supportcandy-ai' ),
			$active_count
		);

		if ( ! empty( $agent_names ) ) {
			$text .= ' — ' . implode( ', ', $agent_names );

			if ( $active_count > count( $agent_names ) ) {
				$text .= sprintf(
					/* translators: %d: Number of additional active agents. */
					__( ' +%d more', 'supportcandy-ai' ),
					$active_count - count( $agent_names )
				);
			}
		}

		return sanitize_text_field( $text );
	}

	/**
	 * Get SupportCandy role labels from its configured roles or role tables.
	 *
	 * @return array<int, string>
	 */
	private function get_supportcandy_role_labels() {
		global $wpdb;

		if ( is_array( $this->role_labels ) ) {
			return $this->role_labels;
		}

		$labels = array();
		$roles  = get_option( 'wpsc-agent-roles', array() );

		if ( is_array( $roles ) ) {
			foreach ( $roles as $role_id => $role ) {
				$role_id = absint( $role_id );

				if ( ! $role_id || ! is_array( $role ) ) {
					continue;
				}

				foreach ( array( 'label', 'name', 'title', 'role' ) as $label_key ) {
					if ( ! empty( $role[ $label_key ] ) && is_scalar( $role[ $label_key ] ) ) {
						$labels[ $role_id ] = sanitize_text_field( (string) $role[ $label_key ] );
						break;
					}
				}
			}
		}

		foreach ( array( $wpdb->prefix . 'psmsc_roles', $wpdb->prefix . 'psmsc_agent_roles' ) as $table_name ) {
			if ( ! empty( $labels ) || ! $this->table_exists( $table_name ) ) {
				continue;
			}

			$labels = $this->get_role_labels_from_table( $table_name );
		}

		/**
		 * Filter detected SupportCandy role labels for display purposes.
		 *
		 * @param array<int, string> $labels Detected labels keyed by role ID.
		 */
		$labels = apply_filters( 'scai_supportcandy_role_labels', $labels );
		$clean  = array();

		if ( is_array( $labels ) ) {
			foreach ( $labels as $role_id => $label ) {
				$role_id = absint( $role_id );
				$label   = is_scalar( $label ) ? sanitize_text_field( (string) $label ) : '';

				if ( $role_id && '' !== $label ) {
					$clean[ $role_id ] = $label;
				}
			}
		}

		$this->role_labels = $clean;

		return $this->role_labels;
	}

	/**
	 * Read labels from a detected SupportCandy role table defensively.
	 *
	 * @param string $table_name Sanitized table name.
	 * @return array<int, string>
	 */
	private function get_role_labels_from_table( $table_name ) {
		global $wpdb;

		$table_name = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $table_name );

		if ( ! is_string( $table_name ) || '' === $table_name ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier is sanitized and table existence was verified.
		$columns  = $wpdb->get_col( "SHOW COLUMNS FROM `{$table_name}`", 0 );
		$columns  = is_array( $columns ) ? array_map( 'sanitize_key', $columns ) : array();
		$id_key   = in_array( 'id', $columns, true ) ? 'id' : ( in_array( 'role_id', $columns, true ) ? 'role_id' : '' );
		$name_key = '';

		foreach ( array( 'label', 'name', 'title', 'role', 'slug' ) as $candidate ) {
			if ( in_array( $candidate, $columns, true ) ) {
				$name_key = $candidate;
				break;
			}
		}

		if ( '' === $id_key || '' === $name_key ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are sanitized and verified against SHOW COLUMNS results.
		$rows   = $wpdb->get_results( "SELECT `{$id_key}`, `{$name_key}` FROM `{$table_name}`", ARRAY_A );
		$labels = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$role_id = isset( $row[ $id_key ] ) ? absint( $row[ $id_key ] ) : 0;
			$label   = isset( $row[ $name_key ] ) ? sanitize_text_field( (string) $row[ $name_key ] ) : '';

			if ( $role_id && '' !== $label ) {
				$labels[ $role_id ] = $label;
			}
		}

		return $labels;
	}

	/**
	 * Get a detected SupportCandy role name when a reliable source is available.
	 *
	 * @param int $role_id SupportCandy role ID.
	 * @return string
	 */
	private function get_detected_role_name( $role_id ) {
		$role_id = absint( $role_id );

		if ( ! $role_id ) {
			return '';
		}

		$labels = $this->get_supportcandy_role_labels();

		return isset( $labels[ $role_id ] ) ? sanitize_text_field( $labels[ $role_id ] ) : '';
	}

	/**
	 * Get normalized allowed SupportCandy role IDs.
	 *
	 * @return array<int, int>
	 */
	private function get_allowed_role_ids() {
		$role_ids = get_option( self::OPTION_NAME, array() );

		if ( is_string( $role_ids ) ) {
			$role_ids = explode( ',', $role_ids );
		}

		if ( ! is_array( $role_ids ) ) {
			return array();
		}

		return $this->normalize_role_ids( $role_ids );
	}

	/**
	 * Save allowed role IDs from the submitted request.
	 *
	 * @return void
	 */
	private function save_allowed_role_ids_from_request() {
		$mode = isset( $_POST['scai_permission_mode'] ) && is_scalar( $_POST['scai_permission_mode'] )
			? sanitize_key( wp_unslash( $_POST['scai_permission_mode'] ) )
			: '';

		if ( 'all' === $mode ) {
			update_option( self::OPTION_NAME, array(), false );
			return;
		}

		$role_ids = array();

		if ( 'selected' === $mode && isset( $_POST['scai_allowed_supportcandy_role_ids'] ) && is_array( $_POST['scai_allowed_supportcandy_role_ids'] ) ) {
			$role_ids = $this->normalize_role_ids( wp_unslash( $_POST['scai_allowed_supportcandy_role_ids'] ) );
		}

		if ( 'selected' !== $mode || empty( $role_ids ) ) {
			$this->redirect_with_message( 'permissions_missing_roles' );
		}

		update_option( self::OPTION_NAME, $role_ids, false );
	}

	/**
	 * Normalize role IDs.
	 *
	 * @param array<int, mixed> $role_ids Raw role IDs.
	 * @return array<int, int>
	 */
	private function normalize_role_ids( array $role_ids ) {
		$normalized = array();

		foreach ( $role_ids as $role_id ) {
			if ( ! is_scalar( $role_id ) ) {
				continue;
			}

			$role_id = absint( $role_id );

			if ( $role_id ) {
				$normalized[] = $role_id;
			}
		}

		$normalized = array_values( array_unique( $normalized ) );
		sort( $normalized, SORT_NUMERIC );

		return $normalized;
	}

	/**
	 * Get the current user's SupportCandy agent summary.
	 *
	 * @return array<string, int|bool>
	 */
	private function get_current_user_agent_summary() {
		global $wpdb;

		$table_name = $this->get_agents_table_name();
		$user_id    = get_current_user_id();

		if ( ! $user_id || '' === $table_name || ! $this->table_exists( $table_name ) ) {
			return array(
				'found'   => false,
				'role_id' => 0,
			);
		}

		$sql = "SELECT `role`, `is_active`, `is_agentgroup` FROM `{$table_name}` WHERE `user` = %d AND `is_agentgroup` = 0 LIMIT 1";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table identifier is sanitized and filterable through this plugin only.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, absint( $user_id ) ), ARRAY_A );

		return array(
			'found'         => is_array( $row ) && ! empty( $row ),
			'role_id'       => is_array( $row ) && isset( $row['role'] ) ? absint( $row['role'] ) : 0,
			'is_active'     => is_array( $row ) && ! empty( $row['is_active'] ),
			'is_agentgroup' => is_array( $row ) && ! empty( $row['is_agentgroup'] ),
		);
	}

	/**
	 * Render the current permission summary.
	 *
	 * @param array<int, int>    $allowed_role_ids Allowed role IDs.
	 * @param array<int, string> $role_labels     Role labels keyed by ID.
	 * @return void
	 */
	private function render_permission_summary( array $allowed_role_ids, array $role_labels ) {
		$agent_summary = $this->get_current_user_agent_summary();
		$can_use_ai    = current_user_can( self::CAPABILITY );

		if ( class_exists( 'SCAI_Permissions' ) ) {
			$permissions = new SCAI_Permissions();
			$can_use_ai  = $permissions->current_user_can_use_ai( 0, 'ticket_ai_panel' );
		}

		$access_mode    = empty( $allowed_role_ids )
			? __( 'All active SupportCandy agents', 'supportcandy-ai' )
			: __( 'Selected SupportCandy roles only', 'supportcandy-ai' );
		$selected_roles = array();

		foreach ( $allowed_role_ids as $role_id ) {
			$selected_roles[] = $this->get_role_summary_label( $role_id, $role_labels );
		}

		$allowed_roles = empty( $selected_roles )
			? __( 'All active SupportCandy agent roles', 'supportcandy-ai' )
			: implode( ', ', $selected_roles );
		$current_role  = ! empty( $agent_summary['found'] )
			? $this->get_role_summary_label( $agent_summary['role_id'], $role_labels )
			: __( 'Not detected', 'supportcandy-ai' );
		?>
		<h2><?php echo esc_html__( 'Current Permission Summary', 'supportcandy-ai' ); ?></h2>
		<table class="widefat striped scai-permission-summary">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Access mode', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( $access_mode ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Allowed roles', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( $allowed_roles ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Current user role', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( $current_role ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Current user can use AI', 'supportcandy-ai' ); ?></th>
					<td><?php echo esc_html( $can_use_ai ? __( 'Yes', 'supportcandy-ai' ) : __( 'No', 'supportcandy-ai' ) ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Format a role label with its numeric ID as secondary information.
	 *
	 * @param int                $role_id     SupportCandy role ID.
	 * @param array<int, string> $role_labels Detected role labels.
	 * @return string
	 */
	private function get_role_summary_label( $role_id, array $role_labels ) {
		$role_id = absint( $role_id );
		$label   = isset( $role_labels[ $role_id ] ) ? sanitize_text_field( $role_labels[ $role_id ] ) : '';

		return '' !== $label
			? sprintf(
				/* translators: 1: SupportCandy role label, 2: role ID. */
				__( '%1$s (ID: %2$d)', 'supportcandy-ai' ),
				$label,
				$role_id
			)
			: sprintf(
				/* translators: %d: SupportCandy role ID. */
				__( 'Role #%d', 'supportcandy-ai' ),
				$role_id
			);
	}

	/**
	 * Render an admin notice after saving.
	 *
	 * @return void
	 */
	private function render_notice() {
		$message = isset( $_GET['scai_message'] ) ? sanitize_key( wp_unslash( $_GET['scai_message'] ) ) : '';

		if ( ! in_array( $message, array( 'permissions_saved', 'permissions_missing_roles' ), true ) ) {
			return;
		}

		$is_error = 'permissions_missing_roles' === $message;
		$text     = $is_error
			? __( 'Please select at least one SupportCandy role or choose allow all.', 'supportcandy-ai' )
			: __( 'AI permissions saved successfully.', 'supportcandy-ai' );
		?>
		<div class="notice <?php echo esc_attr( $is_error ? 'notice-error' : 'notice-success' ); ?> is-dismissible">
			<p><?php echo esc_html( $text ); ?></p>
		</div>
		<?php
	}

	/**
	 * Redirect to this page with a notice message.
	 *
	 * @param string $message Message key.
	 * @return void
	 */
	private function redirect_with_message( $message ) {
		$url = add_query_arg(
			array(
				'page'         => self::PAGE_SLUG,
				'scai_message' => sanitize_key( $message ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
