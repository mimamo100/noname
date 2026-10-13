# Playtest: a team competition, 13 October 2026

The owner set up a competition, "Claude Comps": two teams, **Owls** (Ada, Ben) and **Foxes** (Cal, Dee), everyday words, 18:00 to 18:40 with 15-minute prompts. The four agents played through the API at a human pace for the whole 40 minutes; Eve watched through the watching link in Chrome at phone width. This was the first round on the new code (gentler scoring, prompts ending at 80%, the fairer penalties, the page fixes) and the new dictionary (49,400 words).

Result: **Foxes 1,637, Owls 1,106.** Players: Cal 927, Dee 710, Ada 610, Ben 496. Prompts: "Starts with L, 8+ letters, no A" (150 answers), "A vehicle of 9+ letters", "A metal of 8+ letters", "Has a double G, 7+ letters".

## The owner's notes

- **Watching from the sidelines is a great feature**: exciting to follow.
- **Not much celebration for the winners.** At the end the prompt card says "Finished" with leftovers ("At least 0 words left unsaid", "World ×1.0, Prompt ×1.0", "Ends in –"), the box says "This competition has finished." and "Go to the big world", and the result is only in the team table lower down. Players who send a word get "This competition has finished." with no result. Ideas: a results card in place of the prompt card ("Foxes win! 1,637 to 1,106", the winners' names, the best player), a short celebration for the winners, "You finished 3rd; Owls came 2nd" for each player, and a "Share the results" link.
- The owner moved players between teams before the start, to test the organiser's controls.

## The owner's decision

- **A word your own team already said is free**, like repeating your own word (which is already free). Only an opponent's word, said more than a minute before, costs 2 points.

## What worked

- **Team scores always added up**: each team's score and word count equalled the sum of its players', penalties included, at every check.
- **The new scoring is much gentler**: the prompt bonus peaked at about ×1.7; points per word ran from about 7 to 38. No runaway leader from one prompt.
- **"Free if someone said it in the last minute"** worked.
- **Start and end were clean**: before the start the page counts down and shows the teams forming; a word about 2 seconds before 18:40 counted, one 2 seconds after got "This competition has finished."; final standings appeared at once.
- **The watching view is clear** (Eve at phone width): the banner "Claude Comps · Competition · Everyday words" in its own purple accent, "You're watching. To play, open your team's invite link", the teams and their players before the start, no word box for watchers (only Look up), the team table first, and each player's name in the feed with a dot in their team's colour. "Scores include 2 points off for each wrong guess, so they can go down" explains falling scores.
- The new prompt card ("At least 135 words left unsaid", "Ends after 105 more or in 13m") read well.
- Prompt-ending answers scored their normal points plus 10 (*europium* 41): as designed (a flat 10 instead of doubling).

## Findings

### Teams and competition flow
- **Joining with the other team's link fails silently**: it returns success with no message (also after the end), and quietly keeps you on your team. Say "You're already on Foxes".
- **Players aren't told when the organiser moves them** (none was moved in the end, but nothing would have said so).
- **A word sent before the start joins you to your team** as a side effect, while the reply only says "The competition hasn't started yet."
- **Reporting a word when no prompt is live** says "This world has run out of words" (before the start and after the end). It should say the competition hasn't started or has finished.
- **The last prompt started with under 4 minutes to go.** Consider not starting a prompt that close to the end.
- In the API, a player's `team` is the team's position (0/1) while `teams[].id` is 1/2; the teams' `position` stays fixed rather than showing rank. (For the page this may not matter.)

### Scoring
- **Two-word answers outscored everything**: *paddle steamer* 48, *fishing boat* 40, *sailing boat* 38, against 15 to 18 for single words like *battleship*, *houseboat*. Base points come from length, and a phrase counts all its letters joined; most phrases are also hand-added, so they rank as rare. Foxes pulled away on the vehicle prompt. Consider basing a phrase's points on its longest word, or capping it.
- **"Already said" for the other spelling** (*aluminum* after *aluminium*) cost 2 points. That's correct and consistent (they're one answer, like any repeat of someone else's word), but the player didn't realise: *aluminum* never appeared in the feed. The message should say so: "*aluminum* is the same answer as *aluminium*, which Dee said at 18:23".
- **Words normally used in the plural are judged by their singular's length**: *leggings*, *lodgings*, *nuggets* fined 2 points on 7+ and 8+ letter prompts. They can't be reported either ("already in the dictionary").

### Word lists
- **Two-word road vehicles are missing** ("not a word"): *fire engine*, *fire truck*, *police car*, *sports car*, *racing car*, *double decker*, *ice cream van*, *milk float*, *mountain bike*, *steam train*, *steam engine*, *penny farthing*, *cable car*, *jumbo jet*, *tow truck*, *space shuttle*, *hot air balloon*. Two-word boats work (*rowing boat*, *cruise ship*). The vehicle category needs its phrases, like the boats got.
- **Metal alloys are missing**: *stainless steel*, *wrought iron*, *cast iron*, *sterling silver*, *white gold*, *rose gold*, *pig iron*; also *californium*, *duralumin*.
- Other gaps: *luckless*, *liveliest*, *liveliness*, *loudness*, *lowliest*, *loftiest*, *luminescent*, *litterbug*, *doggedly*.
- Inconsistencies: *container ship* uncounted while *cruise ship* and *cargo ship* count; *wheelbarrow* uncounted while *pushchair* counts.
- Missed phrases still show joined in My misses (*doubledecker*, *lipgloss*), because only listed words have a written form.
- **Sizing**: "Starts with L, 8+ letters, no A" had 150 answers; four players found 68 in 15 minutes, so it never reached its 80% end. Fine for a big room, large for four.

### Smaller
- A report sent just after a prompt changed returned OK but the miss still showed as not reported.
- On the vehicle prompt the unsaid count once dropped by 3 for 4 accepted words (possibly an *also* word).

## Notes on the test itself
- The test helper didn't send which prompt the player saw, so the 30-second grace after a prompt change couldn't apply to the agents (the real page sends it); three penalties came from that. Fixed in the helper for next time.

## Suggested fixes, most valuable first
1. A proper ending: results card, winners, each player's place, a celebration.
2. Phrases in the vehicle category (and alloys in metal); check other categories for missing everyday phrases.
3. Phrase scoring: base points not from the full joined length.
4. Clear messages: joining the other team, being moved, reporting before or after a competition.
5. Make a team-mate's word free (the owner's decision); when a word is already said under its other spelling, say so in the message.
6. Don't start a prompt in the last few minutes.

## Changes made after this playtest (14 October)

1. **A proper ending:** a finished competition, room or round shows a results card in place of the prompt: "Foxes win!", the team scores, "Well played Cal, Dee", the top scorer, and "You finished 3rd of 4. Owls came 2nd of 2." with a Share button; the winners get a short shower of confetti (once per device). A word sent after the end says the result is at the top. A shared result now has a title (the email subject) and a line saying what Unsaid is.
2. **Word lists:** two-word vehicles, alloys, the other gaps, and everyday phrases across the categories (see the commit).
3. **Phrase scoring:** a phrase scores its longest word plus 1 per other word (*paddle steamer* 6 base points, was 11).
4. **Messages:** "This is Owls' link, but you're on Foxes"; "The organiser moved you here from Owls"; a word or report before the start is refused without joining a team; reporting before the start or after the end says so.
5. **A team-mate's word is free.** "Already said" for the other spelling says "“aluminum” is the same answer as “aluminium”, which Dee said at 18:23".
6. **No prompt starts in the last 5 minutes:** the one before runs on to the end, and doesn't stop at 80%.
- Words normally used in the plural (*leggings*, *trousers*) are judged as written (`data/plural-words.txt`). Missed phrases show as typed in My misses, and a report is always about the prompt the miss was for.
