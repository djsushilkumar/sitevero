<?php

declare(strict_types=1);

namespace Sitevero\Admin;

use Sitevero\Capabilities\CapabilityRegistry;
use Sitevero\Mcp\Handlers\DiscoverHandler;
use Sitevero\Mcp\Handlers\RollbackHandler;
use Sitevero\Storage\ActivityRepository;
use Sitevero\Storage\SnapshotRepository;

/**
 * Controller for the Sitevero WordPress Admin Dashboard.
 * Provides system overview, live capability toggles, activity audit logs, and snapshot rollback tools.
 */
final class AdminPage
{
    private CapabilityRegistry $registry;
    private DiscoverHandler $discoverHandler;
    private RollbackHandler $rollbackHandler;
    private ActivityRepository $activityRepository;
    private SnapshotRepository $snapshotRepository;

    public function __construct(
        CapabilityRegistry $registry,
        ?DiscoverHandler $discoverHandler = null,
        ?RollbackHandler $rollbackHandler = null,
        ?ActivityRepository $activityRepository = null,
        ?SnapshotRepository $snapshotRepository = null
    ) {
        $this->registry = $registry;
        $this->discoverHandler = $discoverHandler ?? new DiscoverHandler($registry);
        $this->rollbackHandler = $rollbackHandler ?? new RollbackHandler();
        $this->activityRepository = $activityRepository ?? new ActivityRepository();
        $this->snapshotRepository = $snapshotRepository ?? new SnapshotRepository();
    }

    /**
     * Initialize WordPress admin hooks.
     */
    public function init(): void
    {
        if (function_exists('add_action')) {
            add_action('admin_menu', [$this, 'registerMenu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
            add_action('wp_ajax_sitevero_toggle_capability', [$this, 'handleToggleCapability']);
            add_action('wp_ajax_sitevero_admin_rollback', [$this, 'handleAdminRollback']);
        }
    }

    /**
     * Register top-level admin menu.
     */
    public function registerMenu(): void
    {
        if (function_exists('add_menu_page')) {
            add_menu_page(
                'Sitevero',
                'Sitevero',
                'manage_options',
                'sitevero-dashboard',
                [$this, 'renderDashboard'],
                'dashicons-rest-api',
                30
            );
        }
    }

    /**
     * Enqueue admin styles and scripts on the Sitevero dashboard page.
     */
    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_sitevero-dashboard') {
            return;
        }

        $pluginUrl = function_exists('plugin_dir_url') ? plugin_dir_url(dirname(__DIR__, 2) . '/sitevero.php') : '';

        if (function_exists('wp_enqueue_style')) {
            wp_enqueue_style('sitevero-admin-css', $pluginUrl . 'assets/css/admin.css', [], '1.0.0');
        }

        if (function_exists('wp_enqueue_script')) {
            wp_enqueue_script('sitevero-admin-js', $pluginUrl . 'assets/js/admin.js', [], '1.0.0', true);

            $nonce = function_exists('wp_create_nonce') ? wp_create_nonce('sitevero_admin_nonce') : 'mock_nonce';
            if (function_exists('wp_localize_script')) {
                wp_localize_script('sitevero-admin-js', 'siteveroAdminData', [
                    'nonce'   => $nonce,
                    'ajaxUrl' => function_exists('admin_url') ? admin_url('admin-ajax.php') : '/wp-admin/admin-ajax.php',
                ]);
            }
        }
    }

    /**
     * Render the admin dashboard HTML markup.
     */
    public function renderDashboard(): void
    {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) {
            if (function_exists('wp_die')) {
                wp_die('Unauthorized: You do not have sufficient permissions to access Sitevero settings.');
            }
            return;
        }

        $discovery = $this->discoverHandler->handle(['include_all' => true]);
        $capabilities = $this->registry->getAll();
        $activities = $this->activityRepository->getRecent(25);
        $snapshots = $this->fetchRecentSnapshots(25);

        $enabledCount = 0;
        foreach ($capabilities as $cap) {
            if ($this->registry->isEnabled($cap->getId())) {
                $enabledCount++;
            }
        }

        $totalCapabilities = count($capabilities);
        $mcpEndpoint = function_exists('rest_url') ? rest_url('mcp/v1') : '/wp-json/mcp/v1';

        ?>
        <div class="wrap sitevero-wrap">
            <!-- Header -->
            <div class="sitevero-header">
                <div class="sitevero-header-left">
                    <div class="sitevero-logo-icon">SV</div>
                    <div class="sitevero-title-block">
                        <h1>Sitevero — Universal WordPress AI / MCP Controller</h1>
                        <p>Official WordPress MCP Adapter foundation with Universal Capability Layer.</p>
                    </div>
                </div>
                <div>
                    <span class="sitevero-version-badge">● v1.0.0-beta</span>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="sitevero-stats-grid">
                <div class="sitevero-stat-card">
                    <div class="sitevero-stat-label">
                        <span>MCP Server Status</span>
                        <span class="sitevero-badge sitevero-badge-success">ACTIVE</span>
                    </div>
                    <div class="sitevero-stat-value">Connected</div>
                    <div class="sitevero-stat-detail">Endpoint: <?php echo esc_html($mcpEndpoint); ?></div>
                </div>

                <div class="sitevero-stat-card">
                    <div class="sitevero-stat-label">
                        <span>Active Capabilities</span>
                        <span class="sitevero-badge sitevero-badge-info"><?php echo $enabledCount; ?> / <?php echo $totalCapabilities; ?></span>
                    </div>
                    <div class="sitevero-stat-value"><?php echo $enabledCount; ?> Enabled</div>
                    <div class="sitevero-stat-detail">Builders: Elementor & Gutenberg ready</div>
                </div>

                <div class="sitevero-stat-card">
                    <div class="sitevero-stat-label">
                        <span>Audit Activity Log</span>
                        <span class="sitevero-badge sitevero-badge-neutral">30 Days</span>
                    </div>
                    <div class="sitevero-stat-value"><?php echo count($activities); ?> Events</div>
                    <div class="sitevero-stat-detail">Retention: 30 days auto-pruning</div>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <div class="sitevero-tabs-nav">
                <button type="button" class="sitevero-tab-button active" data-target="sitevero-tab-overview">Overview & System Health</button>
                <button type="button" class="sitevero-tab-button" data-target="sitevero-tab-capabilities">Capability Controls</button>
                <button type="button" class="sitevero-tab-button" data-target="sitevero-tab-activity">Activity Log & Snapshots</button>
                <button type="button" class="sitevero-tab-button" data-target="sitevero-tab-setup">MCP Connection & Setup</button>
            </div>

            <!-- Tab 1: Overview -->
            <div id="sitevero-tab-overview" class="sitevero-tab-panel active">
                <div class="sitevero-card">
                    <div class="sitevero-card-header">
                        <h2>Runtime Environment Diagnostics</h2>
                    </div>
                    <table class="sitevero-table">
                        <tbody>
                            <tr>
                                <td><strong>WordPress Version</strong></td>
                                <td><?php echo esc_html($discovery['environment']['wordpress_version'] ?? 'Unknown'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>PHP Version</strong></td>
                                <td><?php echo esc_html($discovery['environment']['php_version'] ?? PHP_VERSION); ?> (Supported: 8.1+)</td>
                            </tr>
                            <tr>
                                <td><strong>Multisite Mode</strong></td>
                                <td><?php echo !empty($discovery['environment']['multisite']) ? 'Enabled (WPMU)' : 'Single Site (Standard)'; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Elementor Engine</strong></td>
                                <td>
                                    <?php
                                    $el = $discovery['builders']['elementor'] ?? [];
                                    if (!empty($el['installed'])) {
                                        echo 'Installed v' . esc_html((string)$el['version']) . ' (Gen: ' . esc_html((string)$el['generation']) . ')';
                                        if (!empty($el['is_pro'])) {
                                            echo ' <span class="sitevero-badge sitevero-badge-success">PRO ACTIVE</span>';
                                        } else {
                                            echo ' <span class="sitevero-badge sitevero-badge-neutral">FREE CORE</span>';
                                        }
                                    } else {
                                        echo '<span class="sitevero-badge sitevero-badge-neutral">Not Installed</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Gutenberg Block Engine</strong></td>
                                <td>
                                    <?php
                                    $gb = $discovery['builders']['gutenberg'] ?? [];
                                    echo !empty($gb['active'])
                                        ? '<span class="sitevero-badge sitevero-badge-success">ACTIVE</span>'
                                        : '<span class="sitevero-badge sitevero-badge-neutral">INACTIVE</span>';
                                    if (!empty($gb['block_theme'])) {
                                        echo ' (Full Site Editing Block Theme)';
                                    }
                                    ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab 2: Capability Controls -->
            <div id="sitevero-tab-capabilities" class="sitevero-tab-panel">
                <div class="sitevero-card">
                    <div class="sitevero-card-header">
                        <h2>Capability Master Toggles</h2>
                    </div>
                    <div class="sitevero-capabilities-list">
                        <?php foreach ($capabilities as $cap): ?>
                            <?php
                            $capId = $cap->getId();
                            $isEnabled = $this->registry->isEnabled($capId);
                            $riskLevel = $cap->getRiskLevel('update');
                            ?>
                            <div class="sitevero-capability-row">
                                <div class="sitevero-cap-info">
                                    <div class="sitevero-cap-header">
                                        <span class="sitevero-cap-title"><?php echo esc_html(ucfirst($cap->getCategory())); ?> Capability</span>
                                        <span class="sitevero-cap-id"><?php echo esc_html($capId); ?></span>
                                        <span class="sitevero-badge <?php echo $this->getRiskBadgeClass($riskLevel); ?>"><?php echo esc_html($riskLevel); ?></span>
                                    </div>
                                    <p class="sitevero-cap-desc"><?php echo esc_html($cap->getDescription()); ?></p>
                                </div>
                                <div>
                                    <label class="sitevero-switch">
                                        <input type="checkbox" class="sitevero-cap-toggle" data-cap-id="<?php echo esc_attr($capId); ?>" <?php checked($isEnabled); ?>>
                                        <span class="sitevero-slider"></span>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Tab 3: Activity Log & Snapshots -->
            <div id="sitevero-tab-activity" class="sitevero-tab-panel">
                <div class="sitevero-card">
                    <div class="sitevero-card-header">
                        <h2>Recent AI Agent Activity Log</h2>
                    </div>
                    <?php if (empty($activities)): ?>
                        <p style="color: var(--sv-text-muted);">No AI activity recorded in the past 30 days.</p>
                    <?php else: ?>
                        <table class="sitevero-table">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Agent / Client</th>
                                    <th>Capability</th>
                                    <th>Action</th>
                                    <th>Target</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activities as $act): ?>
                                    <tr>
                                        <td class="time-cell"><?php echo esc_html((string)($act['created_at'] ?? '')); ?></td>
                                        <td><strong><?php echo esc_html((string)($act['client_id'] ?? 'unknown')); ?></strong></td>
                                        <td><code><?php echo esc_html((string)($act['capability_id'] ?? '')); ?></code></td>
                                        <td><?php echo esc_html((string)($act['action'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string)($act['target'] ?? '')); ?></td>
                                        <td>
                                            <span class="sitevero-badge <?php echo ($act['status'] ?? '') === 'success' ? 'sitevero-badge-success' : 'sitevero-badge-warning'; ?>">
                                                <?php echo esc_html((string)($act['status'] ?? '')); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="sitevero-card">
                    <div class="sitevero-card-header">
                        <h2>State Snapshots & Single-Step Rollback</h2>
                    </div>
                    <?php if (empty($snapshots)): ?>
                        <p style="color: var(--sv-text-muted);">No pre-execution snapshots captured yet.</p>
                    <?php else: ?>
                        <table class="sitevero-table">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Snapshot UUID</th>
                                    <th>Entity</th>
                                    <th>Entity ID</th>
                                    <th>Capability</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($snapshots as $snap): ?>
                                    <tr>
                                        <td class="time-cell"><?php echo esc_html((string)($snap['created_at'] ?? '')); ?></td>
                                        <td><code><?php echo esc_html((string)($snap['snapshot_uuid'] ?? '')); ?></code></td>
                                        <td><span class="sitevero-badge sitevero-badge-info"><?php echo esc_html((string)($snap['entity_type'] ?? '')); ?></span></td>
                                        <td><strong>#<?php echo esc_html((string)($snap['entity_id'] ?? '')); ?></strong></td>
                                        <td><code><?php echo esc_html((string)($snap['capability_id'] ?? '')); ?></code></td>
                                        <td>
                                            <button type="button" class="sitevero-btn sitevero-btn-danger sitevero-btn-rollback"
                                                    data-uuid="<?php echo esc_attr((string)($snap['snapshot_uuid'] ?? '')); ?>"
                                                    data-entity-type="<?php echo esc_attr((string)($snap['entity_type'] ?? '')); ?>"
                                                    data-entity-id="<?php echo esc_attr((string)($snap['entity_id'] ?? '')); ?>">
                                                Rollback
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tab 4: MCP Connection & Setup -->
            <div id="sitevero-tab-setup" class="sitevero-tab-panel">
                <div class="sitevero-card">
                    <div class="sitevero-card-header">
                        <h2>MCP Server Configuration Setup Guide</h2>
                        <button type="button" id="sitevero-copy-config-btn" class="sitevero-btn sitevero-btn-primary">Copy Config</button>
                    </div>
                    <p style="margin-bottom: 16px; color: var(--sv-text-muted);">
                        Add the configuration below to your AI client (Cursor, Claude Desktop, Antigravity) to establish connection with this WordPress site:
                    </p>
                    <div class="sitevero-code-box">
                        <pre id="sitevero-config-code"><?php
                        $config = [
                            'mcpServers' => [
                                'sitevero' => [
                                    'command' => 'npx',
                                    'args'    => [
                                        '-y',
                                        '@automattic/mcp-adapter',
                                        '--url=' . (function_exists('home_url') ? home_url() : 'http://localhost'),
                                    ],
                                ],
                            ],
                        ];
                        echo esc_html((string)json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                        ?></pre>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for live capability enablement toggles.
     */
    public function handleToggleCapability(): void
    {
        if (function_exists('check_ajax_referer')) {
            check_ajax_referer('sitevero_admin_nonce', 'security');
        }

        if (function_exists('current_user_can') && !current_user_can('manage_options')) {
            if (function_exists('wp_send_json_error')) {
                wp_send_json_error(['message' => 'Unauthorized permissions.'], 403);
            }
            return;
        }

        $rawCapId = isset($_POST['capability_id']) ? (string)$_POST['capability_id'] : '';
        $capId = function_exists('sanitize_text_field') ? sanitize_text_field($rawCapId) : trim(strip_tags($rawCapId));
        $enabled = isset($_POST['enabled']) && (int)$_POST['enabled'] === 1;

        if (empty($capId) || !$this->registry->has($capId)) {
            if (function_exists('wp_send_json_error')) {
                wp_send_json_error(['message' => "Invalid or unregistered capability '{$capId}'."]);
            }
            return;
        }

        $settings = function_exists('get_option') ? get_option('sitevero_capability_settings', []) : [];
        if (!is_array($settings)) {
            $settings = [];
        }

        $settings[$capId] = $enabled;

        if (function_exists('update_option')) {
            update_option('sitevero_capability_settings', $settings);
        }

        if (function_exists('wp_send_json_success')) {
            wp_send_json_success([
                'capability_id' => $capId,
                'enabled'       => $enabled,
                'message'       => "Capability '{$capId}' is now " . ($enabled ? 'enabled' : 'disabled') . '.',
            ]);
        }
    }

    /**
     * AJAX handler for single-step manual snapshot rollback from the admin dashboard.
     */
    public function handleAdminRollback(): void
    {
        if (function_exists('check_ajax_referer')) {
            check_ajax_referer('sitevero_admin_nonce', 'security');
        }

        if (function_exists('current_user_can') && !current_user_can('manage_options')) {
            if (function_exists('wp_send_json_error')) {
                wp_send_json_error(['message' => 'Unauthorized permissions.'], 403);
            }
            return;
        }

        $rawUuid = isset($_POST['snapshot_uuid']) ? (string)$_POST['snapshot_uuid'] : '';
        $uuid = function_exists('sanitize_text_field') ? sanitize_text_field($rawUuid) : trim(strip_tags($rawUuid));
        if (empty($uuid)) {
            if (function_exists('wp_send_json_error')) {
                wp_send_json_error(['message' => 'Missing snapshot_uuid parameter.']);
            }
            return;
        }

        $result = $this->rollbackHandler->handle(['snapshot_uuid' => $uuid]);

        if (isset($result['error'])) {
            if (function_exists('wp_send_json_error')) {
                wp_send_json_error(['message' => $result['message'] ?? 'Rollback failed.']);
            }
            return;
        }

        if (function_exists('wp_send_json_success')) {
            wp_send_json_success([
                'message'            => $result['message'] ?? 'Entity restored from snapshot.',
                'restoration_status' => $result['restoration_status'] ?? 'fully_restored',
            ]);
        }
    }

    /**
     * Helper to fetch recent snapshots from database.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private function fetchRecentSnapshots(int $limit = 25): array
    {
        global $wpdb;
        if (!isset($wpdb)) {
            return [];
        }

        $table = $wpdb->prefix . 'sitevero_snapshots';
        $outputType = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT snapshot_uuid, entity_type, entity_id, capability_id, created_at FROM {$table} ORDER BY id DESC LIMIT %d", $limit),
            $outputType
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Resolve badge CSS class by risk level.
     */
    private function getRiskBadgeClass(string $risk): string
    {
        return match ($risk) {
            'destructive' => 'sitevero-badge-danger',
            'high'        => 'sitevero-badge-warning',
            'medium'      => 'sitevero-badge-info',
            default       => 'sitevero-badge-success',
        };
    }

    public function getRollbackHandler(): RollbackHandler
    {
        return $this->rollbackHandler;
    }
}
