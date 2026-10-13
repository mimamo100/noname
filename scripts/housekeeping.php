<?php
// Daily tidying: old sign-in codes, expired sessions, old free misses, and the detail of
// worlds that finished long ago (see src/Housekeeping.php). Run it once a day from a cPanel
// Cron Job, as docs/DEPLOY.md describes:
//
//   /opt/cpanel/ea-php81/root/usr/bin/php /home/youraccount/unsaid/scripts/housekeeping.php
//
// It prints what it removed, which cPanel emails you if you set a cron email address.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("Run this from the command line or a cron job.\n");
// Errors to stderr, so cron emails them even when the normal output is thrown away.
ini_set('display_errors', 'stderr');

$root = dirname(__DIR__);
require "$root/src/WordFamilies.php";
require "$root/src/Dictionary.php";
require "$root/src/Prompts.php";
require "$root/src/Scoring.php";
require "$root/src/StateCache.php";
require "$root/src/Streaks.php";
require "$root/src/Game.php";
require "$root/src/Housekeeping.php";

$config = require getenv('UNSAID_CONFIG') ?: "$root/config.php";
$db = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'],
    $config['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
Game::installSchema($db);
$cacheDir = $config['cache_dir'] ?? "$root/cache";
$game = new Game($db, Dictionary::load());
$done = (new Housekeeping(
    $db, (int) ($config['housekeeping']['keep_days'] ?? 30), $cacheDir ?: null,
    game: $game, idleDays: (int) ($config['housekeeping']['idle_days'] ?? 60),
))->run();
foreach ($done as $what => $count) echo "$what: $count\n";
