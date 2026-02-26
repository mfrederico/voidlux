# Agent Container Separation Plan

## Problem

Emperor + agents share one container. Restarting the emperor (for code changes,
config updates, or crashes) kills all tmux agent sessions. Agents take 30-60s to
start Claude Code + pass onboarding, so every restart is expensive.

## Goal

Split into two containers:
- **Emperor container**: seneschal + emperor (orchestration, dashboard, API, gossip)
- **Agent container**: tmux sessions running Claude Code (the actual workers)

Emperor restarts don't touch agents. Agent containers can scale independently.

## Current Architecture (what needs to change)

### AgentBridge (local tmux)
`AgentBridge` uses `TmuxService` to:
- Spawn tmux sessions (`createAgent`)
- Send text/paste to panes (`deliverTask`, `sendText`, `injectPersona`)
- Read pane output (`capturePane`, `extractResult`)
- Kill sessions (`killAgent`)

All of this assumes **local tmux** — same PID namespace, same filesystem.

### AgentMonitor (local tmux polling)
- Calls `tmux list-panes -a` to batch-detect agent status
- Reads pane content to detect idle/busy/error states
- Runs in a coroutine loop on the emperor

### Agent Registration
- `registerOneAgent()` spawns tmux session + registers in local SQLite
- Agent tmux session name is `vl-{prefix}-{random}`

## Proposed Architecture

### Phase 1: Agent RPC Layer (minimal change)

Extract an `AgentRpc` interface from AgentBridge:

```php
interface AgentRpc
{
    public function spawn(string $sessionName, string $command, string $workDir): bool;
    public function sendText(string $sessionName, string $text): bool;
    public function pasteText(string $sessionName, string $text): bool;
    public function capturePane(string $sessionName, int $lines = 50): string;
    public function listSessions(): array;  // [{name, panes, pid}]
    public function killSession(string $sessionName): bool;
}
```

Two implementations:
- `LocalTmuxRpc` — current behavior (calls TmuxService directly)
- `RemoteTmuxRpc` — HTTP/WebSocket calls to agent container's RPC server

AgentBridge switches from `TmuxService` to `AgentRpc`. Zero behavior change
when using `LocalTmuxRpc`.

### Phase 2: Agent Container RPC Server

A lightweight HTTP server inside the agent container that exposes the `AgentRpc`
operations. Runs on a known port (e.g., 9095).

```
POST /rpc/spawn       {session, command, workDir}
POST /rpc/send-text   {session, text}
POST /rpc/paste-text  {session, text}
GET  /rpc/capture     ?session=X&lines=50
GET  /rpc/list
POST /rpc/kill        {session}
GET  /rpc/health
```

This is ~200 lines of PHP using Swoole — a thin HTTP wrapper around TmuxService.

The agent container image is the same Dockerfile (it already has tmux, Claude
Code, etc.), just with a different entrypoint:

```yaml
# docker-compose.yml
services:
  emperor:
    build: .
    entrypoint: ["/app/scripts/docker-entrypoint.sh"]
    ports: ["9090:9090", "9091:9091"]
    volumes:
      - ./src:/app/src
      - ./data:/app/data

  agents:
    build: .
    entrypoint: ["php", "bin/voidlux", "agent-host", "--rpc-port=9095"]
    ports: ["5900-5910:5900-5910"]
    volumes:
      - ./workbench:/app/workbench
      - claude-auth:/root/.claude
```

### Phase 3: AgentMonitor Adapts

AgentMonitor currently does `tmux list-panes -a` locally. With remote agents:
- Calls `GET /rpc/list` on agent container(s)
- Each agent container returns its local sessions
- AgentMonitor merges results and proceeds as before
- The batched tmux optimization still works — it's batched per container now

### Phase 4: Multi-Agent Scaling

Once the RPC layer works, scaling is trivial:

```yaml
services:
  agents:
    deploy:
      replicas: 3
```

Each agent container registers with the emperor via P2P (they're worker nodes).
The emperor dispatches tasks to whichever container has idle agents.

This is actually **close to the existing worker model** — the P2P protocol already
supports multi-node agents via AGENT_REGISTER/HEARTBEAT. The main gap is that
workers currently run their own emperor fallback logic. Agent containers would be
pure workers — no task queue, no dispatcher, just RPC + P2P heartbeats.

## Migration Path

| Phase | Effort | Impact |
|-------|--------|--------|
| 1. AgentRpc interface | 2-3 hours | Zero behavior change, clean abstraction |
| 2. Agent RPC server | 3-4 hours | Separate container possible |
| 3. AgentMonitor remote | 1-2 hours | Full separation working |
| 4. Multi-agent scaling | 1 hour | docker compose scale |

**Total: ~1 day of work**

Phase 1 can ship independently — it's just a refactor with no risk. Phases 2-3
ship together as the actual container split. Phase 4 is configuration only.

## Key Design Decisions

1. **HTTP RPC, not WebSocket**: Agent operations are request/response (send text,
   capture pane). No need for persistent connections. HTTP is simpler to debug
   and doesn't need reconnection logic.

2. **Same Docker image**: Both containers use the same image. Only the entrypoint
   differs. Keeps the build simple.

3. **Shared workbench volume**: Both containers mount `./workbench`. Agents write
   code there, emperor reads results. Git operations work because they share the
   same filesystem view.

4. **Claude auth volume shared**: The `claude-auth` volume mounts in the agent
   container so Claude Code can authenticate.

5. **Agent container owns VNC ports**: X11/VNC stays with the agent container
   since that's where the GUI apps run.

6. **P2P still works**: Agent containers join the P2P mesh as workers. Gossip
   propagates agent heartbeats and task state as before. The RPC layer is
   orthogonal to gossip — it's just the tmux I/O channel.

## What Doesn't Change

- Task lifecycle (create → plan → dispatch → claim → deliver → complete)
- SwarmTalk forum (messages, votes, channels)
- Dashboard UI
- P2P gossip protocol
- SQLite storage (emperor-side)
- MCP tools (agents call MCP on emperor)
- Git workspace isolation
