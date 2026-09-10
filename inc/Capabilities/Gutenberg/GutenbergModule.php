<?php

declare(strict_types=1);

namespace Sitevero\Capabilities\Gutenberg;

use Sitevero\Capabilities\BaseCapability;

/**
 * Capability module for WordPress Gutenberg blocks, patterns, and templates.
 * Enforces granular schema-aware attribute and content sanitization before serialization.
 */
final class GutenbergModule extends BaseCapability
{
    private BlockParser $parser;
    private BlockSerializer $serializer;
    private BlockValidator $validator;
    private BlockSanitizer $sanitizer;
    private PatternManager $patternManager;
    private TemplateManager $templateManager;

    public function __construct(
        ?BlockParser $parser = null,
        ?BlockSerializer $serializer = null,
        ?BlockValidator $validator = null,
        ?BlockSanitizer $sanitizer = null,
        ?PatternManager $patternManager = null,
        ?TemplateManager $templateManager = null
    ) {
        $this->parser = $parser ?? new BlockParser();
        $this->serializer = $serializer ?? new BlockSerializer();
        $this->validator = $validator ?? new BlockValidator();
        $this->sanitizer = $sanitizer ?? new BlockSanitizer($this->validator);
        $this->patternManager = $patternManager ?? new PatternManager();
        $this->templateManager = $templateManager ?? new TemplateManager();
    }

    public function getId(): string
    {
        return 'gutenberg.manage_blocks';
    }

    public function getCategory(): string
    {
        return 'gutenberg';
    }

    public function getDescription(): string
    {
        return 'Manage WordPress Gutenberg blocks, patterns, and full-site editing templates with schema-aware validation.';
    }

    public function getSupportedActions(): array
    {
        return ['inspect', 'create', 'update', 'insert_pattern', 'manage_template'];
    }

    public function getRiskLevel(string $action, array $params = []): string
    {
        return match ($action) {
            'create', 'update', 'insert_pattern', 'manage_template' => 'medium',
            default                                                 => 'low',
        };
    }

    public function isAvailable(): bool
    {
        return function_exists('parse_blocks') || true;
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action'        => [
                    'type' => 'string',
                    'enum' => $this->getSupportedActions(),
                ],
                'post_id'       => ['type' => 'integer'],
                'blocks'        => ['type' => 'array'],
                'pattern_name'  => ['type' => 'string'],
                'template'      => ['type' => 'string'],
                'position'      => ['type' => 'string', 'enum' => ['append', 'prepend', 'replace']],
            ],
            'required'   => ['action'],
        ];
    }

    /**
     * Execute a Gutenberg action.
     *
     * @param string $action
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function execute(string $action, array $params): array
    {
        return match ($action) {
            'inspect'         => $this->executeInspect($params),
            'create'          => $this->executeCreate($params),
            'update'          => $this->executeUpdate($params),
            'insert_pattern'  => $this->executeInsertPattern($params),
            'manage_template' => $this->executeManageTemplate($params),
            default           => [
                'error'   => 'ERR_UNKNOWN_ACTION',
                'message' => "Action '{$action}' is not supported by gutenberg.manage_blocks.",
            ],
        };
    }

    /**
     * Inspect Gutenberg blocks for a post.
     */
    private function executeInspect(array $params): array
    {
        $postId = (int) ($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['error' => 'ERR_INVALID_POST_ID', 'message' => 'Valid post_id is required.'];
        }

        $outputType = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $post = function_exists('get_post') ? get_post($postId, $outputType) : null;
        if (!$post) {
            return ['error' => 'ERR_NOT_FOUND', 'message' => "Post #{$postId} not found."];
        }

        $content = (string) ($post['post_content'] ?? '');
        $blocks = $this->parser->parse($content);
        $template = $this->templateManager->getPostTemplate($postId);

        return [
            'status'      => 'success',
            'post_id'     => $postId,
            'block_count' => count($blocks),
            'blocks'      => $blocks,
            'template'    => $template,
        ];
    }

    /**
     * Create or set blocks for a post using the schema-aware sanitization pipeline.
     */
    private function executeCreate(array $params): array
    {
        $postId = (int) ($params['post_id'] ?? 0);
        $rawBlocks = (array) ($params['blocks'] ?? []);

        try {
            // Pipeline: validate -> sanitize attributes -> sanitize HTML -> serialize
            $sanitizedBlocks = $this->sanitizer->sanitizeBlocks($rawBlocks);
            $serializedContent = $this->serializer->serialize($sanitizedBlocks);
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'ERR_VALIDATION_FAILED', 'message' => $e->getMessage()];
        }

        if ($postId > 0 && function_exists('wp_update_post')) {
            wp_update_post([
                'ID'           => $postId,
                'post_content' => $serializedContent,
            ]);
        }

        if (!empty($params['template']) && $postId > 0) {
            $this->templateManager->setPostTemplate($postId, (string) $params['template']);
        }

        // Post-save validation: re-parse content
        $verifiedBlocks = $this->parser->parse($serializedContent);

        return [
            'status'      => 'success',
            'post_id'     => $postId,
            'block_count' => count($verifiedBlocks),
            'blocks'      => $verifiedBlocks,
            'content'     => $serializedContent,
            'message'     => 'Gutenberg blocks created and verified successfully.',
        ];
    }

    /**
     * Update blocks for a post.
     */
    private function executeUpdate(array $params): array
    {
        $postId = (int) ($params['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['error' => 'ERR_INVALID_POST_ID', 'message' => 'Valid post_id is required.'];
        }

        return $this->executeCreate($params);
    }

    /**
     * Insert a registered Block Pattern into post content.
     */
    private function executeInsertPattern(array $params): array
    {
        $postId = (int) ($params['post_id'] ?? 0);
        $patternName = (string) ($params['pattern_name'] ?? '');
        $position = (string) ($params['position'] ?? 'append');

        if (empty($patternName)) {
            return ['error' => 'ERR_MISSING_PATTERN', 'message' => 'Parameter pattern_name is required.'];
        }

        $patternContent = $this->patternManager->getPatternContent($patternName);
        if ($patternContent === null) {
            return ['error' => 'ERR_PATTERN_NOT_FOUND', 'message' => "Block pattern '{$patternName}' not found."];
        }

        $newBlocks = $this->parser->parse($patternContent);

        $existingContent = '';
        if ($postId > 0 && function_exists('get_post')) {
            $outputType = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
            $post = get_post($postId, $outputType);
            $existingContent = (string) ($post['post_content'] ?? '');
        }

        $existingBlocks = !empty($existingContent) ? $this->parser->parse($existingContent) : [];

        $combinedBlocks = match ($position) {
            'prepend' => array_merge($newBlocks, $existingBlocks),
            'replace' => $newBlocks,
            default   => array_merge($existingBlocks, $newBlocks),
        };

        $sanitizedBlocks = $this->sanitizer->sanitizeBlocks($combinedBlocks);
        $serialized = $this->serializer->serialize($sanitizedBlocks);

        if ($postId > 0 && function_exists('wp_update_post')) {
            wp_update_post([
                'ID'           => $postId,
                'post_content' => $serialized,
            ]);
        }

        return [
            'status'       => 'success',
            'post_id'      => $postId,
            'pattern_name' => $patternName,
            'block_count'  => count($sanitizedBlocks),
            'blocks'       => $sanitizedBlocks,
            'message'      => "Pattern '{$patternName}' inserted successfully.",
        ];
    }

    /**
     * Inspect or update page template.
     */
    private function executeManageTemplate(array $params): array
    {
        $postId = (int) ($params['post_id'] ?? 0);
        $template = $params['template'] ?? null;

        if ($template !== null && $postId > 0) {
            $success = $this->templateManager->setPostTemplate($postId, (string) $template);
            return [
                'status'    => $success ? 'success' : 'error',
                'post_id'   => $postId,
                'template'  => $this->templateManager->getPostTemplate($postId),
                'message'   => $success ? "Template set to '{$template}'." : "Invalid template '{$template}'.",
            ];
        }

        return [
            'status'              => 'success',
            'post_id'             => $postId,
            'current_template'    => $postId > 0 ? $this->templateManager->getPostTemplate($postId) : 'default',
            'available_templates' => $this->templateManager->getAvailableTemplates(),
        ];
    }

    public function getParser(): BlockParser
    {
        return $this->parser;
    }

    public function getSerializer(): BlockSerializer
    {
        return $this->serializer;
    }

    public function getSanitizer(): BlockSanitizer
    {
        return $this->sanitizer;
    }

    public function getPatternManager(): PatternManager
    {
        return $this->patternManager;
    }

    public function getTemplateManager(): TemplateManager
    {
        return $this->templateManager;
    }
}
