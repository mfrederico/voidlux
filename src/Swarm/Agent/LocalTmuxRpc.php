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
}
