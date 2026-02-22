<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Storage;

use VoidLux\Swarm\Model\AgentModel;
use VoidLux\Swarm\Model\MessageModel;
use VoidLux\Swarm\Model\SwarmNodeModel;
use VoidLux\Swarm\Model\TaskModel;
use VoidLux\Swarm\Model\TaskStatus;
use VoidLux\Swarm\Offer\OfferModel;
use VoidLux\Swarm\Offer\PaymentModel;

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
                model TEXT NOT NULL DEFAULT \'\',
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

        // Schema migrations for planning + review columns
        $columns = $this->pdo->query("PRAGMA table_info(tasks)")->fetchAll();
        $existing = array_column($columns, 'name');
        $additions = [
            'parent_id' => 'TEXT',
            'work_instructions' => "TEXT NOT NULL DEFAULT ''",
            'acceptance_criteria' => "TEXT NOT NULL DEFAULT ''",
            'review_status' => "TEXT NOT NULL DEFAULT 'none'",
            'review_feedback' => "TEXT NOT NULL DEFAULT ''",
        ];
        foreach ($additions as $col => $def) {
            if (!in_array($col, $existing, true)) {
                $this->pdo->exec("ALTER TABLE tasks ADD COLUMN {$col} {$def}");
            }
        }
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_parent ON tasks(parent_id)');

        // Add archived column for job log
        if (!in_array('archived', $existing, true)) {
            $this->pdo->exec("ALTER TABLE tasks ADD COLUMN archived INTEGER NOT NULL DEFAULT 0");
        }
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_archived ON tasks(archived)');

        // Add git_branch column for per-task branch tracking
        if (!in_array('git_branch', $existing, true)) {
            $this->pdo->exec("ALTER TABLE tasks ADD COLUMN git_branch TEXT NOT NULL DEFAULT ''");
        }

        // Add merge-test-retry columns
        if (!in_array('merge_attempts', $existing, true)) {
            $this->pdo->exec("ALTER TABLE tasks ADD COLUMN merge_attempts INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('test_command', $existing, true)) {
            $this->pdo->exec("ALTER TABLE tasks ADD COLUMN test_command TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('depends_on', $existing, true)) {
            $this->pdo->exec("ALTER TABLE tasks ADD COLUMN depends_on TEXT NOT NULL DEFAULT '[]'");
        }
        if (!in_array('auto_merge', $existing, true)) {
            $this->pdo->exec("ALTER TABLE tasks ADD COLUMN auto_merge INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('pr_url', $existing, true)) {
            $this->pdo->exec("ALTER TABLE tasks ADD COLUMN pr_url TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('complexity', $existing, true)) {
            $this->pdo->exec("ALTER TABLE tasks ADD COLUMN complexity TEXT NOT NULL DEFAULT 'medium'");
        }

        // Add model column to agents table
        $agentColumns = $this->pdo->query("PRAGMA table_info(agents)")->fetchAll();
        $agentExisting = array_column($agentColumns, 'name');
        if (!in_array('model', $agentExisting, true)) {
            $this->pdo->exec("ALTER TABLE agents ADD COLUMN model TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('role', $agentExisting, true)) {
            $this->pdo->exec("ALTER TABLE agents ADD COLUMN role TEXT NOT NULL DEFAULT ''");
        }

        // Swarm node registry table
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS swarm_nodes (
                node_id TEXT PRIMARY KEY,
                role TEXT NOT NULL DEFAULT \'worker\',
                http_host TEXT NOT NULL DEFAULT \'0.0.0.0\',
                http_port INTEGER NOT NULL DEFAULT 0,
                p2p_port INTEGER NOT NULL DEFAULT 0,
                capabilities TEXT NOT NULL DEFAULT \'[]\',
                agent_count INTEGER NOT NULL DEFAULT 0,
                active_task_count INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT \'offline\',
                last_heartbeat TEXT,
                lamport_ts INTEGER NOT NULL DEFAULT 0,
                registered_at TEXT NOT NULL,
                uptime_seconds REAL NOT NULL DEFAULT 0.0,
                memory_usage_bytes INTEGER NOT NULL DEFAULT 0
            )
        ');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_swarm_nodes_status ON swarm_nodes(status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_swarm_nodes_role ON swarm_nodes(role)');

        // Offer-Pay protocol tables
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS offers (
                id TEXT PRIMARY KEY,
                from_node_id TEXT NOT NULL,
                to_node_id TEXT NOT NULL,
                amount INTEGER NOT NULL,
                currency TEXT NOT NULL DEFAULT \'VOID\',
                conditions TEXT NOT NULL DEFAULT \'\',
                status TEXT NOT NULL DEFAULT \'pending\',
                lamport_ts INTEGER NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                task_id TEXT,
                response_reason TEXT
            )
        ');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_offers_status ON offers(status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_offers_lamport ON offers(lamport_ts)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_offers_from ON offers(from_node_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_offers_to ON offers(to_node_id)');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS payments (
                id TEXT PRIMARY KEY,
                offer_id TEXT NOT NULL,
                from_node_id TEXT NOT NULL,
                to_node_id TEXT NOT NULL,
                amount INTEGER NOT NULL,
                currency TEXT NOT NULL DEFAULT \'VOID\',
                status TEXT NOT NULL DEFAULT \'initiated\',
                tx_hash TEXT NOT NULL DEFAULT \'\',
                lamport_ts INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                failure_reason TEXT,
                FOREIGN KEY (offer_id) REFERENCES offers(id)
            )
        ');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_payments_offer ON payments(offer_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_payments_status ON payments(status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_payments_lamport ON payments(lamport_ts)');

        // Message board table
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS board_messages (
                id TEXT PRIMARY KEY,
                author_id TEXT NOT NULL,
                author_name TEXT NOT NULL DEFAULT \'\',
                category TEXT NOT NULL DEFAULT \'discussion\',
                title TEXT NOT NULL,
                content TEXT NOT NULL DEFAULT \'\',
                priority INTEGER NOT NULL DEFAULT 0,
                tags TEXT NOT NULL DEFAULT \'[]\',
                status TEXT NOT NULL DEFAULT \'active\',
                claimed_by TEXT,
                parent_id TEXT,
                task_id TEXT,
                lamport_ts INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_board_category ON board_messages(category)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_board_status ON board_messages(status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_board_lamport ON board_messages(lamport_ts)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_board_parent ON board_messages(parent_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_board_author ON board_messages(author_id)');

        // Agent plugins table (many-to-many: agents <-> plugins)
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS agent_plugins (
                agent_id TEXT NOT NULL,
                plugin_name TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                config TEXT NOT NULL DEFAULT \'{}\',
                enabled_at TEXT NOT NULL,
                PRIMARY KEY (agent_id, plugin_name),
                FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
            )
        ');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_agent_plugins_agent ON agent_plugins(agent_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_agent_plugins_enabled ON agent_plugins(enabled)');
    }

    // --- Task operations ---

    public function insertTask(TaskModel $task): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO tasks
                (id, title, description, status, priority, required_capabilities, created_by,
                 assigned_to, assigned_node, result, error, progress, project_path, context,
                 lamport_ts, claimed_at, completed_at, created_at, updated_at,
                 parent_id, work_instructions, acceptance_criteria, review_status, review_feedback,
                 archived, git_branch, merge_attempts, test_command, depends_on,
                 auto_merge, pr_url)
            VALUES
                (:id, :title, :description, :status, :priority, :required_capabilities, :created_by,
                 :assigned_to, :assigned_node, :result, :error, :progress, :project_path, :context,
                 :lamport_ts, :claimed_at, :completed_at, :created_at, :updated_at,
                 :parent_id, :work_instructions, :acceptance_criteria, :review_status, :review_feedback,
                 :archived, :git_branch, :merge_attempts, :test_command, :depends_on,
                 :auto_merge, :pr_url)
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
            ':parent_id' => $task->parentId,
            ':work_instructions' => $task->workInstructions,
            ':acceptance_criteria' => $task->acceptanceCriteria,
            ':review_status' => $task->reviewStatus,
            ':review_feedback' => $task->reviewFeedback,
            ':archived' => $task->archived ? 1 : 0,
            ':git_branch' => $task->gitBranch,
            ':merge_attempts' => $task->mergeAttempts,
            ':test_command' => $task->testCommand,
            ':depends_on' => json_encode($task->dependsOn),
            ':auto_merge' => $task->autoMerge ? 1 : 0,
            ':pr_url' => $task->prUrl,
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
                completed_at = :completed_at, updated_at = :updated_at,
                parent_id = :parent_id, work_instructions = :work_instructions,
                acceptance_criteria = :acceptance_criteria, review_status = :review_status,
                review_feedback = :review_feedback, archived = :archived,
                git_branch = :git_branch, merge_attempts = :merge_attempts,
                test_command = :test_command, depends_on = :depends_on,
                auto_merge = :auto_merge, pr_url = :pr_url
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
            ':parent_id' => $task->parentId,
            ':work_instructions' => $task->workInstructions,
            ':acceptance_criteria' => $task->acceptanceCriteria,
            ':review_status' => $task->reviewStatus,
            ':review_feedback' => $task->reviewFeedback,
            ':archived' => $task->archived ? 1 : 0,
            ':git_branch' => $task->gitBranch,
            ':merge_attempts' => $task->mergeAttempts,
            ':test_command' => $task->testCommand,
            ':depends_on' => json_encode($task->dependsOn),
            ':auto_merge' => $task->autoMerge ? 1 : 0,
            ':pr_url' => $task->prUrl,
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
            WHERE id = :id AND status IN (\'pending\', \'planning\')
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
            $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE status = :status AND archived = 0 ORDER BY priority DESC, created_at ASC');
            $stmt->execute([':status' => $status]);
        } else {
            $stmt = $this->pdo->query('SELECT * FROM tasks WHERE archived = 0 ORDER BY priority DESC, created_at ASC');
        }
        return array_map(fn(array $row) => TaskModel::fromArray($row), $stmt->fetchAll());
    }

    /**
     * @return TaskModel[]
     */
    public function getTasksSince(int $lamportTs): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE lamport_ts > :ts AND archived = 0 ORDER BY lamport_ts ASC');
        $stmt->execute([':ts' => $lamportTs]);
        return array_map(fn(array $row) => TaskModel::fromArray($row), $stmt->fetchAll());
    }

    public function getTaskCount(?string $status = null): int
    {
        if ($status) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tasks WHERE status = :status AND archived = 0');
            $stmt->execute([':status' => $status]);
            return (int) $stmt->fetchColumn();
        }
        return (int) $this->pdo->query('SELECT COUNT(*) FROM tasks WHERE archived = 0')->fetchColumn();
    }

    public function getMaxTaskLamportTs(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(lamport_ts), 0) FROM tasks')->fetchColumn();
    }

    /**
     * Get the next pending task that matches agent capabilities.
     * Skips pending tasks with unsatisfied dependencies (worker pull-fallback safety).
     */
    public function getNextPendingTask(array $agentCapabilities): ?TaskModel
    {
        $tasks = $this->getTasksByStatus('pending');
        foreach ($tasks as $task) {
            // Skip tasks with unmet dependencies
            if (!empty($task->dependsOn) && !$this->areDependenciesMet($task->dependsOn)) {
                continue;
            }
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

    /**
     * Get blocked tasks where ALL dependencies are completed.
     * @return TaskModel[]
     */
    public function getUnblockedTasks(): array
    {
        $blocked = $this->getTasksByStatus('blocked');
        $unblocked = [];
        foreach ($blocked as $task) {
            if (empty($task->dependsOn)) {
                $unblocked[] = $task;
                continue;
            }
            if ($this->areDependenciesMet($task->dependsOn)) {
                $unblocked[] = $task;
            }
        }
        return $unblocked;
    }

    /**
     * Get blocked tasks where ANY dependency is failed or cancelled.
     * @return TaskModel[]
     */
    public function getBlockedTasksWithFailedDeps(): array
    {
        $blocked = $this->getTasksByStatus('blocked');
        $failed = [];
        foreach ($blocked as $task) {
            if (empty($task->dependsOn)) {
                continue;
            }
            foreach ($task->dependsOn as $depId) {
                $dep = $this->getTask($depId);
                if ($dep && ($dep->status === TaskStatus::Failed || $dep->status === TaskStatus::Cancelled)) {
                    $failed[] = $task;
                    break;
                }
            }
        }
        return $failed;
    }

    /**
     * Get results of completed dependency tasks for prompt injection.
     * @return array<string, array{title: string, result: string}>
     */
    public function getDependencyResults(array $dependsOn): array
    {
        if (empty($dependsOn)) {
            return [];
        }
        $results = [];
        foreach ($dependsOn as $depId) {
            $dep = $this->getTask($depId);
            if ($dep && $dep->status === TaskStatus::Completed) {
                $results[$depId] = [
                    'title' => $dep->title,
                    'result' => $dep->result ?? '',
                ];
            }
        }
        return $results;
    }

    /**
     * Check if all dependency task IDs are completed.
     */
    private function areDependenciesMet(array $dependsOn): bool
    {
        foreach ($dependsOn as $depId) {
            $dep = $this->getTask($depId);
            if (!$dep || $dep->status !== TaskStatus::Completed) {
                return false;
            }
        }
        return true;
    }

    /**
     * Reset a task back to pending so another agent can claim it.
     */
    public function requeueTask(string $taskId, int $lamportTs, string $reason): bool
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('
            UPDATE tasks SET
                status = :status, assigned_to = NULL, assigned_node = NULL,
                progress = NULL, error = :error, lamport_ts = :lamport_ts,
                claimed_at = NULL, updated_at = :updated_at
            WHERE id = :id AND status IN (\'claimed\', \'in_progress\', \'waiting_input\')
        ');

        $stmt->execute([
            ':id' => $taskId,
            ':status' => TaskStatus::Pending->value,
            ':error' => $reason,
            ':lamport_ts' => $lamportTs,
            ':updated_at' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get tasks assigned to a node that are still in active (non-terminal) states.
     * Used for startup cleanup of orphaned tasks.
     *
     * @return TaskModel[]
     */
    public function getOrphanedTasks(string $nodeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tasks WHERE assigned_node = :node_id AND status IN (\'claimed\', \'in_progress\', \'waiting_input\') ORDER BY created_at ASC'
        );
        $stmt->execute([':node_id' => $nodeId]);
        return array_map(fn(array $row) => TaskModel::fromArray($row), $stmt->fetchAll());
    }

    /**
     * Get all idle agents across all nodes.
     * @return AgentModel[]
     */
    public function getAllIdleAgents(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM agents WHERE status = 'idle' AND current_task_id IS NULL ORDER BY registered_at ASC"
        );
        return array_map(fn(array $row) => AgentModel::fromArray($row), $stmt->fetchAll());
    }

    /**
     * Get an idle planner agent (role='planner').
     */
    public function getIdlePlannerAgent(): ?AgentModel
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM agents WHERE role = 'planner' AND status = 'idle' AND current_task_id IS NULL LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? AgentModel::fromArray($row) : null;
    }

    /**
     * Get subtasks for a parent task.
     * @return TaskModel[]
     */
    public function getSubtasks(string $parentId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE parent_id = :parent_id ORDER BY priority DESC, created_at ASC');
        $stmt->execute([':parent_id' => $parentId]);
        return array_map(fn(array $row) => TaskModel::fromArray($row), $stmt->fetchAll());
    }

    /**
     * Update review status and feedback for a task.
     */
    public function updateReviewStatus(string $taskId, string $status, string $feedback = ''): bool
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('
            UPDATE tasks SET review_status = :review_status, review_feedback = :review_feedback, updated_at = :updated_at
            WHERE id = :id
        ');
        $stmt->execute([
            ':id' => $taskId,
            ':review_status' => $status,
            ':review_feedback' => $feedback,
            ':updated_at' => $now,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Archive a task (set archived = 1).
     */
    public function archiveTask(string $id): bool
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('UPDATE tasks SET archived = 1, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([':id' => $id, ':updated_at' => $now]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Archive all terminal tasks (completed, failed, cancelled).
     * @return int Number of tasks archived
     */
    public function archiveAllTerminal(): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare(
            "UPDATE tasks SET archived = 1, updated_at = :updated_at WHERE archived = 0 AND status IN ('completed', 'failed', 'cancelled')"
        );
        $stmt->execute([':updated_at' => $now]);
        return $stmt->rowCount();
    }

    public function setAutoMerge(string $taskId, bool $autoMerge): bool
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('UPDATE tasks SET auto_merge = :auto_merge, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([':id' => $taskId, ':auto_merge' => $autoMerge ? 1 : 0, ':updated_at' => $now]);
        return $stmt->rowCount() > 0;
    }

    public function updateGitBranch(string $taskId, string $branch): bool
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('UPDATE tasks SET git_branch = :branch, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([':id' => $taskId, ':branch' => $branch, ':updated_at' => $now]);
        return $stmt->rowCount() > 0;
    }

    public function incrementMergeAttempts(string $taskId): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->pdo->prepare('UPDATE tasks SET merge_attempts = merge_attempts + 1, updated_at = :updated_at WHERE id = :id')
            ->execute([':id' => $taskId, ':updated_at' => $now]);
        $stmt = $this->pdo->prepare('SELECT merge_attempts FROM tasks WHERE id = :id');
        $stmt->execute([':id' => $taskId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Atomically transition a task's state using compare-and-swap.
     * Only updates if the task's current status matches one of the expected statuses.
     * Prevents TOCTOU race conditions in coroutine-based concurrent access.
     *
     * @param TaskStatus[] $expectedStatuses Allowed current statuses for the transition
     */
    public function transitionTask(TaskModel $task, array $expectedStatuses): bool
    {
        $placeholders = [];
        $params = [
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
            ':parent_id' => $task->parentId,
            ':work_instructions' => $task->workInstructions,
            ':acceptance_criteria' => $task->acceptanceCriteria,
            ':review_status' => $task->reviewStatus,
            ':review_feedback' => $task->reviewFeedback,
            ':archived' => $task->archived ? 1 : 0,
            ':git_branch' => $task->gitBranch,
            ':merge_attempts' => $task->mergeAttempts,
            ':test_command' => $task->testCommand,
            ':depends_on' => json_encode($task->dependsOn),
            ':auto_merge' => $task->autoMerge ? 1 : 0,
            ':pr_url' => $task->prUrl,
        ];

        foreach ($expectedStatuses as $i => $s) {
            $key = ":expected_status_{$i}";
            $placeholders[] = $key;
            $params[$key] = $s->value;
        }

        $inClause = implode(', ', $placeholders);
        $stmt = $this->pdo->prepare("
            UPDATE tasks SET
                title = :title, description = :description, status = :status,
                priority = :priority, required_capabilities = :required_capabilities,
                assigned_to = :assigned_to, assigned_node = :assigned_node,
                result = :result, error = :error, progress = :progress,
                project_path = :project_path, context = :context,
                lamport_ts = :lamport_ts, claimed_at = :claimed_at,
                completed_at = :completed_at, updated_at = :updated_at,
                parent_id = :parent_id, work_instructions = :work_instructions,
                acceptance_criteria = :acceptance_criteria, review_status = :review_status,
                review_feedback = :review_feedback, archived = :archived,
                git_branch = :git_branch, merge_attempts = :merge_attempts,
                test_command = :test_command, depends_on = :depends_on,
                auto_merge = :auto_merge, pr_url = :pr_url
            WHERE id = :id AND status IN ({$inClause})
        ");

        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }

    // --- Agent operations ---

    public function insertAgent(AgentModel $agent): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO agents
                (id, node_id, name, tool, model, capabilities, tmux_session_id, project_path,
                 max_concurrent_tasks, status, current_task_id, last_heartbeat, lamport_ts, registered_at, role)
            VALUES
                (:id, :node_id, :name, :tool, :model, :capabilities, :tmux_session_id, :project_path,
                 :max_concurrent_tasks, :status, :current_task_id, :last_heartbeat, :lamport_ts, :registered_at, :role)
        ');

        return $stmt->execute([
            ':id' => $agent->id,
            ':node_id' => $agent->nodeId,
            ':name' => $agent->name,
            ':tool' => $agent->tool,
            ':model' => $agent->model,
            ':capabilities' => json_encode($agent->capabilities),
            ':tmux_session_id' => $agent->tmuxSessionId,
            ':project_path' => $agent->projectPath,
            ':max_concurrent_tasks' => $agent->maxConcurrentTasks,
            ':status' => $agent->status,
            ':current_task_id' => $agent->currentTaskId,
            ':last_heartbeat' => $agent->lastHeartbeat,
            ':lamport_ts' => $agent->lamportTs,
            ':registered_at' => $agent->registeredAt,
            ':role' => $agent->role,
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

    public function getAgentByName(string $name): ?AgentModel
    {
        $stmt = $this->pdo->prepare('SELECT * FROM agents WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
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

    /**
     * Delete all agents belonging to a specific node.
     * @return string[] IDs of deleted agents
     */
    public function deleteAgentsByNode(string $nodeId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM agents WHERE node_id = :node_id');
        $stmt->execute([':node_id' => $nodeId]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (!empty($ids)) {
            $this->pdo->prepare('DELETE FROM agents WHERE node_id = :node_id')
                ->execute([':node_id' => $nodeId]);
        }

        return $ids;
    }

    public function getAgentCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM agents')->fetchColumn();
    }

    public function getIdleAgentCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM agents WHERE status = 'idle'")->fetchColumn();
    }

    public function getMaxAgentLamportTs(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(lamport_ts), 0) FROM agents')->fetchColumn();
    }

    /** @return AgentModel[] */
    public function getAgentsSince(int $lamportTs): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM agents WHERE lamport_ts > :ts ORDER BY lamport_ts ASC');
        $stmt->execute([':ts' => $lamportTs]);
        return array_map(fn(array $row) => AgentModel::fromArray($row), $stmt->fetchAll());
    }

    /**
     * Archive all tasks (soft-delete) and return the non-archived ones.
     * Uses UPDATE instead of DELETE so anti-entropy sync can see archived state.
     * @return TaskModel[] Tasks that were not already archived
     */
    public function clearAllTasks(): array
    {
        // Get non-archived tasks for return value and gossip
        $stmt = $this->pdo->query("SELECT * FROM tasks WHERE archived = 0 ORDER BY priority DESC, created_at ASC");
        $tasks = array_map(fn(array $row) => TaskModel::fromArray($row), $stmt->fetchAll());

        // Soft-delete: mark all as archived + terminal status
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->pdo->prepare(
            "UPDATE tasks SET archived = 1, status = 'cancelled', updated_at = :now WHERE archived = 0"
        )->execute([':now' => $now]);

        return $tasks;
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

    /**
     * Get the underlying PDO instance (for shared table access, e.g. DHT storage).
     */
    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    // --- Swarm node operations ---

    public function upsertSwarmNode(SwarmNodeModel $node): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO swarm_nodes
                (node_id, role, http_host, http_port, p2p_port, capabilities,
                 agent_count, active_task_count, status, last_heartbeat,
                 lamport_ts, registered_at, uptime_seconds, memory_usage_bytes)
            VALUES
                (:node_id, :role, :http_host, :http_port, :p2p_port, :capabilities,
                 :agent_count, :active_task_count, :status, :last_heartbeat,
                 :lamport_ts, :registered_at, :uptime_seconds, :memory_usage_bytes)
        ');

        return $stmt->execute([
            ':node_id' => $node->nodeId,
            ':role' => $node->role,
            ':http_host' => $node->httpHost,
            ':http_port' => $node->httpPort,
            ':p2p_port' => $node->p2pPort,
            ':capabilities' => json_encode($node->capabilities),
            ':agent_count' => $node->agentCount,
            ':active_task_count' => $node->activeTaskCount,
            ':status' => $node->status,
            ':last_heartbeat' => $node->lastHeartbeat,
            ':lamport_ts' => $node->lamportTs,
            ':registered_at' => $node->registeredAt,
            ':uptime_seconds' => $node->uptimeSeconds,
            ':memory_usage_bytes' => $node->memoryUsageBytes,
        ]);
    }

    public function getSwarmNode(string $nodeId): ?SwarmNodeModel
    {
        $stmt = $this->pdo->prepare('SELECT * FROM swarm_nodes WHERE node_id = :node_id');
        $stmt->execute([':node_id' => $nodeId]);
        $row = $stmt->fetch();
        return $row ? SwarmNodeModel::fromArray($row) : null;
    }

    /** @return SwarmNodeModel[] */
    public function getAllSwarmNodes(): array
    {
        $rows = $this->pdo->query('SELECT * FROM swarm_nodes ORDER BY registered_at ASC')->fetchAll();
        return array_map(fn(array $row) => SwarmNodeModel::fromArray($row), $rows);
    }

    /** @return SwarmNodeModel[] */
    public function getSwarmNodesByStatus(string $status): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM swarm_nodes WHERE status = :status ORDER BY registered_at ASC');
        $stmt->execute([':status' => $status]);
        return array_map(fn(array $row) => SwarmNodeModel::fromArray($row), $stmt->fetchAll());
    }

    /** @return SwarmNodeModel[] */
    public function getSwarmNodesByRole(string $role): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM swarm_nodes WHERE role = :role ORDER BY registered_at ASC');
        $stmt->execute([':role' => $role]);
        return array_map(fn(array $row) => SwarmNodeModel::fromArray($row), $stmt->fetchAll());
    }

    public function deleteSwarmNode(string $nodeId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM swarm_nodes WHERE node_id = :node_id');
        $stmt->execute([':node_id' => $nodeId]);
        return $stmt->rowCount() > 0;
    }

    public function getSwarmNodeCount(?string $status = null): int
    {
        if ($status) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM swarm_nodes WHERE status = :status');
            $stmt->execute([':status' => $status]);
            return (int) $stmt->fetchColumn();
        }
        return (int) $this->pdo->query('SELECT COUNT(*) FROM swarm_nodes')->fetchColumn();
    }

    // --- Offer operations ---

    public function insertOffer(OfferModel $offer): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO offers
                (id, from_node_id, to_node_id, amount, currency, conditions, status,
                 lamport_ts, expires_at, created_at, updated_at, task_id, response_reason)
            VALUES
                (:id, :from_node_id, :to_node_id, :amount, :currency, :conditions, :status,
                 :lamport_ts, :expires_at, :created_at, :updated_at, :task_id, :response_reason)
        ');

        return $stmt->execute([
            ':id' => $offer->id,
            ':from_node_id' => $offer->fromNodeId,
            ':to_node_id' => $offer->toNodeId,
            ':amount' => $offer->amount,
            ':currency' => $offer->currency,
            ':conditions' => $offer->conditions,
            ':status' => $offer->status->value,
            ':lamport_ts' => $offer->lamportTs,
            ':expires_at' => $offer->expiresAt,
            ':created_at' => $offer->createdAt,
            ':updated_at' => $offer->updatedAt,
            ':task_id' => $offer->taskId,
            ':response_reason' => $offer->responseReason,
        ]);
    }

    public function updateOffer(OfferModel $offer): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE offers SET
                status = :status, lamport_ts = :lamport_ts, updated_at = :updated_at,
                response_reason = :response_reason
            WHERE id = :id
        ');

        return $stmt->execute([
            ':id' => $offer->id,
            ':status' => $offer->status->value,
            ':lamport_ts' => $offer->lamportTs,
            ':updated_at' => $offer->updatedAt,
            ':response_reason' => $offer->responseReason,
        ]);
    }

    public function getOffer(string $id): ?OfferModel
    {
        $stmt = $this->pdo->prepare('SELECT * FROM offers WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? OfferModel::fromArray($row) : null;
    }

    /** @return OfferModel[] */
    public function getOffersSince(int $lamportTs): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM offers WHERE lamport_ts > :ts ORDER BY lamport_ts ASC');
        $stmt->execute([':ts' => $lamportTs]);
        return array_map(fn(array $row) => OfferModel::fromArray($row), $stmt->fetchAll());
    }

    /** @return OfferModel[] */
    public function getOffersByNode(string $nodeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM offers WHERE from_node_id = :node_id OR to_node_id = :node_id2 ORDER BY created_at DESC'
        );
        $stmt->execute([':node_id' => $nodeId, ':node_id2' => $nodeId]);
        return array_map(fn(array $row) => OfferModel::fromArray($row), $stmt->fetchAll());
    }

    // --- Payment operations ---

    public function insertPayment(PaymentModel $payment): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO payments
                (id, offer_id, from_node_id, to_node_id, amount, currency, status,
                 tx_hash, lamport_ts, created_at, updated_at, failure_reason)
            VALUES
                (:id, :offer_id, :from_node_id, :to_node_id, :amount, :currency, :status,
                 :tx_hash, :lamport_ts, :created_at, :updated_at, :failure_reason)
        ');

        return $stmt->execute([
            ':id' => $payment->id,
            ':offer_id' => $payment->offerId,
            ':from_node_id' => $payment->fromNodeId,
            ':to_node_id' => $payment->toNodeId,
            ':amount' => $payment->amount,
            ':currency' => $payment->currency,
            ':status' => $payment->status->value,
            ':tx_hash' => $payment->txHash,
            ':lamport_ts' => $payment->lamportTs,
            ':created_at' => $payment->createdAt,
            ':updated_at' => $payment->updatedAt,
            ':failure_reason' => $payment->failureReason,
        ]);
    }

    public function updatePayment(PaymentModel $payment): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE payments SET
                status = :status, lamport_ts = :lamport_ts, updated_at = :updated_at,
                failure_reason = :failure_reason
            WHERE id = :id
        ');

        return $stmt->execute([
            ':id' => $payment->id,
            ':status' => $payment->status->value,
            ':lamport_ts' => $payment->lamportTs,
            ':updated_at' => $payment->updatedAt,
            ':failure_reason' => $payment->failureReason,
        ]);
    }

    public function getPayment(string $id): ?PaymentModel
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? PaymentModel::fromArray($row) : null;
    }

    public function getPaymentByOffer(string $offerId): ?PaymentModel
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE offer_id = :offer_id ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([':offer_id' => $offerId]);
        $row = $stmt->fetch();
        return $row ? PaymentModel::fromArray($row) : null;
    }

    /** @return PaymentModel[] */
    public function getPaymentsSince(int $lamportTs): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE lamport_ts > :ts ORDER BY lamport_ts ASC');
        $stmt->execute([':ts' => $lamportTs]);
        return array_map(fn(array $row) => PaymentModel::fromArray($row), $stmt->fetchAll());
    }

    // --- Message board operations ---

    public function insertMessage(MessageModel $msg): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO board_messages
                (id, author_id, author_name, category, title, content, priority,
                 tags, status, claimed_by, parent_id, task_id, lamport_ts, created_at, updated_at)
            VALUES
                (:id, :author_id, :author_name, :category, :title, :content, :priority,
                 :tags, :status, :claimed_by, :parent_id, :task_id, :lamport_ts, :created_at, :updated_at)
        ');

        return $stmt->execute([
            ':id' => $msg->id,
            ':author_id' => $msg->authorId,
            ':author_name' => $msg->authorName,
            ':category' => $msg->category,
            ':title' => $msg->title,
            ':content' => $msg->content,
            ':priority' => $msg->priority,
            ':tags' => json_encode($msg->tags),
            ':status' => $msg->status,
            ':claimed_by' => $msg->claimedBy,
            ':parent_id' => $msg->parentId,
            ':task_id' => $msg->taskId,
            ':lamport_ts' => $msg->lamportTs,
            ':created_at' => $msg->createdAt,
            ':updated_at' => $msg->updatedAt,
        ]);
    }

    public function updateMessage(MessageModel $msg): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE board_messages SET
                status = :status, claimed_by = :claimed_by, task_id = :task_id,
                lamport_ts = :lamport_ts, updated_at = :updated_at
            WHERE id = :id
        ');

        return $stmt->execute([
            ':id' => $msg->id,
            ':status' => $msg->status,
            ':claimed_by' => $msg->claimedBy,
            ':task_id' => $msg->taskId,
            ':lamport_ts' => $msg->lamportTs,
            ':updated_at' => $msg->updatedAt,
        ]);
    }

    public function getMessage(string $id): ?MessageModel
    {
        $stmt = $this->pdo->prepare('SELECT * FROM board_messages WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? MessageModel::fromArray($row) : null;
    }

    public function hasMessage(string $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM board_messages WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return MessageModel[]
     */
    public function getMessages(?string $category = null, ?string $status = null): array
    {
        $where = [];
        $params = [];
        if ($category !== null) {
            $where[] = 'category = :category';
            $params[':category'] = $category;
        }
        if ($status !== null) {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        }

        $sql = 'SELECT * FROM board_messages';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY priority DESC, created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(fn(array $row) => MessageModel::fromArray($row), $stmt->fetchAll());
    }

    /**
     * Get replies to a message.
     * @return MessageModel[]
     */
    public function getMessageReplies(string $parentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM board_messages WHERE parent_id = :parent_id ORDER BY created_at ASC'
        );
        $stmt->execute([':parent_id' => $parentId]);
        return array_map(fn(array $row) => MessageModel::fromArray($row), $stmt->fetchAll());
    }

    /** @return MessageModel[] */
    public function getMessagesSince(int $lamportTs): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM board_messages WHERE lamport_ts > :ts ORDER BY lamport_ts ASC'
        );
        $stmt->execute([':ts' => $lamportTs]);
        return array_map(fn(array $row) => MessageModel::fromArray($row), $stmt->fetchAll());
    }

    public function getMaxMessageLamportTs(): int
    {
        return (int) $this->pdo->query(
            'SELECT COALESCE(MAX(lamport_ts), 0) FROM board_messages'
        )->fetchColumn();
    }

    public function deleteMessage(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM board_messages WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function getMessageCount(?string $category = null): int
    {
        if ($category) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM board_messages WHERE category = :category');
            $stmt->execute([':category' => $category]);
            return (int) $stmt->fetchColumn();
        }
        return (int) $this->pdo->query('SELECT COUNT(*) FROM board_messages')->fetchColumn();
    }

    // --- Plugin operations ---

    /**
     * Enable a plugin for a specific agent.
     */
    public function enableAgentPlugin(string $agentId, string $pluginName): void
    {
        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO agent_plugins
                (agent_id, plugin_name, enabled, config, enabled_at)
            VALUES
                (:agent_id, :plugin_name, 1, :config, :enabled_at)
        ');
        $stmt->execute([
            ':agent_id' => $agentId,
            ':plugin_name' => $pluginName,
            ':config' => '{}',
            ':enabled_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * Disable a plugin for a specific agent.
     */
    public function disableAgentPlugin(string $agentId, string $pluginName): void
    {
        $stmt = $this->pdo->prepare('
            DELETE FROM agent_plugins
            WHERE agent_id = :agent_id AND plugin_name = :plugin_name
        ');
        $stmt->execute([
            ':agent_id' => $agentId,
            ':plugin_name' => $pluginName,
        ]);
    }

    /**
     * Get all enabled plugin names for an agent.
     *
     * @return string[]
     */
    public function getAgentPlugins(string $agentId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT plugin_name FROM agent_plugins
            WHERE agent_id = :agent_id AND enabled = 1
        ');
        $stmt->execute([':agent_id' => $agentId]);
        return array_column($stmt->fetchAll(), 'plugin_name');
    }

    /**
     * Update agent capabilities (from plugin aggregation).
     */
    public function updateAgentCapabilities(string $agentId, array $capabilities): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE agents
            SET capabilities = :capabilities
            WHERE id = :agent_id
        ');
        $stmt->execute([
            ':agent_id' => $agentId,
            ':capabilities' => json_encode($capabilities),
        ]);
    }
}
