<?php
declare(strict_types=1);

final class Scoring
{
    public const WORLD_MULTIPLIER_CAP = 10;
    public const PROMPT_BONUS_CAP = 5;

    /** Rises as the whole dictionary is burned: x1 at the start, x2 at half, x5 at 80%. */
    public static function worldMultiplier(int $burnedCount, int $dictionarySize): float
    {
        $remaining = 1 - $burnedCount / $dictionarySize;
        return $remaining <= 0 ? self::WORLD_MULTIPLIER_CAP : min(self::WORLD_MULTIPLIER_CAP, 1 / $remaining);
    }

    /** Rises as the current prompt runs dry, relative to what was available when it began. */
    public static function promptBonus(int $remainingNow, int $availableAtStart): float
    {
        if ($availableAtStart <= 0 || $remainingNow <= 0) return self::PROMPT_BONUS_CAP;
        // Never below x1, even if the count rules changed while a prompt was running.
        return max(1.0, min(self::PROMPT_BONUS_CAP, $availableAtStart / $remainingNow));
    }

    /** $rarity: 0 for the most common word, approaching 1 for the rarest. */
    public static function scoreWord(string $word, float $rarity, float $worldMult, float $promptMult): int
    {
        $base = max(1, strlen($word) - 2);
        $rarityMult = 1 + 2 * $rarity;
        return max(1, (int) round($base * $rarityMult * $worldMult * $promptMult));
    }
}
