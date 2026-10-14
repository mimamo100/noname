# Before the first live release

Things to do once, when development settles and the game goes public. Tick them
off here as they're done.

## Code and database

- [ ] **Rewrite `schema.sql`** to describe the tables as they are now, so a new
  installation is complete in one go. Today it still describes the first
  version, and `Game::installSchema` brings it up to date with a growing list
  of upgrade steps.
- [ ] **Remove the upgrade steps** from `Game::installSchema` once the live
  database has them all (it will, by then). Check first: build a fresh
  database from the new `schema.sql` and compare it with the live one
  (`SHOW CREATE TABLE` for each table), so nothing is lost either way.
- [ ] **Drop the `word_decisions` table**: left over from accepting reported
  words on the admin page, before reports became a queue for the word files.
  Remove `Admin::legacyAccepted()` and its notes on the admin page and in the
  download with it.
- [ ] **Add a schema version number** (in the `meta` table), so later changes
  are numbered upgrades that each run once, instead of checks kept forever.

## Server

- [ ] The **housekeeping cron job** is set up (DEPLOY.md, step 9) and the admin
  page shows it ran in the last day.
- [ ] **OPcache** is on (`check.php` says so).
- [ ] **Delete `public/check.php`**: it shows server details.
- [ ] The **admin page** has a long password and an `admin_path` nobody would
  guess.
- [ ] **Backups** of the database are scheduled and kept off the server.

## Players and the law

- [ ] **Sign-in emails** go through an email service (Brevo, Amazon SES), not
  the server's own mail, so a burst of sign-ups doesn't hit the host's limit
  (SCALING.md, section 4).
- [ ] A **privacy notice**: what's stored (email, name, games played), why, and
  for how long (SCALING.md, section 5). Deleting an account is done: Your
  account, Settings.
- [ ] **Payments** for the Host and Organiser plans, setting the plan
  automatically (until then, the admin sets plans by hand).
