<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Git;

/**
 * Manages per-agent git workspaces: worktrees, branches, merge, test.
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
        $path = rtrim($path, '/');
        // Standard clone: .git is a directory
        if (is_dir($path . '/.git')) {
            return true;
        }
        // Worktree: .git is a file containing "gitdir: ..."
        if (is_file($path . '/.git')) {
            $content = file_get_contents($path . '/.git');
            return str_starts_with(trim($content), 'gitdir:');
        }
        return false;
    }

    public function isWorktree(string $path): bool
    {
        $gitPath = rtrim($path, '/') . '/.git';
        if (!is_file($gitPath)) {
            return false;
        }
        $content = file_get_contents($gitPath);
        return str_starts_with(trim($content), 'gitdir:');
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

    /**
     * Ensure the shared base repo exists at the given path.
     * Clones once; subsequent calls are no-ops.
     */
    public function ensureBaseRepo(string $repoUrl, string $baseDir): bool
    {
        if (is_dir($baseDir . '/.git')) {
            // Fetch latest
            $this->exec($baseDir, 'git fetch --all --prune 2>&1');
            return true;
        }

        return $this->cloneRepo($repoUrl, $baseDir);
    }

    /**
     * Add a git worktree linked to the base repo.
     */
    public function addWorktree(string $baseRepoDir, string $worktreePath, string $branchName): bool
    {
        // If worktree already exists, just return true
        if ($this->isWorktree($worktreePath) || $this->isGitRepo($worktreePath)) {
            return true;
        }

        $parentDir = dirname($worktreePath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        $cmd = sprintf(
            'git worktree add --detach %s 2>&1',
            escapeshellarg($worktreePath),
        );

        $code = $this->exec($baseRepoDir, $cmd);

        if ($code !== 0) {
            $this->log("Worktree add failed for {$worktreePath}");
            return false;
        }

        return true;
    }

    /**
     * Remove a git worktree.
     */
    public function removeWorktree(string $baseRepoDir, string $worktreePath): bool
    {
        if (!is_dir($worktreePath)) {
            return true;
        }

        $cmd = sprintf(
            'git worktree remove --force %s 2>&1',
            escapeshellarg($worktreePath),
        );

        $code = $this->exec($baseRepoDir, $cmd);

        if ($code !== 0) {
            // Fallback: prune and retry
            $this->exec($baseRepoDir, 'git worktree prune 2>&1');
            // If dir still exists, remove manually
            if (is_dir($worktreePath)) {
                exec('rm -rf ' . escapeshellarg($worktreePath));
            }
        }

        return true;
    }

    /**
     * Create or reset a merge worktree for integration testing.
     */
    public function createMergeWorktree(string $baseRepoDir, string $worktreePath, string $integrationBranch): bool
    {
        // Fetch latest from origin
        $this->exec($baseRepoDir, 'git fetch --all --prune 2>&1');

        // Remove existing merge worktree if present
        if (is_dir($worktreePath)) {
            $this->removeWorktree($baseRepoDir, $worktreePath);
        }

        $defaultBranch = $this->getDefaultBranch($baseRepoDir);

        // Create worktree on a new integration branch based on default
        $cmd = sprintf(
            'git worktree add -B %s %s origin/%s 2>&1',
            escapeshellarg($integrationBranch),
            escapeshellarg($worktreePath),
            escapeshellarg($defaultBranch),
        );

        $code = $this->exec($baseRepoDir, $cmd);

        if ($code !== 0) {
            $this->log("Failed to create merge worktree at {$worktreePath}");
            return false;
        }

        return true;
    }

    /**
     * Merge multiple subtask branches into the current worktree.
     * Returns MergeResult with details on which branches merged/conflicted.
     */
    public function mergeSubtaskBranches(string $workDir, array $branches, string $baseRepoDir): MergeResult
    {
        // Fetch all branches from origin first
        $this->exec($baseRepoDir, 'git fetch --all --prune 2>&1');
        $this->exec($workDir, 'git fetch --all --prune 2>&1');

        $mergedBranches = [];
        $conflictingBranches = [];
        $conflictOutput = '';

        foreach ($branches as $branch) {
            $output = [];
            $cmd = sprintf(
                'cd %s && git merge --no-ff origin/%s -m %s 2>&1',
                escapeshellarg($workDir),
                escapeshellarg($branch),
                escapeshellarg("Merge branch '{$branch}' into integration"),
            );
            exec($cmd, $output, $code);

            if ($code !== 0) {
                $conflictingBranches[] = $branch;
                $conflictOutput .= "Branch '{$branch}':\n" . implode("\n", $output) . "\n\n";
                // Abort the merge
                $this->exec($workDir, 'git merge --abort 2>&1');
            } else {
                $mergedBranches[] = $branch;
            }
        }

        return new MergeResult(
            success: empty($conflictingBranches),
            mergedBranches: $mergedBranches,
            conflictingBranches: $conflictingBranches,
            conflictOutput: $conflictOutput,
        );
    }

    /**
     * Run the test command in the given directory.
     */
    public function runTests(string $workDir, string $testCommand): TestResult
    {
        if (!$testCommand) {
            return new TestResult(success: true, output: 'No test command configured', exitCode: 0);
        }

        $output = [];
        $cmd = sprintf('cd %s && %s 2>&1', escapeshellarg($workDir), $testCommand);
        exec($cmd, $output, $code);

        $outputStr = implode("\n", $output);

        return new TestResult(
            success: $code === 0,
            output: $outputStr,
            exitCode: $code,
        );
    }

    public function prepareBranch(string $workDir, string $branchName): bool
    {
        $defaultBranch = $this->getDefaultBranch($workDir);

        // Discard any leftover changes from previous task
        $this->exec($workDir, 'git stash --include-untracked 2>/dev/null');

        // Fetch latest — works in both clones and worktrees
        $this->exec($workDir, 'git fetch origin 2>&1');

        // Create fresh branch from origin's default (avoids worktree branch-lock)
        $code = $this->exec(
            $workDir,
            'git checkout -B ' . escapeshellarg($branchName) . ' origin/' . escapeshellarg($defaultBranch) . ' 2>&1',
        );

        if ($code !== 0) {
            // Branch may already exist — switch to it
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

        if ($this->isWorktree($workDir)) {
            // Worktree: detach to avoid branch-lock issues
            $code = $this->exec($workDir, 'git checkout --detach origin/' . escapeshellarg($defaultBranch) . ' 2>&1');
        } else {
            $code = $this->exec($workDir, 'git checkout ' . escapeshellarg($defaultBranch) . ' 2>&1');
        }

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
