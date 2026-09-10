<?php

declare(strict_types=1);

namespace Sitevero\Capabilities\Elementor\Engines;

use Sitevero\Capabilities\Elementor\Normalizer;

/**
 * Hybrid engine coordinating pages where Elementor V3 and V4 elements coexist.
 * Strictly guarantees non-conversion: mutating a V3 branch never converts adjacent V4 branches,
 * and mutating a V4 branch never converts adjacent V3 branches.
 */
final class HybridEngine
{
    private Normalizer $normalizer;
    private V3Engine $v3Engine;
    private V4Engine $v4Engine;

    public function __construct(
        ?Normalizer $normalizer = null,
        ?V3Engine $v3Engine = null,
        ?V4Engine $v4Engine = null
    ) {
        $this->normalizer = $normalizer ?? new Normalizer();
        $this->v3Engine = $v3Engine ?? new V3Engine($this->normalizer);
        $this->v4Engine = $v4Engine ?? new V4Engine($this->normalizer);
    }

    /**
     * Update an element in a hybrid tree while preserving adjacent branches and opaque nodes.
     *
     * @param array<int, array<string, mixed>>|string $elements Raw or normalized element tree
     * @param string $elementId ID of the element to update
     * @param array<string, mixed> $updates Updates payload (settings, classes, variables)
     * @return array<int, array<string, mixed>> Updated native Elementor tree
     * @throws \RuntimeException If target element is an unsupported/opaque structure or not found
     */
    public function updateHybridElement(mixed $elements, string $elementId, array $updates): array
    {
        $normalizedTree = $this->normalizer->normalize($elements);

        $targetNode = $this->findNodeById($normalizedTree, $elementId);
        if ($targetNode === null) {
            throw new \RuntimeException("ERR_ELEMENTOR_ELEMENT_NOT_FOUND: Element '{$elementId}' not found in page tree.");
        }

        $generation = $targetNode['generation'] ?? 'v3';

        // 1. Safety check: Opaque/unsupported structures must not be blindly mutated
        if ($generation === 'opaque') {
            throw new \RuntimeException("ERR_ELEMENTOR_UNSUPPORTED_STRUCTURE: Element '{$elementId}' is an unrecognized or opaque structure and cannot be mutated safely.");
        }

        // 2. Dispatch to appropriate sub-engine without converting adjacent elements
        if ($generation === 'v4') {
            $updatedTree = $this->applyV4Updates($normalizedTree, $elementId, $updates);
        } else {
            $updatedTree = $this->applyV3Updates($normalizedTree, $elementId, $updates);
        }

        // 3. Denormalize back to native format, preserving all untouched nodes and opaque structures
        return $this->normalizer->denormalize($updatedTree);
    }

    /**
     * Locate a node in a normalized tree by ID.
     *
     * @param array<int, array<string, mixed>> $tree
     * @param string $id
     * @return array<string, mixed>|null
     */
    public function findNodeById(array $tree, string $id): ?array
    {
        foreach ($tree as $node) {
            if (!is_array($node)) {
                continue;
            }

            if (($node['id'] ?? '') === $id) {
                return $node;
            }

            if (!empty($node['elements']) && is_array($node['elements'])) {
                $found = $this->findNodeById($node['elements'], $id);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function applyV3Updates(array $tree, string $elementId, array $updates): array
    {
        $walk = function (array &$list) use (&$walk, $elementId, $updates): bool {
            foreach ($list as &$item) {
                if (!is_array($item)) {
                    continue;
                }

                if (($item['id'] ?? '') === $elementId) {
                    $item['settings'] = array_merge($item['settings'] ?? [], $updates['settings'] ?? $updates);
                    return true;
                }

                if (!empty($item['elements']) && is_array($item['elements'])) {
                    if ($walk($item['elements'])) {
                        return true;
                    }
                }
            }
            return false;
        };

        $walk($tree);
        return $tree;
    }

    private function applyV4Updates(array $tree, string $elementId, array $updates): array
    {
        if (!empty($updates['variables']) && is_array($updates['variables'])) {
            $this->v4Engine->validateVariables($updates['variables']);
        }

        $walk = function (array &$list) use (&$walk, $elementId, $updates): bool {
            foreach ($list as &$item) {
                if (!is_array($item)) {
                    continue;
                }

                if (($item['id'] ?? '') === $elementId) {
                    if (isset($updates['classes']) && is_array($updates['classes'])) {
                        $item['classes'] = array_values(array_unique(array_merge($item['classes'] ?? [], $updates['classes'])));
                    }

                    if (isset($updates['variables']) && is_array($updates['variables'])) {
                        $item['variables'] = array_merge($item['variables'] ?? [], $updates['variables']);
                    }

                    if (isset($updates['settings']) && is_array($updates['settings'])) {
                        $item['settings'] = array_merge($item['settings'] ?? [], $updates['settings']);
                    }

                    return true;
                }

                if (!empty($item['elements']) && is_array($item['elements'])) {
                    if ($walk($item['elements'])) {
                        return true;
                    }
                }
            }
            return false;
        };

        $walk($tree);
        return $tree;
    }

    public function getNormalizer(): Normalizer
    {
        return $this->normalizer;
    }

    public function getV3Engine(): V3Engine
    {
        return $this->v3Engine;
    }

    public function getV4Engine(): V4Engine
    {
        return $this->v4Engine;
    }
}
