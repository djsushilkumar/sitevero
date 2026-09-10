# Sitevero — Activity Log & Rollback Specification

## 1. Objective & Boundaries
Sitevero provides a lean, non-intrusive safety net for AI agent operations. It is **not** a full site backup system or version control tool (like Git or UpdraftPlus). Instead, it captures targeted, entity-level delta snapshots before mutations occur, logs every agent action to an audit table, and allows one-click rollback through MCP or the WordPress Admin Dashboard.

---

## 2. Activity Logging System

### 2.1 Log Lifecycle & Data Captured
1. **Request Intake:** When `sitevero_execute` is called, an activity entry is created with status `in_progress`.
2. **Post-Execution Update:** Once the action completes (or fails), the activity entry is updated with:
   - `timestamp`: Exact UTC timestamp.
   - `actor`: WordPress user ID and login executing the action.
   - `agent_client`: Client identifier (e.g., `Antigravity`, `Claude`, `Cursor`).
   - `capability`: Capability identifier (e.g., `elementor.manage_page`).
   - `action`: Verb (e.g., `update`, `trash`, `install`, `upload`).
   - `target`: Human-readable target (e.g., `Post #42`, `Plugin 'elementor'`, `Setting 'blogname'`).
   - `status`: Final status (`success`, `error`, `confirmation_required`, `rolled_back`).
   - `snapshot_uuid`: Foreign reference to snapshot if state was mutated.
   - `validation`: Internal verification result (`verified: true|false`).
   - `warnings_or_errors`: Exact error code, WP_Error message, or validation diffs.
3. **Rollback Logging:** If an action is rolled back, the original activity row is updated and an explicit `rollback` record is appended.

### 2.2 Activity Entry Data Structure
```json
{
  "id": 104,
  "snapshot_uuid": "snp_66e01a8f9b",
  "capability_id": "elementor.manage_page",
  "status": "success",
  "risk_level": "medium",
  "agent_client": "Claude",
  "user_id": 2,
  "user_login": "sitevero_agent",
  "summary": "Updated Elementor V4 page #42 ('Services'): modified Hero heading and added CTA button",
  "details": {
    "post_id": 42,
    "generation": "v4",
    "element_count_before": 4,
    "element_count_after": 5,
    "duration_ms": 142
  },
  "created_at": "2026-09-10 17:35:12"
}
```

---

## 3. Snapshot & Rollback Architecture

### 3.1 Capability-Specific Snapshot Content & Restorability Matrix
A snapshot is captured automatically before executing any capability with risk level `medium`, `high`, or `destructive`. Because Sitevero is an entity delta manager and not a binary filesystem backup tool, snapshot content and restoration guarantees are strictly capability-specific:

| Capability / Entity | What is Captured in Snapshot? | What Can Be Restored? | What CANNOT Be Restored? | Rollback Status |
|---|---|---|---|:---:|
| **Posts & Pages (`content.manage_post`)** | `post_content`, `post_title`, `post_status`, `post_excerpt`, and all custom `postmeta`. | Full post content, status, and metadata restored via `wp_update_post()`. | N/A (Full state restore supported). | `fully_restored` |
| **Elementor (V3, V4, Hybrid) (`elementor.manage_page`)** | Raw `_elementor_data` JSON string, `_elementor_page_settings`, and postmeta. | Full element hierarchy, widget settings, atomic elements, classes, and tokens. CSS cache cleared. | N/A (Full state restore supported). | `fully_restored` |
| **Gutenberg Blocks (`gutenberg.manage_blocks`)** | Raw `post_content` (block comment HTML markup). | Full block structure and inner content restored. | N/A (Full state restore supported). | `fully_restored` |
| **Settings / Options (`system.manage_settings`)** | Serialized option value from `get_option($option_name)`. | Full option value restored via `update_option()`. | N/A (Full state restore supported). | `fully_restored` |
| **Plugin Activation (`system.manage_plugins`)** | `active_plugins` array from database. | Active/inactive state restored via `activate_plugin()` / `deactivate_plugins()`. | N/A (Full state restore supported). | `fully_restored` |
| **Plugin Deletion (`system.manage_plugins`)** | Plugin header metadata, slug, version, and active status. | Records prior presence in audit log. | **Deleted physical plugin files on disk cannot be restored.** | `not_restorable` |
| **Theme Switch (`system.manage_themes`)** | `stylesheet` and `template` option values. | Previous active theme restored via `switch_theme()`. | N/A (Full state restore supported). | `fully_restored` |
| **Theme Deletion (`system.manage_themes`)** | Theme metadata, slug, and previous status. | Records prior presence in audit log. | **Deleted physical theme files on disk cannot be restored.** | `not_restorable` |
| **Media Metadata / Attach (`media.manage`)** | Post attachment metadata, alt text, title, caption, `post_parent`. | Metadata and post associations restored. | N/A (Full state restore supported). | `fully_restored` |
| **Media Deletion (`media.manage`)** | Attachment record ID, metadata, and attachment URL. | Metadata record in audit log. | **Physical binary image/media file deleted from disk cannot be restored.** | `not_restorable` |
| **User Profile / Role (`users.manage`)** | User profile fields, display name, email, and role array. | Profile fields and role assignments restored. | N/A (Full state restore supported). | `fully_restored` |
| **User Deletion (`users.manage`)** | User profile data, role, and post reassignment ID. | User deletion record and reassignment ID in log. | **Deleted user account cannot be automatically recreated** (password hashes are not stored). | `not_restorable` |

### 3.2 Rollback Execution Routine
When `sitevero_rollback(snapshot_uuid)` is executed:
1. Lookup snapshot record in `wp_sitevero_snapshots` by `snapshot_uuid`.
2. Verify user has WordPress core capability to manage the target entity.
3. Parse `before_state` JSON.
4. Determine restorability based on entity type and action:
   - **For Fully Restorable Entities (Content, Elementor, Gutenberg, Options, Plugin/Theme State):**
     - Execute entity restore via standard WordPress APIs.
     - Return `status: "success"`, `restoration_status: "fully_restored"`.
   - **For Irreversible Destructive Actions (Deleted Media, Deleted Plugins, Deleted Themes, Deleted Users):**
     - Do NOT attempt invalid binary/account recreation.
     - Return `status: "completed_with_limitations"`, `restoration_status: "not_restorable"`, with an explicit human-readable explanation of why physical disk/credential assets cannot be recovered.
5. Record a new activity entry of type `rolled_back` (or `rollback_failed`).
6. Return structured response to the AI agent or WP-Admin UI.

---

## 4. Retention & Storage Management
- **30-Day Retention Window:** All activity logs and snapshots older than 30 days are automatically deleted.
- **Housekeeping Maintenance Hook:** Registered with standard WordPress Cron:
  ```php
  // Hooked to 'sitevero_daily_maintenance'
  $wpdb->query("DELETE FROM {$wpdb->prefix}sitevero_snapshots WHERE created_at < NOW() - INTERVAL 30 DAY");
  $wpdb->query("DELETE FROM {$wpdb->prefix}sitevero_activity WHERE created_at < NOW() - INTERVAL 30 DAY");
  ```
- **Storage Limits:** Maximum snapshot payload size strictly capped at 2MB. If an entity's before-state exceeds 2MB, execution halts with `ERR_SNAPSHOT_SIZE_EXCEEDED` to prevent database bloat.
- **Zero External Telemetry:** All audit logs remain 100% local on the host database.

---

## 5. Dependencies
- Custom tables `wp_sitevero_snapshots` and `wp_sitevero_activity`.
- WordPress Cron (`wp_schedule_event`).
- Core entity update APIs.

---

## 6. Explicitly Out of Scope
- Branching version control trees (like Git commits or merge conflicts).
- Multi-step redo stacks (rollback is strictly single-step).
- Binary file rollback (uploaded images deleted via MCP cannot be restored from snapshot in MVP).
- Full database table rollbacks or filesystem file restores.

---

## 7. Resolved Decisions
- *Decision 11.1: Rollback Chaining & Redo Stack:* **RESOLVED.** Disallowed in MVP. Rollback is strictly a single-step restoration to the entity's pre-execution snapshot. There is no multi-level undo/redo tree, eliminating cyclical state corruption and keeping the MVP data model lean.
