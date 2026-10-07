<?php
declare(strict_types=1);

const WORDS = [
    'cat', 'cats', 'run', 'running', 'runs', 'moon', 'moons', 'book', 'books', 'cool',
    'stop', 'stopped', 'agree', 'agreed', 'see', 'seed', 'hop', 'hope', 'hoped',
    'rat', 'rate', 'rates', 'sing', 'singe', 'singing', 'she', 'shed',
    'city', 'cities', 'boot', 'food', 'catch', 'start', 'stare', 'stone', 'stoop',
    'story', 'mood', 'noon', 'zoo', 'zoom',
];

function makeGame(?callable $clock = null): Game
{
    return new Game(testDb(), Dictionary::fromWords(WORDS), $clock ?? fn () => 1_000, promptMin: 1, promptMax: 1000);
}

/** Forces a known prompt so tests don't depend on random picks. */
function setPrompt(Game $game, string $worldId, string $type, string $letters): void
{
    $db = (new ReflectionProperty(Game::class, 'db'))->getValue($game);
    $db->prepare('UPDATE prompts SET type = ?, letters = ? WHERE world_id = ? AND ended_at IS NULL')->execute([$type, $letters, $worldId]);
}

function sorted(array $words): array
{
    sort($words);
    return $words;
}

test('word families group plurals and inflections', function () {
    $d = Dictionary::fromWords(WORDS);
    same(['cat', 'cats'], sorted($d->family('cats')));
    same(['run', 'running', 'runs'], sorted($d->family('running')));
    same(['cities', 'city'], sorted($d->family('cities')));
    same(['stop', 'stopped'], sorted($d->family('stopped')));
    same(['agree', 'agreed'], sorted($d->family('agreed')));
    same(['hope', 'hoped'], sorted($d->family('hoped')), 'hoped is not a form of hop.');
    same(['rate', 'rates'], sorted($d->family('rates')), 'rates is not a form of rat.');
    same(['sing', 'singing'], sorted($d->family('singing')), 'singing is not a form of singe.');
    same(['seed'], $d->family('seed'), 'seed is not a form of see.');
    same(['shed'], $d->family('shed'), 'shed is not a form of she.');
    same(['catch'], $d->family('catch'));
});

test('the real dictionary loads and builds families', function () {
    $d = Dictionary::load();
    check($d->size() > 30000);
    check(in_array('dog', $d->family('dogs'), true));
    check($d->has('moon'));
});

test('prompts match words', function () {
    check(Prompts::matches('starts', 'st', 'stone'));
    check(Prompts::matches('ends', 'on', 'moon'));
    check(Prompts::matches('contains', 'oo', 'book'));
    check(!Prompts::matches('contains', 'oo', 'cat'));
});

test('prompt picker respects the range and skips used prompts', function () {
    $d = Dictionary::fromWords(WORDS);
    $picked = Prompts::pick($d, [], [], 5, 20);
    check($picked['available'] >= 5 && $picked['available'] <= 20, 'Out of range');
    same($picked['available'], Prompts::countUnburned($d, $picked['type'], $picked['letters'], []));
    $used = ["{$picked['type']}:{$picked['letters']}" => true];
    for ($i = 0; $i < 20; $i++) {
        $again = Prompts::pick($d, [], $used, 5, 20);
        check("{$again['type']}:{$again['letters']}" !== "{$picked['type']}:{$picked['letters']}", 'Picked a used prompt');
    }
});

test('prompt counts agree with the live counter for every prompt', function () {
    $d = Dictionary::fromWords(WORDS);
    $burned = ['moon' => true, 'zoo' => true];
    foreach (Prompts::countAll($d, $burned) as $key => $count) {
        [$type, $letters] = explode(':', $key);
        same(Prompts::countUnburned($d, $type, $letters, $burned), $count, "$key:");
    }
});

test('scoring rises with length, rarity and scarcity', function () {
    same(1.0, Scoring::worldMultiplier(0, 100));
    same(2.0, Scoring::worldMultiplier(50, 100));
    same(5.0, round(Scoring::worldMultiplier(80, 100), 6));
    same(10.0, Scoring::worldMultiplier(100, 100));
    same(1.0, Scoring::promptBonus(40, 40));
    same(4.0, Scoring::promptBonus(10, 40));
    same(5.0, Scoring::promptBonus(1, 40));
    same(1, Scoring::scoreWord('cat', 0, 1, 1));
    check(Scoring::scoreWord('cataract', 0, 1, 1) > Scoring::scoreWord('cat', 0, 1, 1));
    check(Scoring::scoreWord('moon', 0.9, 1, 1) > Scoring::scoreWord('moon', 0, 1, 1));
});

test('playing a word burns it and its family for everyone', function () {
    $game = makeGame();
    $world = $game->createWorld('Test');
    setPrompt($game, $world['id'], 'contains', 'oo');
    $alice = $game->join($world['id'], 'Alice');
    $bob = $game->join($world['id'], 'Bob');

    $played = $game->play($world['id'], $alice['token'], ' Moon ');
    same(true, $played['ok']);
    same(['moons'], $played['alsoBurned']);
    check($played['points'] >= 1);

    $again = $game->play($world['id'], $bob['token'], 'moons');
    same('already-burned', $again['reason']);
    same('Alice', $again['burned']['by']);
    same('moon', $again['burned']['playedWord']);

    same('doesnt-fit', $game->play($world['id'], $bob['token'], 'cat')['reason']);
    same('not-a-word', $game->play($world['id'], $bob['token'], 'xyzzy')['reason']);

    $state = $game->state($world['id'], $bob['token']);
    same('Bob', $state['me']);
    same(2, $state['dictionary']['burned']);
    same('moon', $state['recent'][0]['word']);
    same(1, $state['recent'][0]['familyCount']);
    same('Alice', $state['leaderboard'][0]['nickname']);
    same($played['points'], $state['leaderboard'][0]['total']);
});

test('burns are separate per world', function () {
    $game = makeGame();
    $a = $game->createWorld('A');
    $b = $game->createWorld('B');
    setPrompt($game, $a['id'], 'contains', 'oo');
    setPrompt($game, $b['id'], 'contains', 'oo');
    check($game->play($a['id'], $game->join($a['id'], 'Sam')['token'], 'book')['ok']);
    check($game->play($b['id'], $game->join($b['id'], 'Sam')['token'], 'book')['ok']);
});

test('the prompt rotates when it runs dry or times out', function () {
    $clock = 1_000;
    $game = makeGame(function () use (&$clock) { return $clock; });
    $world = $game->createWorld('Rotate', null, 60_000);
    setPrompt($game, $world['id'], 'starts', 'zo');
    $p = $game->join($world['id'], 'Pat');
    check($game->play($world['id'], $p['token'], 'zoo')['ok']);
    check($game->play($world['id'], $p['token'], 'zoom')['ok']);
    $afterDry = $game->state($world['id'], null)['prompt'];
    check("{$afterDry['type']}:{$afterDry['letters']}" !== 'starts:zo', 'Did not rotate when dry');

    $clock += 61_000;
    $afterTimeout = $game->state($world['id'], null)['prompt'];
    check("{$afterTimeout['type']}:{$afterTimeout['letters']}" !== "{$afterDry['type']}:{$afterDry['letters']}", 'Did not rotate on timeout');
});

test('nicknames are validated and unique per world', function () {
    $game = makeGame();
    $world = $game->createWorld('Names');
    $game->join($world['id'], 'Robin');
    throwsStatus(409, fn () => $game->join($world['id'], 'robin'));
    throwsStatus(400, fn () => $game->join($world['id'], ''));
    throwsStatus(400, fn () => $game->join($world['id'], '<script>'));
    throwsStatus(401, fn () => $game->play($world['id'], 'bad-token', 'cat'));
    throwsStatus(404, fn () => $game->state('nope', null));
});
