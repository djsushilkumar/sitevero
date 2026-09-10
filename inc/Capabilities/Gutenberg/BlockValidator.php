<?php

declare(strict_types=1);

namespace Sitevero\Capabilities\Gutenberg;

/**
 * Validates block types and attributes against registered WordPress block schemas.
 */
final class BlockValidator
{
    /**
     * Standard Core Gutenberg blocks supported out-of-the-box.
     */
    public const SUPPORTED_CORE_BLOCKS = [
        'core/paragraph',
        'core/heading',
        'core/image',
        'core/list',
        'core/list-item',
        'core/quote',
        'core/columns',
        'core/column',
        'core/group',
        'core/buttons',
        'core/button',
        'core/table',
        'core/separator',
        'core/spacer',
    ];

    /**
     * Validate a list of blocks.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<string> Array of error messages, empty if valid
     */
    public function validateBlocks(array $blocks): array
    {
        $errors = [];

        foreach ($blocks as $idx => $block) {
            $name = (string) ($block['blockName'] ?? '');
            if (empty($name)) {
                $errors[] = "Block at index {$idx} is missing blockName.";
                continue;
            }

            if (!$this->isBlockRegistered($name)) {
                $errors[] = "Block '{$name}' at index {$idx} is not registered or supported.";
            }

            // Core-specific validation
            if ($name === 'core/heading' && isset($block['attrs']['level'])) {
                $lvl = (int) $block['attrs']['level'];
                if ($lvl < 1 || $lvl > 6) {
                    $errors[] = "Block 'core/heading' level must be between 1 and 6.";
                }
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $childErrors = $this->validateBlocks($block['innerBlocks']);
                $errors = array_merge($errors, $childErrors);
            }
        }

        return $errors;
    }

    /**
     * Check whether a block name is valid.
     */
    public function isBlockRegistered(string $blockName): bool
    {
        $normalized = !str_contains($blockName, '/') ? 'core/' . $blockName : $blockName;

        if (in_array($normalized, self::SUPPORTED_CORE_BLOCKS, true)) {
            return true;
        }

        if (class_exists('\WP_Block_Type_Registry')) {
            return \WP_Block_Type_Registry::get_instance()->is_registered($blockName);
        }

        // Allow third-party namespace format (e.g. 'my-plugin/custom-block')
        return (bool) preg_match('/^[a-z0-9\-]+\/[a-z0-9\-]+$/', $blockName);
    }
}
