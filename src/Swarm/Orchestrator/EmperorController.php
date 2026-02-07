<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Orchestrator;

use Swoole\Http\Request;
use Swoole\Http\Response;
use VoidLux\Swarm\Agent\AgentBridge;
use VoidLux\Swarm\Agent\AgentRegistry;
use VoidLux\Swarm\Storage\SwarmDatabase;
use VoidLux\Swarm\SwarmWebUI;

/**
 * HTTP API controller for the emperor dashboard.
 */
class EmperorController
{
    public function __construct(
        private readonly SwarmDatabase $db,
        private readonly TaskQueue $taskQueue,
        private readonly AgentRegistry $agentRegistry,
        private readonly AgentBridge $bridge,
        private readonly string $nodeId,
        private readonly float $startTime,
    ) {}

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

            case preg_match('#^/api/swarm/tasks/([^/]+)/cancel$#', $path, $m) === 1 && $method === 'POST':
                $this->handleCancelTask($m[1], $response);
                break;

            case preg_match('#^/api/swarm/tasks/([^/]+)$#', $path, $m) === 1 && $method === 'GET':
                $this->handleGetTask($m[1], $response);
                break;

            case $path === '/api/swarm/agents' && $method === 'GET':
                $this->handleListAgents($response);
                break;

            case $path === '/api/swarm/agents' && $method === 'POST':
                $this->handleRegisterAgent($request, $response);
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
                'claimed' => $this->db->getTaskCount('claimed'),
                'in_progress' => $this->db->getTaskCount('in_progress'),
                'completed' => $this->db->getTaskCount('completed'),
                'failed' => $this->db->getTaskCount('failed'),
            ],
            'agents' => [
                'total' => $this->db->getAgentCount(),
            ],
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

        $task = $this->taskQueue->createTask(
            title: $body['title'],
            description: $body['description'] ?? '',
            priority: (int) ($body['priority'] ?? 0),
            requiredCapabilities: $body['required_capabilities'] ?? [],
            projectPath: $body['project_path'] ?? '',
            context: $body['context'] ?? '',
            createdBy: $body['created_by'] ?? $this->nodeId,
        );

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
            capabilities: $body['capabilities'] ?? [],
            tmuxSessionId: $tmuxSession,
            projectPath: $projectPath,
            maxConcurrentTasks: (int) ($body['max_concurrent_tasks'] ?? 1),
        );

        $response->status(201);
        $this->json($response, $agent->toArray());
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

    private function json(Response $response, array $data): void
    {
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
