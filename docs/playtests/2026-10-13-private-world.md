# Playtest: a private world, 13 October 2026

The owner created a private world, "Claude Testers", which turned out to play the **Science word pack on its own**. Ada, Ben, Cal and Dee played it through the API for about 30 minutes at a human pace, joining through the invite link; Eve played a few words through the real page in Chrome at phone width; the owner (Mimamo) created it. Prompts #117 to #122, mostly 5 to 27 answers.

Final scores: Dee 920 (inflated: on the first prompt she played words she'd looked up in the reviewed lists), Cal 540, Ada 539, Ben 527, Eve 4.

**The test site still has the pre-review Science lists and older code** (no phrase display yet), so word-list findings below were checked against today's reviewed lists in `data/packs/science/`.

## The owner's decisions (13 October)

- **Uncounted (*also*) answers score at the prompt bonus from when the prompt began (×1)**, not the current one. They keep their rarity score, but no longer ride the top bonus while the count stays put.
- **The unsaid count says "At least X words left".**

## The owner's notes

- **The private world's page is too much like the front page**: the owner was confused about where they were. Only a small "Claude Testers · Science words" in the header and "Copy invite link" tell them apart. Ideas: the world's name as the page title with "Private world · Science words" under it; a distinct accent colour or band; "Back to the big world"; drop the big world's extras ("Last round", the rooms box).
- **The unsaid count could say "At least X words left"** (see 2).
- **Prompt length on creation**: the owner's own slip. They retried, and a new world kept its 1-hour setting. Still worth making the chosen option more visible on the form.

## 1. The score swing is far worse on small prompts (all four)

- On a 5-answer prompt the bonus runs ×1, ×1.25, ×1.67, ×2.5, ×5, and the last answer doubles: about ×10. *Dark energy* scored 182 and *genotyping* 194, each more than the other four answers on its prompt together; *work* scored 5 on the same prompt. Ada had 185 points from 2 words while Ben had 135 from 10.
- The best strategy is to hold good words back for the end.
- Suggestions from the testers: a bonus from ×1 to about ×2 by the share of answers used, and a flat last-answer bonus instead of doubling.

## 2. Uncounted (*also*) answers keep earning the top bonus

- The unsaid count covers only the main answers; specialist *also* answers are accepted but not counted. On "A subatomic particle with no S" (27 answers) 43 words were accepted, and the count sat at "2 of 27" while about 15 specialist words (*neutralino*, *gravitino*, *phonon*) scored at the ×5 cap, up to 109 points each. It ended only when someone said the bare *alpha* and *beta*.
- Players read the stuck count as a bug. The owner's wording, **"At least 2 words left"**, fixes the impression.
- Scoring needs a fix too: an *also* word shouldn't earn the prompt's top bonus (score it at ×1, or at the bonus from when the prompt began).
- A phrase and its own word scored as two answers: *alpha particle* and *alpha*, *beta particle* and *beta*.

## 3. Penalties and messages

- **A word sent just after the prompt changes is judged against the new prompt and penalised** (Ada: *kinematics*, meant for "A physics term containing K", cost 2 points against "Starts and ends with G", 16 seconds after the change). Small worlds turn over every few minutes, so phone players will hit this constantly. Suggest a grace period: for a few seconds after a change, a word that fits the old prompt counts for it, or at least costs nothing.
- **Plurals are judged by their singular's length**: *flasks* and *glands* (6 letters, one vowel) cost 2 points on "Only one vowel, 6+ letters". This is the earlier rule "judge plurals as their singular for length rules and points", but it feels wrong to players and isn't explained. And `report flasks` fails: "That word is already in the dictionary".
- **A word rejected for its category can't be reported** ("report quark": already in the dictionary). Reports should cover "it fits this prompt" as well as "it's a word".
- **Nothing says the world is Science-only.** Everyday words come back as "isn't in the dictionary" (*strength*, *school*, *string*), which reads as the game being broken. Say "not in this world's Science words", and show the pack clearly on the page.
- Still from round 1: "doesn't fit" gives no reason; "already said" is checked before the letter rule (*gas* was "already said" via *gassing* rather than not ending in G); rejected phrases come back squashed (*geneediting*, *hookeslaw*), which the phrase display work fixes only for listed words.

## 4. The Science lists (checked against today's reviewed lists)

- **Broad categories need their obvious overlaps listed.** "A physics term" rejected *quark*, *kelvin* and *black hole* because they're in the particle, unit and space files. Still true after the review.
- **Biology has no organs**: *heart*, *lung*, *liver*, *kidney*, *stomach*, *brain*, *intestine*, *thymus*, *larynx*, *pharynx*. Still missing after the review.
- Also missing after the review: *length*, *strength*, *intensity* (physics), *Hooke's law*, *top quark*, *up quark*, *grafting*, *genetic engineering*, *gene editing*, *germinating*; and *methyl*, *glycol*, *kinetic*, *tauon*, *rho*, *zeta*.
- Doubtful acceptances: *drying* (and it used up *dry*), *gassing*, *gusting*, *switch*, *stormy*, *frosty*.

## 5. Private worlds

- **Joining through the invite worked first time** for everyone, and the next prompt always came at once (prompts lasted about 2 to 11 minutes).
- Without an invite, or with a wrong one, a non-member gets the same message: "This is a private world. You need an invite link from one of its players." A wrong code could say the link isn't valid. Members are let in regardless of the invite.
- The owner (Mimamo) didn't appear on the leaderboard because they didn't play in this round: as expected, not a bug.
- The prompt leaderboard lists players with 0 points who only had misses.
- Eve at phone width: in a small world the layout works well, since Just said stays short; "Copy invite link" is at the top; "Ends in 23h 56m" looked odd on a prompt that ran dry in minutes.
- The anonymous view sends the world multiplier with about 50 decimal places.

## Suggested fixes, most valuable first

1. Rework the prompt bonus (gentler curve, lower cap, flat last-answer bonus), and score *also* answers without the top bonus.
2. "At least X words left".
3. A grace period after a prompt changes.
4. Say why a word doesn't fit; judge plurals by the length typed (or explain); let players report category misses.
5. Make the pack and the private world obvious: say "not in this world's Science words", and give private worlds their own look.
6. Science lists: organs in biology, overlaps in physics, the other gaps above.
7. Hide 0-point rows on the prompt leaderboard; round the multiplier in the API.
