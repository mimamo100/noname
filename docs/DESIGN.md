# Burned Words — Design

> Every word anyone plays is gone. For everyone. Forever.

A word game where each world starts with the same dictionary, and every word a
player uses is permanently removed from it. Easy words go first; as the
language shrinks, players hunt for rarer words, and those words are worth more.

## The dictionary

- Every world starts with the same fixed list: about 32,000 common English
  words (3+ letters), built by `scripts/build-dictionary.php`.
- A word must be in both the ENABLE word list (no proper nouns, no
  abbreviations) and a large frequency list from film subtitles, so obscure
  word-list junk is excluded.
- Words are ranked by how common they are. The rank drives the rarity bonus.

## Worlds

- **Asynchronous.** Nobody waits for other players. You drop in whenever you
  like and play against the world's current state.
- **Public world.** One shared world that anyone can join straight away.
  Later it becomes seasonal (a new world each month).
- **Private worlds.** Anyone can create one and share the link. Each world
  burns its own copy of the dictionary.
- **Identity.** A nickname per world. No accounts in the prototype.

## How you play

1. The world always has one **current prompt**, a letter pattern such as
   *contains "OO"*, *starts with "ST"* or *ends with "ING"*.
2. You type words. A word is accepted if it:
   - is in the dictionary,
   - fits the current prompt, and
   - hasn't been burned yet.
3. An accepted word scores points and is **burned for everyone in that world,
   permanently**, including for all future prompts. It is burned together with
   its **word family**, so playing *cat* also burns *cats*.
4. The burned word shows who burned it ("*moon* — burned by Sam").
5. Each prompt shows a **live counter**: "41 words left that fit".
6. The prompt changes when its counter reaches zero, or after its time runs
   out (24 hours by default).

Because burns carry over, later prompts begin with many of their fitting words
already gone, so the game gets harder as the world ages.

## Scoring

```
points = base × rarity × world multiplier × prompt bonus
```

| Part | Rule | Range |
|---|---|---|
| Base | word length − 2 | 1 for a 3-letter word, 8 for a 10-letter word |
| Rarity | 1 + 2 × (frequency rank ÷ dictionary size) | ×1 for the most common words, ×3 for the rarest |
| World multiplier | 1 ÷ (1 − share of the dictionary burned) | ×1 at the start, ×2 at half burned, ×5 at 80% burned (capped at ×10) |
| Prompt bonus | words that fit when the prompt began ÷ words that fit now | ×1 when the prompt starts, rising as it runs dry (capped at ×5) |

Points are rounded to whole numbers, with a minimum of 1.

Scarcity drives the multipliers, not the clock. A world that sits quiet for a
week doesn't get easier to score in.

**Holding words back.** Because multipliers rise, a player can save a word
they know and play it later for more points, at the risk that someone else
burns it first. This is intended.

**Balance to watch in testing.** One late, rare word shouldn't outscore a whole
day of early play. The caps above are the first lever to adjust.

## Word families

A word's family is found with simple suffix rules: plurals (*-s*, *-es*,
*-ies*), *-ed*, *-d* and *-ing*, including doubled consonants (*running* →
*run*). A family is only formed when the root is also in the dictionary.
The rules are approximate. Some odd pairs will be grouped together and some
real pairs missed. That's acceptable for the prototype.

## Prompts

- Prompt types: *starts with*, *ends with*, *contains* (two or three letters).
- Each new prompt is picked at random, but only from patterns with a sensible
  number of unburned words (by default 15–200), so prompts are neither trivial
  nor impossible.

## Prototype scope

In the first version:
- Public world plus private worlds by link
- Nickname join, word submission, burning, word families, scoring, leaderboard
- Live counter, recent burns, "who burned this word?" lookup
- Prompt rotation on exhaustion or timeout

Not yet:
- Accounts, seasons, sharing cards, mobile polish
- An offensive-word filter (needed before the public world opens to strangers)
- Rate limiting and anti-cheat (for example, pasting word lists from a solver)

## Open questions

- Should players see their own history of burned words, as a trophy case?
- Should a world end (when the dictionary is X% burned, or by date), and what
  does the ending look like?
- Is 24 hours the right prompt length for small friend groups?
