export const WORLD_MULTIPLIER_CAP = 10;
export const PROMPT_BONUS_CAP = 5;

// Rises as the whole dictionary is burned: x1 at the start, x2 at half, x5 at 80%.
export function worldMultiplier(burnedCount, dictionarySize) {
  const remaining = 1 - burnedCount / dictionarySize;
  return remaining <= 0 ? WORLD_MULTIPLIER_CAP : Math.min(WORLD_MULTIPLIER_CAP, 1 / remaining);
}

// Rises as the current prompt runs dry, relative to what was available when it began.
export function promptBonus(remainingNow, availableAtStart) {
  if (availableAtStart <= 0 || remainingNow <= 0) return PROMPT_BONUS_CAP;
  return Math.min(PROMPT_BONUS_CAP, availableAtStart / remainingNow);
}

// rarity: 0 for the most common word, approaching 1 for the rarest.
export function scoreWord({ word, rarity, worldMult, promptMult }) {
  const base = Math.max(1, word.length - 2);
  const rarityMult = 1 + 2 * rarity;
  return Math.max(1, Math.round(base * rarityMult * worldMult * promptMult));
}
