<?php
// Prints the rarity review table (docs/dictionary-review/RARITY-REVIEW.md): everyday words
// across the whole range, with the rarity multiplier now and under two curves.
//
//   php scripts/rarity-review.php                 the review's words
//   php scripts/rarity-review.php word word ...   any others
declare(strict_types=1);
foreach (['WordFamilies', 'Dictionary'] as $c) require dirname(__DIR__) . "/src/$c.php";
$d = Dictionary::load();
$n = $d->size();
$words = array_slice($argv, 1) ?: explode(' ', 'the house water cat dog kitchen chair table bread apple banana orange milk egg spoon fork knife plate cup bowl kettle toaster fridge oven teapot teaspoon tablespoon saucepan frying colander sieve ladle whisk spatula grater rolling tureen ramekin jug napkin cupboard sink tap mop bucket broom duster pillow blanket curtain carpet sofa wardrobe drawer mirror lamp candle doormat lion tiger giraffe zebra hedgehog badger otter squirrel penguin ostrich flamingo walrus lobster jellyfish beetle ladybird wasp hornet carrot potato onion garlic cabbage parsnip leek turnip courgette aubergine broccoli cauliflower lettuce cucumber radish mango pineapple apricot gooseberry raspberry run jump swim cook bake boil fry roast grill whisk stir chop peel grate knead simmer poach braise happy sad angry tired brave clumsy grumpy cheerful anxious gloomy football tennis cricket rugby golf snooker darts archery fencing curling zeppelin bicycle tractor scooter canoe ferry glider');
$seen = [];
echo "| Word | Place | Now | Curve A (^1.5) | Curve B (^2) | Your mark |\n|---|---|---|---|---|---|\n";
$rows = [];
foreach ($words as $w) {
    if (isset($seen[$w]) || !$d->has($w)) continue;
    $seen[$w] = true;
    $r = $d->rarity($w);
    $rows[] = [$r, sprintf("| %s | %s | ×%.1f | ×%.1f | ×%.1f | |", $w, number_format((int) round($r * $n)), 1 + 2 * $r, 1 + 2 * $r ** 1.5, 1 + 2 * $r ** 2)];
}
usort($rows, fn ($a, $b) => $a[0] <=> $b[0]);
foreach ($rows as [, $line]) echo "$line\n";
fwrite(STDERR, count($rows) . " words\n");
