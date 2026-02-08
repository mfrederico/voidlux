#!/bin/bash
# Submit a task to the VoidLux swarm.
#
# Usage: submit-task.sh <title> [project_path] [port] [description]
#
# Examples:
#   submit-task.sh "Add hello world endpoint"
#   submit-task.sh "Add hello world endpoint" /tmp/test
#   submit-task.sh "Add hello world endpoint" /tmp/test 9090
#   submit-task.sh "WebGL graffiti wall" /tmp/test 9090 "Full description here..."
#
# If description is omitted, title is used as the description.
# If project_path is omitted, defaults to workbench/.
# Reads from stdin if piped (useful for long descriptions):
#   echo "Long description..." | submit-task.sh "Task title" /tmp/test

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

TITLE=${1:-}
PROJECT=${2:-$PROJECT_ROOT/workbench}
PORT=${3:-9090}
DESCRIPTION=${4:-}

if [ -z "$TITLE" ]; then
    echo "Usage: submit-task.sh <title> [project_path] [port] [description]"
    echo ""
    echo "  title          Task title (required)"
    echo "  project_path   Working directory for agents (default: workbench/)"
    echo "  port           Swarm HTTP port (default: 9090)"
    echo "  description    Task description (default: same as title)"
    echo ""
    echo "Pipe stdin for long descriptions:"
    echo "  echo 'Long desc...' | submit-task.sh 'Title' /tmp/test"
    exit 1
fi

# Read description from stdin if piped and no arg given
if [ -z "$DESCRIPTION" ] && [ ! -t 0 ]; then
    DESCRIPTION=$(cat)
fi

# Fall back to title if still empty
if [ -z "$DESCRIPTION" ]; then
    DESCRIPTION="$TITLE"
fi

# Ensure workbench exists when using default
if [ "$PROJECT" = "$PROJECT_ROOT/workbench" ]; then
    mkdir -p "$PROJECT"
fi

# Use jq for safe JSON encoding (handles newlines, quotes, emojis)
if command -v jq &>/dev/null; then
    JSON=$(jq -n \
        --arg title "$TITLE" \
        --arg desc "$DESCRIPTION" \
        --arg path "$PROJECT" \
        '{title: $title, description: $desc, project_path: $path}')
else
    # Fallback: manual escaping (less safe)
    ESC_TITLE=$(printf '%s' "$TITLE" | sed 's/\\/\\\\/g; s/"/\\"/g')
    ESC_DESC=$(printf '%s' "$DESCRIPTION" | sed 's/\\/\\\\/g; s/"/\\"/g; s/\n/\\n/g')
    ESC_PATH=$(printf '%s' "$PROJECT" | sed 's/\\/\\\\/g; s/"/\\"/g')
    JSON="{\"title\":\"$ESC_TITLE\",\"description\":\"$ESC_DESC\",\"project_path\":\"$ESC_PATH\"}"
fi

RESULT=$(curl -s -X POST "http://localhost:$PORT/api/swarm/tasks" \
    -H 'Content-Type: application/json' \
    -d "$JSON")

if command -v jq &>/dev/null; then
    echo "$RESULT" | jq '{id: .id, title: .title, status: .status}'
else
    echo "$RESULT"
fi
