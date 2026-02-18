#!/usr/bin/env bash
#
# Launch the VoidLux swarm session (emperor + 2 workers).
# Does NOT touch the seneschal — it runs in its own long-lived session.
#
# Called by:
#   - scripts/enter-the-void.sh (initial launch)
#   - Seneschal cycle coroutine (restart after code changes)
#
# Env vars:
#   VOIDLUX_LLM_MODEL      — LLM model name (default: qwen3-coder:30b)
#   VOIDLUX_LLM_PROVIDER   — LLM provider (default: ollama)
#   VOIDLUX_LLM_HOST       — LLM host
#   VOIDLUX_LLM_PORT       — LLM port
#   ANTHROPIC_API_KEY       — Claude API key
#   VOIDLUX_AUTH_SECRET     — P2P auth secret
#   VOIDLUX_TEST_COMMAND    — Test command for merge-test-retry
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
DATA_DIR="${PROJECT_DIR}/data"

SWARM_SESSION="voidlux-swarm"

# Port assignments:     Emperor  Worker1  Worker2
HTTP_PORTS=(9091 9092 9093)
P2P_PORTS=(7101 7102 7103)
DISC_PORTS=(6101 6101 6101)

# Emperor's P2P port for worker seeding
EMPEROR_P2P="${P2P_PORTS[0]}"

mkdir -p "${DATA_DIR}"

# ── Kill existing swarm session + agent sessions ────────────────────
tmux kill-session -t "$SWARM_SESSION" 2>/dev/null || true
for s in $(tmux list-sessions -F '#{session_name}' 2>/dev/null | grep '^vl-'); do
    # Get child PIDs before killing
    PANE_PID=$(tmux list-panes -t "$s" -F '#{pane_pid}' 2>/dev/null || true)
    CHILD_PIDS=""
    if [ -n "$PANE_PID" ]; then
        CHILD_PIDS=$(pgrep -P "$PANE_PID" 2>/dev/null || true)
        for cpid in $CHILD_PIDS; do
            CHILD_PIDS="$CHILD_PIDS $(pgrep -P "$cpid" 2>/dev/null || true)"
        done
    fi
    tmux send-keys -t "$s" C-c 2>/dev/null || true
    sleep 0.1
    tmux kill-session -t "$s" 2>/dev/null || true
    for pid in $CHILD_PIDS; do
        [ -n "$pid" ] && kill "$pid" 2>/dev/null || true
    done
done

# Kill orphaned processes on swarm ports
for port in "${HTTP_PORTS[@]}" "${P2P_PORTS[@]}"; do
    pids=$(ss -tlnp "sport = :$port" 2>/dev/null | grep -oP 'pid=\K[0-9]+' | sort -u || true)
    [ -n "$pids" ] && kill $pids 2>/dev/null || true
done
sleep 0.5

# ── Create swarm session ────────────────────────────────────────────
ROLES=(emperor worker worker)

tmux new-session -d -s "$SWARM_SESSION" -x 200 -y 50

for i in 0 1 2; do
    HTTP_PORT=${HTTP_PORTS[$i]}
    P2P_PORT=${P2P_PORTS[$i]}
    DISC_PORT=${DISC_PORTS[$i]}
    ROLE=${ROLES[$i]}

    # Workers seed to emperor; emperor discovers via UDP
    if [ "$i" -eq 0 ]; then
        SEEDS=""
    else
        SEEDS="--seeds=127.0.0.1:${EMPEROR_P2P}"
    fi

    CMD="cd ${PROJECT_DIR} && php bin/voidlux swarm"
    CMD="${CMD} --http-port=${HTTP_PORT}"
    CMD="${CMD} --p2p-port=${P2P_PORT}"
    CMD="${CMD} --discovery-port=${DISC_PORT}"
    CMD="${CMD} --role=${ROLE}"
    CMD="${CMD} --data-dir=${DATA_DIR}"
    [ -n "$SEEDS" ] && CMD="${CMD} ${SEEDS}"

    # Emperor gets LLM config for AI planning/review
    if [ "$ROLE" = "emperor" ]; then
        LLM_MODEL="${VOIDLUX_LLM_MODEL:-qwen3-coder:30b}"
        LLM_PROVIDER="${VOIDLUX_LLM_PROVIDER:-ollama}"
        CMD="${CMD} --llm-provider=${LLM_PROVIDER} --llm-model=${LLM_MODEL}"
        [ -n "${VOIDLUX_LLM_HOST:-}" ] && CMD="${CMD} --llm-host=${VOIDLUX_LLM_HOST}"
        [ -n "${VOIDLUX_LLM_PORT:-}" ] && CMD="${CMD} --llm-port=${VOIDLUX_LLM_PORT}"
        [ -n "${ANTHROPIC_API_KEY:-}" ] && CMD="${CMD} --claude-api-key=${ANTHROPIC_API_KEY}"
    fi

    # Auth secret for P2P connection authentication
    AUTH_SECRET="${VOIDLUX_AUTH_SECRET:-}"
    [ -n "$AUTH_SECRET" ] && CMD="${CMD} --auth-secret=${AUTH_SECRET}"

    # Test command for merge-test-retry loop
    TEST_CMD="${VOIDLUX_TEST_COMMAND:-}"
    [ -n "$TEST_CMD" ] && CMD="${CMD} --test-command=${TEST_CMD}"

    if [ "$i" -eq 0 ]; then
        # First pane (already exists from new-session)
        tmux send-keys -t "$SWARM_SESSION" "$CMD" C-m
    else
        tmux split-window -t "$SWARM_SESSION" -v
        tmux send-keys -t "$SWARM_SESSION" "$CMD" C-m
        tmux select-layout -t "$SWARM_SESSION" tiled
    fi
done

echo "Swarm session launched: $SWARM_SESSION (emperor + 2 workers)"
