#!/usr/bin/env bash
#
# Hot-restart the emperor and seneschal processes inside the running container.
# No Docker rebuild needed — source is volume-mounted.
#
# Usage: scripts/hot-restart.sh [container_name]
#
# This kills the PHP processes and lets the entrypoint's trap handler restart them.
# Since the entrypoint uses wait-n, killing one process triggers cleanup of both.
#

CONTAINER="${1:-voidlux-voidlux-1}"

echo "[hot-restart] Restarting processes in $CONTAINER..."

# Kill the emperor process — entrypoint cleanup() will kill seneschal too
docker exec "$CONTAINER" bash -c 'kill $(pgrep -f "bin/voidlux swarm") 2>/dev/null; sleep 1'

# The container will exit and docker compose will restart it (if restart policy is set)
# For now, just restart the container which re-runs the entrypoint with mounted source
docker restart "$CONTAINER"

echo "[hot-restart] Container restarted. Agents preserved in tmux sessions."
echo "[hot-restart] Check: docker compose logs -f --tail=10"
