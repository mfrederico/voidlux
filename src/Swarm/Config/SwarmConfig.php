<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Config;

/**
 * Centralized configuration for swarm wake-up intervals and timeouts.
 *
 * Defaults match the hardcoded constants used across the swarm codebase.
 * Override via environment variables (VOIDLUX_ prefix) or CLI arguments
 * (--config-key=value format).
 *
 * Environment variables take precedence over CLI arguments.
 */
class SwarmConfig
{
    // --- Agent Monitoring ---
    public readonly int $agentPollInterval;
    public readonly int $agentHeartbeatInterval;
    public readonly int $agentOfflineThreshold;
    public readonly int $agentStartupGrace;

    // --- Leader Election ---
    public readonly int $emperorHeartbeatInterval;
    public readonly int $electionTimeout;
    public readonly int $emperorStaleThreshold;

    // --- Task Dispatch ---
    public readonly int $dispatchHeartbeatInterval;

    // --- Node Registry ---
    public readonly int $nodeHeartbeatInterval;
    public readonly int $nodeOfflineThreshold;

    // --- Health Checks ---
    public readonly int $healthCheckInterval;
    public readonly int $pingTimeout;
    public readonly int $degradedResponseMs;

    // --- Anti-Entropy Sync ---
    public readonly int $antiEntropyInterval;

    // --- Broker ---
    public readonly int $brokerSyncInterval;
    public readonly int $brokerCleanupInterval;
    public readonly float $messageTtl;

    // --- Upgrade ---
    public readonly int $canaryHealthTimeout;
    public readonly int $workerRestartTimeout;

    // --- HTTP Timeouts ---
    public readonly int $llmTimeout;
    public readonly int $httpProxyTimeout;

    // --- Clock Persistence ---
    public readonly int $clockPersistInterval;

    // --- Capability Advertisement ---
    public readonly int $capabilityAdvertiseInterval;

    /** Map of config key => default value */
    private const DEFAULTS = [
        'agent_poll_interval'           => 5,
        'agent_heartbeat_interval'      => 15,
        'agent_offline_threshold'       => 45,
        'agent_startup_grace'           => 10,
        'emperor_heartbeat_interval'    => 10,
        'election_timeout'              => 5,
        'emperor_stale_threshold'       => 30,
        'dispatch_heartbeat_interval'   => 30,
        'node_heartbeat_interval'       => 10,
        'node_offline_threshold'        => 30,
        'health_check_interval'         => 15,
        'ping_timeout'                  => 5,
        'degraded_response_ms'          => 2000,
        'anti_entropy_interval'         => 60,
        'broker_sync_interval'          => 120,
        'broker_cleanup_interval'       => 300,
        'message_ttl'                   => 300.0,
        'canary_health_timeout'         => 60,
        'worker_restart_timeout'        => 45,
        'llm_timeout'                   => 120,
        'http_proxy_timeout'            => 30,
        'clock_persist_interval'        => 30,
        'capability_advertise_interval' => 60,
    ];

    public function __construct(array $values = [])
    {
        $get = fn(string $key) => $values[$key] ?? self::DEFAULTS[$key];

        $this->agentPollInterval          = (int) $get('agent_poll_interval');
        $this->agentHeartbeatInterval     = (int) $get('agent_heartbeat_interval');
        $this->agentOfflineThreshold      = (int) $get('agent_offline_threshold');
        $this->agentStartupGrace          = (int) $get('agent_startup_grace');
        $this->emperorHeartbeatInterval   = (int) $get('emperor_heartbeat_interval');
        $this->electionTimeout            = (int) $get('election_timeout');
        $this->emperorStaleThreshold      = (int) $get('emperor_stale_threshold');
        $this->dispatchHeartbeatInterval  = (int) $get('dispatch_heartbeat_interval');
        $this->nodeHeartbeatInterval      = (int) $get('node_heartbeat_interval');
        $this->nodeOfflineThreshold       = (int) $get('node_offline_threshold');
        $this->healthCheckInterval        = (int) $get('health_check_interval');
        $this->pingTimeout                = (int) $get('ping_timeout');
        $this->degradedResponseMs         = (int) $get('degraded_response_ms');
        $this->antiEntropyInterval        = (int) $get('anti_entropy_interval');
        $this->brokerSyncInterval         = (int) $get('broker_sync_interval');
        $this->brokerCleanupInterval      = (int) $get('broker_cleanup_interval');
        $this->messageTtl                 = (float) $get('message_ttl');
        $this->canaryHealthTimeout        = (int) $get('canary_health_timeout');
        $this->workerRestartTimeout       = (int) $get('worker_restart_timeout');
        $this->llmTimeout                 = (int) $get('llm_timeout');
        $this->httpProxyTimeout           = (int) $get('http_proxy_timeout');
        $this->clockPersistInterval       = (int) $get('clock_persist_interval');
        $this->capabilityAdvertiseInterval = (int) $get('capability_advertise_interval');
    }

    /**
     * Build config from CLI --config-* arguments merged with VOIDLUX_* env vars.
     * Environment variables take precedence over CLI arguments.
     */
    public static function fromCliAndEnv(array $cliOptions): self
    {
        $values = [];

        // Pass 1: CLI --config-key=value args (lowest priority)
        foreach ($cliOptions as $key => $value) {
            if (str_starts_with($key, 'config-')) {
                $configKey = str_replace('-', '_', substr($key, 7));
                if (isset(self::DEFAULTS[$configKey])) {
                    $values[$configKey] = $value;
                }
            }
        }

        // Pass 2: VOIDLUX_* environment variables (highest priority)
        foreach (array_keys(self::DEFAULTS) as $key) {
            $envKey = 'VOIDLUX_' . strtoupper($key);
            $envVal = getenv($envKey);
            if ($envVal !== false) {
                $values[$key] = $envVal;
            }
        }

        return new self($values);
    }

    /**
     * Build config purely from environment variables.
     */
    public static function fromEnvironment(): self
    {
        return self::fromCliAndEnv([]);
    }

    /**
     * Return all current values as an associative array.
     */
    public function toArray(): array
    {
        $result = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $prop = lcfirst(str_replace('_', '', ucwords($key, '_')));
            $result[$key] = $this->$prop;
        }
        return $result;
    }

    /**
     * Return default values map (useful for documentation/help output).
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }
}
