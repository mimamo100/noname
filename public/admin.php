<?php
// Admin pages, in tabs: an overview (stats, emails, housekeeping), reported words, accounts and
// plans, organisers' word packs, and helpers.
// Turn them on by setting 'admin_password' in config.php. They answer only at the address set
// by 'admin_path' (default "admin"); every other address, including /admin.php, gets
// "Not found", so the pages can't be found by guessing.
//
// Two kinds of admin: the super-admin, who logs in with config.php's password and can do
// everything, and helpers: game accounts the super-admin adds in the Helpers tab, each with
// their own permissions (Admin::PERMISSIONS). A helper opens Admin from their account menu in
// the game, which posts their sign-in here; removing them or a permission takes effect at once.

declare(strict_types=1);

$root = dirname(__DIR__);
require "$root/src/WordFamilies.php";
require "$root/src/Dictionary.php";
require "$root/src/Prompts.php";
require "$root/src/Scoring.php";
require "$root/src/StateCache.php";
require "$root/src/Streaks.php";
require "$root/src/Game.php";
require "$root/src/PrivateWorlds.php";
require "$root/src/Competitions.php";
require "$root/src/Packs.php";
require "$root/src/Admin.php";
require "$root/src/Mailer.php";
require "$root/src/Auth.php";
require "$root/src/Housekeeping.php";
require "$root/src/Reminders.php";

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

try {
    $db = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    Game::installSchema($db); // Cheap, and makes sure the admin tables exist.
    $dictionary = Dictionary::load();
} catch (Throwable $e) {
    error_log('Unsaid admin: ' . $e);
    page('Admin', '<p class="flash bad">Something went wrong. See the error_log file, or visit /check.php.</p>');
}

// --- Logging in and out ---

$loginAction = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';
if ($loginAction === 'login') {
    if (hash_equals($password, (string) ($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION = ['csrf' => $csrf, 'admin' => true];
        header("Location: $adminUrl", true, 303);
        exit;
    }
    sleep(2); // Slows down password guessing.
    $flash = 'Wrong password.';
}
if ($loginAction === 'account') {
    // From the game's account menu: the account's sign-in, checked like any API request.
    $user = (new Auth($db, new Mailer($config['mail'] ?? []), $dictionary))->user((string) ($_POST['token'] ?? ''));
    if (!empty($_SESSION['admin'])) {
        header("Location: $adminUrl", true, 303); // The super-admin is already in.
        exit;
    }
    if ($user && Admin::helperPermissions($db, $user['id']) !== null) {
        session_regenerate_id(true);
        $_SESSION = ['csrf' => $csrf, 'helper' => $user['id']];
        header("Location: $adminUrl", true, 303);
        exit;
    }
    $flash = 'That account isn\'t an admin helper.';
}

$super = !empty($_SESSION['admin']);
$helper = null;
$permissions = [];
if (!$super && !empty($_SESSION['helper'])) {
    $stmt = $db->prepare('SELECT id, name FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['helper']]);
    $helper = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $permissions = $helper ? Admin::helperPermissions($db, (int) $helper['id']) : null;
    if ($permissions === null) {
        // Removed as a helper (or the account deleted) since logging in.
        $_SESSION = ['csrf' => $csrf];
        $helper = null;
        $flash = 'You\'re no longer an admin helper.';
    }
}
if (!$super && !$helper) {
    page('Admin login', ($flash ? '<p class="flash bad">' . h($flash) . '</p>' : '')
        . '<h1>Admin</h1><form method="post" class="admin-login">'
        . '<input type="hidden" name="action" value="login">'
        . '<input type="password" name="password" placeholder="Admin password" autofocus required>'
        . '<button type="submit">Log in</button></form>'
        . '<p class="muted">Helpers: open <b>Admin</b> from your account menu in the game, signed in to your account.</p>');
}

/** Whether this admin may do something: the super-admin may do everything. */
$can = fn (string ...$any) => $super || array_intersect($any, $permissions);

// The tabs this admin can see, each with the permissions that open it.
$tabs = array_filter([
    'overview' => $can('overview', 'maintenance') ? 'Overview' : null,
    'reports' => $can('reports', 'review') ? 'Reports' : null,
    'accounts' => $can('accounts', 'emails', 'plans') ? 'Accounts' : null,
    'packs' => $can('packs') ? 'Word packs' : null,
    'helpers' => $super ? 'Helpers' : null,
]);
$tab = (string) ($_REQUEST['tab'] ?? '');
if (!isset($tabs[$tab])) $tab = (string) array_key_first($tabs);
$tabUrl = fn (string $t, array $query = []) => $adminUrl . '?' . http_build_query(['tab' => $t] + $query);

// --- Actions ---

$cacheDir = $config['cache_dir'] ?? "$root/cache";
$packs = new Packs($db, $cacheDir ?: null);
$admin = new Admin($db, $dictionary, packs: $packs);
$denied = fn () => throw new GameError(403, 'You don\'t have permission to do that.');

try {
    // The open reports as a text file, to work through and fold into the word files.
    if (($_GET['download'] ?? '') === 'reports') {
        $can('reports') || $denied();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="unsaid-reports-' . date('Y-m-d') . '.txt"');
        header('Cache-Control: no-store');
        echo $admin->reportsText();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) throw new GameError(400, 'This page was out of date. Please try again.');
        $action = (string) ($_POST['action'] ?? '');
        $userId = (int) ($_POST['user'] ?? 0);
        switch ($action) {
            case 'logout':
                $_SESSION = [];
                session_destroy();
                header("Location: $adminUrl", true, 303);
                exit;
            case 'reviewed':
                $can('review') || $denied();
                $count = $admin->markReviewed();
                $_SESSION['flash'] = "$count " . ($count === 1 ? 'report' : 'reports') . ' marked reviewed.';
                break;
            case 'housekeeping':
                $can('maintenance') || $denied();
                (new Housekeeping(
                    $db, (int) ($config['housekeeping']['keep_days'] ?? 30), $cacheDir ?: null,
                    game: new Game($db, $dictionary), idleDays: (int) ($config['housekeeping']['idle_days'] ?? 60),
                ))->run();
                $_SESSION['flash'] = 'Housekeeping done.';
                break;
            case 'recount':
                $can('maintenance') || $denied();
                Game::recountScores($db);
                $_SESSION['flash'] = 'Scores recounted.';
                break;
            case 'plan':
                $can('plans') || $denied();
                $plan = (string) ($_POST['plan'] ?? '');
                $name = $admin->setPlan($userId, $plan);
                $_SESSION['flash'] = "$name is now on the " . ucfirst($plan) . ' plan.';
                break;
            case 'removepack':
                $can('packs') || $denied();
                $packs->remove((string) ($_POST['pack'] ?? ''));
                $_SESSION['flash'] = 'Pack removed. Rooms that used it now play everyday words.';
                break;
            case 'addhelper':
                $super || $denied();
                $_SESSION['flash'] = $admin->addHelper($userId) . ' is now a helper. Tick what they can do below.';
                break;
            case 'savehelper':
                $super || $denied();
                $admin->setHelperPermissions($userId, array_map('strval', (array) ($_POST['permissions'] ?? [])));
                $_SESSION['flash'] = 'Saved.';
                break;
            case 'removehelper':
                $super || $denied();
                $admin->removeHelper($userId);
                $_SESSION['flash'] = 'Helper removed.';
                break;
        }
        header('Location: ' . $tabUrl($tab), true, 303); // So refreshing the page doesn't repeat the action.
        exit;
    }
} catch (GameError $e) {
    $_SESSION['flash'] = $e->getMessage();
    $_SESSION['flashBad'] = true;
    header('Location: ' . $tabUrl($tab), true, 303);
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

/** A one-button form for an action, returning to the current tab. */
$button = function (string $action, string $label, array $fields = [], string $class = '') use ($csrf, $tab): string {
    return '<form method="post" class="inline">' . hidden(['csrf' => $csrf, 'action' => $action, 'tab' => $tab] + $fields)
        . '<button type="submit" class="' . h($class) . '">' . h($label) . '</button></form>';
};

// Times in the big world's timezone (UK time by default), not the server's.
date_default_timezone_set($config['big_world']['timezone'] ?? 'Europe/London');
$when = fn (int $ms) => date('j M, H:i', intdiv($ms, 1000));
$stats = fn (array $items) => '<div class="stat-row">' . implode('', array_map(
    fn ($label, $value) => '<div class="stat"><b>' . number_format($value) . '</b><span>' . h($label) . '</span></div>',
    array_keys($items), $items,
)) . '</div>';

$html = $flash ? '<p class="flash ' . ($flashBad ? 'bad' : 'good') . '">' . h($flash) . '</p>' : '';
$html .= '<div class="admin-head"><h1>Admin</h1><span class="muted small">'
    . ($super ? 'Super-admin' : 'Helper: ' . h($helper['name'])) . ' · ' . $button('logout', 'Log out', [], 'link') . '</span></div>';
$html .= '<nav class="tabs admin-tabs">';
foreach ($tabs as $key => $label) {
    $html .= '<a class="tab" href="' . h($tabUrl($key)) . '" aria-selected="' . ($key === $tab ? 'true' : 'false') . '">' . h($label) . '</a>';
}
$html .= '</nav>';
if (!$tabs) $html .= '<p class="muted">You don\'t have any admin permissions yet. Ask the admin to tick some for you.</p>';

if ($tab === 'overview') {
    if ($can('overview')) {
        $s = $admin->stats();
        $html .= '<h2>Today</h2>' . $stats([
            'Accounts' => $s['accounts'], 'Active today' => $s['activeToday'], 'Active this week' => $s['activeWeek'],
            'Rooms' => $s['worlds'], 'Words said (24h)' => $s['saidToday'],
            'Misses (24h)' => $s['missesToday'], 'Open reports' => $s['openReports'],
        ]);

        // Emails, against the host's sending limit.
        $emails = $admin->emailStats();
        $perHour = (int) ($config['daily_email']['per_hour'] ?? 150);
        $fromHour = (int) ($config['daily_email']['from_hour'] ?? 7);
        $html .= '<h2>Emails</h2>'
            . '<p class="muted">Your hosting company limits how many emails the site can send each hour. Sign-in codes and the '
            . 'daily email share that limit, so the daily email goes in hourly batches of up to ' . number_format($perHour)
            . ', from ' . sprintf('%d:00', $fromHour) . ' (<code>daily_email</code> in config.php).</p>'
            . $stats([
                'Sign-in emails (last hour)' => $emails['codesHour'], 'Sign-in emails (24h)' => $emails['codesDay'],
                'Busiest hour (24h)' => $emails['busiestHour'], 'Daily email turned on' => $emails['remindersOn'],
                'Daily emails per hour' => $perHour,
            ]);
        $last = $emails['lastDaily'];
        if (empty($config['site_url'])) {
            $html .= '<p class="bad">The daily email is off: set <code>site_url</code> in config.php (see docs/DEPLOY.md, "Daily email").</p>';
        } elseif (!$last && $emails['remindersOn'] > 0) {
            $html .= '<p class="bad">The daily email hasn\'t been sent yet. Is its hourly cron job set up (docs/DEPLOY.md, "Daily email")?</p>';
        }
        if ($last && isset($last['sentToday'])) {
            $html .= '<p class="muted">Last batch ' . h($when((int) $last['at'])) . ': ' . number_format($last['sent'])
                . ' sent, ' . number_format($last['sentToday']) . ' that day in all.</p>';
            if ($last['refused']) {
                $html .= '<p class="bad">The mail server refused one, probably your host\'s hourly limit: lower <code>per_hour</code>. '
                    . 'The rest go in the next batches. cPanel\'s Track Delivery (under Email) shows why.</p>';
            } elseif ($last['due'] > 0) {
                $html .= '<p class="muted">' . number_format($last['due']) . ' still to go, in the next hourly batches.</p>';
            }
        }
    }

    $lastRun = Housekeeping::lastRun($db);
    $html .= '<h2>Housekeeping</h2>';
    if ($lastRun) {
        $labels = [
            'signInCodes' => 'old sign-in codes', 'sessions' => 'expired sessions', 'freeMisses' => 'old free misses',
            'reviewedReports' => 'old reviewed reports',
            'finishedWorlds' => 'finished rooms tidied', 'words' => 'their words', 'misses' => 'their misses', 'cacheFiles' => 'cache files',
            'idleWorldsEnded' => 'idle private rooms ended', 'overdueWorldsFinished' => 'finished competitions and rounds recorded',
        ];
        $parts = [];
        foreach ($lastRun['done'] as $key => $count) $parts[] = $count . ' ' . ($labels[$key] ?? $key);
        $stale = $lastRun['at'] < (int) floor(microtime(true) * 1000) - 2 * 86_400_000;
        $html .= '<p class="' . ($stale ? 'bad' : 'muted') . '">Last ran ' . h($when((int) $lastRun['at'])) . ($stale ? ': more than two days ago. Is the cron job still set up?' : '') . '</p>'
            . '<p class="muted small">Removed: ' . h(implode(', ', $parts)) . '.</p>';
    } else {
        $html .= '<p class="bad">Never run. Set up the daily cron job (see docs/DEPLOY.md, "Housekeeping").</p>';
    }
    if ($can('maintenance')) {
        $html .= '<p>' . $button('housekeeping', 'Run housekeeping now', [], 'secondary') . '</p>'
            . '<h2>Scores</h2><p class="muted">Each player\'s total is kept as a running count. If you change the '
            . '<code>burns</code> or <code>misses</code> tables by hand (in phpMyAdmin), recount so the leaderboards match.</p>'
            . $button('recount', 'Recount scores', [], 'secondary');
    }
}

if ($tab === 'reports') {
    $reports = $admin->openReports();
    $legacy = $admin->legacyAccepted();
    $html .= '<h2>Reported words</h2>'
        . '<p class="muted">Reports never change the game by themselves: the word files are the only source of truth. '
        . '<b>Download</b> the open reports, add the words you approve to <code>data/categories/</code> (or '
        . '<code>data/extra-words.txt</code>), rebuild and upload <code>data/dictionary.php</code>, then press '
        . '<b>Mark all reviewed</b>. Players then see “accepted” for words that now count, and “reviewed” for the rest. '
        . 'Upload before marking, or accepted words show as reviewed.</p>';
    if ($reports || $legacy) {
        $html .= '<p>' . ($can('reports') ? '<a class="button-link" href="' . h($tabUrl('reports', ['download' => 'reports'])) . '">Download reports</a> ' : '')
            . ($can('review') ? $button('reviewed', 'Mark all reviewed', [], 'secondary') : '') . '</p>';
    }
    if (!$reports) {
        $html .= '<p class="muted">No reports waiting. When players press “Report it”, the words appear here.</p>';
    } else {
        $html .= '<div class="table-wrap"><table class="admin-table">'
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
}

if ($tab === 'accounts') {
    // Plans: set by hand until payments are added.
    $byEmail = (bool) $can('emails');
    $search = trim((string) ($_GET['account'] ?? ''));
    $accounts = $admin->accounts($search, $byEmail);
    $planNames = ['free' => 'Free', 'host' => 'Host: private rooms', 'organiser' => 'Organiser: private rooms and competitions'];
    $html .= '<h2>Accounts and plans</h2>'
        . '<form method="get" class="admin-add">' . hidden(['tab' => 'accounts'])
        . '<input name="account" value="' . h($search) . '" placeholder="' . ($byEmail ? 'Name or email' : 'Name') . '">'
        . '<button type="submit">Find</button></form>'
        . '<p class="muted small">' . ($search === '' ? 'Accounts on a paid plan:' : 'Matching accounts:') . '</p>';
    if (!$accounts) {
        $html .= '<p class="muted">' . ($search === '' ? 'None yet. Find an account to see its plan.' : 'No accounts found.') . '</p>';
    } else {
        $html .= '<div class="table-wrap"><table class="admin-table"><thead><tr><th>Name</th>' . ($byEmail ? '<th>Email</th>' : '') . '<th>Plan</th></tr></thead><tbody>';
        foreach ($accounts as $a) {
            if ($can('plans')) {
                $options = '';
                foreach ($planNames as $value => $label) {
                    $options .= '<option value="' . $value . '"' . ($a['plan'] === $value ? ' selected' : '') . '>' . h($label) . '</option>';
                }
                $plan = '<form method="post" class="inline plan-form">' . hidden(['csrf' => $csrf, 'action' => 'plan', 'tab' => 'accounts', 'user' => $a['id']])
                    . '<select name="plan">' . $options . '</select> <button type="submit" class="secondary">Save</button></form>';
            } else {
                $plan = h($planNames[$a['plan']] ?? $a['plan']);
            }
            $html .= '<tr><td><b>' . h($a['name']) . '</b></td>' . ($byEmail ? '<td class="muted small">' . h($a['email']) . '</td>' : '')
                . '<td>' . $plan . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
}

if ($tab === 'packs') {
    // Organisers' own word packs, so a bad one can be taken down.
    $customPacks = $packs->all();
    $html .= '<h2>Organisers\' word packs</h2>';
    if (!$customPacks) {
        $html .= '<p class="muted">None yet.</p>';
    } else {
        $html .= '<div class="table-wrap"><table class="admin-table"><thead><tr><th>Pack</th><th>Owner</th><th>Words</th><th>Changed</th><th></th></tr></thead><tbody>';
        foreach ($customPacks as $pk) {
            $html .= '<tr><td><b>' . h($pk['name']) . '</b></td><td>' . h((string) $pk['owner']) . '</td><td>' . (int) $pk['words'] . '</td>'
                . '<td class="muted small">' . h($when((int) $pk['updated_at'])) . '</td>'
                . '<td class="actions">' . $button('removepack', 'Remove', ['pack' => $pk['id']], 'link') . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
}

if ($tab === 'helpers') {
    $helpers = $admin->helpers();
    $html .= '<h2>Helpers</h2>'
        . '<p class="muted">Helpers are game accounts you let into these pages, each with only what you tick. They open '
        . '<b>Admin</b> from their account menu in the game, so they need no password. Removing a helper, or a tick, takes '
        . 'effect at once. You stay the only one who can manage helpers.</p>';
    if (!$helpers) {
        $html .= '<p class="muted">No helpers yet. Find an account below to add one.</p>';
    }
    foreach ($helpers as $hp) {
        $boxes = '';
        foreach (Admin::PERMISSIONS as $key => $label) {
            $boxes .= '<label><input type="checkbox" name="permissions[]" value="' . h($key) . '"'
                . (in_array($key, $hp['permissions'], true) ? ' checked' : '') . '> ' . h($label) . '</label>';
        }
        $html .= '<div class="helper"><div class="helper-head"><b>' . h($hp['name']) . '</b> <span class="muted small">'
            . h($hp['email']) . ' · added ' . h($when($hp['addedAt'])) . '</span> '
            . $button('removehelper', 'Remove', ['user' => $hp['id']], 'link') . '</div>'
            . '<form method="post" class="perm-list">' . hidden(['csrf' => $csrf, 'action' => 'savehelper', 'tab' => 'helpers', 'user' => $hp['id']])
            . $boxes . '<button type="submit" class="secondary">Save</button></form></div>';
    }
    $find = trim((string) ($_GET['find'] ?? ''));
    $html .= '<h2>Add a helper</h2>'
        . '<form method="get" class="admin-add">' . hidden(['tab' => 'helpers'])
        . '<input name="find" value="' . h($find) . '" placeholder="Their name or email in the game">'
        . '<button type="submit">Find</button></form>';
    if ($find !== '') {
        $isHelper = array_flip(array_column($helpers, 'id'));
        $found = $admin->accounts($find);
        if (!$found) $html .= '<p class="muted">No accounts found.</p>';
        foreach ($found as $a) {
            $html .= '<p><b>' . h($a['name']) . '</b> <span class="muted small">' . h($a['email']) . '</span> '
                . (isset($isHelper[$a['id']]) ? '<span class="muted small">already a helper</span>'
                    : $button('addhelper', 'Make helper', ['user' => $a['id']], 'secondary')) . '</p>';
        }
    }
}

page('Admin', $html);
