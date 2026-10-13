# Missing common words: notes for a review

Playtesters found ordinary words the game doesn't know (*befit*, *filmy*). This is
a review to find the rest and add them. Nothing changes in the game until words are
added to `data/extra-words.txt` and the dictionary is rebuilt.

## Why they're missing

`data/words.txt`, the main word list, keeps a word only if it is in ENABLE (a
public-domain word list for word games) **and** in the 50,000 most frequent words
of film subtitles (OpenSubtitles, via hermitdave/FrequencyWords). That leaves about
32,500 words. Ordinary words just below the frequency line were dropped: *befit* is
around the 72,000th most frequent word, and *filmy* around the 56,000th.

## The candidate list

`docs/dictionary-review/missing-words.txt` lists words that are in ENABLE but not in
the dictionary, most frequent first, using the full subtitle frequency list (about
1.6 million entries). Regenerate it at any time:

```
php -d memory_limit=1G scripts/find-missing-words.php [limit] [output file]
```

- **New words** (16,162 seen 20 or more times; the file lists the first 3,000):
  words of their own, such as *opulence*, *disclaimer*, *thicken*.
- **Forms of words we have** (plurals, -ed, -ing): usually not needed, because the
  game's word families bring them along. Add one only when it's an answer in its own
  right, such as a missing plural of a listed bird.
- Words already in the dictionary or the blocklist are left out, so after adding a
  batch, regenerating the list shows the next ones.

## What to add

The same rules as the dictionary review (PROPOSALS.md):

- **Ordinary English words** a player might reasonably type. Most of the list is
  fine; it thins out further down.
- **Not names** of people or places, which ENABLE sometimes has as ordinary words
  (*sibyl*, *hiller*), unless they're also ordinary words.
- **Not brand names, abbreviations or foreign words** that aren't used in English.
- **Offensive words:** don't add slurs. A word that should be playable but hidden
  goes in `data/masked.txt` as well. Note that the blocklist only covers a blocked
  word and the words formed from it, so related words still appear here
  (*pedophilia*); leave them out, or add them to `data/blocklist.txt`.
- **UK and US spellings:** add both if only one appears; `data/spelling-links.txt`
  links them so they count as one answer.

## How to add them

1. Add approved words to `data/extra-words.txt`, under a heading such as
   `# Missing-words review`, one per line. Add a plural only where the families
   wouldn't bring it (most plurals come along automatically).
2. If a word is an answer to a meaning category (a bird, a tool...), add it to that
   category's file in `data/categories/` too, or it only counts for letter prompts.
3. Rebuild: `php scripts/build-dictionary.php`. It warns about anything it can't use.
4. Upload `data/dictionary.php`.

## Something to decide

Extra words go at the end of the dictionary's frequency order, so the game scores
them as its rarest words (up to ×3). That suits genuinely rare words, but a large
batch of fairly common words (*thicken*, *mismatch*) would then score more than they
deserve. If many are added, the build could place them by their subtitle frequency
instead; that's a small change to `scripts/build-dictionary.php`.
