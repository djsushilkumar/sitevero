<?php

declare(strict_types=1);

namespace Sitevero\Capabilities\Content;

use Sitevero\Capabilities\BaseCapability;

/**
 * Capability module for WordPress core content (Posts, Pages, Custom Post Types).
 * Supports full CRUD, taxonomy management, batch operations up to 50 items, and queries.
 */
final class ContentModule extends BaseCapability
{
    public const MAX_BULK_LIMIT = 50;

    public function getId(): string
    {
        return 'content.manage_post';
    }

    public function getCategory(): string
    {
        return 'content';
    }

    public function getDescription(): string
    {
        return 'Manage WordPress posts and pages: create, read, update, trash, restore, delete, batch update (max 50), and query with taxonomy support.';
    }

    public function getSupportedActions(): array
    {
        return ['create', 'read', 'update', 'trash', 'restore', 'delete', 'batch_update', 'query'];
    }

    public function getRiskLevel(string $action, array $params = []): string
    {
        return match ($action) {
            'delete', 'trash' => 'destructive',
            'create', 'update', 'batch_update' => 'medium',
            default => 'low',
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
                'post_id'  => ['type' => 'integer'],
                'data'     => [
                    'type'       => 'object',
                    'properties' => [
                        'post_title'   => ['type' => 'string'],
                        'post_content' => ['type' => 'string'],
                        'post_status'  => ['type' => 'string'],
                        'post_type'    => ['type' => 'string'],
                        'post_excerpt' => ['type' => 'string'],
                        'taxonomies'   => ['type' => 'object'],
                        'meta'         => ['type' => 'object'],
                    ],
                ],
                'posts'    => [
                    'type'  => 'array',
                    'items' => ['type' => 'object'],
                ],
                'query'    => [
                    'type'       => 'object',
                    'properties' => [
                        'post_type'      => ['type' => 'string'],
                        'post_status'    => ['type' => 'string'],
                        'posts_per_page' => ['type' => 'integer'],
                        'paged'          => ['type' => 'integer'],
                        's'              => ['type' => 'string'],
                        'orderby'        => ['type' => 'string'],
                        'order'          => ['type' => 'string'],
                    ],
                ],
            ],
            'required'   => ['action'],
        ];
    }

    /**
     * Execute a Content action.
     *
     * @param string $action
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function execute(string $action, array $params): array
    {
        return match ($action) {
            'create'       => $this->executeCreate($params),
            'read'         => $this->executeRead($params),
            'update'       => $this->executeUpdate($params),
            'trash'        => $this->executeTrash($params),
            'restore'      => $this->executeRestore($params),
            'delete'       => $this->executeDelete($params),
            'batch_update' => $this->executeBatchUpdate($params),
            'query'        => $this->executeQuery($params),
            default        => [
                'error'   => 'ERR_UNKNOWN_ACTION',
                'message' => "Action '{$action}' is not supported by content.manage_post.",
            ],
        };
    }

    /**
     * Create a post/page/CPT.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeCreate(array $params): array
    {
        $data = isset($params['data']) && is_array($params['data']) ? $params['data'] : $params;

        $postData = [
            'post_title'   => (string) ($data['post_title'] ?? $data['title'] ?? ''),
            'post_content' => (string) ($data['post_content'] ?? $data['content'] ?? ''),
            'post_status'  => (string) ($data['post_status'] ?? $data['status'] ?? 'draft'),
            'post_type'    => (string) ($data['post_type'] ?? 'post'),
            'post_excerpt' => (string) ($data['post_excerpt'] ?? $data['excerpt'] ?? ''),
        ];

        if (function_exists('wp_insert_post')) {
            $postId = wp_insert_post($postData, true);
            if (is_wp_error($postId)) {
                return [
                    'error'   => 'ERR_POST_CREATE_FAILED',
                    'message' => $postId->get_error_message(),
                ];
            }
        } else {
            // Fallback for mock environments
            $postId = rand(1000, 9999);
        }

        // Handle taxonomies
        if (!empty($data['taxonomies']) && is_array($data['taxonomies'])) {
            $this->assignTaxonomies((int) $postId, $data['taxonomies']);
        }

        // Handle meta
        if (!empty($data['meta']) && is_array($data['meta'])) {
            $this->assignMeta((int) $postId, $data['meta']);
        }

        $post = $this->fetchPostData((int) $postId);

        return [
            'status'  => 'success',
            'post_id' => (int) $postId,
            'post'    => $post ?: array_merge(['ID' => $postId], $postData),
            'message' => "Post #{$postId} created successfully.",
        ];
    }

    /**
     * Read a post by ID including meta and taxonomy terms.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeRead(array $params): array
    {
        $postId = (int) ($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return [
                'error'   => 'ERR_INVALID_POST_ID',
                'message' => 'A valid post_id is required.',
            ];
        }

        $post = $this->fetchPostData($postId);
        if ($post === null) {
            return [
                'error'   => 'ERR_POST_NOT_FOUND',
                'message' => "Post #{$postId} not found.",
            ];
        }

        $meta = function_exists('get_post_meta') ? get_post_meta($postId) : [];
        $taxonomies = $this->fetchTaxonomies($postId, (string) ($post['post_type'] ?? 'post'));

        return [
            'status'     => 'success',
            'post_id'    => $postId,
            'post'       => $post,
            'meta'       => $meta,
            'taxonomies' => $taxonomies,
        ];
    }

    /**
     * Update an existing post.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeUpdate(array $params): array
    {
        $postId = (int) ($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return [
                'error'   => 'ERR_INVALID_POST_ID',
                'message' => 'A valid post_id is required.',
            ];
        }

        $existing = $this->fetchPostData($postId);
        if ($existing === null) {
            return [
                'error'   => 'ERR_POST_NOT_FOUND',
                'message' => "Post #{$postId} not found.",
            ];
        }

        $data = isset($params['data']) && is_array($params['data']) ? $params['data'] : $params;
        $updateFields = ['ID' => $postId];
        $updatedKeys = [];

        if (isset($data['post_title']) || isset($data['title'])) {
            $updateFields['post_title'] = (string) ($data['post_title'] ?? $data['title']);
            $updatedKeys[] = 'post_title';
        }
        if (isset($data['post_content']) || isset($data['content'])) {
            $updateFields['post_content'] = (string) ($data['post_content'] ?? $data['content']);
            $updatedKeys[] = 'post_content';
        }
        if (isset($data['post_status']) || isset($data['status'])) {
            $updateFields['post_status'] = (string) ($data['post_status'] ?? $data['status']);
            $updatedKeys[] = 'post_status';
        }
        if (isset($data['post_excerpt']) || isset($data['excerpt'])) {
            $updateFields['post_excerpt'] = (string) ($data['post_excerpt'] ?? $data['excerpt']);
            $updatedKeys[] = 'post_excerpt';
        }

        if (count($updateFields) > 1 && function_exists('wp_update_post')) {
            $result = wp_update_post($updateFields, true);
            if (is_wp_error($result)) {
                return [
                    'error'   => 'ERR_POST_UPDATE_FAILED',
                    'message' => $result->get_error_message(),
                ];
            }
        }

        if (!empty($data['taxonomies']) && is_array($data['taxonomies'])) {
            $this->assignTaxonomies($postId, $data['taxonomies']);
            $updatedKeys[] = 'taxonomies';
        }

        if (!empty($data['meta']) && is_array($data['meta'])) {
            $this->assignMeta($postId, $data['meta']);
            $updatedKeys[] = 'meta';
        }

        $updatedPost = $this->fetchPostData($postId) ?: array_merge($existing, $updateFields);

        return [
            'status'         => 'success',
            'post_id'        => $postId,
            'updated_fields' => $updatedKeys,
            'post'           => $updatedPost,
            'message'        => "Post #{$postId} updated successfully.",
        ];
    }

    /**
     * Move post to trash.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeTrash(array $params): array
    {
        $postId = (int) ($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['error' => 'ERR_INVALID_POST_ID', 'message' => 'A valid post_id is required.'];
        }

        if (function_exists('wp_trash_post')) {
            $result = wp_trash_post($postId);
            if (!$result) {
                return ['error' => 'ERR_TRASH_FAILED', 'message' => "Failed to trash post #{$postId}."];
            }
        }

        return [
            'status'  => 'success',
            'post_id' => $postId,
            'message' => "Post #{$postId} moved to trash.",
        ];
    }

    /**
     * Restore post from trash.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeRestore(array $params): array
    {
        $postId = (int) ($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['error' => 'ERR_INVALID_POST_ID', 'message' => 'A valid post_id is required.'];
        }

        if (function_exists('wp_untrash_post')) {
            $result = wp_untrash_post($postId);
            if (!$result) {
                return ['error' => 'ERR_RESTORE_FAILED', 'message' => "Failed to restore post #{$postId}."];
            }
        }

        return [
            'status'  => 'success',
            'post_id' => $postId,
            'message' => "Post #{$postId} restored from trash.",
        ];
    }

    /**
     * Permanently delete a post.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeDelete(array $params): array
    {
        $postId = (int) ($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['error' => 'ERR_INVALID_POST_ID', 'message' => 'A valid post_id is required.'];
        }

        $force = (bool) ($params['force'] ?? true);

        if (function_exists('wp_delete_post')) {
            $result = wp_delete_post($postId, $force);
            if (!$result) {
                return ['error' => 'ERR_DELETE_FAILED', 'message' => "Failed to delete post #{$postId}."];
            }
        }

        return [
            'status'  => 'success',
            'post_id' => $postId,
            'message' => "Post #{$postId} permanently deleted.",
        ];
    }

    /**
     * Batch update multiple posts, strictly enforcing the 50-item threshold.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeBatchUpdate(array $params): array
    {
        $posts = (array) ($params['posts'] ?? []);
        $total = count($posts);

        if ($total === 0) {
            return [
                'error'   => 'ERR_EMPTY_BATCH',
                'message' => 'No posts provided in batch_update.',
            ];
        }

        if ($total > self::MAX_BULK_LIMIT) {
            return [
                'error'   => 'ERR_BULK_LIMIT_EXCEEDED',
                'message' => "Bulk operations cannot exceed " . self::MAX_BULK_LIMIT . " items. Received {$total}.",
            ];
        }

        $updated = 0;
        $failed = 0;
        $results = [];

        foreach ($posts as $item) {
            $postId = (int) ($item['post_id'] ?? 0);
            $itemData = (array) ($item['data'] ?? []);

            $updateRes = $this->executeUpdate([
                'post_id' => $postId,
                'data'    => $itemData,
            ]);

            if (($updateRes['status'] ?? '') === 'success') {
                $updated++;
                $results[] = [
                    'post_id' => $postId,
                    'status'  => 'success',
                ];
            } else {
                $failed++;
                $results[] = [
                    'post_id' => $postId,
                    'status'  => 'failed',
                    'error'   => $updateRes['error'] ?? 'ERR_UPDATE_FAILED',
                    'message' => $updateRes['message'] ?? '',
                ];
            }
        }

        return [
            'status'  => $failed === 0 ? 'success' : ($updated > 0 ? 'partial' : 'failed'),
            'total'   => $total,
            'updated' => $updated,
            'failed'  => $failed,
            'results' => $results,
        ];
    }

    /**
     * Query posts with pagination and sanitized filters.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function executeQuery(array $params): array
    {
        $queryArgs = (array) ($params['query'] ?? $params);

        $perPage = min((int) ($queryArgs['posts_per_page'] ?? 10), self::MAX_BULK_LIMIT);
        if ($perPage <= 0) {
            $perPage = 10;
        }

        $paged = max((int) ($queryArgs['paged'] ?? $queryArgs['page'] ?? 1), 1);
        $postType = $queryArgs['post_type'] ?? 'post';
        $postStatus = $queryArgs['post_status'] ?? 'publish';
        $search = (string) ($queryArgs['s'] ?? '');
        $orderby = (string) ($queryArgs['orderby'] ?? 'date');
        $order = strtoupper((string) ($queryArgs['order'] ?? 'DESC'));
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'DESC';
        }

        $args = [
            'post_type'      => $postType,
            'post_status'    => $postStatus,
            'posts_per_page' => $perPage,
            'paged'          => $paged,
            'orderby'        => $orderby,
            'order'          => $order,
        ];

        if (!empty($search)) {
            $args['s'] = $search;
        }

        if (!empty($queryArgs['tax_query']) && is_array($queryArgs['tax_query'])) {
            $args['tax_query'] = $queryArgs['tax_query'];
        }

        $posts = [];
        $totalPosts = 0;
        $totalPages = 1;

        if (class_exists('WP_Query')) {
            $query = new \WP_Query($args);
            $totalPosts = (int) $query->found_posts;
            $totalPages = (int) $query->max_num_pages;

            $outputType = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
            foreach ($query->posts as $p) {
                if (is_object($p) && function_exists('get_post')) {
                    $posts[] = get_post($p->ID, $outputType);
                } elseif (is_array($p)) {
                    $posts[] = $p;
                }
            }
        } elseif (function_exists('get_posts')) {
            $posts = get_posts($args);
            $totalPosts = count($posts);
        }

        return [
            'status'         => 'success',
            'total_posts'    => $totalPosts,
            'total_pages'    => $totalPages,
            'page'           => $paged,
            'posts_per_page' => $perPage,
            'posts'          => $posts,
        ];
    }

    /**
     * Assign taxonomy terms to a post.
     *
     * @param int $postId
     * @param array<string, mixed> $taxonomies
     */
    private function assignTaxonomies(int $postId, array $taxonomies): void
    {
        if (!function_exists('wp_set_object_terms')) {
            return;
        }

        foreach ($taxonomies as $taxonomy => $terms) {
            $termList = is_array($terms) ? $terms : [$terms];
            wp_set_object_terms($postId, $termList, (string) $taxonomy);
        }
    }

    /**
     * Assign post meta.
     *
     * @param int $postId
     * @param array<string, mixed> $meta
     */
    private function assignMeta(int $postId, array $meta): void
    {
        if (!function_exists('update_post_meta')) {
            return;
        }

        foreach ($meta as $key => $value) {
            update_post_meta($postId, (string) $key, $value);
        }
    }

    /**
     * Safely fetch a post array.
     *
     * @param int $postId
     * @return array<string, mixed>|null
     */
    private function fetchPostData(int $postId): ?array
    {
        if (!function_exists('get_post')) {
            return ['ID' => $postId, 'post_title' => 'Mock Post', 'post_type' => 'post'];
        }

        $outputType = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $post = get_post($postId, $outputType);
        return is_array($post) ? $post : null;
    }

    /**
     * Fetch taxonomy terms assigned to a post.
     *
     * @param int $postId
     * @param string $postType
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function fetchTaxonomies(int $postId, string $postType): array
    {
        $taxonomies = [];
        if (!function_exists('get_object_taxonomies') || !function_exists('wp_get_object_terms')) {
            return [];
        }

        $taxList = get_object_taxonomies($postType);
        foreach ($taxList as $tax) {
            $terms = wp_get_object_terms($postId, $tax);
            if (is_array($terms) && !is_wp_error($terms)) {
                $taxonomies[$tax] = array_map(static function ($t) {
                    return is_object($t) ? [
                        'term_id' => $t->term_id ?? 0,
                        'name'    => $t->name ?? '',
                        'slug'    => $t->slug ?? '',
                    ] : (array) $t;
                }, $terms);
            }
        }

        return $taxonomies;
    }
}
