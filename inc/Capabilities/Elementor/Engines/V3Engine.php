<?php

declare(strict_types=1);

namespace Sitevero\Capabilities\Elementor\Engines;

use Sitevero\Capabilities\Elementor\Normalizer;

/**
 * Engine for Elementor V3 Flexbox Containers, Sections, Columns, and Widgets.
 */
final class V3Engine
{
    private Normalizer $normalizer;

    public function __construct(?Normalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new Normalizer();
    }

    /**
     * Build a V3 Flexbox Container element.
     *
     * @param array<int, array<string, mixed>> $children
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function buildContainer(array $children = [], array $settings = []): array
    {
        return [
            'id'         => $this->normalizer->generateElementId(),
            'elType'     => 'container',
            'isInner'    => false,
            'settings'   => $settings,
            'elements'   => $children,
        ];
    }

    /**
     * Build a V3 widget element.
     *
     * @param string $widgetType
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function buildWidget(string $widgetType, array $settings = []): array
    {
        return [
            'id'         => $this->normalizer->generateElementId(),
            'elType'     => 'widget',
            'widgetType' => $widgetType,
            'settings'   => $settings,
            'elements'   => [],
        ];
    }

    /**
     * Build a core heading widget.
     */
    public function buildHeading(string $title, string $tag = 'h2', array $styling = []): array
    {
        $settings = array_merge([
            'title'       => $title,
            'header_size' => $tag,
        ], $styling);

        return $this->buildWidget('heading', $settings);
    }

    /**
     * Build a core button widget.
     */
    public function buildButton(string $text, string $url = '#', array $styling = []): array
    {
        $settings = array_merge([
            'text' => $text,
            'link' => ['url' => $url, 'is_external' => false, 'nofollow' => false],
        ], $styling);

        return $this->buildWidget('button', $settings);
    }

    /**
     * Recursively update an element within an elements tree by ID.
     *
     * @param array<int, array<string, mixed>> $elements
     * @param string $elementId
     * @param array<string, mixed> $newSettings
     * @return array{0: array<int, array<string, mixed>>, 1: bool} Tuple of [updatedElements, found]
     */
    public function updateElementSettings(array $elements, string $elementId, array $newSettings): array
    {
        $updated = false;

        $walk = function (array &$list) use (&$walk, $elementId, $newSettings, &$updated): void {
            foreach ($list as &$item) {
                if (!is_array($item)) {
                    continue;
                }

                if (($item['id'] ?? '') === $elementId) {
                    $currentSettings = is_array($item['settings'] ?? null) ? $item['settings'] : [];
                    $item['settings'] = array_merge($currentSettings, $newSettings);
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
}
