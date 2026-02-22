<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Auth;

use Aoe\Tmux\TmuxService;

/**
 * Manages Claude Code OAuth authentication flow.
 *
 * Provides interactive authentication via tmux sessions where users can:
 * 1. Get the OAuth URL
 * 2. Visit it in their browser
 * 3. Paste the resulting code back
 * 4. Complete authentication
 */
class ClaudeAuthManager
{
    private const AUTH_SESSION_PREFIX = 'claude-auth-';

    public function __construct(
        private readonly TmuxService $tmux,
    ) {}

    /**
     * Start an interactive Claude authentication session.
     * Returns the tmux session name that the user can attach to.
     */
    public function startAuthSession(): array
    {
        $sessionName = self::AUTH_SESSION_PREFIX . bin2hex(random_bytes(4));

        // Create detached tmux session (sessions are created detached by default with -d flag)
        $cwd = getcwd();
        $created = $this->tmux->createSessionWithName($sessionName, $cwd);
        if (!$created) {
            return [
                'success' => false,
                'error' => 'Failed to create tmux session',
            ];
        }

        // Send the claude command to trigger auth
        sleep(1); // Wait for session to be ready
        $this->tmux->sendTextByName($sessionName, 'claude');
        $this->tmux->sendEnterByName($sessionName);

        // Wait a bit for Claude to initialize
        sleep(2);

        // Capture the initial output to see if auth is needed
        $output = $this->tmux->capturePaneByName($sessionName, 100, true);

        return [
            'success' => true,
            'session_name' => $sessionName,
            'output' => $output,
            'attach_command' => "tmux attach-session -t {$sessionName}",
        ];
    }

    /**
     * Check if Claude is already authenticated.
     */
    public function isAuthenticated(): bool
    {
        // Check if Claude credentials exist (note: Claude uses .credentials.json with a dot)
        $credentialsPath = getenv('HOME') . '/.claude/.credentials.json';
        return file_exists($credentialsPath);
    }

    /**
     * Get the status of an auth session.
     */
    public function getAuthSessionStatus(string $sessionName): array
    {
        // Check if session exists
        if (!$this->tmux->sessionExistsByName($sessionName)) {
            return [
                'exists' => false,
                'error' => 'Session not found',
            ];
        }

        // Capture current output
        $output = $this->tmux->capturePaneByName($sessionName, 50);

        // Check if authentication completed
        $authenticated = $this->isAuthenticated();

        return [
            'exists' => true,
            'output' => $output,
            'authenticated' => $authenticated,
        ];
    }

    /**
     * Kill an auth session.
     */
    public function killAuthSession(string $sessionName): bool
    {
        if (!str_starts_with($sessionName, self::AUTH_SESSION_PREFIX)) {
            return false; // Safety check
        }

        return $this->tmux->killSessionByName($sessionName);
    }

    /**
     * Get all active auth sessions.
     */
    public function listAuthSessions(): array
    {
        $allSessions = $this->tmux->listSessions();

        return array_filter($allSessions, function($session) {
            return str_starts_with($session['name'], self::AUTH_SESSION_PREFIX);
        });
    }

    /**
     * Get credentials info if authenticated.
     */
    public function getCredentialsInfo(): ?array
    {
        $credentialsPath = getenv('HOME') . '/.claude/.credentials.json';

        if (!file_exists($credentialsPath)) {
            return null;
        }

        $data = json_decode(file_get_contents($credentialsPath), true);
        if (!$data) {
            return null;
        }

        // Extract info from the claudeAiOauth section
        $oauth = $data['claudeAiOauth'] ?? $data;

        // Return non-sensitive info only
        return [
            'authenticated' => true,
            'expires_at' => $oauth['expiresAt'] ?? null,
            'subscription_type' => $oauth['subscriptionType'] ?? null,
            'rate_limit_tier' => $oauth['rateLimitTier'] ?? null,
        ];
    }
}
