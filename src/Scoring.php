<?php
declare(strict_types=1);

final class Scoring
{
    public const WORLD_MULTIPLIER_CAP = 10;
    /** The prompt bonus at most: reached only if every answer were said (a prompt ends sooner). */
    public const PROMPT_BONUS_CAP = 2;
    /** Extra points for the answer that finishes a prompt. */
    public const CLOSING_BONUS = 10;

    /** Rises as the whole dictionary is burned: x1 at the start, x2 at half, x5 at 80%. */
    public static function worldMultiplier(int $burnedCount, int $dictionarySize): float
    {
        $remaining = 1 - $burnedCount / $dictionarySize;
        return $remaining <= 0 ? self::WORLD_MULTIPLIER_CAP : min(self::WORLD_MULTIPLIER_CAP, 1 / $remaining);
    }

    /**
     * Rises gently as the current prompt runs dry: x1 at the start, plus the share of its answers
     * already said (x1.5 when half are said), so late answers are worth more without swamping
     * what was said early. Rarity and length still do most of the work.
     */
    public static function promptBonus(int $remainingNow, int $availableAtStart): float
    {
        if ($availableAtStart <= 0) return 1.0;
        $used = ($availableAtStart - $remainingNow) / $availableAtStart;
        // Never below x1, even if the count rules changed while a prompt was running.
        return round(max(1.0, min(self::PROMPT_BONUS_CAP, 1 + $used)), 2);
    }

    /**
     * A word's base points: its length minus 2 (at least 1). A phrase, written with spaces or
     * hyphens ("paddle steamer"), scores its longest word plus 1 for each other word, so it isn't
     * worth more than a long single word just for being two words joined up.
     */
    public static function base(string $written): int
    {
        $letters = fn (string $s) => strlen(preg_replace('/[^a-z]/', '', strtolower($s)));
        $parts = preg_split('/[\s\-]+/', trim($written), -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) <= 1) return max(1, $letters($written) - 2);
        return max(1, max(array_map($letters, $parts)) - 2) + count($parts) - 1;
    }

    /**
     * $word as written ("paddle steamer" or "steamer"; see base()). $rarity: 0 for the most
     * common word, approaching 1 for the rarest.
     */
    public static function scoreWord(string $word, float $rarity, float $worldMult, float $promptMult): int
    {
        $base = self::base($word);
        $rarityMult = 1 + 2 * $rarity;
        return max(1, (int) round($base * $rarityMult * $worldMult * $promptMult));
    }
}
