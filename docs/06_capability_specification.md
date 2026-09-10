# Sitevero — Capability Specification

## 1. Capability System Architecture
Sitevero uses a modular Capability Registry. A capability is a discrete, typed operation unit that encapsulates:
1. **Schema Definition:** Expected input parameters and constraints.
2. **Permission Check:** Required WordPress core capability (e.g., `edit_posts`, `delete_posts`, `upload_files`, `install_plugins`, `delete_plugins`, `manage_options`, `delete_users`).
3. **Risk Level:** Risk rating (`low`, `medium`, `high`, `destructive`) dictating confirmation behavior.
4. **Snapshot Handler:** Mechanism to record state prior to execution (2MB limit).
5. **Execution Handler:** The deterministic WordPress or builder call.
6. **Validator:** Verification routine run immediately post-execution.

```
                  +-----------------------------------+
                  |        Capability Registry        |
                  +-----------------------------------+
                                    |
          +-------------------------+-------------------------+
          |                         |                         |
          v                         v                         v
+-------------------+     +-------------------+     +-------------------+
|  Content Module   |     | Elementor Module  |     | Gutenberg Module  |
| - manage_post     |     | - manage_page     |     | - manage_blocks   |
| - query           |     |   (V3/V4/Hybrid)  |     | - insert_pattern  |
+-------------------+     +-------------------+     +-------------------+
          |                         |                         |
          v                         v                         v
+-------------------+     +-------------------+     +-------------------+
|   Media Module    |     |   System Module   |     |   Users Module    |
| - manage_media    |     | - manage_settings |     | - manage_users    |
|   (Upload, Meta,  |     | - manage_plugins  |     |   (CRUD, Roles,   |
|    Replace, Del)  |     | - manage_themes   |     |    Deletion)      |
+-------------------+     +-------------------+     +-------------------+
```

---

## 2. Core Interface Contract

```php
namespace Sitevero\Capabilities;

interface CapabilityInterface {
    public function getId(): string;
    public function getCategory(): string;
    public function getDescription(): string;
    public function getRiskLevel(string $action, array $params): string; // 'low'|'medium'|'high'|'destructive'
    public function getRequiredWpCapability(string $action, array $params): string;
    public function getInputSchema(): array;
    public function captureSnapshot(string $action, array $params): ?array;
    public function execute(string $action, array $params): array;
    public function validate(string $action, array $params, array $executionResult): array;
}
```

---

## 3. Catalog of MVP Capabilities

| Capability ID | Category | Supported Actions | Default Risk | WP Cap Required |
|---|---|---|---|---|
| `content.manage_post` | `content` | `create`, `update`, `trash`, `restore`, `delete`, `get`, `bulk_update` | `medium` (create/update) / `destructive` (trash/delete) | `edit_posts` / `delete_posts` |
| `content.query` | `content` | `list`, `search` | `low` | `read` |
| `media.manage` | `media` | `list`, `get`, `upload`, `replace`, `update_meta`, `attach`, `detach`, `delete` | `low` (get) / `medium` (upload/meta) / `destructive` (delete) | `upload_files` (delete requires `delete_posts`) |
| `elementor.manage_page` | `elementor` | `inspect_tree`, `create`, `update` (V3, V4 Atomic, or Hybrid) | `medium` | `edit_posts` |
| `gutenberg.manage_blocks` | `gutenberg` | `parse`, `create`, `update`, `insert_pattern` | `medium` | `edit_posts` |
| `system.manage_settings` | `settings` | `get`, `update` | `high` (update) | `manage_options` |
| `system.manage_plugins` | `plugins` | `list`, `inspect`, `activate`, `deactivate`, `delete`, `install_wporg`, `install_zip` | `low` (list) / `high` (activate/deactivate/install) / `destructive` (delete) | `activate_plugins` / `install_plugins` / `delete_plugins` |
| `system.manage_themes` | `themes` | `list`, `inspect`, `activate`, `delete`, `install_wporg`, `install_zip` | `low` (list) / `high` (activate/install) / `destructive` (delete) | `switch_themes` / `install_themes` / `delete_themes` |
| `users.manage` | `users` | `list`, `get`, `create`, `update_profile`, `update_role`, `delete` | `high` (create/update) / `destructive` (delete) | `list_users`, `create_users`, `promote_users`, `delete_users` |

---

## 4. Capability Deep Dives

### 4.1 `content.manage_post`
- **Actions:**
  - `create`: Inserts post/page via `wp_insert_post()`.
  - `update`: Updates fields via `wp_update_post()`.
  - `trash`: Moves post to trash via `wp_trash_post()`.
  - `restore`: Untrashes post via `wp_untrash_post()`.
  - `delete`: Permanently deletes post via `wp_delete_post(..., true)`. Requires confirmation token.
  - `get`: Returns structured post object + custom fields.
  - `bulk_update`: Updates status, categories, or author across up to 50 posts per batch.
- **Allowed Fields:** `post_title`, `post_content`, `post_excerpt`, `post_status`, `post_type` (`post` or `page`), `featured_media` (attachment ID), `categories` (term IDs), `tags` (term IDs).
- **Snapshot Behavior:** Backs up `post_content`, `post_title`, `post_status`, and all standard `postmeta`.

### 4.2 `media.manage`
- **Actions:**
  - `list` / `get`: Queries attachments, metadata, dimensions, and generated image sizes.
  - `upload`: Handles media upload via `wp_handle_upload()` or `media_sideload_image()` from a public HTTPS URL. Enforces standard WordPress mime-type checks.
  - `replace`: Replaces media file safely on an existing attachment ID without changing the ID reference.
  - `update_meta`: Updates `alt_text` (`_wp_attachment_image_alt`), `post_title`, `post_excerpt` (caption), and `post_content` (description).
  - `attach` / `detach`: Associates or disassociates media from parent post ID (`post_parent`).
  - `delete`: Permanently deletes attachment via `wp_delete_attachment(..., true)`. Requires confirmation token.
- **Snapshot & Rollback Guarantees:**
  - *Captured:* Attachment metadata, alt text, title, caption, and parent ID.
  - *Restorable:* Metadata updates, title/caption edits, and post attachment/detachment.
  - *Non-Restorable:* Deletion physically unlinks the media file from disk; rollback cannot recover deleted binary files in MVP (`restoration_status: not_restorable`).

### 4.3 `elementor.manage_page`
- **Actions:**
  - `inspect_tree`: Inspects page, detects model (`v3`, `v4`, `hybrid`, or `unknown`), and returns normalized representation.
  - `create`: Creates new Elementor page using V3 Containers or V4 Atomic Elements based on detected or requested architecture.
  - `update`: Updates page layout, widget settings, Atomic Elements, classes, variables (Elementor V4 design tokens bound via supported token model; not arbitrary CSS custom properties; no arbitrary CSS injection), styles, and responsive controls.
- **Engine Coordination:** Automatically delegates to `V3Engine`, `V4Engine`, or `HybridEngine`. Preserves untouched models and does not perform forced cross-conversions.
- **Cache Invalidation:** Always clears Elementor CSS cache (`\Elementor\Plugin::$instance->files_manager->clear_cache()`).
- **Snapshot & Rollback Guarantees:**
  - *Captured:* Raw `_elementor_data` JSON string, `_elementor_page_settings`, and postmeta (2MB cap).
  - *Restorable:* 100% full state restoration supported for V3, V4, and Hybrid page structures (`restoration_status: fully_restored`).

### 4.4 `system.manage_plugins`
- **Actions:**
  - `list` / `inspect`: Lists installed plugins, active status, versions, and update reports (`get_plugins()`).
  - `activate`: Activates plugin via `activate_plugin()`. Requires confirmation.
  - `deactivate`: Deactivates plugin via `deactivate_plugins()`. Requires confirmation.
  - `delete`: Deletes plugin via `delete_plugins()`. Requires confirmation token.
  - `install_wporg`: Downloads and installs plugin from official WordPress.org directory via `plugins_api()` and `Plugin_Upgrader`. Requires confirmation.
  - `install_zip`: Installs plugin from user-provided ZIP archive via standard WordPress upgrader APIs (`Plugin_Upgrader` with uploaded package; strictly no manual filesystem unzipping or directory manipulation). Requires confirmation.
- **Update Policy:** Reports available updates via WordPress update transient; strictly does NOT automatically install updates in MVP.
- **Snapshot & Rollback Guarantees:**
  - *Captured:* `active_plugins` option array and plugin header metadata.
  - *Restorable:* Plugin activation and deactivation states are fully restorable (`restoration_status: fully_restored`).
  - *Non-Restorable:* Plugin deletion physically removes plugin files from `wp-content/plugins`; deleted plugin files cannot be restored from delta snapshots in MVP (`restoration_status: not_restorable`).

### 4.5 `system.manage_themes`
- **Actions:**
  - `list` / `inspect`: Lists installed themes and update reports (`wp_get_themes()`).
  - `activate`: Switches active theme via `switch_theme()`. Requires confirmation.
  - `delete`: Deletes theme via `delete_theme()`. Requires confirmation.
  - `install_wporg`: Downloads and installs theme from WordPress.org via `themes_api()` and `Theme_Upgrader`. Requires confirmation.
  - `install_zip`: Installs theme from user-provided ZIP archive via standard WordPress upgrader APIs (`Theme_Upgrader`; strictly no manual filesystem unzipping). Requires confirmation.
- **Update Policy:** Reports available updates; strictly does NOT automatically install updates in MVP.
- **Snapshot & Rollback Guarantees:**
  - *Captured:* Active theme `stylesheet` and `template` option values.
  - *Restorable:* Theme switching is fully restorable to previous active theme (`restoration_status: fully_restored`).
  - *Non-Restorable:* Theme deletion physically removes theme files from disk; deleted theme files cannot be restored from delta snapshots in MVP (`restoration_status: not_restorable`).

### 4.6 `users.manage`
- **Actions:**
  - `list` / `get`: Lists users with pagination and role filters. Excludes password hashes and salts.
  - `create`: Creates user via `wp_insert_user()`. Sends password setup notification. Creating Administrator requires confirmation.
  - `update_profile`: Updates basic user metadata (`display_name`, `user_email`, `first_name`, `last_name`, `description`).
  - `update_role`: Assigns user role via `$user->set_role()`. Requires confirmation; zero privilege escalation.
  - `delete`: Permanently deletes user via `wp_delete_user()`. Requires confirmation token and specifies reassign user ID.
- **Snapshot & Rollback Guarantees:**
  - *Captured:* User profile fields, display name, email, and role array.
  - *Restorable:* Profile updates and role assignments are fully restorable (`restoration_status: fully_restored`).
  - *Non-Restorable:* Deletion reassigns posts to another user and purges the user record; raw passwords/credentials are not stored, so deleted user accounts cannot be automatically recreated via rollback (`restoration_status: not_restorable`).

### 4.7 `system.manage_settings`
- **Whitelisted Safe Settings Only:**
  - General: `blogname`, `blogdescription`, `timezone_string`, `date_format`, `time_format`.
  - Reading: `show_on_front`, `page_on_front`, `page_for_posts`, `posts_per_page`.
  - Discussion: `default_comment_status`, `comment_moderation`.
  - Permalinks: `permalink_structure`.
  - Media: `thumbnail_size_w`, `thumbnail_size_h`, `medium_size_w`, `medium_size_h`, `large_size_w`, `large_size_h`.
- **Validation:** Strict regex and type checks per option. Reject non-whitelisted option keys.
- **Snapshot & Rollback Guarantees:**
  - *Captured:* Serialized option values prior to update.
  - *Restorable:* 100% full state restoration supported (`restoration_status: fully_restored`).

---

## 5. Dependencies
- Standard WordPress Core APIs: `wp_insert_post`, `media_handle_upload`, `wp_delete_attachment`, `wp_delete_user`, `plugins_api`, `themes_api`, `Plugin_Upgrader`, `Theme_Upgrader`, `switch_theme`, `update_option`.
- Elementor Core V3/V4 APIs.

---

## 6. Explicitly Out of Scope
- Custom Database SQL queries or direct `$wpdb` modifications outside core APIs.
- Execution of arbitrary action/filter hooks on demand.
- WooCommerce checkout, orders, or product catalog capabilities.
- Third-party custom fields / meta plugins (ACF, Pods, JetEngine).

---

## 7. Resolved Decisions
- *Decision 6.1: Taxonomies Management:* **RESOLVED.** In MVP, `content.manage_post` supports assigning existing categories and tags by their term IDs (`categories: [1, 5]`, `tags: [12]`). Creating new taxonomy terms dynamically on the fly is deferred to post-MVP to maintain a simple, predictable content mutation flow.
- *Decision 6.2: Bulk Operations Limit:* **RESOLVED.** Bounded to a strict safety threshold of 50 items per batch to prevent execution timeout and ensure deterministic snapshots.
