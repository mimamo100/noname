# Playtest: 20 minutes in The Big Room, on the local copy, 17 October 2026 (17:59 to 18:18)

The first round on the local copy (http://localhost:8082, XAMPP). Ada and Eve played through the real page in headless Chrome (`page.py`; Ada at phone width), Ben, Cal and Dee through the API; the owner played as Mimamo on their phone over the wifi. Two prompts: **#23 "Starts and ends with R"** (143 answers) and **#24 "Contains G and U, 5 letters or fewer"**. Dee led with 175. Agent reports: `unsaid-playtest/17-10-26/` (`-round10.md`, with Eve's screenshots).

## Holding up

- **The local copy works as the server does**: no errors for anyone; scores added up (Eve: 13 from words, 5 misses at -2, total 3; Dee's total matched her words); counts fell sensibly (143, 117, 104); the prompt multiplier rose (1.01 to 1.37).
- **The page at phone and desktop width**: clean, no sideways scrolling at 390 px; tabs, leaderboard, My words and My misses readable.
- **Messages**: already-said replies naming who and when, "Gone with it", other spellings (*rumor* after *rumour*), length messages, typos free.
- **No repetition** between the two prompts.

## Done during the round

- **A form that fits as its word is listed as it** (the owner, on *hugging* accepted on "5 letters or fewer"): it's said as *hug* in Just said and My words, and the reply reads "You said “hugging”, which counts as “hug”, for 1 point."
- **`page.py`** prints the page's special characters (it crashed on the minus sign), and answers OK to confirm boxes.

## Findings

Done the same evening: 1, 2 and 4 as suggested; 3, **the owner chose to make irregular verb forms words of their own**, as words go for the whole round; 6, the missing words added (not *quag*). 5 stays as decided. For 7, **the owner's decisions, to do next**: "Prompt ×" gets both a clearer label ("Points ×") with a line in How to play, and a tap to explain it; the "Recent prompt" card is dropped while that prompt is in the Earlier prompts list (kept for the start of a new round). Also done after the round: a player's first wrong word in the minute after a prompt changes is free, with a warning (the owner lost 2 points that way).

1. **Not a bug, but a misleading message.** Ben and Cal reported penalties on *running*, *rowing*, *rated*, *rarest*, *digging*, *bigger* as breaking "refused only for the form: free". Each fails **as written** too (*running* doesn't end in R), so the 2 points are right; *rarer*, which fits as written, was free. But the reply adds "(“running” counts as “run”)" even when that isn't why it failed, so players blame the rule. **Suggested**: mention what a word counts as only when that's the reason.
2. **A repeated wrong guess costs again** (Ada, Eve): *racing*, *rollers*, *rhubarb* each charged 2 every time. A player who taps Say it twice, or tries a word again, pays twice. **Suggested**: repeating your own miss on the same prompt is free.
3. **Irregular forms use up their word** (Dee): *sung* used up *sing*, *singing*, *sang*; *hung* used up *hang*, *hanged*. As decided today they stay one answer while counting as written; worth confirming now it's been seen in play.
4. **"Nobody said" shows forms** (Ben, Eve): *registered* on "Starts and ends with R" looks wrong (it counts as *register*). **Suggested**: on a letter prompt, show the word it counts as.
5. **"rarer" refused on "Starts and ends with R"** (Eve, Dee): right under the comparative rule (it counts as *rare*), free and clearly worded, but it'll feel harsh. As decided.
6. **Missing words**: *rafter* (though *rafters* is in), *referrer*; rarer: *rhymer*, *rubberneck*, *quag*.
7. **Smaller**: "Prompt ×1.25" isn't explained to a casual player (Ada, Dee); Ada saw "Every vowel is A, 7+ letters" as both the Recent prompt and under Earlier prompts (to check); the API's said list stops at 25 with no note (the kit only).

## The kit

Two page agents with Firefox open were fine. `click` takes the first matching text, so tab names that also appear elsewhere ("This prompt") can miss; a later improvement.
