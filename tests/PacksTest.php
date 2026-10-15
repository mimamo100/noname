<?php
declare(strict_types=1);

const PLANETS = "A planet\nmercury\nvenus\nearth\nmars\njupiter\nsaturn\nuranus\nneptune\n\nA moon\ntitan\neuropa\nrhea\ncallisto\nganymede\n\nA particle\nquark, quarks\nnucleus, nuclei\nproton, protons\nneutron, neutrons\nelectron, electrons\nphoton, photons\ngluon, gluons\nmuon, muons\nboson, bosons\nlepton, leptons\nhadron, hadrons\nmeson, mesons\npion, pions\nkaon, kaons\nbaryon, baryons\nneutrino, neutrinos\npositron, positrons\ntachyon, tachyons\naxion, axions\ngraviton, gravitons\nfermion, fermions\n";

test('a pack is built from lists: forms on a line are one answer, and everyday forms come along', function () {
    $built = Packs::build(Packs::parseText("A planet\nmars\nvenus\n\nA thing\nmoon\nnucleus, nuclei\nXbox\nf0o\n"), testDictionary(), 'sci');
    same(['sci.c1', 'sci.c2'], array_keys($built['categories']));
    same('A planet', $built['categories']['sci.c1']['label']);
    check(in_array('moons', $built['words'], true), 'The everyday family of moon comes along');
    check(in_array(['nucleus', 'nuclei'], array_map(fn ($f) => array_values(array_intersect(['nucleus', 'nuclei'], $f)), $built['families']), true));
    same(1, count($built['problems']), 'Xbox is just lowercased; f0o is not a word');
    same('moon', $built['words'][0], 'Everyday words first, most common first');
});

test('a pack-only dictionary has just the pack; a mixed one adds it to everyday words', function () {
    $everyday = testDictionary();
    $pack = Packs::build(Packs::parseText("A body\nmoon\nnucleus, nuclei\nquasar, quasars\n"), $everyday, 'sci');
    $only = $everyday->onlyPack($pack);
    same(['sci.c1'], $only->categoryIds());
    check($only->has('nuclei') && $only->has('moons') && !$only->has('cat'));
    same(['nuclei', 'nucleus'], sorted($only->family('nucleus')));
    $mixed = $everyday->withPack($pack);
    check($mixed->has('cat') && $mixed->has('quasar'), 'Everyday words and pack words');
    same(['sci.c1'], $mixed->categoryIds(), "The pack's categories make the prompts");
    same(['moon', 'moons'], sorted($mixed->family('moon')));
    check($mixed->rarity('quasar') > $mixed->rarity('moon'), 'Pack-only words count as rare');
});

test('organisers make, change and delete their own packs; hosts get curated packs only', function () {
    $db = testDb();
    $packs = new Packs($db);
    $org = withPlan($db, makeUser($db, 'Org'), 'organiser');
    $host = withPlan($db, makeUser($db, 'Hal'), 'host');
    throwsStatus(403, fn () => $packs->create($host, 'Space', '', PLANETS, testDictionary()));
    throwsStatus(400, fn () => $packs->create($org, 'Tiny', '', "A planet\nmars\nvenus", testDictionary()), 'Too few words');
    throwsStatus(400, fn () => $packs->create($org, '', '', PLANETS, testDictionary()), 'Needs a name');
    throwsStatus(400, fn () => $packs->create($org, 'Bad', '', PLANETS . "\n\nA thing\ngross", testDictionary()), 'Blocked words are refused');
    $made = $packs->create($org, 'Space', 'Planets and particles', PLANETS, testDictionary());
    same(['A planet', 'A moon', 'A particle'], array_column($made['lists'], 'label'));
    same(['Space'], array_column($packs->mine($org), 'name'));
    same(['Space'], array_column($packs->available($org)['custom'], 'name'));
    same([], $packs->available($host)['custom'], 'Only organisers use custom packs');
    same('Space', $packs->checkUsable($org, $made['id']));
    throwsStatus(403, fn () => $packs->checkUsable($host, $made['id']));
    throwsStatus(404, fn () => $packs->checkUsable(withPlan($db, makeUser($db, 'Other'), 'organiser'), $made['id']), "Someone else's pack");
    $packs->update($org, $made['id'], 'Space 2', '', PLANETS . "\n\nA star\nsun\nvega\nsirius\nrigel\n", testDictionary());
    same('Space 2', $packs->mine($org)[0]['name']);
    $packs->delete($org, $made['id']);
    same([], $packs->mine($org));
});

test('a world with a pack plays the pack: its prompts, its words, and its size', function () {
    $db = testDb();
    $packs = new Packs($db);
    $org = withPlan($db, makeUser($db, 'Org'), 'organiser');
    $made = $packs->create($org, 'Space', '', PLANETS, testDictionary());
    $private = new PrivateWorlds($db, makeGameOn($db, testDictionary()));
    $world = $private->create($org, ['name' => 'Space club', 'pack' => $made['id'], 'packMode' => 'only']);
    $row = $db->query("SELECT pack, pack_mode, prompt_min, prompt_max FROM worlds WHERE id = '{$world['id']}'")->fetch(PDO::FETCH_NUM);
    same([$made['id'], 'only', 5, 40], [$row[0], $row[1], (int) $row[2], (int) $row[3]]);
    same(0, (int) $db->query("SELECT COUNT(*) FROM prompts WHERE world_id = '{$world['id']}'")->fetchColumn(), 'No everyday prompt at creation');

    $game = makeGameOn($db, $packs->dictionary($made['id'], 'only', testDictionary()));
    $state = $game->state($world['id'], null);
    check($state['prompt']['availableAtStart'] >= 5 && $state['prompt']['availableAtStart'] <= 40, 'A pack-sized prompt: ' . $state['prompt']['label']);
    setPrompt($game, $world['id'], ['cat' => "{$made['id']}.c3", 'rules' => []]);
    $p = $private->playerFor($game->world($world['id']), $org, null);
    same(true, $game->play($world['id'], $p['token'], 'nuclei')['ok'], 'Irregular forms listed together count');
    same('already-burned', $game->play($world['id'], $p['token'], 'nucleus')['reason']);
    same('not-a-word', $game->play($world['id'], $p['token'], 'cat')['reason'], 'Everyday words are not in a pack-only world');
    same($game->dictionary()->size(), $state['dictionary']['size']);

    // Ending it with the pack's game notes what was left unsaid on the pack's list; the
    // everyday game knows no such list, and found nothing (Music Night, round 7).
    $private->withGame($game)->end($world['id'], $org);
    $ended = $game->state($world['id'], null);
    check($ended['said'][0]['unsaid']['count'] > 0, 'Nobody said: the pack list words left');
    same('This room has finished.', $ended['world']['endedMessage']);

    throwsStatus(400, fn () => $private->create($org, ['name' => 'X', 'pack' => $made['id'], 'packMode' => 'half']));
});

test('reports on curated pack categories reach the admin; custom pack reports stay with the organiser', function () {
    $db = testDb();
    $game = makeGameOn($db, testDictionary());
    $world = $game->createWorld('W');
    $player = $game->join($world['id'], 'Ann');
    $promptId = (int) $db->query("SELECT id FROM prompts WHERE world_id = '{$world['id']}'")->fetchColumn();
    $insert = $db->prepare('INSERT INTO reports (world_id, player_id, prompt_id, category, word, reported_at) VALUES (?, ?, ?, ?, ?, 0)');
    $insert->execute([$world['id'], $player['playerId'], $promptId, 'science.element', 'unobtainium']);
    $insert->execute([$world['id'], $player['playerId'], $promptId, 'p1a2b3c4d.c1', 'mars']);
    $admin = new Admin($db, testDictionary(), packs: new Packs($db));
    same(['unobtainium'], array_column($admin->openReports(), 'word'));
    same(true, $admin->openReports()[0]['inDictionary'], "Pack words needn't be everyday words");
    check(str_contains($admin->reportsText(), '(packs/science/element.txt)'));
});

function makeGameOn(PDO $db, Dictionary $dictionary): Game
{
    return new Game($db, $dictionary, fn () => 1_000, promptMin: 1, promptMax: 1000);
}

test('a curated pack can leave out everyday forms its words would bring along', function () {
    $everyday = Dictionary::fromWords(['gas', 'gases', 'gassing', 'gust', 'gusts', 'gusting', 'acid', 'acids']);
    $groups = ['c' => ['label' => 'A chemistry term', 'words' => [['gas'], ['gust'], ['acid']], 'also' => []]];
    $built = Packs::build($groups, $everyday, 'sci', curated: true, leaveOut: ['gassing', 'gusting', 'nothing']);
    same(['acid', 'acids', 'gas', 'gases', 'gust', 'gusts'], sorted($built['words']));
    same(['“nothing” (leave out) isn\'t an everyday form of any of the pack\'s words'], $built['problems']);
});

test('a pack-only competition gets pack-sized prompts, whatever its pace', function () {
    $clock = 1_000 * HOUR_MS;
    [$db, , $comps, $org] = makeCompetitions($clock);
    $made = (new Packs($db))->create($org, 'Space', '', PLANETS, testDictionary());
    $sizes = function (array $input) use ($db, $comps, $org, $clock): array {
        $world = $comps->create($org, $input + ['name' => 'Space quiz', 'startsAt' => $clock + HOUR_MS, 'endsAt' => $clock + 2 * HOUR_MS, 'teams' => ['Owls', 'Foxes']]);
        $row = $db->query("SELECT prompt_min, prompt_max FROM worlds WHERE id = '{$world['id']}'")->fetch(PDO::FETCH_NUM);
        return [(int) $row[0], (int) $row[1]];
    };
    same([5, 40], $sizes(['promptMinutes' => 5, 'pack' => $made['id'], 'packMode' => 'only']), 'Short prompts');
    same([5, 40], $sizes(['promptMinutes' => 60, 'pack' => $made['id'], 'packMode' => 'only']), 'Long prompts');
    same([30, 200], $sizes(['promptMinutes' => 15, 'pack' => $made['id'], 'packMode' => 'mixed']), 'A mixed pack keeps the live-event sizes');
});

test('pack-only worlds: no world multiplier, and words as rare as they are in everyday English', function () {
    $everyday = testDictionary();
    $pack = Packs::build(Packs::parseText("A body\nmoon, moons\ncat\nquasar, quasars\n"), $everyday, 'sci');
    same(['quasar', 'quasars'], $pack['unknown'], 'Words to check the spelling of');
    $only = $everyday->onlyPack($pack);
    same($everyday->rarity('moon'), $only->rarity('moon'), 'An everyday word keeps its everyday rarity');
    same(Dictionary::PACK_WORD_RARITY, $only->rarity('quasar'), "A word that isn't everyday is fairly rare, not the rarest");
    same(false, Game::usesWorldMultiplier(['kind' => 'private', 'pack' => 'sci', 'pack_mode' => 'only']));
    same(true, Game::usesWorldMultiplier(['kind' => 'private', 'pack' => 'sci', 'pack_mode' => 'mixed']));
    same(true, Game::usesWorldMultiplier(['kind' => 'room', 'pack' => null]));
    same(false, Game::usesWorldMultiplier(['kind' => 'public', 'pack' => null]), 'Not the big world');
    same(false, Game::usesWorldMultiplier(['kind' => 'competition', 'pack' => null]), 'Nor competitions');
});
