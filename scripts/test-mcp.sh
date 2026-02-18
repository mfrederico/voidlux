#!/usr/bin/env bash
#
# Test MCP (JSON-RPC 2.0) endpoint through Seneschal proxy.
# Assumes demo-swarm.sh is running (Seneschal on :9090, Emperor on :9091).
#
# Usage: bash scripts/test-mcp.sh [seneschal_port]
#

set -uo pipefail

PORT="${1:-9090}"
BASE="http://localhost:${PORT}"
PASS=0
FAIL=0

mcp() {
    curl -s -X POST "${BASE}/mcp" -H 'Content-Type: application/json' -d "$1"
}

check() {
    local label="$1" expected="$2" actual="$3"
    if echo "$actual" | grep -q "$expected"; then
        echo "  PASS: $label"
        PASS=$((PASS + 1))
    else
        echo "  FAIL: $label (expected '$expected', got '$actual')"
        FAIL=$((FAIL + 1))
    fi
}

echo "=== MCP Endpoint Tests (via ${BASE}) ==="
echo ""

# 1. Health check
echo "[1] Health check"
HEALTH=$(curl -s "${BASE}/health")
check "health responds" '"status": "ok"' "$HEALTH"

# 2. MCP initialize
echo "[2] MCP initialize"
INIT=$(mcp '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}')
check "protocolVersion" '"protocolVersion"' "$INIT"
check "serverInfo name" '"voidlux-swarm"' "$INIT"
check "capabilities.tools is object" '"tools":{}' "$(echo "$INIT" | tr -d ' \n')"

# 3. MCP tools/list
echo "[3] MCP tools/list"
TOOLS=$(mcp '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}')
check "has task_complete" 'task_complete' "$TOOLS"
check "has task_progress" 'task_progress' "$TOOLS"
check "has task_failed" 'task_failed' "$TOOLS"
check "has task_needs_input" 'task_needs_input' "$TOOLS"

# 4. Create a task
echo "[4] Create test task"
TASK=$(curl -s -X POST "${BASE}/api/swarm/tasks" \
    -H 'Content-Type: application/json' \
    -d '{"title":"MCP test","description":"Automated MCP test"}')
TASK_ID=$(echo "$TASK" | jq -r '.id')
check "task created" 'pending' "$TASK"
echo "     Task ID: ${TASK_ID}"

# 5. task_progress
echo "[5] task_progress"
PROG=$(mcp '{"jsonrpc":"2.0","id":10,"method":"tools/call","params":{"name":"task_progress","arguments":{"task_id":"'"$TASK_ID"'","message":"Step 1 done"}}}')
check "progress updated" 'progress_updated' "$PROG"
STATUS=$(curl -s "${BASE}/api/swarm/tasks/${TASK_ID}" | jq -r '.status')
check "task status in_progress" "in_progress" "$STATUS"

# 6. task_needs_input
echo "[6] task_needs_input"
INPUT=$(mcp '{"jsonrpc":"2.0","id":11,"method":"tools/call","params":{"name":"task_needs_input","arguments":{"task_id":"'"$TASK_ID"'","question":"Which database to use"}}}')
check "waiting_input set" 'waiting_input' "$INPUT"
TSTATUS=$(curl -s "${BASE}/api/swarm/tasks/${TASK_ID}")
check "task status waiting_input" 'waiting_input' "$(echo "$TSTATUS" | jq -r '.status')"
check "question in progress" 'Which database to use' "$(echo "$TSTATUS" | jq -r '.progress')"

# 7. waiting_input in swarm status
echo "[7] Swarm status includes waiting_input"
SWARM=$(curl -s "${BASE}/api/swarm/status")
check "waiting_input count present" 'waiting_input' "$SWARM"

# 8. task_complete
echo "[8] task_complete"
COMP=$(mcp '{"jsonrpc":"2.0","id":12,"method":"tools/call","params":{"name":"task_complete","arguments":{"task_id":"'"$TASK_ID"'","summary":"Used SQLite as recommended"}}}')
check "completed" 'completed' "$COMP"
RESULT=$(curl -s "${BASE}/api/swarm/tasks/${TASK_ID}" | jq -r '.result')
check "result stored" 'Used SQLite' "$RESULT"

# 9. Reject double-complete
echo "[9] Reject terminal state"
REJECT=$(mcp '{"jsonrpc":"2.0","id":13,"method":"tools/call","params":{"name":"task_complete","arguments":{"task_id":"'"$TASK_ID"'","summary":"oops"}}}')
check "isError true" 'isError' "$REJECT"
check "terminal state msg" 'terminal state' "$REJECT"

# 10. task_failed
echo "[10] task_failed"
TASK2=$(curl -s -X POST "${BASE}/api/swarm/tasks" \
    -H 'Content-Type: application/json' \
    -d '{"title":"Fail test","description":"Should fail"}')
TASK2_ID=$(echo "$TASK2" | jq -r '.id')
FAILED=$(mcp '{"jsonrpc":"2.0","id":14,"method":"tools/call","params":{"name":"task_failed","arguments":{"task_id":"'"$TASK2_ID"'","error":"Missing dependency"}}}')
check "task failed" 'failed' "$FAILED"
ERR=$(curl -s "${BASE}/api/swarm/tasks/${TASK2_ID}" | jq -r '.error')
check "error stored" 'Missing dependency' "$ERR"

# 11. Invalid method
echo "[11] Invalid method"
NOTFOUND=$(mcp '{"jsonrpc":"2.0","id":99,"method":"bogus/method","params":{}}')
check "method not found" '32601' "$NOTFOUND"

# 12. Missing params
echo "[12] Missing required params"
MISSING=$(mcp '{"jsonrpc":"2.0","id":100,"method":"tools/call","params":{"name":"task_complete","arguments":{"task_id":"","summary":""}}}')
check "param validation" 'isError' "$MISSING"

echo ""
echo "=== Results: ${PASS} passed, ${FAIL} failed ==="
exit $FAIL
