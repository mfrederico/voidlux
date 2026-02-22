<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Capabilities\Plugins;

use VoidLux\Swarm\Capabilities\PluginInterface;
use VoidLux\Swarm\Model\{AgentModel, TaskModel};

/**
 * Git version control plugin.
 *
 * Provides context for using git CLI and GitHub CLI (gh).
 * No MCP tools - agents use native Bash tool for all operations.
 *
 * Capabilities: git, version-control, github, code-review
 */
class GitPlugin implements PluginInterface
{
    public function getName(): string
    {
        return 'git';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Git version control and GitHub integration via native CLI tools';
    }

    public function getCapabilities(): array
    {
        return ['git', 'version-control', 'github', 'code-review'];
    }

    public function getRequirements(): array
    {
        return ['git', 'gh'];
    }

    public function checkAvailability(): bool
    {
        exec('which git 2>/dev/null', $output, $gitCode);
        exec('which gh 2>/dev/null', $output, $ghCode);
        return $gitCode === 0 && $ghCode === 0;
    }

    public function install(): array
    {
        // Check if git is installed
        exec('which git 2>/dev/null', $output, $gitCode);
        if ($gitCode !== 0) {
            exec('apt-get update && apt-get install -y git 2>&1', $output, $exitCode);
            if ($exitCode !== 0) {
                return [
                    'success' => false,
                    'message' => 'Failed to install git: ' . implode("\n", $output),
                ];
            }
        }

        // Install GitHub CLI
        exec('which gh 2>/dev/null', $output, $ghCode);
        if ($ghCode !== 0) {
            $cmd = <<<'BASH'
curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | tee /etc/apt/sources.list.d/github-cli.list > /dev/null
apt-get update && apt-get install -y gh 2>&1
BASH;
            exec($cmd, $output, $exitCode);
            if ($exitCode !== 0) {
                return [
                    'success' => false,
                    'message' => 'Failed to install gh CLI: ' . implode("\n", $output),
                ];
            }
        }

        return [
            'success' => true,
            'message' => 'Git and GitHub CLI installed successfully',
        ];
    }

    public function injectPromptContext(TaskModel $task, AgentModel $agent): string
    {
        $available = $this->checkAvailability();

        if (!$available) {
            return <<<'CONTEXT'
## Git & GitHub - NOT INSTALLED

To enable version control, install git and gh CLI:
```bash
apt-get update && apt-get install -y git
# Then follow gh CLI install: https://cli.github.com/
```

CONTEXT;
        }

        return <<<'CONTEXT'
## Git & GitHub Available

You have git and GitHub CLI (gh) for version control. Use your native **Bash** tool:

### Basic Git Operations
```bash
# Clone repository
git clone https://github.com/user/repo.git
git clone git@github.com:user/repo.git  # SSH

# Check status and diff
git status
git diff
git diff --staged

# Commit changes
git add file.txt
git add .
git commit -m "Your commit message"

# Push/pull
git push origin main
git pull origin main

# Branching
git checkout -b feature-branch
git branch -a
git merge feature-branch
```

### GitHub CLI (gh) - Advanced Operations
```bash
# Authenticate (if needed)
gh auth login

# Create pull request
gh pr create --title "Add feature" --body "Description here"
gh pr create --draft  # Draft PR

# View PR details
gh pr view 123
gh pr diff 123
gh pr list

# Review PRs
gh pr review 123 --approve
gh pr review 123 --request-changes --body "Please fix X"
gh pr merge 123 --squash

# Issues
gh issue create --title "Bug report" --body "Details"
gh issue list
gh issue view 42
gh issue close 42

# Repository operations
gh repo view owner/repo
gh repo clone owner/repo
gh repo fork owner/repo

# Releases
gh release create v1.0.0 --title "Version 1.0.0" --notes "Release notes"
gh release list

# Workflows (GitHub Actions)
gh workflow list
gh workflow run build.yml
gh run list
gh run view 123456
```

### Advanced Git
```bash
# Interactive rebase
git rebase -i HEAD~3

# Cherry-pick commits
git cherry-pick abc123

# Stash changes
git stash
git stash pop
git stash list

# Reset (careful!)
git reset --soft HEAD~1  # Undo commit, keep changes
git reset --hard HEAD~1  # Undo commit, discard changes

# Search commits
git log --grep="search term"
git log --author="name"
git log --since="2 weeks ago"

# Blame/history
git blame file.txt
git log -p file.txt  # File history with diffs
```

### Tips
- Use `gh pr create --fill` to auto-fill PR from commits
- Check PR checks: `gh pr checks`
- View PR in browser: `gh pr view --web`
- Clone PRs locally: `gh pr checkout 123`
- Git hooks live in `.git/hooks/`

CONTEXT;
    }

    public function onEnable(string $agentId): void
    {
        // No state to initialize
    }

    public function onDisable(string $agentId): void
    {
        // No cleanup needed
    }
}
