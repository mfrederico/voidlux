<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Capabilities\Plugins;

use VoidLux\Swarm\Capabilities\McpToolProvider;
use VoidLux\Swarm\Model\{AgentModel, TaskModel};

/**
 * X11 desktop automation plugin using Xvfb virtual display.
 *
 * Provides stateful MCP primitives for managing virtual displays:
 * - Session management (start/stop X11 displays per agent)
 * - VNC server control (humans can watch agent work)
 * - Display interaction (click, type, screenshot, launch apps)
 *
 * NOTE: This is one of the few legitimate MCP tool providers because
 * it manages complex state (display sessions, PIDs) that bash alone
 * cannot easily maintain. Most plugins should just inject context.
 *
 * Requires: Xvfb, xdotool, ImageMagick, x11vnc
 */
class X11Plugin extends McpToolProvider
{
    /** @var array<string, array{display: string, pid: int, vnc_pid?: int, vnc_port?: int}> Active X11 sessions per agent */
    private static array $sessions = [];

    public function getName(): string
    {
        return 'x11';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Desktop automation via virtual X11 display (screenshot, click, type, launch apps)';
    }

    public function getCapabilities(): array
    {
        return ['x11', 'desktop-automation', 'gui-testing', 'screenshot'];
    }

    public function getRequirements(): array
    {
        return ['xvfb', 'xdotool', 'imagemagick', 'x11vnc'];
    }

    public function checkAvailability(): bool
    {
        // Check all required binaries
        $required = ['Xvfb', 'xdotool', 'import', 'convert', 'x11vnc'];
        foreach ($required as $cmd) {
            exec("which $cmd 2>/dev/null", $output, $exitCode);
            if ($exitCode !== 0) {
                return false;
            }
        }
        return true;
    }

    public function install(): array
    {
        // Check if apt is available
        exec('which apt-get 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            return [
                'success' => false,
                'message' => 'apt-get not found. This plugin requires Debian/Ubuntu.',
            ];
        }

        // Install X11 automation packages
        $packages = 'xvfb xdotool imagemagick x11-utils x11vnc';
        $cmd = "apt-get update && apt-get install -y {$packages} 2>&1";
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return [
                'success' => false,
                'message' => 'Failed to install X11 packages: ' . implode("\n", $output),
            ];
        }

        return [
            'success' => true,
            'message' => 'X11 automation tools installed (Xvfb, xdotool, ImageMagick)',
        ];
    }

    public function getTools(): array
    {
        return [
            (object) [
                'name' => 'x11_start',
                'description' => 'Start a virtual X11 display for desktop automation',
                'inputSchema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'agent_name' => (object) [
                            'type' => 'string',
                            'description' => 'Your agent name for plugin routing',
                        ],
                        'width' => (object) [
                            'type' => 'integer',
                            'description' => 'Display width in pixels (default: 1920)',
                        ],
                        'height' => (object) [
                            'type' => 'integer',
                            'description' => 'Display height in pixels (default: 1080)',
                        ],
                        'depth' => (object) [
                            'type' => 'integer',
                            'description' => 'Color depth (default: 24)',
                        ],
                    ],
                    'required' => ['agent_name'],
                ],
            ],
            (object) [
                'name' => 'x11_screenshot',
                'description' => 'Take a screenshot of the virtual X11 display',
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
                    ],
                    'required' => ['agent_name', 'path'],
                ],
            ],
            (object) [
                'name' => 'x11_click',
                'description' => 'Click the mouse at specific coordinates',
                'inputSchema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'agent_name' => (object) [
                            'type' => 'string',
                            'description' => 'Your agent name for plugin routing',
                        ],
                        'x' => (object) [
                            'type' => 'integer',
                            'description' => 'X coordinate in pixels',
                        ],
                        'y' => (object) [
                            'type' => 'integer',
                            'description' => 'Y coordinate in pixels',
                        ],
                        'button' => (object) [
                            'type' => 'integer',
                            'description' => 'Mouse button (1=left, 2=middle, 3=right, default: 1)',
                        ],
                    ],
                    'required' => ['agent_name', 'x', 'y'],
                ],
            ],
            (object) [
                'name' => 'x11_type',
                'description' => 'Type text via keyboard',
                'inputSchema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'agent_name' => (object) [
                            'type' => 'string',
                            'description' => 'Your agent name for plugin routing',
                        ],
                        'text' => (object) [
                            'type' => 'string',
                            'description' => 'Text to type',
                        ],
                    ],
                    'required' => ['agent_name', 'text'],
                ],
            ],
            (object) [
                'name' => 'x11_launch',
                'description' => 'Launch a GUI application in the virtual display',
                'inputSchema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'agent_name' => (object) [
                            'type' => 'string',
                            'description' => 'Your agent name for plugin routing',
                        ],
                        'command' => (object) [
                            'type' => 'string',
                            'description' => 'Command to execute (e.g., "firefox", "gedit")',
                        ],
                        'wait' => (object) [
                            'type' => 'integer',
                            'description' => 'Seconds to wait after launch (default: 2)',
                        ],
                    ],
                    'required' => ['agent_name', 'command'],
                ],
            ],
            (object) [
                'name' => 'x11_vnc_start',
                'description' => 'Start VNC server for remote viewing of the virtual display',
                'inputSchema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'agent_name' => (object) [
                            'type' => 'string',
                            'description' => 'Your agent name for plugin routing',
                        ],
                        'password' => (object) [
                            'type' => 'string',
                            'description' => 'VNC password (optional, max 8 chars)',
                        ],
                    ],
                    'required' => ['agent_name'],
                ],
            ],
            (object) [
                'name' => 'x11_vnc_stop',
                'description' => 'Stop VNC server',
                'inputSchema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'agent_name' => (object) [
                            'type' => 'string',
                            'description' => 'Your agent name for plugin routing',
                        ],
                    ],
                    'required' => ['agent_name'],
                ],
            ],
            (object) [
                'name' => 'x11_stop',
                'description' => 'Stop the virtual X11 display',
                'inputSchema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'agent_name' => (object) [
                            'type' => 'string',
                            'description' => 'Your agent name for plugin routing',
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
            'x11_start' => $this->handleStart($args, $agentId),
            'x11_screenshot' => $this->handleScreenshot($args, $agentId),
            'x11_click' => $this->handleClick($args, $agentId),
            'x11_type' => $this->handleType($args, $agentId),
            'x11_launch' => $this->handleLaunch($args, $agentId),
            'x11_vnc_start' => $this->handleVncStart($args, $agentId),
            'x11_vnc_stop' => $this->handleVncStop($args, $agentId),
            'x11_stop' => $this->handleStop($args, $agentId),
            default => $this->toolError("Unknown X11 tool: {$toolName}"),
        };
    }

    private function handleStart(array $args, string $agentId): array
    {
        // Check if session already exists
        if (isset(self::$sessions[$agentId])) {
            $display = self::$sessions[$agentId]['display'];
            return $this->toolResult([
                'status' => 'already_running',
                'display' => $display,
            ]);
        }

        $width = $args['width'] ?? 1920;
        $height = $args['height'] ?? 1080;
        $depth = $args['depth'] ?? 24;

        // Find free display number
        $display = $this->findFreeDisplay();

        // Start Xvfb
        $cmd = sprintf(
            'Xvfb %s -screen 0 %dx%dx%d > /dev/null 2>&1 & echo $!',
            $display,
            $width,
            $height,
            $depth
        );

        $pid = (int) trim(shell_exec($cmd));
        if (!$pid) {
            return $this->toolError('Failed to start Xvfb');
        }

        // Wait for X server to be ready
        sleep(1);

        // Verify it's running
        if (!$this->isDisplayRunning($display)) {
            return $this->toolError("Xvfb started but display {$display} not responding");
        }

        // Store session
        self::$sessions[$agentId] = [
            'display' => $display,
            'pid' => $pid,
        ];

        // Auto-start VNC server for remote viewing
        $vncPort = $this->findFreeVncPort();
        $vncCmd = sprintf(
            'x11vnc -display %s -rfbport %d -forever -shared -nopw > /dev/null 2>&1 & echo $!',
            escapeshellarg($display),
            $vncPort
        );
        $vncPid = (int) trim(shell_exec($vncCmd));

        if ($vncPid) {
            sleep(1); // Wait for VNC to be ready
            self::$sessions[$agentId]['vnc_pid'] = $vncPid;
            self::$sessions[$agentId]['vnc_port'] = $vncPort;
        }

        return $this->toolResult([
            'status' => 'started',
            'display' => $display,
            'resolution' => "{$width}x{$height}x{$depth}",
            'pid' => $pid,
            'vnc_port' => $vncPort ?? null,
            'vnc_url' => $vncPid ? "vnc://localhost:{$vncPort}" : null,
            'web_url' => $vncPid ? "http://localhost:6080/vnc.html?host=localhost&port={$vncPort}" : null,
        ]);
    }

    private function handleScreenshot(array $args, string $agentId): array
    {
        if (!isset(self::$sessions[$agentId])) {
            return $this->toolError('No X11 session running. Call x11_start first.');
        }

        $path = $args['path'] ?? '';
        if (!$path) {
            return $this->toolError('path is required');
        }

        // Ensure directory exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $display = self::$sessions[$agentId]['display'];

        // Take screenshot using ImageMagick import
        $cmd = sprintf(
            'DISPLAY=%s import -window root %s 2>&1',
            escapeshellarg($display),
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
            'display' => $display,
        ]);
    }

    private function handleClick(array $args, string $agentId): array
    {
        if (!isset(self::$sessions[$agentId])) {
            return $this->toolError('No X11 session running. Call x11_start first.');
        }

        $x = $args['x'] ?? null;
        $y = $args['y'] ?? null;
        $button = $args['button'] ?? 1;

        if ($x === null || $y === null) {
            return $this->toolError('x and y coordinates are required');
        }

        $display = self::$sessions[$agentId]['display'];

        // Use xdotool to click
        $cmd = sprintf(
            'DISPLAY=%s xdotool mousemove %d %d click %d 2>&1',
            escapeshellarg($display),
            (int) $x,
            (int) $y,
            (int) $button
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return $this->toolError("Click failed: " . implode("\n", $output));
        }

        return $this->toolResult([
            'status' => 'clicked',
            'x' => $x,
            'y' => $y,
            'button' => $button,
        ]);
    }

    private function handleType(array $args, string $agentId): array
    {
        if (!isset(self::$sessions[$agentId])) {
            return $this->toolError('No X11 session running. Call x11_start first.');
        }

        $text = $args['text'] ?? '';
        if (!$text) {
            return $this->toolError('text is required');
        }

        $display = self::$sessions[$agentId]['display'];

        // Use xdotool to type
        $cmd = sprintf(
            'DISPLAY=%s xdotool type %s 2>&1',
            escapeshellarg($display),
            escapeshellarg($text)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return $this->toolError("Type failed: " . implode("\n", $output));
        }

        return $this->toolResult([
            'status' => 'typed',
            'length' => strlen($text),
        ]);
    }

    private function handleLaunch(array $args, string $agentId): array
    {
        if (!isset(self::$sessions[$agentId])) {
            return $this->toolError('No X11 session running. Call x11_start first.');
        }

        $command = $args['command'] ?? '';
        if (!$command) {
            return $this->toolError('command is required');
        }

        $wait = $args['wait'] ?? 2;
        $display = self::$sessions[$agentId]['display'];

        // Launch application in background
        $cmd = sprintf(
            'DISPLAY=%s %s > /dev/null 2>&1 & echo $!',
            escapeshellarg($display),
            $command
        );

        $pid = (int) trim(shell_exec($cmd));
        if (!$pid) {
            return $this->toolError("Failed to launch: {$command}");
        }

        // Wait for app to initialize
        if ($wait > 0) {
            sleep($wait);
        }

        // Check if process is still running
        exec("ps -p {$pid} > /dev/null 2>&1", $output, $exitCode);
        $running = ($exitCode === 0);

        return $this->toolResult([
            'status' => $running ? 'launched' : 'exited',
            'command' => $command,
            'pid' => $pid,
            'display' => $display,
        ]);
    }

    private function handleVncStart(array $args, string $agentId): array
    {
        if (!isset(self::$sessions[$agentId])) {
            return $this->toolError('No X11 session running. Call x11_start first.');
        }

        $session = self::$sessions[$agentId];

        // Check if VNC already running
        if (isset($session['vnc_pid'])) {
            return $this->toolResult([
                'status' => 'already_running',
                'port' => $session['vnc_port'],
            ]);
        }

        $display = $session['display'];
        $password = $args['password'] ?? '';

        // Find free VNC port (5900+)
        $port = $this->findFreeVncPort();

        // Build x11vnc command
        $cmd = sprintf(
            'x11vnc -display %s -rfbport %d -forever -shared -nopw %s > /dev/null 2>&1 & echo $!',
            escapeshellarg($display),
            $port,
            $password ? '-passwd ' . escapeshellarg(substr($password, 0, 8)) : ''
        );

        $vncPid = (int) trim(shell_exec($cmd));
        if (!$vncPid) {
            return $this->toolError('Failed to start VNC server');
        }

        // Wait for VNC to be ready
        sleep(1);

        // Store VNC info in session
        self::$sessions[$agentId]['vnc_pid'] = $vncPid;
        self::$sessions[$agentId]['vnc_port'] = $port;

        return $this->toolResult([
            'status' => 'started',
            'port' => $port,
            'display' => $display,
            'vnc_url' => "vnc://localhost:{$port}",
            'web_url' => "http://localhost:6080/vnc.html?host=localhost&port={$port}",
            'pid' => $vncPid,
        ]);
    }

    private function handleVncStop(array $args, string $agentId): array
    {
        if (!isset(self::$sessions[$agentId]) || !isset(self::$sessions[$agentId]['vnc_pid'])) {
            return $this->toolResult([
                'status' => 'not_running',
            ]);
        }

        $vncPid = self::$sessions[$agentId]['vnc_pid'];

        // Kill VNC process
        exec("kill {$vncPid} 2>/dev/null");

        // Remove VNC info from session
        unset(self::$sessions[$agentId]['vnc_pid']);
        unset(self::$sessions[$agentId]['vnc_port']);

        return $this->toolResult([
            'status' => 'stopped',
        ]);
    }

    private function handleStop(array $args, string $agentId): array
    {
        if (!isset(self::$sessions[$agentId])) {
            return $this->toolResult([
                'status' => 'not_running',
            ]);
        }

        $session = self::$sessions[$agentId];

        // Stop VNC if running
        if (isset($session['vnc_pid'])) {
            exec("kill {$session['vnc_pid']} 2>/dev/null");
        }

        // Kill Xvfb process
        exec("kill {$session['pid']} 2>/dev/null");

        // Clean up session
        unset(self::$sessions[$agentId]);

        return $this->toolResult([
            'status' => 'stopped',
            'display' => $session['display'],
        ]);
    }

    private function findFreeDisplay(): string
    {
        // Start from :99 and look for free display
        for ($i = 99; $i < 200; $i++) {
            $display = ":{$i}";
            if (!$this->isDisplayRunning($display)) {
                return $display;
            }
        }
        return ':99'; // Fallback
    }

    private function isDisplayRunning(string $display): bool
    {
        exec("xdpyinfo -display {$display} > /dev/null 2>&1", $output, $exitCode);
        return $exitCode === 0;
    }

    private function findFreeVncPort(): int
    {
        // Start from 5900 and look for free port
        for ($port = 5900; $port < 5950; $port++) {
            exec("netstat -tuln 2>/dev/null | grep :{$port} > /dev/null", $output, $exitCode);
            if ($exitCode !== 0) {
                return $port;
            }
        }
        return 5900; // Fallback
    }

    public function injectPromptContext(TaskModel $task, AgentModel $agent): string
    {
        $available = $this->checkAvailability();

        if (!$available) {
            return <<<'CONTEXT'
## X11 Desktop Automation - NOT INSTALLED

To enable virtual display automation, install X11 tools:
```bash
apt-get update && apt-get install -y xvfb xdotool imagemagick x11-utils x11vnc
```

CONTEXT;
        }

        return <<<MD
## X11 Desktop Automation Available

You have **two ways** to use X11 automation:

### 1. Stateful MCP Tools (Recommended for Complex Workflows)

Use these MCP primitives for managed virtual display sessions:

- `x11_start(agent_name: "{$agent->name}", width?, height?, depth?)` - Start virtual X11 display (default: 1920x1080x24)
- `x11_screenshot(agent_name: "{$agent->name}", path)` - Capture display screenshot
- `x11_click(agent_name: "{$agent->name}", x, y, button?)` - Click at coordinates
- `x11_type(agent_name: "{$agent->name}", text)` - Type text via keyboard
- `x11_launch(agent_name: "{$agent->name}", command, wait?)` - Launch GUI application
- `x11_vnc_start(agent_name: "{$agent->name}", password?)` - Start VNC (humans can watch!)
- `x11_vnc_stop(agent_name: "{$agent->name}")` - Stop VNC
- `x11_stop(agent_name: "{$agent->name}")` - Stop display

**Workflow**: Start display → [Optional: Start VNC] → Launch apps → Screenshot → Analyze → Click/Type → Verify → Stop

### 2. Direct Bash (For Quick Tasks)

For simple one-off operations, use your native **Bash** tool:

```bash
# Start Xvfb manually
Xvfb :99 -screen 0 1920x1080x24 &
export DISPLAY=:99

# Take screenshot with ImageMagick
DISPLAY=:99 import -window root screenshot.png

# Click with xdotool
DISPLAY=:99 xdotool mousemove 100 200 click 1

# Type text
DISPLAY=:99 xdotool type "Hello World"

# Launch GUI app
DISPLAY=:99 firefox &
```

**Choose MCP tools** when you need persistent sessions or VNC viewing.
**Choose Bash** for quick screenshots or single-action tasks.

**IMPORTANT**: Always pass your agent_name ("{$agent->name}") when using MCP tools.
MD;
    }

    public function onEnable(string $agentId): void
    {
        // Initialize session storage for this agent if needed
    }

    public function onDisable(string $agentId): void
    {
        // Clean up any running X11 session
        if (isset(self::$sessions[$agentId])) {
            $pid = self::$sessions[$agentId]['pid'];
            exec("kill {$pid} 2>/dev/null");
            unset(self::$sessions[$agentId]);
        }
    }
}
