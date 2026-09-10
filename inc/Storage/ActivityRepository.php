<?php

declare(strict_types=1);

namespace Sitevero\Storage;

/**
 * Repository for recording and querying local audit activity logs.
 */
final class ActivityRepository
{
    /**
     * Record an activity log entry.
     *
     * @param int $userId
     * @param string $clientId
     * @param string $capabilityId
     * @param string $action
     * @param string $target
     * @param string $riskLevel
     * @param string $status
     * @param string|null $snapshotUuid
     * @return int Inserted log ID
     */
    public function log(
        int $userId,
        string $clientId,
        string $capabilityId,
        string $action,
        string $target,
        string $riskLevel,
        string $status = 'success',
        ?string $snapshotUuid = null
    ): int {
        global $wpdb;
        if (!isset($wpdb)) {
            return 0;
        }

        $table = $wpdb->prefix . 'sitevero_activity';
        $inserted = $wpdb->insert(
            $table,
            [
                'user_id'       => $userId,
                'client_id'     => $clientId ?: 'unknown',
                'capability_id' => $capabilityId,
                'action'        => $action,
                'target'        => substr($target, 0, 255),
                'risk_level'    => $riskLevel,
                'status'        => $status,
                'snapshot_uuid' => $snapshotUuid,
                'created_at'    => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return ($inserted !== false) ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Retrieve recent activity log records with pagination.
     *
     * @param int $limit
     * @param int $offset
     * @return array<int, array<string, mixed>>
     */
    public function getRecent(int $limit = 20, int $offset = 0): array
    {
        global $wpdb;
        if (!isset($wpdb)) {
            return [];
        }

        $table = $wpdb->prefix . 'sitevero_activity';
        $outputType = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $results = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset),
            $outputType
        );

        return is_array($results) ? $results : [];
    }

    /**
     * Prune activity logs older than specified retention days (default 30 days).
     *
     * @param int $days
     * @return int Number of deleted rows
     */
    public function pruneOlderThan(int $days = 30): int
    {
        global $wpdb;
        if (!isset($wpdb)) {
            return 0;
        }

        $table = $wpdb->prefix . 'sitevero_activity';
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff)
        );

        return is_numeric($deleted) ? (int) $deleted : 0;
    }
}
