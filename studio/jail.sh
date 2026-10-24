#!/usr/bin/env bash
# Run a command confined to one game directory with bubblewrap. The agent inside sees the OS read-only, the kit and the
# studio's client scripts read-only, its own game read-write, and the account's Claude login (so the user's own account pays).
# It cannot see /var/www, other games, or anything else under $HOME. Network is shared (the agent calls the studio's
# API for soaks and the model). usage: jail.sh <game dir> <command...>
# No --proc or --unshare-pid: this host forbids a fresh proc mount in an unprivileged namespace, so the host's /proc is
# bound read-only instead (tested: claude, node and git run; bun crashes without a /proc at all).
set -euo pipefail
GAME=$(readlink -f "$1"); shift
HERE=$(cd "$(dirname "$0")" && pwd); ROOT=$(readlink -f "$HERE/..")
H=${HOME:-/home/$(id -un)}
NODE_BIN=$(dirname "$(readlink -f "$(command -v node)")")
CLAUDE_BIN=$(dirname "$(readlink -f "$(command -v claude)")")
args=(--ro-bind /usr /usr --ro-bind /etc /etc --ro-bind /proc /proc --dev-bind /dev /dev --tmpfs /tmp --tmpfs "$H")
for d in /lib /lib64 /bin /sbin; do [ -e "$d" ] && args+=(--ro-bind "$d" "$d"); done
args+=(--ro-bind "$ROOT/kit" "$ROOT/kit" --ro-bind "$HERE/soak.mjs" "$HERE/soak.mjs" --ro-bind "$HERE/bin" "$HERE/bin")
[ -d "$ROOT/centipede" ] && args+=(--ro-bind "$ROOT/centipede" "$ROOT/centipede")   # the reference game, for reading
args+=(--bind "$GAME" "$GAME")
# the claude CLI and node, wherever they are installed (nvm, ~/.local), read-only
args+=(--ro-bind "$NODE_BIN" "$NODE_BIN" --ro-bind "$CLAUDE_BIN" "$CLAUDE_BIN")
[ -e "$H/.local/bin" ] && args+=(--ro-bind "$H/.local/bin" "$H/.local/bin")
# the account's Claude login and session store, read-write (sessions are kept per game dir under ~/.claude/projects)
[ -d "$H/.claude" ] && args+=(--bind "$H/.claude" "$H/.claude")
[ -f "$H/.claude.json" ] && args+=(--bind "$H/.claude.json" "$H/.claude.json")
[ -f "$H/.gitconfig" ] && args+=(--ro-bind "$H/.gitconfig" "$H/.gitconfig")
exec bwrap "${args[@]}" --chdir "$GAME" --setenv HOME "$H" --setenv PATH "$CLAUDE_BIN:$NODE_BIN:$H/.local/bin:/usr/local/bin:/usr/bin:/bin" \
  --setenv STUDIO_URL "${STUDIO_URL:-http://127.0.0.1:8790}" --setenv VOIDLUX_GAME "$(basename "$GAME")" --unsetenv CLAUDECODE --unsetenv CLAUDE_CODE_CHILD_SESSION --unsetenv CLAUDE_CODE_SESSION_ID --unsetenv CLAUDE_PID --unsetenv CLAUDE_CODE_MESSAGING_SOCKET --unsetenv CLAUDE_CODE_MESSAGING_TOKEN --unsetenv CLAUDE_CODE_ENTRYPOINT --unsetenv ANTHROPIC_API_KEY \
  --die-with-parent -- "$@"
