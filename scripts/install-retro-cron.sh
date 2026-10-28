#!/usr/bin/env bash
# Installs the forge's retrospective schedule in the current user's crontab: the hour at :05, the week on Sunday 09:10.
# Safe to run again: any earlier forge-retro lines are replaced, everything else in the crontab is kept.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
HOURLY="5 * * * * cd $ROOT && /usr/bin/php scripts/forge-retro.php >> log/retro.log 2>&1"
WEEKLY="10 9 * * 0 cd $ROOT && /usr/bin/php scripts/forge-retro.php --weekly >> log/retro.log 2>&1"
{ crontab -l 2>/dev/null | grep -v 'forge-retro.php' || true; echo "$HOURLY"; echo "$WEEKLY"; } | crontab -
echo "installed:"; crontab -l | grep forge-retro.php
