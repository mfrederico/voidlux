# Workbench notebook

The workbench is the pause screen where the player edits the centipede's build. It reorders segments, moves bullets between the body and the bag, fuses pairs into stronger bullets for dots, saves a loadout (the padlock), picks the active mega and wears a hat. A shooting gallery shows the body's measured dps live. In code, the global `Build` (js/workbench.js) owns input and selection. `Render.drawPaused` and its helpers (js/render-bench*.js) draw it and record hit rects. `BenchFx` (js/benchfx.js) plays the animations, which are purely cosmetic. `Gallery` (js/gallery.js) runs the dummy lane. All real model changes go through `Player` (see notebook-player.md).

**Files:**

| file | what it holds |
|---|---|
| js/workbench.js | `Build`: state, `open`/`close`, `update` (keys), pointer handlers (`pointerDown`/`Move`/`Up`, `tap`, `drop`), `nav`, `fuse`, `move`, `place`, `stash`, `auto`, `padlock`, `answer`, `pickMega`, `wearGear`, `destOf`, `tileAt`, `cursor` |
| js/render-bench.js | shared widgets (`benchPill`, `benchTile`, `benchBin`, `benchWrap`, `benchFit`, `benchTier`, chips, `pipeFrom`, `benchChain`), `drawPaused` (the frame), `benchTop` (title, tabs, buttons), `benchBodyRow` (the body row both panels use), `benchBlueprint`, the info-card variants `benchAsk`, `benchLockAsk`, `benchCardInfo`, `benchPillInfo`, `crackle` |
| js/render-bench-body.js | the BODY (LOADOUT) panel: `benchBand` (toolbar, synergy line, body row; shared with FORGE), `benchBody`, `benchSynergy`, `synergyAura`, `benchInfo` (the right-hand card's rect) |
| js/render-bench-perks.js | PERKS page (`benchPerks`), help overlay (`benchHelp`), MEGA DECK and HAT RACK pages (`benchDeck`, `deckOf`, `deckBadge`, `gearItems`), the small MEGA / HAT cards on LOADOUT (`benchMega`, `benchGear`) |
| js/render-bench-forge.js | the BAG page (panel `'forge'`): `benchForge`, `benchBag` (big thumb-sized tiles), `benchInspector` (the info card), `benchSquares` (TRASH / STASH / ADD) |
| js/render-bench-lab.js | the LAB page: `benchLab` (body strip, header), `benchLabCards` (the recipe cards, paged), `benchReactor` (the selected card's ingredients, the core, CRAFT IT, the bag pocket) |
| js/benchfx.js | `BenchFx`: fusion and move tokens, landing squash, slot pulses, the dps count-up, element badge pulses, the white flash |
| js/gallery.js | `Gallery`: a light simulation of the body firing at a training dummy; measured dps |
| js/icons.js | `Icons`: procedural glyphs (`draw`, `tile`, `strip`, `stripWidth`, `bulletItems`, `kindOf`) and `ELEM_COLORS` |

## Model — the state it owns, and its lifecycle

**Panels.** `Build.PANELS = ['body', 'forge', 'lab', 'powers']` are the four tabs: LOADOUT (`'body'`), BAG (`'forge'`), LAB, POWERS. POWERS (`'powers'`) is a hub of three big buttons (`benchHub`) opening the pages in `Build.POWER_PAGES` (`'hats'`, `'megas'`, `'perks'`); `Build.panel` can be any of the seven. `tabOf(panel)` says which tab lights up; `goTab(t)` handles a tab: POWERS reopens `lastPower` (the page you last had open) from another tab, or goes back to the hub from one of its pages. ESC and the ◀ button (`hit.back`, in the title spot) also go back to the hub. `open(panel)` opens on `panel`, else the BAG if it has anything, else LOADOUT. P, C and the HUD's CRAFT button open on the LAB when a recipe is affordable (C: whenever there is one); the craft tip opens the LAB too.

**Selection.** `Build.row` is the zone the cursor is in: `'body' | 'bag' | 'cards' | 'pills' | 'tabs' | 'perks' | 'megas' | 'gears'`. Each zone has its own index: `sel` (body segment), `bagSel` (bag stack index into `Player.bagGroups()`, not a bag index), `cardSel` (absolute index into `Player.fusions()`), `cardFirst` (first card shown on this page), `pillSel`, `perkSel`, `megaSel`, `gearSel`. `cursor(nth)` turns body/bag selection into a Player location `{ where, i }`; for a bag stack, `nth` picks which copy.

**Transient state.**
- `mark`: ingredient A chosen with C, waiting for a partner.
- `held`: a body segment lifted with ENTER, to swap with the next one ENTER lands on.
- `drag`: `{ from, id, x, y, sx, sy, moved }` while a pointer holds a tile. `press`: a pointer down on empty space (taps and swipes).
- `ask`: a modal question in the info card. Either FUSE or MOVE `{ from, target, id, r, cost, p, swap }`, or `{ kind: 'lock' }`.
- `need`: `{ t, r, cost, have }`, the "not enough dots" callout. `Player.craft` sets it.
- `ghost`: show the locked blueprint in place of the body (tap the head, or V).
- `megaArm`: a mega chip pressed once while the bar has charge (a second press switches).
- `fly`: `{ kind, id, from, t0, landed }`, a mega or hat card floating into the right panel.
- `flash`, `help`, `inspKey`/`inspT` (the info card's slide-in), `lastCard` (double tap detection).
- `openBody`, `lockAsked`, `followLock`: remember the body at open so a locked build can tell edits from losses.

**`Build.hit`** is rebuilt from scratch every frame by `Render.drawPaused`. Keys: `body`, `bag`, `fusions`, `buttons`, `tabs`, `perks`, `pills`, `megas`, `gears`, `goto`, `trash`, `stash`, `bodyEnd`, `bodyRow`, `bagRow`, `head`, `ask`, `cardGrid`, `cardPage`.

**Lifecycle.** main.js toggles with P (and C, outside the bench): `G.state = 'paused'` plus `Build.open()`; leaving calls `Build.close()` then `G.state = 'playing'`. ESC in `Build.update` also closes. The desktop build pauses into the bench on blur. `Settings` opens it from the discovery card's tip. While paused, main.js calls `Build.update(dt)`, `BenchFx.update(dt)` and `Gallery.update(dt, Gallery.lane)` each frame, and the HUD calls `Render.drawPaused()` (also under Settings when opened from the bench). `open` resets `BenchFx` and `Gallery`. `close` runs `Player.rebuild()` (a locked build snaps back) and freezes the overworld until a direction is pressed (`Player.ready = false`, `reorient`).

## How it works — the main flows, named by function

**The Build / Render contract.** Render draws, Build decides. Each frame `drawPaused` clears `Build.hit` and every drawn tile, card, pill, tab and button pushes its rect. Next frame, `Build` uses those rects for everything spatial:
- `tileAt(p)` and `tap(p)` hit-test pointer events against them.
- `nav(dx, dy)` builds a node list from all the rects and moves to the nearest node in that direction. So keyboard navigation follows the layout automatically. Off the bottom of the card grid it pages (`hit.cardGrid`).
- `BenchFx.rectOf(L)` finds where a body slot or bag stack is on screen, for animation sources and targets.
- A button rect with a `code` is pressed by `Input.tapKey(code)`. The tap becomes a synthetic key, so the key path in `Build.update` does the work. Codes used: `KeyP` resume, `KeyO` settings, `Slash` help, `KeyZ` FILL, `KeyB` padlock, `KeyX` stash, `Enter` add/swap/craft, `Delete` (the bin).
- Render also writes a little Build state: `inspKey`/`inspT` (the info card), `cardFirst` (clamped in `benchCards`), `fly.landed`. That is the only flow in the other direction.

**Keys (`Build.update`).** Tab cycles panels. `/` help. While `ask` is open only its keys work (Enter / Space / Esc for FUSE or MOVE; Enter / U / Esc for the lock question), and `Input.pressed` is cleared. WASD or arrows call `nav`. ESC drops `held`, then `mark`, then leaves. On PERKS, MEGAS and HATS pages the rest of the keys are swallowed (ENTER picks a mega or hat). Otherwise:
- C: mark, unmark, or fuse with the mark (`fuse`). C on a marked stack fuses two of that stack.
- DEL / Backspace: on BODY, stash the segment; elsewhere `Player.destroy` scraps it for `CFG.TRASH_DOTS` dots.
- Z: `auto` (FILL). B: `padlock`. V: `toggleGhost`. O: settings.
- On the body row: ENTER lifts (`held`) and lands a segment (swap, or ask FUSE / SWAP if a recipe exists and dots are there). Q / E shift the segment (`Player.moveSeg`). X stashes.
- On the bag row: ENTER adds the stack's bullet to the body, or swaps it with the selected body slot when full.
- On cards: ENTER `craftCard(cardSel)`.

**Pointer.** `pointerDown` starts a `drag` on a tile, or a `press` on empty space. A drag past 14 px is a real drag. `pointerUp`: a press that swiped vertically over the cards pages them; a wide horizontal swipe flips the panel; otherwise it is a `tap`. A drag ends in `drop`:
- on the bin: destroy. On the STASH square or the bag row: to the bag.
- on another tile: if `findRecipe` matches and dots are enough, open the FUSE or MOVE `ask`; short of dots, nothing moves and the `need` callout shows; two bag stacks just fuse; otherwise `move` (reorder, swap in, stash). While a dragged piece hovers over one it fuses with, a lightning string arcs from where it came from to the target; while the ask is open the pair keeps arcing, and on into the FUSE button.
- tap a bag piece, then a body piece it fuses with (or the other way round): the same FUSE or MOVE `ask`. `Build.picked` remembers the last tapped piece; only a piece you tapped counts (the cursor's resting place does not), and a second tap on the same piece is what places or stashes it.
- a bag tile on the body's empty slots: `Player.fromBag(i, -1)`.
- `tap` order: help, ask buttons, tabs, perk cards, goto cards, mega chips, hat cards, the head (ghost), buttons, bin, page arrows, crafting cards (a tap selects, a quick double tap crafts), then tiles. Tapping a selected tile does the obvious thing: a body segment goes to the bag, a bag bullet goes in (`place`).

**Fusing (`Build.fuse`).** All fuses go through it. It finishes any running animation, captures source rects and `Player.estimateDps(0, 1)`, then calls `Player.craft(A, B)`. The model changes at once. It then hands `BenchFx.fuse` the sources, the collision point, the destination (from `Player.lastCraft`) and dps before and after. A hat result has no slot: `BenchFx.burst` plays on the head instead. `destOf(f)` predicts where a card's result will land (mirrors `Player.craft`); the cards show it as "→ slot n", bag, or head/rack.

**The LAB.** `Build.labCards()` is `Player.fusions()` (makeable now, affordable first) followed by "near" recipes: ones you hold exactly one half of (`{ near: true, have, need }`), dimmed with "find X", so the page doubles as a recipe book. `cardSel` indexes that list. The reactor (`benchReactor`, in the info card's rect) shows the selected card's two ingredients with where each comes from (body slot or bag), arcs from a body ingredient's slot on the strip down into its reactor tile and from both tiles into the core, the result, and two buttons: BAG (`Space`: the result goes in the bag) and BODY (`Enter`, the head icon: onto the centipede, into the body ingredient's slot or a free slot; greyed "body full" when neither, `Build.bodyRoom`). Head gear gets one CRAFT IT. `Player.craft(A, B, quiet, to)` takes the choice (`to: 'bag' | 'body'`), and `destOf(f, to)` mirrors it. `Build.hit.reactor` gives `craftCard` the tile and core positions: `fuse(A, B, core, { a, b })` passes them as the sources, and `BenchFx` plays a slower gather with lightning beams (`tk.beams`, `BEAM_GATHER`). While a beamed craft flies, the reactor shows FUSING. A bag result flies to the reactor's bag pocket (`Build.hit.bagPocket`, `BenchFx.rectOf`'s fallback). The LAB's body strip is a picture: no drags or taps on its tiles, and `nav` skips it.

**Fresh and NEW.** `Player.craft` puts the result in the body ingredient's slot, or in the bag when both came from the bag, and marks it fresh: `seg.fresh` or `Player.freshBag` (kinds). Fresh tiles wear the lightning frame (`Render.zapFrame`) on every page until touched (`Build.touch`, placing, stashing) or until the bench opens again (`open` clears them). A kind not in `Settings.benchSeen` wears a gold NEW badge (`Render.newBadge`); `close` adds everything on the body and in the bag to `benchSeen` (persisted as `centipede.benchSeen`, seeded from `Settings.seen` the first time).

**Crafting as seen from the bench.** The LAB lists `Player.fusions()`: every recipe from `RECIPES` (plus the Bouncer ricochet rule in `findRecipe`) that the body and bag can make now, one card per recipe, affordable first. Each card shows A + B → result, the stat strip (`Icons.bulletItems`), the dot cost (`CFG.CRAFT_COST` by tier) and the destination. Hovering or selecting a card makes its two ingredient tiles crackle at each other and outlines the destination slot. A recipe whose result is a hat you already own is hidden.

**The padlock (`padlock`).** No lock: `Player.lock()`. Lock set but the body has moved away: re-save it. Lock matches: `Player.unlock()`. When the body first changes on a visit with a lock set, `update` opens the lock `ask`: Enter follows (`followLock`, re-locks after every change), U unlocks, Esc keeps the lock (leaving puts the old build back). `benchBlueprint` draws the padlock pill and the blueprint tiles, with what each missing entry still needs.

**FILL (`auto`).** Only with no lock. Fills empty slots from the bag, scoring tier, the body's main element (+2.5), crafted (+0.5), and off-element (-0.5).

**MEGA DECK and HAT RACK.** `benchDeck` lists only owned megas or hats as cards. `pickMega` calls `Mega.activate` (switching empties the charge, so with charge it needs two presses). `wearGear` calls `Player.equipGear`. Both start a `fly` that `benchDeck` animates into the right panel over 380 ms.

**BenchFx.** A fuse token goes `gather` (ingredient ghosts fly together, `GATHER`) → `burst` (flash, rings, particles, fuse sound) → `hold` → `fly` (an arc to the slot). A move token only flies. While a token is in flight its destination is `pending` and the renderers draw it as an outline. `landed` squashes the tile, pulses the slot, counts the dps readout up (`dpsShown`), pulses new element badges and calls `Gallery.volley()`. `focus` grows the body strip and dims the bag on FORGE while a result flies to the body. Any key or tap calls `BenchFx.finish()`, which lands everything at once.

**Gallery.** `Gallery.update` fires each non-jammed weapon segment on its own clock (cooldown plus flight time, like `Player.estimateDps`), moves tracers along the lane, applies armour (0.6 on plain bullets), combos and element statuses, and logs every hit. `dps()` is damage landed in the last `WINDOW` (4) seconds. The dummy's max hp is about six seconds of estimated output, so KOs come at a steady pace. It is a simulation, not Combat. `Gallery.draw` is called by `benchBody` and records `Gallery.lane` for the next update.

**Icons.** The shared visual language: a bullet tile (`Icons.tile`: colour square, delivery glyph, element badges), stat strips (`Icons.strip` of `{ icon, text, color }` items), and named glyphs (`bag`, `segment`, `helmet`, `dot`, `hammer`, `mega`, `swap`, `plus`, element names...). The bench uses them for cards, chips, the destination marker and the gallery header.

## Interop — reads from / is called by / calls into other systems

- **Player (notebook-player.md):** reads `segments`, `bag`, `bagGroups`, `blueprint`, `autoRebuild`, `gear`, `gears`, `syn`, `synN`, `synAt`, seg `pipe`/`pipeBoost`/`jam`, `fusions`, `rebuildPlan`, `blueprintLines`, `holdings`, `missingBlueprint`, `estimateDps`, `lastCraft`. Writes via `craft`, `moveSeg`, `toBag`, `fromBag`, `destroy`, `lock`, `unlock`, `lockMatches`, `rebuild`, `equipGear`, `ownsGear`. `Player.craft` sets `Build.need`.
- **main.js / G (notebook-core.md):** opens and closes the bench, runs `Build.update`, `BenchFx.update`, `Gallery.update`. Reads `G.dots`, `G.mods`, `G.powerMult`, `G.mega`, `G.god`, `G.msg`.
- **Input:** `Input.once`, `Input.pressed` (cleared to swallow keys), `Input.tapKey`, `Input.touch`, `Input.mouse`, `Input.pinch` (the two-finger magnifier, applied in `drawPaused`), `Input.pts`. js/input.js routes touch and mouse to `Build.pointerDown`/`Move`/`Up`.
- **Render (notebook-render.md):** `Render.drawPaused` is called from js/render-hud.js. Uses `roundRect`, `text`, `circle`, `shine`, `eyes`, `mouth`, `blush`, `tint`, `drawGear`, `statusOverlay`.
- **Perks and megas (notebook-perks.md):** `Mega.has`, `Mega.current`, `Mega.activate`, `Mega.label`, `MEGAS`, `PERKS` (the PERKS page), `Perks.twinMult`.
- **Combat (notebook-combat.md):** `Combat.ramp` in the gallery. **Settings:** `Settings.open('paused')`, `Settings.howItFires`. **Audio (notebook-audio.md):** `SFX.play` (`tab`, `dot`, `shed`, `pickup`, `pellet`, `loadout`, `fuse`, `fuse4`, `land`, `lose`).
- **Bosses:** the god-mode line at the bottom of `drawPaused` reads `BOSSES.crab` and `Enemies.bossHp`.

**CFG keys read:** `W`, `H`, `TRASH_DOTS`, `CRAFT_COST` (via `Player.craftCost`), `SYNERGY` (`need`, `inRow`), `MEGA_MAX`, `SHROOM.dmg`. Data: `BULLETS`, `RECIPES`, `findRecipe`, `ELEMENTS`, `ELEM_COLORS`, `PIPES`, `synInfo`.

**Save fields:** none directly. The build it edits is saved by `Save.write` (see notebook-player.md). Note the gotcha below: closing the bench does not save.

## Invariants and gotchas

- **The model changes first, the animation follows.** Never make game state wait on `BenchFx`. Capture source rects (`BenchFx.rectOf`) before calling the Player method that moves the item, because afterwards the rect belongs to something else.
- **Hit rects are one frame old.** Input handlers use the rects from the last draw. Anything you draw that should be clickable or keyboard-reachable must push a rect into `Build.hit`, and `drawPaused` must create the array in its reset object. `nav` only knows about zones it lists.
- `bagSel` and hit-rect `i` for the bag are stack (group) indexes; Player locations need a bag index (`g.idx[...]`). `tileAt` returns both (`i` and `gi`).
- Body placeholders (`end: true` rects) are drop targets, not tiles. `tileAt` and `nav` skip them.
- `Render.pipeFrom` re-states the piping rule from `Player.resolveBuild` for the status chain display. Change both together.
- Minion segments are selectable and trashable but cannot be stashed or swapped.
- On PERKS, MEGAS and HATS, `update` clears `Input.pressed` early, so global keys that read later in the frame see nothing.
- The ask is modal for both keys and taps; a tap outside its buttons cancels.
- `Build.fly` is only cleared by `open`; `landed` stops it replaying.
- `Build.close` saves the run (`Save.write`). For a while that call sat inside a mid-line `//` comment and never ran (fixed in 2af20dc).
- `benchDeck` lists only the cards you own (unfound megas and hats stay hidden); a line under the grid says where more come from.

## Tuning — where its numbers live

Game numbers come from `CFG` (js/config.js, js/tuning.js): `CRAFT_COST`, `TRASH_DOTS`, `BAG_MAX`, `STACK_MAX`, `SYNERGY`. Layout numbers are local to the render files (`Render.BENCH.M` margin, tile sizes in `benchBodyRow`, card sizes in `benchForge`; `benchText()` scales key text 1.5x on touch). Animation timings are `BenchFx.GATHER`, `BURST`, `HOLD`, `FLY`. Gallery: `Gallery.WINDOW`, dummy armour in `mkDummy`.

## Testing — how to see it working

- Dev server: `python3 tools/devserver.py`, then http://localhost:8765/. Start a run and press P (or C) for the bench.
- `?god=1` gives a full bag and plenty of dots, so every card is craftable. In god mode the bench footer shows a crab time-to-kill estimate and the gallery header shows `est ... (boss calc)`.
- Check: drag a bag bullet onto a body segment with a recipe (FUSE or MOVE question), fuse from a card and watch the token land and the dps count up, press B then edit the body (lock question), press V to view the blueprint, Tab through all five panels, WASD across zones.
- Console while paused: `Build.panel`, `Build.row`, `Build.hit`, `Player.fusions()`, `Build.craftCard(0)`, `BenchFx.tokens`, `Gallery.dps()`.
- Touch: use the phone over adb (see the project memory notes); pinch magnifies, swipes flip panels.

## Change log hooks

Update this notebook in the same commit as any change to the system's behaviour.
