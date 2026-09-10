# Sitevero — Gate G Verification Report

**Verification Date:** September 10, 2026  
**Evaluator:** Antigravity AI Assistant & Lead Technical Reviewer  
**Target Environment:** Live WordPress Staging Instance (`https://bradhive.in`)  
**Specification Source of Truth:** `docs/15_mvp_release_checklist.md` (Gate G: AI Client Compatibility)  

---

## 1. Executive Summary

A strict, evidence-based verification of **Gate G (AI Client Compatibility)** was conducted against the live connected WordPress staging site (`https://bradhive.in`). All four Universal MCP Tools (`sitevero_discover`, `sitevero_inspect`, `sitevero_execute`, `sitevero_rollback`) were systematically invoked, executed, and verified end-to-end through live Model Context Protocol (MCP) tool calls.

---

## 2. Gate G Verification Matrix

| Test | Status | Evidence |
|---|---|---|
| **Antigravity connection** | **PASS** | Initialized MCP protocol over stdio bridge proxy; JSON-RPC handshake succeeded; tools/list returned exactly 4 universal meta-tools. |
| **Antigravity discover** | **PASS** | `sitevero_discover` executed live. Accurately detected WordPress 7.1, PHP 8.3.26, Hello Elementor theme, Elementor V4 Atomic (`v4.3.0-beta1`, Pro `4.2.2`), Gutenberg active, 8 registered capabilities, and `devadmin` administrator context. |
| **Antigravity inspect** | **PASS** | `sitevero_inspect` executed live in dual modes: schema mode for `content.manage_post` & `elementor.manage_page`; entity mode for live site settings and live post states. |
| **Antigravity execute** | **PASS** | `sitevero_execute` executed safe, reversible mutation updating Post #655 from `"Sitevero Gate G Verification Test"` to `"Sitevero Gate G MUTATED Title [State B]"`. Pre-execution snapshot `snp_a429714591c849b27cf106d7c278a8bd` captured automatically. Destructive deletion safely halted by HMAC confirmation gate. |
| **Antigravity rollback** | **PASS** | `sitevero_rollback` executed using snapshot UUID `snp_a429714591c849b27cf106d7c278a8bd`. Returned `restoration_status: fully_restored`. Subsequent inspection proved post title and content 100% restored to initial state. |
| **Claude Desktop** | **NOT TESTED** | External desktop client unavailable (Claude Desktop application and `claude_desktop_config.json` not installed on the Windows host machine). |
| **Cursor** | **NOT TESTED** | External desktop client unavailable (Cursor editor binary and `.cursor` environment not installed on the Windows host machine). |

---

## 3. Detailed Evidence Log (Antigravity on Live WordPress)

### 3.1 Live Site & Server Information
- **WordPress Staging URL:** `https://bradhive.in`
- **Authenticated User:** `devadmin` (User ID: 1, Role: `administrator`)
- **Server Environment:** PHP 8.3.26, WordPress 7.1
- **Active Builders:** Elementor Core 4.3.0-beta1, Elementor Pro 4.2.2 (Generation: `v4`), Gutenberg (active)
- **Exposed MCP Tools:** Exactly 4 Universal Meta-Tools:
  1. `sitevero_discover`
  2. `sitevero_inspect`
  3. `sitevero_execute`
  4. `sitevero_rollback`

### 3.2 Tool 1: `sitevero_discover`
**MCP Tool Request:**
```json
{
  "ServerName": "sitevero",
  "ToolName": "sitevero_discover",
  "Arguments": {
    "include_all": true
  }
}
```
**Live Result Snippet:**
```json
{
  "environment": {
    "wordpress_version": "7.1",
    "php_version": "8.3.26",
    "multisite": false,
    "active_theme": {
      "name": "Hello Elementor",
      "stylesheet": "hello-elementor",
      "is_block_theme": false
    },
    "installed_plugins": {
      "active_count": 8,
      "inactive_count": 0,
      "active": [
        "emcp-tools/emcp-tools.php",
        "elementor/elementor.php",
        "google-site-kit/google-site-kit.php",
        "pro-elements/pro-elements.php",
        "safe-svg/safe-svg.php",
        "seo-by-rank-math/rank-math.php",
        "sitevero/sitevero.php",
        "wp-reset/wp-reset.php"
      ]
    }
  },
  "builders": {
    "elementor": {
      "installed": true,
      "active": true,
      "version": "4.3.0-beta1",
      "is_pro": true,
      "pro_version": "4.2.2",
      "generation": "v4"
    },
    "gutenberg": {
      "active": true,
      "block_theme": false
    }
  },
  "capabilities": [
    { "id": "content.manage_post", "enabled": true, "available": true },
    { "id": "media.manage", "enabled": true, "available": true },
    { "id": "elementor.manage_page", "enabled": true, "available": true },
    { "id": "gutenberg.manage_blocks", "enabled": true, "available": true },
    { "id": "system.manage_settings", "enabled": true, "available": true },
    { "id": "system.manage_plugins", "enabled": true, "available": true },
    { "id": "system.manage_themes", "enabled": true, "available": true },
    { "id": "users.manage", "enabled": true, "available": true }
  ],
  "user_context": {
    "user_id": 1,
    "user_login": "devadmin",
    "roles": ["administrator"],
    "can_manage_options": true,
    "can_edit_posts": true
  }
}
```

### 3.3 Tool 2: `sitevero_inspect`
**Live Inspection A: Capability Schema (`elementor.manage_page`)**
```json
{
  "target": "schema",
  "capability_id": "elementor.manage_page",
  "supported_actions": ["inspect", "create", "update", "clear_cache"],
  "schema": {
    "type": "object",
    "properties": {
      "action": { "type": "string", "enum": ["inspect", "create", "update", "clear_cache"] },
      "page_id": { "type": "integer" },
      "element_id": { "type": "string" },
      "elements": { "type": "array" },
      "settings": { "type": "object" },
      "classes": { "type": "array", "items": { "type": "string" } },
      "variables": { "type": "object" }
    },
    "required": ["action"]
  }
}
```

**Live Inspection B: Live Entity State (`settings`)**
```json
{
  "entity_type": "settings",
  "settings": {
    "blogname": "Bradhive",
    "blogdescription": "High-Performance WordPress &amp; Elementor Studio",
    "show_on_front": "page",
    "page_on_front": "274",
    "timezone_string": "Asia/Kolkata",
    "date_format": "F j, Y",
    "time_format": "g:i a",
    "permalink_structure": "/%postname%/"
  }
}
```

### 3.4 Tool 3: `sitevero_execute` (Safe Mutation & State Verification)
**Step 1: Create Initial Test Draft Post (#655)**
- **Post Title:** `"Sitevero Gate G Verification Test"`
- **Post Content:** `"Initial verification content for Gate G evidence."`
- **Creation Snapshot UUID:** `snp_7fc0ffd75adff00afc50d1b5b0065db4`

**Step 2: Inspect Initial State (Pre-Mutation)**
```json
{
  "entity_type": "post",
  "entity_id": 655,
  "post": {
    "ID": 655,
    "post_title": "Sitevero Gate G Verification Test",
    "post_status": "draft",
    "post_content": "Initial verification content for Gate G evidence."
  }
}
```

**Step 3: Execute Reversible Mutation**
```json
{
  "capability_id": "content.manage_post",
  "action": "update",
  "parameters": {
    "action": "update",
    "post_id": 655,
    "data": {
      "post_title": "Sitevero Gate G MUTATED Title [State B]",
      "post_content": "Mutated content during Gate G execution test."
    }
  }
}
```
**Execution Response:**
```json
{
  "status": "success",
  "post_id": 655,
  "updated_fields": ["post_title", "post_content"],
  "message": "Post #655 updated successfully.",
  "snapshot_uuid": "snp_a429714591c849b27cf106d7c278a8bd"
}
```

**Step 4: Inspect Mutated State (Post-Mutation)**
```json
{
  "entity_type": "post",
  "entity_id": 655,
  "post": {
    "ID": 655,
    "post_title": "Sitevero Gate G MUTATED Title [State B]",
    "post_status": "draft",
    "post_content": "Mutated content during Gate G execution test."
  }
}
```

### 3.5 Tool 4: `sitevero_rollback`
**MCP Tool Request:**
```json
{
  "ServerName": "sitevero",
  "ToolName": "sitevero_rollback",
  "Arguments": {
    "snapshot_uuid": "snp_a429714591c849b27cf106d7c278a8bd"
  }
}
```
**Rollback Response:**
```json
{
  "status": "completed",
  "restoration_status": "fully_restored",
  "snapshot_uuid": "snp_a429714591c849b27cf106d7c278a8bd",
  "entity_type": "post",
  "entity_id": "655",
  "message": "Post #655 fully restored from snapshot."
}
```

### 3.6 Post-Rollback State Inspection (Proof of Restoration)
**Inspection Response:**
```json
{
  "entity_type": "post",
  "entity_id": 655,
  "post": {
    "ID": 655,
    "post_title": "Sitevero Gate G Verification Test",
    "post_status": "draft",
    "post_content": "Initial verification content for Gate G evidence."
  }
}
```
*Result: The post title and content returned exactly to their pre-mutation values.*

### 3.7 Confirmation Gate & Clean-up Verification
To verify safety gating against destructive actions:
1. Called `content.manage_post` `delete` without confirmation token.
2. The gate halted execution and returned:
   ```json
   {
     "confirmation_required": true,
     "confirmation_token": "4758cea397fb0ef7e88b2cd09ed028cf58b8ce5a19cdaaf8785ecb0c31a8451e",
     "risk_level": "destructive",
     "expires_in_seconds": 300,
     "message": "Operation 'delete' on 'content.manage_post' requires confirmation. Pass 'confirmation_token' within 5 minutes to proceed."
   }
   ```
3. Passed the HMAC token with `force: true` to delete the temporary test post. Post was permanently deleted, leaving the staging database in its original clean state.

---

## 4. Failures & Corrective Fixes

- **Failure 1 (NPM Package 404):** Initial client config referenced `@automattic/mcp-adapter` on npm (non-existent). Resolved by switching client config to stdio Node bridge (`sitevero-proxy.mjs`) and direct HTTP endpoint.
- **Failure 2 (JSON-RPC Notification Mismatch):** When MCP clients sent `notifications/initialized` without an `id`, previous server handler returned code `-32601` (`Method not found`). Fixed in `inc/Mcp/AdapterBridge.php` and `sitevero-proxy.mjs` to absorb notifications silently (HTTP 204 No Content).
- **Failure 3 (Base64 Password Placeholder):** Client config had placeholder string `YOUR_APPLICATION_PASSWORD` in the base64 token. Replaced with valid base64 token for `devadmin:ODkmVNZ9pydGdKavs8GWvDCE`.

---

## 5. Documented Limitations

1. **Claude Desktop Testing:** Marked as **`NOT TESTED — external desktop client unavailable`** because the Claude Desktop native Windows binary was not installed in the local execution environment.
2. **Cursor Testing:** Marked as **`NOT TESTED — external desktop client unavailable`** because the Cursor native editor binary was not installed in the local execution environment.
3. Both desktop clients use standard MCP JSON-RPC transports (stdio and SSE/Streamable HTTP) which have been verified through the Node proxy and direct REST endpoints. Physical UI verification in those desktop applications remains pending local installation of those clients.

---

## 6. Final Status

```text
GATE G COMPLETE WITH DOCUMENTED LIMITATIONS
```

- **Antigravity:** 100% PASS with live verifiable evidence across all 4 universal meta-tools (`sitevero_discover`, `sitevero_inspect`, `sitevero_execute`, `sitevero_rollback`), snapshots, confirmation gates, and state restoration.
- **Claude Desktop & Cursor:** Documented limitations due to external desktop client absence on host machine.
