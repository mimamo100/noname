# Playtest: a private room playing a word pack, "Music Night", 15 October 2026

The first private-room playtest since 13 October, and the first of a pack **with everyday words** (earlier pack tests played a pack on its own). Eve (Organiser plan) set up **Music Night** through the page (the owner clicked Create): Music pack with everyday words, **UK spelling only**, prompts of up to an hour, and the new option **"Repeating a word someone else said costs 2 points"** ticked. Ada, Ben, Cal and Dee joined with the invite through the API and played 19:23 to 20:00; the owner played as Mimamo. Eve was to remove Ada at about 19:50 and end the room at about 19:57, but Claude lost track of the time and did both at about 20:05, after the agents had stopped, so Claude checked the end through the API.

Two prompts: "A musician with no R" (26 answers; ended on its own) and "Part of a song, or how it's played with no O" (45 answers; open when the room was ended). **Final result: Ben 205, Dee 99, Eve 45, Mimamo 28, Cal 25** ("Ben Tester wins! with 205 points, out of 5 players"). Ada had 88 before she was removed.

## Done since (15 October, commits f2a9ecd to ebc930d)

- **Ending a room that plays a word pack** now notes the open prompt's Nobody said with the pack's lists. The host's controls used the everyday dictionary, which knows no Music lists, so the last prompt showed "Every answer was said!" with 18 answers left (`api.php` now gives `PrivateWorlds` and `Competitions` the room's own game; a test covers it). Competitions with packs had the same fault.
- **A private room that ends says "This room has finished."**, not "This room has run out of words" (it was the host who ended it). And a leftover "This world has run out of words" on the prompt card now says room.
- **Players' reports** (the owner's download of 15 October): *cumulus*, *uncurl*, *overwrite*, *bestride* and others added; *devil's coach horse*; *chanteuse* (counted), *psalmist*, *kazooist*, *flugelhornist*, *cymbalist* (accepted) on "A musician"; and on "Part of a song, or how it's played", the theory and marking words it invites (*rhythm*, *cadence*, *bar*, *measure*, *rest*, *slur*, *trill*, *fermata*, *andante*, *tune*…) are accepted. Details in `chat/word-decisions.md`.
- **Plurals in -es only after s, x, z, ch, sh or o**: the plural finder had made *filles* a form of *fill* (Cal saw it under "Gone with it"), and *rapes* of *rap*, *examinees* of *examine*: 18 such dropped.
- **4-letter verb forms** (*hoed*, *toed*) are found now.
- **A charged repeat says why** ("−2 points. This room charges for repeats."), and a free one gives the right reason: it said "as it was hidden" for any free repeat.
- **Another spelling of a word said is free**, even where repeats are charged (the owner's decision), and *flutist* and *lutist* are marked as US spellings, so a UK-only room tells you to use *flautist*.
- **About this list / prompt** rewritten as quirks and definitions, without examples (the owner's note).
- **Removing**: the host chooses "Remove" (kept in the standings and results, marked removed, can watch) or "Remove completely" (as before).
- **Music lists**: "A part of a song, or a way of playing", with a note; *diva*, *emcee*, *disc jockey*, *hymnist* accepted for "A musician", with a note. Curated packs can have notes now.
- **"A rarer answer"** instead of "a looser answer".
- **Smaller**: the repeats checkbox is a proper size; "Gone with it" leaves out the other side's spelling in a one-spelling room. Not done: the blank Big Room card (not seen again).

## What worked

- **Setting up through the page**: the form's choices (spelling, pack with or without everyday words, pace, player limit, repeats) and the banner "Music Night [Music] · Private room · Everyday and Music words · UK spelling".
- **UK spelling only**: *center*, *theater*, *color* got "This room uses UK spelling: try “centre”. No penalty." (all four agents).
- **Repeats costing 2**, with the minute's grace: a word someone said moments ago stayed free ("No penalty, as it was moments ago", Eve), older ones cost 2.
- **Removing a player**: Room settings → Remove; Ada's next word and her next look at the room got "You've been removed from this room." (403).
- **Ending**: the results card ("Ben Tester wins! … You finished 🥉 3rd of 5 with 45 points. Share your result"), Ben's result (1st of 5, 205, best word *bassoonist* 32), My words after the end (all 17 of Ben's), and a word after the end refused (410).
- **The "playing now" dots** showed everyone at the start; **My words** matched what each agent said.
- **The -ing/-ed rule**: *bowed* "counts as bow" on a letter rule; on the meaning prompts, forms were judged as written (*strummed*, *plucked* accepted), as designed.

## Repeats that cost points need saying so

All four agents and Eve: "Cal Tester already said “pianist” at 19:23, for “A musician with no R”. −2 points." is right, but nothing says **this room charges for repeats**, and everywhere else they're free; nor that a word said in the last minute is free. A player sees 0 one time and 2 the next. Suggest: "… −2 points: repeats cost 2 in this room (free within a minute of someone saying it)."

Another spelling of a word already said (*flutist* after *flautist*, *tympanist* after *timpanist*, *lutist* after *lutenist*) is the same answer, so it costs the same; the page says "“flutist” is the same answer as “flautist”", which the agents didn't see (API).

## Removing and ending

- **A removed player can't even look at the room** ("You've been removed from this room." for the page too), and **drops out of the final standings**: the result said "out of 5 players", and Ada's 88 points and her place aren't in her Games. That's right for someone removed for bad behaviour; it seems harsh for someone removed to tidy up. Options, for the owner: keep removed players in the final standings (marked "removed"), or let a host choose between "remove" and "remove and hide".
- **Ending and removing use the browser's confirm box**, which freezes Claude in Chrome (the owner clicked OK twice). Not a problem for players; worth knowing for testing. A confirm step on the page itself would avoid it.

## The music lists

- **"A musician"** refused (after today's additions): *diva*, *punk*, *emcee*, *alto* (a voice, fair), *dj* (2 letters: can't be a word here; *deejay* is), *disc jockey*, *glockenspielist*, *hymnist*, *dulcimerist*. Decide on *diva*, *emcee*, *disc jockey*, *hymnist*.
- **Also-answers say "looser"**: *xylophonist*, *ukulelist*, *bandsman* got "It's a looser answer, so it doesn't bring the count down or get the prompt bonus." They're rarer words kept uncounted on purpose, but "looser" reads oddly for a plainly right answer. Suggest "a rarer answer" for those. And while players find only also-answers, the count doesn't move (stuck at 11 for minutes), which looks like nothing is happening.
- **"Part of a song, or how it's played"** reads awkwardly, especially with a letter rule ("…how it's played with no O"), and mixes nouns with verbs (*strummed*). Suggest "A part of a song, or a way of playing" or splitting it.
- **No notes** on either music list (`prompt.note` was null): the music lists could use some (see the owner's note below).

## The owner's notes

- **About this list should be quirks and general definitions, not examples.** Claude's notes for 30 lists, and the automatic letter-prompt notes ("“running” and “ran” are “run”", "“brothers” is 7 letters"), lean on examples. To rewrite.
- **Next round: a competition.**

## Smaller points

- **The repeats checkbox** on the New private room form is tiny next to its text.
- **The Big Room's prompt card was blank** behind the New private room form at 19:21, with 4h 39m of the round left: perhaps between prompts. To check.
- **flautist took flutist with it** in a UK-only room ("Gone with it: flautists, flutist, flutists"): right (one answer), but showing the US spelling in a UK room is odd; the list could leave out the other spelling there.
- Prompt ids jumped 182 to 184: ids are shared by all rooms, so #183 was another room's.

## Fixes, most important first

1. **Say why a repeat cost 2** in a room that charges, and that the last minute is free.
2. **Removed players**: keep them in the final standings (decision needed).
3. **About this list / prompt**: rewrite as quirks and definitions, without examples (in hand).
4. **Music lists**: *diva*, *emcee*, *disc jockey*, *hymnist*; rename "Part of a song, or how it's played"; notes.
5. **"A rarer answer"** instead of "a looser answer" for also-words that clearly belong.
6. **Smaller**: the repeats checkbox; the blank Big Room card; other spellings under "Gone with it" in a one-spelling room.
