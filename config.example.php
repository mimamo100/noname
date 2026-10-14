<?php
// Copy this file to config.php (next to it, outside public/) and fill in your
// MySQL details from cPanel > MySQL Databases. Never commit config.php.
return [
    'db_host' => 'localhost',
    'db_name' => 'cpaneluser_unsaid',
    'db_user' => 'cpaneluser_game',
    'db_pass' => 'change-me',
    // The admin page. Leave the password empty to turn it off.
    'admin_password' => '',
    // The admin page's address: https://your-site/<admin_path>. Pick something nobody
    // would guess (4-64 letters, numbers, - or _), but not "account", which is the players'
    // account page. Every other address says "Not found".
    'admin_path' => 'admin',
    // The big world: open to everyone signed in, started afresh every round_hours
    // (1, 2, 3, 4, 6, 8, 12 or 24), counted from midnight in this timezone.
    'big_world' => [
        'round_hours' => 24,
        'timezone' => 'Europe/London',
        'prompt_minutes' => 15,         // How long each prompt lasts at most.
        'prompt_answers' => [60, 400],  // How many answers each prompt has.
    ],
    // Rooms: filled automatically, and finished when their words run out.
    'rooms' => [
        'size' => 60,       // Players per room.
        'away_days' => 7,   // A player who hasn't guessed for this long gives up their seat.
        'max' => 50,        // Most rooms open at once, to protect the server.
    ],
    // Daily housekeeping (scripts/housekeeping.php, run by a cron job): how long to keep
    // free misses and the words of finished worlds. Final standings are kept for good.
    'housekeeping' => [
        'keep_days' => 30,
        'idle_days' => 60, // A private world with no guesses for this long ends.
    ],
    // Each world's state is kept here for a couple of seconds, so busy worlds stay fast.
    // The folder is created if needed. Set to false to turn caching off.
    // 'cache_dir' => __DIR__ . '/cache',

    // The site's address, for links in emails (the daily email that players can ask for).
    'site_url' => 'https://yourdomain.com',
    // The daily email players can ask for: sent in hourly batches (scripts/daily-email.php,
    // run by a cron job every hour), starting at from_hour in the big world's timezone.
    // Keep per_hour well under your host's hourly email limit: sign-in codes share it.
    'daily_email' => [
        'from_hour' => 7,
        'per_hour' => 150,
    ],

    // Sign-in emails. 'from' should be a real address on your domain (create it in
    // cPanel > Email Accounts), so the codes don't land in spam.
    'mail' => [
        'from' => 'noreply@yourdomain.com',
        'from_name' => 'Unsaid',
        'transport' => 'mail', // The server's own mail. For an email service use 'smtp':
        // 'transport' => 'smtp',
        // 'smtp_host' => 'smtp-relay.brevo.com', 'smtp_port' => 587, 'smtp_secure' => 'tls',
        // 'smtp_user' => '...', 'smtp_pass' => '...',
    ],
];
