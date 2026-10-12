# Word packs

Themed vocabularies for private worlds and competitions: one folder per curated
pack (`science/`, `law/`, `music/`). `php scripts/build-dictionary.php` builds
each folder into `data/packs/<id>.php`, which the game reads. Organisers' own
packs live in the database instead (see `src/Packs.php`).

```
pack.txt          name: Science
                  description: One sentence a player reads when choosing a pack.

element.txt       label: A chemical element

                  argon
                  sulphur sulfur
                  nucleus nuclei
                  ...

                  also:
                  blackhole
```

The format is like `data/categories/` (see its README), with these differences:

- **Pack words needn't be in the everyday dictionary.** Any word of 3–30 letters
  a to z can be listed; blocked words are refused.
- **A line lists one answer with all its forms**, answer first: `quark quarks`,
  `nucleus nuclei`, `metre meter metres meters`. Forms not listed aren't
  accepted (except that a word in the everyday dictionary brings its everyday
  forms along: *acid* brings *acids*). The forms count as one answer.
- **No `includes:`**, and a category's id is `<pack>.<file>` in the game
  (`science.element`), so it never clashes with an everyday category.
- **No names** of people (bands, artists, composers, scientists) or of
  open-ended sets like stars, moons and constellations: there'd be no end to
  them, and no fair way to choose. Words named after people that are now
  ordinary words are fine (units such as *newton* and *kelvin*, *svengali*).
  The planets, a small closed set, are in.

A world plays a pack **on its own** (the pack is the whole dictionary; prompts
of 5–40 answers) or **with everyday words** (the everyday dictionary plus the
pack, with prompts from the pack's categories and letter prompts over all the
words). Hosts and organisers can use curated packs; organisers can also write
their own on the site (My word packs).
