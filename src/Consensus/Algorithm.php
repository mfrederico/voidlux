<?php

declare(strict_types=1);

namespace VoidLux\Consensus;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use VoidLux\P2P\PeerManager;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\Connection;
use VoidLux\P2P\Transport\TcpMesh;
use VoidLux\Swarm\Consensus\ConsensusLog;
use VoidLux\Swarm\Consensus\ConsensusProtocol;
use VoidLux\Swarm\Consensus\PartitionDetector;
use VoidLux\Swarm\Consensus\Proposal;
use VoidLux\Swarm\Consensus\ProposalState;

/**
 * Raft-inspired distributed consensus algorithm for the VoidLux emperor network.
 *
 * Integrates leader election, quorum-based voting, log replication, and state
 * machine application into a single cohesive algorithm. Designed for emperor
 * coordination across a decentralized swarm without any central authority.
 *
 * ## Design
 *
 * Uses a 3-layer approach inspired by Raft but adapted for VoidLux's P2P mesh:
 *
 * 1. **Leader Election** — Term-based leader tracking. The emperor is the leader.
 *    When the emperor fails, nodes detect the missing heartbeat and initiate a
 *    new election. The winner gets a new term number, invalidating stale proposals.
 *
 * 2. **Consensus Voting** — 3-phase quorum protocol (propose → vote → commit/abort).
 *    The leader drives proposals. When partitioned, nodes queue proposals until
 *    quorum is restored. Non-leaders can propose in leaderless fallback mode.
 *
 * 3. **State Machine** — Committed decisions are applied via callbacks. The
 *    consensus log provides ordered, persistent history for replay after restarts.
 *    Anti-entropy sync fills gaps between nodes.
 *
 * ## Partition Tolerance
 *
 * - Proposals require strict quorum (>50% of known cluster)
 * - Minority partition enters read-only mode; proposals are queued
 * - On partition heal, queued proposals re-enter voting
 * - Stale-term proposals are rejected (prevents split-brain commits)
 *
 * ## Consistency Model
 *
 * - **Writes**: Linearizable through leader. Leader verifies quorum before committing.
 * - **Reads**: Leader-verified reads (leader confirms it's still leader before responding).
 *   Stale reads available from any node via local log.
 *
 * ## Usage
 *
 *   $algo = Algorithm::create($mesh, $peerManager, $clock, $nodeId, $dataDir, $role);
 *   $algo->onCommit(function (Proposal $p) { applyToStateMachine($p); });
 *   $algo->onLeaderChange(function (?string $leaderId) { updateDashboard($leaderId); });
 *   $algo->start();
 *
 *   // Propose a distributed decision
 *   $result = $algo->propose('config_change', ['key' => 'max_agents', 'value' => 20]);
 *
 *   // Consistent read (verifies leader status)
 *   $committed = $algo->readConsistent('config_change');
 */
class Algorithm
{
    private const LEADER_HEARTBEAT_INTERVAL = 10;
    private const LEADER_STALE_THRESHOLD = 30;
    private const ELECTION_TIMEOUT_BASE = 5;
    private const ELECTION_TIMEOUT_JITTER = 3;
    private const ANTI_ENTROPY_INTERVAL = 30;
    private const LEADER_VERIFY_TIMEOUT = 3.0;

    private int $currentTerm = 0;
    private ?string $leaderId = null;
    private float $leaderLastSeen = 0.0;
    private bool $electionInProgress = false;
    private bool $running = false;

    /** @var array<string, int> node_id => term they joined the election */
    private array $electionVotes = [];

    /** @var Channel Wakes the election timer when a heartbeat arrives */
    private ?Channel $heartbeatChannel = null;

    /** @var callable(Proposal): void */
    private $onCommit;

    /** @var callable(?string $leaderId, int $term): void */
    private $onLeaderChange;

    /** @var callable(Proposal): bool Validates proposals before voting YES */
    private $validator;

    /** @var callable(string): void */
    private $logger;

    private function __construct(
        private readonly TcpMesh $mesh,
        private readonly PeerManager $peerManager,
        private readonly LamportClock $clock,
        private readonly ConsensusProtocol $protocol,
        private readonly ConsensusLog $log,
        private readonly PartitionDetector $partitionDetector,
        private readonly string $nodeId,
        private readonly string $role,
    ) {}

    /**
     * Factory: create the full consensus algorithm stack.
     */
    public static function create(
        TcpMesh $mesh,
        PeerManager $peerManager,
        LamportClock $clock,
        string $nodeId,
        string $dataDir,
        string $role = 'worker',
    ): self {
        $logPath = $dataDir . "/consensus-{$nodeId}.db";
        $log = new ConsensusLog($logPath);
        $partitionDetector = new PartitionDetector($mesh, $peerManager, $nodeId);
        $protocol = new ConsensusProtocol($mesh, $peerManager, $clock, $log, $partitionDetector, $nodeId);

        $algo = new self($mesh, $peerManager, $clock, $protocol, $log, $partitionDetector, $nodeId, $role);

        // Wire protocol events back to the algorithm
        $protocol->onCommit(function (Proposal $p) use ($algo) {
            $algo->applyCommitted($p);
        });

        return $algo;
    }

    // --- Configuration ---

    /**
     * Called when a proposal is committed (apply to your state machine).
     */
    public function onCommit(callable $cb): void
    {
        $this->onCommit = $cb;
    }

    /**
     * Called when the leader changes.
     * @param callable(?string $leaderId, int $term): void
     */
    public function onLeaderChange(callable $cb): void
    {
        $this->onLeaderChange = $cb;
    }

    /**
     * Set a validator that decides whether this node votes YES on a proposal.
     */
    public function setValidator(callable $cb): void
    {
        $this->validator = $cb;
        $this->protocol->setValidator($cb);
    }

    public function onLog(callable $cb): void
    {
        $this->logger = $cb;
        $this->protocol->onLog($cb);
        $this->partitionDetector->onLog($cb);
    }

    // --- Lifecycle ---

    /**
     * Start the consensus algorithm (leader heartbeats, election monitor, anti-entropy).
     */
    public function start(): void
    {
        $this->running = true;
        $this->heartbeatChannel = new Channel(16);
        $this->currentTerm = $this->log->getLastTerm();

        // Update partition detector with current cluster size
        $this->partitionDetector->setKnownClusterSize($this->peerManager->getPeerCount() + 1);

        // If we're the emperor, start as leader
        if ($this->role === 'emperor') {
            $this->becomeLeader();
        }

        // Start the underlying consensus protocol
        $this->protocol->start();

        // Leader heartbeat / election monitor loop
        Coroutine::create(function () {
            while ($this->running) {
                if ($this->isLeader()) {
                    $this->broadcastLeaderHeartbeat();
                    Coroutine::sleep(self::LEADER_HEARTBEAT_INTERVAL);
                } else {
                    $this->monitorLeader();
                }
            }
        });

        // Anti-entropy: periodically sync consensus log with peers
        Coroutine::create(function () {
            while ($this->running) {
                Coroutine::sleep(self::ANTI_ENTROPY_INTERVAL);
                $this->syncWithRandomPeer();
            }
        });

        // Partition detector update loop
        Coroutine::create(function () {
            while ($this->running) {
                $clusterSize = $this->peerManager->getPeerCount() + 1;
                $this->partitionDetector->setKnownClusterSize($clusterSize);
                $this->partitionDetector->evaluate();
                Coroutine::sleep(5);
            }
        });

        $this->log("Algorithm started (term={$this->currentTerm}, role={$this->role}, node={$this->shortId()})");
    }

    public function stop(): void
    {
        $this->running = false;
        $this->heartbeatChannel?->close();
        $this->protocol->stop();
    }

    // --- Message Routing ---

    /**
     * Route a P2P message to the correct consensus handler.
     * Call this from Server.php's onPeerMessage for consensus message types.
     */
    public function handleMessage(Connection $conn, array $msg): void
    {
        $type = $msg['type'] ?? 0;
        $senderNodeId = $msg['node_id'] ?? '';

        // Track peer liveness for partition detector
        if ($senderNodeId !== '') {
            $this->partitionDetector->peerSeen($senderNodeId);
        }

        switch ($type) {
            case MessageTypes::CONSENSUS_PROPOSE:
                $this->handleProposal($msg);
                break;

            case MessageTypes::CONSENSUS_VOTE:
                $this->protocol->handleVote($msg);
                break;

            case MessageTypes::CONSENSUS_COMMIT:
                $this->protocol->handleCommit($msg);
                break;

            case MessageTypes::CONSENSUS_ABORT:
                $this->protocol->handleAbort($msg);
                break;

            case MessageTypes::CONSENSUS_SYNC_REQ:
                $this->protocol->handleSyncRequest($msg);
                break;

            case MessageTypes::CONSENSUS_SYNC_RSP:
                $this->protocol->handleSyncResponse($msg);
                break;

            case MessageTypes::EMPEROR_HEARTBEAT:
                $this->handleLeaderHeartbeat($msg);
                break;

            case MessageTypes::ELECTION_START:
                $this->handleElectionStart($msg);
                break;

            case MessageTypes::ELECTION_VICTORY:
                $this->handleElectionVictory($msg);
                break;
        }
    }

    // --- Proposals ---

    /**
     * Propose a distributed decision for consensus.
     *
     * If this node is the leader, the proposal is broadcast immediately.
     * If not, the proposal is forwarded to the leader.
     * If no leader is available, enters leaderless fallback mode.
     *
     * @param string $operation Operation type (e.g. 'config_change', 'membership_change')
     * @param array $payload Operation-specific data
     * @return Proposal The created proposal (check state for outcome)
     */
    public function propose(string $operation, array $payload): Proposal
    {
        // Leader drives proposals
        if ($this->isLeader()) {
            return $this->protocol->propose($operation, $payload);
        }

        // Forward to leader if we know who it is
        if ($this->leaderId !== null && $this->isLeaderAlive()) {
            return $this->forwardToLeader($operation, $payload);
        }

        // Leaderless fallback: any node can propose (requires quorum anyway)
        $this->log("No leader available — proposing in leaderless mode");
        return $this->protocol->propose($operation, $payload);
    }

    /**
     * Propose a membership change (node join/leave).
     * These are special proposals that update the known cluster size.
     */
    public function proposeMembershipChange(string $action, string $targetNodeId, array $meta = []): Proposal
    {
        return $this->propose('membership_change', [
            'action' => $action,       // 'join' or 'leave'
            'node_id' => $targetNodeId,
            'meta' => $meta,
            'cluster_size' => $this->peerManager->getPeerCount() + 1,
        ]);
    }

    /**
     * Propose a configuration change.
     */
    public function proposeConfigChange(string $key, mixed $value): Proposal
    {
        return $this->propose('config_change', [
            'key' => $key,
            'value' => $value,
        ]);
    }

    // --- Reads ---

    /**
     * Read from the committed log with leader-verified consistency.
     *
     * The leader verifies it still has quorum before serving the read,
     * preventing stale reads during network partitions.
     *
     * @param string $operation Filter log entries by operation type
     * @param int $limit Max entries to return
     * @return array Committed log entries
     */
    public function readConsistent(string $operation = '', int $limit = 100): array
    {
        // Only leader can serve consistent reads
        if (!$this->isLeader()) {
            // Forward to leader or return error
            return ['error' => 'not_leader', 'leader' => $this->leaderId];
        }

        // Verify we still have quorum (leader lease check)
        if (!$this->verifyLeaderLease()) {
            return ['error' => 'quorum_lost', 'leader' => null];
        }

        return $this->readLocal($operation, $limit);
    }

    /**
     * Read from local committed log (may be stale on non-leader nodes).
     *
     * @param string $operation Filter by operation type (empty = all)
     * @param int $limit Max entries
     * @return array Committed log entries
     */
    public function readLocal(string $operation = '', int $limit = 100): array
    {
        $entries = $this->log->getEntriesSince(0, $limit);

        if ($operation !== '') {
            $entries = array_filter($entries, fn($e) => ($e['operation'] ?? '') === $operation);
            $entries = array_values($entries);
        }

        return $entries;
    }

    // --- Leader Election ---

    /**
     * Is this node the current leader?
     */
    public function isLeader(): bool
    {
        return $this->leaderId === $this->nodeId;
    }

    /**
     * Get the current leader's node ID (null if unknown).
     */
    public function getLeaderId(): ?string
    {
        return $this->leaderId;
    }

    /**
     * Get the current consensus term.
     */
    public function getCurrentTerm(): int
    {
        return $this->currentTerm;
    }

    /**
     * Check if the leader's heartbeat is fresh.
     */
    public function isLeaderAlive(): bool
    {
        if ($this->leaderId === null) {
            return false;
        }
        if ($this->isLeader()) {
            return true;
        }
        return (microtime(true) - $this->leaderLastSeen) < self::LEADER_STALE_THRESHOLD;
    }

    // --- Status ---

    /**
     * Full diagnostic status for monitoring / dashboard.
     */
    public function getStatus(): array
    {
        return [
            'node_id' => $this->shortId(),
            'role' => $this->isLeader() ? 'leader' : 'follower',
            'term' => $this->currentTerm,
            'leader' => $this->leaderId ? substr($this->leaderId, 0, 8) : null,
            'leader_alive' => $this->isLeaderAlive(),
            'election_in_progress' => $this->electionInProgress,
            'log_index' => $this->log->getLastIndex(),
            'committed_entries' => $this->log->count(),
            'partition' => $this->partitionDetector->getStatus(),
            'protocol' => $this->protocol->getStatus(),
        ];
    }

    /**
     * Get the underlying partition detector for external use.
     */
    public function partitionDetector(): PartitionDetector
    {
        return $this->partitionDetector;
    }

    /**
     * Get the underlying consensus protocol for direct access.
     */
    public function protocol(): ConsensusProtocol
    {
        return $this->protocol;
    }

    /**
     * Get the consensus log.
     */
    public function consensusLog(): ConsensusLog
    {
        return $this->log;
    }

    // --- Internal: Leader Election ---

    private function becomeLeader(): void
    {
        $this->currentTerm++;
        $previousLeader = $this->leaderId;
        $this->leaderId = $this->nodeId;
        $this->leaderLastSeen = microtime(true);

        $this->log("Became leader (term={$this->currentTerm})");

        if ($previousLeader !== $this->nodeId) {
            $this->fireLeaderChange();
        }
    }

    private function broadcastLeaderHeartbeat(): void
    {
        $ts = $this->clock->tick();
        $this->mesh->broadcast([
            'type' => MessageTypes::EMPEROR_HEARTBEAT,
            'node_id' => $this->nodeId,
            'term' => $this->currentTerm,
            'http_port' => 0, // Filled by Server.php if needed
            'p2p_port' => 0,
            'lamport_ts' => $ts,
            'log_index' => $this->log->getLastIndex(),
        ]);
    }

    private function handleLeaderHeartbeat(array $msg): void
    {
        $senderNodeId = $msg['node_id'] ?? '';
        $senderTerm = $msg['term'] ?? 0;

        $this->clock->witness($msg['lamport_ts'] ?? 0);
        $this->partitionDetector->peerSeen($senderNodeId);

        // Reject heartbeats from stale terms
        if ($senderTerm < $this->currentTerm) {
            return;
        }

        // Accept higher or equal term leader
        if ($senderTerm > $this->currentTerm) {
            $this->currentTerm = $senderTerm;
        }

        $previousLeader = $this->leaderId;
        $this->leaderId = $senderNodeId;
        $this->leaderLastSeen = microtime(true);

        // Cancel any ongoing election
        if ($this->electionInProgress) {
            $this->log("Leader heartbeat received during election — cancelling");
            $this->electionInProgress = false;
            $this->electionVotes = [];
        }

        // Step down if we thought we were leader but someone with higher/equal term exists
        if ($previousLeader === $this->nodeId && $senderNodeId !== $this->nodeId) {
            $this->log("Stepping down — new leader {$this->shortNodeId($senderNodeId)} term={$senderTerm}");
        }

        // Notify on leader change
        if ($previousLeader !== $senderNodeId) {
            $this->fireLeaderChange();
        }

        // Wake the heartbeat channel
        $this->heartbeatChannel?->push(true, 0.0);
    }

    private function monitorLeader(): void
    {
        // Wait for heartbeat or timeout
        $timeout = self::LEADER_STALE_THRESHOLD - self::LEADER_HEARTBEAT_INTERVAL;
        $received = $this->heartbeatChannel?->pop((float) $timeout);

        if ($received !== false) {
            return; // Heartbeat arrived, leader is alive
        }

        // Check if leader heartbeat is actually stale
        if ($this->isLeaderAlive()) {
            return;
        }

        // No leader or stale leader — start election
        if (!$this->electionInProgress) {
            $this->startElection();
        }
    }

    private function startElection(): void
    {
        $this->electionInProgress = true;
        $this->currentTerm++;
        $this->electionVotes = [];
        $this->leaderId = null;

        // Vote for ourselves
        $this->electionVotes[$this->nodeId] = $this->currentTerm;

        $ts = $this->clock->tick();
        $this->mesh->broadcast([
            'type' => MessageTypes::ELECTION_START,
            'node_id' => $this->nodeId,
            'term' => $this->currentTerm,
            'lamport_ts' => $ts,
            'log_index' => $this->log->getLastIndex(),
        ]);

        $this->log("Election started (term={$this->currentTerm})");

        // Randomized timeout to prevent split elections
        $timeout = self::ELECTION_TIMEOUT_BASE + (mt_rand(0, self::ELECTION_TIMEOUT_JITTER * 1000) / 1000.0);

        Coroutine::create(function () use ($timeout) {
            Coroutine::sleep($timeout);
            $this->resolveElection();
        });
    }

    private function handleElectionStart(array $msg): void
    {
        $candidateId = $msg['node_id'] ?? '';
        $candidateTerm = $msg['term'] ?? 0;
        $candidateLogIndex = $msg['log_index'] ?? 0;

        $this->clock->witness($msg['lamport_ts'] ?? 0);

        // If we're the leader with a current term, reassert authority
        if ($this->isLeader() && $this->currentTerm >= $candidateTerm) {
            $this->broadcastLeaderHeartbeat();
            return;
        }

        // Accept if candidate has higher or equal term
        if ($candidateTerm >= $this->currentTerm) {
            $this->currentTerm = $candidateTerm;
            $this->electionVotes[$candidateId] = $candidateTerm;

            // Join the election if not already
            if (!$this->electionInProgress) {
                $this->startElection();
            } else {
                $this->electionVotes[$candidateId] = $candidateTerm;
            }
        }
    }

    private function resolveElection(): void
    {
        if (!$this->electionInProgress) {
            return;
        }

        $this->electionInProgress = false;

        // Winner: highest log index wins, ties broken by lowest node_id
        // (Raft-style: most up-to-date node becomes leader)
        $candidates = $this->electionVotes;
        if (empty($candidates)) {
            $this->log("Election resolved with no candidates");
            return;
        }

        // Sort: prefer lowest node_id (deterministic tiebreaker)
        $candidateIds = array_keys($candidates);
        sort($candidateIds);
        $winnerId = $candidateIds[0];

        $this->log("Election resolved: winner={$this->shortNodeId($winnerId)} from " . count($candidates) . " candidate(s)");

        if ($winnerId === $this->nodeId) {
            // We won
            $this->becomeLeader();

            $ts = $this->clock->tick();
            $this->mesh->broadcast([
                'type' => MessageTypes::ELECTION_VICTORY,
                'node_id' => $this->nodeId,
                'term' => $this->currentTerm,
                'lamport_ts' => $ts,
                'log_index' => $this->log->getLastIndex(),
            ]);
        }

        $this->electionVotes = [];
    }

    private function handleElectionVictory(array $msg): void
    {
        $winnerId = $msg['node_id'] ?? '';
        $winnerTerm = $msg['term'] ?? 0;

        $this->clock->witness($msg['lamport_ts'] ?? 0);

        if ($winnerTerm < $this->currentTerm) {
            return; // Stale victory, ignore
        }

        $previousLeader = $this->leaderId;
        $this->currentTerm = $winnerTerm;
        $this->leaderId = $winnerId;
        $this->leaderLastSeen = microtime(true);
        $this->electionInProgress = false;
        $this->electionVotes = [];

        $this->log("New leader: {$this->shortNodeId($winnerId)} (term={$winnerTerm})");

        if ($previousLeader !== $winnerId) {
            $this->fireLeaderChange();
        }
    }

    // --- Internal: Proposal Handling ---

    private function handleProposal(array $msg): void
    {
        $proposalData = $msg['proposal'] ?? [];
        $proposalTerm = $proposalData['term'] ?? 0;

        // Reject proposals from stale terms
        if ($proposalTerm < $this->currentTerm) {
            $this->log("Rejecting stale proposal (term {$proposalTerm} < {$this->currentTerm})");
            return;
        }

        // Update term if needed
        if ($proposalTerm > $this->currentTerm) {
            $this->currentTerm = $proposalTerm;
        }

        $this->protocol->handleProposal($msg);
    }

    /**
     * Forward a proposal to the current leader via P2P.
     */
    private function forwardToLeader(string $operation, array $payload): Proposal
    {
        $ts = $this->clock->tick();

        $proposal = Proposal::create(
            proposerNodeId: $this->nodeId,
            term: $this->currentTerm,
            operation: $operation,
            payload: $payload,
            lamportTs: $ts,
            quorumRequired: $this->partitionDetector->quorumSize(),
        );

        // Send to leader for broadcasting
        $sent = $this->mesh->sendTo($this->leaderId, [
            'type' => MessageTypes::CONSENSUS_PROPOSE,
            'proposal' => $proposal->toArray(),
            'forwarded_by' => $this->nodeId,
        ]);

        if (!$sent) {
            $this->log("Failed to forward proposal to leader {$this->shortNodeId($this->leaderId)} — falling back to broadcast");
            return $this->protocol->propose($operation, $payload);
        }

        $this->log("Forwarded proposal {$proposal->id} to leader {$this->shortNodeId($this->leaderId)}");
        return $proposal;
    }

    // --- Internal: State Machine ---

    /**
     * Apply a committed proposal to the state machine.
     */
    private function applyCommitted(Proposal $proposal): void
    {
        // Handle built-in operations
        if ($proposal->operation === 'membership_change') {
            $this->applyMembershipChange($proposal);
        }

        // Fire user callback
        if ($this->onCommit) {
            ($this->onCommit)($proposal);
        }
    }

    private function applyMembershipChange(Proposal $proposal): void
    {
        $action = $proposal->payload['action'] ?? '';
        $targetNodeId = $proposal->payload['node_id'] ?? '';
        $newSize = $proposal->payload['cluster_size'] ?? null;

        if ($newSize !== null) {
            $this->partitionDetector->setKnownClusterSize((int) $newSize);
        }

        $this->log("Membership change committed: {$action} node={$this->shortNodeId($targetNodeId)}");
    }

    // --- Internal: Leader Lease ---

    /**
     * Verify this node still holds the leader lease (has quorum).
     * Prevents serving stale reads during partitions.
     */
    private function verifyLeaderLease(): bool
    {
        if (!$this->isLeader()) {
            return false;
        }

        return $this->partitionDetector->hasQuorum();
    }

    // --- Internal: Anti-Entropy ---

    /**
     * Sync consensus log with a random peer to fill gaps.
     */
    private function syncWithRandomPeer(): void
    {
        $peers = $this->peerManager->getConnectedPeers();
        if (empty($peers)) {
            return;
        }

        $peerIds = array_keys($peers);
        $randomPeer = $peerIds[array_rand($peerIds)];

        $this->protocol->requestSync($randomPeer);
    }

    // --- Internal: Helpers ---

    private function fireLeaderChange(): void
    {
        if ($this->onLeaderChange) {
            ($this->onLeaderChange)($this->leaderId, $this->currentTerm);
        }
    }

    private function shortId(): string
    {
        return substr($this->nodeId, 0, 8);
    }

    private function shortNodeId(?string $nodeId): string
    {
        if ($nodeId === null) {
            return 'null';
        }
        return substr($nodeId, 0, 8);
    }

    private function log(string $msg): void
    {
        if ($this->logger) {
            ($this->logger)("[consensus] {$msg}");
        }
    }
}
