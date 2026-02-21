<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Model;

class AgentModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $nodeId,
        public readonly string $name,
        public readonly string $tool,
        public readonly string $model,
        public readonly array $capabilities,
        public readonly ?string $tmuxSessionId,
        public readonly string $projectPath,
        public readonly int $maxConcurrentTasks,
        public readonly string $status,
        public readonly ?string $currentTaskId,
        public readonly ?string $lastHeartbeat,
        public readonly int $lamportTs,
        public readonly string $registeredAt,
        public readonly string $role = '',
    ) {}

    public static function create(
        string $nodeId,
        string $name,
        int $lamportTs,
        string $tool = 'claude',
        string $model = '',
        array $capabilities = [],
        ?string $tmuxSessionId = null,
        string $projectPath = '',
        int $maxConcurrentTasks = 1,
        string $role = '',
    ): self {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return new self(
            id: self::generateUuid(),
            nodeId: $nodeId,
            name: $name,
            tool: $tool,
            model: $model,
            capabilities: $capabilities,
            tmuxSessionId: $tmuxSessionId,
            projectPath: $projectPath,
            maxConcurrentTasks: $maxConcurrentTasks,
            status: 'starting',
            currentTaskId: null,
            lastHeartbeat: $now,
            lamportTs: $lamportTs,
            registeredAt: $now,
            role: $role,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            nodeId: $data['node_id'],
            name: $data['name'],
            tool: $data['tool'] ?? 'claude',
            model: $data['model'] ?? '',
            capabilities: is_string($data['capabilities'] ?? '[]')
                ? json_decode($data['capabilities'], true) ?? []
                : ($data['capabilities'] ?? []),
            tmuxSessionId: $data['tmux_session_id'] ?? null,
            projectPath: $data['project_path'] ?? '',
            maxConcurrentTasks: (int) ($data['max_concurrent_tasks'] ?? 1),
            status: $data['status'] ?? 'offline',
            currentTaskId: $data['current_task_id'] ?? null,
            lastHeartbeat: $data['last_heartbeat'] ?? null,
            lamportTs: (int) ($data['lamport_ts'] ?? 0),
            registeredAt: $data['registered_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            role: $data['role'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'node_id' => $this->nodeId,
            'name' => $this->name,
            'tool' => $this->tool,
            'model' => $this->model,
            'capabilities' => $this->capabilities,
            'tmux_session_id' => $this->tmuxSessionId,
            'project_path' => $this->projectPath,
            'max_concurrent_tasks' => $this->maxConcurrentTasks,
            'status' => $this->status,
            'current_task_id' => $this->currentTaskId,
            'last_heartbeat' => $this->lastHeartbeat,
            'lamport_ts' => $this->lamportTs,
            'registered_at' => $this->registeredAt,
            'role' => $this->role,
        ];
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
