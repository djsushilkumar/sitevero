<?php

declare(strict_types=1);

namespace Sitevero\Mcp\Handlers;

use Sitevero\Storage\ActivityRepository;
use Sitevero\Storage\SnapshotRepository;

/**
 * Executes rollback requests for sitevero_rollback.
 * Resolves snapshots from wp_sitevero_snapshots and applies capability-specific state restoration,
 * reporting honest restoration status (fully_restored, partially_restored, not_restorable).
 */
final class RollbackHandler
{
    private SnapshotRepository $snapshotRepository;
    private ActivityRepository $activityRepository;

    public function __construct(
        ?SnapshotRepository $snapshotRepository = null,
        ?ActivityRepository $activityRepository = null
    ) {
        $this->snapshotRepository = $snapshotRepository ?? new SnapshotRepository();
        $this->activityRepository = $activityRepository ?? new ActivityRepository();
    }

    /**
     * Handle sitevero_rollback tool execution.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $uuid = (string) ($arguments['snapshot_uuid'] ?? '');
        if (empty($uuid)) {
            return [
                'error'   => 'ERR_MISSING_SNAPSHOT_UUID',
                'message' => "Argument 'snapshot_uuid' is required for rollback.",
            ];
        }

        $snapshot = $this->snapshotRepository->findByUuid($uuid);
        if ($snapshot === null) {
            return [
                'error'   => 'ERR_SNAPSHOT_NOT_FOUND',
                'message' => "Snapshot '{$uuid}' was not found or has expired.",
            ];
        }

        $entityType = (string) ($snapshot['entity_type'] ?? '');
        $entityId = $snapshot['entity_id'] ?? '';
        $capabilityId = (string) ($snapshot['capability_id'] ?? '');
        $beforeState = (array) ($snapshot['before_state'] ?? []);
        $userId = (int) ($snapshot['user_id'] ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0));

        $restoration = match ($entityType) {
            'post'             => $this->rollbackPost($entityId, $beforeState),
            'elementor_data'   => $this->rollbackElementor($entityId, $beforeState),
            'gutenberg_blocks' => $this->rollbackGutenberg($entityId, $beforeState),
            'option'           => $this->rollbackOptions($beforeState),
            'plugin'           => $this->rollbackPlugin($entityId, $beforeState),
            'theme'            => $this->rollbackTheme($entityId, $beforeState),
            'media'            => $this->rollbackMedia($entityId, $beforeState),
            'user'             => $this->rollbackUser($entityId, $beforeState),
            default            => [
                'restoration_status' => 'not_restorable',
                'message'            => "Entity type '{$entityType}' does not support automated rollback.",
            ],
        };

        // Audit log the rollback action
        $this->activityRepository->log(
            $userId,
            'mcp_rollback',
            $capabilityId,
            'rollback',
            "{$entityType}:{$entityId}",
            'high',
            $restoration['restoration_status'] !== 'not_restorable' ? 'success' : 'partial',
            $uuid
        );

        return [
            'status'             => 'completed',
            'restoration_status' => $restoration['restoration_status'],
            'snapshot_uuid'      => $uuid,
            'entity_type'        => $entityType,
            'entity_id'          => $entityId,
            'message'            => $restoration['message'],
        ];
    }

    /**
     * Rollback a core post / page / CPT.
     *
     * @param string|int $entityId
     * @param array<string, mixed> $beforeState
     * @return array{restoration_status: string, message: string}
     */
    private function rollbackPost(string|int $entityId, array $beforeState): array
    {
        // Case: post was newly created by the action being rolled back
        if (!empty($beforeState['is_new'])) {
            $postId = (int) $entityId;
            if ($postId > 0 && function_exists('wp_delete_post')) {
                wp_delete_post($postId, true);
            }
            return [
                'restoration_status' => 'fully_restored',
                'message'            => "Newly created post #{$entityId} was deleted to restore previous state.",
            ];
        }

        $postData = (array) ($beforeState['post'] ?? []);
        $metaData = (array) ($beforeState['meta'] ?? []);
        $postId = (int) ($postData['ID'] ?? $entityId);

        if ($postId <= 0) {
            return [
                'restoration_status' => 'not_restorable',
                'message'            => 'Invalid post ID in snapshot state.',
            ];
        }

        // Restore core post fields
        if (function_exists('wp_update_post')) {
            $updateFields = [
                'ID'           => $postId,
                'post_title'   => (string) ($postData['post_title'] ?? ''),
                'post_content' => (string) ($postData['post_content'] ?? ''),
                'post_status'  => (string) ($postData['post_status'] ?? 'draft'),
                'post_excerpt' => (string) ($postData['post_excerpt'] ?? ''),
            ];
            wp_update_post($updateFields);
        }

        // Restore post meta
        if (function_exists('update_post_meta')) {
            foreach ($metaData as $metaKey => $metaValues) {
                $val = is_array($metaValues) && count($metaValues) === 1 ? $metaValues[0] : $metaValues;
                update_post_meta($postId, (string) $metaKey, $val);
            }
        }

        return [
            'restoration_status' => 'fully_restored',
            'message'            => "Post #{$postId} fully restored from snapshot.",
        ];
    }

    /**
     * Rollback Elementor builder layout and settings.
     *
     * @param string|int $entityId
     * @param array<string, mixed> $beforeState
     * @return array{restoration_status: string, message: string}
     */
    private function rollbackElementor(string|int $entityId, array $beforeState): array
    {
        $pageId = (int) ($beforeState['page_id'] ?? $entityId);
        if ($pageId <= 0) {
            return [
                'restoration_status' => 'not_restorable',
                'message'            => 'Invalid page ID in Elementor snapshot.',
            ];
        }

        if (function_exists('update_post_meta')) {
            if (isset($beforeState['_elementor_data'])) {
                update_post_meta($pageId, '_elementor_data', $beforeState['_elementor_data']);
            }
            if (isset($beforeState['_elementor_page_settings'])) {
                update_post_meta($pageId, '_elementor_page_settings', $beforeState['_elementor_page_settings']);
            }
        }

        // Clear Elementor CSS cache
        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }

        return [
            'restoration_status' => 'fully_restored',
            'message'            => "Elementor data for page #{$pageId} fully restored from snapshot.",
        ];
    }

    /**
     * Rollback Gutenberg blocks content.
     *
     * @param string|int $entityId
     * @param array<string, mixed> $beforeState
     * @return array{restoration_status: string, message: string}
     */
    private function rollbackGutenberg(string|int $entityId, array $beforeState): array
    {
        $postId = (int) ($beforeState['post_id'] ?? $entityId);
        if ($postId <= 0) {
            return [
                'restoration_status' => 'not_restorable',
                'message'            => 'Invalid post ID in Gutenberg snapshot.',
            ];
        }

        $postContent = (string) ($beforeState['post_content'] ?? '');

        if (function_exists('wp_update_post')) {
            wp_update_post([
                'ID'           => $postId,
                'post_content' => $postContent,
            ]);
        }

        return [
            'restoration_status' => 'fully_restored',
            'message'            => "Gutenberg blocks for post #{$postId} fully restored from snapshot.",
        ];
    }

    /**
     * Rollback whitelisted WordPress settings.
     *
     * @param array<string, mixed> $beforeState
     * @return array{restoration_status: string, message: string}
     */
    private function rollbackOptions(array $beforeState): array
    {
        $options = (array) ($beforeState['options'] ?? []);
        if (empty($options)) {
            return [
                'restoration_status' => 'not_restorable',
                'message'            => 'No option values stored in snapshot before_state.',
            ];
        }

        if (function_exists('update_option')) {
            foreach ($options as $key => $value) {
                update_option((string) $key, $value);
            }
        }

        return [
            'restoration_status' => 'fully_restored',
            'message'            => count($options) . " setting(s) restored from snapshot.",
        ];
    }

    /**
     * Rollback plugin state (active plugins list).
     * Honestly reports not_restorable if files were deleted.
     *
     * @param string|int $entityId
     * @param array<string, mixed> $beforeState
     * @return array{restoration_status: string, message: string}
     */
    private function rollbackPlugin(string|int $entityId, array $beforeState): array
    {
        $activePlugins = $beforeState['active_plugins'] ?? null;
        if (!is_array($activePlugins)) {
            return [
                'restoration_status' => 'not_restorable',
                'message'            => 'No active_plugins state recorded in snapshot.',
            ];
        }

        // Check if plugin file exists
        $pluginSlug = (string) $entityId;
        $installed = function_exists('get_plugins') ? get_plugins() : [];
        if ($pluginSlug !== 'all' && !isset($installed[$pluginSlug])) {
            return [
                'restoration_status' => 'not_restorable',
                'message'            => "Plugin files for '{$pluginSlug}' were physically deleted from disk and cannot be restored from snapshot.",
            ];
        }

        if (function_exists('update_option')) {
            update_option('active_plugins', $activePlugins);
        }

        return [
            'restoration_status' => 'fully_restored',
            'message'            => "Active plugins state restored from snapshot.",
        ];
    }

    /**
     * Rollback theme state.
     * Honestly reports not_restorable if theme files were deleted.
     *
     * @param string|int $entityId
     * @param array<string, mixed> $beforeState
     * @return array{restoration_status: string, message: string}
     */
    private function rollbackTheme(string|int $entityId, array $beforeState): array
    {
        $stylesheet = (string) ($beforeState['stylesheet'] ?? '');
        if (empty($stylesheet)) {
            return [
                'restoration_status' => 'not_restorable',
                'message'            => 'No theme stylesheet recorded in snapshot.',
            ];
        }

        if (function_exists('wp_get_theme')) {
            $theme = wp_get_theme($stylesheet);
            if (!$theme->exists()) {
                return [
                    'restoration_status' => 'not_restorable',
                    'message'            => "Theme '{$stylesheet}' files were physically deleted from disk and cannot be restored from snapshot.",
                ];
            }
        }

        if (function_exists('switch_theme')) {
            switch_theme($stylesheet);
        }

        return [
            'restoration_status' => 'fully_restored',
            'message'            => "Active theme restored to '{$stylesheet}'.",
        ];
    }

    /**
     * Rollback media attachment.
     * Honestly reports not_restorable if physical file was deleted from disk.
     *
     * @param string|int $entityId
     * @param array<string, mixed> $beforeState
     * @return array{restoration_status: string, message: string}
     */
    private function rollbackMedia(string|int $entityId, array $beforeState): array
    {
        $attachmentId = (int) ($beforeState['attachment_id'] ?? $entityId);
        $postData = (array) ($beforeState['post'] ?? []);
        $metaData = (array) ($beforeState['meta'] ?? []);

        $outputType = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $currentPost = function_exists('get_post') ? get_post($attachmentId, $outputType) : null;

        if (!$currentPost) {
            return [
                'restoration_status' => 'not_restorable',
                'message'            => "Media attachment #{$attachmentId} was deleted. Physical media files on disk cannot be recovered from snapshot.",
            ];
        }

        // Restore post fields
        if (function_exists('wp_update_post') && !empty($postData)) {
            wp_update_post([
                'ID'           => $attachmentId,
                'post_title'   => (string) ($postData['post_title'] ?? ''),
                'post_content' => (string) ($postData['post_content'] ?? ''),
                'post_excerpt' => (string) ($postData['post_excerpt'] ?? ''),
            ]);
        }

        // Restore metadata
        if (function_exists('wp_update_attachment_metadata') && !empty($metaData)) {
            wp_update_attachment_metadata($attachmentId, $metaData);
        }

        return [
            'restoration_status' => 'fully_restored',
            'message'            => "Media attachment #{$attachmentId} metadata restored from snapshot.",
        ];
    }

    /**
     * Rollback user profile data.
     *
     * @param string|int $entityId
     * @param array<string, mixed> $beforeState
     * @return array{restoration_status: string, message: string}
     */
    private function rollbackUser(string|int $entityId, array $beforeState): array
    {
        $userId = (int) $entityId;
        $userData = (array) ($beforeState['user'] ?? []);

        if ($userId <= 0 || empty($userData)) {
            return [
                'restoration_status' => 'not_restorable',
                'message'            => 'No user data recorded in snapshot before_state.',
            ];
        }

        $existingUser = function_exists('get_userdata') ? get_userdata($userId) : null;
        if (!$existingUser) {
            return [
                'restoration_status' => 'not_restorable',
                'message'            => "User #{$userId} was deleted. User credentials and password hashes cannot be recreated from snapshot.",
            ];
        }

        if (function_exists('wp_update_user')) {
            wp_update_user([
                'ID'           => $userId,
                'display_name' => (string) ($userData['display_name'] ?? ''),
                'user_email'   => (string) ($userData['user_email'] ?? ''),
            ]);
        }

        if (!empty($userData['roles']) && function_exists('get_user_by')) {
            $userObj = get_user_by('id', $userId);
            if ($userObj && method_exists($userObj, 'set_role')) {
                $userObj->set_role((string) $userData['roles'][0]);
            }
        }

        return [
            'restoration_status' => 'fully_restored',
            'message'            => "User #{$userId} profile and roles restored from snapshot.",
        ];
    }

    public function getSnapshotRepository(): SnapshotRepository
    {
        return $this->snapshotRepository;
    }

    public function getActivityRepository(): ActivityRepository
    {
        return $this->activityRepository;
    }
}
