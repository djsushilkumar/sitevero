<?php

declare(strict_types=1);

namespace Sitevero\Storage;

/**
 * Repository for managing pre-execution entity snapshots with a strict 2MB cap.
 */
final class SnapshotRepository
{
    public const MAX_SNAPSHOT_BYTES = 2097152; // 2MB cap

    /**
     * Create a new snapshot record.
     *
     * @param string $entityType
     * @param string $entityId
     * @param string $capabilityId
     * @param array<string, mixed> $beforeState
     * @param int $userId
     * @return string Generated snapshot UUID
     * @throws \RuntimeException If snapshot payload exceeds 2MB
     */
    public function create(
        string $entityType,
        string $entityId,
        string $capabilityId,
        array $beforeState,
        int $userId
    ): string {
        $encoded = json_encode($beforeState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode before_state to JSON.');
        }

        if (strlen($encoded) > self::MAX_SNAPSHOT_BYTES) {
            throw new \RuntimeException("ERR_SNAPSHOT_SIZE_EXCEEDED: Snapshot payload size (" . strlen($encoded) . " bytes) exceeds 2MB limit.");
        }

        $uuid = 'snp_' . bin2hex(random_bytes(16));

        global $wpdb;
        if (isset($wpdb)) {
            $table = $wpdb->prefix . 'sitevero_snapshots';
            $wpdb->insert(
                $table,
                [
                    'snapshot_uuid' => $uuid,
                    'entity_type'   => $entityType,
                    'entity_id'     => $entityId,
                    'capability_id' => $capabilityId,
                    'before_state'  => $encoded,
                    'after_state'   => null,
                    'user_id'       => $userId,
                    'created_at'    => current_time('mysql'),
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
            );
        }

        return $uuid;
    }

    /**
     * Find a snapshot by UUID.
     *
     * @param string $uuid
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        global $wpdb;
        if (!isset($wpdb)) {
            return null;
        }

        $table = $wpdb->prefix . 'sitevero_snapshots';
        $outputType = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE snapshot_uuid = %s", $uuid),
            $outputType
        );

        if (!$row || !is_array($row)) {
            return null;
        }

        $row['before_state'] = json_decode($row['before_state'] ?? '{}', true);
        $row['after_state'] = !empty($row['after_state']) ? json_decode($row['after_state'], true) : null;

        return $row;
    }

    /**
     * Update post-execution after_state for an existing snapshot.
     *
     * @param string $uuid
     * @param array<string, mixed> $afterState
     * @return bool
     */
    public function updateAfterState(string $uuid, array $afterState): bool
    {
        $encoded = json_encode($afterState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > self::MAX_SNAPSHOT_BYTES) {
            return false;
        }

        global $wpdb;
        if (!isset($wpdb)) {
            return false;
        }

        $table = $wpdb->prefix . 'sitevero_snapshots';
        $result = $wpdb->update(
            $table,
            ['after_state' => $encoded],
            ['snapshot_uuid' => $uuid],
            ['%s'],
            ['%s']
        );

        return $result !== false;
    }

    /**
     * Prune snapshots older than specified retention days (default 30 days).
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

        $table = $wpdb->prefix . 'sitevero_snapshots';
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff)
        );

        return is_numeric($deleted) ? (int) $deleted : 0;
    }
}
