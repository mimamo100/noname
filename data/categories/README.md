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
- **Words before `also:`** are clear answers. They size prompts and are
  counted as "words left unsaid".
- **Words after `also:`** are accepted but not counted: words that fit
  through another meaning ("squash" as a sport), and two-word or hyphenated
  phrases run together ("teatowel").
- One lowercase word per line, in any order. Lines starting with `#` are
  comments. Words must be in the dictionary (`data/words.txt` or
  `data/extra-words.txt`); others are ignored.
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
