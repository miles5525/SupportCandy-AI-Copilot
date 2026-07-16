# SupportCandy AI Assistant Public Beta Checklist

Use this checklist first for the v0.9.1 management-test build and later for public beta preparation. Management testing has not started yet; complete package and staging checks before handoff.

## 1. Pre-release Checks

- [ ] Plugin version is `0.9.1` in all direct plugin version references.
- [ ] `readme.txt` is complete and reviewed.
- [ ] Release notes are complete and reviewed.
- [ ] External tester handoff documents are absent from the management-test package by design.
- [ ] License and independent-plugin disclaimer are correct.
- [ ] ZIP excludes internal and private documentation.
- [ ] ZIP excludes `.git`, `.vscode`, `.agents`, and `.codex`.
- [ ] ZIP excludes logs, SQL files, database backups, temporary files, and old ZIP packages.

## 2. Install Tests

- [ ] Fresh installation completes without errors.
- [ ] Upgrade from the existing private beta completes without errors.
- [ ] Plugin activates successfully.
- [ ] Plugin deactivates successfully.
- [ ] Uninstall preserves plugin data by default.
- [ ] Optional uninstall cleanup works only when explicitly enabled.
- [ ] Getting Started, Settings, AI Providers, AI Permissions, System Check, and Usage Logs pages open successfully.
- [ ] The top-level SupportCandy AI menu opens Getting Started.
- [ ] Getting Started is the first visible submenu and no duplicate SupportCandy AI submenu appears.
- [ ] The Getting Started setup checklist reflects the current configuration state.
- [ ] Existing settings remain intact after upgrade.

## 3. SupportCandy Tests

- [ ] Backend ticket page displays the AI panel.
- [ ] Frontend ticket page displays the AI panel for an authorized agent.
- [ ] AI Summary returns a relevant, factual result.
- [ ] Generate Reply returns reply-body-only text.
- [ ] Improve Current Draft preserves the draft's intent.
- [ ] Merge with my draft uses the current draft as the base.
- [ ] Conversation History is visible only to the correct agent.
- [ ] Usage Logs record safe operational data.
- [ ] AI actions never automatically submit a SupportCandy reply.

## 4. Permissions Tests

- [ ] An allowed SupportCandy agent can use AI actions on an accessible ticket.
- [ ] A disallowed SupportCandy agent cannot use AI actions.
- [ ] An allowed agent cannot use AI on a ticket they cannot access.
- [ ] A logged-out user cannot use AI actions.
- [ ] A WordPress administrator does not bypass AI permissions unless also allowed as a SupportCandy agent.
- [ ] Permission failures return safe messages without exposing ticket content.

## 5. Provider Tests

- [ ] API key and provider settings save successfully.
- [ ] Provider connection test succeeds with valid credentials.
- [ ] Invalid credentials produce a safe, useful error.
- [ ] Quota, rate-limit, timeout, and provider errors are handled safely.
- [ ] API keys are not displayed in full in the UI.
- [ ] API keys are not exposed in logs, diagnostics, conversation metadata, or usage metadata.
- [ ] Configured text model supports summary and reply actions.

## 6. BetterDocs Tests

- [ ] AI features continue normally when BetterDocs is inactive.
- [ ] AI features continue without documentation context when BetterDocs is active but the setting is off.
- [ ] BetterDocs knowledge setting saves and enables retrieval.
- [ ] System Check detects the BetterDocs runtime, post type, and taxonomies.
- [ ] BetterDocs Knowledge Search Test returns relevant published documents.
- [ ] AI Summary lists only included Suggested Knowledge References.
- [ ] Generate Reply uses relevant documentation naturally without dumping article content.
- [ ] Improve Current Draft and Merge with my draft use relevant guidance without overriding draft intent.
- [ ] Draft, private, and password-protected BetterDocs documents are never used.
- [ ] BetterDocs content is never modified and no BetterDocs search analytics are written.

## 7. Attachment and Image Tests

- [ ] Supported text or log attachment excerpts appear as bounded context.
- [ ] Unsupported or unreadable attachments fail safely.
- [ ] Image understanding remains off when disabled.
- [ ] Image understanding works with a supported provider and model when enabled.
- [ ] Unsupported provider or model falls back safely without false inspection claims.
- [ ] Base64 image data is not stored in history or logs.

## 8. Security and Privacy Checks

- [ ] All state-changing requests verify nonces.
- [ ] Admin and AI actions enforce the required capabilities and SupportCandy permissions.
- [ ] Admin output, URLs, titles, taxonomy names, and diagnostic values are escaped.
- [ ] Ticket-derived input and search text are sanitized and bounded.
- [ ] No API keys, local paths, base64 data, or secrets are exposed.
- [ ] Usage and conversation metadata contain safe fields only.
- [ ] Raw prompts and full BetterDocs article bodies are not stored in logs or metadata.
- [ ] Conversation retention and agent scoping work as configured.

## 9. Packaging Checks

- [ ] Release ZIP installs successfully through WordPress admin.
- [ ] ZIP has the root folder `supportcandy-ai`.
- [ ] ZIP contains required PHP, JavaScript, CSS, language, and public documentation files.
- [ ] ZIP does not include `AI_CONTEXT.md`, internal/private files, audits, development settings, or editor metadata.
- [ ] ZIP does not include `.git`, `.vscode`, `.agents`, `.codex`, logs, SQL files, backups, temporary files, or previous packages.
- [ ] Installed plugin reports version `0.9.1`.
- [ ] Installed files match the release commit.

## 10. Final Sign-off

- [ ] Local QA passed.
- [ ] Fresh-install QA passed.
- [ ] Upgrade QA passed.
- [ ] Package QA passed.
- [ ] Privacy and permissions review passed.
- [ ] Known limitations are documented for management review.
- [ ] Release commit and package checksum are recorded.
- [ ] Management-test build is ready for management handoff.

Sign-off owner: ____________________

Release commit: ____________________

Package checksum: ____________________

Date: ____________________
