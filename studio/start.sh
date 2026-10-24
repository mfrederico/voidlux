#!/usr/bin/env bash
# Start (or restart) the VoidLux Studio server in the background, logging to studio/studio.log. usage: studio/start.sh [port]
cd "$(dirname "$0")" || exit 1
PORT=${1:-$(python3 -c "import json; print(json.load(open('config.json')).get('port', 8790))")}
pkill -f "^python3 server.py( $PORT)?$" 2>/dev/null; sleep 0.3
nohup python3 server.py "$PORT" >> studio.log 2>&1 &
sleep 0.8; curl -sf "http://127.0.0.1:$PORT/api/status" >/dev/null && echo "VoidLux Studio: http://localhost:$PORT/studio/" || { echo "did not start; see studio/studio.log"; tail -5 studio.log; exit 1; }
