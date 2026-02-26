<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Agent;

use Aoe\Tmux\TmuxService;

/**
 * Local tmux implementation of AgentRpc.
 * Delegates to aoe-php TmuxService for same-container agent I/O.
 */
class LocalTmuxRpc implements AgentRpc
{
    private TmuxService $tmux;

    public function __construct(?TmuxService $tmux = null)
    {
        $this->tmux = $tmux ?? new TmuxService('swarm', 'vl');
    }

    public function sessionExists(string $sessionName): bool
    {
        return $this->tmux->sessionExistsByName($sessionName);
    }

    public function createSession(string $sessionName, string $cwd, string $command): bool
    {
        return $this->tmux->createSessionWithName($sessionName, $cwd, $command);
    }

    public function sendText(string $sessionName, string $text): bool
    {
        return $this->tmux->sendTextByName($sessionName, $text);
    }

    public function sendEnter(string $sessionName): bool
    {
        return $this->tmux->sendEnterByName($sessionName);
    }

    public function sendKeys(string $sessionName, string $keys): bool
    {
        return $this->tmux->sendKeysByName($sessionName, $keys);
    }

    public function pasteText(string $sessionName, string $text): bool
    {
        return $this->tmux->pasteTextByName($sessionName, $text);
    }

    public function capturePane(string $sessionName, int $lines = 50): string
    {
        return $this->tmux->capturePaneByName($sessionName, $lines);
    }

    public function killSession(string $sessionName): bool
    {
        return $this->tmux->killSessionByName($sessionName);
    }

    public function getSessionPids(string $sessionName): array
    {
        $panePid = trim(shell_exec(
            "tmux list-panes -t " . escapeshellarg($sessionName) . " -F '#{pane_pid}' 2>/dev/null"
        ) ?: '');

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

    public function ensureMcpConfig(string $projectPath, string $mcpUrl): void
    {
        $mcpFile = rtrim($projectPath, '/') . '/.mcp.json';

        $config = [];
        if (file_exists($mcpFile)) {
            $existing = json_decode(file_get_contents($mcpFile), true);
            if (is_array($existing)) {
                $config = $existing;
            }
        }

        if (isset($config['mcpServers']['voidlux-swarm'])
            && ($config['mcpServers']['voidlux-swarm']['url'] ?? '') === $mcpUrl
        ) {
            // MCP config already correct — still ensure permissions file exists
            $this->ensureClaudePermissions($projectPath);
            return;
        }

        if (!isset($config['mcpServers'])) {
            $config['mcpServers'] = [];
        }

        $config['mcpServers']['voidlux-swarm'] = [
            'type' => 'http',
            'url' => $mcpUrl,
        ];

        file_put_contents(
            $mcpFile,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        // Pre-approve MCP tools so agents don't get trust prompts
        $this->ensureClaudePermissions($projectPath);
    }

    /**
     * Write .claude/settings.local.json to pre-approve MCP tools.
     */
    private function ensureClaudePermissions(string $projectPath): void
    {
        $settingsDir = rtrim($projectPath, '/') . '/.claude';
        $settingsFile = $settingsDir . '/settings.local.json';

        if (file_exists($settingsFile)) {
            $existing = json_decode(file_get_contents($settingsFile), true);
            if (is_array($existing)
                && isset($existing['permissions']['allow'])
                && in_array('mcp__voidlux-swarm__*', $existing['permissions']['allow'], true)
            ) {
                return; // Already configured
            }
        }

        if (!is_dir($settingsDir)) {
            @mkdir($settingsDir, 0755, true);
        }

        $settings = [
            'permissions' => [
                'allow' => [
                    'Bash',
                    'Read',
                    'Write',
                    'Edit',
                    'Glob',
                    'Grep',
                    'Task',
                    'mcp__voidlux-swarm__*',
                ],
            ],
        ];

        file_put_contents(
            $settingsFile,
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    public function ensureClaudeAuth(string $workDir): void
    {
        $workdirClaude = rtrim($workDir, '/') . '/.claude';
        $rootClaude = $_SERVER['HOME'] ?? '/root';
        $rootClaude = rtrim($rootClaude, '/') . '/.claude';

        if (file_exists($workdirClaude)) {
            return;
        }

        if (is_dir($rootClaude)) {
            @symlink($rootClaude, $workdirClaude);
        }
    }
}
