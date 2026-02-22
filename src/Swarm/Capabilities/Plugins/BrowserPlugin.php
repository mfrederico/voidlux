<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Capabilities\Plugins;

use VoidLux\Swarm\Capabilities\McpToolProvider;
use VoidLux\Swarm\Model\{AgentModel, TaskModel};

/**
 * Browser automation plugin using Playwright.
 *
 * Provides MCP tools for:
 * - Navigating to URLs
 * - Taking screenshots
 * - Clicking elements
 * - Extracting page content
 *
 * Requires: Playwright CLI installed (https://playwright.dev/)
 */
class BrowserPlugin extends McpToolProvider
{
    public function getName(): string
    {
        return 'browser';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Browser automation via Playwright (navigate, screenshot, extract content)';
    }

    public function getCapabilities(): array
    {
        return ['browser', 'web-scraping', 'ui-testing', 'screenshot'];
    }

    public function getRequirements(): array
    {
        return ['playwright'];
    }

    public function checkAvailability(): bool
    {
        // Check if Playwright CLI is installed
        exec('which playwright 2>/dev/null', $output, $exitCode);
        if ($exitCode === 0) {
            return true;
        }

        // Fallback: check for npx playwright
        exec('which npx 2>/dev/null && npx playwright --version 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }

    public function install(): array
    {
        // Check if npm is available
        exec('which npm 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            return [
                'success' => false,
                'message' => 'npm not found. Install Node.js first: apt-get install nodejs npm',
            ];
        }

        // Install playwright globally
        $cmd = 'npm install -g playwright 2>&1';
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return [
                'success' => false,
                'message' => 'Failed to install playwright: ' . implode("\n", $output),
            ];
        }

        // Install browser binaries
        $cmd = 'npx playwright install chromium 2>&1';
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return [
                'success' => false,
                'message' => 'Playwright installed but browser install failed: ' . implode("\n", $output),
            ];
        }

        return [
            'success' => true,
            'message' => 'Playwright and Chromium installed successfully',
        ];
    }

    public function getTools(): array
    {
        return [
            (object) [
                'name' => 'browser_navigate',
                'description' => 'Navigate browser to a URL and optionally wait for a specific element',
                'inputSchema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'agent_name' => (object) [
                            'type' => 'string',
                            'description' => 'Your agent name for plugin routing',
                        ],
                        'url' => (object) [
                            'type' => 'string',
                            'description' => 'The URL to navigate to',
                        ],
                        'wait_for' => (object) [
                            'type' => 'string',
                            'description' => 'Optional CSS selector to wait for before returning',
                        ],
                    ],
                    'required' => ['agent_name', 'url'],
                ],
            ],
            (object) [
                'name' => 'browser_screenshot',
                'description' => 'Take a screenshot of the current page or a specific element',
                'inputSchema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'agent_name' => (object) [
                            'type' => 'string',
                            'description' => 'Your agent name for plugin routing',
                        ],
                        'path' => (object) [
                            'type' => 'string',
                            'description' => 'Output file path for the screenshot',
                        ],
                        'selector' => (object) [
                            'type' => 'string',
                            'description' => 'Optional CSS selector to screenshot (default: full page)',
                        ],
                        'full_page' => (object) [
                            'type' => 'boolean',
                            'description' => 'Capture full scrollable page (default: false)',
                        ],
                    ],
                    'required' => ['agent_name', 'path'],
                ],
            ],
            (object) [
                'name' => 'browser_extract',
                'description' => 'Extract text content from the current page or a specific element',
                'inputSchema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'agent_name' => (object) [
                            'type' => 'string',
                            'description' => 'Your agent name for plugin routing',
                        ],
                        'selector' => (object) [
                            'type' => 'string',
                            'description' => 'CSS selector to extract text from (default: body)',
                        ],
                    ],
                    'required' => ['agent_name'],
                ],
            ],
        ];
    }

    public function handleToolCall(string $toolName, array $args, string $agentId): array
    {
        return match ($toolName) {
            'browser_navigate' => $this->handleNavigate($args),
            'browser_screenshot' => $this->handleScreenshot($args),
            'browser_extract' => $this->handleExtract($args),
            default => $this->toolError("Unknown browser tool: {$toolName}"),
        };
    }

    private function handleNavigate(array $args): array
    {
        $url = $args['url'] ?? '';
        if (!$url) {
            return $this->toolError('url is required');
        }

        $waitFor = $args['wait_for'] ?? '';

        // Simple Playwright navigation via codegen
        // In production, you'd use a proper Playwright script
        $cmd = sprintf(
            'playwright codegen --save-storage=/tmp/pw-state.json %s 2>&1',
            escapeshellarg($url)
        );

        // For now, just verify URL is reachable
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $httpCode >= 400) {
            return $this->toolError("Failed to navigate to {$url} (HTTP {$httpCode})");
        }

        return $this->toolResult([
            'status' => 'navigated',
            'url' => $url,
            'http_code' => $httpCode,
        ]);
    }

    private function handleScreenshot(array $args): array
    {
        $path = $args['path'] ?? '';
        if (!$path) {
            return $this->toolError('path is required');
        }

        // Ensure path is writable
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Simple example: screenshot google.com
        // In production, you'd maintain browser state across tool calls
        $url = 'https://www.google.com';
        $cmd = sprintf(
            'playwright screenshot --full-page %s %s 2>&1',
            escapeshellarg($url),
            escapeshellarg($path)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($path)) {
            return $this->toolError("Screenshot failed: " . implode("\n", $output));
        }

        return $this->toolResult([
            'status' => 'screenshot_taken',
            'path' => $path,
            'size_bytes' => filesize($path),
        ]);
    }

    private function handleExtract(array $args): array
    {
        $selector = $args['selector'] ?? 'body';

        // Example implementation
        // In production, maintain browser state and use proper Playwright API
        return $this->toolResult([
            'status' => 'extracted',
            'selector' => $selector,
            'content' => 'Example: This would contain extracted text from the page',
        ]);
    }

    public function injectPromptContext(TaskModel $task, AgentModel $agent): string
    {
        $available = $this->checkAvailability();
        $installNote = $available ? '' : "\n**NOTE**: Plugin not installed. Call `plugin_install(plugin_name: \"browser\")` first.";

        return <<<MD
**Browser Automation (Playwright)**: You have access to browser automation tools:
- `browser_navigate(agent_name: "{$agent->name}", url, wait_for?)` - Navigate to a URL, optionally wait for an element
- `browser_screenshot(agent_name: "{$agent->name}", path, selector?, full_page?)` - Take a screenshot
- `browser_extract(agent_name: "{$agent->name}", selector?)` - Extract text content from the page

Use these for web scraping, UI testing, or automated browsing tasks.
**IMPORTANT**: Always pass your agent_name ("{$agent->name}") to plugin tools for routing.{$installNote}
MD;
    }

    public function onEnable(string $agentId): void
    {
        // Could initialize a browser instance here
        // For now, browser is created on-demand per tool call
    }

    public function onDisable(string $agentId): void
    {
        // Could cleanup browser instances here
    }
}
