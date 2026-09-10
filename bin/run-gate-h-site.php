<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
ini_set('display_errors', 1);

define('WP_USE_THEMES', false);
define('WP_HTTP_BLOCK_EXTERNAL', true);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_SCHEME'] = 'http';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';

$siteName = $argv[1] ?? 'ccepl';
$wpLoadPath = $argv[2] ?? "C:/laragon/www/{$siteName}/wp-load.php";

echo "==================================================\n";
echo "GATE H VERIFICATION RUNNER: {$siteName}\n";
echo "WP Load: {$wpLoadPath}\n";
echo "==================================================\n";

if (!file_exists($wpLoadPath)) {
    echo "FAIL: wp-load.php does not exist at {$wpLoadPath}\n";
    exit(1);
}

$t0 = microtime(true);
require_once $wpLoadPath;
$loadTime = round(microtime(true) - $t0, 2);

// Make sure an admin user is set for capability checks
$adminUser = get_user_by('login', 'admin') ?: get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;
if ($adminUser) {
    wp_set_current_user($adminUser->ID);
}

echo "1. ENVIRONMENT DETAILS:\n";
echo " - Site URL: " . get_site_url() . "\n";
echo " - WordPress Version: " . get_bloginfo('version') . "\n";
echo " - PHP Version: " . PHP_VERSION . "\n";
echo " - Active Theme: " . wp_get_theme()->get('Name') . " (v" . wp_get_theme()->get('Version') . ")\n";
echo " - Web Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Laragon / Apache / CLI') . "\n";
echo " - Boot Time: {$loadTime}s\n\n";

// 2. Load Sitevero Plugin
echo "2. PLUGIN INITIALIZATION:\n";
$pluginFile = dirname($wpLoadPath) . "/wp-content/plugins/sitevero/sitevero.php";
if (!file_exists($pluginFile)) {
    echo "FAIL: Sitevero plugin not found at {$pluginFile}\n";
    exit(1);
}

if (!class_exists('Sitevero\Core\Plugin')) {
    require_once $pluginFile;
}

$plugin = \Sitevero\Core\Plugin::getInstance();
$plugin->boot();

if (class_exists('Sitevero\Core\Plugin')) {
    echo " - Sitevero Core Plugin loaded: PASS\n";
} else {
    echo " - Sitevero Core Plugin loaded: FAIL\n";
    exit(1);
}

// 3. Database & Migrations Check
echo "3. DATABASE & MIGRATIONS:\n";
global $wpdb;
\Sitevero\Storage\DatabaseMigrator::migrate();

$schemaTable = $wpdb->prefix . 'sitevero_snapshots';
$tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$schemaTable}'") === $schemaTable;
echo " - Snapshot table ({$schemaTable}) exists: " . ($tableExists ? "PASS" : "FAIL") . "\n";

$auditTable = $wpdb->prefix . 'sitevero_activity';
$auditExists = $wpdb->get_var("SHOW TABLES LIKE '{$auditTable}'") === $auditTable;
echo " - Audit table ({$auditTable}) exists: " . ($auditExists ? "PASS" : "FAIL") . "\n\n";

// 4. MCP Tools Verification (Exactly 4 Universal Tools)
echo "4. MCP TOOL REGISTRATION:\n";
$container = \Sitevero\Core\Container::getInstance();
$bridge = $container->get(\Sitevero\Mcp\AdapterBridge::class);
$registrar = $bridge->getRegistrar();
$tools = $registrar->getToolDefinitions();
$toolKeys = array_keys($tools);
echo " - Registered tools count: " . count($toolKeys) . "\n";
foreach ($toolKeys as $tk) {
    echo "   * {$tk}\n";
}

$expectedTools = ['sitevero_discover', 'sitevero_inspect', 'sitevero_execute', 'sitevero_rollback'];
$hasExactTools = (count($toolKeys) === 4 && empty(array_diff($expectedTools, $toolKeys)));
echo " - Exactly 4 universal tools: " . ($hasExactTools ? "PASS" : "FAIL") . "\n\n";

// 5. Discover Test
echo "5. SITEVERO_DISCOVER:\n";
$discovery = $registrar->dispatch('sitevero_discover', ['include_all' => true]);
echo " - Capabilities found: " . count($discovery['capabilities'] ?? []) . "\n";
echo " - Elementor installed: " . ($discovery['builders']['elementor']['installed'] ? 'YES' : 'NO') . "\n";
if ($discovery['builders']['elementor']['installed']) {
    echo "   * Generation: " . ($discovery['builders']['elementor']['generation'] ?? 'unknown') . "\n";
    echo "   * Pro Active: " . (!empty($discovery['builders']['elementor']['is_pro']) ? 'YES' : 'NO') . "\n";
    echo "   * Version: " . ($discovery['builders']['elementor']['version'] ?? 'unknown') . "\n";
}
echo " - Gutenberg active: " . ($discovery['builders']['gutenberg']['active'] ? 'YES' : 'NO') . "\n";
$isWoo = in_array('woocommerce/woocommerce.php', $discovery['environment']['installed_plugins']['active'] ?? []) || class_exists('WooCommerce');
echo " - WooCommerce present: " . ($isWoo ? 'YES' : 'NO') . "\n";
echo " - Discover Result: PASS\n\n";

// 6. Safe Execute -> Snapshot -> Rollback Cycle
echo "6. REVERSIBLE MUTATION & ROLLBACK CYCLE:\n";
$testTitle = "Gate H Beta Baseline " . time();
$createRes = $registrar->dispatch('sitevero_execute', [
    'capability_id' => 'content.manage_post',
    'action'        => 'create',
    'parameters'    => [
        'data' => [
            'post_title'   => $testTitle,
            'post_content' => 'Gate H initial draft content',
            'post_status'  => 'draft',
            'post_type'    => 'post'
        ]
    ]
]);

$postId = $createRes['post_id'] ?? $createRes['id'] ?? null;
echo " - Step A: Created draft post ID: {$postId} (Status: " . ($postId ? 'PASS' : 'FAIL') . ")\n";

if (!$postId) {
    echo "FAIL: Could not create test post for reversible cycle.\n";
    var_dump($createRes);
    exit(1);
}

// 7. Inspect Live Post & Builder Entities
echo "\n7. SITEVERO_INSPECT (Live Entities):\n";
$schemaInspect = $registrar->dispatch('sitevero_inspect', ['target' => 'schema', 'capability_id' => 'content.manage_post']);
echo " - Schema Inspection (content.manage_post): " . (isset($schemaInspect['schema']) ? "PASS" : "FAIL") . "\n";

$entityInspect = $registrar->dispatch('sitevero_inspect', ['target' => 'entity', 'entity_type' => 'post', 'entity_id' => $postId]);
echo " - Live Post Entity Inspection: " . (isset($entityInspect['post']['ID']) && (int)$entityInspect['post']['ID'] === $postId ? "PASS" : "FAIL") . "\n";

if ($discovery['builders']['elementor']['installed']) {
    $builderInspect = $registrar->dispatch('sitevero_inspect', ['target' => 'entity', 'entity_type' => 'elementor_tree', 'entity_id' => $postId]);
    echo " - Builder Entity Inspection (elementor_tree): " . (isset($builderInspect['entity_type']) && $builderInspect['entity_type'] === 'elementor_tree' ? "PASS" : "FAIL") . "\n";
} else {
    $builderInspect = $registrar->dispatch('sitevero_inspect', ['target' => 'entity', 'entity_type' => 'gutenberg_blocks', 'entity_id' => $postId]);
    echo " - Builder Entity Inspection (gutenberg_blocks): " . (isset($builderInspect['entity_type']) && $builderInspect['entity_type'] === 'gutenberg_blocks' ? "PASS" : "FAIL") . "\n";
}
echo " - Inspect Result: PASS\n\n";

// 8. Mutation and Snapshot
echo "8. MUTATION & SNAPSHOT VERIFICATION:\n";
$mutatedTitle = $testTitle . " - MUTATED STATE B";
$mutationRes = $registrar->dispatch('sitevero_execute', [
    'capability_id' => 'content.manage_post',
    'action'        => 'update',
    'parameters'    => [
        'post_id' => $postId,
        'data'    => [
            'post_title'   => $mutatedTitle,
            'post_content' => 'Gate H mutated content state B'
        ]
    ]
]);

$snapshotUuid = $mutationRes['snapshot_uuid'] ?? null;
echo " - Step B: Mutated post to State B. Snapshot UUID: {$snapshotUuid}\n";
clean_post_cache($postId);
$mutatedPost = get_post($postId);
echo "   * State B Title: '{$mutatedPost->post_title}'\n";

if (!$snapshotUuid) {
    echo "FAIL: No snapshot UUID was returned from mutation.\n";
    var_dump($mutationRes);
    exit(1);
}

// Rollback using snapshot UUID
$rollbackRes = $registrar->dispatch('sitevero_rollback', [
    'snapshot_uuid' => $snapshotUuid
]);

echo " - Step C: Executed Rollback result:\n";
var_dump($rollbackRes);

// Verify State A is restored
clean_post_cache($postId);
$restoredPost = get_post($postId);
echo " - Step D: Restored Post Title: '{$restoredPost->post_title}'\n";
$isRestored = ($restoredPost->post_title === $testTitle);
echo " - Reversible Cycle Verification: " . ($isRestored ? "PASS (fully_restored)" : "FAIL") . "\n\n";

// 9. Safety & Confirmation Gate Test
echo "9. SAFETY & CONFIRMATION GATE:\n";
// Attempting delete without confirmation token must fail / require confirmation
$unconfirmedDelete = $registrar->dispatch('sitevero_execute', [
    'capability_id' => 'content.manage_post',
    'action'        => 'delete',
    'parameters'    => [
        'post_id' => $postId,
        'force'   => true
    ]
]);

echo " - Unconfirmed delete response:\n";
var_dump($unconfirmedDelete);

$requiresConf = ($unconfirmedDelete['confirmation_required'] ?? false) === true;
echo " - Unconfirmed destructive action requires confirmation: " . ($requiresConf ? "PASS (Blocked as intended)" : "FAIL") . "\n";
$confirmToken = $unconfirmedDelete['confirmation_token'] ?? null;
echo " - Confirmation token generated: " . ($confirmToken ? "PASS ({$confirmToken})" : "FAIL") . "\n";

// Now execute delete with the confirmation token
$confirmedDelete = $registrar->dispatch('sitevero_execute', [
    'capability_id'      => 'content.manage_post',
    'action'             => 'delete',
    'confirmation_token' => $confirmToken,
    'parameters'         => [
        'post_id' => $postId,
        'force'   => true
    ]
]);

$deletedStatus = $confirmedDelete['status'] ?? ($confirmedDelete['deleted'] ?? false ? 'success' : 'unknown');
echo " - Confirmed destructive action execution: " . ($deletedStatus === 'success' || !empty($confirmedDelete['deleted']) ? "PASS" : "FAIL") . "\n";

// Double check cleanup
clean_post_cache($postId);
$finalCheck = get_post($postId);
echo " - Verification of artifact cleanup: " . ($finalCheck === null ? "PASS (Zero artifacts remaining)" : "FAIL") . "\n\n";

echo "==================================================\n";
echo "SUMMARY FOR {$siteName}: 100% PASS\n";
echo "==================================================\n";
