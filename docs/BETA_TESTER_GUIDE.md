# SupportCandy AI Assistant v0.9.1 Beta Tester Guide

Thank you for testing SupportCandy AI Assistant. This independent plugin adds agent-controlled AI tools to SupportCandy tickets, including summaries, reply drafting, draft improvement, conversation history, and optional BetterDocs knowledge.

SupportCandy AI Assistant is not an official SupportCandy product and is not developed, endorsed, or supported by the official SupportCandy team.

## Important Beta Warning

Version 0.9.1 is beta software. Test it on a staging or non-production site with a current backup. AI output can be incomplete or incorrect and must be reviewed by a support agent before it is used or sent.

The plugin does not automatically submit ticket replies. The agent remains responsible for the final response.

## Requirements

- WordPress 6.0 or later.
- PHP 7.4 or later.
- An active, compatible SupportCandy installation.
- A configured OpenAI-compatible AI provider, API key, and supported model.
- A test SupportCandy agent account and accessible test tickets.
- BetterDocs is optional and required only for BetterDocs knowledge testing.
- A provider and model with image support are required only for image-understanding tests.

## Installation

1. Back up the test site and database.
2. Confirm SupportCandy is installed and active.
3. In WordPress admin, go to Plugins > Add New > Upload Plugin.
4. Select the provided SupportCandy AI Assistant v0.9.1 ZIP.
5. Install and activate the plugin.
6. Confirm the plugin reports version 0.9.1.
7. Open SupportCandy AI System Check and review the reported status before testing ticket actions.

For upgrade testing, retain the existing test installation and settings, install the v0.9.1 package over it, and confirm the previous configuration remains available.

## Basic Setup

1. Open the AI provider settings.
2. Enter the OpenAI-compatible endpoint, API key, and model details supplied for testing.
3. Save the provider configuration and run the connection test.
4. Open AI Permissions and allow only the SupportCandy agent roles selected for testing.
5. Review conversation retention and optional uninstall cleanup settings.
6. Leave image understanding off unless the provider and model support images and image testing is planned.
7. If BetterDocs is installed, publish suitable test documentation and optionally enable BetterDocs knowledge.
8. Run System Check again and confirm the expected services are available.

Never use a production API key in an environment that is not appropriately secured.

## Recommended Test Flow

### 1. Activate the plugin

- Confirm activation completes without warnings or fatal errors.
- Open each SupportCandy AI administration page.

### 2. Configure the AI provider

- Save valid provider details and test the connection.
- Try an invalid credential and confirm the error is useful without revealing the key.

### 3. Configure AI permissions

- Allow one SupportCandy agent role and deny another.
- Confirm each test account receives the expected access.

### 4. Open a SupportCandy ticket

- Test both the backend ticket screen and frontend ticket screen when available.
- Use a ticket with a realistic subject, customer messages, agent replies, and optional attachments.

### 5. Generate Summary

- Confirm the summary reflects the ticket accurately.
- Confirm customer and agent messages are not confused.
- If BetterDocs documents are relevant, confirm Suggested Knowledge References lists only included articles.

### 6. Generate Reply

- Confirm the result is a customer-facing reply body without a subject line or placeholder signature.
- Verify facts, promises, links, troubleshooting steps, and tone before applying it.

### 7. Improve Current Draft

- Enter a short draft and run Improve Current Draft.
- Confirm the result preserves the draft's intent and does not invent commitments or signatures.

### 8. Merge with my draft

- Create an agent draft and an AI suggestion, then merge them.
- Confirm the current agent draft remains the base and duplication is removed.

### 9. Check Conversation History

- Confirm successful AI results appear for the correct agent.
- Confirm another agent cannot view history that does not belong to them.

### 10. Check Usage Logs

- Confirm completed and failed requests record useful operational information.
- Confirm API keys, raw prompts, local paths, and image data are not displayed.

### 11. Test BetterDocs if installed

- Test with BetterDocs knowledge disabled and enabled.
- Run BetterDocs detection and Knowledge Search Test in System Check.
- Confirm relevant public articles can guide summaries and replies.
- Confirm draft, private, and password-protected articles are not used.

## Optional Attachment and Image Tests

- Attach a supported text or log file and confirm a useful bounded excerpt can inform the AI response.
- Test image understanding while disabled.
- If supported, enable image understanding and test with a non-sensitive image.
- Confirm the AI does not claim to inspect an image when image content was not supplied to the model.

## What Testers Should Report

Please report:

- Installation, activation, deactivation, upgrade, or uninstall problems.
- Provider connection, authentication, quota, timeout, or model compatibility errors.
- Missing AI controls or incorrect role/ticket permissions.
- Incorrect, missing, or mixed-up ticket context.
- Summaries or replies that invent facts, promises, references, or attachment details.
- Subject lines, placeholder signatures, or unsuitable reply-editor formatting.
- BetterDocs detection, relevance, filtering, or reference problems.
- Attachment or image handling problems.
- Conversation History or Usage Logs visibility problems.
- Browser-specific layout or interaction issues.

Use the provided `BUG_REPORT_TEMPLATE.md`. Include clear reproduction steps and sanitized evidence where possible.

## What Not to Do

- Do not test on production without a current backup and an approved test plan.
- Do not send AI-generated replies without agent review.
- Do not share API keys, passwords, access tokens, or provider secrets in bug reports.
- Do not include unnecessary customer names, email addresses, ticket content, private documentation, or other personal data in screenshots and logs.
- Do not upload sensitive attachments or images solely for testing.
- Do not assume AI troubleshooting guidance is correct without verification.

## Test Completion

Testing is complete when the planned workflows have been checked with allowed and disallowed users, failures have been documented, and no unresolved critical issue remains for the selected beta audience.
