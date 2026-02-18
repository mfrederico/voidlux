<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Upgrade;

use Swoole\Coroutine;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Handles UPGRADE_REQUEST on worker/emperor nodes.
 * Performs git pull, restarts the process, and reports health back.
 */
class UpgradeHandler
{
    /** @var callable(string): void */
    private $logger;

    /** @var callable(): void|null — callback to trigger graceful restart */
    private $restartCallback;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly string $nodeId,
        private readonly string $projectDir,
    ) {}

    public function onLog(callable $cb): void
    {
        $this->logger = $cb;
    }

    public function onRestart(callable $cb): void
    {
        $this->restartCallback = $cb;
    }

    /**
     * Handle an incoming UPGRADE_REQUEST message.
     */
    public function handleUpgradeRequest(array $msg): void
    {
        $targetNode = $msg['target_node'] ?? '';
        if ($targetNode !== $this->nodeId) {
            return; // Not for us
        }

        $upgradeId = $msg['upgrade_id'] ?? '';
        $targetCommit = $msg['target_commit'] ?? '';
        $isRollback = (bool) ($msg['rollback'] ?? false);
        $action = $isRollback ? 'rollback' : 'upgrade';

        $this->log("Received {$action} request: {$upgradeId} -> {$targetCommit}");

        // Run upgrade in a coroutine to not block message handling
        Coroutine::create(function () use ($upgradeId, $targetCommit, $action) {
            $success = $this->performGitUpdate($targetCommit);

            if (!$success) {
                $this->reportStatus('failed');
                return;
            }

            // Report that git pull succeeded, about to restart
            $this->reportStatus('restarting');

            // Brief delay for status message to propagate
            Coroutine::sleep(1);

            // Trigger graceful restart
            if ($this->restartCallback) {
                $this->log("Triggering graceful restart for {$action}");
                ($this->restartCallback)();
            } else {
                // No restart callback — just report healthy
                // (the node will need manual restart)
                $this->reportStatus('healthy');
                $this->log("No restart callback configured — code updated but process not restarted");
            }
        });
    }

    private function performGitUpdate(string $targetCommit): bool
    {
        if ($targetCommit && $targetCommit !== '(latest)') {
            $output = [];
            exec(sprintf(
                'cd %s && git fetch origin 2>&1 && git checkout %s 2>&1',
                escapeshellarg($this->projectDir),
                escapeshellarg($targetCommit),
            ), $output, $code);

            if ($code !== 0) {
                $this->log("Git checkout failed: " . implode("\n", $output));
                return false;
            }
            return true;
        }

        $output = [];
        exec(sprintf('cd %s && git pull 2>&1', escapeshellarg($this->projectDir)), $output, $code);
        if ($code !== 0) {
            $this->log("Git pull failed: " . implode("\n", $output));
            return false;
        }
        return true;
    }

    private function reportStatus(string $status): void
    {
        $this->mesh->broadcast([
            'type' => MessageTypes::UPGRADE_STATUS,
            'node_id' => $this->nodeId,
            'status' => $status,
        ]);
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)("[upgrade] {$message}");
        }
    }
}
