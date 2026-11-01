<?php
/**
 * GameNotes — which saved notes are about the same thing, so a batch can be consolidated before it goes to the builder. Plain comparison,
 * free and instant: notes about one picked thing, or whose words mostly overlap. It only suggests: a person merges.
 */
namespace app;

class GameNotes {
    private const LIKE = 0.5; // shared words over all words of the two
    private const SMALL = ['the', 'a', 'an', 'it', 'its', 'this', 'that', 'is', 'are', 'be', 'to', 'of', 'on', 'in', 'and', 'or', 'for', 'with', 'should', 'make', 'more', 'less', 'very', 'too', 'so', 'when', 'i', 'we', 'can', 'at', 'as', 'not', 'has', 'have'];
    private static function words(string $t): array { preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($t), $m); return array_values(array_unique(array_diff($m[0], self::SMALL))); }
    public static function alike(string $a, string $b, string $thingA = '', string $thingB = ''): bool {
        if ($thingA !== '' && $thingA === $thingB) return true;
        $x = self::words($a); $y = self::words($b); if (count($x) < 2 || count($y) < 2) return false; $all = count(array_unique(array_merge($x, $y)));
        return count(array_intersect($x, $y)) / $all >= self::LIKE;
    }
    /** Groups of two or more of these {id, text, thing}: each group is ids that could become one note. */
    public static function groups(array $rows): array {
        $out = []; $taken = [];
        foreach ($rows as $i => $r) { if (isset($taken[$r['id']])) continue; $g = [$r['id']];
            foreach ($rows as $j => $o) if ($j > $i && !isset($taken[$o['id']]) && self::alike($r['text'], $o['text'], $r['thing'], $o['thing'])) { $g[] = $o['id']; $taken[$o['id']] = true; }
            if (count($g) > 1) $out[] = $g; }
        return $out;
    }
}
