<?php

declare(strict_types=1);

namespace Sitevero\Capabilities\Elementor;

use Sitevero\Capabilities\BaseCapability;
use Sitevero\Capabilities\Elementor\Engines\HybridEngine;
use Sitevero\Capabilities\Elementor\Engines\V3Engine;
use Sitevero\Capabilities\Elementor\Engines\V4Engine;

/**
 * Universal capability module coordinating Elementor V3, V4, and Hybrid operations.
 */
final class ElementorModule extends BaseCapability
{
    /**
     * Pro-only widgets that cannot be created without an active Elementor Pro license.
     */
    public const PRO_ONLY_WIDGETS = [
        'form',
        'slides',
        'nav-menu',
        'posts',
        'portfolio',
        'gallery',
        'price-table',
        'price-list',
        'countdown',
        'share-buttons',
        'theme-builder',
        'loop-grid',
        'loop-carousel',
    ];

    private FreeProDetector $detector;
    private Normalizer $normalizer;
    private V3Engine $v3Engine;
    private V4Engine $v4Engine;
    private HybridEngine $hybridEngine;

    public function __construct(
        ?FreeProDetector $detector = null,
        ?Normalizer $normalizer = null,
        ?V3Engine $v3Engine = null,
        ?V4Engine $v4Engine = null,
        ?HybridEngine $hybridEngine = null
    ) {
        $this->detector = $detector ?? new FreeProDetector();
        $this->normalizer = $normalizer ?? new Normalizer();
        $this->v3Engine = $v3Engine ?? new V3Engine($this->normalizer);
        $this->v4Engine = $v4Engine ?? new V4Engine($this->normalizer);
        $this->hybridEngine = $hybridEngine ?? new HybridEngine($this->normalizer, $this->v3Engine, $this->v4Engine);
    }

    public function getId(): string
    {
        return 'elementor.manage_page';
    }

    public function getCategory(): string
    {
        return 'elementor';
    }

    public function getDescription(): string
    {
        return 'Manage Elementor pages across V3, V4 Atomic, and Hybrid architectures: inspect elements, create/update layouts, manage classes and design tokens.';
    }

    public function getSupportedActions(): array
    {
        return ['inspect', 'create', 'update', 'clear_cache'];
    }

    public function getRiskLevel(string $action, array $params = []): string
    {
        return match ($action) {
            'create', 'update' => 'medium',
            default            => 'low',
        };
    }

    public function isAvailable(): bool
    {
        return $this->detector->isActive();
    }

    public function getDetector(): FreeProDetector
    {
        return $this->detector;
    }

    public function getNormalizer(): Normalizer
    {
        return $this->normalizer;
    }

    public function getHybridEngine(): HybridEngine
    {
        return $this->hybridEngine;
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action'     => [
                    'type' => 'string',
                    'enum' => $this->getSupportedActions(),
                ],
                'page_id'    => ['type' => 'integer'],
                'element_id' => ['type' => 'string'],
                'elements'   => ['type' => 'array'],
                'settings'   => ['type' => 'object'],
                'classes'    => ['type' => 'array', 'items' => ['type' => 'string']],
                'variables'  => ['type' => 'object'],
            ],
            'required'   => ['action'],
        ];
    }

    /**
     * Execute an Elementor action.
     *
     * @param string $action
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function execute(string $action, array $params): array
    {
        return match ($action) {
            'inspect'     => $this->executeInspect($params),
            'create'      => $this->executeCreate($params),
            'update'      => $this->executeUpdate($params),
            'clear_cache' => $this->executeClearCache(),
            default       => [
                'error'   => 'ERR_UNKNOWN_ACTION',
                'message' => "Action '{$action}' is not supported by elementor.manage_page.",
            ],
        };
    }

    /**
     * Inspect Elementor page tree and detected generation.
     */
    private function executeInspect(array $params): array
    {
        $pageId = (int) ($params['page_id'] ?? 0);
        if ($pageId <= 0) {
            return ['error' => 'ERR_INVALID_PAGE_ID', 'message' => 'Valid page_id is required.'];
        }

        $rawData = function_exists('get_post_meta') ? get_post_meta($pageId, '_elementor_data', true) : '';
        $pageSettings = function_exists('get_post_meta') ? get_post_meta($pageId, '_elementor_page_settings', true) : [];

        $generation = $this->detector->detectGeneration($rawData);
        $normalized = $this->normalizer->normalize($rawData);

        return [
            'status'        => 'success',
            'page_id'       => $pageId,
            'generation'    => $generation,
            'elements'      => $normalized,
            'page_settings' => is_array($pageSettings) ? $pageSettings : [],
        ];
    }

    /**
     * Create a new Elementor page or elements layout.
     */
    private function executeCreate(array $params): array
    {
        $elements = $params['elements'] ?? [];

        // Check for unsupported Pro-only widgets
        $proError = $this->validateProWidgets($elements);
        if ($proError !== null) {
            return $proError;
        }

        $pageId = (int) ($params['page_id'] ?? 0);

        // If elements not provided, create a default container
        if (empty($elements)) {
            $defaultGen = $this->detector->detectGeneration();
            if ($defaultGen === 'v4') {
                $elements = [$this->v4Engine->buildAtomicFlexbox()];
            } else {
                $elements = [$this->v3Engine->buildContainer()];
            }
        }

        $encodedData = json_encode($elements, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($pageId > 0 && function_exists('update_post_meta')) {
            update_post_meta($pageId, '_elementor_data', $encodedData);
            update_post_meta($pageId, '_elementor_edit_mode', 'builder');
            if (!empty($params['settings'])) {
                update_post_meta($pageId, '_elementor_page_settings', $params['settings']);
            }
            $this->executeClearCache();
        }

        return [
            'status'     => 'success',
            'page_id'    => $pageId,
            'generation' => $this->detector->detectGeneration($encodedData),
            'elements'   => $elements,
            'message'    => 'Elementor page layout created successfully.',
        ];
    }

    /**
     * Update an element in an existing Elementor page.
     */
    private function executeUpdate(array $params): array
    {
        $pageId = (int) ($params['page_id'] ?? 0);
        $elementId = (string) ($params['element_id'] ?? '');

        if ($pageId <= 0) {
            return ['error' => 'ERR_INVALID_PAGE_ID', 'message' => 'Valid page_id is required.'];
        }

        $rawData = function_exists('get_post_meta') ? get_post_meta($pageId, '_elementor_data', true) : ($params['elements'] ?? '[]');

        // Check for unsupported Pro-only widgets in update payload
        if (!empty($params['widgetType']) && in_array($params['widgetType'], self::PRO_ONLY_WIDGETS, true)) {
            if (!$this->detector->isProActive()) {
                return [
                    'error'   => 'ERR_ELEMENTOR_PRO_UNSUPPORTED',
                    'message' => "Widget '{$params['widgetType']}' requires Elementor Pro, which is not active.",
                ];
            }
        }

        try {
            if (!empty($elementId)) {
                $updatedElements = $this->hybridEngine->updateHybridElement($rawData, $elementId, $params);
            } else {
                $updatedElements = $params['elements'] ?? json_decode((string) $rawData, true);
            }
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'ERR_INVALID_ARGUMENT', 'message' => $e->getMessage()];
        } catch (\RuntimeException $e) {
            return ['error' => 'ERR_MUTATION_FAILED', 'message' => $e->getMessage()];
        }

        $encodedData = json_encode($updatedElements, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (function_exists('update_post_meta')) {
            update_post_meta($pageId, '_elementor_data', $encodedData);
            if (!empty($params['settings'])) {
                update_post_meta($pageId, '_elementor_page_settings', $params['settings']);
            }
            $this->executeClearCache();
        }

        return [
            'status'     => 'success',
            'page_id'    => $pageId,
            'generation' => $this->detector->detectGeneration($encodedData),
            'elements'   => $updatedElements,
            'message'    => 'Elementor page updated successfully.',
        ];
    }

    /**
     * Clear Elementor CSS file cache.
     */
    public function executeClearCache(): array
    {
        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }

        return [
            'status'  => 'success',
            'message' => 'Elementor CSS cache cleared successfully.',
        ];
    }

    /**
     * Validate an elements array for unauthorized Pro-only widgets when Pro is not active.
     */
    private function validateProWidgets(array $elements): ?array
    {
        if ($this->detector->isProActive()) {
            return null;
        }

        $walk = function (array $list) use (&$walk): ?string {
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $wType = $item['widgetType'] ?? '';
                if (in_array($wType, self::PRO_ONLY_WIDGETS, true)) {
                    return $wType;
                }

                if (!empty($item['elements']) && is_array($item['elements'])) {
                    $found = $walk($item['elements']);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
            return null;
        };

        $proWidgetFound = $walk($elements);
        if ($proWidgetFound !== null) {
            return [
                'error'   => 'ERR_ELEMENTOR_PRO_UNSUPPORTED',
                'message' => "Widget '{$proWidgetFound}' requires Elementor Pro, which is not active.",
            ];
        }

        return null;
    }
}
