<?php

declare(strict_types=1);

namespace Sitevero\Storage;

/**
 * Handles database table creations and migrations using WordPress dbDelta().
 */
final class DatabaseMigrator
{
    /**
     * Run migrations on activation or version upgrade.
     */
    public static function migrate(): void
    {
        global $wpdb;

        if (!isset($wpdb)) {
            return;
        }

        $charsetCollate = $wpdb->get_charset_collate();
        $snapshotsTable = $wpdb->prefix . 'sitevero_snapshots';
        $activityTable = $wpdb->prefix . 'sitevero_activity';

        $sqlSnapshots = "CREATE TABLE {$snapshotsTable} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            snapshot_uuid VARCHAR(64) NOT NULL,
            entity_type VARCHAR(32) NOT NULL,
            entity_id VARCHAR(64) NOT NULL,
            capability_id VARCHAR(64) NOT NULL,
            before_state LONGTEXT NOT NULL,
            after_state LONGTEXT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_uuid (snapshot_uuid),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_created_at (created_at)
        ) {$charsetCollate};";

        $sqlActivity = "CREATE TABLE {$activityTable} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            client_id VARCHAR(64) NOT NULL DEFAULT 'unknown',
            capability_id VARCHAR(64) NOT NULL,
            action VARCHAR(64) NOT NULL,
            target VARCHAR(255) NOT NULL DEFAULT '',
            risk_level VARCHAR(16) NOT NULL DEFAULT 'low',
            status VARCHAR(32) NOT NULL DEFAULT 'success',
            snapshot_uuid VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_user_id (user_id),
            KEY idx_capability_action (capability_id, action),
            KEY idx_status (status),
            KEY idx_created_at (created_at)
        ) {$charsetCollate};";

        if (!function_exists('dbDelta')) {
            $upgradePath = ABSPATH . 'wp-admin/includes/upgrade.php';
            if (file_exists($upgradePath)) {
                require_once $upgradePath;
            }
        }

        if (function_exists('dbDelta')) {
            dbDelta($sqlSnapshots);
            dbDelta($sqlActivity);
        }

        if (function_exists('update_option') && defined('SITEVERO_DB_VERSION')) {
            update_option('sitevero_db_version', SITEVERO_DB_VERSION);
        }
    }
}
