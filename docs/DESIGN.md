# Unsaid — Design

> Every word you play, no one can say again.

A word game where each world starts with the same dictionary, and every word a
player uses is permanently removed from it. Easy words go first; as the
language shrinks, players hunt for rarer words, and those words are worth more.

## The dictionary

- Every world starts with the same fixed list: about 39,600 common English
  words (3+ letters), built by `scripts/build-dictionary.php`.
- A word must be in both the ENABLE word list (no proper nouns, no
  abbreviations) and a large frequency list from film subtitles, so obscure
  word-list junk is excluded.
- Words are ranked by how common they are. The rank drives the rarity bonus.

## Worlds

- **Asynchronous.** Nobody waits for other players. You drop in whenever you
  like and play against the world's current state.
- **The big world.** One world for everyone signed in: the introduction to
  the game, fast and busy. It starts afresh every day at midnight UK time
  (configurable down to every hour). Each round is its own world (id
  `p` + its start time, such as `p202610120000`); the address `/` and
  `/api/worlds/big` always mean the current round. Its prompts are bigger
  (60–400 answers) and shorter (15 minutes), and the last prompt stops when
  the round does. The page counts down to the next round and shows the last
  round's top three.
- **Rooms.** Worlds of up to 60 players where words feel scarce: about 50
  active players use a room up in roughly three weeks. "Join a room" puts you
  back in your own room, or in the busiest room with a free seat (skipping
  rooms more than three quarters used up), or opens a new one, up to 50 at
  once. A player who hasn't guessed for 7 days shows as *away* and their seat
  is free for someone new; their words and points stay. If they come back and
  there's still a seat, they carry on; if not, they're offered a new room. A
  room finishes when it runs out of words, and its players move on to new
  rooms. Rooms can only be joined this way, not by link.
- **Finished worlds and My games.** When a round or room finishes, every
  player who said a word or lost points gets a results row: place (shared on
  equal scores), out of how many, score, words and best word. **My games**
  shows these, newest first, with personal bests (games, wins, best finish,
  best score, most words, words said in all, best word). A finished world
  stays viewable: a **results card** takes the prompt's place ("Foxes win!",
  the team scores and the winners' names, or the winning player; the top
  scorer; "You finished 3rd of 4. Owls came 2nd of 2."), with a short shower
  of confetti for the winners, once per device. **Sharing:** a Share button (in
  My games, on the finished world's page, and by the big world's last round)
  copies a short result to paste anywhere, or opens the share sheet on a
  phone (titled "Unsaid: my result in …", the subject in an email): the
  world, place, points, words and best word (masked words as p***s), a line
  saying what Unsaid is, and a link to watch the world (or to the game, for private worlds
  and competitions, which need an invite). All the detail (who said what)
  is kept too; deleting it after about 30 days is a future housekeeping job.
- **Private worlds.** For a group of friends. Each world uses up its own
  copy of the dictionary. See "Plans and private worlds" below.
- **Competitions:** private worlds
  with teams and a fixed start and end. See "Competitions" below.
- **Accounts.** Everyone needs a free account to play, join or create
  worlds; anyone can watch. See "Accounts" below.
- **Each kind of world has its own look**, so players know where they are: the
  big world is orange, rooms green, private worlds teal and competitions
  purple. Each has a banner above the prompt and that colour behind it. The big
  world's banner has its name, what it is under an (i), and when the round
  ends; the others' have the world's name, what kind of world, its words and
  spelling, and Back to the big world. A world playing a word pack shows the pack's name as a tag.

## How you play

1. The world always has one **current prompt**, a letter pattern such as
   *contains "OO"*, *starts with "ST"* or *ends with "ING"*.
2. You type words. A word is accepted if it:
   - is in the dictionary,
   - fits the current prompt, and
   - hasn't been said yet.
3. An accepted word scores points, and **nobody in that world can say it
   again**, including in all future prompts. Its **word family** goes with it,
   so saying *cat* also uses up *cats*.
4. The game records who said each word ("*moon* — said by Sam").
5. Each prompt shows a **live counter**: "41 words left unsaid".
6. The prompt changes when its counter reaches zero, or after its time runs
   out (24 hours by default).

Because said words stay said, later prompts begin with many of their fitting words
already gone, so the game gets harder as the world ages.

## Naming in the code

The code and database call a said word "burned" (for example the `burns`
table). Players never see that word.

## Scoring

```
points = base × rarity × world multiplier × prompt bonus
```

| Part | Rule | Range |
|---|---|---|
| Base | word length − 2 | 1 for a 3-letter word, 8 for a 10-letter word |
| Rarity | 1 + 2 × (frequency rank ÷ dictionary size) | ×1 for the most common words, ×3 for the rarest |
| World multiplier | 1 ÷ (1 − share of the dictionary said) | ×1 at the start, ×2 at half said, ×5 at 80% said (capped at ×10) |
| Prompt bonus | 1 + the share of the prompt's answers already said | ×1 when the prompt starts, ×1.5 at half said, capped at ×2; *also* answers score at ×1 |

Points are rounded to whole numbers, with a minimum of 1.

Scarcity drives the multipliers, not the clock. A world that sits quiet for a
week doesn't get easier to score in.

**Holding words back.** Because multipliers rise, a player can save a word
they know and play it later for more points, at the risk that someone else
says it first. This is intended.

**Balance to watch in testing.** One late, rare word shouldn't outscore a whole
day of early play. The caps above are the first lever to adjust.

## Word families

A word's family is found with simple suffix rules: plurals (*-s*, *-es*,
*-ies*), *-ed*, *-d* and *-ing*, including doubled consonants (*running* →
*run*). A family is only formed when the root is also in the dictionary.
Words the rules would join to an unrelated word (*bunting* the bird isn't
formed from *bunt*) are listed in `data/family-breaks.txt` and start their own
family.
The rules are approximate. Some odd pairs will be grouped together and some
real pairs missed. That's acceptable for the prototype.

## Prompts

Prompts have to make players think, not just type a pattern into a
word-finder site. There are three kinds:

| Kind | Share | Examples |
|---|---|---|
| Meaning and letters | about half | *An animal starting with B*, *A feeling containing U*, *A family member of 8+ letters* |
| Meaning only | about a fifth | *A bird*, *A musical instrument*, *Weather* |
| Letters only | the rest | *Letters in alphabetical order, 6+ letters*, *Starts and ends with N*, *Two Ys, no L* |

- **Meanings** come from 46 categories (animals, foods, jobs, feelings,
  colours and so on), kept by hand in `data/categories/`, one file each.
  They were first drafted from WordNet (`scripts/build-categories.py` still
  drafts new ones) and then reviewed. A word counts when its main meaning
  fits: *fly* is an insect, but *does* isn't an animal just because a doe is
  a deer.
- **Any meaning counts for accepting an answer.** *Squash* is a sport even
  though WordNet lists the vegetable first. Only words whose *main* meaning
  fits are counted in "words left unsaid" and used to size prompts, so
  obscure meanings don't inflate the counter.
- **Corrections** are made in the category's file. The aim is every answer
  a player is likely to think of, since a missing one is frustrating and
  becomes a suggestion to approve. Accepting a surprising answer is better
  than rejecting a sensible one.
- **Size.** A new prompt is only chosen if it has 10–80 distinct answers still
  unsaid, counting a word family once. Late in a world, when nothing fits
  that range, the game takes the richest prompt it can find.
- **Plurals count as their singular** for length rules and points, so
  adding an S can't stretch *brother* into an 8-letter answer or earn extra
  points. Spelling rules (*starts with*, *contains*) check the word as typed,
  and the game never sets letter rules an S would satisfy for free ("contains
  S", "two Ss"). Only plurals: *-ing* and *-ed* words such as *building* and
  *evening* are often words in their own right.
- **The counter** shows distinct answers left, so saying *apple* (which also
  uses up *apples*) takes it down by one.

## Penalties and limits

- A guess that **doesn't fit** the prompt, or has **already been said** by
  someone else, costs 2 points. Pasting a list from a word-finder site mostly
  hits words that are already said, so it loses points.
- **Repeating your own word is free.** "Already said" is checked before the
  meaning and letters, and the message says when the word was said and for
  which prompt, since that may be several prompts ago and out of sight.
- **A prompt ends once 80% of its answers are said** (`Game::END_SHARE`), or
  when its time runs out, rather than running on with only obscure answers
  left. In a world with an end time, no prompt starts in the last 5 minutes
  (`Game::LAST_PROMPT_MS`): the one before runs on to the end. The answer that ends it earns a flat 10 points (`Scoring::CLOSING_BONUS`),
  marked 🏁 in the feed. The page says "Ends after N more or in 2h".
- **Phrases score by their words:** a two-word answer scores its longest
  word's base points plus 1 for each other word (`Scoring::base`), so
  *paddle steamer* isn't worth more than *battleship* for being joined up.
- **The prompt bonus is gentle:** ×1 plus the share of the prompt's answers
  already said, so ×1.5 at half and at most ×2 (it was answers at the start ÷
  answers left, up to ×5, and late words swamped early ones: playtest, 13 Oct).
  Answers a category accepts without counting (*also* words) score at ×1, as
  they don't bring the count down; the count says "At least N words left".
- **Penalties are only for real mistakes:** the letter rules are checked
  first and the message names the broken rule; "already said" is free when
  someone else said it in the last minute (`Game::SAID_GRACE_MS`), or a
  team-mate said it in a competition; the other spelling of a word already
  said is the same answer, and the message says so; and for 30
  seconds after a prompt changes, a word sent for the old prompt is judged
  against it, free if it misses (`Game::PROMPT_GRACE_MS`; the page sends the
  prompt it showed).
- **Spaces, hyphens and apostrophes are dropped** from what players type, so
  "tea towel" and "T-shirt" play as *teatowel* and *tshirt*. The word files
  list phrases the way people write them ("tea towel", "t-shirt", "habeas
  corpus", "rock and roll"); the game stores and compares the joined form,
  but **shows the written form** wherever a word reaches players: the feed,
  Just said, Nobody said, Who said it?, My misses, the messages after a
  guess, and best words in My games and shared results. The build keeps a
  map from joined to written form (`display` in `data/dictionary.php` and
  each `data/packs/<id>.php`; custom packs build their own), and
  `Dictionary::display()` looks words up in it. Masking is decided on the
  joined word, and a masked word is still shown masked.
- **Typos are free**: a word that isn't in the dictionary costs nothing.
- **Gaps in our lists are free too.** The game can't tell a real answer
  missing from its list from a wrong one, so it gives the benefit of the
  doubt: these guesses cost nothing but don't score either. If a word has the right letters but
  isn't on our list for the meaning ("sofa" for *A sport or game with no R*),
  it costs nothing, and the player can press **Report it**. Words missing
  from the dictionary can be reported the same way. Reports are a queue for
  the word files, which are the only source of truth: the admin downloads
  them, approved words go into `data/categories/`, and they count once the
  rebuilt dictionary is uploaded. My misses then shows each report as
  accepted (the word counts now) or reviewed.
- Players can make at most **20 guesses a minute**, which stops scripts.
- The leaderboard has two tabs: **Overall** ranks everyone by total score,
  and **This prompt** ranks only the current prompt, so everyone starts level
  on each new prompt and a late arrival can still come top. Penalties count
  in both. Each shows its top ten; a player further down sees a ⋯ and then
  their own row, with their place.
- **Just said** lists every word said in the current prompt, newest first,
  with the three prompts before it collapsed underneath. Any older word can be
  found with **Who said it?**.
- **Nobody said:** when a prompt ends, the page shows what was left unsaid:
  how many answers, and the 12 commonest (one word per family, masked words
  counted but not shown), so players see the "oh, of course!" words they
  missed. It's noted on the prompt as it ends (later prompts use words up
  too) and shown under **Recent prompt** (the latest one someone played) and with each earlier prompt in the
  feed. The feed shows three earlier prompts that had words; early in the big world's new round
  it makes them up with the last round's, marked "last round".
- **My misses** lists a player's own wrong guesses (with the prompt, the
  reason and any penalty, plus what happened to any report: waiting,
  accepted or dismissed. An accepted word that has since been used up, for
  any prompt, says who used it and where). It's private: other players can't see your misses,
  because "close" guesses would give them clues, and public mistakes would
  make people afraid to try things.

## Accounts

- **Streaks:** the days in a row an account has said a word, anywhere (UK
  days, matching the big world's rounds), and its best run. Kept on the
  account, as words are tidied away a month after a world finishes. The day's
  first word says "That's 5 days in a row!", and My games shows both.
- **Daily email** (off unless a player turns it on, in the account menu):
  sent in hourly batches from 7am (an hourly cron job, keeping under the
  host's hourly email limit), to those who haven't played yet that day, with their streak and how they did in
  the last round. Each email has a link that turns it off without signing in.

- **Signing in** is by email code: enter your email, get a 6-digit code, type
  it in. New players also choose a name, unique across the game and shown in
  every world ("Ann 2" if an old player there already has the name).
- **Once per device.** The device then stays signed in for a year, renewed
  whenever the player plays, so most players get one email per device.
- **Limits:** 3 codes per email and 10 per network address an hour, 5 tries
  per code, and codes expire after 15 minutes. Only hashes of codes and
  session tokens are stored.
- **Players from before accounts** are claimed automatically when they sign
  in on the same browser, keeping their nickname and scores.
- **World creators** are recorded as owners, ready for owner controls.
- Emails go through the server's own mail by default, or any SMTP service
  (`src/Mailer.php`).
- Not yet: a "prove you're human" check (Cloudflare Turnstile) at sign-up,
  if bot sign-ups appear.

## UK and US spelling

- **One answer, either spelling.** *Colour* and *color* (with *colours*,
  *colored* and so on) are one answer: saying one uses up the other. The pairs
  come from VarCon (`scripts/build-spellings.php` makes `data/spellings.json`),
  and whichever spelling the word list lacks is added (about 400 words).
- **Not every pair is linked.** Spellings that are also everyday words on both
  sides stay separate, so *tired* doesn't use up *tyre* and *check* doesn't use
  up *cheque*. Extra links, like *grey*/*gray*, go in `data/spelling-links.txt`.
- **A choice per private world:** UK and US (the default, and the public
  world), UK only, or US only. In a UK-only world, *color* gets a free
  "This world uses UK spelling: try 'colour'". Counters leave out spellings
  the world doesn't accept.
- **Missing spellings** can be reported like any missing word.

## Offensive words

Two lists, both hand-kept:

- **Blocked** (`data/blocklist.txt`): slurs, strong swearing, sexual crimes
  and the most explicit acts. Blocked words, and the words formed from them
  (*fucking*), are removed from the dictionary, so they can never be played,
  appear in a prompt or show in the feed. One exception: a **curated pack**
  may list blocked words it needs (the law pack's crimes, such as *rape* and
  *incest*). They're playable in that pack's worlds only, and nowhere else.
  Organisers' own packs can't list them, and no world lets one into a
  nickname.
- **Masked** (`data/masked.txt`): milder rude words, body and sex terms and
  sex work (*arse*, *bitch*, *penis*, *nipple*, *porn*, *whore*). They're
  ordinary answers: playable, scoring as usual, counted in "words left
  unsaid" and listed in categories where they fit (*penis* in "A part of the
  body"). But other players see them as *p\*\*\*s* in Just said, with a
  dotted underline: tapping shows the word. Who said it? answers normally
  (you typed the word). Saying a masked word someone else has already said is
  free, as they only saw it masked. Innocent words formed from a masked one
  stay visible with a `!` line (*!cocked*, *!pricked*).

Both lists cover the words formed from a listed word, but never go the other
way: *pubes* doesn't block or mask *pub*. Neither kind can appear in a name,
and the fallback prompt never picks a letter string that is one ("Contains
ASS"). Words that are innocent in their main meaning (*spade*, *queen*,
*pansy*, *hoe*, *bimbo*) are ordinary words.

## Prototype scope

In the first version:
- Public world plus private worlds by link
- Nickname join, word submission, word families, scoring, leaderboard
- Live counter, recently said words, "who said it?" lookup
- Meaning, letter and combined prompts, guess penalties, rate limit, offensive-word filter
- Prompt rotation on exhaustion or timeout

Not yet:
- Accounts, seasons, sharing cards, mobile polish

## Open questions

- Should private worlds end too, and could their owners start them afresh?
- Is 24 hours the right prompt length for small friend groups?

## Plans and private worlds

Three tiers of account. Until payments are added, the admin sets an
account's plan on the admin page.

| Plan | Can |
|---|---|
| **Free** | Play the big world and rooms; join private worlds and competitions they're invited to |
| **Host** (first paid tier) | Also create private worlds: up to 3 open at once |
| **Organiser** (second paid tier) | Also run competitions with teams |

**Creating a private world:** name, spelling (UK, US or both), pace
(prompts of 1 hour, 4 hours or a day) and an optional player limit.

**Invite links.** A private world's invite link is its address plus a code
(`/w/abcd1234?invite=…`). Only its players and people with the current link
can see or join it. Its players can copy the link to invite others.

**Owner controls** (a settings panel only the owner sees):
- rename; change the pace (for prompts from then on); set or change the
  player limit
- **new invite link**: the old one stops working, so a link shared too
  widely can be shut; existing players carry on
- **close to new players**, and open again
- **remove a player**: their words stay used up (taking them back would
  unravel everyone else's scores), but they drop off the leaderboards, and
  can't see the world or rejoin it
- **end the world**: it finishes like a room, with final standings and
  entries in My games
- **delete**: only once it has ended, or if nobody else has played.

**Limits:** a Host can own 3 open private worlds at once (ended ones don't
count). A private world with no guesses for 60 days ends automatically
(housekeeping). Worlds created before plans existed keep working.

**My worlds** (next to the player's name) lists every world they're in: the
big world, their room, and their private worlds, with their score, the
number of players, how much is left and when someone last played.

## Competitions

For organisers: a private world with teams, such as a quiz night, a class or
an office contest.
- **Set-up:** name, spelling and pace (5-minute prompts suit a live event,
  longer ones a week-long contest), a **start and end time**, and **teams**
  (2–20) with a team size limit (say 8), so teams are roughly even.
- **Each team has its own invite link**: "send this to your team" is all
  players need to do. The organiser can move or remove players until the
  start.
- **Before the start**, the page shows the teams forming and a countdown; at
  the start the first prompt appears for everyone at once.
- **Play** is as usual: a word said by anyone is gone for everyone, so teams
  race each other for the good answers. A team's score is the **total** of
  its players' scores. Leaderboards for teams and for individuals (each with
  "this prompt" and overall), with each player's team shown, in team colours,
  and who said each word visible to all.
- **At the end**, final team and individual standings, in My games too
  ("Team Owls, 2nd of 6"). A competition nobody looks at after its end is
  finished by the nightly housekeeping, so its results are still recorded.

As built:
- **Creating** (Organiser plan, up to 3 planned or running at once): name,
  start and end (up to 31 days apart), prompt length (5, 15 or 60 minutes,
  4 hours or a day), 2–20 team names, an optional team size (up to 50), and
  spelling. Prompts of 15 minutes or less get 30–200 answers, so a busy room
  doesn't empty them at once.
- **Links:** each team's link joins that team (a "Join Owls" button before the
  start, or simply saying a word once it's on). A separate watching link lets
  people follow without playing. Only the organiser is shown the links, so
  they decide who gets them (players aren't offered their team's link to
  pass on). Late joiners can join a team with room until the end.
- **Players can't switch teams** by opening another team's link; the
  organiser can move them before the start, and remove them at any time.
- **Organiser controls** (Competition settings): rename; before the start,
  change the times, pace and team size, add, rename or remove teams; after
  it, move the end; make a new link for any team; end now; delete (before
  the start or after the end). The organiser only plays if they join a team
  through its link.
- **On the page:** a countdown and the teams forming before the start; a
  Teams tab on the leaderboard (team, players, this prompt, total); a colour
  dot for each player's team on the leaderboards and in Just said.

## Word packs

Themed vocabularies for specialised games: a science department, a law firm,
a music quiz.

- **Curated packs** (Science, Law, Music) are kept by hand in `data/packs/`
  (see its README), drafted by Claude and approved by the owner. Hosts and
  organisers can use them. Each line is one answer, its forms separated by
  commas ("black hole, black holes"); a form may be a phrase, shown as
  written. They may list blocked words they need (see "Offensive words").
- **Organisers' own packs** are written on the site (menu: My word packs): lists
  separated by blank lines, each a prompt line ("A planet") then one word per
  line, with other forms of a word on the same line. A line with a comma is
  split on commas, so it can hold phrases ("black hole, black holes"); a line
  without one is split on spaces ("quark quarks"), as packs were first
  written. Blocked words are refused. At least 30 answers, at
  most 5,000. Only their owner can use them. Words players report in a custom
  pack's worlds are shown to its owner in the pack editor, not to the admin;
  the admin page lists custom packs and can remove one (its worlds then play
  everyday words).
- **Choosing:** when creating a private world or competition, "Words" offers
  everyday words or a pack, used **on its own** (the pack is the whole
  dictionary; prompts of 5–40 answers; everyday words aren't accepted) or
  **with everyday words** (prompts come from the pack's categories, letter
  prompts from all the words). The world's name shows the pack ("Chambers ·
  Law words").
- **No names** of people (bands, composers, scientists), nor open-ended sets
  of named things (stars, moons, constellations): the lists would never end,
  and there'd be no fair way to choose. Units named after people (*newton*)
  are ordinary words, and the planets are a small closed set, so they're in.
- **Obscure terms go in a category's `also:` list**: accepted and scoring, but
  not counted in the prompt's size or the words left unsaid.

## Ideas for later

- **Account names:** suggest alternatives when a name is taken ("Ann 7");
  let players change their name; let the admin rename an account or merge an
  old unclaimed player into one (for someone who switched browsers before
  signing in); and pre-fill a new account's name with the old nickname this
  browser played under.

- **Payments** for the Host and Organiser plans (for example Stripe or
  PayPal), setting the account's plan automatically.

- **Undo and take back:** undoing an accepted word currently only affects
  the future; anyone who already said it keeps the points, and it stays used
  up in that world. A stronger admin option could remove those points, free
  the word, and note it in the feed ("*sofa* — removed by the admin"). Only
  worth adding if accepted words are ever abused.

- **More word packs** (the mechanism is built; see "Word packs"): themed dictionaries such as Music,
  Food & cooking, Sport, Nature, Film & TV and Science, chosen when creating a
  world. Draft lists can come from WordNet topic tags (about 300 music words,
  950 food words, 450 sport words), but they need main-meaning filtering and a
  manual review. Small packs need smaller prompt ranges (about 5–30 answers),
  and could end with a prize for whoever says the last word.
- **Custom packs:** the world creator pastes their own list, such as office
  jargon, a class spelling list or song titles.
- **Names packs** (artists, bands, labels): a separate source, multi-word
  matching, and regular updates. Harder, and best left until later.
- **A look that fits the name:** the current dark style with orange "ember"
  accents came from the old name. Possible directions: quiet and literary
  (paper and ink), or dark with cooler colours.
