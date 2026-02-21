<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Agent;

use Swoole\Coroutine;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\Swarm\Gossip\TaskGossipEngine;
use VoidLux\Swarm\Model\AgentModel;
use VoidLux\Swarm\Orchestrator\TaskQueue;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Manages agent registration, heartbeat broadcasts, and offline detection.
 *
 * Each agent belongs to a node (identified by node_id). The node_id is the
 * persistent identifier of the swarm process that owns the agent's tmux session.
 * Agent names include a 6-char node_id prefix for uniqueness across workers.
 *
 * Deregistration gossips AGENT_DEREGISTER so all peers remove the record.
 * If the agent has an active task, it is requeued to pending before deletion.
 */
class AgentRegistry
{
    private const HEARTBEAT_INTERVAL = 15;
    private const OFFLINE_THRESHOLD = 45;

    private bool $running = false;

    private ?TaskQueue $taskQueue = null;

    public function __construct(
        private readonly SwarmDatabase $db,
        private readonly TaskGossipEngine $gossip,
        private readonly LamportClock $clock,
        private readonly string $nodeId,
    ) {}

    public function setTaskQueue(TaskQueue $taskQueue): void
    {
        $this->taskQueue = $taskQueue;
    }

    public function register(
        string $name,
        string $tool = 'claude',
        string $model = '',
        array $capabilities = [],
        ?string $tmuxSessionId = null,
        string $projectPath = '',
        int $maxConcurrentTasks = 1,
        string $role = '',
    ): AgentModel {
        $ts = $this->clock->tick();
        $agent = AgentModel::create(
            nodeId: $this->nodeId,
            name: $name,
            lamportTs: $ts,
            tool: $tool,
            model: $model,
            capabilities: $capabilities,
            tmuxSessionId: $tmuxSessionId,
            projectPath: $projectPath,
            maxConcurrentTasks: $maxConcurrentTasks,
            role: $role,
        );

        $this->db->insertAgent($agent);
        $this->gossip->gossipAgentRegister($agent);

        return $agent;
    }

    public function deregister(string $agentId): bool
    {
        // Requeue any active task before removing the agent
        $agent = $this->db->getAgent($agentId);
        if ($agent && $agent->currentTaskId && $this->taskQueue) {
            $this->taskQueue->requeue($agent->currentTaskId, 'Agent deregistered');
        }

        $this->db->updateAgentStatus($agentId, 'offline');
        $deleted = $this->db->deleteAgent($agentId);

        // Gossip so peers remove the agent too
        $this->gossip->gossipAgentDeregister($agentId);

        return $deleted;
    }

    public function getAgent(string $id): ?AgentModel
    {
        return $this->db->getAgent($id);
    }

    /** @return AgentModel[] */
    public function getAllAgents(): array
    {
        return $this->db->getAllAgents();
    }

    /** @return AgentModel[] */
    public function getLocalAgents(): array
    {
        return $this->db->getLocalAgents($this->nodeId);
    }

    /** @return AgentModel[] */
    public function getIdleAgents(): array
    {
        return $this->db->getIdleAgents($this->nodeId);
    }

    /**
     * Re-gossip AGENT_REGISTER for all local agents (census response).
     */
    public function reannounceAll(): int
    {
        $agents = $this->db->getLocalAgents($this->nodeId);
        foreach ($agents as $agent) {
            $this->gossip->gossipAgentRegister($agent);
        }
        return count($agents);
    }

    /**
     * Start heartbeat loop for local agents.
     */
    public function start(): void
    {
        $this->running = true;

        Coroutine::create(function () {
            while ($this->running) {
                Coroutine::sleep(self::HEARTBEAT_INTERVAL);
                $this->broadcastHeartbeats();
                $this->detectOfflineAgents();
            }
        });
    }

    /**
     * Send an immediate heartbeat for a specific agent (e.g. on status change).
     */
    public function gossipAgentNow(AgentModel $agent): void
    {
        $ts = $this->clock->tick();
        $this->gossip->gossipAgentHeartbeat($agent, $ts);
    }

    private function broadcastHeartbeats(): void
    {
        $agents = $this->db->getLocalAgents($this->nodeId);
        $ts = $this->clock->tick();

        foreach ($agents as $agent) {
            $this->gossip->gossipAgentHeartbeat($agent, $ts);
        }
    }

    private function detectOfflineAgents(): void
    {
        $agents = $this->db->getAllAgents();
        $now = time();

        foreach ($agents as $agent) {
            if ($agent->nodeId === $this->nodeId) {
                continue; // Don't mark own agents offline
            }
            if ($agent->status === 'offline') {
                continue;
            }
            if (!$agent->lastHeartbeat) {
                continue;
            }

            $lastBeat = strtotime($agent->lastHeartbeat);
            if ($lastBeat && ($now - $lastBeat) > self::OFFLINE_THRESHOLD) {
                $this->db->updateAgentStatus($agent->id, 'offline');
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }
}
