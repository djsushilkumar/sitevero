# Sitevero — Compatibility Requirements Specification

## 1. Core Environmental Matrix

Sitevero strictly defines its platform compatibility based on a rolling support policy rather than fixed legacy version numbers. 

> **Important Distinction:**
> - **Supported Policy / Architectural Commitment:** The architectural standard and contract Sitevero is built and guaranteed to support.
> - **Target Test & CI Verification Matrix:** The concrete environment configurations scheduled for verification during implementation and CI test execution (Stage 7–8). *Note: As the project is currently pre-code, these represent target test configurations rather than pre-verified builds.*

| Component | Supported Policy / Range | Target Test & CI Verification Matrix | Unsupported / Incompatible |
|---|---|---|---|
| **WordPress Core** | Latest stable major version + previous major version (Self-hosted single-site) | WordPress 6.6.x, WordPress 6.7.x | Versions older than previous major; WordPress.com managed simple sites |
| **PHP Runtime** | PHP 8.1 or higher | PHP 8.1, PHP 8.2, PHP 8.3 | PHP < 8.1 (Lacks typed enums/properties) |
| **Database** | MySQL 5.7+ or MariaDB 10.3+ | MySQL 8.0+, MariaDB 10.11+ | MySQL < 5.6 |
| **Web Server** | Apache 2.4+, Nginx 1.20+, LiteSpeed | Nginx 1.24+, Apache 2.4.58 | Windows IIS (untested) |
| **Architecture** | Standard Single-Site WordPress | LocalWP, Docker, Linux VPS | WordPress Multisite Networks (WPMU) |

---

## 2. Builder Architecture & Compatibility Policy

### 2.1 Elementor Builder Compatibility
Rather than tying Sitevero to an arbitrary or transient minor version string, compatibility is structured around **Elementor Architecture Generations**:

| Elementor Generation | Supported Architectural Models | Target Test & CI Verification Matrix | Operational Policy |
|---|---|---|---|
| **Elementor V3** | Sections, Columns, Flexbox Containers, Core Widgets, typography, colors, spacing, responsive controls | Elementor 3.24.x, 3.25.x | Fully supported for inspection, editing, and new container-based content creation. Legacy sections preserved without forced migration. |
| **Elementor V4** | Atomic Elements (`e-div-block`, `e-flexbox`, `e-grid`), Global Classes, Variables (design tokens), Atomic styling, responsive behavior | Official Elementor V4 Atomic releases | Supported for inspection, atomic creation, class assignment, variable binding, and responsive styling. |
| **Elementor Hybrid** | Coexistence of V3 and V4 elements on the same page | Official Elementor V4 environments running hybrid pages | Fully supported. Dispatches mutations to appropriate sub-engines without cross-converting models. |
| **Elementor Free / Pro**| Free and Pro detection | Elementor Core (Free) & Elementor Pro | Pro is safely detected. Read-only inspection allowed for existing Pro widgets. No Pro bundling; no unsupported Pro creation promised. |

### 2.2 Gutenberg (Block Editor) Compatibility
- **Supported Policy:** Core block editor bundled with supported WordPress versions (latest stable + previous major).
- **Target Test & CI Matrix:** Bundled Block Editor in WordPress 6.6 and 6.7.
- **Theme Compatibility:**
  - Classic Themes with block editor support (e.g., Astra, GeneratePress, Hello Elementor).
  - Block Themes with Full Site Editing support (e.g., Twenty Twenty-Four).

---

## 3. MCP Client & AI Agent Compatibility Matrix

| AI Client | Interface Type | Supported OS | Supported Features |
|---|---|---|---|
| **Antigravity** | Local MCP Bridge / STDIO / HTTP | Windows, macOS, Linux | Full 4-tool lifecycle (discover, inspect, execute, rollback) |
| **Claude Desktop** | MCP Config (JSON-RPC over stdio/HTTP) | macOS, Windows | Full tool lifecycle with interactive risk confirmations |
| **Claude Code** | CLI MCP transport | macOS, Linux, WSL | Full tool lifecycle |
| **Cursor** | MCP configuration | Windows, macOS, Linux | Full tool lifecycle |

---

## 4. Dependencies
- Standard WordPress REST API enabled (`/wp-json/`).
- Pretty permalinks enabled (`/%postname%/`).
- PHP Extensions: `curl`, `json`, `mbstring`, `openssl`, `simplexml`.
- Official `WordPress/mcp-adapter` package.

---

## 5. Explicitly Out of Scope
- WooCommerce and e-commerce plugin architectures.
- WordPress Multisite Networks (WPMU).
- WordPress.com Managed Platform hosting.
- Third-party builder add-ons or custom field plugins (ACF, JetEngine, etc.).
- PHP 7.4 or 8.0 backwards compatibility.
- Headless WordPress configurations.

---

## 6. Resolved Decisions
- *Decision 14.1: Architecture-First Compatibility Policy:* **RESOLVED.** Compatibility is defined by architecture generation (Elementor V3, V4 Atomic, Hybrid, Gutenberg) and rolling WordPress policy (latest stable + previous major version), cleanly separating supported architectural criteria from specific tested build versions.
