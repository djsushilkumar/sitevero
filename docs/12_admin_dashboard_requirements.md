# Sitevero — Admin Dashboard Requirements

## 1. Overview & Purpose
The Sitevero Admin Dashboard provides a clean, native WordPress Admin interface for site owners and administrators to:
1. Verify MCP server connection status and view the active MCP endpoint URL.
2. Monitor AI agent activity in real time with local audit logging.
3. Enable or disable specific Sitevero capabilities via master toggles.
4. Review captured snapshots and trigger manual rollbacks with a single click.
5. Inspect builder status (Elementor V3/V4/Hybrid presence and Free/Pro detection).

---

## 2. Dashboard Layout & Screen Architecture
The dashboard is registered as a top-level menu item in WordPress Admin:
- **Menu Title:** `Sitevero`
- **Slug:** `sitevero-dashboard`
- **Capability Required:** `manage_options`

```
+---------------------------------------------------------------------------------------+
|  Sitevero — Universal WordPress AI / MCP Controller                     [v1.0.0-beta] |
+---------------------------------------------------------------------------------------+
|                                                                                       |
|  +---------------------------+  +--------------------------+  +---------------------+  |
|  | MCP Server Status         |  | Active Capabilities      |  | Total Agent Actions |  |
|  | [● CONNECTED]             |  | 8 / 9 Enabled            |  | 42 (Past 30 Days)   |  |
|  | Endpoint: /wp-json/mcp/...|  | Builders: Elementor V3/V4|  | Rollbacks: 2        |  |
|  +---------------------------+  +--------------------------+  +---------------------+  |
|                                                                                       |
|  [ Tabs: Activity Log & Rollbacks | Capability Controls | MCP Connection & Setup ]     |
|                                                                                       |
|  +---------------------------------------------------------------------------------+  |
|  | Activity History & Snapshots                                                     |  |
|  |---------------------------------------------------------------------------------|  |
|  | Time       | Agent  | Action                | Target  | Status   | Actions      |  |
|  |------------|--------|-----------------------|---------|----------|--------------|  |
|  | 2 mins ago | Claude | elementor.manage_page | Page #42| SUCCESS  | [Rollback]   |  |
|  | 1 hour ago | Cursor | system.manage_settings| Options | SUCCESS  | [Rollback]   |  |
|  | 3 hrs ago  | AGY    | content.manage_post   | Post #12| ROLLED   | [Details]    |  |
|  +---------------------------------------------------------------------------------+  |
+---------------------------------------------------------------------------------------+
```

---

## 3. Detailed Tab Specifications

### 3.1 Tab 1: Activity Log & Rollbacks
- **Table Columns:**
  - `Timestamp`: Humanized relative time (e.g., "5 minutes ago") with exact ISO hover tooltip.
  - `Client / Agent`: Client identifier (e.g., `Antigravity`, `Claude`, `Cursor`).
  - `User`: WordPress username executing the request.
  - `Capability & Action`: E.g., `elementor.manage_page (update)`, `media.manage (upload)`.
  - `Target`: E.g., `Page #42 (About Us)`, `Plugin 'elementor'`, `Setting 'blogname'`.
  - `Status Badge`: `success` (green), `error` (red), `confirmation_required` (yellow), `rolled_back` (gray).
  - `Actions`: 
    - `[View Details]`: Modal showing parameter payload, before/after diff summary, and error traces.
    - `[Rollback]`: Triggers single-step capability-specific restoration from snapshot (with browser confirmation prompt). For non-restorable operations (such as physical media or plugin file deletion), the button is disabled or presents the capability's non-restorable limitation notice.
- **Pagination:** Standard WordPress pagination with 20 items per page, sorted descending by timestamp.

### 3.2 Tab 2: Capability Controls
- List of all registered Sitevero capabilities grouped by category (`Content`, `Media`, `Elementor`, `Gutenberg`, `Plugins`, `Themes`, `Users`, `Settings`).
- Toggle switch (checkbox) for each capability to enable or disable it globally.
- Risk level indicators (`low`, `medium`, `high`, `destructive`).
- Save Settings button storing configuration in `sitevero_capability_settings` option.

### 3.3 Tab 3: MCP Connection & Setup Guide
- Displays the exact MCP Server configuration JSON to copy-paste into Claude Desktop, Cursor, or Antigravity configuration files (`mcpServers` config).
- Connection diagnostics check:
  - Validates Official WordPress MCP Adapter status.
  - Validates transport readiness (STDIO / HTTP).
  - Tests WordPress authentication reachability.

---

## 4. Technical Implementation
- **UI Stack:** Native WordPress Admin styling (`wp-admin` CSS classes: `.wrap`, `.wp-list-table`, `.button-primary`, `.notice`) with minimal vanilla JavaScript. No heavy React/Vite builds required for MVP.
- **Rollback Request Handler:** Admin POST action via `admin-post.php` protected with `check_admin_referer('sitevero_rollback_action')` and `current_user_can('manage_options')`.

---

## 5. Dependencies
- Standard WordPress Admin API (`add_menu_page`, `register_setting`, `wp_nonce_field`).
- Custom tables `wp_sitevero_snapshots` and `wp_sitevero_activity`.

---

## 6. Explicitly Out of Scope
- Real-time WebSockets / Live polling dashboard graph (standard page refresh or simple fetch is used).
- SaaS multi-site central management dashboard or external cloud telemetry.
- Pro licensing, payments, paywalls, or subscription activation screens.
- Complex React/Vue/Vite frontend build systems (strictly native WP-Admin HTML/CSS/Vanilla JS).

---

## 7. Resolved Decisions
- *Decision 12.1: Activity Log Filtering:* **RESOLVED.** The activity log uses standard WordPress pagination (20 items per page) ordered by timestamp descending. Advanced multi-criteria date filters, full-text search, and export features are deferred to post-MVP, preserving a minimal native WP-Admin footprint.
