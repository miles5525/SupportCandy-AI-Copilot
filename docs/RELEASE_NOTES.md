# SupportCandy AI Assistant v0.9.2 Release Notes

SupportCandy AI Assistant is an independent plugin. It is not an official SupportCandy product and is not developed, endorsed, or supported by the official SupportCandy team.

## 1. Release Status

- Current target: SetMySite management testing.
- v0.9.2 Custom Knowledge Base / RAG MVP development is complete.
- Provider scope is the original single GPT/OpenAI-compatible configuration only.
- Multiple AI providers remain future roadmap work and are not implemented in this build.
- The plugin is available under Support → AI Assistant when SupportCandy is active.
- Agent review remains required before using or sending AI-generated content.

## 2. Highlights

- Custom Knowledge Base / RAG MVP.
- Manual text, public URL, and supported file upload knowledge sources.
- Deterministic custom knowledge retrieval with false-positive relevance gating.
- BetterDocs Knowledge Base MVP.
- AI Summary with Suggested Knowledge References.
- Generate Reply with relevant BetterDocs guidance.
- Improve Current Draft while preserving agent intent.
- Merge with my draft workflow.
- Role-based AI access control for SupportCandy agents.
- Usage Logs.
- Agent-scoped Conversation History.
- Optional image understanding.
- Bounded text attachment context.

## 3. New in 0.9.2

- Added the Custom Knowledge Base / RAG MVP using the existing `scai_knowledge` table.
- Added the Knowledge Sources administration page.
- Added manual text knowledge sources.
- Added safe single-page public URL knowledge sources.
- Added file upload sources for TXT, Markdown, CSV, LOG, and JSON.
- Added safe PDF unsupported handling unless an approved extractor is registered.
- Added deterministic custom knowledge search and relevance gating to avoid false-positive results.
- Added custom knowledge context integration for AI Summary, Generate Reply, Improve Current Draft, and Merge with my draft.
- Added Custom Knowledge Base status and search testing to System Check.
- Added Custom Knowledge Base setup status to Getting Started.
- No database schema change was required.

## 4. Previous 0.9.1 Additions

- Added a setting to enable BetterDocs knowledge.
- Added a Getting Started page with a setup checklist for initial configuration and verification.
- Added a safe, read-only BetterDocs adapter for published public documentation.
- Added deterministic Knowledge Search Service retrieval and relevance scoring.
- Added BetterDocs detection to System Check.
- Added a manual BetterDocs Knowledge Search Test.
- Added documentation-aware prompt guidance for summaries, replies, improved drafts, and merged drafts.
- Added Suggested Knowledge References to summaries when relevant documents are included.
- Cleaned customer reply formatting to avoid generated subject lines and placeholder signatures.

## 5. Improved

- Improved AI reply quality and support-owned technical action wording.
- Improved summary structure and visibility of knowledge references.
- Improved BetterDocs and attachment diagnostics.
- Improved safe metadata handling so article bodies and raw prompts are not stored in usage or conversation metadata.
- Improved failure handling when optional BetterDocs or image capabilities are unavailable.
- Moved the visible plugin entry under Support → AI Assistant. The original top-level menu is now used only as a fallback when SupportCandy integration is unavailable.

## 6. Known Limitations

- BetterDocs retrieval uses keyword matching and deterministic scoring; vector embeddings are not included yet.
- Custom Knowledge retrieval is deterministic keyword RAG; embeddings and document chunking are not included yet.
- PDF sources require an approved extractor; otherwise they remain unsupported.
- AI output can be incomplete or incorrect and must be reviewed by an agent before use or sending.
- Only an OpenAI-compatible provider configuration has currently been tested.
- Image understanding depends on the configured provider and model supporting image input.
- BetterDocs MVP uses only published, publicly viewable, non-password-protected documentation.
- The plugin does not automatically submit or send ticket replies.

## 7. Privacy Notes

- Relevant ticket content may be sent to the AI provider configured by the site owner when an authorized agent runs an AI action.
- The site owner controls the provider endpoint, API credentials, model selection, and applicable provider account.
- BetterDocs integration reads only published public documentation and is read-only.
- Custom Knowledge uses bounded retrieved excerpts as supporting context and does not train the AI model.
- The plugin does not train AI models itself.
- The plugin does not automatically send replies.
- Site owners are responsible for reviewing their AI provider's terms, privacy practices, retention policies, and legal requirements.

## 8. Upgrade Notes

- There is no database schema change for v0.9.2; RAG uses the existing `scai_knowledge` table.
- Existing plugin settings should remain intact.
- Back up the site before upgrading.
- Re-test the configured provider connection after upgrading.
- Re-run System Check and confirm agent permissions after upgrading.

## 9. Management Test Notes

- Test the plugin on a staging site before production use.
- Review every AI summary, reply, improved draft, and merged draft before relying on or sending it.
- During management testing, report provider errors, permission problems, missing or incorrect ticket context, BetterDocs relevance issues, and Custom Knowledge retrieval issues.
- Include the WordPress, PHP, SupportCandy, BetterDocs, provider, and model versions in useful bug reports.
- Do not include API keys, private ticket content, or other secrets in reports.
