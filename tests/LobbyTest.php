<?php
declare(strict_types=1);

const DAY_MS = 86_400_000;

function makeUser(PDO $db, string $name): array
{
    $db->prepare('INSERT INTO users (email, name, created_at, last_seen_at) VALUES (?, ?, 0, 0)')
        ->execute([strtolower($name) . '@example.com', $name]);
    return ['id' => (int) $db->lastInsertId(), 'name' => $name];
}

/** A game and lobby sharing a clock that tests can move: $clock is in milliseconds. */
function makeLobby(int &$clock, array $config = []): array
{
    $db = testDb();
    $game = new Game($db, testDictionary(), function () use (&$clock) { return $clock; }, promptMin: 1, promptMax: 1000, awayAfterMs: 7 * DAY_MS);
    $lobby = new Lobby($db, $game, $config + ['big_world' => ['prompt_answers' => [1, 1000]]], function () use (&$clock) { return $clock; });
    return [$db, $game, $lobby];
}

function ms(string $time): int
{
    return (new DateTimeImmutable($time))->getTimestamp() * 1000;
}

test('big world rounds start at midnight UK time, or every few hours', function () {
    $clock = ms('2026-10-12 10:00 UTC'); // 11:00 in the UK (summer time)
    [, , $daily] = makeLobby($clock);
    $round = $daily->currentRound();
    same('p202610120000', $round['id']);
    same('The Big World, 12 Oct', $round['name']);
    same(ms('2026-10-11 23:00 UTC'), $round['start']);
    same(ms('2026-10-12 23:00 UTC'), $round['end']);

    [, , $sixHourly] = makeLobby($clock, ['big_world' => ['round_hours' => 6, 'prompt_answers' => [1, 1000]]]);
    same(['p202610120600', 'The Big World, 12 Oct, 06:00'], [$sixHourly->currentRound()['id'], $sixHourly->currentRound()['name']]);

    $clock = ms('2026-10-25 12:00 UTC'); // The clocks go back that night: a 25-hour day.
    same(25 * 3_600_000, $daily->currentRound()['end'] - $daily->currentRound()['start']);
});

test('a finished round records everyone\'s results, shown in My games', function () {
    $clock = ms('2026-10-12 10:00 UTC');
    [$db, $game, $lobby] = makeLobby($clock);
    $ann = makeUser($db, 'Ann');
    $ben = makeUser($db, 'Ben');
    $first = $lobby->bigWorldId();
    same('public', $game->world($first)['kind']);
    setPrompt($game, $first, spec(null, ['contains', 'oo']));
    $moon = $game->play($first, $lobby->playerFor($first, $ann)['token'], 'moon')['points'];
    $book = $game->play($first, $lobby->playerFor($first, $ann)['token'], 'book')['points'];
    $game->play($first, $lobby->playerFor($first, $ben)['token'], 'moon'); // Already said: a penalty.
    $lobby->playerFor($first, makeUser($db, 'Cy')); // Joined but never guessed: not placed.
    same(null, $lobby->lastRound());

    $clock = ms('2026-10-13 10:00 UTC'); // The next day.
    $second = $lobby->bigWorldId();
    same('p202610130000', $second);
    check($game->world($first)['ended_at'] !== null, 'The old round is finished');
    same(true, $game->state($first, null)['world']['ended']);
    throwsStatus(410, fn () => $game->play($first, $lobby->playerFor($first, $ann)['token'], 'cool'));

    $last = $lobby->lastRound();
    same('The Big World, 12 Oct', $last['name']);
    same([['nickname' => 'Ann', 'place' => 1, 'score' => $moon + $book], ['nickname' => 'Ben', 'place' => 2, 'score' => -Game::PENALTY]], $last['winners']);

    $history = $lobby->history($ann['id']);
    same(1, $history['bests']['games']);
    same(1, $history['bests']['wins']);
    same(2, $history['bests']['wordsSaid']);
    $game1 = $history['games'][0];
    same(['The Big World, 12 Oct', 1, 2, 2], [$game1['name'], $game1['place'], $game1['players'], $game1['words']]);
    same(max($moon, $book), $game1['bestPoints']);
    same([], $lobby->history(makeUser($db, 'Dee')['id'])['games']);
});

test('a round\'s last prompt stops when the round does', function () {
    $clock = ms('2026-10-12 22:50 UTC'); // Ten minutes before midnight in the UK.
    [, $game, $lobby] = makeLobby($clock);
    $id = $lobby->bigWorldId();
    same(ms('2026-10-12 23:00 UTC'), $game->state($id, null)['prompt']['endsAt']);
});

test('rooms fill up, then a new one opens', function () {
    $clock = ms('2026-10-12 10:00 UTC');
    [$db, $game, $lobby] = makeLobby($clock, ['rooms' => ['size' => 2, 'max' => 2]]);
    [$a, $b, $c, $d, $e] = array_map(fn ($n) => makeUser($db, $n), ['Ann', 'Ben', 'Cy', 'Dee', 'Eve']);
    $room1 = $lobby->joinRoom($a);
    same('Room 1', $room1['name']);
    same($room1, $lobby->joinRoom($a), 'Joining again goes back to the same room');
    same($room1, $lobby->joinRoom($b));
    $room2 = $lobby->joinRoom($c);
    same('Room 2', $room2['name']);
    same($room1, $lobby->myRoom($a['id']));
    same('room', $game->state($room1['id'], null)['world']['kind']);

    throwsStatus(403, fn () => $lobby->playerFor($room1['id'], $d)); // Rooms are joined, not walked into.
    same($room2, $lobby->joinRoom($d));
    throwsStatus(503, fn () => $lobby->joinRoom($e)); // Both rooms full, and no more allowed.
});

test('players away for a week give up their seat', function () {
    $clock = ms('2026-10-12 10:00 UTC');
    [$db, $game, $lobby] = makeLobby($clock, ['rooms' => ['size' => 2]]);
    [$a, $b, $c] = array_map(fn ($n) => makeUser($db, $n), ['Ann', 'Ben', 'Cy']);
    $room = $lobby->joinRoom($a)['id'];
    $lobby->joinRoom($b);
    setPrompt($game, $room, spec(null, ['contains', 'oo']));

    $clock += 8 * DAY_MS;
    $game->play($room, $lobby->playerFor($room, $b)['token'], 'moon'); // Ben keeps playing; Ann has gone quiet.
    $board = array_column($game->state($room, null)['leaderboard'], null, 'nickname');
    same(true, $board['Ann']['away'] ?? false);
    check(!isset($board['Ben']['away']));

    same($room, $lobby->joinRoom($c)['id'], "Cy takes Ann's seat");
    same(null, $lobby->myRoom($a['id']), "Ann's seat has gone");
    throwsStatus(403, fn () => $lobby->playerFor($room, $a));
    check($lobby->joinRoom($a)['id'] !== $room, 'Ann gets a new room');

    // Away, but with a seat free: carry on where you left off.
    $clock += 8 * DAY_MS;
    $game->play($room, $lobby->playerFor($room, $b)['token'], 'book'); // Ben is still here; Cy has gone quiet.
    same($room, $lobby->playerFor($room, $c)['world_id']);
});
