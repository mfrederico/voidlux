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
    /** The engine's own actions (kit-events.js), there in every game: counters, per thing or for the whole game. */
    public const BUILTIN = ['Count' => ['name', 'by', 'on'], 'SetCount' => ['name', 'value', 'on']];
    public const COUNTER = '/^[A-Za-z][A-Za-z0-9_-]{0,30}$/';

    private static function file(string $slug, string $f): string { return GameStore::dir($slug) . "/assets/$f"; }
    public static function vocab(string $slug): ?array {
        $p = self::file($slug, 'events-vocab.json'); if (!is_file($p)) return null;
        $v = json_decode((string) file_get_contents($p), true); if (!is_array($v)) throw new \RuntimeException("assets/events-vocab.json in {$slug} is not JSON: the builder must fix it");
        return ['events' => (array) ($v['events'] ?? []), 'actions' => (array) ($v['actions'] ?? []), 'subjects' => (array) ($v['subjects'] ?? []), 'state' => (array) ($v['state'] ?? []), 'targets' => (array) ($v['targets'] ?? [])];
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
                $val = $c['value'] ?? ''; if (!is_scalar($val)) throw new \InvalidArgumentException("$at: a condition's value is a word or a number"); $val = is_string($val) ? mb_substr($val, 0, 80) : $val;
                if (isset($c['state'])) { $s = (string) $c['state']; if (!isset($v['state'][$s])) throw new \InvalidArgumentException("$at: \"{$s}\" is not a state the game can read"); $if[] = ['state' => $s, 'op' => $op, 'value' => $val]; continue; }
                if (isset($c['count'])) { $n = (string) $c['count']; $on2 = (string) ($c['on'] ?? 'self'); if (!preg_match(self::COUNTER, $n)) throw new \InvalidArgumentException("$at: a counter's name is letters, digits, - and _"); if (!in_array($on2, ['self', 'game'], true)) throw new \InvalidArgumentException("$at: a counter is on self or game"); $if[] = ['count' => $n, 'on' => $on2, 'op' => $op, 'value' => $val]; continue; }
                $f = (string) ($c['field'] ?? ''); if (!isset(((array) ($ev['fields'] ?? []))[$f])) throw new \InvalidArgumentException("$at: the event \"{$on}\" has no field \"{$f}\"");
                $if[] = ['field' => $f, 'op' => $op, 'value' => $val];
            }
            $do = [];
            foreach ((array) ($r['do'] ?? []) as $a) {
                $lim = [];
                foreach (['max' => [1, 1000], 'cooldown' => [0, 3600]] as $k => [$lo, $hi]) if (isset($a[$k]) && $a[$k] !== '' && $a[$k] !== null && (float) $a[$k] > 0) $lim[$k] = max($lo, min($hi, $k === 'max' ? (int) $a[$k] : (float) $a[$k]));
                if (isset($a['delay']) && (float) $a['delay'] > 0) $lim['delay'] = min(600, round((float) $a['delay'], 2));
                if (!empty($a['to'])) { $to = (array) $a['to']; $tn = (string) ($to['target'] ?? ''); $td = $v['targets'][$tn] ?? null; if ($td === null) throw new \InvalidArgumentException("$at: \"{$tn}\" is not a target the game can find");
                    $ta = []; foreach ((array) ($to['args'] ?? []) as $k => $x) { if (!isset(((array) ($td['params'] ?? []))[$k]) || !is_scalar($x)) throw new \InvalidArgumentException("$at: the target {$tn} has no setting \"{$k}\""); $ta[$k] = $x; }
                    $tw = (string) ($to['who'] ?? ''); if ($tw !== '' && !($tw === '*' || isset($v['subjects'][$tw]) || (str_starts_with($tw, 'group:') && isset($groups[substr($tw, 6)])))) throw new \InvalidArgumentException("$at: \"{$tw}\" is not a subject or group to aim at");
                    $lim['to'] = ['target' => $tn] + ($ta ? ['args' => $ta] : []) + ($tw !== '' ? ['who' => $tw] : []); }
                if (!empty($a['once'])) $lim['once'] = true; if (isset($a['chance']) && $a['chance'] !== '' && (float) $a['chance'] < 1) { $p = (float) $a['chance']; if ($p <= 0) throw new \InvalidArgumentException("$at: an action's chance is above 0"); $lim['chance'] = $p; }
                $args = []; foreach ((array) ($a['args'] ?? []) as $k => $x) { if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,30}$/', (string) $k) || !is_scalar($x)) throw new \InvalidArgumentException("$at: an argument is a name and a word or number"); $args[$k] = $x; }
                if (!empty($a['emit'])) { $e = (string) $a['emit']; if (!isset($v['events'][$e])) throw new \InvalidArgumentException("$at: it emits \"{$e}\", which is not an event the game has"); $do[] = ['emit' => $e] + ($args ? ['args' => $args] : []) + $lim; continue; }
                $fn = (string) ($a['fn'] ?? '');
                if (isset(self::BUILTIN[$fn])) { $nm = (string) ($args['name'] ?? ''); if (!preg_match(self::COUNTER, $nm)) throw new \InvalidArgumentException("$at: {$fn} needs a counter name (letters, digits, - and _)"); $on2 = (string) ($args['on'] ?? 'self'); if (!in_array($on2, ['self', 'game'], true)) throw new \InvalidArgumentException("$at: a counter is on self or game");
                    foreach (array_keys($args) as $k) if (!in_array($k, self::BUILTIN[$fn], true)) throw new \InvalidArgumentException("$at: {$fn} has no setting \"{$k}\"");
                    $do[] = ['fn' => $fn, 'args' => $args] + $lim; continue; }
                $def = $v['actions'][$fn] ?? null; if ($def === null) throw new \InvalidArgumentException("$at: \"{$fn}\" is not an action the game can do");
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
        return ['builtin' => self::builtin($slug), 'vocab' => $v, 'rules' => $rules, 'wired' => str_contains($page, 'kit-events.js'), 'emitted' => array_values(array_unique($emits)), 'proposals' => self::proposals($slug)];
    }

    // ---- rules from words ----
    private static function propFile(string $slug): string { return GameStore::studioDir($slug) . '/rule-requests.json'; }
    public static function describe(string $slug, string $text, int $memberId, string $extra = '', string $after = '', string $about = ''): array {
        $text = trim(mb_substr($text, 0, 1200)); if ($text === '') throw new \InvalidArgumentException('describe the rule');
        $v = self::vocab($slug); if (!$v) throw new \InvalidArgumentException('the game declares no events yet: wire events into the game first');
        if ($about === '*') $about = ''; // "everything" is no context
        if ($about !== '' && !(isset($v['subjects'][$about]) || (str_starts_with($about, 'group:') && array_filter($v['subjects'], fn($s) => in_array(substr($about, 6), (array) ($s['groups'] ?? []), true))))) throw new \InvalidArgumentException("\"{$about}\" is not a subject or group the game has");
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3)); $dir = GameStore::sketchDir($slug, $id); mkdir($dir, 0775, true);
        GameStore::writeAtomic("$dir/request.json", json_encode(['id' => $id, 'note' => $text, 'key' => 'rules', 'maker' => 'rules', 'rules' => true, 'by' => $memberId, 'at' => time()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $all = is_file(self::propFile($slug)) ? (json_decode((string) file_get_contents(self::propFile($slug)), true) ?: []) : [];
        array_unshift($all, ['id' => $id, 'text' => $text, 'at' => time(), 'state' => 'reading'] + ($after !== '' ? ['after' => $after] : []) + ($about !== '' ? ['about' => $about] : [])); GameStore::writeAtomic(self::propFile($slug), json_encode(array_slice($all, 0, 20), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $p = "EVENT RULES FROM WORDS. The producer describes a concept, how things in this game should behave, in their words: \"{$text}\"\n" . ($extra !== '' ? "{$extra}\n" : '')
           . ($about !== '' ? "They wrote it with " . (str_starts_with($about, 'group:') ? 'the group \"' . substr($about, 6) . '\" (every ' . substr($about, 6) . ')' : "\"{$about}\" ({$v['subjects'][$about]['about']})") . " selected in the editor: what they say happens to \"it\", \"this\" or to no one in particular happens to that, so the rules' who is \"{$about}\" unless their words clearly name something else.\n" : '')
           . "Read ../../kit/js/kit-events.js (its header is the whole rule format), assets/events-vocab.json (the only events, fields, states, targets, actions, settings and subjects there are) and assets/events.json (the rules there already, so yours fit beside them and do not repeat them). Turn the concept into rules, using everything the format offers: conditions on the event's fields, on the game's state (\"state\") and on counters (\"count\", on self or game); actions on the thing itself or, with \"to\", on what a target finds (nearby things, the player, the source…, filtered with who); the built-in Count and SetCount for anything that must be counted or remembered (\"the third time\", \"only 3 times a game\", \"until it is hit again\"); delay for \"after a while\"; max, once, cooldown and chance for \"at most\", \"only the first time\", \"not more than every\" and \"sometimes\"; emit for chains of reactions.\n"
           . "A rule only fires if the game really emits its event for that subject, so CHECK IN THE CODE: for each subject and event your rules use, follow where that thing is created and where it ends or is hit (grep Events.emit and the game's emit helpers, e.g. EV.die / EV.hit in js/events.js) and confirm the event is sent on every way it can happen (a thing removed by setting dead itself, not through the usual kill, sends no die). If it is not sent, that is a missing piece of kind \"emit\": say where it must be emitted.\n"
           . "If the concept needs something the vocabulary does not have, do not invent it and do not bend another one to fit: list it under \"missing\", each as an exact piece for the builder to add: {\"kind\": \"action\" | \"state\" | \"target\" | \"field\" | \"event\" | \"emit\" | \"subject\", \"name\": \"<the name a rule would use>\", \"what\": \"<what it does or reads, its settings and defaults, in one or two sentences>\"}. Then still write the rules as they will be once those pieces exist (they are checked again after the builder adds them).\n"
           . "Write sketches/{$id}/rules.json as {\"rules\": [ …rules in the events.json format, new ids… ], \"explain\": \"<what the rules do, in the producer's words, two or three sentences>\", \"missing\": [ …pieces, or empty… ]}, check it parses with `node -e`, then sketches/{$id}/result.json as {\"ok\": true, \"files\": [], \"note\": \"<how many rules and missing pieces, in one line>\"}. Change no other file. Do not run a soak and do not commit. Reply with one line.";
        GameStore::emit($slug, 'prompt', ['text' => "Event rules: {$text}", 'mode' => 'art', 'by' => GameStore::memberName($memberId), 'job' => $id]);
        $r = GameStore::startArt($slug, $p, $memberId);
        return ['id' => $id, 'queued' => !empty($r['queued'])];
    }
    /** The proposals: reading, proposed (rules checked against the vocabulary, or the reason they do not fit), added, dismissed. */
    public static function proposals(string $slug): array {
        $all = is_file(self::propFile($slug)) ? (json_decode((string) file_get_contents(self::propFile($slug)), true) ?: []) : []; $v = self::vocab($slug); $out = [];
        foreach ($all as $q) {
            if (in_array($q['state'], ['added', 'dismissed', 'superseded'], true)) { if (time() - (int) $q['at'] < 86400) $out[] = $q; continue; }
            if ($q['state'] === 'building') { $out[] = $q; continue; }
            try { $s = GameStore::sketchStatus($slug, (string) $q['id']); } catch (\InvalidArgumentException $e) { continue; }
            if (empty($s['result'])) { $q['state'] = 'reading'; $out[] = $q; continue; }
            if (empty($s['result']['ok'])) { $q['state'] = 'failed'; $q['error'] = (string) ($s['result']['error'] ?? 'the artist could not write rules for it'); $out[] = $q; continue; }
            $j = json_decode((string) @file_get_contents(GameStore::sketchDir($slug, (string) $q['id']) . '/rules.json'), true);
            if (!is_array($j) || !is_array($j['rules'] ?? null)) { $q['state'] = 'failed'; $q['error'] = 'its rules.json is missing or not JSON'; $out[] = $q; continue; }
            $q['state'] = 'proposed'; $q['explain'] = mb_substr((string) ($j['explain'] ?? ''), 0, 600); $q['missing'] = array_slice(array_map(fn($x) => is_array($x) ? mb_substr(trim(($x['kind'] ?? '') . ' ' . ($x['name'] ?? '') . ': ' . ($x['what'] ?? '')), 0, 300) : mb_substr((string) $x, 0, 300), (array) ($j['missing'] ?? [])), 0, 10);
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

    // ---- the loop for a concept that needs more than the game has: the builder adds the missing pieces, then the rules are
    //      written again against the grown vocabulary, and the producer adds them ----
    private static function saveProps(string $slug, array $all): void { GameStore::writeAtomic(self::propFile($slug), json_encode(array_slice($all, 0, 20), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
    public static function buildMissing(string $slug, string $id, int $memberId): array {
        $p = null; foreach (self::proposals($slug) as $q) if ($q['id'] === $id) $p = $q; if (!$p || $p['state'] !== 'proposed' || empty($p['missing'])) throw new \InvalidArgumentException('that proposal has nothing missing to build');
        $mark = "[for the rules request {$id}]";
        $r = GameStore::askBuilder($slug, "Add these to the game's events {$mark}, so the producer's concept \"{$p['text']}\" can be written as rules (\"Events\" in ../../kit/AGENTS.md):\n- " . implode("\n- ", $p['missing'])
            . "\nFor each: implement it (an action with Events.action, a state with Events.state, a target with Events.target, a field by passing it where the event is emitted, an event by emitting it where it happens) and declare it in assets/events-vocab.json with its settings, defaults and a line a producer understands. Keep the names given unless one clashes, and say if you renamed. Do not write or change the producer's rules in assets/events.json. node --check, run ../../studio/bin/soak 60 4, commit.", $memberId, true);
        $all = json_decode((string) @file_get_contents(self::propFile($slug)), true) ?: []; foreach ($all as &$q) if ($q['id'] === $id) { $q['state'] = 'building'; $q['asked'] = time(); } unset($q); self::saveProps($slug, $all);
        return ['queued' => !empty($r['queued'])];
    }
    /** A builder run ended: a concept waiting on it is written again (unless the builder's run for it is still queued). */
    public static function afterBuild(string $slug, int $memberId = 0): array {
        $all = json_decode((string) @file_get_contents(self::propFile($slug)), true) ?: []; $q = GameStore::studioDir($slug) . '/queue.jsonl'; $queued = is_file($q) ? (string) file_get_contents($q) : ''; $did = [];
        foreach ($all as $i => $p) {
            if (($p['state'] ?? '') !== 'building' || str_contains($queued, "[for the rules request {$p['id']}]")) continue;
            $all[$i]['state'] = 'superseded'; self::saveProps($slug, $all);
            $n = self::describe($slug, (string) $p['text'], $memberId, 'The builder has just added what an earlier reading of this concept found missing; use it.', (string) $p['id'], (string) ($p['about'] ?? '')); $did[] = $n['id'];
            $all = json_decode((string) @file_get_contents(self::propFile($slug)), true) ?: [];
        }
        return $did;
    }

    // ---- the catalogue: what the game does built in (assets/events-builtin.json, written by the builder), shown in the Events
    //      tab beside the rules, so every reaction in the game is visible; an entry that can be a rule is converted on request ----
    public static function builtin(string $slug): ?array {
        $p = self::file($slug, 'events-builtin.json'); if (!is_file($p)) return null;
        $j = json_decode((string) file_get_contents($p), true); if (!is_array($j) || !is_array($j['systems'] ?? null)) throw new \RuntimeException("assets/events-builtin.json in {$slug} is not the catalogue's JSON: the builder must fix it");
        return $j;
    }
    public static function catalogue(string $slug, int $memberId): array {
        if (!self::vocab($slug)) throw new \InvalidArgumentException('wire events into the game first');
        $p = "Write the catalogue of what this game does BUILT IN, so the producer sees every reaction in the Events tab beside their rules (\"Events\" in ../../kit/AGENTS.md; the rule format is the header of ../../kit/js/kit-events.js). Change no code in this run.\n"
           . "Go system by system (read docs/notebooks.md and each notebook, then the code): the player and its segments, bullets (every type), elements and status effects (burn, poison, freeze, electric…, how they start, tick and end), weapon combos, parasites, perks and gear, enemies and their attacks, bosses, pickups and loot, the world's props and summons, scoring, levels, death and last stand, and anything else that reacts to something. For each REACTION (when something happens to something, the game does something), one item. Per-frame mechanics that are formulas, not reactions (damage maths, armour, ticking, movement, AI) are items too, marked \"rule\": \"no\" with why, so the catalogue is complete.\n"
           . "Write assets/events-builtin.json as {\"version\": 1, \"made\": \"<date>\", \"systems\": [ {\"id\": \"elements\", \"name\": \"Elements and status effects\", \"about\": \"<one line>\", \"items\": [ {\"id\": \"elements.poison-cloud\", \"who\": \"<a subject key of events-vocab.json, group:<group>, or *>\", \"when\": \"<what sets it off, in plain words>\", \"does\": \"<what the game does, with its numbers, in plain words>\", \"where\": \"<file:line>\", \"settings\": [\"<CFG paths its numbers come from>\"], \"rule\": \"yes\" | \"partly\" | \"no\", \"why\": \"<for partly or no: what stays in code and why>\", \"needs\": [\"<events, fields, states, targets or actions the vocabulary would need to hold it as a rule>\"], \"now\": [\"<ids of rules in assets/events.json that already hold it, if any>\"] } ] } ] }. "
           . "Words a producer understands; ids lowercase with dots and dashes; the reactions already moved into rules (assets/events.json) are listed with now set. Check it parses with node, commit it. No soak (no code changed).";
        return GameStore::askBuilder($slug, $p, $memberId, true);
    }
    /** Catalogue items become rules, in one builder run: it adds what they need, moves each reaction out of the code into rules
     *  that do exactly the same, and marks each item with the rules that now hold it. $also: more the run should add (words). */
    public static function convert(string $slug, array $itemIds, int $memberId, string $also = ''): array {
        $cat = self::builtin($slug); if (!$cat) throw new \InvalidArgumentException('there is no catalogue yet');
        $itemIds = array_values(array_unique(array_filter(array_map('strval', $itemIds)))); if (!$itemIds) throw new \InvalidArgumentException('name the items to make rules of');
        if (count($itemIds) > 8) throw new \InvalidArgumentException('at most 8 items in one run: a smaller run keeps a mistake small');
        $found = []; foreach ($cat['systems'] as $s) foreach ((array) ($s['items'] ?? []) as $x) if (in_array($x['id'] ?? '', $itemIds, true)) $found[$x['id']] = [$s, $x];
        $lines = [];
        foreach ($itemIds as $id) {
            if (!isset($found[$id])) throw new \InvalidArgumentException("no {$id} in the catalogue"); [$sys, $it] = $found[$id];
            if (($it['rule'] ?? '') === 'no') throw new \InvalidArgumentException("{$id} stays in code: " . ($it['why'] ?? ''));
            if (!empty($it['now']) && ($it['rule'] ?? '') === 'yes') throw new \InvalidArgumentException("{$id} is a rule already: " . implode(', ', (array) $it['now']));
            $lines[] = "- {$id} (" . ($sys['name'] ?? '') . '): when ' . ($it['when'] ?? '') . ', ' . ($it['does'] ?? '') . ' (' . ($it['where'] ?? '') . ').' . (!empty($it['needs']) ? ' Needs: ' . implode('; ', (array) $it['needs']) . '.' : '') . (($it['rule'] ?? '') === 'partly' ? ' Only part can be a rule: ' . ($it['why'] ?? '') . ' Move that part, leave the rest in code.' : '');
        }
        $p = "Make built-in behaviours of the game rules the producer can change (\"Events\" in ../../kit/AGENTS.md; items of assets/events-builtin.json):\n" . implode("\n", $lines) . "\n" . ($also !== '' ? "Also: {$also}\n" : '')
           . "First run ../../studio/bin/soak 60 4 and keep its report. Then, item by item:\n"
           . "1. Add to the vocabulary and the code what it needs (events emitted where they happen, fields, states, targets, actions wrapping the game's own functions), declared in assets/events-vocab.json in words a producer reads.\n"
           . "2. Remove the hard-wired reaction from the code and add rules to assets/events.json that reproduce it EXACTLY (the same numbers from the same settings, the same order, the same conditions), each with a note saying it was built in.\n"
           . "3. In assets/events-builtin.json set the item's now to the new rules' ids (and its rule and why, if only part moved).\n"
           . "If an item turns out not to be exactly reproducible, leave it in code, say why in the catalogue and in your reply, and go on to the next.\n"
           . "At the end: node --check; ../../studio/bin/soak 60 4 again and compare with the first (no new errors; deaths, kills and level within noise); never loosen a check. Commit, naming the items moved and any left.";
        return GameStore::askBuilder($slug, $p, $memberId, true);
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
           . "- state: what a rule may ask about, read live with Events.state(name, (target, ctx) => …): the thing's own (self.hp as 0 to 1, self.burning / self.frozen / self.poisoned as yes or no, self.elite…) and the game's (player.segments, player.hp, game.level, game.score, game.region, game.time…), each with its kind or values.\n"
           . "- targets: whom an action can be aimed at, found with Events.target(name, (ctx, args) => [{ key, obj }, …]) and never including the thing itself: nearby (a radius setting, default about two body lengths), player, source (what caused the event, when the event knows it), and every-<group> on screen if the game can list them.\n"
           . "- Call Events.update() once a frame (delayed actions), and Events.reset('game') when a new run starts (game-wide counters).\n"
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
