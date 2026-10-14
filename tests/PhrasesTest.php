<?php
declare(strict_types=1);

// Phrases ("tea towel", "black hole") are played joined and shown as written; curated packs
// may list blocked words, playable only in their worlds.

const SPACE_PHRASES = "A space thing\nblack hole, black holes\nevent horizon, event horizons\nred giant, red giants\nwhite dwarf, white dwarfs\nquasar, quasars\n";

/** A clock tests can move, and a game on a dictionary that uses it. */
function phraseGame(Dictionary $dictionary, int &$clock): Game
{
    return new Game(testDb(), $dictionary, function () use (&$clock) { return $clock; }, promptMin: 1, promptMax: 1000);
}

test('category files take phrases: played joined, shown as written', function () {
    $category = Dictionary::readCategory(['label: Something in the kitchen', 'includes: tool', 'tea towel', 't-shirt', "rock'n'roll", 'kettle', 'also:', "egg  cup"]);
    same('Something in the kitchen', $category['label']);
    same(['tool'], $category['includes']);
    same(['teatowel', 'tshirt', 'rocknroll', 'kettle'], array_keys($category['words']));
    same(['eggcup'], array_keys($category['also']));
    same(['teatowel' => 'tea towel', 'tshirt' => 't-shirt', 'rocknroll' => "rock'n'roll", 'eggcup' => 'egg cup'], $category['display'],
        'Only words written differently are noted, with single spaces');
    foreach (['Tea Towel', 'a b', 'tea2 towel', 'ok'] as $bad) {
        try {
            Dictionary::readCategory([$bad]);
            throw new RuntimeException("\"$bad\" was accepted");
        } catch (InvalidArgumentException) {
            // Refused, as it should be.
        }
    }

    $words = [...WORDS, 'teatowel', 'teatowels', 'kettle'];
    $categories = CATEGORIES + ['kitchen' => ['label' => 'Something in the kitchen', 'words' => ['teatowel', 'kettle']]];
    $dictionary = Dictionary::fromWords($words, $categories, BLOCKED, SPELLING, [], $category['display']);
    same('tea towel', $dictionary->display('teatowel'));
    same('kettle', $dictionary->display('kettle'), 'Single words are shown as they are');
    $clock = 1_000;
    $game = phraseGame($dictionary, $clock);
    $world = $game->createWorld('Kitchen');
    setPrompt($game, $world['id'], spec('kitchen'));
    $p = $game->join($world['id'], 'Kit');
    $played = $game->play($world['id'], $p['token'], 'Tea-Towel');
    same([true, 'tea towel'], [$played['ok'], $played['word']]);
    same('teatowel', (string) gameDb($game)->query("SELECT word FROM burns WHERE played_word = word")->fetchColumn(), 'Stored joined');
    same('tea towel', $game->state($world['id'], $p['token'])['said'][0]['words'][0]['word'], 'The feed shows it as written');
    $lookup = $game->lookup($world['id'], 'teatowel');
    same(['tea towel', 'tea towel'], [$lookup['word'], $lookup['burned']['playedWord']]);
    $again = $game->play($world['id'], $p['token'], 'tea towel');
    same(['already-burned', 'tea towel', 'tea towel'], [$again['reason'], $again['word'], $again['burned']['playedWord']]);
    same('tea towel', $game->state($world['id'], $p['token'])['myMisses'][0]['word'], 'My misses show it as written');
});

test('curated pack files list forms separated by commas, phrases included', function () {
    $group = Packs::readCuratedFile(['label: A thing in physics', 'quark, quarks', 'metre, meter, metres, meters', 'black hole, black holes', 'habeas corpus',
        'also:', "rock'n'roll"]);
    same('A thing in physics', $group['label']);
    same([['quark', 'quarks'], ['metre', 'meter', 'metres', 'meters'], ['black hole', 'black holes'], ['habeas corpus']], $group['words']);
    same([["rock'n'roll"]], $group['also']);
    $built = Packs::build(['thing' => $group], testDictionary(), 'sci', curated: true);
    same([], $built['problems']);
    same(4, $built['categories']['sci.thing']['answers'], 'One answer per line, however many forms');
    check(in_array('habeascorpus', $built['categories']['sci.thing']['words'], true), 'A phrase on its own is one form, joined');
    check(in_array('rocknroll', $built['categories']['sci.thing']['also'], true));
    same(['blackhole' => 'black hole', 'blackholes' => 'black holes', 'habeascorpus' => 'habeas corpus', 'rocknroll' => "rock'n'roll"], $built['display']);
    check(in_array(['blackhole', 'blackholes'], array_map(fn ($f) => array_values(array_intersect(['blackhole', 'blackholes'], $f)), $built['families']), true),
        'The forms are one family');
    $only = testDictionary()->onlyPack($built);
    same('black holes', $only->display('blackholes'));
    same('quark', $only->display('quark'));
});

test("organisers' packs: one answer per line, other forms after commas; old packs split on spaces", function () {
    $groups = Packs::parseText("A space thing\nquark, quarks\nblack hole, black holes\nred giant,\nwhite dwarf\nNeutron  Star , neutron stars\n");
    same([['quark', 'quarks'], ['black hole', 'black holes'], ['red giant'], ['white dwarf'], ['neutron  star', 'neutron stars']], $groups['c1']['words']);
    $built = Packs::build($groups, testDictionary(), 'x');
    same([], $built['problems']);
    check(in_array('blackhole', $built['words'], true) && in_array('whitedwarf', $built['words'], true) && !in_array('white', $built['words'], true));
    // A pack saved before that rule keeps its meaning, and the editor shows it the new way.
    same([['quark', 'quarks'], ['white', 'dwarf']], Packs::parseText("A thing\nquark quarks\nwhite dwarf", splitSpaces: true)['c1']['words']);
    same("A thing\nquark, quarks\nblack hole, black holes\n\nA moon\ntitan", Packs::convertOldText("A thing\nquark quarks\nblack hole, black holes\n\nA moon\ntitan"));
    same('neutron star', $built['display']['neutronstar'], 'Written forms are tidied to single spaces');
    same([], $built['blocked']);
});

test('phrases show as written in the feed and in the unsaid list when a prompt ends', function () {
    $everyday = testDictionary();
    $pack = Packs::build(Packs::parseText(SPACE_PHRASES), $everyday, 'sp');
    $dictionary = $everyday->onlyPack($pack);
    $clock = 1_000;
    $game = phraseGame($dictionary, $clock);
    $world = $game->createWorld('Space', null, 60_000, 'both', null, ['pack' => 'sp', 'packMode' => 'only']);
    $game->state($world['id'], null);
    setPrompt($game, $world['id'], spec('sp.c1'));
    $p = $game->join($world['id'], 'Sal');
    $played = $game->play($world['id'], $p['token'], 'black hole');
    same(['black hole', ['black holes']], [$played['word'], $played['alsoBurned']]);
    same('black hole', $game->state($world['id'], null)['said'][0]['words'][0]['word']);
    $clock += 61_000;
    $said = $game->state($world['id'], null)['said'];
    $ended = end($said);
    same(['event horizon', 'quasar', 'red giant', 'white dwarf'], sorted($ended['unsaid']['words']), 'Nobody said: as written');
    same('["eventhorizon",', substr((string) gameDb($game)->query('SELECT unsaid_words FROM prompts ORDER BY id LIMIT 1')->fetchColumn(), 0, 16),
        'Stored joined');
});

test('masked words stay masked, whatever their written form', function () {
    $dictionary = Dictionary::fromWords(['tit', 'tits', 'teatowel', 'cat'], [], [], [], ['tit', 'tits'], ['tit' => 'ti-t', 'teatowel' => 'tea towel']);
    same('tit', $dictionary->display('tit'), 'A masked word is left as it is, for the page to mask');
    $clock = 1_000;
    $game = phraseGame($dictionary, $clock);
    $world = $game->createWorld('Masked', null, 60_000);
    setPrompt($game, $world['id'], spec(null, ['contains', 't']));
    $p = $game->join($world['id'], 'Ann');
    check($game->play($world['id'], $p['token'], 'ti-t')['ok']);
    $feed = $game->state($world['id'], null)['said'][0]['words'];
    same(['tit', true], [$feed[0]['word'], $feed[0]['masked']]);
    $clock += 61_000;
    $said = $game->state($world['id'], null)['said'];
    same(['cat', 'tea towel'], sorted(end($said)['unsaid']['words']), 'Masked words are still left out of the unsaid list');
});

test('a curated pack may list a blocked word, playable only in its worlds; organisers\' packs and nicknames still refuse it', function () {
    $everyday = testDictionary();
    check($everyday->isBlocked('gross'));
    $group = Packs::readCuratedFile(['label: A crime', 'gross, grosses', 'murder, murders']);
    $built = Packs::build(['crime' => $group], $everyday, 'law', curated: true);
    same([], $built['problems'], 'No blocklist problem for a curated pack');
    same(['gross'], $built['blocked'], 'The blocked words it lists are noted');

    $only = $everyday->onlyPack($built);
    check($only->has('gross') && !$only->isBlocked('gross'), 'Playable in a world of the pack alone');
    check(!$everyday->withPack($built)->isBlocked('gross'), 'And with everyday words');
    check($everyday->isBlocked('gross'), 'Still blocked outside the pack');
    check($only->isOffensiveName('Gross') && $everyday->withPack($built)->isOffensiveName('mr gross'), 'Never a nickname');

    $clock = 1_000;
    $game = phraseGame($only, $clock);
    $world = $game->createWorld('Court', null, 60_000, 'both', null, ['pack' => 'law', 'packMode' => 'only']);
    $game->state($world['id'], null);
    setPrompt($game, $world['id'], spec('law.crime'));
    $p = $game->join($world['id'], 'Judge');
    check($game->play($world['id'], $p['token'], 'gross')['ok'], 'Said in the pack world');
    throwsStatus(400, fn () => $game->join($world['id'], 'Gross'));

    $custom = Packs::build(Packs::parseText("A crime\ngross, grosses\nmurder\n"), $everyday, 'x');
    same(['“gross” is on the blocklist'], $custom['problems'], "Organisers' packs refuse it as before");
    $db = gameDb($game);
    $org = withPlan($db, makeUser($db, 'Org'), 'organiser');
    throwsStatus(400, fn () => (new Packs($db))->create($org, 'Crimes', '', PLANETS . "\n\nA crime\ngross, grosses\n", $everyday));
});

test("a pack world's best word is shown as written in My games", function () {
    $db = testDb();
    $packs = new Packs($db);
    $org = withPlan($db, makeUser($db, 'Org'), 'organiser');
    $made = $packs->create($org, 'Space', '', PLANETS . "\n\n" . SPACE_PHRASES, testDictionary());
    $game = makeGameOn($db, $packs->dictionary($made['id'], 'only', testDictionary()));
    $private = new PrivateWorlds($db, $game);
    $world = $private->create($org, ['name' => 'Space club', 'pack' => $made['id'], 'packMode' => 'only']);
    $game->state($world['id'], null);
    setPrompt($game, $world['id'], spec("{$made['id']}.c4"));
    $p = $private->playerFor($game->world($world['id']), $org, null);
    same('black hole', $game->play($world['id'], $p['token'], 'blackhole')['word']);
    $game->finishWorld($world['id']);
    $lobby = new Lobby($db, makeGameOn($db, testDictionary()), [], null, $packs);
    $history = $lobby->history($org['id']);
    same('black hole', $history['games'][0]['bestWord']);
    same('black hole', $history['bests']['bestWord']['word']);
    same('black hole', $lobby->result($world['id'], $org['id'])['bestWord']);
});

test('a plural of a written phrase is shown written too', function () {
    $d = Dictionary::fromWords(['teatowel', 'teatowels', 'waterlily', 'waterlilies', 'lily', 'lilies'], [], [], [], [],
        ['teatowel' => 'tea towel', 'waterlily' => 'water lily']);
    same('tea towels', $d->display('teatowels'));
    same('water lilies', $d->display('waterlilies'));
    same('lilies', $d->display('lilies'), 'Words with no written form are unchanged');
});
