# Meaning categories

One file per category, `<id>.txt`, kept by hand. The game reads none of these
directly: `php scripts/build-dictionary.php` builds them into
`data/dictionary.php`.

```
label: A fish

cod
haddock

also:
bass
sole
```

- **label:** what the prompt says.
- **note:** (optional) what the list does and doesn't include, in a sentence or two for
  players: `note: Spiders, woodlice and other creepy-crawlies count as bugs.` The page shows
  it under the prompt, behind "About this list". Use it for a list's quirks.
- **Words before `also:`** are clear answers. They size prompts and are
  counted as "words left unsaid".
- **Words after `also:`** are accepted but not counted: words that fit
  through another meaning ("squash" as a sport).
- One lowercase word or phrase per line, in any order. Lines starting with
  `#` are comments. Words must be in the dictionary (`data/words.txt` or
  `data/extra-words.txt`); others are ignored.
- **Write phrases the way people write them**: `tea towel`, `t-shirt`,
  `rock'n'roll`. A line may hold lowercase letters, spaces, hyphens and
  apostrophes. The game drops the spaces, hyphens and apostrophes, as it does
  from what players type, so the word played is the joined form (*teatowel*,
  which must be 3 or more letters a to z, and is what goes in
  `extra-words.txt`). Players see it as written, in the feed, the unsaid list
  and everywhere else. If two files write the same phrase differently, the
  build warns and uses the first.
- **Plurals** count when their singular is listed, so add singulars. When
  removing a word, remove any plural listed too.
- **UK and US spellings** are one answer: either spelling covers both.
- **includes:** (optional, after the label) names categories whose words
  also belong to this one: `includes: mammal bird fish insect` in
  `animal.txt`. The build adds their *main* and *also* words, so list each
  word once, in the most specific category, and a fix is only ever made in
  one place. One deliberate exception: a word can be a clear answer in the
  broader category but only an *also* in the narrower one (*pancake* is a
  food, but only loosely a dessert; *dingo* is a mammal, but only loosely a
  dog). Then it's listed in both.
- **The build checks the files** and prints warnings (it still builds): a
  listed word that isn't in the dictionary (usually a typo, or a word
  missing from `extra-words.txt`) or is blocked, and a listing that adds
  nothing because an included category already has the word.

To draft a brand-new category from WordNet, see
`scripts/build-categories.py`. Never rerun it over an existing category.

## From players' reports

Players report answers our lists are missing. The admin page's **Download
reports** gives a text file grouped by category file, with how many players
reported each word. Add the approved words here (and to
`data/extra-words.txt` if the file marks them NOT IN DICTIONARY), keeping the
rules above, rebuild, and upload `data/dictionary.php`. Then press **Mark all
reviewed** on the admin page.
