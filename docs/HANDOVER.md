# Handover: where Unsaid stands (14 October 2026)

A note for the next working session, so it can pick up without the old conversation. Read
this first, then `docs/DESIGN.md` for how the game works.

## How the owner likes to work

- **Work on the branch `claude/zealous-tesla-2ixwxu`.** Other sessions push to it too (word-list
  reviews, playtests), so **pull it before starting** and again before building anything.
- **Commit and push each change**, with a plain message saying what and why. **No
  Co-Authored-By or Claude-Session lines** in commits.
- **Don't make a zip unless asked.** When asked: `sh scripts/build-zip.sh <path>` builds
  `unsaid.zip` from the last commit (folder `unsaid/`, permissions cPanel accepts). Send it,
  and say which files changed if only a few did. **Leave every `.htaccess` out of the zip**
  (the owner keeps their own on the server); when one changes, tell them the lines to add.
- **Check changes in a browser** (Playwright with the pre-installed Chromium) as well as the
  tests, and say plainly what was and wasn't checked.
- Plain, friendly British English for anything players read. **No em-dashes** in the site's
  own text. Wording lives across the code; `docs/MESSAGES.md` lists every message (redo it
  after big changes).
- The owner reviews word lists and makes the calls on content (what's blocked, masked,
  counted); propose, don't decide. Word-list work can go to a helper agent working only in
  `data/`, but crime/weapon lists have tripped content filters, so check its work.

## Where things are

- **Code:** PHP 8.1, no Composer. `public/` (page, `api.php`, `admin.php`), `src/` (the game:
  `Game.php` is the core; `Lobby`, `PrivateWorlds`, `Competitions`, `Packs`, `Auth`, `Admin`,
  `Housekeeping`, `Streaks`, `Reminders`), `scripts/` (dictionary build, housekeeping, daily
  email, word counts, missing words), `data/` (word lists and the built `dictionary.php`).
- **Tests:** `php tests/run.php` with a test database
  (`TEST_DB_NAME`, `TEST_DB_USER`, `TEST_DB_PASS`); 95 passing. For a browser check, a
  local MariaDB, a `config.php` with `'mail' => ['transport' => 'log', ...]` (the sign-in
  code lands in the log) and `php -S localhost:8000 -t public public/router.php` work.
- **Docs:** `DESIGN.md` (rules and features), `DECISIONS.md` (why, decision by decision: add to it when the owner decides something), `DEPLOY.md` (cPanel set-up, both cron jobs),
  `LAUNCH.md` (before going live), `SCALING.md`, `MESSAGES.md`, `playtests/` (reports with the
  owner's decisions), `dictionary-review/` and `word-packs/` (word-list reviews), and the
  READMEs in `data/categories/` and `data/packs/` (word-list formats).
- **Schema changes** are added as guarded steps in `Game::installSchema`, which runs when a
  column is missing; consolidating them is a launch task.

## The game now (main settings)

| What | Setting |
|---|---|
| A prompt ends | once 80% of its answers are said (`Game::END_SHARE`) or its time runs out; none starts in a world's last 5 minutes |
| Prompt bonus | ×1 plus the share said, at most ×2; *also* answers score at ×1 |
| Answer that ends a prompt | +10 points |
| Phrases | longest word's points plus 1 per other word, at that word's rarity |
| Free misses | typos, not on our list, your own word, a team-mate's, anyone's in the last minute, and 30 seconds' grace after a prompt changes |
| Words | about 52,000; hand-added words placed by subtitle frequency (`count-extra-words.php`) |

Worlds: the daily big world (orange), rooms of 60 (green), private worlds (teal, Host plan),
competitions with teams (purple, Organiser plan), word packs (Science, Law, Music, and
organisers' own). Accounts by email code; streaks; an optional daily email; results cards
with confetti and sharing.

**The page:** every world has a banner above the prompt (the big world's has the date, an (i)
and the round's time). On a wide screen, two columns under the word box: Last round (big
world), Recent prompt, the rooms box and Just said on the left; the leaderboard on the right.
Leaderboards show the top ten, then ⋯ and your own row if you're further down. Earlier
prompts show three that had words, made up from the last round's early in a new round.

**Your account** (`/account`, from the menu under the name): tabs for Worlds (cards in each
kind's colour), Games (streak, bests, past games), Word packs (organisers) and Settings
(change name, daily email switch, plan, sign out, delete account). Deleting keeps words and
scores as "Former player", ends the private worlds and competitions the account runs and
deletes its packs (the owner may want to revisit those two calls).

## On the owner's server

- The last zip sent was built from commit `4231775` (14 October), without `.htaccess` files.
  The owner was told to add `RewriteRule ^account$ index.html [L]` to `public/.htaccess`,
  after the `^w/` line; without it, Your account says "Not found". Whether it's all
  uploaded is the owner's to confirm. `admin_path` must not be `account`.
- `config.php` needs `site_url` for the daily email (and `daily_email` to tune it). The host's
  limit is **200 emails an hour**; the daily email sends 150 an hour from 7am.
- Two cron jobs: housekeeping daily at 4am, and `scripts/daily-email.php` hourly (DEPLOY.md
  steps 9 and 10).

## Open items

1. **Messages:** the owner is reviewing `docs/MESSAGES.md` and will send wording changes.
   If they want to change wording often, the next step (already discussed) is moving the
   wording into two message files, one for the page and one for the server, so it can be
   edited without touching the logic.
2. **Missing words, next batch:** about 13,000 more candidates; regenerate with
   `scripts/find-missing-words.php` and follow `docs/dictionary-review/MISSING-WORDS.md`.
3. **Launch checklist** (`docs/LAUNCH.md`): rewrite `schema.sql` and drop the upgrade steps,
   drop `word_decisions`, a schema version, an email service (Brevo's free plan suggested),
   a privacy notice (account deletion is done), payments for the Host and Organiser plans,
   backups, delete `check.php`.
4. **Ideas raised but not done:** the admin sign-in lasting longer (the owner said leave it);
   message files (above); maybe more playtests (each one has produced a report in
   `docs/playtests/` with decisions at the top and a prioritised list of fixes at the end);
   a "Show all" link under the ten-row leaderboards, if the owner misses the longer list.
5. **Code-only-in-tests:** `Game::join` (nickname join from before accounts), `Dictionary::addWord`
   and `addToCategory`, and `Prompts::fitting` are only called by the tests, as fixtures. The
   owner chose to keep them.
