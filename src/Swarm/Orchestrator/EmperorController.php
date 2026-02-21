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
use VoidLux\Swarm\Model\AgentModel;
use VoidLux\Swarm\Model\TaskModel;
use VoidLux\Swarm\Galactic\GalacticMarketplace;
use VoidLux\Swarm\Gossip\MarketplaceGossipEngine;
use VoidLux\Swarm\Git\GitWorkspace;
use VoidLux\Swarm\Gossip\TaskGossipEngine;
use VoidLux\Swarm\Offer\OfferPayEngine;
use VoidLux\Swarm\Mcp\McpHandler;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\Swarm\Model\MessageModel;
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
    private ?OfferPayEngine $offerPayEngine = null;
    private ?TaskGossipEngine $taskGossip = null;
    private ?LamportClock $clock = null;
    private ?MarketplaceGossipEngine $marketplaceGossip = null;
    private ?SwarmOverseer $overseer = null;

    /** @var callable|null fn(): void — triggers server shutdown */
    private $shutdownCallback = null;

    /** @var callable|null fn(string $agentId, string $status): void */
    private $onAgentStatusChange = null;

    /** @var callable|null fn(string $event, array $taskData): void — pushes task updates to WS */
    private $onTaskEvent = null;

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

    public function setOfferPayEngine(OfferPayEngine $engine): void
    {
        $this->offerPayEngine = $engine;
    }

    public function setTaskGossip(TaskGossipEngine $gossip): void
    {
        $this->taskGossip = $gossip;
    }

    public function setLamportClock(LamportClock $clock): void
    {
        $this->clock = $clock;
    }

    public function setMarketplaceGossip(MarketplaceGossipEngine $gossip): void
    {
        $this->marketplaceGossip = $gossip;
    }

    public function setOverseer(SwarmOverseer $overseer): void
    {
        $this->overseer = $overseer;
    }

    public function onShutdown(callable $callback): void
    {
        $this->shutdownCallback = $callback;
    }

    public function onAgentStatusChange(callable $callback): void
    {
        $this->onAgentStatusChange = $callback;
    }

    public function onTaskEvent(callable $callback): void
    {
        $this->onTaskEvent = $callback;
    }

    private function fireTaskEvent(string $event, $task): void
    {
        if ($this->onTaskEvent) {
            ($this->onTaskEvent)($event, is_array($task) ? $task : $task->toArray());
        }
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

            case preg_match('#^/api/swarm/tasks/([^/]+)/merge-pr$#', $path, $m) === 1 && $method === 'POST':
                $this->handleMergePr($m[1], $response);
                break;

            case preg_match('#^/api/swarm/tasks/([^/]+)/review-fix$#', $path, $m) === 1 && $method === 'POST':
                $this->handleReviewFix($m[1], $request, $response);
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

            case $path === '/api/swarm/overseer' && $method === 'GET':
                $this->handleOverseerCheck($response);
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

            // --- Offer-Pay protocol API ---
            case $path === '/api/swarm/offers' && $method === 'GET':
                $this->handleListOffers($response);
                break;

            case $path === '/api/swarm/offers' && $method === 'POST':
                $this->handleCreateOffer($request, $response);
                break;

            case preg_match('#^/api/swarm/offers/([^/]+)/accept$#', $path, $m) === 1 && $method === 'POST':
                $this->handleAcceptOffer($m[1], $request, $response);
                break;

            case preg_match('#^/api/swarm/offers/([^/]+)/reject$#', $path, $m) === 1 && $method === 'POST':
                $this->handleRejectOffer($m[1], $request, $response);
                break;

            case preg_match('#^/api/swarm/offers/([^/]+)/pay$#', $path, $m) === 1 && $method === 'POST':
                $this->handleInitiatePayment($m[1], $response);
                break;

            case $path === '/api/swarm/payments' && $method === 'GET':
                $this->handleListPayments($response);
                break;

            case preg_match('#^/api/swarm/payments/([^/]+)/confirm$#', $path, $m) === 1 && $method === 'POST':
                $this->handleConfirmPayment($m[1], $response);
                break;

            case $path === '/api/swarm/transactions' && $method === 'GET':
                $this->handleTransactionHistory($response);
                break;

            // --- Message board API ---
            case $path === '/api/swarm/board' && $method === 'GET':
                $this->handleListMessages($request, $response);
                break;

            case $path === '/api/swarm/board' && $method === 'POST':
                $this->handlePostMessage($request, $response);
                break;

            case preg_match('#^/api/swarm/board/([^/]+)$#', $path, $m) === 1 && $method === 'GET':
                $this->handleGetMessage($m[1], $response);
                break;

            case preg_match('#^/api/swarm/board/([^/]+)/reply$#', $path, $m) === 1 && $method === 'POST':
                $this->handleReplyToMessage($m[1], $request, $response);
                break;

            case preg_match('#^/api/swarm/board/([^/]+)/claim$#', $path, $m) === 1 && $method === 'POST':
                $this->handleClaimMessage($m[1], $request, $response);
                break;

            case preg_match('#^/api/swarm/board/([^/]+)/resolve$#', $path, $m) === 1 && $method === 'POST':
                $this->handleResolveMessage($m[1], $response);
                break;

            case preg_match('#^/api/swarm/board/([^/]+)$#', $path, $m) === 1 && $method === 'DELETE':
                $this->handleDeleteMessage($m[1], $response);
                break;

            // --- Bounty API ---
            case $path === '/api/swarm/bounties' && $method === 'GET':
                $this->handleListBounties($response);
                break;

            case $path === '/api/swarm/bounties' && $method === 'POST':
                $this->handlePostBounty($request, $response);
                break;

            case preg_match('#^/api/swarm/bounties/([^/]+)/claim$#', $path, $m) === 1 && $method === 'POST':
                $this->handleClaimBounty($m[1], $response);
                break;

            case preg_match('#^/api/swarm/bounties/([^/]+)$#', $path, $m) === 1 && $method === 'DELETE':
                $this->handleCancelBounty($m[1], $response);
                break;

            // --- Capability API ---
            case $path === '/api/swarm/capabilities' && $method === 'GET':
                $this->handleListCapabilities($response);
                break;

            // --- Delegation API ---
            case $path === '/api/swarm/delegations' && $method === 'GET':
                $this->handleListDelegations($response);
                break;

            case $path === '/api/swarm/delegations' && $method === 'POST':
                $this->handleCreateDelegation($request, $response);
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
                'blocked' => $this->db->getTaskCount('blocked'),
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

        // If planner is available (agent or LLM), create as a planning parent task
        $hasPlannerAgent = $this->db->getIdlePlannerAgent() !== null;
        $hasLlmPlanner = $this->taskPlanner !== null;

        if ($hasPlannerAgent || $hasLlmPlanner) {
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

            // Set auto_merge flag if requested
            if (!empty($body['auto_merge'])) {
                $this->db->setAutoMerge($task->id, true);
                $task = $this->db->getTask($task->id) ?? $task;
            }

            $response->status(201);
            $this->json($response, $task->toArray());

            // Push parent task creation to WS immediately
            $this->fireTaskEvent('task_created', $task);

            // Try planner agent first — it has codebase access
            $plannerAgent = $this->db->getIdlePlannerAgent();
            if ($plannerAgent) {
                $this->dispatchToPlanner($task, $plannerAgent);
                return;
            }

            // Fall back to LLM API decomposition
            if ($this->taskPlanner !== null) {
                $this->decomposeWithLlm($task);
            }

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

        // Set auto_merge flag if requested
        if (!empty($body['auto_merge'])) {
            $this->db->setAutoMerge($task->id, true);
            $task = $this->db->getTask($task->id) ?? $task;
        }

        $this->fireTaskEvent('task_created', $task);
        $this->taskDispatcher?->triggerDispatch();

        $response->status(201);
        $this->json($response, $task->toArray());
    }

    /**
     * Dispatch a planning task to the planner agent (tmux-based Claude Code).
     * The planner explores the codebase and calls task_plan MCP tool with subtask definitions.
     */
    private function dispatchToPlanner(TaskModel $task, AgentModel $plannerAgent): void
    {
        // Claim the planning task for the planner agent
        $this->taskQueue->claim($task->id, $plannerAgent->id);
        $this->db->updateAgentStatus($plannerAgent->id, 'busy', $task->id);

        // Deliver planning prompt to planner's tmux session
        $delivered = $this->bridge->deliverPlanningTask($plannerAgent, $task);
        if (!$delivered) {
            // Planner not ready — unclaim and fall back to LLM
            $this->taskQueue->requeue($task->id, 'Planner agent not ready');
            $this->db->updateAgentStatus($plannerAgent->id, 'idle', null);

            if ($this->taskPlanner !== null) {
                // Re-set to Planning status (requeue sets to Pending)
                $refreshed = $this->db->getTask($task->id);
                if ($refreshed) {
                    $planning = $refreshed->withStatus(TaskStatus::Planning, $refreshed->lamportTs);
                    $this->db->updateTask($planning);
                    $this->decomposeWithLlm($planning);
                }
            }
            return;
        }

        // Transition to in_progress
        $this->taskQueue->updateProgress($task->id, $plannerAgent->id, 'Planner agent analyzing codebase');
        $this->fireTaskEvent('task_updated', $this->db->getTask($task->id)?->toArray() ?? $task->toArray());

        $agent = $this->db->getAgent($plannerAgent->id);
        if ($agent) {
            $this->wsHandler('agent_busy', $agent);
        }
    }

    /**
     * Fall back to LLM API decomposition (original TaskPlanner flow).
     */
    private function decomposeWithLlm(TaskModel $task): void
    {
        $taskId = $task->id;
        $planner = $this->taskPlanner;
        $taskQueue = $this->taskQueue;
        $db = $this->db;
        $dispatcher = $this->taskDispatcher;
        $wsCallback = $this->onTaskEvent;

        Coroutine::create(function () use ($taskId, $planner, $taskQueue, $db, $dispatcher, $wsCallback) {
            $parentTask = $db->getTask($taskId);
            if (!$parentTask) {
                return;
            }

            $subtaskDefs = $planner->decompose($parentTask);

            if (empty($subtaskDefs)) {
                // Decomposition failed — convert to regular pending task
                $updated = $parentTask->withStatus(TaskStatus::Pending, $parentTask->lamportTs);
                $db->updateTask($updated);
                if ($wsCallback) {
                    ($wsCallback)('task_updated', $updated->toArray());
                }
                $dispatcher?->triggerDispatch();
                return;
            }

            // Two-pass creation: first create all subtasks, then resolve dependency IDs
            $idMap = []; // localId => realUUID
            $subtasks = [];

            // Pass 1: Create all subtasks without dependencies
            foreach ($subtaskDefs as $def) {
                $subtask = $taskQueue->createTask(
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
                    complexity: $def['complexity'] ?? 'medium',
                );
                $localId = $def['id'] ?? '';
                if ($localId !== '') {
                    $idMap[$localId] = $subtask->id;
                }
                $subtasks[] = ['task' => $subtask, 'def' => $def];
            }

            // Pass 2: Resolve local dependency IDs to real UUIDs and update blocked tasks
            foreach ($subtasks as $item) {
                $def = $item['def'];
                $subtask = $item['task'];
                $localDeps = $def['dependsOn'] ?? [];

                if (!empty($localDeps)) {
                    $resolvedDeps = [];
                    foreach ($localDeps as $localDepId) {
                        if (isset($idMap[$localDepId])) {
                            $resolvedDeps[] = $idMap[$localDepId];
                        }
                    }
                    if (!empty($resolvedDeps)) {
                        $ts = $subtask->lamportTs;
                        $blocked = new \VoidLux\Swarm\Model\TaskModel(
                            id: $subtask->id, title: $subtask->title, description: $subtask->description,
                            status: \VoidLux\Swarm\Model\TaskStatus::Blocked, priority: $subtask->priority,
                            requiredCapabilities: $subtask->requiredCapabilities, createdBy: $subtask->createdBy,
                            assignedTo: $subtask->assignedTo, assignedNode: $subtask->assignedNode,
                            result: $subtask->result, error: $subtask->error, progress: $subtask->progress,
                            projectPath: $subtask->projectPath, context: $subtask->context,
                            lamportTs: $ts, claimedAt: $subtask->claimedAt, completedAt: $subtask->completedAt,
                            createdAt: $subtask->createdAt, updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
                            parentId: $subtask->parentId, workInstructions: $subtask->workInstructions,
                            acceptanceCriteria: $subtask->acceptanceCriteria, reviewStatus: $subtask->reviewStatus,
                            reviewFeedback: $subtask->reviewFeedback, archived: $subtask->archived,
                            gitBranch: $subtask->gitBranch, mergeAttempts: $subtask->mergeAttempts,
                            testCommand: $subtask->testCommand, dependsOn: $resolvedDeps,
                            autoMerge: $subtask->autoMerge, prUrl: $subtask->prUrl,
                        );
                        $db->updateTask($blocked);
                        $subtask = $blocked;
                    }
                }

                // Push each subtask to WS
                if ($wsCallback) {
                    ($wsCallback)('task_created', $subtask->toArray());
                }
            }

            // Mark parent as in_progress (subtasks being worked on)
            $updated = $parentTask->withStatus(TaskStatus::InProgress, $parentTask->lamportTs);
            $db->updateTask($updated);
            if ($wsCallback) {
                ($wsCallback)('task_updated', $updated->toArray());
            }

            $dispatcher?->triggerDispatch();
        });
    }

    /**
     * Helper to push agent status to WS (avoids exposing wsHandler directly).
     */
    private function wsHandler(string $event, AgentModel $agent): void
    {
        if ($this->onAgentStatusChange) {
            ($this->onAgentStatusChange)($agent->id, str_replace('agent_', '', $event));
        }
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
        $task = $this->db->getTask($taskId);
        if ($task) {
            $this->fireTaskEvent('task_cancelled', $task);
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
        $this->fireTaskEvent('task_archived', $task);
        $this->json($response, $task->toArray());
    }

    private function handleArchiveAll(Response $response): void
    {
        $archivedIds = $this->taskQueue->archiveAllTerminal();
        foreach ($archivedIds as $id) {
            $task = $this->db->getTask($id);
            if ($task) {
                $this->fireTaskEvent('task_archived', $task);
            }
        }
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
            // Use taskQueue->complete() flow: DB update + gossip + tryCompleteParent + dispatch
            $this->taskQueue->completeAccepted($taskId, $task->assignedTo ?? '', $task->result);
            $this->fireTaskEvent('task_updated', $this->db->getTask($taskId)?->toArray() ?? $task->toArray());
            $this->json($response, ['status' => 'accepted', 'task_id' => $taskId]);
        } else {
            $this->db->updateReviewStatus($taskId, 'rejected', $feedback);
            // Requeue with feedback
            $this->taskQueue->requeue($taskId, "Review rejected: {$feedback}");
            $this->fireTaskEvent('task_updated', $this->db->getTask($taskId)?->toArray() ?? $task->toArray());
            $this->taskDispatcher?->triggerDispatch();
            $this->json($response, ['status' => 'rejected', 'task_id' => $taskId, 'feedback' => $feedback]);
        }
    }

    /**
     * Handle PR review feedback: decompose issues into fix subtasks on the existing integration branch.
     * POST /api/swarm/tasks/{id}/review-fix { "issues": ["issue 1", "issue 2", ...] }
     */
    private function handleReviewFix(string $taskId, Request $request, Response $response): void
    {
        $task = $this->taskQueue->getTask($taskId);
        if (!$task) {
            $response->status(404);
            $this->json($response, ['error' => 'Task not found']);
            return;
        }

        // Must be a parent task with a PR (completed or in_progress)
        if (!$task->prUrl && !$task->gitBranch) {
            $response->status(422);
            $this->json($response, ['error' => 'Task has no PR or integration branch']);
            return;
        }

        $body = json_decode($request->getContent(), true);
        $issues = $body['issues'] ?? [];
        if (empty($issues) || !is_array($issues)) {
            $response->status(422);
            $this->json($response, ['error' => 'Must provide "issues" array']);
            return;
        }

        $branch = $task->gitBranch ?: ('integrate/' . substr($task->id, 0, 8));

        // Set parent back to in_progress
        $updated = $task->withStatus(TaskStatus::InProgress, $task->lamportTs + 1);
        $this->db->updateTask($updated);
        $this->fireTaskEvent('task_updated', $updated->toArray());

        // Create fix subtasks — one per issue, all on the integration branch
        $fixTasks = [];
        foreach ($issues as $i => $issue) {
            $issueText = is_string($issue) ? $issue : json_encode($issue);
            $fixTask = $this->taskQueue->createTask(
                title: 'Fix: ' . substr($issueText, 0, 80),
                description: "Fix issue found during PR review of parent task \"{$task->title}\".",
                projectPath: $task->projectPath,
                createdBy: $task->createdBy,
                parentId: $task->id,
                workInstructions: <<<INSTRUCTIONS
## PR Review Fix

You are fixing an issue found during code review of an existing PR.

**Branch**: `{$branch}`
**IMPORTANT**: You MUST work on the `{$branch}` branch, NOT create a new task branch. Check out this branch first:
```
git checkout {$branch}
git pull origin {$branch}
```

**Issue to fix**:
{$issueText}

**Original task**: {$task->title}

After fixing, commit and push to `{$branch}`. Do NOT create a new branch.
INSTRUCTIONS,
                acceptanceCriteria: "The issue is resolved: {$issueText}",
                complexity: 'small',
            );
            $fixTasks[] = $fixTask;

            $this->fireTaskEvent('task_created', $fixTask->toArray());
        }

        $this->taskDispatcher?->triggerDispatch();

        $this->json($response, [
            'status' => 'fix_dispatched',
            'parent_id' => $taskId,
            'branch' => $branch,
            'fix_count' => count($fixTasks),
            'fix_task_ids' => array_map(fn($t) => $t->id, $fixTasks),
        ]);
    }

    private function handleMergePr(string $taskId, Response $response): void
    {
        $task = $this->taskQueue->getTask($taskId);
        if (!$task) {
            $response->status(404);
            $this->json($response, ['error' => 'Task not found']);
            return;
        }

        // Resolve PR URL: check dedicated prUrl field first, then parse from result text
        $prUrl = property_exists($task, 'prUrl') ? $task->prUrl : '';
        if ($prUrl === '' && $task->result) {
            if (preg_match('/PR: (https?:\/\/\S+)/', $task->result, $m)) {
                $prUrl = $m[1];
            }
        }

        if ($prUrl === '') {
            $response->status(422);
            $this->json($response, ['error' => 'Task has no PR URL']);
            return;
        }

        $git = new GitWorkspace();
        $workDir = getcwd() . '/workbench/.merge';
        if (!is_dir($workDir)) {
            $workDir = getcwd();
        }

        [$success, $output] = $git->mergePullRequest($workDir, $prUrl);

        if (!$success) {
            $response->status(502);
            $this->json($response, [
                'error' => 'gh pr merge failed',
                'output' => $output,
                'task_id' => $taskId,
                'pr_url' => $prUrl,
            ]);
            return;
        }

        $this->json($response, [
            'status' => 'merged',
            'task_id' => $taskId,
            'pr_url' => $prUrl,
            'output' => $output,
        ]);
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

        // Gossip terminal state for all tasks so peers don't resurrect them via anti-entropy
        if ($this->taskGossip && $this->clock && $count > 0) {
            foreach ($tasks as $task) {
                if (!$task->status->isTerminal()) {
                    $ts = $this->clock->tick();
                    $this->taskGossip->gossipTaskFail(
                        $task->id,
                        $task->assignedTo ?? '',
                        'Cleared by emperor',
                        $ts
                    );
                }
                // Gossip archive so peers mark them archived (prevents anti-entropy resurrection)
                $this->taskGossip->gossipTaskArchive($task->id, $this->clock->tick());
            }
        }

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
            role: $body['role'] ?? '',
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
     *              capabilities?: string[], model?: string, env?: array<string,string>, role?: string} $spec
     */
    private function registerOneAgent(array $spec, string $nodeShort, int $index): array
    {
        $tool = $spec['tool'] ?? 'claude';
        $projectPath = $spec['project_path'] ?? '';
        $capabilities = $spec['capabilities'] ?? [];
        $model = $spec['model'] ?? '';
        $env = $spec['env'] ?? [];
        $role = $spec['role'] ?? '';
        $namePrefix = $spec['name_prefix'] ?? ($role === 'planner' ? 'planner' : 'agent');

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
            role: $role,
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
            if ($this->onTaskEvent !== null) {
                $this->mcpHandler->onTaskEvent($this->onTaskEvent);
            }
            if ($this->offerPayEngine !== null) {
                $this->mcpHandler->setOfferPayEngine($this->offerPayEngine);
            }
            if ($this->taskGossip !== null) {
                $this->mcpHandler->setTaskGossip($this->taskGossip);
            }
            if ($this->clock !== null) {
                $this->mcpHandler->setLamportClock($this->clock);
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
        // Gossip to P2P mesh
        $this->marketplaceGossip?->gossipOfferingAnnounce($offering);
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
        // Gossip withdrawal to P2P mesh
        $this->marketplaceGossip?->gossipOfferingWithdraw($id);
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
        // Gossip tribute request to P2P mesh
        $this->marketplaceGossip?->gossipTributeRequest($tribute);
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

    // --- Offer-Pay protocol handlers ---

    private function handleListOffers(Response $response): void
    {
        if (!$this->offerPayEngine) {
            $response->status(503);
            $this->json($response, ['error' => 'Offer-Pay engine not initialized']);
            return;
        }
        $this->json($response, array_map(fn($o) => $o->toArray(), $this->offerPayEngine->getAllOffers()));
    }

    private function handleCreateOffer(Request $request, Response $response): void
    {
        if (!$this->offerPayEngine) {
            $response->status(503);
            $this->json($response, ['error' => 'Offer-Pay engine not initialized']);
            return;
        }
        $body = json_decode($request->getContent(), true) ?? [];
        $toNodeId = $body['to_node_id'] ?? '';
        $amount = (int) ($body['amount'] ?? 0);
        if (!$toNodeId || $amount <= 0) {
            $response->status(400);
            $this->json($response, ['error' => 'to_node_id and positive amount are required']);
            return;
        }
        $result = $this->offerPayEngine->createOffer(
            toNodeId: $toNodeId,
            amount: $amount,
            conditions: $body['conditions'] ?? '',
            currency: $body['currency'] ?? 'VOID',
            validitySeconds: (int) ($body['validity_seconds'] ?? 300),
            taskId: $body['task_id'] ?? null,
        );
        if (is_string($result)) {
            $response->status(400);
            $this->json($response, ['error' => $result]);
            return;
        }
        $response->status(201);
        $this->json($response, $result->toArray());
    }

    private function handleAcceptOffer(string $offerId, Request $request, Response $response): void
    {
        if (!$this->offerPayEngine) {
            $response->status(503);
            $this->json($response, ['error' => 'Offer-Pay engine not initialized']);
            return;
        }
        $body = json_decode($request->getContent(), true) ?? [];
        $result = $this->offerPayEngine->acceptOffer($offerId, $body['reason'] ?? null);
        if (is_string($result)) {
            $response->status(400);
            $this->json($response, ['error' => $result]);
            return;
        }
        $this->json($response, $result->toArray());
    }

    private function handleRejectOffer(string $offerId, Request $request, Response $response): void
    {
        if (!$this->offerPayEngine) {
            $response->status(503);
            $this->json($response, ['error' => 'Offer-Pay engine not initialized']);
            return;
        }
        $body = json_decode($request->getContent(), true) ?? [];
        $result = $this->offerPayEngine->rejectOffer($offerId, $body['reason'] ?? null);
        if (is_string($result)) {
            $response->status(400);
            $this->json($response, ['error' => $result]);
            return;
        }
        $this->json($response, $result->toArray());
    }

    private function handleInitiatePayment(string $offerId, Response $response): void
    {
        if (!$this->offerPayEngine) {
            $response->status(503);
            $this->json($response, ['error' => 'Offer-Pay engine not initialized']);
            return;
        }
        $result = $this->offerPayEngine->initiatePayment($offerId);
        if (is_string($result)) {
            $response->status(400);
            $this->json($response, ['error' => $result]);
            return;
        }
        $response->status(201);
        $this->json($response, $result->toArray());
    }

    private function handleListPayments(Response $response): void
    {
        if (!$this->offerPayEngine) {
            $response->status(503);
            $this->json($response, ['error' => 'Offer-Pay engine not initialized']);
            return;
        }
        $this->json($response, array_map(fn($p) => $p->toArray(), $this->offerPayEngine->getAllPayments()));
    }

    private function handleConfirmPayment(string $paymentId, Response $response): void
    {
        if (!$this->offerPayEngine) {
            $response->status(503);
            $this->json($response, ['error' => 'Offer-Pay engine not initialized']);
            return;
        }
        $result = $this->offerPayEngine->confirmPayment($paymentId);
        if (is_string($result)) {
            $response->status(400);
            $this->json($response, ['error' => $result]);
            return;
        }
        $this->json($response, $result->toArray());
    }

    private function handleTransactionHistory(Response $response): void
    {
        if (!$this->offerPayEngine) {
            $response->status(503);
            $this->json($response, ['error' => 'Offer-Pay engine not initialized']);
            return;
        }
        $this->json($response, $this->offerPayEngine->getTransactionHistory());
    }

    // --- Message board handlers ---

    private function handleListMessages(Request $request, Response $response): void
    {
        $category = $request->get['category'] ?? null;
        $status = $request->get['status'] ?? null;
        $messages = $this->db->getMessages($category, $status);
        $this->json($response, array_map(fn($m) => $m->toArray(), $messages));
    }

    private function handlePostMessage(Request $request, Response $response): void
    {
        $body = json_decode($request->getContent(), true);
        if (!$body || empty($body['title'])) {
            $response->status(400);
            $this->json($response, ['error' => 'title is required']);
            return;
        }

        if (!$this->taskGossip || !$this->clock) {
            $response->status(503);
            $this->json($response, ['error' => 'Message board not initialized']);
            return;
        }

        $msg = MessageModel::create(
            authorId: $body['author_id'] ?? $this->nodeId,
            authorName: $body['author_name'] ?? ('Node-' . substr($this->nodeId, 0, 8)),
            category: $body['category'] ?? 'discussion',
            title: $body['title'],
            content: $body['content'] ?? '',
            lamportTs: $this->clock->tick(),
            priority: (int) ($body['priority'] ?? 0),
            tags: $body['tags'] ?? [],
            taskId: $body['task_id'] ?? null,
        );

        $this->taskGossip->createBoardMessage($msg);

        $response->status(201);
        $this->json($response, $msg->toArray());
    }

    private function handleGetMessage(string $messageId, Response $response): void
    {
        $msg = $this->db->getMessage($messageId);
        if (!$msg) {
            $response->status(404);
            $this->json($response, ['error' => 'Message not found']);
            return;
        }

        $replies = $this->db->getMessageReplies($messageId);
        $data = $msg->toArray();
        $data['replies'] = array_map(fn($r) => $r->toArray(), $replies);
        $this->json($response, $data);
    }

    private function handleReplyToMessage(string $parentId, Request $request, Response $response): void
    {
        $parent = $this->db->getMessage($parentId);
        if (!$parent) {
            $response->status(404);
            $this->json($response, ['error' => 'Parent message not found']);
            return;
        }

        $body = json_decode($request->getContent(), true);
        if (!$body || empty($body['content'])) {
            $response->status(400);
            $this->json($response, ['error' => 'content is required']);
            return;
        }

        if (!$this->taskGossip || !$this->clock) {
            $response->status(503);
            $this->json($response, ['error' => 'Message board not initialized']);
            return;
        }

        $reply = MessageModel::create(
            authorId: $body['author_id'] ?? $this->nodeId,
            authorName: $body['author_name'] ?? ('Node-' . substr($this->nodeId, 0, 8)),
            category: $parent->category,
            title: 'Re: ' . $parent->title,
            content: $body['content'],
            lamportTs: $this->clock->tick(),
            parentId: $parentId,
        );

        $this->taskGossip->createBoardMessage($reply);

        $response->status(201);
        $this->json($response, $reply->toArray());
    }

    private function handleClaimMessage(string $messageId, Request $request, Response $response): void
    {
        $msg = $this->db->getMessage($messageId);
        if (!$msg) {
            $response->status(404);
            $this->json($response, ['error' => 'Message not found']);
            return;
        }
        if ($msg->status !== 'active') {
            $response->status(409);
            $this->json($response, ['error' => "Cannot claim message in status: {$msg->status}"]);
            return;
        }

        if (!$this->taskGossip || !$this->clock) {
            $response->status(503);
            $this->json($response, ['error' => 'Message board not initialized']);
            return;
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $claimerId = $body['agent_id'] ?? $this->nodeId;

        $updated = $msg->withClaimedBy($claimerId, $this->clock->tick());
        $this->taskGossip->gossipBoardUpdate($updated);

        $this->json($response, $updated->toArray());
    }

    private function handleResolveMessage(string $messageId, Response $response): void
    {
        $msg = $this->db->getMessage($messageId);
        if (!$msg) {
            $response->status(404);
            $this->json($response, ['error' => 'Message not found']);
            return;
        }

        if (!$this->taskGossip || !$this->clock) {
            $response->status(503);
            $this->json($response, ['error' => 'Message board not initialized']);
            return;
        }

        $updated = $msg->withStatus('resolved', $this->clock->tick());
        $this->taskGossip->gossipBoardUpdate($updated);

        $this->json($response, $updated->toArray());
    }

    private function handleDeleteMessage(string $messageId, Response $response): void
    {
        $msg = $this->db->getMessage($messageId);
        if (!$msg) {
            $response->status(404);
            $this->json($response, ['error' => 'Message not found']);
            return;
        }

        if (!$this->taskGossip || !$this->clock) {
            $response->status(503);
            $this->json($response, ['error' => 'Message board not initialized']);
            return;
        }

        $this->taskGossip->gossipBoardDelete($messageId, $this->clock->tick());
        $this->json($response, ['deleted' => true, 'message_id' => $messageId]);
    }

    // --- Bounty handlers ---

    private function handleListBounties(Response $response): void
    {
        if (!$this->marketplace) {
            $response->status(503);
            $this->json($response, ['error' => 'Marketplace not initialized']);
            return;
        }
        $this->json($response, array_map(fn($b) => $b->toArray(), $this->marketplace->getBounties()));
    }

    private function handlePostBounty(Request $request, Response $response): void
    {
        if (!$this->marketplace) {
            $response->status(503);
            $this->json($response, ['error' => 'Marketplace not initialized']);
            return;
        }
        $body = json_decode($request->getContent(), true) ?? [];
        $title = $body['title'] ?? '';
        if (!$title) {
            $response->status(400);
            $this->json($response, ['error' => 'title is required']);
            return;
        }
        $bounty = $this->marketplace->postBounty(
            title: $title,
            description: $body['description'] ?? '',
            requiredCapabilities: $body['required_capabilities'] ?? [],
            reward: (int) ($body['reward'] ?? 10),
            currency: $body['currency'] ?? 'VOID',
            ttlSeconds: (int) ($body['ttl_seconds'] ?? 3600),
        );
        $this->marketplaceGossip?->gossipBountyPost($bounty);
        $response->status(201);
        $this->json($response, $bounty->toArray());
    }

    private function handleClaimBounty(string $bountyId, Response $response): void
    {
        if (!$this->marketplace) {
            $response->status(503);
            $this->json($response, ['error' => 'Marketplace not initialized']);
            return;
        }
        $claimed = $this->marketplace->claimBounty($bountyId, $this->marketplace->getNodeId());
        if (!$claimed) {
            $response->status(404);
            $this->json($response, ['error' => 'Bounty not found or not open']);
            return;
        }
        $this->marketplaceGossip?->gossipBountyClaim($bountyId, $this->marketplace->getNodeId());
        $this->json($response, ['claimed' => true, 'bounty_id' => $bountyId]);
    }

    private function handleCancelBounty(string $bountyId, Response $response): void
    {
        if (!$this->marketplace) {
            $response->status(503);
            $this->json($response, ['error' => 'Marketplace not initialized']);
            return;
        }
        $cancelled = $this->marketplace->cancelBounty($bountyId);
        if (!$cancelled) {
            $response->status(404);
            $this->json($response, ['error' => 'Bounty not found']);
            return;
        }
        $this->marketplaceGossip?->gossipBountyCancel($bountyId);
        $this->json($response, ['cancelled' => true, 'bounty_id' => $bountyId]);
    }

    // --- Capability handlers ---

    private function handleListCapabilities(Response $response): void
    {
        if (!$this->marketplace) {
            $response->status(503);
            $this->json($response, ['error' => 'Marketplace not initialized']);
            return;
        }
        $this->json($response, array_map(fn($p) => $p->toArray(), $this->marketplace->getCapabilityProfiles()));
    }

    // --- Delegation handlers ---

    private function handleListDelegations(Response $response): void
    {
        if (!$this->marketplace) {
            $response->status(503);
            $this->json($response, ['error' => 'Marketplace not initialized']);
            return;
        }
        $this->json($response, array_map(fn($d) => $d->toArray(), $this->marketplace->getDelegations()));
    }

    private function handleCreateDelegation(Request $request, Response $response): void
    {
        if (!$this->marketplace) {
            $response->status(503);
            $this->json($response, ['error' => 'Marketplace not initialized']);
            return;
        }
        $body = json_decode($request->getContent(), true) ?? [];
        $targetNodeId = $body['target_node_id'] ?? '';
        $title = $body['title'] ?? '';
        if (!$targetNodeId || !$title) {
            $response->status(400);
            $this->json($response, ['error' => 'target_node_id and title are required']);
            return;
        }
        $delegation = $this->marketplace->createDelegation(
            targetNodeId: $targetNodeId,
            title: $title,
            description: $body['description'] ?? '',
            workInstructions: $body['work_instructions'] ?? null,
            acceptanceCriteria: $body['acceptance_criteria'] ?? null,
            requiredCapabilities: $body['required_capabilities'] ?? [],
            projectPath: $body['project_path'] ?? null,
            bountyId: $body['bounty_id'] ?? null,
            tributeId: $body['tribute_id'] ?? null,
        );
        $this->marketplaceGossip?->gossipTaskDelegate($delegation);
        $response->status(201);
        $this->json($response, $delegation->toArray());
    }

    private function handleOverseerCheck(Response $response): void
    {
        if (!$this->overseer) {
            $response->status(503);
            $this->json($response, ['error' => 'Overseer not initialized']);
            return;
        }
        $report = $this->overseer->runCheck();
        $this->json($response, $report);
    }

    private function json(Response $response, array $data): void
    {
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
