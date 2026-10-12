<?php
// JSON API for Unsaid. Apache sends every /api/... request here (see .htaccess).
// Signed-in requests send the session token in an X-Session-Token header.
//
//   POST /api/auth/start                  {email}                  email a sign-in code
//   POST /api/auth/verify                 {email, code, name?, claim?}  sign in; new accounts also send a name
//   POST /api/auth/logout                                          sign this device out
//   GET  /api/auth/me                                              the signed-in account, or null
//
//   GET  /api/me/history                                           finished games and personal bests (account needed)
//   GET  /api/me/worlds                                            rooms and private worlds you're in (account needed)
//
//   POST /api/rooms                                         join a room: your own, or one with a free seat (account needed)
//
//   POST /api/worlds                      {name, spelling, promptHours, maxPlayers}  create a private world (Host plan)
//   ("big" as a world id means the big world's current round)
//   GET  /api/worlds/{id}?since={version}                   world state (anyone can watch); just
//                                                           {unchanged, version, now} if it's the same as last time
//   POST /api/worlds/{id}/join                              join with the account's name (account needed)
//   POST /api/worlds/{id}/words           {word}            say a word, joining first if needed (account needed)
//   GET  /api/worlds/{id}/words/{word}                      who said this word?
//   POST /api/worlds/{id}/reports         {word}            report a missing word (account needed)
//   Private worlds: add ?invite={code} to any of the above to see or join with an invite link.
//   GET  /api/worlds/{id}/settings                          a private world's settings and players (owner)
//   POST /api/worlds/{id}/settings        {name, promptHours, maxPlayers, closed}  change them (owner)
//   POST /api/worlds/{id}/invite                            a new invite link; the old one stops working (owner)
//   POST /api/worlds/{id}/remove          {nickname}        remove a player (owner)
//   POST /api/worlds/{id}/end                               finish the world (owner)
//   POST /api/worlds/{id}/delete                            delete it: once ended, or if nobody else joined (owner)

declare(strict_types=1);

$root = dirname(__DIR__);
require "$root/src/WordFamilies.php";
require "$root/src/Dictionary.php";
require "$root/src/Prompts.php";
require "$root/src/Scoring.php";
require "$root/src/StateCache.php";
require "$root/src/Game.php";
require "$root/src/Lobby.php";
require "$root/src/PrivateWorlds.php";
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

/**
 * The admin page's address, if this browser is logged in to it, for an Admin link next to the
 * player's name. Only looked at when the admin cookie is present, and read without locking the
 * session, so ordinary polls cost nothing.
 */
function adminLink(array $config): ?string
{
    if (empty($_COOKIE['unsaid_admin']) || empty($config['admin_password'])) return null;
    session_name('unsaid_admin');
    if (!@session_start(['read_and_close' => true])) return null;
    $isAdmin = !empty($_SESSION['admin']);
    $_SESSION = [];
    return $isAdmin ? '/' . trim((string) ($config['admin_path'] ?? 'admin'), '/') : null;
}

function body(): array
{
    $raw = file_get_contents('php://input', length: 10_000);
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new GameError(400, 'Invalid JSON');
    return $data;
}

function route(Game $game, Auth $auth, Lobby $lobby, PrivateWorlds $private, array $config): never
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

    if ($resource === 'me' && $method === 'GET' && $worldId === 'history') respond(200, $lobby->history((int) $needUser()['id']));
    if ($resource === 'me' && $method === 'GET' && $worldId === 'worlds') respond(200, ['worlds' => $private->myWorlds($needUser())]);
    if ($resource === 'rooms' && $method === 'POST' && $worldId === null) {
        respond(200, $lobby->joinRoom($needUser()));
    }
    if ($resource !== 'worlds') throw new GameError(404, 'Not found');
    $isBigWorld = $worldId === Lobby::BIG_WORLD_ALIAS;
    if ($isBigWorld) $worldId = $lobby->bigWorldId();

    if ($method === 'POST' && $worldId === null) respond(201, $private->create($needUser(), body()));
    if ($worldId === null) throw new GameError(404, 'Not found');

    // Owner controls for private worlds.
    if ($action === 'settings' && $method === 'GET') respond(200, $private->settings($worldId, $needUser()));
    if ($action === 'settings' && $method === 'POST') respond(200, $private->update($worldId, $needUser(), body()));
    if ($action === 'invite' && $method === 'POST') respond(200, ['invite' => $private->newInvite($worldId, $needUser())]);
    if ($action === 'remove' && $method === 'POST') {
        $private->removePlayer($worldId, $needUser(), (string) (body()['nickname'] ?? ''));
        respond(200, ['ok' => true]);
    }
    if ($action === 'end' && $method === 'POST') {
        $private->end($worldId, $needUser());
        respond(200, ['ok' => true]);
    }
    if ($action === 'delete' && $method === 'POST') {
        $private->delete($worldId, $needUser());
        respond(200, ['ok' => true]);
    }

    // Everything else: who may see the world, and who may play in it.
    $world = $game->world($worldId);
    $invite = isset($_GET['invite']) ? (string) $_GET['invite'] : null;
    $private->checkCanView($world, $user, $invite);
    $playerFor = fn () => ($world['kind'] ?? 'private') === 'private'
        ? $private->playerFor($world, $needUser(), $invite)
        : $lobby->playerFor($worldId, $needUser());

    if ($method === 'GET' && $action === null) {
        $player = $user ? $game->playerForUser($worldId, $user['id']) : null;
        $state = $game->state($worldId, $player['token'] ?? null)
            + ['account' => $user ? ['name' => $user['name'], 'plan' => $user['plan']] : null]
            + ['admin' => adminLink($config)]
            + $private->personal($world, $user);
        if ($isBigWorld) {
            $state['lastRound'] = $lobby->lastRound();
            $state['myRoom'] = $user ? $lobby->myRoom((int) $user['id']) : null;
        }
        // Pages poll every few seconds, mostly for nothing new: then a tiny answer will do.
        $now = $state['now'];
        unset($state['now']);
        $version = substr(md5(json_encode($state)), 0, 16);
        if (($_GET['since'] ?? null) === $version) respond(200, ['unchanged' => true, 'version' => $version, 'now' => $now]);
        respond(200, $state + ['version' => $version, 'now' => $now]);
    }
    if ($method === 'POST' && $action === 'join') respond(201, ['nickname' => $playerFor()['nickname']]);
    if ($method === 'POST' && $action === 'words') respond(200, $game->play($worldId, $playerFor()['token'], (string) (body()['word'] ?? '')));
    if ($method === 'GET' && $action === 'words' && $arg !== null) respond(200, $game->lookup($worldId, $arg));
    if ($method === 'POST' && $action === 'reports') respond(201, $game->report($worldId, $playerFor()['token'], (string) (body()['word'] ?? '')));
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
    // World states are cached in this folder for a couple of seconds ('cache_dir' => false turns it off).
    $cacheDir = $config['cache_dir'] ?? "$root/cache";
    $game = new Game($db, $dictionary, stateCache: $cacheDir ? StateCache::open($cacheDir) : null,
        awayAfterMs: (int) (($config['rooms']['away_days'] ?? 7) * 86_400_000));
    $lobby = new Lobby($db, $game, $config);
    $private = new PrivateWorlds($db, $game);
    $auth = new Auth($db, new Mailer($config['mail'] ?? []), $dictionary);
    try {
        route($game, $auth, $lobby, $private, $config);
    } catch (PDOException $e) {
        // A missing table or column means a new install or an older database: set up or upgrade, then retry.
        if (!in_array($e->getCode(), ['42S02', '42S22'], true)) throw $e;
        if ($db->inTransaction()) $db->rollBack();
        Game::installSchema($db);
        route($game, $auth, $lobby, $private, $config);
    }
} catch (GameError $e) {
    respond($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Unsaid: ' . $e);
    respond(500, ['error' => 'Something went wrong on the server. Visit /check.php, or see the error_log file.']);
}
