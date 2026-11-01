<?php
/**
 * GameWords — the words a game shows, changed where they are written. The game's page says what text it drew and which line drew
 * it; this finds that text among the string literals of the game's scripts (a small scanner: comments and code are never touched)
 * and replaces it there, only when there is exactly one place it can mean. Text put together from values, or found in several places
 * the game's drawing cannot tell apart, is refused with the reason: the builder can do those. Every change is a timeline entry.
 */
namespace app;

class GameWords {
    private const NOT_TEXT = ['bestiary.js', 'tuning.js', 'sfx.js', 'music-themes.js', 'forge-soak.js', 'forge-screens.js', 'soak-store.js']; // the editors' own files, and the forge's checks: no screen shows those

    /** {ok, file?, why?} for each {value, site}: can these words be changed directly? */
    public static function status(string $slug, array $texts): array {
        $lits = self::literals($slug);
        return array_map(function ($t) use ($lits) { try { $m = self::locate($lits, (string) ($t['value'] ?? ''), (string) ($t['site'] ?? '')); return ['ok' => true, 'file' => $m['file']]; } catch (\InvalidArgumentException $e) { return ['ok' => false, 'why' => $e->getMessage()]; } }, array_values($texts));
    }
    /** Replaces the words in the one place they are written. Returns {sha, file}. */
    public static function change(string $slug, string $old, string $new, string $site, string $who, string $key = ''): array {
        if (GameStore::agentState($slug)['busy']) throw new \RuntimeException('The builder is working on this game: wait for it, or stop it, then change the words.');
        $new = str_replace("\r", '', trim($new)); if ($new === '') throw new \InvalidArgumentException('The words cannot be empty.'); if ($new === $old) throw new \InvalidArgumentException('Those are the words already there.');
        if (mb_strlen($new) > 600 || preg_match('/[\x00-\x09\x0b-\x1f]/', $new)) throw new \InvalidArgumentException('Words are up to 600 characters, with no control characters.');
        $m = self::locate(self::literals($slug), $old, $site); $d = GameStore::dir($slug); $file = $m['file']; $src = (string) file_get_contents("$d/$file");
        $out = substr($src, 0, $m['at']) . self::encode($new, $m['quote']) . substr($src, $m['at'] + strlen(self::encode($old, $m['quote'])));
        $tmp = GameStore::studioDir($slug) . '/words-check.js'; file_put_contents($tmp, $out);
        try { exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc); } finally { @unlink($tmp); }
        if ($rc !== 0) throw new \RuntimeException('The script would not parse with those words, so nothing was changed: ' . mb_substr(implode(' ', $o), 0, 200));
        GameStore::writeAtomic("$d/$file", $out);
        GameStore::activity($slug, $who, "changed the words \"{$old}\" to \"{$new}\" ({$file})");
        return ['sha' => Timeline::record($slug, 'Words: "' . mb_substr($old, 0, 60) . '" to "' . mb_substr($new, 0, 60) . '"', 'text', $who, [$file], $key), 'file' => $file];
    }

    /** The one place: the line that drew it, else the only literal that is exactly these words, else the only one holding them. */
    private static function locate(array $lits, string $text, string $site): array {
        if (mb_strlen($text) < 2 || !preg_match('/\p{L}/u', $text)) throw new \InvalidArgumentException('Too short to find safely.');
        $hits = []; foreach ($lits as $l) { $needle = self::encode($text, $l['quote']); $from = 0; $n = 0; while (($p = strpos($l['raw'], $needle, $from)) !== false) { $whole = strlen($needle) === strlen($l['raw']); if (!($whole && $l['logic'])) $hits[] = ['file' => $l['file'], 'line' => $l['line'] + substr_count($l['raw'], "\n", 0, $p), 'at' => $l['at'] + $p, 'quote' => $l['quote'], 'whole' => $whole]; $from = $p + strlen($needle); } }
        if (!$hits) throw new \InvalidArgumentException('The game puts these words together as it runs, so they are not written anywhere as they stand. The builder can change them.');
        if ($site !== '' && preg_match('#^(js/[A-Za-z0-9_.-]+):(\d+)$#', $site, $s)) { $here = array_values(array_filter($hits, fn($h) => $h['file'] === $s[1] && $h['line'] === (int) $s[2])); if (count($here) === 1) return $here[0]; }
        if (count($hits) === 1) return $hits[0];
        $whole = array_values(array_filter($hits, fn($h) => $h['whole'])); if (count($whole) === 1) return $whole[0];
        throw new \InvalidArgumentException('These words are written in ' . count($hits) . ' places (' . implode(', ', array_unique(array_column(array_slice($hits, 0, 3), 'file'))) . '), and which one this is cannot be told. The builder can change them.');
    }
    /** How text is written inside a literal of this quote. */
    private static function encode(string $text, string $q): string {
        $s = str_replace(['\\', "\n", $q], ['\\\\', '\\n', '\\' . $q], $text); return $q === '`' ? str_replace('${', '\\${', $s) : $s;
    }
    /** Every string literal of the game's own scripts: {file, at (byte), line, raw, quote, logic (it reads as a name to the code, not words)}. */
    private static function literals(string $slug): array {
        $d = GameStore::dir($slug); $out = [];
        foreach (glob("$d/js/*.js") ?: [] as $f) { if (in_array(basename($f), self::NOT_TEXT, true)) continue; $src = (string) file_get_contents($f); $rel = 'js/' . basename($f);
            foreach (self::scan($src) as [$at, $raw, $q]) { if ($raw === '') continue; $before = rtrim(substr($src, max(0, $at - 12), min($at, 12) - 1)); $after = ltrim(substr($src, $at + strlen($raw) + 1, 12));
                $logic = $after !== '' && ($after[0] === ':' || str_starts_with($after, '===') || str_starts_with($after, '!==') || $after[0] === ']') && $q !== '`' || preg_match('/(===|!==|\bcase|\[)$/', $before);
                $out[] = ['file' => $rel, 'at' => $at, 'line' => substr_count($src, "\n", 0, $at) + 1, 'raw' => $raw, 'quote' => $q, 'logic' => (bool) $logic]; } }
        return $out;
    }
    /** The literals of a script: [byte offset of the contents, contents, quote]; a template literal's fixed parts each count as one. */
    private static function scan(string $s): array {
        $o = []; $n = strlen($s); $i = 0; $prev = ''; $stack = [];
        while ($i < $n) { $c = $s[$i]; $st = -1;
            if ($stack && $c === '}' && end($stack) === 0) { array_pop($stack); $st = $i + 1; }
            elseif ($stack && $c === '{') $stack[count($stack) - 1]++; elseif ($stack && $c === '}') $stack[count($stack) - 1]--;
            if ($st < 0) {
                if ($c === '/' && ($s[$i + 1] ?? '') === '/') { while ($i < $n && $s[$i] !== "\n") $i++; continue; }
                if ($c === '/' && ($s[$i + 1] ?? '') === '*') { $e = strpos($s, '*/', $i + 2); $i = $e === false ? $n : $e + 2; continue; }
                if ($c === '/' && strpos("(,=:[!&|?{};\n", $prev === '' ? "\n" : $prev) !== false) { $j = $i + 1; $cl = false; while ($j < $n && ($s[$j] !== '/' || $cl) && $s[$j] !== "\n") { if ($s[$j] === '\\') $j++; elseif ($s[$j] === '[') $cl = true; elseif ($s[$j] === ']') $cl = false; $j++; } if (($s[$j] ?? '') === '/') { $i = $j + 1; $prev = '/'; continue; } }
                if ($c === "'" || $c === '"') { $j = $i + 1; while ($j < $n && $s[$j] !== $c && $s[$j] !== "\n") { if ($s[$j] === '\\') $j++; $j++; } $o[] = [$i + 1, substr($s, $i + 1, $j - $i - 1), $c]; $i = $j + 1; $prev = $c; continue; }
                if ($c === '`') $st = $i + 1;
            }
            if ($st >= 0) { $j = $st; while ($j < $n && $s[$j] !== '`' && !($s[$j] === '$' && ($s[$j + 1] ?? '') === '{')) { if ($s[$j] === '\\') $j++; $j++; } $o[] = [$st, substr($s, $st, $j - $st), '`']; if (($s[$j] ?? '') === '`') { $i = $j + 1; $prev = '`'; continue; } $stack[] = 0; $i = $j + 2; $prev = '{'; continue; }
            if (!ctype_space($c)) $prev = $c; $i++;
        }
        return $o;
    }
}
