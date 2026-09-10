<?php

declare(strict_types=1);

namespace Sitevero\Capabilities;

/**
 * Base class providing common functionality for capability modules.
 */
abstract class BaseCapability implements CapabilityInterface
{
    /**
     * Helper to verify WordPress current user permissions.
     */
    protected function checkUserCan(string $capability, mixed ...$args): bool
    {
        if (!function_exists('current_user_can')) {
            return false;
        }
        return current_user_can($capability, ...$args);
    }

    /**
     * Default availability check (available by default unless overridden).
     */
    public function isAvailable(): bool
    {
        return true;
    }
}
