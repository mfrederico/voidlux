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
 *
 * Model mappings associate each complexity tier with an appropriate LLM model
 * per provider. Defaults can be overridden via CLI flags or config files.
 */
class AgentSizeConfig
{
    private const VALID_TIERS = ['small', 'medium', 'large', 'xl'];

    private const MODEL_DEFAULTS = [
        'claude' => [
            'small'  => 'claude-haiku-4-5-20251001',
            'medium' => 'claude-sonnet-4-5-20250929',
            'large'  => 'claude-opus-4-6',
            'xl'     => 'claude-opus-4-6',
        ],
        'ollama' => [
            'small'  => 'qwen3:8b',
            'medium' => 'qwen3:32b',
            'large'  => 'qwen3:32b',
            'xl'     => 'qwen3:32b',
        ],
    ];

    /** @var array<string, array<string, string>> provider => tier => model */
    private static array $modelOverrides = [];

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

    public static function small(string $provider = 'claude'): self
    {
        return self::create(
            name: 'small',
            maxConcurrentTasks: 1,
            memoryLimitMb: 256,
            cpuWeight: 1,
            taskTimeoutSeconds: 120,
            preferredModel: self::modelForComplexity('small', $provider),
        );
    }

    public static function medium(string $provider = 'claude'): self
    {
        return self::create(
            name: 'medium',
            maxConcurrentTasks: 1,
            memoryLimitMb: 512,
            cpuWeight: 2,
            taskTimeoutSeconds: 300,
            preferredModel: self::modelForComplexity('medium', $provider),
        );
    }

    public static function large(string $provider = 'claude'): self
    {
        return self::create(
            name: 'large',
            maxConcurrentTasks: 2,
            memoryLimitMb: 1024,
            cpuWeight: 4,
            taskTimeoutSeconds: 600,
            preferredModel: self::modelForComplexity('large', $provider),
        );
    }

    public static function xl(string $provider = 'claude'): self
    {
        return self::create(
            name: 'xl',
            maxConcurrentTasks: 2,
            memoryLimitMb: 2048,
            cpuWeight: 8,
            taskTimeoutSeconds: 900,
            preferredModel: self::modelForComplexity('xl', $provider),
        );
    }

    /**
     * Resolve the appropriate size config for a task based on its priority.
     * Priority 0-3 = small, 4-7 = medium, 8-9 = large, 10 = xl.
     */
    public static function forTaskPriority(int $priority, string $provider = 'claude'): self
    {
        $complexity = match (true) {
            $priority >= 10 => 'xl',
            $priority >= 8 => 'large',
            $priority >= 4 => 'medium',
            default => 'small',
        };

        return self::forComplexity($complexity, $provider);
    }

    /**
     * Get the full size config for a complexity tier.
     */
    public static function forComplexity(string $complexity, string $provider = 'claude'): self
    {
        return match ($complexity) {
            'small'  => self::small($provider),
            'large'  => self::large($provider),
            'xl'     => self::xl($provider),
            default  => self::medium($provider),
        };
    }

    /**
     * Resolve the model name for a given complexity tier and provider.
     * Checks overrides first, then falls back to built-in defaults.
     */
    public static function modelForComplexity(string $complexity, string $provider = 'claude'): string
    {
        if (!in_array($complexity, self::VALID_TIERS, true)) {
            $complexity = 'medium';
        }

        // Check overrides first
        if (isset(self::$modelOverrides[$provider][$complexity])) {
            return self::$modelOverrides[$provider][$complexity];
        }

        return self::MODEL_DEFAULTS[$provider][$complexity]
            ?? self::MODEL_DEFAULTS['claude'][$complexity]
            ?? '';
    }

    /**
     * Set a custom model mapping for a specific provider and tier.
     */
    public static function setModelMapping(string $provider, string $tier, string $model): void
    {
        if (!in_array($tier, self::VALID_TIERS, true)) {
            return;
        }
        self::$modelOverrides[$provider][$tier] = $model;
    }

    /**
     * Get all model mappings for a provider (overrides merged with defaults).
     *
     * @return array<string, string> tier => model
     */
    public static function getModelMappings(string $provider = 'claude'): array
    {
        $defaults = self::MODEL_DEFAULTS[$provider] ?? self::MODEL_DEFAULTS['claude'];
        $overrides = self::$modelOverrides[$provider] ?? [];
        return array_merge($defaults, $overrides);
    }

    /**
     * Load model mappings from a JSON config file.
     *
     * Expected format: { "claude": { "small": "model-name", ... }, "ollama": { ... } }
     */
    public static function loadModelMappings(string $configPath): bool
    {
        if (!file_exists($configPath)) {
            return false;
        }

        $content = @file_get_contents($configPath);
        if ($content === false) {
            return false;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return false;
        }

        foreach ($data as $provider => $tiers) {
            if (!is_string($provider) || !is_array($tiers)) {
                continue;
            }
            foreach ($tiers as $tier => $model) {
                if (is_string($tier) && is_string($model)) {
                    self::setModelMapping($provider, $tier, $model);
                }
            }
        }

        return true;
    }

    /**
     * Apply model overrides from CLI options.
     *
     * Recognized flags: --model-small, --model-medium, --model-large, --model-xl, --model-config
     * Each --model-{tier} sets the model for ALL providers. Use --model-config for per-provider control.
     */
    public static function applyCliOverrides(array $options): void
    {
        // Load config file first (CLI flags take precedence over file)
        $configPath = $options['model-config'] ?? '';
        if ($configPath !== '') {
            self::loadModelMappings($configPath);
        }

        // Per-tier CLI overrides apply to all providers
        foreach (self::VALID_TIERS as $tier) {
            $model = $options["model-{$tier}"] ?? '';
            if ($model !== '') {
                foreach (array_keys(self::MODEL_DEFAULTS) as $provider) {
                    self::setModelMapping($provider, $tier, $model);
                }
                // Also set for any providers already in overrides
                foreach (array_keys(self::$modelOverrides) as $provider) {
                    self::setModelMapping($provider, $tier, $model);
                }
            }
        }
    }

    /**
     * Clear all overrides (useful for testing).
     */
    public static function resetOverrides(): void
    {
        self::$modelOverrides = [];
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
