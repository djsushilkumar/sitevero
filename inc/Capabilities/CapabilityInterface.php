<?php

declare(strict_types=1);

namespace Sitevero\Capabilities;

/**
 * Standard contract for all Sitevero capability modules.
 */
interface CapabilityInterface
{
    /**
     * Unique identifier for capability (e.g., 'content.manage_post', 'elementor.manage_page').
     */
    public function getId(): string;

    /**
     * Category group (e.g., 'content', 'media', 'elementor', 'gutenberg', 'system', 'users').
     */
    public function getCategory(): string;

    /**
     * Human-readable description for discovery.
     */
    public function getDescription(): string;

    /**
     * List of supported actions (e.g. ['create', 'read', 'update', 'delete']).
     *
     * @return array<string>
     */
    public function getSupportedActions(): array;

    /**
     * Risk level for a given action ('low', 'medium', 'high', 'destructive').
     *
     * @param array<string, mixed> $params
     */
    public function getRiskLevel(string $action, array $params = []): string;

    /**
     * Parameter schema array for inspection.
     *
     * @return array<string, mixed>
     */
    public function getSchema(): array;

    /**
     * Whether this capability is environmentally available on the current site.
     */
    public function isAvailable(): bool;

    /**
     * Execute a capability action.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function execute(string $action, array $params): array;
}
