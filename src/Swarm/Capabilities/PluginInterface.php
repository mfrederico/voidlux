<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Capabilities;

/**
 * Core interface that all VoidLux plugins must implement.
 *
 * Plugins extend agent capabilities by providing:
 * - Capability tags (e.g., 'browser', 'email', 'database')
 * - MCP tools (via McpToolProvider subclass)
 * - Runtime dependency checking
 * - Lifecycle hooks for enable/disable events
 */
interface PluginInterface
{
    /**
     * Unique plugin identifier (lowercase, no spaces).
     */
    public function getName(): string;

    /**
     * Semantic version (e.g., "1.0.0").
     */
    public function getVersion(): string;

    /**
     * Human-readable description of what this plugin does.
     */
    public function getDescription(): string;

    /**
     * List of capability tags this plugin provides.
     * These are added to agent.capabilities when the plugin is enabled.
     *
     * @return string[] e.g., ['browser', 'web-scraping', 'ui-testing']
     */
    public function getCapabilities(): array;

    /**
     * List of external dependencies required for this plugin to work.
     * Used by checkAvailability() to verify the plugin can run.
     *
     * @return string[] e.g., ['playwright', 'chromium', 'ANTHROPIC_API_KEY']
     */
    public function getRequirements(): array;

    /**
     * Runtime check to verify all dependencies are available.
     * Called during plugin discovery and before enabling.
     *
     * @return bool True if the plugin can be enabled, false otherwise
     */
    public function checkAvailability(): bool;

    /**
     * Install plugin dependencies.
     * Called when an agent requests plugin installation.
     *
     * @return array Result with 'success' bool and 'message' string
     */
    public function install(): array;

    /**
     * Lifecycle hook: called when this plugin is enabled for an agent.
     *
     * @param string $agentId The agent ID this plugin is being enabled for
     */
    public function onEnable(string $agentId): void;

    /**
     * Lifecycle hook: called when this plugin is disabled for an agent.
     *
     * @param string $agentId The agent ID this plugin is being disabled for
     */
    public function onDisable(string $agentId): void;
}
