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
     * Initialize hooks to connect into WordPress/mcp-adapter.
     */
    public function init(): void
    {
        // 1. Hook for official WordPress MCP Adapter server registration
        add_action('mcp_adapter_init', [$this, 'registerWithAdapter'], 10, 1);
        add_filter('mcp_adapter_tools', [$this, 'filterAdapterTools'], 10, 1);
        add_filter('mcp_tools', [$this, 'filterAdapterTools'], 10, 1);

        // 2. Custom action hook for programmatic integration
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
