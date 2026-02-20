<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Agent;

/**
 * Defines sizing parameters for agent capability fitting.
 *
 * Each size config describes what an agent can handle: which capabilities
 * it advertises, what resources it requires, and baseline performance
 * expectations. Used by the dispatcher to match tasks to appropriately
 * sized agents and by the emperor to plan agent provisioning.
 */
class AgentSizeConfig
{
    public function __construct(
        public readonly string $name,
        public readonly array $capabilities,
        public readonly int $maxConcurrentTasks,
        public readonly int $memoryLimitMb,
        public readonly int $cpuWeight,
        public readonly int $taskTimeoutSeconds,
        public readonly int $maxTasksPerHour,
        public readonly string $preferredTool,
        public readonly string $preferredModel,
    ) {}

    public static function create(
        string $name,
        array $capabilities = [],
        int $maxConcurrentTasks = 1,
        int $memoryLimitMb = 512,
        int $cpuWeight = 1,
        int $taskTimeoutSeconds = 300,
        int $maxTasksPerHour = 0,
        string $preferredTool = 'claude',
        string $preferredModel = '',
    ): self {
        return new self(
            name: $name,
            capabilities: $capabilities,
            maxConcurrentTasks: $maxConcurrentTasks,
            memoryLimitMb: $memoryLimitMb,
            cpuWeight: $cpuWeight,
            taskTimeoutSeconds: $taskTimeoutSeconds,
            maxTasksPerHour: $maxTasksPerHour,
            preferredTool: $preferredTool,
            preferredModel: $preferredModel,
        );
    }

    public static function small(): self
    {
        return self::create(
            name: 'small',
            maxConcurrentTasks: 1,
            memoryLimitMb: 256,
            cpuWeight: 1,
            taskTimeoutSeconds: 120,
        );
    }

    public static function medium(): self
    {
        return self::create(
            name: 'medium',
            maxConcurrentTasks: 1,
            memoryLimitMb: 512,
            cpuWeight: 2,
            taskTimeoutSeconds: 300,
        );
    }

    public static function large(): self
    {
        return self::create(
            name: 'large',
            maxConcurrentTasks: 2,
            memoryLimitMb: 1024,
            cpuWeight: 4,
            taskTimeoutSeconds: 600,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? 'custom',
            capabilities: is_string($data['capabilities'] ?? '[]')
                ? json_decode($data['capabilities'], true) ?? []
                : ($data['capabilities'] ?? []),
            maxConcurrentTasks: (int) ($data['max_concurrent_tasks'] ?? 1),
            memoryLimitMb: (int) ($data['memory_limit_mb'] ?? 512),
            cpuWeight: (int) ($data['cpu_weight'] ?? 1),
            taskTimeoutSeconds: (int) ($data['task_timeout_seconds'] ?? 300),
            maxTasksPerHour: (int) ($data['max_tasks_per_hour'] ?? 0),
            preferredTool: $data['preferred_tool'] ?? 'claude',
            preferredModel: $data['preferred_model'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'capabilities' => $this->capabilities,
            'max_concurrent_tasks' => $this->maxConcurrentTasks,
            'memory_limit_mb' => $this->memoryLimitMb,
            'cpu_weight' => $this->cpuWeight,
            'task_timeout_seconds' => $this->taskTimeoutSeconds,
            'max_tasks_per_hour' => $this->maxTasksPerHour,
            'preferred_tool' => $this->preferredTool,
            'preferred_model' => $this->preferredModel,
        ];
    }

    /**
     * Check if this config's capabilities satisfy a task's requirements.
     * Empty capabilities = universal (matches any task).
     */
    public function satisfies(array $requiredCapabilities): bool
    {
        if (empty($this->capabilities)) {
            return true;
        }
        if (empty($requiredCapabilities)) {
            return true;
        }
        return empty(array_diff($requiredCapabilities, $this->capabilities));
    }
}
