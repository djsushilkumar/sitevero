# Sitevero — MVP Scope Specification

## 1. MVP Objective & Definition of Done
The objective of the Sitevero MVP is to deliver a robust, beta-ready, single-plugin solution that demonstrates an end-to-end safe loop:
```
AI Agent -> MCP Connection -> Sitevero Discovery -> Execute Action -> Validate -> Report Result
```
The MVP is complete when an external AI agent (Antigravity, Claude, or Cursor) connects over standard MCP, discovers the site's capabilities, safely creates or edits both Elementor pages (V3, V4, or Hybrid) and Gutenberg pages, manages core WordPress entities (media, content, plugins, themes, users, settings), triggers pre-execution snapshots before mutations, validates the result, and can successfully rollback the change if needed.

---

## 2. In-Scope Functional Areas & Capabilities Matrix

| Capability Area | Specific MVP Functionality | Out-of-Scope Elements |
|---|---|---|
| **MCP Transport** | 4 Universal Meta-Tools (`sitevero_discover`, `sitevero_inspect`, `sitevero_execute`, `sitevero_rollback`) registered into the Official WordPress MCP Adapter (`WordPress/mcp-adapter`), which handles transports, authentication, and wire protocol | Implementing custom/parallel transport or server daemons; individual granular tools for every entity; version-specific tools |
| **Discovery** | Introspect active WordPress version, theme, active plugins, capabilities, Elementor V3/V4 presence, Free/Pro status, Gutenberg support | Remote dynamic extension downloads |
| **Posts & Pages** | Full CRUD: list, search, read, create, update, trash, restore, publish, categories/tags assignment by ID | Revision purging, taxonomy term auto-creation |
| **Media Library** | List/search, inspect metadata & sizes, upload media, replace media safely, update metadata (alt text, caption, description), assign featured images, attach/detach, delete where permitted | Arbitrary binary streaming over MCP sockets; complex image transformations |
| **Elementor V3** | Inspect element tree, sections/columns (where present), Flexbox Containers, widgets, widget settings, layout, typography, colors, spacing, responsive controls | Legacy section auto-migration |
| **Elementor V4** | Inspect Atomic Elements (`e-div-block`, `e-flexbox`, `e-grid`), create/update supported Atomic structures, classes, variables (Elementor design tokens bound via supported token model; not arbitrary CSS custom properties), styling system, supported responsive controls, supported interactions | Promising unreleased/unsupported V4 experimental features; arbitrary CSS injection |
| **Elementor Hybrid** | Inspect and update pages where V3 and V4 elements coexist; route updates internally; preserve untouched models without cross-converting | Automated cross-conversion (V3 to V4 or V4 to V3) |
| **Elementor Free / Pro**| Detect Free vs. Pro active status; inspect existing Pro widgets read-only; expose only available capabilities | Bundling Elementor Pro; promising unsupported Pro-only creation |
| **Gutenberg** | Read/write block grammar, insert core blocks (Paragraph, Heading, Image, Columns, Buttons, Group, List), insert Block Patterns, edit basic page/site templates; granular attribute & content sanitization | Blanket document-level stripping that destroys valid block attributes; custom block JS bundle compilation |
| **Plugin Management**| List, inspect status, activate, deactivate, delete where permitted, install from WordPress.org and user ZIP via WordPress upgrader APIs (no direct filesystem manipulation), report updates | Automatic update installation in MVP; arbitrary external URL download; direct file unzipping |
| **Theme Management** | List, inspect status, activate/switch, delete where permitted, install from WordPress.org and user ZIP via WordPress upgrader APIs, report updates | Automatic update installation in MVP; arbitrary external URL download; direct file unzipping |
| **Users & Roles** | List, inspect, create, edit basic profile info, assign roles, delete users where permissions allow (with confirmation and post reassignment; no privilege escalation) | Exposing password hashes; super-admin network promotion |
| **Settings** | Common safe settings: site title, tagline, homepage/posts page display (`show_on_front`, `page_on_front`, `page_for_posts`), timezone, date format, time format, permalinks, reading settings, discussion settings, media settings | Arbitrary unwhitelisted option editing |
| **Bulk Actions** | Batch operations supported where safe with a consistent batch safety threshold (max 50 items/batch); confirmation for risky/destructive bulk ops | Unbounded batch execution risking PHP timeouts |
| **Safety & Gate** | Mandatory transient confirmation token for risky/destructive actions (trash, delete, deactivate, switch, update settings) | AI-decided bypasses, auto-accept in production |
| **Rollback & History**| JSON delta snapshots of post content, postmeta, Elementor data, and option values before changes (2MB cap, 30-day retention). Capability-specific rollback: full state restore for content/Elementor/Gutenberg/settings; explicit irreversible status reporting for physical deletions | Full-site binary backups, physical filesystem file rollback, raw database table dumps, multi-step redo trees |
| **Dashboard** | Admin menu page in WP Admin showing MCP server status, endpoint URL, capability toggles, activity feed, and manual rollback button | External SaaS control plane, analytics graphs, React/Vite builds |

---

## 3. Explicit Non-Goals (Out of MVP)
To avoid bloat and enterprise over-engineering, the following are strictly excluded from MVP:
1. **No WooCommerce / E-Commerce:** No cart, checkout, product schemas, or order management.
2. **No Multisite (WPMU):** Only single-site WordPress installs supported.
3. **No Code Execution:** Zero execution of arbitrary PHP (`eval`, `call_user_func` from agent input), JavaScript, or raw SQL queries.
4. **No Direct Filesystem Access:** No file reading/writing outside of standard WordPress Media and upload abstractions (`wp_handle_upload`).
5. **No Visual Screenshot Verification:** Agent relies on structured JSON validation return payloads, not automated headless browser rendering.
6. **No Scheduled / Cron Automation:** No background jobs, queued retries, or recurrent maintenance tasks. Internal WP-Cron is used exclusively for 30-day housekeeping.
7. **No Autonomous Retry Loops:** If an action fails, Sitevero reports an exact machine-readable error payload and terminates the transaction.
8. **No Pro Features:** No licensing keys, payment paywalls, subscription gates, or multi-tenant agent management.
9. **No Third-Party Plugin Integrations:** No ACF, JetEngine, Yoast, or external builder add-on integrations in MVP.
10. **No WordPress.com Managed Hosting:** Strictly self-hosted single-site WordPress environments.
11. **No External Telemetry:** Zero phone-home tracking or remote telemetry in MVP; 100% private local operation.

---

## 4. Dependencies & Environmental Boundaries
- **WordPress:** Latest stable WordPress version + previous major WordPress version (Self-hosted single-site).
- **PHP Runtime:** PHP 8.1+.
- **Elementor:** Elementor Core (V3 and V4 architecture support; Free and Pro detected; Pro-only creation not promised).
- **Official WordPress MCP Adapter:** Canonical `WordPress/mcp-adapter` package as protocol foundation.

---

## 5. Resolved Decisions
- *Decision 2.1: Media Attachment & Management:* **RESOLVED.** Support full practical media management: listing, inspecting, uploading, safe replacement, metadata updating, featured image assignment, and deletion where permitted. All uploads enforce WordPress mime-type and permission checks.
- *Decision 2.2: Confirmation Token Mechanics:* **RESOLVED.** A deterministic two-step flow is enforced for high-risk and destructive actions. Sitevero halts execution, stores a 5-minute HMAC token in WordPress transients, returns `status: "confirmation_required"`, and only proceeds when the exact `confirmation_token` is supplied in the subsequent call.
