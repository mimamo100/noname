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
- **Forms of words we have** (plurals, -ed, -ing): these are needed. A form that isn't in
  the dictionary can't be played at all (*limped* is "not a word"); word families only
  decide what gets used up together. They're regular forms ENABLE confirms, so they can be
  added in bulk after a quick skim.
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

## How added words score

Hand-added words go after the main word list, ordered by how often they appear in film
subtitles, so a fairly common one (*thicken*) doesn't score as one of the rarest. The counts
are in `data/extra-word-counts.txt`; after adding words, run
`php -d memory_limit=1G scripts/count-extra-words.php`, then rebuild. Words with no count
(most category words, such as *capybara*) come last, as before.

## Done: the first review, 13 October 2026

- The first 3,000 new words were reviewed: **2,854 added**, with 204 also placed in their
  meaning category (*whippoorwill* in bird, *longshoreman* in job). Left out: 57 names, 31
  fragments or misspellings, 29 foreign words, 5 abbreviations, 4 brands.
- **All 6,600 forms** of words already in the dictionary were added (only *filles* left out).
- **Blocked:** slurs and sexual crimes (*redskin*, *paleface*, *kaffir*, *yid*, *necrophilia*,
  *cunnilingus* to match *fellatio*). **Masked:** crude words (*gangbang*, *bunghole*,
  *knackers*), and *shit* with its family, moved from the blocklist by the owner. Pejoratives
  that aren't crude are ordinary words (*papist*, *shiksa*).
- The dictionary went from 39,720 to 49,410 words. About 13,000 further new words appear 20
  or more times in the subtitles; regenerate the list to review the next batch.
