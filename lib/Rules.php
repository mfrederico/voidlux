<?php
/**
 * Rules — a game's events: what happens to its sprites and what the game does about it, as rules the producer writes on the
 * sprite page's Events tab and kit/js/kit-events.js runs (its header is the format). Two files in the game's assets/:
 *   events-vocab.json  the builder's: the events the game emits (with their fields), the actions it implements (with their
 *                      params) and the subjects (sprite or model keys, with groups). Written by the wiring run, never here.
 *   events.json        the producer's rules, checked here against the vocabulary before they are written.
 * Rules from words: an art job reads the vocabulary and the producer's description and proposes rules; the producer adds them.
 */
namespace app;

class Rules {
    public const OPS = ['is', 'not', '>', '<', '>=', '<=', 'has', 'chance'];

    private static function file(string $slug, string $f): string { return GameStore::dir($slug) . "/assets/$f"; }
    public static function vocab(string $slug): ?array {
        $p = self::file($slug, 'events-vocab.json'); if (!is_file($p)) return null;
        $v = json_decode((string) file_get_contents($p), true); if (!is_array($v)) throw new \RuntimeException("assets/events-vocab.json in {$slug} is not JSON: the builder must fix it");
        return ['events' => (array) ($v['events'] ?? []), 'actions' => (array) ($v['actions'] ?? []), 'subjects' => (array) ($v['subjects'] ?? [])];
    }
    public static function read(string $slug): array {
        $p = self::file($slug, 'events.json'); if (!is_file($p)) return ['version' => 1, 'rules' => []];
        $j = json_decode((string) file_get_contents($p), true); if (!is_array($j)) throw new \RuntimeException("assets/events.json in {$slug} is not JSON; fix or remove it");
        return ['version' => 1, 'rules' => array_values((array) ($j['rules'] ?? []))];
    }
    /** The rules, cleaned and checked against the vocabulary; the first problem is thrown, naming the rule. */
    public static function clean(array $rules, array $v): array {
        $groups = []; foreach ($v['subjects'] as $s) foreach ((array) ($s['groups'] ?? []) as $g) $groups[(string) $g] = true;
        $out = []; $ids = [];
        foreach (array_values($rules) as $n => $r) {
            if (!is_array($r)) throw new \InvalidArgumentException('rule ' . ($n + 1) . ' is not a rule');
            $id = preg_match('/^[a-z0-9_-]{1,40}$/i', (string) ($r['id'] ?? '')) ? (string) $r['id'] : 'r' . substr(sha1(json_encode($r) . $n), 0, 8);
            if (isset($ids[$id])) $id .= '-' . ($n + 1); $ids[$id] = true; $at = "rule " . ($n + 1) . " ({$id})";
            $who = (string) ($r['who'] ?? ''); $on = (string) ($r['on'] ?? '');
            if (!($who === '*' || isset($v['subjects'][$who]) || (str_starts_with($who, 'group:') && isset($groups[substr($who, 6)])))) throw new \InvalidArgumentException("$at: \"{$who}\" is not a subject or group the game has");
            $ev = $v['events'][$on] ?? null; if (!$ev) throw new \InvalidArgumentException("$at: \"{$on}\" is not an event the game sends");
            $if = [];
            foreach ((array) ($r['if'] ?? []) as $c) {
                $op = (string) ($c['op'] ?? ''); if (!in_array($op, self::OPS, true)) throw new \InvalidArgumentException("$at: unknown condition \"{$op}\"");
                if ($op === 'chance') { $p = (float) ($c['value'] ?? 1); if ($p <= 0 || $p > 1) throw new \InvalidArgumentException("$at: a chance is between 0 and 1"); $if[] = ['op' => 'chance', 'value' => $p]; continue; }
                $f = (string) ($c['field'] ?? ''); if (!isset(((array) ($ev['fields'] ?? []))[$f])) throw new \InvalidArgumentException("$at: the event \"{$on}\" has no field \"{$f}\"");
                $val = $c['value'] ?? ''; if (!is_scalar($val)) throw new \InvalidArgumentException("$at: a condition's value is a word or a number");
                $if[] = ['field' => $f, 'op' => $op, 'value' => is_string($val) ? mb_substr($val, 0, 80) : $val];
            }
            $do = [];
            foreach ((array) ($r['do'] ?? []) as $a) {
                $lim = [];
                foreach (['max' => [1, 1000], 'cooldown' => [0, 3600]] as $k => [$lo, $hi]) if (isset($a[$k]) && $a[$k] !== '' && $a[$k] !== null && (float) $a[$k] > 0) $lim[$k] = max($lo, min($hi, $k === 'max' ? (int) $a[$k] : (float) $a[$k]));
                if (!empty($a['once'])) $lim['once'] = true; if (isset($a['chance']) && $a['chance'] !== '' && (float) $a['chance'] < 1) { $p = (float) $a['chance']; if ($p <= 0) throw new \InvalidArgumentException("$at: an action's chance is above 0"); $lim['chance'] = $p; }
                $args = []; foreach ((array) ($a['args'] ?? []) as $k => $x) { if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,30}$/', (string) $k) || !is_scalar($x)) throw new \InvalidArgumentException("$at: an argument is a name and a word or number"); $args[$k] = $x; }
                if (!empty($a['emit'])) { $e = (string) $a['emit']; if (!isset($v['events'][$e])) throw new \InvalidArgumentException("$at: it emits \"{$e}\", which is not an event the game has"); $do[] = ['emit' => $e] + ($args ? ['args' => $args] : []) + $lim; continue; }
                $fn = (string) ($a['fn'] ?? ''); $def = $v['actions'][$fn] ?? null; if ($def === null) throw new \InvalidArgumentException("$at: \"{$fn}\" is not an action the game can do");
                foreach (array_keys($args) as $k) if (!isset(((array) ($def['params'] ?? []))[$k])) throw new \InvalidArgumentException("$at: the action {$fn} has no setting \"{$k}\"");
                $do[] = ['fn' => $fn] + ($args ? ['args' => $args] : []) + $lim;
            }
            if (!$do) throw new \InvalidArgumentException("$at: it does nothing (add an action or an event to fire)");
            $out[] = ['id' => $id, 'who' => $who, 'on' => $on] + ($if ? ['if' => $if] : []) + ['do' => $do] + (!empty($r['off']) ? ['off' => true] : []) + (trim((string) ($r['note'] ?? '')) !== '' ? ['note' => mb_substr(trim((string) $r['note']), 0, 300)] : []);
        }
        return $out;
    }
    public static function save(string $slug, array $rules): array {
        $v = self::vocab($slug); if (!$v) throw new \InvalidArgumentException('the game declares no events yet: wire events into the game first');
        $clean = self::clean($rules, $v);
        GameStore::writeAtomic(self::file($slug, 'events.json'), json_encode(['version' => 1, 'rules' => $clean], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        GameStore::git(GameStore::dir($slug), ['add', 'assets/events.json']);
        return $clean;
    }

    /** Everything the Events tab needs: the vocabulary, the rules (each marked if it no longer fits the vocabulary), whether the
     *  game loads the runtime, and the proposals from words. */
    public static function status(string $slug): array {
        $d = GameStore::dir($slug); $v = self::vocab($slug); $rules = self::read($slug)['rules'];
        if ($v) foreach ($rules as &$r) { try { self::clean([$r], $v); } catch (\InvalidArgumentException $e) { $r['broken'] = preg_replace('/^rule 1 \([^)]*\): /', '', $e->getMessage()); } } unset($r);
        $page = is_file("$d/index.html") ? (string) file_get_contents("$d/index.html") : '';
        $emits = []; foreach (glob("$d/js/*.js") ?: [] as $f) if (preg_match_all('/Events\.emit\(\s*[\'"]([A-Za-z0-9_:-]+)[\'"]/', (string) file_get_contents($f), $m)) $emits = array_merge($emits, $m[1]);
        return ['vocab' => $v, 'rules' => $rules, 'wired' => str_contains($page, 'kit-events.js'), 'emitted' => array_values(array_unique($emits)), 'proposals' => self::proposals($slug)];
    }

    // ---- rules from words ----
    private static function propFile(string $slug): string { return GameStore::studioDir($slug) . '/rule-requests.json'; }
    public static function describe(string $slug, string $text, int $memberId): array {
        $text = trim(mb_substr($text, 0, 1200)); if ($text === '') throw new \InvalidArgumentException('describe the rule');
        if (!self::vocab($slug)) throw new \InvalidArgumentException('the game declares no events yet: wire events into the game first');
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3)); $dir = GameStore::sketchDir($slug, $id); mkdir($dir, 0775, true);
        GameStore::writeAtomic("$dir/request.json", json_encode(['id' => $id, 'note' => $text, 'key' => 'rules', 'maker' => 'rules', 'rules' => true, 'by' => $memberId, 'at' => time()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $all = is_file(self::propFile($slug)) ? (json_decode((string) file_get_contents(self::propFile($slug)), true) ?: []) : [];
        array_unshift($all, ['id' => $id, 'text' => $text, 'at' => time(), 'state' => 'reading']); GameStore::writeAtomic(self::propFile($slug), json_encode(array_slice($all, 0, 20), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $p = "EVENT RULES FROM WORDS. The producer describes how things in this game should react, in their words: \"{$text}\"\n"
           . "Read ../../kit/js/kit-events.js (its header is the rule format and what each part means), assets/events-vocab.json (the only events, fields, actions, settings and subjects there are) and assets/events.json (the rules there already). Write the rules that do what the producer said, using ONLY names from the vocabulary. A chain of reactions is a rule whose action emits an event that another rule reacts to. Limits (max, once, cooldown, chance) are how \"at most\", \"only the first time\", \"not more than every\" and \"sometimes\" are said.\n"
           . "If what they ask needs an event, field or action the vocabulary does not have, do not invent it and do not bend another one to fit: name it under \"missing\", one line each, as what the game would have to add.\n"
           . "Write sketches/{$id}/rules.json as {\"rules\": [ …rules in the events.json format, new ids… ], \"explain\": \"<what the rules do, in the producer's words, two or three sentences>\", \"missing\": [\"<what the game would need>\", …]}, check it parses with `node -e`, then sketches/{$id}/result.json as {\"ok\": true, \"files\": [], \"note\": \"<how many rules, in one line>\"}. Change no other file. Do not run a soak and do not commit. Reply with one line.";
        GameStore::emit($slug, 'prompt', ['text' => "Event rules: {$text}", 'mode' => 'art', 'by' => GameStore::memberName($memberId), 'job' => $id]);
        $r = GameStore::startArt($slug, $p, $memberId);
        return ['id' => $id, 'queued' => !empty($r['queued'])];
    }
    /** The proposals: reading, proposed (rules checked against the vocabulary, or the reason they do not fit), added, dismissed. */
    public static function proposals(string $slug): array {
        $all = is_file(self::propFile($slug)) ? (json_decode((string) file_get_contents(self::propFile($slug)), true) ?: []) : []; $v = self::vocab($slug); $out = [];
        foreach ($all as $q) {
            if (in_array($q['state'], ['added', 'dismissed'], true)) { if (time() - (int) $q['at'] < 86400) $out[] = $q; continue; }
            try { $s = GameStore::sketchStatus($slug, (string) $q['id']); } catch (\InvalidArgumentException $e) { continue; }
            if (empty($s['result'])) { $q['state'] = 'reading'; $out[] = $q; continue; }
            if (empty($s['result']['ok'])) { $q['state'] = 'failed'; $q['error'] = (string) ($s['result']['error'] ?? 'the artist could not write rules for it'); $out[] = $q; continue; }
            $j = json_decode((string) @file_get_contents(GameStore::sketchDir($slug, (string) $q['id']) . '/rules.json'), true);
            if (!is_array($j) || !is_array($j['rules'] ?? null)) { $q['state'] = 'failed'; $q['error'] = 'its rules.json is missing or not JSON'; $out[] = $q; continue; }
            $q['state'] = 'proposed'; $q['explain'] = mb_substr((string) ($j['explain'] ?? ''), 0, 600); $q['missing'] = array_slice(array_map(fn($x) => mb_substr((string) $x, 0, 200), (array) ($j['missing'] ?? [])), 0, 8);
            try { $q['rules'] = $v ? self::clean($j['rules'], $v) : []; } catch (\InvalidArgumentException $e) { $q['rules'] = []; $q['error'] = 'the proposed rules do not fit the game: ' . $e->getMessage(); }
            $out[] = $q;
        }
        return $out;
    }
    /** A proposal's rules join the game's rules (or it is dismissed). */
    public static function settleProposal(string $slug, string $id, bool $add): array {
        $all = is_file(self::propFile($slug)) ? (json_decode((string) file_get_contents(self::propFile($slug)), true) ?: []) : [];
        $p = null; foreach (self::proposals($slug) as $q) if ($q['id'] === $id) $p = $q; if (!$p) throw new \InvalidArgumentException('no such proposal');
        $added = [];
        if ($add) { if ($p['state'] !== 'proposed' || !$p['rules']) throw new \InvalidArgumentException('that proposal has no rules to add' . (!empty($p['error']) ? ': ' . $p['error'] : ''));
            $have = self::read($slug)['rules']; $ids = array_column($have, 'id');
            foreach ($p['rules'] as $r) { while (in_array($r['id'], $ids, true)) $r['id'] .= 'b'; $ids[] = $r['id']; $have[] = $r; $added[] = $r['id']; }
            self::save($slug, $have); }
        foreach ($all as &$q) if ($q['id'] === $id) $q['state'] = $add ? 'added' : 'dismissed'; unset($q);
        GameStore::writeAtomic(self::propFile($slug), json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $added;
    }

    // ---- the builder's part ----
    /** One builder run: the runtime in, the vocabulary written, events emitted where they happen, actions implemented, and the
     *  reactions the code hard-wires today moved into rules where a rule reproduces them exactly. */
    public static function wire(string $slug, int $memberId, string $extra = ''): array {
        $st = self::status($slug);
        $keys = array_keys((array) (GameStore::readSprites($slug)['sprites'] ?? [])); $cast = is_file(Cast::path($slug)) ? Cast::read($slug)['items'] : [];
        $p = "Wire the game's events in, following \"Events\" in ../../kit/AGENTS.md and the header of ../../kit/js/kit-events.js exactly. " . ($st['wired'] ? 'The runtime is in already: extend the vocabulary and the emits as below, keeping what is there. ' : '')
           . "The producer will write rules on the sprite page's Events tab using only what you declare, so the vocabulary is the game's whole vocabulary of reactions:\n"
           . "- subjects: every kind of thing that can be hit, touched, spawn or die (the player, each enemy and boss type, props, pickups), keyed by its sprite or model key where it has one" . ($cast ? ' (the 3D cast\'s keys and groups are in assets/models.json: use them)' : ($keys ? ' (' . implode(', ', array_slice($keys, 0, 40)) . ')' : '')) . ", each with its groups (player, enemy, boss, prop, pickup…).\n"
           . "- events: at least spawn, hit, die, touch (the player touching it) and pickup where they apply, each with the fields the code knows at that moment (hit: type, amount, source; …). Emit each with Events.emit(name, key, fields, theObject) at the one place in the code where it happens.\n"
           . "- actions: what the game can already do to a thing (set it burning, freeze, poison, knock back, heal, an effect burst, a sound, spawn something, award points, drop a pickup…), each wrapping the game's own function, with its settings and defaults; mark lasting the ones with a duration and call end() when they are over.\n"
           . "Then move the reactions the code hard-wires today (a fire hit adding burn, and the like) into assets/events.json as rules, but ONLY where the rule reproduces the behaviour exactly (same numbers, same order); leave the rest in code and list them in the commit message.\n"
           . ($extra !== '' ? "Also: {$extra}\n" : '')
           . "Check: node --check every file you touched; run ../../studio/bin/soak 60 4 before you start and again at the end, and compare (the same deaths, kills and score within noise; no new errors); fix anything that changed. Commit.";
        return GameStore::askBuilder($slug, $p, $memberId, true);
    }
    /** The producer wants an action, event or field the vocabulary does not have: the builder adds it. */
    public static function askFor(string $slug, string $what, int $memberId): array {
        $what = trim(mb_substr($what, 0, 600)); if ($what === '') throw new \InvalidArgumentException('say what the game should be able to do');
        return GameStore::askBuilder($slug, "Add to the game's events (\"Events\" in ../../kit/AGENTS.md): {$what}. Implement it, declare it in assets/events-vocab.json with its fields or settings and their defaults, emit or wire it where it belongs, node --check, run ../../studio/bin/soak 60 4, and commit. Do not change the producer's rules in assets/events.json.", $memberId, true);
    }
}
