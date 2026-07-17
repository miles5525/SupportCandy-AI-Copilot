# Private Beta Status

## Completed
- Private beta security audit blockers fixed.
- H-01 ticket-level access authorization passed.
- H-02 agent-scoped conversation history passed.
- H-04 conversation retention enforcement passed.
- H-03 full uninstall cleanup passed.
- M-03 image understanding default OFF passed.
- Private beta ZIP created and install-tested.
- Full QA passed.
- BetterDocs Knowledge Base MVP completed and tested.
- BetterDocs setting and safe read-only public document adapter passed.
- Knowledge Search Service and System Check search test passed.
- AI Summary Suggested Knowledge References passed.
- AI Reply, Improve Current Draft, and Merge with my draft BetterDocs guidance passed.
- Reply body formatting without subject lines or placeholder signatures passed.
- BetterDocs final hardening review completed.
- Getting Started / Setup Checklist page completed.
- Admin menu cleanup completed: the top-level SupportCandy AI menu opens Getting Started and the duplicate submenu was removed.
- Custom Knowledge Base / RAG MVP completed and hardened.
- Knowledge Sources administration for manual text, URL, and file upload sources completed.
- TXT, Markdown, CSV, LOG, and JSON source extraction completed.
- Safe unsupported PDF handling completed for installations without an approved extractor.
- Custom knowledge retrieval, relevance gating, AI context integration, System Check, and Getting Started support completed.
- RAG uses the existing `scai_knowledge` table; no database schema change was required.
- External tester handoff documentation intentionally removed because this package is for management testing.

## Package Status
v0.9.1 private beta ZIP install test passed and remains the previous verified package baseline.

v0.9.2 RAG MVP development is complete from the development side.

Current package target: `supportcandy-ai-v0.9.2-rag-management-test.zip`. Management testing will happen later.

## Next Phase
Build and install-test `supportcandy-ai-v0.9.2-rag-management-test.zip`, then hand it off for management testing when scheduled.

Multiple AI providers are planned next:

- Gemini Free
- Gemini Pro/Paid
- Custom OpenAI-compatible provider
