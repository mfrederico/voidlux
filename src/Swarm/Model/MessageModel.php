<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Model;

class MessageModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $authorId,
        public readonly string $authorName,
        public readonly string $category,
        public readonly string $title,
        public readonly string $content,
        public readonly int $priority,
        public readonly array $tags,
        public readonly string $status,
        public readonly ?string $claimedBy,
        public readonly ?string $parentId,
        public readonly ?string $taskId,
        public readonly int $lamportTs,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    public static function create(
        string $authorId,
        string $authorName,
        string $category,
        string $title,
        string $content,
        int $lamportTs,
        int $priority = 0,
        array $tags = [],
        ?string $parentId = null,
        ?string $taskId = null,
    ): self {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return new self(
            id: self::generateUuid(),
            authorId: $authorId,
            authorName: $authorName,
            category: $category,
            title: $title,
            content: $content,
            priority: $priority,
            tags: $tags,
            status: 'active',
            claimedBy: null,
            parentId: $parentId,
            taskId: $taskId,
            lamportTs: $lamportTs,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            authorId: $data['author_id'] ?? '',
            authorName: $data['author_name'] ?? '',
            category: $data['category'] ?? 'discussion',
            title: $data['title'] ?? '',
            content: $data['content'] ?? '',
            priority: (int) ($data['priority'] ?? 0),
            tags: is_string($data['tags'] ?? '[]')
                ? json_decode($data['tags'], true) ?? []
                : ($data['tags'] ?? []),
            status: $data['status'] ?? 'active',
            claimedBy: $data['claimed_by'] ?? null,
            parentId: $data['parent_id'] ?? null,
            taskId: $data['task_id'] ?? null,
            lamportTs: (int) ($data['lamport_ts'] ?? 0),
            createdAt: $data['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            updatedAt: $data['updated_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'author_id' => $this->authorId,
            'author_name' => $this->authorName,
            'category' => $this->category,
            'title' => $this->title,
            'content' => $this->content,
            'priority' => $this->priority,
            'tags' => $this->tags,
            'status' => $this->status,
            'claimed_by' => $this->claimedBy,
            'parent_id' => $this->parentId,
            'task_id' => $this->taskId,
            'lamport_ts' => $this->lamportTs,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function withStatus(string $status, int $lamportTs): self
    {
        return new self(
            id: $this->id,
            authorId: $this->authorId,
            authorName: $this->authorName,
            category: $this->category,
            title: $this->title,
            content: $this->content,
            priority: $this->priority,
            tags: $this->tags,
            status: $status,
            claimedBy: $this->claimedBy,
            parentId: $this->parentId,
            taskId: $this->taskId,
            lamportTs: $lamportTs,
            createdAt: $this->createdAt,
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public function withClaimedBy(string $agentId, int $lamportTs): self
    {
        return new self(
            id: $this->id,
            authorId: $this->authorId,
            authorName: $this->authorName,
            category: $this->category,
            title: $this->title,
            content: $this->content,
            priority: $this->priority,
            tags: $this->tags,
            status: 'claimed',
            claimedBy: $agentId,
            parentId: $this->parentId,
            taskId: $this->taskId,
            lamportTs: $lamportTs,
            createdAt: $this->createdAt,
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public function withTaskId(string $taskId, int $lamportTs): self
    {
        return new self(
            id: $this->id,
            authorId: $this->authorId,
            authorName: $this->authorName,
            category: $this->category,
            title: $this->title,
            content: $this->content,
            priority: $this->priority,
            tags: $this->tags,
            status: $this->status,
            claimedBy: $this->claimedBy,
            parentId: $this->parentId,
            taskId: $taskId,
            lamportTs: $lamportTs,
            createdAt: $this->createdAt,
            updatedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
