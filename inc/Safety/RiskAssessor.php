<?php

declare(strict_types=1);

namespace Sitevero\Safety;

use Sitevero\Capabilities\CapabilityRegistry;

/**
 * Assesses risk tiers and enforces safety limits on operations.
 */
final class RiskAssessor
{
    public const TIER_LOW = 'low';
    public const TIER_MEDIUM = 'medium';
    public const TIER_HIGH = 'high';
    public const TIER_DESTRUCTIVE = 'destructive';

    public const MAX_BULK_ITEMS = 50;

    private CapabilityRegistry $registry;

    public function __construct(CapabilityRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Determine the risk level for an incoming capability action.
     *
     * @param string $capabilityId
     * @param string $action
     * @param array<string, mixed> $params
     * @return string 'low', 'medium', 'high', or 'destructive'
     */
    public function assess(string $capabilityId, string $action, array $params = []): string
    {
        $capability = $this->registry->get($capabilityId);

        if ($capability !== null) {
            return $capability->getRiskLevel($action, $params);
        }

        // Fallback default rules
        return match ($action) {
            'delete', 'trash'                                                  => self::TIER_DESTRUCTIVE,
            'activate', 'deactivate', 'install_wporg', 'install_zip', 'switch' => self::TIER_HIGH,
            'create', 'update', 'upload', 'replace', 'batch_update'            => self::TIER_MEDIUM,
            default                                                            => self::TIER_LOW,
        };
    }

    /**
     * Check if an action requires a transient confirmation token.
     */
    public function requiresConfirmation(string $riskLevel): bool
    {
        return in_array($riskLevel, [self::TIER_HIGH, self::TIER_DESTRUCTIVE], true);
    }

    /**
     * Check if an action requires an automatic pre-execution snapshot.
     */
    public function requiresSnapshot(string $riskLevel): bool
    {
        return in_array($riskLevel, [self::TIER_MEDIUM, self::TIER_HIGH, self::TIER_DESTRUCTIVE], true);
    }

    /**
     * Validate bulk item count against the 50-item safety limit.
     *
     * @param array<mixed> $items
     * @return bool
     */
    public function validateBulkLimit(array $items): bool
    {
        return count($items) <= self::MAX_BULK_ITEMS;
    }
}
