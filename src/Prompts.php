<?php
declare(strict_types=1);

// Prompts are letter patterns: words that start with, end with, or contain some letters.
final class Prompts
{
    public static function matches(string $type, string $letters, string $word): bool
    {
        return match ($type) {
            'starts' => str_starts_with($word, $letters),
            'ends' => str_ends_with($word, $letters),
            'contains' => str_contains($word, $letters),
        };
    }

    public static function describe(string $type, string $letters): string
    {
        $upper = strtoupper($letters);
        return match ($type) {
            'starts' => "Starts with “{$upper}”",
            'ends' => "Ends with “{$upper}”",
            'contains' => "Contains “{$upper}”",
        };
    }

    /** @param array<string, true> $burned */
    public static function countUnburned(Dictionary $dictionary, string $type, string $letters, array $burned): int
    {
        $count = 0;
        foreach ($dictionary->words as $word) {
            if (!isset($burned[$word]) && self::matches($type, $letters, $word)) $count++;
        }
        return $count;
    }

    /** @return string[] */
    public static function fitting(Dictionary $dictionary, string $type, string $letters): array
    {
        return array_values(array_filter($dictionary->words, fn ($w) => self::matches($type, $letters, $w)));
    }

    /**
     * Counts unburned words for every possible 2-3 letter prompt in one pass.
     *
     * @param array<string, true> $burned
     * @return array<string, int> keys like "starts:st"
     */
    public static function countAll(Dictionary $dictionary, array $burned): array
    {
        $counts = [];
        foreach ($dictionary->words as $word) {
            if (isset($burned[$word])) continue;
            $keys = [];
            $length = strlen($word);
            foreach ([2, 3] as $n) {
                if ($length < $n) continue;
                $keys['starts:' . substr($word, 0, $n)] = true;
                $keys['ends:' . substr($word, -$n)] = true;
                for ($i = 0; $i + $n <= $length; $i++) $keys['contains:' . substr($word, $i, $n)] = true;
            }
            foreach ($keys as $key => $_) $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * Picks a random unused prompt with a playable number of unburned words.
     * Late in a world nothing may be in range, so it falls back to the richest prompt left.
     *
     * @param array<string, true> $burned
     * @param array<string, true> $used keys like "starts:st"
     * @return array{type: string, letters: string, available: int}|null
     */
    public static function pick(Dictionary $dictionary, array $burned, array $used = [], int $min = 15, int $max = 200): ?array
    {
        $counts = self::countAll($dictionary, $burned);
        $inRange = [];
        $fallback = null;
        foreach ($counts as $key => $count) {
            if (isset($used[$key])) continue;
            if ($count >= $min && $count <= $max) $inRange[] = $key;
            elseif ($fallback === null || $count > $counts[$fallback]) $fallback = $key;
        }
        $key = $inRange ? $inRange[random_int(0, count($inRange) - 1)] : $fallback;
        if ($key === null) return null;
        [$type, $letters] = explode(':', $key);
        return ['type' => $type, 'letters' => $letters, 'available' => $counts[$key]];
    }
}
