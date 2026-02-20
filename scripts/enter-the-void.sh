#!/usr/bin/env bash
#
# Enter the Void — Launch a VoidLux swarm.
# Two tmux sessions:
#   voidlux-seneschal — Long-lived stable proxy (survives cycles)
#   voidlux-swarm     — Emperor (cyclable)
#
# Usage:
#   bash scripts/enter-the-void.sh          # Full restart (seneschal + emperor)
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
DATA_DIR="${PROJECT_DIR}/data"

SENESCHAL_SESSION="voidlux-seneschal"
SWARM_SESSION="voidlux-swarm"

# Port assignments:            Seneschal  Emperor
HTTP_PORTS=(9090 9091)
P2P_PORTS=(7100 7101)
DISC_PORTS=(6101 6101)

# Clean old swarm data
rm -rf "${DATA_DIR}"/swarm-*
mkdir -p "${DATA_DIR}"

# Emperor's P2P port for seneschal seeding
EMPEROR_P2P="${P2P_PORTS[1]}"

# ── Kill swarm session + agent sessions ─────────────────────────────
tmux kill-session -t "$SWARM_SESSION" 2>/dev/null || true
for s in $(tmux list-sessions -F '#{session_name}' 2>/dev/null | grep '^vl-'); do
    tmux kill-session -t "$s" 2>/dev/null || true
done

# Kill seneschal session
tmux kill-session -t "$SENESCHAL_SESSION" 2>/dev/null || true

# Kill orphaned PHP/Swoole processes still holding ports
KILL_PORTS=("${HTTP_PORTS[@]}" "${P2P_PORTS[@]}")
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
echo "Starting seneschal session..."
tmux new-session -d -s "$SENESCHAL_SESSION" -x 200 -y 50

CMD="cd ${PROJECT_DIR} && php bin/voidlux seneschal"
CMD="${CMD} --http-port=${HTTP_PORTS[0]}"
CMD="${CMD} --p2p-port=${P2P_PORTS[0]}"
CMD="${CMD} --discovery-port=${DISC_PORTS[0]}"
CMD="${CMD} --seeds=127.0.0.1:${EMPEROR_P2P}"
tmux send-keys -t "$SENESCHAL_SESSION" "$CMD" C-m

# ── Swarm session (emperor only) ──────────────────────────────────
bash "$SCRIPT_DIR/launch-swarm-session.sh"

echo ""
echo "=== Enter the Void ==="
echo "Sessions:"
echo "  tmux attach -t $SENESCHAL_SESSION   (seneschal — long-lived)"
echo "  tmux attach -t $SWARM_SESSION       (emperor)"
echo ""
echo "  Seneschal: http://localhost:${HTTP_PORTS[0]}  (stable proxy — use this)"
echo "  Emperor:   http://localhost:${HTTP_PORTS[1]}  (dashboard, direct)"
echo ""
echo "LLM: ${VOIDLUX_LLM_PROVIDER:-ollama}/${VOIDLUX_LLM_MODEL:-qwen3-coder:30b}"
[ -n "${VOIDLUX_AUTH_SECRET:-}" ] && echo "Auth: HMAC-SHA256 (secret configured)"
[ -n "${VOIDLUX_TEST_COMMAND:-}" ] && echo "Test: ${VOIDLUX_TEST_COMMAND}"
echo ""
echo "Quick start:"
echo "  # Register agents on emperor:"
echo "  bash scripts/register-agents.sh 3 claude /tmp/test ${HTTP_PORTS[1]}"
echo ""
echo "  # Create a task (via Seneschal):"
echo "  curl -s -X POST http://localhost:${HTTP_PORTS[0]}/api/swarm/tasks \\"
echo "    -H 'Content-Type: application/json' \\"
echo "    -d '{\"title\":\"Add hello world endpoint\",\"project_path\":\"/tmp/test\"}'"
echo ""
echo "  # Restart:"
echo "  bash scripts/enter-the-void.sh"
echo ""
