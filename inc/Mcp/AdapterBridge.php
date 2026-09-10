<?php

declare(strict_types=1);

namespace Sitevero\Mcp;

use Sitevero\Capabilities\CapabilityRegistry;

/**
 * Bridge integrating Sitevero's 4 Universal Meta-Tools with the Official WordPress MCP Adapter.
 */
final class AdapterBridge
{
    private ToolRegistrar $registrar;

    public function __construct(CapabilityRegistry $registry)
    {
        $this->registrar = new ToolRegistrar($registry);
    }

    /**
     * Initialize hooks to connect into WordPress/mcp-adapter and Abilities API.
     */
    public function init(): void
    {
        // 1. Hook for official WordPress MCP Adapter server registration
        add_action('mcp_adapter_init', [$this, 'registerWithAdapter'], 10, 1);
        add_filter('mcp_adapter_tools', [$this, 'filterAdapterTools'], 10, 1);
        add_filter('mcp_tools', [$this, 'filterAdapterTools'], 10, 1);

        // 2. Official WordPress Abilities API (WordPress 6.8+ and modern MCP Adapter)
        add_action('wp_abilities_api_init', [$this, 'registerAbilities'], 10);
        add_action('init', [$this, 'registerAbilitiesFallback'], 20);

        // 3. Direct MCP REST endpoint for stdio-proxy and direct HTTP MCP transports
        add_action('rest_api_init', [$this, 'registerRestRoutes']);

        // 4. Custom action hook for programmatic integration
        add_action('sitevero_register_mcp_tools', [$this, 'registerTools'], 10, 1);
    }

    /**
     * Callback when official adapter fires mcp_adapter_init with server instance.
     */
    public function registerWithAdapter(mixed $server = null): void
    {
        if (is_object($server) && method_exists($server, 'register_tool')) {
            foreach ($this->registrar->getToolDefinitions() as $toolName => $definition) {
                $server->register_tool(
                    $toolName,
                    $definition['description'],
                    $definition['parameters'],
                    fn(array $args): array => $this->registrar->dispatch($toolName, $args)
                );
            }
        }
    }

    /**
     * Filter callback appending Sitevero's 4 Universal Tools to adapter tools list.
     *
     * @param mixed $tools
     * @return array<string, mixed>
     */
    public function filterAdapterTools(mixed $tools): array
    {
        $toolList = is_array($tools) ? $tools : [];

        foreach ($this->registrar->getToolDefinitions() as $toolName => $definition) {
            $toolList[$toolName] = [
                'name'        => $toolName,
                'description' => $definition['description'],
                'parameters'  => $definition['parameters'],
                'callback'    => fn(array $args): array => $this->registrar->dispatch($toolName, $args),
            ];
        }

        return $toolList;
    }

    /**
     * Register Sitevero's 4 Universal Tools with the WordPress Abilities API.
     */
    public function registerAbilities(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        if (function_exists('wp_register_ability_category')) {
            wp_register_ability_category('sitevero', [
                'label'       => 'Sitevero',
                'description' => 'Sitevero Universal WordPress AI MCP Meta-Tools',
            ]);
        }

        foreach ($this->registrar->getToolDefinitions() as $toolName => $definition) {
            $abilityName = str_replace('_', '/', $toolName);

            $abilityArgs = [
                'label'               => ucfirst(str_replace('_', ' ', $toolName)),
                'description'         => $definition['description'],
                'category'            => 'sitevero',
                'input_schema'        => $definition['parameters'],
                'execute_callback'    => fn(array $input = []): array => $this->registrar->dispatch($toolName, $input),
                'permission_callback' => fn(): bool => current_user_can('manage_options'),
                'meta'                => [
                    'annotations' => [
                        'readonly'    => in_array($toolName, ['sitevero_discover', 'sitevero_inspect'], true),
                        'destructive' => in_array($toolName, ['sitevero_execute', 'sitevero_rollback'], true),
                        'idempotent'  => false,
                    ],
                    'mcp' => [
                        'public' => true,
                    ],
                    'show_in_rest' => true,
                    'public'       => true,
                ],
            ];

            wp_register_ability($abilityName, $abilityArgs);
            if ($abilityName !== $toolName) {
                wp_register_ability($toolName, $abilityArgs);
            }
        }
    }

    /**
     * Fallback abilities registration on init hook if wp_abilities_api_init did not fire.
     */
    public function registerAbilitiesFallback(): void
    {
        if (function_exists('wp_register_ability') && function_exists('wp_has_ability') && !wp_has_ability('sitevero/discover')) {
            $this->registerAbilities();
        }
    }

    /**
     * Register direct JSON-RPC REST endpoint for stdio-proxy and direct HTTP MCP clients.
     */
    public function registerRestRoutes(): void
    {
        if (!function_exists('register_rest_route')) {
            return;
        }

        register_rest_route('mcp', '/sitevero-server', [
            'methods'             => ['POST', 'GET'],
            'callback'            => [$this, 'handleJsonRpcRequest'],
            'permission_callback' => fn(): bool => current_user_can('manage_options'),
        ]);

        register_rest_route('sitevero/v1', '/mcp', [
            'methods'             => ['POST', 'GET'],
            'callback'            => [$this, 'handleJsonRpcRequest'],
            'permission_callback' => fn(): bool => current_user_can('manage_options'),
        ]);
    }

    /**
     * JSON-RPC 2.0 Request Handler for direct MCP clients and stdio proxy.
     */
    public function handleJsonRpcRequest(mixed $request): mixed
    {
        $method = is_object($request) && method_exists($request, 'get_method')
            ? $request->get_method()
            : ($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'GET') {
            $data = [
                'status'  => 'ok',
                'server'  => 'Sitevero Universal WordPress AI MCP Server',
                'version' => defined('SITEVERO_VERSION') ? SITEVERO_VERSION : '1.0.0',
                'tools'   => array_keys($this->registrar->getToolDefinitions()),
            ];
            return class_exists('\WP_REST_Response') ? new \WP_REST_Response($data, 200) : $data;
        }

        $body = is_object($request) && method_exists($request, 'get_json_params')
            ? (array) $request->get_json_params()
            : [];

        if (empty($body)) {
            $raw = file_get_contents('php://input');
            $body = json_decode($raw, true) ?: [];
        }

        $hasId = isset($body['id']);
        $id = $body['id'] ?? null;
        $rpcMethod = (string) ($body['method'] ?? '');
        $params = (array) ($body['params'] ?? []);

        // 1. Silent handling for MCP Notifications (e.g., notifications/initialized)
        if (!$hasId || str_starts_with($rpcMethod, 'notifications/')) {
            return class_exists('\WP_REST_Response') ? new \WP_REST_Response(null, 204) : null;
        }

        switch ($rpcMethod) {
            case 'ping':
                $response = [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => (object)[],
                ];
                break;

            case 'initialize':
                $response = [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => [
                        'protocolVersion' => '2024-11-05',
                        'capabilities'    => [
                            'tools'     => ['listChanged' => false],
                            'prompts'   => ['listChanged' => false],
                            'resources' => ['subscribe' => false, 'listChanged' => false],
                        ],
                        'serverInfo'      => [
                            'name'    => 'Sitevero Universal WordPress AI MCP Server',
                            'version' => defined('SITEVERO_VERSION') ? SITEVERO_VERSION : '1.0.0',
                        ],
                        'instructions'    => 'Sitevero Universal WordPress AI MCP Server providing discover, inspect, execute, and rollback tools.',
                    ],
                ];
                break;

            case 'resources/list':
                $response = [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => [
                        'resources' => [],
                    ],
                ];
                break;

            case 'prompts/list':
                $response = [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => [
                        'prompts' => [],
                    ],
                ];
                break;

            case 'tools/list':
                $toolList = [];
                foreach ($this->registrar->getToolDefinitions() as $name => $def) {
                    $toolList[] = [
                        'name'        => $name,
                        'description' => $def['description'],
                        'inputSchema' => $def['parameters'],
                    ];
                }
                $response = [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => [
                        'tools' => $toolList,
                    ],
                ];
                break;

            case 'tools/call':
                $toolName = (string) ($params['name'] ?? '');
                $arguments = (array) ($params['arguments'] ?? []);
                $dispatchResult = $this->registrar->dispatch($toolName, $arguments);

                $response = [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => json_encode($dispatchResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                            ],
                        ],
                        'isError' => isset($dispatchResult['error']),
                    ],
                ];
                break;

            default:
                $response = [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'error'   => [
                        'code'    => -32601,
                        'message' => "Method not found: {$rpcMethod}",
                    ],
                ];
                break;
        }

        return class_exists('\WP_REST_Response') ? new \WP_REST_Response($response, 200) : $response;
    }

    /**
     * Public registration for custom/programmatic setups.
     */
    public function registerTools(mixed $server): void
    {
        $this->registerWithAdapter($server);
    }

    /**
     * Directly dispatch a tool call through the bridge.
     *
     * @param string $toolName
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function dispatch(string $toolName, array $arguments = []): array
    {
        return $this->registrar->dispatch($toolName, $arguments);
    }

    public function getRegistrar(): ToolRegistrar
    {
        return $this->registrar;
    }
}
