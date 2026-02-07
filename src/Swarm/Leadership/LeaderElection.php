<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Leadership;

use Swoole\Coroutine;
use VoidLux\P2P\PeerManager;
use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Lightweight leader election for emperor failover.
 *
 * - Emperor broadcasts EMPEROR_HEARTBEAT every 10s
 * - Workers track last heartbeat; if stale >30s, start election
 * - Election: lowest node_id among candidates wins (Bully variant)
 * - Winner broadcasts ELECTION_VICTORY and calls onPromoted callback
 */
class LeaderElection
{
    private const HEARTBEAT_INTERVAL = 10;
    private const ELECTION_TIMEOUT = 5;
    private const EMPEROR_STALE_THRESHOLD = 30;

    private ?string $emperorNodeId = null;
    private float $emperorLastHeartbeat = 0;
    private ?int $emperorHttpPort = null;
    private ?int $emperorP2pPort = null;

    private bool $electionInProgress = false;
    /** @var array<string, int> node_id => lamport_ts of candidates */
    private array $electionCandidates = [];

    private bool $running = false;

    /** @var callable(string $emperorNodeId, int $httpPort, int $p2pPort): void */
    private $onPromoted;

    /** @var callable(string $msg): void */
    private $logger;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly PeerManager $peerManager,
        private readonly LamportClock $clock,
        private readonly string $nodeId,
        private readonly int $httpPort,
        private readonly int $p2pPort,
        private string $role,
    ) {}

    public function onPromoted(callable $cb): void
    {
        $this->onPromoted = $cb;
    }

    public function onLog(callable $cb): void
    {
        $this->logger = $cb;
    }

    public function getEmperorNodeId(): ?string
    {
        return $this->emperorNodeId;
    }

    public function getEmperorHttpPort(): ?int
    {
        return $this->emperorHttpPort;
    }

    public function isEmperorAlive(): bool
    {
        if ($this->emperorNodeId === null) {
            return false;
        }
        return (microtime(true) - $this->emperorLastHeartbeat) < self::EMPEROR_STALE_THRESHOLD;
    }

    public function setRole(string $role): void
    {
        $this->role = $role;
    }

    public function start(): void
    {
        $this->running = true;

        if ($this->role === 'emperor') {
            $this->emperorNodeId = $this->nodeId;
            $this->emperorLastHeartbeat = microtime(true);
            $this->emperorHttpPort = $this->httpPort;
            $this->emperorP2pPort = $this->p2pPort;
        }

        // Heartbeat / monitor loop
        Coroutine::create(function () {
            while ($this->running) {
                Coroutine::sleep(self::HEARTBEAT_INTERVAL);

                if ($this->role === 'emperor') {
                    $this->broadcastHeartbeat();
                } else {
                    $this->checkEmperorAlive();
                }
            }
        });
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function broadcastHeartbeat(): void
    {
        $ts = $this->clock->tick();
        $this->mesh->broadcast([
            'type' => MessageTypes::EMPEROR_HEARTBEAT,
            'node_id' => $this->nodeId,
            'http_port' => $this->httpPort,
            'p2p_port' => $this->p2pPort,
            'lamport_ts' => $ts,
        ]);
    }

    private function checkEmperorAlive(): void
    {
        if ($this->electionInProgress) {
            return;
        }

        // No emperor known yet — give it time on startup
        if ($this->emperorNodeId === null && $this->emperorLastHeartbeat === 0.0) {
            // First check: set the timer so we start tracking
            $this->emperorLastHeartbeat = microtime(true);
            return;
        }

        $stale = microtime(true) - $this->emperorLastHeartbeat;
        if ($stale > self::EMPEROR_STALE_THRESHOLD) {
            $this->log("Emperor heartbeat stale ({$stale}s), starting election");
            $this->startElection();
        }
    }

    private function startElection(): void
    {
        $this->electionInProgress = true;
        $this->electionCandidates = [];

        // Add ourselves as a candidate
        $ts = $this->clock->tick();
        $this->electionCandidates[$this->nodeId] = $ts;

        // Broadcast election start
        $this->mesh->broadcast([
            'type' => MessageTypes::ELECTION_START,
            'node_id' => $this->nodeId,
            'lamport_ts' => $ts,
        ]);

        $this->log("Election started, collecting candidates for " . self::ELECTION_TIMEOUT . "s");

        // Wait for candidates to respond
        Coroutine::create(function () {
            Coroutine::sleep(self::ELECTION_TIMEOUT);
            $this->resolveElection();
        });
    }

    private function resolveElection(): void
    {
        if (!$this->electionInProgress) {
            return;
        }

        // Lowest node_id wins
        $candidates = array_keys($this->electionCandidates);
        sort($candidates);

        $winnerId = $candidates[0] ?? $this->nodeId;
        $this->electionInProgress = false;

        $this->log("Election resolved: winner is {$winnerId} (from " . count($candidates) . " candidates)");

        if ($winnerId === $this->nodeId) {
            // We won — promote ourselves
            $ts = $this->clock->tick();
            $this->mesh->broadcast([
                'type' => MessageTypes::ELECTION_VICTORY,
                'node_id' => $this->nodeId,
                'http_port' => $this->httpPort,
                'p2p_port' => $this->p2pPort,
                'lamport_ts' => $ts,
            ]);

            $this->emperorNodeId = $this->nodeId;
            $this->emperorLastHeartbeat = microtime(true);
            $this->emperorHttpPort = $this->httpPort;
            $this->emperorP2pPort = $this->p2pPort;
            $this->role = 'emperor';

            $this->log("Elected as emperor");

            if ($this->onPromoted) {
                ($this->onPromoted)($this->nodeId, $this->httpPort, $this->p2pPort);
            }
        }
    }

    /**
     * Handle EMPEROR_HEARTBEAT from the network.
     */
    public function handleHeartbeat(array $msg): void
    {
        $nodeId = $msg['node_id'] ?? '';
        $httpPort = $msg['http_port'] ?? 0;
        $p2pPort = $msg['p2p_port'] ?? 0;
        $ts = $msg['lamport_ts'] ?? 0;

        $this->clock->witness($ts);

        $this->emperorNodeId = $nodeId;
        $this->emperorLastHeartbeat = microtime(true);
        $this->emperorHttpPort = $httpPort;
        $this->emperorP2pPort = $p2pPort;

        // If we receive a heartbeat during an election, cancel the election
        if ($this->electionInProgress) {
            $this->log("Received emperor heartbeat during election, cancelling");
            $this->electionInProgress = false;
            $this->electionCandidates = [];
        }
    }

    /**
     * Handle ELECTION_START from the network.
     */
    public function handleElectionStart(array $msg): void
    {
        $nodeId = $msg['node_id'] ?? '';
        $ts = $msg['lamport_ts'] ?? 0;

        $this->clock->witness($ts);

        // If we're the current emperor, reassert authority
        if ($this->role === 'emperor') {
            $this->broadcastHeartbeat();
            return;
        }

        // Add remote candidate
        $this->electionCandidates[$nodeId] = $ts;

        // If we haven't started our own election, join
        if (!$this->electionInProgress) {
            $this->startElection();
        } else {
            // Already in election, just add candidate
            $this->electionCandidates[$nodeId] = $ts;
        }
    }

    /**
     * Handle ELECTION_VICTORY from the network.
     */
    public function handleElectionVictory(array $msg): void
    {
        $nodeId = $msg['node_id'] ?? '';
        $httpPort = $msg['http_port'] ?? 0;
        $p2pPort = $msg['p2p_port'] ?? 0;
        $ts = $msg['lamport_ts'] ?? 0;

        $this->clock->witness($ts);

        $this->emperorNodeId = $nodeId;
        $this->emperorLastHeartbeat = microtime(true);
        $this->emperorHttpPort = $httpPort;
        $this->emperorP2pPort = $p2pPort;
        $this->electionInProgress = false;
        $this->electionCandidates = [];

        $this->log("New emperor elected: {$nodeId} (http:{$httpPort}, p2p:{$p2pPort})");
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)("[election] {$message}");
        }
    }
}
