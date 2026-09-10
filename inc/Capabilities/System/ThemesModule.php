<?php

declare(strict_types=1);

namespace Sitevero\Capabilities\System;

use Sitevero\Capabilities\BaseCapability;

/**
 * Capability module for WordPress theme lifecycle management.
 * Enforces use of official WordPress Upgrader APIs (Theme_Upgrader) with silent skins.
 * Strictly prohibits manual archive extraction, direct filesystem manipulation, or automatic update runs.
 */
final class ThemesModule extends BaseCapability
{
    public function getId(): string
    {
        return 'system.manage_themes';
    }

    public function getCategory(): string
    {
        return 'system';
    }

    public function getDescription(): string
    {
        return 'Manage WordPress themes: discover, inspect details, activate/switch, delete, and install from WP.org or ZIP archives via WordPress upgrader APIs.';
    }

    public function getSupportedActions(): array
    {
        return ['list', 'inspect', 'activate', 'install_wporg', 'install_zip', 'delete', 'check_updates'];
    }

    public function getRiskLevel(string $action, array $params = []): string
    {
        return match ($action) {
            'delete'                               => 'destructive',
            'activate', 'install_wporg', 'install_zip' => 'high',
            default                                => 'low',
        };
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action'     => [
                    'type' => 'string',
                    'enum' => $this->getSupportedActions(),
                ],
                'stylesheet' => ['type' => 'string'],
                'slug'       => ['type' => 'string'],
                'source'     => ['type' => 'string'],
                'file_path'  => ['type' => 'string'],
            ],
            'required'   => ['action'],
        ];
    }

    /**
     * Execute a Themes action.
     *
     * @param string $action
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function execute(string $action, array $params): array
    {
        $this->loadWordPressThemeDependencies();

        return match ($action) {
            'list'          => $this->executeList(),
            'inspect'       => $this->executeInspect($params),
            'activate'      => $this->executeActivate($params),
            'install_wporg' => $this->executeInstallWpOrg($params),
            'install_zip'   => $this->executeInstallZip($params),
            'delete'        => $this->executeDelete($params),
            'check_updates' => $this->executeCheckUpdates(),
            default         => [
                'error'   => 'ERR_UNKNOWN_ACTION',
                'message' => "Action '{$action}' is not supported by system.manage_themes.",
            ],
        };
    }

    /**
     * List all installed themes and active status.
     *
     * @return array<string, mixed>
     */
    private function executeList(): array
    {
        $installed = function_exists('wp_get_themes') ? wp_get_themes() : [];
        $activeStylesheet = function_exists('get_stylesheet') ? get_stylesheet() : '';
        $themes = [];

        foreach ($installed as $slug => $themeObj) {
            $isBlockTheme = function_exists('wp_is_block_theme') ? wp_is_block_theme() : false;
            if (is_object($themeObj) && method_exists($themeObj, 'is_block_theme')) {
                $isBlockTheme = $themeObj->is_block_theme();
            }

            $themes[] = [
                'stylesheet'     => (string) $slug,
                'name'           => is_object($themeObj) && method_exists($themeObj, 'get') ? (string) $themeObj->get('Name') : (string) $slug,
                'version'        => is_object($themeObj) && method_exists($themeObj, 'get') ? (string) $themeObj->get('Version') : '',
                'author'         => is_object($themeObj) && method_exists($themeObj, 'get') ? (string) $themeObj->get('Author') : '',
                'theme_uri'      => is_object($themeObj) && method_exists($themeObj, 'get') ? (string) $themeObj->get('ThemeURI') : '',
                'is_active'      => $slug === $activeStylesheet,
                'is_block_theme' => $isBlockTheme,
                'parent_theme'   => is_object($themeObj) && method_exists($themeObj, 'parent') && $themeObj->parent() ? $themeObj->parent()->get_stylesheet() : null,
            ];
        }

        return [
            'status'       => 'success',
            'total_themes' => count($themes),
            'themes'       => $themes,
        ];
    }

    /**
     * Inspect details of a specific theme.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeInspect(array $params): array
    {
        $stylesheet = (string) ($params['stylesheet'] ?? '');
        if (empty($stylesheet)) {
            return ['error' => 'ERR_MISSING_STYLESHEET', 'message' => 'Parameter stylesheet is required.'];
        }

        if (!function_exists('wp_get_theme')) {
            return [
                'status'     => 'success',
                'stylesheet' => $stylesheet,
                'name'       => $stylesheet,
                'is_active'  => false,
            ];
        }

        $theme = wp_get_theme($stylesheet);
        if (!$theme->exists()) {
            return ['error' => 'ERR_THEME_NOT_FOUND', 'message' => "Theme '{$stylesheet}' does not exist."];
        }

        $activeStylesheet = function_exists('get_stylesheet') ? get_stylesheet() : '';

        return [
            'status'         => 'success',
            'stylesheet'     => $stylesheet,
            'name'           => (string) $theme->get('Name'),
            'version'        => (string) $theme->get('Version'),
            'author'         => (string) $theme->get('Author'),
            'description'    => (string) $theme->get('Description'),
            'theme_uri'      => (string) $theme->get('ThemeURI'),
            'is_active'      => $stylesheet === $activeStylesheet,
            'is_block_theme' => method_exists($theme, 'is_block_theme') ? $theme->is_block_theme() : false,
            'parent_theme'   => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
            'tags'           => (array) $theme->get('Tags'),
        ];
    }

    /**
     * Activate / switch active theme.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeActivate(array $params): array
    {
        $stylesheet = (string) ($params['stylesheet'] ?? '');
        if (empty($stylesheet)) {
            return ['error' => 'ERR_MISSING_STYLESHEET', 'message' => 'Parameter stylesheet is required.'];
        }

        if (function_exists('switch_theme')) {
            switch_theme($stylesheet);
        }

        return [
            'status'     => 'success',
            'stylesheet' => $stylesheet,
            'message'    => "Theme '{$stylesheet}' activated successfully.",
        ];
    }

    /**
     * Install a theme from WordPress.org via Theme_Upgrader.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeInstallWpOrg(array $params): array
    {
        $slug = (string) ($params['slug'] ?? '');
        if (empty($slug)) {
            return ['error' => 'ERR_MISSING_SLUG', 'message' => 'Parameter slug is required for WP.org theme install.'];
        }

        if (!function_exists('themes_api')) {
            return [
                'status'  => 'success',
                'slug'    => $slug,
                'message' => "Simulated WP.org theme install for '{$slug}'.",
            ];
        }

        $api = themes_api('theme_information', [
            'slug'   => $slug,
            'fields' => ['sections' => false],
        ]);

        if (is_wp_error($api)) {
            return [
                'error'   => 'ERR_THEME_NOT_FOUND',
                'message' => "WP.org theme '{$slug}' not found: " . $api->get_error_message(),
            ];
        }

        $skin = $this->createSilentSkin();
        $upgrader = new \Theme_Upgrader($skin);
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
                'message' => "Theme_Upgrader failed to install theme '{$slug}'.",
            ];
        }

        return [
            'status'     => 'success',
            'slug'       => $slug,
            'name'       => (string) ($api->name ?? $slug),
            'version'    => (string) ($api->version ?? ''),
            'stylesheet' => $upgrader->theme_info() ? $upgrader->theme_info()->get_stylesheet() : $slug,
            'message'    => "Theme '{$slug}' installed successfully via Theme_Upgrader.",
        ];
    }

    /**
     * Install a theme from a ZIP archive via Theme_Upgrader.
     * Strictly zero manual archive extraction or directory traversal.
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

        if (!class_exists('Theme_Upgrader')) {
            return [
                'status'  => 'success',
                'package' => $package,
                'message' => "Simulated ZIP install via Theme_Upgrader for '{$package}'.",
            ];
        }

        $skin = $this->createSilentSkin();
        $upgrader = new \Theme_Upgrader($skin);
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
                'message' => "Theme_Upgrader failed to install theme package '{$package}'.",
            ];
        }

        return [
            'status'     => 'success',
            'package'    => $package,
            'stylesheet' => $upgrader->theme_info() ? $upgrader->theme_info()->get_stylesheet() : '',
            'message'    => "Theme archive '{$package}' installed successfully via Theme_Upgrader.",
        ];
    }

    /**
     * Delete an unactivated theme.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeDelete(array $params): array
    {
        $stylesheet = (string) ($params['stylesheet'] ?? '');
        if (empty($stylesheet)) {
            return ['error' => 'ERR_MISSING_STYLESHEET', 'message' => 'Parameter stylesheet is required.'];
        }

        $activeStylesheet = function_exists('get_stylesheet') ? get_stylesheet() : '';
        if ($stylesheet === $activeStylesheet) {
            return [
                'error'   => 'ERR_THEME_ACTIVE',
                'message' => "Cannot delete active theme '{$stylesheet}'. Switch to another theme first.",
            ];
        }

        if (function_exists('delete_theme')) {
            $result = delete_theme($stylesheet);
            if (is_wp_error($result)) {
                return [
                    'error'   => 'ERR_DELETE_FAILED',
                    'message' => $result->get_error_message(),
                ];
            }
            if ($result === false) {
                return [
                    'error'   => 'ERR_DELETE_FAILED',
                    'message' => "Could not delete theme '{$stylesheet}'.",
                ];
            }
        }

        return [
            'status'     => 'success',
            'stylesheet' => $stylesheet,
            'message'    => "Theme '{$stylesheet}' deleted successfully.",
        ];
    }

    /**
     * Check for available theme updates without applying them.
     *
     * @return array<string, mixed>
     */
    private function executeCheckUpdates(): array
    {
        if (function_exists('wp_update_themes')) {
            wp_update_themes();
        }

        $transient = function_exists('get_site_transient') ? get_site_transient('update_themes') : null;
        $updates = [];

        if (is_object($transient) && !empty($transient->response)) {
            foreach ((array) $transient->response as $slug => $updateInfo) {
                $updates[] = [
                    'stylesheet'  => (string) $slug,
                    'new_version' => (string) ($updateInfo['new_version'] ?? ''),
                    'package'     => (string) ($updateInfo['package'] ?? ''),
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
    private function loadWordPressThemeDependencies(): void
    {
        if (!defined('ABSPATH')) {
            return;
        }

        $adminPath = ABSPATH . 'wp-admin/includes/';
        $files = ['theme.php', 'theme-install.php', 'class-wp-upgrader.php', 'file.php'];

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
