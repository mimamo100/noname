# Dictionary review: instructions for a Claude session

These are instructions for reviewing the game's meaning categories. Start a
Claude Code session in a copy of this repository (see "Before you start") and
say: **"Follow docs/DICTIONARY-REVIEW.md"**.

The review has two stages with a stop in between. Stage 1 proposes changes
and writes them up for the owner. Stage 2 applies only what the owner
approves. **Don't change any word files in stage 1.**

## Before you start (on your own computer)

The session works on a copy of the repository and runs its scripts, so you
need these on your computer first:

- **git**, and a copy of the repository on the branch with the latest work:

  ```sh
  git clone https://github.com/mimamo100/unsaid.git unsaid
  cd unsaid
  git checkout claude/zealous-tesla-2ixwxu
  ```

  (Or, once that branch is merged, the main branch.)
- **PHP 8.1 or later on the command line**, with the `mbstring` extension.
  Check with `php -v` and `php -m`. On a Mac: `brew install php`. On Windows:
  download it from windows.php.net, and in `php.ini` make sure the line
  `extension=mbstring` has no `;` in front.
- **Claude Code**, started in that folder.
- Only for new categories (stage 2, step 4): **Python 3** with NLTK. The
  session can install NLTK itself.
- Not needed: MySQL, Apache or the server. The tests need a database, so the
  session will skip them if there isn't one; the checks in stage 2 cover the
  word data.

The session doesn't need to read the game's code: everything it needs is in
this file, `scripts/category-report.php` and the `data/` files.

## Background: what a category is

Meaning prompts ask for "A bird", "A fish with no R" and so on. Each meaning
is a *category*: a list of the dictionary's words that fit it. There are 31
today. List them with:

```sh
php scripts/category-report.php            # every category, with sizes
php scripts/category-report.php fish       # one category's words
php scripts/category-report.php fish cod trout haddock   # how given words stand
```

Each category has two lists:

- **main**: clear answers. These size the prompts and are counted as "words
  left unsaid".
- **also**: accepted because one of the word's *other* meanings fits ("squash"
  is a sport, though its main meaning is the vegetable). Accepted, but not
  counted, so obscure meanings don't inflate the counter.

The lists are built automatically from WordNet by
`scripts/build-categories.py`, which goes by each word's *main meaning*. That
causes the two problems this review is for:

1. **Everyday members missing from main.** WordNet often files a word's main
   meaning elsewhere. Examples found so far: carrot, onion and leek are only
   *also* for "A vegetable" (their main meaning is the plant). "A fish" has
   no cod, trout or haddock in main (their main meaning is the food). "A
   landscape feature" had no moor, field or meadow (they're "tracts of land"
   in WordNet).
2. **Odd members.** Words that technically fit through a rare sense and that
   no player would think of as an answer: *molly* and *coney* in fish,
   *bonanza* and *mains* in landscape features (removed since).

How the game uses the lists:

- Only words in the dictionary (`data/dictionary.php`, about 32,700 common
  words) can be played. A word missing from the dictionary must be added to
  `data/extra-words.txt` as well. It scores as a rare word.
- **Plurals take care of themselves:** a plural counts when its singular is on
  the list, so add singulars only. For a plural to be playable at all, it must
  be in the dictionary: add it to `extra-words.txt` next to its new singular
  (as with *copse*, *copses*).
- UK and US spellings of a word are one answer; either spelling on the list
  covers both.
- Answers are judged by a typical UK adult's sense of the label. A correct but
  unusual answer (*gurnard* for a fish) is good: rarer words score more. A
  word that only fits through a meaning nobody would think of is not.
- `data/blocklist.txt` words can never be played; don't propose them.

Corrections go in `data/category-overrides.txt`, one line per category:
`fish: +cod +trout -molly` (`+` adds to main, also promoting a word from
*also*; `-` removes from both lists). Labels live in `data/categories.json`
and in `CATEGORIES` in `scripts/build-categories.py`; change both. After
editing, rebuild with `php scripts/build-dictionary.php`.

## Stage 1: propose

1. Run `php scripts/category-report.php` for the list of categories.
2. Use helper agents in parallel, about 6, each taking about 5 categories.
   Group related ones together (animal, mammal, bird, fish, insect; food,
   fruit, vegetable, drink, kitchen; and so on). Give each agent:
   - the Background section above;
   - its categories, and the instruction to run
     `php scripts/category-report.php <id>` for each, and
     `php scripts/category-report.php <id> <words…>` to check candidates;
   - what to return for each category:
     - **remove**: odd members of *main* or *also*, each with a few words of
       reason. Only clear cases.
     - **add**: common answers missing from *main*, including any that are
       only in *also*. Mark each one: already main / also (promote) / in the
       dictionary / NOT IN DICTIONARY. Start with the answers most people
       would give first, then good less-common ones. **No limit:** the aim is
       every answer a player is likely to think of, because a missing one is
       frustrating to play and ends up as a suggestion someone must approve.
     - **label**: a better label, if the current one promises more or less
       than the list delivers.
     - **notes**: anything else, such as a category that overlaps another
       badly.
3. Separately, propose **new categories**: about 10–15 meanings that would
   make good prompts. A category needs at least about 30 main answers in the
   dictionary, so prompts with letter rules still have 10 or more. Ideas to
   evaluate: a herb or spice, a dessert or sweet, a shape, a fabric or
   material, a metal, a gemstone, a container, a toy, a reptile, a sea
   creature, a dog breed, a unit of measurement, a hairstyle, a game (board
   or card). For each, name the WordNet synsets it would use, estimate its
   size, and list 20 example answers. Only list them in stage 1; they're
   built in stage 2.
4. Merge everything into **`docs/dictionary-review/PROPOSALS.md`**:
   - a short summary at the top (how many adds and removes, and the biggest
     problems found);
   - one section per category, in the order of the report: the current label
     and sizes, then **Remove**, **Add** (with the dictionary status of each
     word) and **Label**, in short comma-separated lists, not tables;
   - a **New categories** section.
5. Commit and push it. If the session can publish an Artifact, also publish
   the proposals as a page that's easy to read on a phone, and give the owner
   the link.
6. **Stop.** Tell the owner the proposals are ready. Ask them to reply with
   anything to leave out, change or add, or "approved". Don't change any word
   files yet.

## Stage 2: apply what's approved

Decided on 2026-10-12: the categories stop being generated from WordNet.
They become plain files kept in the repository and edited by hand, one per
category, and the approved changes go straight into those files. WordNet is
only used to draft a new category, once.

1. **Freeze the categories.** Write `data/categories/<id>.txt` for every
   category from what the game has now (`data/categories.json` with
   `data/category-overrides.txt` applied): the label on the first line, then
   the *main* words, then an `also:` line and the *also* words, one per line,
   sorted. Leave out plurals whose singular is listed if the game accepts
   those plurals anyway (check how before relying on it). **Animal** is the
   exception: `animal.txt` keeps only the words that aren't in mammal, bird,
   fish or insect, and the build adds those four categories' *main* and
   *also* words to animal's (decided 2026-10-12, so no fix is made twice).
2. **Read the folder.** Change `scripts/build-dictionary.php` to read
   `data/categories/` instead of `categories.json` and the overrides. Rebuild
   and check that every category in `data/dictionary.php` is exactly what it
   was before (same words, same lists). Commit this on its own, before any
   word changes.
3. **Make the WordNet script a drafting tool.** Change
   `scripts/build-categories.py` so it writes a draft file for a category
   that doesn't exist yet and never overwrites an existing one. Delete
   `data/categories.json` and `data/category-overrides.txt`, and update
   every mention of them (README.md, docs/DESIGN.md, docs/DEPLOY.md,
   `public/admin.php`, `schema.sql`, the `data/extra-words.txt` header).
4. **Apply the approved changes** to the category files: removes, adds,
   promotions from *also*, and labels. When removing a word, remove its
   plural too.
5. Add approved words that are NOT IN DICTIONARY to `data/extra-words.txt`,
   with their plurals, under a heading per category.
6. **New categories:** draft each approved one with the drafting tool
   (needs Python with NLTK), then clean the draft using the proposals before
   committing it:

   ```sh
   pip install nltk
   python -c "import nltk; nltk.download('wordnet')"
   ```

   If NLTK can't be installed, skip new categories and say so.
7. Rebuild: `php scripts/build-dictionary.php`.
8. Check:
   - `php scripts/category-report.php`: compare sizes with before.
   - Spot-check several added and removed words per category with
     `php scripts/category-report.php <id> <words…>`.
   - Each category should still give prompts: run
     `php -r 'foreach (["WordFamilies","Dictionary","Prompts"] as $f) require "src/$f.php"; $d = Dictionary::load(); foreach ($d->categoryIds() as $id) printf("%-12s %d\n", $id, Prompts::countUnsaid(["cat" => $id, "rules" => []], $d, []));'`
   - If MySQL or MariaDB is available, run the tests (see README.md).
9. Commit with a message summarising the changes per category, and push.
10. Tell the owner how to put it live. The game reads only
   `data/dictionary.php`, so uploading that one file is enough: in cPanel's
   File Manager, open the game's `data` folder and upload it over the old
   one. (The full zip, from `sh scripts/build-zip.sh unsaid.zip`, works too,
   but needs `sh` and Python, which Windows lacks without Git Bash.) Changes
   take effect at once; a prompt running at the time has its count updated.
11. Update `docs/dictionary-review/PROPOSALS.md`: mark it as applied, noting
    anything left out.
