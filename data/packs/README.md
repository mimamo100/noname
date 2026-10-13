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
                  sulphur, sulfur
                  nucleus, nuclei
                  black hole, black holes
                  ...

                  also:
                  habeas corpus
```

The format is like `data/categories/` (see its README), with these differences:

- **Pack words needn't be in the everyday dictionary.** Any word of 3–30 letters
  a to z can be listed.
- **A line lists one answer with all its forms**, answer first, separated by
  commas: `quark, quarks`, `nucleus, nuclei`, `metre, meter, metres, meters`.
  Forms not listed aren't accepted (except that a word in the everyday
  dictionary brings its everyday forms along: *acid* brings *acids*). The
  forms count as one answer.
- **Phrases are written the way people write them**: `black hole, black
  holes`, and a phrase on its own is just `habeas corpus`. Each form may hold
  spaces, hyphens and apostrophes; it's played joined (*blackhole*, 3–30
  letters a to z) and shown to players as written.
- **Blocked words may be listed** when the pack needs them (the law pack's
  crimes: *rape*, *incest*, *sodomy*, *bestiality*). The build notes them and
  says so; they're playable in this pack's worlds only, never anywhere else,
  and never in a nickname. Organisers' own packs still refuse them.
- **No `includes:`**, and a category's id is `<pack>.<file>` in the game
  (`science.element`), so it never clashes with an everyday category.
- **No names** of people (bands, artists, composers, scientists) or of
  open-ended sets like stars, moons and constellations: there'd be no end to
  them, and no fair way to choose. Words named after people that are now
  ordinary words are fine (units such as *newton* and *kelvin*, *svengali*).
  The planets, a small closed set, are in.
- **Obscure but correct terms go under `also:`** (*molto*, *assumpsit*,
  *amercement*): they're accepted and score, but don't count towards the
  answers a prompt has, so a prompt never leaves the room hunting for words
  only a specialist knows.

A world plays a pack **on its own** (the pack is the whole dictionary; prompts
of 5–40 answers) or **with everyday words** (the everyday dictionary plus the
pack, with prompts from the pack's categories and letter prompts over all the
words). Hosts and organisers can use curated packs; organisers can also write
their own on the site (My word packs). There a line with a comma is split on
commas, as here; a line without one is split on spaces (`quark quarks`), so
packs written before commas keep working, and a phrase on its own needs a
comma after it (`black hole,`).
