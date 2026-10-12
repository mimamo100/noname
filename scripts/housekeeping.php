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
require "$root/src/Mailer.php";
require "$root/src/Reminders.php";

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
// The daily email, for accounts that asked for it. Needs site_url for its links.
if (!empty($config['site_url'])) {
    $streaks = new Streaks($db, $config['big_world']['timezone'] ?? 'Europe/London');
    // No web visitor here to take the domain from, so a missing 'from' comes from site_url.
    $mail = ($config['mail'] ?? []) + ['from' => 'noreply@' . preg_replace('/^www\./', '', (string) parse_url($config['site_url'], PHP_URL_HOST))];
    $done['dailyEmails'] = (new Reminders($db, $streaks))->send(new Mailer($mail), $config['site_url'], (int) ($config['daily_email_limit'] ?? 300));
}
foreach ($done as $what => $count) echo "$what: $count\n";
