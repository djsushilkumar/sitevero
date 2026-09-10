<?php

declare(strict_types=1);

/**
 * Deterministic CLI verification runner for sitevero_inspect and Phase 2 Safety Architecture.
 */

// 1. Setup mock functions if running standalone outside WordPress
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    $GLOBALS['mock_transients'] = [];
    $GLOBALS['mock_options'] = [
        'blogname'        => 'Sitevero Test Site',
        'blogdescription' => 'Universal AI MCP Platform',
        'active_plugins'  => ['sitevero/sitevero.php'],
    ];

    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
    function register_activation_hook(string $file, callable $callback): void {}
    function register_deactivation_hook(string $file, callable $callback): void {}
    function plugin_dir_path(string $file): string { return dirname(__DIR__) . DIRECTORY_SEPARATOR; }
    function plugin_dir_url(string $file): string { return 'http://example.com/wp-content/plugins/sitevero/'; }
    function esc_html__(string $text, string $domain = 'default'): string { return $text; }
    function current_time(string $type): string { return date('Y-m-d H:i:s'); }
    function wp_salt(string $scheme = 'auth'): string { return 'deterministic_test_salt_for_hmac_1234567890'; }

    function get_option(string $option, mixed $default = false): mixed {
        return $GLOBALS['mock_options'][$option] ?? $default;
    }
    function update_option(string $option, mixed $value, mixed $autoload = null): bool {
        $GLOBALS['mock_options'][$option] = $value;
        return true;
    }

    function set_transient(string $transient, mixed $value, int $expiration = 0): bool {
        $GLOBALS['mock_transients'][$transient] = [
            'value'   => $value,
            'expires' => time() + $expiration,
        ];
        return true;
    }
    function get_transient(string $transient): mixed {
        if (!isset($GLOBALS['mock_transients'][$transient])) {
            return false;
        }
        if (time() > $GLOBALS['mock_transients'][$transient]['expires']) {
            unset($GLOBALS['mock_transients'][$transient]);
            return false;
        }
        return $GLOBALS['mock_transients'][$transient]['value'];
    }
    function delete_transient(string $transient): bool {
        unset($GLOBALS['mock_transients'][$transient]);
        return true;
    }

    function get_current_user_id(): int { return 1; }
    function current_user_can(string $cap, mixed ...$args): bool { return true; }
    function get_userdata(int $userId): object {
        return (object) [
            'ID'              => $userId,
            'user_login'      => 'admin',
            'display_name'    => 'Administrator',
            'user_email'      => 'admin@example.com',
            'user_pass'       => '$P$Bsupersecretpasswordhashthatmustneverbeexposed',
            'roles'           => ['administrator'],
            'user_registered' => '2026-01-01 00:00:00',
        ];
    }
    function get_plugins(): array {
        return [
            'sitevero/sitevero.php' => ['Name' => 'Sitevero', 'Version' => '1.0.0-beta', 'Author' => 'Sitevero Team'],
        ];
    }
    function wp_get_themes(): array { return []; }
    function is_multisite(): bool { return false; }
    function get_bloginfo(string $show = ''): string { return '6.7.1'; }
    function parse_blocks(string $content): array { return []; }
    function serialize_blocks(array $blocks): string { return ''; }
}

// 2. Load Plugin Bootstrap
require_once __DIR__ . '/../sitevero.php';

$plugin = \Sitevero\Core\Plugin::getInstance();
$plugin->onPluginsLoaded();

/** @var \Sitevero\Mcp\AdapterBridge $adapterBridge */
$adapterBridge = $plugin->getContainer()->get(\Sitevero\Mcp\AdapterBridge::class);
$registrar = $adapterBridge->getRegistrar();
$riskAssessor = $registrar->getRiskAssessor();
$confirmationGate = $registrar->getConfirmationGate();
$snapshotRepo = $registrar->getSnapshotManager()->getRepository();

echo "=========================================================\n";
echo "  Sitevero — Phase 2 Inspection & Safety Test Runner\n";
echo "=========================================================\n\n";

$errors = [];

// TEST 1: sitevero_inspect Schema Mode
echo "1. Testing sitevero_inspect (schema mode)...\n";
$schemaResult = $adapterBridge->dispatch('sitevero_inspect', [
    'target'        => 'schema',
    'capability_id' => 'elementor.manage_page',
]);

if (!isset($schemaResult['schema']['properties']['action'])) {
    $errors[] = "Inspect schema for elementor.manage_page missing 'action' property.";
}
if (!isset($schemaResult['supported_actions']) || !in_array('create', $schemaResult['supported_actions'], true)) {
    $errors[] = "Inspect schema missing supported_actions.";
}
echo "   -> Schema inspection: PASSED\n";

// TEST 2: sitevero_inspect Entity Mode (Settings & Users)
echo "2. Testing sitevero_inspect (entity mode)...\n";
$settingsResult = $adapterBridge->dispatch('sitevero_inspect', [
    'target'      => 'entity',
    'entity_type' => 'settings',
    'entity_id'   => 'blogname',
]);
if (($settingsResult['settings']['blogname'] ?? '') !== 'Sitevero Test Site') {
    $errors[] = "Inspect settings failed to return mock blogname.";
}

$userResult = $adapterBridge->dispatch('sitevero_inspect', [
    'target'      => 'entity',
    'entity_type' => 'users',
    'entity_id'   => 1,
]);
if (!isset($userResult['user']['user_login']) || $userResult['user']['user_login'] !== 'admin') {
    $errors[] = "Inspect user failed to return user data.";
}
if (isset($userResult['user']['user_pass'])) {
    $errors[] = "SECURITY VIOLATION: Inspect user returned password hash!";
}
echo "   -> Entity inspection & zero password leakage: PASSED\n";

// TEST 3: RiskAssessor Tiers & Bulk Safety
echo "3. Testing RiskAssessor classification & bulk limits...\n";
$tierDelete = $riskAssessor->assess('content.manage_post', 'delete');
$tierActivate = $riskAssessor->assess('system.manage_plugins', 'activate');
$tierCreate = $riskAssessor->assess('content.manage_post', 'create');
$tierRead = $riskAssessor->assess('content.manage_post', 'read');

if ($tierDelete !== \Sitevero\Safety\RiskAssessor::TIER_DESTRUCTIVE) {
    $errors[] = "Expected 'delete' to be destructive, got {$tierDelete}";
}
if ($tierActivate !== \Sitevero\Safety\RiskAssessor::TIER_HIGH) {
    $errors[] = "Expected 'activate' to be high, got {$tierActivate}";
}
if ($tierCreate !== \Sitevero\Safety\RiskAssessor::TIER_MEDIUM) {
    $errors[] = "Expected 'create' to be medium, got {$tierCreate}";
}
if ($tierRead !== \Sitevero\Safety\RiskAssessor::TIER_LOW) {
    $errors[] = "Expected 'read' to be low, got {$tierRead}";
}

$smallBatch = array_fill(0, 50, 'item');
$largeBatch = array_fill(0, 51, 'item');
if (!$riskAssessor->validateBulkLimit($smallBatch)) {
    $errors[] = "50 items should be permitted.";
}
if ($riskAssessor->validateBulkLimit($largeBatch)) {
    $errors[] = "51 items should be rejected by 50-item safety limit.";
}
echo "   -> RiskAssessor tiers and 50-item bulk limit: PASSED\n";

// TEST 4: ConfirmationGate (HMAC tokens, TTL, and Replay Attack Prevention)
echo "4. Testing ConfirmationGate (HMAC token, TTL, replay prevention)...\n";
$confirmReq = $confirmationGate->generateConfirmationRequest(1, 'system.manage_plugins', 'activate', 'high');
if (!isset($confirmReq['confirmation_required']) || !$confirmReq['confirmation_required']) {
    $errors[] = "ConfirmationGate failed to return confirmation_required flag.";
}
$token = $confirmReq['confirmation_token'] ?? '';
if (strlen($token) !== 64) {
    $errors[] = "Confirmation token is not 64-character SHA-256 HMAC.";
}

$isValidFirst = $confirmationGate->verify($token, 1, 'system.manage_plugins', 'activate');
if (!$isValidFirst) {
    $errors[] = "ConfirmationGate failed to verify valid token.";
}

// Second verification must fail (replay attack test)
$isValidSecond = $confirmationGate->verify($token, 1, 'system.manage_plugins', 'activate');
if ($isValidSecond) {
    $errors[] = "SECURITY VIOLATION: Confirmation token was re-used after consumption (replay attack succeeded)!";
}
echo "   -> ConfirmationGate token generation and replay prevention: PASSED\n";

// TEST 5: SnapshotRepository 2MB Payload Cap Enforcement
echo "5. Testing SnapshotRepository 2MB payload ceiling...\n";
$normalPayload = ['test' => 'data', 'timestamp' => time()];
$uuid = $snapshotRepo->create('post', '42', 'content.manage_post', $normalPayload, 1);
if (!str_starts_with($uuid, 'snp_')) {
    $errors[] = "Snapshot UUID does not start with snp_ prefix.";
}

$oversizedPayload = ['huge_blob' => str_repeat('A', 2097152 + 100)];
$caughtOversized = false;
try {
    $snapshotRepo->create('post', '42', 'content.manage_post', $oversizedPayload, 1);
} catch (\RuntimeException $e) {
    if (str_contains($e->getMessage(), 'ERR_SNAPSHOT_SIZE_EXCEEDED')) {
        $caughtOversized = true;
    }
}
if (!$caughtOversized) {
    $errors[] = "Oversized snapshot (>2MB) failed to throw ERR_SNAPSHOT_SIZE_EXCEEDED exception.";
}
echo "   -> SnapshotRepository 2MB cap enforcement: PASSED\n";

// TEST 6: sitevero_execute Confirmation Integration
echo "6. Testing sitevero_execute confirmation gating...\n";
$gatedResult = $adapterBridge->dispatch('sitevero_execute', [
    'capability_id' => 'system.manage_plugins',
    'action'        => 'activate',
    'parameters'    => ['plugin' => 'test-plugin'],
]);

if (!isset($gatedResult['confirmation_required']) || !$gatedResult['confirmation_required']) {
    $errors[] = "sitevero_execute did not halt high-risk action with confirmation_required.";
}
$execToken = $gatedResult['confirmation_token'] ?? '';

$approvedResult = $adapterBridge->dispatch('sitevero_execute', [
    'capability_id'      => 'system.manage_plugins',
    'action'             => 'activate',
    'parameters'         => ['plugin' => 'test-plugin'],
    'confirmation_token' => $execToken,
]);

if (($approvedResult['status'] ?? '') !== 'success') {
    $errors[] = "sitevero_execute failed to proceed with valid confirmation token.";
}
echo "   -> sitevero_execute confirmation gate enforcement: PASSED\n";

echo "\n=========================================================\n";
if (!empty($errors)) {
    echo "FAILED with errors:\n";
    foreach ($errors as $err) {
        echo " - {$err}\n";
    }
    exit(1);
}

echo "SUCCESS: All Phase 2 inspection & safety tests PASSED!\n";
echo "=========================================================\n";
exit(0);
