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
