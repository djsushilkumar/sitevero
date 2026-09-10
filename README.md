# sitevero

**Sitevero** is a Universal WordPress AI/MCP Plugin that allows MCP-compatible AI agents (such as Antigravity, Cursor, and Claude Desktop) to safely interact with, manage, and build on WordPress.

Sitevero uses the official WordPress MCP Adapter (`@automattic/mcp-adapter`) as its transport foundation and exposes 4 Universal Meta-Tools with a built-in safety architecture:
1. `sitevero_discover`: Runtime environment, active builders, capabilities catalog, and user permissions discovery.
2. `sitevero_inspect`: Dual-mode schema and live WordPress/builder entity inspection with strict credential protection.
3. `sitevero_execute`: Safety-gated execution with 4-tier risk classification, 5-minute cryptographic HMAC confirmation gate, 2MB snapshot limits, and 50-item bulk safety ceilings.
4. `sitevero_rollback`: Automated single-step state restoration with honest capability-specific status reporting (`fully_restored`, `partially_restored`, `not_restorable`).

## Features

- **Elementor Multi-Generation Builder Engine**: Seamless support for Elementor V3 Containers/Widgets, V4 Atomic Elements (`e-flexbox`, `e-grid`, `e-div-block`) with design token binding (`e-var:*`), Hybrid coexistence, and Pro safety gating.
- **Gutenberg Block Engine**: Standard Gutenberg comment grammar serializer/parser with granular schema-aware pre-serialization sanitization, pattern insertion, and template management.
- **WordPress Core Capabilities**: Content CRUD, Media Library with safe in-place replacement, Plugins & Themes lifecycle via official Core Upgrader APIs (strictly zero manual filesystem extraction), Users management with Zero Privilege Escalation, and whitelisted Settings control.
- **Native Admin Dashboard**: Intuitive WP-Admin dashboard for live capability toggles, 30-day activity audit logging, snapshot inspection, and manual rollback.

## Requirements

- WordPress 6.5+
- PHP 8.1+
- Official WordPress MCP Adapter (`WordPress/mcp-adapter`)

## License

GPL-2.0-or-later
