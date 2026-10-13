<?php
// The daily email, for players who turned it on: one batch each run. Run it every hour from a
// cPanel Cron Job, as docs/DEPLOY.md describes:
//
//   /opt/cpanel/ea-php81/root/usr/bin/php /home/youraccount/unsaid/scripts/daily-email.php
//
// Before from_hour it does nothing; after that each run sends up to per_hour emails (see
// 'daily_email' in config.php) until everyone due one has it. Needs site_url for the links.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("Run this from the command line or a cron job.\n");
ini_set('display_errors', 'stderr');

$root = dirname(__DIR__);
require "$root/src/WordFamilies.php";
require "$root/src/Dictionary.php";
require "$root/src/Prompts.php";
require "$root/src/Scoring.php";
require "$root/src/StateCache.php";
require "$root/src/Streaks.php";
require "$root/src/Game.php";
require "$root/src/Mailer.php";
require "$root/src/Reminders.php";

$config = require getenv('UNSAID_CONFIG') ?: "$root/config.php";
if (empty($config['site_url'])) exit("Not sending: set site_url in config.php.\n");
$db = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'],
    $config['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
Game::installSchema($db);
$streaks = new Streaks($db, $config['big_world']['timezone'] ?? 'Europe/London');
// No web visitor here to take the domain from, so a missing 'from' comes from site_url.
$mail = ($config['mail'] ?? []) + ['from' => 'noreply@' . preg_replace('/^www\./', '', (string) parse_url($config['site_url'], PHP_URL_HOST))];
$settings = $config['daily_email'] ?? [];
$sent = (new Reminders($db, $streaks))->send(new Mailer($mail), $config['site_url'],
    (int) ($settings['per_hour'] ?? 150), (int) ($settings['from_hour'] ?? 7));
echo "dailyEmails: $sent\n";
