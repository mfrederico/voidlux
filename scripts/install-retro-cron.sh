#!/usr/bin/env bash
# Installs the forge's schedule in the current user's crontab: the sweep every minute (starts waiting notes and queued
# jobs that nothing is carrying), the retrospective at :05 every hour, the week on Sunday 09:10.
# Safe to run again: any earlier forge lines are replaced, everything else in the crontab is kept.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
HOURLY="5 * * * * cd $ROOT && /usr/bin/php scripts/forge-retro.php >> log/retro.log 2>&1"
WEEKLY="10 9 * * 0 cd $ROOT && /usr/bin/php scripts/forge-retro.php --weekly >> log/retro.log 2>&1"
SWEEP="* * * * * cd $ROOT && /usr/bin/php scripts/forge-sweep.php >> log/sweep.log 2>&1"
{ crontab -l 2>/dev/null | grep -v -e 'forge-retro.php' -e 'forge-sweep.php' || true; echo "$SWEEP"; echo "$HOURLY"; echo "$WEEKLY"; } | crontab -
echo "installed:"; crontab -l | grep -e forge-retro.php -e forge-sweep.php
