<?php

declare(strict_types=1);

namespace Sitevero\Mcp\Handlers;

use Sitevero\Capabilities\CapabilityRegistry;
use Sitevero\Capabilities\Elementor\FreeProDetector;

/**
 * Handler for the sitevero_inspect universal tool.
 * Inspects capability schemas or queries live states of WordPress/builder entities.
 */
final class InspectHandler
{
    /**
     * Whitelist of safe settings allowed for inspection.
     */
    public const WHITELISTED_SETTINGS = [
        'blogname',
        'blogdescription',
        'show_on_front',
        'page_on_front',
        'page_for_posts',
        'timezone_string',
        'date_format',
        'time_format',
        'permalink_structure',
        'posts_per_page',
        'default_comment_status',
        'comment_moderation',
        'thumbnail_size_w',
        'thumbnail_size_h',
        'medium_size_w',
        'medium_size_h',
        'large_size_w',
        'large_size_h',
    ];

    private CapabilityRegistry $registry;
    private FreeProDetector $detector;

    public function __construct(CapabilityRegistry $registry, ?FreeProDetector $detector = null)
    {
        $this->registry = $registry;
        $this->detector = $detector ?? new FreeProDetector();
    }

    /**
     * Execute inspection.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments = []): array
    {
        $target = $arguments['target'] ?? 'schema';

        return match ($target) {
            'schema' => $this->inspectSchema($arguments),
            'entity' => $this->inspectEntity($arguments),
            default  => [
                'error'   => 'ERR_INVALID_TARGET',
                'message' => "Inspection target must be 'schema' or 'entity'.",
            ],
        };
    }

    /**
     * Inspect input parameter schema for a capability.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function inspectSchema(array $arguments): array
    {
        $capId = (string) ($arguments['capability_id'] ?? '');
        if (empty($capId)) {
            return [
                'error'   => 'ERR_MISSING_CAPABILITY_ID',
                'message' => "Argument 'capability_id' is required when target is 'schema'.",
            ];
        }

        $capability = $this->registry->get($capId);
        if ($capability === null) {
            return [
                'error'   => 'ERR_CAPABILITY_NOT_FOUND',
                'message' => "Capability '{$capId}' is not registered.",
            ];
        }

        return [
            'target'            => 'schema',
            'capability_id'     => $capability->getId(),
            'category'          => $capability->getCategory(),
            'description'       => $capability->getDescription(),
            'supported_actions' => $capability->getSupportedActions(),
            'enabled'           => $this->registry->isEnabled($capability->getId()),
            'available'         => $capability->isAvailable(),
            'schema'            => $capability->getSchema(),
        ];
    }

    /**
     * Inspect live state of a WordPress or builder entity.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function inspectEntity(array $arguments): array
    {
        $entityType = (string) ($arguments['entity_type'] ?? '');
        $entityId = $arguments['entity_id'] ?? '';

        return match ($entityType) {
            'post', 'page'      => $this->inspectPost((int) $entityId),
            'elementor_tree'    => $this->inspectElementorTree((int) $entityId),
            'gutenberg_blocks'  => $this->inspectGutenbergBlocks((int) $entityId),
            'media'             => $this->inspectMedia((int) $entityId),
            'plugins'           => $this->inspectPlugins((string) $entityId),
            'themes'            => $this->inspectThemes((string) $entityId),
            'users'             => $this->inspectUser((int) $entityId),
            'settings'          => $this->inspectSettings((string) $entityId),
            default             => [
                'error'   => 'ERR_UNSUPPORTED_ENTITY_TYPE',
                'message' => "Entity type '{$entityType}' is not supported for inspection.",
            ],
        };
    }

    private function inspectPost(int $postId): array
    {
        if ($postId <= 0) {
            return ['error' => 'ERR_INVALID_ID', 'message' => 'Valid post ID is required.'];
        }

        if (function_exists('current_user_can') && !current_user_can('edit_post', $postId) && !current_user_can('read_post', $postId)) {
            return ['error' => 'ERR_FORBIDDEN_WP_CAP', 'message' => 'Permission denied to inspect this post.'];
        }

        $post = function_exists('get_post') ? get_post($postId, ARRAY_A) : null;
        if (!$post) {
            return ['error' => 'ERR_NOT_FOUND', 'message' => "Post #{$postId} not found."];
        }

        $meta = function_exists('get_post_meta') ? get_post_meta($postId) : [];

        return [
            'entity_type' => 'post',
            'entity_id'   => $postId,
            'post'        => [
                'ID'           => (int) ($post['ID'] ?? $postId),
                'post_title'   => (string) ($post['post_title'] ?? ''),
                'post_status'  => (string) ($post['post_status'] ?? 'draft'),
                'post_type'    => (string) ($post['post_type'] ?? 'post'),
                'post_name'    => (string) ($post['post_name'] ?? ''),
                'post_author'  => (int) ($post['post_author'] ?? 0),
                'post_date'    => (string) ($post['post_date'] ?? ''),
                'post_content' => (string) ($post['post_content'] ?? ''),
                'post_excerpt' => (string) ($post['post_excerpt'] ?? ''),
            ],
            'meta'        => $meta,
        ];
    }

    private function inspectElementorTree(int $pageId): array
    {
        if ($pageId <= 0) {
            return ['error' => 'ERR_INVALID_ID', 'message' => 'Valid page ID is required.'];
        }

        if (function_exists('current_user_can') && !current_user_can('edit_post', $pageId)) {
            return ['error' => 'ERR_FORBIDDEN_WP_CAP', 'message' => 'Permission denied to inspect Elementor tree.'];
        }

        $rawData = function_exists('get_post_meta') ? get_post_meta($pageId, '_elementor_data', true) : '';
        $pageSettings = function_exists('get_post_meta') ? get_post_meta($pageId, '_elementor_page_settings', true) : [];

        $generation = $this->detector->detectGeneration($rawData);
        $elements = is_string($rawData) ? json_decode($rawData, true) : $rawData;

        return [
            'entity_type'   => 'elementor_tree',
            'entity_id'     => $pageId,
            'generation'    => $generation,
            'elements'      => is_array($elements) ? $elements : [],
            'page_settings' => is_array($pageSettings) ? $pageSettings : [],
        ];
    }

    private function inspectGutenbergBlocks(int $postId): array
    {
        if ($postId <= 0) {
            return ['error' => 'ERR_INVALID_ID', 'message' => 'Valid post ID is required.'];
        }

        if (function_exists('current_user_can') && !current_user_can('edit_post', $postId)) {
            return ['error' => 'ERR_FORBIDDEN_WP_CAP', 'message' => 'Permission denied to inspect Gutenberg blocks.'];
        }

        $post = function_exists('get_post') ? get_post($postId, ARRAY_A) : null;
        if (!$post) {
            return ['error' => 'ERR_NOT_FOUND', 'message' => "Post #{$postId} not found."];
        }

        $content = $post['post_content'] ?? '';
        $blocks = function_exists('parse_blocks') ? parse_blocks($content) : [];

        return [
            'entity_type'  => 'gutenberg_blocks',
            'entity_id'    => $postId,
            'block_count'  => count($blocks),
            'blocks'       => $blocks,
        ];
    }

    private function inspectMedia(int $attachmentId): array
    {
        if ($attachmentId <= 0) {
            return ['error' => 'ERR_INVALID_ID', 'message' => 'Valid attachment ID is required.'];
        }

        $post = function_exists('get_post') ? get_post($attachmentId, ARRAY_A) : null;
        if (!$post || ($post['post_type'] ?? '') !== 'attachment') {
            return ['error' => 'ERR_NOT_FOUND', 'message' => "Media attachment #{$attachmentId} not found."];
        }

        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($attachmentId) : [];
        $url = function_exists('wp_get_attachment_url') ? wp_get_attachment_url($attachmentId) : '';
        $alt = function_exists('get_post_meta') ? get_post_meta($attachmentId, '_wp_attachment_image_alt', true) : '';

        return [
            'entity_type'   => 'media',
            'entity_id'     => $attachmentId,
            'attachment'    => [
                'ID'          => $attachmentId,
                'title'       => $post['post_title'],
                'caption'     => $post['post_excerpt'],
                'description' => $post['post_content'],
                'mime_type'   => $post['post_mime_type'],
                'url'         => $url,
                'alt_text'    => $alt,
            ],
            'metadata'      => $meta,
        ];
    }

    private function inspectPlugins(string $slug = ''): array
    {
        if (function_exists('current_user_can') && !current_user_can('activate_plugins')) {
            return ['error' => 'ERR_FORBIDDEN_WP_CAP', 'message' => 'Permission denied to inspect plugins.'];
        }

        $allPlugins = function_exists('get_plugins') ? get_plugins() : [];
        $activePlugins = function_exists('get_option') ? get_option('active_plugins', []) : [];

        $list = [];
        foreach ($allPlugins as $file => $data) {
            $isActive = in_array($file, (array) $activePlugins, true);
            $pluginKey = dirname($file) !== '.' ? dirname($file) : $file;

            if (!empty($slug) && $slug !== $file && $slug !== $pluginKey) {
                continue;
            }

            $list[] = [
                'plugin_file' => $file,
                'name'        => $data['Name'] ?? 'Unknown',
                'version'     => $data['Version'] ?? '',
                'author'      => $data['Author'] ?? '',
                'active'      => $isActive,
            ];
        }

        return [
            'entity_type' => 'plugins',
            'plugins'     => $list,
        ];
    }

    private function inspectThemes(string $stylesheet = ''): array
    {
        if (function_exists('current_user_can') && !current_user_can('switch_themes')) {
            return ['error' => 'ERR_FORBIDDEN_WP_CAP', 'message' => 'Permission denied to inspect themes.'];
        }

        $allThemes = function_exists('wp_get_themes') ? wp_get_themes() : [];
        $activeStylesheet = function_exists('get_option') ? get_option('stylesheet', '') : '';

        $list = [];
        foreach ($allThemes as $sheet => $themeObj) {
            if (!empty($stylesheet) && $stylesheet !== $sheet) {
                continue;
            }

            $list[] = [
                'stylesheet'     => $sheet,
                'name'           => $themeObj->get('Name'),
                'version'        => $themeObj->get('Version'),
                'author'         => $themeObj->get('Author'),
                'is_active'      => ($sheet === $activeStylesheet),
                'is_block_theme' => method_exists($themeObj, 'is_block_theme') ? $themeObj->is_block_theme() : false,
            ];
        }

        return [
            'entity_type' => 'themes',
            'themes'      => $list,
        ];
    }

    private function inspectUser(int $userId): array
    {
        if ($userId <= 0) {
            return ['error' => 'ERR_INVALID_ID', 'message' => 'Valid user ID is required.'];
        }

        if (function_exists('current_user_can') && !current_user_can('list_users')) {
            return ['error' => 'ERR_FORBIDDEN_WP_CAP', 'message' => 'Permission denied to inspect users.'];
        }

        $user = function_exists('get_userdata') ? get_userdata($userId) : null;
        if (!$user) {
            return ['error' => 'ERR_NOT_FOUND', 'message' => "User #{$userId} not found."];
        }

        // Strictly omit passwords, password hashes, and session tokens
        return [
            'entity_type' => 'users',
            'entity_id'   => $userId,
            'user'        => [
                'ID'           => $user->ID,
                'user_login'   => $user->user_login,
                'display_name' => $user->display_name,
                'user_email'   => $user->user_email,
                'roles'        => array_values($user->roles),
                'registered'   => $user->user_registered,
            ],
        ];
    }

    private function inspectSettings(string $key = ''): array
    {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) {
            return ['error' => 'ERR_FORBIDDEN_WP_CAP', 'message' => 'Permission denied to inspect settings.'];
        }

        $results = [];
        $keysToInspect = !empty($key) ? [$key] : self::WHITELISTED_SETTINGS;

        foreach ($keysToInspect as $k) {
            if (!in_array($k, self::WHITELISTED_SETTINGS, true)) {
                continue;
            }
            $results[$k] = function_exists('get_option') ? get_option($k) : null;
        }

        return [
            'entity_type' => 'settings',
            'settings'    => $results,
        ];
    }
}
