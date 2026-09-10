<?php

declare(strict_types=1);

namespace Sitevero\Capabilities\Gutenberg;

use Sitevero\Safety\Sanitizer;

/**
 * Multi-stage schema-aware block sanitizer.
 * Sanitizes block attributes and content fields granularly BEFORE serialization,
 * strictly avoiding corrupting serialized block comment grammar via blanket string filters.
 */
final class BlockSanitizer
{
    private BlockValidator $validator;

    public function __construct(?BlockValidator $validator = null)
    {
        $this->validator = $validator ?? new BlockValidator();
    }

    /**
     * Sanitize a tree of block definitions.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     * @throws \InvalidArgumentException If an invalid or unpermitted block type is encountered
     */
    public function sanitizeBlocks(array $blocks): array
    {
        $sanitizedBlocks = [];

        foreach ($blocks as $block) {
            $name = (string) ($block['blockName'] ?? '');
            if (!$this->validator->isBlockRegistered($name)) {
                throw new \InvalidArgumentException("ERR_INVALID_BLOCK_TYPE: Block type '{$name}' is not registered or supported.");
            }

            // 1. Sanitize block attributes according to schema
            $rawAttrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $sanitizedAttrs = Sanitizer::sanitizeBlockAttributes($rawAttrs);

            // 2. Sanitize inner HTML content
            $rawHtml = (string) ($block['innerHTML'] ?? '');
            $sanitizedHtml = Sanitizer::sanitizeHtml($rawHtml);

            // 3. Recursively sanitize inner child blocks
            $sanitizedInnerBlocks = [];
            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $sanitizedInnerBlocks = $this->sanitizeBlocks($block['innerBlocks']);
            }

            $sanitizedBlocks[] = [
                'blockName'    => $name,
                'attrs'        => $sanitizedAttrs,
                'innerBlocks'  => $sanitizedInnerBlocks,
                'innerHTML'    => $sanitizedHtml,
                'innerContent' => [$sanitizedHtml],
            ];
        }

        return $sanitizedBlocks;
    }
}
