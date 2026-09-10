<?php

declare(strict_types=1);

/**
 * Deterministic CLI verification runner for Phase 4 Gutenberg Block Engine & Sanitization Pipeline.
 */

// 1. Setup mock functions if running standalone outside WordPress
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    $GLOBALS['mock_posts'] = [
        1 => [
            'ID'           => 1,
            'post_title'   => 'Sample Gutenberg Post',
            'post_content' => "<!-- wp:heading {\"level\":2} -->\n<h2>Initial Title</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Initial paragraph.</p>\n<!-- /wp:paragraph -->",
        ],
    ];
    $GLOBALS['mock_postmeta'] = [];

    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
    function register_activation_hook(string $file, callable $callback): void {}
    function register_deactivation_hook(string $file, callable $callback): void {}
    function plugin_dir_path(string $file): string { return dirname(__DIR__) . DIRECTORY_SEPARATOR; }
    function plugin_dir_url(string $file): string { return 'http://example.com/wp-content/plugins/sitevero/'; }
    function esc_html__(string $text, string $domain = 'default'): string { return $text; }
    function current_time(string $type): string { return date('Y-m-d H:i:s'); }
    function wp_salt(string $scheme = 'auth'): string { return 'deterministic_test_salt'; }
    function get_option(string $option, mixed $default = false): mixed { return $default; }
    function is_multisite(): bool { return false; }
    function get_bloginfo(string $show = ''): string { return '6.7.1'; }

    function get_post(int $postId, string $output = 'OBJECT'): ?array {
        return $GLOBALS['mock_posts'][$postId] ?? null;
    }

    function wp_update_post(array $postArr): int {
        $id = (int) ($postArr['ID'] ?? 0);
        if (isset($GLOBALS['mock_posts'][$id])) {
            $GLOBALS['mock_posts'][$id] = array_merge($GLOBALS['mock_posts'][$id], $postArr);
        }
        return $id;
    }

    function get_post_meta(int $postId, string $key = '', bool $single = false): mixed {
        if (!empty($key)) {
            return $GLOBALS['mock_postmeta'][$postId][$key] ?? ($single ? '' : []);
        }
        return $GLOBALS['mock_postmeta'][$postId] ?? [];
    }

    function update_post_meta(int $postId, string $key, mixed $value): bool {
        $GLOBALS['mock_postmeta'][$postId][$key] = $value;
        return true;
    }
}

// 2. Load Plugin Bootstrap
require_once __DIR__ . '/../sitevero.php';

use Sitevero\Capabilities\Gutenberg\BlockParser;
use Sitevero\Capabilities\Gutenberg\BlockSanitizer;
use Sitevero\Capabilities\Gutenberg\BlockSerializer;
use Sitevero\Capabilities\Gutenberg\BlockValidator;
use Sitevero\Capabilities\Gutenberg\GutenbergModule;
use Sitevero\Capabilities\Gutenberg\PatternManager;
use Sitevero\Capabilities\Gutenberg\TemplateManager;

echo "=========================================================\n";
echo "  Sitevero — Phase 4 Gutenberg Block Engine Test\n";
echo "=========================================================\n\n";

$errors = [];

$parser = new BlockParser();
$serializer = new BlockSerializer();
$validator = new BlockValidator();
$sanitizer = new BlockSanitizer($validator);
$patternManager = new PatternManager();
$templateManager = new TemplateManager();
$gutenbergModule = new GutenbergModule($parser, $serializer, $validator, $sanitizer, $patternManager, $templateManager);

// TEST 1: Block Parsing & Serialization Round-trip
echo "1. Testing Block Parsing and Serialization round-trip...\n";
$sampleMarkup = "<!-- wp:heading {\"level\":1} -->\n<h1>Page Header</h1>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>First paragraph.</p>\n<!-- /wp:paragraph -->";
$parsedBlocks = $parser->parse($sampleMarkup);

if (count($parsedBlocks) !== 2) {
    $errors[] = "Expected 2 parsed blocks, got " . count($parsedBlocks);
}
if (($parsedBlocks[0]['blockName'] ?? '') !== 'core/heading') {
    $errors[] = "First parsed block is not core/heading.";
}
if (($parsedBlocks[1]['blockName'] ?? '') !== 'core/paragraph') {
    $errors[] = "Second parsed block is not core/paragraph.";
}

$serializedOutput = $serializer->serialize($parsedBlocks);
if (!str_contains($serializedOutput, '<!-- wp:heading') || !str_contains($serializedOutput, '<!-- wp:paragraph')) {
    $errors[] = "Serialized output missing valid Gutenberg block comment delimiters.";
}
echo "   -> Parsing & round-trip serialization: PASSED\n";

// TEST 2: Granular Sanitization Pipeline (Preserving Formatting & Stripping Injections)
echo "2. Testing Granular Sanitization Pipeline (Script stripping vs. formatting preservation)...\n";
$dirtyBlocks = [
    [
        'blockName' => 'core/paragraph',
        'attrs'     => ['fontSize' => 'large', 'customData' => '<script>alert(1)</script>'],
        'innerHTML' => '<p>Valid <strong>bold</strong> and <em>italic</em> with <a href="https://sitevero.com">link</a>.<script>alert("xss")</script><iframe src="evil.com"></iframe><img src="x" onerror="alert(2)"></p>',
    ],
];

$cleanBlocks = $sanitizer->sanitizeBlocks($dirtyBlocks);
$cleanHtml = $cleanBlocks[0]['innerHTML'] ?? '';
$cleanAttrs = $cleanBlocks[0]['attrs'] ?? [];

// Assert formatting tags preserved
if (!str_contains($cleanHtml, '<strong>bold</strong>') || !str_contains($cleanHtml, '<em>italic</em>')) {
    $errors[] = "Sanitizer corrupted valid formatting markup (strong/em).";
}
if (!str_contains($cleanHtml, '<a href="https://sitevero.com">link</a>')) {
    $errors[] = "Sanitizer corrupted valid link markup.";
}

// Assert executable injection stripped
if (str_contains($cleanHtml, '<script>') || str_contains($cleanHtml, '<iframe') || str_contains($cleanHtml, 'onerror=')) {
    $errors[] = "SECURITY VIOLATION: Malicious script/iframe/event handler survived sanitization!";
}
if (str_contains(json_encode($cleanAttrs), '<script>')) {
    $errors[] = "SECURITY VIOLATION: Script tag survived inside block attributes!";
}

// Assert serialized comment structure remains intact without blanket post-serialization mangling
$cleanSerialized = $serializer->serialize($cleanBlocks);
if (!str_starts_with($cleanSerialized, '<!-- wp:paragraph')) {
    $errors[] = "Sanitization corrupted block comment syntax.";
}
echo "   -> Granular attribute and content sanitization: PASSED\n";

// TEST 3: Schema Validation
echo "3. Testing Schema Validation for Block Names and Attributes...\n";
$validCheck = $validator->validateBlocks([
    ['blockName' => 'core/heading', 'attrs' => ['level' => 2]],
    ['blockName' => 'core/paragraph', 'attrs' => []],
]);
if (!empty($validCheck)) {
    $errors[] = "Valid core blocks failed validation: " . implode(', ', $validCheck);
}

$invalidNameCheck = $validator->validateBlocks([
    ['blockName' => 'unregistered_hack_block', 'attrs' => []],
]);
if (empty($invalidNameCheck)) {
    $errors[] = "Validator failed to flag unregistered block name.";
}

$invalidLevelCheck = $validator->validateBlocks([
    ['blockName' => 'core/heading', 'attrs' => ['level' => 9]],
]);
if (empty($invalidLevelCheck)) {
    $errors[] = "Validator failed to flag invalid heading level 9.";
}
echo "   -> Block schema and attribute validation: PASSED\n";

// TEST 4: Pattern Manager Retrieval & Insertion
echo "4. Testing Block Pattern Manager...\n";
$patterns = $patternManager->getPatterns();
if (empty($patterns)) {
    $errors[] = "PatternManager returned empty pattern list.";
}

$patternContent = $patternManager->getPatternContent('sitevero/hero-card');
if (empty($patternContent) || !str_contains($patternContent, '<!-- wp:heading')) {
    $errors[] = "PatternManager failed to retrieve content for sitevero/hero-card.";
}

$insertResult = $gutenbergModule->execute('insert_pattern', [
    'post_id'      => 1,
    'pattern_name' => 'sitevero/hero-card',
    'position'     => 'append',
]);

if (($insertResult['status'] ?? '') !== 'success') {
    $errors[] = "Failed to execute insert_pattern on GutenbergModule.";
}
echo "   -> Block pattern discovery and insertion: PASSED\n";

// TEST 5: Template Management
echo "5. Testing Template Inspection & Assignment...\n";
$templateInspect = $gutenbergModule->execute('manage_template', ['post_id' => 1]);
if (!isset($templateInspect['available_templates']['default'])) {
    $errors[] = "manage_template inspect missing default template.";
}

$templateSet = $gutenbergModule->execute('manage_template', [
    'post_id'  => 1,
    'template' => 'full-width',
]);
if (($templateSet['status'] ?? '') !== 'success' || ($templateSet['template'] ?? '') !== 'full-width') {
    $errors[] = "manage_template failed to set template to 'full-width'.";
}
echo "   -> Page template management: PASSED\n";

echo "\n=========================================================\n";
if (!empty($errors)) {
    echo "FAILED with errors:\n";
    foreach ($errors as $err) {
        echo " - {$err}\n";
    }
    exit(1);
}

echo "SUCCESS: All Phase 4 Gutenberg block engine tests PASSED!\n";
echo "=========================================================\n";
exit(0);
