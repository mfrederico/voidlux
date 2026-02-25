#!/bin/bash
# Bulk register agents with the VoidLux swarm.
#
# Usage: register-agents.sh [count] [tool] [project_path] [port] [model]
#
# Examples:
#   register-agents.sh 3 claude /tmp/test 9092
#   register-agents.sh 3 claude /tmp/test 9092 claude-sonnet-4-5-20250929
#   OLLAMA=1 register-agents.sh 3 claude /tmp/test 9092 qwen3:32b
#   ROLE=planner register-agents.sh 1 claude /tmp/test 9092
#   PERSONA=marcus register-agents.sh 1 claude /tmp/test 9091
#   PERSONAS=all register-agents.sh 0 claude /tmp/test 9091  # Register all personas

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

# Register all personas mode
if [ "$PERSONAS" = "all" ]; then
    echo "Fetching persona definitions..."
    PERSONA_DATA=$(curl -s "http://localhost:$PORT/api/swarm/forum/personas")
    SLUGS=$(echo "$PERSONA_DATA" | python3 -c "import sys,json; [print(p['slug']) for p in json.load(sys.stdin).get('personas',[])]" 2>/dev/null)

    if [ -z "$SLUGS" ]; then
        echo "No personas found. Add personas at /settings first."
        exit 1
    fi

    AGENTS_JSON="["
    FIRST=1
    for SLUG in $SLUGS; do
        if [ $FIRST -eq 0 ]; then AGENTS_JSON="$AGENTS_JSON,"; fi
        FIRST=0
        AGENT="{\"persona_slug\":\"$SLUG\",\"tool\":\"$TOOL\",\"project_path\":\"$PROJECT\""
        if [ -n "$MODEL" ]; then AGENT="$AGENT,\"model\":\"$MODEL\""; fi
        if [ -n "$OLLAMA" ]; then AGENT="$AGENT,\"env\":{\"ANTHROPIC_AUTH_TOKEN\":\"ollama\",\"ANTHROPIC_BASE_URL\":\"http://localhost:11434\"}"; fi
        AGENT="$AGENT}"
        AGENTS_JSON="$AGENTS_JSON$AGENT"
    done
    AGENTS_JSON="$AGENTS_JSON]"

    curl -s -X POST "http://localhost:$PORT/api/swarm/agents/bulk" \
      -H 'Content-Type: application/json' \
      -d "{\"agents\":$AGENTS_JSON}"
    echo ""
    exit 0
fi

# Single persona mode
if [ -n "$PERSONA" ]; then
    JSON="{\"agents\":[{\"persona_slug\":\"$PERSONA\",\"tool\":\"$TOOL\",\"project_path\":\"$PROJECT\""
    if [ -n "$MODEL" ]; then JSON="$JSON,\"model\":\"$MODEL\""; fi
    if [ -n "$OLLAMA" ]; then JSON="$JSON,\"env\":{\"ANTHROPIC_AUTH_TOKEN\":\"ollama\",\"ANTHROPIC_BASE_URL\":\"http://localhost:11434\"}"; fi
    JSON="$JSON}]}"

    curl -s -X POST "http://localhost:$PORT/api/swarm/agents/bulk" \
      -H 'Content-Type: application/json' \
      -d "$JSON"
    echo ""
    exit 0
fi

# Build JSON payload (original batch mode)
ROLE="${ROLE:-}"
NAME_PREFIX="agent"
if [ "$ROLE" = "planner" ]; then
    NAME_PREFIX="planner"
fi

JSON="{\"count\":$COUNT,\"tool\":\"$TOOL\",\"capabilities\":[],\"project_path\":\"$PROJECT\",\"name_prefix\":\"$NAME_PREFIX\""

if [ -n "$ROLE" ]; then
    JSON="$JSON,\"role\":\"$ROLE\""
fi

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
