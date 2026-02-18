<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Mcp;

use Swoole\Http\Request;
use Swoole\Http\Response;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\Swarm\Git\GitWorkspace;
use VoidLux\Swarm\Gossip\TaskGossipEngine;
use VoidLux\Swarm\Model\MessageModel;
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
    private GitWorkspace $git;

    /** @var callable|null fn(string $agentId, string $status): void */
    private $onAgentStatusChange = null;

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
            'offer_create' => $this->callOfferCreate($args),
            'offer_accept' => $this->callOfferAccept($args),
            'offer_reject' => $this->callOfferReject($args),
            'payment_initiate' => $this->callPaymentInitiate($args),
            'payment_confirm' => $this->callPaymentConfirm($args),
            'post_message' => $this->callPostMessage($args),
            'list_messages' => $this->callListMessages($args),
            'claim_bounty' => $this->callClaimBounty($args),
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

        // Git: commit and push branch (no PR — integration PR created by merge loop)
        if ($agentId) {
            $agent = $this->db->getAgent($agentId);
            if ($agent && $agent->projectPath && $this->git->isGitRepo($agent->projectPath)) {
                $branchName = $task->gitBranch ?: ('task/' . substr($task->id, 0, 8));
                $commitMsg = "Task: {$task->title}\n\n{$summary}";
                $this->git->commitAndPush($agent->projectPath, $commitMsg, $branchName);
            }
        }

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

        // Git: reset workspace to default branch (don't push failed work)
        if ($agentId) {
            $agent = $this->db->getAgent($agentId);
            if ($agent && $agent->projectPath && $this->git->isGitRepo($agent->projectPath)) {
                $this->git->resetToDefault($agent->projectPath);
            }
        }

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
