# Galactic Marketplace — Architecture Analysis & Foundation Mapping

> Deep-dive analysis of VoidLux's existing P2P protocol, Seneschal proxy, gossip engine, marketplace models, and process lifecycle. Identifies reusable primitives and gaps for building the full Galactic Marketplace.

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Reusable P2P Primitives](#2-reusable-p2p-primitives)
3. [Wire Protocol & Message Types](#3-wire-protocol--message-types)
4. [Gossip & State Synchronization](#4-gossip--state-synchronization)
5. [Existing Marketplace Models](#5-existing-marketplace-models)
6. [Node Identity & Trust](#6-node-identity--trust)
7. [Process Lifecycle & Startup](#7-process-lifecycle--startup)
8. [State Persistence Patterns](#8-state-persistence-patterns)
9. [Dashboard & API Surface](#9-dashboard--api-surface)
10. [Merge-Test-Retry Flow](#10-merge-test-retry-flow)
11. [Architectural Gaps](#11-architectural-gaps)
12. [Implementation Roadmap](#12-implementation-roadmap)

---

## 1. Executive Summary

The VoidLux codebase already contains **substantial infrastructure** for a decentralized marketplace. The P2P layer provides gossip dissemination, anti-entropy sync, Lamport ordering, and discovery. The Swarm layer adds task lifecycle, agent orchestration, and leader election. The Galactic namespace has stub models for offerings and tributes.

### What Exists (Reusable)

| Primitive | Location | Status |
|-----------|----------|--------|
| TCP mesh with node-dedup | `src/P2P/Transport/TcpMesh.php` | Production |
| Length-prefixed JSON codec | `src/P2P/Protocol/MessageCodec.php` | Production |
| Lamport clock (causal ordering) | `src/P2P/Protocol/LamportClock.php` | Production |
| Push gossip with dedup | `src/P2P/Gossip/GossipEngine.php` | Production |
| Pull anti-entropy sync | `src/P2P/Gossip/AntiEntropy.php` | Production |
| Peer discovery (UDP + PEX + DHT + seeds) | `src/P2P/Discovery/` | Production |
| Wire protocol types 0xC0-0xC4 | `src/P2P/Protocol/MessageTypes.php` | Defined, not wired |
| OfferingModel | `src/Swarm/Galactic/OfferingModel.php` | In-memory only |
| TributeModel | `src/Swarm/Galactic/TributeModel.php` | In-memory only |
| GalacticMarketplace engine | `src/Swarm/Galactic/GalacticMarketplace.php` | In-memory, local-only |
| Dashboard marketplace UI | `src/Swarm/SwarmWebUI.php` | Rendering stubs |
| HTTP API endpoints | `src/Swarm/Orchestrator/EmperorController.php` | 6 endpoints active |
| WebSocket push | `src/Swarm/SwarmWebSocketHandler.php` | Status events only |
| Task lifecycle + dispatch | `src/Swarm/Orchestrator/` | Full pipeline |
| Agent lifecycle + tmux bridge | `src/Swarm/Agent/` | Full pipeline |
| Git worktree isolation | `src/Swarm/Git/GitWorkspace.php` | Production |
| Leader election (Bully) | `src/Swarm/Leadership/LeaderElection.php` | Production |
| MCP agent protocol | `src/Swarm/Mcp/McpHandler.php` | Production |

### What's Missing (Gaps)

| Gap | Impact | Priority |
|-----|--------|----------|
| Marketplace gossip (0xC0-0xC4 not wired to mesh) | Offerings/tributes are local-only, invisible to peers | Critical |
| SQLite persistence for offerings/tributes | State lost on restart | Critical |
| Marketplace anti-entropy (pull sync) | Network partitions lose marketplace state | High |
| Tribute negotiation protocol (accept/reject via P2P) | Tributes can only be created, not settled across nodes | High |
| Wallet persistence & transfer logic | Wallet always returns 1000 VOID, no actual ledger | High |
| Offering expiration gossip | Peers don't learn about withdrawals/expirations | Medium |
| Cross-node task dispatch for tributes | Tribute acceptance doesn't actually dispatch tasks to buyer | Medium |
| Price discovery / reputation | No mechanism to price agents or rate providers | Low |
| DHT-based offering index | Offerings only available via gossip flood | Low |
| Consensus for payments | No 2PC or Raft for transaction safety | Low (VOID is stub) |

---

## 2. Reusable P2P Primitives

### 2.1 TCP Mesh (`src/P2P/Transport/TcpMesh.php`)

**Capability:** Full-duplex peer connections with node-ID-indexed dedup.

**Key API:**
```php
$mesh->start();                              // Accept loop
$mesh->connectTo($host, $port);              // Dial peer
$mesh->broadcast($message, $excludeAddr);    // Flood all peers
$mesh->sendTo($nodeId, $message);            // Targeted send
$mesh->hasNodeConnection($nodeId);           // Check connectivity
```

**Connection Dedup:** When two peers establish mutual connections, the lower node_id keeps outbound, higher keeps inbound. Prevents duplicate edges.

**Marketplace Use:** Direct channel for OFFERING_ANNOUNCE broadcasts and targeted TRIBUTE_REQUEST/ACCEPT/REJECT messages.

### 2.2 Message Codec (`src/P2P/Protocol/MessageCodec.php`)

**Format:** 4-byte big-endian uint32 length prefix + JSON payload.

**Marketplace Use:** All marketplace messages use the same framing. No changes needed.

### 2.3 Lamport Clock (`src/P2P/Protocol/LamportClock.php`)

**API:**
```php
$ts = $clock->tick();            // Local event (create offering)
$ts = $clock->witness($remote);  // Remote event (receive offering)
$val = $clock->value();          // Current counter
```

**Marketplace Use:** Ordering concurrent offerings. Resolving conflicting tribute claims. Anti-entropy "since" queries.

### 2.4 Gossip Engine (`src/P2P/Gossip/GossipEngine.php`)

**Pattern:** Push-based flooding with UUID dedup (seenMessages set, max 10k entries).

**Marketplace Extension Points:**
- `OFFERING_ANNOUNCE` (0xC0) — gossip new offerings to all peers
- `OFFERING_WITHDRAW` (0xC1) — gossip offering retraction
- `TRIBUTE_REQUEST` (0xC2) — targeted to offering node (not broadcast)
- `TRIBUTE_ACCEPT/REJECT` (0xC3/0xC4) — targeted response

### 2.5 Anti-Entropy (`src/P2P/Gossip/AntiEntropy.php`)

**Pattern:** Every 30-60s, pick random peer, pull state since max Lamport TS.

**Marketplace Use:** New anti-entropy class for offerings/tributes. Handles network partitions, late-joining nodes.

### 2.6 Discovery Stack (`src/P2P/Discovery/`)

| Mechanism | File | Interval | Scope |
|-----------|------|----------|-------|
| UDP broadcast | `UdpBroadcast.php` | 10s | LAN |
| Peer exchange (PEX) | `PeerExchange.php` | 30s | WAN |
| DHT lookup (Kademlia) | `DhtDiscovery.php` | On-demand | WAN |
| Seed peers | `SeedPeers.php` | On startup | Bootstrap |

**Marketplace Use:** No changes needed. Marketplace peers are discovered through the same mesh.

### 2.7 Peer Manager (`src/P2P/PeerManager.php`)

**Features:** Max 20 connections, PING/PONG keepalive (15s), reconnection (10s), known address tracking.

**Marketplace Use:** Marketplace traffic shares the same connections. No separate transport needed.

---

## 3. Wire Protocol & Message Types

### 3.1 Existing Protocol Map (`src/P2P/Protocol/MessageTypes.php`)

```
0x01-0x07  Core P2P (HELLO, POST, SYNC, PEX, PING/PONG)
0x10-0x17  Swarm Tasks (CREATE, CLAIM, UPDATE, COMPLETE, FAIL, CANCEL, ASSIGN, ARCHIVE)
0x20-0x22  Swarm Agents (REGISTER, HEARTBEAT, DEREGISTER)
0x30-0x31  Task Sync (SYNC_REQ, SYNC_RSP)
0x40-0x42  Leader Election (EMPEROR_HEARTBEAT, ELECTION_START, ELECTION_VICTORY)
0x50-0x52  Census & Agent Sync
0x60-0x62  Authentication (CHALLENGE, RESPONSE, REJECT)
0x70-0x73  Identity (ANNOUNCE, CREDENTIAL_ISSUE, SYNC)
0x80-0x85  Consensus (PROPOSE, VOTE, COMMIT, ABORT, SYNC)
0x90-0x95  DHT Storage (PUT, GET, GET_RSP, DELETE, SYNC)
0xA0-0xA2  DHT Discovery (LOOKUP, LOOKUP_RSP, ANNOUNCE)
0xB0-0xB1  Swarm Node Registry (REGISTER, STATUS)
0xC0-0xC4  Galactic Marketplace (OFFERING_ANNOUNCE, OFFERING_WITHDRAW, TRIBUTE_REQUEST, TRIBUTE_ACCEPT, TRIBUTE_REJECT)
```

### 3.2 Marketplace Message Definitions (Existing Constants, No Handler)

```php
const OFFERING_ANNOUNCE = 0xC0;   // Broadcast: new offering available
const OFFERING_WITHDRAW = 0xC1;   // Broadcast: offering retracted
const TRIBUTE_REQUEST   = 0xC2;   // Targeted: buyer → seller
const TRIBUTE_ACCEPT    = 0xC3;   // Targeted: seller → buyer
const TRIBUTE_REJECT    = 0xC4;   // Targeted: seller → buyer
```

### 3.3 Proposed Message Payloads

**OFFERING_ANNOUNCE (0xC0) — Broadcast:**
```json
{
  "type": 192,
  "offering": {
    "id": "uuid",
    "node_id": "32-hex",
    "idle_agents": 3,
    "capabilities": ["php", "testing"],
    "price_per_task": 1,
    "currency": "VOID",
    "expires_at": "2026-02-18T12:00:00Z",
    "created_at": "2026-02-18T11:55:00Z"
  },
  "lamport_ts": 42
}
```

**OFFERING_WITHDRAW (0xC1) — Broadcast:**
```json
{
  "type": 193,
  "offering_id": "uuid",
  "node_id": "32-hex",
  "lamport_ts": 43
}
```

**TRIBUTE_REQUEST (0xC2) — Targeted (buyer → seller):**
```json
{
  "type": 194,
  "tribute": {
    "id": "uuid",
    "offering_id": "uuid",
    "from_node_id": "32-hex-buyer",
    "to_node_id": "32-hex-seller",
    "task_count": 2,
    "total_cost": 2,
    "currency": "VOID"
  },
  "lamport_ts": 44
}
```

**TRIBUTE_ACCEPT (0xC3) — Targeted (seller → buyer):**
```json
{
  "type": 195,
  "tribute_id": "uuid",
  "tx_hash": "0x...",
  "lamport_ts": 45
}
```

**TRIBUTE_REJECT (0xC4) — Targeted (seller → buyer):**
```json
{
  "type": 196,
  "tribute_id": "uuid",
  "reason": "No agents available",
  "lamport_ts": 46
}
```

---

## 4. Gossip & State Synchronization

### 4.1 Current Gossip Layers

| Layer | Engine | Anti-Entropy | Dedup Key Format |
|-------|--------|-------------|------------------|
| Graffiti Posts | `GossipEngine` | `AntiEntropy` | `post_id` |
| Tasks | `TaskGossipEngine` | `TaskAntiEntropy` | `task_id:action:lamport_ts` |
| Agents | (via AgentRegistry) | `AgentAntiEntropy` | `hb:{agent_id}:{lamport_ts}` |
| **Marketplace** | **Not implemented** | **Not implemented** | — |

### 4.2 Marketplace Gossip Design

**Push Layer (MarketplaceGossipEngine):**
- `gossipOfferingAnnounce(offering)` — broadcast to all peers
- `gossipOfferingWithdraw(offeringId)` — broadcast tombstone
- `receiveOfferingAnnounce(data)` — insert into local store + re-broadcast
- `receiveOfferingWithdraw(data)` — remove from local store + re-broadcast
- Dedup key: `offering:{id}:{action}:{lamport_ts}`

**Targeted Messages (No Gossip):**
- `TRIBUTE_REQUEST` sent via `mesh->sendTo(toNodeId, msg)`
- `TRIBUTE_ACCEPT/REJECT` sent via `mesh->sendTo(fromNodeId, msg)`
- These are bilateral negotiations, not broadcast

**Pull Layer (MarketplaceAntiEntropy):**
- Every 60s, sync offerings from random peer
- Request: `MARKETPLACE_SYNC_REQ` with `since_lamport_ts`
- Response: `MARKETPLACE_SYNC_RSP` with offerings since TS
- Handles late-joining nodes and partition recovery
- Requires new message types (suggest 0xC5/0xC6)

### 4.3 Convergence Guarantees

| Scenario | Push Handles? | Pull Handles? | Notes |
|----------|---------------|---------------|-------|
| Normal operation | Yes | Redundant | Broadcast propagates immediately |
| Temporary partition | No | Yes | Anti-entropy catches up after rejoin |
| Late-joining node | No | Yes | First sync pulls all active offerings |
| Offering expiration | N/A | N/A | Each node prunes locally based on `expires_at` |

---

## 5. Existing Marketplace Models

### 5.1 OfferingModel (`src/Swarm/Galactic/OfferingModel.php`)

```php
public readonly string $id;           // UUID
public readonly string $nodeId;       // Provider's persistent node ID
public readonly int $idleAgents;      // Available agent count
public readonly array $capabilities;  // Agent skills
public readonly int $pricePerTask;    // Cost per task (default: 1)
public readonly string $currency;     // "VOID"
public readonly string $expiresAt;    // ISO 8601 (TTL: 300s default)
public readonly string $createdAt;

// Methods: create(), fromArray(), toArray(), isExpired(), generateUuid()
```

**Gap:** No SQLite persistence. In-memory array in `GalacticMarketplace`.

### 5.2 TributeModel (`src/Swarm/Galactic/TributeModel.php`)

```php
public readonly string $id;           // UUID
public readonly string $offeringId;   // Referenced offering
public readonly string $fromNodeId;   // Buyer
public readonly string $toNodeId;     // Seller
public readonly int $taskCount;       // Requested task count
public readonly int $totalCost;       // taskCount * pricePerTask
public readonly string $currency;     // "VOID"
public readonly string $status;       // pending | accepted | rejected | completed
public readonly string $txHash;       // Stub: 0x{64-hex}
public readonly string $createdAt;

// Methods: create(), fromArray(), toArray(), withStatus()
```

**Gap:** No P2P negotiation. Status changes are local-only.

### 5.3 GalacticMarketplace Engine (`src/Swarm/Galactic/GalacticMarketplace.php`)

**Current State:**
- Constructor takes `string $nodeId`
- All state in-memory arrays (`$offerings`, `$tributes`)
- `announceOffering()` — creates OfferingModel locally
- `withdrawOffering()` — removes from local array
- `receiveOffering()` / `receiveWithdraw()` — handlers for gossip (exist but not wired)
- `requestTribute()` — creates TributeModel with stub txHash
- `acceptTribute()` / `rejectTribute()` — update local status
- `getWallet()` — always returns `['balance' => 1000, 'currency' => 'VOID']`

**What Works:**
- Model creation and serialization
- Local CRUD operations
- Dashboard rendering from these models

**What Doesn't:**
- No mesh integration (receiveOffering/receiveWithdraw never called from P2P)
- No SQLite tables (state lost on restart)
- No cross-node tribute negotiation
- No wallet ledger (balance is hardcoded)
- No task dispatch triggered by tribute acceptance

---

## 6. Node Identity & Trust

### 6.1 Current Identity System

**Generation:** `bin2hex(random_bytes(16))` — 32 hex chars, cryptographically random.

**Persistence:** SQLite `swarm_state` table, key `node_id`. Survives restarts.

**Usage:**
- P2P routing (`mesh->sendTo($nodeId, ...)`)
- Agent ownership (`agent.nodeId`)
- Task attribution (`task.createdBy`, `task.assignedNode`)
- Leader election tiebreaker (lowest nodeId wins)
- Dashboard display (8-char shortened prefix)
- Offering ownership (`offering.nodeId`)
- Tribute routing (`tribute.fromNodeId`, `tribute.toNodeId`)

### 6.2 Authentication Framework (Defined, Not Implemented)

**Wire protocol constants exist:**
```php
const AUTH_CHALLENGE  = 0x60;
const AUTH_RESPONSE   = 0x61;
const AUTH_REJECT     = 0x62;
```

**Identity constants exist:**
```php
const IDENTITY_ANNOUNCE   = 0x70;
const CREDENTIAL_ISSUE    = 0x71;
const IDENTITY_SYNC_REQ   = 0x72;
const IDENTITY_SYNC_RSP   = 0x73;
```

**Gap:** No implementation. All peers currently trusted on HELLO handshake. For marketplace, this means:
- Any node can impersonate another node's offerings
- No way to verify wallet balance claims
- No cryptographic proof of identity

### 6.3 Consensus Framework (Defined, Not Implemented)

```php
const CONSENSUS_PROPOSE   = 0x80;
const CONSENSUS_VOTE      = 0x81;
const CONSENSUS_COMMIT    = 0x82;
const CONSENSUS_ABORT     = 0x83;
const CONSENSUS_SYNC_REQ  = 0x84;
const CONSENSUS_SYNC_RSP  = 0x85;
```

**Gap:** No implementation. Would be needed for atomic wallet transfers if VOID becomes real currency.

---

## 7. Process Lifecycle & Startup

### 7.1 Server Initialization Sequence (`src/Swarm/Server.php`)

```
1. SQLite DB: data/swarm-{p2pPort}.db (WAL mode)
2. Node ID: load from swarm_state or generate + persist
3. Lamport Clock: restore from swarm_state
4. HTTP/WS Server: Swoole\WebSocket\Server on httpPort

on('workerStart'):
  5. initComponents()
     ├── TcpMesh(0.0.0.0, p2pPort, nodeId)
     ├── PeerManager(mesh, nodeId)
     ├── TaskGossipEngine(mesh, db, clock)
     ├── AgentRegistry(db, nodeId, gossip)
     ├── AgentMonitor(db, nodeId, bridge, registry)
     ├── TaskQueue(db, gossip, clock)
     ├── TaskDispatcher(queue, registry, mesh, bridge) [emperor only]
     ├── LeaderElection(mesh, nodeId, httpPort, p2pPort)
     ├── DiscoveryManager(mesh, peerMgr, nodeId, ...)
     ├── DhtStorage + DhtEngine
     ├── GalacticMarketplace(nodeId)  ← marketplace init
     ├── EmperorController(queue, registry, ...)
     └── SwarmWebSocketHandler(server)

  6. startP2P()
     ├── mesh->start()
     ├── peerManager->start()
     └── discoveryManager->start()

  7. startSwarm()
     ├── Requeue orphaned tasks
     ├── Wellness check (prune dead agents)
     ├── [Emperor] initEmperorAi() → LLM client, planner, reviewer, dispatcher
     ├── taskAntiEntropy->start()
     ├── agentAntiEntropy->start()
     ├── agentRegistry->start() (heartbeat broadcast)
     ├── agentMonitor->start() (5s poll)
     ├── leaderElection->start()
     └── Periodic clock persistence (30s)
```

### 7.2 Seneschal Lifecycle (`src/Swarm/Seneschal.php`)

```
1. Ephemeral node ID (no persistence)
2. HTTP proxy server on httpPort
3. P2P mesh (passive listener, role: seneschal)
4. Tracks emperor via EMPEROR_HEARTBEAT + ELECTION_VICTORY
5. HTTP: coroutine-based proxy to current emperor
6. WebSocket: relay via upstream HttpClient::upgrade('/ws')
7. On emperor change: close all upstream WS, browser auto-reconnects
```

**Marketplace Impact:** Seneschal proxies marketplace API calls to emperor. No marketplace state of its own.

### 7.3 Demo Script (`scripts/demo-swarm.sh`)

```bash
# Topology:
# Seneschal (9090/7100) → Emperor (9091/7101) → Worker1 (9092/7102) + Worker2 (9093/7103)

# Kills orphaned tmux sessions (vl-*) on restart
# Env vars: VOIDLUX_LLM_MODEL, VOIDLUX_LLM_PROVIDER, ANTHROPIC_API_KEY, VOIDLUX_TEST_COMMAND
```

---

## 8. State Persistence Patterns

### 8.1 SQLite Schema (Per-Node)

**Existing Tables:**

```sql
-- Task storage (fully indexed)
CREATE TABLE tasks (
    id TEXT PRIMARY KEY,
    title TEXT, description TEXT, status TEXT, priority INTEGER,
    required_capabilities TEXT, -- JSON array
    created_by TEXT, assigned_to TEXT, assigned_node TEXT,
    result TEXT, error TEXT, progress TEXT,
    project_path TEXT, context TEXT,
    lamport_ts INTEGER,
    claimed_at TEXT, completed_at TEXT, created_at TEXT, updated_at TEXT,
    parent_id TEXT,
    work_instructions TEXT, acceptance_criteria TEXT,
    review_status TEXT, review_feedback TEXT,
    archived INTEGER DEFAULT 0,
    git_branch TEXT, merge_attempts INTEGER DEFAULT 0, test_command TEXT
);

-- Agent registry
CREATE TABLE agents (
    id TEXT PRIMARY KEY,
    node_id TEXT, name TEXT, tool TEXT, model TEXT,
    capabilities TEXT, -- JSON array
    tmux_session_id TEXT, project_path TEXT,
    max_concurrent_tasks INTEGER DEFAULT 1,
    status TEXT, current_task_id TEXT,
    last_heartbeat TEXT, lamport_ts INTEGER, registered_at TEXT
);

-- Key-value state
CREATE TABLE swarm_state (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

-- Indexes
CREATE INDEX idx_tasks_status ON tasks(status);
CREATE INDEX idx_tasks_lamport ON tasks(lamport_ts);
CREATE INDEX idx_tasks_priority ON tasks(priority DESC, created_at ASC);
CREATE INDEX idx_tasks_parent ON tasks(parent_id);
CREATE INDEX idx_tasks_archived ON tasks(archived);
CREATE INDEX idx_agents_node ON agents(node_id);
CREATE INDEX idx_agents_status ON agents(status);
```

### 8.2 Proposed Marketplace Tables

```sql
-- Offerings (replicated via gossip)
CREATE TABLE offerings (
    id TEXT PRIMARY KEY,
    node_id TEXT NOT NULL,
    idle_agents INTEGER NOT NULL,
    capabilities TEXT DEFAULT '[]',  -- JSON array
    price_per_task INTEGER DEFAULT 1,
    currency TEXT DEFAULT 'VOID',
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    lamport_ts INTEGER NOT NULL,
    withdrawn INTEGER DEFAULT 0       -- tombstone flag
);
CREATE INDEX idx_offerings_node ON offerings(node_id);
CREATE INDEX idx_offerings_expires ON offerings(expires_at);
CREATE INDEX idx_offerings_active ON offerings(withdrawn, expires_at);

-- Tributes (bilateral, not gossipped)
CREATE TABLE tributes (
    id TEXT PRIMARY KEY,
    offering_id TEXT NOT NULL,
    from_node_id TEXT NOT NULL,
    to_node_id TEXT NOT NULL,
    task_count INTEGER NOT NULL,
    total_cost INTEGER NOT NULL,
    currency TEXT DEFAULT 'VOID',
    status TEXT DEFAULT 'pending',     -- pending, accepted, rejected, completed
    tx_hash TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT,
    lamport_ts INTEGER NOT NULL,
    FOREIGN KEY (offering_id) REFERENCES offerings(id)
);
CREATE INDEX idx_tributes_status ON tributes(status);
CREATE INDEX idx_tributes_from ON tributes(from_node_id);
CREATE INDEX idx_tributes_to ON tributes(to_node_id);

-- Wallet ledger (local per-node)
CREATE TABLE wallet_ledger (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tribute_id TEXT,
    amount INTEGER NOT NULL,
    direction TEXT NOT NULL,           -- credit | debit
    counterparty_node_id TEXT,
    balance_after INTEGER NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX idx_wallet_tribute ON wallet_ledger(tribute_id);
```

### 8.3 State Management Patterns

| Pattern | Used By | Marketplace Applicability |
|---------|---------|--------------------------|
| SQLite + WAL mode | Tasks, Agents, swarm_state | Offerings, Tributes, Wallet |
| In-memory arrays + gossip sync | SeenMessages dedup | Offering dedup set |
| Lamport TS on all records | Tasks, Agents | Offerings, Tributes |
| Anti-entropy periodic pull | Task sync, Agent sync | Offering sync |
| Gossip broadcast + forward | Task create/update, Agent heartbeat | Offering announce/withdraw |
| Targeted P2P send | TASK_ASSIGN (emperor → worker) | TRIBUTE_REQUEST/ACCEPT/REJECT |
| Tombstone + TTL | Agent deregister (120s tombstone) | Offering withdraw + expiry |

---

## 9. Dashboard & API Surface

### 9.1 Existing Marketplace HTTP Endpoints

| Method | Endpoint | Current Behavior | Gap |
|--------|----------|-----------------|-----|
| GET | `/api/swarm/offerings` | Returns in-memory offerings (incl. expired) | No persistence, no gossip |
| POST | `/api/swarm/offerings` | Creates local offering | Doesn't broadcast to peers |
| DELETE | `/api/swarm/offerings/{id}` | Removes from local array | Doesn't gossip withdrawal |
| POST | `/api/swarm/tributes` | Creates local tribute with stub txHash | No P2P negotiation |
| GET | `/api/swarm/tributes` | Returns local tributes | No cross-node state |
| GET | `/api/swarm/wallet` | Always returns `{balance: 1000, currency: 'VOID'}` | No ledger |

### 9.2 Existing Dashboard UI Components

**Galactic Marketplace Section:**
- Offering cards: idle agent count, price/task, node ID, capabilities, expiration, "Request Tribute" button
- Wallet badge: balance + currency display
- Tribute history: status-colored rows with transaction hash, task count, total cost

**Pipeline Status Indicator:**
- 5-phase progress: Planning → Working → Reviewing → Merging → Done
- Active phase highlighted, connectors change color

**Contributions View:**
- Auto-discovers PR URLs from task results via regex
- Displays PR title, date, subtask completion ratio

### 9.3 WebSocket Events for Marketplace

**Current:** Only `status` event type carries marketplace data (offerings, wallet).

**Needed:**
```json
{"type": "offering_update", "event": "announced|withdrawn|expired", "offering": {...}}
{"type": "tribute_update", "event": "requested|accepted|rejected|completed", "tribute": {...}}
{"type": "wallet_update", "balance": 998, "currency": "VOID"}
```

---

## 10. Merge-Test-Retry Flow

### 10.1 Current Flow (Relevant to Cross-Node Task Execution)

```
Parent Task Created
  ↓
TaskPlanner.decompose() — LLM creates subtasks
  ↓
Subtasks dispatched to agents (potentially cross-node via tributes)
  ↓
Each agent works on task/{8-char-id} branch
  ↓
MCP task_complete → commit + push branch
  ↓
All subtasks complete → tryCompleteParent()
  ↓
Parent status → Merging
  ↓
mergeAndTest():
  1. Create merge worktree: workbench/.merge/integrate/{parentId-8char}
  2. Merge all subtask branches
     - Conflict? → requeue conflicting subtasks (max 3 attempts)
  3. Run test command
     - Failure? → requeue ALL subtasks (max 3 attempts)
  4. Success → push integration branch, create PR
  ↓
Parent status → Completed, result = PR URL
```

### 10.2 Marketplace Implications

When a tribute is accepted and tasks are dispatched to a remote node's agents:
- Those agents work in their local worktrees
- Branches are pushed to the shared git remote
- The emperor (buyer's node) still runs mergeAndTest()
- Merge conflicts or test failures trigger requeue — which may go back to the seller's agents
- **Key question:** Does tribute acceptance cover requeued tasks? Need policy: "tribute covers N task executions" vs "tribute covers task until completion"

---

## 11. Architectural Gaps

### 11.1 Critical (Must Fix for MVP)

**Gap 1: Marketplace Gossip Not Wired**
- `Server.php::onPeerMessage()` has no cases for 0xC0-0xC4
- `GalacticMarketplace.receiveOffering()` and `receiveWithdraw()` exist but are never called
- **Fix:** Add message handlers in `onPeerMessage()`, wire to marketplace engine

**Gap 2: No SQLite Persistence**
- Offerings and tributes stored in PHP arrays, lost on restart
- **Fix:** Add `offerings` and `tributes` tables to `SwarmDatabase.php`, load on startup

**Gap 3: Tribute Negotiation is Local-Only**
- `requestTribute()` creates a TributeModel locally but doesn't send to seller
- `acceptTribute()`/`rejectTribute()` update local state but don't notify buyer
- **Fix:** Wire TRIBUTE_REQUEST → mesh->sendTo(toNodeId), TRIBUTE_ACCEPT/REJECT → mesh->sendTo(fromNodeId)

### 11.2 High Priority

**Gap 4: Wallet Has No Ledger**
- `getWallet()` returns hardcoded 1000 VOID
- **Fix:** Add `wallet_ledger` table, debit on tribute acceptance, credit on tribute receipt

**Gap 5: Tribute Acceptance Doesn't Dispatch Tasks**
- When seller accepts tribute, nothing happens to the buyer's pending tasks
- **Fix:** On TRIBUTE_ACCEPT receipt, emperor should dispatch N tasks to seller's agents

**Gap 6: Offering Expiration Not Propagated**
- Each node prunes expired offerings locally, but doesn't tell peers
- Peers may show stale offerings
- **Fix:** Either gossip OFFERING_WITHDRAW on expiry, or rely on anti-entropy + local TTL check

### 11.3 Medium Priority

**Gap 7: No Marketplace Anti-Entropy**
- If a node misses an OFFERING_ANNOUNCE gossip, it never learns about it (until anti-entropy for general sync)
- **Fix:** Dedicated `MarketplaceAntiEntropy` class, new message types 0xC5/0xC6

**Gap 8: No Offering Validation**
- Node can announce 100 idle agents when it has 0
- **Fix:** Validate against actual idle agent count before announcing (emperor-local check)

**Gap 9: Cross-Node Task Results**
- When remote agents complete tasks, results come via gossip (TASK_COMPLETE)
- But the task's `result` text may be truncated or missing context
- **Fix:** Ensure git push happens before TASK_COMPLETE gossip, so buyer can inspect branch

### 11.4 Low Priority (Future)

**Gap 10: Price Discovery**
- No mechanism for market-rate pricing
- Potential: DHT-indexed price history, moving average

**Gap 11: Reputation System**
- No way to rate providers or track delivery reliability
- Potential: on-chain reputation (if VOID becomes real), or gossip-based ratings

**Gap 12: Consensus for Payments**
- Current wallet is local-only; no double-spend prevention
- Potential: 2-phase commit for atomic transfers, or Raft-based ledger

**Gap 13: Authentication**
- Wire protocol 0x60-0x62 defined but not implemented
- Any node can impersonate another
- Potential: Ed25519 key pairs for node identity, signed messages

---

## 12. Implementation Roadmap

### Phase 1: Wire It Up (Critical Gaps)

1. **Add marketplace message handlers to `Server.php::onPeerMessage()`**
   - Handle OFFERING_ANNOUNCE, OFFERING_WITHDRAW, TRIBUTE_REQUEST, TRIBUTE_ACCEPT, TRIBUTE_REJECT
   - Route to `GalacticMarketplace` methods

2. **Add gossip broadcasting to `GalacticMarketplace`**
   - `announceOffering()` → `mesh->broadcast(OFFERING_ANNOUNCE, ...)`
   - `withdrawOffering()` → `mesh->broadcast(OFFERING_WITHDRAW, ...)`
   - Dedup via seenMessages pattern

3. **Add SQLite tables for offerings/tributes**
   - Schema as defined in Section 8.2
   - Load active offerings on startup
   - Purge expired on read

4. **Wire tribute negotiation via P2P**
   - `requestTribute()` → `mesh->sendTo(toNodeId, TRIBUTE_REQUEST, ...)`
   - Seller receives → auto-accept or queue for manual review
   - `acceptTribute()`/`rejectTribute()` → `mesh->sendTo(fromNodeId, TRIBUTE_ACCEPT/REJECT, ...)`

### Phase 2: Complete the Loop

5. **Wallet ledger**
   - `wallet_ledger` table with credit/debit entries
   - Initial balance configurable (default 1000)
   - Debit on tribute request acceptance
   - Credit on tribute fulfillment receipt

6. **Cross-node task dispatch on tribute acceptance**
   - On TRIBUTE_ACCEPT, buyer's emperor dispatches N tasks to seller's idle agents
   - Tasks carry `tribute_id` for tracking
   - Seller's AgentMonitor delivers via normal flow

7. **Marketplace anti-entropy**
   - New class: `MarketplaceAntiEntropy`
   - Sync offerings every 60s from random peer
   - New message types: `MARKETPLACE_SYNC_REQ (0xC5)`, `MARKETPLACE_SYNC_RSP (0xC6)`

8. **WebSocket push for marketplace events**
   - Add `offering_update`, `tribute_update`, `wallet_update` event types
   - Dashboard renders reactively

### Phase 3: Hardening

9. **Offering validation** — check idle agent count before announcing
10. **Tribute completion tracking** — mark tribute completed when all dispatched tasks finish
11. **Offering auto-renewal** — re-announce if agents are still idle at expiry
12. **Dashboard enhancements** — offering history, tribute analytics, wallet transaction log

### Phase 4: Trust & Security (Future)

13. **Node authentication** (0x60-0x62) — challenge-response handshake
14. **Identity credentials** (0x70-0x73) — signed node announcements
15. **Payment consensus** (0x80-0x85) — atomic wallet transfers
16. **Reputation gossip** — provider reliability tracking

---

## Appendix A: File Reference

| File | Lines | Purpose |
|------|-------|---------|
| `src/P2P/Protocol/MessageTypes.php` | ~80 | Wire protocol constants |
| `src/P2P/Protocol/MessageCodec.php` | ~60 | Length-prefix framing |
| `src/P2P/Protocol/LamportClock.php` | ~30 | Causal ordering |
| `src/P2P/Transport/TcpMesh.php` | ~300 | TCP server + mesh |
| `src/P2P/Transport/Connection.php` | ~150 | Peer connection wrapper |
| `src/P2P/Gossip/GossipEngine.php` | ~200 | Push gossip + dedup |
| `src/P2P/Gossip/AntiEntropy.php` | ~100 | Pull sync |
| `src/P2P/Discovery/UdpBroadcast.php` | ~120 | LAN discovery |
| `src/P2P/Discovery/PeerExchange.php` | ~80 | Peer gossip |
| `src/P2P/Discovery/DhtDiscovery.php` | ~250 | Kademlia discovery |
| `src/Swarm/Server.php` | ~925 | Main server |
| `src/Swarm/Seneschal.php` | ~582 | Reverse proxy |
| `src/Swarm/Orchestrator/EmperorController.php` | ~600 | HTTP API |
| `src/Swarm/Orchestrator/TaskDispatcher.php` | ~250 | Push dispatch |
| `src/Swarm/Orchestrator/TaskQueue.php` | ~400 | Task lifecycle |
| `src/Swarm/Agent/AgentBridge.php` | ~200 | tmux integration |
| `src/Swarm/Agent/AgentMonitor.php` | ~300 | Agent polling |
| `src/Swarm/Agent/AgentRegistry.php` | ~250 | Agent CRUD + gossip |
| `src/Swarm/Gossip/TaskGossipEngine.php` | ~350 | Task replication |
| `src/Swarm/Gossip/TaskAntiEntropy.php` | ~100 | Task pull sync |
| `src/Swarm/Gossip/AgentAntiEntropy.php` | ~100 | Agent pull sync |
| `src/Swarm/Ai/LlmClient.php` | ~200 | Ollama + Claude |
| `src/Swarm/Ai/TaskPlanner.php` | ~200 | LLM decomposition |
| `src/Swarm/Ai/TaskReviewer.php` | ~150 | LLM code review |
| `src/Swarm/Git/GitWorkspace.php` | ~300 | Worktree management |
| `src/Swarm/Git/MergeResult.php` | ~30 | Merge outcome |
| `src/Swarm/Git/TestResult.php` | ~30 | Test outcome |
| `src/Swarm/Mcp/McpHandler.php` | ~300 | MCP JSON-RPC |
| `src/Swarm/Galactic/GalacticMarketplace.php` | ~200 | Marketplace engine |
| `src/Swarm/Galactic/OfferingModel.php` | ~80 | Offering data |
| `src/Swarm/Galactic/TributeModel.php` | ~80 | Tribute data |
| `src/Swarm/Model/TaskModel.php` | ~150 | Task data |
| `src/Swarm/Model/AgentModel.php` | ~100 | Agent data |
| `src/Swarm/Model/TaskStatus.php` | ~30 | Status enum |
| `src/Swarm/Leadership/LeaderElection.php` | ~200 | Bully election |
| `src/Swarm/Storage/SwarmDatabase.php` | ~400 | SQLite persistence |
| `src/Swarm/SwarmWebUI.php` | ~1310 | Dashboard SPA |
| `src/Swarm/SwarmWebSocketHandler.php` | ~150 | WS push |
| `scripts/demo-swarm.sh` | ~100 | Demo launcher |
| `scripts/register-agents.sh` | ~50 | Bulk agent reg |

## Appendix B: Existing vs Required Message Flow

### Current (Working)

```
                    ┌──────────────┐
                    │   Seneschal  │ (HTTP/WS proxy)
                    │  port 9090   │
                    └──────┬───────┘
                           │ proxy
                    ┌──────▼───────┐
    ┌───────────────│   Emperor    │───────────────┐
    │               │  port 9091   │               │
    │               └──────────────┘               │
    │ TASK_ASSIGN         │          TASK_ASSIGN   │
    │ AGENT_HEARTBEAT     │          AGENT_HEARTBEAT│
    ▼                     │                        ▼
┌──────────┐    gossip flood    ┌──────────┐
│ Worker 1 │◄────────────────►│ Worker 2 │
│ port 9092│                    │ port 9093│
│ agents   │                    │ agents   │
└──────────┘                    └──────────┘
```

### Required (Marketplace)

```
    ┌──────────────┐         ┌──────────────┐
    │   Node A     │         │   Node B     │
    │  (buyer)     │         │  (seller)    │
    └──────┬───────┘         └──────┬───────┘
           │                        │
           │   OFFERING_ANNOUNCE    │
           │◄───────────────────────│  (gossip broadcast)
           │                        │
           │   TRIBUTE_REQUEST      │
           │───────────────────────►│  (targeted P2P)
           │                        │
           │   TRIBUTE_ACCEPT       │
           │◄───────────────────────│  (targeted P2P)
           │                        │
           │   TASK_ASSIGN          │
           │───────────────────────►│  (existing dispatch)
           │                        │
           │   TASK_COMPLETE        │
           │◄───────────────────────│  (gossip)
           │                        │
```
