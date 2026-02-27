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
    public const GENERAL_CHANNEL_ID = 'general';

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

    /** @var array<string, float> agentId => last general notification timestamp */
    private array $generalNotifyCooldown = [];

    /** @var array<string, int> agentId => notification count in current window */
    private array $generalNotifyCount = [];

    /** @var array<string, float> agentId => window start timestamp */
    private array $generalNotifyWindowStart = [];

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

    public function getGeneralChannelId(): string
    {
        return self::GENERAL_CHANNEL_ID;
    }

    /**
     * Seed the "general" channel with a welcome message on emperor startup.
     * Idempotent: skips if messages already exist in the channel.
     */
    public function seedGeneralChannel(): void
    {
        $existing = $this->db->getMessagesByChannel(self::GENERAL_CHANNEL_ID);
        if (!empty($existing)) {
            $this->log("general channel already seeded (" . count($existing) . " messages)");
            return;
        }

        $msg = MessageModel::create(
            authorId: 'system',
            authorName: 'SwarmTalk',
            category: 'discussion',
            title: 'Welcome to the General Channel',
            content: "# Welcome to General\n\n"
                . "This is the team's open channel for introductions, ideas, and coordination.\n\n"
                . "- Introduce yourself and your area of expertise\n"
                . "- Share ideas or observations about the codebase\n"
                . "- Coordinate with teammates on upcoming work\n\n"
                . "Use `forum_list` (channel_id=\"general\") to read and `forum_post` (channel_id=\"general\") to participate.",
            lamportTs: $this->clock->tick(),
            channelId: self::GENERAL_CHANNEL_ID,
        );

        $this->taskGossip->createBoardMessage($msg);
        $this->lastNotifiedTs[self::GENERAL_CHANNEL_ID] = $msg->lamportTs;

        if ($this->onTaskEvent) {
            ($this->onTaskEvent)('forum_channel_created', [
                'channel_id' => self::GENERAL_CHANNEL_ID,
                'task_id' => '',
                'task_title' => 'General Channel',
            ]);
        }

        $this->log("seeded general channel with welcome message");
    }

    /**
     * Prompt a persona agent to introduce themselves in the general channel.
     * Spawns a coroutine with a staggered delay so agents don't all post at once.
     */
    public function promptGeneralIntroduction(AgentModel $agent, int $delaySeconds): void
    {
        \Swoole\Coroutine::create(function () use ($agent, $delaySeconds) {
            \Swoole\Coroutine::sleep($delaySeconds);

            // Re-check agent is still idle before prompting
            $freshAgent = $this->db->getAgent($agent->id);
            if (!$freshAgent || $freshAgent->status !== 'idle') {
                $this->log("skipping general intro for {$agent->name} — no longer idle");
                return;
            }

            $persona = $agent->persona ? json_decode($agent->persona, true) : null;
            $displayName = $persona['display_name'] ?? $agent->name;

            $prompt = "## General Channel\n\n";
            $prompt .= "The team has a **general** channel (channel_id=\"general\") where everyone introduces themselves and coordinates.\n\n";
            $prompt .= "1. Read the general channel with `forum_list` (channel_id=\"general\")\n";
            $prompt .= "2. Introduce yourself as {$displayName} — share what you focus on and what you bring to the team\n";
            $prompt .= "3. If others have already posted, respond to their introductions too\n\n";
            $prompt .= "Use `forum_post` with channel_id=\"general\" to post.\n";

            $this->bridge->sendText($freshAgent, $prompt);
            $this->log("prompted {$agent->name} to introduce themselves in general (delay={$delaySeconds}s)");
        });
    }

    /**
     * Send enriched notifications for the general channel with anti-loop protection.
     * Uses per-agent read pointers from the DB so each agent only gets notified
     * about messages they haven't personally read via forum_list.
     *
     * Anti-loop protection:
     * - 60s cooldown per agent
     * - 3 notifications per 10min per agent
     * - Skip agent's own messages
     *
     * @param AgentModel[] $agents
     */
    public function notifyGeneralChannel(array $agents): void
    {
        $now = microtime(true);
        $anyDelivered = false;

        foreach ($agents as $agent) {
            if ($agent->status !== 'idle') {
                continue;
            }

            // Per-agent read pointer from DB
            $agentReadTs = $this->db->getLastReadTs($agent->id, self::GENERAL_CHANNEL_ID);
            $messages = $this->db->getMessagesByChannel(self::GENERAL_CHANNEL_ID, $agentReadTs);

            if (empty($messages)) {
                continue;
            }

            // Skip own messages — don't notify agent about their own posts
            $otherMessages = array_filter($messages, fn($m) => $m->authorName !== $agent->name);
            if (empty($otherMessages)) {
                continue;
            }

            // 60s cooldown per agent
            $lastNotified = $this->generalNotifyCooldown[$agent->id] ?? 0.0;
            if (($now - $lastNotified) < 60.0) {
                continue;
            }

            // 3 per 10min rate limit
            $windowStart = $this->generalNotifyWindowStart[$agent->id] ?? 0.0;
            if (($now - $windowStart) > 600.0) {
                $this->generalNotifyWindowStart[$agent->id] = $now;
                $this->generalNotifyCount[$agent->id] = 0;
            }
            $count = $this->generalNotifyCount[$agent->id] ?? 0;
            if ($count >= 3) {
                continue;
            }

            // Build content-aware nudge from the most recent other message
            $latest = end($otherMessages);
            $preview = mb_substr($latest->content, 0, 80);
            if (mb_strlen($latest->content) > 80) {
                $preview .= '...';
            }

            $notification = "[SwarmTalk General] {$latest->authorName} posted: \"{$preview}\" — Read and respond with forum_list/forum_post (channel_id=\"general\").";
            $this->log("general: notifying {$agent->name} (readTs={$agentReadTs}, latest by {$latest->authorName})");
            $this->bridge->sendText($agent, $notification);

            $this->generalNotifyCooldown[$agent->id] = $now;
            $this->generalNotifyCount[$agent->id] = $count + 1;
            $anyDelivered = true;
            usleep(200_000);
        }

        if (!$anyDelivered) {
            $this->log("general: no agents notified (all busy/cooldown/rate-limited/caught-up)");
        }
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
     * Uses per-agent read pointers from the DB so each agent only gets notified
     * about messages above their personal high-water mark.
     * Called periodically (every 15s) by the notification coroutine.
     *
     * @param AgentModel[] $agents
     */
    public function notifyNewMessages(string $channelId, array $agents): void
    {
        // Get channel label from first message or task
        $task = $this->db->getTask($channelId) ?? $this->db->getTask(str_replace('review:', '', $channelId));
        $channelLabel = $task ? $task->title : $channelId;

        foreach ($agents as $agent) {
            if ($agent->status !== 'idle') {
                continue; // Don't interrupt busy agents
            }

            // Per-agent read pointer from DB
            $agentReadTs = $this->db->getLastReadTs($agent->id, $channelId);
            $messages = $this->db->getMessagesByChannel($channelId, $agentReadTs);

            if (empty($messages)) {
                continue;
            }

            // Skip agent's own messages
            $otherMessages = array_filter($messages, fn($m) => $m->authorName !== $agent->name);
            if (empty($otherMessages)) {
                continue;
            }

            $count = count($otherMessages);
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

    // --- Board Post Discussion & Vote Workflow ---

    /** @var array<string, string> boardChannelId => boardMessageId */
    private array $activeBoardDiscussions = [];

    /**
     * Start a discussion for a board post. Creates a channel, prompts persona agents.
     */
    public function startBoardDiscussion(MessageModel $boardMsg): void
    {
        $channelId = "board:{$boardMsg->id}";

        // Don't start duplicate discussions
        if (isset($this->activeBoardDiscussions[$channelId])) {
            return;
        }

        $rootContent = "# Board Discussion\n\n";
        $rootContent .= "**{$boardMsg->title}**\n\n";
        if ($boardMsg->content) {
            $rootContent .= $boardMsg->content . "\n\n";
        }
        $rootContent .= "---\n";
        $rootContent .= "Discuss this board post with the team. Share your analysis, ask questions, and post your findings.\n\n";
        $rootContent .= "- Use `board_reply` (message_id=\"{$boardMsg->id}\") to reply directly on the board\n";
        $rootContent .= "- Use `forum_post` (channel_id=\"{$channelId}\") to discuss here\n";
        $rootContent .= "- Use `forum_vote` (channel_id=\"{$channelId}\", vote=\"approve\"/\"reject\") when you've reached a conclusion\n";

        $msg = MessageModel::create(
            authorId: 'system',
            authorName: 'SwarmTalk',
            category: 'forum',
            title: "Board: {$boardMsg->title}",
            content: $rootContent,
            lamportTs: $this->clock->tick(),
            channelId: $channelId,
        );

        $this->taskGossip->createBoardMessage($msg);
        $this->activeBoardDiscussions[$channelId] = $boardMsg->id;
        $this->discussionStartTimes[$channelId] = microtime(true);

        if ($this->onTaskEvent) {
            ($this->onTaskEvent)('forum_channel_created', [
                'channel_id' => $channelId,
                'task_id' => '',
                'task_title' => "Board: {$boardMsg->title}",
            ]);
        }

        // Prompt idle persona agents
        $agents = $this->getIdlePersonaAgents();
        foreach ($agents as $i => $agent) {
            \Swoole\Coroutine::create(function () use ($agent, $boardMsg, $channelId, $i) {
                \Swoole\Coroutine::sleep(2 + $i * 3); // stagger

                $freshAgent = $this->db->getAgent($agent->id);
                if (!$freshAgent || $freshAgent->status !== 'idle') {
                    return;
                }

                $persona = $freshAgent->persona ? json_decode($freshAgent->persona, true) : null;
                $displayName = $persona['display_name'] ?? $freshAgent->name;
                $systemPrompt = $persona['system_prompt'] ?? '';

                $prompt = "## Board Post Discussion\n\n";
                if ($systemPrompt) {
                    $prompt .= "{$systemPrompt}\n\n";
                }
                $prompt .= "A new post has been made to the Message Board:\n\n";
                $prompt .= "**{$boardMsg->title}**\n";
                if ($boardMsg->content) {
                    $prompt .= $boardMsg->content . "\n";
                }
                $prompt .= "\n### Instructions\n";
                $prompt .= "1. Read the discussion channel with `forum_list` (channel_id=\"{$channelId}\")\n";
                $prompt .= "2. Analyze the post from your perspective as {$displayName}\n";
                $prompt .= "3. Post your findings with `board_reply` (message_id=\"{$boardMsg->id}\")\n";
                $prompt .= "4. Discuss with teammates via `forum_post` (channel_id=\"{$channelId}\")\n";
                $prompt .= "5. When you've formed a conclusion, vote with `forum_vote` (channel_id=\"{$channelId}\", vote=\"approve\"/\"reject\")\n";

                $this->bridge->sendText($freshAgent, $prompt);
                $this->log("board discussion: prompted {$freshAgent->name} for board:{$boardMsg->id}");
            });
        }

        $this->log("board discussion started: channel={$channelId} title='{$boardMsg->title}' agents=" . count($agents));
    }

    /**
     * Check board discussion votes. Returns the board message ID if majority approved, null otherwise.
     * Resolves the channel on approval or timeout.
     *
     * @return array{action: string, board_message_id: string, title: string}|null
     */
    public function checkBoardDiscussionVotes(): ?array
    {
        foreach ($this->activeBoardDiscussions as $channelId => $boardMessageId) {
            $outcome = $this->checkVotes($channelId);
            $timedOut = $this->isTimedOut($channelId);

            if ($outcome === 'approve') {
                $boardMsg = $this->db->getMessage($boardMessageId);
                $this->resolveChannel($channelId, 'approved');
                unset($this->activeBoardDiscussions[$channelId]);
                $this->log("board discussion approved: {$channelId}");
                return [
                    'action' => 'create_task',
                    'board_message_id' => $boardMessageId,
                    'title' => $boardMsg ? $boardMsg->title : 'Board Post',
                    'description' => $boardMsg ? $boardMsg->content : '',
                ];
            }

            if ($outcome === 'reject') {
                $this->resolveChannel($channelId, 'rejected');
                unset($this->activeBoardDiscussions[$channelId]);
                $this->log("board discussion rejected: {$channelId}");
                continue;
            }

            if ($timedOut) {
                // Timeout without majority — auto-reject
                $this->resolveChannel($channelId, 'timeout');
                unset($this->activeBoardDiscussions[$channelId]);
                $this->log("board discussion timed out: {$channelId}");
                continue;
            }
        }

        return null;
    }

    /**
     * Get active channel IDs — combines in-memory tracked discussions
     * with any channels that have messages in the DB.
     * @return string[]
     */
    public function getActiveChannels(): array
    {
        $channels = array_keys($this->discussionStartTimes);

        // Also include channels from the DB that have active messages
        $dbChannels = $this->db->getActiveChannels();
        foreach ($dbChannels as $ch) {
            $id = $ch['channel_id'] ?? '';
            if ($id && !in_array($id, $channels, true)) {
                $channels[] = $id;
            }
        }

        return $channels;
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

    /**
     * Prompt a persona agent to check forum activity after finishing a task.
     * Gathers all channels with unread messages and sends a consolidated prompt.
     * Skips if no unread activity exists.
     */
    public function promptForumCheck(AgentModel $agent): void
    {
        // Get all active channels (non-DM)
        $channels = $this->db->getActiveChannels();

        // Get DMs addressed to this agent
        $allMessages = $this->db->getMessages();
        $dmChannels = [];
        foreach ($allMessages as $msg) {
            if (str_starts_with($msg->channelId, 'dm:') && str_contains($msg->channelId, $agent->name)) {
                // Skip DMs sent by this agent
                if ($msg->authorName === $agent->name) {
                    continue;
                }
                $key = $msg->channelId;
                if (!isset($dmChannels[$key])) {
                    $dmChannels[$key] = ['count' => 0, 'last_author' => ''];
                }
                $dmChannels[$key]['count']++;
                $dmChannels[$key]['last_author'] = $msg->authorName;
            }
        }

        $lines = [];

        foreach ($channels as $ch) {
            $channelId = $ch['channel_id'] ?? '';
            $count = (int) ($ch['message_count'] ?? 0);
            if (!$channelId || $count === 0) {
                continue;
            }
            // Get recent messages to find last author
            $msgs = $this->db->getMessagesByChannel($channelId);
            $lastAuthor = !empty($msgs) ? end($msgs)->authorName : 'unknown';
            // Skip channels where this agent posted last (they already saw it)
            if ($lastAuthor === $agent->name) {
                continue;
            }
            $lines[] = "- **{$channelId}**: {$count} messages (last from {$lastAuthor})";
        }

        foreach ($dmChannels as $channelId => $info) {
            $lines[] = "- **{$channelId}**: {$info['count']} DM(s) from {$info['last_author']}";
        }

        if (empty($lines)) {
            return; // No unread activity — don't spam the agent
        }

        $prompt = "## Forum Activity\n\n";
        $prompt .= "You've finished your task. Here's what's happening in SwarmTalk:\n\n";
        $prompt .= implode("\n", $lines) . "\n\n";
        $prompt .= "Use `forum_list` to read and `forum_post` to respond. Use `forum_dm` for private replies.\n";

        $this->bridge->sendText($agent, $prompt);
        $this->log("sent forum check prompt to {$agent->name} (" . count($lines) . " active channels)");
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
