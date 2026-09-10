<?php

declare(strict_types=1);

namespace Sitevero\Capabilities\Gutenberg;

/**
 * Serializes structured block arrays into valid WordPress block markup comments.
 */
final class BlockSerializer
{
    /**
     * Serialize structured blocks into Gutenberg HTML comment grammar.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return string
     */
    public function serialize(array $blocks): string
    {
        if (function_exists('serialize_blocks')) {
            $serialized = serialize_blocks($blocks);
            if (!empty($serialized)) {
                return trim($serialized);
            }
        }

        return trim($this->fallbackSerialize($blocks));
    }

    /**
     * Fallback block serialization conforming to WordPress Gutenberg block comment standards.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return string
     */
    public function fallbackSerialize(array $blocks): string
    {
        $output = '';

        foreach ($blocks as $block) {
            $name = (string) ($block['blockName'] ?? 'freeform');
            // In WordPress Gutenberg comment grammar, core/ blocks omit the prefix (e.g. wp:heading, wp:paragraph)
            if (str_starts_with($name, 'core/')) {
                $name = substr($name, 5);
            }
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $attrsJson = !empty($attrs) ? ' ' . json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
            $innerHTML = (string) ($block['innerHTML'] ?? '');
            $innerBlocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];

            if (!empty($innerBlocks)) {
                $childContent = $this->fallbackSerialize($innerBlocks);
                $output .= "<!-- wp:{$name}{$attrsJson} -->\n{$innerHTML}\n{$childContent}\n<!-- /wp:{$name} -->\n\n";
            } elseif (!empty(trim($innerHTML))) {
                $output .= "<!-- wp:{$name}{$attrsJson} -->\n{$innerHTML}\n<!-- /wp:{$name} -->\n\n";
            } else {
                $output .= "<!-- wp:{$name}{$attrsJson} /-->\n\n";
            }
        }

        return $output;
    }
}
