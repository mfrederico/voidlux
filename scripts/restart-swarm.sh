#!/usr/bin/env bash
#
# Restart the VoidLux swarm cleanly.
# Kills all agent sessions + orphan processes, tears down the swarm tmux session,
# then relaunches via demo-swarm.sh.
#
# Usage: bash scripts/restart-swarm.sh
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
SESSION="voidlux-swarm"

echo "=== Restarting VoidLux Swarm ==="

# ── 1. Kill agent tmux sessions and their processes ──────────────────
echo "[1/4] Killing agent sessions..."
AGENT_COUNT=0
for s in $(tmux list-sessions -F '#{session_name}' 2>/dev/null | grep '^vl-'); do
    # Capture PIDs before killing
    PANE_PID=$(tmux list-panes -t "$s" -F '#{pane_pid}' 2>/dev/null || true)
    CHILD_PIDS=""
    if [ -n "$PANE_PID" ]; then
        CHILD_PIDS=$(pgrep -P "$PANE_PID" 2>/dev/null || true)
        for cpid in $CHILD_PIDS; do
            CHILD_PIDS="$CHILD_PIDS $(pgrep -P "$cpid" 2>/dev/null || true)"
        done
    fi

    # Kill tmux session
    tmux send-keys -t "$s" C-c 2>/dev/null || true
    sleep 0.2
    tmux kill-session -t "$s" 2>/dev/null || true

    # Kill orphaned processes
    for pid in $CHILD_PIDS; do
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            kill "$pid" 2>/dev/null || true
        fi
    done
    AGENT_COUNT=$((AGENT_COUNT + 1))
done
echo "     Killed $AGENT_COUNT agent session(s)"

# ── 2. Kill swarm tmux session ───────────────────────────────────────
echo "[2/4] Killing swarm session..."
if tmux has-session -t "$SESSION" 2>/dev/null; then
    # Get PIDs of swarm processes (PHP workers)
    SWARM_PIDS=""
    for pane_id in $(tmux list-panes -t "$SESSION" -F '#{pane_pid}' 2>/dev/null); do
        SWARM_PIDS="$SWARM_PIDS $(pgrep -P "$pane_id" 2>/dev/null || true)"
    done
    tmux kill-session -t "$SESSION" 2>/dev/null || true

    # Kill orphaned swarm PHP processes
    sleep 0.5
    for pid in $SWARM_PIDS; do
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            kill "$pid" 2>/dev/null || true
        fi
    done
    echo "     Session killed"
else
    echo "     No session running"
fi

# ── 3. Final cleanup: kill any straggler swarm processes ─────────────
echo "[3/4] Cleaning up stragglers..."
STRAGGLERS=$(pgrep -f "voidlux swarm|voidlux seneschal" 2>/dev/null || true)
if [ -n "$STRAGGLERS" ]; then
    for pid in $STRAGGLERS; do
        kill "$pid" 2>/dev/null || true
    done
    sleep 0.5
    # SIGKILL any that survived
    for pid in $STRAGGLERS; do
        if kill -0 "$pid" 2>/dev/null; then
            kill -9 "$pid" 2>/dev/null || true
        fi
    done
    echo "     Cleaned $(echo "$STRAGGLERS" | wc -w) straggler(s)"
else
    echo "     None found"
fi

# ── 4. Relaunch ──────────────────────────────────────────────────────
echo "[4/4] Launching swarm..."
bash "$SCRIPT_DIR/demo-swarm.sh"
