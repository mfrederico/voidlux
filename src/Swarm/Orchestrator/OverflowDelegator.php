<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Orchestrator;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as HttpClient;
use VoidLux\Swarm\Model\TaskModel;
use VoidLux\Swarm\Model\TaskStatus;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Delegates overflow tasks to the inter-swarm marketplace when local agents
 * are saturated. Communicates with the Seneschal's broker API via HTTP.
 *
 * Flow:
 * 1. TaskDispatcher finds pending tasks with no idle agents
 * 2. OverflowDelegator posts bounties to the broker network
 * 3. Remote swarms claim bounties and work on tasks
 * 4. Periodic poll detects completed/failed bounties
 * 5. Results are routed back to the local TaskQueue
 */
class OverflowDelegator
{
    /** How often to poll broker for bounty status updates (seconds) */
    private const POLL_INTERVAL = 15;

    /** Max bounties to post per dispatch cycle */
    private const MAX_BOUNTIES_PER_CYCLE = 5;

    /** Default bounty TTL in seconds */
    private const DEFAULT_TTL = 600;

    /** Default reward per task */
    private const DEFAULT_REWARD = 10;

    /** Minimum reputation score to accept bids from a swarm */
    private const MIN_REPUTATION = 0.2;

    private bool $running = false;

    /** @var array<string, array{bounty_id: string, task_id: string, delegated_at: int, status: string}> task_id => delegation info */
    private array $delegatedTasks = [];

    /** @var array<string, array{completed: int, failed: int, abandoned: int, total_seconds: float, last_seen: int}> node_id => reputation record */
    private array $reputationRecords = [];

    public function __construct(
        private readonly SwarmDatabase $db,
        private readonly TaskQueue $taskQueue,
        private readonly string $nodeId,
        private readonly string $brokerHost = '127.0.0.1',
        private readonly int $brokerPort = 9090,
    ) {}

    /**
     * Start the background polling coroutine that monitors delegated bounties.
     */
    public function start(): void
    {
        $this->running = true;

        Coroutine::create(function () {
            while ($this->running) {
                Coroutine::sleep(self::POLL_INTERVAL);
                if ($this->running && !empty($this->delegatedTasks)) {
                    $this->pollBountyStatus();
                }
            }
        });
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Check if the broker is reachable.
     */
    public function isBrokerAvailable(): bool
    {
        $data = $this->brokerGet('/api/broker/status');
        return $data !== null;
    }

    /**
     * Attempt to delegate overflow tasks to the marketplace.
     * Called by TaskDispatcher when pending tasks exist but no local idle agents.
     *
     * @param TaskModel[] $overflowTasks Tasks that couldn't be dispatched locally
     * @return int Number of tasks successfully delegated
     */
    public function delegateOverflow(array $overflowTasks): int
    {
        $delegated = 0;

        foreach ($overflowTasks as $task) {
            if ($delegated >= self::MAX_BOUNTIES_PER_CYCLE) {
                break;
            }

            if (!$this->shouldDelegate($task)) {
                continue;
            }

            if ($this->postBountyForTask($task)) {
                $delegated++;
            }
        }

        if ($delegated > 0) {
            $this->log("Delegated {$delegated} overflow task(s) to marketplace");
        }

        return $delegated;
    }

    /**
     * Check if a task should be delegated to the marketplace.
     */
    public function shouldDelegate(TaskModel $task): bool
    {
        // Only delegate pending tasks
        if ($task->status !== TaskStatus::Pending) {
            return false;
        }

        // Don't delegate parent tasks — they get decomposed into subtasks locally
        $subtasks = $this->db->getSubtasks($task->id);
        if (!empty($subtasks)) {
            return false;
        }

        // Don't double-delegate
        if (isset($this->delegatedTasks[$task->id])) {
            return false;
        }

        return true;
    }

    /**
     * Post a bounty to the broker network for a given task.
     */
    private function postBountyForTask(TaskModel $task): bool
    {
        // First check if any remote swarms have matching capabilities
        $capableNodes = $this->findCapableNodes($task->requiredCapabilities);
        if (empty($capableNodes)) {
            // No capable swarms known — still post bounty (someone might pick it up)
            $this->log("No known capable swarms for task {$this->shortId($task->id)}, posting bounty anyway");
        } else {
            // Filter by reputation
            $suitable = $this->filterByReputation($capableNodes);
            if (empty($suitable)) {
                $this->log("No swarms with sufficient reputation for task {$this->shortId($task->id)}");
                return false;
            }
        }

        $bountyData = $this->brokerPost('/api/broker/bounties', [
            'title' => $task->title,
            'description' => $this->buildBountyDescription($task),
            'required_capabilities' => $task->requiredCapabilities,
            'reward' => self::DEFAULT_REWARD,
            'currency' => 'VOID',
            'ttl_seconds' => self::DEFAULT_TTL,
        ]);

        if (!$bountyData || !isset($bountyData['bounty']['id'])) {
            $this->log("Failed to post bounty for task {$this->shortId($task->id)}");
            return false;
        }

        $bountyId = $bountyData['bounty']['id'];
        $this->delegatedTasks[$task->id] = [
            'bounty_id' => $bountyId,
            'task_id' => $task->id,
            'delegated_at' => time(),
            'status' => 'open',
        ];

        $this->log("Posted bounty {$this->shortId($bountyId)} for task {$this->shortId($task->id)}");
        return true;
    }

    /**
     * Build a description for the bounty from the task.
     */
    private function buildBountyDescription(TaskModel $task): string
    {
        $parts = [];
        if ($task->description) {
            $parts[] = $task->description;
        }
        if ($task->workInstructions) {
            $parts[] = "## Work Instructions\n" . $task->workInstructions;
        }
        if ($task->acceptanceCriteria) {
            $parts[] = "## Acceptance Criteria\n" . $task->acceptanceCriteria;
        }
        if ($task->projectPath) {
            $parts[] = "Project: " . $task->projectPath;
        }
        return implode("\n\n", $parts) ?: $task->title;
    }

    /**
     * Poll the broker for bounty status updates.
     * Called periodically by the background coroutine.
     */
    private function pollBountyStatus(): void
    {
        $bounties = $this->brokerGet('/api/broker/bounties');
        if ($bounties === null || !isset($bounties['bounties'])) {
            return;
        }

        $bountyMap = [];
        foreach ($bounties['bounties'] as $b) {
            $bountyMap[$b['id']] = $b;
        }

        foreach ($this->delegatedTasks as $taskId => $info) {
            $bounty = $bountyMap[$info['bounty_id']] ?? null;
            if (!$bounty) {
                // Bounty disappeared — might have expired
                $this->handleBountyExpired($taskId, $info);
                continue;
            }

            $newStatus = $bounty['status'] ?? 'open';
            $oldStatus = $info['status'];

            if ($newStatus !== $oldStatus) {
                $this->delegatedTasks[$taskId]['status'] = $newStatus;
                $this->handleBountyStatusChange($taskId, $info, $bounty, $oldStatus, $newStatus);
            }

            // Check for timeout — bounty open too long
            $age = time() - $info['delegated_at'];
            if ($newStatus === 'open' && $age > self::DEFAULT_TTL) {
                $this->handleBountyExpired($taskId, $info);
            }
        }
    }

    /**
     * Handle bounty status transitions.
     */
    private function handleBountyStatusChange(
        string $taskId,
        array $info,
        array $bounty,
        string $oldStatus,
        string $newStatus,
    ): void {
        $this->log("Bounty {$this->shortId($info['bounty_id'])} status: {$oldStatus} -> {$newStatus}");

        switch ($newStatus) {
            case 'claimed':
                $claimedBy = $bounty['claimed_by_node_id'] ?? '';
                $this->log("Task {$this->shortId($taskId)} claimed by remote swarm {$this->shortId($claimedBy)}");
                break;

            case 'completed':
                $this->handleBountyCompleted($taskId, $info, $bounty);
                break;

            case 'cancelled':
            case 'expired':
                $this->handleBountyExpired($taskId, $info);
                break;

            case 'failed':
                $this->handleBountyFailed($taskId, $info, $bounty);
                break;
        }
    }

    /**
     * Handle a successfully completed bounty — route result back to task.
     */
    private function handleBountyCompleted(string $taskId, array $info, array $bounty): void
    {
        $result = $bounty['result'] ?? 'Completed by remote swarm';
        $claimedBy = $bounty['claimed_by_node_id'] ?? '';
        $duration = time() - $info['delegated_at'];

        // Record reputation
        $this->recordCompletion($claimedBy, (float) $duration);

        // Complete the local task
        $this->taskQueue->complete($taskId, $this->nodeId, "Delegated result: {$result}");

        unset($this->delegatedTasks[$taskId]);
        $this->log("Delegated task {$this->shortId($taskId)} completed by {$this->shortId($claimedBy)}");
    }

    /**
     * Handle a failed bounty — record reputation and optionally re-delegate.
     */
    private function handleBountyFailed(string $taskId, array $info, array $bounty): void
    {
        $claimedBy = $bounty['claimed_by_node_id'] ?? '';
        $error = $bounty['error'] ?? 'Remote swarm failed to complete task';

        if ($claimedBy) {
            $this->recordFailure($claimedBy);
        }

        unset($this->delegatedTasks[$taskId]);
        $this->log("Delegated task {$this->shortId($taskId)} failed: {$error}");
        // Task stays pending locally — dispatcher will try again (local or re-delegate)
    }

    /**
     * Handle expired/cancelled bounty — task returns to local pool.
     */
    private function handleBountyExpired(string $taskId, array $info): void
    {
        $claimedBy = $info['claimed_by'] ?? '';
        if ($claimedBy) {
            $this->recordAbandonment($claimedBy);
        }

        // Cancel the bounty on the broker if still open
        $this->brokerPost("/api/broker/bounties/{$info['bounty_id']}/cancel", []);

        unset($this->delegatedTasks[$taskId]);
        $this->log("Bounty {$this->shortId($info['bounty_id'])} expired/cancelled, task {$this->shortId($taskId)} returns to local pool");
    }

    // ── Reputation Scoring ─────────────────────────────────────────────

    public function recordCompletion(string $nodeId, float $durationSeconds): void
    {
        $this->ensureRecord($nodeId);
        $this->reputationRecords[$nodeId]['completed']++;
        $this->reputationRecords[$nodeId]['total_seconds'] += $durationSeconds;
        $this->reputationRecords[$nodeId]['last_seen'] = time();
    }

    public function recordFailure(string $nodeId): void
    {
        $this->ensureRecord($nodeId);
        $this->reputationRecords[$nodeId]['failed']++;
        $this->reputationRecords[$nodeId]['last_seen'] = time();
    }

    public function recordAbandonment(string $nodeId): void
    {
        $this->ensureRecord($nodeId);
        $this->reputationRecords[$nodeId]['abandoned']++;
        $this->reputationRecords[$nodeId]['last_seen'] = time();
    }

    /**
     * Calculate reputation score for a node (0.0-1.0).
     *
     * Weights:
     * - Completion rate: 40%
     * - Reliability (no abandonment): 25%
     * - Speed (normalized against 300s baseline): 20%
     * - Recency (decay over 24h): 15%
     */
    public function reputationScore(string $nodeId): float
    {
        $r = $this->reputationRecords[$nodeId] ?? null;
        if (!$r || ($r['completed'] + $r['failed'] + $r['abandoned']) === 0) {
            return 0.5; // Neutral for unknown nodes
        }

        $total = $r['completed'] + $r['failed'] + $r['abandoned'];

        $completionRate = $r['completed'] / $total;

        $penaltyWeight = $r['failed'] + ($r['abandoned'] * 2);
        $reliability = max(0.0, 1.0 - ($penaltyWeight / max(1, $total)));

        $avgSpeed = $r['completed'] > 0 ? $r['total_seconds'] / $r['completed'] : 300.0;
        $speedScore = max(0.0, min(1.0, 1.0 - ($avgSpeed / 600.0)));

        $age = time() - $r['last_seen'];
        $recency = max(0.0, 1.0 - ($age / 86400.0));

        return round(
            ($completionRate * 0.40) +
            ($reliability * 0.25) +
            ($speedScore * 0.20) +
            ($recency * 0.15),
            3,
        );
    }

    /**
     * Rank all known swarms by reputation score.
     * @return array<string, float> node_id => score, descending
     */
    public function rankSwarms(): array
    {
        $scores = [];
        foreach (array_keys($this->reputationRecords) as $nodeId) {
            $scores[$nodeId] = $this->reputationScore($nodeId);
        }
        arsort($scores);
        return $scores;
    }

    /**
     * Get reputation record for a node.
     */
    public function getReputationRecord(string $nodeId): array
    {
        return $this->reputationRecords[$nodeId] ?? [
            'completed' => 0, 'failed' => 0, 'abandoned' => 0,
            'total_seconds' => 0.0, 'last_seen' => 0,
        ];
    }

    /**
     * Get all reputation records.
     * @return array<string, array>
     */
    public function getAllReputationRecords(): array
    {
        return $this->reputationRecords;
    }

    /**
     * Get info on currently delegated tasks.
     * @return array<string, array>
     */
    public function getDelegatedTasks(): array
    {
        return $this->delegatedTasks;
    }

    /**
     * Get delegation stats.
     */
    public function stats(): array
    {
        $byStatus = [];
        foreach ($this->delegatedTasks as $info) {
            $s = $info['status'];
            $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
        }

        return [
            'delegated_total' => count($this->delegatedTasks),
            'by_status' => $byStatus,
            'known_swarms' => count($this->reputationRecords),
            'reputation_scores' => $this->rankSwarms(),
            'broker_host' => $this->brokerHost,
            'broker_port' => $this->brokerPort,
        ];
    }

    // ── Broker HTTP Client ─────────────────────────────────────────────

    /**
     * Find remote nodes with matching capabilities via broker API.
     * @return array[] List of capability profile data
     */
    private function findCapableNodes(array $requiredCapabilities): array
    {
        $query = !empty($requiredCapabilities)
            ? '?capabilities=' . urlencode(implode(',', $requiredCapabilities))
            : '';

        $data = $this->brokerGet('/api/broker/find' . $query);
        return $data['nodes'] ?? [];
    }

    /**
     * Filter capability profiles by reputation score.
     * @param array[] $nodes
     * @return array[]
     */
    private function filterByReputation(array $nodes): array
    {
        return array_values(array_filter($nodes, function (array $node) {
            $nodeId = $node['node_id'] ?? '';
            return $this->reputationScore($nodeId) >= self::MIN_REPUTATION;
        }));
    }

    private function brokerGet(string $path): ?array
    {
        try {
            $client = new HttpClient($this->brokerHost, $this->brokerPort);
            $client->set(['timeout' => 5]);
            $client->get($path);

            if ($client->statusCode !== 200) {
                $client->close();
                return null;
            }

            $data = json_decode($client->body, true);
            $client->close();
            return $data;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function brokerPost(string $path, array $body): ?array
    {
        try {
            $client = new HttpClient($this->brokerHost, $this->brokerPort);
            $client->set(['timeout' => 5]);
            $client->setHeaders(['Content-Type' => 'application/json']);
            $client->post($path, json_encode($body));

            if ($client->statusCode < 200 || $client->statusCode >= 300) {
                $client->close();
                return null;
            }

            $data = json_decode($client->body, true);
            $client->close();
            return $data;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function ensureRecord(string $nodeId): void
    {
        if (!$nodeId || isset($this->reputationRecords[$nodeId])) {
            return;
        }
        $this->reputationRecords[$nodeId] = [
            'completed' => 0,
            'failed' => 0,
            'abandoned' => 0,
            'total_seconds' => 0.0,
            'last_seen' => time(),
        ];
    }

    private function shortId(string $id): string
    {
        return substr($id, 0, 8);
    }

    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}][overflow] {$message}\n";
    }
}
