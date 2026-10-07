<?php
declare(strict_types=1);

const WORDS = [
    'cat', 'cats', 'run', 'running', 'runs', 'moon', 'moons', 'book', 'books', 'cool',
    'stop', 'stopped', 'agree', 'agreed', 'see', 'seed', 'hop', 'hope', 'hoped',
    'rat', 'rate', 'rates', 'sing', 'singe', 'singing', 'she', 'shed',
    'city', 'cities', 'boot', 'food', 'catch', 'start', 'stare', 'stone', 'stoop',
    'story', 'mood', 'noon', 'zoo', 'zoom', 'robin', 'robins', 'owl', 'stork', 'crow',
    'almost', 'first', 'floor', 'level', 'yummy', 'gross', 'brother', 'brothers', 'daughter',
    'daughters', 'box', 'boxes', 'news', 'new',
];

const CATEGORIES = [
    'bird' => ['label' => 'A bird', 'words' => ['robin', 'robins', 'owl', 'stork', 'crow']],
    'animal' => ['label' => 'An animal', 'words' => ['cat', 'cats', 'rat', 'robin', 'robins', 'owl', 'stork', 'crow']],
];

const BLOCKED = ['gross'];

function testDictionary(): Dictionary
{
    return Dictionary::fromWords(WORDS, CATEGORIES, BLOCKED);
}

function makeGame(?callable $clock = null): Game
{
    return new Game(testDb(), testDictionary(), $clock ?? fn () => 1_000, promptMin: 1, promptMax: 1000);
}

function gameDb(Game $game): PDO
{
    return (new ReflectionProperty(Game::class, 'db'))->getValue($game);
}

/** Forces a known prompt so tests don't depend on random picks. */
function setPrompt(Game $game, string $worldId, array $spec): void
{
    $remaining = Prompts::countUnsaid($spec, testDictionary(), []);
    gameDb($game)->prepare('UPDATE prompts SET spec = ?, label = NULL, available_at_start = ? WHERE world_id = ? AND ended_at IS NULL')
        ->execute([Prompts::key($spec), $remaining, $worldId]);
}

function sorted(array $words): array
{
    sort($words);
    return $words;
}

function spec(?string $cat, array ...$rules): array
{
    return ['cat' => $cat, 'rules' => $rules];
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

test('the real dictionary loads with categories and without blocked words', function () {
    $d = Dictionary::load();
    check($d->size() > 30000);
    check(in_array('dog', $d->family('dogs'), true));
    check($d->has('moon'));
    check(!$d->has('fuck') && !$d->has('fucking'), 'Blocked words and their families must be removed');
    check($d->isBlocked('fuck'));
    check($d->has('spade') && $d->has('queen'), 'Innocent words stay');
    check(count($d->categoryIds()) >= 25);
    check($d->inCategory('bird', 'robin'));
    check($d->inCategory('insect', 'fly'), 'fly counts as an insect');
    check($d->inCategory('colour', 'red') && $d->inCategory('colour', 'white'));
    check(!$d->inCategory('animal', 'does'), 'does is a verb, not a deer');
    check(!$d->inCategory('animal', 'young'), 'Overrides remove odd members');
});

test('letter rules match words', function () {
    $d = testDictionary();
    $cases = [
        [['starts', 'st'], 'stone', 'moon'],
        [['ends', 'on'], 'moon', 'cat'],
        [['contains', 'oo'], 'book', 'cat'],
        [['lacks', 'o'], 'cat', 'moon'],
        [['bookends', 'n'], 'noon', 'moon'],
        [['double', 'o'], 'zoom', 'cat'],
        [['count', 'y', 2], 'yummy', 'story'],
        [['minLen', 5], 'stone', 'cat'],
        [['maxLen', 3], 'cat', 'stone'],
        [['len', 4], 'moon', 'cat'],
        [['alpha'], 'almost', 'stone'],
        [['oneVowel'], 'first', 'stone'],
        [['onlyVowel', 'o'], 'floor', 'stone'],
    ];
    foreach ($cases as [$rule, $yes, $no]) {
        check(Prompts::matches(spec(null, $rule), $d, $yes), json_encode($rule) . " should match $yes");
        check(!Prompts::matches(spec(null, $rule), $d, $no), json_encode($rule) . " should not match $no");
    }
    check(Prompts::matches(spec('bird', ['starts', 'r']), $d, 'robin'));
    check(!Prompts::matches(spec('bird', ['starts', 'r']), $d, 'rat'), 'rat is not a bird');
});

test('prompts are described in plain English', function () {
    $d = testDictionary();
    same('A bird', Prompts::describe(spec('bird'), $d));
    same('An animal starting with B', Prompts::describe(spec('animal', ['starts', 'b']), $d));
    same('An animal of exactly 4 letters', Prompts::describe(spec('animal', ['len', 4]), $d));
    same('Starts and ends with N', Prompts::describe(spec(null, ['bookends', 'n']), $d));
    same('Letters in alphabetical order, 5+ letters', Prompts::describe(spec(null, ['alpha'], ['minLen', 5]), $d));
    same('Two Ys, no L', Prompts::describe(spec(null, ['count', 'y', 2], ['lacks', 'l']), $d));
    same('Contains G and Y, 5 letters or fewer', Prompts::describe(spec(null, ['contains', 'g'], ['contains', 'y'], ['maxLen', 5]), $d));
});

test('plurals count as their singular for length rules and points', function () {
    $d = testDictionary();
    same('brother', $d->singular('brothers'));
    same('box', $d->singular('boxes'));
    same('city', $d->singular('cities'));
    same('news', $d->singular('news'), 'news is not a plural of new');
    same('running', $d->singular('running'), 'Only plurals, not -ing forms');
    $eightPlus = spec(null, ['minLen', 8]);
    check(!Prompts::matches($eightPlus, $d, 'brothers'), 'brothers is judged as brother: 7 letters');
    check(Prompts::matches($eightPlus, $d, 'daughter'));
    check(Prompts::matches($eightPlus, $d, 'daughters'));
    check(Prompts::matches(spec(null, ['len', 3]), $d, 'boxes'), 'boxes is judged as box');
    check(Prompts::matches(spec(null, ['contains', 'es']), $d, 'boxes'), 'Spelling rules check the word as typed');

    $game = makeGame();
    $world = $game->createWorld('Plurals');
    setPrompt($game, $world['id'], spec(null, ['contains', 'ox']));
    $p = $game->join($world['id'], 'Pam');
    same(Scoring::scoreWord('box', $d->rarity('box'), 1, 1), $game->play($world['id'], $p['token'], 'boxes')['points'], 'boxes scores as box');
});

test('letter rules never ask for an S that a plural would supply', function () {
    $d = Dictionary::load();
    for ($i = 0; $i < 300; $i++) {
        $spec = Prompts::pick($d, [], [], 1, 100000, 1)['spec'];
        foreach ($spec['rules'] as $rule) {
            if (in_array($rule[0], ['contains', 'count', 'bookends', 'double'], true)) {
                check(!str_contains($rule[1], 's'), 'Rule with S: ' . Prompts::describe($spec, $d));
            }
        }
    }
});

test('counts are of distinct answers, so a family counts once', function () {
    $d = testDictionary();
    same(4, Prompts::countUnsaid(spec('bird'), $d, []), 'robin and robins are one answer');
    same(3, Prompts::countUnsaid(spec('bird'), $d, ['robin' => true, 'robins' => true]));
});

test('prompt picker respects the range and skips used prompts', function () {
    $d = testDictionary();
    for ($i = 0; $i < 20; $i++) {
        $picked = Prompts::pick($d, [], [], 2, 6);
        check($picked['available'] >= 2 && $picked['available'] <= 6, 'Out of range: ' . json_encode($picked));
        same($picked['available'], Prompts::countUnsaid($picked['spec'], $d, []));
    }
    $used = [Prompts::key(spec('bird')) => true];
    for ($i = 0; $i < 20; $i++) {
        check(Prompts::key(Prompts::pick($d, [], $used, 1, 100)['spec']) !== Prompts::key(spec('bird')), 'Picked a used prompt');
    }
    same(null, Prompts::pick($d, array_fill_keys(WORDS, true)), 'No prompt once every word is said');
});

test('the real dictionary yields a varied mix of prompts', function () {
    $d = Dictionary::load();
    $kinds = [];
    for ($i = 0; $i < 30; $i++) {
        $picked = Prompts::pick($d, []);
        check($picked['available'] >= 10 && $picked['available'] <= 80, 'Out of range: ' . Prompts::describe($picked['spec'], $d));
        $kinds[$picked['spec']['cat'] ? ($picked['spec']['rules'] ? 'both' : 'meaning') : 'letters'] = true;
    }
    same(3, count($kinds), 'All three kinds should appear in 30 picks.');
});

test('scoring rises with length, rarity and scarcity', function () {
    same(1.0, Scoring::worldMultiplier(0, 100));
    same(2.0, Scoring::worldMultiplier(50, 100));
    same(5.0, round(Scoring::worldMultiplier(80, 100), 6));
    same(10.0, Scoring::worldMultiplier(100, 100));
    same(1.0, Scoring::promptBonus(40, 40));
    same(4.0, Scoring::promptBonus(10, 40));
    same(5.0, Scoring::promptBonus(1, 40));
    same(1.0, Scoring::promptBonus(50, 40), 'Never below x1');
    same(1, Scoring::scoreWord('cat', 0, 1, 1));
    check(Scoring::scoreWord('cataract', 0, 1, 1) > Scoring::scoreWord('cat', 0, 1, 1));
    check(Scoring::scoreWord('moon', 0.9, 1, 1) > Scoring::scoreWord('moon', 0, 1, 1));
});

test('playing a word uses it and its family up for everyone', function () {
    $game = makeGame();
    $world = $game->createWorld('Test');
    setPrompt($game, $world['id'], spec(null, ['contains', 'oo']));
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

    $state = $game->state($world['id'], $bob['token']);
    same('Bob', $state['me']);
    same('Contains OO', $state['prompt']['label']);
    same(2, $state['dictionary']['burned']);
    same('moon', $state['recent'][0]['word']);
    same(1, $state['recent'][0]['familyCount']);
    same('Alice', $state['leaderboard'][0]['nickname']);
    same($played['points'], $state['leaderboard'][0]['total']);
});

test('meaning prompts accept only words in the category', function () {
    $game = makeGame();
    $world = $game->createWorld('Birds');
    setPrompt($game, $world['id'], spec('bird'));
    $p = $game->join($world['id'], 'Pip');
    same('A bird', $game->state($world['id'], null)['prompt']['label']);
    same(4, $game->state($world['id'], null)['prompt']['remaining']);
    check($game->play($world['id'], $p['token'], 'robin')['ok']);
    same('doesnt-fit', $game->play($world['id'], $p['token'], 'cat')['reason']);
    same(3, $game->state($world['id'], null)['prompt']['remaining']);
});

test('wrong guesses cost points, typos are free', function () {
    $game = makeGame();
    $world = $game->createWorld('Penalties');
    setPrompt($game, $world['id'], spec(null, ['contains', 'oo']));
    $a = $game->join($world['id'], 'Ann');
    $b = $game->join($world['id'], 'Ben');
    $points = $game->play($world['id'], $a['token'], 'moon')['points'];

    same(0, $game->play($world['id'], $b['token'], 'xyzzy')['penalty'], 'Typos are free');
    same(Game::PENALTY, $game->play($world['id'], $b['token'], 'cat')['penalty'], "Doesn't fit");
    same(Game::PENALTY, $game->play($world['id'], $b['token'], 'moon')['penalty'], 'Already said');

    $board = array_column($game->state($world['id'], null)['leaderboard'], null, 'nickname');
    same($points, $board['Ann']['total']);
    same(-2 * Game::PENALTY, $board['Ben']['total']);
    same(-2 * Game::PENALTY, $board['Ben']['thisPrompt']);
    same(0, $board['Ben']['words']);
});

test('guesses are rate limited', function () {
    $game = makeGame();
    $world = $game->createWorld('Fast');
    setPrompt($game, $world['id'], spec(null, ['contains', 'oo']));
    $p = $game->join($world['id'], 'Speedy');
    for ($i = 0; $i < Game::GUESSES_PER_MINUTE; $i++) $game->play($world['id'], $p['token'], "nope$i");
    throwsStatus(429, fn () => $game->play($world['id'], $p['token'], 'moon'));
});

test('burns are separate per world', function () {
    $game = makeGame();
    $a = $game->createWorld('A');
    $b = $game->createWorld('B');
    setPrompt($game, $a['id'], spec(null, ['contains', 'oo']));
    setPrompt($game, $b['id'], spec(null, ['contains', 'oo']));
    check($game->play($a['id'], $game->join($a['id'], 'Sam')['token'], 'book')['ok']);
    check($game->play($b['id'], $game->join($b['id'], 'Sam')['token'], 'book')['ok']);
});

test('the prompt rotates when it runs dry or times out', function () {
    $clock = 1_000;
    $game = makeGame(function () use (&$clock) { return $clock; });
    $world = $game->createWorld('Rotate', null, 60_000);
    setPrompt($game, $world['id'], spec(null, ['starts', 'zo']));
    $p = $game->join($world['id'], 'Pat');
    check($game->play($world['id'], $p['token'], 'zoo')['ok']);
    check($game->play($world['id'], $p['token'], 'zoom')['ok']);
    $afterDry = $game->state($world['id'], null)['prompt']['label'];
    check($afterDry !== 'Starts with ZO', 'Did not rotate when dry');

    $clock += 61_000;
    check($game->state($world['id'], null)['prompt']['label'] !== $afterDry, 'Did not rotate on timeout');
});

test('nicknames are validated, unique per world and not offensive', function () {
    $game = makeGame();
    $world = $game->createWorld('Names');
    $game->join($world['id'], 'Robin');
    throwsStatus(409, fn () => $game->join($world['id'], 'robin'));
    throwsStatus(400, fn () => $game->join($world['id'], ''));
    throwsStatus(400, fn () => $game->join($world['id'], '<script>'));
    throwsStatus(400, fn () => $game->join($world['id'], 'Gross'));
    throwsStatus(400, fn () => $game->join($world['id'], 'mr gross 99'));
    throwsStatus(400, fn () => $game->join($world['id'], 'G-R-O-S-S'));
    check($game->join($world['id'], 'Grossman') !== null, 'Names merely containing a blocked word are fine');
    throwsStatus(401, fn () => $game->play($world['id'], 'bad-token', 'cat'));
    throwsStatus(404, fn () => $game->state('nope', null));
});

test('older databases are upgraded and their prompts keep working', function () {
    $db = testDb();
    // Recreate the original prompts table, without the spec column.
    $db->exec('DROP TABLE misses, burns, prompts');
    $db->exec("CREATE TABLE prompts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        world_id VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        type VARCHAR(10) CHARACTER SET ascii NOT NULL,
        letters VARCHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        available_at_start INT NOT NULL, started_at BIGINT NOT NULL, ends_at BIGINT NOT NULL, ended_at BIGINT NULL
    ) ENGINE=InnoDB");
    $db->exec("INSERT INTO worlds (id, name, prompt_duration_ms, created_at) VALUES ('old', 'Old', 86400000, 0)");
    $db->exec("INSERT INTO prompts (world_id, type, letters, available_at_start, started_at, ends_at) VALUES ('old', 'contains', 'oo', 9, 0, 999999999)");

    Game::installSchema($db);
    Game::installSchema($db); // Running it twice is harmless.
    $game = new Game($db, testDictionary(), fn () => 1_000);
    same('Contains OO', $game->state('old', null)['prompt']['label']);
    $p = $game->join('old', 'Olive');
    check($game->play('old', $p['token'], 'book')['ok']);
    same(Game::PENALTY, $game->play('old', $p['token'], 'cat')['penalty']);
});
