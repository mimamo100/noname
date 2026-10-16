# Playtest: a quick look at The Big Room, 17 October 2026 (00:19 to 00:40)

A short check on how things are holding up, with no changes since the Night Cup. The four agents (Ada casual, Ben competitive, Cal edge cases, Dee word lists) played The Big Room through the API for about 15 minutes each; Eve played through the page in Chrome; the owner played as Mimamo. Two prompts: **#258 "A plant or flower of exactly 6 letters"** (74 answers) and **#259 "Contains M and T, 6 letters or fewer"** (285), which came early when #258 reached its count.

Standings at about 00:37: Dee 96, Eve 73, Mimamo 66, Ada 0 (12 words), Ben -1, Cal -2 (Cal missed on purpose).

## Holding up

- **Nothing broke**: no errors or failed requests for anyone; every score the agents checked against `raw` added up (Cal: 33 earned, 30 in penalties).
- **The page**: the "About this list" note ("Trees, grasses, mosses, cacti and seaweed count. Fungi don't. Plurals count as their singular for length."), My misses with "free" or "-2" against each, "Gone with it", the dots, the leaderboard, and the prompt changing with a cleared miss message all worked. One slow first load (the tab sat on "Loading..." for a while before the state came in). Timed afterwards, every request to the site, even a static file, takes about half a second to its first byte: the host, not the game. The long wait was a one-off.
- **The verb rule's free refusals**: *dreamt*, *meant* on "Contains M and T" refused for nothing (`verbForm: true`); families burn together (*timed* after *time*: "Ada Tester used up "timed" by saying "time"", no penalty).
- **Plurals by their singular**: "*tulips* doesn't fit: it's 5 letters (*tulips* counts as *tulip*), not 6. -2 points." Clear to everyone.

## Findings

1. **Ada and Ben lost many points to wrong lengths** on the plant prompt (Ada 0 from 12 words, Ben -19 before the second prompt), and called it harsh. **The owner: no problem with the rules.** The prompt was clear and many plants were left unsaid; the misses were the players' own counting.
2. **The verb rule lets long words under a length cap**: *tempted* (7) was accepted on "6 letters or fewer" as *tempt*, and *smelting*, *meeting* likewise. Consistent with the rule, but odd to see on the board. **The owner: probably fair; the rule stays.** Forms that have become words in their own right (*building*, *wedding*, *crooked*) will count as written, from a hand-kept list (draft in `chat/standalone-forms-draft.md`, for the owner to mark).
3. **"it has no T ("dreamt" counts as "dream")"** reads wrong, since *dreamt* plainly has a T (Ada, Ben, Dee). Suggested: "“dreamt” counts as “dream”, which has no T." **Agreed; done.**
4. **lichen accepted as a plant** (9 points) though the note says fungi don't count; a lichen is partly a fungus. Crops (carrot, tomato, ginger, nutmeg, orange, marrow) were accepted too, some as "a rarer answer". **The owner: a lichen is part plant, so it counts.** The note now says "Trees, grasses, mosses, lichens, cacti and seaweed count."
5. **metre and meter are separate families** while *tumour* burns *tumor*: Cal scored both *metre* and *meter*. **The owner: separate families.**
6. **Missing words**: *fescue*, *sphagnum*, *colza*, *maltose* (all refused as not words; free). The agents noted them but didn't report them. **Added**, with *sea kale*; the plants count for "A plant or flower".
7. **The API's `uncounted: true`** (tomato, ginger) has no text; the page says "a rarer answer, so it doesn't bring the count down". Only the kit is affected.
8. **spruce's family** includes *spruced*, *sprucing* (to spruce up), a different word. **Done**: *spruced* and *sprucing* stand on their own, and *spruce up* (*spruced up*, *sprucing up*) was added.

## Eve's notes

- After a miss, the word stays selected in the box, so typing replaces it; clicking into the box first deselects it and the next word is appended (*tulipsgarlic*). Normal for a text box; only noted.
- "Ends after 45 more or in 11m" counted down sensibly; the prompt changed early on its count and the box kept the last miss ("rhubarb") with the message cleared.

Agent reports: `unsaid-playtest/17-10-26/` (Ada, Ben, Cal, Dee `-round9.md`).
