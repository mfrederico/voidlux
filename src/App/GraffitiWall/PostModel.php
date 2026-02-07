<?php

declare(strict_types=1);

namespace VoidLux\App\GraffitiWall;

/**
 * Post data object for the graffiti wall.
 */
class PostModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $content,
        public readonly string $author,
        public readonly string $nodeId,
        public readonly int $lamportTs,
        public readonly string $createdAt,
        public readonly string $receivedAt,
    ) {}

    public static function create(string $content, string $author, string $nodeId, int $lamportTs): self
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return new self(
            id: self::generateUuid(),
            content: $content,
            author: $author,
            nodeId: $nodeId,
            lamportTs: $lamportTs,
            createdAt: $now,
            receivedAt: $now,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            content: $data['content'],
            author: $data['author'],
            nodeId: $data['node_id'],
            lamportTs: (int) $data['lamport_ts'],
            createdAt: $data['created_at'],
            receivedAt: $data['received_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'author' => $this->author,
            'node_id' => $this->nodeId,
            'lamport_ts' => $this->lamportTs,
            'created_at' => $this->createdAt,
            'received_at' => $this->receivedAt,
        ];
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // v4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant 1
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
