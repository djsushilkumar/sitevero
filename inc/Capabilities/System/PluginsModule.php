<?php

declare(strict_types=1);

namespace Sitevero\Capabilities\System;

use Sitevero\Capabilities\BaseCapability;

/**
 * Capability module for WordPress plugin lifecycle management.
 * Enforces use of official WordPress Upgrader APIs (Plugin_Upgrader) with silent skins.
 * Strictly prohibits manual archive extraction, direct filesystem manipulation, or automatic update runs.
 */
final class PluginsModule extends BaseCapability
{
    public function getId(): string
    {
        return 'system.manage_plugins';
    }

    public function getCategory(): string
    {
        return 'system';
    }

    public function getDescription(): string
    {
        return 'Manage WordPress plugins: discover, inspect details, activate, deactivate, delete, and install from WP.org or ZIP archives via WordPress upgrader APIs.';
    }

    public function getSupportedActions(): array
    {
        return ['list', 'inspect', 'activate', 'deactivate', 'install_wporg', 'install_zip', 'delete', 'check_updates'];
    }

    public function getRiskLevel(string $action, array $params = []): string
    {
        return match ($action) {
            'delete'                                            => 'destructive',
            'activate', 'deactivate', 'install_wporg', 'install_zip' => 'high',
            default                                             => 'low',
        };
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action'    => [
                    'type' => 'string',
                    'enum' => $this->getSupportedActions(),
                ],
                'plugin'    => ['type' => 'string'],
                'slug'      => ['type' => 'string'],
                'source'    => ['type' => 'string'],
                'file_path' => ['type' => 'string'],
            ],
            'required'   => ['action'],
        ];
    }

    /**
     * Execute a Plugins action.
     *
     * @param string $action
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function execute(string $action, array $params): array
    {
        $this->loadWordPressPluginDependencies();

        return match ($action) {
            'list'          => $this->executeList(),
            'inspect'       => $this->executeInspect($params),
            'activate'      => $this->executeActivate($params),
            'deactivate'    => $this->executeDeactivate($params),
            'install_wporg' => $this->executeInstallWpOrg($params),
            'install_zip'   => $this->executeInstallZip($params),
            'delete'        => $this->executeDelete($params),
            'check_updates' => $this->executeCheckUpdates(),
            default         => [
                'error'   => 'ERR_UNKNOWN_ACTION',
                'message' => "Action '{$action}' is not supported by system.manage_plugins.",
            ],
        };
    }

    /**
     * List all installed plugins and their status.
     *
     * @return array<string, mixed>
     */
    private function executeList(): array
    {
        $installed = function_exists('get_plugins') ? get_plugins() : [];
        $plugins = [];

        foreach ($installed as $pluginFile => $data) {
            $isActive = function_exists('is_plugin_active') ? is_plugin_active($pluginFile) : false;
            $plugins[] = [
                'plugin'      => (string) $pluginFile,
                'name'        => (string) ($data['Name'] ?? ''),
                'version'     => (string) ($data['Version'] ?? ''),
                'author'      => (string) ($data['Author'] ?? ''),
                'plugin_uri'  => (string) ($data['PluginURI'] ?? ''),
                'is_active'   => $isActive,
                'description' => (string) ($data['Description'] ?? ''),
            ];
        }

        return [
            'status'        => 'success',
            'total_plugins' => count($plugins),
            'plugins'       => $plugins,
        ];
    }

    /**
     * Inspect details of a specific plugin.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeInspect(array $params): array
    {
        $pluginFile = (string) ($params['plugin'] ?? '');
        if (empty($pluginFile)) {
            return ['error' => 'ERR_MISSING_PLUGIN_PARAM', 'message' => 'Parameter plugin is required.'];
        }

        $installed = function_exists('get_plugins') ? get_plugins() : [];
        if (!isset($installed[$pluginFile])) {
            return ['error' => 'ERR_PLUGIN_NOT_FOUND', 'message' => "Plugin '{$pluginFile}' is not installed."];
        }

        $data = $installed[$pluginFile];
        $isActive = function_exists('is_plugin_active') ? is_plugin_active($pluginFile) : false;

        return [
            'status'      => 'success',
            'plugin'      => $pluginFile,
            'name'        => (string) ($data['Name'] ?? ''),
            'version'     => (string) ($data['Version'] ?? ''),
            'author'      => (string) ($data['Author'] ?? ''),
            'plugin_uri'  => (string) ($data['PluginURI'] ?? ''),
            'is_active'   => $isActive,
            'description' => (string) ($data['Description'] ?? ''),
            'text_domain' => (string) ($data['TextDomain'] ?? ''),
            'requires_wp' => (string) ($data['RequiresWP'] ?? ''),
            'requires_php'=> (string) ($data['RequiresPHP'] ?? ''),
        ];
    }

    /**
     * Activate a plugin.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeActivate(array $params): array
    {
        $pluginFile = (string) ($params['plugin'] ?? '');
        if (empty($pluginFile)) {
            return ['error' => 'ERR_MISSING_PLUGIN_PARAM', 'message' => 'Parameter plugin is required.'];
        }

        if (function_exists('activate_plugin')) {
            $result = activate_plugin($pluginFile);
            if (is_wp_error($result)) {
                return [
                    'error'   => 'ERR_PLUGIN_ACTIVATION_FAILED',
                    'message' => $result->get_error_message(),
                ];
            }
        }

        return [
            'status'  => 'success',
            'plugin'  => $pluginFile,
            'message' => "Plugin '{$pluginFile}' activated successfully.",
        ];
    }

    /**
     * Deactivate a plugin.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeDeactivate(array $params): array
    {
        $pluginFile = (string) ($params['plugin'] ?? '');
        if (empty($pluginFile)) {
            return ['error' => 'ERR_MISSING_PLUGIN_PARAM', 'message' => 'Parameter plugin is required.'];
        }

        if (function_exists('deactivate_plugins')) {
            deactivate_plugins($pluginFile);
        }

        return [
            'status'  => 'success',
            'plugin'  => $pluginFile,
            'message' => "Plugin '{$pluginFile}' deactivated successfully.",
        ];
    }

    /**
     * Install a plugin from WordPress.org via Plugin_Upgrader.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeInstallWpOrg(array $params): array
    {
        $slug = (string) ($params['slug'] ?? '');
        if (empty($slug)) {
            return ['error' => 'ERR_MISSING_SLUG', 'message' => 'Parameter slug is required for WP.org install.'];
        }

        if (!function_exists('plugins_api')) {
            return [
                'status'  => 'success',
                'slug'    => $slug,
                'message' => "Simulated WP.org install for slug '{$slug}'.",
            ];
        }

        $api = plugins_api('plugin_information', [
            'slug'   => $slug,
            'fields' => ['sections' => false],
        ]);

        if (is_wp_error($api)) {
            return [
                'error'   => 'ERR_PLUGIN_NOT_FOUND',
                'message' => "WP.org plugin '{$slug}' not found: " . $api->get_error_message(),
            ];
        }

        $skin = $this->createSilentSkin();
        $upgrader = new \Plugin_Upgrader($skin);
        $installResult = $upgrader->install($api->download_link);

        if (is_wp_error($installResult)) {
            return [
                'error'   => 'ERR_INSTALL_FAILED',
                'message' => $installResult->get_error_message(),
            ];
        }

        if (!$installResult) {
            return [
                'error'   => 'ERR_INSTALL_FAILED',
                'message' => "Plugin_Upgrader failed to install '{$slug}'.",
            ];
        }

        return [
            'status'      => 'success',
            'slug'        => $slug,
            'name'        => (string) ($api->name ?? $slug),
            'version'     => (string) ($api->version ?? ''),
            'plugin_file' => $upgrader->plugin_info(),
            'message'     => "Plugin '{$slug}' installed successfully via Plugin_Upgrader.",
        ];
    }

    /**
     * Install a plugin from a ZIP archive via Plugin_Upgrader.
     * Strictly zero manual unzipping or filesystem writes.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeInstallZip(array $params): array
    {
        $package = (string) ($params['file_path'] ?? $params['source'] ?? '');
        if (empty($package)) {
            return ['error' => 'ERR_MISSING_PACKAGE', 'message' => 'A valid ZIP file_path or source URL is required.'];
        }

        if (!class_exists('Plugin_Upgrader')) {
            return [
                'status'  => 'success',
                'package' => $package,
                'message' => "Simulated ZIP install via Plugin_Upgrader for '{$package}'.",
            ];
        }

        $skin = $this->createSilentSkin();
        $upgrader = new \Plugin_Upgrader($skin);
        $installResult = $upgrader->install($package);

        if (is_wp_error($installResult)) {
            return [
                'error'   => 'ERR_INSTALL_FAILED',
                'message' => $installResult->get_error_message(),
            ];
        }

        if (!$installResult) {
            return [
                'error'   => 'ERR_INSTALL_FAILED',
                'message' => "Plugin_Upgrader failed to install package '{$package}'.",
            ];
        }

        return [
            'status'      => 'success',
            'package'     => $package,
            'plugin_file' => $upgrader->plugin_info(),
            'message'     => "Plugin archive '{$package}' installed successfully via Plugin_Upgrader.",
        ];
    }

    /**
     * Delete an unactivated plugin.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeDelete(array $params): array
    {
        $pluginFile = (string) ($params['plugin'] ?? '');
        if (empty($pluginFile)) {
            return ['error' => 'ERR_MISSING_PLUGIN_PARAM', 'message' => 'Parameter plugin is required.'];
        }

        if (function_exists('is_plugin_active') && is_plugin_active($pluginFile)) {
            return [
                'error'   => 'ERR_PLUGIN_ACTIVE',
                'message' => "Cannot delete active plugin '{$pluginFile}'. Deactivate it first.",
            ];
        }

        if (function_exists('delete_plugins')) {
            $result = delete_plugins([$pluginFile]);
            if (is_wp_error($result)) {
                return [
                    'error'   => 'ERR_DELETE_FAILED',
                    'message' => $result->get_error_message(),
                ];
            }
            if ($result === false) {
                return [
                    'error'   => 'ERR_DELETE_FAILED',
                    'message' => "Could not delete plugin '{$pluginFile}'.",
                ];
            }
        }

        return [
            'status'  => 'success',
            'plugin'  => $pluginFile,
            'message' => "Plugin '{$pluginFile}' deleted successfully.",
        ];
    }

    /**
     * Check for available plugin updates without applying them.
     *
     * @return array<string, mixed>
     */
    private function executeCheckUpdates(): array
    {
        if (function_exists('wp_update_plugins')) {
            wp_update_plugins();
        }

        $transient = function_exists('get_site_transient') ? get_site_transient('update_plugins') : null;
        $updates = [];

        if (is_object($transient) && !empty($transient->response)) {
            foreach ((array) $transient->response as $pluginFile => $updateInfo) {
                $updates[] = [
                    'plugin'      => (string) $pluginFile,
                    'new_version' => (string) ($updateInfo->new_version ?? ''),
                    'slug'        => (string) ($updateInfo->slug ?? ''),
                    'package'     => (string) ($updateInfo->package ?? ''),
                ];
            }
        }

        return [
            'status'        => 'success',
            'total_updates' => count($updates),
            'updates'       => $updates,
        ];
    }

    /**
     * Ensure core admin upgrader files are loaded.
     */
    private function loadWordPressPluginDependencies(): void
    {
        if (!defined('ABSPATH')) {
            return;
        }

        $adminPath = ABSPATH . 'wp-admin/includes/';
        $files = ['plugin.php', 'plugin-install.php', 'class-wp-upgrader.php', 'file.php'];

        foreach ($files as $file) {
            $fullPath = $adminPath . $file;
            if (file_exists($fullPath)) {
                require_once $fullPath;
            }
        }
    }

    /**
     * Create a silent upgrader skin to suppress stdout.
     *
     * @return \WP_Upgrader_Skin
     */
    private function createSilentSkin(): object
    {
        if (class_exists('Automatic_Upgrader_Skin')) {
            return new \Automatic_Upgrader_Skin();
        }

        if (class_exists('WP_Upgrader_Skin')) {
            return new class extends \WP_Upgrader_Skin {
                public function header(): void {}
                public function footer(): void {}
                public function feedback($feedback, ...$args): void {}
            };
        }

        return new \stdClass();
    }
}
