# __TITLE__

**Slug:** `__SLUG__` · **Template:** iso voxel — Lonely Cat's isometric pastel voxel world, as a springboard · **Created:** __DATE__

## Brief

__BRIEF__

## What this template starts from

_This game starts as a copy of **Lonely cat**: its code, its look and its feel. Keep what makes that look and engine work (named below) and reshape everything else to the brief above: the fantasy, the player, the rules, the enemies, the goals and the name. The design below is Lonely cat's, for reference; the planner writes this game's own Design under it._

**The fantasy.** You are a small, round, very hungry stray cat in a cute voxel neighbourhood, seen from an isometric
angle. All you want is a pat and a snack. You wander the lanes, charm kids and grown-ups, pounce on birds and squirrels
for show-off points, dodge cars, and, when the sun goes down, keep out of the witches' sight before the full moon rises.

**Look.** Block-built world drawn in code: a grid of iso tiles with cube houses, cube trees, hedges, a road with
a crossing, a park and a little graveyard-ish corner where the coven meets. Everything is pastel and rounded in spirit,
with big-eyed block characters. Pools of `Draw.sprite` cubes (top, left, right faces shaded) so the whole map is stamps.

**Core loop.** Move with WASD / arrows / joystick on the iso grid (screen-up is north-east). Each person on the map is a
Friend standing or strolling about. Walk up to one and hold `Space` (or the touch MEOW button) to meow: a heart meter fills
over about a second, then they pat you (Affection +) and often hand over food (Belly +). Birds and squirrels flit about
the park; `Space` near one pounces for a short lunge: catch it for bonus points and a combo multiplier, miss and you
are winded briefly. Belly drains slowly all the time; Affection is the score engine. Pats and catches in quick
succession chain a combo (x1 to x5) that decays when you idle.

**A run is a string of days.** Each day is about 70 s of sun. At the end of the day the sky turns, the moon rises and the
night lasts about 35 s (the full moon). Survive the night and a new, harder day begins (day counter = your bragging
number). Score counts everything you earn, with a bonus for each night survived.

**How a run ends.**
- Run over by a car: you have 3 lives (nine would be too long; the HUD shows paws). Each hit costs a paw and flings you
  back with a short invulnerable blink. Zero paws ends the run.
- Starving: Belly reaching 0 ends the run ("too hungry to go on").
- Sacrificed: at night, if a witch catches you (touch, or she holds you in her lantern beam for 1.5 s) you are carried
  to the circle and the run ends at once, whatever your paws. Darkness is the rule, light is safety.

**Collecting.** Hearts (Affection points) from friends; food: fish, milk, a sausage, a bowl set out by a kind adult
(Belly refill sizes differ); catches (bird 50, squirrel 100 base, times combo); little moon charms scattered on safe
rooftops-edge spots that, when kept, make a night's witches slow for 6 s (one held at a time). A porch cushion in
each lane is a safe spot at night (a witch cannot grab a cat curled on it, but it only holds 8 s then she notices).
Best score and settings are saved.

**Friends (not enemies).** Kids (quick to pat, small reward, wander fast), adults (slow, give food, but sometimes
shoo you if you meow twice in a row without a pat), the old lady with the bowl (best food, stays at her porch),
the baker with sausages (stationary, appears day 2+). Each has a mood bubble above the head so the player reads the
reward before they commit.

**Threats.**
- Cars: drive along the road lanes on a loop, horn-honk and flash a short warning 0.6 s before entering the crossing;
  cheap, readable, rhythm-based. Day 3+ adds a fast delivery van, day 5+ adds two lanes at once.
- Witches: three coven members, only walking by night. Each carries a lantern whose cone (a lit triangle on the tiles)
  sweeps ahead; being inside it fills a visible "spotted" ring and they then chase at cat-speed minus a bit. Their
  count and speed grow with the day (1 on night 1, 2 on night 3, 3 on night 5), and they cast a purple glow so you see them
  coming. Hiding on a porch cushion or behind a hedge breaks the lantern line.
- Hunger itself is the quiet third threat: all the above trades time for food.

**Levels / waves.** One neighbourhood map (a single screen of about 20 x 20 tiles, camera follows the cat) so scope is small.
Difficulty is the day number: more cars and vans, faster cars, more witches, fewer friends who linger, shorter hunger
tank. Day 1 is a calm tutorial: no vans, one witch at night, slow cars.

**SFX keys.** `meow`, `purr` (a pat lands), `pickup` (food/charm), `pounce`, `catch`, `miss`, `combo`, `honk` (car warning),
`splat` (hit by car, comedic not gory: a bonk), `hurt`, `witch` (spotted), `cackle` (caught/die), `moon` (night
begins), `dawn` (day ends safely), `start`, `tab`, `die`.

**Music themes.** `title` (gentle plinky lullaby), `play` (bouncy day-time walk), `night` (slow, minor, wobbly theremin-ish
tension), `gameover` (sad but sweet).

**What sells it.** Hook: "cutest stray cat in a voxel town; pet the kids, pounce on birds, don't get sacrificed." The
screenshot: the cat mid-meow with a heart bubble over a kid, a bird fleeing, the purple witch cone creeping on a moonlit
block street. Price: free to play in the browser, quick runs of 3 to 6 minutes, score chasing; ad-light if ever monetised.

## Design

_The design doc the planner writes for THIS game, from the brief: the fantasy, the core loop, what makes a run end, what the player collects or builds, the enemies and how each one threatens, the levels or waves, the sounds and music the game needs, what sells it._

## Acceptance criteria

_Checkable claims the reviewer tests the build against. One per line, each true or false._

- [ ] The game loads with no console errors and a 60 s soak with `?soak=60&fast=4` reports `ok: true`.
- [ ] Title, play and game over screens exist and are reachable with the keyboard and by touch.
- [ ] The score, the best score and the settings survive a reload.
- [ ] Nothing of Lonely cat's own story remains (its name, its characters, its goals) unless the brief asks for it.

## Tasks

_The build plan: ordered tasks, each small enough for one agent turn, each with its own acceptance line._

## Log

_One line per build or review round: date, what was done, what the soak said._
