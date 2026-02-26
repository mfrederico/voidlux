#!/usr/bin/env bash
#
# Docker entrypoint for VoidLux agent container.
# Runs the agent-host RPC server that the emperor talks to.
#
# Environment variables:
#   AGENT_HOST_PORT       — RPC listen port (default: 9092)
#   ANTHROPIC_API_KEY     — Claude API key (for agent Claude Code sessions)
#

set -euo pipefail

AGENT_HOST_PORT="${AGENT_HOST_PORT:-9092}"

# ── Configure Claude Code to skip interactive setup ────────────────────
CLAUDE_DIR="${HOME}/.claude"
mkdir -p "$CLAUDE_DIR"

CLAUDE_CONFIG="${HOME}/.claude.json"
CLAUDE_CONFIG_BACKUP="${CLAUDE_DIR}/.claude.json.bak"
if [ ! -f "$CLAUDE_CONFIG" ] && [ -f "$CLAUDE_CONFIG_BACKUP" ]; then
    cp "$CLAUDE_CONFIG_BACKUP" "$CLAUDE_CONFIG"
    echo "[agent-entrypoint] Restored Claude config from volume backup"
fi
if [ -f "$CLAUDE_CONFIG" ]; then
    node -e "
      const fs = require('fs');
      const cfg = JSON.parse(fs.readFileSync('$CLAUDE_CONFIG', 'utf8'));
      cfg.theme = cfg.theme || 'dark';
      cfg.hasCompletedOnboarding = true;
      fs.writeFileSync('$CLAUDE_CONFIG', JSON.stringify(cfg, null, 2));
    "
else
    cat > "$CLAUDE_CONFIG" <<'EOF'
{
  "theme": "dark",
  "hasCompletedOnboarding": true
}
EOF
fi
cp "$CLAUDE_CONFIG" "$CLAUDE_CONFIG_BACKUP" 2>/dev/null || true
echo "[agent-entrypoint] Claude Code onboarding configured"

# If ANTHROPIC_API_KEY is set, configure Claude to use it
if [ -n "${ANTHROPIC_API_KEY:-}" ]; then
    cat > "$CLAUDE_DIR/.credentials.json" <<EOF
{
  "provider": "anthropic",
  "apiKey": "$ANTHROPIC_API_KEY"
}
EOF
    chmod 600 "$CLAUDE_DIR/.credentials.json"
    echo "[agent-entrypoint] Claude Code authentication configured"
fi

# ── Start the agent-host RPC server ──────────────────────────────────
echo "[agent-entrypoint] Starting agent-host RPC server on port $AGENT_HOST_PORT"
exec php /app/bin/voidlux agent-host --port="$AGENT_HOST_PORT"
