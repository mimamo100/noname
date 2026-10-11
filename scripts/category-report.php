<?php
// Shows what the meaning categories contain, for reviewing them. Reads data/dictionary.php,
// so rebuild first (php scripts/build-dictionary.php) after changing the word files.
//
//   php scripts/category-report.php                      every category, with sizes
//   php scripts/category-report.php fish                 one category's words
//   php scripts/category-report.php fish cod hake trout  how each word stands for that category

declare(strict_types=1);

require __DIR__ . '/../src/WordFamilies.php';
require __DIR__ . '/../src/Dictionary.php';
require __DIR__ . '/../src/Prompts.php';

$data = require __DIR__ . '/../data/dictionary.php';
$dictionary = Dictionary::load();
[, $id] = $argv + [null, null];
$words = array_slice($argv, 2);

if ($id === null) {
    foreach ($data['categories'] as $catId => $c) {
        printf("%-12s %-34s %4d main  %4d also\n", $catId, $c['label'], count($c['words']), count($c['also'] ?? []));
    }
    exit;
}
$category = $data['categories'][$id] ?? exit("No category '$id'. Run with no arguments to list them.\n");

if (!$words) {
    echo "$id: {$category['label']}\n\n";
    echo 'Main (' . count($category['words']) . "): clear answers, counted as \"left unsaid\"\n";
    echo wordwrap(implode(', ', $category['words']), 100) . "\n\n";
    echo 'Also (' . count($category['also'] ?? []) . "): accepted because another meaning fits, not counted\n";
    echo wordwrap(implode(', ', $category['also'] ?? []), 100) . "\n";
    exit;
}

$main = array_flip($category['words']);
$also = array_flip($category['also'] ?? []);
foreach ($words as $word) {
    $word = strtolower($word);
    $status = match (true) {
        !$dictionary->has($word) => $dictionary->isBlocked($word) ? 'blocked (offensive list)' : 'NOT IN DICTIONARY (add to data/extra-words.txt)',
        isset($main[$word]) => 'main',
        isset($also[$word]) => 'also',
        $dictionary->inCategory($id, $word) => 'accepted as the plural of ' . $dictionary->singular($word),
        default => 'in the dictionary, not in this category',
    };
    printf("%-16s %s\n", $word, $status);
}
