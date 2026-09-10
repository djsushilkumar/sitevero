# Sitevero — Product Requirements Document (PRD)

## 1. Product Overview & Vision
Sitevero is a universal WordPress AI / Model Context Protocol (MCP) plugin designed for self-hosted WordPress environments. It provides a secure, deterministic bridge that empowers AI agents (such as Antigravity, Claude, and Cursor) to safely inspect, manage, build, and maintain WordPress websites.

Rather than exposing an unmanageable catalog of hundreds of low-level tools that overwhelm AI agent context windows, Sitevero leverages the **official WordPress MCP Adapter** as its transport/protocol foundation while introducing a **Sitevero Universal Capability Layer** driven by 4 universal meta-tools: `sitevero_discover`, `sitevero_inspect`, `sitevero_execute`, and `sitevero_rollback`.

```
+-------------------------------------------------------------+
|                          AI Agent                           |
|               (Antigravity / Claude / Cursor)               |
+-------------------------------------------------------------+
                              |
                              v [MCP Protocol: STDIO / HTTP]
+-------------------------------------------------------------+
|                Official WordPress MCP Adapter               |
|          (Official Foundation: Transport & JSON-RPC)        |
+-------------------------------------------------------------+
                              |
                              v [Internal Dispatch]
+-------------------------------------------------------------+
|                Sitevero Universal Core Engine               |
|   (Auth Validation, Capability Registry, Risk Gate, Log)    |
+-------------------------------------------------------------+
                              |
        +---------------------+---------------------+
        |                     |                     |
        v                     v                     v
+---------------+     +---------------+     +---------------+
| WP Core Engine|     |   Elementor   |     |   Gutenberg   |
| Posts, Media, |     | Engine        |     | Engine        |
| Plugins/Themes|     | V3 / V4 /     |     | Blocks, FSE,  |
| Users,Settings|     | Hybrid Pages  |     | Patterns      |
+---------------+     +---------------+     +---------------+
                              |
                              v
+-------------------------------------------------------------+
|                      WordPress Core / DB                    |
+-------------------------------------------------------------+
```

---

## 2. Core Problem Statement
Modern AI agents struggle with WordPress for three major reasons:
1. **Tool Bloat:** Standard REST or MCP implementations expose hundreds of granular endpoints, exhausting context windows and causing high hallucination rates.
2. **Safety & Destructive Actions:** AI models can break layouts, overwrite content without history, or perform unauthorized administrative actions without safety gates.
3. **Builder Complexity & Version Drift:** Visual builders (Elementor V3, modern Elementor V4 Atomic architecture, and Gutenberg Block Editor) store distinct serialization schemas that models cannot reliably navigate without specialized normalization layers.

Sitevero solves this by providing:
- Compact capability discovery and 4 universal meta-tools.
- Native understanding of Elementor V3, Elementor V4 (Atomic Elements), Hybrid V3/V4 layouts, and Gutenberg block schemas.
- Non-destructive execution with automatic pre-execution snapshots and rollback.
- Strict WordPress permission enforcement and mandatory risk confirmations.
- Full practical management of WordPress core entities: content, media, plugins, themes, users, and settings.

---

## 3. Product Model & Packaging
- **Packaging:** Single standard WordPress plugin installable via `.zip` upload or `wp-content/plugins` folder.
- **License Model:**
  - **Sitevero Core (Free / MVP):** Focus of this specification. Full universal capability layer, Elementor V3/V4/Hybrid and Gutenberg builders, core WP management, snapshot rollback, audit log, and admin dashboard.
  - **Sitevero Pro (Post-MVP):** Explicitly excluded from MVP. Will include scheduled automations, multisite support, WooCommerce deep integrations, advanced team roles, and external backup sync. Pro licensing, paywalls, payments, or Pro-only gates are strictly forbidden in MVP.

---

## 4. Target Personas
1. **Solo Web Builders & Agencies:** Building client sites using WordPress, Elementor, and Gutenberg who want to delegate repetitive tasks (content creation, layout adjustment, styling, plugin audits) to an AI agent.
2. **Developers & Technical Marketers:** Using AI coding assistants (Cursor, Antigravity, Claude Code) to orchestrate changes across staging and production sites safely.
3. **Site Administrators:** Needing audit trails, diffs, and instant rollback safety nets when AI tools modify pages, theme settings, or site configurations.

---

## 5. Scope Boundaries

### What is Required (In Scope)
- Standard self-hosted WordPress installation (single site).
- Official WordPress MCP Adapter integration (`WordPress/mcp-adapter`).
- 4 universal MCP meta-tools: `sitevero_discover`, `sitevero_inspect`, `sitevero_execute`, `sitevero_rollback`.
- Complete 5-stage lifecycle: Discovery -> Inspection -> Execution -> Validation -> Rollback.
- **Core Entities:**
  - **Posts & Pages:** Full CRUD, trashing, restoring, publishing, categories/tags assignment by ID.
  - **Media Library:** List, search, inspect, upload, replace safely, update metadata (alt text, title, caption, description), assign featured images, attach/detach, inspect sizes, and delete where permitted.
  - **Plugins:** List, inspect status, activate, deactivate, delete where permitted, install from WordPress.org, install from user-provided ZIP via standard WordPress upgrader APIs (no manual directory manipulation or direct filesystem writes), update reporting (no automatic updates in MVP).
  - **Themes:** List, inspect status, activate/switch, delete where permitted, install from WordPress.org, install from user-provided ZIP via standard WordPress upgrader APIs, update reporting (no automatic updates in MVP).
  - **Users & Roles:** List, inspect, create, edit basic profile info, assign roles, delete users where permissions allow (with confirmation and post reassignment; no privilege escalation).
  - **Common Settings:** Site title, tagline, homepage/posts page display (`show_on_front`, `page_on_front`, `page_for_posts`), timezone, date format, time format, permalinks, reading settings, discussion settings, media settings.
  - **Bulk Actions:** Batch operations supported where safe with a consistent batch safety threshold (max 50 items/batch) and confirmation on risky/destructive actions.
- **Elementor Builder Engine:**
  - Support for **Elementor V3** (Sections, Columns, Flexbox Containers, Widgets, widget settings, layout, typography, colors, spacing, responsive controls).
  - Support for **Elementor V4** (Atomic Elements, classes, variables/design tokens bound via Elementor's supported token model, styling system, supported responsive controls, supported interactions, container layout).
  - Support for **Hybrid Pages** where V3 and V4 elements coexist, preserving untouched models and routing internally without version-specific MCP tools.
  - Detection of Elementor Free and Elementor Pro (read-only inspection of existing Pro widgets; never bundling Pro; never promising unsupported Pro-only creation).
- **Gutenberg Builder Engine:** Blocks, block creation/editing with granular attribute sanitization, Block Patterns registry, and basic Template/Site editing.
- **Security & Safety:** WordPress capabilities check as final authority, two-gate access control, confirmation tokens for risky/destructive actions, zero arbitrary PHP/JS/CSS/SQL execution, controlled read-only DB fallback, no direct filesystem access.
- **Snapshots & Rollback:** Automated pre-execution snapshots before medium/high/destructive mutations (2MB cap per entity), capability-specific single-step rollback (full state restore for content, Elementor, Gutenberg, settings; explicit limitation reporting for irreversible deletions), 30-day retention pruning via internal maintenance cron.
- **Native Admin Dashboard:** MCP connection status, capability toggles, activity log, manual rollback access, basic health status.
- **AI Agent Interoperability:** Verified across Antigravity, Claude, and Cursor.
- **Zero Telemetry:** 100% self-hosted, private, and self-contained; zero external tracking scripts or phone-home analytics in MVP.

### What is Out of Scope (Explicitly Excluded)
- WooCommerce and e-commerce capabilities.
- WordPress Multisite (WPMU) networks.
- WordPress.com managed hosting specific APIs.
- Third-party plugin extensions (ACF, JetEngine, Yoast, etc.).
- Arbitrary PHP code execution (`eval`, dynamic custom functions).
- Direct server filesystem editing / file manager capabilities.
- Arbitrary CSS/JS script injection bypasses.
- Visual AI screenshot/computer vision verification.
- Agent-scheduled/recurring background cron actions.
- Automatic self-healing or autonomous retry loops.
- Full-site binary backup archiving (only entity-level delta snapshots).
- Pro commercial licensing, payments, or upsells.

---

## 6. Compatibility & Version Policy
- **WordPress Core:** Latest stable WordPress version + previous major WordPress version (Self-hosted single-site).
- **PHP Runtime:** PHP 8.1 or higher.
- **Official WordPress MCP Adapter:** Official `WordPress/mcp-adapter` package as transport and JSON-RPC foundation.
- **Elementor Builder:** Full architectural support for Elementor V3, Elementor V4, and Hybrid pages across both Free and Pro environments.
- **Gutenberg Builder:** Core block editor bundled with supported WordPress versions.

---

## 7. Resolved Decisions
- *Decision 1.1: Official WordPress MCP Adapter Integration:* **RESOLVED.** The official WordPress MCP Adapter is the sole protocol and transport foundation. Sitevero relies entirely on the verified official WordPress MCP Adapter for transport handling, server registration, authentication, and protocol framing. Sitevero does NOT implement a parallel or custom MCP transport architecture; it solely implements the Universal Capability Layer registered into the official adapter.
- *Decision 1.2: Execution Timeout Threshold:* **RESOLVED.** Standard synchronous execution timeout is set to 30 seconds. Operations exceeding this window halt safely, prevent database locks, and return a clean timeout error.
