# VoidLux — the forge

A tiknix app that makes games. Write a brief; a planner turns it into a design, acceptance criteria and tasks; Claude Code
sessions build each task on a shared engine in the game's own folder; a headless soak plays it; an independent reviewer
judges it; every step is a git checkpoint. Tune the sound in the SFX editor and the chip tracker, then publish: the game
is served at https://<slug>.voidlux.com/ straight from disk.

Your code lives at the root of this repository; the **tiknix runtime** (members, teams, Communications, the admin,
`/agents`, Ui, the shell) is `vendor/tiknix/runtime/`, pinned in `composer.lock`. See AGENTS.md for how to work on it.

## Layout

```
controls/Forge.php      /forge (the games), /forge/summon (the brief form)
controls/Game.php       /game/view/<slug> (play, build, test, editors, design, history, ship) and its JSON actions
controls/Gameapi.php    /gameapi/<verb>/<slug>: the editors' JSON (sfx, sounds, tuning, musicsave, musicdelete, token)
controls/Home.php       / — the VoidLux landing page (views/home/index.php)
lib/GameStore.php       games on disk: create from a template, checkpoints, soaks, the editors' files, events, publish
lib/GameAgent.php       claude -p runs and the pipeline (plan, build, soak, fix, review, rework)
lib/GameNotices.php     Communications notices when a run ends
scripts/game-agent.php  the detached runner the web starts (one per game at a time)
scripts/forge-import.php  register game folders that have no row yet
services/Schema/Seeds/30_Forge.php   the game table and permission rows
views/app/              the shell's slots: nav, theme (the void over the design system), dashboard panel, team panel
views/forge/, views/game/            the forge's pages;  public/forge.css, public/forge.js
kit/                    the engine every game runs on (kit/AGENTS.md) and the editors (kit/editor/*, served at /editor/)
templates/              what a new game is copied from (blank: a complete arena shooter)
games/<slug>/           one git repo per game (not tracked here); .studio/ inside holds events, agent state and soaks
studio/                 soak.mjs (Playwright), bin/soak (what agents run), jail.sh (bubblewrap, not used yet)
centipede/              the reference game, its own repo, playable at /centipede/
```

`public/kit`, `public/games`, `public/editor` and `public/centipede` are symlinks, so nginx serves a game in
development at /games/<slug>/ and the editors at /editor/<name>/?game=<slug>.

## Config

`conf/config.ini` `[forge]`: `domain` (games publish to https://<slug>.<domain>/ in
`publish_root`/<slug>.<domain minus tld>/public/, which capricorn serves by folder name), `agent_login` (`host`: the
claude login of the user PHP runs as; `app`: the token set on /agents), `model`, `max_turns`, `playwright_root`,
`chrome`, `soak_url`.

## The loop

1. Summon: title, slug, brief, template, team. The folder is copied from the template, substituted, committed, `cp-0`.
2. Plan: GAME.md gets a Design, Acceptance criteria and Tasks (`- [ ] T1: ... (check: ...)`).
3. Build: one run per task in the game's folder, ending in `node --check`, `../../studio/bin/soak`, a tick, a commit;
   the forge tags a checkpoint after every run.
4. Soak: Playwright opens the game with `?soak=90&fast=4`; the scenes' bots play; errors, fps and scenes come back.
   Failing soaks go back to the agent (two rounds).
5. Review: a fresh session judges each criterion with evidence and ends with a VERDICT line. Failures become rework.
6. Ship: SFX editor, tracker and config editor write into the game's files; Publish builds `dist/` with the kit inlined
   and copies it to the game's subdomain folder.

Teams: a game with a team is open to everyone on it (Ship tab → Team); the team's page lists its games; the team's
room in Communications is where its people talk. A finished run posts a notice to the owner's inbox.
