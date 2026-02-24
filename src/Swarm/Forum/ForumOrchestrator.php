<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Forum;

use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\Swarm\Agent\AgentBridge;
use VoidLux\Swarm\Gossip\TaskGossipEngine;
use VoidLux\Swarm\Model\AgentModel;
use VoidLux\Swarm\Model\MessageModel;
use VoidLux\Swarm\Model\TaskModel;
use VoidLux\Swarm\Model\TaskStatus;
use VoidLux\Swarm\Storage\SwarmDatabase;

class ForumOrchestrator
{
    /** @var array<string, int> channelId => last notified lamport ts */
    private array $lastNotifiedTs = [];

    /** @var array<string, float> channelId => discussion start time (microtime) */
    private array $discussionStartTimes = [];

    /** @var callable|null fn(string $event, array $data): void */
    private $onTaskEvent = null;

    private int $discussionTimeoutSeconds = 300; // 5 min default

    private int $voteCheckIntervalSeconds = 10;

    private int $notificationIntervalSeconds = 15;

    /** @var array<string, array> Loaded persona definitions indexed by slug */
    private array $personaDefs = [];

    public function __construct(
        private readonly SwarmDatabase $db,
        private readonly AgentBridge $bridge,
        private readonly TaskGossipEngine $taskGossip,
        private readonly LamportClock $clock,
    ) {}

    public function onTaskEvent(callable $callback): void
    {
        $this->onTaskEvent = $callback;
    }

    public function setDiscussionTimeout(int $seconds): void
    {
        $this->discussionTimeoutSeconds = $seconds;
    }

    /**
     * Load persona definitions from config file.
     */
    public function loadPersonas(string $configPath): void
    {
        if (!file_exists($configPath)) {
            $this->log("personas config not found: {$configPath}");
            return;
        }

        $data = json_decode(file_get_contents($configPath), true);
        if (!is_array($data)) {
            $this->log("invalid personas config: {$configPath}");
            return;
        }

        $this->personaDefs = [];
        foreach ($data as $persona) {
            $slug = $persona['slug'] ?? '';
            if ($slug) {
                $this->personaDefs[$slug] = $persona;
            }
        }

        $this->log("loaded " . count($this->personaDefs) . " persona definitions");
    }

    /**
     * Get a persona definition by slug.
     */
    public function getPersona(string $slug): ?array
    {
        return $this->personaDefs[$slug] ?? null;
    }

    /**
     * Get all loaded persona definitions.
     * @return array<string, array>
     */
    public function getPersonaDefs(): array
    {
        return $this->personaDefs;
    }

    /**
     * Start a discussion for a task. Sets task to Discussing, creates root channel message.
     */
    public function startDiscussion(TaskModel $task): void
    {
        $channelId = $task->id;

        // Create root message with task description
        $msg = MessageModel::create(
            authorId: 'system',
            authorName: 'SwarmTalk',
            category: 'forum',
            title: "Discussion: {$task->title}",
            content: $this->buildRootMessage($task),
            lamportTs: $this->clock->tick(),
            channelId: $channelId,
        );

        $this->taskGossip->createBoardMessage($msg);

        $this->discussionStartTimes[$channelId] = microtime(true);
        $this->lastNotifiedTs[$channelId] = $msg->lamportTs;

        if ($this->onTaskEvent) {
            ($this->onTaskEvent)('forum_channel_created', [
                'channel_id' => $channelId,
                'task_id' => $task->id,
                'task_title' => $task->title,
            ]);
        }

        $this->log("discussion started for task '{$task->title}' in channel {$channelId}");
    }

    /**
     * Deliver discussion prompts to persona agents.
     *
     * @param AgentModel[] $agents Persona agents to notify
     */
    public function deliverDiscussionPrompts(TaskModel $task, array $agents): void
    {
        $channelId = $task->id;

        foreach ($agents as $agent) {
            $prompt = $this->buildDiscussionPrompt($task, $agent);
            $this->bridge->sendText($agent, $prompt);

            // Stagger deliveries to avoid overwhelming agents
            usleep(500_000);
        }

        $this->log("delivered discussion prompts to " . count($agents) . " agents for channel {$channelId}");
    }

    /**
     * Deliver review prompts to persona agents for QA.
     *
     * @param AgentModel[] $agents
     */
    public function deliverReviewPrompts(TaskModel $task, array $agents, string $mergeResult, string $testOutput): void
    {
        $channelId = "review:{$task->id}";

        // Create root review message
        $msg = MessageModel::create(
            authorId: 'system',
            authorName: 'SwarmTalk',
            category: 'review',
            title: "QA Review: {$task->title}",
            content: $this->buildReviewRootMessage($task, $mergeResult, $testOutput),
            lamportTs: $this->clock->tick(),
            channelId: $channelId,
        );

        $this->taskGossip->createBoardMessage($msg);
        $this->discussionStartTimes[$channelId] = microtime(true);

        if ($this->onTaskEvent) {
            ($this->onTaskEvent)('forum_channel_created', [
                'channel_id' => $channelId,
                'task_id' => $task->id,
                'task_title' => $task->title,
                'type' => 'review',
            ]);
        }

        foreach ($agents as $agent) {
            $prompt = $this->buildReviewPrompt($task, $agent, $mergeResult, $testOutput);
            $this->bridge->sendText($agent, $prompt);
            usleep(500_000);
        }

        $this->log("delivered review prompts to " . count($agents) . " agents for channel {$channelId}");
    }

    /**
     * Check vote status for a channel.
     * Returns 'approve' if majority approve, 'reject' if majority reject, null if no majority.
     */
    public function checkVotes(string $channelId): ?string
    {
        $votes = $this->db->getVotesByChannel($channelId);

        // Latest vote per agent wins
        $latestVotes = [];
        foreach ($votes as $v) {
            $latestVotes[$v->authorName] = $v->vote;
        }

        $approve = 0;
        $reject = 0;
        $total = count($latestVotes);

        if ($total === 0) {
            return null;
        }

        foreach ($latestVotes as $vote) {
            if ($vote === 'approve') {
                $approve++;
            } elseif ($vote === 'reject') {
                $reject++;
            }
        }

        $majority = ceil($total / 2);

        if ($approve >= $majority) {
            return 'approve';
        }
        if ($reject >= $majority) {
            return 'reject';
        }

        return null;
    }

    /**
     * Check if a discussion has timed out.
     */
    public function isTimedOut(string $channelId): bool
    {
        $startTime = $this->discussionStartTimes[$channelId] ?? null;
        if ($startTime === null) {
            return false;
        }
        return (microtime(true) - $startTime) >= $this->discussionTimeoutSeconds;
    }

    /**
     * Force advance a discussion (human override).
     */
    public function forceAdvance(string $channelId, string $decision): void
    {
        // Post override message
        $msg = MessageModel::create(
            authorId: 'human',
            authorName: 'Human Operator',
            category: 'forum',
            title: "Override: {$decision}",
            content: "Human operator forced discussion to {$decision}.",
            lamportTs: $this->clock->tick(),
            channelId: $channelId,
        );

        $this->taskGossip->createBoardMessage($msg);

        // Clean up tracking
        unset($this->discussionStartTimes[$channelId], $this->lastNotifiedTs[$channelId]);

        if ($this->onTaskEvent) {
            ($this->onTaskEvent)('forum_channel_resolved', [
                'channel_id' => $channelId,
                'outcome' => $decision,
                'forced' => true,
            ]);
        }

        $this->log("discussion force-advanced: channel={$channelId} decision={$decision}");
    }

    /**
     * Send new-message notifications to agents that haven't seen recent messages.
     * Called periodically (every 15s) by the notification coroutine.
     *
     * @param AgentModel[] $agents
     */
    public function notifyNewMessages(string $channelId, array $agents): void
    {
        $sinceTs = $this->lastNotifiedTs[$channelId] ?? 0;
        $messages = $this->db->getMessagesByChannel($channelId, $sinceTs);

        if (empty($messages)) {
            return;
        }

        $count = count($messages);
        $lastTs = end($messages)->lamportTs;
        $this->lastNotifiedTs[$channelId] = $lastTs;

        // Get channel label from first message or task
        $task = $this->db->getTask($channelId) ?? $this->db->getTask(str_replace('review:', '', $channelId));
        $channelLabel = $task ? $task->title : $channelId;

        foreach ($agents as $agent) {
            if ($agent->status !== 'idle') {
                continue; // Don't interrupt busy agents
            }

            // Check if this agent already posted one of the new messages
            $agentPosted = false;
            foreach ($messages as $m) {
                if ($m->authorName === $agent->name) {
                    $agentPosted = true;
                    break;
                }
            }
            if ($agentPosted) {
                continue; // They already know about their own messages
            }

            $notification = "[SwarmTalk] {$count} new message(s) in channel '{$channelLabel}'. Use forum_list with channel_id=\"{$channelId}\" to read and respond.";
            $this->bridge->sendText($agent, $notification);
            usleep(200_000);
        }
    }

    /**
     * Resolve a discussion channel — clean up tracking state.
     */
    public function resolveChannel(string $channelId, string $outcome): void
    {
        unset($this->discussionStartTimes[$channelId], $this->lastNotifiedTs[$channelId]);

        if ($this->onTaskEvent) {
            ($this->onTaskEvent)('forum_channel_resolved', [
                'channel_id' => $channelId,
                'outcome' => $outcome,
            ]);
        }

        $this->log("channel resolved: {$channelId} outcome={$outcome}");
    }

    /**
     * Get active discussion channel IDs being tracked.
     * @return string[]
     */
    public function getActiveChannels(): array
    {
        return array_keys($this->discussionStartTimes);
    }

    /**
     * Get all persona agents (agents with non-empty persona field).
     * @return AgentModel[]
     */
    public function getPersonaAgents(): array
    {
        $agents = $this->db->getAllAgents();
        return array_filter($agents, fn(AgentModel $a) => $a->persona !== '');
    }

    /**
     * Get idle persona agents.
     * @return AgentModel[]
     */
    public function getIdlePersonaAgents(): array
    {
        return array_filter(
            $this->getPersonaAgents(),
            fn(AgentModel $a) => $a->status === 'idle'
        );
    }

    // --- Prompt Builders ---

    private function buildRootMessage(TaskModel $task): string
    {
        $msg = "# Task Discussion\n\n";
        $msg .= "**Title**: {$task->title}\n";
        if ($task->description) {
            $msg .= "**Description**: {$task->description}\n";
        }
        if ($task->context) {
            $msg .= "**Context**: {$task->context}\n";
        }
        if ($task->projectPath) {
            $msg .= "**Project**: {$task->projectPath}\n";
        }
        $msg .= "\nPlease discuss the approach, share concerns, and vote when ready.\n";
        $msg .= "Use `forum_vote` with channel_id=\"{$task->id}\" to cast your vote.\n";
        return $msg;
    }

    public function buildDiscussionPrompt(TaskModel $task, AgentModel $agent): string
    {
        $persona = $agent->persona ? json_decode($agent->persona, true) : null;
        $personaName = $persona['display_name'] ?? $agent->name;
        $personaTitle = $persona['title'] ?? '';
        $systemPrompt = $persona['system_prompt'] ?? '';

        $prompt = "## SwarmTalk Discussion\n\n";
        if ($systemPrompt) {
            $prompt .= "{$systemPrompt}\n\n";
        }
        $prompt .= "You are participating in a team discussion about an upcoming task.\n\n";
        $prompt .= "### Task\n";
        $prompt .= "**Title**: {$task->title}\n";
        if ($task->description) {
            $prompt .= "**Description**: {$task->description}\n";
        }
        if ($task->context) {
            $prompt .= "**Context**: {$task->context}\n";
        }
        if ($task->projectPath) {
            $prompt .= "**Project**: {$task->projectPath}\n";
        }

        $prompt .= "\n### Instructions\n";
        $prompt .= "1. Read the discussion with `forum_list` (channel_id=\"{$task->id}\")\n";
        $prompt .= "2. Share your perspective using `forum_post` (channel_id=\"{$task->id}\")\n";
        $prompt .= "3. Respond to other team members' points\n";
        $prompt .= "4. When you've formed an opinion, vote with `forum_vote` (channel_id=\"{$task->id}\", vote=\"approve\"/\"reject\"/\"abstain\")\n\n";
        $prompt .= "Focus on your area of expertise as {$personaName}";
        if ($personaTitle) {
            $prompt .= " ({$personaTitle})";
        }
        $prompt .= ". Be concise but substantive.\n";

        return $prompt;
    }

    private function buildReviewRootMessage(TaskModel $task, string $mergeResult, string $testOutput): string
    {
        $msg = "# QA Review\n\n";
        $msg .= "**Task**: {$task->title}\n\n";
        $msg .= "## Merge Result\n```\n{$mergeResult}\n```\n\n";
        if ($testOutput) {
            $msg .= "## Test Output\n```\n{$testOutput}\n```\n\n";
        }
        $msg .= "Please review the implementation and vote approve/reject.\n";
        $msg .= "Use `forum_vote` with channel_id=\"review:{$task->id}\" to cast your vote.\n";
        return $msg;
    }

    public function buildReviewPrompt(TaskModel $task, AgentModel $agent, string $mergeResult, string $testOutput): string
    {
        $persona = $agent->persona ? json_decode($agent->persona, true) : null;
        $personaName = $persona['display_name'] ?? $agent->name;
        $personaTitle = $persona['title'] ?? '';
        $systemPrompt = $persona['system_prompt'] ?? '';

        $prompt = "## SwarmTalk QA Review\n\n";
        if ($systemPrompt) {
            $prompt .= "{$systemPrompt}\n\n";
        }
        $prompt .= "A task has been implemented and needs your review.\n\n";
        $prompt .= "### Task: {$task->title}\n";
        if ($task->description) {
            $prompt .= "**Description**: {$task->description}\n";
        }
        $prompt .= "\n### Instructions\n";
        $prompt .= "1. Read the review channel with `forum_list` (channel_id=\"review:{$task->id}\")\n";
        $prompt .= "2. Review the merge result and test output\n";
        $prompt .= "3. Share your assessment with `forum_post` (channel_id=\"review:{$task->id}\")\n";
        $prompt .= "4. Vote with `forum_vote` (channel_id=\"review:{$task->id}\", vote=\"approve\"/\"reject\")\n\n";
        $prompt .= "Focus on your area of expertise as {$personaName}";
        if ($personaTitle) {
            $prompt .= " ({$personaTitle})";
        }
        $prompt .= ".\n";

        return $prompt;
    }

    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}][forum] {$message}\n";
    }
}
