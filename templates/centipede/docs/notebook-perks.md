# Perks notebook

Perks are the run-long upgrades the player buys in the perk shop that opens after every cleared dungeon (maze or saucer deck). Ghosts eaten in dungeons are the currency; each clear also gives one free pick. Megas are the big screen-clearing powers fired with E (or the MEGA button) when the power bar is full. The player collects them into a MEGA DECK and keeps one active at a time. In code, `PERKS` is a table of perk definitions whose `apply()` changes `G.mods`, `G.powerMult` or `Player.maxSegs`. `Perks` runs the shop. `MEGAS` and `Mega` hold the megas, their charge, and their effects while they run. `GOD_PRESETS` are test loadouts for god mode.

**Files:**

| file | what it holds |
|---|---|
| js/perks.js | `PERKS`, `PERK_ICONS`, `MEGAS`, `Mega`, the mega cards added to `PERKS` (`mega_<id>`), `GOD_PRESETS`, `Perks` (defaults, shop, prices, reel, purchase fx) |
| js/render-perkshop.js | `Render.drawPerks` (the shop screen: cards, reel, price tags, MEGA tab, CONTINUE button) and `Render.drawPerkFx` (the purchase burst) |
| js/render-bench-perks.js | the workbench PERKS tab (uses `Perks.total`, `Perks.priceAt`) and the MEGAS tab / mega chips (see notebook-workbench.md) |
| js/render-cards.js | `Render.drawPerkTip`, the one-time "your perks live on the PERKS tab" card |
| js/render-hud.js | the mega bar (`Render.megaBar`, `Render.deskMega`) and the touch MEGA button |
| js/config.js | `MEGA_DECK`, `MEGA_MAX`, `SACRIFICE`, `HIVE_MIND`, `DEEP_FREEZE`, `LIGHTNING`, `LICH`, `BALANCE.freeCap/bodySq`, `MAGNET_BASE`, `TWIN.mult` |

## Model — the state it owns, and its lifecycle

Run state (reset in `G.start`, saved by `Save.write`):
- `G.mods`: the perk multipliers, created by `Perks.defaults()` (`speed, cd, bspeed, range, invuln, magnet, paraHold, luck, elem, minions, twin, summon, hardHead`). Other systems read these. On load, `Save` merges the saved mods over `Perks.defaults()`, so a new key gets its default in old saves.
- `G.perks`: every plain perk id bought, one entry per copy. `Perks.level(id)` counts copies in it. Megas are never pushed here.
- `G.ghosts`: the purse. It grows when you eat a frightened ghost in a maze (`Dungeon`), ring the high striker, or pick up a deck UFO's `ghost` pickup (`Player` pickup code). Unspent ghosts carry over between shops.
- `G.powerMult`: Brawn multiplies it (rounded to 2 decimals). `Player.maxSegs`: Bigger Body adds 1.
- `G.mega = { id, owned, charge, used }`: `owned` is the deck (always starts `['nuke']`, Sacrifice), `id` the active mega, `charge` 0..`CFG.MEGA_MAX`. `used` is counted but read nowhere.
- `G.bought`: also saved. The list of what this shop sold.

Shop state (lives only while `G.state === 'perk'`; none of it is saved):
- `G.perkChoices`: the card ids on the shelf (`Perks.SLOTS` = 4). `G.perkSel`, `G.perkLeaveSel`: the keyboard cursor (a card, or the CONTINUE button).
- `G.perkFree`: 1 when the shop opens; the first purchase uses it up. Close does not clear it, but the next `offer` sets it to 1 again.
- `G.perkReel`: the slot-machine reel (`Perks.mkReel`). It is null once every card has landed and the settle bounce is over.
- `G.perkLeave`: the CONTINUE button rect. `Render.drawPerks` writes it each frame and `Input.tapShop` reads it.
- `G.perkClosing`: a countdown (0.9 s). When nothing more is affordable after a buy, the burst plays out and then the shop closes on its own.
- `Perks.fx`: the cosmetic burst (glow, particles, rings, badge pops). It is a module field, not part of `G`.

Mega run state (on `G`, reset in `G.start`): `G.freeze` / `G.pendingBlast` / `G.sacEnd` (Sacrifice), `G.hive` (Hive Mind), `G.deepFreeze` (Deep Freeze), `G.storm` / `G.stormEnd` (Bullet Storm), `G.bolt` (Lightning Storm), `G.lich` (Will of the Lich).

## How it works — the main flows, named by function

**Opening the shop.** `Dungeon` exit on success (and `Deck` on success) calls `Perks.offer()`. It builds the shelf like this: every `always` perk (only Bigger Body), plus at most one mega card you do not own, plus shuffled plain perks. It cuts that to 4 and shuffles it. Then it sets `G.state = 'perk'`, builds the reel, plays `perkShop`, and starts the `perk_shop` theme (or the level theme). `Main.update` then sends every frame to `Perks.update(dt)`, and `Render` (via render-hud.js) draws `Render.drawPerks`.

**The reel.** `Perks.mkReel` gives each card a sequence of random faces that ends on its real perk. Cards further right spin longer. `Perks.tickReel` plays a tick for each step and a `land` sound when a card stops; when the last card lands it plays `fuse`. While `Perks.spinning()` is true, any key (or a tap, via `Input.tapShop`) calls `Perks.finishReel()`. You cannot buy anything until every card has landed.

**Prices.** `Perks.priceAt(id, n) = 1 + n`, where n is the number of copies you already own. Bigger Body adds `floor(n²/CFG.BALANCE.bodySq)` on top. `Perks.price(id)` uses `Mega.price()` for a mega card. `Perks.cost(id)` is what you actually pay: the price minus `CFG.BALANCE.freeCap` while `G.perkFree > 0`, and never below 0. `Perks.priceLabel` shows FREE, FREE+n, or "n ghosts". The free pick is spent on the first purchase, whatever it is.

**Buying.** The keys are 1-4 or Enter on the selected card (`Perks.update`), or a tap (`Input.tapShop` sends `Digit<n>`). Each calls `Perks.take(id)`:
- If you cannot afford it, it plays `lose` and shows a message.
- Otherwise it charges ghosts, uses up the free pick, and calls `apply()`.
- It pushes the id to `G.perks` (plain perks only) and to `G.bought`.
- It calls `Perks.burst` and restocks the slot. Bigger Body is never replaced. A sold mega slot gets another mega you do not own, or a plain perk once your deck is complete.
- If nothing on the shelf is affordable any more, it sets `G.perkClosing`.

**Purchase fx.** `Perks.burst(i, id)` fills `Perks.fx` with a glow, 30 particles, 2 rings and a badge "pop" that flies from the card down to the "Taken" line. It plays `fuse` (or `fuse4` for a mega). `Perks.tickFx` animates it and `Render.drawPerkFx` draws it. It is purely cosmetic.

**Leaving.** S/Down then Enter, Space, Escape, or the CONTINUE button calls `Perks.close()`. It sets the state back to `'playing'`, calls `Save.write()`, and goes back to the level theme if `perk_shop` was playing (world mode only). It shows a banner naming what you bought, then calls `Settings.perkTip(G.bought)`. That shows the one-time "YOUR PERKS" card (`G.discover = {tip:'perks'}`, drawn by `Render.drawPerkTip`). The flag is `Settings.data.perkTip` in localStorage, so the card appears once per browser, not once per run.

**Megas.**
- `Mega.charge(e)` is called from `Combat` on every kill. It adds `8 + 4*tier` to the bar, and a boss fills it outright. It adds nothing for stones, or for kills made by Bullet Storm (and for 1 s after it), Lightning Storm, the Lich, or Sacrifice (the freeze, plus 1.5 s after the blast).
- When the bar crosses full, it shows a READY message and plays `megaReady`.
- `Main.update` on KeyE calls `Mega.use()`. That empties the bar and calls `Mega[id]()`.
- `Mega.update(dt)` runs each playing frame. It ticks Lightning (`updateBolt`), the Lich (`updateLich`) and Hive Mind.
- The other timers (`G.storm`, `G.deepFreeze`, `G.freeze`) are counted down in `Main.update`. When `G.freeze` reaches 0, `Main.update` calls `Mega.blast(G.pendingBlast)`.

The megas:
- `nuke` (Sacrifice): removes the tail weapon (never a minion) and freezes time 1.1 s. Then `Mega.blast` hits everything within `SACRIFICE.r × range²`. Damage falls to half at the rim, a boss takes at most `bossCap` of its max HP, and the tail's elements add DoTs. In world mode it also breaks wall sections in range, except the sealed boss arena and the swarm walls.
- `recruit` (Hive Mind): your riding parasites become allied enemies, and nearby wild parasites turn ally for `HIVE_MIND.time`. In world mode `Mega.burrow` adds more until there are 2 per level. `Mega.level('recruit')` = the number of Berserk / Mad Moth segments, at least 1. `G.hive` replaces the fallen every `respawn` s.
- `frost` (Deep Freeze): freezes every enemy for 5 s. For `DEEP_FREEZE.time` your bullets are ice too, and `Combat.resist` clamps ice to at least `mult` (see notebook-combat.md).
- `storm` (Bullet Storm): `G.storm = 3`. `Player` fire code drops the one-bullet rule. Bosses take `megaResist` of Storm damage. It has no CFG block.
- `lightning` (Lightning Storm): `G.bolt = {t, t0, acc, k}`. The player is invulnerable for the whole time. `Player.scale()`, `Player.ballK()` and `Player.ballPos` curl the body into a rising ball. Each segment in turn fires `Mega.boltFrom`: a bolt that chains `hops` times, with damage scaling by segment tier. At the start it shocks and stuns everything within 900 px. Every tendril that lands plays `zap`, panned to where it strikes (`Mega.boltFrom`).
- `lich` (Will of the Lich): `G.lich = {t, t0, skulls}`. Each segment becomes a homing skull that spreads out across targets (a target already chased weighs more). It burns and dries (`Combat.dry`) what it hits, and flies home for the last `LICH.back` s. Meanwhile `Player` fire code does not shoot, and `Render` hides the body and draws the skulls.

**Getting megas.** A shop card calls `PERKS['mega_<id>'].apply()`, which calls `Mega.add(id)`. A unique monster killed in world mode drops a `mega` pickup `MEGA_DECK.uniqueDrop` of the time, for a mega you do not own and that is not already lying on the ground (`World.loot`). Picking it up calls `Mega.add(id, 'found…')` plus a MEGA FOUND banner. `Mega.add` makes the new mega active only if the bar is empty. **Switching** happens on the workbench (`Build.pickMega` calls `Mega.activate`), which empties the bar. When the bar has charge, the first press only arms the switch (`Build.megaArm`).

## Interop — reads from / is called by / calls into other systems

- Called by: `Dungeon` exit and `Deck` win (`Perks.offer`; see notebook-dungeon.md). `Main.update` (`Perks.update`, `Mega.use`, `Mega.update`, `Mega.blast`; god key J calls `Perks.offer`; presets call `G.applyPreset`). `Combat.kill` (`Mega.charge`). `Input.tapShop`. `Build.pickMega` (notebook-workbench.md). `Player` pickup (`Mega.add`). `World.loot` (the mega drop; notebook-world-loot.md). `Bot` (reads `G.perkChoices`, `Perks.canBuy`, `Perks.priceLabel`, `PERKS[].desc`, `MEGAS[].desc`; notebook-tools.md).
- Helpers read elsewhere: `Perks.summonLaps` and `Perks.summonPower` (world-summons, render-world, render-circus/jungle/graveyard), `Perks.magnetRadius` (World pickups, Player hurt), `Perks.twinMult` (Combat, player-build), `G.mods.*` (Player, Combat, Minions).
- Calls into: `Player` (`maxSegs`, `segments`, `segPos`, `removeAt`, `cleanse`, `isMinion`, `hardHeadChance`, `computeSegPos`; notebook-player.md), `Combat` (`damage`, `addStatus`, `resist`, `burst`, `arcs`, `clouds`, `dry`; notebook-combat.md), `Enemies.spawnParasite`, `Enemies.hpScale`, `World.regionAt/regions` (notebook-enemies.md, notebook-world.md), `Minions.hatch/cap/count`, `Settings.perkTip`, `Save.write` (notebook-core.md), `Music` and `SFX` (notebook-audio.md).
- Rendering: `Render.drawPerks` is called from render-hud.js when `G.state === 'perk'`. The mega HUD lives in render-hud.js, and the timers' screen titles (BULLET STORM, LIGHTNING STORM…) in render.js (notebook-render.md).
- CFG keys: `MEGA_DECK.{base,step,uniqueDrop}`, `MEGA_MAX`, `SACRIFICE.*`, `HIVE_MIND.*`, `DEEP_FREEZE.*`, `LIGHTNING.*`, `LICH.*`, `BALANCE.freeCap`, `BALANCE.bodySq`, `MAGNET_BASE`, `TWIN.mult`, `GEAR.hardHead`, `INVULN`, `WORLD_H`.
- Save fields (js/save.js): `mods, perks, bought, ghosts, mega`, plus `maxSegs` (the player block) and `powerMult`. An open shop is saved too (`shop: { choices, free }`, written while `G.state === 'perk'`): the dungeon's exit saves after `offer`, each `Perks.take` saves, and `Save.resume` reopens the shop as it was.

## Invariants and gotchas

- The card layout (w 260, h 250 or 350 on touch, gap 24, y 250) is written out three times: `Render.drawPerks`, `Perks.cardRect` and `Input.tapShop`. Change all three together.
- A mega card's id is `'mega_' + megaId` with `mega: megaId`. Code tells mega cards apart by `PERKS[id].mega`. Megas are never in `G.perks`, so `Perks.level` of a mega card is always 0.
- `Mega.owned()` falls back to `['nuke']` and `Mega.current()` falls back to `'nuke'`. Sacrifice can never be lost.
- `apply()` must be safe to call again and again: it runs once per copy. `Main.levelLoadout` and `G.applyPreset` call it directly. `applyPreset` skips `body`, because the preset sets `cap` itself.
- Megas a boss takes damage from scale by `BOSSES[type].megaResist` (Storm, Lightning, Lich). Sacrifice uses `SACRIFICE.bossCap` instead.
- `Perks.offer` ends by resetting `Perks.fx` and `G.perkClosing`. That reset was once swallowed by a mid-line `//` comment (fixed in 2af20dc); keep inline comments as `/* */`.
- `Mega.add` shows its own message, so `Perks.take` does not show "Perk: …" for a mega.
- In god mode (`G.god`), `Main.update` sets `G.mega.charge = MEGA_MAX` every frame, so the bar is always full.

## Tuning — where its numbers live

- `js/config.js` (overridden by js/tuning.js; see notebook-config.md): the CFG keys listed above. `MEGA_DECK` (price base 2, step 2, unique drop 0.35), `MEGA_MAX` 120, `BALANCE.freeCap` 4, `BALANCE.bodySq` 6.
- In code, not CFG: each perk's multipliers inside its `apply()` (and repeated in its `desc`, `card`, `PERK_ICONS` strip and `Perks.total`; keep all four in step). `Perks.SLOTS` = 4. Bullet Storm's 3 s and 2× rate. Deep Freeze's 5 s freeze. Lightning's 900 px opening shock.

## Testing

- `python3 tools/devserver.py`, then http://localhost:8765/. Add `?god=1` for god mode (or press G in a run): the mega bar stays full, J opens the perk shop, keys 1-9 load `GOD_PRESETS`, H hops into the nearest dungeon, V toggles mortal.
- `?deck=1` starts on the saucer deck, and winning it opens the shop. `?bot=1&brain=scripted` lets the autopilot buy perks (`Bot` scripted shop logic).
- Console: `G.ghosts = 30`, `Perks.offer()`, `Mega.add('lich')`, `Mega.activate('lightning')`, `G.mega.charge = CFG.MEGA_MAX`, `Mega.use()`, `Perks.priceAt('body', 5)`.
- To see the one-time tip again: `Settings.data.perkTip = false`.

## Adding a perk

1. Add an entry to `PERKS`: `name, key, color, desc` (the full story; the bot reads it), `card` (one or two lines), and `apply()`. Add `always: true` only if it must always be on the shelf.
2. If it needs a new multiplier, add the key with its neutral value to `Perks.defaults()`. Old saves pick it up through the merge in `Save`. Then read `G.mods.<key>` where it takes effect.
3. Add a `PERK_ICONS` entry: a badge glyph that exists in `Icons.draw`, plus a stat strip (an array, or a function for live values).
4. Add a `case` to `Perks.total` (the workbench PERKS tab line).
5. If it changes DPS, check `Bot.perkDps` and the bot's perk ranking in js/bot.js. Update the README feature list.

## Adding a mega

1. Add an entry to `MEGAS`: `name, key, color, desc, card`. The loop after `Mega` creates its shop card `mega_<id>` automatically.
2. Add a method `Mega.<id>()`, which `Mega.use` calls. Put any timed state on `G`, reset it in `G.start`, tick it from `Mega.update` (or `Main.update`), and clear it on death.
3. If its kills should not charge the next mega, add a guard in `Mega.charge`. If bosses should resist it, multiply by `BOSSES[type].megaResist`.
4. Add a CFG block for its numbers. Add a screen title in render.js if it is timed. Add HUD and player drawing as needed.
5. Check the bench MEGAS tab (`render-bench-perks.js` reads `Object.keys(MEGAS)`) and the bot's mega question.

## Change log hooks
Update this notebook in the same commit as any change to the system's behaviour.
