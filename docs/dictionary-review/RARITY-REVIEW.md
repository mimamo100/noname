# Rarity review (to do with the owner)

**Status: waiting for the owner's marks.** Started 14 October 2026, after the owner found
*teaspoon* scoring ×1.8 for rarity "a bit odd".

## How rarity works now

A word's points are multiplied by its rarity, from ×1 for the commonest words to ×3 for the
rarest: `1 + 2 × (place ÷ 52,019)`, where the place is the word's position in
`data/words.txt`, which is ordered by how often words are said in film and TV subtitles
(hand-added words are placed by the same counts). Two things make familiar words score high:

1. **The scale is a straight line.** Most of the 52,019 words are words people know but seldom
   say aloud, so a familiar word can sit in the middle: *teaspoon* is 22,012th, so ×1.8.
2. **Subtitle counts under-rank some everyday words**, household nouns and British words
   especially (*saucepan* ×2.2, *colander* ×2.4, *courgette* ×2.7).

A curve fixes the first; only moving words by hand fixes the second.

## The curves

- **Now:** `1 + 2 × p`, where p is the place ÷ 52,019.
- **Curve A:** `1 + 2 × p^1.5`, a gentle bend.
- **Curve B:** `1 + 2 × p²`, a stronger one: familiar words stay near ×1, and the top of the
  scale is kept for really unusual words.

Both keep the order of words; they only change how quickly the multiplier rises. (In pack-only
worlds the same applies, as pack words take their everyday rarity; a pack word that isn't an
everyday word counts as 0.6 of the way, ×2.2 now.)

## What to do

For each word, mark the column that feels right, or write **too high** / **too low** / **ok**
against the current value. Then:

1. Choose a curve (or another: `p^1.25`, `p^1.75`), from the column that looks right most often.
2. List words that are still wrong under that curve: they're candidates for moving by hand
   (as `data/extra-word-counts.txt` places hand-added words), which needs a small new override
   file in `data/` and a rebuild.
3. The change itself is one line in `src/Scoring.php` (`scoreWord`, the rarity multiplier),
   plus the How to play text and `DESIGN.md`'s scoring table; record the decision in
   `docs/DECISIONS.md`.

To see other words: `php scripts/rarity-review.php word word ...`.

## The words, commonest first

| Word | Place | Now | Curve A (^1.5) | Curve B (^2) | Your mark |
|---|---|---|---|---|---|
| the | 1 | ×1.0 | ×1.0 | ×1.0 | |
| house | 172 | ×1.0 | ×1.0 | ×1.0 | |
| happy | 251 | ×1.0 | ×1.0 | ×1.0 | |
| run | 276 | ×1.0 | ×1.0 | ×1.0 | |
| water | 326 | ×1.0 | ×1.0 | ×1.0 | |
| dog | 482 | ×1.0 | ×1.0 | ×1.0 | |
| tired | 674 | ×1.0 | ×1.0 | ×1.0 | |
| table | 740 | ×1.0 | ×1.0 | ×1.0 | |
| angry | 852 | ×1.0 | ×1.0 | ×1.0 | |
| sad | 873 | ×1.0 | ×1.0 | ×1.0 | |
| cat | 954 | ×1.0 | ×1.0 | ×1.0 | |
| jump | 1,024 | ×1.0 | ×1.0 | ×1.0 | |
| kitchen | 1,034 | ×1.0 | ×1.0 | ×1.0 | |
| cook | 1,281 | ×1.0 | ×1.0 | ×1.0 | |
| knife | 1,289 | ×1.0 | ×1.0 | ×1.0 | |
| cup | 1,327 | ×1.1 | ×1.0 | ×1.0 | |
| milk | 1,360 | ×1.1 | ×1.0 | ×1.0 | |
| chair | 1,470 | ×1.1 | ×1.0 | ×1.0 | |
| brave | 1,619 | ×1.1 | ×1.0 | ×1.0 | |
| football | 1,693 | ×1.1 | ×1.0 | ×1.0 | |
| bread | 1,726 | ×1.1 | ×1.0 | ×1.0 | |
| plate | 1,932 | ×1.1 | ×1.0 | ×1.0 | |
| mirror | 2,025 | ×1.1 | ×1.0 | ×1.0 | |
| swim | 2,039 | ×1.1 | ×1.0 | ×1.0 | |
| egg | 2,132 | ×1.1 | ×1.0 | ×1.0 | |
| apple | 2,400 | ×1.1 | ×1.0 | ×1.0 | |
| tiger | 2,458 | ×1.1 | ×1.0 | ×1.0 | |
| bowl | 2,590 | ×1.1 | ×1.0 | ×1.0 | |
| rolling | 2,594 | ×1.1 | ×1.0 | ×1.0 | |
| lion | 2,626 | ×1.1 | ×1.0 | ×1.0 | |
| orange | 2,682 | ×1.1 | ×1.0 | ×1.0 | |
| golf | 2,735 | ×1.1 | ×1.0 | ×1.0 | |
| sink | 3,085 | ×1.1 | ×1.0 | ×1.0 | |
| chop | 3,254 | ×1.1 | ×1.0 | ×1.0 | |
| tap | 3,425 | ×1.1 | ×1.0 | ×1.0 | |
| anxious | 3,578 | ×1.1 | ×1.0 | ×1.0 | |
| fridge | 3,623 | ×1.1 | ×1.0 | ×1.0 | |
| bucket | 3,642 | ×1.1 | ×1.0 | ×1.0 | |
| blanket | 3,820 | ×1.1 | ×1.0 | ×1.0 | |
| tennis | 3,832 | ×1.1 | ×1.0 | ×1.0 | |
| potato | 3,919 | ×1.2 | ×1.0 | ×1.0 | |
| drawer | 4,022 | ×1.2 | ×1.0 | ×1.0 | |
| pillow | 4,026 | ×1.2 | ×1.0 | ×1.0 | |
| lamp | 4,148 | ×1.2 | ×1.0 | ×1.0 | |
| carpet | 4,163 | ×1.2 | ×1.0 | ×1.0 | |
| banana | 4,217 | ×1.2 | ×1.0 | ×1.0 | |
| oven | 4,291 | ×1.2 | ×1.0 | ×1.0 | |
| fry | 4,454 | ×1.2 | ×1.1 | ×1.0 | |
| candle | 4,546 | ×1.2 | ×1.1 | ×1.0 | |
| curtain | 4,699 | ×1.2 | ×1.1 | ×1.0 | |
| roast | 4,753 | ×1.2 | ×1.1 | ×1.0 | |
| bicycle | 4,971 | ×1.2 | ×1.1 | ×1.0 | |
| spoon | 5,157 | ×1.2 | ×1.1 | ×1.0 | |
| cricket | 5,301 | ×1.2 | ×1.1 | ×1.0 | |
| sofa | 5,393 | ×1.2 | ×1.1 | ×1.0 | |
| fork | 5,526 | ×1.2 | ×1.1 | ×1.0 | |
| lobster | 5,668 | ×1.2 | ×1.1 | ×1.0 | |
| wardrobe | 5,778 | ×1.2 | ×1.1 | ×1.0 | |
| stir | 5,849 | ×1.2 | ×1.1 | ×1.0 | |
| bake | 5,923 | ×1.2 | ×1.1 | ×1.0 | |
| grill | 6,126 | ×1.2 | ×1.1 | ×1.0 | |
| boil | 6,240 | ×1.2 | ×1.1 | ×1.0 | |
| clumsy | 6,243 | ×1.2 | ×1.1 | ×1.0 | |
| garlic | 6,249 | ×1.2 | ×1.1 | ×1.0 | |
| peel | 6,286 | ×1.2 | ×1.1 | ×1.0 | |
| ferry | 6,354 | ×1.2 | ×1.1 | ×1.0 | |
| cheerful | 6,397 | ×1.2 | ×1.1 | ×1.0 | |
| squirrel | 6,570 | ×1.3 | ×1.1 | ×1.0 | |
| cupboard | 6,636 | ×1.3 | ×1.1 | ×1.0 | |
| onion | 6,681 | ×1.3 | ×1.1 | ×1.0 | |
| penguin | 7,079 | ×1.3 | ×1.1 | ×1.0 | |
| scooter | 7,126 | ×1.3 | ×1.1 | ×1.0 | |
| kettle | 7,154 | ×1.3 | ×1.1 | ×1.0 | |
| tractor | 7,260 | ×1.3 | ×1.1 | ×1.0 | |
| broom | 7,271 | ×1.3 | ×1.1 | ×1.0 | |
| carrot | 7,323 | ×1.3 | ×1.1 | ×1.0 | |
| cabbage | 7,458 | ×1.3 | ×1.1 | ×1.0 | |
| mop | 7,921 | ×1.3 | ×1.1 | ×1.0 | |
| beetle | 7,963 | ×1.3 | ×1.1 | ×1.0 | |
| pineapple | 8,760 | ×1.3 | ×1.1 | ×1.1 | |
| grumpy | 8,811 | ×1.3 | ×1.1 | ×1.1 | |
| gloomy | 8,862 | ×1.3 | ×1.1 | ×1.1 | |
| napkin | 9,045 | ×1.3 | ×1.1 | ×1.1 | |
| lettuce | 9,223 | ×1.4 | ×1.1 | ×1.1 | |
| raspberry | 9,481 | ×1.4 | ×1.2 | ×1.1 | |
| badger | 9,921 | ×1.4 | ×1.2 | ×1.1 | |
| darts | 9,933 | ×1.4 | ×1.2 | ×1.1 | |
| mango | 10,014 | ×1.4 | ×1.2 | ×1.1 | |
| toaster | 10,025 | ×1.4 | ×1.2 | ×1.1 | |
| rugby | 10,377 | ×1.4 | ×1.2 | ×1.1 | |
| zebra | 10,380 | ×1.4 | ×1.2 | ×1.1 | |
| jug | 10,401 | ×1.4 | ×1.2 | ×1.1 | |
| frying | 10,453 | ×1.4 | ×1.2 | ×1.1 | |
| canoe | 10,591 | ×1.4 | ×1.2 | ×1.1 | |
| cucumber | 10,641 | ×1.4 | ×1.2 | ×1.1 | |
| hedgehog | 10,854 | ×1.4 | ×1.2 | ×1.1 | |
| giraffe | 11,091 | ×1.4 | ×1.2 | ×1.1 | |
| broccoli | 11,384 | ×1.4 | ×1.2 | ×1.1 | |
| jellyfish | 11,909 | ×1.5 | ×1.2 | ×1.1 | |
| fencing | 12,184 | ×1.5 | ×1.2 | ×1.1 | |
| wasp | 12,646 | ×1.5 | ×1.2 | ×1.1 | |
| ostrich | 13,441 | ×1.5 | ×1.3 | ×1.1 | |
| hornet | 14,928 | ×1.6 | ×1.3 | ×1.2 | |
| otter | 15,112 | ×1.6 | ×1.3 | ×1.2 | |
| walrus | 15,603 | ×1.6 | ×1.3 | ×1.2 | |
| simmer | 15,610 | ×1.6 | ×1.3 | ×1.2 | |
| teapot | 16,128 | ×1.6 | ×1.3 | ×1.2 | |
| grate | 16,559 | ×1.6 | ×1.4 | ×1.2 | |
| archery | 16,706 | ×1.6 | ×1.4 | ×1.2 | |
| curling | 16,813 | ×1.6 | ×1.4 | ×1.2 | |
| cauliflower | 16,868 | ×1.6 | ×1.4 | ×1.2 | |
| flamingo | 17,040 | ×1.7 | ×1.4 | ×1.2 | |
| zeppelin | 17,091 | ×1.7 | ×1.4 | ×1.2 | |
| radish | 17,196 | ×1.7 | ×1.4 | ×1.2 | |
| turnip | 17,588 | ×1.7 | ×1.4 | ×1.2 | |
| snooker | 18,519 | ×1.7 | ×1.4 | ×1.3 | |
| duster | 18,618 | ×1.7 | ×1.4 | ×1.3 | |
| whisk | 18,874 | ×1.7 | ×1.4 | ×1.3 | |
| apricot | 19,021 | ×1.7 | ×1.4 | ×1.3 | |
| doormat | 19,639 | ×1.8 | ×1.5 | ×1.3 | |
| glider | 19,652 | ×1.8 | ×1.5 | ×1.3 | |
| sieve | 20,806 | ×1.8 | ×1.5 | ×1.3 | |
| spatula | 20,963 | ×1.8 | ×1.5 | ×1.3 | |
| poach | 21,728 | ×1.8 | ×1.5 | ×1.3 | |
| teaspoon | 22,012 | ×1.8 | ×1.6 | ×1.4 | |
| ladle | 22,352 | ×1.9 | ×1.6 | ×1.4 | |
| leek | 24,103 | ×1.9 | ×1.6 | ×1.4 | |
| ladybird | 24,898 | ×2.0 | ×1.7 | ×1.5 | |
| gooseberry | 27,369 | ×2.1 | ×1.8 | ×1.6 | |
| grater | 29,417 | ×2.1 | ×1.9 | ×1.6 | |
| knead | 30,283 | ×2.2 | ×1.9 | ×1.7 | |
| saucepan | 31,520 | ×2.2 | ×1.9 | ×1.7 | |
| parsnip | 31,813 | ×2.2 | ×2.0 | ×1.7 | |
| tablespoon | 33,138 | ×2.3 | ×2.0 | ×1.8 | |
| aubergine | 34,090 | ×2.3 | ×2.1 | ×1.9 | |
| colander | 35,584 | ×2.4 | ×2.1 | ×1.9 | |
| braise | 37,476 | ×2.4 | ×2.2 | ×2.0 | |
| tureen | 38,241 | ×2.5 | ×2.3 | ×2.1 | |
| courgette | 43,005 | ×2.7 | ×2.5 | ×2.4 | |
| ramekin | 46,021 | ×2.8 | ×2.7 | ×2.6 | |
