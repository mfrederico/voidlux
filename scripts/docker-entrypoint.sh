#!/usr/bin/env bash
#
# Docker entrypoint for VoidLux — starts seneschal + emperor in one container.
#
# Environment variables:
#   SENESCHAL_HTTP_PORT   — Seneschal HTTP proxy port (default: 9090)
#   SENESCHAL_P2P_PORT    — Seneschal P2P port (default: 7100)
#   SENESCHAL_DISC_PORT   — Seneschal discovery port (default: 6100)
#   EMPEROR_HTTP_PORT     — Emperor HTTP port (default: 9091)
#   EMPEROR_P2P_PORT      — Emperor P2P port (default: 7101)
#   EMPEROR_DISC_PORT     — Emperor discovery port (default: 6101)
#   VOIDLUX_DATA_DIR      — Data directory (default: /data)
#   VOIDLUX_LLM_PROVIDER  — LLM provider: ollama or claude
#   VOIDLUX_LLM_MODEL     — LLM model name
#   VOIDLUX_LLM_HOST      — Ollama host
#   VOIDLUX_LLM_PORT      — Ollama port
#   ANTHROPIC_API_KEY      — Claude API key
#   VOIDLUX_AUTH_SECRET    — P2P auth shared secret
#   VOIDLUX_TEST_COMMAND   — Test command for merge-test-retry
#

set -euo pipefail

SENESCHAL_HTTP_PORT="${SENESCHAL_HTTP_PORT:-9090}"
SENESCHAL_P2P_PORT="${SENESCHAL_P2P_PORT:-7100}"
SENESCHAL_DISC_PORT="${SENESCHAL_DISC_PORT:-6100}"
EMPEROR_HTTP_PORT="${EMPEROR_HTTP_PORT:-9091}"
EMPEROR_P2P_PORT="${EMPEROR_P2P_PORT:-7101}"
EMPEROR_DISC_PORT="${EMPEROR_DISC_PORT:-6101}"
DATA_DIR="${VOIDLUX_DATA_DIR:-/data}"

mkdir -p "$DATA_DIR"

# ── Configure Claude Code to skip interactive setup ────────────────────
CLAUDE_DIR="${HOME}/.claude"
mkdir -p "$CLAUDE_DIR"

# Claude Code's REAL config file is ~/.claude.json (NOT ~/.claude/config.json).
# The path is: $CLAUDE_CONFIG_DIR/.claude.json or $HOME/.claude.json
# Setting theme + hasCompletedOnboarding skips the entire onboarding wizard.
# We persist a backup in the volume so config survives container restarts.
CLAUDE_CONFIG="${HOME}/.claude.json"
CLAUDE_CONFIG_BACKUP="${CLAUDE_DIR}/.claude.json.bak"
if [ ! -f "$CLAUDE_CONFIG" ] && [ -f "$CLAUDE_CONFIG_BACKUP" ]; then
    cp "$CLAUDE_CONFIG_BACKUP" "$CLAUDE_CONFIG"
    echo "[entrypoint] Restored Claude config from volume backup"
fi
if [ -f "$CLAUDE_CONFIG" ]; then
    # Merge into existing config (preserve runtime state like oauthAccount, caches)
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
# Backup to volume for persistence across restarts
cp "$CLAUDE_CONFIG" "$CLAUDE_CONFIG_BACKUP" 2>/dev/null || true
echo "[entrypoint] Claude Code onboarding configured (${CLAUDE_CONFIG})"

# If ANTHROPIC_API_KEY is set, configure Claude to use it
if [ -n "${ANTHROPIC_API_KEY:-}" ]; then
    cat > "$CLAUDE_DIR/.credentials.json" <<EOF
{
  "provider": "anthropic",
  "apiKey": "$ANTHROPIC_API_KEY"
}
EOF
    chmod 600 "$CLAUDE_DIR/.credentials.json"
    echo "[entrypoint] Claude Code authentication configured with ANTHROPIC_API_KEY"
fi

SENESCHAL_PID=""
EMPEROR_PID=""

cleanup() {
    echo "[entrypoint] Shutting down..."
    [ -n "$EMPEROR_PID" ] && kill "$EMPEROR_PID" 2>/dev/null || true
    [ -n "$SENESCHAL_PID" ] && kill "$SENESCHAL_PID" 2>/dev/null || true
    wait
    echo "[entrypoint] All processes stopped."
    exit 0
}

trap cleanup SIGTERM SIGINT

# ── Build emperor command ──────────────────────────────────────────
EMPEROR_CMD=(php bin/voidlux swarm
    --role=emperor
    --http-port="$EMPEROR_HTTP_PORT"
    --p2p-port="$EMPEROR_P2P_PORT"
    --discovery-port="$EMPEROR_DISC_PORT"
    --data-dir="$DATA_DIR"
)

[ -n "${VOIDLUX_LLM_PROVIDER:-}" ] && EMPEROR_CMD+=(--llm-provider="$VOIDLUX_LLM_PROVIDER")
[ -n "${VOIDLUX_LLM_MODEL:-}" ]    && EMPEROR_CMD+=(--llm-model="$VOIDLUX_LLM_MODEL")
[ -n "${VOIDLUX_LLM_HOST:-}" ]     && EMPEROR_CMD+=(--llm-host="$VOIDLUX_LLM_HOST")
[ -n "${VOIDLUX_LLM_PORT:-}" ]     && EMPEROR_CMD+=(--llm-port="$VOIDLUX_LLM_PORT")
[ -n "${ANTHROPIC_API_KEY:-}" ]     && EMPEROR_CMD+=(--claude-api-key="$ANTHROPIC_API_KEY")
[ -n "${VOIDLUX_AUTH_SECRET:-}" ]   && EMPEROR_CMD+=(--auth-secret="$VOIDLUX_AUTH_SECRET")
[ -n "${VOIDLUX_TEST_COMMAND:-}" ]  && EMPEROR_CMD+=(--test-command="$VOIDLUX_TEST_COMMAND")

# ── Build seneschal command ────────────────────────────────────────
SENESCHAL_CMD=(php bin/voidlux seneschal
    --http-port="$SENESCHAL_HTTP_PORT"
    --p2p-port="$SENESCHAL_P2P_PORT"
    --discovery-port="$SENESCHAL_DISC_PORT"
    --seeds="127.0.0.1:$EMPEROR_P2P_PORT"
    --data-dir="$DATA_DIR"
)

# ── Start both processes ───────────────────────────────────────────
echo "[entrypoint] Starting emperor on HTTP=$EMPEROR_HTTP_PORT P2P=$EMPEROR_P2P_PORT"
"${EMPEROR_CMD[@]}" &
EMPEROR_PID=$!

# Give emperor a moment to bind ports before seneschal tries to connect
sleep 1

echo "[entrypoint] Starting seneschal on HTTP=$SENESCHAL_HTTP_PORT P2P=$SENESCHAL_P2P_PORT"
"${SENESCHAL_CMD[@]}" &
SENESCHAL_PID=$!

echo "[entrypoint] Both processes running (emperor=$EMPEROR_PID, seneschal=$SENESCHAL_PID)"

# Wait for either process to exit — if one dies, kill the other
wait -n "$EMPEROR_PID" "$SENESCHAL_PID"
EXIT_CODE=$?

echo "[entrypoint] A process exited with code $EXIT_CODE — shutting down"
cleanup
