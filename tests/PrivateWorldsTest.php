<?php
declare(strict_types=1);

function makePrivate(): array
{
    $game = makeGame();
    $db = gameDb($game);
    return [$db, $game, new PrivateWorlds($db, $game)];
}

function withPlan(PDO $db, array $user, string $plan): array
{
    $db->prepare('UPDATE users SET plan = ? WHERE id = ?')->execute([$plan, $user['id']]);
    return $user + ['plan' => $plan];
}

test('private worlds need a paid plan, and a host can have 3 open at once', function () {
    [$db, $game, $private] = makePrivate();
    $free = makeUser($db, 'Fay') + ['plan' => 'free'];
    throwsStatus(403, fn () => $private->create($free, ['name' => 'Nope']));
    $host = withPlan($db, makeUser($db, 'Hal'), 'host');
    $worlds = array_map(fn ($i) => $private->create($host, ['name' => "W$i", 'promptHours' => 4, 'maxPlayers' => 10]), [1, 2, 3]);
    $w = $game->world($worlds[0]['id']);
    same(['private', 4 * 3_600_000, 10, $host['id']], [$w['kind'], (int) $w['prompt_duration_ms'], (int) $w['max_players'], (int) $w['owner_user_id']]);
    throwsStatus(403, fn () => $private->create($host, ['name' => 'Fourth']));
    $private->end($worlds[0]['id'], $host);
    $private->create($host, ['name' => 'Fourth']); // Ended worlds don't count.
    throwsStatus(400, fn () => $private->create(withPlan($db, makeUser($db, 'Org'), 'organiser'), ['name' => 'X', 'promptHours' => 2]));
});

test('only players and invite holders see a private world; the owner can replace the link', function () {
    [$db, $game, $private] = makePrivate();
    $host = withPlan($db, makeUser($db, 'Hal'), 'host');
    $made = $private->create($host, ['name' => 'Friends']);
    $world = fn () => $game->world($made['id']);
    $ann = makeUser($db, 'Ann') + ['plan' => 'free'];
    $ben = makeUser($db, 'Ben') + ['plan' => 'free'];

    $private->checkCanView($world(), $host, null); // The owner, without the link.
    throwsStatus(403, fn () => $private->checkCanView($world(), $ann, null));
    throwsStatus(403, fn () => $private->checkCanView($world(), null, 'wrong'));
    $private->checkCanView($world(), null, $made['invite']); // Anyone with the link can look.
    throwsStatus(403, fn () => $private->playerFor($world(), $ann, null));
    $private->playerFor($world(), $ann, $made['invite']);
    same(['isOwner' => false, 'invite' => $made['invite']], $private->personal($world(), $ann), 'Players can pass the link on');

    $new = $private->newInvite($made['id'], $host);
    throwsStatus(403, fn () => $private->playerFor($world(), $ben, $made['invite']));
    $private->checkCanView($world(), $ann, null); // Players carry on without it.
    $private->playerFor($world(), $ben, $new);
    throwsStatus(403, fn () => $private->newInvite($made['id'], $ann));
});

test('owners can close a world, limit its players, rename it and change its pace', function () {
    [$db, $game, $private] = makePrivate();
    $host = withPlan($db, makeUser($db, 'Hal'), 'host');
    $made = $private->create($host, ['name' => 'Friends', 'maxPlayers' => 2]);
    $world = fn () => $game->world($made['id']);
    [$ann, $ben, $cy] = array_map(fn ($n) => makeUser($db, $n) + ['plan' => 'free'], ['Ann', 'Ben', 'Cy']);
    $private->playerFor($world(), $ann, $made['invite']);
    $private->playerFor($world(), $ben, $made['invite']);
    throwsStatus(403, fn () => $private->playerFor($world(), $cy, $made['invite'])); // Full.

    $settings = $private->update($made['id'], $host, ['name' => 'Pals', 'promptHours' => 1, 'maxPlayers' => null, 'closed' => true]);
    same(['Pals', 1, null, true], [$settings['name'], $settings['promptHours'], $settings['maxPlayers'], $settings['closed']]);
    throwsStatus(403, fn () => $private->playerFor($world(), $cy, $made['invite'])); // Closed.
    same(null, $private->personal($world(), $ann)['invite'], 'A closed world hides the link from players');
    $private->update($made['id'], $host, ['closed' => false]);
    $private->playerFor($world(), $cy, $made['invite']);
    throwsStatus(403, fn () => $private->update($made['id'], $ann, ['name' => 'Mine']));
    throwsStatus(400, fn () => $private->update($made['id'], $host, ['maxPlayers' => 1]));
});

test('a host can remove a player but keep them in the standings and results, marked removed', function () {
    [$db, $game, $private] = makePrivate();
    $host = withPlan($db, makeUser($db, 'Hal'), 'host');
    $made = $private->create($host, ['name' => 'Friends']);
    $world = fn () => $game->world($made['id']);
    setPrompt($game, $made['id'], spec(null, ['contains', 'oo']));
    $ann = makeUser($db, 'Ann') + ['plan' => 'free'];
    $token = $private->playerFor($world(), $ann, $made['invite'])['token'];
    $game->play($made['id'], $token, 'moon');

    $private->removePlayer($made['id'], $host, 'Ann', completely: false);
    $board = $game->state($made['id'], null)['leaderboard'];
    same(['Ann', true], [$board[0]['nickname'], $board[0]['removed'] ?? false], 'Still on the leaderboard, marked removed');
    $private->checkCanView($world(), $ann, null); // May still watch, without the link.
    throwsStatus(403, fn () => $private->playerFor($world(), $ann, $made['invite']));
    throwsStatus(403, fn () => $game->play($made['id'], $token, 'book'));
    $private->end($made['id'], $host);
    same(1, (int) $db->query("SELECT COUNT(*) FROM results WHERE world_id = '{$made['id']}' AND nickname = 'Ann'")->fetchColumn(), 'In the final results');
});

test('a removed player drops off the leaderboard and cannot see, play or rejoin', function () {
    [$db, $game, $private] = makePrivate();
    $host = withPlan($db, makeUser($db, 'Hal'), 'host');
    $made = $private->create($host, ['name' => 'Friends']);
    $world = fn () => $game->world($made['id']);
    setPrompt($game, $made['id'], spec(null, ['contains', 'oo']));
    $ann = makeUser($db, 'Ann') + ['plan' => 'free'];
    $token = $private->playerFor($world(), $ann, $made['invite'])['token'];
    $game->play($made['id'], $token, 'moon');

    $private->removePlayer($made['id'], $host, 'Ann');
    $state = $game->state($made['id'], $token);
    same([], $state['leaderboard']);
    same(null, $state['me']);
    same('moon', $state['said'][0]['words'][0]['word'], 'Their words stay used up');
    throwsStatus(403, fn () => $private->checkCanView($world(), $ann, $made['invite']));
    throwsStatus(403, fn () => $private->playerFor($world(), $ann, $made['invite']));
    throwsStatus(403, fn () => $game->play($made['id'], $token, 'book'));
    throwsStatus(400, fn () => $private->removePlayer($made['id'], $host, $private->playerFor($world(), $host, null)['nickname']));
});

test('owners can end and delete worlds, and My worlds lists them', function () {
    [$db, $game, $private] = makePrivate();
    $host = withPlan($db, makeUser($db, 'Hal'), 'host');
    $ann = makeUser($db, 'Ann') + ['plan' => 'free'];
    $solo = $private->create($host, ['name' => 'Solo']);
    $shared = $private->create($host, ['name' => 'Shared']);
    setPrompt($game, $shared['id'], spec(null, ['contains', 'oo']));
    $game->play($shared['id'], $private->playerFor($game->world($shared['id']), $ann, $shared['invite'])['token'], 'moon');

    same(['Shared', 'Solo'], array_column($private->myWorlds($host), 'name'), 'Most recently played first');
    $mine = array_column($private->myWorlds($ann), null, 'name');
    same([false, 1, 1], [$mine['Shared']['isOwner'], $mine['Shared']['words'], $mine['Shared']['players']]);
    check(!isset($mine['Solo']));
    $cards = array_column($private->myWorlds($host), null, 'name');
    check($cards['Shared']['lastPlayed'] !== null, 'Ann said a word in Shared');
    same(null, $cards['Solo']['lastPlayed'], 'Nobody has said a word in Solo: no "Last word just now"');

    throwsStatus(409, fn () => $private->delete($shared['id'], $host)); // Ann has played: end it first.
    $private->delete($solo['id'], $host);
    throwsStatus(404, fn () => $game->world($solo['id']));
    $private->end($shared['id'], $host);
    same(1, (int) $db->query("SELECT COUNT(*) FROM results WHERE world_id = '{$shared['id']}'")->fetchColumn());
    same([], $private->myWorlds($ann), 'Finished worlds move to My games');
    same([true], array_column($private->myWorlds($host), 'ended'), 'The owner still sees it, to delete');
    $private->delete($shared['id'], $host);
    same([], $private->myWorlds($host));
});

test('the admin finds accounts and sets their plan', function () {
    [$db] = makePrivate();
    $admin = new Admin($db, testDictionary());
    $ann = makeUser($db, 'Ann');
    makeUser($db, 'Annabel');
    makeUser($db, 'Ben');
    same([], $admin->accounts(), 'Nobody on a paid plan yet');
    same(['Annabel', 'Ann'], array_column($admin->accounts('ann'), 'name'));
    same('Ann', $admin->setPlan($ann['id'], 'organiser'));
    same([['Ann', 'organiser']], array_map(fn ($a) => [$a['name'], $a['plan']], $admin->accounts()));
    throwsStatus(400, fn () => $admin->setPlan($ann['id'], 'gold'));
    same([], $admin->accounts('100%_'), 'Wildcards are matched literally');
});
