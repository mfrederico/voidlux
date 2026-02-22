<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Capabilities;

/**
 * Core interface that all VoidLux plugins must implement.
 *
 * Plugins extend agent capabilities by providing:
 * - Capability tags (e.g., 'browser', 'email', 'database')
 * - Prompt context (instructions for using native Claude Code tools)
 * - Optional: MCP tools (via McpToolProvider subclass) - only for true primitives
 * - Runtime dependency checking
 * - Lifecycle hooks for enable/disable events
 *
 * ARCHITECTURE NOTE:
 * Most plugins should provide context/instructions, NOT MCP tools.
 * MCP tools are only for swarm primitives (task lifecycle) and stateful
 * operations (X11 session management). For everything else, inject context
 * and let agents use their native Bash, Read, Write, Edit tools.
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

    /**
     * Inject plugin-specific context into task prompts.
     *
     * This is the PRIMARY way plugins extend agent capabilities. Instead of
     * creating custom MCP tools, provide rich context showing agents how to
     * use their native Bash, Read, Write tools to accomplish tasks.
     *
     * Example (BrowserPlugin):
     * ```
     * ## Browser Automation Available
     * Use Playwright via native Bash tool:
     * - npx playwright screenshot "https://example.com" output.png
     * - npx playwright eval "url" "document.title"
     * ```
     *
     * @param TaskModel $task The task being assigned to the agent
     * @param AgentModel $agent The agent receiving the task
     * @return string Markdown-formatted context to inject into the task prompt
     */
    public function injectPromptContext(\VoidLux\Swarm\Model\TaskModel $task, \VoidLux\Swarm\Model\AgentModel $agent): string;
}
