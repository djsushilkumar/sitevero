<?php

declare(strict_types=1);

namespace Sitevero\Safety;

/**
 * Manages 5-minute cryptographic HMAC confirmation tokens for high and destructive risk operations.
 */
final class ConfirmationGate
{
    public const TOKEN_TTL_SECONDS = 300; // 5 minutes

    /**
     * Generate a 5-minute transient HMAC confirmation token.
     *
     * @param int $userId
     * @param string $capabilityId
     * @param string $action
     * @param string $riskLevel
     * @return array<string, mixed>
     */
    public function generateConfirmationRequest(int $userId, string $capabilityId, string $action, string $riskLevel): array
    {
        $salt = function_exists('wp_salt') ? wp_salt('auth') : 'sitevero_default_secure_salt';
        $entropy = bin2hex(random_bytes(16)) . ':' . microtime(true);
        $token = hash_hmac('sha256', "{$userId}:{$capabilityId}:{$action}:{$entropy}", $salt);

        $payload = [
            'user_id'       => $userId,
            'capability_id' => $capabilityId,
            'action'        => $action,
            'risk_level'    => $riskLevel,
            'created_at'    => time(),
            'expires_at'    => time() + self::TOKEN_TTL_SECONDS,
        ];

        if (function_exists('set_transient')) {
            set_transient('sitevero_cfm_' . $token, $payload, self::TOKEN_TTL_SECONDS);
        }

        return [
            'confirmation_required' => true,
            'confirmation_token'    => $token,
            'risk_level'            => $riskLevel,
            'expires_in_seconds'    => self::TOKEN_TTL_SECONDS,
            'message'               => "Operation '{$action}' on '{$capabilityId}' requires confirmation. Pass 'confirmation_token' within 5 minutes to proceed.",
        ];
    }

    /**
     * Verify and immediately consume a confirmation token to prevent replay attacks.
     *
     * @param string $token
     * @param int $userId
     * @param string $capabilityId
     * @param string $action
     * @return bool
     */
    public function verify(string $token, int $userId, string $capabilityId, string $action): bool
    {
        if (empty($token) || strlen($token) !== 64) {
            return false;
        }

        if (!function_exists('get_transient') || !function_exists('delete_transient')) {
            return false;
        }

        $transientKey = 'sitevero_cfm_' . $token;
        $payload = get_transient($transientKey);

        if (!is_array($payload)) {
            return false;
        }

        // Verify bounds and parameters match
        if (
            (int) ($payload['user_id'] ?? 0) !== $userId ||
            ($payload['capability_id'] ?? '') !== $capabilityId ||
            ($payload['action'] ?? '') !== $action ||
            time() > ($payload['expires_at'] ?? 0)
        ) {
            delete_transient($transientKey);
            return false;
        }

        // Token is valid; immediately delete it (one-time use)
        delete_transient($transientKey);
        return true;
    }
}
