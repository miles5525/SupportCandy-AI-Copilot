# Multiple AI Providers Architecture Audit

## 1. Purpose and decision summary

This audit defines a low-risk path from the v0.9.2 single OpenAI-compatible configuration to the v0.9.3 multiple-provider milestone. It is an implementation plan only. It does not change plugin code, provider settings, the AI Engine, or the database schema.

Recommended direction: **use registry-driven presets with one reusable OpenAI-compatible transport implementation** (option D: mix preset definitions with one reusable provider class).

- Keep `SCAI_AI_Engine`, `SCAI_AI_Request`, `SCAI_AI_Response`, and the provider interface provider-neutral.
- Refactor the current OpenAI-compatible provider so its identity, defaults, UI capabilities, and image declaration come from an immutable preset definition rather than hardcoded methods.
- Register four logical providers: `openai`, `gemini_free`, `gemini_pro`, and `custom_openai_compatible`.
- Represent Gemini Free and Gemini Pro/Paid separately in the UI, while routing both through the same OpenAI-compatible request/response implementation.
- Keep advanced Gemini-native features out of the MVP. Google's OpenAI compatibility API is still described as beta and does not expose every native Gemini feature.
- Preserve the legacy `openai_compatible` active key and config during migration. Do not rename or delete it destructively.
- No database schema change is needed. The existing option structure already stores configurations keyed by provider key.

Official Google documentation currently shows the OpenAI-compatible base URL `https://generativelanguage.googleapis.com/v1beta/openai/`, bearer authentication with a Gemini API key obtained from Google AI Studio, `/chat/completions`, editable model identifiers, multimodal message compatibility, and a models listing endpoint. Model availability is time-sensitive, so defaults must remain editable and be verified before release: [Gemini OpenAI compatibility](https://ai.google.dev/gemini-api/docs/openai). Free and paid are API project billing/quota tiers rather than different wire protocols: [Gemini API billing](https://ai.google.dev/gemini-api/docs/billing/), [Gemini API pricing](https://ai.google.dev/gemini-api/docs/pricing).

## 2. Current provider architecture

### Provider contract and base behavior

`includes/providers/interface-provider.php` defines a useful provider-neutral contract:

- stable key, name, description, default model, and model suggestions;
- image and streaming capability declarations;
- configuration validation;
- normalized generation entry point.

`includes/providers/abstract-provider.php` supplies common request normalization, required-field validation, safe success/error response creation, usage normalization, and sensitive-config redaction. This layer is reusable and should remain provider-neutral.

### Current OpenAI-compatible implementation

`includes/providers/class-openai-compatible-provider.php` owns the complete transport mapping:

- hardcoded provider key `openai_compatible`;
- hardcoded label, description, default model, model suggestions, and `supports_images() = true`;
- required `api_key` and `base_url` validation;
- endpoint composition by appending `/chat/completions` unless already present;
- bearer authorization plus optional `OpenAI-Organization` and `OpenAI-Project` headers;
- OpenAI chat-completion payload generation;
- temperature, max-token, system/user message, and image data-URL mapping;
- OpenAI-style content, usage, model, request ID, and finish-reason normalization;
- bounded/redacted response and request metadata.

The transport shape is already suitable for Gemini's documented compatibility route. The hardcoded identity/default methods are the main barrier to safe reuse as four distinct logical providers.

### Registry and manager

`includes/services/class-provider-registry.php` hooks `scai_registered_providers` and currently adds one `SCAI_OpenAI_Compatible_Provider` instance.

`includes/providers/class-provider-manager.php`:

- accepts constructor-provided and filter-provided provider instances;
- indexes instances by their `get_key()` value;
- exposes provider choices/details;
- reads and updates the active provider key through `SCAI_Settings` with a direct option fallback;
- validates config and normalizes provider responses into `SCAI_AI_Response`.

The manager already supports multiple instances. It should not need a conceptual rewrite.

### Configuration storage

`includes/services/class-provider-config.php` stores all configs in the single WordPress option `scai_provider_configs`, keyed by provider key. The active provider is stored in `scai_active_provider` (also registered through `SCAI_Settings`). Config fields currently include:

- `enabled`;
- `api_key`;
- `base_url`;
- `model`;
- `organization`;
- `project`;
- `timeout`;
- `extra`.

Secrets are preserved when an empty password field is submitted and are masked when fetched for display. The option structure is already adequate for separate configs for four presets. No table or schema change is warranted.

The uninstaller already deletes `scai_active_provider` and `scai_provider_configs`; new preset configs stored inside the existing aggregate option require no additional uninstall key.

### AI Engine and normalized objects

`includes/ai/class-ai-engine.php`:

1. normalizes the input to `SCAI_AI_Request`;
2. reads the active provider key;
3. checks that the manager registered it;
4. loads the matching config with secrets;
5. validates it through the selected provider;
6. delegates generation to the provider manager;
7. records safe operational metadata through the usage logger.

Its connection test is a normal provider-neutral request with a small deterministic prompt, 20 max tokens, and temperature zero.

`SCAI_AI_Request` holds feature, model, instructions, prompt/messages, context, transient images, stream, temperature, max tokens, and safe metadata. Image serialization excludes raw data URLs. `SCAI_AI_Response` normalizes content, provider/model identity, token counts, duration, finish reason, errors, references, raw-response metadata, and general metadata.

RAG and BetterDocs context are composed before the AI Engine receives the request, so adding compatible providers should not require changes to retrieval, context, or prompt composition.

### HTTP client

`includes/services/class-http-client.php` uses `wp_remote_request()` with JSON encoding, a 1-120 second timeout, three redirects, normalized JSON/body/headers, and redacted safe request metadata. It currently accepts both HTTP and HTTPS hosts and does not reject localhost, private, link-local, reserved, or internal destinations.

This behavior is acceptable only for explicitly trusted fixed presets. It is not an adequate default security boundary for an administrator-supplied custom provider endpoint.

### Provider Settings page

`includes/admin/class-provider-settings-page.php` is capability- and nonce-protected and supports one active provider. However, it is currently provider-specific:

- the selector is registry-driven, but all rendered fields are hardcoded to `openai_compatible`;
- saving always updates the `openai_compatible` config, regardless of selected provider;
- the connection test reads the already-saved active provider/config through `SCAI_AI_Engine` rather than testing unsaved posted values;
- API keys use password inputs and only masked stored values are displayed;
- test errors are kept in a short user-scoped transient and the exact active API key is replaced if echoed.

The page needs a registry/preset-driven field renderer and generic save routing. No provider-specific JavaScript currently participates, and the v0.9.3 MVP can remain server-rendered if selecting a preset submits/reloads the page. If dynamic cards are desired later, any JavaScript should control visibility only; validation and authorization must remain server-side.

### Loader and admin integration

`includes/core/class-loader.php` already loads the interface, abstract provider, OpenAI-compatible provider, manager, registry, AI Engine, and Provider Settings page in a valid dependency order. A preset-definition value object or factory would need a loader entry before the registry/provider instances. `includes/admin/class-admin.php` already registers the AI Providers page and requires no new menu.

## 3. Current end-to-end provider flow

1. `SCAI_Provider_Registry` registers the built-in provider through `scai_registered_providers`.
2. `SCAI_Provider_Manager` indexes registered provider instances by key.
3. The Providers page saves `scai_active_provider` and a keyed entry under `scai_provider_configs`.
4. A ticket action builds RAG/BetterDocs/ticket context and produces an `SCAI_AI_Request` before provider selection.
5. `SCAI_AI_Engine` resolves the active key and matching stored config.
6. The manager validates config and invokes the provider.
7. The OpenAI-compatible provider converts the normalized request to an OpenAI chat-completion request and calls `SCAI_HTTP_Client`.
8. The provider converts compatible JSON into `SCAI_AI_Response`.
9. The AI Engine/upper services log safe metadata and return the normalized result to Summary, Reply, Improve, or Merge.

Errors remain normalized as safe codes/messages. A concern for v0.9.3 is that provider-supplied error messages and decoded JSON can contain echoed request material. Error display/logging should use an allow-listed classification layer rather than retaining arbitrary provider JSON.

## 4. Recommended implementation design

### Chosen approach: preset definitions plus one transport

Do not create separate Gemini Free and Gemini Pro provider classes containing duplicate HTTP/payload parsing logic. Instead:

1. Add an immutable provider preset definition (array contract or small value object) containing identity, defaults, field rules, help text, and declared capabilities.
2. Refactor `SCAI_OpenAI_Compatible_Provider` to accept a preset definition in its constructor.
3. Register one provider instance per preset, all sharing the same OpenAI-compatible behavior.
4. Allow only the custom preset to edit its base URL and image declaration.
5. Keep provider-specific advanced/native features outside the shared transport until there is a demonstrated need.

The preset contract should include at least:

- `key`, `label`, `description`;
- `default_base_url`, `default_model`, `model_suggestions`;
- `api_key_label`;
- `supports_images` as `yes`, `no`, or `unknown/unverified` at definition level;
- `base_url_editable`, `model_editable`;
- `organization_project_fields`;
- `setup_help`, `warning_text`;
- `endpoint_path` fixed to `/chat/completions` for these presets;
- optional `legacy_keys` for migration.

The existing boolean provider interface cannot express “unknown.” For runtime safety, unknown must behave as `false`; the richer registry detail can still show “Not verified.” Do not enable image transmission for a preset until the exact compatibility route/model combination passes QA.

### Why not separate Gemini classes

Gemini Free and Pro/Paid differ principally in project billing status, quotas, and available models, not in the chat-completion wire format. Separate classes would duplicate authentication, payload, response, usage, error, and image behavior and make fixes diverge. A distinct class becomes justified only if the plugin adopts the native Gemini API or features that cannot be represented safely through OpenAI compatibility.

## 5. Proposed provider registry entries

Model defaults below are release-time suggestions, not permanent guarantees. The implementation must verify them against the official models endpoint/docs immediately before shipping, keep the field editable, and avoid silently replacing an administrator's saved model.

| Key | Label | Description | Default base URL | Suggested default model | API key label | Images | Base URL editable | Model editable |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `openai` | OpenAI | OpenAI chat completions using the plugin's compatible transport. | `https://api.openai.com/v1` | Preserve current `gpt-4o-mini` for migration initially; revalidate before release. | OpenAI API Key | Model-dependent; preserve current behavior but warn | No | Yes |
| `gemini_free` | Google Gemini Free | Gemini API project using the Free Tier where available. | `https://generativelanguage.googleapis.com/v1beta/openai/` | Use an officially listed compatible Flash model verified at release; the docs currently demonstrate `gemini-3.5-flash`. | Gemini API Key | Unknown until preset/model QA; runtime false initially | No | Yes |
| `gemini_pro` | Google Gemini Pro / Paid | Gemini API project with billing enabled and paid-tier quotas/models. | Same Gemini compatibility URL | Editable officially available paid-compatible model; do not infer availability from the preset name. | Gemini API Key | Unknown until preset/model QA; runtime false initially | No | Yes |
| `custom_openai_compatible` | Custom OpenAI-Compatible | Administrator-supplied compatible endpoint and model. | Empty | Empty | Provider API Key | Administrator-declared only after warning; default false | Yes | Yes |

Setup/help text:

- **OpenAI:** Obtain an API key from the provider's developer platform. A consumer chat subscription does not necessarily include API usage.
- **Gemini Free:** Obtain the key from Google AI Studio/Gemini API. Free-tier access, models, regions, quotas, and data terms vary and can change. This is not the consumer Gemini application subscription.
- **Gemini Pro/Paid:** Use a Gemini API project with billing configured in Google AI Studio/Google Cloud. “Pro/Paid” is a plugin usage preset, not a guarantee that a particular model is available or that a consumer Gemini subscription covers API charges.
- **Custom:** Confirm that the endpoint implements OpenAI-style `/chat/completions`. Sending ticket and retrieved knowledge context to an arbitrary endpoint is a deliberate trust decision.

Free/paid presets should never alter billing. They only clarify expected account mode and defaults.

## 6. Model strategy

- Always keep the model field editable for every preset.
- Supply a conservative, release-verified default only for a new empty configuration.
- Never overwrite a saved model during plugin upgrades.
- Treat static model lists as suggestions, not validation allow lists.
- Show the configured model prominently in connection-test results.
- Add a future authenticated “Refresh available models” action using the preset's fixed host and `/models` endpoint. Make it explicit and nonce/capability protected; do not refresh on every page load.
- Cache only model IDs/labels for a short period, never credentials or raw responses.
- If model listing fails, retain manual entry and the last saved model.

The attached request cites `gemini-3.5-flash`, and the current official compatibility documentation also demonstrates it. Because model catalogs change, the audit intentionally does not make it a permanent hardcoded assumption.

## 7. Gemini-specific considerations

- Gemini Free and Gemini Pro/Paid can share the same compatibility transport and bearer authentication.
- The distinction is billing/quota/model availability and product guidance, not a separate plugin protocol.
- Gemini API keys come from the Gemini API/Google AI Studio project flow, not merely from a consumer Gemini app subscription.
- The base URL should be fixed and non-editable for both presets.
- The model remains editable and must be verified against the account's available models.
- Although official compatibility docs demonstrate image inputs, image support must be QA-tested for each selected model before the plugin declares it. Start disabled/unknown for v0.9.3 unless the full ticket-image path passes regression testing.
- Do not add native Gemini grounding, files, thought controls, audio, embeddings, function calling, streaming, or provider-specific `extra_body` parameters in the MVP.
- Compatibility support is documented as beta; error shapes, supported parameters, and behavior may evolve.
- Temperature/max tokens should remain the existing provider-neutral request controls. Do not expose new Gemini-only tuning fields yet.

## 8. Custom OpenAI-compatible provider

Required fields:

- Provider display name (administrator label, bounded plain text; stored in config, not used as the registry key).
- Base URL.
- API key.
- Model.
- Supports images toggle, default off, with an explicit warning that endpoint and model support must both be confirmed.
- Timeout using the current 1-120 second safe clamp.

Endpoint path:

- The current provider appends `/chat/completions`, so a separate path field is not required for the MVP.
- Accept either a version base URL or a full URL ending in `/chat/completions` as current behavior does.
- Defer arbitrary endpoint-path templates. If later added, allow only a relative path with no scheme, host, query credentials, or traversal.

Optional headers:

- Organization/project headers already exist, but they are OpenAI-specific. Show them for the OpenAI preset only unless a documented custom endpoint needs them.
- Do not add arbitrary custom-header storage in the MVP. It expands the secret-redaction and header-injection surface.

Security policy:

- Permit only public HTTPS endpoints by default.
- Reject URL credentials, fragments, malformed hosts, loopback, private, link-local, reserved, unspecified, multicast, metadata-service, `.local`, and `.internal` destinations after IPv4/IPv6 resolution.
- Revalidate immediately before connecting to reduce DNS rebinding risk.
- Do not follow authenticated cross-host redirects. The safest MVP is `redirection => 0` for provider calls; alternatively implement explicit same-scheme/same-host redirect validation and rebuild the request per hop.
- Never forward `Authorization`, organization/project, or future secret headers to a different host.
- Local/private/self-hosted endpoint support should be deferred to an explicit advanced opt-in product decision with prominent warnings and ideally a server-side constant/filter, not a casual checkbox.
- Bound response bytes and JSON depth. The current shared client does not set a response-size limit.
- Store/display only classified safe errors. Do not store raw provider response JSON or messages that may echo prompts, images, credentials, or source content.

## 9. Provider Settings UI impact

Recommended layout:

1. Four provider cards or a clearly labelled selector.
2. Active badge on the selected provider.
3. Provider-specific description, setup link/help, billing/quota warning, and data-sharing reminder.
4. Fields rendered from the selected preset definition.
5. Fixed base URL shown read-only or as explanatory text for OpenAI/Gemini presets; editable only for Custom.
6. Editable model field plus suggestions.
7. Password API-key input always blank; show only “saved” or a conservative mask. Never add reveal functionality.
8. Save and Test Connection actions scoped to the selected provider.
9. Test result should show safe provider label, model, success/failure category, and HTTP status if useful—never raw response or API key.

Use server-side cards/forms first. Avoid requiring JavaScript for correctness. If JavaScript is later added for switching visible cards, all posted provider keys/fields must still be validated against registry definitions.

The connection-test workflow should be corrected so it is unambiguous:

- either require Save before Test and label the button “Test Saved Configuration”; or
- validate and test the posted selected config directly without persisting it, through a dedicated provider-manager method.

The second option is friendlier but must never log posted secrets. It should not route through the global active-provider option.

## 10. Migration and backward compatibility

The requested final public registry uses `openai`, while current installations use `openai_compatible`. Use a non-destructive migration/alias strategy:

1. Continue registering a hidden legacy alias for `openai_compatible` during at least the v0.9.3 upgrade window, or make the manager resolve it to the OpenAI preset.
2. If `scai_active_provider === openai_compatible`, copy—not move—the existing config to `openai` only when `openai` has no config, then set the active key to `openai` after successful validation.
3. Keep the legacy config in the aggregate option for rollback during the transition; remove it only in a later deliberate cleanup release.
4. Preserve base URL, API key, model, organization, project, timeout, and enabled state exactly after sanitization.
5. Never replace an existing new-format `openai` config with legacy data.
6. Make the migration idempotent and safe when interrupted.

Alternative lower-risk product choice: retain `openai_compatible` as the visible key instead of `openai`. If the four requested keys are mandatory, the alias/copy migration above is required.

No schema migration is needed. New configs remain subkeys of `scai_provider_configs`. Existing uninstall cleanup already covers the aggregate config and active-provider options.

## 11. Error and logging hardening

- Map common failures to safe categories: invalid configuration, authentication, quota/rate limit, model unavailable, timeout/network, endpoint rejected, unsupported image, malformed response, and generic provider failure.
- Preserve HTTP status, safe provider key, safe model, duration, and provider request ID when available.
- Do not persist raw response bodies or arbitrary decoded provider JSON in `SCAI_AI_Response::raw_response`, usage logs, transients, or conversation metadata.
- Provider test notices should show a bounded safe message. Exact API-key replacement is useful but insufficient because responses can echo prompts or other secrets.
- Ensure provider configs are never merged into request metadata.
- Continue excluding raw prompts and image data URLs from logs/history.

## 12. Files likely to change during implementation

Core provider work:

- `includes/providers/class-openai-compatible-provider.php` — make identity/defaults/capabilities preset-driven while preserving transport behavior.
- `includes/services/class-provider-registry.php` — define/register four logical providers and legacy alias behavior.
- `includes/providers/class-provider-manager.php` — only if alias resolution, preset metadata, or direct-config connection testing belongs here.
- `includes/services/class-provider-config.php` — generic per-key field validation/defaults and idempotent legacy migration helpers.
- `includes/services/class-http-client.php` — endpoint validation, redirect credential safety, response limits, and safe errors.
- `includes/admin/class-provider-settings-page.php` — generic selected-provider rendering, saving, test flow, help, warnings, and active state.

Potential supporting files:

- a new provider preset definition/factory under `includes/providers/` or `includes/services/`;
- `includes/core/class-loader.php` for any new class;
- `includes/installers/class-uninstaller.php` only if new top-level options/transients are introduced (prefer not to introduce them);
- `includes/admin/class-getting-started-page.php` and diagnostics only if provider labels/readiness need richer reporting;
- admin CSS for provider cards; JavaScript is optional and not required for the MVP;
- version/readme/release/checklist/internal status documentation at Task H.

Files that should normally remain unchanged:

- `includes/ai/class-ai-engine.php`, unless a safe direct-config test seam cannot be added below it;
- `includes/ai/class-ai-request.php` and `class-ai-response.php`, unless safe error metadata needs tightening;
- Ticket AI, Context Engine, Prompt Engine, BetterDocs, and Custom Knowledge services;
- database schema, installer, and migrator.

## 13. Manual QA plan

### Upgrade and configuration

- Existing `openai_compatible` configuration and active selection survive upgrade.
- Legacy config is copied/mapped safely to `openai` without losing the API key, model, base URL, or timeout.
- Switching presets does not overwrite another preset's config.
- Blank API-key submission preserves the saved key only for the selected provider.
- API keys are never revealed or logged.

### OpenAI regression

- Save and test OpenAI.
- Generate Summary, Reply, Improve Current Draft, and Merge with my draft.
- Verify text-only and supported-image behavior remains equivalent to v0.9.2.

### Gemini Free

- Save a Google AI Studio Gemini API key and editable model.
- Test connection with a Free Tier project.
- Generate Summary.
- Verify authentication, quota, model-unavailable, and regional/free-tier errors are classified safely.

### Gemini Pro/Paid

- Save a paid-tier Gemini API project key and model.
- Test connection and Generate Reply.
- Confirm the plugin does not claim or modify billing status.
- Confirm Free and Paid configs remain independent even though they share a base URL.

### Custom provider

- Save/test a valid public HTTPS compatible endpoint and generate a reply.
- Reject HTTP by default, URL credentials, malformed URLs, localhost, IPv4/IPv6 loopback, RFC1918/private, link-local, metadata-service, `.local`, and `.internal` targets.
- Reject or safely handle redirects without forwarding bearer credentials cross-host.
- Handle invalid key, invalid model, timeout, quota, HTML/non-JSON, oversized, and malformed responses safely.

### Cross-provider regression

- RAG custom knowledge and BetterDocs context work with every provider.
- Summary references remain correct.
- Reply body still has no subject line or placeholder signature.
- Improve/Merge preserve draft intent.
- Disabled image understanding sends no image.
- Unknown/unsupported image capability falls back without false visual-inspection claims.
- Provider/model labels and safe usage data are correct; raw prompts, source bodies, API keys, and base64 images are absent.

## 14. Step-by-step implementation plan

### Task A: Provider registry/config audit cleanup

- Define the preset metadata contract and key allow list.
- Separate transport behavior from hardcoded provider identity.
- Make config rendering/saving generic by selected registered key.
- Define and test the `openai_compatible` to `openai` compatibility strategy.
- Add no new provider yet.

Acceptance: current OpenAI-compatible installation behaves exactly as before, and configs can be addressed generically without schema changes.

### Task B: Add Gemini Free/Pro registry presets

- Register `gemini_free` and `gemini_pro` using the reusable compatible provider.
- Fix their base URL to Google's compatibility endpoint.
- Add editable release-verified model suggestions and distinct help/warnings.
- Leave image support false/unknown until verified.

Acceptance: both appear as distinct logical providers without duplicated transport classes.

### Task C: Update Provider Settings UI

- Add provider cards/selector, active indicator, preset-driven fields, model suggestions, saved-key state, and setup guidance.
- Save only the selected provider config.
- Preserve all other provider configs.

Acceptance: selecting/saving one preset cannot mutate another preset's credentials.

### Task D: Provider connection tests for Gemini presets

- Make Test explicitly use the selected saved config or safely test posted config.
- Classify Gemini auth, quota, model, region, and malformed-response errors.
- Show only safe bounded results.

Acceptance: Free and Pro/Paid can be tested independently without making either globally active unintentionally.

### Task E: Add Custom OpenAI-compatible provider UI

- Add display name, public HTTPS base URL, API key, model, timeout, and image toggle defaulting off.
- Keep endpoint suffix behavior fixed and defer arbitrary headers/path templates.

Acceptance: valid public endpoints work; private/local endpoints are rejected by default.

### Task F: Security hardening for provider URLs/errors

- Add public-host IPv4/IPv6 validation and DNS checks.
- Disable redirects or enforce same-host authenticated redirects.
- Add response-size and JSON-depth bounds.
- Replace raw provider error retention with safe categories/metadata.

Acceptance: bearer tokens cannot cross hosts, SSRF test cases fail closed, and prompts/secrets cannot be echoed into logs/notices.

### Task G: Regression tests for all AI actions

- Run Summary, Reply, Improve, and Merge with each provider.
- Test BetterDocs, custom RAG, neither, and both.
- Test text-only and image-disabled/unsupported/supported paths.

Acceptance: provider choice changes transport only; shared AI behavior remains stable.

### Task H: Documentation, version, and package

- Update readme, release notes, provider setup/privacy text, QA checklist, version, and internal status.
- Build/install-test the v0.9.3 management package.

Acceptance: the package contains no internal docs, secrets, logs, or old archives, and upgrade preserves v0.9.2 configuration.

## 15. Risks and blockers before coding

1. **Provider key migration:** requested `openai` conflicts with the existing persisted `openai_compatible` key. An explicit alias/copy migration decision is required.
2. **Custom endpoint SSRF:** current HTTP validation accepts HTTP, localhost/private destinations, custom ports, and three redirects.
3. **Bearer redirect leakage:** authenticated redirects are not constrained to the original host. Disable redirects or implement explicit same-host handling before Custom Provider ships.
4. **Raw error retention:** provider JSON/error messages may echo prompt or sensitive request data. Tighten before expanding endpoint trust.
5. **Image capability granularity:** the interface is boolean while actual support is endpoint-and-model-specific. Unknown must fail closed.
6. **Connection-test semantics:** the current button tests saved active configuration, not necessarily the visible unsaved fields.
7. **Model churn:** Gemini and OpenAI model names/availability change. Defaults must be verified at release and remain editable.
8. **Compatibility beta:** Google's OpenAI compatibility surface is beta and may not match all current OpenAI parameters/features.
9. **Free vs paid terminology:** UI must explain these are Gemini API project tiers/modes, not consumer subscription entitlements or billing guarantees.

## 16. Recommended starting point

Start with **Task A: Provider registry/config audit cleanup**. Refactor the existing single provider into a preset-driven instance while registering only the current provider, then prove that existing configuration, connection testing, all four AI actions, RAG, and images remain unchanged. This creates the safe extension seam before Gemini or arbitrary custom endpoints are exposed.
