# The producer's browser: running a game in someone's open page

How the forge plays, playtests and screenshots a game **without a browser on the server**. Anything that needs the
game running (an agent's playtest, a screenshot) is done by a page a person already has open; the server only queues the
request and collects the answer. Another system can do the same by keeping the five parts below.

## Why

A headless browser on the server took the machine down, and agents in the jail have none. People, though, keep the
game's page open. So that page is the runner: it picks up work, runs the game in a small frame, and posts back the result.

## The five parts

```
 agent / CLI                server (forge)                       the person's open page
 ───────────                ──────────────                       ──────────────────────
 1. write a request  ──►  .studio/soak-request.json
                          .studio/shot-request.json
                                         ◄── 2. poll every 5 s (8 s when hidden): "any request?"
                                         ──► the unclaimed request, if any
                                         ◄── 3. claim it (first page to claim wins)
                                              4. run the game in a frame (with the frame shim),
                                                 wait, read its result from window
                                         ◄── 5. post the result
 wait for the result file ◄── .studio/soaks/<id>.json, .studio/shots/<id>.png
```

### 1. The request (agent side)

A JSON file in the game's `.studio/`, written atomically (write a `.tmp`, then rename):

| File | Fields |
|---|---|
| `soak-request.json` | `id` (time + random), `secs` (game-seconds), `fast` (speed), `t` (unix time), `claimed: 0` |
| `shot-request.json` | `id`, `secs` (run time before the capture), `query` (added to the game's URL), `t`, `claimed: 0` |

One request of each kind at a time per game. Writers: `studio/bin/soak` and `studio/bin/shot` (shell), or
`GameStore::requestSoak()` (PHP).

### 2. The poll (page side)

The game's page (`views/game/view.php`, `poll()`) calls `GET /game/events/<slug>` every 5 s, 1.5 s while an agent works,
8 s while the tab is hidden. The answer carries `soakRequest` / `shotRequest`: the request, only while **unclaimed**.
Each poll also stamps `.studio/browser-seen` (`GameStore::browserSeen`): "a browser is attached", which tools read.

### 3. The claim

`POST /game/soakclaim/<slug>` or `/game/shotclaim/<slug>` with `{ id }` (logged in, CSRF token). The server sets
`claimed` to the time if the id matches and nobody claimed it first, and answers `{ claimed: true|false }`. Only a
page that got `true` runs it, so two open pages never run the same request.

### 4. The run: a frame the page controls

The page adds a small window with an iframe on **`/game/shotframe/<slug>?<query>`**: the game's own `index.html`, served
with `<base href="/games/<slug>/">` and **`kit/js/kit-shot.js` loaded before any game script**. The shim serves
`requestAnimationFrame` from a message-channel pump while the tab is hidden. A browser stops animation frames in a
background tab, and without the shim a game in a hidden page never draws or advances, so the result is blank.

- **Playtest:** the URL has `?soak=<secs>&fast=<k>&seed=7`. The game's soak code (`kit/js/kit-test.js`, or a game's own
  `js/forge-soak.js`) plays with the autopilot and, when done, sets **`window.__SOAK`** to its report
  (`{ errors, checks, scenes, fps, score, level, kills, shot? }`). While it runs it updates
  `window.__SOAK_PROGRESS = { t, scenes, errors }`. The page polls the frame every 0.5 s for `__SOAK`, up to a time
  budget (`secs / fast * 2.5 + 20 s`).
- **Screenshot:** the page waits `secs`, then reads the frame's `<canvas>` with `toDataURL('image/png')`.
- The frame is same-origin, so the page can read its window. Listen for the frame's `error` and
  `unhandledrejection` **after its `load` event**: a listener added before load is lost when the frame's window is
  replaced.
- If no `__SOAK` comes, the page reports why, from what it can see: the game's errors, how far `__SOAK_PROGRESS` got,
  and whether the tab was hidden (`status: error | hidden-tab | timeout`).

### 5. The result

`POST /game/soakdone/<slug>` `{ id, report }` or `/game/shotdone/<slug>` `{ id, png, w, h, errors }`. The server
writes `.studio/soaks/<id>.json` (with `ok = no errors, no failed checks, status ok`) or `.studio/shots/<id>.png`
plus `<id>.json`, clears the request, and emits an event for the game page's timeline.

### The waiting side's rules

The agent's tool waits for the result file:

- **Claim deadline:** if nobody claims the request within `BROWSER_SOAK_WAIT` (60 s), give up. Remove the request,
  print `{"ok": false, "status": "no-browser", …}` and exit 2.
- **Result deadline:** once claimed, wait up to the run's budget plus a margin.
- **"No browser" is not a fault in the game.** The tools say so, and the agent says in its reply that the check is still
  to do. It must never start a browser itself.

## Doing this in another system

Keep the contract, swap the plumbing:

| Part | Here | Anything equivalent |
|---|---|---|
| Queue | one JSON file per kind in the game's folder | a DB row, a Redis key |
| Discovery | the page polls every few seconds | polling, server-sent events, a websocket |
| Claim | `claimed` set once, compare-and-set | a row update `WHERE claimed IS NULL` |
| Runner | an iframe in a page someone has open | the same; or a worker page left open on a spare machine |
| Frame shim | `kit/js/kit-shot.js` before the app's scripts | the same file: it depends on nothing |
| Result | `window.__SOAK` read from the same-origin frame | `postMessage` from the frame if it is cross-origin |
| Waiting | the CLI polls for the result file, with two deadlines | the same two deadlines |

The app under test needs only three things:

1. a URL mode that runs itself (`?soak=N`: autopilot, then a report),
2. a report on a known global (`window.__SOAK`) and progress on another (`window.__SOAK_PROGRESS`),
3. to be servable with a script injected first (for the frame shim).

## Files

- `studio/bin/soak`, `studio/bin/shot`: the agents' tools (write a request, wait, print the result)
- `lib/GameStore.php`: `requestSoak`, `soakRequest`, `claimSoak`, `finishBrowserSoak`, `browserSoak` (the PHP side's
  wait), `shotRequest`, `claimShot`, `browserSeen`
- `controls/Game.php`: `events` (the poll), `soakclaim`, `soakdone`, `shotclaim`, `shotdone`, `shotframe` (the frame)
- `views/game/view.php`: `poll()`, `runBrowserSoak()`, `runBrowserShot()`
- `kit/js/kit-shot.js`: the frame shim; `kit/js/kit-test.js`: a game's soak mode and its `__SOAK` report
