# VoidLux

P2P swarm orchestration for AI agents over OpenSwoole gossip mesh.

VoidLux coordinates AI agents (Claude Code, OpenCode, etc.) running in tmux sessions across a decentralized P2P network. An "emperor" node drives the swarm through a web dashboard, while worker nodes register agents that auto-claim and execute tasks. All coordination flows through a gossip-based TCP mesh with Lamport-ordered conflict resolution.

## Requirements

- PHP 8.1+
- OpenSwoole extension (`pecl install openswoole`)
- SQLite3 PDO extension
- tmux
- [aoe-php](https://github.com/mfrederico/aoe-php) as a sibling directory (`../aoe-php`)

## Quick Start

```bash
# Install dependencies
composer install

# Launch the swarm: 1 emperor + 2 workers
bash scripts/demo-swarm.sh
```

Open the emperor dashboard at http://localhost:9090, then:

```bash
# Register an agent on worker 1
curl -X POST http://localhost:9091/api/swarm/agents \
  -H 'Content-Type: application/json' \
  -d '{"name":"claude-1","tool":"claude","capabilities":["php"],"project_path":"/home/you/project"}'

# Create a task from emperor
curl -X POST http://localhost:9090/api/swarm/tasks \
  -H 'Content-Type: application/json' \
  -d '{"title":"Optimize queries","description":"Review and optimize all SQL queries for performance"}'
```

The task gossips to all nodes, gets auto-claimed by an idle agent with matching capabilities, and is delivered to the agent's tmux session. The monitor polls every 5 seconds, detecting completion and propagating results back through the mesh.

## Swarm Architecture

```
                    +-----------------+
                    |    Emperor      |
                    |  Dashboard UI   |
                    |  HTTP API       |
                    |  Task Creation  |
                    +--------+--------+
                             |
                    P2P Gossip Mesh (TCP)
                    /                  \
          +--------+--------+  +--------+--------+
          |    Worker 1     |  |    Worker 2     |
          |  AgentRegistry  |  |  AgentRegistry  |
          |  AgentMonitor   |  |  AgentMonitor   |
          +---+----+----+---+  +---+----+----+---+
              |    |    |          |    |    |
           [tmux] [tmux] [tmux] [tmux] [tmux] [tmux]
           claude  claude opencode ...
```

### Task Lifecycle

1. **Emperor creates task** -- stored in SQLite, gossiped as `TASK_CREATE` to all peers
2. **Worker claims task** -- atomic SQLite update, gossiped as `TASK_CLAIM`
3. **ClaimResolver handles conflicts** -- lowest Lamport timestamp wins, ties broken by node_id
4. **AgentBridge delivers to tmux** -- checks agent is idle via StatusDetector, sends task prompt via `sendText()`
5. **AgentMonitor polls every 5s** -- `capturePane()` + StatusDetector:
   - **Running** -- extract progress, broadcast `TASK_UPDATE`
   - **Idle** -- task finished, extract result, broadcast `TASK_COMPLETE`
   - **Error** -- broadcast `TASK_FAIL`
   - **Waiting** -- flag for emperor attention (agent needs permission)
6. **All nodes converge** via gossip + periodic anti-entropy sync

### Conflict Resolution

When multiple workers claim the same task simultaneously:
- Each claim carries a Lamport timestamp
- Lower timestamp wins
- Equal timestamps are broken by lexicographic node_id comparison
- Losing nodes revert to pending, allowing re-claim

This ensures exactly one agent works each task with no central coordinator.

## CLI Commands

```bash
# Run the swarm server
php bin/voidlux swarm [options]

# Run the graffiti wall demo
php bin/voidlux demo [options]

# Detect extensions in a PHP project
php bin/voidlux detect <app-dir>

# Compile to static binary
php bin/voidlux compile <app-dir> --output=./build/myapp
```

### Swarm Options

| Option | Default | Description |
|---|---|---|
| `--http-port` | 9090 | HTTP/WebSocket server port |
| `--p2p-port` | 7101 | P2P TCP mesh port |
| `--discovery-port` | 6101 | UDP LAN discovery port |
| `--seeds` | (none) | Comma-separated seed peers (`host:port,...`) |
| `--data-dir` | ./data | SQLite database directory |
| `--role` | emperor | Role: `emperor` or `worker` |

### Example: 3-Node Swarm

```bash
# Emperor node
php bin/voidlux swarm --role=emperor --http-port=9090 --p2p-port=7101

# Worker 1 (seeds to emperor)
php bin/voidlux swarm --role=worker --http-port=9091 --p2p-port=7102 --seeds=127.0.0.1:7101

# Worker 2
php bin/voidlux swarm --role=worker --http-port=9092 --p2p-port=7103 --seeds=127.0.0.1:7101
```

Or just run `bash scripts/demo-swarm.sh` to launch all three in tmux.

## HTTP API

### Tasks

| Method | Path | Description |
|---|---|---|
| `GET` | `/api/swarm/tasks` | List tasks (filter with `?status=pending`) |
| `POST` | `/api/swarm/tasks` | Create task |
| `GET` | `/api/swarm/tasks/{id}` | Get task detail |
| `POST` | `/api/swarm/tasks/{id}/cancel` | Cancel task |

#### Create Task

```bash
curl -X POST http://localhost:9090/api/swarm/tasks \
  -H 'Content-Type: application/json' \
  -d '{
    "title": "Add unit tests",
    "description": "Write PHPUnit tests for the auth service",
    "priority": 5,
    "required_capabilities": ["php", "testing"],
    "project_path": "/home/you/project",
    "context": "Focus on edge cases"
  }'
```

Fields:
- **title** (required) -- short task name
- **description** -- detailed instructions sent to the agent
- **priority** -- higher numbers are claimed first (default: 0)
- **required_capabilities** -- only agents with all listed capabilities can claim
- **project_path** -- working directory for the agent
- **context** -- additional context appended to the prompt

### Agents

| Method | Path | Description |
|---|---|---|
| `GET` | `/api/swarm/agents` | List all agents across the swarm |
| `POST` | `/api/swarm/agents` | Register agent (creates tmux session) |
| `DELETE` | `/api/swarm/agents/{id}` | Deregister agent |
| `POST` | `/api/swarm/agents/{id}/send` | Send text to agent's tmux |
| `GET` | `/api/swarm/agents/{id}/output` | Capture agent's pane output |

#### Register Agent

```bash
curl -X POST http://localhost:9091/api/swarm/agents \
  -H 'Content-Type: application/json' \
  -d '{
    "name": "claude-worker-1",
    "tool": "claude",
    "capabilities": ["php", "testing", "refactoring"],
    "project_path": "/home/you/project"
  }'
```

If `project_path` is provided without `tmux_session_id`, a tmux session is automatically created with the specified tool (claude/opencode) running inside.

### Status

| Method | Path | Description |
|---|---|---|
| `GET` | `/` | Emperor dashboard (WebUI) |
| `GET` | `/health` | Health check |
| `GET` | `/api/swarm/status` | Swarm overview with task/agent counts |

## Wire Protocol

Messages use JSON with a 4-byte uint32 big-endian length prefix.

### Core Messages

| Type | Code | Description |
|---|---|---|
| HELLO | 0x01 | Handshake with node ID |
| POST | 0x02 | Graffiti wall post |
| SYNC_REQ | 0x03 | Request posts since lamport_ts |
| SYNC_RSP | 0x04 | Response with missed posts |
| PEX | 0x05 | Peer exchange |
| PING | 0x06 | Keepalive |
| PONG | 0x07 | Keepalive response |

### Swarm Messages

| Type | Code | Description |
|---|---|---|
| TASK_CREATE | 0x10 | New task announcement |
| TASK_CLAIM | 0x11 | Agent claims a task |
| TASK_UPDATE | 0x12 | Progress update |
| TASK_COMPLETE | 0x13 | Task completed with result |
| TASK_FAIL | 0x14 | Task failed with error |
| TASK_CANCEL | 0x15 | Task cancelled |
| AGENT_REGISTER | 0x20 | New agent announcement |
| AGENT_HEARTBEAT | 0x21 | Agent status heartbeat |
| TASK_SYNC_REQ | 0x30 | Pull-based task sync request |
| TASK_SYNC_RSP | 0x31 | Task sync response |

## P2P Networking

- **TCP Mesh** -- Swoole coroutine TCP server + client connections
- **UDP Broadcast** -- LAN peer discovery on `255.255.255.255`
- **Seed Peers** -- Static peer list for WAN bootstrap
- **Peer Exchange (PEX)** -- Gossip-based peer list sharing every 30s
- **Gossip Engine** -- Push-based dissemination with UUID dedup
- **Anti-Entropy** -- Pull-based consistency repair every 60s
- **Lamport Clock** -- Logical timestamps for causal ordering

## Project Structure

```
voidlux/
├── bin/voidlux                           CLI entry point
├── src/
│   ├── Compat/OpenSwooleShim.php         OpenSwoole ↔ Swoole aliases
│   ├── Compiler/                         Static binary compiler
│   ├── Template/                         {{VAR}} template engine
│   ├── P2P/
│   │   ├── PeerManager.php               Peer lifecycle management
│   │   ├── Discovery/                    UDP broadcast, seeds, PEX
│   │   ├── Protocol/                     Message types, codec, Lamport clock
│   │   ├── Gossip/                       Push gossip + pull anti-entropy
│   │   └── Transport/                    TCP mesh + connection wrapper
│   ├── App/GraffitiWall/                 Graffiti wall demo
│   └── Swarm/
│       ├── Server.php                    Main swarm server (HTTP+WS+TCP+UDP)
│       ├── SwarmWebUI.php                Emperor dashboard HTML
│       ├── SwarmWebSocketHandler.php     Real-time WS push
│       ├── Model/
│       │   ├── TaskModel.php             Task value object
│       │   ├── AgentModel.php            Agent value object
│       │   └── TaskStatus.php            Status enum
│       ├── Storage/
│       │   └── SwarmDatabase.php         SQLite persistence
│       ├── Gossip/
│       │   ├── TaskGossipEngine.php      Push-based task dissemination
│       │   └── TaskAntiEntropy.php       Pull-based task sync
│       ├── Orchestrator/
│       │   ├── TaskQueue.php             Task lifecycle management
│       │   ├── ClaimResolver.php         Lamport-ordered conflict resolution
│       │   └── EmperorController.php     HTTP API controller
│       └── Agent/
│           ├── AgentRegistry.php         Registration + heartbeats
│           ├── AgentBridge.php           aoe-php TmuxService wrapper
│           └── AgentMonitor.php          Coroutine polling loop
├── scripts/
│   ├── demo-swarm.sh                     1 emperor + 2 workers
│   ├── demo-5-nodes.sh                   5-node graffiti wall
│   ├── build-graffiti.sh                 Compile graffiti wall binary
│   └── install-spc.sh                    Install static-php-cli
├── templates/                            Compiler templates
└── composer.json
```

## Graffiti Wall Demo

The original demo application -- a P2P chat wall where posts propagate via gossip:

```bash
php bin/voidlux demo --http-port=8080
# Or launch 5 nodes:
bash scripts/demo-5-nodes.sh
```

## Static Binary Compiler

VoidLux can compile PHP/OpenSwoole applications into standalone static binaries using [static-php-cli](https://github.com/crazywhalecc/static-php-cli):

```bash
bash scripts/install-spc.sh
bash scripts/build-graffiti.sh
./build/graffiti-wall demo --http-port=8080
```

## License

MIT
