# Private Beta Security Fix Verification

Date: 2026-07-15  
Scope: H-01, H-02, H-03, H-04, and M-03 only  
Method: Focused source review plus non-destructive runtime checks against the local WordPress installation. Destructive uninstall and expired-row deletion were verified structurally and were not executed against the live development data.

## Overall status: PASS

The private beta blocker fixes pass focused source review and runtime verification. AI and history authorization now fail closed when `SCAI_Permissions` is unavailable, and WordPress administrator status alone does not bypass SupportCandy authorization.

## Critical

No Critical findings were identified in this focused verification.

## High

### H-01 — Ticket-level access authorization: PASS

Verified:

- `SCAI_AI_Controller::current_user_can_run_ai_action()` rejects logged-out users.
- All AI generation handlers and the conversation-history handler call the centralized ticket-aware permission path before accessing ticket AI data.
- `SCAI_Permissions::user_can_use_ai()` requires a nonzero WordPress user ID, an active non-group SupportCandy agent row, and an AI-allowed SupportCandy role.
- For `ticket_id > 0`, ticket existence is checked before authorization.
- SupportCandy's `WPSC_Ticket::get_current_read_permission_agents()` is attempted first.
- The defensive fallback checks SupportCandy customer identity, ticket creator, assignment, and SupportCandy view capabilities.
- SupportCandy global access is established from SupportCandy agent/role capabilities. Default role ID 1 is only a compatibility fallback for an active, AI-allowed SupportCandy agent; it is not based on WordPress administrator status.
- Built-in ticket denial occurs before the `scai_user_can_use_ai` filter, so that filter cannot override a failed ticket-access decision.
- Runtime checks passed for zero-user denial, valid-ticket access by an active SupportCandy administrator agent, invalid-ticket denial, missing-permission-service denial, and denial of a WordPress administrator without an active authorized SupportCandy agent.
- `SCAI_AI_Controller::current_user_can_run_ai_action()` returns false when `SCAI_Permissions` is unavailable; there is no `manage_options` authorization fallback.

Conclusion: AI and history endpoints enforce centralized SupportCandy authorization and fail closed when it cannot be performed.

### H-02 — Agent-scoped conversation history: PASS

Verified:

- Successful Summary, Reply, Improve, and Merge responses share `SCAI_Ticket_AI_Service::maybe_save_conversation()`.
- Conversation `agent_id` is consistently the current WordPress user ID.
- Conversation persistence is skipped when no current WordPress user ID exists, preventing new unowned rows.
- The popup endpoint obtains the same WordPress user identity and rejects an invalid identity.
- User-facing history calls `get_by_ticket_for_agent()` rather than the unscoped ticket method.
- `get_by_ticket_for_agent()` requires positive ticket and agent IDs, forces the `agent_id` predicate, and returns an empty array for `agent_id = 0`.
- Legacy unscoped rows with `agent_id = 0` are not returned.
- The JSON response excludes metadata, prompt data, image data, local paths, API details, and agent IDs.
- Runtime repository checks confirmed that all returned rows belonged to the requested agent and that a zero agent ID returned no rows.

Conclusion: one agent cannot retrieve another agent's popup history through the scoped repository/AJAX path.

## Medium

### M-03 — Image understanding default OFF: PASS

Verified:

- `SCAI_Settings` defines `image_understanding_enabled` with default `0`.
- The installer does not create or overwrite this option, so a fresh installation resolves it as disabled.
- Existing saved enabled or disabled values continue to take precedence over the default.
- The settings checkbox still saves explicit `1` or `0` values.
- `SCAI_Ticket_AI_Service::is_image_understanding_enabled()` returns false when the settings service or saved option is absent.
- `prepare_ticket_images_for_ai()` returns an empty image list before reading/preparing images when the setting is disabled.
- Images also require an active provider that advertises image support.
- Runtime checks confirmed default `0` and preservation of the existing saved choice.

Conclusion: image attachment transmission is opt-in and remains gated by both the administrator setting and provider capability.

## Low

No Low findings were identified in this focused verification.

## H-04 — Conversation retention enforcement: PASS

Verified:

- `get_by_ticket()` applies the UTC read predicate: expiration is null, empty, zero-date, or later than the current UTC MySQL time.
- `get_by_ticket_for_agent()` and `get_latest_by_ticket_and_feature()` inherit that filtering through `get_by_ticket()`.
- `get_count_by_ticket()` applies the same retention predicate.
- Read-time filtering protects visibility when WP-Cron is delayed or disabled.
- `delete_expired( $limit = 500 )` targets only the plugin-owned conversations table.
- Cleanup excludes null, empty, and zero-date values and deletes only rows with `expires_at <=` current UTC time.
- SQL values and the bounded batch limit are prepared; the maximum batch size is 1,000.
- `scai_daily_conversation_cleanup` is registered, scheduled once with the `daily` recurrence, and invokes repository cleanup without output.
- Runtime checks confirmed a daily scheduled event and that all currently visible fixture rows were unexpired.
- The uninstaller explicitly clears `scai_daily_conversation_cleanup` on each applicable site.

Limitation: the local database had no expired fixture, so physical deletion was not executed. The prepared deletion predicate and table target were verified directly in source.

## H-03 — Full uninstall cleanup: PASS

Verified destructive option inventory:

- `scai_provider_configs`
- `scai_active_provider`
- `scai_company_instructions`
- `scai_image_understanding_enabled`
- `scai_knowledge_sync_enabled`
- `scai_last_knowledge_sync_at`
- `scai_allowed_supportcandy_role_ids`
- `scai_schema_version`
- `scai_installed_at`
- `scai_conversation_retention_days`
- `scai_delete_data_on_uninstall`

Additional verification:

- Registered `SCAI_Settings` option names are merged with the explicit fallback inventory.
- The final option inventory is restricted to explicit `scai_*` keys; no prefix-wide SQL deletion is used.
- `scai_provider_configs`, which may contain API credentials, is explicitly included without reading or logging its value.
- The known `scai_database_migration_lock` transient is cleared explicitly.
- Tables are obtained only from `SCAI_Schema`: `scai_conversations`, `scai_knowledge`, and `scai_usage_logs`.
- No `psmsc_*`, WordPress core, SupportCandy, BetterDocs, or official SupportCandy AI table is included.
- Plugin-owned cron hooks cleared are `scai_daily_conversation_cleanup`, `scai_daily_knowledge_sync`, and `scai_usage_log_cleanup`.
- When destructive cleanup is disabled, tables and options are preserved while scheduled hooks are still cleared.
- Network uninstall iterates sites with `switch_to_blog()` and restores the prior site in a `finally` block.
- Runtime reflection checks confirmed the required option inventory, `scai_*` restriction, and schema table inventory.

Limitation: destructive uninstall was not run against the active development site. Control flow, explicit inventories, and schema-derived targets were verified without deleting data.

## Private beta recommendation

**PASS for private beta within the focused scope of H-01, H-02, H-03, H-04, and M-03.**

The five audited blocker fixes are suitable for private beta based on the completed source and runtime verification.

## Any remaining must-fix items

None within this focused verification scope.
