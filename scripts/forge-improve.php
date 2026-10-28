#!/usr/bin/env php
<?php
/**
 * forge-improve.php <proposal-id> [--stub] — the improver's run for an approved proposal (lib/Improve.php), started
 * detached when a person approves. --stub makes a trivial change instead of calling the agent (tests the pipeline).
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../vendor/autoload.php';
chdir(dirname(__DIR__));
define('BASE_PATH', \app\Paths::root());
new \app\Bootstrap('conf/config.ini');
$id = (string) ($argv[1] ?? ''); if ($id === '') { fwrite(STDERR, "usage: forge-improve.php <proposal-id> [--stub]\n"); exit(1); }
try { $s = \app\Improve::run($id, in_array('--stub', $argv, true)); echo date('c') . " $id: " . ($s['status'] ?? '?') . (isset($s['error']) ? ' — ' . $s['error'] : '') . "\n"; }
catch (\Throwable $e) { fwrite(STDERR, date('c') . " $id: " . $e->getMessage() . "\n"); exit(1); }
