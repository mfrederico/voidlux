#!/usr/bin/env bash
# Run a command confined to one game folder with bubblewrap. Inside: the OS read-only, the kit, the studio tools, the
# templates and Centipede read-only, the game folder read-write, Chrome and Playwright for the soak, and the claude CLI.
# Nothing else of this machine is visible: not /var/www, not the other games, not the rest of $HOME. Network is shared.
# usage: jail.sh <game dir> <command...>      (GameAgent runs the agent through this when [forge] jail is on)
#
# No --proc and no --unshare-pid: this host forbids a fresh proc mount in an unprivileged namespace, so the host's /proc
# is bound read-only instead (tested: claude, node, git and Chrome run; bun crashes without a /proc at all).
# HOME inside is a tmpfs with the account's ~/.claude and ~/.claude.json bound in when [forge] agent_login is host (the
# runner passes JAIL_HOME_CLAUDE=1); with agent_login = app the token travels in the environment and nothing of $HOME
# is shown. JAIL_RW=path:path adds read-write binds; JAIL_RO=path:path read-only ones.
set -euo pipefail
GAME=$(readlink -f "$1"); shift
HERE=$(cd "$(dirname "$0")" && pwd); ROOT=$(readlink -f "$HERE/..")
H=${HOME:-/home/$(id -un)}
NODE_BIN=$(dirname "$(readlink -f "$(command -v node)")")
CLAUDE=$(readlink -f "${CLAUDE_BIN:-$ROOT/bin/claude}")
[ -x "$CLAUDE" ] || CLAUDE=$(readlink -f "$(command -v claude)")
CLAUDE_DIR=$(dirname "$CLAUDE")
args=(--ro-bind /usr /usr --ro-bind /etc /etc --ro-bind /proc /proc --dev-bind /dev /dev --tmpfs /tmp --tmpfs "$H" --tmpfs /run --dir /run/user/$(id -u))
for d in /lib /lib64 /bin /sbin /opt; do [ -e "$d" ] && args+=(--ro-bind "$d" "$d"); done
for d in kit studio templates centipede; do [ -d "$ROOT/$d" ] && args+=(--ro-bind "$ROOT/$d" "$ROOT/$d"); done
args+=(--bind "$GAME" "$GAME")
args+=(--ro-bind "$CLAUDE_DIR" "$CLAUDE_DIR")
# the node install: the whole prefix, not just bin/ (npx is a link into ../lib/node_modules, and a plugin's MCP server such as
# mojulo starts with `npx -y`); /usr and /opt are bound already
NODE_HOME=$(dirname "$NODE_BIN"); case "$NODE_HOME" in /usr*|/opt*) ;; *) args+=(--ro-bind "$NODE_HOME" "$NODE_HOME");; esac
# the soak: Playwright from the operator's node_modules, its browser cache, and Chrome (/opt/google is under /opt above)
PW_ROOT=${PLAYWRIGHT_ROOT:-$H}
[ -d "$PW_ROOT/node_modules" ] && args+=(--ro-bind "$PW_ROOT/node_modules" "$PW_ROOT/node_modules")
[ -d "$H/.cache/ms-playwright" ] && args+=(--ro-bind "$H/.cache/ms-playwright" "$H/.cache/ms-playwright")
if [ "${JAIL_HOME_CLAUDE:-0}" = "1" ]; then
  [ -d "$H/.claude" ] && args+=(--bind "$H/.claude" "$H/.claude")
  [ -f "$H/.claude.json" ] && args+=(--bind "$H/.claude.json" "$H/.claude.json")
else
  # the app's own agents' home (.aibuilder/home): where the AI agents page (/agents) installs plugins and skills with the
  # app's bin/claude, so every game's runs get them. Sessions still stay per game (claude keys them by working folder).
  AH="$ROOT/.aibuilder/home"; mkdir -p "$AH/.claude"; [ -f "$AH/.claude.json" ] || echo '{}' > "$AH/.claude.json"
  args+=(--bind "$AH/.claude" "$H/.claude" --bind "$AH/.claude.json" "$H/.claude.json")
  # what the plugins keep between runs: mojulo's recipes and models (~/.mojulo), and npm's cache so `npx -y` does not fetch every run
  for d in .mojulo .npm; do mkdir -p "$AH/$d"; args+=(--bind "$AH/$d" "$H/$d"); done
fi
[ -f "$H/.gitconfig" ] && args+=(--ro-bind "$H/.gitconfig" "$H/.gitconfig")
IFS=: read -ra extra <<< "${JAIL_RW:-}"; for d in "${extra[@]}"; do [ -n "$d" ] && [ -e "$d" ] && args+=(--bind "$d" "$d"); done
IFS=: read -ra extra <<< "${JAIL_RO:-}"; for d in "${extra[@]}"; do [ -n "$d" ] && [ -e "$d" ] && args+=(--ro-bind "$d" "$d"); done
exec bwrap "${args[@]}" --chdir "$GAME" --setenv HOME "$H" --setenv PATH "$CLAUDE_DIR:$NODE_BIN:/usr/local/bin:/usr/bin:/bin" --setenv JAILED 1 \
  --setenv PLAYWRIGHT_ROOT "$PW_ROOT" --setenv CHROME "${CHROME:-/usr/bin/google-chrome}" --setenv XDG_RUNTIME_DIR "/run/user/$(id -u)" \
  --unsetenv CLAUDECODE --unsetenv CLAUDE_CODE_CHILD_SESSION --unsetenv CLAUDE_CODE_SESSION_ID --unsetenv CLAUDE_PID --unsetenv CLAUDE_CODE_MESSAGING_SOCKET --unsetenv CLAUDE_CODE_MESSAGING_TOKEN --unsetenv CLAUDE_CODE_ENTRYPOINT \
  --die-with-parent -- "$@"
