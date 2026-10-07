# Unsaid

> Every word you play, no one can say again.

A word game where each world starts with the same dictionary of about 32,000
common English words. Players type words that fit the current prompt (such as
*contains "OO"*). Once a word is said, nobody in that world can say it
again, and its family goes with it (*cat* takes *cats*). As fewer words are
left unsaid, scores rise, so clever late players can still catch up.

- Game design: [docs/DESIGN.md](docs/DESIGN.md)
- Putting it on a cPanel host: [docs/DEPLOY.md](docs/DEPLOY.md)

## Requirements

PHP 8.1+ with `pdo_mysql`, and MySQL 5.7+ or MariaDB 10.3+. There are no
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
| `data/dictionary.php` | Generated from `words.txt` by `php scripts/build-dictionary.php` |

## Word list credits

`data/words.txt` combines two sources:
- [ENABLE](https://github.com/dolph/dictionary) word list (public domain)
- [FrequencyWords](https://github.com/hermitdave/FrequencyWords) by Hermit Dave,
  built from OpenSubtitles (CC BY-SA 4.0)

The word list is therefore shared under CC BY-SA 4.0.
