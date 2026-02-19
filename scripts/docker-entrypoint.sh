#!/usr/bin/env bash
set -euo pipefail

# VoidLux Docker Entrypoint
#
# Usage:
#   docker run voidlux swarm --role=emperor --http-port=9091
#   docker run voidlux seneschal --http-port=9090 --seeds=emperor:7101
#   docker run voidlux <any voidlux CLI command>
#
# Environment variables:
#   VOIDLUX_ROLE       - Default role (emperor|worker|seneschal)
#   VOIDLUX_HTTP_PORT  - HTTP port override
#   VOIDLUX_P2P_PORT   - P2P port override
#   VOIDLUX_SEEDS      - Comma-separated seed peers
#   VOIDLUX_DATA_DIR   - Data directory (default: /app/data)

# Ensure data and workbench dirs exist
mkdir -p "${VOIDLUX_DATA_DIR:-/app/data}" /app/workbench

# If no arguments, default to swarm emperor
if [ $# -eq 0 ]; then
    set -- swarm --role="${VOIDLUX_ROLE:-emperor}" \
        --http-port="${VOIDLUX_HTTP_PORT:-9091}" \
        --p2p-port="${VOIDLUX_P2P_PORT:-7101}" \
        --data-dir="${VOIDLUX_DATA_DIR:-/app/data}"

    if [ -n "${VOIDLUX_SEEDS:-}" ]; then
        set -- "$@" "--seeds=${VOIDLUX_SEEDS}"
    fi
fi

exec php /app/bin/voidlux "$@"
