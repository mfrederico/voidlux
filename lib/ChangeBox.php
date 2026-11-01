<?php
/**
 * ChangeBox — the Studio's one input: a person says what they want different, about the thing picked or the whole game, and
 * sees where that would go and what it would cost before anything runs. Five ways: its words (free, edited on its card, lib/GameWords.php), its numbers (free, at once), a rule (the
 * artist writes rules to read first), a new version of its model, or the builder. The way is suggested by plain word matching:
 * free, instant, and shown with the words it went by, so a person can overrule it. Nothing here calls an agent; do() starts
 * the same jobs the editors start.
 */
namespace app;

class ChangeBox {
    private const WORDS = [ // what points at each way, in the order they are tried: "faster when hit" is a rule, not a number
        'note' => '/\b(later|notes?|remember|idea|maybe|someday)\b/i',
        'words' => '/\b(text|words?|wording|says?|rename\w*|spelling|typo|call it|label|title|heading|description)\b/i',
        'rule' => '/\b(when|whenever|each time|every time|after it|once it|if it|if you|on death|dies|clicked|tapped|clicking|tapping|hovered|opens|closes|killed|explodes?|drops?|spawns? (a|an|two|three|more)|splits?)\b/i',
        'numbers' => '/\b(faster|slower|speed|quicker|more often|less often|rarer|commoner|appears?|range|weaker to|weak to|resist\w*|immune|attacks? (more|less)|shoots? (more|less|faster|slower)|fire|burn\w*|poison\w*|venom\w*|ice|cold|frost\w*|freez\w*|electric\w*|shock\w*|lightning)\b/i',
        'art' => '/\b(look|looks|looking|colou?rs?|red|blue|green|yellow|orange|purple|pink|black|white|model|shape|wears?|wearing|hat|eyes?|legs?|arms?|wings?|skin|texture|shiny|scarier|cuter|redesign|redraw|draw)\b/i',
    ];
    private const FIELDS = [ // a number of the bestiary, in the person's words, and the words that point at it
        'speed' => ['Move speed (pixels a second)', '/\b(faster|slower|speed|quicker)\b/i'],
        'cd' => ['Seconds between attacks', '/\b(attacks?|shoots?) (more|less|faster|slower)\b/i'],
        'range' => ['Attack range (pixels)', '/\brange\b/i'],
        'shotSpeed' => ['Shot speed (pixels a second)', '/\b(bullets?|shots?) (faster|slower)\b|\bshoots? (faster|slower)\b/i'],
        'hold' => ['Seconds it jams you for', '/\b(jams?|holds?)\b/i'],
        'tempo' => ['Boss pace (× every ability)', '/\b(pace|tempo)\b/i'],
        'spawn' => ['How often it appears (× , 0 never)', '/\b(more often|less often|rarer|commoner|appears?)\b/i'],
    ];
    private const ELEMENTS = ['fire' => '/\b(fire|burn\w*)\b/i', 'poison' => '/\b(poison\w*|venom\w*)\b/i', 'ice' => '/\b(ice|cold|frost\w*|freez\w*)\b/i', 'electric' => '/\b(electric\w*|shock\w*|lightning)\b/i'];

    /** The ways for this request: {suggested, why, ways: [{id, title, does, cost, free, can, whyNot, go, fields?}]}. */
    public static function ways(string $slug, string $text, string $key, string $label = '', string $context = ''): array {
        $text = trim($text); if ($text === '') throw new \InvalidArgumentException('Say what you want different.');
        $thing = self::thing($slug, $key, $label, $context); $name = $thing['screen'] ? $thing['label'] : ($key !== '' ? $key : 'the whole game');
        $art = RunCosts::estimate($slug, 'art')['cost']; $row = $thing['row']; $vocab = Rules::vocab($slug);
        $model = $key !== '' && !$thing['screen'] && is_file(Cast::path($slug)) ? (Cast::read($slug)['items'][$key] ?? null) : null;
        $subject = $thing['screen'] && isset($vocab['subjects'][$key]); // part of a screen that the event rules know: rules about it can be written
        $ways = [
            'words' => ['title' => 'Change its words', 'does' => 'Edit the words yourself on its card below. They reach the running game when they can, and you can undo them.', 'cost' => 'Free.', 'free' => true, 'go' => 'Edit the words', 'can' => $thing['screen'], 'whyNot' => $key === '' ? 'Pick the words on the screen first, with Inspect.' : "This works on what a screen shows; {$name}'s name in the rules is the builder's."],
            'numbers' => ['title' => 'Change its numbers', 'does' => "Change {$name}'s numbers yourself. They reach the running game when they can, and you can undo them.", 'cost' => 'Free.', 'free' => true, 'go' => 'Save these numbers',
                          'can' => $row !== null, 'whyNot' => $thing['screen'] ? "{$name} is part of a screen: its numbers are in the game's code." : ($key === '' ? 'Pick a thing first; the numbers of the whole game are on the game page, under Config.' : "{$key} has no row in this game's bestiary.")],
            'rule' => ['title' => 'Write it as a rule', 'does' => 'The artist writes rules for ' . $name . ' and you read them first. Nothing changes until you add them.', 'cost' => $art, 'free' => false, 'go' => 'Ask for the rules',
                       'can' => $vocab !== null && (!$thing['screen'] || $subject), 'whyNot' => $thing['screen'] ? "The game does not send events about {$name} yet; the builder can add that." : 'This game has no events wired in yet.'],
            'art' => ['title' => 'Draw a new version', 'does' => "The artist makes a new version of {$name}'s model. The game keeps the current one until you choose.", 'cost' => $art, 'free' => false, 'go' => 'Ask for a new version',
                      'can' => !empty($model['file']), 'whyNot' => $thing['screen'] ? "The game draws {$name} in code, so the builder changes how it looks." : ($key === '' ? 'Pick a thing first.' : "{$key} has no 3D model to revise; the builder can change how the game draws it.")],
            'builder' => ['title' => 'Ask the builder', 'does' => 'The builder changes the game\'s code' . ($key !== '' ? ", with {$name} as what you mean," : '') . ' and commits it.' . (GameStore::agentState($slug)['busy'] ? ' It is busy now: this goes with its next run.' : ''), 'cost' => RunCosts::estimate($slug, 'chat')['cost'], 'free' => false, 'go' => 'Send it to the builder', 'can' => true, 'whyNot' => ''],
            'note' => ['title' => 'Save it as a note', 'does' => 'Keeps your words with a screenshot of where you pointed. Nothing runs. Send your notes to the builder together when you are ready.', 'cost' => 'Free.', 'free' => true, 'go' => 'Save this note', 'can' => true, 'whyNot' => ''],
        ];
        $suggested = 'builder'; $why = $thing['screen'] && !$subject ? 'This is part of a screen, which lives in the game\'s code.' : 'Nothing you said points at a number, a rule or the look, so this is the way that can do anything.';
        foreach (self::WORDS as $way => $re) if ($ways[$way]['can'] && preg_match($re, $text, $m)) { $suggested = $way; $why = 'Because you said "' . $m[0] . '".'; break; }
        if ($row !== null) $ways['numbers']['fields'] = self::fields($slug, $row, $text);
        return ['suggested' => $suggested, 'why' => $why, 'about' => $key, 'ways' => array_map(fn($id, $w) => ['id' => $id] + $w, array_keys($ways), $ways)];
    }
    /** Starts the change. Returns what the page follows: {way, id?, queued?, before?}. A refusal throws with the reason. */
    public static function start(string $slug, string $way, string $text, string $key, array $values, int $memberId, string $who, string $label = '', string $context = ''): array {
        $text = trim(mb_substr($text, 0, 1200)); $thing = self::thing($slug, $key, $label, $context);
        if ($way !== 'numbers' && $text === '') throw new \InvalidArgumentException('Say what you want different.');
        switch ($way) {
            case 'numbers':
                if ($thing['row'] === null) throw new \InvalidArgumentException('That has no numbers to change.');
                self::saveNumbers($slug, $thing['row'], $values);
                $said = implode(', ', array_map(fn($f, $v) => $f . ' ' . ($v === null ? "the game's own" : $v), array_keys($values), $values));
                GameStore::activity($slug, $who, "changed the numbers of {$key} in the Studio ({$said})" . ($text !== '' ? ": {$text}" : ''));
                return ['way' => $way, 'names' => array_keys($values), 'sha' => Timeline::record($slug, "{$key}: {$said}", 'numbers', $who, ['js/bestiary.js'], $key)];
            case 'rule':
                $r = Rules::describe($slug, $text, $memberId, '', '', $key);
                GameStore::activity($slug, $who, 'asked for event rules' . ($key !== '' ? " (about {$key})" : '') . ": {$text}");
                return ['way' => $way, 'id' => $r['id'], 'queued' => $r['queued']];
            case 'art':
                $r = Cast::revise($slug, $key, $text, $memberId);
                GameStore::activity($slug, $who, "asked for a revision of {$key}: {$text}");
                return ['way' => $way, 'id' => (string) ($r['id'] ?? ''), 'key' => $key, 'queued' => !empty($r['queued'])];
            case 'builder':
                $about = $key !== '' ? 'About "' . ($thing['screen'] ? "{$thing['label']}\", {$key}" : "{$key}\"") . ($thing['about'] !== '' ? " ({$thing['about']})" : '') . ', picked in the Studio' . ($thing['screen'] ? ' on one of the game\'s screens' : '') . ': ' : '';
                $r = GameStore::askBuilder($slug, $about . $text, $memberId);
                return ['way' => $way, 'queued' => !empty($r['queued'])];
        }
        throw new \InvalidArgumentException('The way is words, numbers, rule, art or builder.');
    }

    /** {row: the thing's own bestiary row or null, about, screen, label}. A part of a screen (perk:swift, button:x) is named by the game's page, and only ever becomes words for the builder; any other name the game does not have is refused, not guessed at. */
    private static function thing(string $slug, string $key, string $label = '', string $context = ''): array {
        if ($key === '') return ['row' => null, 'about' => '', 'screen' => false, 'label' => ''];
        if (preg_match('/^[a-z]+:[A-Za-z0-9_-]{1,60}$/', $key)) { $one = fn($s, $n) => mb_substr(trim((string) preg_replace('/\s+/', ' ', $s)), 0, $n); return ['row' => null, 'about' => $one($context, 600), 'screen' => true, 'label' => $one($label, 80) ?: $key]; }
        $card = Inspector::card($slug, $key); $s = Rules::vocab($slug)['subjects'][$key] ?? [];
        $own = array_values(array_filter((array) ($s['bestiary'] ?? []), fn($r) => !str_starts_with((string) $r, 'family-')));
        return ['row' => $own[0] ?? null, 'about' => (string) $card['about'], 'screen' => false, 'label' => $key];
    }
    /** The row's numbers: those the words point at first (marked), an empty value is the game's own. */
    private static function fields(string $slug, string $row, string $text): array {
        $B = GameStore::readBestiary($slug); $out = [];
        foreach (self::ELEMENTS as $el => $re) $out[] = ['name' => $el, 'label' => ucfirst($el) . ' damage (×, 1 normal, 0 immune)', 'value' => $B['resist'][$row][$el] ?? null, 'lo' => 0, 'hi' => 5, 'meant' => (bool) preg_match($re, $text)];
        foreach (self::FIELDS as $f => [$label, $re]) { [$lo, $hi] = GameStore::BESTIARY_STATS[$f]; $out[] = ['name' => $f, 'label' => $label, 'value' => $B['stats'][$row][$f] ?? null, 'lo' => $lo, 'hi' => $hi, 'meant' => (bool) preg_match($re, $text)]; }
        return $out;
    }
    /** Writes the given numbers into one row. Empty clears a value. */
    private static function saveNumbers(string $slug, string $row, array $values): void {
        if (!$values) throw new \InvalidArgumentException('No number was changed.');
        $B = GameStore::readBestiary($slug); if (!$B) throw new \RuntimeException("{$slug} has no bestiary to save into");
        $B['resist'] = (array) ($B['resist'] ?? []); $B['stats'] = (array) ($B['stats'] ?? []);
        foreach ($values as $f => $v) {
            $isEl = isset(self::ELEMENTS[$f]); if (!$isEl && !isset(self::FIELDS[$f])) throw new \InvalidArgumentException("There is no number called {$f}.");
            if ($v !== null && !is_int($v) && !is_float($v)) throw new \InvalidArgumentException("{$f}: a number, or empty for the game's own.");
            $sec = $isEl ? 'resist' : 'stats';
            if ($isEl) $B['resist'][$row] = (array) ($B['resist'][$row] ?? []);
            $B[$sec][$row][$f] = $v;
        }
        GameStore::saveBestiary($slug, $B); GameStore::emit($slug, 'note', ['text' => "bestiary saved from the Studio: {$row}"]);
    }
}
