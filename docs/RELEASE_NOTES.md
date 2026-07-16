# SupportCandy AI Assistant v0.9.1 Release Notes

SupportCandy AI Assistant is an independent plugin. It is not an official SupportCandy product and is not developed, endorsed, or supported by the official SupportCandy team.

## 1. Release Status

- Public beta preparation build currently targeted for management testing.
- v0.9.1 private beta ZIP install test passed.
- v0.9.1 development is complete from the development side; management testing will follow after the final package is rebuilt and install-tested.
- Agent review remains required before using or sending AI-generated content.

## 2. Highlights

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

## 3. New in 0.9.1

- Added a setting to enable BetterDocs knowledge.
- Added a Getting Started page with a setup checklist for initial configuration and verification.
- Added a safe, read-only BetterDocs adapter for published public documentation.
- Added deterministic Knowledge Search Service retrieval and relevance scoring.
- Added BetterDocs detection to System Check.
- Added a manual BetterDocs Knowledge Search Test.
- Added documentation-aware prompt guidance for summaries, replies, improved drafts, and merged drafts.
- Added Suggested Knowledge References to summaries when relevant documents are included.
- Cleaned customer reply formatting to avoid generated subject lines and placeholder signatures.

## 4. Improved

- Improved AI reply quality and support-owned technical action wording.
- Improved summary structure and visibility of knowledge references.
- Improved BetterDocs and attachment diagnostics.
- Improved safe metadata handling so article bodies and raw prompts are not stored in usage or conversation metadata.
- Improved failure handling when optional BetterDocs or image capabilities are unavailable.
- Simplified the admin menu by removing the duplicate SupportCandy AI submenu; the top-level menu now opens Getting Started.

## 5. Known Limitations

- BetterDocs retrieval uses keyword matching and deterministic scoring; vector embeddings are not included yet.
- AI output can be incomplete or incorrect and must be reviewed by an agent before use or sending.
- Only an OpenAI-compatible provider configuration has currently been tested.
- Image understanding depends on the configured provider and model supporting image input.
- BetterDocs MVP uses only published, publicly viewable, non-password-protected documentation.
- The plugin does not automatically submit or send ticket replies.

## 6. Privacy Notes

- Relevant ticket content may be sent to the AI provider configured by the site owner when an authorized agent runs an AI action.
- The site owner controls the provider endpoint, API credentials, model selection, and applicable provider account.
- BetterDocs integration reads only published public documentation and is read-only.
- The plugin does not train AI models itself.
- The plugin does not automatically send replies.
- Site owners are responsible for reviewing their AI provider's terms, privacy practices, retention policies, and legal requirements.

## 7. Upgrade Notes

- There is no database schema change from the previous private beta.
- Existing plugin settings should remain intact.
- Back up the site before upgrading.
- Re-test the configured provider connection after upgrading.
- Re-run System Check and confirm agent permissions after upgrading.

## 8. Management Test Notes

- Test the plugin on a staging site before production use.
- Review every AI summary, reply, improved draft, and merged draft before relying on or sending it.
- During management testing, report provider errors, permission problems, missing or incorrect ticket context, and BetterDocs relevance issues.
- Include the WordPress, PHP, SupportCandy, BetterDocs, provider, and model versions in useful bug reports.
- Do not include API keys, private ticket content, or other secrets in reports.
