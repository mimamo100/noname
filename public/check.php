<?php
// Setup check: visit /check.php to see whether the server is ready to run Unsaid.
// Deliberately written in old-style PHP so it still runs, and reports the problem,
// when the server's PHP is too old for the game itself.
// It shows server details, so delete it once the game is working.

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$failures = 0;
function report($pass, $text, $fix = '')
{
    global $failures;
    if (!$pass) $failures++;
    echo ($pass ? '[ OK ]  ' : '[FAIL]  ') . $text . "\n";
    if (!$pass && $fix !== '') echo '        Fix: ' . $fix . "\n";
}

echo "Unsaid setup check\n==================\n\n";

report(version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP version ' . PHP_VERSION . ' (needs 8.1 or later)',
    'cPanel > MultiPHP Manager: tick this subdomain and choose PHP 8.1 or later.');
report(extension_loaded('pdo_mysql'), 'pdo_mysql extension',
    'cPanel > Select PHP Version > Extensions: tick pdo_mysql (ask your host if it is missing).');
report(extension_loaded('mbstring'), 'mbstring extension',
    'cPanel > Select PHP Version > Extensions: tick mbstring.');

$root = dirname(__DIR__);
report(is_file(__DIR__ . '/.htaccess'), 'public/.htaccess is present',
    'Extract the zip again with hidden files shown (File Manager > Settings > Show Hidden Files).');
report(is_file($root . '/data/dictionary.php'), 'data/dictionary.php is present',
    'Upload and extract the whole zip so the data folder sits next to public.');
report(basename(__DIR__) === 'public', 'This page is running from the public folder',
    "Set the subdomain's document root to the public folder inside the game folder.");

$configPath = $root . '/config.php';
$hasConfig = is_file($configPath);
report($hasConfig, 'config.php is present next to the public folder',
    'Copy config.example.php to config.php in the game folder (not in public) and fill in your database details.');

if ($hasConfig && extension_loaded('pdo_mysql')) {
    $config = require $configPath;
    try {
        $db = new PDO(
            'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
            $config['db_user'],
            $config['db_pass'],
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
        );
        report(true, 'Connected to database ' . $config['db_name']);
        $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        echo '        Tables: ' . ($tables ? implode(', ', $tables) : 'none yet (the game creates them on first use)') . "\n";
        $db->query('CREATE TABLE IF NOT EXISTS unsaid_check (id INT)');
        $db->query('DROP TABLE unsaid_check');
        report(true, 'The database user can create tables');
    } catch (Exception $e) {
        report(false, 'Database: ' . $e->getMessage(),
            'Check the name, user and password in config.php (cPanel adds your account name in front, e.g. myacct_unsaid), '
            . 'and that the user was added to the database with ALL PRIVILEGES.');
    }
}

echo "\n" . ($failures === 0
    ? "Everything looks good. If the game still says Loading..., visit /api/worlds/public and send the result.\n"
    : "$failures problem(s) found. Fix them in order, then reload this page.\n");
echo "\nDelete public/check.php once the game is working.\n";
