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
require "$root/src/Game.php";
require "$root/src/Admin.php";

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

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) throw new GameError(400, 'This page was out of date. Please try again.');
        $action = (string) ($_POST['action'] ?? '');
        $category = (string) ($_POST['category'] ?? '');
        $word = (string) ($_POST['word'] ?? '');
        switch ($action) {
            case 'logout':
                $_SESSION = [];
                session_destroy();
                header("Location: $adminUrl", true, 303);
                exit;
            case 'accept':
            case 'add':
                $admin->decide($category, $word, 'accepted');
                $_SESSION['flash'] = "Accepted “{$word}”. It counts from now on.";
                break;
            case 'dismiss':
                $admin->decide($category, $word, 'dismissed');
                $_SESSION['flash'] = "Dismissed “{$word}”.";
                break;
            case 'undo':
                $admin->undo($category, $word);
                $_SESSION['flash'] = "Undone: “{$word}”.";
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
$decisions = $admin->decisions();
$when = fn (int $ms) => date('j M, H:i', intdiv($ms, 1000));

$html = $flash ? '<p class="flash ' . ($flashBad ? 'bad' : 'good') . '">' . h($flash) . '</p>' : '';
$html .= '<div class="admin-head"><h1>Admin</h1>' . button($csrf, 'logout', 'Log out', [], 'link') . '</div>';

$html .= '<div class="stat-row">';
foreach ([
    'Accounts' => $stats['accounts'], 'Worlds' => $stats['worlds'], 'Players' => $stats['players'], 'Words said (24h)' => $stats['saidToday'],
    'Misses (24h)' => $stats['missesToday'], 'Open reports' => $stats['openReports'],
] as $label => $value) {
    $html .= '<div class="stat"><b>' . number_format($value) . '</b><span>' . h($label) . '</span></div>';
}
$html .= '</div>';

$html .= '<h2>Reported words</h2>';
if (!$reports) {
    $html .= '<p class="muted">No reports waiting. When players press “Report it”, the words appear here.</p>';
} else {
    $html .= '<p class="muted">Accept a word to make it count straight away. Dismiss it if it shouldn’t.</p><div class="table-wrap"><table class="admin-table">'
        . '<thead><tr><th>Word</th><th>Should count as</th><th>Reports</th><th>Prompts</th><th></th></tr></thead><tbody>';
    foreach ($reports as $r) {
        $fields = ['category' => $r['category'], 'word' => $r['word']];
        $html .= '<tr><td><b>' . h($r['word']) . '</b>' . ($r['inDictionary'] ? '' : '<br><span class="muted small">not in dictionary yet</span>') . '</td>'
            . '<td>' . h($r['categoryLabel']) . '</td>'
            . '<td>' . $r['reports'] . ($r['players'] > 1 ? ' <span class="muted">(' . $r['players'] . ' players)</span>' : '') . '</td>'
            . '<td class="muted small">' . implode('<br>', array_map('h', $r['prompts'])) . '</td>'
            . '<td class="actions">' . button($csrf, 'accept', 'Accept', $fields) . button($csrf, 'dismiss', 'Dismiss', $fields, 'secondary') . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
}

$options = '<option value="">Dictionary only (no meaning)</option>';
foreach ($admin->categories() as $id => $label) $options .= '<option value="' . h($id) . '">' . h($label) . '</option>';
$html .= '<h2>Add a word</h2><p class="muted">For a word nobody has reported yet.</p>'
    . '<form method="post" class="admin-add">' . hidden(['csrf' => $csrf, 'action' => 'add'])
    . '<input name="word" placeholder="Word" required pattern="[A-Za-z]{3,30}" title="3–30 letters">'
    . '<select name="category">' . $options . '</select><button type="submit">Add</button></form>';

$html .= '<h2>Decisions</h2>';
if (!$decisions) {
    $html .= '<p class="muted">None yet.</p>';
} else {
    $html .= '<div class="table-wrap"><table class="admin-table"><thead><tr><th>Word</th><th>Category</th><th>Decision</th><th>When</th><th></th></tr></thead><tbody>';
    foreach ($decisions as $d) {
        $html .= '<tr><td><b>' . h($d['word']) . '</b></td><td>' . h($d['categoryLabel']) . '</td>'
            . '<td class="' . ($d['decision'] === 'accepted' ? 'good' : 'muted') . '">' . h(ucfirst($d['decision'])) . '</td>'
            . '<td class="muted small">' . h($when($d['decidedAt'])) . '</td>'
            . '<td class="actions">' . button($csrf, 'undo', 'Undo', ['category' => $d['category'], 'word' => $d['word']], 'link') . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
}
$html .= '<p class="muted small">Accepted words are kept in the database. To make them permanent in the word files, '
    . 'add them to <code>data/category-overrides.txt</code> or <code>data/extra-words.txt</code> when the game is next updated.</p>';

page('Admin', $html);
