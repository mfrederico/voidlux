<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Mcp;

use Swoole\Http\Request;
use Swoole\Http\Response;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\Swarm\Capabilities\PluginManager;
use VoidLux\Swarm\Git\GitWorkspace;
use VoidLux\Swarm\Gossip\TaskGossipEngine;
use VoidLux\Swarm\Model\MessageModel;
use VoidLux\Swarm\Model\TaskModel;
use VoidLux\Swarm\Model\TaskStatus;
use VoidLux\Swarm\Offer\OfferPayEngine;
use VoidLux\Swarm\Orchestrator\TaskDispatcher;
use VoidLux\Swarm\Orchestrator\TaskQueue;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * JSON-RPC 2.0 handler implementing MCP (Model Context Protocol) for agent task reporting.
 *
 * Agents call task_complete, task_failed, task_progress, and task_needs_input
 * to report structured results back to the swarm.
 */
class McpHandler
{
    private const PROTOCOL_VERSION = '2024-11-05';

    private ?TaskDispatcher $taskDispatcher = null;
    private ?OfferPayEngine $offerPayEngine = null;
    private ?TaskGossipEngine $taskGossip = null;
    private ?LamportClock $clock = null;
    private ?PluginManager $pluginManager = null;
    private GitWorkspace $git;

    /** @var callable|null fn(string $agentId, string $status): void */
    private $onAgentStatusChange = null;

    /** @var callable|null fn(string $event, array $taskData): void */
    private $onTaskEvent = null;

    public function __construct(
        private readonly TaskQueue $taskQueue,
        private readonly SwarmDatabase $db,
        ?GitWorkspace $git = null,
    ) {
        $this->git = $git ?? new GitWorkspace();
    }

    public function setTaskDispatcher(TaskDispatcher $dispatcher): void
    {
        $this->taskDispatcher = $dispatcher;
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

    public function setPluginManager(PluginManager $manager): void
    {
        $this->pluginManager = $manager;
    }

    public function onAgentStatusChange(callable $callback): void
    {
        $this->onAgentStatusChange = $callback;
    }

    public function onTaskEvent(callable $callback): void
    {
        $this->onTaskEvent = $callback;
    }

    public function handle(Request $request, Response $response): void
    {
        $response->header('Content-Type', 'application/json');

        $raw = $request->getContent();
        $rpc = json_decode($raw, true);

        if ($rpc === null) {
            $this->sendError($response, null, -32700, 'Parse error');
            return;
        }

        if (empty($rpc['method']) || ($rpc['jsonrpc'] ?? '') !== '2.0') {
            $this->sendError($response, $rpc['id'] ?? null, -32600, 'Invalid Request');
            return;
        }

        $method = $rpc['method'];
        $params = $rpc['params'] ?? [];
        $id = $rpc['id'] ?? null;

        // Notifications (no id) get empty response
        if ($id === null) {
            $response->end('');
            return;
        }

        $result = match ($method) {
            'initialize' => $this->handleInitialize(),
            'tools/list' => $this->handleToolsList(),
            'tools/call' => $this->handleToolsCall($params),
            default => null,
        };

        if ($result === null) {
            $this->sendError($response, $id, -32601, "Method not found: {$method}");
            return;
        }

        $this->sendResult($response, $id, $result);
    }

    private function handleInitialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => (object) ['tools' => (object) []],
            'serverInfo' => (object) [
                'name' => 'voidlux-swarm',
                'version' => '1.0.0',
            ],
        ];
    }

    private function handleToolsList(): array
    {
        $tools = $this->getCoreTools();

        // Merge plugin tools
        if ($this->pluginManager) {
            foreach ($this->pluginManager->getAllPlugins() as $plugin) {
                if ($plugin instanceof \VoidLux\Swarm\Capabilities\McpToolProvider) {
                    $tools = array_merge($tools, $plugin->getTools());
                }
            }
        }

        return [
            'tools' => $tools,
        ];
    }

    private function getCoreTools(): array
    {
        return [
                (object) [
                    'name' => 'task_complete',
                    'description' => 'Mark a swarm task as completed with a summary of what was accomplished.',
                    'inputSchema' => (object) [
                        'type' => 'object',
                        'properties' => (object) [
                            'task_id' => (object) ['type' => 'string', 'description' => 'The task ID to complete'],
                            'summary' => (object) ['type' => 'string', 'description' => 'Summary of what was accomplished'],
                        ],
                        'required' => ['task_id', 'summary'],
                    ],
                ],
                (object) [
                    'name' => 'task_progress',
                    'description' => 'Report progress on a swarm task.',
                    'inputSchema' => (object) [
                        'type' => 'object',
                        'properties' => (object) [
                            'task_id' => (object) ['type' => 'string', 'description' => 'The task ID'],
                            'message' => (object) ['type' => 'string', 'description' => 'Progress message'],
                        ],
                        'required' => ['task_id', 'message'],
                    ],
                ],
                (object) [
                    'name' => 'task_failed',
                    'description' => 'Mark a swarm task as failed with an error description.',
                    'inputSchema' => (object) [
                        'type' => 'object',
                        'properties' => (object) [
                            'task_id' => (object) ['type' => 'string', 'description' => 'The task ID'],
                            'error' => (object) ['type' => 'string', 'description' => 'Error description'],
                        ],
                        'required' => ['task_id', 'error'],
                    ],
                ],
                (object) [
                    'name' => 'task_needs_input',
                    'description' => 'Signal that a task needs human input or clarification before proceeding.',
                    'inputSchema' => (object) [
                        'type' => 'object',
                        'properties' => (object) [
                            'task_id' => (object) ['type' => 'string', 'description' => 'The task ID'],
                            'question' => (object) ['type' => 'string', 'description' => 'What input or clarification is needed'],
                        ],
                        'required' => ['task_id', 'question'],
                    ],
                ],
                (object) [
                    'name' => 'task_plan',
                    'description' => 'Submit subtask decomposition for a planning task. Called by the planner agent after analyzing the codebase.',
                    'inputSchema' => (object) [
                        'type' => 'object',
                        'properties' => (object) [
                            'task_id' => (object) ['type' => 'string', 'description' => 'The parent task ID being decomposed'],
                            'subtasks' => (object) [
                                'type' => 'array',
                                'description' => 'Array of subtask definitions',
                                'items' => (object) [
                                    'type' => 'object',
                                    'properties' => (object) [
                                        'id' => (object) ['type' => 'string', 'description' => 'Local subtask ID (e.g. subtask-1)'],
                                        'title' => (object) ['type' => 'string', 'description' => 'Short imperative title'],
                                        'description' => (object) ['type' => 'string', 'description' => 'What this subtask accomplishes'],
                                        'work_instructions' => (object) ['type' => 'string', 'description' => 'Specific files, approach, code patterns'],
                                        'acceptance_criteria' => (object) ['type' => 'string', 'description' => 'How to verify correctness'],
                                        'complexity' => (object) ['type' => 'string', 'description' => 'small|medium|large|xl'],
                                        'priority' => (object) ['type' => 'integer', 'description' => 'Higher = more important'],
                                        'dependsOn' => (object) ['type' => 'array', 'items' => (object) ['type' => 'string'], 'description' => 'IDs of subtasks that must complete first'],
                                    ],
                                    'required' => ['id', 'title'],
                                ],
                            ],
                        ],
                        'required' => ['task_id', 'subtasks'],
                    ],
                ],
                (object) [
                    'name' => 'agent_ready',
                    'description' => 'Signal that this agent has started and is ready to receive tasks. Call this when you first start up.',
                    'inputSchema' => (object) [
                        'type' => 'object',
                        'properties' => (object) [
                            'agent_name' => (object) ['type' => 'string', 'description' => 'Your agent name (e.g. agent-62dec4-1)'],
                        ],
                        'required' => ['agent_name'],
                    ],
                ],
                (object) [
                    'name' => 'plugin_install',
                    'description' => 'Install dependencies for a plugin. Use this when a plugin is marked as unavailable and you need to install its requirements.',
                    'inputSchema' => (object) [
                        'type' => 'object',
                        'properties' => (object) [
                            'plugin_name' => (object) ['type' => 'string', 'description' => 'Plugin name to install (e.g. "browser")'],
                        ],
                        'required' => ['plugin_name'],
                    ],
                ],
                (object) [
                    'name' => 'offer_create',
                    'description' => 'Create an offer to pay another node for services (e.g. task execution).',
                    'inputSchema' => (object) [
                        'type' => 'object',
                        'properties' => (object) [
                            'to_node_id' => (object) ['type' => 'string', 'description' => 'Target node ID to send the offer to'],
                            'amount' => (object) ['type' => 'integer', 'description' => 'Payment amount in the specified currency'],
                            'conditions' => (object) ['type' => 'string', 'description' => 'Conditions for offer acceptance (optional)'],
                            'currency' => (object) ['type' => 'string', 'description' => 'Currency (default: VOID)'],
                            'validity_seconds' => (object) ['type' => 'integer', 'description' => 'Seconds until offer expires (default: 300)'],
                            'task_id' => (object) ['type' => 'string', 'description' => 'Associated task ID (optional)'],
                        ],
                        'required' => ['to_node_id', 'amount'],
                    ],
                ],
                (object) [
                    'name' => 'offer_accept',
                    'description' => 'Accept a received offer.',
                    'inputSchema' => (object) [
                        'type' => 'object',
                        'properties' => (object) [
                            'offer_id' => (object) ['type' => 'string', 'description' => 'The offer ID to accept'],
                            'reason' => (object) ['type' => 'string', 'description' => 'Reason for acceptance (optional)'],
                        ],
                        'required' => ['offer_id'],
                    ],
                ],
                (object) [
                    'name' => 'offer_reject',
                    'description' => 'Reject a received offer.',
                    'inputSchema' => (object) [
                        'type' => 'object',
                        'properties' => (object) [
                            'offer_id' => (object) ['type' => 'string', 'description' => 'The offer ID to reject'],
                            'reason' => (object) ['type' => 'string', 'description' => 'Reason for rejection (optional)'],
                        ],
                        'required' => ['offer_id'],
                    ],
                ],
                (object) [
                    'name' => 'payment_initiate',
                    'description' => 'Initiate payment for an accepted offer.',
                    'inputSchema' => (object) [
                        'type' => 'object',
                        'properties' => (object) [
                            'offer_id' => (object) ['type' => 'string', 'description' => 'The accepted offer ID to pay for'],
                        ],
                        'required' => ['offer_id'],
                    ],
                ],
                (object) [
                    'name' => 'payment_confirm',
                    'description' => 'Confirm receipt of a payment.',
                    'inputSchema' => (object) [
                        'type' => 'object',
                        'properties' => (object) [
                            'payment_id' => (object) ['type' => 'string', 'description' => 'The payment ID to confirm'],
                        ],
                        'required' => ['payment_id'],
                    ],
                ],
                (object) [
                    'name' => 'post_message',
                    'description' => 'Post a message to the swarm message board. Categories: task, idea, bounty, announcement, discussion.',
                    'inputSchema' => (object) [
                        'type' => 'object',
                        'properties' => (object) [
                            'title' => (object) ['type' => 'string', 'description' => 'Message title'],
                            'content' => (object) ['type' => 'string', 'description' => 'Message content/body'],
                            'category' => (object) ['type' => 'string', 'description' => 'Category: task, idea, bounty, announcement, discussion (default: discussion)'],
                            'priority' => (object) ['type' => 'integer', 'description' => 'Priority 0-10 (default: 0)'],
                            'tags' => (object) ['type' => 'array', 'items' => (object) ['type' => 'string'], 'description' => 'Tags for categorization'],
                            'author_name' => (object) ['type' => 'string', 'description' => 'Your display name'],
                        ],
                        'required' => ['title', 'content'],
                    ],
                ],
                (object) [
                    'name' => 'list_messages',
                    'description' => 'List messages from the swarm message board. Optionally filter by category or status.',
                    'inputSchema' => (object) [
                        'type' => 'object',
                        'properties' => (object) [
                            'category' => (object) ['type' => 'string', 'description' => 'Filter by category: task, idea, bounty, announcement, discussion'],
                            'status' => (object) ['type' => 'string', 'description' => 'Filter by status: active, claimed, resolved, archived'],
                        ],
                        'required' => [],
                    ],
                ],
                (object) [
                    'name' => 'claim_bounty',
                    'description' => 'Claim a bounty or task posting on the message board.',
                    'inputSchema' => (object) [
                        'type' => 'object',
                        'properties' => (object) [
                            'message_id' => (object) ['type' => 'string', 'description' => 'The board message ID to claim'],
                            'agent_name' => (object) ['type' => 'string', 'description' => 'Your agent name'],
                        ],
                        'required' => ['message_id', 'agent_name'],
                    ],
                ],
        ];
    }

    private function handleToolsCall(array $params): ?array
    {
        $toolName = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        // Try core tools first
        $coreResult = $this->handleCoreTools($toolName, $args);
        if ($coreResult !== null) {
            return $coreResult;
        }

        // Try plugin tools
        if ($this->pluginManager) {
            $agentId = $this->extractAgentIdFromArgs($args);
            if ($agentId) {
                $pluginResult = $this->pluginManager->handleToolCall($toolName, $args, $agentId);
                if ($pluginResult !== null) {
                    return $pluginResult;
                }
            }
        }

        // Unknown tool
        return ['content' => [['type' => 'text', 'text' => json_encode([
            'error' => "Unknown tool: {$toolName}",
        ])]], 'isError' => true];
    }

    private function handleCoreTools(string $toolName, array $args): ?array
    {
        return match ($toolName) {
            'task_complete' => $this->callTaskComplete($args),
            'task_progress' => $this->callTaskProgress($args),
            'task_failed' => $this->callTaskFailed($args),
            'task_needs_input' => $this->callTaskNeedsInput($args),
            'task_plan' => $this->callTaskPlan($args),
            'agent_ready' => $this->callAgentReady($args),
            'plugin_install' => $this->callPluginInstall($args),
            'offer_create' => $this->callOfferCreate($args),
            'offer_accept' => $this->callOfferAccept($args),
            'offer_reject' => $this->callOfferReject($args),
            'payment_initiate' => $this->callPaymentInitiate($args),
            'payment_confirm' => $this->callPaymentConfirm($args),
            'post_message' => $this->callPostMessage($args),
            'list_messages' => $this->callListMessages($args),
            'claim_bounty' => $this->callClaimBounty($args),
            default => null,
        };
    }

    private function extractAgentIdFromArgs(array $args): ?string
    {
        // Try to extract agent ID from common argument names
        return $args['agent_name'] ?? $args['agent_id'] ?? null;
    }

    private function callTaskComplete(array $args): array
    {
        $taskId = $args['task_id'] ?? '';
        $summary = $args['summary'] ?? '';

        if (!$taskId || !$summary) {
            return $this->toolError('task_id and summary are required');
        }

        $task = $this->db->getTask($taskId);
        if (!$task) {
            return $this->toolError("Task not found: {$taskId}");
        }
        if ($task->status->isTerminal()) {
            return $this->toolError("Task already in terminal state: {$task->status->value}");
        }
        // Enhanced state validation: expected working states vs stale/unexpected
        $stateWarning = $this->validateCompletionState($task);

        $agentId = $task->assignedTo ?? '';

        // Git: commit and push branch (no PR — integration PR created by merge loop)
        if ($agentId) {
            $agent = $this->db->getAgent($agentId);
            if ($agent && $agent->projectPath && $this->git->isGitRepo($agent->projectPath)) {
                $branchName = $task->gitBranch ?: ('task/' . substr($task->id, 0, 8));
                $commitMsg = "Task: {$task->title}\n\n{$summary}";
                $this->git->commitAndPush($agent->projectPath, $commitMsg, $branchName);
            }
        }

        $completed = $this->taskQueue->complete($taskId, $agentId, $summary);

        if ($agentId) {
            $this->db->updateAgentStatus($agentId, 'idle', null);
            if ($this->onAgentStatusChange) {
                ($this->onAgentStatusChange)($agentId, 'idle');
            }
        }

        // Trigger dispatch so idle agent gets next pending task immediately
        $this->taskDispatcher?->triggerDispatch();

        $result = ['status' => 'completed', 'task_id' => $taskId];

        if (!$completed) {
            $result['warning'] = 'Task completion was not processed — task may have reached terminal state concurrently';
            $this->log("task_complete for {$taskId}: TaskQueue rejected completion (concurrent terminal transition)");
        } elseif ($stateWarning) {
            $result['warning'] = $stateWarning;
        }

        return $this->toolResult($result);
    }

    /**
     * Validate task state for completion. Returns a warning string if the state
     * was unexpected, or null if the state is normal.
     * Accepts completions for ANY non-terminal state to prevent data loss from
     * stale emperor state, but logs warnings for states where no agent should
     * have been working.
     */
    private function validateCompletionState(TaskModel $task): ?string
    {
        $expectedWorkingStates = [
            TaskStatus::Claimed,
            TaskStatus::InProgress,
            TaskStatus::WaitingInput,
            TaskStatus::PendingReview,
        ];

        if (in_array($task->status, $expectedWorkingStates, true)) {
            return null; // Normal path
        }

        // Task is in a non-working, non-terminal state (pending, blocked, planning, merging)
        $warning = "Task was in state '{$task->status->value}'";
        if ($task->assignedTo) {
            $warning .= " with agent {$task->assignedTo} — accepted (possible stale state)";
            $this->log("WARN: task_complete for {$task->id} in unexpected state '{$task->status->value}'"
                . " with agent {$task->assignedTo} — accepting (stale state recovery)");
        } else {
            $warning .= " with no agent assigned — accepted to prevent data loss";
            $this->log("WARN: task_complete for {$task->id} in state '{$task->status->value}'"
                . " with NO agent assigned — accepting to prevent data loss");
        }

        return $warning;
    }

    private function callTaskProgress(array $args): array
    {
        $taskId = $args['task_id'] ?? '';
        $message = $args['message'] ?? '';

        if (!$taskId || !$message) {
            return $this->toolError('task_id and message are required');
        }

        $task = $this->db->getTask($taskId);
        if (!$task) {
            return $this->toolError("Task not found: {$taskId}");
        }
        if ($task->status->isTerminal()) {
            return $this->toolError("Task already in terminal state: {$task->status->value}");
        }
        // Accept progress from any non-terminal state (gossip race: emperor may have stale state)

        $agentId = $task->assignedTo ?? '';
        $this->taskQueue->updateProgress($taskId, $agentId, $message);

        return $this->toolResult(['status' => 'progress_updated', 'task_id' => $taskId]);
    }

    private function callTaskFailed(array $args): array
    {
        $taskId = $args['task_id'] ?? '';
        $error = $args['error'] ?? '';

        if (!$taskId || !$error) {
            return $this->toolError('task_id and error are required');
        }

        $task = $this->db->getTask($taskId);
        if (!$task) {
            return $this->toolError("Task not found: {$taskId}");
        }
        if ($task->status->isTerminal()) {
            return $this->toolError("Task already in terminal state: {$task->status->value}");
        }
        // Enhanced state validation (same pattern as task_complete)
        $stateWarning = $this->validateCompletionState($task);

        $agentId = $task->assignedTo ?? '';

        // Git: reset workspace to default branch (don't push failed work)
        if ($agentId) {
            $agent = $this->db->getAgent($agentId);
            if ($agent && $agent->projectPath && $this->git->isGitRepo($agent->projectPath)) {
                $this->git->resetToDefault($agent->projectPath);
            }
        }

        $failed = $this->taskQueue->fail($taskId, $agentId, $error);

        if ($agentId) {
            $this->db->updateAgentStatus($agentId, 'idle', null);
            if ($this->onAgentStatusChange) {
                ($this->onAgentStatusChange)($agentId, 'idle');
            }
        }

        // Trigger dispatch so idle agent gets next pending task immediately
        $this->taskDispatcher?->triggerDispatch();

        $result = ['status' => 'failed', 'task_id' => $taskId];

        if (!$failed) {
            $result['warning'] = 'Task failure was not processed — task may have reached terminal state concurrently';
            $this->log("task_failed for {$taskId}: TaskQueue rejected failure (concurrent terminal transition)");
        } elseif ($stateWarning) {
            $result['warning'] = $stateWarning;
        }

        return $this->toolResult($result);
    }

    private function callTaskNeedsInput(array $args): array
    {
        $taskId = $args['task_id'] ?? '';
        $question = $args['question'] ?? '';

        if (!$taskId || !$question) {
            return $this->toolError('task_id and question are required');
        }

        $task = $this->db->getTask($taskId);
        if (!$task) {
            return $this->toolError("Task not found: {$taskId}");
        }
        if ($task->status->isTerminal()) {
            return $this->toolError("Task already in terminal state: {$task->status->value}");
        }
        // Accept input requests from any non-terminal state (gossip race: emperor may have stale state)

        $agentId = $task->assignedTo ?? '';
        $this->taskQueue->setWaitingInput($taskId, $agentId, $question);

        if ($agentId) {
            $this->db->updateAgentStatus($agentId, 'waiting', $taskId);
        }

        return $this->toolResult(['status' => 'waiting_input', 'task_id' => $taskId]);
    }

    private function callAgentReady(array $args): array
    {
        $agentName = $args['agent_name'] ?? '';
        if (!$agentName) {
            return $this->toolError('agent_name is required');
        }

        $agent = $this->db->getAgentByName($agentName);
        if (!$agent) {
            // Agent registered on a worker node may not have gossipped to emperor yet.
            // Return success — AgentMonitor will flip status when it detects idle.
            return $this->toolResult(['status' => 'ready', 'agent_name' => $agentName, 'note' => 'agent not yet synced']);
        }

        if ($agent->status === 'starting') {
            $this->db->updateAgentStatus($agent->id, 'idle', null);
            if ($this->onAgentStatusChange) {
                ($this->onAgentStatusChange)($agent->id, 'idle');
            }
            $this->taskDispatcher?->triggerDispatch();
        }

        return $this->toolResult(['status' => 'ready', 'agent_name' => $agentName]);
    }

    private function callPluginInstall(array $args): array
    {
        $pluginName = $args['plugin_name'] ?? '';
        if (!$pluginName) {
            return $this->toolError('plugin_name is required');
        }

        if (!$this->pluginManager) {
            return $this->toolError('Plugin system not initialized');
        }

        try {
            $plugin = $this->pluginManager->getPlugin($pluginName);
        } catch (\Exception $e) {
            return $this->toolError("Plugin not found: {$pluginName}");
        }

        // Check if already available
        if ($plugin->checkAvailability()) {
            return $this->toolResult([
                'status' => 'already_installed',
                'plugin' => $pluginName,
                'message' => 'Plugin dependencies already available',
            ]);
        }

        // Run plugin installation
        $result = $plugin->install();

        if ($result['success']) {
            return $this->toolResult([
                'status' => 'installed',
                'plugin' => $pluginName,
                'message' => $result['message'],
            ]);
        } else {
            return $this->toolError($result['message']);
        }
    }

    /**
     * Handle task_plan: planner agent submits subtask decomposition.
     * Creates subtasks with two-pass dependency resolution, sets parent to InProgress.
     */
    private function callTaskPlan(array $args): array
    {
        $taskId = $args['task_id'] ?? '';
        $subtaskDefs = $args['subtasks'] ?? [];

        if (!$taskId || empty($subtaskDefs)) {
            return $this->toolError('task_id and non-empty subtasks array are required');
        }

        $parentTask = $this->db->getTask($taskId);
        if (!$parentTask) {
            return $this->toolError("Parent task not found: {$taskId}");
        }

        // Accept planning from Planning (unassigned) or InProgress/Claimed (assigned to planner)
        $planningStates = [TaskStatus::Planning, TaskStatus::InProgress, TaskStatus::Claimed];
        if (!in_array($parentTask->status, $planningStates, true)) {
            return $this->toolError("Task is not in a plannable state: {$parentTask->status->value}");
        }

        $this->log("task_plan: received " . count($subtaskDefs) . " subtask(s) for '{$parentTask->title}'");

        // Two-pass subtask creation (same logic as EmperorController)
        $idMap = []; // localId => realUUID
        $subtasks = [];

        // Pass 1: Create all subtasks without dependencies
        foreach ($subtaskDefs as $def) {
            if (empty($def['title'])) {
                continue;
            }

            $complexity = (string) ($def['complexity'] ?? 'medium');
            if (!in_array($complexity, ['small', 'medium', 'large', 'xl'], true)) {
                $complexity = 'medium';
            }

            $subtask = $this->taskQueue->createTask(
                title: (string) $def['title'],
                description: (string) ($def['description'] ?? ''),
                priority: (int) ($def['priority'] ?? 0),
                requiredCapabilities: (array) ($def['requiredCapabilities'] ?? $def['required_capabilities'] ?? []),
                projectPath: $parentTask->projectPath,
                context: $parentTask->context,
                createdBy: $parentTask->createdBy,
                parentId: $parentTask->id,
                workInstructions: (string) ($def['work_instructions'] ?? ''),
                acceptanceCriteria: (string) ($def['acceptance_criteria'] ?? ''),
                complexity: $complexity,
            );

            $localId = (string) ($def['id'] ?? '');
            if ($localId !== '') {
                $idMap[$localId] = $subtask->id;
            }
            $subtasks[] = ['task' => $subtask, 'def' => $def];
        }

        if (empty($subtasks)) {
            return $this->toolError('No valid subtasks provided');
        }

        // Pass 2: Resolve local dependency IDs to real UUIDs and update blocked tasks
        foreach ($subtasks as $item) {
            $def = $item['def'];
            $subtask = $item['task'];
            $localDeps = $def['dependsOn'] ?? $def['depends_on'] ?? [];

            if (!empty($localDeps)) {
                $resolvedDeps = [];
                foreach ($localDeps as $localDepId) {
                    if (isset($idMap[$localDepId])) {
                        $resolvedDeps[] = $idMap[$localDepId];
                    }
                }
                if (!empty($resolvedDeps)) {
                    $ts = $subtask->lamportTs;
                    $blocked = new TaskModel(
                        id: $subtask->id, title: $subtask->title, description: $subtask->description,
                        status: TaskStatus::Blocked, priority: $subtask->priority,
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
                    $this->db->updateTask($blocked);
                    $subtask = $blocked;
                }
            }

            // Push subtask to WS
            if ($this->onTaskEvent) {
                ($this->onTaskEvent)('task_created', $subtask->toArray());
            }
        }

        // Mark parent as in_progress (subtasks being worked on)
        $updated = $parentTask->withStatus(TaskStatus::InProgress, $parentTask->lamportTs);
        $this->db->updateTask($updated);
        if ($this->onTaskEvent) {
            ($this->onTaskEvent)('task_updated', $updated->toArray());
        }

        // Mark planner agent as idle
        $agentId = $parentTask->assignedTo ?? '';
        if ($agentId) {
            $this->db->updateAgentStatus($agentId, 'idle', null);
            if ($this->onAgentStatusChange) {
                ($this->onAgentStatusChange)($agentId, 'idle');
            }
        }

        // Trigger dispatch for new subtasks
        $this->taskDispatcher?->triggerDispatch();

        $this->log("task_plan: created " . count($subtasks) . " subtask(s), parent '{$parentTask->title}' → in_progress");

        return $this->toolResult([
            'status' => 'planned',
            'parent_task_id' => $taskId,
            'subtask_count' => count($subtasks),
            'subtask_ids' => array_map(fn($s) => $s['task']->id, $subtasks),
        ]);
    }

    private function callOfferCreate(array $args): array
    {
        if (!$this->offerPayEngine) {
            return $this->toolError('Offer-Pay engine not initialized');
        }
        $toNodeId = $args['to_node_id'] ?? '';
        $amount = (int) ($args['amount'] ?? 0);
        if (!$toNodeId || $amount <= 0) {
            return $this->toolError('to_node_id and positive amount are required');
        }

        $result = $this->offerPayEngine->createOffer(
            toNodeId: $toNodeId,
            amount: $amount,
            conditions: $args['conditions'] ?? '',
            currency: $args['currency'] ?? 'VOID',
            validitySeconds: (int) ($args['validity_seconds'] ?? 300),
            taskId: $args['task_id'] ?? null,
        );

        if (is_string($result)) {
            return $this->toolError($result);
        }
        return $this->toolResult(['status' => 'offer_created', 'offer_id' => $result->id, 'offer' => $result->toArray()]);
    }

    private function callOfferAccept(array $args): array
    {
        if (!$this->offerPayEngine) {
            return $this->toolError('Offer-Pay engine not initialized');
        }
        $offerId = $args['offer_id'] ?? '';
        if (!$offerId) {
            return $this->toolError('offer_id is required');
        }
        $result = $this->offerPayEngine->acceptOffer($offerId, $args['reason'] ?? null);
        if (is_string($result)) {
            return $this->toolError($result);
        }
        return $this->toolResult(['status' => 'offer_accepted', 'offer' => $result->toArray()]);
    }

    private function callOfferReject(array $args): array
    {
        if (!$this->offerPayEngine) {
            return $this->toolError('Offer-Pay engine not initialized');
        }
        $offerId = $args['offer_id'] ?? '';
        if (!$offerId) {
            return $this->toolError('offer_id is required');
        }
        $result = $this->offerPayEngine->rejectOffer($offerId, $args['reason'] ?? null);
        if (is_string($result)) {
            return $this->toolError($result);
        }
        return $this->toolResult(['status' => 'offer_rejected', 'offer' => $result->toArray()]);
    }

    private function callPaymentInitiate(array $args): array
    {
        if (!$this->offerPayEngine) {
            return $this->toolError('Offer-Pay engine not initialized');
        }
        $offerId = $args['offer_id'] ?? '';
        if (!$offerId) {
            return $this->toolError('offer_id is required');
        }
        $result = $this->offerPayEngine->initiatePayment($offerId);
        if (is_string($result)) {
            return $this->toolError($result);
        }
        return $this->toolResult(['status' => 'payment_initiated', 'payment' => $result->toArray()]);
    }

    private function callPaymentConfirm(array $args): array
    {
        if (!$this->offerPayEngine) {
            return $this->toolError('Offer-Pay engine not initialized');
        }
        $paymentId = $args['payment_id'] ?? '';
        if (!$paymentId) {
            return $this->toolError('payment_id is required');
        }
        $result = $this->offerPayEngine->confirmPayment($paymentId);
        if (is_string($result)) {
            return $this->toolError($result);
        }
        return $this->toolResult(['status' => 'payment_confirmed', 'payment' => $result->toArray()]);
    }

    private function callPostMessage(array $args): array
    {
        $title = $args['title'] ?? '';
        $content = $args['content'] ?? '';

        if (!$title || !$content) {
            return $this->toolError('title and content are required');
        }

        if (!$this->taskGossip || !$this->clock) {
            return $this->toolError('Message board not initialized');
        }

        $msg = MessageModel::create(
            authorId: $args['author_id'] ?? 'agent',
            authorName: $args['author_name'] ?? 'Agent',
            category: $args['category'] ?? 'discussion',
            title: $title,
            content: $content,
            lamportTs: $this->clock->tick(),
            priority: (int) ($args['priority'] ?? 0),
            tags: $args['tags'] ?? [],
        );

        $this->taskGossip->createBoardMessage($msg);

        return $this->toolResult([
            'status' => 'posted',
            'message_id' => $msg->id,
            'message' => $msg->toArray(),
        ]);
    }

    private function callListMessages(array $args): array
    {
        $category = $args['category'] ?? null;
        $status = $args['status'] ?? null;

        $messages = $this->db->getMessages($category, $status);
        $result = array_map(fn($m) => $m->toArray(), $messages);

        return $this->toolResult([
            'count' => count($result),
            'messages' => $result,
        ]);
    }

    private function callClaimBounty(array $args): array
    {
        $messageId = $args['message_id'] ?? '';
        $agentName = $args['agent_name'] ?? '';

        if (!$messageId || !$agentName) {
            return $this->toolError('message_id and agent_name are required');
        }

        if (!$this->taskGossip || !$this->clock) {
            return $this->toolError('Message board not initialized');
        }

        $msg = $this->db->getMessage($messageId);
        if (!$msg) {
            return $this->toolError("Message not found: {$messageId}");
        }
        if ($msg->status !== 'active') {
            return $this->toolError("Cannot claim message in status: {$msg->status}");
        }

        $agent = $this->db->getAgentByName($agentName);
        $claimerId = $agent ? $agent->id : $agentName;

        $updated = $msg->withClaimedBy($claimerId, $this->clock->tick());
        $this->taskGossip->gossipBoardUpdate($updated);

        return $this->toolResult([
            'status' => 'claimed',
            'message_id' => $messageId,
            'claimed_by' => $claimerId,
        ]);
    }

    private function toolResult(array $data): array
    {
        return ['content' => [(object) ['type' => 'text', 'text' => json_encode($data)]]];
    }

    private function toolError(string $message): array
    {
        return ['content' => [(object) ['type' => 'text', 'text' => json_encode(['error' => $message])]], 'isError' => true];
    }

    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}][mcp] {$message}\n";
    }

    private function sendResult(Response $response, mixed $id, array $result): void
    {
        $response->end(json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function sendError(Response $response, mixed $id, int $code, string $message): void
    {
        $response->end(json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => (object) ['code' => $code, 'message' => $message],
        ], JSON_UNESCAPED_SLASHES));
    }
}
