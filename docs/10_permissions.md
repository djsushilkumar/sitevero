# Sitevero — Permissions & Access Control Specification

## 1. Core Permission Rules
1. **Zero Privilege Escalation:** Sitevero checks permissions against the active, authenticated WordPress user context (`wp_get_current_user()`). Under no circumstances does Sitevero bypass role restrictions or grant higher privileges than what the WordPress user possesses.
2. **WordPress Authority:** If WordPress core rejects an action (e.g., `current_user_can('edit_post', $post_id)` or `current_user_can('delete_users')` returns `false`), Sitevero immediately rejects the call.
3. **Dual-Layer Gate:** To execute an action, the user must satisfy:
   - **Layer 1 (Plugin Control):** The capability must be enabled in Sitevero Admin Settings.
   - **Layer 2 (WordPress Core):** The user's role must have the required WordPress core capability.

```
Incoming Request
      |
      v
[ Is Sitevero Capability Enabled? ] ---> NO ---> Abort (ERR_CAPABILITY_DISABLED)
      |
      | YES
      v
[ Does User have WP Core Cap? ]     ---> NO ---> Abort (ERR_FORBIDDEN_WP_CAP)
      |
      | YES
      v
[ Proceed to Execution ]
```

---

## 2. Role-to-Capability Matrix

| Sitevero Capability & Action | Required WP Core Capability | Administrator | Editor | Author | Contributor |
|---|---|:---:|:---:|:---:|:---:|
| `sitevero_discover` | `read` | Allowed | Allowed | Allowed | Allowed |
| `sitevero_inspect` (schema) | `read` | Allowed | Allowed | Allowed | Allowed |
| `sitevero_inspect` (content) | `edit_posts` / `read_post` | Allowed | Allowed | Allowed (own) | Allowed (own) |
| `sitevero_inspect` (settings) | `manage_options` | Allowed | Denied | Denied | Denied |
| `content.query` | `read` | Allowed | Allowed | Allowed | Allowed |
| `content.manage_post` (create) | `publish_posts` / `edit_posts` | Allowed | Allowed | Allowed | Draft only |
| `content.manage_post` (update) | `edit_others_posts` / `edit_posts`| Allowed | Allowed | Own only | Own draft only |
| `content.manage_post` (trash/delete) | `delete_others_posts` / `delete_posts`| Allowed | Allowed | Own only | Denied |
| `media.manage` (upload/meta/replace) | `upload_files` | Allowed | Allowed | Allowed | Denied |
| `media.manage` (delete) | `delete_posts` | Allowed | Allowed | Own only | Denied |
| `elementor.manage_page` (V3/V4/Hybrid) | `edit_posts` | Allowed | Allowed | Allowed (own) | Denied |
| `gutenberg.manage_blocks` | `edit_posts` | Allowed | Allowed | Allowed (own) | Draft only |
| `system.manage_settings` | `manage_options` | Allowed | Denied | Denied | Denied |
| `system.manage_plugins` (list/inspect)| `activate_plugins` | Allowed | Denied | Denied | Denied |
| `system.manage_plugins` (activate/deactivate)| `activate_plugins` | Allowed | Denied | Denied | Denied |
| `system.manage_plugins` (install) | `install_plugins` | Allowed | Denied | Denied | Denied |
| `system.manage_plugins` (delete) | `delete_plugins` | Allowed | Denied | Denied | Denied |
| `system.manage_themes` (switch) | `switch_themes` | Allowed | Denied | Denied | Denied |
| `system.manage_themes` (install) | `install_themes` | Allowed | Denied | Denied | Denied |
| `system.manage_themes` (delete) | `delete_themes` | Allowed | Denied | Denied | Denied |
| `users.manage` (list/inspect) | `list_users` | Allowed | Denied | Denied | Denied |
| `users.manage` (create/update) | `create_users` / `promote_users` | Allowed | Denied | Denied | Denied |
| `users.manage` (delete) | `delete_users` | Allowed | Denied | Denied | Denied |
| `sitevero_rollback` | Match original capability cap | Allowed | Allowed (content only)| Denied | Denied |

---

## 3. Capability-Level Enable/Disable Controls
In the WordPress Admin Dashboard under `Sitevero -> Capabilities`, the site administrator has master toggle controls over every capability:

```json
{
  "sitevero_capability_settings": {
    "content.manage_post": true,
    "media.manage": true,
    "elementor.manage_page": true,
    "gutenberg.manage_blocks": true,
    "system.manage_settings": false,
    "system.manage_plugins": false,
    "system.manage_themes": false,
    "users.manage": false
  }
}
```
If an administrator disables `system.manage_plugins`, the capability is omitted from `sitevero_discover` and immediately rejected with `ERR_CAPABILITY_DISABLED` if invoked.

---

## 4. Dependencies
- WordPress Core Capabilities API (`current_user_can()`, `map_meta_cap()`).
- WordPress User & Roles system (`WP_User`, `WP_Roles`).

---

## 5. Explicitly Out of Scope
- Creating custom WordPress roles or modifying role capabilities via MCP in MVP.
- Per-agent custom API keys or granular sub-user permissions (relies on standard WordPress users).
- Multi-site network super-admin capabilities (`manage_network`).

---

## 6. Resolved Decisions
- *Decision 10.1: Dedicated "AI Assistant" Role & Authentication:* **RESOLVED.** Sitevero does not create custom WordPress roles on activation. AI agents authenticate through the official WordPress MCP Adapter's supported authentication layer (tied to an existing WordPress user account, e.g., Application Passwords or active REST session). Sitevero evaluates capabilities directly against `wp_get_current_user()` and WordPress core `current_user_can()`. This avoids role table pollution and guarantees that native WordPress user permissions remain the single, uncompromised source of truth.
