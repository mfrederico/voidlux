#!/bin/bash
# Bulk register agents with the VoidLux swarm emperor.
# Usage: register-agents.sh [count] [tool] [project_path] [port]

COUNT=${1:-5}
TOOL=${2:-claude}
PROJECT=${3:-$(pwd)}
PORT=${4:-9090}

curl -s -X POST "http://localhost:$PORT/api/swarm/agents/bulk" \
  -H 'Content-Type: application/json' \
  -d "{\"count\":$COUNT,\"tool\":\"$TOOL\",\"capabilities\":[],\"project_path\":\"$PROJECT\",\"name_prefix\":\"agent\"}"

echo ""
