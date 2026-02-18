<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Consensus;

/**
 * A consensus proposal represents a state change that requires agreement from a quorum of nodes.
 *
 * 3-phase flow: propose → vote → commit/abort
 */
class Proposal
{
    public function __construct(
        public readonly string $id,
        public readonly int $term,
        public readonly string $proposerNodeId,
        public readonly string $operation,
        public readonly array $payload,
        public readonly int $lamportTs,
        public readonly string $createdAt,
        public ProposalState $state = ProposalState::Pending,
        public int $votesFor = 0,
        public int $votesAgainst = 0,
        public int $quorumRequired = 0,
        public ?string $committedAt = null,
    ) {}

    public static function create(
        string $proposerNodeId,
        int $term,
        string $operation,
        array $payload,
        int $lamportTs,
        int $quorumRequired,
    ): self {
        return new self(
            id: bin2hex(random_bytes(16)),
            term: $term,
            proposerNodeId: $proposerNodeId,
            operation: $operation,
            payload: $payload,
            lamportTs: $lamportTs,
            createdAt: gmdate('Y-m-d\TH:i:s\Z'),
            quorumRequired: $quorumRequired,
        );
    }

    public function hasQuorum(): bool
    {
        return $this->votesFor >= $this->quorumRequired;
    }

    public function isExpired(float $timeoutSeconds = 10.0): bool
    {
        $created = strtotime($this->createdAt);
        return (time() - $created) > $timeoutSeconds;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'term' => $this->term,
            'proposer_node_id' => $this->proposerNodeId,
            'operation' => $this->operation,
            'payload' => $this->payload,
            'lamport_ts' => $this->lamportTs,
            'state' => $this->state->value,
            'votes_for' => $this->votesFor,
            'votes_against' => $this->votesAgainst,
            'quorum_required' => $this->quorumRequired,
            'created_at' => $this->createdAt,
            'committed_at' => $this->committedAt,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? bin2hex(random_bytes(16)),
            term: $data['term'] ?? 0,
            proposerNodeId: $data['proposer_node_id'] ?? '',
            operation: $data['operation'] ?? '',
            payload: $data['payload'] ?? [],
            lamportTs: $data['lamport_ts'] ?? 0,
            createdAt: $data['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            state: ProposalState::tryFrom($data['state'] ?? '') ?? ProposalState::Pending,
            votesFor: $data['votes_for'] ?? 0,
            votesAgainst: $data['votes_against'] ?? 0,
            quorumRequired: $data['quorum_required'] ?? 0,
            committedAt: $data['committed_at'] ?? null,
        );
    }
}
