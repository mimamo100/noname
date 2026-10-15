<?php
declare(strict_types=1);

/**
 * Prompts: a meaning category ("A bird"), letter rules ("Starts with P, 8+ letters, no E"),
 * or both ("An animal starting with B").
 *
 * A prompt is stored as a spec array: ['cat' => ?string, 'rules' => [[type, ...args], ...]].
 * Rule types:
 *   starts/ends/contains/lacks <letters>   bookends <letter>   double <letter>
 *   count <letter> <n>   minLen <n>   maxLen <n>   len <n>
 *   alpha (letters in alphabetical order)   oneVowel   onlyVowel <vowel>
 *
 * Plurals count as their singular for length rules, so adding an S can't stretch
 * "brother" into an 8-letter answer. Other rules check the word as typed.
 */
final class Prompts
{
    private const VOWELS = 'aeiou';
    private const NUMBER_WORDS = [2 => 'Two', 3 => 'Three'];

    // --- Matching ---

    /** @return callable(string): bool */
    public static function compile(array $spec, Dictionary $dictionary): callable
    {
        $length = fn (string $w) => strlen($dictionary->singular($w));
        $tests = array_map(fn (array $rule) => self::ruleTest($rule, $length), $spec['rules'] ?? []);
        $cat = $spec['cat'] ?? null;
        return function (string $word) use ($tests, $cat, $dictionary): bool {
            if ($cat !== null && !$dictionary->inCategory($cat, $word)) return false;
            $judged = $cat !== null ? $word : $dictionary->verbRoot($word); // As judged() does.
            foreach ($tests as $test) if (!$test($judged)) return false;
            return true;
        };
    }

    private const LENGTH_RULES = ['minLen', 'maxLen', 'len'];

    /**
     * "About this prompt": what a player might not expect. A meaning prompt has its list's note
     * (a "note:" line in its category file); a letter prompt says how forms of a word are judged,
     * as players reach for -ing words and plurals first. A rule that only looks at a word's
     * start needs no note: a verb's forms start as it does.
     */
    public static function note(array $spec, Dictionary $dictionary): ?string
    {
        $types = array_map(fn ($rule) => $rule[0], $spec['rules'] ?? []);
        $length = (bool) array_intersect($types, self::LENGTH_RULES);
        $letters = (bool) array_diff($types, [...self::LENGTH_RULES, 'starts']);
        if (isset($spec['cat'])) {
            $note = $dictionary->categoryNote($spec['cat']);
            if (!$length) return $note;
            return trim(($note ?? '') . ' Plurals count as their singular for length: “brothers” is 7 letters.');
        }
        return match (true) {
            $length && $letters => "A verb's other forms count as the verb here, and plurals as their singular: “running” and “ran” are “run”, and “brothers” is 7 letters.",
            $length => "Plurals count as their singular here, and a verb's other forms as the verb: “brothers” is 7 letters, and “running” is 3.",
            $letters => "A verb's other forms count as the verb here: “running” and “ran” are “run”.",
            default => null,
        };
    }

    /**
     * The word a prompt's letter rules look at: on a letter prompt, a verb's -ing or -ed form is
     * judged as the verb ("running" is run: 3 letters, and it doesn't end in G). A meaning prompt
     * judges the word as written, as its list names it ("swimming" for A sport).
     */
    public static function judged(array $spec, Dictionary $dictionary, string $word): string
    {
        return isset($spec['cat']) ? $word : $dictionary->verbRoot($word);
    }

    public static function matches(array $spec, Dictionary $dictionary, string $word): bool
    {
        return self::compile($spec, $dictionary)($word);
    }

    private static function ruleTest(array $rule, callable $length): callable
    {
        [$type, $a, $b] = $rule + [null, null, null];
        return match ($type) {
            'starts' => fn ($w) => str_starts_with($w, $a),
            'ends' => fn ($w) => str_ends_with($w, $a),
            'contains' => fn ($w) => str_contains($w, $a),
            'lacks' => fn ($w) => !str_contains($w, $a),
            'bookends' => fn ($w) => strlen($w) > 2 && $w[0] === $a && $w[-1] === $a,
            'double' => fn ($w) => str_contains($w, $a . $a),
            'count' => fn ($w) => substr_count($w, $a) === $b,
            'minLen' => fn ($w) => $length($w) >= $a,
            'maxLen' => fn ($w) => $length($w) <= $a,
            'len' => fn ($w) => $length($w) === $a,
            'alpha' => function ($w) { $l = str_split($w); $s = $l; sort($s); return $l === $s; },
            'oneVowel' => fn ($w) => strlen($w) - strlen(str_replace(str_split(self::VOWELS), '', $w)) === 1,
            'onlyVowel' => fn ($w) => str_contains($w, $a) && strpbrk($w, str_replace($a, '', self::VOWELS)) === false,
        };
    }

    /**
     * Why a word breaks the prompt's letter rules, in a few words for the player ("it has an E",
     * "it's 4 letters, not 5+"), or null if it keeps them all. Only the first broken rule is named.
     */
    public static function whyNot(array $spec, Dictionary $dictionary, string $word): ?string
    {
        $typed = $word;
        $word = self::judged($spec, $dictionary, $word);
        $singular = $dictionary->singular($word);
        $length = fn (string $w) => strlen($dictionary->singular($w));
        // Length rules judge a plural as its singular, and letter prompts a verb's form as the verb:
        // say so, or "flasks" being 5 letters, or "running" not ending in G, is a mystery.
        $asSingular = $singular !== $typed ? " (“{$typed}” counts as “{$singular}”)" : '';
        $asVerb = $word !== $typed ? " (“{$typed}” counts as “{$word}”)" : '';
        foreach ($spec['rules'] ?? [] as $rule) {
            if (self::ruleTest($rule, $length)($word)) continue;
            [$type, $a, $b] = $rule + [null, null, null];
            $upper = is_string($a) ? strtoupper($a) : $a;
            $n = $length($word);
            $why = match ($type) {
                'starts' => "it doesn't start with $upper",
                'ends' => "it doesn't end in $upper",
                'contains' => "it has no $upper",
                'lacks' => 'it has ' . (in_array($upper, ['A', 'E', 'F', 'H', 'I', 'L', 'M', 'N', 'O', 'R', 'S', 'X'], true) ? 'an' : 'a') . " $upper",
                'bookends' => "it doesn't start and end with $upper",
                'double' => "it has no double $upper",
                'count' => 'it has ' . substr_count($word, (string) $a) . " {$upper}s, not $b",
                'minLen' => "it's $n letters$asSingular, not $a or more",
                'maxLen' => "it's $n letters$asSingular, not $a or fewer",
                'len' => "it's $n letters$asSingular, not $a",
                'alpha' => self::alphaWhy($word),
                'oneVowel' => 'it has more than one vowel',
                'onlyVowel' => str_contains($word, (string) $a) ? "it has vowels other than $upper" : "it has no $upper",
                default => null,
            };
            // Length messages already say what the word counts as.
            return $why === null || in_array($type, ['minLen', 'maxLen', 'len'], true) ? $why : $why . $asVerb;
        }
        return null;
    }

    /** "its letters aren't in alphabetical order: H comes before E" */
    private static function alphaWhy(string $word): string
    {
        for ($i = 1; $i < strlen($word); $i++) {
            if ($word[$i] < $word[$i - 1]) {
                return "its letters aren't in alphabetical order: " . strtoupper($word[$i - 1]) . ' comes before ' . strtoupper($word[$i]);
            }
        }
        return "its letters aren't in alphabetical order";
    }

    /** @return string[] matching words from the dictionary, most common first */
    public static function fitting(array $spec, Dictionary $dictionary): array
    {
        $test = self::compile($spec, $dictionary);
        $pool = isset($spec['cat']) ? $dictionary->categoryWords($spec['cat']) : $dictionary->words;
        return array_values(array_filter($pool, $test));
    }

    /**
     * How many distinct answers are still unsaid. Saying a word uses up its whole family,
     * so families count once ("apple" and "apples" are one answer).
     *
     * @param array<string, true> $burned
     */
    public static function countUnsaid(array $spec, Dictionary $dictionary, array $burned): int
    {
        $test = self::compile($spec, $dictionary);
        $pool = isset($spec['cat']) ? $dictionary->categoryWords($spec['cat']) : $dictionary->words;
        $roots = [];
        foreach ($pool as $word) {
            if (!isset($burned[$word]) && $test($word)) $roots[$dictionary->familyRoot($word)] = true;
        }
        return count($roots);
    }

    /**
     * The unsaid answers, one word per family (the commonest form), most common first.
     *
     * @param array<string, true> $burned
     * @return string[]
     */
    public static function unsaid(array $spec, Dictionary $dictionary, array $burned): array
    {
        $test = self::compile($spec, $dictionary);
        $pool = isset($spec['cat']) ? $dictionary->categoryWords($spec['cat']) : $dictionary->words;
        $found = [];
        foreach ($pool as $word) {
            if (isset($burned[$word]) || !$dictionary->has($word) || !$test($word)) continue;
            $found[$dictionary->familyRoot($word)] ??= $word;
        }
        $words = array_values($found);
        usort($words, fn ($a, $b) => $dictionary->rarity($a) <=> $dictionary->rarity($b));
        return $words;
    }

    // --- Describing ---

    public static function describe(array $spec, Dictionary $dictionary): string
    {
        $rules = $spec['rules'] ?? [];
        if (isset($spec['cat'])) {
            $label = $dictionary->categoryLabel($spec['cat']);
            return $rules ? $label . ' ' . implode(', ', array_map([self::class, 'phraseAfterCategory'], $rules)) : $label;
        }
        // Merge repeated "contains" rules: "Contains G and Y".
        $contains = array_values(array_filter($rules, fn ($r) => $r[0] === 'contains'));
        if (count($contains) > 1) {
            $merged = ['contains', implode(' and ', array_map(fn ($r) => strtoupper($r[1]), $contains))];
            $rules = array_merge([$merged], array_values(array_filter($rules, fn ($r) => $r[0] !== 'contains')));
            $rules[0][1] = strtolower($rules[0][1]); // phrase() upper-cases it again.
            return ucfirst(str_replace(' AND ', ' and ', implode(', ', array_map([self::class, 'phrase'], $rules))));
        }
        return ucfirst(implode(', ', array_map([self::class, 'phrase'], $rules)));
    }

    /** "An animal starting with B", "Something you wear of 7+ letters". */
    private static function phraseAfterCategory(array $rule): string
    {
        [$type, $a] = $rule + [null, null];
        $upper = is_string($a) ? strtoupper($a) : $a;
        return match ($type) {
            'starts' => "starting with $upper",
            'ends' => "ending in $upper",
            'contains' => "containing $upper",
            'lacks' => "with no $upper",
            'minLen' => "of $a+ letters",
            'maxLen' => "of $a letters or fewer",
            'len' => "of exactly $a letters",
            default => lcfirst(self::phrase($rule)),
        };
    }

    /** Standalone letter rules: "Starts with P, 8+ letters, no E". */
    private static function phrase(array $rule): string
    {
        [$type, $a, $b] = $rule + [null, null, null];
        $upper = is_string($a) ? strtoupper($a) : $a;
        return match ($type) {
            'starts' => "starts with $upper",
            'ends' => "ends in $upper",
            'contains' => "contains $upper",
            'lacks' => "no $upper",
            'bookends' => "starts and ends with $upper",
            'double' => "has a double $upper",
            'count' => (self::NUMBER_WORDS[$b] ?? $b) . " {$upper}s",
            'minLen' => "$a+ letters",
            'maxLen' => "$a letters or fewer",
            'len' => "exactly $a letters",
            'alpha' => 'letters in alphabetical order',
            'oneVowel' => 'only one vowel',
            'onlyVowel' => "every vowel is $upper",
        };
    }

    // --- Choosing new prompts ---

    /**
     * Picks a random unused prompt whose number of unsaid answers is between $min and $max.
     * About half combine a meaning with a letter rule, a fifth are meaning only, and
     * the rest are letter rules. Late in a world nothing may fit the range, so it falls back to
     * the richest candidate it saw, then to a simple letter pattern.
     *
     * @param array<string, true> $burned
     * @param array<string, true> $used prompt keys already used in this world
     * @return array{spec: array, available: int}|null null when no words are left at all
     */
    /**
     * @param ?string $avoidCat the last prompt's meaning list: another list is chosen if one fits,
     *   so a small pack doesn't ask about the same list twice running (its "Nobody said" answers
     *   would still be fresh)
     */
    public static function pick(Dictionary $dictionary, array $burned, array $used = [], int $min = 10, int $max = 80, int $attempts = 40, ?string $avoidCat = null): ?array
    {
        if (count($dictionary->categoryIds()) < 2) $avoidCat = null;
        // Choose the kind first, so kinds that rarely land in range still come up as often as intended.
        $kinds = $dictionary->categoryIds() ? self::weightedOrder(['meaningAndLetters' => 50, 'meaning' => 20, 'letters' => 30]) : ['letters'];
        $best = null;
        foreach ($kinds as $kind) {
            for ($i = 0; $i < $attempts; $i++) {
                $spec = self::randomSpec($dictionary, $kind);
                if (isset($used[self::key($spec)]) || ($avoidCat !== null && ($spec['cat'] ?? null) === $avoidCat)) continue;
                $count = self::countUnsaid($spec, $dictionary, $burned);
                if ($count >= $min && $count <= $max) return ['spec' => $spec, 'available' => $count];
                if ($count > 0 && ($best === null || $count > $best['available'])) $best = ['spec' => $spec, 'available' => $count];
            }
        }
        // Nothing else fits: the same list will do after all.
        if ($avoidCat !== null) return self::pick($dictionary, $burned, $used, $min, $max, $attempts);
        return $best ?? self::simplePrompt($dictionary, $burned, $used);
    }

    public static function key(array $spec): string
    {
        return json_encode(['cat' => $spec['cat'] ?? null, 'rules' => $spec['rules'] ?? []]);
    }

    private static function randomSpec(Dictionary $dictionary, string $kind): array
    {
        return match ($kind) {
            'meaningAndLetters' => ['cat' => self::pickOne($dictionary->categoryIds()), 'rules' => [self::randomCategoryRule()]],
            'meaning' => ['cat' => self::pickOne($dictionary->categoryIds()), 'rules' => []],
            'letters' => ['cat' => null, 'rules' => self::randomLetterRules()],
        };
    }

    /** @param array<string, int> $weights @return string[] keys in a random order, heavier ones likelier first */
    private static function weightedOrder(array $weights): array
    {
        $order = [];
        while ($weights) {
            $roll = random_int(1, array_sum($weights));
            foreach ($weights as $key => $weight) {
                if (($roll -= $weight) <= 0) {
                    $order[] = $key;
                    unset($weights[$key]);
                    break;
                }
            }
        }
        return $order;
    }

    // S is left out of rules that a plural would satisfy for free ("contains S", "two Ss").
    private const LETTERS_NO_S = 'abcdeghilmnoprtuy';

    private static function randomCategoryRule(): array
    {
        return match (random_int(1, 6)) {
            1, 2 => ['starts', self::pickOne(str_split('abcdefghiklmnoprstuwy'))],
            3 => ['contains', self::pickOne(str_split('abcdefghiklmnoprtuwy'))],
            4 => ['ends', self::pickOne(['y', 'er', 'le', 'et', 'ow', 'ch', 'k', 'n', 'l'])],
            5 => ['lacks', self::pickOne(['e', 'a', 'o', 'r', 's', 't'])],
            6 => random_int(0, 1) ? ['minLen', random_int(7, 9)] : ['len', random_int(4, 6)],
        };
    }

    private static function randomLetterRules(): array
    {
        [$x, $y] = self::pickTwo(str_split(self::LETTERS_NO_S));
        return match (random_int(1, 8)) {
            1 => [['alpha'], ['minLen', random_int(5, 6)]],
            2 => [['oneVowel'], ['minLen', random_int(6, 7)]],
            3 => [['onlyVowel', self::pickOne(str_split(self::VOWELS))], ['minLen', random_int(6, 8)]],
            4 => [['bookends', $x]],
            5 => [['count', $x, 2], ['lacks', $y]],
            6 => [['contains', $x], ['contains', $y], ['maxLen', random_int(5, 6)]],
            7 => [['double', self::pickOne(str_split('bdefglmnoprt'))], ['minLen', random_int(6, 8)]],
            8 => [['starts', $x], ['minLen', random_int(8, 9)], ['lacks', self::pickOne(array_diff(['e', 'a', 'i', 'o'], [$x]))]],
        };
    }

    /** Last resort: the richest "starts/ends/contains two or three letters" pattern left. */
    private static function simplePrompt(Dictionary $dictionary, array $burned, array $used): ?array
    {
        $counts = [];
        foreach ($dictionary->words as $word) {
            if (isset($burned[$word])) continue;
            $keys = [];
            foreach ([2, 3] as $n) {
                if (strlen($word) < $n) continue;
                $keys['starts:' . substr($word, 0, $n)] = true;
                $keys['ends:' . substr($word, -$n)] = true;
                for ($i = 0; $i + $n <= strlen($word); $i++) $keys['contains:' . substr($word, $i, $n)] = true;
            }
            foreach ($keys as $k => $_) $counts[$k] = ($counts[$k] ?? 0) + 1;
        }
        arsort($counts);
        foreach ($counts as $k => $_) {
            [$type, $letters] = explode(':', $k);
            // Never a prompt that spells a rude word ("Contains ASS").
            if ($dictionary->isBlocked($letters) || $dictionary->isMasked($letters)) continue;
            $spec = ['cat' => null, 'rules' => [[$type, $letters]]];
            if (!isset($used[self::key($spec)])) return ['spec' => $spec, 'available' => self::countUnsaid($spec, $dictionary, $burned)];
        }
        return null;
    }

    private static function pickOne(array $items): mixed
    {
        $items = array_values($items);
        return $items[random_int(0, count($items) - 1)];
    }

    private static function pickTwo(array $items): array
    {
        $first = random_int(0, count($items) - 1);
        $second = random_int(0, count($items) - 2);
        if ($second >= $first) $second++;
        return [$items[$first], $items[$second]];
    }
}
