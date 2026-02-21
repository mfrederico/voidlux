<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Agent;

use Aoe\Session\Status;
use Aoe\Tmux\StatusDetector;
use Aoe\Tmux\TmuxService;
use VoidLux\Swarm\Agent\AgentSizeConfig;
use VoidLux\Swarm\Ai\TaskPlanner;
use VoidLux\Swarm\Git\GitWorkspace;
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
    private GitWorkspace $git;

    public function __construct(
        private readonly SwarmDatabase $db,
        private readonly int $httpPort = 9091,
        ?TmuxService $tmux = null,
        ?StatusDetector $detector = null,
        ?GitWorkspace $git = null,
    ) {
        $this->tmux = $tmux ?? new TmuxService('swarm', 'vl');
        $this->detector = $detector ?? new StatusDetector();
        $this->git = $git ?? new GitWorkspace();
    }

    /**
     * Deliver a task to an agent's tmux session.
     * Resolves git URLs to the agent's worktree directory, prepares a per-task branch,
     * and pastes the task prompt into the agent's tmux pane.
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

        // Resolve the effective working directory.
        // When the task has a git URL, use the agent's worktree instead.
        $workDir = $this->resolveWorkDir($agent, $task);

        // Prepare git branch if the resolved work directory is a git repo
        if ($workDir && $this->git->isGitRepo($workDir)) {
            $branchName = 'task/' . substr($task->id, 0, 8);
            $prepared = $this->git->prepareBranch($workDir, $branchName);
            if ($prepared) {
                $this->db->updateGitBranch($task->id, $branchName);
            }
        }

        // Switch to the appropriate model for this task's complexity.
        // Uses the planner-assigned complexity field to resolve model tier.
        $provider = ($agent->tool === 'claude') ? 'claude' : 'ollama';
        $sizeConfig = AgentSizeConfig::forComplexity($task->complexity ?? 'medium', $provider);
        $targetModel = $sizeConfig->preferredModel;
        if ($targetModel !== '' && $agent->tool === 'claude') {
            $this->switchModel($sessionName, $targetModel);
        }

        // Clear agent context before new task
        // Note: usleep (not Coroutine::sleep) to keep delivery atomic —
        // yielding here lets the monitor poll and see "idle" before the prompt arrives
        $this->tmux->sendTextByName($sessionName, '/clear');
        $this->tmux->sendEnterByName($sessionName);
        usleep(1_500_000);

        // Build the task prompt with the resolved working directory
        $prompt = $this->buildTaskPrompt($task, $workDir);

        // Paste into tmux using load-buffer + paste-buffer (bracketed paste mode).
        // Unlike send-keys -l, this handles newlines, emojis, and special characters
        // correctly — the entire text is treated as a single paste operation.
        $sent = $this->tmux->pasteTextByName($sessionName, $prompt);
        // Claude Code needs time to render pasted text — scale by line count
        $lineCount = substr_count($prompt, "\n") + 1;
        usleep(max(500_000, $lineCount * 250_000));
        $this->tmux->sendEnterByName($sessionName);

        return $sent;
    }

    /**
     * Deliver a planning task to the planner agent's tmux session.
     * Unlike regular task delivery, this skips git branch prep and model switching.
     * The planner agent explores the codebase and calls task_plan MCP tool.
     */
    public function deliverPlanningTask(AgentModel $planner, TaskModel $task): bool
    {
        $sessionName = $planner->tmuxSessionId;
        if (!$sessionName) {
            return false;
        }

        // Check agent is idle before sending
        $status = $this->detectStatus($planner);
        if ($status !== Status::Idle) {
            return false;
        }

        // Clear agent context before new planning task
        $this->tmux->sendTextByName($sessionName, '/clear');
        $this->tmux->sendEnterByName($sessionName);
        usleep(1_500_000);

        // Build planning-specific prompt
        $prompt = $this->buildPlannerPrompt($task);

        // Paste into tmux
        $sent = $this->tmux->pasteTextByName($sessionName, $prompt);
        $lineCount = substr_count($prompt, "\n") + 1;
        usleep(max(500_000, $lineCount * 250_000));
        $this->tmux->sendEnterByName($sessionName);

        return $sent;
    }

    /**
     * Build a prompt for the planner agent. Instructs it to explore the codebase
     * and call task_plan MCP tool with subtask definitions.
     */
    private function buildPlannerPrompt(TaskModel $task): string
    {
        $prompt = "## Planning Request\n\n";
        $prompt .= "You are the architect/planner for the VoidLux swarm. ";
        $prompt .= "Decompose the following task into specific subtasks that AI coding agents can execute independently.\n\n";

        $prompt .= "### Task to Decompose\n";
        $prompt .= "**Title**: {$task->title}\n";
        if ($task->description) {
            $prompt .= "**Description**: {$task->description}\n";
        }
        if ($task->context) {
            $prompt .= "**Context**: {$task->context}\n";
        }

        $effectiveDir = $task->projectPath;
        if ($effectiveDir && is_dir($effectiveDir)) {
            $prompt .= "\n**Project Path**: {$effectiveDir}\n";
        }

        $prompt .= <<<'INSTRUCTIONS'

### Instructions

1. **Explore the codebase** — read files, understand the project structure, existing patterns, and conventions.
2. **Identify the changes needed** — which files to create or modify, what approach to take.
3. **Decompose into subtasks** — each should be assignable to a single agent working independently.
4. **Define dependencies** — if subtask B needs subtask A's output, declare the dependency.
5. **Include architecture context** — describe how data flows across subtasks so each agent understands the full picture.
6. **Call the `task_plan` MCP tool** with your subtask definitions.

### Output Format

Call `task_plan` with these arguments:
INSTRUCTIONS;

        $prompt .= "\n- `task_id`: \"{$task->id}\"\n";
        $prompt .= <<<'FORMAT'
- `subtasks`: array of objects, each with:
  - `id`: unique local ID (e.g. "subtask-1", "subtask-2")
  - `title`: short imperative title
  - `description`: what this subtask accomplishes
  - `work_instructions`: specific files to modify/create, approach, code patterns. Include architecture context here.
  - `acceptance_criteria`: how to verify correctness
  - `complexity`: "small" | "medium" | "large" | "xl"
  - `priority`: integer (higher = more important)
  - `dependsOn`: array of subtask IDs that must complete first (e.g. ["subtask-1"])

### Rules
- Return 1-8 subtasks
- Reference exact file paths from the actual codebase (explore first!)
- Only declare dependencies when truly needed
- Include architecture context in work_instructions so agents know how pieces connect
- Do NOT include testing/documentation subtasks unless the task explicitly requests them
FORMAT;

        $prompt .= "\n\n---\nTASK ID: {$task->id}\n";
        $prompt .= "Call `task_plan` when your decomposition is ready.\n";

        return $prompt;
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

        $sent = $this->tmux->pasteTextByName($sessionName, $text);
        $lineCount = substr_count($text, "\n") + 1;
        usleep(max(500_000, $lineCount * 250_000));
        $this->tmux->sendEnterByName($sessionName);
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
    /**
     * @param array{model?: string, env?: array<string, string>} $options
     */
    public function ensureSession(string $sessionName, string $cwd, string $tool = 'claude', array $options = []): bool
    {
        if ($this->tmux->sessionExistsByName($sessionName)) {
            return true;
        }

        $model = $options['model'] ?? '';
        $env = $options['env'] ?? [];

        $command = match ($tool) {
            'claude' => 'claude --dangerously-skip-permissions' . ($model ? ' --model ' . escapeshellarg($model) : ''),
            'opencode' => 'opencode',
            default => '',
        };

        // Unset CLAUDECODE so nested Claude Code sessions don't refuse to start
        $envPrefix = 'unset CLAUDECODE; ';

        // Prefix env vars (e.g. ANTHROPIC_AUTH_TOKEN=ollama ANTHROPIC_BASE_URL=...)
        if (!empty($env)) {
            foreach ($env as $key => $value) {
                // Env var names: alphanumeric + underscore only (sanitize, don't quote)
                $safeKey = preg_replace('/[^A-Za-z0-9_]/', '', $key);
                $envPrefix .= 'export ' . $safeKey . '=' . escapeshellarg($value) . '; ';
            }
        }

        if ($command !== '') {
            $command = $envPrefix . $command;
        }

        $created = $this->tmux->createSessionWithName($sessionName, $cwd, $command);

        if ($created) {
            $this->ensureMcpConfig($cwd);
        }

        return $created;
    }

    /**
     * Ensure the project directory has a .mcp.json with the voidlux-swarm MCP server entry.
     * Merges into existing config if present, skips if entry already exists.
     */
    public function ensureMcpConfig(string $projectPath): void
    {
        $mcpFile = rtrim($projectPath, '/') . '/.mcp.json';

        $config = [];
        if (file_exists($mcpFile)) {
            $existing = json_decode(file_get_contents($mcpFile), true);
            if (is_array($existing)) {
                $config = $existing;
            }
        }

        $expectedUrl = "http://localhost:{$this->httpPort}/mcp";

        // Skip if already configured with correct URL
        if (isset($config['mcpServers']['voidlux-swarm'])
            && ($config['mcpServers']['voidlux-swarm']['url'] ?? '') === $expectedUrl
        ) {
            return;
        }

        if (!isset($config['mcpServers'])) {
            $config['mcpServers'] = [];
        }

        $config['mcpServers']['voidlux-swarm'] = [
            'type' => 'http',
            'url' => "http://localhost:{$this->httpPort}/mcp",
        ];

        file_put_contents(
            $mcpFile,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    /**
     * Send /model command to switch the agent's LLM model before task delivery.
     * Waits for Claude Code to process the switch by polling the pane for
     * an idle prompt, up to a maximum timeout.
     */
    private function switchModel(string $sessionName, string $model): bool
    {
        $this->tmux->sendTextByName($sessionName, '/model ' . $model);
        $this->tmux->sendEnterByName($sessionName);

        // Poll for the agent to return to idle after model switch.
        // Claude Code processes /model quickly — 5s max is generous.
        $maxWaitUs = 5_000_000;
        $pollIntervalUs = 500_000;
        $waited = 0;
        while ($waited < $maxWaitUs) {
            usleep($pollIntervalUs);
            $waited += $pollIntervalUs;
            $content = $this->tmux->capturePaneByName($sessionName, 10);
            $status = $this->detector->detect($content);
            if ($status === Status::Idle) {
                return true;
            }
        }

        // Timed out — proceed anyway, model may have switched
        return true;
    }

    /**
     * Resolve the effective working directory for a task+agent pair.
     * When the task's projectPath is a git URL, uses the agent's worktree
     * (creating one on-the-fly if needed) instead of the raw URL.
     */
    private function resolveWorkDir(AgentModel $agent, TaskModel $task): string
    {
        $taskPath = $task->projectPath;

        // If task path is not a git URL, use it as-is (or fall back to agent's path)
        if (!$taskPath || !$this->git->isGitUrl($taskPath)) {
            return $taskPath ?: $agent->projectPath;
        }

        // Task path is a git URL — prefer the agent's actual directory (worktree)
        if ($agent->projectPath && is_dir($agent->projectPath)) {
            return $agent->projectPath;
        }

        // Agent doesn't have a valid directory — create worktree on the fly
        $cwd = getcwd();
        $baseDir = $cwd . '/workbench/.base';
        $worktreePath = $cwd . '/workbench/' . $agent->name;

        $ensured = $this->git->ensureBaseRepo($taskPath, $baseDir);
        if ($ensured) {
            $added = $this->git->addWorktree($baseDir, $worktreePath, 'worktree/' . $agent->name);
            if ($added) {
                return $worktreePath;
            }
        }

        // Fallback: agent's path or workbench
        return $agent->projectPath ?: $cwd . '/workbench';
    }

    /**
     * Build the task prompt. Uses $workDir (the resolved directory) for
     * "Work in this directory" and project type detection, instead of
     * the raw task projectPath which may be a git URL.
     */
    private function buildTaskPrompt(TaskModel $task, string $workDir = ''): string
    {
        $prompt = "## Task: " . $task->title . "\n\n";
        $prompt .= $task->description ?: $task->title;

        if ($task->workInstructions) {
            $prompt .= "\n\n## Work Instructions\n" . $task->workInstructions;
        }

        if ($task->acceptanceCriteria) {
            $prompt .= "\n\n## Acceptance Criteria\n" . $task->acceptanceCriteria;
        }

        // Inject prerequisite results from completed dependencies
        if (!empty($task->dependsOn)) {
            $depResults = $this->db->getDependencyResults($task->dependsOn);
            if (!empty($depResults)) {
                $prompt .= "\n\n## Prerequisite Results\nThe following tasks completed before yours. Use their results as context:\n";
                foreach ($depResults as $depId => $dep) {
                    $shortId = substr($depId, 0, 8);
                    $resultText = $dep['result'];
                    if (strlen($resultText) > 3000) {
                        $resultText = substr($resultText, 0, 3000) . "\n... (truncated)";
                    }
                    $prompt .= "\n### [{$shortId}] {$dep['title']}\n{$resultText}\n";
                }
            }
        }

        if ($task->context) {
            $prompt .= "\n\nContext: " . $task->context;
        }

        // Show the resolved working directory (not the raw git URL)
        $effectiveDir = $workDir ?: $task->projectPath;
        if ($effectiveDir && is_dir($effectiveDir)) {
            $prompt .= "\n\nWork in this directory: " . $effectiveDir;
        }

        // Detect and include project type so agents use the correct language
        $projectDir = $workDir ?: $task->projectPath;
        if ($projectDir && is_dir($projectDir)) {
            $projectType = TaskPlanner::detectProjectType($projectDir);
            if ($projectType) {
                $prompt .= "\n\n## Project Type\n" . $projectType;
            }
        }

        $prompt .= "\n\n---\nTASK ID: " . $task->id;
        $prompt .= "\nWhen finished, call the `task_complete` tool with this task_id and a summary.";
        $prompt .= "\nIf you hit an error, call `task_failed`. Need clarification? Call `task_needs_input`.";

        return $prompt;
    }

    /**
     * Kill an agent's tmux session and its child processes.
     * Captures PIDs before killing session, then explicitly kills orphaned processes.
     */
    public function killSession(AgentModel $agent): bool
    {
        $sessionName = $agent->tmuxSessionId;
        if (!$sessionName || !$this->tmux->sessionExistsByName($sessionName)) {
            return false;
        }

        // Capture PIDs of all processes in the session before killing it
        $pids = $this->getSessionPids($sessionName);

        $this->tmux->sendKeysByName($sessionName, 'C-c');
        usleep(200_000);
        $killed = $this->tmux->killSessionByName($sessionName);

        // Kill any orphaned processes that survived the tmux SIGHUP
        usleep(500_000);
        foreach ($pids as $pid) {
            if ($this->isProcessAlive($pid)) {
                posix_kill($pid, SIGTERM);
            }
        }

        // Final SIGKILL for stubborn processes
        usleep(500_000);
        foreach ($pids as $pid) {
            if ($this->isProcessAlive($pid)) {
                posix_kill($pid, SIGKILL);
            }
        }

        return $killed;
    }

    /**
     * Get PIDs of all processes in a tmux session (pane PID + its children).
     * @return int[]
     */
    private function getSessionPids(string $sessionName): array
    {
        $panePid = trim(shell_exec("tmux list-panes -t " . escapeshellarg($sessionName) . " -F '#{pane_pid}' 2>/dev/null") ?: '');
        if (!$panePid || !is_numeric($panePid)) {
            return [];
        }

        // Get all descendants of the pane shell process
        $tree = trim(shell_exec("pgrep -P $panePid 2>/dev/null") ?: '');
        $pids = array_filter(array_map('intval', explode("\n", $tree)));

        // Also get grandchildren (claude spawns child processes)
        $grandchildren = [];
        foreach ($pids as $pid) {
            $gc = trim(shell_exec("pgrep -P $pid 2>/dev/null") ?: '');
            $grandchildren = array_merge($grandchildren, array_filter(array_map('intval', explode("\n", $gc))));
        }

        return array_unique(array_merge($pids, $grandchildren));
    }

    private function isProcessAlive(int $pid): bool
    {
        return $pid > 0 && posix_kill($pid, 0);
    }

    public function getTmuxService(): TmuxService
    {
        return $this->tmux;
    }
}
