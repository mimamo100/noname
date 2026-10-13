<?php
declare(strict_types=1);

const WORDS = [
    'cat', 'cats', 'run', 'running', 'runs', 'moon', 'moons', 'book', 'books', 'cool',
    'stop', 'stopped', 'agree', 'agreed', 'see', 'seed', 'hop', 'hope', 'hoped',
    'rat', 'rate', 'rates', 'sing', 'singe', 'singing', 'she', 'shed',
    'city', 'cities', 'boot', 'food', 'catch', 'start', 'stare', 'stone', 'stoop',
    'story', 'mood', 'noon', 'zoo', 'zoom', 'robin', 'robins', 'owl', 'stork', 'crow',
    'almost', 'first', 'floor', 'level', 'yummy', 'gross', 'brother', 'brothers', 'daughter',
    'daughters', 'box', 'boxes', 'news', 'new', 'squash', 'chess', 'sofa', 'colour', 'colours', 'color', 'colors',
];

const SPELLING = [
    'usOnly' => ['color', 'colors'],
    'ukOnly' => ['colour', 'colours'],
    'partners' => ['colour' => ['color'], 'color' => ['colour'], 'colours' => ['colors'], 'colors' => ['colours']],
];

const CATEGORIES = [
    'bird' => ['label' => 'A bird', 'words' => ['robin', 'robins', 'owl', 'stork', 'crow']],
    'sport' => ['label' => 'A sport or game', 'words' => ['chess'], 'also' => ['squash']],
    'animal' => ['label' => 'An animal', 'words' => ['cat', 'cats', 'rat', 'robin', 'robins', 'owl', 'stork', 'crow']],
];

const BLOCKED = ['gross'];

function testDictionary(): Dictionary
{
    return Dictionary::fromWords(WORDS, CATEGORIES, BLOCKED, SPELLING);
}

/** The test dictionary after the word files were updated to count "sofa" as a sport, as an approved report would be. */
function withSofaAsSport(): Dictionary
{
    $d = testDictionary();
    $d->addToCategory('sport', 'sofa');
    return $d;
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
    // Counted with the game's own dictionary: a prompt with too few answers would end at once.
    $remaining = Prompts::countUnsaid($spec, $game->dictionary(), []);
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

test('a word listed as standalone starts its own family', function () {
    $words = ['bunt', 'bunting', 'buntings', 'rail', 'railing', 'rails'];
    same(['bunt', 'bunting', 'buntings'], sorted(WordFamilies::build($words)['bunt']), 'By the rules, bunting is formed from bunt');
    $families = WordFamilies::build($words, ['bunting']);
    same(['bunting', 'buntings'], sorted($families['bunting']), 'The bird keeps its plural');
    check(!isset($families['bunt']), 'bunt is on its own');
    same(['rail', 'railing', 'rails'], sorted($families['rail']));
});

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

test('plurals of hand-added category words count too', function () {
    $d = testDictionary();
    check(!$d->inCategory('sport', 'books'));
    $d->addToCategory('sport', 'book'); // As a hand-added word in a category file does.
    check($d->inCategory('sport', 'book') && $d->inCategory('sport', 'books'));
    check(!$d->inCategory('sport', 'cats'), 'Only plurals of words on the list');
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
    // Box is the only answer, so it also finishes the prompt: the closing bonus.
    same(Scoring::scoreWord('box', $d->rarity('box'), 1, 1) + Scoring::CLOSING_BONUS, $game->play($world['id'], $p['token'], 'boxes')['points'], 'boxes scores as box');
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
    same(1.5, Scoring::promptBonus(20, 40), 'Gently: x1 plus the share said');
    same(1.75, Scoring::promptBonus(10, 40));
    same(2.0, Scoring::promptBonus(0, 40), 'At most x2');
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

test('reports are a queue: downloaded, folded into the word files, then marked reviewed', function () {
    $clock = 5_000;
    $game = makeGame(function () use (&$clock) { return $clock; });
    $db = gameDb($game);
    $world = $game->createWorld('Admin');
    setPrompt($game, $world['id'], spec('sport'));
    $p = $game->join($world['id'], 'Ann');
    $q = $game->join($world['id'], 'Bea');
    same('not-in-category', $game->play($world['id'], $p['token'], 'sofa')['reason']);
    $game->play($world['id'], $q['token'], 'sofa');
    $game->play($world['id'], $p['token'], 'netball');
    $game->report($world['id'], $p['token'], 'sofa');
    $game->report($world['id'], $q['token'], 'sofa');
    $game->report($world['id'], $p['token'], 'netball');

    $admin = new Admin($db, testDictionary(), fn () => $clock);
    $reports = $admin->openReports();
    same(['sofa', 'netball'], array_column($reports, 'word'), 'Most-reported first');
    same([2, 'A sport or game', ['A sport or game'], false], [$reports[0]['players'], $reports[0]['categoryLabel'], $reports[0]['prompts'], $reports[0]['counts']]);
    same(false, $reports[1]['inDictionary']);
    same(2, $admin->stats()['openReports']);
    $text = $admin->reportsText();
    check(str_contains($text, "## sport: A sport or game (categories/sport.txt)"), 'Grouped by category file');
    check((bool) preg_match('/^sofa +2 players/m', $text) && (bool) preg_match('/^netball .*NOT IN DICTIONARY/m', $text));
    same('reported', $game->state($world['id'], $p['token'])['myMisses'][1]['reportStatus'], 'Nothing changes until the files do');

    // The files are updated and uploaded: sofa counts now, netball doesn't.
    $updated = new Game($db, withSofaAsSport(), fn () => $clock, promptMin: 1, promptMax: 1000);
    $admin = new Admin($db, withSofaAsSport(), fn () => $clock);
    same([true, false], array_column($admin->openReports(), 'counts'));
    same(1, $admin->stats()['openReports'], 'Words that count now are no longer open');
    check(!str_contains($admin->reportsText(), 'sofa '), 'Nor in the download');
    $statuses = fn () => array_column($updated->state($world['id'], $p['token'])['myMisses'], 'reportStatus', 'word');
    same(['netball' => 'reported', 'sofa' => 'accepted'], $statuses());
    same(3, $admin->markReviewed(), 'Two reports of sofa, one of netball');
    same([], $admin->openReports());
    same(['netball' => 'dismissed', 'sofa' => 'accepted'], $statuses());

    // Words accepted under the old live acceptance, which the files don't have, are listed to fold in.
    $db->exec("INSERT INTO word_decisions (category, word, decision, decided_at) VALUES ('sport', 'sofa', 'accepted', 0), ('sport', 'chess', 'accepted', 0), ('sport', 'cat', 'dismissed', 0)");
    same(['sofa'], array_column((new Admin($db, testDictionary()))->legacyAccepted(), 'word'), 'Chess is in the files already');
    check(str_contains((new Admin($db, testDictionary()))->reportsText(), 'Accepted on the admin page earlier'));
    same([], (new Admin($db, withSofaAsSport()))->legacyAccepted());
    (new Admin($db, testDictionary()))->markReviewed();
    same([], (new Admin($db, testDictionary()))->legacyAccepted(), 'Marking reviewed clears the old list too');

    same([2, 2], [$admin->stats()['activeToday'], $admin->stats()['activeWeek']], 'Ann and Bea played');
    $later = (new Admin($db, testDictionary(), fn () => $clock + 2 * 86_400_000))->stats();
    same([0, 2], [$later['activeToday'], $later['activeWeek']], 'Two days on');
});

test('UK and US spellings are one answer', function () {
    $d = testDictionary();
    same(['color', 'colors', 'colour', 'colours'], sorted($d->family('colour')));
    $game = makeGame();
    $world = $game->createWorld('Both');
    setPrompt($game, $world['id'], spec(null, ['starts', 'co'])); // colour and cool, so it doesn't run dry.
    $a = $game->join($world['id'], 'Ann');
    check($game->play($world['id'], $a['token'], 'colour')['ok']);
    $again = $game->play($world['id'], $a['token'], 'color');
    same('already-burned', $again['reason'], 'color was used up by colour');
    same('colour', $again['burned']['playedWord']);
});

test('a UK or US world turns the other spelling away, free, with a suggestion', function () {
    $game = makeGame();
    $uk = $game->createWorld('UK', null, Game::DEFAULT_PROMPT_DURATION_MS, 'uk');
    same('uk', $game->state($uk['id'], null)['world']['spelling']);
    setPrompt($game, $uk['id'], spec(null, ['starts', 'co']));
    $p = $game->join($uk['id'], 'Pip');
    $wrong = $game->play($uk['id'], $p['token'], 'color');
    same('wrong-spelling', $wrong['reason']);
    same(0, $wrong['penalty']);
    same('uk', $wrong['spelling']);
    same(['colour'], $wrong['suggestions']);
    check($game->play($uk['id'], $p['token'], 'colour')['ok']);

    // Counts leave out spellings the world doesn't accept: in a US world nothing starting
    // "colo" contains a U, so "colour" isn't counted as an answer left.
    $us = $game->createWorld('US', null, Game::DEFAULT_PROMPT_DURATION_MS, 'us');
    setPrompt($game, $us['id'], spec(null, ['contains', 'ou'], ['starts', 'col']));
    same(0, Prompts::countUnsaid(spec(null, ['contains', 'ou'], ['starts', 'col']), testDictionary(), testDictionary()->wrongSpellings('us')));
    same(1, Prompts::countUnsaid(spec(null, ['contains', 'ou'], ['starts', 'col']), testDictionary(), testDictionary()->wrongSpellings('both')));

    throwsStatus(400, fn () => $game->createWorld('French', null, Game::DEFAULT_PROMPT_DURATION_MS, 'fr'));
});

test('the real dictionary links UK and US spellings', function () {
    $d = Dictionary::load();
    check(in_array('color', $d->family('colours'), true), 'colours and color are one answer');
    check(in_array('gray', $d->family('grey'), true), 'Hand-linked grey and gray');
    check(!in_array('tyre', $d->family('tired'), true), 'tired is not tyre');
    check($d->has('jewellery') && $d->has('jewelry'), 'Missing spellings are added');
    check($d->inCategory('clothing', 'jewellery'), 'Added spellings join their partner’s categories');
    check(isset($d->wrongSpellings('uk')['color']) && isset($d->wrongSpellings('us')['colour']));
    same([], $d->wrongSpellings('both'));
});

test('an already-said word is reported as such before the meaning is checked', function () {
    // The "fencing" case: said for a sport, then tried for a meaning it isn't listed under.
    $clock = 1_000;
    $game = makeGame(function () use (&$clock) { return $clock; });
    $world = $game->createWorld('Fencing', null, 60_000);
    setPrompt($game, $world['id'], spec('sport'));
    $me = $game->join($world['id'], 'Me');
    $other = $game->join($world['id'], 'Other');
    check($game->play($world['id'], $me['token'], 'chess')['ok']);
    $clock += 61_000;
    $game->state($world['id'], null); // Let the game move on to a new prompt.
    setPrompt($game, $world['id'], spec('bird'));

    $mine = $game->play($world['id'], $me['token'], 'chess');
    same('already-burned', $mine['reason'], 'Not "not on our list", which would invite a pointless report');
    same(0, $mine['penalty'], 'Repeating your own word is free');
    same(true, $mine['byYou']);
    same('A sport or game', $mine['burned']['prompt'], 'Says which prompt it was said for');

    $theirs = $game->play($world['id'], $other['token'], 'chess');
    same(Game::PENALTY, $theirs['penalty'], "Someone else's word still costs points");
    same(false, $theirs['byYou']);
    same('Me', $theirs['burned']['by']);
});

test('my misses show what happened to my reports', function () {
    $game = makeGame();
    $db = gameDb($game);
    $world = $game->createWorld('Reports');
    setPrompt($game, $world['id'], spec('sport'));
    $p = $game->join($world['id'], 'Rae');
    $game->play($world['id'], $p['token'], 'sofa');
    $status = function () use (&$game, $world, $p) { return $game->state($world['id'], $p['token'])['myMisses'][0]['reportStatus']; };
    same(null, $status());
    $game->report($world['id'], $p['token'], 'sofa');
    same('reported', $status(), 'Remembered by the server, not just the page');
    // The word files are updated and uploaded, so it counts now.
    $game = new Game($db, withSofaAsSport(), fn () => 1_000, promptMin: 1, promptMax: 1000);
    same('accepted', $status());
    same(null, $game->state($world['id'], $p['token'])['myMisses'][0]['said']);
    check($game->play($world['id'], $p['token'], 'sofa')['ok']);
    $said = $game->state($world['id'], $p['token'])['myMisses'][0]['said'];
    same([true, 'A sport or game'], [$said['byYou'], $said['prompt']], 'Said since, for the same prompt');
});

test('my misses say when an accepted word was used up elsewhere', function () {
    $clock = 1_000;
    $game = makeGame(function () use (&$clock) { return $clock; });
    $db = gameDb($game);
    $world = $game->createWorld('Used up', null, 60_000);
    setPrompt($game, $world['id'], spec('sport'));
    $rae = $game->join($world['id'], 'Rae');
    $sam = $game->join($world['id'], 'Sam');
    $game->play($world['id'], $rae['token'], 'sofa');
    $game->report($world['id'], $rae['token'], 'sofa');
    $clock += 61_000;
    $game->state($world['id'], null); // Let the game move on to a new prompt.
    setPrompt($game, $world['id'], spec(null, ['contains', 'fa']));
    check($game->play($world['id'], $sam['token'], 'sofa')['ok']);
    $updated = new Game($db, withSofaAsSport(), function () use (&$clock) { return $clock; }, promptMin: 1, promptMax: 1000);

    $miss = $updated->state($world['id'], $rae['token'])['myMisses'][0];
    same('accepted', $miss['reportStatus']);
    same(['Sam', false, 'Contains FA'], [$miss['said']['by'], $miss['said']['byYou'], $miss['said']['prompt']]);
});

test('wrong guesses cost points, typos are free', function () {
    $clock = 1_000;
    $game = makeGame(function () use (&$clock) { return $clock; });
    $world = $game->createWorld('Penalties');
    setPrompt($game, $world['id'], spec(null, ['contains', 'oo']));
    $a = $game->join($world['id'], 'Ann');
    $b = $game->join($world['id'], 'Ben');
    $points = $game->play($world['id'], $a['token'], 'moon')['points'];

    same(0, $game->play($world['id'], $b['token'], 'xyzzy')['penalty'], 'Typos are free');
    $justNow = $game->play($world['id'], $b['token'], 'moons');
    same([0, true], [$justNow['penalty'], $justNow['justNow']], 'Said by someone else moments ago: free');
    $clock += Game::SAID_GRACE_MS + 1;
    same(Game::PENALTY, $game->play($world['id'], $b['token'], 'cat')['penalty'], "Doesn't fit");
    same(Game::PENALTY, $game->play($world['id'], $b['token'], 'moon')['penalty'], 'Already said, a while ago');

    $board = array_column($game->state($world['id'], null)['leaderboard'], null, 'nickname');
    same($points, $board['Ann']['total']);
    same(-2 * Game::PENALTY, $board['Ben']['total']);
    same(-2 * Game::PENALTY, array_column($game->state($world['id'], null)['promptBoard'], 'points', 'nickname')['Ben']);
    same(0, $board['Ben']['words']);
});

test('the prompt leaderboard ranks the current prompt alone', function () {
    $clock = 1_000;
    $game = makeGame(function () use (&$clock) { return $clock; });
    $world = $game->createWorld('Late', null, 600_000);
    setPrompt($game, $world['id'], spec(null, ['contains', 'oo']));
    $early = $game->join($world['id'], 'Early');
    $before = $game->play($world['id'], $early['token'], 'moon')['points']
        + $game->play($world['id'], $early['token'], 'book')['points'];
    $clock += 601_000;
    $game->state($world['id'], null); // Let the game move on to a new prompt.
    setPrompt($game, $world['id'], spec('bird'));

    same([], $game->state($world['id'], null)['promptBoard'], 'Empty until someone tries the new prompt');
    $late = $game->join($world['id'], 'Late');
    $points = $game->play($world['id'], $late['token'], 'robin')['points'];
    $clock += Game::SAID_GRACE_MS + 1;
    $game->play($world['id'], $early['token'], 'robin'); // Already said by Late, a while ago: a penalty.

    $state = $game->state($world['id'], null);
    $overall = array_column($state['leaderboard'], 'total', 'nickname');
    same($before - Game::PENALTY, $overall['Early'], 'Overall keeps the earlier prompt');
    same([
        ['nickname' => 'Late', 'points' => $points, 'words' => 1],
        ['nickname' => 'Early', 'points' => -Game::PENALTY, 'words' => 0],
    ], $state['promptBoard']);
});

test('running score totals match a full recount', function () {
    $game = makeGame();
    $db = gameDb($game);
    $world = $game->createWorld('Totals');
    setPrompt($game, $world['id'], spec(null, ['contains', 'oo']));
    $a = $game->join($world['id'], 'Ann');
    $b = $game->join($world['id'], 'Ben');
    $game->play($world['id'], $a['token'], 'moon');
    $game->play($world['id'], $a['token'], 'book');
    $game->play($world['id'], $b['token'], 'cat'); // Doesn't fit: a penalty.
    $game->play($world['id'], $b['token'], 'xyzzy'); // Typo: free.
    $board = fn () => array_map(fn ($p) => [$p['nickname'], $p['total'], $p['words']], $game->state($world['id'], null)['leaderboard']);
    $kept = $board();
    $db->exec('UPDATE players SET score = 0, words = 0');
    Game::recountScores($db, $world['id']);
    same($kept, $board());
    same(['Ben', -Game::PENALTY, 0], $kept[1]);
});

test('older databases get score totals filled in', function () {
    $game = makeGame();
    $db = gameDb($game);
    $world = $game->createWorld('Old');
    setPrompt($game, $world['id'], spec(null, ['contains', 'oo']));
    $points = $game->play($world['id'], $game->join($world['id'], 'Ann')['token'], 'moon')['points'];
    $db->exec('ALTER TABLE players DROP KEY idx_score, DROP COLUMN score, DROP COLUMN words');
    $db->exec('ALTER TABLE worlds DROP COLUMN version');
    Game::installSchema($db);
    same([$points, 1], [(int) $db->query('SELECT score FROM players')->fetchColumn(), (int) $db->query('SELECT words FROM players')->fetchColumn()]);
});

test('the state cache shares one answer between viewers but shows new words at once', function () {
    $dir = sys_get_temp_dir() . '/unsaid-cache-test-' . bin2hex(random_bytes(4));
    $cache = StateCache::open($dir, 60);
    $db = testDb();
    $game = new Game($db, testDictionary(), fn () => 1_000, promptMin: 1, promptMax: 1000, stateCache: $cache);
    $world = $game->createWorld('Cached');
    setPrompt($game, $world['id'], spec(null, ['contains', 'oo']));
    $first = $game->state($world['id'], null);
    // A change the game doesn't know about (no version bump) stays hidden while the copy is fresh...
    $db->exec("UPDATE worlds SET name = 'Renamed'");
    same('Cached', $game->state($world['id'], null)['world']['name'], 'Served from the cache');
    // ...but saying a word shows straight away.
    $game->play($world['id'], $game->join($world['id'], 'Ann')['token'], 'moon');
    $after = $game->state($world['id'], null);
    same('moon', $after['said'][0]['words'][0]['word']);
    same('Renamed', $after['world']['name']);
    check($first['prompt']['remaining'] > $after['prompt']['remaining']);

    $calls = 0;
    $short = new StateCache($dir, 0.2);
    $compute = function () use (&$calls) { $calls++; return ['n' => $calls]; };
    $short->get('x', 1, $compute);
    $short->get('x', 1, $compute);
    same(1, $calls, 'Fresh copy reused');
    $short->get('x', 2, $compute);
    same(2, $calls, 'New version recomputed');
    usleep(250_000);
    same(['n' => 3], $short->get('x', 2, $compute), 'Old copy recomputed');
    array_map('unlink', glob("$dir/*"));
    rmdir($dir);
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
    same([0, Game::PENALTY], array_column($bo, 'penalty'), 'Amy said moon moments ago, so it was free');
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
    same(['count' => 6, 'words' => ['stoop', 'mood', 'noon', 'zoo', 'zoom', 'floor']], $state['said'][1]['unsaid'],
        'What nobody said, most common first');
    check(!isset($state['said'][0]['unsaid']), 'Not for the prompt still running');
});

test('the unsaid reveal is noted when the prompt ends, and leaves masked words out', function () {
    $clock = 1_000;
    $dictionary = Dictionary::fromWords(['cat', 'cats', 'tit', 'tits', 'moon', 'assets', 'start'], [], [], [], ['tit', 'tits']);
    $game = new Game(testDb(), $dictionary, function () use (&$clock) { return $clock; }, promptMin: 1, promptMax: 1000);
    $world = $game->createWorld('Reveal', null, 60_000);
    setPrompt($game, $world['id'], spec(null, ['contains', 't']));
    $clock += 61_000;
    $game->state($world['id'], null);
    // Noted at the end, as later prompts use words up too.
    same('4', (string) gameDb($game)->query("SELECT unsaid_count FROM prompts WHERE world_id = '{$world['id']}' ORDER BY id LIMIT 1")->fetchColumn());
    $said = $game->state($world['id'], null)['said'];
    same(['count' => 4, 'words' => ['cat', 'assets', 'start']], end($said)['unsaid'], 'tit is counted but not shown');
});

test('spaces, hyphens and apostrophes are dropped from what players type', function () {
    same('teatowel', Game::typed(' Tea towel '));
    same('tshirt', Game::typed('T-Shirt'));
    same('dont', Game::typed("don\u{2019}t"));
    $game = makeGame();
    $world = $game->createWorld('Typed');
    setPrompt($game, $world['id'], spec(null, ['contains', 'oo']));
    $p = $game->join($world['id'], 'Ty');
    same('book', $game->play($world['id'], $p['token'], 'Bo-ok')['word']);
    same('Ty', $game->lookup($world['id'], 'B OOK')['burned']['by']);
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
    $zoo = $game->play($world['id'], $p['token'], 'zoo');
    check($zoo['ok'] && !isset($zoo['closing']));
    $zoom = $game->play($world['id'], $p['token'], 'zoom');
    same(true, $zoom['closing'], 'The answer that finishes it');
    same(Scoring::scoreWord('zoom', testDictionary()->rarity('zoom'), $zoom['multipliers']['world'], $zoom['multipliers']['prompt']) + Scoring::CLOSING_BONUS,
        $zoom['points'], 'earns the closing bonus');
    $afterDry = $game->state($world['id'], null)['prompt']['label'];
    $said = $game->state($world['id'], null)['said'][1];
    same(0, $said['unsaid']['count'], 'Every answer was said');
    same('zoom', $said['closingWord']);
    check($afterDry !== 'Starts with ZO', 'Did not rotate when dry');

    $clock += 61_000;
    check($game->state($world['id'], null)['prompt']['label'] !== $afterDry, 'Did not rotate on timeout');
});

test('a prompt ends once 80% of its answers are said', function () {
    $game = makeGame();
    $world = $game->createWorld('Share');
    setPrompt($game, $world['id'], spec(null, ['contains', 'oo'])); // 11 answers: it ends with 2 left.
    $p = $game->join($world['id'], 'Pat');
    same(2, $game->state($world['id'], null)['prompt']['endsWhenLeft']);
    foreach (['moon', 'book', 'cool', 'boot', 'food', 'stoop', 'mood', 'noon'] as $w) check(!isset($game->play($world['id'], $p['token'], $w)['closing']));
    same([3, 'Contains OO'], [$game->state($world['id'], null)['prompt']['remaining'], $game->state($world['id'], null)['prompt']['label']]);
    same(true, $game->play($world['id'], $p['token'], 'zoo')['closing'], 'The ninth answer finishes it');
    check($game->state($world['id'], null)['prompt']['label'] !== 'Contains OO', 'A new prompt');
    same(2, $game->state($world['id'], null)['said'][1]['unsaid']['count'], 'Two left unsaid');
});

test('answers a category accepts without counting score without the prompt bonus', function () {
    $game = makeGame();
    $world = $game->createWorld('Also');
    setPrompt($game, $world['id'], spec('animal'));
    $p = $game->join($world['id'], 'Pat');
    foreach (['cat', 'rat', 'robin', 'owl', 'stork'] as $w) $game->play($world['id'], $p['token'], $w); // 5 of 6: x1.83 now.
    setPrompt($game, $world['id'], spec('sport'));
    gameDb($game)->exec("UPDATE prompts SET available_at_start = 4 WHERE world_id = '{$world['id']}' AND ended_at IS NULL"); // As if 3 were said.
    $squash = $game->play($world['id'], $p['token'], 'squash');
    same([true, 1.0], [$squash['uncounted'], $squash['multipliers']['prompt']], 'Squash is only an "also" sport');
    same(1, $game->state($world['id'], null)['prompt']['remaining'], "It doesn't bring the count down");
    $chess = $game->play($world['id'], $p['token'], 'chess');
    same(1.75, $chess['multipliers']['prompt'], 'A counted answer gets the bonus');
});

test('why a word does not fit', function () {
    $d = testDictionary();
    same('it has an E', Prompts::whyNot(spec(null, ['lacks', 'e']), $d, 'agree'));
    same("it doesn't start with ST", Prompts::whyNot(spec(null, ['starts', 'st']), $d, 'moon'));
    same('it\'s 7 letters (“brothers” counts as “brother”), not 8 or more', Prompts::whyNot(spec(null, ['minLen', 8]), $d, 'brothers'));
    same("its letters aren't in alphabetical order: O comes before N", Prompts::whyNot(spec(null, ['alpha']), $d, 'moon'));
    same(null, Prompts::whyNot(spec(null, ['contains', 'oo']), $d, 'moon'));
    $game = makeGame();
    $world = $game->createWorld('Why');
    setPrompt($game, $world['id'], spec(null, ['lacks', 'e']));
    $p = $game->join($world['id'], 'Pat');
    $miss = $game->play($world['id'], $p['token'], '  Agree ');
    same(['doesnt-fit', 'it has an E', 'agree'], [$miss['reason'], $miss['why'], $miss['word']]);
    same('dont know', $game->play($world['id'], $p['token'], 'Dont  Know')['word'], 'An unknown word is echoed as typed');
});

test('a word sent just as the prompt changed is judged against the old one', function () {
    $clock = 1_000;
    $game = makeGame(function () use (&$clock) { return $clock; });
    $world = $game->createWorld('Grace', null, 60_000);
    setPrompt($game, $world['id'], spec(null, ['contains', 'oo']));
    $p = $game->join($world['id'], 'Pat');
    $old = $game->state($world['id'], null)['prompt']['id'];
    $clock += 61_000;
    $game->state($world['id'], null); // The prompt changes.
    setPrompt($game, $world['id'], spec(null, ['starts', 'zo']));
    $clock += 10_000;
    $late = $game->play($world['id'], $p['token'], 'moon', $old);
    same([true, 'Contains OO'], [$late['ok'], $late['previousPrompt']], 'Counted for the prompt the player saw');
    $miss = $game->play($world['id'], $p['token'], 'cat', $old);
    same(['doesnt-fit', 0], [$miss['reason'], $miss['penalty']], "A miss for the old prompt is free");
    $clock += Game::PROMPT_GRACE_MS;
    same('doesnt-fit', $game->play($world['id'], $p['token'], 'book', $old)['reason'], 'Too late: judged against the new prompt');
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

    $db->exec('ALTER TABLE worlds DROP COLUMN spelling');
    Game::installSchema($db);
    Game::installSchema($db); // Running it twice is harmless.
    check((bool) $db->query("SHOW COLUMNS FROM worlds LIKE 'spelling'")->fetch(), 'Spelling column added');
    $game = new Game($db, testDictionary(), fn () => 1_000);
    same('Contains OO', $game->state('old', null)['prompt']['label']);
    $p = $game->join('old', 'Olive');
    check($game->play('old', $p['token'], 'book')['ok']);
    same(Game::PENALTY, $game->play('old', $p['token'], 'cat')['penalty']);
});

test('masked words are playable, hidden in the feed, free to repeat, and kept out of names and prompts', function () {
    $words = ['tit', 'tits', 'moon', 'assess', 'assets', 'passage', 'classes', 'masses', 'grass', 'ass', 'cat'];
    $dictionary = Dictionary::fromWords($words, [], [], [], ['tit', 'tits', 'ass']);
    $game = new Game(testDb(), $dictionary, fn () => 1_000, promptMin: 1, promptMax: 1000);
    $world = $game->createWorld('Masked');
    setPrompt($game, $world['id'], spec(null, ['contains', 't']));
    $ann = $game->join($world['id'], 'Ann');
    $ben = $game->join($world['id'], 'Ben');
    check($game->play($world['id'], $ann['token'], 'tit')['ok'], 'Playable and scores');

    $feed = $game->state($world['id'], null)['said'][0]['words'];
    same(['tit', true], [$feed[0]['word'], $feed[0]['masked']]);
    $again = $game->play($world['id'], $ben['token'], 'tits');
    same(['already-burned', 0], [$again['reason'], $again['penalty']], "Ben couldn't see it, so it's free");

    check($dictionary->isOffensiveName('Big Tits') && $dictionary->isOffensiveName('ass'));
    check(!$dictionary->isOffensiveName('Cat'));

    // The fallback prompt picks common letter strings, but never one that is a rude word.
    $simple = new ReflectionMethod(Prompts::class, 'simplePrompt');
    $allPicks = function (Dictionary $d) use ($simple): array {
        $used = [];
        $letters = [];
        while ($pick = $simple->invoke(null, $d, [], $used)) {
            $used[Prompts::key($pick['spec'])] = true;
            $letters[] = $pick['spec']['rules'][0][1];
        }
        return $letters;
    };
    $plain = $allPicks(Dictionary::fromWords($words));
    check(in_array('ass', $plain, true) && in_array('tit', $plain, true), 'Without masking they come up');
    same([], array_values(array_intersect(['ass', 'tit'], $allPicks($dictionary))), 'With masking they never do');
});

test('a phrase scores by its longest word, plus 1 for each other word', function () {
    same(5, Scoring::base('steamer'));
    same(6, Scoring::base('paddle steamer'));
    same(7, Scoring::base('hot air balloon'), 'balloon 5, plus 2');
    same(1, Scoring::base('cat'));
});

test('the other spelling of a word already said is the same answer, and the message can say so', function () {
    $clock = 1_000;
    $game = makeGame(function () use (&$clock) { return $clock; });
    $world = $game->createWorld('Spell');
    setPrompt($game, $world['id'], spec(null, ['contains', 'o']));
    $a = $game->join($world['id'], 'Ann');
    $b = $game->join($world['id'], 'Ben');
    check($game->play($world['id'], $a['token'], 'colour')['ok']);
    $miss = $game->play($world['id'], $b['token'], 'color');
    same(['already-burned', true, 'colour'], [$miss['reason'], $miss['otherSpelling'], $miss['burned']['playedWord']]);
});

test('words normally used in the plural are judged as written', function () {
    $d = new Dictionary(['trouser', 'trousers', 'brother', 'brothers'], [['trouser', 'trousers'], ['brother', 'brothers']], [], [], [], [], [], [], ['trousers']);
    same('trousers', $d->singular('trousers'));
    same('brother', $d->singular('brothers'));
    check(Prompts::matches(spec(null, ['minLen', 8]), $d, 'trousers'), '8 letters as written');
});

test('a missed phrase is shown as typed in My misses, and a miss says which prompt it was for', function () {
    $game = makeGame();
    $world = $game->createWorld('Typed');
    setPrompt($game, $world['id'], spec(null, ['contains', 'oo']));
    $p = $game->join($world['id'], 'Pat');
    $miss = $game->play($world['id'], $p['token'], 'Double Decker');
    same([$game->state($world['id'], null)['prompt']['id'], 'double decker'], [$miss['promptId'], $miss['word']]);
    same('double decker', $game->state($world['id'], $p['token'])['myMisses'][0]['word']);
});
