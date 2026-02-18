<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Orchestrator;

use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use VoidLux\P2P\Discovery\DiscoveryManager;
use VoidLux\Swarm\Agent\AgentBridge;
use VoidLux\Swarm\Agent\AgentMonitor;
use VoidLux\Swarm\Agent\AgentRegistry;
use VoidLux\Swarm\Ai\TaskPlanner;
use VoidLux\Swarm\Galactic\GalacticMarketplace;
use VoidLux\Swarm\Git\GitWorkspace;
use VoidLux\Swarm\Mcp\McpHandler;
use VoidLux\Swarm\Model\TaskStatus;
use VoidLux\Swarm\Orchestrator\TaskDispatcher;
use VoidLux\Swarm\Storage\DhtEngine;
use VoidLux\Swarm\Storage\SwarmDatabase;
use VoidLux\Swarm\SwarmWebUI;

/**
 * HTTP API controller for the emperor dashboard.
 */
class EmperorController
{
    private ?AgentMonitor $agentMonitor = null;
    private ?McpHandler $mcpHandler = null;
    private ?TaskDispatcher $taskDispatcher = null;
    private ?TaskPlanner $taskPlanner = null;
    private ?DhtEngine $dhtEngine = null;
    private ?DiscoveryManager $discoveryManager = null;
    private ?GalacticMarketplace $marketplace = null;

    /** @var callable|null fn(): void — triggers server shutdown */
    private $shutdownCallback = null;

    /** @var callable|null fn(string $agentId, string $status): void */
    private $onAgentStatusChange = null;

    public function __construct(
        private readonly SwarmDatabase $db,
        private readonly TaskQueue $taskQueue,
        private readonly AgentRegistry $agentRegistry,
        private readonly AgentBridge $bridge,
        private readonly string $nodeId,
        private readonly float $startTime,
    ) {}

    public function setAgentMonitor(AgentMonitor $monitor): void
    {
        $this->agentMonitor = $monitor;
    }

    public function setTaskDispatcher(TaskDispatcher $dispatcher): void
    {
        $this->taskDispatcher = $dispatcher;
    }

    public function setTaskPlanner(TaskPlanner $planner): void
    {
        $this->taskPlanner = $planner;
    }

    public function setDhtEngine(DhtEngine $engine): void
    {
        $this->dhtEngine = $engine;
    }

    public function setDiscoveryManager(DiscoveryManager $dm): void
    {
        $this->discoveryManager = $dm;
    }

    public function setMarketplace(GalacticMarketplace $marketplace): void
    {
        $this->marketplace = $marketplace;
    }

    public function onShutdown(callable $callback): void
    {
        $this->shutdownCallback = $callback;
    }

    public function onAgentStatusChange(callable $callback): void
    {
        $this->onAgentStatusChange = $callback;
    }

    public function handle(Request $request, Response $response): void
    {
        $path = $request->server['request_uri'] ?? '/';
        $method = $request->server['request_method'] ?? 'GET';

        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Content-Type');

        if ($method === 'OPTIONS') {
            $response->status(204);
            $response->end('');
            return;
        }

        // Route matching
        switch (true) {
            case $path === '/' && $method === 'GET':
                $response->header('Content-Type', 'text/html; charset=utf-8');
                $response->end(SwarmWebUI::render($this->nodeId));
                break;

            case $path === '/mcp' && $method === 'POST':
                $this->getMcpHandler()->handle($request, $response);
                break;

            case $path === '/health' && $method === 'GET':
                $this->json($response, [
                    'status' => 'ok',
                    'uptime' => round(microtime(true) - $this->startTime, 1),
                    'node_id' => $this->nodeId,
                ]);
                break;

            case $path === '/api/swarm/status' && $method === 'GET':
                $this->handleSwarmStatus($response);
                break;

            case $path === '/api/swarm/tasks' && $method === 'GET':
                $this->handleListTasks($request, $response);
                break;

            case $path === '/api/swarm/tasks' && $method === 'POST':
                $this->handleCreateTask($request, $response);
                break;

            case $path === '/api/swarm/tasks/clear' && $method === 'POST':
                $this->handleClearTasks($response);
                break;

            case $path === '/api/swarm/tasks/archive-all' && $method === 'POST':
                $this->handleArchiveAll($response);
                break;

            case preg_match('#^/api/swarm/tasks/([^/]+)/archive$#', $path, $m) === 1 && $method === 'POST':
                $this->handleArchiveTask($m[1], $response);
                break;

            case preg_match('#^/api/swarm/tasks/([^/]+)/cancel$#', $path, $m) === 1 && $method === 'POST':
                $this->handleCancelTask($m[1], $response);
                break;

            case preg_match('#^/api/swarm/tasks/([^/]+)/review$#', $path, $m) === 1 && $method === 'POST':
                $this->handleReviewTask($m[1], $request, $response);
                break;

            case preg_match('#^/api/swarm/tasks/([^/]+)/subtasks$#', $path, $m) === 1 && $method === 'GET':
                $this->handleGetSubtasks($m[1], $response);
                break;

            case preg_match('#^/api/swarm/tasks/([^/]+)$#', $path, $m) === 1 && $method === 'GET':
                $this->handleGetTask($m[1], $response);
                break;

            case $path === '/api/swarm/ollama/models' && $method === 'GET':
                $this->handleOllamaModels($response);
                break;

            case $path === '/api/swarm/agents' && $method === 'GET':
                $this->handleListAgents($response);
                break;

            case $path === '/api/swarm/agents' && $method === 'POST':
                $this->handleRegisterAgent($request, $response);
                break;

            case $path === '/api/swarm/agents/bulk' && $method === 'POST':
                $this->handleBulkRegisterAgents($request, $response);
                break;

            case $path === '/api/swarm/agents/wellness' && $method === 'POST':
                $this->handleWellnessCheck($response);
                break;

            case $path === '/api/swarm/agents/kill-all' && $method === 'POST':
                $this->handleKillPopulation($response);
                break;

            case $path === '/api/swarm/regicide' && $method === 'POST':
                $this->handleRegicide($response);
                break;

            case preg_match('#^/api/swarm/agents/([^/]+)/send$#', $path, $m) === 1 && $method === 'POST':
                $this->handleSendToAgent($m[1], $request, $response);
                break;

            case preg_match('#^/api/swarm/agents/([^/]+)/output$#', $path, $m) === 1 && $method === 'GET':
                $this->handleAgentOutput($m[1], $request, $response);
                break;

            case preg_match('#^/api/swarm/agents/([^/]+)$#', $path, $m) === 1 && $method === 'DELETE':
                $this->handleDeregisterAgent($m[1], $response);
                break;

            // --- DHT (decentralized storage) API ---
            case $path === '/api/swarm/dht' && $method === 'POST':
                $this->handleDhtPut($request, $response);
                break;

            case preg_match('#^/api/swarm/dht/(.+)$#', $path, $m) === 1 && $method === 'GET':
                $this->handleDhtGet($m[1], $response);
                break;

            case preg_match('#^/api/swarm/dht/(.+)$#', $path, $m) === 1 && $method === 'DELETE':
                $this->handleDhtDelete($m[1], $response);
                break;

            case $path === '/api/swarm/dht-stats' && $method === 'GET':
                $this->handleDhtStats($response);
                break;

            case $path === '/api/swarm/discovery' && $method === 'GET':
                $this->handleDiscoveryStats($response);
                break;

            // --- Swarm node registry API ---
            case $path === '/api/swarm/nodes' && $method === 'GET':
                $this->handleListNodes($response);
                break;

            case $path === '/api/swarm/nodes/status' && $method === 'GET':
                $this->handleSwarmNodeStatus($response);
                break;

            // --- Galactic marketplace API ---
            case $path === '/api/swarm/offerings' && $method === 'GET':
                $this->handleListOfferings($response);
                break;

            case $path === '/api/swarm/offerings' && $method === 'POST':
                $this->handleCreateOffering($request, $response);
                break;

            case preg_match('#^/api/swarm/offerings/([^/]+)$#', $path, $m) === 1 && $method === 'DELETE':
                $this->handleWithdrawOffering($m[1], $response);
                break;

            case $path === '/api/swarm/tributes' && $method === 'POST':
                $this->handleRequestTribute($request, $response);
                break;

            case $path === '/api/swarm/tributes' && $method === 'GET':
                $this->handleListTributes($response);
                break;

            case $path === '/api/swarm/wallet' && $method === 'GET':
                $this->handleWallet($response);
                break;

            default:
                $response->status(404);
                $this->json($response, ['error' => 'Not found']);
                break;
        }
    }

    private function handleSwarmStatus(Response $response): void
    {
        $this->json($response, [
            'node_id' => $this->nodeId,
            'uptime' => round(microtime(true) - $this->startTime, 1),
            'tasks' => [
                'total' => $this->db->getTaskCount(),
                'pending' => $this->db->getTaskCount('pending'),
                'planning' => $this->db->getTaskCount('planning'),
                'claimed' => $this->db->getTaskCount('claimed'),
                'in_progress' => $this->db->getTaskCount('in_progress'),
                'pending_review' => $this->db->getTaskCount('pending_review'),
                'merging' => $this->db->getTaskCount('merging'),
                'waiting_input' => $this->db->getTaskCount('waiting_input'),
                'completed' => $this->db->getTaskCount('completed'),
                'failed' => $this->db->getTaskCount('failed'),
            ],
            'agents' => [
                'total' => $this->db->getAgentCount(),
            ],
            'discovery' => $this->discoveryManager?->stats() ?? [],
        ]);
    }

    private function handleListTasks(Request $request, Response $response): void
    {
        $status = $request->get['status'] ?? null;
        $tasks = $this->taskQueue->getTasks($status);
        $this->json($response, array_map(fn($t) => $t->toArray(), $tasks));
    }

    private function handleCreateTask(Request $request, Response $response): void
    {
        $body = json_decode($request->getContent(), true);
        if (!$body || empty($body['title'])) {
            $response->status(400);
            $this->json($response, ['error' => 'title is required']);
            return;
        }

        // If planner is available, create as a planning parent task and decompose
        if ($this->taskPlanner !== null) {
            $task = $this->taskQueue->createTask(
                title: $body['title'],
                description: $body['description'] ?? '',
                priority: (int) ($body['priority'] ?? 0),
                requiredCapabilities: $body['required_capabilities'] ?? [],
                projectPath: $body['project_path'] ?? '',
                context: $body['context'] ?? '',
                createdBy: $body['created_by'] ?? $this->nodeId,
                status: TaskStatus::Planning,
                testCommand: $body['test_command'] ?? '',
            );

            $response->status(201);
            $this->json($response, $task->toArray());

            // Decompose in background coroutine
            $taskId = $task->id;
            $planner = $this->taskPlanner;
            $taskQueue = $this->taskQueue;
            $db = $this->db;
            $dispatcher = $this->taskDispatcher;

            Coroutine::create(function () use ($taskId, $planner, $taskQueue, $db, $dispatcher) {
                $parentTask = $db->getTask($taskId);
                if (!$parentTask) {
                    return;
                }

                $subtaskDefs = $planner->decompose($parentTask);

                if (empty($subtaskDefs)) {
                    // Decomposition failed — convert to regular pending task
                    $updated = $parentTask->withStatus(TaskStatus::Pending, $parentTask->lamportTs);
                    $db->updateTask($updated);
                    $dispatcher?->triggerDispatch();
                    return;
                }

                foreach ($subtaskDefs as $def) {
                    $taskQueue->createTask(
                        title: $def['title'],
                        description: $def['description'],
                        priority: $def['priority'],
                        requiredCapabilities: $def['requiredCapabilities'],
                        projectPath: $parentTask->projectPath,
                        context: $parentTask->context,
                        createdBy: $parentTask->createdBy,
                        parentId: $parentTask->id,
                        workInstructions: $def['work_instructions'],
                        acceptanceCriteria: $def['acceptance_criteria'],
                    );
                }

                // Mark parent as in_progress (subtasks being worked on — NOT pending, to avoid dispatch)
                $updated = $parentTask->withStatus(TaskStatus::InProgress, $parentTask->lamportTs);
                $db->updateTask($updated);

                $dispatcher?->triggerDispatch();
            });

            return;
        }

        // No planner — create task directly
        $task = $this->taskQueue->createTask(
            title: $body['title'],
            description: $body['description'] ?? '',
            priority: (int) ($body['priority'] ?? 0),
            requiredCapabilities: $body['required_capabilities'] ?? [],
            projectPath: $body['project_path'] ?? '',
            context: $body['context'] ?? '',
            createdBy: $body['created_by'] ?? $this->nodeId,
            testCommand: $body['test_command'] ?? '',
        );

        $this->taskDispatcher?->triggerDispatch();

        $response->status(201);
        $this->json($response, $task->toArray());
    }

    private function handleGetTask(string $taskId, Response $response): void
    {
        $task = $this->taskQueue->getTask($taskId);
        if (!$task) {
            $response->status(404);
            $this->json($response, ['error' => 'Task not found']);
            return;
        }
        $this->json($response, $task->toArray());
    }

    private function handleCancelTask(string $taskId, Response $response): void
    {
        $cancelled = $this->taskQueue->cancel($taskId);
        if (!$cancelled) {
            $response->status(409);
            $this->json($response, ['error' => 'Cannot cancel task (not found or already terminal)']);
            return;
        }
        $this->json($response, ['status' => 'cancelled', 'task_id' => $taskId]);
    }

    private function handleArchiveTask(string $taskId, Response $response): void
    {
        $task = $this->taskQueue->archiveTask($taskId);
        if (!$task) {
            $response->status(409);
            $this->json($response, ['error' => 'Cannot archive task (not found or not terminal)']);
            return;
        }
        $this->json($response, $task->toArray());
    }

    private function handleArchiveAll(Response $response): void
    {
        $archivedIds = $this->taskQueue->archiveAllTerminal();
        $this->json($response, [
            'archived' => count($archivedIds),
            'task_ids' => $archivedIds,
        ]);
    }

    private function handleReviewTask(string $taskId, Request $request, Response $response): void
    {
        $task = $this->taskQueue->getTask($taskId);
        if (!$task) {
            $response->status(404);
            $this->json($response, ['error' => 'Task not found']);
            return;
        }

        $body = json_decode($request->getContent(), true);
        $accepted = (bool) ($body['accepted'] ?? false);
        $feedback = (string) ($body['feedback'] ?? '');

        if ($accepted) {
            $this->db->updateReviewStatus($taskId, 'accepted', $feedback);
            // Complete the task
            $ts = $task->lamportTs;
            $updated = $task->withStatus(TaskStatus::Completed, $ts);
            $this->db->updateTask($updated);
            $this->json($response, ['status' => 'accepted', 'task_id' => $taskId]);
        } else {
            $this->db->updateReviewStatus($taskId, 'rejected', $feedback);
            // Requeue with feedback
            $this->taskQueue->requeue($taskId, "Review rejected: {$feedback}");
            $this->taskDispatcher?->triggerDispatch();
            $this->json($response, ['status' => 'rejected', 'task_id' => $taskId, 'feedback' => $feedback]);
        }
    }

    private function handleGetSubtasks(string $parentId, Response $response): void
    {
        $subtasks = $this->db->getSubtasks($parentId);
        $this->json($response, array_map(fn($t) => $t->toArray(), $subtasks));
    }

    private function handleClearTasks(Response $response): void
    {
        $tasks = $this->db->clearAllTasks();
        $count = count($tasks);

        // Write to log file in data directory
        if ($count > 0) {
            $dataDir = getcwd() . '/data';
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0755, true);
            }
            $logFile = $dataDir . '/tasks-' . date('Y-m-d-His') . '.txt';

            $lines = [];
            $lines[] = "# Task Archive - " . date('Y-m-d H:i:s');
            $lines[] = "# Total: $count tasks";
            $lines[] = str_repeat('=', 60);

            foreach ($tasks as $task) {
                $lines[] = '';
                $lines[] = "ID:          {$task->id}";
                $lines[] = "Title:       {$task->title}";
                $lines[] = "Status:      {$task->status->value}";
                $lines[] = "Created:     {$task->createdAt}";
                if ($task->assignedTo) {
                    $lines[] = "Assigned To: {$task->assignedTo}";
                }
                if ($task->description) {
                    $lines[] = "Description: {$task->description}";
                }
                if ($task->result) {
                    $lines[] = "Result:      " . substr($task->result, 0, 200);
                }
                if ($task->error) {
                    $lines[] = "Error:       {$task->error}";
                }
                $lines[] = str_repeat('-', 60);
            }

            file_put_contents($logFile, implode("\n", $lines) . "\n");
        }

        $this->json($response, [
            'cleared' => $count,
            'log_file' => isset($logFile) ? basename($logFile) : null,
        ]);
    }

    private function handleListAgents(Response $response): void
    {
        $agents = $this->agentRegistry->getAllAgents();
        $this->json($response, array_map(fn($a) => $a->toArray(), $agents));
    }

    private function handleRegisterAgent(Request $request, Response $response): void
    {
        $body = json_decode($request->getContent(), true);
        if (!$body || empty($body['name'])) {
            $response->status(400);
            $this->json($response, ['error' => 'name is required']);
            return;
        }

        $tmuxSession = $body['tmux_session_id'] ?? null;
        $projectPath = $body['project_path'] ?? '';

        // Auto-create tmux session if not specified
        if (!$tmuxSession && $projectPath) {
            $tool = $body['tool'] ?? 'claude';
            $sessionName = 'vl-agent-' . substr(bin2hex(random_bytes(4)), 0, 8);
            $this->bridge->ensureSession($sessionName, $projectPath, $tool);
            $tmuxSession = $sessionName;
        }

        $agent = $this->agentRegistry->register(
            name: $body['name'],
            tool: $body['tool'] ?? 'claude',
            model: $body['model'] ?? '',
            capabilities: $body['capabilities'] ?? [],
            tmuxSessionId: $tmuxSession,
            projectPath: $projectPath,
            maxConcurrentTasks: (int) ($body['max_concurrent_tasks'] ?? 1),
        );

        $this->taskDispatcher?->triggerDispatch();

        $response->status(201);
        $this->json($response, $agent->toArray());
    }

    private function handleWellnessCheck(Response $response): void
    {
        if (!$this->agentMonitor) {
            $response->status(503);
            $this->json($response, ['error' => 'Agent monitor not available']);
            return;
        }

        $report = $this->agentMonitor->wellnessCheck();
        $this->json($response, [
            'alive' => count($report['alive']),
            'pruned' => count($report['pruned']),
            'agents' => $report['alive'],
            'removed' => $report['pruned'],
        ]);
    }

    private function handleKillPopulation(Response $response): void
    {
        $agents = $this->agentRegistry->getLocalAgents();
        $killed = [];
        $deregisteredIds = [];
        $git = new GitWorkspace();
        $baseDir = getcwd() . '/workbench/.base';

        foreach ($agents as $agent) {
            $sessionKilled = $this->bridge->killSession($agent);

            // Clean up worktree if it exists
            if ($agent->projectPath && $git->isWorktree($agent->projectPath) && is_dir($baseDir . '/.git')) {
                $git->removeWorktree($baseDir, $agent->projectPath);
            }

            $this->agentRegistry->deregister($agent->id);
            $deregisteredIds[] = $agent->id;
            $killed[] = [
                'id' => $agent->id,
                'name' => $agent->name,
                'session' => $agent->tmuxSessionId,
                'session_killed' => $sessionKilled,
            ];
        }

        // Bulk-purge any ghost records for this node (gossip tombstones prevent resurrection)
        $ghostIds = $this->db->deleteAgentsByNode($this->nodeId);
        foreach ($ghostIds as $ghostId) {
            if (!in_array($ghostId, $deregisteredIds, true)) {
                $this->agentRegistry->deregister($ghostId);
            }
        }

        $this->json($response, [
            'killed' => count($killed),
            'agents' => $killed,
        ]);
    }

    private function handleRegicide(Response $response): void
    {
        $this->json($response, [
            'status' => 'dying',
            'node_id' => $this->nodeId,
            'message' => 'Emperor process shutting down, workers should elect a new leader',
        ]);

        // Schedule shutdown after response is sent
        if ($this->shutdownCallback) {
            \Swoole\Timer::after(500, $this->shutdownCallback);
        }
    }

    /**
     * Bulk register agents. Supports two formats:
     *
     * Per-agent config (for external orchestration):
     *   {"agents": [
     *     {"project_path": "/path/to/project", "tool": "claude", "name": "my-agent", ...},
     *     {"project_path": "/other/project", "tool": "opencode", "capabilities": ["php"]},
     *   ]}
     *
     * Uniform batch (backward-compatible):
     *   {"count": 5, "tool": "claude", "project_path": "/path", ...}
     */
    private function handleBulkRegisterAgents(Request $request, Response $response): void
    {
        $body = json_decode($request->getContent(), true);
        if (!$body) {
            $response->status(400);
            $this->json($response, ['error' => 'Invalid JSON body']);
            return;
        }

        $nodeShort = substr($this->nodeId, 0, 6);

        // Per-agent config mode
        if (isset($body['agents']) && is_array($body['agents'])) {
            $agents = [];
            foreach ($body['agents'] as $i => $spec) {
                $agents[] = $this->registerOneAgent($spec, $nodeShort, $i + 1);
            }
            $response->status(201);
            $this->json($response, $agents);
            return;
        }

        // Uniform batch mode (backward-compatible)
        $count = max(1, min(50, (int) ($body['count'] ?? 1)));
        $agents = [];
        for ($i = 0; $i < $count; $i++) {
            $agents[] = $this->registerOneAgent($body, $nodeShort, $i + 1);
        }

        $response->status(201);
        $this->json($response, $agents);
    }

    /**
     * Register a single agent from a spec array.
     * @param array{tool?: string, project_path?: string, name?: string, name_prefix?: string,
     *              capabilities?: string[], model?: string, env?: array<string,string>} $spec
     */
    private function registerOneAgent(array $spec, string $nodeShort, int $index): array
    {
        $tool = $spec['tool'] ?? 'claude';
        $projectPath = $spec['project_path'] ?? '';
        $capabilities = $spec['capabilities'] ?? [];
        $model = $spec['model'] ?? '';
        $env = $spec['env'] ?? [];
        $namePrefix = $spec['name_prefix'] ?? 'agent';

        // Explicit name or auto-generated
        $agentName = $spec['name'] ?? ($namePrefix . '-' . $nodeShort . '-' . $index);
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $sessionName = 'vl-' . $namePrefix . '-' . $suffix;

        // If project_path is a git URL, use shared base repo + worktrees
        $git = new GitWorkspace();
        if ($git->isGitUrl($projectPath)) {
            $baseDir = getcwd() . '/workbench/.base';
            $worktreePath = getcwd() . '/workbench/' . $agentName;
            $ensured = $git->ensureBaseRepo($projectPath, $baseDir);
            if ($ensured) {
                $added = $git->addWorktree($baseDir, $worktreePath, 'worktree/' . $agentName);
                if ($added) {
                    $projectPath = $worktreePath;
                }
            }
        }

        if ($projectPath) {
            $this->bridge->ensureSession($sessionName, $projectPath, $tool, [
                'model' => $model,
                'env' => $env,
            ]);
        }

        $agent = $this->agentRegistry->register(
            name: $agentName,
            tool: $tool,
            model: $model,
            capabilities: $capabilities,
            tmuxSessionId: $projectPath ? $sessionName : null,
            projectPath: $projectPath,
        );

        return $agent->toArray();
    }

    private function handleDeregisterAgent(string $agentId, Response $response): void
    {
        $deleted = $this->agentRegistry->deregister($agentId);
        if (!$deleted) {
            $response->status(404);
            $this->json($response, ['error' => 'Agent not found']);
            return;
        }
        $this->json($response, ['status' => 'deregistered', 'agent_id' => $agentId]);
    }

    private function handleSendToAgent(string $agentId, Request $request, Response $response): void
    {
        $agent = $this->agentRegistry->getAgent($agentId);
        if (!$agent) {
            $response->status(404);
            $this->json($response, ['error' => 'Agent not found']);
            return;
        }

        $body = json_decode($request->getContent(), true);
        $text = $body['text'] ?? '';
        if (!$text) {
            $response->status(400);
            $this->json($response, ['error' => 'text is required']);
            return;
        }

        $sent = $this->bridge->sendText($agent, $text);
        $this->json($response, ['sent' => $sent]);
    }

    private function handleAgentOutput(string $agentId, Request $request, Response $response): void
    {
        $agent = $this->agentRegistry->getAgent($agentId);
        if (!$agent) {
            $response->status(404);
            $this->json($response, ['error' => 'Agent not found']);
            return;
        }

        $lines = (int) ($request->get['lines'] ?? 50);
        $output = $this->bridge->captureOutput($agent, $lines);
        $status = $this->bridge->detectStatus($agent);

        $this->json($response, [
            'agent_id' => $agentId,
            'status' => $status->value,
            'output' => $output,
        ]);
    }

    private function handleOllamaModels(Response $response): void
    {
        $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', 11434);
        $client->set(['timeout' => 3]);
        $client->get('/api/tags');

        if ($client->statusCode !== 200) {
            $this->json($response, ['models' => [], 'error' => 'Ollama not available']);
            $client->close();
            return;
        }

        $data = json_decode($client->body, true);
        $client->close();

        $models = [];
        foreach ($data['models'] ?? [] as $m) {
            $models[] = $m['name'] ?? $m['model'] ?? '';
        }
        sort($models);

        $this->json($response, ['models' => $models]);
    }

    private function getMcpHandler(): McpHandler
    {
        if ($this->mcpHandler === null) {
            $this->mcpHandler = new McpHandler($this->taskQueue, $this->db);
            if ($this->taskDispatcher !== null) {
                $this->mcpHandler->setTaskDispatcher($this->taskDispatcher);
            }
            if ($this->onAgentStatusChange !== null) {
                $this->mcpHandler->onAgentStatusChange($this->onAgentStatusChange);
            }
        }
        return $this->mcpHandler;
    }

    // --- DHT API handlers ---

    private function handleDhtPut(Request $request, Response $response): void
    {
        if (!$this->dhtEngine) {
            $response->status(503);
            $this->json($response, ['error' => 'DHT not initialized']);
            return;
        }

        $body = json_decode($request->rawContent(), true) ?? [];
        $value = $body['value'] ?? null;
        $key = $body['key'] ?? null;
        $ttl = (int) ($body['ttl'] ?? 0);

        if ($value === null) {
            $response->status(400);
            $this->json($response, ['error' => 'value is required']);
            return;
        }

        if ($key !== null) {
            $entry = $this->dhtEngine->put($key, $value, 3, $ttl);
        } else {
            $entry = $this->dhtEngine->putContent($value, 3, $ttl);
        }

        $response->status(201);
        $this->json($response, $entry->toArray());
    }

    private function handleDhtGet(string $key, Response $response): void
    {
        if (!$this->dhtEngine) {
            $response->status(503);
            $this->json($response, ['error' => 'DHT not initialized']);
            return;
        }

        $entry = $this->dhtEngine->get(urldecode($key));
        if ($entry === null) {
            $response->status(404);
            $this->json($response, ['error' => 'Key not found']);
            return;
        }

        $this->json($response, $entry->toArray());
    }

    private function handleDhtDelete(string $key, Response $response): void
    {
        if (!$this->dhtEngine) {
            $response->status(503);
            $this->json($response, ['error' => 'DHT not initialized']);
            return;
        }

        $deleted = $this->dhtEngine->delete(urldecode($key));
        if (!$deleted) {
            $response->status(404);
            $this->json($response, ['error' => 'Key not found']);
            return;
        }

        $this->json($response, ['deleted' => true, 'key' => urldecode($key)]);
    }

    private function handleDhtStats(Response $response): void
    {
        if (!$this->dhtEngine) {
            $response->status(503);
            $this->json($response, ['error' => 'DHT not initialized']);
            return;
        }

        $this->json($response, $this->dhtEngine->stats());
    }

    private function handleDiscoveryStats(Response $response): void
    {
        if (!$this->discoveryManager) {
            $response->status(503);
            $this->json($response, ['error' => 'Discovery manager not initialized']);
            return;
        }

        $this->json($response, $this->discoveryManager->stats());
    }

    // --- Swarm node registry ---

    private ?\VoidLux\Swarm\Registry $swarmRegistry = null;
    private ?\VoidLux\Swarm\Status $swarmStatus = null;

    public function setSwarmRegistry(\VoidLux\Swarm\Registry $registry): void
    {
        $this->swarmRegistry = $registry;
    }

    public function setSwarmStatus(\VoidLux\Swarm\Status $status): void
    {
        $this->swarmStatus = $status;
    }

    private function handleListNodes(Response $response): void
    {
        if (!$this->swarmRegistry) {
            $response->status(503);
            $this->json($response, ['error' => 'Swarm registry not initialized']);
            return;
        }

        $nodes = $this->swarmRegistry->getAllNodes();
        $this->json($response, [
            'nodes' => array_map(fn($n) => $n->toArray(), $nodes),
            'count' => count($nodes),
        ]);
    }

    private function handleSwarmNodeStatus(Response $response): void
    {
        if (!$this->swarmStatus) {
            $response->status(503);
            $this->json($response, ['error' => 'Swarm status not initialized']);
            return;
        }

        $this->json($response, $this->swarmStatus->getSnapshot());
    }

    // --- Galactic marketplace handlers ---

    private function handleListOfferings(Response $response): void
    {
        if (!$this->marketplace) {
            $response->status(503);
            $this->json($response, ['error' => 'Marketplace not initialized']);
            return;
        }
        $this->json($response, array_map(fn($o) => $o->toArray(), $this->marketplace->getOfferings()));
    }

    private function handleCreateOffering(Request $request, Response $response): void
    {
        if (!$this->marketplace) {
            $response->status(503);
            $this->json($response, ['error' => 'Marketplace not initialized']);
            return;
        }
        $body = json_decode($request->getContent(), true) ?? [];
        $idleAgents = (int) ($body['idle_agents'] ?? 0);
        $capabilities = $body['capabilities'] ?? [];
        if ($idleAgents <= 0) {
            $response->status(400);
            $this->json($response, ['error' => 'idle_agents must be > 0']);
            return;
        }
        $offering = $this->marketplace->announceOffering($idleAgents, $capabilities);
        $response->status(201);
        $this->json($response, $offering->toArray());
    }

    private function handleWithdrawOffering(string $id, Response $response): void
    {
        if (!$this->marketplace) {
            $response->status(503);
            $this->json($response, ['error' => 'Marketplace not initialized']);
            return;
        }
        $withdrawn = $this->marketplace->withdrawOffering($id);
        if (!$withdrawn) {
            $response->status(404);
            $this->json($response, ['error' => 'Offering not found or not owned by this node']);
            return;
        }
        $this->json($response, ['withdrawn' => true, 'offering_id' => $id]);
    }

    private function handleRequestTribute(Request $request, Response $response): void
    {
        if (!$this->marketplace) {
            $response->status(503);
            $this->json($response, ['error' => 'Marketplace not initialized']);
            return;
        }
        $body = json_decode($request->getContent(), true) ?? [];
        $offeringId = $body['offering_id'] ?? '';
        $taskCount = (int) ($body['task_count'] ?? 1);
        if (!$offeringId) {
            $response->status(400);
            $this->json($response, ['error' => 'offering_id is required']);
            return;
        }
        $tribute = $this->marketplace->requestTribute($offeringId, $taskCount);
        if (!$tribute) {
            $response->status(404);
            $this->json($response, ['error' => 'Offering not found or expired']);
            return;
        }
        $response->status(201);
        $this->json($response, $tribute->toArray());
    }

    private function handleListTributes(Response $response): void
    {
        if (!$this->marketplace) {
            $response->status(503);
            $this->json($response, ['error' => 'Marketplace not initialized']);
            return;
        }
        $this->json($response, array_map(fn($t) => $t->toArray(), $this->marketplace->getTributes()));
    }

    private function handleWallet(Response $response): void
    {
        if (!$this->marketplace) {
            $this->json($response, ['balance' => 0, 'currency' => 'VOID']);
            return;
        }
        $this->json($response, $this->marketplace->getWallet());
    }

    private function json(Response $response, array $data): void
    {
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
