<?php
/**
 * Permission service for SupportCandy AI Assistant.
 *
 * @package SupportCandy_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes permission checks for AI features.
 */
final class SCAI_Permissions {

	/**
	 * Check whether the current user can use AI features.
	 *
	 * @param int    $ticket_id Optional ticket ID.
	 * @param string $feature   Optional AI feature key.
	 * @return bool
	 */
	public function current_user_can_use_ai( $ticket_id = 0, $feature = '' ) {
		return $this->user_can_use_ai( get_current_user_id(), $ticket_id, $feature );
	}

	/**
	 * Check whether a user can use AI features.
	 *
	 * @param int    $user_id   WordPress user ID.
	 * @param int    $ticket_id Optional ticket ID.
	 * @param string $feature   Optional AI feature key.
	 * @return bool
	 */
	public function user_can_use_ai( $user_id, $ticket_id = 0, $feature = '' ) {
		$user_id   = absint( $user_id );
		$ticket_id = absint( $ticket_id );
		$feature   = sanitize_key( $feature );

		if ( ! $user_id ) {
			return false;
		}

		$allowed_role_ids = $this->get_allowed_supportcandy_role_ids();
		$agent            = $this->get_user_agent( $user_id );
		$can_use_ai       = ! empty( $agent )
			&& $this->is_active_supportcandy_agent( $agent )
			&& $this->is_agent_role_allowed( $agent['role'], $allowed_role_ids );

		if ( ! $can_use_ai ) {
			return false;
		}

		/*
		 * An allowed agent role does not imply access to every ticket. Keep ticket
		 * authorization fail-closed and do not let the general-purpose filter below
		 * override a denied ticket-level decision.
		 */
		if ( $ticket_id && ! $this->user_can_access_ticket( $user_id, $ticket_id, $agent, $allowed_role_ids ) ) {
			return false;
		}

		/**
		 * Filter whether a user can use SupportCandy AI features.
		 *
		 * @param bool   $can_use_ai Whether the user can use AI.
		 * @param int    $user_id    WordPress user ID.
		 * @param int    $ticket_id  Optional ticket ID.
		 * @param string $feature    Optional AI feature key.
		 */
		return (bool) apply_filters( 'scai_user_can_use_ai', $can_use_ai, $user_id, $ticket_id, $feature );
	}

	/**
	 * Check whether a SupportCandy agent can view a ticket.
	 *
	 * SupportCandy's read-permission API is authoritative when available. Older or
	 * incompatible versions use a conservative database fallback. Any unknown
	 * schema, malformed assignment value, or otherwise indeterminate result denies
	 * access.
	 *
	 * @param int                       $user_id          WordPress user ID.
	 * @param int                       $ticket_id        SupportCandy ticket ID.
	 * @param array<string, int|string> $agent            Optional sanitized agent row.
	 * @param array<int, int>           $allowed_role_ids SupportCandy roles allowed to use AI.
	 * @return bool
	 */
	private function user_can_access_ticket( $user_id, $ticket_id, $agent = array(), $allowed_role_ids = array() ) {
		$user_id   = absint( $user_id );
		$ticket_id = absint( $ticket_id );

		if ( ! $user_id || ! $ticket_id ) {
			return false;
		}

		if ( empty( $agent ) ) {
			$agent = $this->get_user_agent( $user_id );
		}

		if ( empty( $agent ) || absint( $agent['user'] ) !== $user_id || ! $this->is_active_supportcandy_agent( $agent ) ) {
			return false;
		}

		// Never authorize a role against a ticket ID that does not exist.
		if ( ! $this->supportcandy_ticket_exists( $ticket_id ) ) {
			return false;
		}

		// Prefer SupportCandy's own complete read-permission calculation.
		if ( class_exists( 'WPSC_Ticket' ) && method_exists( 'WPSC_Ticket', 'get_current_read_permission_agents' ) ) {
			try {
				$ticket = new WPSC_Ticket( $ticket_id );

				$allowed_agents = $ticket->get_current_read_permission_agents();
				if ( is_array( $allowed_agents ) ) {
					foreach ( $allowed_agents as $allowed_agent ) {
						$allowed_agent_id = is_object( $allowed_agent ) && isset( $allowed_agent->id )
							? absint( $allowed_agent->id )
							: ( is_array( $allowed_agent ) && isset( $allowed_agent['id'] ) ? absint( $allowed_agent['id'] ) : 0 );

						if ( $allowed_agent_id === absint( $agent['id'] ) ) {
							return true;
						}
					}
				}
			} catch ( Throwable $exception ) {
				// Continue with role and database checks, which remain fail-closed.
			}
		}

		if ( $this->supportcandy_agent_has_global_ticket_access( $agent, $allowed_role_ids ) ) {
			return true;
		}

		return $this->user_can_access_ticket_from_database( $ticket_id, $agent );
	}

	/**
	 * Check whether a SupportCandy role can view every active ticket.
	 *
	 * This uses SupportCandy agent capabilities and its known role option. It never
	 * uses the WordPress manage_options capability as an authorization shortcut.
	 *
	 * @param array<string, int|string> $agent            Sanitized SupportCandy agent row.
	 * @param array<int, int>           $allowed_role_ids SupportCandy roles allowed to use AI.
	 * @return bool
	 */
	private function supportcandy_agent_has_global_ticket_access( array $agent, array $allowed_role_ids ) {
		$role_id = isset( $agent['role'] ) ? absint( $agent['role'] ) : 0;
		if ( ! $role_id || ! $this->is_active_supportcandy_agent( $agent ) ) {
			return false;
		}

		$view_caps = array( 'view-unassigned', 'view-assigned-me', 'view-assigned-others' );

		// Use SupportCandy's capability API when the installed version provides it.
		if ( class_exists( 'WPSC_Agent' ) && method_exists( 'WPSC_Agent', 'has_cap' ) ) {
			try {
				$sc_agent = new WPSC_Agent( absint( $agent['id'] ) );
				if ( ! empty( $sc_agent->id ) ) {
					$has_all_view_caps = true;
					foreach ( $view_caps as $capability ) {
						if ( ! $sc_agent->has_cap( $capability ) ) {
							$has_all_view_caps = false;
							break;
						}
					}

					if ( $has_all_view_caps ) {
						return true;
					}
				}
			} catch ( Throwable $exception ) {
				// Inspect the known SupportCandy role option below.
			}
		}

		$roles = get_option( 'wpsc-agent-roles', null );
		if ( is_array( $roles ) && isset( $roles[ $role_id ] ) && is_array( $roles[ $role_id ] ) ) {
			$role = $roles[ $role_id ];
			$caps = isset( $role['caps'] ) && is_array( $role['caps'] ) ? $role['caps'] : array();

			if ( ! empty( $caps ) ) {
				$has_all_view_caps = true;
				foreach ( $view_caps as $capability ) {
					if ( empty( $caps[ $capability ] ) ) {
						$has_all_view_caps = false;
						break;
					}
				}

				if ( $has_all_view_caps ) {
					return true;
				}

				$global_caps = array( 'view_all', 'view-all', 'view_all_tickets', 'view-all-tickets', 'manage_tickets', 'manage-tickets', 'administrator', 'admin' );
				foreach ( $global_caps as $capability ) {
					if ( ! empty( $caps[ $capability ] ) ) {
						return true;
					}
				}

				return false;
			}
		}

		/*
		 * Compatibility for versions where role metadata cannot be detected. Role 1
		 * is SupportCandy's default Administrator role. This still requires an active
		 * SupportCandy agent and the plugin's SupportCandy role allow-list; it is not
		 * based on WordPress administrator status.
		 */
		return 1 === $role_id && $this->is_agent_role_allowed( $role_id, $allowed_role_ids );
	}

	/**
	 * Conservatively determine ticket access from known SupportCandy schema.
	 *
	 * @param int                       $ticket_id SupportCandy ticket ID.
	 * @param array<string, int|string> $agent     Sanitized SupportCandy agent row.
	 * @return bool
	 */
	private function user_can_access_ticket_from_database( $ticket_id, array $agent ) {
		global $wpdb;

		$table_name = $this->get_tickets_table_name();
		if ( '' === $table_name || ! $this->table_exists( $table_name ) ) {
			return false;
		}

		$columns = $this->get_table_columns( $table_name );
		if ( ! in_array( 'id', $columns, true ) || ! in_array( 'customer', $columns, true ) ) {
			return false;
		}

		$select_columns = array_intersect( array( 'id', 'customer', 'agent_created', 'assigned_agent' ), $columns );
		$sql            = 'SELECT `' . implode( '`, `', $select_columns ) . "` FROM `{$table_name}` WHERE `id` = %d LIMIT 1";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are sanitized and selected from verified columns.
		$ticket = $wpdb->get_row( $wpdb->prepare( $sql, absint( $ticket_id ) ), ARRAY_A );
		if ( ! is_array( $ticket ) || empty( $ticket['id'] ) ) {
			return false;
		}

		if ( ! empty( $agent['customer'] ) && absint( $ticket['customer'] ) === absint( $agent['customer'] ) ) {
			return true;
		}

		if ( isset( $ticket['agent_created'] ) && absint( $ticket['agent_created'] ) === absint( $agent['id'] ) ) {
			return true;
		}

		if ( ! array_key_exists( 'assigned_agent', $ticket ) ) {
			return false;
		}

		$assigned_agent_ids = $this->parse_assigned_agent_ids( $ticket['assigned_agent'] );
		if ( null === $assigned_agent_ids ) {
			return false;
		}

		$roles = get_option( 'wpsc-agent-roles', array() );
		if ( ! is_array( $roles ) || ! isset( $roles[ absint( $agent['role'] ) ]['caps'] ) || ! is_array( $roles[ absint( $agent['role'] ) ]['caps'] ) ) {
			return false;
		}

		$caps = $roles[ absint( $agent['role'] ) ]['caps'];
		if ( empty( $assigned_agent_ids ) ) {
			return ! empty( $caps['view-unassigned'] );
		}

		if ( in_array( absint( $agent['id'] ), $assigned_agent_ids, true ) ) {
			return ! empty( $caps['view-assigned-me'] );
		}

		return ! empty( $caps['view-assigned-others'] );
	}

	/**
	 * Check whether the current user can manage plugin settings.
	 *
	 * @return bool
	 */
	public function current_user_can_manage_settings() {
		$can_manage_settings = current_user_can( 'manage_options' );

		/**
		 * Filter whether the current user can manage SupportCandy AI settings.
		 *
		 * @param bool $can_manage_settings Whether the current user can manage settings.
		 */
		return (bool) apply_filters( 'scai_user_can_manage_settings', $can_manage_settings );
	}

	/**
	 * Check whether the current user can view plugin diagnostics.
	 *
	 * @return bool
	 */
	public function current_user_can_view_diagnostics() {
		$can_view_diagnostics = current_user_can( 'manage_options' );

		/**
		 * Filter whether the current user can view SupportCandy AI diagnostics.
		 *
		 * @param bool $can_view_diagnostics Whether the current user can view diagnostics.
		 */
		return (bool) apply_filters( 'scai_user_can_view_diagnostics', $can_view_diagnostics );
	}

	/**
	 * Get the current user's SupportCandy agent row.
	 *
	 * @return array<string, int|string>
	 */
	public function get_current_user_agent() {
		return $this->get_user_agent( get_current_user_id() );
	}

	/**
	 * Get a user's active SupportCandy agent row.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, int|string>
	 */
	public function get_user_agent( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return array();
		}

		$table_name = $this->get_agents_table_name();

		if ( '' === $table_name || ! $this->table_exists( $table_name ) ) {
			return array();
		}

		$sql = "SELECT `id`, `user`, `customer`, `role`, `name`, `is_agentgroup`, `is_active` FROM `{$table_name}` WHERE `user` = %d AND `is_active` = 1 AND `is_agentgroup` = 0 LIMIT 1";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is sanitized and filterable through this plugin only.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $user_id ), ARRAY_A );

		if ( ! is_array( $row ) || empty( $row ) ) {
			return array();
		}

		$agent = $this->sanitize_agent_row( $row );

		if ( empty( $agent ) || ! $this->is_active_supportcandy_agent( $agent ) ) {
			return array();
		}

		$has_agent_capability = $this->user_has_supportcandy_agent_capability( $user_id );

		/**
		 * Filter whether an active SupportCandy agent row is sufficient when the
		 * expected WordPress agent capability is unavailable.
		 *
		 * @param bool                     $row_is_sufficient    Whether the active row is sufficient.
		 * @param int                      $user_id              WordPress user ID.
		 * @param array<string, int|string> $agent                Sanitized SupportCandy agent row.
		 * @param bool                     $has_agent_capability Whether an approved capability was found.
		 */
		$row_is_sufficient = (bool) apply_filters(
			'scai_active_agent_row_is_sufficient',
			true,
			$user_id,
			$agent,
			$has_agent_capability
		);

		if ( ! $has_agent_capability && ! $row_is_sufficient ) {
			return array();
		}

		return $agent;
	}

	/**
	 * Get SupportCandy role IDs allowed to use AI.
	 *
	 * Empty means all active SupportCandy agents are allowed for MVP.
	 *
	 * @return array<int, int>
	 */
	public function get_allowed_supportcandy_role_ids() {
		$role_ids = $this->get_allowed_role_ids_from_settings();
		$role_ids = $this->normalize_role_ids( $role_ids );

		/**
		 * Filter SupportCandy role IDs allowed to use SupportCandy AI.
		 *
		 * Return an empty array to allow all active SupportCandy agents.
		 *
		 * @param array<int, int> $role_ids Allowed role IDs.
		 */
		$role_ids = apply_filters( 'scai_allowed_supportcandy_role_ids', $role_ids );

		return $this->normalize_role_ids( $role_ids );
	}

	/**
	 * Get the SupportCandy agents table name.
	 *
	 * @return string
	 */
	private function get_agents_table_name() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'psmsc_agents';

		/**
		 * Filter the SupportCandy agents table used for permission checks.
		 *
		 * @param string $table_name Database table name.
		 */
		$table_name = apply_filters( 'scai_supportcandy_agents_table', $table_name );

		return $this->sanitize_database_identifier( $table_name );
	}

	/**
	 * Get the SupportCandy tickets table name.
	 *
	 * @return string
	 */
	private function get_tickets_table_name() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'psmsc_tickets';

		/**
		 * Filter the SupportCandy tickets table used for permission checks.
		 *
		 * @param string $table_name Database table name.
		 */
		$table_name = apply_filters( 'scai_supportcandy_tickets_table', $table_name );

		return $this->sanitize_database_identifier( $table_name );
	}

	/**
	 * Check whether an active SupportCandy ticket exists.
	 *
	 * @param int $ticket_id SupportCandy ticket ID.
	 * @return bool
	 */
	private function supportcandy_ticket_exists( $ticket_id ) {
		global $wpdb;

		$ticket_id  = absint( $ticket_id );
		$table_name = $this->get_tickets_table_name();

		if ( ! $ticket_id || '' === $table_name || ! $this->table_exists( $table_name ) ) {
			return false;
		}

		$columns = $this->get_table_columns( $table_name );
		if ( ! in_array( 'id', $columns, true ) ) {
			return false;
		}

		$sql = "SELECT `id` FROM `{$table_name}` WHERE `id` = %d LIMIT 1";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifier is sanitized and its schema was verified.
		$found_ticket_id = $wpdb->get_var( $wpdb->prepare( $sql, $ticket_id ) );

		return absint( $found_ticket_id ) === $ticket_id;
	}

	/**
	 * Check whether a database table exists.
	 *
	 * @param string $table_name Database table name.
	 * @return bool
	 */
	private function table_exists( $table_name ) {
		global $wpdb;

		$table_name = $this->sanitize_database_identifier( $table_name );

		if ( '' === $table_name ) {
			return false;
		}

		$found_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		return is_string( $found_table ) && $this->sanitize_database_identifier( $found_table ) === $table_name;
	}

	/**
	 * Get sanitized column names for a verified table.
	 *
	 * @param string $table_name Database table name.
	 * @return array<int, string>
	 */
	private function get_table_columns( $table_name ) {
		global $wpdb;

		$table_name = $this->sanitize_database_identifier( $table_name );
		if ( '' === $table_name || ! $this->table_exists( $table_name ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier is sanitized and the table existence was verified.
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_name}`", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$columns = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['Field'] ) ) {
				$column = $this->sanitize_database_identifier( $row['Field'] );
				if ( '' !== $column ) {
					$columns[] = $column;
				}
			}
		}

		return array_values( array_unique( $columns ) );
	}

	/**
	 * Parse known SupportCandy assigned-agent storage formats.
	 *
	 * A null return denotes an unsafe or unknown format; an empty array denotes a
	 * reliably unassigned ticket.
	 *
	 * @param mixed $value Stored assignment value.
	 * @return array<int, int>|null
	 */
	private function parse_assigned_agent_ids( $value ) {
		if ( null === $value || '' === $value || 0 === $value || '0' === $value ) {
			return array();
		}

		if ( is_string( $value ) && is_serialized( $value ) ) {
			$value = maybe_unserialize( $value );
		} elseif ( is_string( $value ) && in_array( substr( trim( $value ), 0, 1 ), array( '[', '{' ), true ) ) {
			$value = json_decode( $value, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return null;
			}
		} elseif ( is_string( $value ) ) {
			if ( ! preg_match( '/^\s*\d+(?:\s*[,|]\s*\d+)*\s*$/', $value ) ) {
				return null;
			}
			$value = preg_split( '/\s*[,|]\s*/', trim( $value ) );
		}

		if ( is_scalar( $value ) ) {
			$value = array( $value );
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		$ids = array();
		foreach ( $value as $item ) {
			if ( is_object( $item ) && isset( $item->id ) ) {
				$item = $item->id;
			} elseif ( is_array( $item ) && isset( $item['id'] ) ) {
				$item = $item['id'];
			}

			if ( ! is_scalar( $item ) || ! ctype_digit( trim( (string) $item ) ) ) {
				return null;
			}

			$id = absint( $item );
			if ( $id ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Check whether a user has a SupportCandy agent capability.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	private function user_has_supportcandy_agent_capability( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		/**
		 * Filter WordPress capabilities that identify SupportCandy agents.
		 *
		 * @param array<int, string> $capabilities Candidate capabilities.
		 * @param int                $user_id      WordPress user ID.
		 */
		$capabilities = apply_filters( 'scai_ai_capabilities', array( 'wpsc_agent' ), $user_id );

		if ( ! is_array( $capabilities ) ) {
			return false;
		}

		return $this->user_has_any_capability( $user_id, $capabilities );
	}

	/**
	 * Check whether a sanitized row represents an active SupportCandy agent.
	 *
	 * @param array<string, int|string> $agent SupportCandy agent row.
	 * @return bool
	 */
	private function is_active_supportcandy_agent( array $agent ) {
		return ! empty( $agent['id'] )
			&& ! empty( $agent['user'] )
			&& 1 === absint( $agent['is_active'] )
			&& 0 === absint( $agent['is_agentgroup'] );
	}

	/**
	 * Check whether an agent role is allowed to use AI.
	 *
	 * @param int             $role_id          SupportCandy agent role ID.
	 * @param array<int, int> $allowed_role_ids Allowed SupportCandy role IDs.
	 * @return bool
	 */
	private function is_agent_role_allowed( $role_id, array $allowed_role_ids ) {
		$role_id          = absint( $role_id );
		$allowed_role_ids = $this->normalize_role_ids( $allowed_role_ids );

		if ( empty( $allowed_role_ids ) ) {
			return 0 < $role_id;
		}

		return in_array( $role_id, $allowed_role_ids, true );
	}

	/**
	 * Check whether a user has at least one capability.
	 *
	 * @param int                  $user_id      WordPress user ID.
	 * @param array<int, string>   $capabilities Candidate capabilities.
	 * @return bool
	 */
	private function user_has_any_capability( $user_id, array $capabilities ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		foreach ( $capabilities as $capability ) {
			$capability = sanitize_key( $capability );

			if ( '' === $capability ) {
				continue;
			}

			if ( user_can( $user_id, $capability ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get allowed role IDs from settings or option storage.
	 *
	 * @return mixed
	 */
	private function get_allowed_role_ids_from_settings() {
		if ( class_exists( 'SCAI_Settings' ) && method_exists( 'SCAI_Settings', 'get_allowed_supportcandy_role_ids' ) ) {
			$method = new ReflectionMethod( 'SCAI_Settings', 'get_allowed_supportcandy_role_ids' );

			if ( $method->isPublic() && $method->isStatic() ) {
				return SCAI_Settings::get_allowed_supportcandy_role_ids();
			}
		}

		return get_option( 'scai_allowed_supportcandy_role_ids', array() );
	}

	/**
	 * Normalize allowed role IDs from option/filter values.
	 *
	 * @param mixed $role_ids Raw role IDs.
	 * @return array<int, int>
	 */
	private function normalize_role_ids( $role_ids ) {
		if ( is_string( $role_ids ) ) {
			$role_ids = explode( ',', $role_ids );
		}

		if ( ! is_array( $role_ids ) ) {
			return array();
		}

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
	 * Sanitize a SupportCandy agent database row.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, int|string>
	 */
	private function sanitize_agent_row( array $row ) {
		$agent = array(
			'id'            => isset( $row['id'] ) ? absint( $row['id'] ) : 0,
			'user'          => isset( $row['user'] ) ? absint( $row['user'] ) : 0,
			'customer'      => isset( $row['customer'] ) ? absint( $row['customer'] ) : 0,
			'role'          => isset( $row['role'] ) ? absint( $row['role'] ) : 0,
			'name'          => isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '',
			'is_agentgroup' => isset( $row['is_agentgroup'] ) ? absint( $row['is_agentgroup'] ) : 0,
			'is_active'     => isset( $row['is_active'] ) ? absint( $row['is_active'] ) : 0,
		);

		if ( ! $agent['id'] || ! $agent['user'] ) {
			return array();
		}

		return $agent;
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
}
