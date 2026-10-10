<?php
declare(strict_types=1);

const WORDS = [
    'cat', 'cats', 'run', 'running', 'runs', 'moon', 'moons', 'book', 'books', 'cool',
    'stop', 'stopped', 'agree', 'agreed', 'see', 'seed', 'hop', 'hope', 'hoped',
    'rat', 'rate', 'rates', 'sing', 'singe', 'singing', 'she', 'shed',
    'city', 'cities', 'boot', 'food', 'catch', 'start', 'stare', 'stone', 'stoop',
    'story', 'mood', 'noon', 'zoo', 'zoom', 'robin', 'robins', 'owl', 'stork', 'crow',
    'almost', 'first', 'floor', 'level', 'yummy', 'gross', 'brother', 'brothers', 'daughter',
    'daughters', 'box', 'boxes', 'news', 'new', 'squash', 'chess', 'sofa',
];

const CATEGORIES = [
    'bird' => ['label' => 'A bird', 'words' => ['robin', 'robins', 'owl', 'stork', 'crow']],
    'sport' => ['label' => 'A sport or game', 'words' => ['chess'], 'also' => ['squash']],
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
    check(!in_array('does', $d->categoryWords('animal'), true), 'does is mostly a verb, so not counted as an animal');
    check($d->inCategory('sport', 'squash') && $d->inCategory('sport', 'chess'), 'Other meanings that fit are accepted');
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
    same($state['prompt']['id'], $state['said'][0]['promptId'], 'The current prompt comes first');
    same('moon', $state['said'][0]['words'][0]['word']);
    same(1, $state['said'][0]['words'][0]['familyCount']);
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
    same('not-in-category', $game->play($world['id'], $p['token'], 'cat')['reason'], 'cat is not a bird, but costs nothing');
    same(3, $game->state($world['id'], null)['prompt']['remaining']);
});

test('words with another meaning that fits are accepted but not counted', function () {
    $d = testDictionary();
    check($d->inCategory('sport', 'squash'), 'squash is accepted as a sport');
    same(1, Prompts::countUnsaid(spec('sport'), $d, []), 'Only clear answers are counted');
    $game = makeGame();
    $world = $game->createWorld('Sports');
    setPrompt($game, $world['id'], spec('sport', ['lacks', 'r']));
    $p = $game->join($world['id'], 'Sid');
    check($game->play($world['id'], $p['token'], 'squash')['ok'], 'squash fits "A sport or game with no R"');
});

test("a real word missing from a meaning list costs nothing and can be reported", function () {
    $game = makeGame();
    $world = $game->createWorld('Reports');
    setPrompt($game, $world['id'], spec('sport', ['lacks', 'r']));
    $p = $game->join($world['id'], 'Rae');

    $missing = $game->play($world['id'], $p['token'], 'sofa');
    same('not-in-category', $missing['reason'], 'Right letters, not on the list');
    same(0, $missing['penalty']);
    same('A sport or game', $missing['category']);
    $wrong = $game->play($world['id'], $p['token'], 'robin');
    same('doesnt-fit', $wrong['reason'], 'Wrong letters still cost points');
    same(Game::PENALTY, $wrong['penalty']);

    same(true, $game->report($world['id'], $p['token'], 'sofa')['ok']);
    same(true, $game->report($world['id'], $p['token'], 'Sofa')['ok'], 'Reporting twice is harmless');
    $rows = gameDb($game)->query('SELECT category, word FROM reports')->fetchAll(PDO::FETCH_ASSOC);
    same([['category' => 'sport', 'word' => 'sofa']], $rows);
    throwsStatus(400, fn () => $game->report($world['id'], $p['token'], 'x1'), 'Only proper words can be reported');

    setPrompt($game, $world['id'], spec(null, ['starts', 's']));
    throwsStatus(400, fn () => $game->report($world['id'], $p['token'], 'sofa'), 'A word already in the dictionary has nothing to report on a letters prompt');
    same(true, $game->report($world['id'], $p['token'], 'netball')['ok'], 'Words missing from the dictionary can be reported on any prompt');
});

test('the admin can accept, dismiss and undo reported words', function () {
    $clock = 5_000;
    $game = makeGame(function () use (&$clock) { return $clock; });
    $db = gameDb($game);
    $world = $game->createWorld('Admin');
    setPrompt($game, $world['id'], spec('sport'));
    $p = $game->join($world['id'], 'Ann');
    $q = $game->join($world['id'], 'Bea');
    same('not-in-category', $game->play($world['id'], $p['token'], 'sofa')['reason']);
    $game->report($world['id'], $p['token'], 'sofa');
    $game->report($world['id'], $q['token'], 'sofa');
    $game->report($world['id'], $p['token'], 'netball');

    $admin = new Admin($db, testDictionary(), fn () => $clock);
    $reports = $admin->openReports();
    same(['sofa', 'netball'], array_column($reports, 'word'), 'Most-reported first');
    same(2, $reports[0]['reports']);
    same(2, $reports[0]['players']);
    same('A sport or game', $reports[0]['categoryLabel']);
    same(['A sport or game'], $reports[0]['prompts']);
    same(false, $reports[1]['inDictionary']);

    // Accepting makes the word count straight away, for a fresh request.
    $admin->decide('sport', 'sofa', 'accepted');
    $admin->decide('sport', 'netball', 'dismissed');
    same([], $admin->openReports());
    $fresh = new Game($db, testDictionary(), fn () => $clock, promptMin: 1, promptMax: 1000);
    $fresh->applyWordDecisions();
    check($fresh->play($world['id'], $q['token'], 'sofa')['ok'], 'An accepted word counts');

    // An accepted word that wasn't in the dictionary is added to it.
    $admin->decide(Admin::DICTIONARY_ONLY, 'Zorbing', 'accepted');
    $dictionary = testDictionary();
    (new Game($db, $dictionary, fn () => $clock))->applyWordDecisions();
    check($dictionary->has('zorbing'));

    // Undo brings a dismissed report back.
    $admin->undo('sport', 'netball');
    same(['netball'], array_column($admin->openReports(), 'word'));
    same(['zorbing', 'sofa'], array_column(array_filter($admin->decisions(), fn ($d) => $d['decision'] === 'accepted'), 'word'));

    throwsStatus(400, fn () => $admin->decide('sport', 'gross', 'accepted'), 'Blocked words stay blocked');
    throwsStatus(400, fn () => $admin->decide('nope', 'sofa', 'accepted'));
    throwsStatus(400, fn () => $admin->decide('sport', 'two words', 'accepted'));
    same(1, $admin->stats()['openReports']);
    same(2, $admin->stats()['players']);
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

test('players see only their own misses', function () {
    $game = makeGame();
    $world = $game->createWorld('Misses');
    setPrompt($game, $world['id'], spec(null, ['contains', 'oo']));
    $a = $game->join($world['id'], 'Amy');
    $b = $game->join($world['id'], 'Bo');
    $game->play($world['id'], $a['token'], 'moon');
    $game->play($world['id'], $b['token'], 'cat');
    $game->play($world['id'], $b['token'], 'moon');
    $game->play($world['id'], $a['token'], 'xyzzy');

    $bo = $game->state($world['id'], $b['token'])['myMisses'];
    same(['moon', 'cat'], array_column($bo, 'word'), 'Newest first');
    same(['already-burned', 'doesnt-fit'], array_column($bo, 'reason'));
    same([Game::PENALTY, Game::PENALTY], array_column($bo, 'penalty'));
    same('Contains OO', $bo[0]['prompt']);
    same($game->state($world['id'], null)['prompt']['id'], $bo[0]['promptId'], 'Misses link to the current prompt');
    same(['xyzzy'], array_column($game->state($world['id'], $a['token'])['myMisses'], 'word'), "Amy sees only hers");
    same(0, $game->state($world['id'], $a['token'])['myMisses'][0]['penalty']);
    same([], $game->state($world['id'], null)['myMisses'], 'Spectators see none');
});

test('just said shows every word of the current prompt, then earlier prompts', function () {
    $clock = 1_000;
    $game = makeGame(function () use (&$clock) { return $clock; });
    $world = $game->createWorld('Said', null, 60_000);
    setPrompt($game, $world['id'], spec(null, ['contains', 'oo']));
    $p = $game->join($world['id'], 'Sal');
    foreach (['moon', 'book', 'cool', 'boot', 'food'] as $w) check($game->play($world['id'], $p['token'], $w)['ok']);
    $clock += 61_000; // Next prompt.
    $state = $game->state($world['id'], null);
    same(2, count($state['said']));
    same([], $state['said'][0]['words'], 'Nothing said in the new prompt yet');
    same('Contains OO', $state['said'][1]['prompt']);
    same(['food', 'boot', 'cool', 'book', 'moon'], array_column($state['said'][1]['words'], 'word'));
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
    $db->exec('DROP TABLE reports, misses, burns, prompts');
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
