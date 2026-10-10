<?php
// Builds data/spellings.json, the UK/US spelling data, from VarCon (part of SCOWL,
// http://wordlist.aspell.net/, copyright Kevin Atkinson and others; see README.md).
//
//   php scripts/build-spellings.php [path/to/varcon.txt]
//
// Without a path it downloads varcon.txt. Then rebuild: php scripts/build-dictionary.php
//
// The output has:
//   clusters  spellings of the same word ("color", "colour"); each cluster has at least
//             one word in our word list. They become one answer in the game. Spellings that
//             are also everyday words on both sides ("check" for "cheque", "tire" for "tyre")
//             aren't linked, so saying "tired" doesn't use up "tyre".
//   us, uk    words acceptable in US or UK spelling (preferred, or a common variant).
//             A word in neither list isn't regional ("cat"), so it's fine everywhere.

declare(strict_types=1);

const VARCON_URL = 'https://raw.githubusercontent.com/en-wl/wordlist/master/varcon/varcon.txt';

$dataDir = dirname(__DIR__) . '/data';
$source = $argv[1] ?? VARCON_URL;
$text = file_get_contents($source);
if ($text === false) {
    fwrite(STDERR, "Could not read $source\n");
    exit(1);
}

$known = [];
foreach (['words.txt', 'extra-words.txt'] as $file) {
    foreach (file("$dataDir/$file") as $line) {
        $word = trim(preg_replace('/#.*/', '', $line));
        if ($word !== '') $known[$word] = true;
    }
}

// Which regions accept each spelling. Variant marks: none or "." = preferred, "v" = common
// variant (accepted), "V" = seldom used, "-" = possible, "x" = improper (not accepted).
$us = [];
$uk = [];
$shared = [];
$clusters = [];
foreach (explode("\n", $text) as $line) {
    $line = trim(explode('|', $line)[0]); // Drop "| comment".
    if ($line === '' || $line[0] === '#') continue;
    $cluster = [];
    foreach (explode(' / ', $line) as $entry) {
        if (!str_contains($entry, ': ')) continue;
        [$tags, $word] = explode(': ', $entry, 2);
        $word = trim($word);
        if (!preg_match('/^[a-z]+$/', $word)) continue; // Skip possessives, capitals, hyphens.
        $regions = [];
        foreach (preg_split('/\s+/', trim($tags)) as $tag) {
            if (!preg_match('/^([ABZCD_])([.vV\-x]?)\d*$/', $tag, $m)) continue;
            [, $region, $mark] = $m;
            if (!in_array($mark, ['', '.', 'v'], true)) continue;
            if ($region === 'A') $regions['us'] = $us[$word] = true;
            if ($region === 'B' || $region === 'Z') $regions['uk'] = $uk[$word] = true;
        }
        if ($regions) $cluster[$word] = $regions;
    }
    if (count($cluster) > 1) {
        $clusters[] = array_keys($cluster);
    } elseif (count($cluster) === 1 && count(reset($cluster)) === 2) {
        // A line of its own, used on both sides: the word has a meaning that isn't regional
        // ("check" the verb, "tire" as in tired), so it mustn't be linked to other spellings.
        $shared[array_key_first($cluster)] = true;
    }
}

// Keep clusters touching our words, and merge clusters that share a word.
$parent = [];
$find = function (string $w) use (&$parent, &$find): string {
    if (!isset($parent[$w]) || $parent[$w] === $w) return $parent[$w] = $w;
    return $parent[$w] = $find($parent[$w]);
};
foreach ($clusters as $cluster) {
    if (!array_intersect_key(array_flip($cluster), $known)) continue;
    if (array_intersect_key(array_flip($cluster), $shared)) continue; // "check"/"cheque", "tire"/"tyre".
    $root = $find($cluster[0]);
    foreach ($cluster as $word) $parent[$find($word)] = $root;
}
$merged = [];
foreach (array_keys($parent) as $word) $merged[$find($word)][] = $word;
$merged = array_values(array_map(function ($c) { sort($c); return $c; }, $merged));
sort($merged);

// Regional words only matter if they're in a cluster: a word with no other spelling is fine everywhere.
$inClusters = array_fill_keys(array_merge(...$merged), true);
$usOnly = $ukOnly = [];
foreach (array_keys($inClusters) as $word) {
    $isUs = isset($us[$word]);
    $isUk = isset($uk[$word]);
    if ($isUs && !$isUk) $usOnly[] = $word;
    if ($isUk && !$isUs) $ukOnly[] = $word;
}
sort($usOnly);
sort($ukOnly);

file_put_contents("$dataDir/spellings.json", json_encode(
    ['clusters' => $merged, 'usOnly' => $usOnly, 'ukOnly' => $ukOnly],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
) . "\n");
echo 'Wrote data/spellings.json: ' . count($merged) . ' spelling clusters, '
    . count($usOnly) . ' US-only and ' . count($ukOnly) . " UK-only spellings\n";
