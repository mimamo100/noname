<?php
// JSON API for Unsaid. Apache sends every /api/... request here (see .htaccess).
// Signed-in requests send the session token in an X-Session-Token header.
//
//   POST /api/auth/start                  {email}                  email a sign-in code
//   POST /api/auth/verify                 {email, code, name?, claim?}  sign in; new accounts also send a name
//   POST /api/auth/logout                                          sign this device out
//   GET  /api/auth/me                                              the signed-in account, or null
//
//   POST /api/worlds                      {name, spelling}  create a private world (account needed)
//   GET  /api/worlds/{id}                                   world state (anyone can watch)
//   POST /api/worlds/{id}/join                              join with the account's name (account needed)
//   POST /api/worlds/{id}/words           {word}            say a word, joining first if needed (account needed)
//   GET  /api/worlds/{id}/words/{word}                      who said this word?
//   POST /api/worlds/{id}/reports         {word}            report a missing word (account needed)

declare(strict_types=1);

$root = dirname(__DIR__);
require "$root/src/WordFamilies.php";
require "$root/src/Dictionary.php";
require "$root/src/Prompts.php";
require "$root/src/Scoring.php";
require "$root/src/Game.php";
require "$root/src/Admin.php";
require "$root/src/Mailer.php";
require "$root/src/Auth.php";

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array
{
    $raw = file_get_contents('php://input', length: 10_000);
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new GameError(400, 'Invalid JSON');
    return $data;
}

function route(Game $game, Auth $auth): never
{
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $parts = array_map('rawurldecode', array_values(array_filter(explode('/', $path), 'strlen')));
    // Expect: api, worlds, {id}, {action}, {arg}   or   api, auth, {action}
    [$api, $resource, $worldId, $action, $arg] = array_pad($parts, 5, null);
    if ($api !== 'api') throw new GameError(404, 'Not found');
    $session = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? null;
    $user = $auth->user($session);
    $needUser = fn () => $user ?? throw new GameError(401, 'Please sign in to play');

    if ($resource === 'auth') {
        $authAction = $worldId; // api/auth/{action}
        if ($method === 'POST' && $authAction === 'start') {
            $auth->start((string) (body()['email'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? '');
            respond(200, ['ok' => true]);
        }
        if ($method === 'POST' && $authAction === 'verify') {
            $b = body();
            $name = isset($b['name']) ? (string) $b['name'] : null;
            respond(200, $auth->verify((string) ($b['email'] ?? ''), (string) ($b['code'] ?? ''), $name, (array) ($b['claim'] ?? [])));
        }
        if ($method === 'POST' && $authAction === 'logout') {
            $auth->logout($session);
            respond(200, ['ok' => true]);
        }
        if ($method === 'GET' && $authAction === 'me') respond(200, ['user' => $user]);
        throw new GameError(404, 'Not found');
    }

    if ($resource !== 'worlds') throw new GameError(404, 'Not found');
    $game->applyWordDecisions();
    if ($worldId === Game::PUBLIC_WORLD_ID) $game->ensureWorld(Game::PUBLIC_WORLD_ID, 'The Public World');

    if ($method === 'POST' && $worldId === null) {
        $owner = $needUser();
        $b = body();
        respond(201, $game->createWorld((string) ($b['name'] ?? ''), null, Game::DEFAULT_PROMPT_DURATION_MS, (string) ($b['spelling'] ?? 'both'), $owner['id']));
    }
    if ($worldId === null) throw new GameError(404, 'Not found');
    if ($method === 'GET' && $action === null) {
        $player = $user ? $game->playerForUser($worldId, $user['id']) : null;
        respond(200, $game->state($worldId, $player['token'] ?? null) + ['account' => $user ? ['name' => $user['name']] : null]);
    }
    if ($method === 'POST' && $action === 'join') {
        $player = $game->joinAsUser($worldId, $needUser());
        respond(201, ['nickname' => $player['nickname']]);
    }
    if ($method === 'POST' && $action === 'words') {
        $player = $game->joinAsUser($worldId, $needUser());
        respond(200, $game->play($worldId, $player['token'], (string) (body()['word'] ?? '')));
    }
    if ($method === 'GET' && $action === 'words' && $arg !== null) respond(200, $game->lookup($worldId, $arg));
    if ($method === 'POST' && $action === 'reports') {
        $player = $game->joinAsUser($worldId, $needUser());
        respond(201, $game->report($worldId, $player['token'], (string) (body()['word'] ?? '')));
    }
    throw new GameError(404, 'Not found');
}

try {
    $configPath = getenv('UNSAID_CONFIG') ?: "$root/config.php";
    if (!is_file($configPath)) respond(500, ['error' => 'Missing config.php — copy config.example.php and fill in your database details. Visit /check.php for help.']);
    $config = require $configPath;
    $db = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
    );
    $dictionary = Dictionary::load();
    $game = new Game($db, $dictionary);
    $auth = new Auth($db, new Mailer($config['mail'] ?? []), $dictionary);
    try {
        route($game, $auth);
    } catch (PDOException $e) {
        // A missing table or column means a new install or an older database: set up or upgrade, then retry.
        if (!in_array($e->getCode(), ['42S02', '42S22'], true)) throw $e;
        if ($db->inTransaction()) $db->rollBack();
        Game::installSchema($db);
        route($game, $auth);
    }
} catch (GameError $e) {
    respond($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Unsaid: ' . $e);
    respond(500, ['error' => 'Something went wrong on the server. Visit /check.php, or see the error_log file.']);
}
