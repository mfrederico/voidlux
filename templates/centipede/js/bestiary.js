// Creature data, edited in the bestiary editor (http://localhost:8765/editor/bestiary/ via tools/devserver.py).
// resist: per row (a family default or one creature), a multiplier per element: 0 immune, 0.5 suppressed, 1 normal,
// 1.5 weak, 2 very weak (any value from 0 to 5); null = use the family default.
// stats: per creature, only what differs from js/config.js: move speed (px/s) + speedR per region, cd (s between
// attacks), range (px), shotSpeed (px/s), hold (a parasite's s jammed), tempo (a boss: x every ability cooldown),
// spawn (x how often it appears: 0 never, 1 as the game has it).
// The editor rewrites this file on every save.
const BESTIARY = {
  "resist": {
    "boss-clowncar": {"fire": 1.5, "poison": 0.5, "ice": 0, "electric": 0, "note": "A car full of fuel; sealed in, gas barely gets in."},
    "boss-crab": {"fire": 1.5, "poison": null, "ice": 0.5, "electric": 1.5, "note": "Shell resists cold; wet, so it conducts."},
    "boss-overlord": {"fire": null, "poison": 0.5, "ice": null, "electric": 1.5, "note": "Alien biology; the disc is machinery."},
    "boss-serpent": {"fire": 1.5, "poison": 0.5, "ice": 1, "electric": null, "note": "A giant snake: same tilt as the family."},
    "boss-skull": {"fire": 0.5, "poison": 0.5, "ice": 1.5, "electric": 1, "note": "Breathes fire, all bone."},
    "bug-beetle": {"fire": 0.5, "poison": null, "ice": 0, "electric": null, "note": "Hard shell: does not care about cold."},
    "bug-blob": {"fire": 0, "poison": null, "ice": 2, "electric": null, "note": "Lava: fire feeds it, ice cracks it."},
    "bug-brainling": {"fire": null, "poison": null, "ice": null, "electric": 1.5, "note": "Big exposed brain."},
    "bug-elephant": {"fire": null, "poison": null, "ice": 0.5, "electric": null, "note": "Too big to freeze solid."},
    "bug-iguana": {"fire": 0, "poison": 1, "ice": 1.5, "electric": null, "note": "A reptile: slows in the cold like the snakes."},
    "bug-roach": {"fire": null, "poison": null, "ice": 0, "electric": null, "note": "Cockroaches survive anything cold."},
    "bug-saucer": {"fire": null, "poison": 0, "ice": null, "electric": 2, "note": "A machine: no blood to poison, electrics short out."},
    "bug-skull": {"fire": 0, "poison": 0, "ice": null, "electric": null, "note": "Made of fire (already fireproof today); bone, nothing to poison."},
    "bug-stinkbug": {"fire": null, "poison": null, "ice": 1.5, "electric": null},
    "family-boss": {"fire": 1, "poison": 1, "ice": 1, "electric": 1, "note": "Proposal: bosses only get gentle tilts (½× or 1.5×), never immune, so no build is stuck."},
    "family-bug": {"fire": 1.5, "poison": 1.5, "ice": 1, "electric": 1, "note": "Proposal: venom works great against bugs."},
    "family-parasite": {"fire": 1.5, "poison": 1.5, "ice": 1.5, "electric": 1.5, "note": "Proposal: venomous themselves."},
    "family-snake": {"fire": 1, "poison": 0.5, "ice": 1.5, "electric": 1, "note": "Proposal: cold-blooded, sluggish in the cold; shrug off venom."},
    "level-1": {"fire": 1.5, "poison": 1.5, "ice": 1.5, "electric": 1.5},
    "level-2": {"fire": 1.5, "poison": null, "ice": null, "electric": null},
    "level-3": {"fire": 1, "poison": 1, "ice": 1.5, "electric": null},
    "level-4": {"fire": null, "poison": 1.5, "ice": null, "electric": null},
    "level-5": {"fire": null, "poison": null, "ice": null, "electric": 1.5},
    "snake-balloon": {"fire": 2, "poison": 0, "ice": 1, "electric": 0.5, "note": "Rubber: fire pops it, poison does nothing."},
    "snake-bone": {"fire": null, "poison": 0, "ice": 1, "electric": null, "note": "A skeleton: nothing to poison, not cold-blooded any more."},
    "snake-python": {"fire": null, "poison": null, "ice": 2, "electric": null, "note": "Tropical: cold hits it hardest."},
    "summon-chicken": {"fire": 1, "poison": 1, "ice": null, "electric": null},
    "summon-kamiclown": {"fire": 0.5, "poison": 1, "ice": null, "electric": 0.5},
    "summon-kamighost": {"fire": null, "poison": 0, "ice": 0, "electric": 1.5, "note": "A ghost: no body to poison."}
  },
  "stats": {

  }
};
