<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Mcp;

use Swoole\Http\Request;
use Swoole\Http\Response;
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

    /** @var callable|null fn(string $agentId, string $status): void */
    private $onAgentStatusChange = null;

    public function __construct(
        private readonly TaskQueue $taskQueue,
        private readonly SwarmDatabase $db,
    ) {}

    public function setTaskDispatcher(TaskDispatcher $dispatcher): void
    {
        $this->taskDispatcher = $dispatcher;
    }

    public function onAgentStatusChange(callable $callback): void
    {
        $this->onAgentStatusChange = $callback;
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
        return [
            'tools' => [
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
            ],
        ];
    }

    private function handleToolsCall(array $params): ?array
    {
        $toolName = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        return match ($toolName) {
            'task_complete' => $this->callTaskComplete($args),
            'task_progress' => $this->callTaskProgress($args),
            'task_failed' => $this->callTaskFailed($args),
            'task_needs_input' => $this->callTaskNeedsInput($args),
            'agent_ready' => $this->callAgentReady($args),
            default => ['content' => [['type' => 'text', 'text' => json_encode([
                'error' => "Unknown tool: {$toolName}",
            ])]], 'isError' => true],
        };
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

        $agentId = $task->assignedTo ?? '';
        $this->taskQueue->complete($taskId, $agentId, $summary);

        if ($agentId) {
            $this->db->updateAgentStatus($agentId, 'idle', null);
            if ($this->onAgentStatusChange) {
                ($this->onAgentStatusChange)($agentId, 'idle');
            }
        }

        // Trigger dispatch so idle agent gets next pending task immediately
        $this->taskDispatcher?->triggerDispatch();

        return $this->toolResult(['status' => 'completed', 'task_id' => $taskId]);
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

        $agentId = $task->assignedTo ?? '';
        $this->taskQueue->fail($taskId, $agentId, $error);

        if ($agentId) {
            $this->db->updateAgentStatus($agentId, 'idle', null);
            if ($this->onAgentStatusChange) {
                ($this->onAgentStatusChange)($agentId, 'idle');
            }
        }

        // Trigger dispatch so idle agent gets next pending task immediately
        $this->taskDispatcher?->triggerDispatch();

        return $this->toolResult(['status' => 'failed', 'task_id' => $taskId]);
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
            return $this->toolError("Agent not found: {$agentName}");
        }

        if ($agent->status === 'starting') {
            $this->db->updateAgentStatus($agent->id, 'idle', null);
            $this->taskDispatcher?->triggerDispatch();
        }

        return $this->toolResult(['status' => 'ready', 'agent_name' => $agentName]);
    }

    private function toolResult(array $data): array
    {
        return ['content' => [(object) ['type' => 'text', 'text' => json_encode($data)]]];
    }

    private function toolError(string $message): array
    {
        return ['content' => [(object) ['type' => 'text', 'text' => json_encode(['error' => $message])]], 'isError' => true];
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
