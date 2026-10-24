# Audio notebook

Every sound in the game is synthesized live with the Web Audio API; there are no audio files. Sound effects (shots, hits, pickups, a death noise per creature) come from `SFX`, a tiny oscillator synth in js/util.js. Any of them can be replaced by a "designed" sound, a stack of layers made in the SFX editor and stored in js/sfx.js. Music comes from `Music` (js/music.js), a step sequencer. It plays two kinds of tune: hand-written boss themes, and songs made in the 8-track chip tracker (editor/tracker/). All tunes live in `Music.THEMES` (js/music-themes.js). The game looks a tune up by name, so a song becomes the level, maze, shop or boss music just by being saved under the right name.

**Files:**

| file | what it holds |
|---|---|
| js/util.js | `SFX`: `init` (AudioContext + master gain), `tone`, `growl`, `moo`, `skitter`, `engine`/`engineOff` (the tractor), `custom`/`layers`/`voice` (designed sounds), `typeOf`, `hitOn`, `attack`, `extra`, `death`, `gate`/`DUR`/`MAX`, `ALIAS`, `play` |
| js/sfx.js | `SFX_DATA`: the designed sounds, written by the SFX editor through the dev server |
| js/music.js | `Music`: the engine (start/stop/tick, setMood, song, playOnce, fanfare/victory, levelTheme, bossTheme, the tracker synth `Music.inst`) |
| js/music-themes.js | `Music.THEMES`: hand-written themes, then tracker songs; the `const _ = null` rest marker |
| editor/sfx/index.html | the SFX editor |
| editor/tracker/index.html, README.md, songs/ | the chip tracker, its manual, and exported `.tracker.json` files |
| tools/devserver.py | `/api/sfx` (GET/POST), `/api/music/save`, `/api/music/delete`: these rewrite js/sfx.js and js/music-themes.js |

Load order (index.html): util.js, music.js, music-themes.js, config.js, tuning.js, bestiary.js, sfx.js… `SFX_DATA` loads after `SFX`, so `SFX.custom` checks `typeof SFX_DATA` at play time.

## Model — the state it owns, and its lifecycle

- `SFX.ctx` / `SFX.master`: one AudioContext for the whole game, created on the first user gesture (`SFX.init` is called from `Input` key, touch and mouse handlers, and from `G.titleSound`). Music shares it and connects to `SFX.master`. Master gain = `0.22 × Settings.data.volume`.
- `SFX.muted`: the one mute flag for sound and music. It is set from `Settings.data.sound` (`Settings.apply`), toggled with M (`Main.update`) and by the title's sound button (`G.titleSound`), and forced on in `?trials` runs.
- `SFX.active`: per-sound start times, used by `SFX.gate` to limit how many copies of a sound play at once.
- `Music`: `on`, `name` (the current theme name), `theme` (its object), `step`, `nextT` (when the next step plays, in AudioContext time), `semitones`, `fever`, `timer` (a 40 ms `setInterval` lookahead). Buses: `bus` → `filter` → master for the loop; `oneBus` for `playOnce` layers; `fanBus` for fanfares, so `stopFanfare` can fade just that one. `songUntil` stops two singing trees from overlapping.
- Nothing audio-related is saved with the run. `Settings.data.sound`, `volume` and `stereo` persist in localStorage (`centipede.settings`).

## How it works — the main flows, named by function

**SFX.init.** It creates the AudioContext. On touch devices (`'ontouchstart' in window || maxTouchPoints > 0`) it passes `{ latencyHint: 'playback' }`. Android's default buffer of about 4 ms ran dry while the game drew a frame, and the music crackled; the larger buffer fixed that on the phone (Note 20). **Do not revert this or "optimise" it to `'interactive'`.** The extra latency is fine for this game. Each call also resumes a suspended context.

**SFX.play(name).**
1. If muted or there is no ctx, it does nothing.
2. `gate(name)` checks the limit: one copy at a time by default, `MAX` allows `shoot` and `hit` 2. `DUR` is how long each sound holds its slot (0.2 s if not listed).
3. If `SFX_DATA[name]` exists, its layers play (`SFX.layers`).
4. Otherwise, if the name is in `ALIAS`, the aliased sound plays. `megaReady`, `perkShop`, `cleared`, `minion`, `loadout`, `discover`, `storm` and `synergy` borrow `power`; `perk` borrows `pickup`; `gearBlast` borrows `wall`. This way each event has its own name to design later.
5. Otherwise the hand-coded `switch` case plays, built from `tone`, `growl` and `setTimeout`.

**Creature sounds.** `SFX.typeOf(e)` names a monster: the bug type, `snake`/`python`/`boneSnake`, `boss-<type>`, or the kind.
- `SFX.death(e)`, from `Combat` kills, is gated to two of a kind at once. It plays `death:<type>` from `SFX_DATA` if designed, else its own `switch` case, else `play('kill')`.
- `SFX.hitOn(e)` plays `hit:<type>` if designed, else `play('hit')`.
- `SFX.attack(e)` calls `extra('attack:<type>')`. It is silent for allies and minions.
- `SFX.extra(key)` (attacks, `elem:fire`, `elem:poison` from `Combat`) is silent until the sound is designed.

**Designed sounds (`SFX.layers`, `SFX.voice`).** One random pitch factor `1 ± jitter` is picked per play and applied to every layer. Each layer starts after `t` ms (via `setTimeout`). Muted layers (`m: 1`) are skipped. One layer is:
- an oscillator of wave `w`, or white noise through a bandpass whose centre sweeps (`w: 'noise'`);
- its pitch sweeps exponentially from `f0` to `f1` over `d` seconds while its gain fades from `v` to 0.001;
- `lfo` (Hz) with `depth` (Hz) adds a square-wave pitch wobble, and `crush: 1` adds a tanh waveshaper;
- `c` is only the layer's colour in the editor.

**The voice pool.** `SFX.voice` (and `SFX.tone`, which is one layer) plays on `SFX.pool`: `SFX.POOL` (16) voices made on
the first sound and left running silent forever. Each is an oscillator and a looping noise source through a bandpass,
picked by two gains, then a waveshaper (curve null, or `crushCurve` for `crush`), an envelope gain and its own
`StereoPanner` to the master; a square LFO wobbles both frequencies. `SFX.takeVoice` hands out the voice that finishes
first, and `voice` re-aims it with automation (cancel, set, ramp). When all 16 are busy the oldest sound is cut short.
**Do not go back to creating nodes per sound.** Chrome 154 keeps memory for every Web Audio node a page creates: a fight
plays 40 to 100 voices a second, which grew the tab ~10 MB a minute (900 MB over a long bot run) and its clean-up
stuttered frames. A bare test page making two oscillators every 50 ms grew 25 MB a minute; the same beeps on 8 pooled
voices stayed flat. The rare sounds (`growl`, `moo`, the ghost wail, the engine) still build their own nodes.

**Music.start(name).**
1. It returns at once if that theme is already playing. Otherwise it stops the current one.
2. It looks the theme up. **An unknown name falls back to `THEMES.crab`.**
3. It sets the filter (`theme.filter`, or 12000) and the bus gain, resets `step`, and sets `semitones` (`CFG.NIGHTMARE.pitch` in nightmare runs).
4. It starts the 40 ms `tick` timer. `tick` schedules every step that falls due within the next 0.12 s.
- A hand-written theme steps in eighths. Each step can play bass, kick, toms, hat, organ chords, bell and lead, with options `warp`, `wail` and `theremin`.
- A tracker theme (`tickTracker`) steps in 16ths. Each track plays through `Music.inst`. A note holds `len` steps, or until the track's next note. Melodic tracks shift with `semitones`; drums do not.
- `Music.stop()` fades the loop bus over 80 ms and clears the timer.

**Boss mood.** `Enemies` boss update maps HP to a mood: <50% → 1, <30% → 2, <20% → 3 (fever), <10% → 4 (music stops). It calls `Music.setMood(semitones, fever)`. Fever means 1.45× tempo, louder drums, and a wider filter on hand-written themes. The theme also stops when the boss dies (`Combat`, and the boss's own update).

**One-shots over the loop.**
- `Music.playOnce(name, secs, sync, bus)` schedules a tracker song all at once on its own bus, so the loop carries on underneath. With `sync` it locks to the playing theme's tempo, pitch and bar phase. The singing tree uses this (`Music.song`, from `World` summons): a tracker `treesong` if there is one, else a generated pentatonic flute.
- `Music.fanfare(level)` plays at level-won time (`G` win flow). It tries `complete_level<N>` then `complete`. When it finds neither it returns false and the caller plays a chime.
- `Music.victory(level, deck)` plays when a dungeon or deck is cleared. When it plays, the caller stops the maze or deck tune; otherwise the caller plays `SFX.play('cleared')` (or `pellet` on the deck).
- Both play once on `fanBus`. `Music.stopFanfare()` is called when the player continues from the win screen.

### Every theme name the game looks for

| name(s), first match wins | where | when |
|---|---|---|
| `title` | `G.update` (title state), `G.titleSound`; render-screens shows "♪ title theme" | on the title, once audio is running |
| `level1`/`meadow`, `level2`/`graveyard`, `level3`/`jungle`, `level4`/`farm`, `level5`/`circus` (`Music.LEVEL_NAMES`, `Music.levelTheme`) | `World` region change, `G.start`, `G.enterNightmare`, `Save.resume`, `Perks.close`, Dungeon/Deck exit fallback | across a level's regions; stops in a level with no theme |
| `maze_level<N>`, `dungeon_level<N>`, `maze_<burrow\|cathedral\|temple\|ufo\|bigtop>`, `maze`, `dungeon` | `Dungeon.enter` | in a maze; on exit the previous tune (`prevTheme`) or the level theme comes back |
| `hammer` | `Dungeon` (big top hammer pickup), `Dungeon.endHammerMusic` | while the hammer lasts |
| `deck` | `Deck.enter`, Deck exit | the saucer deck (farm dungeons when `CFG.DECK_MODE`) |
| `complete_level<N>`, `complete` | `Music.fanfare` | a level's boss beaten |
| `dungeon_victory_level<N>`, `maze_victory_level<N>`, `dungeon_victory`, `maze_victory` (the deck tries `deck_victory_level<N>`, `deck_victory` first) | `Music.victory` from `Dungeon.checkClear` and the deck's last wave | a dungeon cleared |
| `perk_shop` (else the level theme) | `Perks.offer`; `Perks.close` goes back to the level theme | the perk shop |
| `treesong` | `Music.song` | a singing jungle tree |
| boss id if it is a tracker song: `crab`, `skull`, `serpent`, `overlord`, `clowncar`; else `BOSSES[type].music` (`skull`, `serpent`, `alien`, `clown`; the crab has none → `crab`) | `Music.bossTheme`, from the boss waking in enemies-bosses.js | a boss fight |

`demo` is in THEMES, but nothing looks for it.

**The SFX editor (editor/sfx/index.html).**
- It loads js/util.js and js/config.js (not sfx.js) and plays everything through the game's own `SFX`.
- It builds its sound list by fetching util.js as text and regex-matching the `case '<name>':` lines inside `play(name) {` and `death(e) {`. The trailing `//` comment on a case becomes the sound's description. It adds the `SFX.ALIAS` names, plus slots that exist only once designed: `hit:`/`attack:` per creature, `death:`/`attack:`/`hit:` per boss (`boss-<type>` from `BOSSES`), and `elem:fire`/`elem:poison`.
- Opening a sound "records" how the game plays it now: it runs the code with `tone` and `growl` captured and `setTimeout` on a virtual clock, which turns the code into layers. That original is drawn as a dashed outline.
- Editing: sliders per layer (log pitch), a graph you can click, drag and nudge, multi-select, recipes (Punch, Coin, Zap…), copy and paste between sounds (kept in localStorage `sfx.clip`), undo, Simplify (joins layers that make one line), Shimmer, Reverse (Ctrl+R), Repeat (Ctrl+D), pitch shift, mute (M), per-layer colour, jitter, trim handles, and Revert to the game's sound.
- **Recording:** use the microphone (`MediaRecorder`) or load an audio file. It is cut into pieces (60 ms by default). A voiced piece becomes a buzz at the speaker's pitch plus two formant tones (pitch by autocorrelation, formants by linear prediction). A hissy piece becomes a noise layer. A style option (8-bit square or soft sine) and snap-to-notes are offered. The piece length grows so the sound fits in 64 layers.
- **Saving** (Ctrl+S): it POSTs the whole `SFX_DATA` object to `/api/sfx`. `devserver.save_sfx` checks each key (`[a-z]…(:…)?`), allows 1-64 layers, accepts `w` only from the 5 waves, clamps the numbers, keeps `crush`, `m` and `c`, and rewrites js/sfx.js sorted by key, keeping the file's header comment. It only accepts saves from localhost. Without the dev server the editor is read-only.

**The tracker (editor/tracker/).** This is an 8-track pattern tracker. Its manual is editor/tracker/README.md; read it for the keys.
- `Song.exportJS` writes the game format: a `    // made in the tracker (date): …` line, then `    <name>: { tracker: true, bpm, steps, tracks: [` with one track per line, then `    ] },`. Muted or empty tracks are dropped. The pattern order is flattened into one `notes` array per track.
- Save (Ctrl+S) POSTs `{name, snippet}` to `/api/music/save`. `devserver.save_song` replaces the block with that name, or inserts it just above the `// ---- tracker songs below` marker line. The `/` dialog lists THEMES, which it fetches and evaluates from `/js/music-themes.js`; it shows hand-written themes converted into tracks, loads songs, and deletes them (`/api/music/delete` → `delete_song`). Every write first copies the old file to js/music-themes.js.bak.
- Without the dev server, Save copies the snippet to the clipboard. The song autosaves to localStorage `tracker.song`, and Export downloads a `.tracker.json`.

### Stereo

Sounds that belong to a place on screen are panned by where it is: `SFX.at(x, fn)` plays whatever `fn` plays from where x
is relative to the centipede's head (the screen middle when there is no head): a quarter of the view width to the left is -k,
to the right +k, where k is `Settings.data.stereo` (off 0, soft 0.5,
wide 0.8, the default). A pooled voice pans with its own panner (`SFX.pan` at the time it starts). The rare sounds outside the pool connect
through `SFX.bus()`: a `StereoPanner` shared by every sound at that pan (rounded to 0.1, kept in `SFX.pans`, at most 21),
or the master when centred. Noise shares one looping 2 s buffer (`SFX.noiseBuf`) and every distortion shares
`SFX.crushCurve`. `SFX.later(fn, ms)` replaces `setTimeout` inside SFX so a delayed note keeps its side.
Positioned today: `Combat.kill`, each bullet hit (the `applyHit` call in `Combat.update`, so its elements and any kill
it causes share the side), explosions, `SFX.hitOn` and `SFX.attack`. Interface sounds and the engine hum stay centred.
Use `SFX.at(x, ...)` for any new sound tied to a place, and `this.later` instead of `setTimeout` inside SFX.

## Interop

- Called by almost everything: `SFX.play` from Player, Combat, World, Enemies, Dungeon, Deck, Perks, Build, Settings, Input. `SFX.death`, `hitOn` and `extra` from Combat (notebook-combat.md). `SFX.attack` from Combat (enemy shots), enemies-crab.js and player-hurt.js (bites, latches). `SFX.skitter` from Enemies (an approaching parasite). `SFX.engine`/`engineOff` from Player (the tractor). `growl` is used only inside util.js (notebook-enemies.md, notebook-bosses.md, notebook-player.md).
- Music is driven by World (region change; notebook-world.md), Dungeon and Deck (notebook-dungeon.md), the boss update and Combat (notebook-bosses.md), Perks (notebook-perks.md), `G` (title, start, win, nightmare; notebook-core.md), Save resume, and the World summons (singing tree).
- Settings (notebook-core.md) owns the `sound`, `volume` and `stereo` settings and writes `SFX.muted` and the master gain. The title screen reads `SFX.ctx.state` to show SOUND ON / tap for sound.
- CFG keys read: `NIGHTMARE.pitch` (`Music.start`/`setMood`), `SONG.sync` (`Music.song`), `DECK_MODE` (decides deck vs maze music, via `G.enterDungeon`).
- Save fields: none. localStorage: `centipede.settings` (sound, volume, stereo), `sfx.clip` (the SFX editor), `tracker.song` and the tracker's synth presets.
- The editors and the dev server: notebook-tools.md.

## Invariants and gotchas

- **`latencyHint: 'playback'` on touch stays.** It fixed music crackle on the phone, and the user wants it kept.
- **`Music.inst` must stay identical to `Synth.inst` in editor/tracker/index.html** (`DRUMS`, `noise`, `env`, `inst`), so the tracker sounds like the game. As of this writing they differ only by one comment. Change both in the same commit.
- **music-themes.js format is load-bearing.** The dev server's regex (`save_song`, `delete_song`) only finds a song block that is exactly `    // made in the tracker…` (4 spaces), then `    <name>: { tracker: true,`, and so on up to a line `    ] },`. A song whose comment line says something else (today `farm`, `deck` and `hammer` start with `// made for …`) cannot be replaced or deleted by the tracker. Saving one from the tracker *adds* a second block with the same key below the marker. JS keeps the last duplicate key, so the game plays the new one, but the old block stays as dead text. Keep the marker comment line inside THEMES.
- **Classic themes.** The hand-written crab and clown themes are `crab_classic` and `clown_classic`; the tracker songs `crab` and `clown` play. `Music.bossTheme` falls back to `<music>_classic` when the named song is gone, so deleting a tracker song brings the classic back.
- Saves and the dev server rewrite whole files (sfx.js, music-themes.js). Do not hand-edit sfx.js while the editor has unsaved work, because its next save overwrites the file.
- The SFX editor finds events by parsing util.js text. A new sound appears there only if it has a `case '<name>':` in `SFX.play` (one per line, comment at the end) or an `ALIAS` entry. Keep `play` the last method of `SFX`, because the parser relies on it.
- Never put a `//` comment in the middle of a line that has code after it. It happened in `Music.start`: a comment swallowed `this.nextT = c.currentTime + 0.05;`, so each start scheduled every step since the last stop in one burst (fixed in 2af20dc). `tools/check-comments.py` catches it.
- `SFX.play('ghost')` (Will of the Lich) borrows the ghost's death wail (`death({ kind: 'ghost' })`). The `perk` alias is never played.
- util.js ends with a dangling "Boss music: …" comment and no code after it (left over from when the boss music lived there).
- music-themes.js's marker comment says the save is done by `../tracker/serve.py`; it is tools/devserver.py.

## Tuning — where its numbers live

- SFX: each sound's numbers are in its `case` in `SFX.play` / `SFX.death`, or in js/sfx.js once designed. Overlap limits are in `SFX.DUR` and `SFX.MAX`; master level is `0.22 × volume` in `SFX.init` and `Settings.apply`.
- Music: per theme in music-themes.js (`bpm`, `root`, `filter`, track `vol`/`len`/`cut`…). Loop bus gain is 0.55; fever is 1.45× tempo and drums ×1.15; the boss mood thresholds are in enemies-bosses.js; nightmare transpose is `CFG.NIGHTMARE.pitch` (-3).

## Testing

- `python3 tools/devserver.py`, then http://localhost:8765/ (the game) and http://localhost:8765/editor/ (the SFX editor at /editor/sfx/, the tracker at /editor/tracker/). Reload the game after a save. The server sends no-store, so the reload runs the new file.
- M mutes in a run. With `?god=1` (or G): B/Y/Comma/0/Q warp to bosses (boss themes); U hurts the boss by 19% to step through the mood changes; H hops into a dungeon (maze tune, then `dungeon_victory`); J opens the perk shop (`perk_shop`); L/O/X/Period warp to levels 2-5 (level themes).
- Console: `Music.start('perk_shop')`, `Music.name`, `Music.setMood(2, true)`, `Music.fanfare(1)`, `Music.victory(1)`, `SFX.play('megaReady')`, `SFX.layers(SFX_DATA.zap)`, `Object.keys(Music.THEMES)`.
- To test phone audio, see the adb notes in memory (Note 20). Crackle shows up there and not on desktop.

## Change log hooks
Update this notebook in the same commit as any change to the system's behaviour.
