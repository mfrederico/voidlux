<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Upgrade;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as HttpClient;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Coordinates rolling restart with auto-rollback across the swarm.
 *
 * Strategy:
 * 1. Record current git commit as rollback point
 * 2. Git pull on Seneschal's project dir to get target commit
 * 3. Send UPGRADE_REQUEST to a canary worker via P2P
 * 4. Wait for canary to pull, restart, and report healthy (UPGRADE_STATUS)
 * 5. If canary healthy: proceed with remaining workers sequentially
 * 6. If canary fails: rollback canary, abort upgrade
 * 7. After all workers: trigger emperor restart (regicide + election)
 * 8. Wait for new emperor health check (60s timeout)
 * 9. If >50% workers rejoined: success. Otherwise: rollback all.
 */
class UpgradeCoordinator
{
    private const CANARY_HEALTH_TIMEOUT = 60;
    private const WORKER_RESTART_TIMEOUT = 45;
    private const EMPEROR_REJOIN_TIMEOUT = 60;
    private const WORKER_REJOIN_THRESHOLD = 0.5; // >50% must rejoin

    private bool $upgradeInProgress = false;

    /** @var array<string, array{status: string, ts: float}> node_id => upgrade status */
    private array $nodeStatuses = [];

    /** @var callable(string): void */
    private $logger;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly UpgradeDatabase $db,
        private readonly string $nodeId,
        private readonly string $projectDir,
    ) {}

    public function onLog(callable $cb): void
    {
        $this->logger = $cb;
    }

    public function isUpgradeInProgress(): bool
    {
        return $this->upgradeInProgress;
    }

    /**
     * Handle UPGRADE_STATUS messages from workers reporting their restart outcome.
     */
    public function handleUpgradeStatus(array $msg): void
    {
        $nodeId = $msg['node_id'] ?? '';
        $status = $msg['status'] ?? '';

        if ($nodeId && $status) {
            $this->nodeStatuses[$nodeId] = [
                'status' => $status,
                'ts' => microtime(true),
            ];
            $this->log("Upgrade status from " . substr($nodeId, 0, 8) . ": {$status}");
        }
    }

    /**
     * Start a rolling upgrade.
     *
     * @param string $targetCommit Git ref to upgrade to (commit, branch, tag). Empty = latest (git pull).
     * @param string $emperorHost Emperor HTTP host for health checks
     * @param int $emperorHttpPort Emperor HTTP port for health checks
     * @param array<array{node_id: string, host: string, http_port: int}> $workers Known worker nodes
     * @return UpgradeHistory The final upgrade record
     */
    public function startUpgrade(
        string $targetCommit,
        string $emperorHost,
        int $emperorHttpPort,
        array $workers,
    ): UpgradeHistory {
        if ($this->upgradeInProgress) {
            return $this->createFailedEntry('', '', 'Upgrade already in progress');
        }

        $this->upgradeInProgress = true;
        $this->nodeStatuses = [];

        $fromCommit = $this->getCurrentCommit();
        $upgradeId = substr(bin2hex(random_bytes(8)), 0, 16);
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $entry = new UpgradeHistory(
            id: $upgradeId,
            fromCommit: $fromCommit,
            toCommit: $targetCommit ?: '(latest)',
            status: 'in_progress',
            initiatedBy: $this->nodeId,
            failureReason: '',
            nodesTotal: count($workers) + 1, // workers + emperor
            nodesUpdated: 0,
            nodesRolledBack: 0,
            startedAt: $now,
            completedAt: '',
        );
        $this->db->insert($entry);

        $targetLabel = $targetCommit ?: '(latest)';
        $this->log("Starting rolling upgrade {$upgradeId}: {$fromCommit} -> {$targetLabel}");
        $this->log("Nodes to upgrade: " . count($workers) . " workers + 1 emperor");

        // Step 1: Git pull on Seneschal's own project dir
        $pullOk = $this->gitPull($targetCommit);
        if (!$pullOk) {
            return $this->finalizeUpgrade($entry, 'failed', 'Git pull failed on Seneschal');
        }

        $toCommit = $this->getCurrentCommit();
        if ($toCommit === $fromCommit && !$targetCommit) {
            return $this->finalizeUpgrade($entry, 'success', '', nodesUpdated: 0);
        }

        // Update entry with actual target commit
        $entry = new UpgradeHistory(
            id: $entry->id,
            fromCommit: $entry->fromCommit,
            toCommit: $toCommit,
            status: 'in_progress',
            initiatedBy: $entry->initiatedBy,
            failureReason: '',
            nodesTotal: $entry->nodesTotal,
            nodesUpdated: 0,
            nodesRolledBack: 0,
            startedAt: $entry->startedAt,
            completedAt: '',
        );
        $this->db->update($entry);

        // Step 2: Canary — pick first worker
        if (empty($workers)) {
            $this->log("No workers to upgrade, proceeding to emperor");
        } else {
            $canary = $workers[0];
            $this->log("Canary worker: " . substr($canary['node_id'], 0, 8));

            $canaryOk = $this->upgradeNode($canary, $upgradeId, $toCommit, self::CANARY_HEALTH_TIMEOUT);
            if (!$canaryOk) {
                // Rollback canary
                $this->rollbackNode($canary, $fromCommit);
                return $this->finalizeUpgrade($entry, 'rolled_back', 'Canary worker failed health check', nodesRolledBack: 1);
            }

            $nodesUpdated = 1;
            $this->log("Canary healthy, proceeding with remaining workers");

            // Step 3: Sequential upgrade of remaining workers
            $remainingWorkers = array_slice($workers, 1);
            foreach ($remainingWorkers as $worker) {
                $ok = $this->upgradeNode($worker, $upgradeId, $toCommit, self::WORKER_RESTART_TIMEOUT);
                if (!$ok) {
                    // Rollback this worker and all previously updated
                    $this->log("Worker " . substr($worker['node_id'], 0, 8) . " failed, rolling back all");
                    $rollbackCount = $this->rollbackAll($workers, $nodesUpdated, $fromCommit);
                    return $this->finalizeUpgrade($entry, 'rolled_back', 'Worker failed health check: ' . substr($worker['node_id'], 0, 8), nodesUpdated: $nodesUpdated, nodesRolledBack: $rollbackCount);
                }
                $nodesUpdated++;
            }

            $entry = new UpgradeHistory(
                id: $entry->id,
                fromCommit: $entry->fromCommit,
                toCommit: $entry->toCommit,
                status: 'in_progress',
                initiatedBy: $entry->initiatedBy,
                failureReason: '',
                nodesTotal: $entry->nodesTotal,
                nodesUpdated: $nodesUpdated,
                nodesRolledBack: 0,
                startedAt: $entry->startedAt,
                completedAt: '',
            );
            $this->db->update($entry);
        }

        // Step 4: Emperor restart via regicide
        $this->log("All workers upgraded, triggering emperor restart (regicide)");
        $regicideOk = $this->triggerRegicide($emperorHost, $emperorHttpPort);
        if (!$regicideOk) {
            $this->log("Regicide request failed — emperor may already be down");
        }

        // Step 5: Wait for new emperor to come up
        $this->log("Waiting for new emperor (timeout: " . self::EMPEROR_REJOIN_TIMEOUT . "s)");
        $emperorUp = $this->waitForEmperor($emperorHost, $emperorHttpPort, self::EMPEROR_REJOIN_TIMEOUT);
        if (!$emperorUp) {
            $rollbackCount = $this->rollbackAll($workers, count($workers), $fromCommit);
            return $this->finalizeUpgrade($entry, 'rolled_back', 'Emperor did not come back after restart', nodesUpdated: count($workers), nodesRolledBack: $rollbackCount);
        }

        // Step 6: Cluster health check — >50% workers must have rejoined
        Coroutine::sleep(5); // Brief grace period for workers to reconnect
        $rejoinedCount = $this->countRejoinedWorkers($emperorHost, $emperorHttpPort, $workers);
        $threshold = (int) ceil(count($workers) * self::WORKER_REJOIN_THRESHOLD);

        if ($rejoinedCount < $threshold && count($workers) > 0) {
            $this->log("Only {$rejoinedCount}/" . count($workers) . " workers rejoined (need {$threshold}), rolling back");
            $rollbackCount = $this->rollbackAll($workers, count($workers), $fromCommit);
            return $this->finalizeUpgrade($entry, 'rolled_back', "Insufficient workers rejoined: {$rejoinedCount}/" . count($workers), nodesUpdated: count($workers) + 1, nodesRolledBack: $rollbackCount);
        }

        $totalUpdated = count($workers) + 1; // workers + emperor
        $this->log("Upgrade complete: {$totalUpdated} nodes updated");

        return $this->finalizeUpgrade($entry, 'success', '', nodesUpdated: $totalUpdated);
    }

    /**
     * Send upgrade request to a node and wait for it to report healthy.
     */
    private function upgradeNode(array $node, string $upgradeId, string $targetCommit, int $timeout): bool
    {
        $nodeId = $node['node_id'];
        $short = substr($nodeId, 0, 8);

        // Send UPGRADE_REQUEST via P2P mesh
        $this->mesh->broadcast([
            'type' => MessageTypes::UPGRADE_REQUEST,
            'upgrade_id' => $upgradeId,
            'target_node' => $nodeId,
            'target_commit' => $targetCommit,
            'node_id' => $this->nodeId,
        ]);

        $this->log("Sent upgrade request to {$short}, waiting {$timeout}s for health check");

        // Wait for UPGRADE_STATUS response
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            Coroutine::sleep(2);

            $status = $this->nodeStatuses[$nodeId] ?? null;
            if ($status) {
                if ($status['status'] === 'healthy') {
                    $this->log("Node {$short} reports healthy after upgrade");
                    return true;
                }
                if ($status['status'] === 'failed') {
                    $this->log("Node {$short} reports upgrade failure");
                    return false;
                }
            }
        }

        // Fallback: HTTP health check
        $host = $node['host'] ?? '127.0.0.1';
        $port = $node['http_port'] ?? 0;
        if ($port > 0 && $this->httpHealthCheck($host, $port)) {
            $this->log("Node {$short} healthy via HTTP fallback");
            return true;
        }

        $this->log("Node {$short} timed out waiting for health check");
        return false;
    }

    /**
     * Rollback a single node to a given commit.
     */
    private function rollbackNode(array $node, string $commit): bool
    {
        $nodeId = $node['node_id'];
        $this->log("Rolling back " . substr($nodeId, 0, 8) . " to {$commit}");

        $this->mesh->broadcast([
            'type' => MessageTypes::UPGRADE_REQUEST,
            'upgrade_id' => 'rollback',
            'target_node' => $nodeId,
            'target_commit' => $commit,
            'node_id' => $this->nodeId,
            'rollback' => true,
        ]);

        return true;
    }

    /**
     * Rollback all previously upgraded workers.
     * @return int Number of nodes rollback was sent to
     */
    private function rollbackAll(array $workers, int $updatedCount, string $fromCommit): int
    {
        $count = 0;
        for ($i = 0; $i < min($updatedCount, count($workers)); $i++) {
            $this->rollbackNode($workers[$i], $fromCommit);
            $count++;
        }

        // Also rollback Seneschal's own git
        $this->gitCheckout($fromCommit);
        $this->log("Rolled back Seneschal to {$fromCommit}");

        return $count;
    }

    private function triggerRegicide(string $host, int $port): bool
    {
        $client = new HttpClient($host, $port);
        $client->set(['timeout' => 10]);
        $client->setMethod('POST');
        $client->execute('/api/swarm/regicide');

        $ok = $client->statusCode === 200;
        $client->close();
        return $ok;
    }

    /**
     * Wait for emperor to become reachable via /health endpoint.
     */
    private function waitForEmperor(string $host, int $port, int $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            Coroutine::sleep(3);
            if ($this->httpHealthCheck($host, $port)) {
                $this->log("Emperor is back up");
                return true;
            }
        }
        return false;
    }

    private function httpHealthCheck(string $host, int $port): bool
    {
        $client = new HttpClient($host, $port);
        $client->set(['timeout' => 5]);
        $client->get('/health');
        $ok = $client->statusCode === 200;
        $client->close();
        return $ok;
    }

    /**
     * Count how many workers have rejoined by querying emperor's /api/swarm/status.
     */
    private function countRejoinedWorkers(string $host, int $port, array $expectedWorkers): int
    {
        $client = new HttpClient($host, $port);
        $client->set(['timeout' => 10]);
        $client->get('/api/swarm/status');

        if ($client->statusCode !== 200) {
            $client->close();
            return 0;
        }

        $data = json_decode($client->body, true);
        $client->close();

        $agentCount = $data['agents']['total'] ?? 0;
        $peerCount = $data['discovery']['peers'] ?? $data['discovery']['peer_count'] ?? 0;

        // Use peer count as proxy for rejoined workers
        return max($agentCount > 0 ? count($expectedWorkers) : 0, $peerCount);
    }

    private function getCurrentCommit(): string
    {
        $output = [];
        exec(
            sprintf('cd %s && git rev-parse HEAD 2>/dev/null', escapeshellarg($this->projectDir)),
            $output,
            $code
        );
        return ($code === 0 && !empty($output[0])) ? trim($output[0]) : '';
    }

    private function gitPull(string $targetCommit): bool
    {
        if ($targetCommit) {
            // Fetch and checkout specific commit/branch/tag
            $code = 0;
            exec(sprintf(
                'cd %s && git fetch origin 2>&1 && git checkout %s 2>&1',
                escapeshellarg($this->projectDir),
                escapeshellarg($targetCommit),
            ), $output, $code);
            return $code === 0;
        }

        // Default: pull latest on current branch
        $output = [];
        exec(sprintf('cd %s && git pull 2>&1', escapeshellarg($this->projectDir)), $output, $code);
        return $code === 0;
    }

    private function gitCheckout(string $commit): bool
    {
        $output = [];
        exec(sprintf(
            'cd %s && git checkout %s 2>&1',
            escapeshellarg($this->projectDir),
            escapeshellarg($commit),
        ), $output, $code);
        return $code === 0;
    }

    private function finalizeUpgrade(
        UpgradeHistory $entry,
        string $status,
        string $failureReason = '',
        int $nodesUpdated = 0,
        int $nodesRolledBack = 0,
    ): UpgradeHistory {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $final = new UpgradeHistory(
            id: $entry->id,
            fromCommit: $entry->fromCommit,
            toCommit: $entry->toCommit,
            status: $status,
            initiatedBy: $entry->initiatedBy,
            failureReason: $failureReason,
            nodesTotal: $entry->nodesTotal,
            nodesUpdated: $nodesUpdated ?: $entry->nodesUpdated,
            nodesRolledBack: $nodesRolledBack,
            startedAt: $entry->startedAt,
            completedAt: $now,
        );
        $this->db->update($final);

        $this->upgradeInProgress = false;

        if ($status === 'success') {
            $this->log("Upgrade {$entry->id} completed successfully");
        } else {
            $this->log("Upgrade {$entry->id} {$status}: {$failureReason}");
        }

        return $final;
    }

    private function createFailedEntry(string $from, string $to, string $reason): UpgradeHistory
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $entry = new UpgradeHistory(
            id: substr(bin2hex(random_bytes(8)), 0, 16),
            fromCommit: $from,
            toCommit: $to,
            status: 'failed',
            initiatedBy: $this->nodeId,
            failureReason: $reason,
            nodesTotal: 0,
            nodesUpdated: 0,
            nodesRolledBack: 0,
            startedAt: $now,
            completedAt: $now,
        );
        $this->db->insert($entry);
        $this->upgradeInProgress = false;
        return $entry;
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)("[upgrade] {$message}");
        }
    }
}
