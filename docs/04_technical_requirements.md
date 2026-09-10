# Sitevero — Technical Requirements Specification

## 1. System Architecture
Sitevero is developed as an object-oriented, PSR-4 compliant WordPress plugin. It adheres to WordPress coding standards while utilizing modern PHP 8.1+ features (typed properties, enums, return types, match expressions, nullsafe operators).

```
                      +------------------------------------------+
                      |         AI Agent (MCP Client)            |
                      +------------------------------------------+
                                           |
                                           | MCP Protocol (STDIO / HTTP)
                                           v
+---------------------------------------------------------------------------------------+
|  Sitevero WordPress Plugin Container                                                  |
|                                                                                       |
|   +-------------------------------------------------------------------------------+   |
|   | Official WordPress MCP Adapter (Transport & Protocol Foundation)              |   |
|   | - Canonical WordPress/mcp-adapter package handles transports, auth, & framing |   |
|   | - Sitevero registers the 4 Universal Tools: discover, inspect, execute, revert|   |
|   +-------------------------------------------------------------------------------+   |
|                                          |                                            |
|                                          v                                            |
|   +-------------------------------------------------------------------------------+   |
|   | Sitevero Universal Core Engine                                                |   |
|   |  - Auth & Capability Check (current_user_can)                                 |   |
|   |  - Capability Registry (Dynamic routing to handlers)                          |   |
|   |  - Risk Assessor & Confirmation Gate                                          |   |
|   |  - Snapshot Engine (Pre-execution state capture with 2MB cap)                 |   |
|   |  - Activity Logger (Local audit logging)                                      |   |
|   +-------------------------------------------------------------------------------+   |
|                                          |                                            |
|          +-------------------------------+-------------------------------+            |
|          |                               |                               |            |
|          v                               v                               v            |
|   +---------------+              +---------------+              +---------------+     |
|   | Core Module   |              | Elementor Mod |              | Gutenberg Mod |     |
|   | (Content,     |              | - V3 Engine   |              | (Block APIs,  |     |
|   |  Media, Users,|              | - V4 Atomic   |              |  Patterns,    |     |
|   |  Plugins/Thms,|              | - Hybrid      |              |  Templates)   |     |
|   |  Settings)    |              | - Normalizer  |              |               |     |
|   +---------------+              +---------------+              +---------------+     |
|                                                                                       |
+---------------------------------------------------------------------------------------+
                                           |
                                           v
               +-------------------------------------------------------+
               | WordPress Core / Database Fallback                    |
               | - Standard APIs: wp_insert_post, update_option, etc.  |
               | - Controlled READ-ONLY SQL fallback (SELECT only)     |
               | - wp_sitevero_snapshots (custom table)                |
               | - wp_sitevero_activity (custom table)                 |
               +-------------------------------------------------------+
```

---

## 2. Directory & Namespace Structure

```
sitevero/
├── sitevero.php                 # Main plugin bootstrap file
├── composer.json                # Dependencies & PSR-4 autoloading (Sitevero\\ => inc/)
├── inc/
│   ├── Core/
│   │   ├── Plugin.php           # Singleton lifecycle & hook orchestrator
│   │   └── Container.php        # Lightweight service container
│   ├── Mcp/
│   │   ├── AdapterBridge.php    # Bridge into Official WordPress MCP Adapter
│   │   ├── ToolRegistrar.php    # Registers 4 universal meta-tools
│   │   └── SchemaProvider.php   # Generates JSON Schemas for tools
│   ├── Capabilities/
│   │   ├── CapabilityRegistry.php # Central registry of modules & capabilities
│   │   ├── CapabilityInterface.php# Contract for all capability handlers
│   │   ├── BaseCapability.php   # Shared validation, permission, snapshot logic
│   │   ├── Content/             # Posts, pages, CPTs
│   │   ├── Media/               # Attachments, uploads, metadata, replacement
│   │   ├── Elementor/           # Elementor universal capability module
│   │   │   ├── ElementorModule.php       # Master coordinator & model detector
│   │   │   ├── Normalizer.php            # Universal schema representation
│   │   │   ├── Engines/
│   │   │   │   ├── V3Engine.php          # V3 widgets, containers, sections
│   │   │   │   ├── V4Engine.php          # V4 Atomic Elements, classes, variables
│   │   │   │   └── HybridEngine.php      # Coexistence & non-destructive routing
│   │   │   └── FreeProDetector.php       # Detects Elementor Free vs. Pro capabilities
│   │   ├── Gutenberg/           # Block parser, serializer, patterns, FSE templates
│   │   ├── System/              # Plugins, themes, whitelisted settings
│   │   └── Users/               # User and role management (including deletion)
│   ├── Safety/
│   │   ├── RiskAssessor.php     # Evaluates action risk levels
│   │   ├── ConfirmationGate.php # Manages transient tokens for confirmation
│   │   ├── Sanitizer.php        # Input/output sanitation (wp_kses_post, etc.)
│   │   └── SnapshotManager.php  # Database snapshot creation and restoration
│   ├── Storage/
│   │   ├── DatabaseMigrator.php # DB delta migrations for custom tables
│   │   ├── SnapshotRepository.php # CRUD operations for snapshots
│   │   └── ActivityRepository.php # CRUD operations for audit logs
│   └── Admin/
│       ├── AdminDashboard.php   # WP Admin menu, settings, status view
│       └── Assets/              # Minimal vanilla JS/CSS for admin dashboard
└── docs/                        # Complete technical documentation
```

---

## 3. Database Schema Requirements
Sitevero uses standard WordPress `dbDelta()` to create two lightweight custom tables on plugin activation.

### 3.1 `wp_sitevero_snapshots`
Stores pre-execution state for instant single-step rollback.

| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY` | Snapshot unique identifier |
| `snapshot_uuid` | `VARCHAR(64) UNIQUE NOT NULL` | UUID string exposed to AI agent (e.g., `snp_01hn...`) |
| `entity_type` | `VARCHAR(32) NOT NULL` | `post`, `postmeta`, `elementor_data`, `option`, `plugin`, `theme`, `user` |
| `entity_id` | `VARCHAR(64) NOT NULL` | Target ID (e.g., post ID `105`, user ID `2`, or option name `blogname`) |
| `capability_id` | `VARCHAR(64) NOT NULL` | Capability triggering snapshot (e.g., `elementor.manage_page`) |
| `before_state` | `LONGTEXT NOT NULL` | JSON-encoded snapshot of state before mutation |
| `after_state` | `LONGTEXT NULL` | JSON-encoded snapshot of state after mutation (populated post-exec) |
| `user_id` | `BIGINT(20) UNSIGNED NOT NULL` | WP User ID executing action |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | Creation timestamp |

### 3.2 `wp_sitevero_activity`
Stores audit history of all agent operations.

| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY` | Log primary key |
| `snapshot_uuid` | `VARCHAR(64) NULL` | Foreign UUID link to snapshot if state was mutated |
| `capability_id` | `VARCHAR(64) NOT NULL` | Capability executed |
| `status` | `VARCHAR(20) NOT NULL` | `success`, `error`, `confirmation_required`, `rolled_back` |
| `risk_level` | `VARCHAR(16) NOT NULL` | `low`, `medium`, `high`, `destructive` |
| `agent_client` | `VARCHAR(64) NULL` | Client identifier (e.g., `Antigravity`, `Claude`, `Cursor`) |
| `summary` | `VARCHAR(255) NOT NULL` | Human-readable summary of the action |
| `details` | `LONGTEXT NULL` | JSON metadata, parameters, validation results, or error traces |
| `user_id` | `BIGINT(20) UNSIGNED NOT NULL` | WP User ID executing action |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | Timestamp |

### 3.3 Internal Maintenance & Retention Pruning
- Daily WordPress cron hook (`sitevero_daily_maintenance_event`) executes:
  - Deletes snapshots and activity rows older than 30 days (`created_at < NOW() - INTERVAL 30 DAY`).
  - No continuous background daemon; runs on standard WordPress cron visits.
  - **Strict Constraint:** This is an internal plugin housekeeping routine only. AI agents cannot schedule, alter, or trigger recurring or delayed cron actions over MCP.

---

## 4. Technical Constraints & Security Boundaries
- **PHP Memory Limit:** Compatible with standard 128MB/256MB environments; Elementor V3/V4 payloads are parsed with zero unnecessary object duplication.
- **Request Execution Timeout:** Operations complete within standard HTTP timeout limits (max 30s).
- **Batch Safety Threshold:** Maximum 50 items per bulk request to prevent PHP execution timeout and memory exhaustion.
- **Zero Arbitrary Code Execution:** No `eval()`, `exec()`, `shell_exec()`, `system()`, or dynamic function invocations from user input.
- **No Direct Filesystem Access:** All file interactions run strictly through WordPress APIs (`wp_handle_upload()`, `Plugin_Upgrader`, `Theme_Upgrader`, `plugins_api()`, `themes_api()`). Sitevero never performs direct directory traversal, manual ZIP extraction, or arbitrary file writing.
- **Read-Only Database Fallback:** Direct `$wpdb` access is restricted exclusively to Sitevero's internal custom tables (`wp_sitevero_snapshots`, `wp_sitevero_activity`) and controlled, read-only `SELECT` queries where WordPress APIs do not expose needed inspection data. No arbitrary SQL writes or updates are permitted.
- **Zero External Telemetry:** All activity logging is stored locally in `wp_sitevero_activity`; zero data is transmitted to third-party endpoints.

---

## 5. Dependencies & Environment
- **PHP Runtime:** PHP 8.1+
- **WordPress Core:** Latest stable WordPress version + previous major WordPress version (Self-hosted single-site)
- **Database:** MySQL 5.7+ or MariaDB 10.3+
- **Official WordPress MCP Adapter:** Canonical `WordPress/mcp-adapter` package. Sitevero relies entirely on this verified adapter for transport handling, server registration, authentication, and protocol implementation, avoiding any parallel or custom MCP transport layer.

---

## 6. Resolved Decisions
- *Decision 4.1: Snapshot Storage Threshold:* **RESOLVED.** The maximum snapshot payload size is strictly capped at 2MB per entity. If an entity's before-state JSON exceeds 2MB, execution halts with `ERR_SNAPSHOT_SIZE_EXCEEDED`, preventing database bloat and MySQL packet overflow.
