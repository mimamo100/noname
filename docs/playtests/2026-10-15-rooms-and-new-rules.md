# Playtest: Public room 1 and The Big Room, with the new rules, 15 October 2026

A check of the day's changes on the live site, uploaded at about 18:20: players see "room" (The Big Room, public rooms, private rooms); a verb's -ing and -ed forms count as the verb on letter prompts and score as it, with 2,358 missing forms added; "About this prompt"; My words; the "playing now" dot; free repeats; leaving a public room. Ada, Ben, Cal and Dee played through the API from 18:24 to about 18:51: about 15 minutes in Public room 1 (`4fa81784`, still on round 5's insect prompt, now 226 answers), then The Big Room. Cal left Public room 1 partway and was given Public room 2. Eve sat this round out: Claude in Chrome had disconnected, and reconnecting needs a restart, which would have stopped the agents. The owner watched the page; another player, Pinky, led The Big Room.

Standings when the agents stopped: **Public room 1**: Dee 249, Ada 231, Ben 217, Cal 190 (left), Eve 123, Mimamo 76. **The Big Room, 15 Oct**: Pinky 256, Dee 132, Ben 70, Ada 42, Cal 42, Mimamo 22.

## Done since (during the round, commits 128892c and before)

- **"(just left)"** by a name for an hour after leaving a public room, then nothing: the missing "playing now" dot says the rest (the owner's decision).
- **screed**, **wicked**, **wretched** and **aniseed** stand on their own: the family rules had joined them to *scree*, *wick*, *wretch* and *anise* (Cal found *scree* using up *screed*).
- **A room's card on Your account** says when someone last said a word, not when someone last joined (the old "Last word just now" for an empty room).

## The owner's notes

- **"OMG 173 8-letter insects or bugs left unsaid?!! I can't think of a single other one."** The insect prompt grew from 77 to 226 answers with the new insect list; with 173 unsaid it ends only after 128 more. A big list's long tail (*planthopper*, *bristletail*, *emperor moth*) makes the count look hopeless to a player who has said the ones they know. The agents agreed: "a long, calm grind" (Dee), "a slog with no urgency" (Ada); by the second half nearly every guess was a free repeat. See "Big prompts" below.
- **The "playing now" dots are working nicely.**
- **Cal leaving, shown on the leaderboard, is "a nice touch"**, better as **"just left"**: done, for an hour (above).

## What worked

- **The -ing/-ed rule**, all four agreed, reads clearly and feels fair: "it doesn't end in G (“running” counts as “run”)", "“crushing” counts as “crush”", "“shrunk” counts as “shrink”". Saying the verb uses up its forms (*thrust* took *thrusting*, *thrusted*, *thrusts*), and a form scores as the verb (*crunching* as *crunch*). Dee: "I liked it."
- **The added forms**: every -ing/-ed form Ada looked up (*creasing*, *heckled* and others) was a word.
- **Free repeats**: every already-said reply cost 0; a word that doesn't fit still costs 2.
- **My words** and **promptsPlayed** matched what each agent said, with the right points and prompts; `lastActive` moved as people played.
- **Leaving**: `leave` freed the seat, a word sent afterwards got "You left this room. Join a public room from the home page." (403), the leaderboard marked Cal as left, and `room` gave him Public room 2, where he played on.
- **The insect list**: the new names were accepted throughout (*bark beetle*, *assassin bug*, *jumping spider*, *trapdoor spider*, *water scorpion*, *orb weaver*, *mole cricket*, *burnet moth*), and the long tail scored well (*lanternfly* 31, *springtail* 32, *scorpionfly* 34).
- No HTTP errors.

## Not bugs

- **"Starts and ends with G" accepted *getting*, *gargling* and *gouging*, and listed *going*, *giving*, *growing* as Nobody said** (Ben, Cal, Dee). That prompt ran at 17:00, before the upload: the owner said those words then, and its Nobody said list was recorded when it ended. Under the new code *getting*, *gouging* and *giving* are refused. (*going* still fits: its verb *go* is too short to be a word here.)
- **No note on "A natural landscape feature with no T"** (Ada, Dee): a meaning prompt judges words as written, so the verb rule doesn't apply, and that list has no note.
- **Cal saw no "left" flag**: it's there (`leftRoom: true`, and his leaderboard row), but the helper's text view doesn't print it.
- **`promptsPlayed` was 6 after two prompts** (Ada): it counts the whole day's round of The Big Room, earlier prompts included.
- **`multipliers.world` is null in The Big Room**: by design, only public and private rooms have one.

## Big prompts

A meaning list of 300 answers (the insects now) makes a prompt of 200+, which runs for hours in a quiet room and leaves players staring at "146 left" with nothing more to say. Rooms ask for 40 to 200 answers, and this one only got 226 because the list grew under it. Ideas, for the owner:

- **Cap a room prompt's count at 200 when it starts** (it was 77 when picked), so a list that grows doesn't stretch it: in effect already true for new prompts.
- **End a prompt that has gone quiet**: say, nobody has found a new answer in 30 minutes and most of the obvious ones are said, so the room moves on (the Nobody said list then shows the long tail, which players enjoy).
- **Lower rooms' maximum** to about 120.

## Word lists and the dictionary

- **Plurals that aren't in the dictionary are refused** even when their singular is: *blurbs* (Ada). The dictionary only has plurals the word list had; about 5,500 regular plurals of dictionary words are missing. They'd be safe to add from ENABLE, as the -ing forms were: a plural is judged and scored as its singular, and no prompt asks for an S. Most are words nobody would type (*heliums*), which does no harm. **Decision needed.**
- **Odd forms now in families**: the added forms include some nobody uses, and they show under "Gone with it": *plained*, *plaining*, *springed* (Dee). Fix: only add forms of verbs WordNet has seen used (its usage counts), which keeps *creasing* and drops *plained*.
- **Still-missing forms and words** (all reported): *dibbing*, *dibbed*, *tarmacked*, *tarmacking*, *hoed*, *overwrote*, *bestrode* (Dee); *overwrite*, *overwritten*, *bestride*, *bestridden*, *upturn*, *uncurl*, *cumulus*, *cumuli*, *succubi*; insects *bombardier beetle*, *devil's coach horse*, *pill woodlouse*, *green lacewing*, *seven-spot ladybird* (and *cuckoo spit*, which is the froth, not the insect). Ben also listed *planthopper*, *bristletail*, *lace bug* and *wood ant* as refused, but the live site has all four as words and none is said yet: his list seems to carry over round 5's, from before the insect list grew.
- **The insect note says worms don't count**, but *mealworm*, *wireworm* and *glowworm* do (Ben): they're insect larvae. The note could say "Earthworms, slugs and snails don't."
- **"Every vowel is U"** doesn't say whether Y is a vowel (*church*, *hungry*, *supply* were answers Ben missed): an About this prompt line for vowel prompts ("Y doesn't count as a vowel").
- **The length note's example** ("“brothers” is 7 letters") is out of place on an insect prompt (Ada): it could use a word from the prompt's own list.
- **"snail" costs 2 on the insect prompt, "earthworm" is free** (Cal): *snail* isn't 8+ letters, so it fails the letter rule (2 points); *earthworm* fits the letters but isn't on the list (free). Consistent with the rules, but a player sees two refusals for the same reason.

## Smaller points

- **My words' points add up to more than the score** (Ben: 82 against 70): it lists points earned, and the summary says "before penalties"; fine, but a line with the penalties would close the gap.
- **The "plunged" refusal doesn't say which letter broke the rule** (Cal, on a "no E" prompt): "it has an E" already does; worth checking the message for a verb form judged as its verb.
- **A known flaky test**: one prompt test picks 30 random prompts and expects all three kinds; once in a while it doesn't get them. To make it certain.

## Fixes, most important first

1. **Big prompts**: decide between capping, ending a quiet prompt, or a lower maximum for rooms.
2. **Plurals**: add the missing regular plurals of dictionary words (decision needed).
3. **Odd forms**: drop added forms of verbs WordNet hasn't seen used (*plained*).
4. **Missing words and forms** above.
5. **Notes**: "Earthworms, slugs and snails"; Y and vowels; an example from the prompt's own list.
6. **Smaller**: penalties in My words' summary; the flaky test.
