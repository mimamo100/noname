# Unsaid

> Every word you play, no one can say again.

A word game where each world starts with the same dictionary of about 32,000
common English words. Players type words that fit the current prompt (such as
*contains "OO"*). Once a word is said, nobody in that world can say it
again, and its family goes with it (*cat* takes *cats*). As fewer words are
left unsaid, scores rise, so clever late players can still catch up.

- Game design: [docs/DESIGN.md](docs/DESIGN.md)
- Putting it on a cPanel host: [docs/DEPLOY.md](docs/DEPLOY.md)
- Growing to many players: [docs/SCALING.md](docs/SCALING.md)

## Requirements

PHP 8.1+ with `pdo_mysql` and `mbstring`, and MySQL 5.7+ or MariaDB 10.3+. There are no
Composer packages or build steps.

## Running it locally

1. Create an empty MySQL database and copy `config.example.php` to
   `config.php` with its details. Tables are created automatically.
2. Start PHP's built-in server:

   ```sh
   php -S localhost:8000 -t public public/router.php
   ```

3. Open http://localhost:8000.

## Tests

The tests need an empty database they're allowed to wipe:

```sh
TEST_DB_NAME=unsaid_test TEST_DB_USER=me TEST_DB_PASS=secret php tests/run.php
```

## Project layout

| Path | What it is |
|---|---|
| `public/` | Everything served to the web: the page, browser code, `api.php` and `.htaccess` |
| `src/Game.php` | Worlds, players, playing words and prompt rotation |
| `src/Dictionary.php`, `src/WordFamilies.php` | Word list, rarity and word families |
| `src/Prompts.php` | Prompt patterns and the prompt picker |
| `src/Scoring.php` | Points and multipliers |
| `schema.sql` | Database tables |
| `data/words.txt` | The word list, most common first |
| `data/extra-words.txt` | Words added by hand that the main list misses (editable) |
| `data/blocklist.txt` | Offensive words that can never be played (editable) |
| `data/spellings.json` | UK/US spelling pairs, generated from VarCon by `scripts/build-spellings.php` |
| `data/spelling-links.txt` | Extra UK/US spelling pairs (editable) |
| `data/categories.json` | Meaning categories, generated from WordNet by `scripts/build-categories.py` |
| `data/category-overrides.txt` | Hand corrections to the categories (editable) |
| `data/dictionary.php` | The file the game reads, generated from all of the above |

## Changing the word data

After editing `data/extra-words.txt`, `data/blocklist.txt` or
`data/category-overrides.txt`, rebuild:

```sh
php scripts/build-dictionary.php
```

To rebuild the categories themselves from WordNet (rarely needed), you also
need Python with NLTK, on your own computer rather than the server:

```sh
pip install nltk
python -c "import nltk; nltk.download('wordnet')"
python scripts/build-categories.py
php scripts/build-dictionary.php
```

## Word list credits

`data/words.txt` combines two sources:
- [ENABLE](https://github.com/dolph/dictionary) word list (public domain)
- [FrequencyWords](https://github.com/hermitdave/FrequencyWords) by Hermit Dave,
  built from OpenSubtitles (CC BY-SA 4.0)

The word list is therefore shared under CC BY-SA 4.0.

UK/US spellings come from [VarCon](http://wordlist.aspell.net/varcon-readme.html):
Copyright 2000-2019 by Kevin Atkinson and 2016 by Benjamin Titze, with permission
to use, copy, modify, distribute and sell it for any purpose without fee, provided
the copyright notice and permission notice appear in supporting documentation.
VarCon is based on Ispell word lists, Copyright 1993 Geoff Kuenning.

The meaning categories are built with [WordNet](https://wordnet.princeton.edu/)
(Princeton University, WordNet 3.0 licence), and the blocklist was reviewed
starting from the [LDNOOBW](https://github.com/LDNOOBW/List-of-Dirty-Naughty-Obscene-and-Otherwise-Bad-Words)
list (CC BY 4.0).
