<?php
declare(strict_types=1);

// Groups words into families with simple suffix rules: plurals, -ed and -ing.
// The rules are approximate; see docs/DESIGN.md.
final class WordFamilies
{
    /**
     * @param string[] $words
     * @return array<string, string[]> root => members, only for families of 2+ words
     */
    public static function build(array $words): array
    {
        $known = array_flip($words);
        $parent = [];
        foreach ($words as $word) {
            $parent[$word] = $word;
            foreach (self::rootCandidates($word) as $candidate) {
                if ($candidate !== $word && isset($known[$candidate])) {
                    $parent[$word] = $candidate;
                    break;
                }
            }
        }
        $members = [];
        foreach ($words as $word) {
            $root = $word;
            while ($parent[$root] !== $root) $root = $parent[$root]; // Roots are always shorter, so this ends.
            $members[$root][] = $word;
        }
        return array_filter($members, fn (array $m) => count($m) > 1);
    }

    /** Candidate roots for a word, most likely first. */
    public static function rootCandidates(string $word): array
    {
        $candidates = [];
        if (str_ends_with($word, 'ies')) $candidates[] = substr($word, 0, -3) . 'y';
        if (str_ends_with($word, 's') && !str_ends_with($word, 'ss')) $candidates[] = substr($word, 0, -1); // rates -> rate
        if (str_ends_with($word, 'es')) $candidates[] = substr($word, 0, -2); // boxes -> box
        if (str_ends_with($word, 'ied')) $candidates[] = substr($word, 0, -3) . 'y';
        if (str_ends_with($word, 'ed')) {
            // -d is only stripped from longer words, so seed doesn't become see or shed become she.
            $minLength = str_ends_with($word, 'eed') ? 6 : 5;
            array_push($candidates, ...self::verbRoots(substr($word, 0, -2), strlen($word) >= $minLength));
        }
        if (str_ends_with($word, 'ing')) array_push($candidates, ...self::verbRoots(substr($word, 0, -3), true));
        return array_values(array_filter($candidates, fn (string $c) => strlen($c) >= 3));
    }

    private static function verbRoots(string $stem, bool $allowSilentE): array
    {
        if (strlen($stem) < 3) return [];
        [$a, $b] = [$stem[-2], $stem[-1]];
        // running -> run, stopped -> stop (but falling -> fall comes first).
        if ($a === $b && self::isConsonant($b)) return [$stem, substr($stem, 0, -1)];
        // singing -> sing, not singe.
        if (self::isConsonant($a) && self::isConsonant($b)) return [$stem];
        // hoping -> hope, not hop; rated -> rate, not rat.
        return $allowSilentE ? [$stem . 'e', $stem] : [$stem];
    }

    private static function isConsonant(string $ch): bool
    {
        return !str_contains('aeiouy', $ch);
    }
}
