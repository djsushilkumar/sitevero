# Sitevero — MVP Release Checklist

## 1. Release Readiness Overview
Before tagging version `1.0.0-beta` for local and staging beta testing across 5–10 WordPress sites, every requirement in this checklist must be satisfied and verified.

---

## 2. Release Gates

### Gate A: Plugin Architecture & Environment
- [ ] Single zip plugin packaging installs cleanly on supported WordPress environments (latest stable + previous major version).
- [ ] Plugin strictly enforces PHP >= 8.1 on activation (`register_activation_hook`) and deactivates gracefully with an admin notice on older PHP versions.
- [ ] Custom database tables `wp_sitevero_snapshots` and `wp_sitevero_activity` are created via `dbDelta()` with correct indexes.
- [ ] Official `WordPress/mcp-adapter` foundation initializes cleanly on STDIO and HTTP transports.
- [ ] Daily WP-Cron event `sitevero_daily_maintenance_event` is registered strictly for internal 30-day snapshot and log pruning.
- [ ] Zero phone-home telemetry or external tracking scripts present.

### Gate B: Universal MCP Tools & Protocol
- [ ] Exactly 4 universal tools exposed over MCP:
  - `sitevero_discover`
  - `sitevero_inspect`
  - `sitevero_execute`
  - `sitevero_rollback`
- [ ] Tool definitions remain compact (< 1,000 tokens total in agent context).
- [ ] No granular tools or version-specific tools (e.g., `elementor_v3_edit`, `elementor_v4_edit`) exposed.
- [ ] JSON-RPC 2.0 error codes and standard error payloads returned on any failure.

### Gate C: Capability & Builder Execution
- [ ] **Content:** Can create, read, update, trash, restore, delete posts and pages, and perform batch updates up to 50 items.
- [ ] **Media:** Can list, inspect metadata & sizes, upload via `wp_handle_upload()`, replace media safely, update alt text/captions, assign featured images, attach/detach, and delete media under confirmation.
- [ ] **Elementor V3:**
  - Can inspect existing V3 Section/Column and Flexbox Container pages.
  - Can create and update pages with Containers, Headings, Text, Image, and Button widgets.
  - Can apply typography, colors, spacing, and responsive overrides.
  - Can clear Elementor CSS cache on save.
- [ ] **Elementor V4 (Atomic):**
  - Can inspect Atomic Elements (`e-div-block`, `e-flexbox`, `e-grid`), global classes, and design tokens/variables (`e-var:*`).
  - Can create and update supported Atomic Elements, assign classes, and bind global design tokens using Elementor's supported variable model (not arbitrary CSS injection).
  - Can apply responsive controls and supported interaction states.
- [ ] **Elementor Hybrid Pages:**
  - Can detect pages with coexisting V3 and V4 elements (`generation: "hybrid"`).
  - Can update a V3 element on a hybrid page without corrupting adjacent V4 structures.
  - Can update a V4 element on a hybrid page without corrupting adjacent V3 structures.
  - Verifies that no forced automated cross-conversion occurs.
- [ ] **Elementor Free / Pro Handling:**
  - Correctly detects active status of Elementor Core and Elementor Pro.
  - Allows read-only inspection of existing Pro widgets without schema crashes.
  - Strictly rejects creation of Pro-only widgets with `ERR_ELEMENTOR_PRO_UNSUPPORTED`.
  - Confirms Elementor Pro is never bundled with Sitevero.
- [ ] **Gutenberg:**
  - Can inspect and parse existing block pages via `parse_blocks()`.
  - Can create and update pages with core blocks using granular attribute schema validation and content field sanitization without corrupting block grammar or attributes via blanket post-serialization filtering.
  - Can insert registered Block Patterns and manage basic page/site templates.
- [ ] **Plugins & Themes:**
  - Can list installed plugins and themes and report available updates.
  - Can activate and deactivate plugins under confirmation.
  - Can install plugins and themes from WordPress.org and user-provided ZIP archives under confirmation strictly via core `Plugin_Upgrader` / `Theme_Upgrader` APIs (zero manual unzipping or filesystem writes).
  - Can delete plugins and themes under confirmation where permissions allow.
  - Confirms that automatic updates are NOT executed in MVP.
- [ ] **Users & Roles:**
  - Can list and inspect user profiles without exposing password hashes.
  - Can create users, edit basic profile information, and assign roles under confirmation.
  - Can delete users under confirmation with post reassignment.
  - Confirms zero privilege escalation (cannot grant permissions higher than active user).
- [ ] **Common Settings:**
  - Can read and update whitelisted settings (title, tagline, homepage/posts display, timezone, date/time, permalinks, reading, discussion, media settings) under confirmation.
  - Rejects attempts to access non-whitelisted option keys.
- [ ] **Bulk Actions:**
  - Enforces the 50-item safety threshold per bulk request.

### Gate D: Security & Safety Gates
- [ ] WordPress user permissions are strictly checked via `current_user_can()`.
- [ ] Zero privilege escalation: Low-privilege users (e.g., Contributor) are blocked from unauthorized operations.
- [ ] High-risk and destructive actions immediately halt and return `confirmation_required` with a 5-minute transient HMAC token.
- [ ] Execution only proceeds when a valid confirmation token is passed.
- [ ] Gutenberg block content & attributes sanitized via schema-aware validation and content sanitization pipeline, stripping arbitrary `<script>`, `<iframe>`, and event handlers without corrupting block grammar.
- [ ] Zero arbitrary PHP execution (`eval()`, dynamic functions), zero arbitrary CSS file writing.
- [ ] Zero arbitrary SQL writes; database fallback is strictly controlled and read-only (`SELECT`).
- [ ] Direct server filesystem traversal is blocked; all package installations delegated to WordPress upgrader APIs.
- [ ] Zero autonomous retry or self-healing loops.

### Gate E: Snapshot & Rollback
- [ ] Pre-execution snapshots captured automatically before every `medium`, `high`, or `destructive` mutation.
- [ ] Snapshots strictly enforce the 2MB payload cap.
- [ ] Calling `sitevero_rollback` performs single-step capability-specific restoration, returning `fully_restored` for content/builders/settings and `not_restorable` with an explanatory message for destructive physical deletions (media files, plugins, themes).
- [ ] Manual rollback button in WP Admin Dashboard functions correctly according to capability-specific restoration status.
- [ ] No multi-step redo stacks supported.

### Gate F: Admin Dashboard
- [ ] Top-level `Sitevero` menu renders in WP Admin using native WordPress Admin styles.
- [ ] Displays live MCP connection status and copyable connection configuration.
- [ ] Displays builder detection status (Elementor V3/V4/Hybrid and Free/Pro status).
- [ ] Activity log table displays recent agent operations with 20-item pagination, status badges, and detail modals.
- [ ] Capability toggle switches allow administrators to enable/disable specific capabilities globally.
- [ ] Disabled capabilities are omitted from `sitevero_discover` and blocked on execution.

### Gate G: AI Client Compatibility
- [ ] Verified with **Antigravity** (Complete flow: discover -> inspect -> execute -> rollback).
- [ ] Verified with **Claude Desktop / Claude Code** (Interactive confirmation flow and execution).
- [ ] Verified with **Cursor** (Discovery, Elementor V3/V4, and Gutenberg generation).

### Gate H: Beta Site Testing
- [ ] Successfully tested on 5–10 real-world local and staging WordPress sites.
- [ ] Tested with popular themes: Astra, GeneratePress, Hello Elementor, Twenty Twenty-Four.
- [ ] Tested on both Apache and Nginx web servers.
- [ ] Confirms no production-site beta requirement.

---

## 3. Explicitly Out-of-Scope Verifications
- No WooCommerce or e-commerce features present.
- No WordPress Multisite support claims.
- No automated visual AI screenshot checks.
- No autonomous retries or infinite self-fixing loops.
- No AI scheduled background cron actions.
- No external telemetry scripts.

---

## 4. Dependencies
- Complete passing PHPUnit test suite.
- Passing MCP interoperability test runner (`bin/test-mcp-flow.php`).

---

## 5. Resolved Decisions
- *Decision 15.1: Beta Telemetry & Analytics:* **RESOLVED.** Sitevero MVP contains zero phone-home telemetry, tracking scripts, or remote error reporting. The plugin operates 100% privately and self-contained on the host server.
