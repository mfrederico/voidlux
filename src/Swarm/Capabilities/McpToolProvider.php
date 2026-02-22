<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Capabilities;

use VoidLux\Swarm\Model\{AgentModel, TaskModel};

/**
 * Base class for plugins that provide MCP tools to agents.
 *
 * MCP tools are exposed to agents via the Model Context Protocol,
 * allowing them to invoke plugin functionality (browser automation,
 * email sending, database queries, etc.) from within task execution.
 */
abstract class McpToolProvider implements PluginInterface
{
    /**
     * Return array of MCP tool definitions.
     *
     * Each tool must have:
     * - name: string
     * - description: string
     * - inputSchema: object (JSON Schema for parameters)
     *
     * @return array Array of tool definition objects
     */
    abstract public function getTools(): array;

    /**
     * Handle a tool invocation from an agent.
     *
     * @param string $toolName Name of the tool being called
     * @param array $args Tool arguments (validated against inputSchema)
     * @param string $agentId ID of the agent calling the tool
     * @return array MCP tool result (see toolResult/toolError helpers)
     */
    abstract public function handleToolCall(string $toolName, array $args, string $agentId): array;

    /**
     * Inject plugin-specific context into agent task prompts.
     *
     * This text is appended to the task prompt when the plugin is enabled
     * for the assigned agent. Use it to document available tools and usage.
     *
     * @param TaskModel $task The task being assigned
     * @param AgentModel $agent The agent receiving the task
     * @return string Markdown-formatted context to inject (or empty string)
     */
    public function injectPromptContext(TaskModel $task, AgentModel $agent): string
    {
        return '';
    }

    /**
     * Helper: Format successful tool result for MCP protocol.
     *
     * @param array $data Result data (will be JSON-encoded)
     * @return array MCP-formatted response
     */
    protected function toolResult(array $data): array
    {
        return [
            'content' => [
                (object) [
                    'type' => 'text',
                    'text' => json_encode($data, JSON_THROW_ON_ERROR),
                ],
            ],
        ];
    }

    /**
     * Helper: Format tool error for MCP protocol.
     *
     * @param string $message Error message
     * @return array MCP-formatted error response
     */
    protected function toolError(string $message): array
    {
        return [
            'content' => [
                (object) [
                    'type' => 'text',
                    'text' => json_encode(['error' => $message], JSON_THROW_ON_ERROR),
                ],
            ],
            'isError' => true,
        ];
    }
}
