#!/usr/bin/env bash
#
# Restart the VoidLux swarm cleanly.
# Kills all agent sessions + orphan processes, tears down the swarm tmux session,
# then relaunches via launch-swarm-session.sh.
#
# By default, preserves the seneschal session. Use --full to restart everything.
#
# Usage:
#   bash scripts/restart-swarm.sh          # Restart swarm only (seneschal stays)
#   bash scripts/restart-swarm.sh --full   # Restart everything including seneschal
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

SENESCHAL_SESSION="voidlux-seneschal"
SWARM_SESSION="voidlux-swarm"

FULL_RESTART=false
if [[ "${1:-}" == "--full" ]]; then
    FULL_RESTART=true
fi

echo "=== Restarting VoidLux Swarm ==="
$FULL_RESTART && echo "(full restart — including seneschal)"

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
if tmux has-session -t "$SWARM_SESSION" 2>/dev/null; then
    # Get PIDs of swarm processes (PHP workers)
    SWARM_PIDS=""
    for pane_id in $(tmux list-panes -t "$SWARM_SESSION" -F '#{pane_pid}' 2>/dev/null); do
        SWARM_PIDS="$SWARM_PIDS $(pgrep -P "$pane_id" 2>/dev/null || true)"
    done
    tmux kill-session -t "$SWARM_SESSION" 2>/dev/null || true

    # Kill orphaned swarm PHP processes
    sleep 0.5
    for pid in $SWARM_PIDS; do
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            kill "$pid" 2>/dev/null || true
        fi
    done
    echo "     Swarm session killed"
else
    echo "     No swarm session running"
fi

# Kill seneschal if --full
if $FULL_RESTART; then
    echo "     Killing seneschal session..."
    if tmux has-session -t "$SENESCHAL_SESSION" 2>/dev/null; then
        SENESCHAL_PIDS=""
        for pane_id in $(tmux list-panes -t "$SENESCHAL_SESSION" -F '#{pane_pid}' 2>/dev/null); do
            SENESCHAL_PIDS="$SENESCHAL_PIDS $(pgrep -P "$pane_id" 2>/dev/null || true)"
        done
        tmux kill-session -t "$SENESCHAL_SESSION" 2>/dev/null || true
        sleep 0.5
        for pid in $SENESCHAL_PIDS; do
            if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
                kill "$pid" 2>/dev/null || true
            fi
        done
        echo "     Seneschal session killed"
    else
        echo "     No seneschal session running"
    fi
fi

# ── 3. Final cleanup: kill any straggler swarm processes ─────────────
echo "[3/4] Cleaning up stragglers..."
if $FULL_RESTART; then
    STRAGGLERS=$(pgrep -f "voidlux swarm|voidlux seneschal" 2>/dev/null || true)
else
    # Only kill swarm processes, not seneschal
    STRAGGLERS=$(pgrep -f "voidlux swarm" 2>/dev/null || true)
fi
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
echo "[4/4] Launching..."
if $FULL_RESTART; then
    bash "$SCRIPT_DIR/enter-the-void.sh"
else
    bash "$SCRIPT_DIR/launch-swarm-session.sh"
fi
