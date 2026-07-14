# SupportCandy AI Assistant - AI Development Context

## Project Name

SupportCandy AI Assistant

## Project Owner

Milind Ighe / WebiZons

## Project Type

Standalone WordPress plugin for SupportCandy.

This plugin is not associated with the official SupportCandy team.

## Product Goal

Build a commercial-quality AI Assistant plugin for SupportCandy that helps support agents:

* Understand tickets.
* Generate replies.
* Improve existing replies.
* Use company knowledge.
* Analyze ticket screenshots.
* Continue AI conversations inside a ticket context.
* Track AI usage for administrators.

## Development Philosophy

This project must be built like a long-term commercial WordPress product.

Do not generate the whole plugin at once.

Always work in this sequence:

1. Architecture
2. Folder structure
3. One file
4. Test
5. Next file
6. Test

Every file must be production-ready before moving to the next file.

## Current Development Status

Already completed:

* `supportcandy-ai.php`
* `includes/core/class-loader.php`
* `includes/core/class-plugin.php`
* Folder structure has already been created.

Current next milestone:

* Database and installation foundation.

## Core Architecture

The approved architecture is:

```text
SupportCandy
    ↓
SupportCandy Adapter
    ↓
Context Engine
    ↓
Prompt Engine
    ↓
Conversation Engine
    ↓
Knowledge Engine
    ↓
Image Engine
    ↓
AI Engine
    ↓
Provider
    ↓
Response Formatter
    ↓
Sidebar
```

This project must be built using reusable engines and services.

Do not build feature-specific spaghetti code.

## Critical Isolation Rule

This plugin must work even if the official SupportCandy AI module is installed.

There must be zero conflicts.

Never use official SupportCandy AI:

* Tables
* Options
* AJAX actions
* REST endpoints
* Classes
* CSS
* JavaScript
* Internal AI logic

Everything related to AI must belong to this plugin.

## SupportCandy Integration Rule

This plugin may only read SupportCandy data.

Allowed read-only data:

* Tickets
* Threads
* Attachments
* Ticket fields
* Users
* BetterDocs content if available

Do not modify SupportCandy core data unless a future feature explicitly requires it and the architecture has been reviewed first.

## Naming Standards

Use these naming standards everywhere:

```text
Classes: SCAI_
Options: scai_
AJAX actions: scai_
REST namespace: /wp-json/scai/v1/
CSS classes: scai-
Database tables: {$wpdb->prefix}scai_
```

Examples:

```php
SCAI_Schema
SCAI_Installer
SCAI_Provider_Manager
scai_active_provider
scai_generate_reply
/wp-json/scai/v1/providers
.scai-sidebar
```

## Current Folder Structure

```text
supportcandy-ai/
├── assets/
│   ├── css/
│   ├── js/
│   ├── icons/
│   └── images/
├── includes/
│   ├── admin/
│   ├── ai/
│   ├── adapter/
│   ├── core/
│   ├── database/
│   ├── helpers/
│   ├── installers/
│   ├── models/
│   ├── providers/
│   └── services/
├── languages/
├── templates/
├── vendor/
├── supportcandy-ai.php
├── readme.txt
├── uninstall.php
└── AI_CONTEXT.md
```

## Planned Database Tables

Current planned tables:

```text
wp_scai_conversations
wp_scai_knowledge
wp_scai_usage_logs
```

Future tables:

```text
wp_scai_embeddings
wp_scai_sync_queue
```

Always use `$wpdb->prefix`.

Never hardcode `wp_`.

## Version 1 Features

### AI Provider Management

Supported provider types:

* OpenAI
* Google Gemini
* OpenAI-compatible APIs
* OpenRouter
* Ollama
* Groq
* DeepSeek

Administrators can configure multiple providers.

Only one provider is active at a time.

### Knowledge Base

Supported knowledge sources:

* PDF
* TXT
* URLs
* BetterDocs
* Previous tickets

There is one global knowledge base.

Knowledge sync behavior:

* Automatic sync every 24 hours.
* Manual sync.
* Incremental sync only.

### Ticket AI Overview

The overview should include:

* Summary
* Customer sentiment
* Key issues
* Pending questions
* Suggested next step
* Ticket field values

### AI Reply Generator

The reply generator should use:

* Current ticket
* Complete ticket conversation
* Ticket fields
* Knowledge base
* Recent tickets
* Images

Supported actions:

* Append
* Replace
* Copy

### Reply Assistant

Supported actions:

* Improve writing
* Fix grammar
* Make professional
* Make friendly
* Make shorter
* Make longer

### AI Conversation

Conversation behavior:

* ChatGPT-style conversation.
* Private per agent.
* Remembers previous prompts.
* Scoped by ticket ID, agent ID, and conversation ID.
* Retention period: 30 days.
* Cleanup via WP Cron.

### Image Understanding

Image understanding is important.

Supported image types:

* JPG
* JPEG
* PNG
* WEBP

Only the five most recent ticket images should be analyzed automatically.

Image understanding should be used for:

* Ticket summary
* Reply generation
* AI conversation

### Knowledge References

When AI uses knowledge sources, show references from:

* BetterDocs
* PDFs
* Websites
* Previous tickets

### Company Instructions

Administrators can define company-wide AI instructions.

These instructions must be applied automatically to every AI request.

### Reports

Reports are administrator-only.

Track:

* Requests
* Tokens
* Usage
* Features

### Sidebar UI

Use a modern sidebar, not popup windows.

Workflow:

```text
AI Button beside reply editor
    ↓
Open Sidebar
    ↓
Summary
    ↓
Reply
    ↓
Improve
    ↓
Conversation
    ↓
Prompt Input
    ↓
Knowledge References
```

## Coding Standards

Always follow:

* WordPress Coding Standards.
* SOLID principles where practical.
* Single Responsibility Principle.
* Proper PHPDoc.
* Proper sanitization.
* Proper escaping.
* Nonce verification where requests are involved.
* Capability checks where privileged actions are involved.
* Prepared SQL queries where dynamic values are involved.
* Upgrade-safe database changes.

Avoid:

* Hacks.
* Shortcuts.
* Duplicate logic.
* Unrelated changes.
* Over-engineering before the milestone requires it.
* Editing multiple files unless the task explicitly requires it.

## Security Rules

For every admin, AJAX, REST, or database-related file:

* Validate input.
* Sanitize input.
* Escape output.
* Verify nonces.
* Check capabilities.
* Use `$wpdb->prepare()` for dynamic SQL values.
* Do not expose API keys.
* Do not log sensitive provider credentials.
* Do not expose private ticket data to unauthorized users.

## WordPress Compatibility Rules

Use WordPress APIs whenever possible.

Prefer:

* `dbDelta()` for table creation.
* `get_option()` and `update_option()` for options.
* `wp_schedule_event()` for cron.
* `wp_clear_scheduled_hook()` for cleanup.
* `wp_remote_get()` and `wp_remote_post()` for HTTP requests.
* `wp_json_encode()` for JSON.
* `sanitize_text_field()`, `sanitize_key()`, `sanitize_email()`, `wp_kses_post()`, and similar sanitizers.
* `esc_html()`, `esc_attr()`, `esc_url()`, and similar escaping functions.

Do not directly echo unescaped dynamic data.

## Database Rules

All plugin-owned tables must use:

```php
$wpdb->prefix . 'scai_table_name'
```

Do not hardcode table prefixes.

Database schema classes should define schema only.

Installer classes should execute installation.

Migrator classes should handle upgrades.

Keep schema definition separate from installation logic.

## Current Build Order

Recommended current order:

```text
1. AI_CONTEXT.md
2. includes/database/class-schema.php
3. includes/installers/class-installer.php
4. includes/installers/class-uninstaller.php
5. includes/database/class-migrator.php or class-database.php
6. Admin settings foundation
7. Provider management
8. SupportCandy adapter
9. Context engine
10. Prompt engine
11. Conversation engine
12. Knowledge engine
13. Image engine
14. AI engine
15. Provider implementations
16. Response formatter
17. Sidebar UI
18. Reports
19. Cleanup jobs
20. Release hardening
```

## Instructions for Codex

Before editing code, always read this file.

When working on a task:

1. Modify only the requested file unless explicitly instructed.
2. Do not generate the whole plugin.
3. Do not invent new architecture.
4. Follow existing project structure.
5. Follow naming standards.
6. Keep code production-ready.
7. Explain what changed.
8. Explain how to test.
9. Mention risks or assumptions.

## Standard Codex Task Format

Use this format for implementation tasks:

```text
Read AI_CONTEXT.md first.

Task:
Create or update only this file:
[FILE PATH]

Context:
[Explain current milestone and purpose]

Requirements:
- Follow the SupportCandy AI Assistant architecture.
- Follow WordPress Coding Standards.
- Use class prefix SCAI_.
- Use option prefix scai_.
- Use plugin-owned tables only.
- Do not use official SupportCandy AI code, tables, options, AJAX, REST, CSS, or JS.
- Add PHPDoc.
- Add sanitization, escaping, nonce verification, and capability checks where relevant.
- Do not introduce unrelated changes.
- Do not generate the whole plugin.

After changes, explain:
1. What changed
2. Why it changed
3. How to test
4. Any risk or assumption
```

## Do Not Touch Without Instruction

Do not modify these unless specifically asked:

* Vendor files
* Generated dependency files
* Build files
* Existing completed core files
* SupportCandy plugin files
* WordPress core files
* Database migrations
* Public API contracts
* Unrelated assets

## Local Development Environment

Local development site:

```text
http://localhost/supportcandy-ai-test/
```

Recommended WordPress debug settings:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

Plugin folder:

```text
wp-content/plugins/supportcandy-ai/
```

## Testing Expectations

After every file or milestone:

* Check plugin activation.
* Check PHP error log.
* Check browser console if UI is involved.
* Check database tables if database logic is involved.
* Check admin access control if admin logic is involved.
* Check AJAX/REST nonce behavior if request handling is involved.
* Check that official SupportCandy AI module is not touched.
* Check that naming prefixes are respected.

## Project Priority

Optimize for:

1. Maintainability
2. Isolation
3. Security
4. Extensibility
5. Testability
6. Commercial product quality

Speed is useful, but architecture and long-term maintainability are more important.
