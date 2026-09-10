<?php

declare(strict_types=1);

namespace Sitevero\Capabilities\Gutenberg;

/**
 * Parses raw Gutenberg post content into normalized structured block trees.
 */
final class BlockParser
{
    /**
     * Parse raw post HTML content into structured block arrays.
     *
     * @param string $content
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $content): array
    {
        if (empty(trim($content))) {
            return [];
        }

        if (function_exists('parse_blocks')) {
            $rawBlocks = parse_blocks($content);
        } else {
            $rawBlocks = $this->fallbackParse($content);
        }

        return $this->normalizeBlocks($rawBlocks);
    }

    /**
     * Clean and normalize raw parsed blocks, removing superfluous whitespace-only delimiters.
     *
     * @param array<int, array<string, mixed>> $rawBlocks
     * @return array<int, array<string, mixed>>
     */
    public function normalizeBlocks(array $rawBlocks): array
    {
        $normalized = [];

        foreach ($rawBlocks as $block) {
            $blockName = $block['blockName'] ?? null;
            $innerHTML = (string) ($block['innerHTML'] ?? '');

            // WordPress core default namespace: if no slash, default to core/
            if ($blockName !== null && !str_contains($blockName, '/')) {
                $blockName = 'core/' . $blockName;
            }

            // Skip null blockName with empty or whitespace-only innerHTML
            if ($blockName === null && empty(trim($innerHTML))) {
                continue;
            }

            $childBlocks = [];
            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $childBlocks = $this->normalizeBlocks($block['innerBlocks']);
            }

            $normalized[] = [
                'blockName'    => $blockName ?? 'core/freeform',
                'attrs'        => is_array($block['attrs'] ?? null) ? $block['attrs'] : [],
                'innerBlocks'  => $childBlocks,
                'innerHTML'    => $innerHTML,
                'innerContent' => is_array($block['innerContent'] ?? null) ? $block['innerContent'] : [$innerHTML],
            ];
        }

        return $normalized;
    }

    /**
     * Minimal fallback block parser when WordPress parse_blocks() is not loaded.
     *
     * @param string $content
     * @return array<int, array<string, mixed>>
     */
    private function fallbackParse(string $content): array
    {
        $pattern = '/<!--\s+wp:([a-z0-9\/-]+)(?:\s+(\{.*?\}))?\s+(?:\/-->|-->(.*?)<!--\s+\/wp:\1\s+-->)/s';
        $blocks = [];

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $blockName = $match[1];
                $attrsJson = $match[2] ?? '{}';
                $innerHTML = $match[3] ?? '';

                $attrs = json_decode($attrsJson, true);

                $blocks[] = [
                    'blockName'    => $blockName,
                    'attrs'        => is_array($attrs) ? $attrs : [],
                    'innerBlocks'  => [],
                    'innerHTML'    => $innerHTML,
                    'innerContent' => [$innerHTML],
                ];
            }
        }

        return $blocks;
    }
}
