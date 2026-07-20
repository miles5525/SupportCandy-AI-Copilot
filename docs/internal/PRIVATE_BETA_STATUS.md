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
- Admin menu integration completed: Support → AI Assistant opens Getting Started, with the original top-level menu retained only as a fallback when SupportCandy integration is unavailable.
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

Current target: SetMySite management testing of the v0.9.2 GPT/OpenAI-compatible-only RAG build.

Provider scope: the original single GPT/OpenAI-compatible provider configuration only.

## Next Phase
Prepare and install-test the SetMySite v0.9.2 management package when packaging is authorized, then hand it off for management testing.

Multiple AI providers remain a future roadmap item and are not implemented in this build:

- Gemini Free
- Gemini Pro/Paid
- Custom OpenAI-compatible provider
