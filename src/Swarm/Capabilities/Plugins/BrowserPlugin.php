<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Capabilities\Plugins;

use VoidLux\Swarm\Capabilities\PluginInterface;
use VoidLux\Swarm\Model\{AgentModel, TaskModel};

/**
 * Browser automation plugin using Playwright.
 *
 * Provides context for agents to use native Bash tool for:
 * - Navigating to URLs
 * - Taking screenshots
 * - Extracting page content
 * - UI testing and web scraping
 *
 * Requires: Playwright installed (https://playwright.dev/)
 */
class BrowserPlugin implements PluginInterface
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

    public function injectPromptContext(TaskModel $task, AgentModel $agent): string
    {
        $available = $this->checkAvailability();

        if (!$available) {
            return <<<'CONTEXT'
## Browser Automation (Playwright) - NOT INSTALLED

To enable browser automation, install Playwright first:
```bash
npm install -g playwright && npx playwright install chromium
```

CONTEXT;
        }

        return <<<'CONTEXT'
## Browser Automation Available (Playwright)

You have Playwright installed for browser automation. Use your native **Bash** tool:

### Navigate to URL and extract content:
```bash
npx playwright eval "https://example.com" "document.body.innerText"
```

### Take full-page screenshot:
```bash
npx playwright screenshot --full-page "https://example.com" screenshot.png
```

### Take element screenshot:
```bash
npx playwright screenshot --selector ".main-content" "https://example.com" element.png
```

### Generate Playwright script for complex interactions:
```bash
npx playwright codegen https://example.com
# Opens browser for recording, generates script to stdout
```

### Run custom Playwright script:
Create a script file with the Playwright API, then:
```bash
node your-script.js
```

**Example script** (save as `scrape.js`):
```javascript
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.goto('https://example.com');

  // Extract data
  const title = await page.title();
  const content = await page.textContent('body');

  console.log(JSON.stringify({ title, content }));
  await browser.close();
})();
```

**Tips:**
- Use `--browser=chromium|firefox|webkit` to choose browser
- Add `--timeout=30000` for slow pages
- Combine with `jq` to parse JSON output
- Screenshots are saved relative to current directory

CONTEXT;
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
