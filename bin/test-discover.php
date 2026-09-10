<?php

declare(strict_types=1);

/**
 * Deterministic CLI verification runner for sitevero_discover.
 * Can run standalone in pure PHP CLI or within a live WordPress environment.
 */

// 1. Setup WordPress mock functions if running standalone outside WordPress
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');

    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
    function register_activation_hook(string $file, callable $callback): void {}
    function register_deactivation_hook(string $file, callable $callback): void {}
    function plugin_dir_path(string $file): string { return dirname(__DIR__) . DIRECTORY_SEPARATOR; }
    function plugin_dir_url(string $file): string { return 'http://example.com/wp-content/plugins/sitevero/'; }
    function esc_html__(string $text, string $domain = 'default'): string { return $text; }
    function get_option(string $option, mixed $default = false): mixed { return $default; }
    function update_option(string $option, mixed $value, mixed $autoload = null): bool { return true; }
    function is_multisite(): bool { return false; }
    function get_bloginfo(string $show = ''): string { return '6.7.1'; }
    function parse_blocks(string $content): array { return []; }
    function serialize_blocks(array $blocks): string { return ''; }
}

// 2. Load Plugin Bootstrap
require_once __DIR__ . '/../sitevero.php';

// 3. Initialize services
$plugin = \Sitevero\Core\Plugin::getInstance();
$plugin->onPluginsLoaded();

/** @var \Sitevero\Mcp\AdapterBridge $adapterBridge */
$adapterBridge = $plugin->getContainer()->get(\Sitevero\Mcp\AdapterBridge::class);

echo "=========================================================\n";
echo "  Sitevero — Deterministic CLI Discovery Test Runner\n";
echo "=========================================================\n\n";

echo "Dispatching 'sitevero_discover' via AdapterBridge...\n";
$result = $adapterBridge->dispatch('sitevero_discover', ['include_all' => true]);

// 4. Assertions
$errors = [];

if (!is_array($result)) {
    $errors[] = "Result is not an array.";
}

if (!isset($result['environment']['php_version']) || $result['environment']['php_version'] !== PHP_VERSION) {
    $errors[] = "Environment php_version does not match runtime.";
}

if (!isset($result['builders']['elementor']) || !isset($result['builders']['gutenberg'])) {
    $errors[] = "Builders block is missing elementor or gutenberg.";
}

if (!isset($result['capabilities']) || count($result['capabilities']) < 6) {
    $errors[] = "Expected at least 6 registered capabilities, found: " . count($result['capabilities'] ?? []);
}

$expectedCapIds = [
    'content.manage_post',
    'media.manage',
    'elementor.manage_page',
    'gutenberg.manage_blocks',
    'system.manage_settings',
    'system.manage_plugins',
    'system.manage_themes',
    'users.manage',
];

$registeredIds = array_column($result['capabilities'] ?? [], 'id');
foreach ($expectedCapIds as $expectedId) {
    if (!in_array($expectedId, $registeredIds, true)) {
        $errors[] = "Missing expected capability ID: {$expectedId}";
    }
}

if (!isset($result['user_context']['roles'])) {
    $errors[] = "User context block is invalid.";
}

echo "\nDiscovery Payload JSON:\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

if (!empty($errors)) {
    echo "FAILED with errors:\n";
    foreach ($errors as $err) {
        echo " - {$err}\n";
    }
    exit(1);
}

echo "SUCCESS: sitevero_discover executed successfully with all assertions passed!\n";
echo "Discovered " . count($result['capabilities']) . " capabilities across " . count($result['builders']) . " builders.\n";
exit(0);
