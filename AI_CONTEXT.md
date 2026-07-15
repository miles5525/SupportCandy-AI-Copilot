# SupportCandy AI Assistant - AI Development Context

## Project
SupportCandy AI Assistant is a standalone WordPress plugin for SupportCandy.

It is not official SupportCandy AI and must not use or conflict with official SupportCandy AI tables, options, classes, AJAX, REST, CSS, or JS.

## Naming Rules
- Classes: SCAI_
- Options: scai_
- AJAX actions: scai_
- REST namespace: /wp-json/scai/v1/
- CSS prefix: scai-
- DB tables: {$wpdb->prefix}scai_...

## Architecture
SupportCandy Adapter
Context Engine
Prompt Engine
Ticket AI Service
AI Engine
Provider
Conversation Repository
Usage Logger
Admin Pages
Frontend/Backend Popup

## Current Completed Features
- AI Summary
- AI Reply
- Improve Current Draft
- Merge with my draft
- Replace draft
- Append below draft
- Apply merged reply
- Conversation history popup
- Usage logs
- AI permissions by SupportCandy agent role
- Ticket-level access authorization
- Agent-scoped conversation history
- Conversation retention enforcement
- Full uninstall cleanup
- Image understanding with real visual inspection
- Image understanding OFF by default for new installs
- Text/log attachment reading
- Backend AI popup
- Frontend AI popup
- Unsaved draft warning for manual and AI draft changes
- Settings page
- Provider settings page
- AI Permissions page
- System Check page
- Usage Logs page
- BetterDocs Knowledge Base MVP complete
- BetterDocs setting and safe read-only public document adapter
- Deterministic BetterDocs Knowledge Search Service
- BetterDocs detection and knowledge search tests in System Check
- BetterDocs guidance in AI Summary, AI Reply, Improve Current Draft, and Merge with my draft
- Suggested Knowledge References in summaries when relevant documents are included
- Reply body formatting without generated subject lines or placeholder signatures
- BetterDocs integration hardening review complete

## Security Rules
- WordPress administrator must not bypass SupportCandy AI access rules.
- AI access requires active SupportCandy agent role and ticket-level access.
- No logged-out AI access.
- No wp_ajax_nopriv for AI actions.
- All AJAX must verify nonce.
- Do not expose API keys.
- Do not expose local file paths.
- Do not store base64 image data in logs/history.
- Do not store raw prompts in usage logs.
- Do not modify SupportCandy tickets automatically.
- Never auto-submit a reply.
- Agent must manually submit final reply.

## Important Audit Fixes Completed
- H-01 Ticket-level SupportCandy access authorization fixed.
- H-02 Conversation history scoped to current agent/user.
- H-04 Expired conversations hidden on reads and daily cleanup scheduled.
- H-03 Destructive uninstall deletes all plugin-owned options including provider configs/API key.
- M-03 Image understanding disabled by default for fresh installs.

## Development Workflow
- Modify one file or one small feature at a time.
- Explain purpose before code.
- Do not rewrite the whole plugin.
- Run tests after each change.
- Keep private-beta ZIP clean.
- Exclude AI_CONTEXT.md, audit reports, .git, .vscode, .agents, .codex, logs, SQL backups, temp files from ZIP.

## Local URLs
Backend ticket:
http://localhost/supportcandy-ai-test/wp-admin/admin.php?page=wpsc-tickets&section=ticket-list&id=1

Frontend ticket:
http://localhost/supportcandy-ai-test/support/?wpsc-section=ticket-list&ticket-id=1

Settings:
http://localhost/supportcandy-ai-test/wp-admin/admin.php?page=scai-settings

Provider:
http://localhost/supportcandy-ai-test/wp-admin/admin.php?page=scai-provider-settings

Permissions:
http://localhost/supportcandy-ai-test/wp-admin/admin.php?page=scai-permissions

System Check:
http://localhost/supportcandy-ai-test/wp-admin/admin.php?page=scai-diagnostics

Usage Logs:
http://localhost/supportcandy-ai-test/wp-admin/admin.php?page=scai-usage-logs

## Current Product Status
BetterDocs Knowledge Base MVP: COMPLETE

The plugin is ready for the next private beta package.

## Next Product Phase
- Create the next private beta ZIP.
- Run a clean install and upgrade test using the ZIP.
- Continue with UI polish and public beta preparation after package verification.
