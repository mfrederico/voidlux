<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Agent;

use Aoe\Session\Status;
use Aoe\Tmux\StatusDetector;
use Aoe\Tmux\TmuxService;
use VoidLux\Swarm\Model\AgentModel;
use VoidLux\Swarm\Model\TaskModel;
use VoidLux\Swarm\Storage\SwarmDatabase;

/**
 * Bridge between the swarm orchestrator and actual tmux-based AI agents.
 * Wraps aoe-php TmuxService for sending tasks and reading output.
 */
class AgentBridge
{
    private TmuxService $tmux;
    private StatusDetector $detector;

    public function __construct(
        private readonly SwarmDatabase $db,
        ?TmuxService $tmux = null,
        ?StatusDetector $detector = null,
    ) {
        $this->tmux = $tmux ?? new TmuxService('swarm', 'vl');
        $this->detector = $detector ?? new StatusDetector();
    }

    /**
     * Deliver a task to an agent's tmux session.
     * Returns true if the task was sent successfully.
     */
    public function deliverTask(AgentModel $agent, TaskModel $task): bool
    {
        $sessionName = $agent->tmuxSessionId;
        if (!$sessionName) {
            return false;
        }

        // Check agent is idle before sending
        $status = $this->detectStatus($agent);
        if ($status !== Status::Idle) {
            return false;
        }

        // Build the task prompt
        $prompt = $this->buildTaskPrompt($task);

        // Send to tmux
        $sent = $this->tmux->sendTextByName($sessionName, $prompt);
        if ($sent) {
            $this->tmux->sendEnterByName($sessionName);
        }

        return $sent;
    }

    /**
     * Detect the current status of an agent by reading its tmux pane.
     */
    public function detectStatus(AgentModel $agent): Status
    {
        $sessionName = $agent->tmuxSessionId;
        if (!$sessionName) {
            return Status::Stopped;
        }

        if (!$this->tmux->sessionExistsByName($sessionName)) {
            return Status::Stopped;
        }

        $content = $this->tmux->capturePaneByName($sessionName, 30);
        return $this->detector->detect($content);
    }

    /**
     * Capture the output from an agent's tmux pane.
     */
    public function captureOutput(AgentModel $agent, int $lines = 50): string
    {
        $sessionName = $agent->tmuxSessionId;
        if (!$sessionName) {
            return '';
        }

        return $this->tmux->capturePaneByName($sessionName, $lines);
    }

    /**
     * Send arbitrary text to an agent's tmux session.
     */
    public function sendText(AgentModel $agent, string $text): bool
    {
        $sessionName = $agent->tmuxSessionId;
        if (!$sessionName) {
            return false;
        }

        $sent = $this->tmux->sendTextByName($sessionName, $text);
        if ($sent) {
            $this->tmux->sendEnterByName($sessionName);
        }
        return $sent;
    }

    /**
     * Extract task result from pane output.
     * Looks for TASK_RESULT: marker or returns the last N lines.
     */
    public function extractResult(string $output): ?string
    {
        // Look for explicit marker
        if (preg_match('/TASK_RESULT:\s*(.+?)(?:\n|$)/s', $output, $matches)) {
            return trim($matches[1]);
        }

        // Look for common completion patterns
        $lines = array_filter(explode("\n", $output), fn($l) => trim($l) !== '');
        if (empty($lines)) {
            return null;
        }

        // Return last 10 meaningful lines as result summary
        $recent = array_slice($lines, -10);
        return implode("\n", $recent);
    }

    /**
     * Create a tmux session for an agent if it doesn't exist.
     */
    public function ensureSession(string $sessionName, string $cwd, string $tool = 'claude'): bool
    {
        if ($this->tmux->sessionExistsByName($sessionName)) {
            return true;
        }

        $command = match ($tool) {
            'claude' => 'claude',
            'opencode' => 'opencode',
            default => '',
        };

        return $this->tmux->createSessionWithName($sessionName, $cwd, $command);
    }

    private function buildTaskPrompt(TaskModel $task): string
    {
        $prompt = $task->description ?: $task->title;

        if ($task->context) {
            $prompt .= "\n\nContext: " . $task->context;
        }

        if ($task->projectPath) {
            $prompt .= "\n\nProject path: " . $task->projectPath;
        }

        return $prompt;
    }

    public function getTmuxService(): TmuxService
    {
        return $this->tmux;
    }
}
