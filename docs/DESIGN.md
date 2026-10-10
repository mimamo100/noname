# Unsaid — Design

> Every word you play, no one can say again.

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
  uses up its own copy of the dictionary.
- **Identity.** A nickname per world. No accounts in the prototype.

## How you play

1. The world always has one **current prompt**, a letter pattern such as
   *contains "OO"*, *starts with "ST"* or *ends with "ING"*.
2. You type words. A word is accepted if it:
   - is in the dictionary,
   - fits the current prompt, and
   - hasn't been said yet.
3. An accepted word scores points, and **nobody in that world can say it
   again**, including in all future prompts. Its **word family** goes with it,
   so saying *cat* also uses up *cats*.
4. The game records who said each word ("*moon* — said by Sam").
5. Each prompt shows a **live counter**: "41 words left unsaid".
6. The prompt changes when its counter reaches zero, or after its time runs
   out (24 hours by default).

Because said words stay said, later prompts begin with many of their fitting words
already gone, so the game gets harder as the world ages.

## Naming in the code

The code and database call a said word "burned" (for example the `burns`
table). Players never see that word.

## Scoring

```
points = base × rarity × world multiplier × prompt bonus
```

| Part | Rule | Range |
|---|---|---|
| Base | word length − 2 | 1 for a 3-letter word, 8 for a 10-letter word |
| Rarity | 1 + 2 × (frequency rank ÷ dictionary size) | ×1 for the most common words, ×3 for the rarest |
| World multiplier | 1 ÷ (1 − share of the dictionary said) | ×1 at the start, ×2 at half said, ×5 at 80% said (capped at ×10) |
| Prompt bonus | words that fit when the prompt began ÷ words that fit now | ×1 when the prompt starts, rising as it runs dry (capped at ×5) |

Points are rounded to whole numbers, with a minimum of 1.

Scarcity drives the multipliers, not the clock. A world that sits quiet for a
week doesn't get easier to score in.

**Holding words back.** Because multipliers rise, a player can save a word
they know and play it later for more points, at the risk that someone else
says it first. This is intended.

**Balance to watch in testing.** One late, rare word shouldn't outscore a whole
day of early play. The caps above are the first lever to adjust.

## Word families

A word's family is found with simple suffix rules: plurals (*-s*, *-es*,
*-ies*), *-ed*, *-d* and *-ing*, including doubled consonants (*running* →
*run*). A family is only formed when the root is also in the dictionary.
The rules are approximate. Some odd pairs will be grouped together and some
real pairs missed. That's acceptable for the prototype.

## Prompts

Prompts have to make players think, not just type a pattern into a
word-finder site. There are three kinds:

| Kind | Share | Examples |
|---|---|---|
| Meaning and letters | about half | *An animal starting with B*, *A feeling containing U*, *A family member of 8+ letters* |
| Meaning only | about a fifth | *A bird*, *A musical instrument*, *Weather* |
| Letters only | the rest | *Letters in alphabetical order, 6+ letters*, *Starts and ends with N*, *Two Ys, no L* |

- **Meanings** come from 31 categories (animals, foods, jobs, feelings,
  colours and so on), built from WordNet by `scripts/build-categories.py`.
  A word counts when its main meaning fits: *fly* is an insect, but *does*
  isn't an animal just because a doe is a deer.
- **Any meaning counts for accepting an answer.** *Squash* is a sport even
  though WordNet lists the vegetable first. Only words whose *main* meaning
  fits are counted in "words left unsaid" and used to size prompts, so
  obscure meanings don't inflate the counter.
- **Corrections** go in `data/category-overrides.txt`, for example removing
  *young* from animals or adding *white* to colours. WordNet occasionally
  accepts odd answers (*queen* is an insect, as in queen bee). Accepting a
  surprising answer is better than rejecting a sensible one.
- **Size.** A new prompt is only chosen if it has 10–80 distinct answers still
  unsaid, counting a word family once. Late in a world, when nothing fits
  that range, the game takes the richest prompt it can find.
- **Plurals count as their singular** for length rules and points, so
  adding an S can't stretch *brother* into an 8-letter answer or earn extra
  points. Spelling rules (*starts with*, *contains*) check the word as typed,
  and the game never sets letter rules an S would satisfy for free ("contains
  S", "two Ss"). Only plurals: *-ing* and *-ed* words such as *building* and
  *evening* are often words in their own right.
- **The counter** shows distinct answers left, so saying *apple* (which also
  uses up *apples*) takes it down by one.

## Penalties and limits

- A guess that **doesn't fit** the prompt, or has **already been said**,
  costs 2 points. Pasting a list from a word-finder site mostly hits words
  that are already said, so it loses points.
- **Typos are free**: a word that isn't in the dictionary costs nothing.
- **Gaps in our lists are free too.** The game can't tell a real answer
  missing from its list from a wrong one, so it gives the benefit of the
  doubt: these guesses cost nothing but don't score either. If a word has the right letters but
  isn't on our list for the meaning ("sofa" for *A sport or game with no R*),
  it costs nothing, and the player can press **Report it**. Words missing
  from the dictionary can be reported the same way. The admin page (`/admin`)
  lists reports, and accepting one makes the word count straight away.
- Players can make at most **20 guesses a minute**, which stops scripts.
- Penalties count in both the total and "this prompt" columns of the
  leaderboard.
- **Just said** lists every word said in the current prompt, newest first,
  with the three prompts before it collapsed underneath. Any older word can be
  found with **Who said it?**.
- **My misses** lists a player's own wrong guesses (with the prompt, the
  reason and any penalty). It's private: other players can't see your misses,
  because "close" guesses would give them clues, and public mistakes would
  make people afraid to try things.

## UK and US spelling

- **One answer, either spelling.** *Colour* and *color* (with *colours*,
  *colored* and so on) are one answer: saying one uses up the other. The pairs
  come from VarCon (`scripts/build-spellings.php` makes `data/spellings.json`),
  and whichever spelling the word list lacks is added (about 400 words).
- **Not every pair is linked.** Spellings that are also everyday words on both
  sides stay separate, so *tired* doesn't use up *tyre* and *check* doesn't use
  up *cheque*. Extra links, like *grey*/*gray*, go in `data/spelling-links.txt`.
- **A choice per private world:** UK and US (the default, and the public
  world), UK only, or US only. In a UK-only world, *color* gets a free
  "This world uses UK spelling: try 'colour'". Counters leave out spellings
  the world doesn't accept.
- **Missing spellings** can be reported like any missing word.

## Offensive words

`data/blocklist.txt` lists slurs, strong profanity and explicit sexual terms.
Blocked words, and their families, are removed from the dictionary, so they
can never be played, appear in a prompt or show in the feed. Nicknames
containing a blocked word are refused. Words that are innocent in their main
meaning (*spade*, *queen*, *pansy*, *hoe*) are deliberately allowed.

## Prototype scope

In the first version:
- Public world plus private worlds by link
- Nickname join, word submission, word families, scoring, leaderboard
- Live counter, recently said words, "who said it?" lookup
- Meaning, letter and combined prompts, guess penalties, rate limit, offensive-word filter
- Prompt rotation on exhaustion or timeout

Not yet:
- Accounts, seasons, sharing cards, mobile polish

## Open questions

- Should players see their own history of said words, as a trophy case?
- Should a world end (when X% of the dictionary is said, or by date), and what
  does the ending look like?
- Is 24 hours the right prompt length for small friend groups?

## Ideas for later

- **Word packs for private worlds:** themed dictionaries such as Music,
  Food & cooking, Sport, Nature, Film & TV and Science, chosen when creating a
  world. Draft lists can come from WordNet topic tags (about 300 music words,
  950 food words, 450 sport words), but they need main-meaning filtering and a
  manual review. Small packs need smaller prompt ranges (about 5–30 answers),
  and could end with a prize for whoever says the last word.
- **Custom packs:** the world creator pastes their own list, such as office
  jargon, a class spelling list or song titles.
- **Names packs** (artists, bands, labels): a separate source, multi-word
  matching, and regular updates. Harder, and best left until later.
- **A look that fits the name:** the current dark style with orange "ember"
  accents came from the old name. Possible directions: quiet and literary
  (paper and ink), or dark with cooler colours.
