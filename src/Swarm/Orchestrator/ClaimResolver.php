<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Orchestrator;

use VoidLux\Swarm\Model\TaskStatus;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Resolves concurrent claim conflicts using Lamport ordering.
 *
 * When multiple nodes claim the same task, the claim with the lowest
 * lamport_ts wins. Ties are broken by node_id (lexicographic).
 * Losing claims revert the task to pending so it can be re-claimed.
 */
class ClaimResolver
{
    public function __construct(
        private readonly SwarmDatabase $db,
        private readonly string $nodeId,
    ) {}

    /**
     * Process a remote claim message. Returns true if the remote claim wins.
     */
    public function resolveRemoteClaim(
        string $taskId,
        string $remoteAgentId,
        string $remoteNodeId,
        int $remoteLamportTs,
    ): bool {
        $task = $this->db->getTask($taskId);
        if (!$task) {
            return false;
        }

        // Task is still pending — remote claim can proceed
        if ($task->status === TaskStatus::Pending) {
            $this->db->claimTask($taskId, $remoteAgentId, $remoteNodeId, $remoteLamportTs);
            return true;
        }

        // Task already claimed — check if remote claim should win
        if ($task->status === TaskStatus::Claimed) {
            if ($this->remoteWins($task->lamportTs, $task->assignedNode ?? '', $remoteLamportTs, $remoteNodeId)) {
                // Remote wins — overwrite claim
                $this->db->claimTask($taskId, $remoteAgentId, $remoteNodeId, $remoteLamportTs);
                return true;
            }
            // Local claim stands
            return false;
        }

        // Task is in_progress, completed, failed, or cancelled — ignore claim
        return false;
    }

    /**
     * Determine if a remote claim beats the local claim.
     * Lower lamport_ts wins. Ties broken by lower node_id (lexicographic).
     */
    private function remoteWins(
        int $localTs,
        string $localNodeId,
        int $remoteTs,
        string $remoteNodeId,
    ): bool {
        if ($remoteTs < $localTs) {
            return true;
        }
        if ($remoteTs === $localTs) {
            return $remoteNodeId < $localNodeId;
        }
        return false;
    }
}
