<?php

declare(strict_types=1);

namespace Sitevero\Capabilities\Elementor;

/**
 * Detects Elementor Core, Elementor Pro active states, and architectural generations.
 */
final class FreeProDetector
{
    /**
     * Check if Elementor Core is installed.
     */
    public function isInstalled(): bool
    {
        if (defined('ELEMENTOR_VERSION')) {
            return true;
        }

        if (function_exists('get_plugins')) {
            $plugins = get_plugins();
            return isset($plugins['elementor/elementor.php']);
        }

        return false;
    }

    /**
     * Check if Elementor Core is active.
     */
    public function isActive(): bool
    {
        return defined('ELEMENTOR_VERSION') || (function_exists('is_plugin_active') && is_plugin_active('elementor/elementor.php'));
    }

    /**
     * Get Elementor Core version.
     */
    public function getVersion(): ?string
    {
        if (defined('ELEMENTOR_VERSION')) {
            return ELEMENTOR_VERSION;
        }
        return null;
    }

    /**
     * Check if Elementor Pro is active.
     */
    public function isProActive(): bool
    {
        return defined('ELEMENTOR_PRO_VERSION') || (function_exists('is_plugin_active') && is_plugin_active('elementor-pro/elementor-pro.php'));
    }

    /**
     * Get Elementor Pro version.
     */
    public function getProVersion(): ?string
    {
        if (defined('ELEMENTOR_PRO_VERSION')) {
            return ELEMENTOR_PRO_VERSION;
        }
        return null;
    }

    /**
     * Detect architectural generation from elements data or environment.
     *
     * @param array<string, mixed>|string|null $data
     * @return string 'v3', 'v4', 'hybrid', 'empty', 'unknown', or 'not_installed'
     */
    public function detectGeneration(mixed $data = null): string
    {
        if (!$this->isActive()) {
            return 'not_installed';
        }

        if ($data === null) {
            $ver = $this->getVersion();
            if ($ver !== null && version_compare($ver, '4.0.0', '>=')) {
                return 'v4';
            }
            return 'v3';
        }

        $elements = is_string($data) ? json_decode($data, true) : $data;
        if (!is_array($elements) || empty($elements)) {
            return 'empty';
        }

        $hasV3 = false;
        $hasV4 = false;

        $checkNode = function (array $node) use (&$checkNode, &$hasV3, &$hasV4): void {
            $elType = $node['elType'] ?? '';
            $widgetType = $node['widgetType'] ?? '';

            // Verified V4 Atomic layout containers and structured properties
            if (in_array($elType, ['e-div-block', 'e-flexbox', 'e-grid'], true)
                || (!empty($node['classes']) && isset($node['props']))
            ) {
                $hasV4 = true;
            } elseif (in_array($elType, ['section', 'column', 'container'], true)
                || !empty($widgetType)
            ) {
                $hasV3 = true;
            }

            if (!empty($node['elements']) && is_array($node['elements'])) {
                foreach ($node['elements'] as $child) {
                    if (is_array($child)) {
                        $checkNode($child);
                    }
                }
            }
        };

        foreach ($elements as $topNode) {
            if (is_array($topNode)) {
                $checkNode($topNode);
            }
        }

        if ($hasV3 && $hasV4) {
            return 'hybrid';
        }
        if ($hasV4) {
            return 'v4';
        }
        if ($hasV3) {
            return 'v3';
        }

        return 'unknown';
    }
}
