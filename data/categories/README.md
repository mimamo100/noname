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
- **animal** lists only animals that fit none of mammal, bird, fish and
  insect. The build adds those four categories' words to it, so a fix is
  only ever made in one place.

To draft a brand-new category from WordNet, see
`scripts/build-categories.py`. Never rerun it over an existing category.
