# Playtest: a quick look at The Big Room, 17 October 2026 (00:19 to 00:40)

A short check on how things are holding up, with no changes since the Night Cup. The four agents (Ada casual, Ben competitive, Cal edge cases, Dee word lists) played The Big Room through the API for about 15 minutes each; Eve played through the page in Chrome; the owner played as Mimamo. Two prompts: **#258 "A plant or flower of exactly 6 letters"** (74 answers) and **#259 "Contains M and T, 6 letters or fewer"** (285), which came early when #258 reached its count.

Standings at about 00:37: Dee 96, Eve 73, Mimamo 66, Ada 0 (12 words), Ben -1, Cal -2 (Cal missed on purpose).

## Holding up

- **Nothing broke**: no errors or failed requests for anyone; every score the agents checked against `raw` added up (Cal: 33 earned, 30 in penalties).
- **The page**: the "About this list" note ("Trees, grasses, mosses, cacti and seaweed count. Fungi don't. Plurals count as their singular for length."), My misses with "free" or "-2" against each, "Gone with it", the dots, the leaderboard, and the prompt changing with a cleared miss message all worked. One slow first load (the tab sat on "Loading..." for a while before the state came in).
- **The verb rule's free refusals**: *dreamt*, *meant* on "Contains M and T" refused for nothing (`verbForm: true`); families burn together (*timed* after *time*: "Ada Tester used up "timed" by saying "time"", no penalty).
- **Plurals by their singular**: "*tulips* doesn't fit: it's 5 letters (*tulips* counts as *tulip*), not 6. -2 points." Clear to everyone.

## Findings

1. **Exact-length meaning prompts are harsh** (Ada, Ben). The easy 6-letter plants went in the first two minutes, and each wrong length cost 2: Ada ended on 0 points from 12 words, Ben was at -19 before the second prompt. "Contains M and T" with 285 answers was "far more fun". Another case for the pending sizing discussion; or a smaller penalty for a length miss on a meaning prompt.
2. **The verb rule lets long words under a length cap**: *tempted* (7) was accepted on "6 letters or fewer" as *tempt*, and *smelting*, *meeting* likewise. Consistent with the rule, but odd to see on the board. Decision: whether "N letters or fewer" should judge as written (like meaning prompts), or keep the rule everywhere on letter prompts.
3. **"it has no T ("dreamt" counts as "dream")"** reads wrong, since *dreamt* plainly has a T (Ada, Ben, Dee). Suggested: "“dreamt” counts as “dream”, which has no T."
4. **lichen accepted as a plant** (9 points) though the note says fungi don't count; a lichen is partly a fungus. Crops (carrot, tomato, ginger, nutmeg, orange, marrow) were accepted too, some as "a rarer answer". Decision on lichen.
5. **metre and meter are separate families** while *tumour* burns *tumor*: Cal scored both *metre* and *meter*. Probably right (a meter is also a gas meter, in UK spelling too), but worth confirming.
6. **Missing words**: *fescue*, *sphagnum*, *colza*, *maltose* (all refused as not words; free).
7. **The API's `uncounted: true`** (tomato, ginger) has no text; the page says "a rarer answer, so it doesn't bring the count down". Only the kit is affected.
8. **spruce's family** includes *spruced*, *sprucing* (to spruce up), a different word: a family break candidate.

## Eve's notes

- After a miss, the word stays selected in the box, so typing replaces it; clicking into the box first deselects it and the next word is appended (*tulipsgarlic*). Normal for a text box; only noted.
- "Ends after 45 more or in 11m" counted down sensibly; the prompt changed early on its count and the box kept the last miss ("rhubarb") with the message cleared.

Agent reports: `unsaid-playtest/17-10-26/` (Ada, Ben, Cal, Dee `-round9.md`).
