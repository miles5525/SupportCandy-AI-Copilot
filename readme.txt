=== SupportCandy AI Assistant ===
Contributors: webizons
Tags: supportcandy, ai, support, tickets, helpdesk, openai, betterdocs, rag, knowledge base
Requires at least: 6.0
Tested up to: 7.0.1
Requires PHP: 7.4
Stable tag: 0.9.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-assisted ticket summaries, reply drafting, and retrieved BetterDocs or custom knowledge for SupportCandy helpdesks.

== Description ==

SupportCandy AI Assistant adds agent-controlled AI tools to a SupportCandy helpdesk. It can summarize ticket conversations, draft customer replies, improve an existing draft, and merge an agent's draft with an AI suggestion.

This is independent beta software. It is not an official SupportCandy product and is not developed, endorsed, or supported by the official SupportCandy team.

AI actions run only when an authorized support agent requests them. The plugin does not automatically submit or send ticket replies. Agents should review every AI-generated summary and reply before relying on or sending it.

== Features ==

* Generate an internal AI summary of ticket context.
* Generate a customer-facing reply draft.
* Improve the current agent draft while preserving its intent.
* Merge an agent draft with an AI suggestion.
* Apply, replace, append, or merge generated text without automatically submitting a reply.
* View agent-scoped AI conversation history.
* Review AI usage logs without storing raw prompts.
* Control AI access by SupportCandy agent role and ticket access.
* Read bounded excerpts from supported text and log attachments.
* Optionally include supported images for AI visual inspection.
* Optionally use relevant published BetterDocs articles as supporting knowledge.
* Build a Custom Knowledge Base from trusted manual text, public URLs, and uploaded files.
* Retrieve relevant custom knowledge as supporting context for summaries, replies, improved drafts, and merged drafts.
* Follow a Getting Started setup checklist for provider, permissions, diagnostics, and optional features.
* Run system checks for SupportCandy, attachments, image support, and BetterDocs availability.

== Requirements ==

* WordPress 6.0 or later.
* PHP 7.4 or later.
* An active and compatible SupportCandy installation.
* A configured AI service that provides an OpenAI-compatible API.
* An API key and model access from the chosen AI service.
* BetterDocs is optional and required only for BetterDocs knowledge features.

Compatibility can vary between WordPress, SupportCandy, BetterDocs, and AI provider versions. Test beta releases on a staging site before production use.

== Supported AI Providers ==

The v0.9.2 management build uses one GPT/OpenAI-compatible provider configuration. Administrators can configure its endpoint, API key, and model in the provider settings and test the connection before use.

Compatibility with a service depends on how closely its API follows the expected OpenAI-compatible request and response format. A compatible endpoint does not imply endorsement or official integration by that provider.

Multiple provider presets are future roadmap work and are not implemented in this build.

== BetterDocs Knowledge Base ==

The optional BetterDocs integration searches published, publicly viewable, non-password-protected documentation and supplies a bounded set of relevant articles as supporting AI context.

When relevant articles are found:

* Summaries can list short Suggested Knowledge References.
* Replies, improved drafts, and merged drafts can use relevant troubleshooting guidance.
* Ticket and customer facts remain the primary source of truth.

The integration is read-only. It does not modify BetterDocs content, write BetterDocs search analytics, or retrieve draft, private, or password-protected articles.

== Custom Knowledge Base / RAG ==

Administrators can add trusted Custom Knowledge Base sources using manual text, a public URL, or a file upload. Supported uploaded file types are TXT, Markdown, CSV, LOG, and JSON.

PDF extraction requires an approved extractor connected through the plugin's PDF extraction hook. Without one, an uploaded PDF is safely marked unsupported and is not used as AI context.

The plugin uses deterministic retrieval and relevance checks to select bounded excerpts as supporting context. This does not train the AI model. Ticket and customer facts remain primary, and agents must review all AI output before sending or relying on it.

== Image Understanding ==

Image understanding is optional and disabled by default for new installations. When enabled and supported by the configured provider, selected ticket images may be included in the AI request for visual inspection.

Image support depends on the selected model and provider. Agents should verify visual descriptions. The plugin does not store base64 image data in conversation history or usage logs.

== Privacy and Data ==

When an authorized agent uses an AI action, relevant ticket content may be sent to the configured AI provider. Depending on the request, this can include ticket fields, conversation messages, bounded text attachment excerpts, selected images, relevant public BetterDocs content, and bounded excerpts retrieved from active Custom Knowledge Base sources.

Important privacy considerations:

* API keys are stored in WordPress options. Protect administrator access and the WordPress database.
* The plugin does not train AI models itself.
* The plugin does not automatically send or submit ticket replies.
* Raw prompts are not stored in usage logs.
* Base64 image data and local server paths are not stored in AI history or usage logs.
* BetterDocs knowledge reads only published, public, non-password-protected articles.
* Conversation history is scoped to the current agent and subject to configured retention controls.
* The site owner is responsible for the configured AI provider's terms, privacy policy, data retention, regional requirements, and legal compliance.

Review your provider configuration and privacy obligations before enabling AI features on a production helpdesk.

== Installation ==

1. Back up the WordPress site and test on staging first.
2. Install and activate SupportCandy.
3. Upload the SupportCandy AI Assistant ZIP through Plugins > Add New > Upload Plugin, or copy the plugin directory into `/wp-content/plugins/`.
4. Activate SupportCandy AI Assistant.
5. Open Support > AI Assistant to view the Getting Started checklist.
6. Configure the provider, permissions, and optional features.
7. Run System Check before using AI actions on live tickets.

== Configuration ==

1. Open Support > AI Assistant. It opens the Getting Started setup checklist. If SupportCandy menu integration is unavailable, the plugin falls back to its original top-level menu.
2. Open AI Providers and configure an OpenAI-compatible endpoint, API key, and model.
3. Test the provider connection.
4. Open AI Permissions and select the SupportCandy agent roles allowed to use AI features.
5. Review conversation retention and uninstall cleanup settings.
6. Optionally enable image understanding after confirming that the model supports images.
7. Optionally install and activate BetterDocs, publish documentation, and enable BetterDocs knowledge.
8. Optionally add trusted manual, URL, or file sources under Knowledge Sources.
9. Use System Check to verify ticket access, attachments, images, BetterDocs detection, and Custom Knowledge retrieval.

== Frequently Asked Questions ==

= Is this an official SupportCandy plugin? =

No. SupportCandy AI Assistant is an independent plugin and is not developed, endorsed, or supported by the official SupportCandy team.

= Does the plugin automatically send replies? =

No. It prepares text for the reply editor. A support agent must review and manually submit the final reply.

= Which AI providers can I use? =

The beta supports configurable OpenAI-compatible endpoints. Actual compatibility depends on the provider's API and selected model.

= Is ticket information sent to an external service? =

Yes, when an authorized agent runs an AI action, relevant ticket context may be sent to the configured AI provider. Review the Privacy and Data section and your provider's policies.

= Does the plugin train an AI model with my tickets? =

The plugin does not train models. The configured provider may process or retain requests according to its own terms and privacy policy.

= Is BetterDocs required? =

No. BetterDocs is optional. If it is unavailable or BetterDocs knowledge is disabled, the existing ticket AI features continue without documentation context.

= Which BetterDocs articles can be used? =

Only published, publicly viewable, non-password-protected articles are eligible. The integration is read-only.

= Is image understanding enabled automatically? =

No. It is disabled by default for new installations and must be enabled by an administrator. The configured model must also support image input.

= Should agents trust AI output without review? =

No. AI output can be incomplete or incorrect. Agents should verify facts, troubleshooting steps, policies, links, and customer-facing language before use.

= Is this release production ready? =

Version 0.9.2 is beta software. Site owners should use staging, backups, limited permissions, and careful monitoring before production deployment.

== Screenshots ==

1. Getting Started setup checklist.
2. SupportCandy AI Assistant settings and optional feature controls.
3. OpenAI-compatible provider configuration and connection test.
4. SupportCandy agent-role AI permissions.
5. AI assistant actions in a SupportCandy ticket.
6. AI conversation history popup.
7. System Check with attachment, image, and BetterDocs diagnostics.
8. Usage Logs administration page.

== Changelog ==

= 0.9.2 =

* Moved the visible admin entry under Support > AI Assistant while preserving direct plugin page URLs and a fallback top-level menu.
* Added Custom Knowledge Base / RAG MVP using the existing knowledge table.
* Added manual text, public URL, and file upload knowledge sources.
* Added TXT, Markdown, CSV, LOG, and JSON extraction with safe unsupported PDF handling.
* Added deterministic custom knowledge search and relevance gating.
* Added custom knowledge context to Summary, Generate Reply, Improve Current Draft, and Merge with my draft.
* Added Custom Knowledge status and search diagnostics to System Check and Getting Started.
* No database schema change was required.

= 0.9.1 =

* Added BetterDocs Knowledge Base MVP.
* Added BetterDocs detection and search diagnostics.
* Added Suggested Knowledge References in summaries.
* Improved AI reply formatting to avoid subject lines and placeholder signatures.
* Improved BetterDocs-guided replies, improve draft, and merge draft flows.
* Added final private beta hardening.
* Added a Getting Started setup checklist and streamlined the admin menu.

= 0.9.0 =

* Initial private beta with AI ticket summary, reply generation, reply improvement, merge draft, conversation history, usage logs, permissions, image understanding, and attachment context.

== Upgrade Notice ==

= 0.9.2 =

Adds the Custom Knowledge Base / RAG MVP with manual, URL, and supported file sources. No database schema change is required. Back up the site and test source retrieval and AI output on staging after upgrading.

= 0.9.1 =

Adds optional BetterDocs knowledge, knowledge diagnostics, explicit summary references, and improved customer-reply formatting. Back up the site and verify provider, permissions, and optional feature settings after upgrading.
