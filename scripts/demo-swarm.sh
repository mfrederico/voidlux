#!/usr/bin/env bash
#
# Launch a VoidLux swarm: Seneschal + 1 emperor + 2 workers.
# Two tmux sessions:
#   voidlux-seneschal — Long-lived stable proxy (survives cycles)
#   voidlux-swarm     — Emperor + 2 Workers (cyclable)
#
# This is an alias for enter-the-void.sh.
#

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
exec bash "$SCRIPT_DIR/enter-the-void.sh" "$@"
