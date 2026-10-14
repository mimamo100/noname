<?php
declare(strict_types=1);

const HOUR_MS = 3_600_000;

/** A game and competitions sharing a clock tests can move, with an organiser. */
function makeCompetitions(int &$clock): array
{
    $db = testDb();
    $time = function () use (&$clock) { return $clock; };
    $game = new Game($db, testDictionary(), $time, promptMin: 1, promptMax: 1000);
    $organiser = withPlan($db, makeUser($db, 'Org'), 'organiser');
    return [$db, $game, new Competitions($db, $game, $time), $organiser];
}

function teamCode(PDO $db, string $worldId, string $team): string
{
    $stmt = $db->prepare('SELECT invite_code FROM teams WHERE world_id = ? AND name = ?');
    $stmt->execute([$worldId, $team]);
    return (string) $stmt->fetchColumn();
}

test('competitions need the Organiser plan and sensible settings', function () {
    $clock = 1_000 * HOUR_MS;
    [$db, , $comps, $org] = makeCompetitions($clock);
    $base = ['name' => 'Quiz night', 'startsAt' => $clock + HOUR_MS, 'endsAt' => $clock + 2 * HOUR_MS, 'promptMinutes' => 5, 'teams' => ['Owls', 'Foxes']];
    throwsStatus(403, fn () => $comps->create(withPlan($db, makeUser($db, 'Hal'), 'host'), $base));
    throwsStatus(400, fn () => $comps->create($org, ['teams' => ['Owls']] + $base), 'At least two teams');
    throwsStatus(400, fn () => $comps->create($org, ['teams' => ['Owls', 'owls']] + $base), 'Names differ');
    throwsStatus(400, fn () => $comps->create($org, ['endsAt' => $clock] + $base), 'Ends after it starts');
    throwsStatus(400, fn () => $comps->create($org, ['startsAt' => $clock - 2 * HOUR_MS] + $base), 'Not in the past');
    throwsStatus(400, fn () => $comps->create($org, ['promptMinutes' => 7] + $base));
    $made = $comps->create($org, $base);
    $settings = $comps->settings($made['id'], $org);
    same(['Quiz night', 5, false, true], [$settings['name'], $settings['promptMinutes'], $settings['started'], $settings['canDelete']]);
    same(['Owls', 'Foxes'], array_column($settings['teams'], 'name'));
});

test('a competition: teams join by their links, it starts on time, and teams score together', function () {
    $clock = 1_000 * HOUR_MS;
    [$db, $game, $comps, $org] = makeCompetitions($clock);
    $made = $comps->create($org, ['name' => 'Quiz', 'startsAt' => $clock + HOUR_MS, 'endsAt' => $clock + 3 * HOUR_MS,
        'promptMinutes' => 60, 'teams' => ['Owls', 'Foxes', 'Bats'], 'teamSize' => 2]);
    $id = $made['id'];
    $world = fn () => $game->world($id);
    [$ann, $ben, $cy, $dee] = array_map(fn ($n) => makeUser($db, $n) + ['plan' => 'free'], ['Ann', 'Ben', 'Cy', 'Dee']);

    // Seeing it: the organiser, players, and anyone with a team or watching link.
    throwsStatus(403, fn () => $comps->checkCanView($world(), $ann, null));
    $comps->checkCanView($world(), $ann, teamCode($db, $id, 'Owls'));
    $comps->checkCanView($world(), null, $world()['invite_code']);
    $comps->checkCanView($world(), $org, null);
    throwsStatus(403, fn () => $comps->playerFor($world(), $ann, $world()['invite_code']), 'The watching link does not join a team');

    // Joining before the start, by team link.
    $annP = $comps->playerFor($world(), $ann, teamCode($db, $id, 'Owls'));
    $benP = $comps->playerFor($world(), $ben, teamCode($db, $id, 'Owls'));
    throwsStatus(403, fn () => $comps->playerFor($world(), $cy, teamCode($db, $id, 'Owls')), 'Owls is full');
    $cyP = $comps->playerFor($world(), $cy, teamCode($db, $id, 'Foxes'));
    same($annP['id'], $comps->playerFor($world(), $ann, teamCode($db, $id, 'Foxes'))['id'], 'No switching team by opening another link');
    same(['name' => 'Owls', 'position' => 0], $comps->personal($world(), $ann, null)['myTeam']);
    same(null, $comps->personal($world(), $ann, null)['invite'], "Players aren't shown their team's link");
    same('Bats', $comps->personal($world(), $dee, teamCode($db, $id, 'Bats'))['joiningTeam']['name']);
    same(['Owls', 'Foxes', 'Bats'], array_map(fn ($code) => $db->query("SELECT name FROM teams WHERE invite_code = '$code'")->fetchColumn(),
        array_values($comps->personal($world(), $org, null)['teamLinks'])), "The organiser gets every team's link");
    check(!isset($comps->personal($world(), $ann, null)['teamLinks']), "Players don't");

    // Before the start: no prompt, no playing, teams forming.
    $state = $game->state($id, null);
    same([null, $clock + HOUR_MS], [$state['prompt'], $state['world']['startsAt']]);
    same(['Ann', 'Ben'], array_column($state['teams'], 'players', 'name')['Owls']);
    throwsStatus(409, fn () => $game->play($id, $annP['token'], 'moon'));

    // The organiser can move players until the start.
    $comps->movePlayer($id, $org, 'Ben', (int) array_column($state['teams'], 'id', 'name')['Foxes']);

    // It starts.
    $clock += HOUR_MS;
    check($game->state($id, null)['prompt'] !== null, 'A prompt at the start');
    setPrompt($game, $id, spec(null, ['contains', 'oo']));
    $moon = $game->play($id, $annP['token'], 'moon')['points'];
    $book = $game->play($id, $benP['token'], 'book')['points'];
    $cool = $game->play($id, $cyP['token'], 'cool')['points'];
    $game->play($id, $cyP['token'], 'cat'); // Doesn't fit: a penalty.
    throwsStatus(400, fn () => $comps->movePlayer($id, $org, 'Ben', 1), 'No moves after the start');

    $state = $game->state($id, null);
    $teams = array_column($state['teams'], null, 'name');
    same([$moon, $book + $cool - Game::PENALTY, 0], [$teams['Owls']['score'], $teams['Foxes']['score'], $teams['Bats']['score']]);
    same($teams['Foxes']['score'], $teams['Foxes']['thisPrompt']);
    same(0, array_column($state['leaderboard'], 'team', 'nickname')['Ann'], 'Players show their team');
    same(0, $state['said'][0]['words'][array_search('moon', array_column($state['said'][0]['words'], 'word'))]['team']);

    // Late joiners can still join a team with room.
    $comps->playerFor($world(), $dee, teamCode($db, $id, 'Bats'));

    // The end: final standings for players and teams, in My games.
    $clock += 2 * HOUR_MS;
    $state = $game->state($id, null);
    same(true, $state['world']['ended']);
    throwsStatus(410, fn () => $game->play($id, $annP['token'], 'cool'));
    $teamResults = $db->query("SELECT name, place, teams, players FROM team_results WHERE world_id = '$id' ORDER BY place, name")->fetchAll(PDO::FETCH_NUM);
    same(3, count($teamResults));
    same(['Bats', 3, 3, 1], array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $teamResults[2]), 'Every team is placed');
    $lobby = new Lobby($db, $game, [], fn () => $clock);
    $mine = $lobby->history($ann['id'])['games'][0];
    same(['Quiz', 'Owls'], [$mine['name'], $mine['team']['name']]);
    same(3, $mine['team']['teams']);
});

test('organisers edit teams and times before the start, and can end or delete', function () {
    $clock = 1_000 * HOUR_MS;
    [$db, $game, $comps, $org] = makeCompetitions($clock);
    $made = $comps->create($org, ['name' => 'Office', 'startsAt' => $clock + HOUR_MS, 'endsAt' => $clock + 2 * HOUR_MS,
        'promptMinutes' => 15, 'teams' => ['Sales', 'IT']]);
    $id = $made['id'];
    $comps->addTeam($id, $org, 'Finance');
    $teams = fn () => array_column($comps->settings($id, $org)['teams'], 'id', 'name');
    $comps->renameTeam($id, $org, $teams()['IT'], 'Tech');
    throwsStatus(400, fn () => $comps->renameTeam($id, $org, $teams()['Tech'], 'Sales'));
    $comps->removeTeam($id, $org, $teams()['Finance']);
    same(['Sales', 'Tech'], array_keys($teams()));
    throwsStatus(400, fn () => $comps->removeTeam($id, $org, $teams()['Tech']), 'At least two teams');
    $old = teamCode($db, $id, 'Sales');
    $new = $comps->newInvite($id, $org, $teams()['Sales']);
    check($new !== $old && teamCode($db, $id, 'Sales') === $new);
    $settings = $comps->update($id, $org, ['name' => 'Office cup', 'startsAt' => $clock + 2 * HOUR_MS, 'endsAt' => $clock + 5 * HOUR_MS, 'promptMinutes' => 60, 'teamSize' => 4]);
    same(['Office cup', $clock + 2 * HOUR_MS, 60, 4], [$settings['name'], $settings['startsAt'], $settings['promptMinutes'], $settings['teamSize']]);
    throwsStatus(403, fn () => $comps->update($id, makeUser($db, 'Ann') + ['plan' => 'free'], ['name' => 'Mine']));

    $clock += 2 * HOUR_MS; // Started.
    $settings = $comps->update($id, $org, ['startsAt' => $clock + HOUR_MS, 'promptMinutes' => 5, 'endsAt' => $clock + 4 * HOUR_MS]);
    same([$clock, 60, $clock + 4 * HOUR_MS], [$settings['startsAt'], $settings['promptMinutes'], $settings['endsAt']], 'Only the end can move once started');
    throwsStatus(400, fn () => $comps->addTeam($id, $org, 'Late'));
    throwsStatus(409, fn () => $comps->delete($id, $org), 'End it first');
    $comps->end($id, $org);
    same(true, $game->state($id, null)['world']['ended']);
    $comps->delete($id, $org);
    throwsStatus(404, fn () => $game->world($id));
});

test('housekeeping records competitions that ended unseen', function () {
    $clock = 1_000 * HOUR_MS;
    [$db, $game, $comps, $org] = makeCompetitions($clock);
    $made = $comps->create($org, ['name' => 'Quick', 'startsAt' => $clock, 'endsAt' => $clock + HOUR_MS, 'promptMinutes' => 5, 'teams' => ['A', 'B']]);
    $clock += 2 * HOUR_MS;
    same(1, (new Housekeeping($db, 30, null, fn () => $clock, $game))->run()['overdueWorldsFinished']);
    check($game->world($made['id'])['ended_at'] !== null);
});

test('a team-mate\'s word is free; players are told about another team\'s link and being moved', function () {
    $clock = 1_000 * HOUR_MS;
    [$db, $game, $comps, $org] = makeCompetitions($clock);
    $made = $comps->create($org, ['name' => 'Quiz', 'startsAt' => $clock + HOUR_MS, 'endsAt' => $clock + 3 * HOUR_MS,
        'promptMinutes' => 60, 'teams' => ['Owls', 'Foxes']]);
    $id = $made['id'];
    $world = fn () => $game->world($id);
    [$ann, $ben, $cy] = array_map(fn ($n) => makeUser($db, $n) + ['plan' => 'free'], ['Ann', 'Ben', 'Cy']);
    $annP = $comps->playerFor($world(), $ann, teamCode($db, $id, 'Owls'));
    $benP = $comps->playerFor($world(), $ben, teamCode($db, $id, 'Owls'));
    $cyP = $comps->playerFor($world(), $cy, teamCode($db, $id, 'Foxes'));
    throwsStatus(409, fn () => $comps->checkStarted($world()), 'Words are refused before the start');

    same('Foxes', $comps->personal($world(), $ann, teamCode($db, $id, 'Foxes'))['otherTeamLink'], "Ann opened the Foxes' link");
    $comps->movePlayer($id, $org, 'Ben', (int) $db->query("SELECT id FROM teams WHERE world_id = '$id' AND name = 'Foxes'")->fetchColumn());
    same(['Foxes', 'Owls'], [$comps->personal($world(), $ben, null)['myTeam']['name'], $comps->personal($world(), $ben, null)['movedFrom']]);

    $clock += HOUR_MS;
    $comps->checkStarted($world());
    $game->state($id, null);
    setPrompt($game, $id, spec(null, ['contains', 'oo']));
    $game->play($id, $cyP['token'], 'moon');
    $clock += Game::SAID_GRACE_MS + 1;
    $mate = $game->play($id, $benP['token'], 'moon'); // Ben is on the Foxes now, with Cy.
    same([0, true], [$mate['penalty'], $mate['teamMate']], "A team-mate's word is free");
    same(Game::PENALTY, $game->play($id, $annP['token'], 'moon')['penalty'], "An opponent's costs");
});

test('no prompt starts in the last few minutes of a competition', function () {
    $clock = 1_000 * HOUR_MS;
    [$db, $game, $comps, $org] = makeCompetitions($clock);
    $made = $comps->create($org, ['name' => 'Short', 'startsAt' => $clock + HOUR_MS, 'endsAt' => $clock + HOUR_MS + 18 * 60_000,
        'promptMinutes' => 15, 'teams' => ['Owls', 'Foxes']]);
    $clock += HOUR_MS;
    $first = $game->state($made['id'], null)['prompt'];
    same($clock + 18 * 60_000, $first['endsAt'], 'It would have left 3 minutes, so it runs to the end');
    $clock += 14 * 60_000;
    same(0, $game->state($made['id'], null)['prompt']['endsWhenLeft'], 'In the last minutes it runs on rather than ending at 80%');
});

test("an ended competition shows its result on the organiser's card, and is in their history even unplayed", function () {
    $clock = 1_000 * HOUR_MS;
    [$db, $game, $comps, $org] = makeCompetitions($clock);
    $made = $comps->create($org, ['name' => 'Quiz', 'startsAt' => $clock + HOUR_MS, 'endsAt' => $clock + 2 * HOUR_MS, 'promptMinutes' => 5, 'teams' => ['Owls', 'Foxes']]);
    $game->finishWorld($made['id']);
    $card = array_column((new PrivateWorlds($db, $game))->myWorlds($org), null, 'id')[$made['id']];
    same(2, count($card['teams']), 'Both teams, in order');
    $ran = (new Lobby($db, $game))->history((int) $org['id'])['ran'];
    same([$made['id']], array_column($ran, 'worldId'));
});
