<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Consensus;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use VoidLux\P2P\PeerManager;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Decentralized consensus protocol for the VoidLux swarm.
 *
 * Implements a 3-phase quorum-based consensus that works alongside the emperor
 * system. Any node can propose; agreement requires a majority of known live nodes.
 *
 * Phases:
 *   1. PROPOSE  — proposer broadcasts proposal to all peers
 *   2. VOTE     — each node validates and votes YES/NO
 *   3. COMMIT   — if quorum reached, proposer broadcasts COMMIT; otherwise ABORT
 *
 * Integration with the emperor system:
 *   - Emperor is the primary proposer for task-critical operations
 *   - Workers can propose when emperor is unreachable (leaderless mode)
 *   - Committed decisions propagate via existing gossip infrastructure
 *   - Proposals from higher terms override lower terms (prevents stale emperors)
 *
 * Network partition handling:
 *   - PartitionDetector continuously tracks peer reachability
 *   - Proposals require quorum (>50% of cluster) to commit
 *   - Minority partition enters read-only mode — proposals are queued, not committed
 *   - On partition heal, queued proposals are re-proposed
 */
class ConsensusProtocol
{
    private const PROPOSAL_TIMEOUT = 10.0; // seconds to collect votes
    private const MONITOR_INTERVAL = 5.0;
    private const PROPOSAL_CACHE_LIMIT = 1000;

    private int $currentTerm = 0;
    private bool $running = false;

    /** @var array<string, Proposal> Active proposals by ID */
    private array $activeProposals = [];

    /** @var array<string, array<string, bool>> proposal_id => [node_id => voted] */
    private array $voteTracker = [];

    /** @var Proposal[] Queued proposals (waiting for quorum/partition heal) */
    private array $pendingQueue = [];

    /** @var array<string, true> Seen proposal IDs for dedup */
    private array $seenProposals = [];

    /** @var callable(Proposal): void Called when a proposal is committed */
    private $onCommit;

    /** @var callable(Proposal): bool Validates whether to vote YES on a proposal */
    private $validator;

    /** @var callable(string $msg): void */
    private $logger;

    /** @var Channel Used to wake the monitor loop when a vote arrives */
    private ?Channel $voteChannel = null;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly PeerManager $peerManager,
        private readonly LamportClock $clock,
        private readonly ConsensusLog $consensusLog,
        private readonly PartitionDetector $partitionDetector,
        private readonly string $nodeId,
    ) {
        $this->currentTerm = $this->consensusLog->getLastTerm();
    }

    public function onCommit(callable $cb): void
    {
        $this->onCommit = $cb;
    }

    /**
     * Set a validator that decides whether this node votes YES on a proposal.
     * Receives the Proposal, returns true to vote YES, false for NO.
     * If not set, all valid proposals receive YES votes.
     */
    public function setValidator(callable $cb): void
    {
        $this->validator = $cb;
    }

    public function onLog(callable $cb): void
    {
        $this->logger = $cb;
    }

    public function getCurrentTerm(): int
    {
        return $this->currentTerm;
    }

    /**
     * Start the consensus monitor loop.
     */
    public function start(): void
    {
        $this->running = true;
        $this->voteChannel = new Channel(64);

        // Proposal timeout / cleanup loop
        Coroutine::create(function () {
            while ($this->running) {
                $this->checkProposalTimeouts();
                $this->retryPendingQueue();
                $this->partitionDetector->pruneStale();
                $this->partitionDetector->evaluate();
                $this->pruneSeenProposals();
                Coroutine::sleep(self::MONITOR_INTERVAL);
            }
        });

        $this->log("Consensus protocol started (term: {$this->currentTerm})");
    }

    public function stop(): void
    {
        $this->running = false;
        $this->voteChannel?->close();
    }

    /**
     * Propose a state change for consensus.
     *
     * If the node has quorum, broadcasts the proposal and waits for votes.
     * If partitioned (no quorum), queues the proposal for later.
     *
     * @param string $operation Operation type (e.g. 'task_assign', 'agent_deregister', 'config_change')
     * @param array $payload Operation-specific data
     * @return Proposal The created proposal (check state for outcome)
     */
    public function propose(string $operation, array $payload): Proposal
    {
        $this->currentTerm++;
        $ts = $this->clock->tick();

        $proposal = Proposal::create(
            proposerNodeId: $this->nodeId,
            term: $this->currentTerm,
            operation: $operation,
            payload: $payload,
            lamportTs: $ts,
            quorumRequired: $this->partitionDetector->quorumSize(),
        );

        // If partitioned, queue for later
        if ($this->partitionDetector->isPartitioned()) {
            $this->log("Partitioned — queuing proposal {$proposal->id} ({$operation})");
            $proposal->state = ProposalState::Pending;
            $this->pendingQueue[] = $proposal;
            return $proposal;
        }

        return $this->broadcastProposal($proposal);
    }

    /**
     * Broadcast a proposal to all peers and self-vote.
     */
    private function broadcastProposal(Proposal $proposal): Proposal
    {
        $proposal->state = ProposalState::Voting;
        $this->activeProposals[$proposal->id] = $proposal;
        $this->voteTracker[$proposal->id] = [];
        $this->seenProposals[$proposal->id] = true;

        // Self-vote YES (proposer always votes for own proposal)
        $this->recordVote($proposal->id, $this->nodeId, true);

        // Broadcast to all peers
        $this->mesh->broadcast([
            'type' => MessageTypes::CONSENSUS_PROPOSE,
            'proposal' => $proposal->toArray(),
        ]);

        $this->log("Proposed: {$proposal->id} ({$proposal->operation}) term={$proposal->term} quorum={$proposal->quorumRequired}");

        return $proposal;
    }

    /**
     * Handle CONSENSUS_PROPOSE from the network.
     */
    public function handleProposal(array $msg): void
    {
        $proposalData = $msg['proposal'] ?? [];
        $proposalId = $proposalData['id'] ?? '';

        if (!$proposalId || isset($this->seenProposals[$proposalId])) {
            return;
        }
        $this->seenProposals[$proposalId] = true;

        $proposal = Proposal::fromArray($proposalData);
        $this->clock->witness($proposal->lamportTs);

        // Already committed?
        if ($this->consensusLog->hasProposal($proposalId)) {
            return;
        }

        // Reject proposals from stale terms
        if ($proposal->term < $this->currentTerm) {
            $this->sendVote($proposal, false, 'stale_term');
            return;
        }

        // Update our term if the proposal has a higher one
        if ($proposal->term > $this->currentTerm) {
            $this->currentTerm = $proposal->term;
        }

        // Validate the proposal
        $voteYes = true;
        $reason = '';
        if ($this->validator) {
            $voteYes = ($this->validator)($proposal);
            if (!$voteYes) {
                $reason = 'validator_rejected';
            }
        }

        // Track the proposal locally so we can process the commit
        $this->activeProposals[$proposal->id] = $proposal;

        // Send vote
        $this->sendVote($proposal, $voteYes, $reason);

        // Forward proposal to peers (gossip)
        $this->mesh->broadcast([
            'type' => MessageTypes::CONSENSUS_PROPOSE,
            'proposal' => $proposal->toArray(),
        ], $msg['_sender'] ?? null);
    }

    /**
     * Handle CONSENSUS_VOTE from the network.
     */
    public function handleVote(array $msg): void
    {
        $proposalId = $msg['proposal_id'] ?? '';
        $voterNodeId = $msg['voter_node_id'] ?? '';
        $voteYes = $msg['vote'] ?? false;

        if (!$proposalId || !$voterNodeId) {
            return;
        }

        $this->clock->witness($msg['lamport_ts'] ?? 0);
        $this->partitionDetector->peerSeen($voterNodeId);

        // Only the proposer tallies votes
        $proposal = $this->activeProposals[$proposalId] ?? null;
        if (!$proposal || $proposal->state !== ProposalState::Voting) {
            return;
        }

        if ($proposal->proposerNodeId !== $this->nodeId) {
            return; // Not our proposal to tally
        }

        $this->recordVote($proposalId, $voterNodeId, $voteYes);

        // Check if we've reached quorum
        if ($proposal->hasQuorum()) {
            $this->commitProposal($proposal);
        } elseif ($proposal->votesAgainst >= $proposal->quorumRequired) {
            // Enough NO votes to guarantee we'll never reach quorum
            $this->abortProposal($proposal, 'rejected_by_quorum');
        }
    }

    /**
     * Handle CONSENSUS_COMMIT from the network.
     */
    public function handleCommit(array $msg): void
    {
        $proposalId = $msg['proposal_id'] ?? '';
        if (!$proposalId) {
            return;
        }

        $this->clock->witness($msg['lamport_ts'] ?? 0);

        // Already committed locally?
        if ($this->consensusLog->hasProposal($proposalId)) {
            return;
        }

        $proposal = $this->activeProposals[$proposalId] ?? null;
        if (!$proposal) {
            // We might not have the proposal yet — reconstruct from the message
            $proposalData = $msg['proposal'] ?? [];
            if (empty($proposalData)) {
                return;
            }
            $proposal = Proposal::fromArray($proposalData);
        }

        $proposal->state = ProposalState::Committed;
        $proposal->committedAt = gmdate('Y-m-d\TH:i:s\Z');

        // Append to local log
        $this->consensusLog->append($proposal);
        unset($this->activeProposals[$proposalId]);
        unset($this->voteTracker[$proposalId]);

        $this->log("Committed (remote): {$proposalId} ({$proposal->operation}) term={$proposal->term}");

        // Fire commit callback
        if ($this->onCommit) {
            ($this->onCommit)($proposal);
        }

        // Forward commit to peers (gossip)
        $this->mesh->broadcast([
            'type' => MessageTypes::CONSENSUS_COMMIT,
            'proposal_id' => $proposalId,
            'proposal' => $proposal->toArray(),
            'lamport_ts' => $this->clock->tick(),
        ], $msg['_sender'] ?? null);
    }

    /**
     * Handle CONSENSUS_ABORT from the network.
     */
    public function handleAbort(array $msg): void
    {
        $proposalId = $msg['proposal_id'] ?? '';
        if (!$proposalId) {
            return;
        }

        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $proposal = $this->activeProposals[$proposalId] ?? null;
        if ($proposal) {
            $proposal->state = ProposalState::Aborted;
            unset($this->activeProposals[$proposalId]);
            unset($this->voteTracker[$proposalId]);
            $reason = $msg['reason'] ?? 'unknown';
            $this->log("Aborted (remote): {$proposalId} reason={$reason}");
        }
    }

    /**
     * Handle CONSENSUS_SYNC_REQ: peer requests committed entries we may have.
     */
    public function handleSyncRequest(array $msg): void
    {
        $afterIndex = $msg['after_index'] ?? 0;
        $senderNodeId = $msg['node_id'] ?? '';

        if (!$senderNodeId) {
            return;
        }

        $entries = $this->consensusLog->getEntriesSince($afterIndex);

        $this->mesh->sendTo($senderNodeId, [
            'type' => MessageTypes::CONSENSUS_SYNC_RSP,
            'node_id' => $this->nodeId,
            'entries' => $entries,
            'last_index' => $this->consensusLog->getLastIndex(),
            'last_term' => $this->consensusLog->getLastTerm(),
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    /**
     * Handle CONSENSUS_SYNC_RSP: apply entries from a peer that we're missing.
     */
    public function handleSyncResponse(array $msg): int
    {
        $entries = $msg['entries'] ?? [];
        $this->clock->witness($msg['lamport_ts'] ?? 0);

        $applied = 0;
        foreach ($entries as $entry) {
            $proposalId = $entry['id'] ?? '';
            if (!$proposalId || $this->consensusLog->hasProposal($proposalId)) {
                continue;
            }

            $proposal = Proposal::fromArray([
                'id' => $proposalId,
                'term' => $entry['term'] ?? 0,
                'proposer_node_id' => $entry['proposer_node_id'] ?? '',
                'operation' => $entry['operation'] ?? '',
                'payload' => json_decode($entry['payload'] ?? '[]', true) ?: [],
                'lamport_ts' => $entry['lamport_ts'] ?? 0,
                'state' => 'committed',
                'committed_at' => $entry['committed_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
                'created_at' => $entry['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            ]);

            $this->consensusLog->append($proposal);
            $applied++;

            // Fire commit callback for each new entry
            if ($this->onCommit) {
                ($this->onCommit)($proposal);
            }
        }

        if ($applied > 0) {
            $this->log("Synced {$applied} consensus entries from peer");
        }

        return $applied;
    }

    /**
     * Request consensus log sync from a specific peer.
     */
    public function requestSync(string $peerNodeId): void
    {
        $this->mesh->sendTo($peerNodeId, [
            'type' => MessageTypes::CONSENSUS_SYNC_REQ,
            'node_id' => $this->nodeId,
            'after_index' => $this->consensusLog->getLastIndex(),
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    /**
     * Get diagnostic status.
     */
    public function getStatus(): array
    {
        return [
            'current_term' => $this->currentTerm,
            'committed_entries' => $this->consensusLog->count(),
            'last_log_index' => $this->consensusLog->getLastIndex(),
            'active_proposals' => count($this->activeProposals),
            'pending_queue' => count($this->pendingQueue),
            'partition' => $this->partitionDetector->getStatus(),
        ];
    }

    // --- Internal ---

    private function sendVote(Proposal $proposal, bool $voteYes, string $reason = ''): void
    {
        $ts = $this->clock->tick();

        // Send vote to proposer (and broadcast for transparency)
        $voteMsg = [
            'type' => MessageTypes::CONSENSUS_VOTE,
            'proposal_id' => $proposal->id,
            'voter_node_id' => $this->nodeId,
            'vote' => $voteYes,
            'reason' => $reason,
            'lamport_ts' => $ts,
        ];

        // Try direct send to proposer first
        if (!$this->mesh->sendTo($proposal->proposerNodeId, $voteMsg)) {
            // Fallback: broadcast so it reaches proposer via gossip
            $this->mesh->broadcast($voteMsg);
        }
    }

    private function recordVote(string $proposalId, string $voterNodeId, bool $voteYes): void
    {
        $proposal = $this->activeProposals[$proposalId] ?? null;
        if (!$proposal || $proposal->state !== ProposalState::Voting) {
            return;
        }

        // Don't double-count
        if (isset($this->voteTracker[$proposalId][$voterNodeId])) {
            return;
        }
        $this->voteTracker[$proposalId][$voterNodeId] = true;

        if ($voteYes) {
            $proposal->votesFor++;
        } else {
            $proposal->votesAgainst++;
        }
    }

    private function commitProposal(Proposal $proposal): void
    {
        $proposal->state = ProposalState::Committed;
        $proposal->committedAt = gmdate('Y-m-d\TH:i:s\Z');

        // Append to local log
        $this->consensusLog->append($proposal);

        // Broadcast commit to all peers
        $ts = $this->clock->tick();
        $this->mesh->broadcast([
            'type' => MessageTypes::CONSENSUS_COMMIT,
            'proposal_id' => $proposal->id,
            'proposal' => $proposal->toArray(),
            'lamport_ts' => $ts,
        ]);

        unset($this->activeProposals[$proposal->id]);
        unset($this->voteTracker[$proposal->id]);

        $this->log("Committed: {$proposal->id} ({$proposal->operation}) votes={$proposal->votesFor}/{$proposal->quorumRequired}");

        // Fire commit callback
        if ($this->onCommit) {
            ($this->onCommit)($proposal);
        }
    }

    private function abortProposal(Proposal $proposal, string $reason): void
    {
        $proposal->state = ProposalState::Aborted;

        $ts = $this->clock->tick();
        $this->mesh->broadcast([
            'type' => MessageTypes::CONSENSUS_ABORT,
            'proposal_id' => $proposal->id,
            'reason' => $reason,
            'lamport_ts' => $ts,
        ]);

        unset($this->activeProposals[$proposal->id]);
        unset($this->voteTracker[$proposal->id]);

        $this->log("Aborted: {$proposal->id} ({$proposal->operation}) reason={$reason}");
    }

    private function checkProposalTimeouts(): void
    {
        foreach ($this->activeProposals as $id => $proposal) {
            if ($proposal->state === ProposalState::Voting && $proposal->isExpired(self::PROPOSAL_TIMEOUT)) {
                if ($proposal->proposerNodeId === $this->nodeId) {
                    // We proposed this — check if we have enough votes even if timeout
                    if ($proposal->hasQuorum()) {
                        $this->commitProposal($proposal);
                    } else {
                        $this->abortProposal($proposal, 'timeout');
                    }
                } else {
                    // Not our proposal — just clean up
                    $proposal->state = ProposalState::Expired;
                    unset($this->activeProposals[$id]);
                    unset($this->voteTracker[$id]);
                }
            }
        }
    }

    private function retryPendingQueue(): void
    {
        if (empty($this->pendingQueue) || $this->partitionDetector->isPartitioned()) {
            return;
        }

        $this->log("Partition healed — retrying " . count($this->pendingQueue) . " pending proposals");

        $queue = $this->pendingQueue;
        $this->pendingQueue = [];

        foreach ($queue as $proposal) {
            if (!$proposal->isExpired(60.0)) {
                $this->broadcastProposal($proposal);
            }
        }
    }

    private function pruneSeenProposals(): void
    {
        if (count($this->seenProposals) > self::PROPOSAL_CACHE_LIMIT) {
            $this->seenProposals = array_slice($this->seenProposals, -(self::PROPOSAL_CACHE_LIMIT / 2), null, true);
        }
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)("[consensus] {$message}");
        }
    }
}
