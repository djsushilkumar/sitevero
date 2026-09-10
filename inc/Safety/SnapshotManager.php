<?php

declare(strict_types=1);

namespace Sitevero\Safety;

use Sitevero\Storage\SnapshotRepository;

/**
 * Coordinates capturing pre-execution state snapshots across WordPress core and builder entities.
 */
final class SnapshotManager
{
    private SnapshotRepository $repository;

    public function __construct(?SnapshotRepository $repository = null)
    {
        $this->repository = $repository ?? new SnapshotRepository();
    }

    /**
     * Capture pre-execution state for an entity before mutation.
     *
     * @param string $capabilityId
     * @param string $action
     * @param array<string, mixed> $params
     * @param int $userId
     * @return string|null Snapshot UUID if captured, null if not applicable
     */
    public function capture(string $capabilityId, string $action, array $params, int $userId): ?string
    {
        $entityInfo = $this->resolveEntityInfo($capabilityId, $action, $params);
        if ($entityInfo === null) {
            return null;
        }

        [$entityType, $entityId, $beforeState] = $entityInfo;

        return $this->repository->create(
            $entityType,
            (string) $entityId,
            $capabilityId,
            $beforeState,
            $userId
        );
    }

    /**
     * Resolve target entity type, ID, and captured before_state.
     *
     * @param string $capabilityId
     * @param string $action
     * @param array<string, mixed> $params
     * @return array{0: string, 1: string|int, 2: array<string, mixed>}|null
     */
    private function resolveEntityInfo(string $capabilityId, string $action, array $params): ?array
    {
        return match ($capabilityId) {
            'content.manage_post' => $this->captureContentState($params),
            'elementor.manage_page' => $this->captureElementorState($params),
            'gutenberg.manage_blocks' => $this->captureGutenbergState($params),
            'media.manage' => $this->captureMediaState($params),
            'system.manage_settings' => $this->captureSettingsState($params),
            'system.manage_plugins' => $this->capturePluginsState($params),
            'system.manage_themes' => $this->captureThemesState($params),
            'users.manage' => $this->captureUserState($params),
            default => null,
        };
    }

    private function captureContentState(array $params): ?array
    {
        $postId = (int) ($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['post', 'new', ['is_new' => true]];
        }

        $outputType = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $post = function_exists('get_post') ? get_post($postId, $outputType) : null;
        $meta = function_exists('get_post_meta') ? get_post_meta($postId) : [];

        return [
            'post',
            $postId,
            [
                'post' => $post ?: ['ID' => $postId],
                'meta' => $meta,
            ],
        ];
    }

    private function captureElementorState(array $params): ?array
    {
        $pageId = (int) ($params['page_id'] ?? $params['post_id'] ?? 0);
        if ($pageId <= 0) {
            return ['elementor_data', 'new', ['is_new' => true]];
        }

        $rawElementorData = function_exists('get_post_meta') ? get_post_meta($pageId, '_elementor_data', true) : '';
        $pageSettings = function_exists('get_post_meta') ? get_post_meta($pageId, '_elementor_page_settings', true) : [];

        return [
            'elementor_data',
            $pageId,
            [
                'page_id'                  => $pageId,
                '_elementor_data'          => $rawElementorData,
                '_elementor_page_settings' => $pageSettings,
            ],
        ];
    }

    private function captureGutenbergState(array $params): ?array
    {
        $postId = (int) ($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['gutenberg_blocks', 'new', ['is_new' => true]];
        }

        $outputType = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $post = function_exists('get_post') ? get_post($postId, $outputType) : null;

        return [
            'gutenberg_blocks',
            $postId,
            [
                'post_id'      => $postId,
                'post_content' => $post['post_content'] ?? '',
            ],
        ];
    }

    private function captureMediaState(array $params): ?array
    {
        $attachmentId = (int) ($params['attachment_id'] ?? 0);
        if ($attachmentId <= 0) {
            return null;
        }

        $outputType = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $post = function_exists('get_post') ? get_post($attachmentId, $outputType) : null;
        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($attachmentId) : [];

        return [
            'media',
            $attachmentId,
            [
                'attachment_id' => $attachmentId,
                'post'          => $post,
                'meta'          => $meta,
            ],
        ];
    }

    private function captureSettingsState(array $params): ?array
    {
        $settings = $params['settings'] ?? [];
        $keys = array_keys((array) $settings);

        $captured = [];
        if (function_exists('get_option')) {
            foreach ($keys as $key) {
                $captured[$key] = get_option((string) $key);
            }
        }

        return [
            'option',
            implode(',', $keys),
            ['options' => $captured],
        ];
    }

    private function capturePluginsState(array $params): ?array
    {
        $activePlugins = function_exists('get_option') ? get_option('active_plugins', []) : [];
        $pluginSlug = $params['plugin'] ?? $params['slug'] ?? 'all';

        return [
            'plugin',
            (string) $pluginSlug,
            ['active_plugins' => $activePlugins],
        ];
    }

    private function captureThemesState(array $params): ?array
    {
        $stylesheet = function_exists('get_option') ? get_option('stylesheet', '') : '';
        $template = function_exists('get_option') ? get_option('template', '') : '';

        return [
            'theme',
            (string) ($params['stylesheet'] ?? 'active_theme'),
            [
                'stylesheet' => $stylesheet,
                'template'   => $template,
            ],
        ];
    }

    private function captureUserState(array $params): ?array
    {
        $userId = (int) ($params['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $user = function_exists('get_userdata') ? get_userdata($userId) : null;
        $userData = [];

        if ($user) {
            $userData = [
                'ID'           => $user->ID,
                'user_login'   => $user->user_login,
                'display_name' => $user->display_name,
                'user_email'   => $user->user_email,
                'roles'        => $user->roles,
            ];
        }

        return [
            'user',
            $userId,
            ['user' => $userData],
        ];
    }

    public function getRepository(): SnapshotRepository
    {
        return $this->repository;
    }
}
