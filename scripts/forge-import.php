#!/usr/bin/env php
<?php
/**
 * Register game folders that have no row yet: games/<slug>/ made before the forge, or copied in by hand.
 *   php scripts/forge-import.php <owner member id> [slug ...]       (no slugs: every folder without a row)
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../vendor/autoload.php';
chdir(dirname(__DIR__));
define('BASE_PATH', \app\Paths::root());
new \app\Bootstrap('conf/config.ini');
$owner = (int) ($argv[1] ?? 0); if ($owner <= 0) exit("usage: forge-import.php <owner member id> [slug ...]\n");
$slugs = array_slice($argv, 2) ?: array_values(array_filter(scandir(\app\GameStore::gamesDir()), fn($s) => $s[0] !== '.' && is_dir(\app\GameStore::gamesDir() . '/' . $s)));
foreach ($slugs as $slug) {
    if (!preg_match(\app\GameStore::SLUG, $slug)) { echo "  {$slug}: not a slug, skipped\n"; continue; }
    if (\app\GameStore::row($slug)) { echo "  {$slug}: already registered\n"; continue; }
    $dir = \app\GameStore::dir($slug);
    $m = json_decode((string) @file_get_contents($dir . '/game.json'), true) ?: [];
    $brief = ''; if (is_file($dir . '/GAME.md') && preg_match('/## Brief\s+(.+?)\n\s*\n/s', (string) file_get_contents($dir . '/GAME.md'), $mm)) $brief = trim($mm[1]);
    $g = \app\Bean::dispense('game');
    $g->slug = $slug; $g->title = (string) ($m['title'] ?? $slug); $g->template = (string) ($m['template'] ?? ''); $g->brief = $brief; $g->status = (string) ($m['status'] ?? 'new');
    $g->price = isset($m['price']) && $m['price'] !== null ? (float) $m['price'] : null; $g->tags = json_encode($m['tags'] ?? []); $g->notes = (string) ($m['notes'] ?? '');
    $g->memberId = $owner; $g->teamId = null; $g->createdAt = (string) ($m['created'] ?? date('c')); $g->publishedAt = (string) ($m['published']['at'] ?? ''); $g->publishedUrl = (string) ($m['published']['url'] ?? '');
    \app\Bean::store($g);
    echo "  {$slug}: registered to member {$owner}\n";
}
