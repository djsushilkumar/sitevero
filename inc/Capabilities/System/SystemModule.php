<?php

declare(strict_types=1);

namespace Sitevero\Capabilities\System;

use Sitevero\Capabilities\BaseCapability;

/**
 * Capability module for managing WordPress settings.
 * Enforces a strict whitelist of safe options and rejects mutations to sensitive core options.
 */
final class SystemModule extends BaseCapability
{
    /**
     * Complete whitelist of allowed settings.
     */
    public const ALLOWED_SETTINGS = [
        // General
        'blogname',
        'blogdescription',
        'admin_email',
        'users_can_register',
        'default_role',
        'timezone_string',
        'date_format',
        'time_format',
        'start_of_week',
        'siteurl',
        'home',

        // Reading / Front page
        'show_on_front',
        'page_on_front',
        'page_for_posts',
        'posts_per_page',

        // Discussion
        'default_comment_status',
        'default_ping_status',
        'comment_moderation',
        'require_name_email',

        // Permalinks
        'permalink_structure',

        // Media dimensions
        'thumbnail_size_w',
        'thumbnail_size_h',
        'thumbnail_crop',
        'medium_size_w',
        'medium_size_h',
        'large_size_w',
        'large_size_h',
    ];

    /**
     * Read-only settings that cannot be modified via update.
     */
    private const READ_ONLY_SETTINGS = [
        'siteurl',
        'home',
    ];

    public function getId(): string
    {
        return 'system.manage_settings';
    }

    public function getCategory(): string
    {
        return 'system';
    }

    public function getDescription(): string
    {
        return 'Manage whitelisted WordPress settings: site title, tagline, homepage display, permalinks, reading, discussion, and media dimensions.';
    }

    public function getSupportedActions(): array
    {
        return ['read', 'update'];
    }

    public function getRiskLevel(string $action, array $params = []): string
    {
        return match ($action) {
            'update' => 'high',
            default  => 'low',
        };
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action'   => [
                    'type' => 'string',
                    'enum' => $this->getSupportedActions(),
                ],
                'keys'     => [
                    'type'  => 'array',
                    'items' => ['type' => 'string'],
                ],
                'settings' => ['type' => 'object'],
            ],
            'required'   => ['action'],
        ];
    }

    /**
     * Execute a Settings action.
     *
     * @param string $action
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function execute(string $action, array $params): array
    {
        return match ($action) {
            'read'   => $this->executeRead($params),
            'update' => $this->executeUpdate($params),
            default  => [
                'error'   => 'ERR_UNKNOWN_ACTION',
                'message' => "Action '{$action}' is not supported by system.manage_settings.",
            ],
        };
    }

    /**
     * Read whitelisted settings.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeRead(array $params): array
    {
        $requestedKeys = (array) ($params['keys'] ?? $params['settings'] ?? []);
        if (empty($requestedKeys)) {
            $keysToFetch = self::ALLOWED_SETTINGS;
        } else {
            // Filter to only allowed keys
            $keysToFetch = array_values(array_intersect(
                is_array($requestedKeys) ? array_map('strval', $requestedKeys) : [],
                self::ALLOWED_SETTINGS
            ));
        }

        $values = [];
        foreach ($keysToFetch as $key) {
            $values[$key] = function_exists('get_option') ? get_option($key, '') : "mock_{$key}";
        }

        return [
            'status'   => 'success',
            'settings' => $values,
        ];
    }

    /**
     * Update whitelisted settings. Rejects non-whitelisted or read-only settings immediately.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeUpdate(array $params): array
    {
        $settings = (array) ($params['settings'] ?? []);
        if (empty($settings)) {
            return [
                'error'   => 'ERR_NO_SETTINGS_PROVIDED',
                'message' => 'No settings provided to update.',
            ];
        }

        // 1. Strict Whitelist Validation
        foreach ($settings as $key => $val) {
            $keyStr = (string) $key;
            if (!in_array($keyStr, self::ALLOWED_SETTINGS, true)) {
                return [
                    'error'   => 'ERR_DISALLOWED_SETTING',
                    'message' => "Setting '{$keyStr}' is not in the allowed settings whitelist.",
                ];
            }

            if (in_array($keyStr, self::READ_ONLY_SETTINGS, true)) {
                return [
                    'error'   => 'ERR_READ_ONLY_SETTING',
                    'message' => "Setting '{$keyStr}' is read-only and cannot be altered through AI meta-tools.",
                ];
            }
        }

        // 2. Perform updates
        $updated = [];
        foreach ($settings as $key => $val) {
            $keyStr = (string) $key;
            if (function_exists('update_option')) {
                update_option($keyStr, $val);
            }
            $updated[$keyStr] = $val;
        }

        return [
            'status'   => 'success',
            'updated'  => array_keys($updated),
            'settings' => $updated,
            'message'  => count($updated) . ' setting(s) updated successfully.',
        ];
    }

    /**
     * Check if a specific setting key is in the allowed whitelist.
     */
    public function isAllowedSetting(string $key): bool
    {
        return in_array($key, self::ALLOWED_SETTINGS, true);
    }
}
