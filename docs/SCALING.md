# Unsaid at scale

How the database and server load grow as players arrive, and what to do about
it, in priority order. The figures are rough estimates for planning, not
measurements.

## How each table grows

| Table | One row per | Growth | Rough size |
|---|---|---|---|
| `burns` | word said, plus each family member it uses up (about 2–3 rows per word) | **Capped per world**: a world can never hold more than the dictionary (about 32,700 rows) | about 5–8 MB for a world whose whole dictionary is used up; most private worlds use a few hundred KB |
| `misses` | wrong guess | **Grows forever** | about 150 bytes each: 10,000 players × 10 misses a day ≈ 15 MB a day, 5 GB a year |
| `players` | account in a world | Slow | tiny |
| `prompts` | prompt (about one a day per world) | Slow | tiny |
| `users`, `sessions` | account, signed-in device | Slow | tiny: 100,000 accounts ≈ 30 MB |
| `login_codes` | sign-in email | **Grows forever** | small, but it stores emails and network addresses (personal data) |
| `reports`, `word_decisions` | report, admin decision | Slow | tiny |

So the database stays small, except for `misses` and `login_codes`, which only
need tidying (see 3 below). Most cPanel plans allow 1–10 GB of databases.

## 1. The public world runs out of words (decided)

A world has about 21,500 distinct answers. With many players, one shared world
burns through them fast:

| Active players a day | Words said a day (20 each) | One world used up in |
|---|---|---|
| 50 | 1,000 | about 3 weeks |
| 500 | 10,000 | about 2 days |
| 10,000 | 200,000 | a few hours |

So the game has two kinds of public play (see DESIGN.md, "Worlds"):
- **The big world** starts afresh every day, with bigger, shorter prompts, so
  running out within the day is part of the fun.
- **Rooms** of up to 60 players hold the slow game where words feel scarce,
  lasting a few weeks each. New rooms open as players arrive, up to a limit
  (50 by default) that protects the server.

One limit to know: plays in one world are handled one at a time, so a single
world takes a few dozen words a second at most, roughly 1,000–2,000 people
playing hard at the same moment. That's fine for rooms; for the big world
it's the ceiling before it would need splitting.

Each finished round or room keeps a small results row per player for good,
plus all its detail. A busy big world round could add about 5 MB of detail a
day, so deleting detail older than about 30 days belongs with the
housekeeping below.

## 2. Polling is the real server load (mostly done)

Each open game page asks the server for the world's state every few seconds.
That is where the server's work goes, so the game already does the following:

1. **One answer shared between viewers.** Each world's state is kept in a
   small file in the `cache` folder (next to `config.php`) for 2 seconds, so
   100 people watching a world cost one database run every 2 seconds, not 100.
   Every world has a version number that goes up whenever a word is said, so
   a new word shows straight away rather than after the 2 seconds.
2. **Running totals instead of recounting.** Each player's score and word
   count are stored and updated as they play, so the leaderboard no longer
   re-adds every word and miss in the world. (If you edit the `burns` or
   `misses` tables by hand, press **Recount scores** on the admin page.)
3. **Short "nothing changed" answers.** The page says which version it has,
   and if nothing is new the server replies in 67 bytes instead of about
   20 KB. Pages also check less often when the world is quiet (every 6, then
   12 seconds) and every 30 seconds in a background tab.
4. **OPcache.** `check.php` says whether it's on. Without it, every request
   rereads the 1.8 MB dictionary (40–260 ms). Turn it on in cPanel > Select
   PHP Version > Extensions if it's off.

Measured on a test world with 15,000 words said, 300 players and 40,000
misses (Apache, OPcache on):

| | Before | Now |
|---|---|---|
| A poll | about 140 ms, 22 KB | about 15 ms, 22 KB |
| A poll with nothing new | the same | about 9 ms, 67 bytes |
| Rebuilding a world's state (at most every 2 s) | about 115 ms | about 55 ms |

So shared hosting should cope with a few hundred players online at once; by
then most of each request's cost is PHP starting up and checking the
session. What's left for later: the rebuild still reads all of a world's said
words, which grows with the world, and polling itself could be
replaced by pushed updates, which needs a VPS (from about £5–20 a month). The
code is plain PHP and MySQL, so it moves without changes.

## 3. Housekeeping

- **Old sign-in codes:** delete codes older than a day. They also hold
  network addresses, which are personal data.
- **Expired sessions:** delete them.
- **Finished rounds and rooms:** after about 30 days, delete their words,
  misses and prompts, keeping the results rows that My games shows.
- **Misses:** keep the last 30 days, or only those for recent prompts (My
  misses shows the last 50 anyway). This keeps the biggest growing table in
  check.

This can run on a cPanel **Cron Job** once a day, or a little at a time during
normal requests.

## 4. Email

Shared hosting usually allows a few hundred emails an hour. Each new device
needs one, so a burst of sign-ups (say, after a mention online) could hit the
limit and leave players without codes. Switching to an email service (Brevo,
Amazon SES) is a settings change in `config.php`, and is worth doing before
any promotion.

## 5. Personal data (UK GDPR)

Accounts mean you store email addresses, so the game needs:
- a short **privacy notice**: what's stored (email, name, games played),
  why, and for how long
- a way to **delete an account** (on request at first, a button later)
- the housekeeping above, so codes and addresses aren't kept longer than needed
- backups that are kept safe, since they contain emails.

## 6. Backups

As players invest time, losing the database would hurt. cPanel's **Backup**
or a scheduled database export, kept off the server (for example downloaded
weekly), covers this.

## Suggested order

1. Now, while small: housekeeping (3) and a privacy notice (5). Both are small.
2. Before promoting: switch to an email service (4).
3. When shared hosting strains: move to a VPS (2).
