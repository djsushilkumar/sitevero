# Sitevero — Gutenberg Specification

## 1. Objective & Scope
The Gutenberg module empowers AI agents to inspect, generate, and edit block-based content and templates within WordPress. Gutenberg structures content using serialized HTML comment grammar:
```html
<!-- wp:paragraph {"fontSize":"medium"} -->
<p class="has-medium-font-size">Example paragraph content.</p>
<!-- /wp:paragraph -->
```
Sitevero leverages WordPress core block functions (`parse_blocks()`, `serialize_blocks()`, `render_block()`) to convert raw post content into structured JSON arrays for agent inspection and compiles structured agent block definitions back into standard WordPress block markup.

---

## 2. In-Scope Gutenberg Features (MVP)

### 2.1 Supported Core Blocks
The MVP supports the most common structural and content blocks:
- **`core/paragraph`**: `content`, `fontSize`, `textColor`, `backgroundColor`, `align`.
- **`core/heading`**: `content`, `level` (1–6), `align`, `textColor`, `fontSize`.
- **`core/image`**: `id` (attachment ID), `url`, `alt`, `caption`, `sizeSlug` (`full`, `large`, `medium`), `aspectRatio`, `scale`.
- **`core/buttons` & `core/button`**: `text`, `url`, `linkTarget`, `backgroundColor`, `textColor`, `borderRadius`, `layout` (justify, orientation).
- **`core/columns` & `core/column`**: Multi-column layouts, column widths (`width` percentage/px), `isStackedOnMobile`.
- **`core/group`**: Layout containers (flex, grid, or flow), `tagName` (`div`, `section`, `article`), background color, padding/margin dimensions.
- **`core/list` & `core/list-item`**: Ordered / unordered lists, items content.

### 2.2 Block Patterns
- **Discovery:** Query all patterns registered in `WP_Block_Patterns_Registry::get_instance()->get_all_registered()`.
- **Pattern Filtering:** Filter by category (e.g., `featured`, `header`, `call-to-action`, `pages`).
- **Insertion:** Insert a registered block pattern by its slug into an existing or new page at a specified position (`append`, `prepend`, or `replace`).

### 2.3 Template & Site Editing (Basic)
- **Classic Themes:** Assign page templates (e.g., `template-fullwidth.php`, `default`) via `_wp_page_template` postmeta.
- **Block Themes (Full Site Editing):**
  - Query registered `wp_template` and `wp_template_part` entities.
  - Read and update block content of user-customized templates in the active block theme.

---

## 3. Data Representation & Translation

### 3.1 Inspection Payload (Parsed Blocks)
When an agent calls `sitevero_inspect(target="entity_state", entity_type="gutenberg_blocks", entity_id="42")`, Sitevero runs `parse_blocks($post->post_content)` and returns a sanitized JSON array:

```json
{
  "entity_type": "gutenberg_blocks",
  "entity_id": "42",
  "blocks": [
    {
      "name": "core/heading",
      "attributes": {
        "level": 2,
        "content": "Why Choose Our Service",
        "textAlign": "center"
      }
    },
    {
      "name": "core/columns",
      "attributes": { "columns": 2 },
      "innerBlocks": [
        {
          "name": "core/column",
          "attributes": { "width": "50%" },
          "innerBlocks": [
            {
              "name": "core/paragraph",
              "attributes": { "content": "Fast, reliable, and secure." }
            }
          ]
        },
        {
          "name": "core/column",
          "attributes": { "width": "50%" },
          "innerBlocks": [
            {
              "name": "core/paragraph",
              "attributes": { "content": "Integrated directly with your workflow." }
            }
          ]
        }
      ]
    }
  ]
}
```

### 3.2 Compilation & Granular Sanitization Pipeline
When an agent calls `sitevero_execute` with `capability_id="gutenberg.manage_blocks"` and `action="update"`, Sitevero follows a structured schema-aware sanitization pipeline rather than running a blanket string filter across the entire document:
1. **Validate Block Types:** Verifies that every block name exists in the registered block types registry (`WP_Block_Type_Registry::get_instance()`). Unknown block types are either preserved as opaque HTML or rejected safely.
2. **Validate & Sanitize Attributes:** Validates block attributes against their declared block type schema (e.g., ensuring `level` is an integer between 1 and 6, URLs are valid via `esc_url_raw()`, and CSS class names are sanitized via `sanitize_html_class()`).
3. **Sanitize Content Fields:** User-provided text/content strings (e.g., paragraph inner content, heading text) are sanitized according to contextual rules (allowing safe inline formatting like `<strong>`, `<em>`, `<a>`, `<code>`, while stripping executable scripts, `<iframe>`, and event handlers).
4. **Build Block Tree:** Assembles the normalized array of valid block objects with clean attributes and inner content.
5. **Serialize Blocks:** Invokes core `serialize_blocks($blocks)` to generate standard WordPress HTML block comment grammar.
6. **Save via WordPress Core API:** Saves the post content via `wp_update_post()`.
7. **Post-Save Validation:** Executes `parse_blocks()` on the newly saved post to verify block structure integrity and element counts.

---

## 4. Execution Workflow

```
[Agent JSON Block Tree] 
        |
        v
[Validate Registered Block Types (WP_Block_Type_Registry)]
        |
        v
[Validate & Sanitize Attributes Against Block Schema]
        |
        v
[Sanitize Content Fields (Strip Scripts/Iframes/Event Handlers)]
        |
        v
[Capture Pre-Execution Snapshot of existing post_content (2MB limit)]
        |
        v
[Assemble Block Nodes & Serialize: serialize_blocks()]
        |
        v
[Save Post Content via Core API: wp_update_post()]
        |
        v
[Validate Internally: parse_blocks() on newly saved content]
        |
        v
[Return Validated Block Count & Snapshot UUID]
```

---

## 5. Explicitly Out of Scope
- Custom block registration or JavaScript block bundle compilation on the fly.
- Arbitrary inline `<script>` tags, executable JavaScript, or script injection (strictly rejected/stripped).
- Blanket post-serialization string sanitization that damages valid block comment syntax or attributes.
- Complex custom block plugins (e.g., Kadence, Spectra, Stackable) beyond standard core blocks.
- Global theme style switching (`theme.json` raw editing excluded from MVP).

---

## 6. Dependencies
- WordPress Core: Latest stable WordPress version + previous major WordPress version.
- PHP Runtime: PHP 8.1+.
- Core block library (`wp-includes/blocks/`).

---

## 7. Resolved Decisions
- *Decision 8.1: Granular Block Sanitization Pipeline:* **RESOLVED.** Sitevero employs a schema-aware attribute and content sanitization pipeline before serialization. It validates block types against `WP_Block_Type_Registry`, sanitizes attributes against their schemas, and sanitizes content fields, preventing arbitrary code execution while preserving valid block comment markup, attributes, and safe inline HTML (`<strong>`, `<em>`, `<a>`, `<code>`). Blanket string stripping across the entire serialized document is strictly avoided.
