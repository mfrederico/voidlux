#!/usr/bin/env php
<?php
/**
 * agent-shell.php — Claude Code at the keyboard, in the forge's agents' own environment: the app's bin/claude, the app's
 * sign-in ([forge] agent_login), the agents' HOME (.aibuilder/home, where /agents installs plugins and where their MCP
 * sign-ins and mojulo's data live) and [forge] node_bin. For what a run cannot do for itself:
 *   - signing a plugin's MCP server in: `/mcp`, pick spritecook or cellcog, open the link it prints, paste the code. The token
 *     lands in .aibuilder/home/.claude/.credentials.json, which every run (jailed or not) uses from then on.
 *   - trying a plugin's skill or tool by hand before asking a game's builder to.
 * Not jailed: it is a person at the keyboard. From an ssh session:
 *   php scripts/agent-shell.php                 interactive, in games/ (no game's session is touched)
 *   php scripts/agent-shell.php <slug>          interactive, in that game's folder (its sessions, its guides)
 *   php scripts/agent-shell.php [slug] <args>   runs `claude <args>` there instead: `mcp list`, `plugin list`, `--version`
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../vendor/autoload.php';
chdir(dirname(__DIR__));
define('BASE_PATH', \app\Paths::root());
new \app\Bootstrap('conf/config.ini');
$root = \app\Paths::root(); $args = array_slice($argv, 1); $cwd = \app\GameStore::root() . '/games';
if ($args && preg_match('/^[a-z0-9][a-z0-9-]*$/D', $args[0]) && is_dir(\app\GameStore::root() . '/games/' . $args[0])) { $cwd = \app\GameStore::root() . '/games/' . array_shift($args); }
if (!is_dir($cwd)) { fwrite(STDERR, "no such folder: {$cwd}\n"); exit(1); }
try { [$env, $bin, $note] = \app\GameAgent::environment($root); } catch (\Throwable $e) { fwrite(STDERR, 'ERROR ' . $e->getMessage() . "\n"); exit(1); }
// the agents' home, as the jail presents it: plugins, MCP sign-ins, .claude.json, ~/.mojulo, npm's cache
$home = $root . '/.aibuilder/home';
foreach (['/.claude', '/.mojulo', '/.npm'] as $d) if (!is_dir($home . $d) && !mkdir($home . $d, 0700, true)) { fwrite(STDERR, "cannot create {$home}{$d}\n"); exit(1); }
if (!is_file($home . '/.claude.json')) file_put_contents($home . '/.claude.json', '{}');
$env['HOME'] = $home;
foreach (array_keys($env) as $k) if (str_starts_with($k, 'CLAUDE_CODE_') && $k !== 'CLAUDE_CODE_OAUTH_TOKEN') unset($env[$k]); // not a child of any other session
fwrite(STDERR, "agent shell: {$bin} as {$note}, HOME {$home}, in {$cwd}" . ($args ? '' : ' — /mcp signs a plugin\'s server in; Ctrl-C twice leaves') . "\n");
chdir($cwd);
pcntl_exec($bin, $args, $env);
fwrite(STDERR, "could not start {$bin}\n"); exit(1);
