<?php

declare(strict_types=1);

namespace Sitevero\Mcp;

use Sitevero\Capabilities\CapabilityRegistry;
use Sitevero\Mcp\Handlers\DiscoverHandler;
use Sitevero\Mcp\Handlers\InspectHandler;
use Sitevero\Mcp\Handlers\RollbackHandler;
use Sitevero\Safety\ConfirmationGate;
use Sitevero\Safety\RiskAssessor;
use Sitevero\Safety\SnapshotManager;

/**
 * Dispatches and registers the 4 Universal Meta-Tools for the MCP Adapter.
 */
final class ToolRegistrar
{
    private CapabilityRegistry $registry;
    private DiscoverHandler $discoverHandler;
    private InspectHandler $inspectHandler;
    private RollbackHandler $rollbackHandler;
    private RiskAssessor $riskAssessor;
    private ConfirmationGate $confirmationGate;
    private SnapshotManager $snapshotManager;

    public function __construct(
        CapabilityRegistry $registry,
        ?RiskAssessor $riskAssessor = null,
        ?ConfirmationGate $confirmationGate = null,
        ?SnapshotManager $snapshotManager = null,
        ?RollbackHandler $rollbackHandler = null
    ) {
        $this->registry = $registry;
        $this->riskAssessor = $riskAssessor ?? new RiskAssessor($registry);
        $this->confirmationGate = $confirmationGate ?? new ConfirmationGate();
        $this->snapshotManager = $snapshotManager ?? new SnapshotManager();

        $this->discoverHandler = new DiscoverHandler($registry);
        $this->inspectHandler = new InspectHandler($registry);
        $this->rollbackHandler = $rollbackHandler ?? new RollbackHandler($this->snapshotManager->getRepository());
    }

    /**
     * Get definitions and schemas for all 4 universal meta-tools.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getToolDefinitions(): array
    {
        return [
            'sitevero_discover' => SchemaProvider::getDiscoverSchema(),
            'sitevero_inspect'  => SchemaProvider::getInspectSchema(),
            'sitevero_execute'  => SchemaProvider::getExecuteSchema(),
            'sitevero_rollback' => SchemaProvider::getRollbackSchema(),
        ];
    }

    /**
     * Dispatch an incoming tool execution request.
     *
     * @param string $toolName
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function dispatch(string $toolName, array $arguments = []): array
    {
        return match ($toolName) {
            'sitevero_discover' => $this->discoverHandler->handle($arguments),
            'sitevero_inspect'  => $this->inspectHandler->handle($arguments),
            'sitevero_execute'  => $this->handleExecute($arguments),
            'sitevero_rollback' => $this->handleRollback($arguments),
            default             => [
                'error'   => 'UNKNOWN_TOOL',
                'message' => "Tool '{$toolName}' is not recognized.",
            ],
        };
    }

    /**
     * Safety-gated execute handler.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function handleExecute(array $arguments): array
    {
        $capId = (string) ($arguments['capability_id'] ?? '');
        $action = (string) ($arguments['action'] ?? '');
        $parameters = (array) ($arguments['parameters'] ?? []);
        $token = (string) ($arguments['confirmation_token'] ?? '');

        $capability = $this->registry->get($capId);
        if ($capability === null) {
            return [
                'error'   => 'ERR_CAPABILITY_NOT_FOUND',
                'message' => "Capability '{$capId}' is not registered.",
            ];
        }

        if (!$this->registry->isEnabled($capId)) {
            return [
                'error'   => 'ERR_CAPABILITY_DISABLED',
                'message' => "Capability '{$capId}' is disabled by site administrator.",
            ];
        }

        $userId = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        $riskLevel = $this->riskAssessor->assess($capId, $action, $parameters);

        // 1. Enforce 5-minute Confirmation Gate for high & destructive actions
        if ($this->riskAssessor->requiresConfirmation($riskLevel)) {
            if (empty($token)) {
                return $this->confirmationGate->generateConfirmationRequest(
                    $userId,
                    $capId,
                    $action,
                    $riskLevel
                );
            }

            if (!$this->confirmationGate->verify($token, $userId, $capId, $action)) {
                return [
                    'error'   => 'ERR_INVALID_CONFIRMATION_TOKEN',
                    'message' => 'Provided confirmation token is invalid, expired, or already consumed.',
                ];
            }
        }

        // 2. Pre-execution snapshot capture if mutation warrants it
        $snapshotUuid = null;
        if ($this->riskAssessor->requiresSnapshot($riskLevel)) {
            try {
                $snapshotUuid = $this->snapshotManager->capture($capId, $action, $parameters, $userId);
            } catch (\RuntimeException $e) {
                return [
                    'error'   => 'ERR_SNAPSHOT_FAILED',
                    'message' => $e->getMessage(),
                ];
            }
        }

        // 3. Execute capability action
        $result = $capability->execute($action, $parameters);

        if ($snapshotUuid !== null) {
            $result['snapshot_uuid'] = $snapshotUuid;
        }

        return $result;
    }

    /**
     * Rollback dispatcher.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function handleRollback(array $arguments): array
    {
        return $this->rollbackHandler->handle($arguments);
    }

    public function getRollbackHandler(): RollbackHandler
    {
        return $this->rollbackHandler;
    }

    public function getRiskAssessor(): RiskAssessor
    {
        return $this->riskAssessor;
    }

    public function getConfirmationGate(): ConfirmationGate
    {
        return $this->confirmationGate;
    }

    public function getSnapshotManager(): SnapshotManager
    {
        return $this->snapshotManager;
    }
}
