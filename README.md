# VoidLux

**A self-organizing AI agent swarm that decomposes, delegates, builds, reviews, and merges code — autonomously.**

VoidLux orchestrates AI coding agents (Claude Code, OpenCode, etc.) across a decentralized P2P mesh. Give it a task in plain English and a git repo. It plans subtasks with dependency ordering, dispatches them to agents running in isolated git worktrees, reviews results against acceptance criteria, resolves merge conflicts, runs tests, and opens a pull request — all without human intervention.

It built its own Dockerfile. It added its own PR merge button. It fixes its own bugs. The swarm improves itself.

---

## How It Works

```
  You: "Add user authentication with JWT tokens"
   |
   v
 Emperor (LLM planner)
   |-- Subtask 1: Create JWT middleware         --> Agent A (worktree branch)
   |-- Subtask 2: Add login/register endpoints  --> Agent B (worktree branch)  [depends on 1]
   |-- Subtask 3: Update user model             --> Agent C (worktree branch)
   |-- Subtask 4: Add auth tests                --> Agent D (worktree branch)  [depends on 1,2,3]
   |
   v
 LLM Review (acceptance criteria check per subtask)
   |-- Pass --> merge branches --> run tests --> open PR
   |-- Fail --> requeue with feedback (up to 3 attempts)
```

Each agent works in its own **git worktree** on an isolated branch. When all subtasks complete, VoidLux merges them into an integration branch, runs your test suite, and creates a single PR. Merge conflicts? It requeues the conflicting subtasks. Test failures? It requeues everything. Up to 3 attempts before giving up.

## Quick Start

### Bare Metal

```bash
# Requirements: PHP 8.1+, OpenSwoole extension, tmux, git, gh CLI
composer install

# Launch seneschal (stable proxy) + emperor
bash scripts/enter-the-void.sh

# Register 3 agents with Claude Code
bash scripts/register-agents.sh 3 claude https://github.com/you/your-repo.git

# Open the dashboard
open http://localhost:9090
```

### Docker

```bash
docker compose up -d

# Register agents (Claude Code binary mounted from host)
bash scripts/register-agents.sh 3 claude https://github.com/you/your-repo.git 9091
```

```yaml
# docker-compose.yml
services:
  voidlux:
    build: .
    ports:
      - "9090:9090"   # Dashboard (Seneschal proxy)
      - "9091:9091"   # Emperor API
    volumes:
      - ./data:/app/data
      - ./workbench:/app/workbench
      - ${CLAUDE_PATH:-/usr/local/bin/claude}:/usr/local/bin/claude:ro
    environment:
      - ANTHROPIC_API_KEY
      - VOIDLUX_LLM_PROVIDER=claude
      - VOIDLUX_LLM_MODEL=claude-sonnet-4-5-20250929
```

> **Docker permissions**: If you get `permission denied` on the Docker socket, add your user to the `docker` group and start a new shell:
> ```bash
> sudo usermod -aG docker $USER
> newgrp docker  # or log out and back in
> ```
> Any tmux sessions or background processes started *before* the group change won't inherit it — restart them after.

## Dashboard

The emperor serves a real-time web dashboard at the seneschal port (default `:9090`):

- **Deploy Swarm** — paste a git URL and describe what you want built
- **Task cards** — live status, progress, agent assignment, subtask tree
- **Agent cards** — status (starting/idle/busy), live tmux output, node info
- **PR management** — view pull requests, merge with one click, or enable auto-merge
- **Contributions** — history of all PRs created by the swarm
- Real-time updates via WebSocket

## Architecture

```
                 Browser
                    |
              +-----+-----+
              | Seneschal  |  Stable reverse proxy — survives emperor failover
              |  :9090     |  HTTP + WebSocket relay
              +-----+------+
                    |
              +-----+------+
              |  Emperor   |  Dashboard, AI planner, task dispatch, code review
              |  :9091     |  P2P gossip mesh, leader election, merge-test-retry
              +--+----+--+-+
                 |    |  |
              [tmux] [tmux] [tmux]
              Agent1 Agent2 Agent3
              (Claude Code sessions in isolated git worktrees)
```

### Key Components

| Component | What it does |
|---|---|
| **Seneschal** | Reverse proxy that tracks the emperor via P2P gossip. Browser never changes ports, even during failover. |
| **Emperor** | Hosts the dashboard, AI planner, task dispatcher, code reviewer, and agent tmux sessions. |
| **TaskPlanner** | LLM decomposes high-level tasks into subtasks with dependency ordering, work instructions, and acceptance criteria. |
| **TaskDispatcher** | Push-based dispatch via Swoole channels. Event-driven, not polling. |
| **TaskReviewer** | LLM evaluates completed work against acceptance criteria. Reject = requeue with feedback. |
| **AgentBridge** | Delivers task prompts to Claude Code via tmux `load-buffer` + bracketed paste. |
| **AgentMonitor** | Polls tmux panes every 5s. Detects idle/busy/error states. Requeues orphaned tasks. |
| **GitWorkspace** | Manages worktrees, branches, commits, PR creation, and auto-merge via `gh` CLI. |
| **MergeTestRetry** | Merges all subtask branches, runs test command, requeues on conflict/failure. Max 3 attempts. |
| **P2P Mesh** | TCP gossip with Lamport clocks, UDP LAN discovery, peer exchange, anti-entropy sync. |
| **Leader Election** | Bully algorithm. Emperor heartbeats every 10s, workers detect stale >30s, lowest node ID wins. |

### Task Lifecycle

```
create --> planning --> [LLM decomposes into subtasks with deps]
              |
              v
         in_progress
              |
   +----------+----------+
   |          |          |
subtask1   subtask2   subtask3   (dispatched to idle agents)
   |          |          |
 claimed    blocked    claimed    (deps resolved in order)
   |          |          |
complete  unblocked  complete
   |       claimed      |
   |          |          |
   |       complete      |
   +----------+----------+
              |
         pending_review --> [LLM checks acceptance criteria]
              |
        pass / fail
         |       |
      merging   requeue (with feedback, max 3x)
         |
   [merge branches + run tests]
         |
    pass / fail
      |       |
  completed  requeue subtasks
      |
   [push + create PR]
```

### MCP Integration

Agents communicate completion/failure/progress back to the emperor via [Model Context Protocol](https://modelcontextprotocol.io/) tools:

| Tool | Purpose |
|---|---|
| `task_complete` | Agent signals task done with summary |
| `task_failed` | Agent signals failure with error |
| `task_progress` | Agent reports incremental progress |
| `task_needs_input` | Agent requests human clarification |
| `agent_ready` | Agent signals it has loaded and is ready |

## CLI Reference

```bash
# Emperor (single-machine, recommended)
php bin/voidlux swarm --role=emperor \
  --http-port=9091 --p2p-port=7101 \
  --llm-provider=claude --llm-model=claude-sonnet-4-5-20250929 \
  --claude-api-key=$ANTHROPIC_API_KEY

# Seneschal (stable proxy)
php bin/voidlux seneschal \
  --http-port=9090 --p2p-port=7100 \
  --seeds=127.0.0.1:7101

# Multi-machine: additional emperor on another host
php bin/voidlux swarm --role=worker \
  --http-port=9091 --p2p-port=7101 \
  --seeds=emperor-host:7101

# Register agents
bash scripts/register-agents.sh [count] [tool] [project_path] [port]
# Example: 3 Claude Code agents working on a GitHub repo
bash scripts/register-agents.sh 3 claude https://github.com/you/repo.git 9091
```

### Environment Variables

| Variable | Description |
|---|---|
| `ANTHROPIC_API_KEY` | Claude API key for task planning and review |
| `VOIDLUX_LLM_PROVIDER` | `claude` or `ollama` |
| `VOIDLUX_LLM_MODEL` | Model name (e.g. `claude-sonnet-4-5-20250929`, `qwen3:32b`) |
| `VOIDLUX_LLM_HOST` | Ollama host (default: `127.0.0.1`) |
| `VOIDLUX_LLM_PORT` | Ollama port (default: `11434`) |
| `VOIDLUX_TEST_COMMAND` | Test command for merge-test-retry (e.g. `composer test`) |
| `VOIDLUX_AUTH_SECRET` | Shared secret for P2P authentication |

## HTTP API

### Tasks

| Method | Path | Description |
|---|---|---|
| `POST` | `/api/swarm/tasks` | Create task (triggers LLM planning) |
| `GET` | `/api/swarm/tasks` | List tasks (`?status=pending`) |
| `GET` | `/api/swarm/tasks/{id}` | Get task detail |
| `GET` | `/api/swarm/tasks/{id}/subtasks` | Get subtasks |
| `POST` | `/api/swarm/tasks/{id}/cancel` | Cancel task |
| `POST` | `/api/swarm/tasks/{id}/review` | Accept/reject completed task |
| `POST` | `/api/swarm/tasks/{id}/merge-pr` | Merge the task's pull request |
| `POST` | `/api/swarm/tasks/clear` | Archive all tasks |

### Agents

| Method | Path | Description |
|---|---|---|
| `GET` | `/api/swarm/agents` | List all agents |
| `POST` | `/api/swarm/agents` | Register agent (creates tmux session) |
| `DELETE` | `/api/swarm/agents/{id}` | Deregister agent |
| `POST` | `/api/swarm/agents/{id}/send` | Send text to agent tmux |
| `GET` | `/api/swarm/agents/{id}/output` | Capture pane output |
| `POST` | `/api/swarm/agents/wellness` | Health check all agents |

## P2P Wire Protocol

Messages use JSON with a 4-byte uint32 big-endian length prefix.

| Code | Message | Description |
|---|---|---|
| `0x01` | HELLO | Handshake with node ID |
| `0x05` | PEX | Peer exchange |
| `0x06`/`0x07` | PING/PONG | Keepalive |
| `0x10`-`0x16` | TASK_* | Create, claim, update, complete, fail, cancel, assign |
| `0x20`-`0x22` | AGENT_* | Register, heartbeat, deregister |
| `0x30`-`0x31` | TASK_SYNC_* | Anti-entropy pull sync |
| `0x40`-`0x42` | ELECTION_* | Emperor heartbeat, election start, victory |

## Requirements

- **PHP 8.1+** with OpenSwoole extension
- **tmux** (agent sessions)
- **git** (worktrees, branches, PRs)
- **gh** CLI (pull request creation and merging)
- **SQLite3** PDO extension
- An LLM provider: **Claude API** (recommended) or **Ollama** (local)

## License

MIT
