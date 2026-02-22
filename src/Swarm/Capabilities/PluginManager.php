<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Capabilities;

use VoidLux\Swarm\Storage\SwarmDatabase;
use VoidLux\Swarm\Model\{AgentModel, TaskModel};
use VoidLux\Swarm\Capabilities\Exceptions\{PluginNotFoundException, DependencyMissingException};

/**
 * Manages plugin discovery, lifecycle, and capability aggregation.
 *
 * Responsibilities:
 * - Auto-discover plugins in Plugins/ directory
 * - Enable/disable plugins per agent (via agent_plugins table)
 * - Aggregate capabilities for agents
 * - Route MCP tool calls to plugins
 * - Inject plugin context into task prompts
 */
class PluginManager
{
    private SwarmDatabase $db;

    /** @var array<string, PluginInterface> Plugin name => instance */
    private array $discoveredPlugins = [];

    public function __construct(SwarmDatabase $db)
    {
        $this->db = $db;
        $this->discoverPlugins();
    }

    /**
     * Auto-scan Plugins/ directory for plugin classes.
     */
    private function discoverPlugins(): void
    {
        $dir = __DIR__ . '/Plugins';
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob("$dir/*Plugin.php") as $file) {
            $className = basename($file, '.php');
            $fqcn = 'VoidLux\\Swarm\\Capabilities\\Plugins\\' . $className;

            if (!class_exists($fqcn)) {
                continue;
            }

            try {
                $plugin = new $fqcn();
                if (!($plugin instanceof PluginInterface)) {
                    continue;
                }
                $this->discoveredPlugins[$plugin->getName()] = $plugin;
            } catch (\Throwable $e) {
                // Plugin instantiation failed, skip it
                continue;
            }
        }
    }

    /**
     * Get all discovered plugins.
     *
     * @return array<string, PluginInterface>
     */
    public function getAllPlugins(): array
    {
        return $this->discoveredPlugins;
    }

    /**
     * Get a specific plugin by name.
     *
     * @throws PluginNotFoundException
     */
    public function getPlugin(string $name): PluginInterface
    {
        if (!isset($this->discoveredPlugins[$name])) {
            throw new PluginNotFoundException("Plugin not found: {$name}");
        }
        return $this->discoveredPlugins[$name];
    }

    /**
     * Enable a plugin for a specific agent.
     *
     * @throws PluginNotFoundException
     * @throws DependencyMissingException
     */
    public function enablePlugin(string $agentId, string $pluginName): void
    {
        $plugin = $this->getPlugin($pluginName);

        if (!$plugin->checkAvailability()) {
            $requirements = implode(', ', $plugin->getRequirements());
            throw new DependencyMissingException(
                "Plugin {$pluginName} requirements not met: {$requirements}"
            );
        }

        $this->db->enableAgentPlugin($agentId, $pluginName);
        $plugin->onEnable($agentId);
    }

    /**
     * Disable a plugin for a specific agent.
     *
     * @throws PluginNotFoundException
     */
    public function disablePlugin(string $agentId, string $pluginName): void
    {
        $plugin = $this->getPlugin($pluginName);
        $this->db->disableAgentPlugin($agentId, $pluginName);
        $plugin->onDisable($agentId);
    }

    /**
     * Get all enabled plugins for an agent.
     *
     * @return array<string, PluginInterface>
     */
    public function getEnabledPlugins(string $agentId): array
    {
        $pluginNames = $this->db->getAgentPlugins($agentId);
        $enabled = [];

        foreach ($pluginNames as $name) {
            if (isset($this->discoveredPlugins[$name])) {
                $enabled[$name] = $this->discoveredPlugins[$name];
            }
        }

        return $enabled;
    }

    /**
     * Detect and aggregate capabilities for an agent based on enabled plugins.
     *
     * @return string[]
     */
    public function detectCapabilities(string $agentId): array
    {
        $plugins = $this->getEnabledPlugins($agentId);
        $capabilities = [];

        foreach ($plugins as $plugin) {
            $capabilities = array_merge($capabilities, $plugin->getCapabilities());
        }

        return array_values(array_unique($capabilities));
    }

    /**
     * Route an MCP tool call to the appropriate plugin.
     *
     * @param string $toolName Name of the tool being called
     * @param array $args Tool arguments
     * @param string $agentId Agent ID calling the tool
     * @return array|null Tool result, or null if no plugin handles this tool
     */
    public function handleToolCall(string $toolName, array $args, string $agentId): ?array
    {
        $plugins = $this->getEnabledPlugins($agentId);

        foreach ($plugins as $plugin) {
            if (!($plugin instanceof McpToolProvider)) {
                continue;
            }

            $tools = $plugin->getTools();
            $toolNames = array_column($tools, 'name');

            if (in_array($toolName, $toolNames, true)) {
                return $plugin->handleToolCall($toolName, $args, $agentId);
            }
        }

        return null;
    }

    /**
     * Get aggregated prompt context from all enabled plugins for an agent.
     *
     * @param TaskModel $task The task being assigned
     * @param AgentModel $agent The agent receiving the task
     * @return string Combined plugin context (or empty string)
     */
    public function getPromptContext(TaskModel $task, AgentModel $agent): string
    {
        $plugins = $this->getEnabledPlugins($agent->id);
        $context = [];

        foreach ($plugins as $plugin) {
            // All plugins can provide context (not just MCP tool providers)
            $pluginContext = $plugin->injectPromptContext($task, $agent);
            if ($pluginContext) {
                $context[] = $pluginContext;
            }
        }

        return implode("\n\n", $context);
    }
}
