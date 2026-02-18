#!/usr/bin/env bash
#
# Start the VoidLux swarm with PHP lint as the merge-test command.
# Agents are Claude Code instances in tmux.
# Emperor uses Ollama for task planning/decomposition.
#

set -euo pipefail

VOIDLUX_TEST_COMMAND='find src -name "*.php" -exec php -l {} +' \
  bash "$(dirname "$0")/../scripts/demo-swarm.sh"
