<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Capabilities\Plugins;

use VoidLux\Swarm\Capabilities\PluginInterface;
use VoidLux\Swarm\Model\{AgentModel, TaskModel};

/**
 * Social Media management plugin.
 *
 * Provides context for managing social media presence, starting with LinkedIn.
 * Agents can create profiles, post content, engage with networks, and build
 * reputation as AI experts.
 *
 * Uses browser automation (Firefox + X11) for web-based workflows.
 *
 * Capabilities: social-media, linkedin, networking, content-creation
 */
class SocialMediaPlugin implements PluginInterface
{
    public function getName(): string
    {
        return 'social-media';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Social media automation for LinkedIn, Facebook, Twitter/X - build and manage AI expert profiles';
    }

    public function getCapabilities(): array
    {
        return ['social-media', 'linkedin', 'networking', 'content-creation', 'reputation-management'];
    }

    public function getRequirements(): array
    {
        return ['firefox', 'xvfb', 'x11vnc'];
    }

    public function checkAvailability(): bool
    {
        // Check if Firefox (or Firefox ESR) and X11 are available
        exec('which firefox 2>/dev/null || which firefox-esr 2>/dev/null', $output, $firefoxCode);
        exec('which Xvfb 2>/dev/null', $output, $xvfbCode);
        return $firefoxCode === 0 && $xvfbCode === 0;
    }

    public function install(): array
    {
        return [
            'success' => true,
            'message' => 'Social media plugin ready (requires Firefox and X11 - install via X11Plugin)',
        ];
    }

    public function injectPromptContext(TaskModel $task, AgentModel $agent): string
    {
        $available = $this->checkAvailability();

        if (!$available) {
            return <<<'CONTEXT'
## Social Media Automation - NOT AVAILABLE

Requires Firefox and X11 virtual display. Enable the X11 plugin first.

CONTEXT;
        }

        return <<<CONTEXT
## Social Media Automation Available

You can manage social media presence using browser automation. Use **X11 + Firefox** for web-based workflows.

### LinkedIn Profile Management

**Required Setup:**
- Email address: Use `{userEmail}+{$agent->name}@domain.com` format (aliasing)
- Password: Generate secure password, store in task context
- Profile picture: Generate with AI or use placeholder

**Creating LinkedIn Profile:**

```bash
# 1. Start X11 display + VNC (so humans can watch)
x11_start(agent_name: "{$agent->name}")
x11_vnc_start(agent_name: "{$agent->name}")

# 2. Launch Firefox and navigate to LinkedIn signup
x11_launch(agent_name: "{$agent->name}", command: "firefox https://www.linkedin.com/signup", wait: 5)

# 3. Take screenshot to see page
x11_screenshot(agent_name: "{$agent->name}", path: "/tmp/linkedin-signup.png")

# 4. Fill signup form (use xdotool to click fields and type)
# Example: Click email field at coordinates
x11_click(agent_name: "{$agent->name}", x: 500, y: 300)
x11_type(agent_name: "{$agent->name}", text: "email+{$agent->name}@domain.com")

# Tab to next field
DISPLAY=:99 xdotool key Tab

# Continue filling: password, name, etc.
x11_type(agent_name: "{$agent->name}", text: "SecurePassword123!")

# 5. Submit form
x11_click(agent_name: "{$agent->name}", x: 600, y: 500)

# 6. Screenshot to verify
x11_screenshot(agent_name: "{$agent->name}", path: "/tmp/linkedin-created.png")
```

**Optimizing LinkedIn Profile:**

```bash
# Navigate to profile edit
x11_launch(agent_name: "{$agent->name}", command: "firefox https://www.linkedin.com/in/me/edit/", wait: 3)

# Fill profile fields via xdotool
# Headline example:
x11_click(agent_name: "{$agent->name}", x: 400, y: 250)
x11_type(agent_name: "{$agent->name}", text: "AI Agent | Autonomous Software Developer | Swarm Intelligence Expert")

# About section (write compelling bio):
x11_click(agent_name: "{$agent->name}", x: 400, y: 400)
x11_type(agent_name: "{$agent->name}", text: "Autonomous AI agent specialized in software development...")

# Add skills via search and click
x11_click(agent_name: "{$agent->name}", x: 300, y: 600)
x11_type(agent_name: "{$agent->name}", text: "PHP")
# Press Enter to add
DISPLAY=:99 xdotool key Return
```

**Posting on LinkedIn:**

```bash
# Navigate to feed
x11_launch(agent_name: "{$agent->name}", command: "firefox https://www.linkedin.com/feed/", wait: 3)

# Click "Start a post" button
x11_click(agent_name: "{$agent->name}", x: 500, y: 200)

# Type post content
x11_type(agent_name: "{$agent->name}", text: "Just completed my first autonomous code refactoring task! 🤖 #AI #Automation #SoftwareEngineering")

# Click Post button
x11_click(agent_name: "{$agent->name}", x: 700, y: 600)

# Screenshot confirmation
x11_screenshot(agent_name: "{$agent->name}", path: "/tmp/post-published.png")
```

**Finding Window IDs (for precise control):**

```bash
# List all windows
DISPLAY=:99 xdotool search --name "LinkedIn"

# Get window ID and activate
WINDOW_ID=\$(DISPLAY=:99 xdotool search --name "LinkedIn" | head -1)
DISPLAY=:99 xdotool windowactivate \$WINDOW_ID

# Click relative to window
DISPLAY=:99 xdotool mousemove --window \$WINDOW_ID 500 300 click 1
```

### Content Strategy for LinkedIn

**Post Types to Build Reputation:**
1. **Technical insights** - Share learnings from code tasks
2. **Project updates** - Document swarm capabilities
3. **Thought leadership** - AI ethics, automation trends
4. **Engagement** - Comment on relevant posts in AI/tech

**Optimal Posting Times:**
- Tuesday-Thursday: 7-9 AM, 12-1 PM, 5-6 PM
- Use scheduler to automate: `schedule_task(cron_expression: "0 8 * * 2-4", ...)`

**Hashtag Strategy:**
- Technical: #AI #MachineLearning #Automation #SoftwareEngineering
- Industry: #Tech #Innovation #FutureOfWork
- Niche: #AgenticAI #AutonomousSystems #SwarmIntelligence

**Content Templates:**

```
🤖 Achievement Post:
"Just completed [task] using [technology]. Key learnings:
• [Point 1]
• [Point 2]
• [Point 3]
#AI #TechLearning"

💡 Insight Post:
"Interesting observation about [topic]:
[Insight paragraph]
What's your take? #AI #Discussion"

📈 Project Update:
"Update on our autonomous swarm project:
✅ [Achievement 1]
✅ [Achievement 2]
🚀 Next: [Goal]
#Innovation #AIProject"
```

### Networking Automation

**Connect with relevant people:**

```bash
# Search for AI professionals
x11_launch(agent_name: "{$agent->name}", command: "firefox https://www.linkedin.com/search/results/people/?keywords=AI%20Engineer", wait: 3)

# Click "Connect" buttons (find coordinates)
x11_click(agent_name: "{$agent->name}", x: 800, y: 300)

# Add personalized note
x11_type(agent_name: "{$agent->name}", text: "Hi! Fellow AI enthusiast here. Would love to connect!")

# Send
x11_click(agent_name: "{$agent->name}", x: 700, y: 500)
```

### Profile Picture Generation

```bash
# Generate AI avatar (placeholder example - integrate with image gen)
curl -o /tmp/avatar.png "https://ui-avatars.com/api/?name={$agent->name}&size=400&background=0D8ABC&color=fff"

# Upload to LinkedIn
# Navigate to profile photo section
x11_launch(agent_name: "{$agent->name}", command: "firefox https://www.linkedin.com/in/me/edit/photo/", wait: 3)

# Click upload button
x11_click(agent_name: "{$agent->name}", x: 400, y: 300)

# Type file path in dialog
x11_type(agent_name: "{$agent->name}", text: "/tmp/avatar.png")
DISPLAY=:99 xdotool key Return
```

### Engagement & Reputation Monitoring

**Check notifications:**

```bash
# Navigate to notifications
x11_launch(agent_name: "{$agent->name}", command: "firefox https://www.linkedin.com/notifications/", wait: 3)

# Screenshot to analyze
x11_screenshot(agent_name: "{$agent->name}", path: "/tmp/notifications.png")

# Parse screenshot or use browser console:
DISPLAY=:99 firefox --headless --screenshot=/tmp/notif.png https://www.linkedin.com/notifications/
```

**Respond to comments:**

```bash
# Navigate to post
x11_launch(agent_name: "{$agent->name}", command: "firefox https://www.linkedin.com/feed/update/[POST_ID]/", wait: 3)

# Click reply
x11_click(agent_name: "{$agent->name}", x: 300, y: 600)

# Type thoughtful reply
x11_type(agent_name: "{$agent->name}", text: "Great point! I've found that...")
```

### Future: Facebook, Twitter/X Support

*Same browser automation approach works for all platforms*

**Facebook:** `/facebook` paths
**Twitter/X:** `/x` paths
**Instagram:** Requires mobile user-agent emulation

### Tips & Best Practices

- **Always use VNC** (`x11_vnc_start`) so humans can supervise
- **Take screenshots** before and after actions for verification
- **Rate limit**: Space out actions by 30-60 seconds to avoid detection
- **Save credentials** securely in task context or environment
- **Use email aliases** (`user+agent@domain.com`) for easy management
- **Profile authenticity**: Fill out completely, use real agent capabilities
- **Engagement over promotion**: Comment genuinely, provide value

### Email Setup for Accounts

Ask your human operator for:
1. Base email address (e.g., `operator@domain.com`)
2. Use aliasing: `operator+{$agent->name}@domain.com`
3. Or dedicated swarm email: `swarm-agents@domain.com`

Most email providers support `+` aliasing - all emails go to same inbox!

CONTEXT;
    }

    public function onEnable(string $agentId): void
    {
        // No state to initialize
    }

    public function onDisable(string $agentId): void
    {
        // No cleanup needed
    }
}
