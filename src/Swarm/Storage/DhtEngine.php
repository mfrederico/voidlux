<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Storage;

use VoidLux\P2P\Protocol\LamportClock;
use VoidLux\P2P\Protocol\MessageTypes;
use VoidLux\P2P\Transport\TcpMesh;

/**
 * Decentralized hash table engine: content-addressed storage with P2P replication.
 *
 * Data is distributed across network nodes using push-gossip for writes and
 * pull-based anti-entropy for consistency repair. Every node stores a full
 * replica (full-replication DHT) to ensure availability without centralized
 * repositories.
 *
 * Features:
 * - Content-addressed storage: SHA-256(value) as key for integrity
 * - Named key storage: arbitrary string keys for application use
 * - Push gossip: writes propagated immediately to all peers
 * - Lamport clock ordering: last-writer-wins conflict resolution
 * - Tombstone deletion: deletes propagate as tombstones, purged after TTL
 * - TTL-based expiration: optional time-to-live per entry
 * - Integrity verification: SHA-256 hash checked on read
 */
class DhtEngine
{
    /** @var array<string, true> Seen message keys for dedup */
    private array $seenMessages = [];
    private int $seenLimit = 10000;

    /** @var callable(DhtEntry, string): void */
    private $onPut;
    /** @var callable(string): void */
    private $onDelete;

    public function __construct(
        private readonly TcpMesh $mesh,
        private readonly DhtStorage $storage,
        private readonly LamportClock $clock,
        private readonly string $nodeId,
    ) {}

    /**
     * Register callback for put events (entry, eventType).
     */
    public function onPut(callable $cb): void
    {
        $this->onPut = $cb;
    }

    /**
     * Register callback for delete events (key).
     */
    public function onDelete(callable $cb): void
    {
        $this->onDelete = $cb;
    }

    // --- Public API ---

    /**
     * Store a value using content-addressed key (SHA-256 of value).
     * Returns the entry with the content hash as key.
     */
    public function putContent(string $value, int $replicaCount = 3, int $ttl = 0): DhtEntry
    {
        $ts = $this->clock->tick();
        $entry = DhtEntry::createContentAddressed($value, $this->nodeId, $ts, $replicaCount, $ttl);
        return $this->putEntry($entry);
    }

    /**
     * Store a value under a named key.
     */
    public function put(string $key, string $value, int $replicaCount = 3, int $ttl = 0): DhtEntry
    {
        $ts = $this->clock->tick();
        $entry = DhtEntry::createNamed($key, $value, $this->nodeId, $ts, $replicaCount, $ttl);
        return $this->putEntry($entry);
    }

    /**
     * Retrieve a value by key. Returns null if not found or tombstoned.
     * Verifies data integrity on read.
     */
    public function get(string $key): ?DhtEntry
    {
        $entry = $this->storage->get($key);
        if ($entry === null) {
            return null;
        }

        if (!$entry->verifyIntegrity()) {
            $this->log("Integrity check FAILED for key={$key}, content_hash mismatch");
            return null;
        }

        if ($entry->isExpired()) {
            return null;
        }

        return $entry;
    }

    /**
     * Delete a key by propagating a tombstone.
     */
    public function delete(string $key): bool
    {
        $existing = $this->storage->getRaw($key);
        if ($existing === null) {
            return false;
        }

        $ts = $this->clock->tick();
        $tombstone = $existing->asTombstone($ts);
        $this->storage->put($tombstone);

        $dedupKey = "dht:del:{$key}:{$ts}";
        $this->seenMessages[$dedupKey] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::DHT_DELETE,
            'entry' => $tombstone->toArray(),
            'lamport_ts' => $ts,
        ]);

        if (isset($this->onDelete)) {
            ($this->onDelete)($key);
        }

        $this->log("DELETE key={$this->shortKey($key)}");
        return true;
    }

    /**
     * Find entries by content hash (content-addressed lookup).
     * @return DhtEntry[]
     */
    public function findByHash(string $contentHash): array
    {
        return $this->storage->findByContentHash($contentHash);
    }

    /**
     * Check if a key exists (non-tombstoned, non-expired).
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Get storage statistics.
     */
    public function stats(): array
    {
        return [
            'entry_count' => $this->storage->getEntryCount(),
            'total_size_bytes' => $this->storage->getTotalSize(),
            'max_lamport_ts' => $this->storage->getMaxLamportTs(),
            'node_id' => $this->nodeId,
        ];
    }

    // --- Gossip handlers (called by Server.onPeerMessage) ---

    /**
     * Handle incoming DHT_PUT from a peer.
     */
    public function receivePut(array $msg, ?string $senderAddress = null): ?DhtEntry
    {
        $entryData = $msg['entry'] ?? null;
        if ($entryData === null) {
            return null;
        }

        $key = $entryData['key'] ?? '';
        $ts = (int) ($entryData['lamport_ts'] ?? 0);
        $dedupKey = "dht:put:{$key}:{$ts}";

        if (isset($this->seenMessages[$dedupKey])) {
            return null;
        }

        $this->clock->witness($ts);
        $entry = DhtEntry::fromArray($entryData);

        // Verify integrity of received data
        if (!$entry->verifyIntegrity()) {
            $this->log("REJECT DHT_PUT: integrity check failed for key={$this->shortKey($key)}");
            return null;
        }

        // Last-writer-wins: only store if incoming lamport_ts is higher
        $existing = $this->storage->getRaw($key);
        if ($existing !== null && $existing->lamportTs >= $entry->lamportTs) {
            $this->seenMessages[$dedupKey] = true;
            return null;
        }

        $this->storage->put($entry);
        $this->seenMessages[$dedupKey] = true;

        // Forward to other peers (exclude sender)
        $this->mesh->broadcast([
            'type' => MessageTypes::DHT_PUT,
            'entry' => $entry->toArray(),
            'lamport_ts' => $entry->lamportTs,
        ], $senderAddress);

        $this->pruneSeenMessages();

        if (isset($this->onPut)) {
            ($this->onPut)($entry, 'replicated');
        }

        $this->log("REPLICATED key={$this->shortKey($key)} from peer");
        return $entry;
    }

    /**
     * Handle incoming DHT_DELETE from a peer.
     */
    public function receiveDelete(array $msg, ?string $senderAddress = null): bool
    {
        $entryData = $msg['entry'] ?? null;
        if ($entryData === null) {
            return false;
        }

        $key = $entryData['key'] ?? '';
        $ts = (int) ($entryData['lamport_ts'] ?? 0);
        $dedupKey = "dht:del:{$key}:{$ts}";

        if (isset($this->seenMessages[$dedupKey])) {
            return false;
        }

        $this->clock->witness($ts);
        $tombstone = DhtEntry::fromArray($entryData);

        // Only apply if newer than existing
        $existing = $this->storage->getRaw($key);
        if ($existing !== null && $existing->lamportTs >= $tombstone->lamportTs) {
            $this->seenMessages[$dedupKey] = true;
            return false;
        }

        $this->storage->put($tombstone);
        $this->seenMessages[$dedupKey] = true;

        // Forward to other peers
        $this->mesh->broadcast([
            'type' => MessageTypes::DHT_DELETE,
            'entry' => $tombstone->toArray(),
            'lamport_ts' => $tombstone->lamportTs,
        ], $senderAddress);

        $this->pruneSeenMessages();

        if (isset($this->onDelete)) {
            ($this->onDelete)($key);
        }

        $this->log("TOMBSTONE key={$this->shortKey($key)} from peer");
        return true;
    }

    /**
     * Handle incoming DHT_GET request from a peer. Returns entry if found locally.
     */
    public function receiveGetRequest(array $msg, \VoidLux\P2P\Transport\Connection $conn): void
    {
        $key = $msg['key'] ?? '';
        $requestId = $msg['request_id'] ?? '';
        if (!$key || !$requestId) {
            return;
        }

        $entry = $this->get($key);
        $conn->send([
            'type' => MessageTypes::DHT_GET_RSP,
            'request_id' => $requestId,
            'found' => $entry !== null,
            'entry' => $entry?->toArray(),
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    /**
     * Handle anti-entropy sync request: return entries since the given lamport_ts.
     */
    public function handleSyncRequest(array $msg, \VoidLux\P2P\Transport\Connection $conn): void
    {
        $sinceTs = (int) ($msg['since_lamport_ts'] ?? 0);
        $entries = $this->storage->getEntriesSince($sinceTs);

        $conn->send([
            'type' => MessageTypes::DHT_SYNC_RSP,
            'entries' => array_map(fn(DhtEntry $e) => $e->toArray(), $entries),
            'lamport_ts' => $this->clock->tick(),
        ]);
    }

    /**
     * Handle anti-entropy sync response: ingest entries from peer.
     * @return int Number of entries ingested
     */
    public function handleSyncResponse(array $msg): int
    {
        $entries = $msg['entries'] ?? [];
        $ingested = 0;

        foreach ($entries as $entryData) {
            $this->clock->witness((int) ($entryData['lamport_ts'] ?? 0));
            $entry = DhtEntry::fromArray($entryData);

            if (!$entry->tombstone && !$entry->verifyIntegrity()) {
                continue;
            }

            $existing = $this->storage->getRaw($entry->key);
            if ($existing !== null && $existing->lamportTs >= $entry->lamportTs) {
                continue;
            }

            $this->storage->put($entry);
            $ingested++;
        }

        if ($ingested > 0) {
            $this->log("Anti-entropy: ingested {$ingested} DHT entries from peer");
        }

        return $ingested;
    }

    // --- Internal ---

    private function putEntry(DhtEntry $entry): DhtEntry
    {
        $this->storage->put($entry);

        $dedupKey = "dht:put:{$entry->key}:{$entry->lamportTs}";
        $this->seenMessages[$dedupKey] = true;

        $this->mesh->broadcast([
            'type' => MessageTypes::DHT_PUT,
            'entry' => $entry->toArray(),
            'lamport_ts' => $entry->lamportTs,
        ]);

        if (isset($this->onPut)) {
            ($this->onPut)($entry, 'local');
        }

        $this->log("PUT key={$this->shortKey($entry->key)} size=" . strlen($entry->value) . "B");
        return $entry;
    }

    private function pruneSeenMessages(): void
    {
        if (count($this->seenMessages) > $this->seenLimit) {
            $this->seenMessages = array_slice($this->seenMessages, -($this->seenLimit / 2), null, true);
        }
    }

    private function shortKey(string $key): string
    {
        return strlen($key) > 16 ? substr($key, 0, 8) . '...' : $key;
    }

    private function log(string $msg): void
    {
        $short = substr($this->nodeId, 0, 8);
        echo "[DHT:{$short}] {$msg}\n";
    }
}
