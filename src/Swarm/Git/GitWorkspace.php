<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Git;

/**
 * Manages per-agent git workspaces: clone, branch, commit, push, PR.
 * Uses shell exec like existing AgentBridge.getSessionPids().
 */
class GitWorkspace
{
    public function isGitUrl(string $path): bool
    {
        return (bool) preg_match('#^(https?://|git://|ssh://|git@)#', $path);
    }

    public function isGitRepo(string $path): bool
    {
        return is_dir(rtrim($path, '/') . '/.git');
    }

    public function cloneRepo(string $repoUrl, string $targetDir): bool
    {
        if (is_dir($targetDir . '/.git')) {
            return true; // Already cloned
        }

        $parentDir = dirname($targetDir);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        $cmd = sprintf(
            'git clone %s %s 2>&1',
            escapeshellarg($repoUrl),
            escapeshellarg($targetDir),
        );

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);

        if ($code !== 0) {
            $this->log("Clone failed ({$code}): " . implode("\n", $output));
            return false;
        }

        return true;
    }

    public function prepareBranch(string $workDir, string $branchName): bool
    {
        $defaultBranch = $this->getDefaultBranch($workDir);

        // Discard any leftover changes from previous task
        $this->exec($workDir, 'git stash --include-untracked 2>/dev/null');
        $this->exec($workDir, 'git checkout ' . escapeshellarg($defaultBranch) . ' 2>&1');
        $this->exec($workDir, 'git pull origin ' . escapeshellarg($defaultBranch) . ' 2>&1');

        // Create fresh branch
        $code = $this->exec($workDir, 'git checkout -b ' . escapeshellarg($branchName) . ' 2>&1');

        if ($code !== 0) {
            // Branch may already exist from a requeued task — switch to it
            $code = $this->exec($workDir, 'git checkout ' . escapeshellarg($branchName) . ' 2>&1');
        }

        return $code === 0;
    }

    public function commitAndPush(string $workDir, string $commitMsg, string $branchName): bool
    {
        $this->exec($workDir, 'git add -A');

        // Check if there's anything to commit
        $code = $this->exec($workDir, 'git diff --cached --quiet 2>&1');
        if ($code === 0) {
            $this->log("Nothing to commit in {$workDir}");
            return false;
        }

        $code = $this->exec(
            $workDir,
            'git commit -m ' . escapeshellarg($commitMsg) . ' 2>&1',
        );
        if ($code !== 0) {
            return false;
        }

        $code = $this->exec(
            $workDir,
            'git push -u origin ' . escapeshellarg($branchName) . ' 2>&1',
        );

        return $code === 0;
    }

    public function createPullRequest(string $workDir, string $title, string $body): ?string
    {
        // Check if gh CLI is available
        $code = $this->exec($workDir, 'which gh 2>/dev/null');
        if ($code !== 0) {
            return null;
        }

        $output = [];
        $cmd = sprintf(
            'cd %s && gh pr create --title %s --body %s 2>&1',
            escapeshellarg($workDir),
            escapeshellarg($title),
            escapeshellarg($body),
        );

        exec($cmd, $output, $code);

        if ($code !== 0) {
            $this->log("PR creation failed: " . implode("\n", $output));
            return null;
        }

        // gh pr create outputs the PR URL on the last line
        $url = trim(end($output) ?: '');
        return $url ?: null;
    }

    public function getDefaultBranch(string $workDir): string
    {
        $output = [];
        $cmd = sprintf(
            'cd %s && git symbolic-ref refs/remotes/origin/HEAD 2>/dev/null',
            escapeshellarg($workDir),
        );
        exec($cmd, $output, $code);

        if ($code === 0 && !empty($output[0])) {
            // refs/remotes/origin/main → main
            $ref = trim($output[0]);
            $parts = explode('/', $ref);
            return end($parts) ?: 'main';
        }

        // Fallback: check if main or master exists
        $code = $this->exec($workDir, 'git rev-parse --verify origin/main 2>/dev/null');
        if ($code === 0) {
            return 'main';
        }

        return 'master';
    }

    public function resetToDefault(string $workDir): bool
    {
        $defaultBranch = $this->getDefaultBranch($workDir);
        $this->exec($workDir, 'git stash --include-untracked 2>/dev/null');
        $code = $this->exec($workDir, 'git checkout ' . escapeshellarg($defaultBranch) . ' 2>&1');
        return $code === 0;
    }

    private function exec(string $workDir, string $cmd): int
    {
        $fullCmd = sprintf('cd %s && %s', escapeshellarg($workDir), $cmd);
        $output = [];
        $code = 0;
        exec($fullCmd, $output, $code);
        return $code;
    }

    private function log(string $message): void
    {
        $time = date('H:i:s');
        echo "[{$time}][git] {$message}\n";
    }
}
