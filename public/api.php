<?php
// JSON API for Unsaid. Apache sends every /api/... request here (see .htaccess).
//
//   POST /api/worlds                      {name}      create a private world
//   GET  /api/worlds/{id}                             world state (send X-Player-Token to see "me")
//   POST /api/worlds/{id}/join            {nickname}  returns a player token
//   POST /api/worlds/{id}/words           {word}      play a word (needs X-Player-Token)
//   GET  /api/worlds/{id}/words/{word}                who said this word?

declare(strict_types=1);

$root = dirname(__DIR__);
require "$root/src/WordFamilies.php";
require "$root/src/Dictionary.php";
require "$root/src/Prompts.php";
require "$root/src/Scoring.php";
require "$root/src/Game.php";

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

function route(Game $game): never
{
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $parts = array_map('rawurldecode', array_values(array_filter(explode('/', $path), 'strlen')));
    // Expect: api, worlds, {id}, {action}, {arg}
    [$api, $resource, $worldId, $action, $arg] = array_pad($parts, 5, null);
    if ($api !== 'api' || $resource !== 'worlds') throw new GameError(404, 'Not found');
    $token = $_SERVER['HTTP_X_PLAYER_TOKEN'] ?? null;

    if ($worldId === Game::PUBLIC_WORLD_ID) $game->ensureWorld(Game::PUBLIC_WORLD_ID, 'The Public World');

    if ($method === 'POST' && $worldId === null) respond(201, $game->createWorld((string) (body()['name'] ?? '')));
    if ($worldId === null) throw new GameError(404, 'Not found');
    if ($method === 'GET' && $action === null) respond(200, $game->state($worldId, $token));
    if ($method === 'POST' && $action === 'join') respond(201, $game->join($worldId, (string) (body()['nickname'] ?? '')));
    if ($method === 'POST' && $action === 'words') respond(200, $game->play($worldId, $token, (string) (body()['word'] ?? '')));
    if ($method === 'GET' && $action === 'words' && $arg !== null) respond(200, $game->lookup($worldId, $arg));
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
    $game = new Game($db, Dictionary::load());
    try {
        route($game);
    } catch (PDOException $e) {
        // A missing table or column means a new install or an older database: set up or upgrade, then retry.
        if (!in_array($e->getCode(), ['42S02', '42S22'], true)) throw $e;
        if ($db->inTransaction()) $db->rollBack();
        Game::installSchema($db);
        route($game);
    }
} catch (GameError $e) {
    respond($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Unsaid: ' . $e);
    respond(500, ['error' => 'Something went wrong on the server. Visit /check.php, or see the error_log file.']);
}
