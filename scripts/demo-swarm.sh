#!/usr/bin/env bash
#
# Launch a VoidLux swarm: 1 emperor + 2 workers in tmux panes.
# Emperor gets the dashboard; workers auto-discover via UDP.
#

set -euo pipefail

SESSION="voidlux-swarm"
PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DATA_DIR="${PROJECT_DIR}/data"

# Port assignments:            Emperor   Worker1   Worker2
HTTP_PORTS=(9090 9091 9092)
P2P_PORTS=(7101 7102 7103)
DISC_PORTS=(6101 6101 6101)   # Same discovery port for UDP mesh

ROLES=(emperor worker worker)

# Clean old swarm data
rm -rf "${DATA_DIR}"/swarm-*
mkdir -p "${DATA_DIR}"

# Build seed list: all nodes seed to emperor's P2P port
EMPEROR_SEED="127.0.0.1:${P2P_PORTS[0]}"

# Kill existing session
tmux kill-session -t "$SESSION" 2>/dev/null || true

# Create tmux session
tmux new-session -d -s "$SESSION" -x 200 -y 50

for i in 0 1 2; do
    HTTP_PORT=${HTTP_PORTS[$i]}
    P2P_PORT=${P2P_PORTS[$i]}
    DISC_PORT=${DISC_PORTS[$i]}
    ROLE=${ROLES[$i]}

    # Workers seed to emperor; emperor has no seeds (discovers via UDP)
    if [ "$i" -eq 0 ]; then
        SEEDS=""
    else
        SEEDS="--seeds=${EMPEROR_SEED}"
    fi

    CMD="cd ${PROJECT_DIR} && php bin/voidlux swarm"
    CMD="${CMD} --http-port=${HTTP_PORT}"
    CMD="${CMD} --p2p-port=${P2P_PORT}"
    CMD="${CMD} --discovery-port=${DISC_PORT}"
    CMD="${CMD} --role=${ROLE}"
    CMD="${CMD} --data-dir=${DATA_DIR}"
    [ -n "$SEEDS" ] && CMD="${CMD} ${SEEDS}"

    if [ "$i" -eq 0 ]; then
        tmux send-keys -t "$SESSION" "$CMD" C-m
    else
        tmux split-window -t "$SESSION" -v
        tmux send-keys -t "$SESSION" "$CMD" C-m
    fi

    tmux select-layout -t "$SESSION" tiled
done

echo ""
echo "=== VoidLux Swarm ==="
echo "3 nodes launched in tmux session: $SESSION"
echo ""
echo "  Emperor:  http://localhost:${HTTP_PORTS[0]}  (dashboard)"
echo "  Worker 1: http://localhost:${HTTP_PORTS[1]}"
echo "  Worker 2: http://localhost:${HTTP_PORTS[2]}"
echo ""
echo "Quick start:"
echo "  # Register an agent on worker 1:"
echo "  curl -s -X POST http://localhost:${HTTP_PORTS[1]}/api/swarm/agents \\"
echo "    -H 'Content-Type: application/json' \\"
echo "    -d '{\"name\":\"claude-1\",\"tool\":\"claude\",\"capabilities\":[\"php\"],\"project_path\":\"/tmp/test\"}'"
echo ""
echo "  # Create a task from emperor:"
echo "  curl -s -X POST http://localhost:${HTTP_PORTS[0]}/api/swarm/tasks \\"
echo "    -H 'Content-Type: application/json' \\"
echo "    -d '{\"title\":\"Test task\",\"description\":\"Echo hello world\"}'"
echo ""
echo "Attach: tmux attach -t $SESSION"
echo "Kill:   tmux kill-session -t $SESSION"
echo ""
