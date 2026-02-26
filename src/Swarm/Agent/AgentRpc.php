<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Agent;

/**
 * Abstraction over tmux session operations for AI agents.
 *
 * Implementations:
 * - LocalTmuxRpc: direct tmux calls (current behavior, same container)
 * - RemoteTmuxRpc: HTTP RPC to agent container (future, Phase 2)
 */
interface AgentRpc
{
    /**
     * Check if a session exists.
     */
    public function sessionExists(string $sessionName): bool;

    /**
     * Create a new tmux session with the given name, working directory, and command.
     */
    public function createSession(string $sessionName, string $cwd, string $command): bool;

    /**
     * Send text to a session (types it character by character, no Enter).
     */
    public function sendText(string $sessionName, string $text): bool;

    /**
     * Send Enter key to a session.
     */
    public function sendEnter(string $sessionName): bool;

    /**
     * Send arbitrary key sequence to a session (e.g. 'C-c', 'Enter').
     */
    public function sendKeys(string $sessionName, string $keys): bool;

    /**
     * Paste text into a session via load-buffer + paste-buffer (bracketed paste).
     * Handles newlines, emojis, and special characters correctly.
     */
    public function pasteText(string $sessionName, string $text): bool;

    /**
     * Capture the visible content of a session's pane.
     */
    public function capturePane(string $sessionName, int $lines = 50): string;

    /**
     * Kill a session.
     */
    public function killSession(string $sessionName): bool;

    /**
     * Get PIDs of all processes in a session (for orphan cleanup).
     * @return int[]
     */
    public function getSessionPids(string $sessionName): array;
}
