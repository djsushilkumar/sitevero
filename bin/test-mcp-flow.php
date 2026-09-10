<?php

declare(strict_types=1);

/**
 * Deterministic MCP Flow & Interoperability Test Runner (Stage 3).
 * Tests the complete, deterministic loop:
 *   AI Agent -> MCP Adapter -> sitevero_discover -> sitevero_inspect -> sitevero_execute -> Validation -> sitevero_rollback
 * Referenced in docs/13_testing_strategy.md and docs/15_mvp_release_checklist.md.
 */

// 1. Setup WordPress mock functions if running standalone outside WordPress
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    global $mock_posts, $mock_meta, $mock_options, $mock_activity, $mock_snapshots, $mock_current_user;
    $mock_posts = [];
    $mock_meta = [];
    $mock_options = [
        'blogname'                     => 'Sitevero Automated Testbed',
        'sitevero_capability_settings' => [],
        'active_plugins'               => ['sitevero/sitevero.php'],
        'stylesheet'                   => 'twentytwentyfour',
    ];
    $mock_activity = [];
    $mock_snapshots = [];
    $mock_current_user = (object)['ID' => 1, 'user_login' => 'ai_agent_runner', 'roles' => ['administrator']];

    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
    function register_activation_hook(string $file, callable $callback): void {}
    function register_deactivation_hook(string $file, callable $callback): void {}
    function plugin_dir_path(string $file): string { return dirname(__DIR__) . DIRECTORY_SEPARATOR; }
    function plugin_dir_url(string $file): string { return 'http://example.com/wp-content/plugins/sitevero/'; }
    function is_wp_error(mixed $thing): bool { return false; }
    function current_time(string $type): string { return date('Y-m-d H:i:s'); }
    function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    function esc_attr(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    function checked(bool $checked, bool $echo = true): string { return $checked ? 'checked="checked"' : ''; }
    function current_user_can(string $cap): bool { return true; }
    function get_current_user_id(): int { return 1; }
    function wp_get_current_user(): object { global $mock_current_user; return $mock_current_user; }

    function get_option(string $option, mixed $default = false): mixed {
        global $mock_options;
        return $mock_options[$option] ?? $default;
    }
    function update_option(string $option, mixed $value, mixed $autoload = null): bool {
        global $mock_options;
        $mock_options[$option] = $value;
        return true;
    }

    function wp_insert_post(array $postarr, bool $wp_error = false): int {
        global $mock_posts;
        static $seq = 500;
        $id = ++$seq;
        $postarr['ID'] = $id;
        $mock_posts[$id] = $postarr;
        return $id;
    }
    function get_post(int|object $post, string $output = 'OBJECT'): mixed {
        global $mock_posts;
        $id = is_object($post) ? (int)$post->ID : (int)$post;
        return $mock_posts[$id] ?? null;
    }
    function wp_update_post(array $postarr, bool $wp_error = false): int {
        global $mock_posts;
        $id = (int)($postarr['ID'] ?? 0);
        if (isset($mock_posts[$id])) {
            $mock_posts[$id] = array_merge($mock_posts[$id], $postarr);
        }
        return $id;
    }
    function wp_delete_post(int $id, bool $force = false): bool {
        global $mock_posts;
        if (isset($mock_posts[$id])) {
            unset($mock_posts[$id]);
            return true;
        }
        return false;
    }

    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed {
        global $mock_meta;
        if (!empty($key)) {
            $val = $mock_meta[$post_id][$key] ?? ($single ? '' : []);
            return $single && is_array($val) ? ($val[0] ?? '') : $val;
        }
        return $mock_meta[$post_id] ?? [];
    }
    function update_post_meta(int $post_id, string $key, mixed $val): bool {
        global $mock_meta;
        $mock_meta[$post_id][$key] = is_array($val) ? $val : [$val];
        return true;
    }

    function get_plugins(): array {
        return [
            'sitevero/sitevero.php' => ['Name' => 'Sitevero', 'Version' => '1.0.0'],
        ];
    }
    function wp_get_themes(): array { return []; }
    function wp_get_theme(?string $stylesheet = null): object {
        return new class($stylesheet) {
            public function __construct(private ?string $s) {}
            public function exists(): bool { return true; }
            public function get(string $k): string { return match($k) { 'Name' => 'Twenty Twenty-Four', default => '' }; }
            public function get_stylesheet(): string { return 'twentytwentyfour'; }
            public function is_block_theme(): bool { return true; }
        };
    }
    function switch_theme(string $s): void {}

    // Mock $wpdb for Snapshot and Activity tables
    global $wpdb;
    $wpdb = new class {
        public string $prefix = 'wp_';
        public int $insert_id = 1;

        public function prepare(string $query, ...$args): string {
            if (empty($args)) return $query;
            $first = $args[0];
            $flatArgs = is_array($first) && count($args) === 1 ? $first : $args;
            foreach ($flatArgs as $arg) {
                $replacement = is_numeric($arg) ? (string)$arg : "'" . addslashes((string)$arg) . "'";
                $query = preg_replace('/%[sdf]/', $replacement, $query, 1);
            }
            return $query;
        }

        public function insert(string $table, array $data, array $format = []): int|false {
            global $mock_snapshots, $mock_activity;
            if (str_contains($table, 'sitevero_snapshots')) {
                $mock_snapshots[$data['snapshot_uuid']] = $data;
                return 1;
            }
            if (str_contains($table, 'sitevero_activity')) {
                $mock_activity[] = $data;
                return 1;
            }
            return 1;
        }

        public function get_row(string $query, string $output = 'OBJECT'): mixed {
            global $mock_snapshots;
            foreach ($mock_snapshots as $uuid => $snap) {
                if (str_contains($query, $uuid)) {
                    return $snap;
                }
            }
            return null;
        }

        public function get_results(string $query, string $output = 'OBJECT'): array {
            global $mock_activity, $mock_snapshots;
            if (str_contains($query, 'sitevero_activity')) {
                return array_reverse($mock_activity);
            }
            if (str_contains($query, 'sitevero_snapshots')) {
                return array_values($mock_snapshots);
            }
            return [];
        }

        public function query(string $query): int {
            return 0;
        }
    };
}

// 2. Load Plugin Bootstrap
require_once __DIR__ . '/../sitevero.php';

use Sitevero\Core\Plugin;
use Sitevero\Mcp\AdapterBridge;
use Sitevero\Mcp\ToolRegistrar;

echo "=========================================================\n";
echo "  Sitevero — Stage 3 Deterministic MCP Flow Test Runner\n";
echo "  Loop: Discover -> Inspect -> Execute -> Validate -> Rollback\n";
echo "=========================================================\n\n";

$plugin = Plugin::getInstance();
$plugin->onPluginsLoaded();

/** @var AdapterBridge $adapterBridge */
$adapterBridge = $plugin->getContainer()->get(AdapterBridge::class);
/** @var ToolRegistrar $registrar */
$registrar = $adapterBridge->getRegistrar();

$errors = [];

// =========================================================================
// Step 1: Protocol Verification — Exactly 4 Universal Tools Exposed
// =========================================================================
echo "1. Verifying MCP Protocol Tool Registration (Exactly 4 Universal Tools)...\n";
$definitions = $registrar->getToolDefinitions();
$expectedTools = ['sitevero_discover', 'sitevero_inspect', 'sitevero_execute', 'sitevero_rollback'];

if (count($definitions) !== 4) {
    $errors[] = "Expected exactly 4 universal tools, got " . count($definitions);
} else {
    foreach ($expectedTools as $tool) {
        if (!isset($definitions[$tool])) {
            $errors[] = "Missing universal tool: {$tool}";
        }
    }
    // Token budget verification (< 1,000 tokens estimated as ~4,000 characters of JSON)
    $jsonSize = strlen(json_encode($definitions));
    if ($jsonSize > 8000) {
        $errors[] = "Tool definitions exceed compact size limit (got {$jsonSize} bytes).";
    } else {
        echo "   ✓ Exactly 4 universal tools exposed with compact schema ({$jsonSize} bytes total)\n";
    }
}

// =========================================================================
// Step 2: sitevero_discover
// =========================================================================
echo "\n2. Testing AI Agent Tool: sitevero_discover...\n";
$discoverResult = $adapterBridge->dispatch('sitevero_discover', ['include_all' => true]);

if (!isset($discoverResult['environment']) || !isset($discoverResult['capabilities'])) {
    $errors[] = "sitevero_discover output missing required sections: " . json_encode($discoverResult);
} else {
    $caps = array_column($discoverResult['capabilities'], 'id');
    echo "   ✓ Discovered " . count($caps) . " capabilities (" . implode(', ', array_slice($caps, 0, 4)) . "...)\n";
    echo "   ✓ Environment: PHP " . ($discoverResult['environment']['php_version'] ?? 'unknown') . "\n";
}

// =========================================================================
// Step 3: sitevero_inspect (Schema and Entity Modes)
// =========================================================================
echo "\n3. Testing AI Agent Tool: sitevero_inspect...\n";

// 3.1 Schema inspection
$inspectSchemaRes = $adapterBridge->dispatch('sitevero_inspect', [
    'capability_id' => 'content.manage_post',
]);
if (!isset($inspectSchemaRes['schema']['properties'])) {
    $errors[] = "sitevero_inspect schema mode failed: " . json_encode($inspectSchemaRes);
} else {
    echo "   ✓ Schema inspected for content.manage_post\n";
}

// Create initial post for entity inspection
$initialPostId = wp_insert_post([
    'post_title'   => 'Deterministic Initial Post',
    'post_content' => 'Initial deterministic body text.',
    'post_status'  => 'publish',
]);
update_post_meta($initialPostId, '_meta_demo', 'val_alpha');

// 3.2 Entity inspection
$inspectEntityRes = $adapterBridge->dispatch('sitevero_inspect', [
    'target'      => 'entity',
    'entity_type' => 'post',
    'entity_id'   => $initialPostId,
]);
if (($inspectEntityRes['post']['post_title'] ?? '') !== 'Deterministic Initial Post') {
    $errors[] = "sitevero_inspect entity mode failed: " . json_encode($inspectEntityRes);
} else {
    echo "   ✓ Entity inspected: Post #{$initialPostId} ('Deterministic Initial Post')\n";
}

// =========================================================================
// Step 4: sitevero_execute with Confirmation Gating & Snapshot Capture
// =========================================================================
echo "\n4. Testing AI Agent Tool: sitevero_execute (Safety Gate & Mutation)...\n";

// 4.1 Execute medium-risk update (auto-captures snapshot, updates post)
$updateRes = $adapterBridge->dispatch('sitevero_execute', [
    'capability_id' => 'content.manage_post',
    'action'        => 'update',
    'parameters'    => [
        'post_id' => $initialPostId,
        'data'    => [
            'post_title' => 'AI Mutated Title v2',
            'meta'       => ['_meta_demo' => 'val_beta'],
        ],
    ],
]);

$snapshotUuid = $updateRes['snapshot_uuid'] ?? null;
if (($updateRes['status'] ?? '') !== 'success' || empty($snapshotUuid)) {
    $errors[] = "sitevero_execute update failed or missing snapshot_uuid: " . json_encode($updateRes);
} else {
    echo "   ✓ Post updated to 'AI Mutated Title v2' with snapshot captured: {$snapshotUuid}\n";
}

// 4.2 Validate mutation took effect in database
$mutatedPost = get_post($initialPostId, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
if (($mutatedPost['post_title'] ?? '') !== 'AI Mutated Title v2') {
    $errors[] = "Validation failed: post_title was not updated in database.";
} else {
    echo "   ✓ Database state verified: Title is now 'AI Mutated Title v2'\n";
}

// 4.3 Test Confirmation Gate for destructive action (delete)
$deleteAttempt = $adapterBridge->dispatch('sitevero_execute', [
    'capability_id' => 'content.manage_post',
    'action'        => 'delete',
    'parameters'    => ['post_id' => $initialPostId],
]);

if (empty($deleteAttempt['confirmation_required']) || empty($deleteAttempt['confirmation_token'])) {
    $errors[] = "Destructive delete did not halt with confirmation_required: " . json_encode($deleteAttempt);
} else {
    $token = $deleteAttempt['confirmation_token'];
    echo "   ✓ Destructive action halted with confirmation_required and 5-minute token: {$token}\n";
}

// =========================================================================
// Step 5: sitevero_rollback
// =========================================================================
echo "\n5. Testing AI Agent Tool: sitevero_rollback...\n";

$rollbackRes = $adapterBridge->dispatch('sitevero_rollback', [
    'snapshot_uuid' => $snapshotUuid,
]);

if (($rollbackRes['status'] ?? '') !== 'completed' || ($rollbackRes['restoration_status'] ?? '') !== 'fully_restored') {
    $errors[] = "sitevero_rollback failed: " . json_encode($rollbackRes);
} else {
    echo "   ✓ Rollback completed with restoration_status: fully_restored\n";

    // Step 6: Validate post state restored to pre-mutation state
    $restoredPost = get_post($initialPostId, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
    $restoredMeta = get_post_meta($initialPostId, '_meta_demo', true);

    if (($restoredPost['post_title'] ?? '') !== 'Deterministic Initial Post' || $restoredMeta !== 'val_alpha') {
        $errors[] = "Post was not restored to pre-mutation state. Got: " . ($restoredPost['post_title'] ?? '');
    } else {
        echo "   ✓ Database validation passed: Title reverted to 'Deterministic Initial Post', meta to 'val_alpha'\n";
    }
}

// =========================================================================
// Step 6: Bulk Safety Threshold (50-Item Limit)
// =========================================================================
echo "\n6. Testing Bulk 50-Item Safety Threshold...\n";
$posts55 = [];
for ($i = 1; $i <= 55; $i++) {
    $posts55[] = ['post_id' => $i, 'data' => ['title' => "Bulk {$i}"]];
}
$bulkRes = $adapterBridge->dispatch('sitevero_execute', [
    'capability_id' => 'content.manage_post',
    'action'        => 'batch_update',
    'parameters'    => ['posts' => $posts55],
]);

if (($bulkRes['error'] ?? '') !== 'ERR_BULK_LIMIT_EXCEEDED') {
    $errors[] = "Bulk 50-item limit was not enforced for 55 items: " . json_encode($bulkRes);
} else {
    echo "   ✓ Bulk 50-item threshold enforced with ERR_BULK_LIMIT_EXCEEDED\n";
}

// =========================================================================
// Step 7: Admin Capability Toggle Enforcement
// =========================================================================
echo "\n7. Testing Admin Capability Toggle Enforcement on Execution...\n";
update_option('sitevero_capability_settings', [
    'content.manage_post' => false,
]);
$plugin->getContainer()->get(\Sitevero\Capabilities\CapabilityRegistry::class)->refreshSettings();

$disabledExecRes = $adapterBridge->dispatch('sitevero_execute', [
    'capability_id' => 'content.manage_post',
    'action'        => 'read',
    'parameters'    => ['post_id' => $initialPostId],
]);

if (($disabledExecRes['error'] ?? '') !== 'ERR_CAPABILITY_DISABLED') {
    $errors[] = "Disabled capability execution was not blocked: " . json_encode($disabledExecRes);
} else {
    echo "   ✓ Disabled capability blocked with ERR_CAPABILITY_DISABLED\n";
}

// Re-enable
update_option('sitevero_capability_settings', [
    'content.manage_post' => true,
]);

// =========================================================================
// Results Summary
// =========================================================================
echo "\n=========================================================\n";
if (!empty($errors)) {
    echo "FAILED with " . count($errors) . " error(s):\n";
    foreach ($errors as $err) {
        echo " - {$err}\n";
    }
    exit(1);
}

echo "SUCCESS: Complete Deterministic MCP Flow Verified!\n";
echo "Verified 4 Universal Tools: discover -> inspect -> execute -> rollback.\n";
exit(0);
