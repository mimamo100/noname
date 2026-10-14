<?php
// JSON API for Unsaid. Apache sends every /api/... request here (see .htaccess).
// Signed-in requests send the session token in an X-Session-Token header.
//
//   POST /api/auth/start                  {email}                  email a sign-in code
//   POST /api/auth/verify                 {email, code, name?, claim?}  sign in; new accounts also send a name
//   POST /api/auth/logout                                          sign this device out
//   GET  /api/auth/me                                              the signed-in account, or null, and the admin link if any
//
//   GET  /api/me/history                                           finished games and personal bests (account needed)
//   GET  /api/me/worlds                                            rooms, private worlds and competitions you're in (account needed)
//   POST /api/me/reminders                {on}                     the daily email on or off (account needed)
//   POST /api/me/name                     {name}                   change the account's name (account needed)
//   POST /api/me/delete                   {confirm: true}          delete the account (account needed)
//   POST /api/reminders/stop              {token}                  stop the daily email, from its link
//
//   POST /api/rooms                                         join a room: your own, or one with a free seat (account needed)
//   GET  /api/packs                                         word packs this account may use: {curated, custom}
//   GET  /api/me/packs                                      the account's own word packs, with their text (Organiser plan)
//   POST /api/packs                       {name, description, text}  make a word pack (Organiser plan)
//   POST /api/packs/{id}                  {name, description, text}  change it;  POST /api/packs/{id}/delete
//   POST /api/packs/{id}/seen                               mark players' suggested words for the pack as seen
//   Creating a private world or competition takes {pack, packMode: 'only' | 'mixed'} too.
//   POST /api/competitions                {name, spelling, startsAt, endsAt, promptMinutes, teams[], teamSize}  (Organiser plan)
//
//   POST /api/worlds                      {name, spelling, promptHours, maxPlayers}  create a private world (Host plan)
//   ("big" as a world id means the big world's current round)
//   GET  /api/worlds/{id}?since={version}                   world state (anyone can watch); just
//                                                           {unchanged, version, now} if it's the same as last time
//   POST /api/worlds/{id}/join                              join with the account's name (account needed)
//   POST /api/worlds/{id}/words           {word, promptId?} say a word, joining first if needed (account needed); promptId
//                                                           is the prompt shown, so a word sent just as it changed counts for it
//   GET  /api/worlds/{id}/words/{word}                      who said this word?
//   POST /api/worlds/{id}/reports         {word, promptId?} report a missing word, for that prompt (default: the current one)
//   Private worlds: add ?invite={code} to any of the above to see or join with an invite link.
//   GET  /api/worlds/{id}/settings                          a private world's settings and players (owner)
//   POST /api/worlds/{id}/settings        {name, promptHours, maxPlayers, closed}  change them (owner)
//   POST /api/worlds/{id}/invite                            a new invite link; the old one stops working (owner)
//   POST /api/worlds/{id}/remove          {nickname}        remove a player (owner)
//   POST /api/worlds/{id}/end                               finish the world (owner)
//   POST /api/worlds/{id}/delete                            delete it: once ended, or if nobody else joined (owner)
//   Competitions also: invite takes {team} (none = the watching link);
//   POST /api/worlds/{id}/teams           {add} | {rename, name} | {remove}  teams, before the start (organiser)
//   POST /api/worlds/{id}/move            {nickname, team}  move a player, before the start (organiser)
//   A competition's team links join that team: ?invite={team code}.

declare(strict_types=1);

$root = dirname(__DIR__);
require "$root/src/WordFamilies.php";
require "$root/src/Dictionary.php";
require "$root/src/Prompts.php";
require "$root/src/Scoring.php";
require "$root/src/StateCache.php";
require "$root/src/Streaks.php";
require "$root/src/Game.php";
require "$root/src/Lobby.php";
require "$root/src/PrivateWorlds.php";
require "$root/src/Competitions.php";
require "$root/src/Packs.php";
require "$root/src/Admin.php";
require "$root/src/Mailer.php";
require "$root/src/Auth.php";
require "$root/src/Reminders.php";

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

/** Logs this browser out of the admin page too, when its game account signs out. */
function endAdminSession(): void
{
    if (empty($_COOKIE['unsaid_admin'])) return;
    session_name('unsaid_admin');
    if (!@session_start()) return;
    $_SESSION = [];
    session_destroy();
    setcookie('unsaid_admin', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
}

function body(): array
{
    $raw = file_get_contents('php://input', length: 10_000);
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new GameError(400, 'Invalid JSON');
    return $data;
}

function route(Game $game, Auth $auth, Lobby $lobby, PrivateWorlds $private, Competitions $competitions, Packs $packs, Closure $gameWith, array $config, Streaks $streaks, Reminders $reminders): never
{
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $parts = array_map('rawurldecode', array_values(array_filter(explode('/', $path), 'strlen')));
    // Expect: api, {resource}, {id or action}, {action}, {arg}, as in the list at the top
    [$api, $resource, $worldId, $action, $arg] = array_pad($parts, 5, null);
    if ($api !== 'api') throw new GameError(404, 'Not found');
    $session = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? null;
    $user = $auth->user($session);
    $needUser = fn () => $user ?? throw new GameError(401, 'Please sign in to play');

    if ($resource === 'auth') {
        $authAction = $worldId;
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
            endAdminSession();
            respond(200, ['ok' => true]);
        }
        if ($method === 'GET' && $authAction === 'me') respond(200, ['user' => $user, 'admin' => adminLink($config)]);
        throw new GameError(404, 'Not found');
    }

    if ($resource === 'me' && $method === 'GET' && $worldId === 'history') {
        $history = $lobby->history((int) $needUser()['id']);
        $history['bests'] += $streaks->of((int) $needUser()['id']);
        respond(200, $history);
    }
    // The daily email: on or off for the account, or off from the link in an email.
    if ($resource === 'me' && $method === 'POST' && $worldId === 'reminders') {
        $reminders->set((int) $needUser()['id'], (bool) (body()['on'] ?? false));
        respond(200, ['ok' => true]);
    }
    if ($resource === 'me' && $method === 'POST' && $worldId === 'name') {
        respond(200, ['user' => $auth->rename($needUser(), (string) (body()['name'] ?? ''), $game)]);
    }
    if ($resource === 'me' && $method === 'POST' && $worldId === 'delete') {
        if ((body()['confirm'] ?? null) !== true) throw new GameError(400, 'Confirm that you want to delete your account');
        $auth->deleteAccount($needUser(), $game);
        endAdminSession();
        respond(200, ['ok' => true]);
    }
    if ($resource === 'reminders' && $method === 'POST' && $worldId === 'stop') {
        if (!$reminders->stop((string) (body()['token'] ?? ''))) throw new GameError(404, 'That link has expired.');
        respond(200, ['ok' => true]);
    }
    if ($resource === 'me' && $method === 'GET' && $worldId === 'worlds') {
        // Pack worlds: their name, and how much is left of the pack rather than the everyday dictionary.
        $worlds = array_map(function ($w) use ($packs, $game) {
            if (!$w['pack']) return $w;
            $size = $packs->dictionary($w['pack'], $w['packMode'] ?? 'only', $game->dictionary())->size();
            return ['packName' => $packs->name($w['pack'], $game->dictionary()), 'left' => max(0, 1 - $w['burned'] / max(1, $size))] + $w;
        }, $private->myWorlds($needUser()));
        respond(200, ['worlds' => $worlds]);
    }
    // Word packs: the ones you may use, and organisers' own.
    if ($resource === 'packs' && $method === 'GET' && $worldId === null) respond(200, $packs->available($user));
    if ($resource === 'me' && $method === 'GET' && $worldId === 'packs') respond(200, ['packs' => $packs->mine($needUser())]);
    if ($resource === 'packs' && $method === 'POST') {
        $b = body();
        [$name, $description, $text] = [(string) ($b['name'] ?? ''), (string) ($b['description'] ?? ''), (string) ($b['text'] ?? '')];
        if ($worldId === null) respond(201, $packs->create($needUser(), $name, $description, $text, $game->dictionary()));
        if ($action === 'delete') {
            $packs->delete($needUser(), $worldId);
            respond(200, ['ok' => true]);
        }
        if ($action === 'seen') {
            $packs->markReportsSeen($needUser(), $worldId);
            respond(200, ['ok' => true]);
        }
        if ($action === null) respond(200, $packs->update($needUser(), $worldId, $name, $description, $text, $game->dictionary()));
    }
    if ($resource === 'competitions' && $method === 'POST' && $worldId === null) {
        $b = body();
        if (!empty($b['pack'])) $packs->checkUsable($needUser(), (string) $b['pack']);
        respond(201, $competitions->create($needUser(), $b));
    }
    if ($resource === 'rooms' && $method === 'POST' && $worldId === null) {
        respond(200, $lobby->joinRoom($needUser()));
    }
    if ($resource !== 'worlds') throw new GameError(404, 'Not found');
    $isBigWorld = $worldId === Lobby::BIG_WORLD_ALIAS;
    if ($isBigWorld) $worldId = $lobby->bigWorldId();

    if ($method === 'POST' && $worldId === null) {
        $b = body();
        if (!empty($b['pack'])) $packs->checkUsable($needUser(), (string) $b['pack']);
        respond(201, $private->create($needUser(), $b));
    }
    if ($worldId === null) throw new GameError(404, 'Not found');
    $world = $game->world($worldId);
    $isCompetition = ($world['kind'] ?? '') === 'competition';
    // A world with a word pack plays with that pack's dictionary.
    if (!empty($world['pack'])) $game = $gameWith($packs->dictionary($world['pack'], $world['pack_mode'] ?? 'only', $game->dictionary()));

    // Owner controls: for private worlds and competitions.
    $owned = $isCompetition ? $competitions : $private;
    if ($action === 'settings' && $method === 'GET') respond(200, $owned->settings($worldId, $needUser()));
    if ($action === 'settings' && $method === 'POST') respond(200, $owned->update($worldId, $needUser(), body()));
    if ($action === 'invite' && $method === 'POST') {
        $team = body()['team'] ?? null;
        respond(200, ['invite' => $isCompetition
            ? $competitions->newInvite($worldId, $needUser(), $team === null ? null : (int) $team)
            : $private->newInvite($worldId, $needUser())]);
    }
    if ($action === 'remove' && $method === 'POST') {
        $owned->removePlayer($worldId, $needUser(), (string) (body()['nickname'] ?? ''));
        respond(200, ['ok' => true]);
    }
    if ($action === 'end' && $method === 'POST') {
        $owned->end($worldId, $needUser());
        respond(200, ['ok' => true]);
    }
    if ($action === 'delete' && $method === 'POST') {
        $owned->delete($worldId, $needUser());
        respond(200, ['ok' => true]);
    }
    if ($isCompetition && $action === 'teams' && $method === 'POST') {
        $b = body();
        if (isset($b['add'])) $competitions->addTeam($worldId, $needUser(), (string) $b['add']);
        elseif (isset($b['rename'])) $competitions->renameTeam($worldId, $needUser(), (int) $b['rename'], (string) ($b['name'] ?? ''));
        elseif (isset($b['remove'])) $competitions->removeTeam($worldId, $needUser(), (int) $b['remove']);
        else throw new GameError(400, 'Say add, rename or remove');
        respond(200, $competitions->settings($worldId, $needUser()));
    }
    if ($isCompetition && $action === 'move' && $method === 'POST') {
        $b = body();
        $competitions->movePlayer($worldId, $needUser(), (string) ($b['nickname'] ?? ''), (int) ($b['team'] ?? 0));
        respond(200, $competitions->settings($worldId, $needUser()));
    }

    // Everything else: who may see the world, and who may play in it.
    $invite = isset($_GET['invite']) ? (string) $_GET['invite'] : null;
    if ($isCompetition) $competitions->checkCanView($world, $user, $invite);
    else $private->checkCanView($world, $user, $invite);
    $playerFor = fn () => match ($world['kind'] ?? 'private') {
        'private' => $private->playerFor($world, $needUser(), $invite),
        'competition' => $competitions->playerFor($world, $needUser(), $invite),
        default => $lobby->playerFor($worldId, $needUser()),
    };

    if ($method === 'GET' && $action === null) {
        $player = $user ? $game->playerForUser($worldId, $user['id']) : null;
        $state = $game->state($worldId, $player['token'] ?? null)
            + ['account' => $user ? ['name' => $user['name'], 'plan' => $user['plan'], 'reminders' => $user['reminders']] : null]
            + ['admin' => adminLink($config)]
            + ($isCompetition ? $competitions->personal($world, $user, $invite) : $private->personal($world, $user));
        if (!empty($world['pack'])) {
            $state['pack'] = ['name' => $packs->name($world['pack'], $game->dictionary()) ?? 'a removed pack', 'mode' => $world['pack_mode'] ?? 'only'];
        }
        // How the viewer finished, to share.
        if ($user && !empty($state['world']['ended'])) $state['myResult'] = $lobby->result($world['id'], (int) $user['id']);
        if ($isBigWorld) {
            $state['roundHours'] = $lobby->roundHours();
            // Early in a new round, Recent prompt shows the last round's last played prompt, and
            // Earlier prompts are made up to three with the last round's.
            $earlier = count(array_filter($state['said'] ?? [], fn ($g) => $g['promptId'] !== ($state['prompt']['id'] ?? null)));
            if ((empty($state['recentPrompt']) || $earlier < 3) && ($previous = $lobby->previousRoundId())) {
                $fromLast = array_map(fn ($g) => $g + ['lastRound' => true], $game->recentPrompts($previous, 3));
                if (empty($state['recentPrompt']) && $fromLast) $state['recentPrompt'] = $fromLast[0];
                $state['said'] = array_merge($state['said'] ?? [], array_slice($fromLast, 0, max(0, 3 - $earlier)));
            }
            $state['lastRound'] = $lobby->lastRound();
            if ($user && $state['lastRound']) $state['lastRound']['mine'] = $lobby->result($state['lastRound']['id'], (int) $user['id']);
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
    // Before a competition starts, a word or report is refused before it could join a team.
    if ($isCompetition && $method === 'POST' && in_array($action, ['words', 'reports'], true)) $competitions->checkStarted($world);
    if ($method === 'POST' && $action === 'words') {
        $b = body();
        respond(200, $game->play($worldId, $playerFor()['token'], (string) ($b['word'] ?? ''), isset($b['promptId']) ? (int) $b['promptId'] : null));
    }
    if ($method === 'GET' && $action === 'words' && $arg !== null) respond(200, $game->lookup($worldId, $arg));
    if ($method === 'POST' && $action === 'reports') {
        $b = body();
        respond(201, $game->report($worldId, $playerFor()['token'], (string) ($b['word'] ?? ''), isset($b['promptId']) ? (int) $b['promptId'] : null));
    }
    throw new GameError(404, 'Not found');
}

try {
    $configPath = getenv('UNSAID_CONFIG') ?: "$root/config.php";
    if (!is_file($configPath)) respond(500, ['error' => 'Missing config.php: copy config.example.php and fill in your database details. Visit /check.php for help.']);
    $config = require $configPath;
    $db = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
    );
    $dictionary = Dictionary::load();
    // World states are cached in this folder for a couple of seconds ('cache_dir' => false turns it off).
    $cacheDir = $config['cache_dir'] ?? "$root/cache";
    $stateCache = $cacheDir ? StateCache::open($cacheDir) : null;
    $awayAfterMs = (int) (($config['rooms']['away_days'] ?? 7) * 86_400_000);
    $streaks = new Streaks($db, $config['big_world']['timezone'] ?? 'Europe/London');
    $reminders = new Reminders($db, $streaks);
    // A game for a dictionary: the everyday one, or a word pack's for a world that plays one.
    $gameWith = fn (Dictionary $d) => new Game($db, $d, stateCache: $stateCache, awayAfterMs: $awayAfterMs, streaks: $streaks);
    $game = $gameWith($dictionary);
    $packs = new Packs($db, $cacheDir ?: null);
    $lobby = new Lobby($db, $game, $config, packs: $packs);
    $private = new PrivateWorlds($db, $game);
    $competitions = new Competitions($db, $game);
    $auth = new Auth($db, new Mailer($config['mail'] ?? []), $dictionary);
    try {
        route($game, $auth, $lobby, $private, $competitions, $packs, $gameWith, $config, $streaks, $reminders);
    } catch (PDOException $e) {
        // A missing table or column means a new install or an older database: set up or upgrade, then retry.
        if (!in_array($e->getCode(), ['42S02', '42S22'], true)) throw $e;
        if ($db->inTransaction()) $db->rollBack();
        Game::installSchema($db);
        route($game, $auth, $lobby, $private, $competitions, $packs, $gameWith, $config, $streaks, $reminders);
    }
} catch (GameError $e) {
    respond($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Unsaid: ' . $e);
    respond(500, ['error' => 'Something went wrong on the server. Visit /check.php, or see the error_log file.']);
}
