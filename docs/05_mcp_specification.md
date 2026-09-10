# Sitevero — MCP Specification

## 1. Design Philosophy
Rather than overloading the AI agent's context window with hundreds of single-purpose tools (e.g., `wp_create_post`, `elementor_v3_create_widget`, `elementor_v4_create_atomic`, `wp_install_plugin`), Sitevero exposes **4 Universal Meta-Tools**. 

This approach:
1. Keeps tool definitions under 1,000 tokens in the agent system prompt.
2. Supports Elementor V3, Elementor V4 (Atomic Elements), and Hybrid pages through a unified interface without exposing fragmented version-specific tools.
3. Enforces a uniform, secure pipeline: Discovery -> Inspection -> Execution -> Validation -> Rollback.
4. Integrates cleanly on top of the official `WordPress/mcp-adapter` package.

---

## 2. Universal Tool Definitions

### Tool 1: `sitevero_discover`
Discovers the WordPress environment, active builders (Elementor V3/V4/Hybrid, Gutenberg), installed plugins/themes, and lists all available Sitevero capabilities.

#### Schema
```json
{
  "name": "sitevero_discover",
  "description": "Discovers WordPress environment details, detected builders (Elementor V3/V4/Hybrid, Gutenberg), Free/Pro status, active plugins/themes, and returns the catalog of available Sitevero capabilities.",
  "parameters": {
    "type": "object",
    "properties": {
      "category": {
        "type": "string",
        "enum": ["all", "content", "media", "elementor", "gutenberg", "plugins", "themes", "users", "settings"],
        "default": "all",
        "description": "Optional category filter to narrow down the capability catalog."
      },
      "search": {
        "type": "string",
        "description": "Optional keyword to search capabilities."
      }
    }
  }
}
```

#### Example Output
```json
{
  "environment": {
    "wp_version": "6.7.1",
    "php_version": "8.2.18",
    "site_title": "Sitevero Universal Demo",
    "site_url": "https://example.com",
    "active_theme": {
      "name": "Astra",
      "version": "4.8.0",
      "is_block_theme": false
    },
    "builders": {
      "elementor": {
        "installed": true,
        "active": true,
        "is_pro_active": false,
        "version": "4.0.0",
        "supported_generations": ["v3", "v4", "hybrid"]
      },
      "gutenberg": {
        "installed": true,
        "active": true,
        "fse_templates_supported": true
      }
    }
  },
  "capabilities": [
    {
      "id": "content.manage_post",
      "category": "content",
      "description": "Create, read, update, trash, restore, or delete WordPress posts and pages.",
      "risk_level": "medium"
    },
    {
      "id": "media.manage",
      "category": "media",
      "description": "List, inspect, upload, replace, attach/detach, update metadata, and delete media items.",
      "risk_level": "medium"
    },
    {
      "id": "elementor.manage_page",
      "category": "elementor",
      "description": "Universal Elementor engine: inspect, create, and update V3, V4 Atomic, or Hybrid pages.",
      "risk_level": "medium"
    },
    {
      "id": "system.manage_plugins",
      "category": "plugins",
      "description": "List, inspect, activate, deactivate, delete, and install plugins from WP.org or ZIP.",
      "risk_level": "high"
    },
    {
      "id": "system.manage_themes",
      "category": "themes",
      "description": "List, inspect, activate/switch, delete, and install themes from WP.org or ZIP.",
      "risk_level": "high"
    },
    {
      "id": "users.manage",
      "category": "users",
      "description": "List, inspect, create, edit profile, assign roles, and delete users.",
      "risk_level": "high"
    },
    {
      "id": "system.manage_settings",
      "category": "settings",
      "description": "Read and update whitelisted common WordPress settings.",
      "risk_level": "high"
    }
  ]
}
```

---

### Tool 2: `sitevero_inspect`
Inspects either:
1. The JSON parameter schema for a specific capability.
2. The current state/tree of an existing entity (Post, Elementor V3/V4/Hybrid tree, Gutenberg block tree, Media metadata/sizes, Settings, Plugins, Themes, Users).

#### Schema
```json
{
  "name": "sitevero_inspect",
  "description": "Inspects the parameter schema of a capability OR fetches the current state/tree of a specific WordPress entity or builder page.",
  "parameters": {
    "type": "object",
    "properties": {
      "target": {
        "type": "string",
        "enum": ["capability_schema", "entity_state"],
        "description": "Whether to inspect a capability's input schema or an entity's current state."
      },
      "capability_id": {
        "type": "string",
        "description": "Required if target is 'capability_schema' (e.g., 'elementor.manage_page')."
      },
      "entity_type": {
        "type": "string",
        "enum": ["post", "page", "elementor_tree", "gutenberg_blocks", "media", "plugins", "themes", "users", "settings"],
        "description": "Required if target is 'entity_state'."
      },
      "entity_id": {
        "type": "string",
        "description": "ID of target entity (e.g., post ID '42', user ID '1', media ID '85', or setting key 'general')."
      }
    },
    "required": ["target"]
  }
}
```

#### Example Output (Entity State: Elementor V4 Atomic Tree)
```json
{
  "target": "entity_state",
  "entity_type": "elementor_tree",
  "entity_id": "42",
  "page_title": "Modern Services",
  "elementor_model": {
    "generation": "v4",
    "is_hybrid": false,
    "has_legacy_sections": false
  },
  "structure": [
    {
      "id": "atm_flex_01",
      "type": "e-flexbox",
      "classes": ["hero-wrapper"],
      "settings": {
        "direction": "column",
        "gap": "e-var:space-md"
      },
      "elements": [
        {
          "id": "atm_heading_02",
          "type": "heading",
          "classes": ["hero-title"],
          "settings": {
            "title": "Scalable AI for WordPress",
            "tag": "h1"
          },
          "variables": {
            "color": "e-var:brand-primary",
            "typography": "e-var:font-heading-xl"
          }
        }
      ]
    }
  ]
}
```

---

### Tool 3: `sitevero_execute`
Executes an internal WordPress capability with permission verification, risk evaluation, automatic pre-execution snapshots (2MB limit), internal state validation, and error reporting.

#### Schema
```json
{
  "name": "sitevero_execute",
  "description": "Executes a supported capability. Assesses risk, captures a pre-execution snapshot, executes the action, validates the result internally, and returns the updated state.",
  "parameters": {
    "type": "object",
    "properties": {
      "capability_id": {
        "type": "string",
        "description": "Target capability identifier (e.g., 'elementor.manage_page', 'content.manage_post', 'media.manage')."
      },
      "action": {
        "type": "string",
        "description": "Action verb (e.g., 'create', 'update', 'trash', 'restore', 'delete', 'upload', 'replace', 'activate', 'deactivate', 'install')."
      },
      "parameters": {
        "type": "object",
        "description": "Arguments matching the capability schema retrieved via sitevero_inspect. Maximum 50 items for bulk operations."
      },
      "confirmation_token": {
        "type": "string",
        "description": "Required if a previous call indicated that confirmation was necessary for a high-risk or destructive action."
      }
    },
    "required": ["capability_id", "action", "parameters"]
  }
}
```

#### Example Output (Success with Internal Validation & Snapshot)
```json
{
  "status": "success",
  "capability_id": "elementor.manage_page",
  "action": "update",
  "snapshot_uuid": "snp_a1b2c3d4e5f6",
  "validation": {
    "verified": true,
    "entity_id": 42,
    "status": "publish",
    "generation": "v4",
    "element_count": 3
  },
  "summary": "Updated Elementor V4 page #42 ('Modern Services') with 1 e-flexbox container and 2 Atomic elements."
}
```

#### Example Output (Confirmation Required for Destructive Action)
```json
{
  "status": "confirmation_required",
  "risk_level": "destructive",
  "message": "Permanently deleting user #12 ('editor_john') requires explicit confirmation.",
  "confirmation_token": "cf_e83719ab2",
  "expires_in_seconds": 300,
  "instruction": "Re-run sitevero_execute with confirmation_token='cf_e83719ab2' to proceed."
}
```

---

### Tool 4: `sitevero_rollback`
Restores a target entity to its captured supported state prior to an execution using the provided snapshot UUID. Reports capability-specific restoration outcomes.

#### Schema
```json
{
  "name": "sitevero_rollback",
  "description": "Rolls back a previous action using its snapshot_uuid, restoring supported state in a single non-destructive step. Returns explicit restoration_status (fully_restored, partially_restored, not_restorable).",
  "parameters": {
    "type": "object",
    "properties": {
      "snapshot_uuid": {
        "type": "string",
        "description": "UUID of the snapshot to restore (returned from sitevero_execute or listed in the activity log)."
      }
    },
    "required": ["snapshot_uuid"]
  }
}
```

#### Example Output (Full State Restoration)
```json
{
  "status": "success",
  "restoration_status": "fully_restored",
  "snapshot_uuid": "snp_a1b2c3d4e5f6",
  "restored_entity": {
    "type": "post",
    "id": 42,
    "restored_at": "2026-09-10T17:30:00Z"
  },
  "summary": "Post #42 was fully restored to its captured pre-execution state."
}
```

#### Example Output (Irreversible Deletion Limitation)
```json
{
  "status": "completed_with_limitations",
  "restoration_status": "not_restorable",
  "snapshot_uuid": "snp_f9e8d7c6b5a4",
  "summary": "Plugin 'elementor-addon' files were permanently deleted and cannot be reconstructed from delta snapshots in MVP."
}
```

---

## 3. Protocol & Transport Integration
- **Sole Protocol Foundation:** Official `WordPress/mcp-adapter` package.
- **Delegated Responsibilities:**
  - Sitevero **relies entirely on the verified official WordPress MCP Adapter** for transport management, server registration, endpoint exposure, authentication, and wire protocol implementation.
  - Sitevero does **NOT** expose a parallel JSON-RPC server, socket daemon, or custom HTTP transport.
  - Whether executing locally (via WP-CLI / STDIO) or remotely (via the adapter's HTTP endpoints), the adapter provides the communication channel and authenticates the incoming user context.
- **Sitevero Ownership:** Sitevero strictly and exclusively owns the **Universal Capability Layer**:
  - Capability Registry and tool routing
  - Schema discovery and entity inspection
  - Dual-gate permission checks (`current_user_can()`)
  - 5-minute cryptographic confirmation gate for high/destructive actions
  - Pre-execution delta snapshots (2MB cap) and post-execution internal validation
  - Capability-specific rollback execution (`sitevero_rollback`)
  - Local audit activity logging (`wp_sitevero_activity`)
- **Pinned Dependency Versioning:** Exact adapter initialization hooks and tool registration contracts are pinned to the verified `WordPress/mcp-adapter` release in `composer.json`.

---

## 4. Dependencies
- Canonical `WordPress/mcp-adapter` package.
- Sitevero Universal Core Dispatcher.

---

## 5. Explicitly Out of Scope
- Exposing 50+ granular tools or version-specific tools (`elementor_v3_edit`, `elementor_v4_edit`).
- Direct binary image streaming over MCP sockets (standard upload and URL sideloading used).
- Asynchronous background polling tools or agent-controlled cron schedulers.

---

## 6. Resolved Decisions
- *Decision 5.1: Tool Naming Convention & Set:* **RESOLVED.** Exactly 4 universal tools in snake_case (`sitevero_discover`, `sitevero_inspect`, `sitevero_execute`, `sitevero_rollback`). All version-specific handling (Elementor V3 vs V4 vs Hybrid) is routed internally within `elementor.manage_page`.
