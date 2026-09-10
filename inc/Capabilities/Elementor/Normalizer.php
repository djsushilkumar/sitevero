<?php

declare(strict_types=1);

namespace Sitevero\Capabilities\Elementor;

/**
 * Universal schema normalizer providing bidirectional translation between
 * external agent JSON representations and internal Elementor element trees.
 */
final class Normalizer
{
    /**
     * Normalize a raw Elementor elements tree into a clean structured format.
     *
     * @param array<string, mixed>|string $rawElements
     * @return array<int, array<string, mixed>>
     */
    public function normalize(mixed $rawElements): array
    {
        $elements = is_string($rawElements) ? json_decode($rawElements, true) : $rawElements;

        if (!is_array($elements)) {
            return [];
        }

        $normalized = [];
        foreach ($elements as $node) {
            if (is_array($node)) {
                $normalized[] = $this->normalizeNode($node);
            }
        }

        return $normalized;
    }

    /**
     * Normalize a single Elementor node.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public function normalizeNode(array $node): array
    {
        $id = (string) ($node['id'] ?? $this->generateElementId());
        $elType = (string) ($node['elType'] ?? 'widget');
        $widgetType = isset($node['widgetType']) ? (string) $node['widgetType'] : null;

        $generation = $this->determineNodeGeneration($node);

        $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];
        $classes = isset($node['classes']) && is_array($node['classes']) ? array_values($node['classes']) : [];
        $variables = isset($node['variables']) && is_array($node['variables'])
            ? $node['variables']
            : (isset($node['props']['variables']) && is_array($node['props']['variables']) ? $node['props']['variables'] : []);

        $children = [];
        if (!empty($node['elements']) && is_array($node['elements'])) {
            foreach ($node['elements'] as $child) {
                if (is_array($child)) {
                    $children[] = $this->normalizeNode($child);
                }
            }
        }

        return [
            'id'         => $id,
            'elType'     => $elType,
            'widgetType' => $widgetType,
            'generation' => $generation,
            'settings'   => $settings,
            'classes'    => $classes,
            'variables'  => $variables,
            'elements'   => $children,
            'raw_node'   => $node,
        ];
    }

    /**
     * Denormalize a normalized tree back into Elementor's native array structure.
     *
     * @param array<int, array<string, mixed>> $normalizedTree
     * @return array<int, array<string, mixed>>
     */
    public function denormalize(array $normalizedTree): array
    {
        $denormalized = [];

        foreach ($normalizedTree as $node) {
            if (is_array($node)) {
                $denormalized[] = $this->denormalizeNode($node);
            }
        }

        return $denormalized;
    }

    /**
     * Denormalize a single node into Elementor internal format.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public function denormalizeNode(array $node): array
    {
        $generation = $node['generation'] ?? 'v3';

        // 1. Opaque unknown node: return original untouched raw node
        if ($generation === 'opaque' && isset($node['raw_node']) && is_array($node['raw_node'])) {
            return $node['raw_node'];
        }

        $id = (string) ($node['id'] ?? $this->generateElementId());
        $elType = (string) ($node['elType'] ?? 'widget');

        $children = [];
        if (!empty($node['elements']) && is_array($node['elements'])) {
            foreach ($node['elements'] as $child) {
                if (is_array($child)) {
                    $children[] = $this->denormalizeNode($child);
                }
            }
        }

        // 2. Elementor V4 Atomic Node
        if ($generation === 'v4') {
            $denorm = [
                'id'       => $id,
                'elType'   => $elType,
                'classes'  => $node['classes'] ?? [],
                'props'    => [
                    'variables' => $node['variables'] ?? [],
                ],
                'elements' => $children,
            ];

            if (!empty($node['settings'])) {
                $denorm['settings'] = $node['settings'];
            }

            return $denorm;
        }

        // 3. Elementor V3 Node (Section / Column / Container / Widget)
        $denorm = [
            'id'       => $id,
            'elType'   => $elType,
            'settings' => $node['settings'] ?? [],
            'elements' => $children,
        ];

        if (!empty($node['widgetType'])) {
            $denorm['widgetType'] = $node['widgetType'];
        }

        return $denorm;
    }

    /**
     * Classify a node into 'v3', 'v4', or 'opaque'.
     *
     * @param array<string, mixed> $node
     * @return string
     */
    public function determineNodeGeneration(array $node): string
    {
        $elType = (string) ($node['elType'] ?? '');

        // Verified V4 Atomic layout element types or explicit V4 properties
        if (in_array($elType, ['e-div-block', 'e-flexbox', 'e-grid'], true)
            || (!empty($node['classes']) && isset($node['props']))
        ) {
            return 'v4';
        }

        // Standard V3 layout types and standard widgets
        if (in_array($elType, ['section', 'column', 'container', 'widget'], true)) {
            return 'v3';
        }

        // Unknown or custom third-party structure -> mark as opaque
        return 'opaque';
    }

    /**
     * Generate a 7-character random hexadecimal ID conforming to native Elementor IDs.
     */
    public function generateElementId(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 7);
    }
}
