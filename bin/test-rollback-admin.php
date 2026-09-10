<?php

declare(strict_types=1);

/**
 * Deterministic test harness for Sitevero Rollback Engine (sitevero_rollback)
 * and Admin Dashboard Controller (Phase 6).
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
        'blogname'                     => 'Original Sitevero Hub',
        'sitevero_capability_settings' => [],
    ];
    $mock_activity = [];
    $mock_snapshots = [];
    $mock_current_user = (object)['ID' => 1, 'user_login' => 'admin', 'roles' => ['administrator']];

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
    function checked(bool $checked, bool $echo = true): string {
        $res = $checked ? 'checked="checked"' : '';
        if ($echo) echo $res;
        return $res;
    }
    function current_user_can(string $cap): bool { return true; }
    function get_current_user_id(): int { return 1; }

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
        static $seq = 100;
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
    function wp_get_theme(string $stylesheet): object {
        return new class($stylesheet) {
            public function __construct(private string $s) {}
            public function exists(): bool { return $this->s === 'active_theme'; }
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
            // Match snapshot_uuid
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
use Sitevero\Mcp\Handlers\RollbackHandler;
use Sitevero\Storage\SnapshotRepository;
use Sitevero\Storage\ActivityRepository;
use Sitevero\Capabilities\CapabilityRegistry;

echo "=========================================================\n";
echo "  Sitevero — Phase 6 Rollback Engine & Admin Test Runner\n";
echo "=========================================================\n\n";

$plugin = Plugin::getInstance();
$plugin->onPluginsLoaded();

/** @var AdapterBridge $adapterBridge */
$adapterBridge = $plugin->getContainer()->get(AdapterBridge::class);
/** @var CapabilityRegistry $registry */
$registry = $plugin->getContainer()->get(CapabilityRegistry::class);

$snapshotRepo = new SnapshotRepository();
$activityRepo = new ActivityRepository();
$rollbackHandler = new RollbackHandler($snapshotRepo, $activityRepo);

$errors = [];

// ==========================================
// 1. Post Rollback (Fully Restored)
// ==========================================
echo "1. Testing Post State Rollback (fully_restored)...\n";

$postId = wp_insert_post([
    'post_title'   => 'Original Post Title',
    'post_content' => 'Original Content',
    'post_status'  => 'publish',
]);
update_post_meta($postId, '_test_meta_key', 'initial_value');

// Capture snapshot
$postSnapshotUuid = $snapshotRepo->create(
    'post',
    (string)$postId,
    'content.manage_post',
    [
        'post' => get_post($postId, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'),
        'meta' => get_post_meta($postId),
    ],
    1
);

// Mutate post
wp_update_post([
    'ID'           => $postId,
    'post_title'   => 'Mutated Title via AI',
    'post_content' => 'Mutated Content via AI',
]);
update_post_meta($postId, '_test_meta_key', 'corrupted_value');

// Execute rollback via AdapterBridge dispatch
$postRollbackRes = $adapterBridge->dispatch('sitevero_rollback', [
    'snapshot_uuid' => $postSnapshotUuid,
]);

if (($postRollbackRes['status'] ?? '') !== 'completed' || ($postRollbackRes['restoration_status'] ?? '') !== 'fully_restored') {
    $errors[] = "Post rollback failed: " . json_encode($postRollbackRes);
} else {
    $restoredPost = get_post($postId, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
    $restoredMeta = get_post_meta($postId, '_test_meta_key', true);

    if (($restoredPost['post_title'] ?? '') !== 'Original Post Title' || $restoredMeta !== 'initial_value') {
        $errors[] = "Post fields or meta not restored. Title: " . ($restoredPost['post_title'] ?? '') . ", Meta: {$restoredMeta}";
    } else {
        echo "   ✓ Post #{$postId} title and meta fully restored from snapshot\n";
    }
}

// ==========================================
// 2. Newly Created Post Rollback (Deletion)
// ==========================================
echo "\n2. Testing Newly Created Post Rollback (Deletion)...\n";

$newPostId = wp_insert_post(['post_title' => 'Temporary Post to be Deleted']);
$newPostSnapshotUuid = $snapshotRepo->create(
    'post',
    (string)$newPostId,
    'content.manage_post',
    ['is_new' => true],
    1
);

$newPostRollbackRes = $adapterBridge->dispatch('sitevero_rollback', [
    'snapshot_uuid' => $newPostSnapshotUuid,
]);

if (($newPostRollbackRes['restoration_status'] ?? '') !== 'fully_restored') {
    $errors[] = "New post rollback failed: " . json_encode($newPostRollbackRes);
} else {
    $checkDeleted = get_post($newPostId);
    if ($checkDeleted !== null) {
        $errors[] = "Newly created post #{$newPostId} was not deleted during rollback.";
    } else {
        echo "   ✓ Newly created post #{$newPostId} deleted to revert creation\n";
    }
}

// ==========================================
// 3. Elementor Data Rollback
// ==========================================
echo "\n3. Testing Elementor Data Rollback...\n";

$pageId = wp_insert_post(['post_title' => 'Elementor Page']);
update_post_meta($pageId, '_elementor_data', json_encode([['id' => 'v3_section_original']]));
update_post_meta($pageId, '_elementor_page_settings', ['custom_bg' => '#ffffff']);

$elSnapshotUuid = $snapshotRepo->create(
    'elementor_data',
    (string)$pageId,
    'elementor.manage_page',
    [
        'page_id'                  => $pageId,
        '_elementor_data'          => json_encode([['id' => 'v3_section_original']]),
        '_elementor_page_settings' => ['custom_bg' => '#ffffff'],
    ],
    1
);

// Mutate
update_post_meta($pageId, '_elementor_data', json_encode([['id' => 'corrupted_layout']]));

$elRollbackRes = $adapterBridge->dispatch('sitevero_rollback', [
    'snapshot_uuid' => $elSnapshotUuid,
]);

if (($elRollbackRes['restoration_status'] ?? '') !== 'fully_restored') {
    $errors[] = "Elementor rollback failed: " . json_encode($elRollbackRes);
} else {
    $restoredElData = get_post_meta($pageId, '_elementor_data', true);
    if (!str_contains($restoredElData, 'v3_section_original')) {
        $errors[] = "Elementor data was not restored. Got: {$restoredElData}";
    } else {
        echo "   ✓ Elementor _elementor_data and settings fully restored\n";
    }
}

// ==========================================
// 4. Whitelisted Options Rollback
// ==========================================
echo "\n4. Testing Whitelisted Settings Rollback...\n";

update_option('blogname', 'Pre-Mutation Blogname');
$optSnapshotUuid = $snapshotRepo->create(
    'option',
    'blogname',
    'system.manage_settings',
    [
        'options' => ['blogname' => 'Pre-Mutation Blogname'],
    ],
    1
);

// Mutate option
update_option('blogname', 'Hacked / AI Overwritten Blogname');

$optRollbackRes = $adapterBridge->dispatch('sitevero_rollback', [
    'snapshot_uuid' => $optSnapshotUuid,
]);

if (($optRollbackRes['restoration_status'] ?? '') !== 'fully_restored' || get_option('blogname') !== 'Pre-Mutation Blogname') {
    $errors[] = "Option rollback failed. Current blogname: " . get_option('blogname');
} else {
    echo "   ✓ Option 'blogname' restored to 'Pre-Mutation Blogname'\n";
}

// ==========================================
// 5. Honest Non-Restorable Reporting (Deleted Media / Plugins)
// ==========================================
echo "\n5. Testing Honest Restoration Status (not_restorable)...\n";

// 5.1 Media attachment deleted
$mediaSnapshotUuid = $snapshotRepo->create(
    'media',
    '9999',
    'media.manage',
    ['attachment_id' => 9999, 'post' => null],
    1
);

$mediaRollbackRes = $adapterBridge->dispatch('sitevero_rollback', [
    'snapshot_uuid' => $mediaSnapshotUuid,
]);

if (($mediaRollbackRes['restoration_status'] ?? '') !== 'not_restorable') {
    $errors[] = "Expected not_restorable for deleted media file, got: " . json_encode($mediaRollbackRes);
} else {
    echo "   ✓ Deleted media file correctly reported honest 'not_restorable' status\n";
}

// 5.2 Deleted plugin files
$pluginSnapshotUuid = $snapshotRepo->create(
    'plugin',
    'deleted-plugin/deleted-plugin.php',
    'system.manage_plugins',
    ['active_plugins' => []],
    1
);

$pluginRollbackRes = $adapterBridge->dispatch('sitevero_rollback', [
    'snapshot_uuid' => $pluginSnapshotUuid,
]);

if (($pluginRollbackRes['restoration_status'] ?? '') !== 'not_restorable') {
    $errors[] = "Expected not_restorable for physically deleted plugin, got: " . json_encode($pluginRollbackRes);
} else {
    echo "   ✓ Deleted plugin files correctly reported honest 'not_restorable' status\n";
}

// ==========================================
// 6. Capability Toggle & Execution Gating
// ==========================================
echo "\n6. Testing Admin Capability Toggle & Execution Gating...\n";

// Disable content.manage_post in settings
update_option('sitevero_capability_settings', [
    'content.manage_post' => false,
]);

// Refresh cache by re-creating registry or checking isEnabled
$newRegistry = new CapabilityRegistry();
$newRegistry->register(new \Sitevero\Capabilities\Content\ContentModule());
if ($newRegistry->isEnabled('content.manage_post')) {
    $errors[] = "Expected content.manage_post to be disabled.";
} else {
    echo "   ✓ CapabilityRegistry correctly identifies content.manage_post as disabled\n";
}

// Check that ToolRegistrar blocks execution of disabled capability
$toolRegistrar = new \Sitevero\Mcp\ToolRegistrar($newRegistry);
$blockedExecRes = $toolRegistrar->dispatch('sitevero_execute', [
    'capability_id' => 'content.manage_post',
    'action'        => 'create',
    'parameters'    => ['data' => ['title' => 'Blocked']],
]);

if (($blockedExecRes['error'] ?? '') !== 'ERR_CAPABILITY_DISABLED') {
    $errors[] = "Expected ERR_CAPABILITY_DISABLED when executing disabled capability, got: " . json_encode($blockedExecRes);
} else {
    echo "   ✓ sitevero_execute correctly blocked execution with ERR_CAPABILITY_DISABLED\n";
}

// Re-enable
update_option('sitevero_capability_settings', [
    'content.manage_post' => true,
]);

// ==========================================
// 7. Audit Activity Log Verification
// ==========================================
echo "\n7. Testing Audit Activity Log for Rollback Events...\n";

$recentLogs = $activityRepo->getRecent(5);
$foundRollbackLog = false;
foreach ($recentLogs as $log) {
    if (($log['action'] ?? '') === 'rollback' && ($log['client_id'] ?? '') === 'mcp_rollback') {
        $foundRollbackLog = true;
        break;
    }
}

if (!$foundRollbackLog) {
    $errors[] = "No rollback action found in ActivityRepository logs.";
} else {
    echo "   ✓ Rollback event verified in local activity audit log\n";
}

// ==========================================
// Final Results
// ==========================================
echo "\n=========================================================\n";
if (!empty($errors)) {
    echo "FAILED with " . count($errors) . " error(s):\n";
    foreach ($errors as $err) {
        echo " - {$err}\n";
    }
    exit(1);
}

echo "SUCCESS: All Phase 6 Rollback Engine & Admin tests PASSED!\n";
exit(0);
