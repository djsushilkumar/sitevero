<?php

declare(strict_types=1);

/**
 * Deterministic CLI verification runner for Phase 3 Elementor Multi-Generation Builder Engine.
 */

// 1. Setup mock functions if running standalone outside WordPress
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');

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
    function parse_blocks(string $content): array { return []; }
    function serialize_blocks(array $blocks): string { return ''; }

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

use Sitevero\Capabilities\Elementor\ElementorModule;
use Sitevero\Capabilities\Elementor\Engines\HybridEngine;
use Sitevero\Capabilities\Elementor\Engines\V3Engine;
use Sitevero\Capabilities\Elementor\Engines\V4Engine;
use Sitevero\Capabilities\Elementor\FreeProDetector;
use Sitevero\Capabilities\Elementor\Normalizer;

echo "=========================================================\n";
echo "  Sitevero — Phase 3 Elementor Multi-Generation Test\n";
echo "=========================================================\n\n";

$errors = [];
$normalizer = new Normalizer();
$v3Engine = new V3Engine($normalizer);
$v4Engine = new V4Engine($normalizer);
$hybridEngine = new HybridEngine($normalizer, $v3Engine, $v4Engine);
$elementorModule = new ElementorModule();

// TEST 1: V3 Engine Creation & Updating
echo "1. Testing V3 Engine (Containers & Widgets)...\n";
$heading = $v3Engine->buildHeading('Welcome to Sitevero', 'h1', ['title_color' => '#111827']);
$button = $v3Engine->buildButton('Get Started', 'https://example.com');
$container = $v3Engine->buildContainer([$heading, $button], ['direction' => 'column', 'gap' => '20px']);

if (($container['elType'] ?? '') !== 'container') {
    $errors[] = "V3 container elType is not 'container'.";
}
if (($container['elements'][0]['widgetType'] ?? '') !== 'heading') {
    $errors[] = "V3 first child widgetType is not 'heading'.";
}

$headingId = $container['elements'][0]['id'];
[$updatedContainerList, $wasUpdated] = $v3Engine->updateElementSettings([$container], $headingId, ['title' => 'Updated Heading Title']);
if (!$wasUpdated || ($updatedContainerList[0]['elements'][0]['settings']['title'] ?? '') !== 'Updated Heading Title') {
    $errors[] = "V3 updateElementSettings failed to update heading title.";
}
echo "   -> V3 Container & Widget construction and updates: PASSED\n";

// TEST 2: V4 Atomic Engine & Design Token Enforcement
echo "2. Testing V4 Atomic Engine & Design Token Enforcement...\n";
$atomicDiv = $v4Engine->buildAtomicDiv([], ['card-body'], ['spacing' => 'e-var:space-md']);
$atomicFlex = $v4Engine->buildAtomicFlexbox(
    [$atomicDiv],
    ['hero-card', 'shadow-md'],
    ['color' => 'e-var:brand-primary', 'spacing' => 'e-var:space-lg'],
    ['gap' => '1.5rem']
);

if (($atomicFlex['elType'] ?? '') !== 'e-flexbox') {
    $errors[] = "V4 atomic flexbox elType is not 'e-flexbox'.";
}
if (!in_array('hero-card', $atomicFlex['classes'] ?? [], true)) {
    $errors[] = "V4 atomic flexbox missing 'hero-card' class.";
}
if (($atomicFlex['props']['variables']['color'] ?? '') !== 'e-var:brand-primary') {
    $errors[] = "V4 atomic flexbox design token 'color' not set to e-var:brand-primary.";
}

// Test rejection of arbitrary CSS injection
$caughtCssInjection = false;
try {
    $v4Engine->buildAtomicDiv([], [], ['bad_token' => 'var(--injected-property); display:none;']);
} catch (\InvalidArgumentException $e) {
    if (str_contains($e->getMessage(), 'ERR_INVALID_DESIGN_TOKEN')) {
        $caughtCssInjection = true;
    }
}
if (!$caughtCssInjection) {
    $errors[] = "V4Engine failed to reject arbitrary CSS custom property injection.";
}
echo "   -> V4 Atomic elements & design token enforcement: PASSED\n";

// TEST 3: Hybrid Engine Coexistence & Strict Non-Conversion
echo "3. Testing Hybrid Coexistence & Non-Conversion Guarantee...\n";
$opaqueCustomNode = [
    'id'         => 'cust_99',
    'elType'     => 'third-party-carousel',
    'custom_prop'=> 'preserve-exactly',
    'elements'   => [],
];

$hybridPageTree = [
    $container,         // V3 Container (headingId inside)
    $atomicFlex,        // V4 Atomic Flexbox
    $opaqueCustomNode,  // Opaque custom node
];

$flexId = $atomicFlex['id'];

// A. Mutate V3 element on hybrid page
$afterV3Mutation = $hybridEngine->updateHybridElement($hybridPageTree, $headingId, [
    'settings' => ['title' => 'Mutated V3 on Hybrid Page'],
]);

if (($afterV3Mutation[0]['elements'][0]['settings']['title'] ?? '') !== 'Mutated V3 on Hybrid Page') {
    $errors[] = "HybridEngine failed to update V3 branch.";
}
// Assert V4 branch remained V4 Atomic
if (($afterV3Mutation[1]['elType'] ?? '') !== 'e-flexbox' || empty($afterV3Mutation[1]['classes'])) {
    $errors[] = "CONVERSION VIOLATION: Mutating V3 node corrupted or converted adjacent V4 node!";
}
// Assert opaque node was preserved intact
if (($afterV3Mutation[2]['custom_prop'] ?? '') !== 'preserve-exactly') {
    $errors[] = "CORRUPTION VIOLATION: Mutating V3 node corrupted opaque third-party node!";
}

// B. Mutate V4 element on hybrid page
$afterV4Mutation = $hybridEngine->updateHybridElement($afterV3Mutation, $flexId, [
    'classes'   => ['additional-v4-class'],
    'variables' => ['typography' => 'e-var:font-heading-xl'],
]);

if (!in_array('additional-v4-class', $afterV4Mutation[1]['classes'] ?? [], true)) {
    $errors[] = "HybridEngine failed to append class to V4 node.";
}
if (($afterV4Mutation[1]['props']['variables']['typography'] ?? '') !== 'e-var:font-heading-xl') {
    $errors[] = "HybridEngine failed to bind design token to V4 node.";
}
// Assert V3 branch remained V3 and untouched
if (($afterV4Mutation[0]['elType'] ?? '') !== 'container' || ($afterV4Mutation[0]['elements'][0]['settings']['title'] ?? '') !== 'Mutated V3 on Hybrid Page') {
    $errors[] = "CONVERSION VIOLATION: Mutating V4 node corrupted or converted adjacent V3 node!";
}

// C. Attempt to mutate opaque unknown node
$caughtOpaqueMutation = false;
try {
    $hybridEngine->updateHybridElement($afterV4Mutation, 'cust_99', ['settings' => ['break' => true]]);
} catch (\RuntimeException $e) {
    if (str_contains($e->getMessage(), 'ERR_ELEMENTOR_UNSUPPORTED_STRUCTURE')) {
        $caughtOpaqueMutation = true;
    }
}
if (!$caughtOpaqueMutation) {
    $errors[] = "HybridEngine failed to reject mutation of unrecognized/opaque structure.";
}
echo "   -> Hybrid coexistence, strict non-conversion, and opaque node preservation: PASSED\n";

// TEST 4: Elementor Free vs Pro Safety Gating
echo "4. Testing Elementor Free vs. Pro Safety Gating...\n";
// Free mode: attempt to create a page with a Pro-only 'form' widget
$proCreateResult = $elementorModule->execute('create', [
    'page_id'  => 101,
    'elements' => [
        [
            'id'         => 'pro_form_1',
            'elType'     => 'widget',
            'widgetType' => 'form',
            'settings'   => ['form_name' => 'Newsletter'],
        ],
    ],
]);

if (($proCreateResult['error'] ?? '') !== 'ERR_ELEMENTOR_PRO_UNSUPPORTED') {
    $errors[] = "ElementorModule failed to reject Pro-only widget creation in Free mode.";
}

// Clear cache action
$cacheResult = $elementorModule->execute('clear_cache', []);
if (($cacheResult['status'] ?? '') !== 'success') {
    $errors[] = "ElementorModule clear_cache action failed.";
}
echo "   -> Elementor Pro safety gating and cache clearing: PASSED\n";

echo "\n=========================================================\n";
if (!empty($errors)) {
    echo "FAILED with errors:\n";
    foreach ($errors as $err) {
        echo " - {$err}\n";
    }
    exit(1);
}

echo "SUCCESS: All Phase 3 Elementor multi-generation builder tests PASSED!\n";
echo "=========================================================\n";
exit(0);
