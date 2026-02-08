#!/usr/bin/env bash
#
# Launch a VoidLux swarm: Seneschal + 1 emperor + 2 workers in tmux panes.
# Seneschal is a stable reverse proxy that tracks the emperor.
# Emperor gets the dashboard; workers auto-discover via UDP.
#

set -euo pipefail

SESSION="voidlux-swarm"
PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DATA_DIR="${PROJECT_DIR}/data"

# Port assignments:            Seneschal  Emperor  Worker1  Worker2
HTTP_PORTS=(9090 9091 9092 9093)
P2P_PORTS=(7100 7101 7102 7103)
DISC_PORTS=(6101 6101 6101 6101)   # Same discovery port for UDP mesh

# Clean old swarm data
rm -rf "${DATA_DIR}"/swarm-*
mkdir -p "${DATA_DIR}"

# Emperor's P2P port for seeding
EMPEROR_P2P="${P2P_PORTS[1]}"

# Kill existing session and orphan agent sessions
tmux kill-session -t "$SESSION" 2>/dev/null || true
for s in $(tmux list-sessions -F '#{session_name}' 2>/dev/null | grep '^vl-'); do
    tmux kill-session -t "$s" 2>/dev/null || true
done

# Create tmux session
tmux new-session -d -s "$SESSION" -x 200 -y 50

# ── Pane 0: Seneschal (reverse proxy) ──────────────────────────────
CMD="cd ${PROJECT_DIR} && php bin/voidlux seneschal"
CMD="${CMD} --http-port=${HTTP_PORTS[0]}"
CMD="${CMD} --p2p-port=${P2P_PORTS[0]}"
CMD="${CMD} --discovery-port=${DISC_PORTS[0]}"
CMD="${CMD} --seeds=127.0.0.1:${EMPEROR_P2P}"
tmux send-keys -t "$SESSION" "$CMD" C-m

# ── Panes 1-3: Emperor + Workers ───────────────────────────────────
ROLES=(emperor worker worker)

for i in 0 1 2; do
    IDX=$((i + 1))
    HTTP_PORT=${HTTP_PORTS[$IDX]}
    P2P_PORT=${P2P_PORTS[$IDX]}
    DISC_PORT=${DISC_PORTS[$IDX]}
    ROLE=${ROLES[$i]}

    # Workers seed to emperor; emperor has no seeds (discovers via UDP)
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

    tmux split-window -t "$SESSION" -v
    tmux send-keys -t "$SESSION" "$CMD" C-m
    tmux select-layout -t "$SESSION" tiled
done

echo ""
echo "=== VoidLux Swarm ==="
echo "4 processes launched in tmux session: $SESSION"
echo ""
echo "  Seneschal: http://localhost:${HTTP_PORTS[0]}  (stable proxy — use this)"
echo "  Emperor:   http://localhost:${HTTP_PORTS[1]}  (dashboard, direct)"
echo "  Worker 1:  http://localhost:${HTTP_PORTS[2]}"
echo "  Worker 2:  http://localhost:${HTTP_PORTS[3]}"
echo ""
echo "LLM: ${VOIDLUX_LLM_PROVIDER:-ollama}/${VOIDLUX_LLM_MODEL:-qwen3-coder:30b}"
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
echo "  VOIDLUX_LLM_MODEL=llama3.1:8b bash scripts/demo-swarm.sh"
echo ""
echo "Attach: tmux attach -t $SESSION"
echo "Kill:   tmux kill-session -t $SESSION"
echo ""
