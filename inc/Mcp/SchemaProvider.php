<?php

declare(strict_types=1);

namespace Sitevero\Mcp;

/**
 * Provides standard JSON Schemas for the 4 Universal Meta-Tools.
 */
final class SchemaProvider
{
    /**
     * Get tool definition schema for sitevero_discover.
     *
     * @return array<string, mixed>
     */
    public static function getDiscoverSchema(): array
    {
        return [
            'name'        => 'sitevero_discover',
            'description' => 'Discovers environment details, detected builder status (Elementor V3/V4/Hybrid and Gutenberg), active theme, installed plugins, and the catalog of available Sitevero capabilities.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'include_all' => [
                        'type'        => 'boolean',
                        'description' => 'Optional flag to include extended environment details. Defaults to true.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Get tool definition schema for sitevero_inspect.
     *
     * @return array<string, mixed>
     */
    public static function getInspectSchema(): array
    {
        return [
            'name'        => 'sitevero_inspect',
            'description' => 'Inspects the JSON input parameter schema for a specific capability or queries the live state of target WordPress/builder entities.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'target'        => [
                        'type'        => 'string',
                        'enum'        => ['schema', 'entity'],
                        'description' => 'Target of inspection: "schema" for capability input schemas, or "entity" for live WordPress/builder states.',
                    ],
                    'capability_id' => [
                        'type'        => 'string',
                        'description' => 'Capability identifier when target is "schema".',
                    ],
                    'entity_type'   => [
                        'type'        => 'string',
                        'enum'        => ['post', 'page', 'elementor_tree', 'gutenberg_blocks', 'media', 'plugins', 'themes', 'users', 'settings'],
                        'description' => 'Type of entity when target is "entity".',
                    ],
                    'entity_id'     => [
                        'type'        => ['string', 'integer'],
                        'description' => 'Identifier of the entity to inspect.',
                    ],
                ],
                'required'   => ['target'],
            ],
        ];
    }

    /**
     * Get tool definition schema for sitevero_execute.
     *
     * @return array<string, mixed>
     */
    public static function getExecuteSchema(): array
    {
        return [
            'name'        => 'sitevero_execute',
            'description' => 'Executes a supported capability. Assesses risk, captures a pre-execution snapshot, executes the action, validates the result internally, and returns the updated state.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'capability_id'      => [
                        'type'        => 'string',
                        'description' => 'Exact capability identifier to execute.',
                    ],
                    'action'             => [
                        'type'        => 'string',
                        'description' => 'Supported action on the capability.',
                    ],
                    'parameters'         => [
                        'type'        => 'object',
                        'description' => 'Payload arguments matching the capability schema.',
                    ],
                    'confirmation_token' => [
                        'type'        => 'string',
                        'description' => '5-minute cryptographic confirmation token required for high-risk and destructive actions.',
                    ],
                ],
                'required'   => ['capability_id', 'action', 'parameters'],
            ],
        ];
    }

    /**
     * Get tool definition schema for sitevero_rollback.
     *
     * @return array<string, mixed>
     */
    public static function getRollbackSchema(): array
    {
        return [
            'name'        => 'sitevero_rollback',
            'description' => 'Rolls back a previously executed action to its captured pre-execution state using the snapshot UUID.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'snapshot_uuid' => [
                        'type'        => 'string',
                        'description' => 'Unique UUID of the snapshot to restore.',
                    ],
                ],
                'required'   => ['snapshot_uuid'],
            ],
        ];
    }
}
