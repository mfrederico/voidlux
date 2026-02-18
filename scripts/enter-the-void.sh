#!/usr/bin/env bash
#
# Enter the Void — Launch a VoidLux swarm.
# Two tmux sessions:
#   voidlux-seneschal — Long-lived stable proxy (survives cycles)
#   voidlux-swarm     — Emperor + 2 Workers (cyclable)
#
# Usage:
#   bash scripts/enter-the-void.sh          # Start both (skip seneschal if running)
#   bash scripts/enter-the-void.sh --full   # Force-restart everything including seneschal
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
DATA_DIR="${PROJECT_DIR}/data"

SENESCHAL_SESSION="voidlux-seneschal"
SWARM_SESSION="voidlux-swarm"

FULL_RESTART=false
if [[ "${1:-}" == "--full" ]]; then
    FULL_RESTART=true
fi

# Port assignments:            Seneschal  Emperor  Worker1  Worker2
HTTP_PORTS=(9090 9091 9092 9093)
P2P_PORTS=(7100 7101 7102 7103)
DISC_PORTS=(6101 6101 6101 6101)   # Same discovery port for UDP mesh

# Clean old swarm data
rm -rf "${DATA_DIR}"/swarm-*
mkdir -p "${DATA_DIR}"

# Emperor's P2P port for seeding
EMPEROR_P2P="${P2P_PORTS[1]}"

# ── Kill swarm session + agent sessions ─────────────────────────────
tmux kill-session -t "$SWARM_SESSION" 2>/dev/null || true
for s in $(tmux list-sessions -F '#{session_name}' 2>/dev/null | grep '^vl-'); do
    tmux kill-session -t "$s" 2>/dev/null || true
done

# Kill seneschal session only if --full
if $FULL_RESTART; then
    tmux kill-session -t "$SENESCHAL_SESSION" 2>/dev/null || true
fi

# Kill orphaned PHP/Swoole processes still holding swarm ports
# (tmux kill-session doesn't kill reparented child processes)
if $FULL_RESTART; then
    KILL_PORTS=("${HTTP_PORTS[@]}" "${P2P_PORTS[@]}")
else
    # Only kill swarm ports (not seneschal's 9090/7100)
    KILL_PORTS=(${HTTP_PORTS[@]:1} ${P2P_PORTS[@]:1})
fi
for port in "${KILL_PORTS[@]}"; do
    pids=$(ss -tlnp "sport = :$port" 2>/dev/null | grep -oP 'pid=\K[0-9]+' | sort -u || true)
    [ -n "$pids" ] && kill $pids 2>/dev/null || true
done
# Wait until ports are actually free (up to 5s)
for attempt in $(seq 1 10); do
    BUSY=false
    for port in "${KILL_PORTS[@]}"; do
        if ss -tlnp "sport = :$port" 2>/dev/null | grep -q pid=; then
            BUSY=true
            break
        fi
    done
    $BUSY || break
    sleep 0.5
done

# ── Seneschal session (create if not running) ────────────────────────
if ! tmux has-session -t "$SENESCHAL_SESSION" 2>/dev/null; then
    echo "Starting seneschal session..."
    tmux new-session -d -s "$SENESCHAL_SESSION" -x 200 -y 50

    CMD="cd ${PROJECT_DIR} && php bin/voidlux seneschal"
    CMD="${CMD} --http-port=${HTTP_PORTS[0]}"
    CMD="${CMD} --p2p-port=${P2P_PORTS[0]}"
    CMD="${CMD} --discovery-port=${DISC_PORTS[0]}"
    CMD="${CMD} --seeds=127.0.0.1:${EMPEROR_P2P}"
    tmux send-keys -t "$SENESCHAL_SESSION" "$CMD" C-m
else
    echo "Seneschal session already running (use --full to restart)"
fi

# ── Swarm session (emperor + 2 workers) ─────────────────────────────
bash "$SCRIPT_DIR/launch-swarm-session.sh"

echo ""
echo "=== Enter the Void ==="
echo "Sessions:"
echo "  tmux attach -t $SENESCHAL_SESSION   (seneschal — long-lived)"
echo "  tmux attach -t $SWARM_SESSION       (emperor + workers — cyclable)"
echo ""
echo "  Seneschal: http://localhost:${HTTP_PORTS[0]}  (stable proxy — use this)"
echo "  Emperor:   http://localhost:${HTTP_PORTS[1]}  (dashboard, direct)"
echo "  Worker 1:  http://localhost:${HTTP_PORTS[2]}"
echo "  Worker 2:  http://localhost:${HTTP_PORTS[3]}"
echo ""
echo "LLM: ${VOIDLUX_LLM_PROVIDER:-ollama}/${VOIDLUX_LLM_MODEL:-qwen3-coder:30b}"
[ -n "${VOIDLUX_AUTH_SECRET:-}" ] && echo "Auth: HMAC-SHA256 (secret configured)"
[ -n "${VOIDLUX_TEST_COMMAND:-}" ] && echo "Test: ${VOIDLUX_TEST_COMMAND}"
echo ""
echo "Quick start:"
echo "  # Register agents on worker 1:"
echo "  bash scripts/register-agents.sh 3 claude /tmp/test ${HTTP_PORTS[2]}"
echo ""
echo "  # Create a task (via Seneschal — emperor will decompose + dispatch):"
echo "  curl -s -X POST http://localhost:${HTTP_PORTS[0]}/api/swarm/tasks \\"
echo "    -H 'Content-Type: application/json' \\"
echo "    -d '{\"title\":\"Add hello world endpoint\",\"project_path\":\"/tmp/test\"}'"
echo ""
echo "  # Override LLM model:"
echo "  VOIDLUX_LLM_MODEL=llama3.1:8b bash scripts/enter-the-void.sh"
echo ""
echo "  # Full restart (including seneschal):"
echo "  bash scripts/enter-the-void.sh --full"
echo ""
