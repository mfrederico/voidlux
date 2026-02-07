#!/usr/bin/env bash
#
# Launch 5 VoidLux graffiti wall instances in tmux panes.
# Each instance gets unique HTTP, P2P, and discovery ports.
# All instances seed-peer to each other for instant mesh formation.
#

set -euo pipefail

SESSION="voidlux-demo"
PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DATA_DIR="${PROJECT_DIR}/data"

# Port assignments
HTTP_PORTS=(8081 8082 8083 8084 8085)
P2P_PORTS=(7001 7002 7003 7004 7005)
DISC_PORTS=(6001 6002 6003 6004 6005)

# Clean up old data (optional)
rm -rf "${DATA_DIR}"
mkdir -p "${DATA_DIR}"

# Build seed peer list: all nodes know about all others
build_seeds() {
    local exclude_port=$1
    local seeds=""
    for port in "${P2P_PORTS[@]}"; do
        if [ "$port" != "$exclude_port" ]; then
            [ -n "$seeds" ] && seeds="${seeds},"
            seeds="${seeds}127.0.0.1:${port}"
        fi
    done
    echo "$seeds"
}

# Kill existing session if running
tmux kill-session -t "$SESSION" 2>/dev/null || true

# Create new tmux session
tmux new-session -d -s "$SESSION" -x 200 -y 50

for i in $(seq 0 4); do
    HTTP_PORT=${HTTP_PORTS[$i]}
    P2P_PORT=${P2P_PORTS[$i]}
    DISC_PORT=${DISC_PORTS[$i]}
    SEEDS=$(build_seeds "$P2P_PORT")

    CMD="cd ${PROJECT_DIR} && php bin/voidlux demo"
    CMD="${CMD} --http-port=${HTTP_PORT}"
    CMD="${CMD} --p2p-port=${P2P_PORT}"
    CMD="${CMD} --discovery-port=${DISC_PORT}"
    CMD="${CMD} --seeds=${SEEDS}"
    CMD="${CMD} --data-dir=${DATA_DIR}"

    if [ "$i" -eq 0 ]; then
        tmux send-keys -t "$SESSION" "$CMD" C-m
    else
        tmux split-window -t "$SESSION" -v
        tmux send-keys -t "$SESSION" "$CMD" C-m
    fi

    # Tile the layout after each pane
    tmux select-layout -t "$SESSION" tiled
done

echo ""
echo "=== VoidLux Demo ==="
echo "5 nodes launched in tmux session: $SESSION"
echo ""
echo "Web UIs:"
for i in $(seq 0 4); do
    echo "  Node $((i+1)): http://localhost:${HTTP_PORTS[$i]}"
done
echo ""
echo "Attach: tmux attach -t $SESSION"
echo "Kill:   tmux kill-session -t $SESSION"
echo ""
