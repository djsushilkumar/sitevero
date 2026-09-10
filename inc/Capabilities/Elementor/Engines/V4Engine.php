<?php

declare(strict_types=1);

namespace Sitevero\Capabilities\Elementor\Engines;

use Sitevero\Capabilities\Elementor\Normalizer;
use Sitevero\Safety\Sanitizer;

/**
 * Engine for Elementor V4 Atomic architecture (Atomic elements, classes, and design tokens).
 */
final class V4Engine
{
    private Normalizer $normalizer;

    public function __construct(?Normalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new Normalizer();
    }

    /**
     * Build an Atomic Flexbox Container (e-flexbox).
     *
     * @param array<int, array<string, mixed>> $children
     * @param array<string> $classes
     * @param array<string, string> $variables Design token bindings (e-var:*)
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function buildAtomicFlexbox(
        array $children = [],
        array $classes = [],
        array $variables = [],
        array $settings = []
    ): array {
        $this->validateVariables($variables);

        return [
            'id'       => $this->normalizer->generateElementId(),
            'elType'   => 'e-flexbox',
            'classes'  => array_values($classes),
            'props'    => [
                'variables' => $variables,
            ],
            'settings' => $settings,
            'elements' => $children,
        ];
    }

    /**
     * Build an Atomic Grid Container (e-grid).
     *
     * @param array<int, array<string, mixed>> $children
     * @param array<string> $classes
     * @param array<string, string> $variables
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function buildAtomicGrid(
        array $children = [],
        array $classes = [],
        array $variables = [],
        array $settings = []
    ): array {
        $this->validateVariables($variables);

        return [
            'id'       => $this->normalizer->generateElementId(),
            'elType'   => 'e-grid',
            'classes'  => array_values($classes),
            'props'    => [
                'variables' => $variables,
            ],
            'settings' => $settings,
            'elements' => $children,
        ];
    }

    /**
     * Build an Atomic Div Block (e-div-block).
     *
     * @param array<int, array<string, mixed>> $children
     * @param array<string> $classes
     * @param array<string, string> $variables
     * @return array<string, mixed>
     */
    public function buildAtomicDiv(
        array $children = [],
        array $classes = [],
        array $variables = []
    ): array {
        $this->validateVariables($variables);

        return [
            'id'       => $this->normalizer->generateElementId(),
            'elType'   => 'e-div-block',
            'classes'  => array_values($classes),
            'props'    => [
                'variables' => $variables,
            ],
            'elements' => $children,
        ];
    }

    /**
     * Recursively update a V4 Atomic element by ID.
     *
     * @param array<int, array<string, mixed>> $elements
     * @param string $elementId
     * @param array<string, mixed> $updates
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    public function updateAtomicElement(array $elements, string $elementId, array $updates): array
    {
        if (!empty($updates['variables']) && is_array($updates['variables'])) {
            $this->validateVariables($updates['variables']);
        }

        $updated = false;

        $walk = function (array &$list) use (&$walk, $elementId, $updates, &$updated): void {
            foreach ($list as &$item) {
                if (!is_array($item)) {
                    continue;
                }

                if (($item['id'] ?? '') === $elementId) {
                    if (isset($updates['classes']) && is_array($updates['classes'])) {
                        $item['classes'] = array_values(array_unique(array_merge($item['classes'] ?? [], $updates['classes'])));
                    }

                    if (isset($updates['variables']) && is_array($updates['variables'])) {
                        $currentVars = $item['props']['variables'] ?? [];
                        $item['props']['variables'] = array_merge($currentVars, $updates['variables']);
                    }

                    if (isset($updates['settings']) && is_array($updates['settings'])) {
                        $currentSettings = $item['settings'] ?? [];
                        $item['settings'] = array_merge($currentSettings, $updates['settings']);
                    }

                    $updated = true;
                    return;
                }

                if (!empty($item['elements']) && is_array($item['elements'])) {
                    $walk($item['elements']);
                    if ($updated) {
                        return;
                    }
                }
            }
        };

        $walk($elements);

        return [$elements, $updated];
    }

    /**
     * Validate variable bindings to enforce Elementor design token syntax (e-var:*)
     * and strictly block arbitrary CSS property injection.
     *
     * @param array<string, mixed> $variables
     * @throws \InvalidArgumentException If an invalid token or arbitrary CSS is passed
     */
    public function validateVariables(array $variables): void
    {
        foreach ($variables as $property => $token) {
            if (!is_string($token) || !Sanitizer::isValidElementorToken($token)) {
                throw new \InvalidArgumentException(
                    "ERR_INVALID_DESIGN_TOKEN: Variable '{$property}' references invalid token '{$token}'. Must conform to 'e-var:*' and not arbitrary CSS."
                );
            }
        }
    }
}
