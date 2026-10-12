<?php
// Admin page: review reported words, accept or dismiss them, add words, see a few stats.
// Turn it on by setting 'admin_password' in config.php. It answers only at the address set
// by 'admin_path' (default "admin"); every other address, including /admin.php, gets
// "Not found", so the page can't be found by guessing.

declare(strict_types=1);

$root = dirname(__DIR__);
require "$root/src/WordFamilies.php";
require "$root/src/Dictionary.php";
require "$root/src/Prompts.php";
require "$root/src/Scoring.php";
require "$root/src/StateCache.php";
require "$root/src/Game.php";
require "$root/src/PrivateWorlds.php";
require "$root/src/Admin.php";
require "$root/src/Housekeeping.php";

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function page(string $title, string $body): never
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . h($title) . ' · Unsaid</title>'
        . '<link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/style.css">'
        . '<link rel="stylesheet" href="/admin.css"></head><body>'
        . '<header class="top"><a class="brand" href="/">Unsaid</a><span class="world-name">Admin</span></header>'
        . '<main class="admin">' . $body . '</main></body></html>';
    exit;
}

$configPath = getenv('UNSAID_CONFIG') ?: "$root/config.php";
$config = is_file($configPath) ? require $configPath : [];
$password = (string) ($config['admin_password'] ?? '');
$adminPath = trim((string) ($config['admin_path'] ?? 'admin'), '/');
$adminUrl = '/' . $adminPath;
$requested = trim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if ($password === '' || !preg_match('/^[A-Za-z0-9_-]{4,64}$/', $adminPath) || !hash_equals($adminPath, $requested)) {
    // Off, misconfigured (check.php says which), or the wrong address: look like any missing page.
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
]);
session_name('unsaid_admin');
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
$flash = '';

// --- Logging in and out ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (hash_equals($password, (string) ($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        header("Location: $adminUrl", true, 303);
        exit;
    }
    sleep(2); // Slows down password guessing.
    $flash = 'Wrong password.';
}
if (empty($_SESSION['admin'])) {
    page('Admin login', ($flash ? '<p class="flash bad">' . h($flash) . '</p>' : '')
        . '<h1>Admin</h1><form method="post" class="admin-login">'
        . '<input type="hidden" name="action" value="login">'
        . '<input type="password" name="password" placeholder="Admin password" autofocus required>'
        . '<button type="submit">Log in</button></form>');
}

// --- Actions ---

try {
    $db = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    Game::installSchema($db); // Cheap, and makes sure the admin tables exist.
    $dictionary = Dictionary::load();
    $admin = new Admin($db, $dictionary);

    // The open reports as a text file, to work through and fold into the word files.
    if (($_GET['download'] ?? '') === 'reports') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="unsaid-reports-' . date('Y-m-d') . '.txt"');
        header('Cache-Control: no-store');
        echo $admin->reportsText();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) throw new GameError(400, 'This page was out of date. Please try again.');
        $action = (string) ($_POST['action'] ?? '');
        switch ($action) {
            case 'logout':
                $_SESSION = [];
                session_destroy();
                header("Location: $adminUrl", true, 303);
                exit;
            case 'reviewed':
                $count = $admin->markReviewed();
                $_SESSION['flash'] = "$count " . ($count === 1 ? 'report' : 'reports') . ' marked reviewed.';
                break;
            case 'housekeeping':
                $cacheDir = $config['cache_dir'] ?? "$root/cache";
                (new Housekeeping(
                    $db, (int) ($config['housekeeping']['keep_days'] ?? 30), $cacheDir ?: null,
                    game: new Game($db, $dictionary), idleDays: (int) ($config['housekeeping']['idle_days'] ?? 60),
                ))->run();
                $_SESSION['flash'] = 'Housekeeping done.';
                break;
            case 'plan':
                $plan = (string) ($_POST['plan'] ?? '');
                $name = $admin->setPlan((int) ($_POST['user'] ?? 0), $plan);
                $_SESSION['flash'] = "$name is now on the " . ucfirst($plan) . ' plan.';
                break;
            case 'recount':
                Game::recountScores($db);
                $_SESSION['flash'] = 'Scores recounted.';
                break;
        }
        header("Location: $adminUrl", true, 303); // So refreshing the page doesn't repeat the action.
        exit;
    }
} catch (GameError $e) {
    $_SESSION['flash'] = $e->getMessage();
    $_SESSION['flashBad'] = true;
    header("Location: $adminUrl", true, 303);
    exit;
} catch (Throwable $e) {
    error_log('Unsaid admin: ' . $e);
    page('Admin', '<p class="flash bad">Something went wrong. See the error_log file, or visit /check.php.</p>');
}

$flash = $_SESSION['flash'] ?? '';
$flashBad = !empty($_SESSION['flashBad']);
unset($_SESSION['flash'], $_SESSION['flashBad']);

// --- Page ---

function hidden(array $fields): string
{
    $out = '';
    foreach ($fields as $name => $value) $out .= '<input type="hidden" name="' . h($name) . '" value="' . h((string) $value) . '">';
    return $out;
}

function button(string $csrf, string $action, string $label, array $fields = [], string $class = ''): string
{
    return '<form method="post" class="inline">' . hidden(['csrf' => $csrf, 'action' => $action] + $fields)
        . '<button type="submit" class="' . h($class) . '">' . h($label) . '</button></form>';
}

$stats = $admin->stats();
$reports = $admin->openReports();
$when = fn (int $ms) => date('j M, H:i', intdiv($ms, 1000));

$html = $flash ? '<p class="flash ' . ($flashBad ? 'bad' : 'good') . '">' . h($flash) . '</p>' : '';
$html .= '<div class="admin-head"><h1>Admin</h1>' . button($csrf, 'logout', 'Log out', [], 'link') . '</div>';

$html .= '<div class="stat-row">';
foreach ([
    'Accounts' => $stats['accounts'], 'Active today' => $stats['activeToday'], 'Active this week' => $stats['activeWeek'],
    'Worlds' => $stats['worlds'], 'Words said (24h)' => $stats['saidToday'],
    'Misses (24h)' => $stats['missesToday'], 'Open reports' => $stats['openReports'],
] as $label => $value) {
    $html .= '<div class="stat"><b>' . number_format($value) . '</b><span>' . h($label) . '</span></div>';
}
$html .= '</div>';

$html .= '<h2>Reported words</h2>'
    . '<p class="muted">Reports never change the game by themselves: the word files are the only source of truth. '
    . '<b>Download</b> the open reports, add the words you approve to <code>data/categories/</code> (or '
    . '<code>data/extra-words.txt</code>), rebuild and upload <code>data/dictionary.php</code>, then press '
    . '<b>Mark all reviewed</b>. Players then see “accepted” for words that now count, and “reviewed” for the rest.</p>';
$legacy = $admin->legacyAccepted();
if ($reports || $legacy) {
    $html .= '<p><a class="button-link" href="' . h($adminUrl) . '?download=reports">Download reports</a> '
        . button($csrf, 'reviewed', 'Mark all reviewed', [], 'secondary') . '</p>';
}
if (!$reports) {
    $html .= '<p class="muted">No reports waiting. When players press “Report it”, the words appear here.</p>';
} else {
    $html .= ''
        . '<div class="table-wrap"><table class="admin-table">'
        . '<thead><tr><th>Word</th><th>Reported for</th><th>Players</th><th>Prompts</th></tr></thead><tbody>';
    foreach ($reports as $r) {
        $note = $r['counts'] ? '<br><span class="good small">counts now</span>'
            : ($r['inDictionary'] ? '' : '<br><span class="muted small">not in dictionary yet</span>');
        $html .= '<tr><td><b>' . h($r['word']) . '</b>' . $note . '</td>'
            . '<td>' . h($r['categoryLabel']) . '</td>'
            . '<td>' . $r['players'] . '</td>'
            . '<td class="muted small">' . implode('<br>', array_map('h', $r['prompts'])) . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
}
if ($legacy) {
    $one = count($legacy) === 1;
    $html .= '<p class="bad small">' . count($legacy) . ($one ? ' word' : ' words') . ' accepted on this page before '
        . ($one ? 'is' : 'are') . ' not in the word files, so ' . ($one ? 'it no longer counts' : 'they no longer count') . ': '
        . h(implode(', ', array_column($legacy, 'word'))) . '. ' . ($one ? 'It is' : 'They are') . ' at the end of the download. '
        . 'Add ' . ($one ? 'it' : 'any you want') . ' to the files; <b>Mark all reviewed</b> then clears this list.</p>';
}

$html .= '<h2>Scores</h2><p class="muted">Each player\'s total is kept as a running count. If you change the '
    . '<code>burns</code> or <code>misses</code> tables by hand (in phpMyAdmin), recount so the leaderboards match.</p>'
    . button($csrf, 'recount', 'Recount scores', [], 'secondary');

// Plans: set by hand until payments are added.
$search = trim((string) ($_GET['account'] ?? ''));
$accounts = $admin->accounts($search);
$planNames = ['free' => 'Free', 'host' => 'Host: private worlds', 'organiser' => 'Organiser: private worlds and competitions'];
$html .= '<h2>Accounts and plans</h2>'
    . '<form method="get" class="admin-add" id="account-search"><input name="account" value="' . h($search) . '" placeholder="Name or email">'
    . '<button type="submit">Find</button></form>'
    . '<p class="muted small">' . ($search === '' ? 'Accounts on a paid plan:' : 'Matching accounts:') . '</p>';
if (!$accounts) {
    $html .= '<p class="muted">' . ($search === '' ? 'None yet. Find an account to change its plan.' : 'No accounts found.') . '</p>';
} else {
    $html .= '<div class="table-wrap"><table class="admin-table"><thead><tr><th>Name</th><th>Email</th><th>Plan</th></tr></thead><tbody>';
    foreach ($accounts as $a) {
        $options = '';
        foreach ($planNames as $value => $label) {
            $options .= '<option value="' . $value . '"' . ($a['plan'] === $value ? ' selected' : '') . '>' . h($label) . '</option>';
        }
        $html .= '<tr><td><b>' . h($a['name']) . '</b></td><td class="muted small">' . h($a['email']) . '</td><td>'
            . '<form method="post" class="inline plan-form">' . hidden(['csrf' => $csrf, 'action' => 'plan', 'user' => (string) $a['id']])
            . '<select name="plan">' . $options . '</select> <button type="submit" class="secondary">Save</button></form></td></tr>';
    }
    $html .= '</tbody></table></div>';
}

$lastRun = Housekeeping::lastRun($db);
$html .= '<h2>Housekeeping</h2>';
if ($lastRun) {
    $labels = [
        'signInCodes' => 'old sign-in codes', 'sessions' => 'expired sessions', 'freeMisses' => 'old free misses',
        'reviewedReports' => 'old reviewed reports',
        'finishedWorlds' => 'finished worlds tidied', 'words' => 'their words', 'misses' => 'their misses', 'cacheFiles' => 'cache files',
        'idleWorldsEnded' => 'idle private worlds ended', 'overdueWorldsFinished' => 'finished competitions and rounds recorded',
    ];
    $parts = [];
    foreach ($lastRun['done'] as $key => $count) $parts[] = $count . ' ' . ($labels[$key] ?? $key);
    $stale = $lastRun['at'] < (int) floor(microtime(true) * 1000) - 2 * 86_400_000;
    $html .= '<p class="' . ($stale ? 'bad' : 'muted') . '">Last ran ' . h($when((int) $lastRun['at'])) . ($stale ? ': more than two days ago. Is the cron job still set up?' : '') . '</p>'
        . '<p class="muted small">Removed: ' . h(implode(', ', $parts)) . '.</p>';
} else {
    $html .= '<p class="bad">Never run. Set up the daily cron job (see docs/DEPLOY.md, "Housekeeping").</p>';
}
$html .= button($csrf, 'housekeeping', 'Run housekeeping now', [], 'secondary');

page('Admin', $html);
