# Sitevero — Universal WordPress AI/MCP Plugin Documentation

Welcome to the technical documentation repository for **Sitevero**, a Universal WordPress AI/MCP Plugin.

Sitevero enables MCP-compatible AI agents (Antigravity, Claude, Cursor) to safely interact with, configure, build, and maintain WordPress websites through the Official WordPress MCP Adapter (`WordPress/mcp-adapter`) and Sitevero's Universal Capability Core.

---

## Technical Audit & Readiness Report

* **[00. Technical Documentation Audit & Final Review](file:///c:/Users/420/Documents/Sitevero%20is%20a%20Universal%20WordPress%20AI%20MCP%20Plugin/docs/00_documentation_audit.md)** — Comprehensive architecture review, issue reconciliations, Elementor V3/V4/Hybrid verification, resolved decisions, and development readiness assessment (**STATUS: READY FOR DEVELOPMENT**).

---

## Complete Specification Index

1. **[01. Product Requirements](file:///c:/Users/420/Documents/Sitevero%20is%20a%20Universal%20WordPress%20AI%20MCP%20Plugin/docs/01_product_requirements.md)**
   - Vision, personas, core architecture, scope boundaries, Elementor V3/V4/Hybrid support, rolling version policy.
2. **[02. MVP Scope](file:///c:/Users/420/Documents/Sitevero%20is%20a%20Universal%20WordPress%20AI%20MCP%20Plugin/docs/02_mvp_scope.md)**
   - Definition of Done, functional inclusions (full plugin/theme, media, user deletion, settings), explicit non-goals, beta boundaries.
3. **[03. Functional Requirements](file:///c:/Users/420/Documents/Sitevero%20is%20a%20Universal%20WordPress%20AI%20MCP%20Plugin/docs/03_functional_requirements.md)**
   - 5-stage pipeline (Discovery, Inspection, Execution, Validation, Rollback), bulk safety thresholds, and module deep-dives.
4. **[04. Technical Requirements](file:///c:/Users/420/Documents/Sitevero%20is%20a%20Universal%20WordPress%20AI%20MCP%20Plugin/docs/04_technical_requirements.md)**
   - PSR-4 plugin structure, Elementor sub-engines, custom DB tables (`wp_sitevero_snapshots`, `wp_sitevero_activity`), read-only DB fallback.
5. **[05. MCP Specification](file:///c:/Users/420/Documents/Sitevero%20is%20a%20Universal%20WordPress%20AI%20MCP%20Plugin/docs/05_mcp_specification.md)**
   - The 4 Universal Meta-Tools (`sitevero_discover`, `sitevero_inspect`, `sitevero_execute`, `sitevero_rollback`) with full JSON schemas and internal V3/V4 routing.
6. **[06. Capability Specification](file:///c:/Users/420/Documents/Sitevero%20is%20a%20Universal%20WordPress%20AI%20MCP%20Plugin/docs/06_capability_specification.md)**
   - Capability Registry, interface contracts, module specifications (Content, Media, Elementor, Gutenberg, Plugins, Themes, Users, Settings).
7. **[07. Elementor Specification](file:///c:/Users/420/Documents/Sitevero%20is%20a%20Universal%20WordPress%20AI%20MCP%20Plugin/docs/07_elementor_specification.md)**
   - Elementor V3 (Containers/widgets), Elementor V4 (Atomic Elements, classes, variables), Hybrid coexistence, Universal Normalizer, and Free/Pro detection.
8. **[08. Gutenberg Specification](file:///c:/Users/420/Documents/Sitevero%20is%20a%20Universal%20WordPress%20AI%20MCP%20Plugin/docs/08_gutenberg_specification.md)**
   - Core block grammar parsing/serialization, Block Patterns registry, and basic template editing.
9. **[09. Security Specification](file:///c:/Users/420/Documents/Sitevero%20is%20a%20Universal%20WordPress%20AI%20MCP%20Plugin/docs/09_security_specification.md)**
   - Authority model, prohibition of arbitrary PHP/JS/CSS/SQL, controlled read-only DB fallback, confirmation gate, and internal cron boundaries.
10. **[10. Permissions](file:///c:/Users/420/Documents/Sitevero%20is%20a%20Universal%20WordPress%20AI%20MCP%20Plugin/docs/10_permissions.md)**
    - Role-to-capability matrix, zero privilege escalation, and admin capability toggles.
11. **[11. Activity Log and Rollback](file:///c:/Users/420/Documents/Sitevero%20is%20a%20Universal%20WordPress%20AI%20MCP%20Plugin/docs/11_activity_log_and_rollback.md)**
    - Entity delta snapshots (2MB cap), single-step rollback routines, and 30-day retention pruning.
12. **[12. Admin Dashboard Requirements](file:///c:/Users/420/Documents/Sitevero%20is%20a%20Universal%20WordPress%20AI%20MCP%20Plugin/docs/12_admin_dashboard_requirements.md)**
    - Native WP Admin interface, MCP connection monitor, capability toggles, activity log table, and snapshot rollback UI.
13. **[13. Testing Strategy](file:///c:/Users/420/Documents/Sitevero%20is%20a%20Universal%20WordPress%20AI%20MCP%20Plugin/docs/13_testing_strategy.md)**
    - 9-stage testing progression, dedicated Elementor V3/V4/Hybrid/Free/Pro test suites, PHPUnit, and AI client matrix (Antigravity, Claude, Cursor).
14. **[14. Compatibility Requirements](file:///c:/Users/420/Documents/Sitevero%20is%20a%20Universal%20WordPress%20AI%20MCP%20Plugin/docs/14_compatibility_requirements.md)**
    - Policy-based compatibility (latest stable + previous major WP, PHP 8.1+), architectural separation of supported vs. tested versions.
15. **[15. MVP Release Checklist](file:///c:/Users/420/Documents/Sitevero%20is%20a%20Universal%20WordPress%20AI%20MCP%20Plugin/docs/15_mvp_release_checklist.md)**
    - Comprehensive release gates (A through H) for tagging `v1.0.0-beta`.
