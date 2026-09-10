# Sitevero — Security Specification

## 1. Security Principles & Architecture
Sitevero adheres to a defense-in-depth security model. AI agents interact with WordPress exclusively through strictly controlled, typed APIs with continuous boundary validation.

```
[ Incoming MCP Request ]
           |
           v
+--------------------------------------------------------------+
| 1. Authentication Layer                                       |
|    - Official WordPress MCP Adapter Transport Layer          |
|    - Maps to authenticated WP_User                           |
+--------------------------------------------------------------+
           |
           v
+--------------------------------------------------------------+
| 2. Authorization & Capability Barrier                         |
|    - Check user capability (current_user_can)                |
|    - Zero privilege escalation                               |
|    - Check capability toggle (Sitevero Admin enabled/disabled)|
+--------------------------------------------------------------+
           |
           v
+--------------------------------------------------------------+
| 3. Input Sanitization & Content Filtering                    |
|    - Reject arbitrary PHP / shell / SQL                      |
|    - Strip executable JS / inline script tags / event handler|
|    - Schema-aware block attribute & content sanitization     |
|    - Restrict CSS to verified builder design token structures|
+--------------------------------------------------------------+
           |
           v
+--------------------------------------------------------------+
| 4. Risk Evaluation & Confirmation Gate                       |
|    - Check risk level (low, medium, high, destructive)       |
|    - If high/destructive: Require valid confirmation_token   |
+--------------------------------------------------------------+
           |
           v
+--------------------------------------------------------------+
| 5. Pre-Execution State Snapshot                              |
|    - Save before_state to wp_sitevero_snapshots (2MB limit)  |
+--------------------------------------------------------------+
           |
           v
+--------------------------------------------------------------+
| 6. Deterministic Core Execution                              |
|    - Standard WordPress Core & Upgrader APIs only            |
|    - Fail immediately on error; NO retry loop                |
+--------------------------------------------------------------+
```

---

## 2. Hard Security Boundaries

### 2.1 WordPress User Permissions as Final Authority
- Sitevero operates strictly under the identity of the authenticated WordPress user (`wp_get_current_user()`).
- Sitevero **never escalates privileges** (e.g., an Author or Contributor user can never activate plugins, delete users, or modify global settings).
- Every capability verifies appropriate WordPress capabilities (e.g., `edit_posts`, `delete_posts`, `upload_files`, `manage_options`, `install_plugins`, `delete_plugins`, `delete_users`).

### 2.2 Strict Prohibition of Arbitrary Code Execution
- **No Arbitrary PHP:** Zero usage of `eval()`, `create_function()`, `assert()`, `preg_replace('/e')`, or dynamic `call_user_func()` with user-supplied function names.
- **No Arbitrary JavaScript:** Content submitted to posts or widgets is sanitized: user text fields allow safe inline markup (`<strong>`, `<em>`, `<a>`, `<code>`) while aggressively stripping `<script>` tags, inline event handlers (`onclick`, `onload`, `onerror`), and `javascript:` URIs. Gutenberg block attributes and structures undergo granular schema validation prior to serialization (avoiding blanket post-serialization destruction).
- **No Arbitrary CSS Files / CSS Injection:** Agents cannot write raw `.css` files to the filesystem, nor inject arbitrary CSS custom properties. Elementor V4 styling is restricted to verified design tokens and structured property definitions compiled by Elementor's CSS engine.

### 2.3 Filesystem Protection
- **No Direct Filesystem Access:** Agents cannot traverse directories, read system files (e.g., `wp-config.php`, `.htaccess`, `/etc/passwd`), or write arbitrary files.
- Media uploads are restricted to `wp_handle_upload()` or `media_sideload_image()` which enforce WordPress extension and mime-type whitelists.
- Plugin and theme installations are restricted to standard WordPress upgrader routines (`Plugin_Upgrader`, `Theme_Upgrader`). Sitevero strictly prohibits direct filesystem operations, manual ZIP file extraction, or directory traversal.

### 2.4 Controlled Read-Only Database Safeguards
- All entity mutations use high-level WordPress Core functions (`wp_insert_post`, `update_option`, etc.).
- Direct `$wpdb` access is restricted solely to Sitevero's internal custom tables (`wp_sitevero_snapshots`, `wp_sitevero_activity`) and controlled, read-only `SELECT` queries where WordPress APIs do not expose needed inspection data.
- **Zero Arbitrary SQL Writes:** Sitevero exposes no arbitrary SQL query tools over MCP. Direct SQL execution of `INSERT`, `UPDATE`, `DELETE`, `DROP`, `ALTER`, or `TRUNCATE` is strictly prohibited.

---

## 3. Risk Levels & Confirmation Gate

Sitevero classifies all actions into 4 risk tiers:

| Tier | Risk Level | Examples | Confirmation Token Required? | Automatic Snapshot? |
|---|---|---|---|---|
| 1 | `low` | `content.query`, `sitevero_discover`, `sitevero_inspect`, `media.get`, `system.manage_plugins` (list) | No | No |
| 2 | `medium` | `content.manage_post` (create/update), `elementor.manage_page` (update), `gutenberg.manage_blocks` (update), `media.manage` (upload/replace/meta) | No | **Yes** |
| 3 | `high` | `system.manage_settings` (update), `system.manage_plugins` (activate/deactivate/install), `system.manage_themes` (activate/install), `users.manage` (create/update_role), bulk content updates | **Yes** | **Yes** |
| 4 | `destructive` | `content.manage_post` (trash/delete), `media.manage` (delete), `users.manage` (delete), `system.manage_plugins` (delete), `system.manage_themes` (delete), `system.manage_themes` (switch) | **Yes** | **Yes** |

### Confirmation Token Mechanics
1. When a `high` or `destructive` action is requested without a token, Sitevero halts execution immediately.
2. A cryptographic token is generated: `hash_hmac('sha256', $user_id . $action . microtime(), wp_salt())`.
3. The token and payload are stored in WordPress transients with a 5-minute TTL (300 seconds).
4. The MCP response returns `status: "confirmation_required"` with the token, action summary, and expiration.
5. The agent or human user must pass `confirmation_token` in the subsequent call to authorize execution.

---

## 4. Failure & Halting Policy
- **No Autonomous Retries:** Sitevero never attempts to automatically re-try a failed action or run self-healing code loops.
- **Explicit Stop & Report:** Any `WP_Error`, validation mismatch, or permission failure immediately stops execution and returns an error payload containing:
  - Error code (e.g., `ERR_INSUFFICIENT_PERMISSIONS`, `ERR_VALIDATION_FAILED`, `ERR_CONFIRMATION_REQUIRED`).
  - Human-readable message.
  - Snapshot UUID (if snapshot was taken prior to failure).

---

## 5. Background Tasks & Telemetry Boundaries
- **No AI Scheduled Actions:** AI agents cannot register scheduled, recurring, or deferred background actions in MVP.
- **Housekeeping Cron Only:** Internal WP-Cron is utilized exclusively for 30-day snapshot and activity log pruning.
- **Zero External Telemetry:** No tracking, phone-home metrics, or external API communications in MVP; 100% self-hosted operation.

---

## 6. Dependencies
- WordPress Core authentication system.
- WordPress Core sanitization functions (`sanitize_text_field`, `wp_kses_post`, `esc_url_raw`).
- WordPress Transients API (`set_transient`, `get_transient`, `delete_transient`).

---

## 7. Resolved Decisions
- *Decision 9.1: Confirmation Gate Override:* **RESOLVED.** Confirmation tokens are strictly mandatory for all `high` and `destructive` actions. No UI toggle exists in the admin dashboard to disable confirmation, preventing accidental bypass in production. For automated unit/integration test runners only, developers may utilize the PHP filter `sitevero_bypass_confirmation_in_dev`, which is strictly ignored unless `defined('WP_DEBUG') && WP_DEBUG === true`.
