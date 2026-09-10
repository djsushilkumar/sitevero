<?php

declare(strict_types=1);

namespace Sitevero\Capabilities;

/**
 * Registry storing and managing capability modules and admin enablement toggles.
 */
final class CapabilityRegistry
{
    /**
     * @var array<string, CapabilityInterface>
     */
    private array $capabilities = [];

    /**
     * @var array<string, bool>|null
     */
    private ?array $settingsCache = null;

    /**
     * Register a capability module.
     */
    public function register(CapabilityInterface $capability): void
    {
        $this->capabilities[$capability->getId()] = $capability;
    }

    /**
     * Retrieve a registered capability module.
     */
    public function get(string $id): ?CapabilityInterface
    {
        return $this->capabilities[$id] ?? null;
    }

    /**
     * Check if a capability module exists.
     */
    public function has(string $id): bool
    {
        return isset($this->capabilities[$id]);
    }

    /**
     * Get all registered capability modules.
     *
     * @return array<string, CapabilityInterface>
     */
    public function getAll(): array
    {
        return $this->capabilities;
    }

    /**
     * Check if capability is enabled via admin settings option.
     */
    public function isEnabled(string $id): bool
    {
        if ($this->settingsCache === null) {
            $settings = function_exists('get_option') ? get_option('sitevero_capability_settings', []) : [];
            $this->settingsCache = is_array($settings) ? $settings : [];
        }

        // Enabled by default unless explicitly disabled in settings
        return !isset($this->settingsCache[$id]) || (bool) $this->settingsCache[$id];
    }

    /**
     * Clear cached settings.
     */
    public function refreshSettings(): void
    {
        $this->settingsCache = null;
    }

    /**
     * Generate compact discovery catalog for sitevero_discover.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDiscoveryCatalog(): array
    {
        $catalog = [];

        foreach ($this->capabilities as $cap) {
            $catalog[] = [
                'id'                => $cap->getId(),
                'category'          => $cap->getCategory(),
                'description'       => $cap->getDescription(),
                'enabled'           => $this->isEnabled($cap->getId()),
                'available'         => $cap->isAvailable(),
                'supported_actions' => $cap->getSupportedActions(),
            ];
        }

        return $catalog;
    }
}
