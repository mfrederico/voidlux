<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Gossip;

use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\TcpMesh;
use VoidLux\Swarm\Model\AgentModel;
use VoidLux\Swarm\Model\MessageModel;
use VoidLux\Swarm\Model\TaskModel;
use VoidLux\Swarm\Model\TaskStatus;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Push-based task and agent dissemination over the P2P mesh.
 * Mirrors GossipEngine but for swarm messages.
 */
class TaskGossipEngine
{
    /** @var array<string, true> Seen message UUIDs for dedup */
    private array $seenMessages = [];
    private int $seenLimit = 10000;

    /** @var array<string, int> Agent IDs → deregister timestamp (tombstone to prevent resurrection) */
    private array $agentTombstones = [];
    private const TOMBSTONE_TTL = 120; // seconds

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly SwarmDatabase $db,
        private readonly LamportClock $clock,
    ) {}

    // --- Task gossip ---

    public function createTask(TaskModel $task): TaskModel
    {
        $this->db->insertTask($task);
        $this->seenMessages[$task->id . ':create'] = true;
        $this->broadcastTask(MessageTypes::TASK_CREATE, $task);
        return $task;
    }

    public function receiveTaskCreate(array $taskData, ?string $senderAddress = null): ?TaskModel
    {
        $id = $taskData['id'] ?? '';
        $key = $id . ':create';
        if (!$id || isset($this->seenMessages[$key])) {
            return null;
        }

        if ($this->db->hasTask($id)) {
            $this->seenMessages[$key] = true;
            return null;
        }

        $this->clock->witness($taskData['lamport_ts'] ?? 0);
        $task = TaskModel::fromArray($taskData);
        $this->db->insertTask($task);
        $this->seenMessages[$key] = true;

        $this->broadcastTask(MessageTypes::TASK_CREATE, $task, $senderAddress);
        $this->pruneSeenMessages();

        return $task;
    }

    public function gossipTaskClaim(string $taskId, string $agentId, string $nodeId, int $lamportTs): void
    {
        $key = $taskId . ':claim:' . $agentId;
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::TASK_CLAIM,
            'task_id' => $taskId,
            'agent_id' => $agentId,
            'node_id' => $nodeId,
            'lamport_ts' => $lamportTs,
        ]);
    }

    public function receiveTaskClaim(array $msg, ?string $senderAddress = null): bool
    {
        $taskId = $msg['task_id'] ?? '';
        $agentId = $msg['agent_id'] ?? '';
        $key = $taskId . ':claim:' . $agentId;

        if (isset($this->seenMessages[$key])) {
            return false;
        }
        $this->seenMessages[$key] = true;

        $this->clock->witness($msg['lamport_ts'] ?? 0);

        // Forward to peers
        $this->mesh->broadcast([
            'type' => MessageTypes::TASK_CLAIM,
            'task_id' => $taskId,
            'agent_id' => $agentId,
            'node_id' => $msg['node_id'] ?? '',
            'lamport_ts' => $msg['lamport_ts'] ?? 0,
        ], $senderAddress);

        $this->pruneSeenMessages();
        return true;
    }

    public function gossipTaskUpdate(string $taskId, string $agentId, string $status, ?string $progress, int $lamportTs): void
    {
        $key = $taskId . ':update:' . $lamportTs;
        $this->seenMessages[$key] = true;

        // Include gitBranch so remote nodes (especially emperor) get the branch name
        $task = $this->db->getTask($taskId);
        $gitBranch = $task?->gitBranch ?? '';

        $this->mesh->broadcast([
            'type' => MessageTypes::TASK_UPDATE,
            'task_id' => $taskId,
            'agent_id' => $agentId,
            'status' => $status,
            'progress' => $progress,
            'lamport_ts' => $lamportTs,
            'git_branch' => $gitBranch,
        ]);
    }

    public function receiveTaskUpdate(array $msg, ?string $senderAddress = null): bool
    {
        $key = ($msg['task_id'] ?? '') . ':update:' . ($msg['lamport_ts'] ?? 0);
        if (isset($this->seenMessages[$key])) {
            return false;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        // Write to local DB so emperor dashboard reflects the update
        $task = $this->db->getTask($msg['task_id'] ?? '');
        if ($task && !$task->status->isTerminal()) {
            $newStatus = TaskStatus::tryFrom($msg['status'] ?? '') ?? TaskStatus::InProgress;
            $updated = new TaskModel(
                id: $task->id,
                title: $task->title,
                description: $task->description,
                status: $newStatus,
                priority: $task->priority,
                requiredCapabilities: $task->requiredCapabilities,
                createdBy: $task->createdBy,
                assignedTo: $msg['agent_id'] ?? $task->assignedTo,
                assignedNode: $task->assignedNode,
                result: $task->result,
                error: $task->error,
                progress: $msg['progress'] ?? $task->progress,
                projectPath: $task->projectPath,
                context: $task->context,
                lamportTs: $msg['lamport_ts'] ?? $task->lamportTs,
                claimedAt: $task->claimedAt,
                completedAt: $task->completedAt,
                createdAt: $task->createdAt,
                updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
                parentId: $task->parentId,
                workInstructions: $task->workInstructions,
                acceptanceCriteria: $task->acceptanceCriteria,
                reviewStatus: $task->reviewStatus,
                reviewFeedback: $task->reviewFeedback,
                archived: $task->archived,
                gitBranch: ($msg['git_branch'] ?? '') !== '' ? $msg['git_branch'] : $task->gitBranch,
                mergeAttempts: $task->mergeAttempts,
                testCommand: $task->testCommand,
                dependsOn: $task->dependsOn,
                autoMerge: $task->autoMerge,
                prUrl: $task->prUrl,
            );
            $this->db->updateTask($updated);
        }

        $this->mesh->broadcast($msg + ['type' => MessageTypes::TASK_UPDATE], $senderAddress);
        $this->pruneSeenMessages();
        return true;
    }

    public function gossipTaskComplete(string $taskId, string $agentId, ?string $result, int $lamportTs): void
    {
        $key = $taskId . ':complete';
        $this->seenMessages[$key] = true;

        // Include gitBranch so emperor can merge subtask branches
        $task = $this->db->getTask($taskId);
        $gitBranch = $task?->gitBranch ?? '';

        $this->mesh->broadcast([
            'type' => MessageTypes::TASK_COMPLETE,
            'task_id' => $taskId,
            'agent_id' => $agentId,
            'result' => $result,
            'lamport_ts' => $lamportTs,
            'git_branch' => $gitBranch,
        ]);
    }

    public function receiveTaskComplete(array $msg, ?string $senderAddress = null): bool
    {
        $key = ($msg['task_id'] ?? '') . ':complete';
        if (isset($this->seenMessages[$key])) {
            return false;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        // Update local DB
        $task = $this->db->getTask($msg['task_id'] ?? '');
        if ($task && !$task->status->isTerminal()) {
            // Use gitBranch from gossip if present (worker set it during delivery),
            // fall back to local DB value
            $gitBranch = ($msg['git_branch'] ?? '') !== '' ? $msg['git_branch'] : $task->gitBranch;

            $updated = new TaskModel(
                id: $task->id,
                title: $task->title,
                description: $task->description,
                status: TaskStatus::Completed,
                priority: $task->priority,
                requiredCapabilities: $task->requiredCapabilities,
                createdBy: $task->createdBy,
                assignedTo: $msg['agent_id'] ?? $task->assignedTo,
                assignedNode: $task->assignedNode,
                result: $msg['result'] ?? null,
                error: null,
                progress: null,
                projectPath: $task->projectPath,
                context: $task->context,
                lamportTs: $msg['lamport_ts'] ?? $task->lamportTs,
                claimedAt: $task->claimedAt,
                completedAt: gmdate('Y-m-d\TH:i:s\Z'),
                createdAt: $task->createdAt,
                updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
                parentId: $task->parentId,
                workInstructions: $task->workInstructions,
                acceptanceCriteria: $task->acceptanceCriteria,
                reviewStatus: $task->reviewStatus,
                reviewFeedback: $task->reviewFeedback,
                archived: $task->archived,
                gitBranch: $gitBranch,
                mergeAttempts: $task->mergeAttempts,
                testCommand: $task->testCommand,
                dependsOn: $task->dependsOn,
                autoMerge: $task->autoMerge,
                prUrl: $task->prUrl,
            );
            $this->db->updateTask($updated);
        }

        $this->mesh->broadcast($msg + ['type' => MessageTypes::TASK_COMPLETE], $senderAddress);
        $this->pruneSeenMessages();
        return true;
    }

    public function gossipTaskFail(string $taskId, string $agentId, ?string $error, int $lamportTs): void
    {
        $key = $taskId . ':fail';
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::TASK_FAIL,
            'task_id' => $taskId,
            'agent_id' => $agentId,
            'error' => $error,
            'lamport_ts' => $lamportTs,
        ]);
    }

    public function receiveTaskFail(array $msg, ?string $senderAddress = null): bool
    {
        $key = ($msg['task_id'] ?? '') . ':fail';
        if (isset($this->seenMessages[$key])) {
            return false;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $task = $this->db->getTask($msg['task_id'] ?? '');
        if ($task && !$task->status->isTerminal()) {
            $updated = new TaskModel(
                id: $task->id,
                title: $task->title,
                description: $task->description,
                status: TaskStatus::Failed,
                priority: $task->priority,
                requiredCapabilities: $task->requiredCapabilities,
                createdBy: $task->createdBy,
                assignedTo: $msg['agent_id'] ?? $task->assignedTo,
                assignedNode: $task->assignedNode,
                result: null,
                error: $msg['error'] ?? null,
                progress: null,
                projectPath: $task->projectPath,
                context: $task->context,
                lamportTs: $msg['lamport_ts'] ?? $task->lamportTs,
                claimedAt: $task->claimedAt,
                completedAt: gmdate('Y-m-d\TH:i:s\Z'),
                createdAt: $task->createdAt,
                updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
                parentId: $task->parentId,
                workInstructions: $task->workInstructions,
                acceptanceCriteria: $task->acceptanceCriteria,
                reviewStatus: $task->reviewStatus,
                reviewFeedback: $task->reviewFeedback,
                archived: $task->archived,
                gitBranch: $task->gitBranch,
                mergeAttempts: $task->mergeAttempts,
                testCommand: $task->testCommand,
                dependsOn: $task->dependsOn,
                autoMerge: $task->autoMerge,
                prUrl: $task->prUrl,
            );
            $this->db->updateTask($updated);
        }

        $this->mesh->broadcast($msg + ['type' => MessageTypes::TASK_FAIL], $senderAddress);
        $this->pruneSeenMessages();
        return true;
    }

    public function gossipTaskCancel(string $taskId, int $lamportTs): void
    {
        $key = $taskId . ':cancel';
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::TASK_CANCEL,
            'task_id' => $taskId,
            'lamport_ts' => $lamportTs,
        ]);
    }

    public function receiveTaskCancel(array $msg, ?string $senderAddress = null): bool
    {
        $key = ($msg['task_id'] ?? '') . ':cancel';
        if (isset($this->seenMessages[$key])) {
            return false;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $task = $this->db->getTask($msg['task_id'] ?? '');
        if ($task && !$task->status->isTerminal()) {
            $updated = $task->withStatus(TaskStatus::Cancelled, $msg['lamport_ts'] ?? $task->lamportTs);
            $this->db->updateTask($updated);
        }

        $this->mesh->broadcast($msg + ['type' => MessageTypes::TASK_CANCEL], $senderAddress);
        $this->pruneSeenMessages();
        return true;
    }

    public function gossipTaskArchive(string $taskId, int $lamportTs): void
    {
        $key = $taskId . ':archive';
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::TASK_ARCHIVE,
            'task_id' => $taskId,
            'lamport_ts' => $lamportTs,
        ]);
    }

    public function receiveTaskArchive(array $msg, ?string $senderAddress = null): bool
    {
        $key = ($msg['task_id'] ?? '') . ':archive';
        if (isset($this->seenMessages[$key])) {
            return false;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $this->db->archiveTask($msg['task_id'] ?? '');

        $this->mesh->broadcast($msg + ['type' => MessageTypes::TASK_ARCHIVE], $senderAddress);
        $this->pruneSeenMessages();
        return true;
    }

    // --- Agent gossip ---

    public function gossipAgentRegister(AgentModel $agent): void
    {
        $key = 'agent:' . $agent->id;
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::AGENT_REGISTER,
            'agent' => $agent->toArray(),
        ]);
    }

    public function receiveAgentRegister(array $msg, ?string $senderAddress = null): ?AgentModel
    {
        $agentData = $msg['agent'] ?? [];
        $id = $agentData['id'] ?? '';
        $key = 'agent:' . $id;

        if (!$id || isset($this->seenMessages[$key])) {
            return null;
        }
        if ($this->isAgentTombstoned($id)) {
            return null;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($agentData['lamport_ts'] ?? 0);

        $agent = AgentModel::fromArray($agentData);
        $this->db->insertAgent($agent);

        $this->mesh->broadcast($msg + ['type' => MessageTypes::AGENT_REGISTER], $senderAddress);
        $this->pruneSeenMessages();
        return $agent;
    }

    public function gossipAgentHeartbeat(AgentModel $agent, int $lamportTs): void
    {
        $key = 'hb:' . $agent->id . ':' . $lamportTs;
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::AGENT_HEARTBEAT,
            'agent_id' => $agent->id,
            'node_id' => $agent->nodeId,
            'name' => $agent->name,
            'tool' => $agent->tool,
            'model' => $agent->model,
            'capabilities' => $agent->capabilities,
            'tmux_session_id' => $agent->tmuxSessionId,
            'project_path' => $agent->projectPath,
            'status' => $agent->status,
            'current_task_id' => $agent->currentTaskId,
            'lamport_ts' => $lamportTs,
        ]);
    }

    public function gossipAgentDeregister(string $agentId): void
    {
        $key = 'agent_deregister:' . $agentId;
        $this->seenMessages[$key] = true;
        $this->agentTombstones[$agentId] = time();

        $this->mesh->broadcast([
            'type' => MessageTypes::AGENT_DEREGISTER,
            'agent_id' => $agentId,
        ]);
    }

    public function receiveAgentDeregister(array $msg, ?string $senderAddress = null): ?string
    {
        $agentId = $msg['agent_id'] ?? '';
        $key = 'agent_deregister:' . $agentId;

        if (!$agentId || isset($this->seenMessages[$key])) {
            return null;
        }
        $this->seenMessages[$key] = true;
        $this->agentTombstones[$agentId] = time();

        $this->db->updateAgentStatus($agentId, 'offline');
        $this->db->deleteAgent($agentId);

        $this->mesh->broadcast($msg + ['type' => MessageTypes::AGENT_DEREGISTER], $senderAddress);
        $this->pruneSeenMessages();
        return $agentId;
    }

    public function receiveAgentHeartbeat(array $msg, ?string $senderAddress = null): void
    {
        $agentId = $msg['agent_id'] ?? '';
        if (!$agentId) {
            return;
        }

        // Dedup: each heartbeat (agent + lamport_ts) processed only once per node
        $key = 'hb:' . $agentId . ':' . ($msg['lamport_ts'] ?? 0);
        if (isset($this->seenMessages[$key])) {
            return;
        }
        $this->seenMessages[$key] = true;

        // Don't resurrect recently deregistered agents
        if ($this->isAgentTombstoned($agentId)) {
            return;
        }

        $this->clock->witness($msg['lamport_ts'] ?? 0);

        // If we don't know this agent yet, create a stub record from heartbeat data
        if (!$this->db->getAgent($agentId)) {
            $agent = AgentModel::fromArray([
                'id' => $agentId,
                'node_id' => $msg['node_id'] ?? '',
                'name' => $msg['name'] ?? $agentId,
                'tool' => $msg['tool'] ?? 'claude',
                'model' => $msg['model'] ?? '',
                'capabilities' => $msg['capabilities'] ?? '[]',
                'tmux_session_id' => $msg['tmux_session_id'] ?? null,
                'project_path' => $msg['project_path'] ?? '',
                'max_concurrent_tasks' => 1,
                'status' => $msg['status'] ?? 'idle',
                'current_task_id' => $msg['current_task_id'] ?? null,
                'last_heartbeat' => gmdate('Y-m-d\TH:i:s\Z'),
                'lamport_ts' => $msg['lamport_ts'] ?? 0,
                'registered_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $this->db->insertAgent($agent);
        } else {
            $this->db->updateAgentHeartbeat($agentId, $msg['status'] ?? 'offline', $msg['current_task_id'] ?? null);
        }

        $this->mesh->broadcast($msg + ['type' => MessageTypes::AGENT_HEARTBEAT], $senderAddress);
        $this->pruneSeenMessages();
    }

    // --- Message board gossip ---

    public function createBoardMessage(MessageModel $msg): MessageModel
    {
        $this->db->insertMessage($msg);
        $this->seenMessages['board:' . $msg->id . ':create'] = true;
        $this->mesh->broadcast([
            'type' => MessageTypes::BOARD_POST,
            'message' => $msg->toArray(),
        ]);
        return $msg;
    }

    public function receiveBoardPost(array $msgData, ?string $senderAddress = null): ?MessageModel
    {
        $data = $msgData['message'] ?? $msgData;
        $id = $data['id'] ?? '';
        $key = 'board:' . $id . ':create';

        if (!$id || isset($this->seenMessages[$key])) {
            return null;
        }
        if ($this->db->hasMessage($id)) {
            $this->seenMessages[$key] = true;
            return null;
        }

        $this->clock->witness($data['lamport_ts'] ?? 0);
        $msg = MessageModel::fromArray($data);
        $this->db->insertMessage($msg);
        $this->seenMessages[$key] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::BOARD_POST,
            'message' => $msg->toArray(),
        ], $senderAddress);
        $this->pruneSeenMessages();

        return $msg;
    }

    public function gossipBoardUpdate(MessageModel $msg): void
    {
        $key = 'board:' . $msg->id . ':update:' . $msg->lamportTs;
        $this->seenMessages[$key] = true;
        $this->db->updateMessage($msg);

        $this->mesh->broadcast([
            'type' => MessageTypes::BOARD_UPDATE,
            'message' => $msg->toArray(),
        ]);
    }

    public function receiveBoardUpdate(array $msgData, ?string $senderAddress = null): bool
    {
        $data = $msgData['message'] ?? $msgData;
        $id = $data['id'] ?? '';
        $ts = $data['lamport_ts'] ?? 0;
        $key = 'board:' . $id . ':update:' . $ts;

        if (!$id || isset($this->seenMessages[$key])) {
            return false;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($ts);

        $existing = $this->db->getMessage($id);
        if ($existing && $ts > $existing->lamportTs) {
            $updated = MessageModel::fromArray($data);
            $this->db->updateMessage($updated);
        }

        $this->mesh->broadcast([
            'type' => MessageTypes::BOARD_UPDATE,
            'message' => $data,
        ], $senderAddress);
        $this->pruneSeenMessages();
        return true;
    }

    public function gossipBoardDelete(string $messageId, int $lamportTs): void
    {
        $key = 'board:' . $messageId . ':delete';
        $this->seenMessages[$key] = true;
        $this->db->deleteMessage($messageId);

        $this->mesh->broadcast([
            'type' => MessageTypes::BOARD_DELETE,
            'message_id' => $messageId,
            'lamport_ts' => $lamportTs,
        ]);
    }

    public function receiveBoardDelete(array $msg, ?string $senderAddress = null): bool
    {
        $id = $msg['message_id'] ?? '';
        $key = 'board:' . $id . ':delete';

        if (!$id || isset($this->seenMessages[$key])) {
            return false;
        }
        $this->seenMessages[$key] = true;
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $this->db->deleteMessage($id);

        $this->mesh->broadcast($msg + ['type' => MessageTypes::BOARD_DELETE], $senderAddress);
        $this->pruneSeenMessages();
        return true;
    }

    // --- Helpers ---

    private function broadcastTask(int $type, TaskModel $task, ?string $excludeAddress = null): void
    {
        $this->mesh->broadcast([
            'type' => $type,
            'task' => $task->toArray(),
        ], $excludeAddress);
    }

    /**
     * Check if an agent was recently deregistered (tombstone prevents resurrection).
     */
    private function isAgentTombstoned(string $agentId): bool
    {
        if (!isset($this->agentTombstones[$agentId])) {
            return false;
        }
        if ((time() - $this->agentTombstones[$agentId]) > self::TOMBSTONE_TTL) {
            unset($this->agentTombstones[$agentId]);
            return false;
        }
        return true;
    }

    private function pruneSeenMessages(): void
    {
        if (count($this->seenMessages) > $this->seenLimit) {
            $this->seenMessages = array_slice($this->seenMessages, -($this->seenLimit / 2), null, true);
        }

        // Prune expired tombstones
        $now = time();
        foreach ($this->agentTombstones as $id => $ts) {
            if (($now - $ts) > self::TOMBSTONE_TTL) {
                unset($this->agentTombstones[$id]);
            }
        }
    }
}
