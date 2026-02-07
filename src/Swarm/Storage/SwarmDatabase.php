<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Storage;

use VoidLux\Swarm\Model\AgentModel;
use VoidLux\Swarm\Model\TaskModel;
use VoidLux\Swarm\Model\TaskStatus;

class SwarmDatabase
{
    private \PDO $pdo;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new \PDO("sqlite:{$dbPath}", null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA synchronous=NORMAL');

        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS tasks (
                id TEXT PRIMARY KEY,
                title TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT \'\',
                status TEXT NOT NULL DEFAULT \'pending\',
                priority INTEGER NOT NULL DEFAULT 0,
                required_capabilities TEXT NOT NULL DEFAULT \'[]\',
                created_by TEXT NOT NULL,
                assigned_to TEXT,
                assigned_node TEXT,
                result TEXT,
                error TEXT,
                progress TEXT,
                project_path TEXT NOT NULL DEFAULT \'\',
                context TEXT NOT NULL DEFAULT \'\',
                lamport_ts INTEGER NOT NULL,
                claimed_at TEXT,
                completed_at TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS agents (
                id TEXT PRIMARY KEY,
                node_id TEXT NOT NULL,
                name TEXT NOT NULL,
                tool TEXT NOT NULL DEFAULT \'claude\',
                capabilities TEXT NOT NULL DEFAULT \'[]\',
                tmux_session_id TEXT,
                project_path TEXT NOT NULL DEFAULT \'\',
                max_concurrent_tasks INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT \'offline\',
                current_task_id TEXT,
                last_heartbeat TEXT,
                lamport_ts INTEGER NOT NULL,
                registered_at TEXT NOT NULL
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS swarm_state (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )
        ');

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_lamport ON tasks(lamport_ts)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_priority ON tasks(priority DESC, created_at ASC)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_agents_node ON agents(node_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_agents_status ON agents(status)');
    }

    // --- Task operations ---

    public function insertTask(TaskModel $task): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO tasks
                (id, title, description, status, priority, required_capabilities, created_by,
                 assigned_to, assigned_node, result, error, progress, project_path, context,
                 lamport_ts, claimed_at, completed_at, created_at, updated_at)
            VALUES
                (:id, :title, :description, :status, :priority, :required_capabilities, :created_by,
                 :assigned_to, :assigned_node, :result, :error, :progress, :project_path, :context,
                 :lamport_ts, :claimed_at, :completed_at, :created_at, :updated_at)
        ');

        return $stmt->execute([
            ':id' => $task->id,
            ':title' => $task->title,
            ':description' => $task->description,
            ':status' => $task->status->value,
            ':priority' => $task->priority,
            ':required_capabilities' => json_encode($task->requiredCapabilities),
            ':created_by' => $task->createdBy,
            ':assigned_to' => $task->assignedTo,
            ':assigned_node' => $task->assignedNode,
            ':result' => $task->result,
            ':error' => $task->error,
            ':progress' => $task->progress,
            ':project_path' => $task->projectPath,
            ':context' => $task->context,
            ':lamport_ts' => $task->lamportTs,
            ':claimed_at' => $task->claimedAt,
            ':completed_at' => $task->completedAt,
            ':created_at' => $task->createdAt,
            ':updated_at' => $task->updatedAt,
        ]);
    }

    public function updateTask(TaskModel $task): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE tasks SET
                title = :title, description = :description, status = :status,
                priority = :priority, required_capabilities = :required_capabilities,
                assigned_to = :assigned_to, assigned_node = :assigned_node,
                result = :result, error = :error, progress = :progress,
                project_path = :project_path, context = :context,
                lamport_ts = :lamport_ts, claimed_at = :claimed_at,
                completed_at = :completed_at, updated_at = :updated_at
            WHERE id = :id
        ');

        return $stmt->execute([
            ':id' => $task->id,
            ':title' => $task->title,
            ':description' => $task->description,
            ':status' => $task->status->value,
            ':priority' => $task->priority,
            ':required_capabilities' => json_encode($task->requiredCapabilities),
            ':assigned_to' => $task->assignedTo,
            ':assigned_node' => $task->assignedNode,
            ':result' => $task->result,
            ':error' => $task->error,
            ':progress' => $task->progress,
            ':project_path' => $task->projectPath,
            ':context' => $task->context,
            ':lamport_ts' => $task->lamportTs,
            ':claimed_at' => $task->claimedAt,
            ':completed_at' => $task->completedAt,
            ':updated_at' => $task->updatedAt,
        ]);
    }

    /**
     * Atomically claim a task. Returns true if this node won the claim.
     */
    public function claimTask(string $taskId, string $agentId, string $nodeId, int $lamportTs): bool
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('
            UPDATE tasks SET
                status = :status, assigned_to = :agent_id, assigned_node = :node_id,
                lamport_ts = :lamport_ts, claimed_at = :claimed_at, updated_at = :updated_at
            WHERE id = :id AND status = \'pending\'
        ');

        $stmt->execute([
            ':id' => $taskId,
            ':status' => TaskStatus::Claimed->value,
            ':agent_id' => $agentId,
            ':node_id' => $nodeId,
            ':lamport_ts' => $lamportTs,
            ':claimed_at' => $now,
            ':updated_at' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function hasTask(string $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM tasks WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetchColumn() !== false;
    }

    public function getTask(string $id): ?TaskModel
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? TaskModel::fromArray($row) : null;
    }

    /**
     * @return TaskModel[]
     */
    public function getTasksByStatus(?string $status = null): array
    {
        if ($status) {
            $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE status = :status ORDER BY priority DESC, created_at ASC');
            $stmt->execute([':status' => $status]);
        } else {
            $stmt = $this->pdo->query('SELECT * FROM tasks ORDER BY priority DESC, created_at ASC');
        }
        return array_map(fn(array $row) => TaskModel::fromArray($row), $stmt->fetchAll());
    }

    /**
     * @return TaskModel[]
     */
    public function getTasksSince(int $lamportTs): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE lamport_ts > :ts ORDER BY lamport_ts ASC');
        $stmt->execute([':ts' => $lamportTs]);
        return array_map(fn(array $row) => TaskModel::fromArray($row), $stmt->fetchAll());
    }

    public function getTaskCount(?string $status = null): int
    {
        if ($status) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tasks WHERE status = :status');
            $stmt->execute([':status' => $status]);
            return (int) $stmt->fetchColumn();
        }
        return (int) $this->pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
    }

    public function getMaxTaskLamportTs(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(lamport_ts), 0) FROM tasks')->fetchColumn();
    }

    /**
     * Get the next pending task that matches agent capabilities.
     */
    public function getNextPendingTask(array $agentCapabilities): ?TaskModel
    {
        $tasks = $this->getTasksByStatus('pending');
        foreach ($tasks as $task) {
            if (empty($task->requiredCapabilities)) {
                return $task;
            }
            $missing = array_diff($task->requiredCapabilities, $agentCapabilities);
            if (empty($missing)) {
                return $task;
            }
        }
        return null;
    }

    // --- Agent operations ---

    public function insertAgent(AgentModel $agent): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO agents
                (id, node_id, name, tool, capabilities, tmux_session_id, project_path,
                 max_concurrent_tasks, status, current_task_id, last_heartbeat, lamport_ts, registered_at)
            VALUES
                (:id, :node_id, :name, :tool, :capabilities, :tmux_session_id, :project_path,
                 :max_concurrent_tasks, :status, :current_task_id, :last_heartbeat, :lamport_ts, :registered_at)
        ');

        return $stmt->execute([
            ':id' => $agent->id,
            ':node_id' => $agent->nodeId,
            ':name' => $agent->name,
            ':tool' => $agent->tool,
            ':capabilities' => json_encode($agent->capabilities),
            ':tmux_session_id' => $agent->tmuxSessionId,
            ':project_path' => $agent->projectPath,
            ':max_concurrent_tasks' => $agent->maxConcurrentTasks,
            ':status' => $agent->status,
            ':current_task_id' => $agent->currentTaskId,
            ':last_heartbeat' => $agent->lastHeartbeat,
            ':lamport_ts' => $agent->lamportTs,
            ':registered_at' => $agent->registeredAt,
        ]);
    }

    public function updateAgentStatus(string $agentId, string $status, ?string $currentTaskId = null): bool
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('
            UPDATE agents SET status = :status, current_task_id = :task_id, last_heartbeat = :heartbeat
            WHERE id = :id
        ');
        return $stmt->execute([
            ':id' => $agentId,
            ':status' => $status,
            ':task_id' => $currentTaskId,
            ':heartbeat' => $now,
        ]);
    }

    public function updateAgentHeartbeat(string $agentId, string $status, ?string $currentTaskId = null): bool
    {
        return $this->updateAgentStatus($agentId, $status, $currentTaskId);
    }

    public function getAgent(string $id): ?AgentModel
    {
        $stmt = $this->pdo->prepare('SELECT * FROM agents WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? AgentModel::fromArray($row) : null;
    }

    /**
     * @return AgentModel[]
     */
    public function getAllAgents(): array
    {
        $rows = $this->pdo->query('SELECT * FROM agents ORDER BY registered_at ASC')->fetchAll();
        return array_map(fn(array $row) => AgentModel::fromArray($row), $rows);
    }

    /**
     * @return AgentModel[]
     */
    public function getLocalAgents(string $nodeId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM agents WHERE node_id = :node_id ORDER BY registered_at ASC');
        $stmt->execute([':node_id' => $nodeId]);
        return array_map(fn(array $row) => AgentModel::fromArray($row), $stmt->fetchAll());
    }

    /**
     * @return AgentModel[]
     */
    public function getIdleAgents(string $nodeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM agents WHERE node_id = :node_id AND status = \'idle\' ORDER BY registered_at ASC'
        );
        $stmt->execute([':node_id' => $nodeId]);
        return array_map(fn(array $row) => AgentModel::fromArray($row), $stmt->fetchAll());
    }

    public function deleteAgent(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM agents WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function getAgentCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM agents')->fetchColumn();
    }

    // --- State operations ---

    public function getState(string $key, string $default = ''): string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM swarm_state WHERE key = :key');
        $stmt->execute([':key' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    }

    public function setState(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('INSERT OR REPLACE INTO swarm_state (key, value) VALUES (:key, :value)');
        $stmt->execute([':key' => $key, ':value' => $value]);
    }
}
