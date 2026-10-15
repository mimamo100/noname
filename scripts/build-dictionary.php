<?php
// Builds data/dictionary.php, the only data file the game reads, from:
//   data/words.txt                 the word list, most common first
//   data/extra-words.txt           hand-added words the list misses
//   data/word-counts.txt,          how often words appear in film subtitles, to place the
//     extra-word-counts.txt          hand-added words (built by scripts/count-extra-words.php)
//   data/family-breaks.txt         words that start their own family
//   data/rarity-overrides.txt      words placed by hand, by the rarity multiplier they should have
//   data/verb-forms.txt           -ing and -ed forms with their verb (scripts/find-word-forms.py)
//   data/plural-forms.txt         plurals the word lists lack, with their singular (the same script)
//   data/irregular-forms.txt       irregular plurals and forms joined to their word (mouse, mice)
//   data/plural-words.txt          words normally used in the plural, judged as written
//   data/blocklist.txt             words that can never be played
//   data/masked.txt                words that can be played but are hidden from other players
//   data/packs/<id>/               curated word packs, each built into data/packs/<id>.php
//   data/categories/<id>.txt       meaning categories, one file each
//   data/spellings.json            UK/US spellings (built by scripts/build-spellings.php)
//   data/spelling-links.txt        hand-added UK/US spelling links
//
//   php scripts/build-dictionary.php          rebuild
//   php scripts/build-dictionary.php --fetch  also re-download data/words.txt first
//
// data/words.txt holds common English words, most frequent first. A word must be
// in ENABLE (public domain) and in the OpenSubtitles frequency list from
// hermitdave/FrequencyWords (CC BY-SA 4.0).

declare(strict_types=1);

require __DIR__ . '/../src/WordFamilies.php';
require __DIR__ . '/../src/Dictionary.php';
require __DIR__ . '/../src/Packs.php';
require __DIR__ . '/../src/Scoring.php';

const ENABLE_URL = 'https://raw.githubusercontent.com/dolph/dictionary/master/enable1.txt';
const FREQ_URL = 'https://raw.githubusercontent.com/hermitdave/FrequencyWords/master/content/2018/en/en_50k.txt';
const MAX_WORDS = 40000;
const MIN_LENGTH = 3;

$dataDir = dirname(__DIR__) . '/data';

if (in_array('--fetch', $argv, true)) {
    $enable = array_flip(array_map('trim', explode("\n", fetch(ENABLE_URL))));
    $words = [];
    foreach (explode("\n", fetch(FREQ_URL)) as $line) {
        $word = strtolower(trim(explode(' ', $line)[0]));
        if (strlen($word) < MIN_LENGTH || !preg_match('/^[a-z]+$/', $word)) continue;
        if (!isset($enable[$word]) || isset($words[$word])) continue;
        $words[$word] = true;
        if (count($words) >= MAX_WORDS) break;
    }
    file_put_contents("$dataDir/words.txt", implode("\n", array_keys($words)) . "\n");
    echo 'Wrote ' . count($words) . " words to data/words.txt\n";
}

$words = array_values(array_filter(array_map('trim', file("$dataDir/words.txt"))));

// Hand-added words are placed by how often they appear in film subtitles (data/extra-word-counts.txt,
// from scripts/count-extra-words.php), so a fairly common one (thicken) isn't scored as one of the
// rarest. One as common as the main list's words (chinese, which subtitles capitalise) joins the
// main list at its place (data/word-counts.txt has the main list's counts); the rest follow it,
// most common first, and words without a count come last, as written.
$counts = [];
if (is_file("$dataDir/extra-word-counts.txt")) {
    foreach (readList("$dataDir/extra-word-counts.txt") as $line) {
        [$word, $count] = explode(' ', $line) + [null, 0];
        $counts[$word] = (int) $count;
    }
}
$listed = array_flip($words);
$extra = [];
foreach (readList("$dataDir/extra-words.txt") as $word) {
    $word = strtolower($word);
    if (!preg_match('/^[a-z]{3,}$/', $word)) {
        fwrite(STDERR, "extra-words.txt: \"$word\" must be 3+ lowercase letters\n");
        exit(1);
    }
    if (!isset($listed[$word])) $extra[$word] ??= count($extra);
}
uksort($extra, fn ($a, $b) => [-($counts[$a] ?? -1), $extra[$a]] <=> [-($counts[$b] ?? -1), $extra[$b]]);
$mainCounts = [];
if (is_file("$dataDir/word-counts.txt")) {
    foreach (readList("$dataDir/word-counts.txt") as $line) {
        [$word, $count] = explode(' ', $line) + [null, 0];
        $mainCounts[$word] = (int) $count;
    }
}
// Merge: before each main word, any hand-added words more common than it. A main word without a
// count keeps its place (it counts as common as the word before it).
$pending = array_keys($extra);
$merged = [];
$previous = PHP_INT_MAX;
foreach ($words as $word) {
    $count = $mainCounts[$word] ?? $previous;
    while ($pending && ($counts[$pending[0]] ?? -1) > $count) $merged[] = array_shift($pending);
    $merged[] = $word;
    $previous = $count;
}
$words = array_merge($merged, $pending);

// Words the family rules would join to an unrelated word: "bunting" (the bird) isn't formed
// from "bunt". Each starts its own family, which its plural still joins ("buntings").
$standalone = readList("$dataDir/family-breaks.txt");

// Remove blocked words, together with the words formed from them ("cocks", "cocked").
// Never the word a blocked one seems to be formed from: "pubes" doesn't block "pub".
$listed = array_flip(readList("$dataDir/blocklist.txt"));
$blocked = $listed;
$parent = WordFamilies::parents(array_merge($words, array_keys($listed)), $standalone);
foreach ($words as $word) {
    for ($w = $word; ; $w = $parent[$w]) {
        if (isset($listed[$w])) {
            $blocked[$word] = true;
            break;
        }
        if ($parent[$w] === $w) break;
    }
}
$words = array_values(array_filter($words, fn ($w) => !isset($blocked[$w])));

// Masked words stay playable but are hidden from other players. Like blocking, a masked
// word masks the words formed from it, except those listed with a ! in front.
$maskLines = readList("$dataDir/masked.txt");
$maskListed = array_flip(array_filter($maskLines, fn ($l) => $l[0] !== '!'));
$unmasked = array_flip(array_map(fn ($l) => substr($l, 1), array_filter($maskLines, fn ($l) => $l[0] === '!')));
$parent = WordFamilies::parents(array_merge($words, array_keys($maskListed)), $standalone);
$masked = [];
foreach ($words as $word) {
    if (isset($unmasked[$word])) continue;
    for ($w = $word; ; $w = $parent[$w]) {
        if (isset($maskListed[$w])) {
            $masked[$word] = true;
            break;
        }
        if ($parent[$w] === $w) break;
    }
}
foreach (array_keys($maskListed) as $word) {
    if (!in_array($word, $words, true)) fwrite(STDERR, "masked.txt: \"$word\" isn't in the dictionary (add it to extra-words.txt to make it playable)\n");
}

// UK/US spellings: add whichever spelling is missing next to the one we have (so it's
// equally rare), then link them so both spellings count as one answer.
$spellings = json_decode(file_get_contents("$dataDir/spellings.json"), true);
$clusters = $spellings['clusters'];
$manualUk = $manualUs = [];
foreach (readList("$dataDir/spelling-links.txt") as $line) {
    $group = preg_split('/\s+/', strtolower($line));
    $clusters[] = $group;
    $manualUk[] = $group[0]; // UK spelling first, then US.
    array_push($manualUs, ...array_slice($group, 1));
}
$rank = array_flip($words);
$after = []; // existing word => spellings to insert after it
$clustersInDictionary = [];
foreach ($clusters as $cluster) {
    $present = array_values(array_filter($cluster, fn ($w) => isset($rank[$w])));
    if (!$present) continue;
    $clustersInDictionary[] = $cluster;
    foreach ($cluster as $word) {
        if (!isset($rank[$word]) && !isset($blocked[$word]) && preg_match('/^[a-z]{3,}$/', $word)) $after[$present[0]][] = $word;
    }
}
$addedSpellings = 0;
$withSpellings = [];
foreach ($words as $word) {
    $withSpellings[] = $word;
    foreach ($after[$word] ?? [] as $extra) {
        if (in_array($extra, $withSpellings, true)) continue;
        $withSpellings[] = $extra;
        $addedSpellings++;
    }
}
$words = $withSpellings;

// A verb's -ing and -ed forms (data/verb-forms.txt, from WordNet): one answer with the verb,
// judged and scored as it. Forms the dictionary lacks are added beside their verb, so they're
// as rare as it, and masked if it is; so are plurals it lacks (data/plural-forms.txt), which
// are judged and scored as their singular.
$verbForms = []; // form => its verb
$breaks = array_flip($standalone);
$known = array_flip($words);
foreach (readList("$dataDir/verb-forms.txt") as $line) {
    [$form, $verb] = preg_split('/\s+/', $line) + [null, null];
    if (!$verb || !isset($known[$verb]) || isset($breaks[$form]) || isset($blocked[$form])) continue;
    $verbForms[$form] = $verb;
}
$pluralForms = []; // plural => its singular, for plurals the word lists lack
foreach (readList("$dataDir/plural-forms.txt") as $line) {
    [$plural, $singular] = preg_split('/\s+/', $line) + [null, null];
    if (!$singular || !isset($known[$singular]) || isset($known[$plural]) || isset($breaks[$plural]) || isset($blocked[$plural])) continue;
    $pluralForms[$plural] = $singular;
}
$newForms = [];
foreach ([...$verbForms, ...$pluralForms] as $form => $root) {
    if (isset($known[$form])) continue;
    $newForms[$root][] = $form;
    if (isset($masked[$root])) $masked[$form] = true;
}
$withForms = [];
foreach ($words as $word) {
    $withForms[] = $word;
    foreach ($newForms[$word] ?? [] as $form) $withForms[] = $form;
}
$addedForms = count($withForms) - count($words);
$words = $withForms;

// Words placed by hand (data/rarity-overrides.txt): familiar words that subtitles under-count
// (courgette, housefly) or the odd one they over-count. Each moves to the place in the list
// that gives it the multiplier written, with its other spellings beside it.
$overrides = [];
foreach (readList("$dataDir/rarity-overrides.txt") as $line) {
    [$word, $multiplier] = preg_split('/\s+/', strtolower($line)) + [null, null];
    if (!in_array($word, $words, true) || !is_numeric($multiplier) || $multiplier < 1 || $multiplier > 3) {
        fwrite(STDERR, "rarity-overrides.txt: \"$line\" needs a word in the dictionary and a multiplier from 1 to 3\n");
        continue;
    }
    $overrides[$word] = (float) $multiplier;
}
$spellingsOf = [];
foreach ($clusters as $cluster) foreach ($cluster as $w) $spellingsOf[$w] = $cluster;
asort($overrides);
foreach ($overrides as $word => $multiplier) {
    $group = array_values(array_filter($spellingsOf[$word] ?? [$word], fn ($w) => in_array($w, $words, true)));
    $words = array_values(array_diff($words, $group));
    $at = (int) round(Scoring::rarityFor($multiplier) * (count($words) + count($group)));
    array_splice($words, min($at, count($words)), 0, $group);
}
// Verb forms start their own families here and are joined to their verb below, so the ending
// rules can't join them to the wrong word (browsed to brow).
$families = WordFamilies::build($words, [...$standalone, ...array_keys($verbForms)]);
foreach ($standalone as $word) {
    if (!in_array($word, $words, true)) fwrite(STDERR, "family-breaks.txt: \"$word\" isn't in the dictionary\n");
}

// Merge the families of linked spellings: "colour" and "color" (with "colours", "colored"...).
$familyOf = [];
foreach ($families as $root => $members) foreach ($members as $m) $familyOf[$m] = (string) $root;
$union = [];
$find = function (string $w) use (&$union, &$find): string {
    if (!isset($union[$w]) || $union[$w] === $w) return $union[$w] = $w;
    return $union[$w] = $find($union[$w]);
};
// Irregular forms join their word's family the same way (data/irregular-forms.txt); a plural
// there is judged and scored as its singular. Lines after "verbs:" are a verb's forms, judged
// and scored as the verb when it's in the dictionary, like its regular forms (verb-forms.txt).
$irregular = []; // form => its singular or base word
$irregularClusters = [];
$verbs = false;
foreach (readList("$dataDir/irregular-forms.txt") as $line) {
    if (strtolower(trim($line)) === 'verbs:') {
        $verbs = true;
        continue;
    }
    $forms = array_values(array_filter(array_map('trim', explode(',', strtolower($line))), 'strlen'));
    $present = array_values(array_filter($forms, fn ($w) => in_array($w, $words, true)));
    // A verb's base form may be too short to be a word here (go, went, gone).
    if (count($present) < 2 || (!$verbs && $present[0] !== $forms[0])) {
        fwrite(STDERR, "irregular-forms.txt: \"$line\" needs " . ($verbs ? 'two of its words' : 'its first word and another') . " in the dictionary\n");
        continue;
    }
    $irregularClusters[] = $present;
    if (!$verbs) foreach (array_slice($present, 1) as $form) $irregular[$form] = $present[0];
    // A verb's irregular forms are judged as it too (wrote, written as write), when it's here.
    elseif ($present[0] === $forms[0]) foreach (array_slice($present, 1) as $form) $verbForms[$form] = $present[0];
}
foreach ([...$verbForms, ...$pluralForms] as $form => $root) $irregularClusters[] = [$root, $form];
foreach ([...$clustersInDictionary, ...$irregularClusters] as $cluster) {
    $roots = [];
    foreach ($cluster as $w) if (in_array($w, $words, true)) $roots[] = $familyOf[$w] ?? $w;
    foreach (array_slice($roots, 1) as $r) $union[$find($r)] = $find($roots[0]);
}
$mergedFamilies = [];
foreach ($words as $w) {
    $root = $familyOf[$w] ?? $w;
    $mergedFamilies[$find($root)][] = $w;
}
$families = array_filter($mergedFamilies, fn ($m) => count($m) > 1);
$spellingPartners = [];
foreach ($clustersInDictionary as $cluster) {
    $inDictionary = array_values(array_filter($cluster, fn ($w) => in_array($w, $words, true)));
    foreach ($inDictionary as $w) {
        $others = array_values(array_diff($inDictionary, [$w]));
        if ($others) $spellingPartners[$w] = $others;
    }
}
$known = array_flip($words);
$usOnly = array_values(array_unique(array_filter(array_merge($spellings['usOnly'], $manualUs), fn ($w) => isset($known[$w]))));
$ukOnly = array_values(array_unique(array_filter(array_merge($spellings['ukOnly'], $manualUk), fn ($w) => isset($known[$w]))));

// Meaning categories, one hand-kept file each (see data/categories/README.md). A phrase
// ("tea towel") is played joined ("teatowel") and shown as written.
$categories = [];
$display = []; // Joined word => written form, for words written differently.
$displayFrom = [];
foreach (glob("$dataDir/categories/*.txt") as $path) {
    $id = basename($path, '.txt');
    try {
        $category = Dictionary::readCategory(readList($path));
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, "categories/$id.txt: {$e->getMessage()}\n");
        exit(1);
    }
    if ($category['label'] === null) {
        fwrite(STDERR, "categories/$id.txt: no \"label:\" line\n");
        exit(1);
    }
    foreach ($category['display'] as $joined => $written) {
        if (isset($display[$joined]) && $display[$joined] !== $written) {
            fwrite(STDERR, "categories/$id.txt: \"$written\" is written \"{$display[$joined]}\" in {$displayFrom[$joined]}.txt; the first is used\n");
            continue;
        }
        $display[$joined] = $written;
        $displayFrom[$joined] ??= $id;
    }
    unset($category['display']);
    $categories[$id] = $category;
}

// Checks that don't stop the build: words the files list but the game will ignore (usually a
// typo, or a word missing from extra-words.txt), and listings that add nothing because a
// category it includes already gives the word the same standing or better. (A full answer in
// "food" that is only an "also" in "dessert", like pancake, is deliberate, so isn't flagged.)
$warnings = [];
$own = array_map(fn ($c) => $c['words'] + $c['also'], $categories);
foreach ($own as $id => $listed) {
    foreach (array_keys($listed) as $word) {
        if (isset($blocked[$word])) $warnings[] = "categories/$id.txt: \"$word\" is blocked, so it's ignored";
        elseif (!isset($known[$word])) $warnings[] = "categories/$id.txt: \"$word\" isn't in the dictionary (a typo, or add it to extra-words.txt)";
    }
}
$below = function (string $id, array $seen = []) use (&$below, $categories): array {
    $out = [];
    foreach ($categories[$id]['includes'] as $sub) {
        if (!isset($categories[$sub]) || in_array($sub, $seen, true)) continue; // Reported below.
        $out[] = $sub;
        array_push($out, ...$below($sub, [...$seen, $id]));
    }
    return $out;
};
foreach (array_keys($categories) as $id) {
    foreach (array_unique($below($id)) as $sub) {
        foreach (array_keys(array_intersect_key($own[$id], $own[$sub])) as $word) {
            $subIsMain = isset($categories[$sub]['words'][$word]);
            if (!$subIsMain && isset($categories[$id]['words'][$word])) continue; // Main here, only "also" there: deliberate.
            $warnings[] = "categories/$id.txt: \"$word\" is already in $sub.txt" . ($subIsMain ? '' : ' (as also)')
                . ", which $id includes, so this listing adds nothing";
        }
    }
}

// A category takes in the categories it includes ("animal" includes "bird"), so each
// word is listed once. Included categories can include others in turn.
$merged = [];
$merge = function (string $id, array $path = []) use (&$merge, &$merged, &$categories): void {
    if (isset($merged[$id])) return;
    if (in_array($id, $path, true)) {
        fwrite(STDERR, 'categories: includes go round in a circle: ' . implode(' -> ', [...$path, $id]) . "\n");
        exit(1);
    }
    foreach ($categories[$id]['includes'] as $sub) {
        if (!isset($categories[$sub])) {
            fwrite(STDERR, "categories/$id.txt: includes unknown category \"$sub\"\n");
            exit(1);
        }
        $merge($sub, [...$path, $id]);
        $categories[$id]['words'] += $categories[$sub]['words'];
        $categories[$id]['also'] += $categories[$sub]['also'];
    }
    $merged[$id] = true;
};
foreach (array_keys($categories) as $id) $merge($id);
foreach ($categories as $id => $category) unset($categories[$id]['includes']);
// Other spellings belong wherever their partner does: "jewellery" is worn like "jewelry".
foreach ($categories as $id => $category) {
    foreach (['words', 'also'] as $list) {
        foreach (array_keys($category[$list]) as $word) {
            foreach ($spellingPartners[$word] ?? [] as $partner) $categories[$id][$list][$partner] = true;
        }
    }
}
foreach ($categories as $id => $category) {
    // Keep dictionary order (most common first), and only allowed words.
    $categories[$id]['words'] = array_values(array_filter($words, fn ($w) => isset($category['words'][$w])));
    $categories[$id]['also'] = array_values(array_filter($words, fn ($w) => isset($category['also'][$w]) && !isset($category['words'][$w])));
}

// How phrases are written, for the words that made it into the dictionary.
$display = array_intersect_key($display, $known);
ksort($display);

$export = "<?php\n// Generated by scripts/build-dictionary.php. Do not edit by hand.\nreturn "
    . var_export([
        'words' => $words, 'families' => $families, 'categories' => $categories, 'blocked' => array_keys($blocked),
        'masked' => array_keys($masked),
        // Words normally used in the plural (data/plural-words.txt): judged and scored as written.
        'pluralWords' => array_values(array_filter(readList("$dataDir/plural-words.txt"), fn ($w) => isset($known[$w]))),
        'spelling' => ['usOnly' => $usOnly, 'ukOnly' => $ukOnly, 'partners' => $spellingPartners],
        'display' => $display,
        'irregular' => $irregular,
        'verbForms' => $verbForms,
        // Words the build added from verb-forms.txt and plural-forms.txt, so that
        // scripts/find-word-forms.py can tell them from the word lists' own.
        'addedForms' => array_values(array_merge(...array_values($newForms ?: [[]]))),
    ], true) . ";\n";
file_put_contents("$dataDir/dictionary.php", $export);
if ($warnings) {
    sort($warnings);
    fwrite(STDERR, implode("\n", $warnings) . "\n" . count($warnings) . (count($warnings) === 1 ? ' warning' : ' warnings') . " (the dictionary was still built)\n\n");
}
echo 'Wrote data/dictionary.php: ' . count($words) . ' words (' . count($blocked) . ' blocked, ' . count($masked) . ' masked, '
    . $addedSpellings . ' spellings added, ' . $addedForms . ' forms added), ' . count($families) . ' families, ' . count($categories) . ' categories, '
    . count($spellingPartners) . " words with another spelling\n";

// Curated word packs: data/packs/<id>/ (pack.txt and one file per category) into data/packs/<id>.php.
// Each line is one answer, its forms separated by commas ("black hole, black holes").
$everyday = Dictionary::load("$dataDir/dictionary.php");
foreach (glob("$dataDir/packs/*/pack.txt") ?: [] as $packFile) {
    $packDir = dirname($packFile);
    $packId = basename($packDir);
    $meta = [];
    foreach (readList($packFile) as $line) {
        if (preg_match('/^(name|description|leave out):\s*(.*)$/', $line, $m)) $meta[$m[1]] = $m[2];
    }
    $groups = [];
    foreach (glob("$packDir/*.txt") as $path) {
        if (basename($path) === 'pack.txt') continue;
        $id = basename($path, '.txt');
        $group = Packs::readCuratedFile(readList($path));
        if ($group['label'] === null) {
            fwrite(STDERR, "packs/$packId/$id.txt: no \"label:\" line\n");
            exit(1);
        }
        $groups[$id] = $group;
    }
    // Curated, so blocked words it lists (the law pack's crimes) are kept, and playable only in its worlds.
    // "leave out:" names everyday forms the pack's words would otherwise bring along ("gassing").
    $leaveOut = Packs::forms($meta['leave out'] ?? '');
    $built = Packs::build($groups, $everyday, $packId, curated: true, leaveOut: $leaveOut);
    foreach ($built['problems'] as $problem) fwrite(STDERR, "packs/$packId: $problem\n");
    unset($built['problems']);
    ksort($built['display']);
    $pack = ['name' => $meta['name'] ?? $packId, 'description' => $meta['description'] ?? ''] + $built;
    file_put_contents("$dataDir/packs/$packId.php", "<?php\n// Generated by scripts/build-dictionary.php from data/packs/$packId/. Do not edit by hand.\nreturn "
        . var_export($pack, true) . ";\n");
    $answers = array_sum(array_column($built['categories'], 'answers'));
    echo "Wrote data/packs/$packId.php: {$pack['name']}, " . count($built['categories']) . " categories, $answers answers, " . count($built['words']) . " words"
        . ($built['blocked'] ? ' (' . count($built['blocked']) . ' blocked words, playable only in this pack: ' . implode(', ', $built['blocked']) . ')' : '') . "\n";
}

/** Non-empty lines of a text file, without # comments. */
function readList(string $path): array
{
    $lines = array_map(fn ($l) => trim(preg_replace('/#.*/', '', $l)), file($path));
    return array_values(array_filter($lines, 'strlen'));
}

function fetch(string $url): string
{
    $text = file_get_contents($url);
    if ($text === false) {
        fwrite(STDERR, "Could not download $url\n");
        exit(1);
    }
    return $text;
}
