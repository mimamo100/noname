<?php
// Builds data/dictionary.php, the only data file the game reads, from:
//   data/words.txt                 the word list, most common first
//   data/extra-words.txt           hand-added words the list misses
//   data/blocklist.txt             words that can never be played
//   data/categories.json           meaning categories (built by scripts/build-categories.py)
//   data/category-overrides.txt    hand corrections to the categories
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

// Hand-added words go at the end, so they count as rare.
foreach (readList("$dataDir/extra-words.txt") as $word) {
    $word = strtolower($word);
    if (!preg_match('/^[a-z]{3,}$/', $word)) {
        fwrite(STDERR, "extra-words.txt: \"$word\" must be 3+ lowercase letters\n");
        exit(1);
    }
    if (!in_array($word, $words, true)) $words[] = $word;
}

// Remove blocked words, together with their families.
$blocked = [];
foreach (readList("$dataDir/blocklist.txt") as $word) $blocked[$word] = true;
foreach (WordFamilies::build($words) as $members) {
    if (array_intersect_key(array_flip($members), $blocked)) {
        foreach ($members as $member) $blocked[$member] = true;
    }
}
$words = array_values(array_filter($words, fn ($w) => !isset($blocked[$w])));

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
$families = WordFamilies::build($words);

// Merge the families of linked spellings: "colour" and "color" (with "colours", "colored"...).
$familyOf = [];
foreach ($families as $root => $members) foreach ($members as $m) $familyOf[$m] = (string) $root;
$union = [];
$find = function (string $w) use (&$union, &$find): string {
    if (!isset($union[$w]) || $union[$w] === $w) return $union[$w] = $w;
    return $union[$w] = $find($union[$w]);
};
foreach ($clustersInDictionary as $cluster) {
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

// Meaning categories, with hand corrections applied.
$categories = [];
foreach (json_decode(file_get_contents("$dataDir/categories.json"), true) as $id => $category) {
    $categories[$id] = [
        'label' => $category['label'],
        'words' => array_fill_keys($category['words'], true),
        'also' => array_fill_keys($category['also'] ?? [], true),
    ];
}
foreach (readList("$dataDir/category-overrides.txt") as $line) {
    [$id, $changes] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
    if (!isset($categories[$id])) {
        fwrite(STDERR, "category-overrides.txt: unknown category \"$id\"\n");
        exit(1);
    }
    foreach (preg_split('/\s+/', $changes, -1, PREG_SPLIT_NO_EMPTY) as $change) {
        $word = strtolower(substr($change, 1));
        if ($change[0] === '+') $categories[$id]['words'][$word] = true;
        elseif ($change[0] === '-') unset($categories[$id]['words'][$word], $categories[$id]['also'][$word]);
    }
}
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

$export = "<?php\n// Generated by scripts/build-dictionary.php. Do not edit by hand.\nreturn "
    . var_export([
        'words' => $words, 'families' => $families, 'categories' => $categories, 'blocked' => array_keys($blocked),
        'spelling' => ['usOnly' => $usOnly, 'ukOnly' => $ukOnly, 'partners' => $spellingPartners],
    ], true) . ";\n";
file_put_contents("$dataDir/dictionary.php", $export);
echo 'Wrote data/dictionary.php: ' . count($words) . ' words (' . count($blocked) . ' blocked, '
    . $addedSpellings . ' spellings added), ' . count($families) . ' families, ' . count($categories) . ' categories, '
    . count($spellingPartners) . " words with another spelling\n";

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
