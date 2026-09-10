# Sitevero — Functional Requirements Specification

## 1. System Overview & Core Loop
Sitevero acts as a deterministic capability router between MCP-compliant AI agents and WordPress.
The core lifecycle of every agent interaction follows this 5-stage pipeline:

```
+---------------+     +---------------+     +---------------+     +---------------+     +---------------+
| 1. DISCOVERY  | --> | 2. INSPECTION | --> | 3. EXECUTION  | --> | 4. VALIDATION | --> |  5. ROLLBACK  |
| Environment,  |     | Query entity  |     | Permission,   |     | Internal      |     | (Optional)    |
| capabilities, |     | schemas, V3/V4|     | risk gate,    |     | verification  |     | Single-step   |
| Free/Pro status|    | trees, states |     | snapshot & run|     | & state check |     | delta restore |
+---------------+     +---------------+     +---------------+     +---------------+     +---------------+
```

The external MCP interface is strictly bounded to 4 universal meta-tools (`sitevero_discover`, `sitevero_inspect`, `sitevero_execute`, `sitevero_rollback`). Validation is an internal pipeline step within `sitevero_execute`.

---

## 2. Functional Requirements by Lifecycle Stage

### 2.1 Capability Discovery (`sitevero_discover`)
- **FR-DISC-01:** Returns environment details: WordPress version, active theme, active plugins, PHP version, and detected builder presence:
  - Elementor status: `installed`, `active`, `is_pro_active`, supported generations (`v3: true`, `v4: true`, `hybrid: true`).
  - Gutenberg status: `installed`, `active`, FSE/block template support.
- **FR-DISC-02:** Returns a categorized catalog of available capabilities (`content`, `media`, `elementor`, `gutenberg`, `plugins`, `themes`, `users`, `settings`).
- **FR-DISC-03:** Each capability in the catalog specifies:
  - `capability_id` (e.g., `elementor.manage_page`, `content.manage_post`, `media.manage`).
  - `description` (short, semantic explanation for LLM).
  - `risk_level` (`low`, `medium`, `high`, `destructive`).
  - `is_enabled` (boolean reflecting Sitevero Admin toggle).

### 2.2 Schema & State Inspection (`sitevero_inspect`)
- **FR-INSP-01:** Returns the exact input parameter schema (JSON Schema format) for any capability.
- **FR-INSP-02:** Inspects existing WordPress entities by ID/type (e.g., fetch Post #42 content, postmeta, status).
- **FR-INSP-03 (Elementor V3/V4/Hybrid Inspection):**
  - Detects whether target page is Elementor V3, V4, or Hybrid using verified structural and schema markers (not blanket prefix matching).
  - Returns normalized representation indicating the page model (`generation: "v3" | "v4" | "hybrid" | "unknown"`).
  - For V3: returns containers/sections and widget tree.
  - For V4: returns Atomic Elements (`e-div-block`, `e-flexbox`, `e-grid`), classes, and design token references bound via Elementor's supported token model.
  - For Hybrid: returns coexisting structures cleanly demarcated.
- **FR-INSP-04:** For Gutenberg pages, returns parsed block arrays (`parse_blocks()`).
- **FR-INSP-05:** For media, returns attachment metadata, URLs, dimensions, and generated thumbnail sizes.
- **FR-INSP-06:** For system settings, returns option values against whitelisted safe setting keys.

### 2.3 Execution, Risk Confirmation & Snapshot (`sitevero_execute`)
- **FR-EXEC-01:** Validates authenticated WordPress user context (`wp_get_current_user()`). Never escalates privileges.
- **FR-EXEC-02:** Validates and sanitizes all execution parameters against the capability's declared schema before mutation.
- **FR-EXEC-03 (Risk Gate & Confirmation):**
  - If `risk_level` is `high` or `destructive` (e.g., trashing/deleting a post or user, deactivating/deleting a plugin or theme, switching themes, updating global settings, or performing bulk destructive edits), execution halts and returns:
    ```json
    {
      "status": "confirmation_required",
      "risk_level": "destructive",
      "action_summary": "Permanently delete User #15 ('editor_jane')",
      "confirmation_token": "cf_9a8b7c6d5e...",
      "expires_in_seconds": 300
    }
    ```
  - Execution proceeds only if the subsequent call includes the matching `confirmation_token`.
- **FR-EXEC-04 (Pre-Execution Snapshot):** Before mutating any entity marked `medium`, `high`, or `destructive`, automatically captures a state snapshot into `wp_sitevero_snapshots` (2MB limit) and generates a `snapshot_uuid`.
- **FR-EXEC-05 (Halting on Error):** If an error occurs during execution, halts immediately, terminates transaction, and returns the exact error message. No autonomous retry loops.

### 2.4 Internal State Validation (Within `sitevero_execute`)
- **FR-VAL-01:** Immediately following execution, re-queries the modified entity to verify existence, property matching, and structure integrity.
- **FR-VAL-02:** Returns the verified post-execution state alongside `snapshot_uuid`.
- **FR-VAL-03:** Validation is strictly internal to `sitevero_execute`, preserving the 4-tool universal surface.

### 2.5 Rollback (`sitevero_rollback`)
- **FR-ROLL-01:** Restores an entity to its captured supported state using `snapshot_uuid`. Full state restoration is guaranteed for Content (posts/pages), Elementor (V3, V4, Hybrid), Gutenberg, and Settings. For irreversible destructive operations (physical file unlinking in media, plugin/theme deletions, or user deletion), the rollback routine returns `restoration_status: "not_restorable"` or `"partially_restored"` with an explicit explanation that physical filesystem assets cannot be recreated.
- **FR-ROLL-02:** Strictly single-step restoration; no multi-step redo tree.
- **FR-ROLL-03:** Enforces user capability to manage target entity before permitting rollback. Logs rollback in activity history.

---

## 3. Specific Capability Modules

### 3.1 Content (Posts & Pages)
- Full CRUD: list, search, get, create, update, trash, restore, delete posts and pages.
- Standard fields: `title`, `content`, `excerpt`, `status`, `slug`, `author`, `featured_media`, `categories` (by ID), `tags` (by ID).
- Bulk content actions supported up to 50 items/batch; destructive bulk actions require confirmation.

### 3.2 Media Library
- List and search media with pagination, mime-type, and date filters.
- Inspect attachment metadata, dimensions, and generated image sizes.
- Upload media via standard WordPress upload pipeline (`wp_handle_upload()`).
- Replace media safely where supported without breaking attachment ID references.
- Update metadata: `alt_text` (`_wp_attachment_image_alt`), `title`, `caption`, `description`.
- Assign or remove featured image on posts/pages.
- Attach/detach media to/from parent posts.
- Delete media where permissions allow (requires confirmation).

### 3.3 Elementor Engine (V3, V4, and Hybrid)
- **Model Detection:** Detects whether a page is built with Elementor, and whether it uses V3 widgets/containers, V4 Atomic Elements, or a Hybrid mix.
- **V3 Support:** Inspect and create/update Sections, Columns, Flexbox Containers, Core Widgets, widget settings, layout, typography, colors, spacing, and responsive controls.
- **V4 Support:** Inspect and create/update Atomic Elements (`e-div-block`, `e-flexbox`, `e-grid`), classes, variables (Elementor V4 design tokens bound via supported token schema; distinct from arbitrary CSS custom properties; no arbitrary CSS injection), styling system, supported responsive controls, supported interactions, container layout.
- **Hybrid Support:** Safely manages pages where V3 and V4 elements coexist. Routes updates to appropriate sub-engines internally; never forces cross-conversion.
- **Free vs. Pro Detection:** Inspects existing Pro widgets read-only; strictly disallows creating Pro-only widgets; never bundles Elementor Pro.
- **Cache Invalidation:** Triggers `\Elementor\Plugin::$instance->files_manager->clear_cache()` on save.

### 3.4 Gutenberg Block Engine
- Inspect and parse block structure (`parse_blocks()`).
- Granular Attribute & Content Sanitization: Validates registered block types, sanitizes block attributes against supported schemas, and sanitizes user text content fields (preserving legitimate HTML formatting elements without running blanket post-serialization stripping).
- Serialize block structure back to standard HTML comments (`serialize_blocks()`).
- Create/update core blocks: `core/paragraph`, `core/heading`, `core/image`, `core/columns`, `core/buttons`, `core/group`, `core/list`.
- List and insert registered Block Patterns (`WP_Block_Patterns_Registry`).
- Basic Template and Site Editing: read/update `wp_template` block content and assign `_wp_page_template`.

### 3.5 Plugin Management
- List installed plugins with active status, version, and update availability report.
- Inspect plugin details.
- Activate plugin (requires confirmation).
- Deactivate plugin (requires confirmation).
- Delete plugin where WordPress permissions permit (requires confirmation; irreversible in MVP).
- Install plugin from official WordPress.org repository via standard WordPress upgrader APIs (`plugins_api()` and `Plugin_Upgrader`) (requires confirmation).
- Install plugin from user-provided ZIP archive via standard WordPress upgrader APIs (`Plugin_Upgrader` with uploaded package; strictly no manual filesystem unzipping or directory manipulation) (requires confirmation).
- Report updates; strictly no automatic update installation in MVP.

### 3.6 Theme Management
- List installed themes with active theme indicator and update availability report.
- Inspect theme details.
- Switch/activate theme (requires confirmation).
- Delete theme where WordPress permissions permit (requires confirmation; irreversible in MVP).
- Install theme from official WordPress.org repository via standard WordPress upgrader APIs (`themes_api()` and `Theme_Upgrader`) (requires confirmation).
- Install theme from user-provided ZIP archive via standard WordPress upgrader APIs (`Theme_Upgrader`; strictly no manual filesystem unzipping or directory manipulation) (requires confirmation).
- Report updates; strictly no automatic update installation in MVP.

### 3.7 Users & Roles
- List users with pagination and role filters.
- Inspect user profiles (never exposes password hashes or salts).
- Create user with specified role (Administrator requires destructive confirmation).
- Edit basic user profile information (email, display name, name, bio).
- Assign or change user roles (requires confirmation; no privilege escalation).
- Delete user where WordPress permissions allow (requires confirmation; reassigns posts to specified user ID).

### 3.8 Common WordPress Settings
- Read and update whitelisted safe core settings:
  - Site Title (`blogname`)
  - Tagline (`blogdescription`)
  - Homepage / Posts page display (`show_on_front`, `page_on_front`, `page_for_posts`)
  - Timezone (`timezone_string`)
  - Date format (`date_format`) and Time format (`time_format`)
  - Permalinks structure (`permalink_structure`)
  - Reading settings (`posts_per_page`)
  - Discussion settings (`default_comment_status`, `comment_moderation`)
  - Media settings (`thumbnail_size_w`, `thumbnail_size_h`, `medium_size_w`, `medium_size_h`, `large_size_w`, `large_size_h`)
- Updating settings requires `manage_options` and high-risk confirmation.

### 3.9 Bulk Actions & Batch Safety Threshold
- Bulk operations are permitted for content, media, and plugin/theme status where underlying WordPress APIs safely support them.
- **Batch Safety Threshold:** Maximum 50 items per bulk request to prevent PHP execution timeout and memory saturation. Requests exceeding 50 items must be chunked by the agent.
- Risky or destructive bulk actions require explicit confirmation tokens.

---

## 4. Dependencies
- Standard WordPress Core APIs (`wp_insert_post`, `media_handle_upload`, `wp_delete_user`, `plugins_api`, `themes_api`, `wp_update_post`, `update_option`).
- Canonical `WordPress/mcp-adapter` package.
- Elementor V3 & V4 core APIs (`\Elementor\Plugin::$instance->documents`).
- Gutenberg Core block APIs (`parse_blocks`, `serialize_blocks`, `WP_Block_Patterns_Registry`).

---

## 5. Explicitly Out of Scope
- WooCommerce or e-commerce capabilities.
- WordPress Multisite Networks.
- Arbitrary SQL execution or database write bypasses.
- Direct filesystem file editing.
- Automatic background cron scheduling by AI agents.
- External phone-home telemetry.

---

## 6. Resolved Decisions
- *Decision 3.1: Custom Post Types (CPTs):* **RESOLVED.** MVP defaults to `post` and `page`; public CPTs can be enabled via an admin settings toggle; taxonomy term auto-creation is deferred to post-MVP.
- *Decision 3.2: Bulk Operations Threshold:* **RESOLVED.** Bounded to a strict safety threshold of 50 items per batch to prevent execution timeout and ensure deterministic snapshots.
