# SupportCandy AI Assistant - Private Beta Security Audit

Audit date: 2026-07-15  
Scope: Entire `supportcandy-ai` plugin folder at commit `3a2f0a7`  
Method: Static source review, PHP lint of every PHP file, WordPress runtime bootstrap checks, hook/class registration checks, and local schema/table checks. No source files were modified. This audit did not perform an external penetration test or send live provider requests.

## Summary

- Overall status: **BLOCKED**
- Total findings: **13**
- Critical findings: **0**
- High findings: **4**
- Medium findings: **5**
- Low findings: **4**

The plugin has a solid baseline: all PHP files parse, runtime classes and AJAX hooks load, AJAX actions are logged-in only, nonces and role restrictions are present, SQL is generally constrained/prepared, output is generally escaped, and attachment readers enforce local upload-directory boundaries and size/type limits.

Private beta is blocked because an allowed SupportCandy role is not additionally checked for access to the requested ticket. An allowed agent can supply another internal ticket ID to the AJAX endpoints. Conversation history is also returned ticket-wide rather than being scoped to the current agent. In addition, expired conversations remain queryable because retention cleanup is not scheduled or enforced during reads, and uninstall cleanup leaves the provider configuration option (including the API key) behind even when destructive cleanup is selected.

## Release Recommendation

**Do not package the current revision for private beta.** Fix all four High findings first, then repeat the endpoint authorization, retention, and uninstall tests. Medium findings concerning provider URL policy, credential storage, attachment opt-in, and asset routing should be resolved or explicitly documented and accepted before distributing the beta outside a controlled local environment.

## Critical Findings

No Critical findings were confirmed.

## High Findings

### H-01: AI endpoints do not enforce ticket-level SupportCandy access

- File: `includes/services/class-permissions.php`
- Function/method: `SCAI_Permissions::user_can_use_ai()`
- Related file/methods: `includes/ai/class-ai-controller.php` — `current_user_can_run_ai_action()` and all five AJAX handlers
- Issue: `$ticket_id` is sanitized and passed through the permission API, but the built-in decision only checks whether the WordPress user has an active, non-group SupportCandy agent row and an allowed SupportCandy role. It does not verify that the agent can view the requested ticket. The ticket ID and feature are only passed to the `scai_user_can_use_ai` filter after the permissive role decision.
- Risk: Any authenticated user in an allowed role can enumerate internal ticket IDs and cause ticket subjects, customer messages, private/internal notes, text attachment excerpts, and images to be sent to the configured AI provider. The generated response can reveal details from tickets the agent cannot normally access. This is an authenticated insecure direct object reference/data exposure issue.
- Suggested fix: Add a fail-closed SupportCandy ticket access check in the central permission service. Verify the ticket exists and the current agent is allowed to view it under SupportCandy assignment, role, group, and visibility rules. Apply the same check to generation, improvement, merge, history, and asset loading. Do not rely on UI visibility or a filter supplied by another plugin.
- Blocks private beta: **Yes**

### H-02: Conversation history is not scoped to the current agent

- File: `includes/ai/class-ai-controller.php`
- Function/method: `get_ticket_conversation_history()`
- Related file/method: `includes/services/class-conversation-repository.php` — `get_by_ticket()`
- Issue: The history endpoint calls `get_by_ticket( $ticket_id, array( 'limit' => $limit ) )` without `agent_id`. The repository supports an agent filter but defaults it to zero, so it returns records from every agent for that ticket. This conflicts with the documented private-per-agent conversation model in `AI_CONTEXT.md`.
- Risk: One allowed agent can read AI-generated conversation history created by another agent. Combined with H-01, an agent can request history for arbitrary ticket IDs.
- Suggested fix: Pass the correct current agent identity to `get_by_ticket()` and enforce it in repository queries used by user-facing history. Decide explicitly whether `agent_id` means WordPress user ID or SupportCandy agent row ID and use one definition consistently. Keep any administrator-wide audit view separate and capability protected.
- Blocks private beta: **Yes**

### H-03: Delete-on-uninstall leaves provider credentials and plugin options

- File: `includes/installers/class-uninstaller.php`
- Function/method: `delete_options()`
- Issue: When `scai_delete_data_on_uninstall` is enabled, only four options are deleted. At minimum, `scai_provider_configs` (which contains the API key), `scai_active_provider`, `scai_company_instructions`, `scai_image_understanding_enabled`, `scai_knowledge_sync_enabled`, `scai_last_knowledge_sync_at`, and `scai_allowed_supportcandy_role_ids` are not removed.
- Risk: Administrators selecting destructive cleanup reasonably expect plugin-owned credentials and configuration to be removed. API credentials and company instructions remain in the WordPress options table after uninstall.
- Suggested fix: Build the deletion list from the authoritative settings/provider services plus the permissions option, explicitly include `scai_provider_configs`, and verify only `scai_*` plugin-owned options and tables are deleted. Add single-site and multisite uninstall tests.
- Blocks private beta: **Yes**

### H-04: Conversation retention is not enforced

- File: `includes/services/class-conversation-repository.php`
- Function/methods: `get_by_ticket()`, `delete_expired()`
- Related files: `includes/core/class-plugin.php`, `includes/installers/class-uninstaller.php`
- Issue: Records receive `expires_at`, and a deletion method exists, but no cron event or callback is registered anywhere in the plugin. `get_by_ticket()` also does not exclude rows whose `expires_at` is in the past. The uninstaller lists a future cleanup hook, but no runtime code schedules or handles it.
- Risk: The advertised retention setting does not limit storage or visibility. Expired customer-related AI content remains in the database and continues to be returned by the history endpoint indefinitely.
- Suggested fix: Exclude expired rows in all user-facing reads and register a bounded recurring cleanup callback. Schedule it idempotently, clear it on deactivation/uninstall, and test the configured 1–365 day retention behavior. Read-time exclusion should remain even if WP-Cron is delayed.
- Blocks private beta: **Yes**

## Medium Findings

### M-01: Configurable provider URL permits server-side requests to arbitrary hosts

- File: `includes/services/class-http-client.php`
- Function/methods: `validate_url()`, `request()`
- Related file/method: `includes/providers/class-openai-compatible-provider.php` — `is_valid_base_url()`
- Issue: Validation accepts any syntactically valid HTTP/HTTPS host, including loopback, link-local, private-network, and metadata-service addresses. Requests use `wp_remote_request()` rather than the safe URL variant, allow three redirects, and do not set `reject_unsafe_urls`.
- Risk: A user with `manage_options` can make the WordPress server send authenticated or unauthenticated requests to internal services. Administrator-only configuration reduces exploitability, and local/self-hosted providers may be intentional, but this is still an SSRF-capable design requiring an explicit policy.
- Suggested fix: Define a provider endpoint policy. For hosted providers, use `wp_safe_remote_request()`/`reject_unsafe_urls` and reject private/link-local destinations, including after redirects. If localhost/private endpoints are a supported feature, require an explicit opt-in or allowlist and document the risk. Never forward the provider bearer token across a cross-host redirect.
- Blocks private beta: **Needs product/security decision**

### M-02: Provider API keys are stored as plaintext WordPress option values

- File: `includes/services/class-provider-config.php`
- Function/methods: `update()`, `get_all()`, `get()`
- Issue: API keys are sanitized and stored directly in `scai_provider_configs`. UI output is masked, and the option is non-autoloaded, but the credential is not encrypted at rest. Public service methods also default to returning secrets when called without the optional argument.
- Risk: Database backups, SQL access, vulnerable plugins, or accidental debug code can expose provider credentials. WordPress has no universal secret vault, so this may be an accepted platform limitation, but it must be disclosed and minimized.
- Suggested fix: Document the storage model for beta testers. Prefer encryption using site-held key material where operationally supportable, or integrate with environment constants/secret management. Change general getters to exclude secrets by default and expose a narrowly scoped runtime-only secret getter.
- Blocks private beta: **No if explicitly accepted and H-03 is fixed**

### M-03: Image attachment transmission is enabled by default

- File: `includes/services/class-settings.php`
- Function/method: `get_definitions()` (`image_understanding_enabled` default)
- Related file: `includes/ai/class-ticket-ai-service.php`
- Issue: The default setting is `1`, so eligible ticket images can be transmitted to the active vision-capable provider before an administrator has deliberately opted in, depending on how the option was initialized.
- Risk: Screenshots may contain customer personal data, credentials, tokens, or private infrastructure information. Sending them to a third party by default creates a privacy and consent risk.
- Suggested fix: Default image understanding to disabled for new installations, present an explicit data-transfer notice, and require administrator opt-in. Existing installations should not be silently changed without an upgrade decision.
- Blocks private beta: **Recommended before beta involving real customer data**

### M-04: Ticket AI assets are enqueued more broadly than ticket-detail pages

- File: `includes/admin/class-assets.php`
- Function/method: `should_enqueue_ticket_ai_assets()`
- Related file/method: `includes/frontend/class-assets.php` — `should_enqueue_ticket_ai_assets()`
- Issue: Backend assets load for every `page=wpsc-tickets` screen when the role check passes, even when no detail ticket is established. Frontend assets load when any ticket-like query parameter or broad SupportCandy content marker is present. JavaScript performs additional route detection and usually avoids rendering, but the assets and nonce are still delivered.
- Risk: This increases attack surface, creates UI/selector collision risk on ticket lists/settings, and contradicts the intended detail-only loading rule. It is not an authorization bypass because AJAX rechecks permission.
- Suggested fix: Require reliable ticket-detail evidence before enqueueing where possible, then keep the JavaScript route guard as defense in depth. Validate behavior across SupportCandy AJAX navigation.
- Blocks private beta: **No, but should be fixed**

### M-05: Network activation does not provision sites created later

- File: `includes/installers/class-installer.php`
- Function/method: `install_network()`
- Related file: `includes/core/class-plugin.php`
- Issue: Network activation installs current sites, but no `wp_initialize_site`/new-site hook installs plugin tables and defaults for sites created afterward.
- Risk: On multisite, a later-created site can run plugin code without its tables/options, producing missing logs/history and degraded behavior.
- Suggested fix: If multisite is supported, add a network-aware new-site installer with safe context switching and idempotent schema creation. Otherwise state that private beta supports single-site only.
- Blocks private beta: **No for single-site beta; yes for multisite beta**

## Low Findings / Cleanup

### L-01: Malformed array input can reach scalar sanitizers

- File: `includes/ai/class-ai-controller.php`
- Function/methods: `verify_request()`, `get_ticket_id_from_request()`, `ajax_improve_reply()`
- Issue: Merge/style fields check `is_scalar()`, but nonce, ticket ID, limit, and improve `reply_text` do not consistently do so before scalar sanitization. Crafted array-shaped parameters can generate warnings or `TypeError` behavior depending on WordPress/PHP versions.
- Risk: An authenticated caller with a valid nonce can trigger noisy 500 responses for its own requests and pollute logs.
- Suggested fix: Require scalar values before `wp_unslash()`/sanitization and return a controlled 400/403 JSON error.
- Blocks private beta: **No**

### L-02: Provider error details are propagated to agents and logs

- File: `includes/providers/class-openai-compatible-provider.php`
- Function/method: `extract_provider_error()`
- Related files: `includes/ai/class-ai-controller.php` — `send_ai_response()`; `includes/services/class-usage-logger.php`
- Issue: Sanitized upstream provider error text is returned to the AJAX caller and stored as `error_message`. Sanitization prevents markup injection but does not guarantee the provider did not echo sensitive request fragments.
- Risk: A misbehaving compatible endpoint could reflect prompt/customer data into UI errors or durable usage logs.
- Suggested fix: Return a generic agent-facing error, retain only allowlisted provider codes/status data, and keep detailed errors in a separately protected, redacted debug channel if needed.
- Blocks private beta: **No**

### L-03: Loader silently skips missing required files

- File: `includes/core/class-loader.php`
- Function/method: `load_files()`
- Issue: Missing files are skipped without an administrative error. Component initializers then quietly return when classes are absent.
- Risk: A partial/corrupt ZIP can appear activated while security or functionality components are missing, complicating diagnosis.
- Suggested fix: Treat truly required files as activation/runtime errors with a safe administrator notice; retain defensive optional-component checks only where intentional.
- Blocks private beta: **No; ZIP integrity testing mitigates it**

### L-04: Unsaved-warning suppression begins on submit intent, not confirmed success

- File: `assets/js/admin-ticket-ai.js`
- Function/method: `watchSupportCandyReplySubmit()` / `beginReplySubmission()` flow
- Issue: The fallback warning is suppressed as soon as a broad reply/send control is clicked or a containing form submit starts. If client validation or the SupportCandy request fails and no later editor event occurs, navigation may proceed without the fallback warning.
- Risk: An agent could lose an unsent AI-inserted draft after a failed submission attempt. This is a data-loss bug, not an access-control vulnerability.
- Suggested fix: Clear dirty state only after confirmed SupportCandy reply success or editor reset; restore it on validation/AJAX failure.
- Blocks private beta: **No**

## Positive Checks Passed

- All **38 PHP files** passed `php -l` under the local PHP runtime.
- WordPress runtime bootstrap confirmed all primary `SCAI_*` classes load, including schema, controllers, engines, adapter, providers, repositories, attachment services, and admin pages.
- The three plugin-owned schema tables (`scai_conversations`, `scai_knowledge`, `scai_usage_logs`) exist in the test site.
- All five expected AJAX hooks are registered; no matching `wp_ajax_nopriv_*` hooks are registered.
- Every AI AJAX action verifies the shared `scai_ai_action` nonce, validates a positive ticket ID, and calls the central role permission service before AI execution/repository access.
- WordPress administrators do not automatically bypass `SCAI_Permissions`; an active non-group SupportCandy agent row and allowed role are required when the service is loaded.
- Settings, provider, and permissions saves use `manage_options` and WordPress nonces.
- System Check is `manage_options` protected; advanced tools require both `WP_DEBUG` and `manage_options`.
- Usage Logs is read-only and administrator/diagnostics-capability protected. Filters and pagination values are allowlisted or integer-normalized.
- SQL values are generally prepared. Dynamic identifiers are schema-controlled or sanitized/derived from inspected database metadata. No direct user-controlled table or ORDER BY value was confirmed.
- Attachment text reads are local-only, realpath checked, constrained to allowed upload directories, size bounded, line/character bounded, and type allowlisted. Null-byte/binary detection is present.
- Image preparation is local-only, realpath checked, upload-directory constrained, size bounded, requires matching allowed MIME plus extension, and verifies actual image MIME before base64 encoding.
- No external attachment URL is downloaded by the reader/preparer.
- Prepared base64 image data is attached transiently to provider payloads. Usage-log metadata records booleans/counts rather than prompt, image data, or file paths.
- Conversation metadata and usage metadata recursively strip common secret/path/raw-response keys. Conversation rows store generated assistant content, not raw prompts or prepared image data.
- Provider settings UI reads redacted configuration and displays a masked key, not the full stored key.
- HTTP timeout is bounded to 120 seconds, redirects are limited to three, and SSL verification is not explicitly disabled.
- AI/customer/history content is rendered with `textContent` in the popup. The only `innerHTML` assignment is in a detached helper used to convert editor HTML to plain text; generated reply insertion escapes text before TinyMCE HTML insertion.
- No automatic SupportCandy reply submission was found. Append, replace, and merged-reply application only modify the editor.
- Normal uninstall preserves data by default and table deletion is limited to schema-defined `scai_*` tables.
- No official SupportCandy AI module classes, AJAX/REST endpoints, options, tables, CSS, or JavaScript were found in use.
- No unscoped generic CSS selector that intentionally restyles WordPress/SupportCandy globally was confirmed in the reviewed plugin styles.

## AJAX Endpoint Review

| Action | Nonce | Capability/permission | Sanitization | Result |
|---|---|---|---|---|
| `scai_generate_ticket_summary` | `scai_ai_action` verified | Logged-in AJAX plus active/allowed SupportCandy agent role; **missing ticket ACL** | Ticket ID `absint`; length allowlisted | **FAIL — H-01** |
| `scai_generate_ticket_reply` | `scai_ai_action` verified | Logged-in AJAX plus active/allowed SupportCandy agent role; **missing ticket ACL** | Ticket ID `absint`; tone/length/format allowlisted | **FAIL — H-01** |
| `scai_improve_ticket_reply` | `scai_ai_action` verified | Logged-in AJAX plus active/allowed SupportCandy agent role; **missing ticket ACL** | Ticket ID `absint`; reply sanitized as textarea; style options allowlisted; scalar-shape gap | **FAIL — H-01, L-01** |
| `scai_merge_ticket_reply` | `scai_ai_action` verified | Logged-in AJAX plus active/allowed SupportCandy agent role; **missing ticket ACL** | Draft/suggestion scalar checked and textarea sanitized; style options allowlisted | **FAIL — H-01** |
| `scai_get_ticket_conversation_history` | `scai_ai_action` verified | Logged-in AJAX plus active/allowed SupportCandy agent role; **missing ticket ACL and agent scope** | Ticket ID `absint`; limit capped at 25; minimal response fields | **FAIL — H-01, H-02, H-04** |

No `scai_*` `nopriv` action was found.

## Admin Page Review

| Page | Capability | Nonce on save/action | Escaping status | Notes |
|---|---|---|---|---|
| Settings | `manage_options` | Yes | Passed reviewed fields/notices | Registered setting sanitizers and safe redirect pattern used. |
| AI Providers | `manage_options` | Yes for save/test | Passed; API key masked | M-01 and M-02 apply. Provider test saves posted configuration before testing. |
| AI Permissions | `manage_options` | Yes | Passed | Save values normalized; UI does not change enforcement. Ticket ACL remains absent in service (H-01). |
| System Check | `manage_options` | Yes for actions | Passed reviewed output | Advanced database/context tools require `WP_DEBUG` and administrator capability. No normal-mode local path/base64 output confirmed. |
| Usage Logs | Administrator diagnostics capability | Read-only; no mutation nonce needed | Passed reviewed table/filter/pagination output | Error-message minimization recommended (L-02). |

## File/Attachment Security Review

### Text attachment reader

`SCAI_Attachment_Reader` rejects URLs/streams, resolves real paths, restricts reads to the WordPress uploads base (plus trusted filtered bases), verifies readability, caps default file size at 1 MB, caps excerpts at 6,000 characters/200 lines, and detects null bytes. Supported extension/MIME lists are bounded and filterable by trusted server code. No arbitrary file read was confirmed.

### Image attachment preparer

`SCAI_Image_Attachment_Preparer` applies realpath/upload-base containment, requires both an allowed extension and MIME, checks the actual image MIME, caps images at 5 MB by default, and returns no local path in its result. Data URLs are built only for validated local JPG/JPEG, PNG, WebP, or GIF files. No raw base64 rendering or storage path was confirmed.

### Adapter path resolution

`SCAI_SupportCandy_Adapter` constructs candidates from SupportCandy metadata, normalizes paths, rejects traversal, validates containment, and rechecks `realpath()` before returning an internal local path. The local path remains in structured adapter/context data for trusted internal consumers, but reviewed provider, history, usage-log, and normal diagnostics output paths do not serialize it. Ticket authorization must be fixed before this data can be considered adequately isolated (H-01).

## Data Leakage Review

| Data type | Current result |
|---|---|
| API keys in UI | Full key not shown; masked value only. |
| API keys in logs/history | No direct storage path confirmed. Sensitive-key stripping is present. |
| API keys at rest/uninstall | Stored plaintext in a non-autoloaded option (M-02) and not deleted by destructive uninstall (H-03). |
| Local attachment paths | Used internally; not present in normal System Check, AJAX history, usage metadata, or conversation metadata. Advanced sanitizers strip path-like keys. |
| Base64 images | Transient request payload only in reviewed flow; no database/log/history storage confirmed. |
| Raw prompts | Usage logs store prompt/message presence flags, not raw prompt text. Conversation rows store assistant output and safe metadata. |
| Raw provider responses | Held in response objects with redaction and not copied into the reviewed usage/conversation metadata; provider error text can still reach UI/logs (L-02). |
| Ticket/customer/internal-note data | Exposed to the configured provider by intended AI operation, but current missing ticket ACL permits access by any allowed-role agent using another ticket ID (H-01). |
| Cross-agent AI history | Currently exposed ticket-wide (H-02). |

AI prompt instructions tell the model not to reveal internal notes in customer-facing replies, but prompt instructions are not a security boundary. Agent review before insertion/submission remains required, and adversarial customer/attachment prompt-injection testing should be added before public release.

## Final Fix Plan

### 1. Must fix before private beta

1. Add fail-closed SupportCandy ticket-view authorization to `SCAI_Permissions` and apply it to every AI/history endpoint and asset decision.
2. Scope user-facing conversation history by current agent identity and document the identity field semantics.
3. Enforce conversation expiration during reads and implement/schedule bounded cleanup.
4. Make destructive uninstall remove all plugin-owned options, especially `scai_provider_configs` and its API key.
5. Add automated negative tests: allowed role + unauthorized ticket, denied role, inactive agent, agent group, logged-out request, expired history, cross-agent history, and uninstall credential removal.

### 2. Should fix before public release

1. Decide and enforce a provider URL/SSRF policy, including redirect behavior and private-network endpoints.
2. Establish and document provider credential-at-rest handling; reduce secret-returning API defaults.
3. Make image transmission opt-in for new installations and display a clear third-party data-transfer notice.
4. Tighten backend/frontend asset enqueue conditions to verified ticket-detail contexts.
5. Normalize all AJAX parameter shape checks and return controlled errors for arrays/objects.
6. Replace detailed provider errors in agent responses/durable logs with allowlisted safe diagnostics.
7. Add prompt-injection/red-team tests for customer messages, text attachments, image text, and internal notes.
8. Add PHPCS (WordPress ruleset), static analysis, and automated JS tests to CI; those tools were not installed in this environment.

### 3. Later cleanup

1. Make the loader fail visibly for missing required files.
2. Tie unsaved-warning suppression to confirmed reply success rather than submit intent.
3. Add multisite new-site provisioning or explicitly document single-site support.
4. Add an explicit deactivation lifecycle once recurring jobs are introduced.
5. Update Usage Logs feature labels/filters to include `reply_merge` for operational completeness.

