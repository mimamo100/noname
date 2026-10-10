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

## 1. The public world runs out of words (a game design issue)

A world has about 21,500 distinct answers. With many players, one shared world
burns through them fast:

| Active players a day | Words said a day (20 each) | Public world used up in |
|---|---|---|
| 50 | 1,000 | about 3 weeks |
| 500 | 10,000 | about 2 days |
| 10,000 | 200,000 | a few hours |

Each prompt (10–80 answers) would also last minutes rather than a day. The
game is at its best when a world belongs to a group small enough for words to
feel scarce but not instantly gone.

Options, which can be combined:
- **Public rooms:** several public worlds, each with room for, say, 50 active
  players. New players join the least crowded one, and a new room opens when
  all are full.
- **Seasons:** public worlds reset weekly or monthly, with a final leaderboard.
  A natural ending, and a reason to come back.
- **Daily world:** one fresh public world a day, the same for everyone, like
  Wordle.

This matters more than any technical issue, and is worth deciding before
promoting the game widely.

## 2. Polling is the real server load

Each open game page asks the server for the world's state every 3 seconds.
Each request loads the dictionary, the world's said words, counts what's left
for the prompt and builds the leaderboard: about 10–30 ms of server work.

- 20 players with the game open ≈ 7 requests a second: fine.
- 300 players with it open ≈ 100 requests a second: more than shared hosting
  allows. cPanel plans limit simultaneous PHP processes ("entry processes")
  and CPU.

Fixes, roughly in order of value:
1. **Share one answer between viewers.** Cache each world's state for about
   2 seconds, so 100 viewers of a world cost one computation, not 100.
2. **Keep counts instead of recounting:** store each prompt's "left unsaid"
   count and each player's score, updated when a word is said, instead of
   recounting every request.
3. **Poll less when nothing happens:** slow to every 10–15 seconds when the
   page is in the background or the world is quiet, and send "nothing
   changed" answers that cost almost nothing.
4. **Make sure OPcache is on** (cPanel > Select PHP Version), so the 1.5 MB
   dictionary is kept in memory rather than reread every request.

With 1–3 in place, shared hosting should cope with a few hundred players
online at once. Beyond that, move to a small VPS (from about £5–20 a month),
where live updates (pushing changes rather than polling) also become possible.
The code is plain PHP and MySQL, so it moves without changes.

## 3. Housekeeping

- **Old sign-in codes:** delete codes older than a day. They also hold
  network addresses, which are personal data.
- **Expired sessions:** delete them.
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
2. Before promoting: decide the public world design (1), and switch to an
   email service (4).
3. When more than about 50 players are online together: caching and
   counters (2).
4. When shared hosting strains: move to a VPS.
