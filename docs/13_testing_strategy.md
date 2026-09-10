# Sitevero — Testing Strategy

## 1. Quality Objectives
The primary quality objective for the MVP is verifying the complete, deterministic loop:
```
AI Agent -> MCP Connection -> Discovery -> Execution -> Validation -> Rollback
```
Across 3 major AI agent environments: **Antigravity**, **Claude (Desktop/Code)**, and **Cursor**, with dedicated validation suites for **Elementor V3**, **Elementor V4**, and **Hybrid pages**.

---

## 2. Nine-Stage Testing Progression

Sitevero MVP testing strictly follows this 9-stage progression:

1. **Stage 1: Unit Tests** (PHPUnit: Core Dispatcher, Normalizers, Risk Assessor, Validators)
2. **Stage 2: WordPress Integration Tests** (DB migrations, Core APIs, postmeta, transients)
3. **Stage 3: Deterministic MCP Flow Tests** (PHP CLI test runner for the 4 universal tools)
4. **Stage 4: Antigravity Testing** (End-to-end tool calling, Elementor V3/V4 & Gutenberg page generation)
5. **Stage 5: Claude Testing** (Desktop/Code: Interactive risk confirmation and rollback)
6. **Stage 6: Cursor Testing** (Tool discovery, block pattern, and Atomic Element editing)
7. **Stage 7: Local Environments** (LocalWP, wp-env, Docker on Windows, macOS, Linux)
8. **Stage 8: Staging Environments** (Nginx, Apache, PHP 8.1, 8.2, 8.3)
9. **Stage 9: Controlled Beta Sites** (5–10 diverse WordPress sites; no production-site beta requirement)

---

## 3. Dedicated Elementor Test Suites

### 3.1 Elementor V3 Test Suite
- **TC-EL-V3-01 (Inspection):** Inspect existing V3 Section/Column and Flexbox Container pages; assert normalized tree contains widget settings, typography, and spacing tokens.
- **TC-EL-V3-02 (Creation):** Create new page using Flexbox Container with Heading, Text, and Button widgets.
- **TC-EL-V3-03 (Update & Styling):** Update container background color, widget typography (font size, weight), and padding.
- **TC-EL-V3-04 (Responsive Controls):** Apply responsive font size and padding overrides for tablet and mobile; verify proper CSS compilation.
- **TC-EL-V3-05 (Validation & Rollback):** Assert CSS cache clears on save; execute rollback using `snapshot_uuid` and verify complete restoration to pre-edit state.

### 3.2 Elementor V4 Atomic Test Suite
- **TC-EL-V4-01 (Atomic Inspection):** Inspect existing V4 page containing `e-flexbox`, `e-grid`, and `e-div-block`; assert classes and Elementor V4 design tokens are correctly parsed.
- **TC-EL-V4-02 (Atomic Creation):** Create new V4 page with `e-flexbox` container, atomic heading, and atomic button referencing Elementor V4 design tokens (e.g., `e-var:brand-primary`).
- **TC-EL-V4-03 (Classes & Styling):** Assign reusable classes (e.g., `hero-card`) and compile atomic CSS styles.
- **TC-EL-V4-04 (Responsive & Interactions):** Apply breakpoint styling and verify hover/focus interaction states.
- **TC-EL-V4-05 (Validation & Rollback):** Verify saved atomic elements in database; execute rollback and verify full state restoration (`restoration_status: "fully_restored"`).

### 3.3 Elementor Hybrid Test Suite
- **TC-EL-HYB-01 (Hybrid Detection):** Load page containing both V3 containers/widgets and V4 Atomic Elements; verify Sitevero detects `generation: "hybrid"`.
- **TC-EL-HYB-02 (Isolated V3 Mutation):** Modify a V3 widget on a hybrid page; verify V3 element is updated while adjacent V4 atomic structures remain completely untouched.
- **TC-EL-HYB-03 (Isolated V4 Mutation):** Modify a V4 atomic element on a hybrid page; verify V4 element is updated while adjacent V3 elements remain completely untouched.
- **TC-EL-HYB-04 (Non-Conversion):** Verify Sitevero does not convert V3 elements to V4 or V4 to V3 during hybrid updates.
- **TC-EL-HYB-05 (Hybrid Rollback):** Execute rollback and assert both V3 and V4 branches revert to their captured pre-execution states (`restoration_status: "fully_restored"`).

### 3.4 Elementor Free vs. Pro Test Suite
- **TC-EL-PRO-01 (Free Active):** Run `sitevero_discover` on Elementor Free environment; verify `is_pro_active: false` and Pro-only creation capabilities are omitted.
- **TC-EL-PRO-02 (Pro Active):** Run `sitevero_discover` on Elementor Pro environment; verify `is_pro_active: true`.
- **TC-EL-PRO-03 (Pro Inspection):** Inspect existing page containing Elementor Pro Form or Theme Builder widget; assert successful read-only inspection without schema error.
- **TC-EL-PRO-04 (Pro Creation Rejection):** Attempt creating a Pro-only widget; assert clean rejection with `ERR_ELEMENTOR_PRO_UNSUPPORTED`.

---

## 4. Core WordPress Capabilities Test Suites

### 4.1 Gutenberg Block Test Suite
- **TC-GUT-01 (Block Creation & Parsing):** Create block post with Paragraph, Heading, Image, Columns, and Buttons; verify valid HTML comment generation via `serialize_blocks()`.
- **TC-GUT-02 (Granular Sanitization):** Pass block with inline `<strong>` tags and invalid `<script>` attributes; assert safe formatting preserved while script injection is cleanly stripped.
- **TC-GUT-03 (Block Patterns):** Query block pattern registry and insert pattern by slug.
- **TC-GUT-04 (Rollback):** Execute rollback on modified block post; assert `restoration_status: "fully_restored"`.

### 4.2 Plugins & Themes Test Suite
- **TC-SYS-PLUG-01 (List & Inspect):** List installed plugins; verify version and update availability flags.
- **TC-SYS-PLUG-02 (Install WP.org):** Install plugin from official WordPress.org directory via standard `Plugin_Upgrader`; verify confirmation requirement and activation.
- **TC-SYS-PLUG-03 (Install ZIP):** Upload and install plugin from valid ZIP archive via `Plugin_Upgrader` without manual file extraction.
- **TC-SYS-PLUG-04 (Deactivate & Delete):** Deactivate and delete plugin under confirmation; assert snapshot captured.
- **TC-SYS-PLUG-05 (Deletion Rollback Limitation):** Execute rollback on deleted plugin; assert `restoration_status: "not_restorable"` with explanatory message.
- **TC-SYS-THM-01 (Switch & Delete):** Switch active theme and delete unused theme under confirmation.

### 4.3 Users & Roles Test Suite
- **TC-USR-01 (CRUD & Role):** Create user, update display name and role under confirmation.
- **TC-USR-02 (Delete User):** Permanently delete user under confirmation with post reassignment.
- **TC-USR-03 (Privilege Escalation Prevention):** Verify Contributor user cannot delete users or modify roles (`ERR_FORBIDDEN_WP_CAP`).
- **TC-USR-04 (Deletion Rollback Limitation):** Execute rollback on deleted user; assert `restoration_status: "not_restorable"`.

### 4.4 Media Library Test Suite
- **TC-MED-01 (Upload & Replace):** Upload image via `wp_handle_upload()`; replace media file safely on existing attachment ID.
- **TC-MED-02 (Metadata & Featured Image):** Update alt text and assign as featured image on post #42.
- **TC-MED-03 (Delete Media & Rollback):** Delete media attachment under confirmation; assert record removed; assert rollback reports `restoration_status: "not_restorable"`.

### 4.5 Common Settings & Bulk Actions Test Suite
- **TC-SET-01 (Settings Update & Rollback):** Update `blogname`, `show_on_front`, `page_on_front`, and `posts_per_page` under confirmation; rollback and assert `restoration_status: "fully_restored"`.
- **TC-BLK-01 (Bulk Update Safety):** Execute bulk category assignment on 40 posts (within 50-item limit); verify successful batch snapshot.
- **TC-BLK-02 (Bulk Limit Rejection):** Attempt bulk update on 60 items; verify clean rejection prompting batch chunking.

---

## 5. Dependencies
- PHPUnit 10+.
- `wp-env` or Docker-based WordPress testing environment.
- Official `WordPress/mcp-adapter` test harness.

---

## 6. Explicitly Out of Scope
- Automated visual regression testing via headless Chromium/Puppeteer.
- Production-site beta testing (testing is strictly bounded to local, staging, and 5–10 controlled beta sites).
- High-concurrency load testing (> 100 concurrent MCP agent requests).

---

## 7. Resolved Decisions
- *Decision 13.1: Deterministic MCP Mock Runner:* **RESOLVED.** A lightweight, standalone PHP CLI script (`bin/test-mcp-flow.php`) will be provided to exercise the complete 4-tool lifecycle (`sitevero_discover`, `sitevero_inspect`, `sitevero_execute`, `sitevero_rollback`) deterministically in CI and local environments without consuming paid external AI model tokens.
