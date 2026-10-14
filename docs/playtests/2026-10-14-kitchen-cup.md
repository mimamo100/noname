# Playtest: an organiser's own word pack, 14 October 2026

Eve (Organiser plan) made a word pack and ran a competition with it, all through the real page in Chrome; the owner watched from the watching link. **Kitchen Cup**: Eve's pack "Kitchen" on its own (3 lists, 80 answers: cutlery or crockery, ways of cooking, kitchen jobs), 20:00 to 20:30, prompts of up to 5 minutes, **Spoons** (Ada, Ben) v **Forks** (Cal, Dee). The four agents played through the API at a human pace. This was the first round on the code with results cards, phrase scoring, free team-mate words and the new account page.

Result: **Forks 902, Spoons 194.** Players: Dee 598, Cal 304, Ben 203, Ada −9. Six prompts: cutlery or crockery with no R, the same with no E, a way of cooking, a kitchen job, cutlery or crockery with no O, a kitchen job with no T.

## The owner's notes

- **Prompt lengths**: the choices (5 minutes, 15, an hour, 4 hours, a day) leave big gaps. Suggested: 5, 10, 15 and 30 minutes, 1 hour, 4 hours, 1 day, with a hint under the box ("About 6 prompts in your 30 minutes") and a default worked out from the start and end times (4 to 6 prompts). The setting is a maximum, as prompts end at 80% of their answers, so finer control buys little.
- **"Starts in 11m (Wed 14 Oct, 20:00), ends Wed 14 Oct, 20:30." is hard to read.** Suggested: "Starts today at 20:00 (in 11 minutes) and ends at 20:30."; the date only when it isn't today; "minutes" in full; "Starts in under a minute." near the start.
- **"It's on: ends in 30m." should say "Live"**: "Live: ends at 20:30 (in 26 minutes)".
- **A long prompt wrapping onto a second line** ("A piece of cutlery or crockery with no" / "R") looks wrong. Tried in Eve's tab: a smaller size for long prompts (`clamp(24px, 5.5vw, 40px)` for labels over about 30 characters) fits it on one line on a wide screen, and `text-wrap: balance` on `.prompt-card h1` (`public/style.css`) splits the lines evenly on a phone.
- **A pack this small gets repetitive**; it suits a shorter competition (see "Small packs" below).
- **Using the "Nobody said" list was a great trick** by the players (see "The same list twice in a row").
- **Organisers need helpful hints on putting a word pack together**: one answer per line with its other forms, enough answers per list for the competition's length, lists that don't overlap, and checking for the everyday words players will reach for first (*dish*, *pan*, *mix*). A short guide beside the editor, plus the summary after saving (see "Making a pack").
- **Players should learn to check before sending in small packs**: people forget that a word can only be said once in each world, so in a small pack they keep trying words said on earlier prompts and lose 2 points each time (Ada's −9). Ideas: a line at the start of a pack world ("Words stay said for the whole competition: try Who said it? if you're unsure"), and the list's words already said shown with the prompt when the same list comes round again.
- **A stray up/down arrow** at the right end of the tabs on Your account, in Chrome only (not in Firefox): the tab row's scrollbar showing.

## What worked

- **Your account** (`/account`) loads, shows "Organiser plan", and the Worlds tab shows the private world from yesterday.
- **Making a competition with a pack**: the pack appears under "Your packs" in Words, with "On their own" and "With everyday words too".
- **Before the start**: the page shows each team's link with its players as they join. **Moving a player** in Competition settings is instant, and the organiser sees "Ben Tester moved."
- **The results card**: "FINAL RESULT / Forks win! / Forks 902 · Spoons 194 / Well played Cal Tester, Dee Tester. / Top scorer: Dee Tester, 598 points." Team totals equal the players' sums.
- **A team-mate's word is free** (`teamMate: true`, no penalty), as decided.
- **Phrases**: *egg cup* = *eggcup*, *tea cup* = *teacup*, *table spoon* = *tablespoon*, *butter-knife* = *butter knife*, *barbeque* = *barbecue*; phrases show as written.
- **Words outside the pack cost nothing** (`not-a-word`, no penalty); words that break the letter rule are told why ("it has an R").
- After the end, `say` replies "This competition has finished." (HTTP 410), and `raw` has `myStanding` and `myResult`.

## Findings

### A bug: an accented letter breaks the player's view of the world
- Ben typed *sauté*. From then on, **every load of the world failed for Ben** (HTTP 500, "Something went wrong on the server"), from 20:11 to the end and still afterwards; saying words still worked. He played the last 19 minutes blind and never saw the result.
- Cause: a miss is stored as typed (`misses.word` is utf8mb4, so "sauté" keeps its é). Loading the world lists the player's misses and looks them up in `burns.word`, which is ASCII (`Game::missesOf`). MariaDB refuses the comparison: "Illegal mix of collations (ascii_bin,IMPLICIT), (utf8mb4_general_ci,COERCIBLE) for operation 'in'" (reproduced locally). Any accent or emoji does it (*café*, *naïve*, *jalapeño*), in any world, until 50 newer misses push the word out of the player's list.
- Fix ideas: only look up words of a to z in `missesOf` (the others can't have been said); and turn accented letters into plain ones when a word is typed (*sauté* → *saute*), so *sauté* counts as *saute*, *café* as *cafe*, which is what the player meant.

### Making a pack
- **Lines without a comma are split on spaces** (the old format). Eve wrote one answer per line, as most organisers would:
  - "wash up" became *wash* and *up*: "Please fix: “up” isn't a word of 3–30 letters a to z".
  - Silently, "egg cup" became one answer whose forms are *egg* and *cup*, so *cup*, *egg* and *egg cup* merged into one answer (and *egg* counted as crockery); likewise *milk jug* with *jug*, *sugar bowl* with *bowl*, *wine glass* with *glass*, and all three knives with *knife*.
  - The hint says "a phrase on its own takes a comma after it", which is easy to miss. Suggest: **one answer per line, and commas the only separator** (pack text already saved could be converted once).
- **Errors show in a browser alert box** (`app.js`, `alert(err.message)` in the pack editor and some other dialogs) rather than on the form. Show them on the form, in red, like the main page. (The alert also froze the tab for the testing tools until it was clicked.)
- **No view of what the pack became**: "Saved “Kitchen”: 80 words in 3 lists." A summary per list (answers counted, phrases, which lines were joined) would catch mistakes like the one above.
- **Typos are accepted silently**: any 3–30 letters a to z. A gentle "not in our dictionary, check the spelling?" for words that aren't everyday words would help (phrases and specialist words excepted).
- On the Word packs tab the New pack button jumps down when the list arrives (Eve's first click missed).

### Small packs
- **Scoring swings with time, not skill.** The world multiplier is 1 ÷ (share of the dictionary still unsaid). In the big world that barely moves; in an 80-answer pack (233 words with their forms) it rose from ×1.0 to ×2 by 20:21, ×4.2 at 20:24 and ×6.1 at the end. *Teaspoon* scored 16 at 20:08; *ramekin* 76, *steak knife* 87 and *butter knife* 90 after 20:24. All four players noticed and none could tell why. Ideas: no world multiplier in pack-only worlds, or a much lower cap, or one based on time.
- **Rarity is magnified.** In a pack, a word's rarity is its place among the pack's own words. Words close together in the everyday dictionary end up far apart (*milk jug* 17, *soup spoon* 7), and a phrase that isn't an everyday word ranks rarest (*deep fry* 17, *stir fry* 9). Consider using the everyday rarity directly in packs, or flattening it.
- **The same list twice in a row.** "Cutlery or crockery with no R" ran out of time and "Nobody said" showed 6 answers; the next prompt was "Cutlery or crockery with no E", and the players said the revealed answers (*milk jug*, *chopsticks*, *soup spoon*, *fork*): it ran dry in about a minute. Three of the six prompts were lists that had already come up, with a letter rule added, and words said earlier stay said, so the later ones started with 5 to 10 answers. Options: (1) don't use the same list twice in a row when the pack has others (suggested); (2) in small packs, hold back "Nobody said" until the list won't come up again or the end; (3) keep it as a skill.
- **Sizing**: by the end the pack was nearly used up (the last prompt left only *glaze*). Suggest the competition form says what a pack is good for when it's chosen ("Kitchen: 80 answers in 3 lists, enough for about 15 minutes") and warns when the times are much longer; the pack editor could show the same.

### Messages
- **Everyday words outside a pack say "not a word"** (*kettle*, *toaster*, *pan*, *banana*). In a pack-only world, say "*kettle* isn't in the Kitchen pack" (and offer to report it to the organiser).
- **Why a repeat costs 2 or nothing isn't visible**: free if it was your word, a team-mate's or said in the last minute; 2 points if an opponent said it earlier (Dee's *ladle*, said by Ben 20 minutes before). Players found it random. The message could say "*ladle* was said by Ben at 20:01: 2 points off" or "Cal said *cup* a moment ago: no points off". (The agents saw the API only; check what the page shows.)
- **Players aren't told when they're moved**: Ben was moved to Forks and back; nothing in his view said so.
- **Reports get no reply**: `{"ok": true}` only. The page could say "Thanks: the organiser will see it" for a custom pack.
- In Competition settings, Teams: "Opening it and saying a word joins that team." is out of date (opening the link joins now).

### The end
- Under the results card, the old box stays: "This competition has finished." and "Go to the big world". Redundant next to the card.
- The leaderboard stays on **This prompt**: "Nobody has tried this prompt yet. First in leads." Once finished, show Teams and hide This prompt.
- Sending a word after the end gets "This competition has finished." with no result; it could name the winners and the player's place. (In the API, `teams[].position` is the join order, not the rank, and there's no winner field.)
- The last prompt showed 306 s left when it began, just over the 5-minute maximum.

### Word lists (the pack's, not the game's)
Kitchen words the players expected and Eve's lists lacked: *dish*, *tray*, *saucepan*, *pan*, *colander*, *sieve*, *spatula*, *tureen*, *dinner plate*, *salad bowl*, *mixing bowl*, *cake fork*, *fish knife*, *butter dish*; jobs *mix*, *cook*, *wash*, *rinse*, *drain*, *wipe*, *pour*, *measure*, *carve*, *whip*, *fillet*, *washing up*; ways of cooking *griddle*, *slow cook*, *pan fry*. That's down to Eve's lists, but a kitchen pack made by a real organiser will have the same gaps: the reports feature (above) is how they'd find them. Also, *grilling* was refused for "A way of cooking" (only listed forms and plurals count for a list); for lists of verbs, the -ing form is a natural answer.

## Notes on the test itself
- The Create competition click was blocked by Claude's own permission check; the owner clicked it.
- Ada, the casual player, finished on −9: 4 words, about 50 points lost to 2-point repeats of words opponents had said earlier (not to everyday words, which were free). Dee, the methodical player, took most common words within seconds of a prompt starting.

## Suggested fixes, most valuable first
1. **The accent bug**: plain letters for accented ones when a word is typed, and only a-to-z words looked up in `missesOf`.
2. **The pack editor**: one answer per line (commas the only separator), errors on the form not in an alert, a summary of what the pack became, and a short guide to putting a pack together.
3. **Small-pack scoring**: no world multiplier (or a low cap) in pack-only worlds; everyday rarity for pack words.
4. **Prompts in small packs**: not the same list twice in a row; consider holding back "Nobody said".
5. **Messages**: a reminder in pack worlds that words stay said (check with Who said it?); "isn't in the Kitchen pack"; why a repeat costs or doesn't; "you've been moved to Forks"; a reply to reports; the out-of-date Teams hint.
6. **Wording the owner asked for**: the "Starts..." line, "Live", the long prompt on one line.
7. **Competition form**: 10- and 30-minute prompts, "about N prompts", what a pack is good for.
8. **The end**: drop the old "finished" box under the card; leaderboard on Teams; the result in the reply to a late word.
9. Smaller: the Chrome scrollbar arrow on Your account; the New pack button jumping; -ing forms for verb lists.
