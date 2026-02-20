#!/bin/bash
# Bulk register agents with the VoidLux swarm.
#
# Usage: register-agents.sh [count] [tool] [project_path] [port] [model]
#
# Examples:
#   register-agents.sh 3 claude /tmp/test 9092
#   register-agents.sh 3 claude /tmp/test 9092 claude-sonnet-4-5-20250929
#   OLLAMA=1 register-agents.sh 3 claude /tmp/test 9092 qwen3:32b

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

COUNT=${1:-5}
TOOL=${2:-claude}
PROJECT=${3:-$PROJECT_ROOT/workbench}
PORT=${4:-9091}
MODEL=${5:-}

# Ensure workbench exists when using default
if [ "$PROJECT" = "$PROJECT_ROOT/workbench" ]; then
    mkdir -p "$PROJECT"
fi

# Build JSON payload
JSON="{\"count\":$COUNT,\"tool\":\"$TOOL\",\"capabilities\":[],\"project_path\":\"$PROJECT\",\"name_prefix\":\"agent\""

if [ -n "$MODEL" ]; then
    JSON="$JSON,\"model\":\"$MODEL\""
fi

if [ -n "$OLLAMA" ]; then
    JSON="$JSON,\"env\":{\"ANTHROPIC_AUTH_TOKEN\":\"ollama\",\"ANTHROPIC_BASE_URL\":\"http://localhost:11434\"}"
fi

JSON="$JSON}"

curl -s -X POST "http://localhost:$PORT/api/swarm/agents/bulk" \
  -H 'Content-Type: application/json' \
  -d "$JSON"

echo ""
