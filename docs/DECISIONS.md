# Decisions

Why the game is the way it is: one entry per decision, newest first, with the reason and
where it came from. `DESIGN.md` says what the rules are, and `WORDS.md` everything about the
words; this says why. Earlier decisions are at the top of each report in `playtests/`.

## 17 October 2026

**Prompts keep clear of the last 10**: their meaning lists, letter rules and letters-only
shapes. Before, only the exact prompt and the list just before were avoided, so "A plant or
flower of exactly 6 letters" and "A plant or flower of 9+ letters" came within a few prompts of
each other in The Big Room. A repeat is still allowed when nothing else fits. *Owner.*

**Comparatives and superlatives are forms of their adjective**, as -ing and -ed forms are of their
verb: *bigger* and *biggest* are one answer with *big*, and count as *big* on letter prompts.
Irregular ones (*better*, *best*, *worse*, *more*, *least*, *further*) stay words of their own:
the rule is there to stop cheap stretching (*big* to *biggest*), and they don't stretch anything.
The same goes for anything irregular: *wrote*, *written*, *ran*, *mice*, *children* are one answer
with their word but count as written for letter rules and points (*mice* still counts as *mouse*
for "A small animal"). Words that only look like
comparatives stand alone (*broker*, *butcher*, *number*, *outer*, *gamer*); ones that are both
(*stranger*, *cleaner*, *lighter*, *the latest*) are on the own-meaning list. *Owner.*

**Verb forms that are words in their own right count as written**: the verb rule stays (*tempted*
counts as *tempt*, so it fits "6 letters or fewer"), but a hand-kept list of -ing and -ed forms
that have become words of their own (*building*, *wedding*, *crooked*) count as themselves on
letter prompts. They still use up their verb and its forms. The test: the word means
something other than doing the verb or having done it (*a building*, *icing*, *crooked*), so
activities (*swimming*, *walking*) and states (*married*, *bored*) stay the verb; a line between
*swimming* and *walking* had no logic. The list is `data/own-meaning.txt` (130 words;
*given*, *mistaken*, *stricken* and *drunk* came off, as irregular forms count as written anyway).
-en forms are one answer with their verb (*shown*, *swollen*, *molten*), except *torn* (tear, the
teardrop, would join it), *drunken* and *laden*. This takes up the list set aside on
15 October; the owner marked a draft in groups.
*Owner.*

## 15 October 2026

**The verb rule stays, without its penalty**: a word refused only because a verb's form counts
as the verb (it would fit as typed) costs nothing. *programming* and *swimming* are nouns to most
people, and in the Night Cup most penalties were such words. A hand-kept list of -ing nouns that
count as themselves was considered and set aside: almost every -ing word is also a noun.
*Owner.*

**A host chooses how to remove a player**: "Remove" keeps them in the standings and results,
marked "(removed)", and lets them watch; "Remove completely" takes them off and stops them seeing
the room (as before). Either way they can't play again and their words stay said. In Music
Night, removing Ada had taken her 88 points out of the results, harsh for someone removed only
to tidy up. *Owner.*

**Music pack**: "Part of a song, or how it's played" is now "A part of a song, or a way of
playing", with a note; *diva*, *emcee*, *disc jockey* and *hymnist* are accepted for "A
musician", which has a note too. Curated packs can now have notes. *Owner, Music Night.*

**"A rarer answer"**, not "a looser answer", for an answer that's accepted but doesn't bring the
count down. *Owner.*

**Another spelling of a word already said is free**, even in a room that charges for repeats:
the other side's spelling (*color* in a UK room) is let off, so *flutist* after *flautist* should
be too. *flutist* and *lutist* are marked as the US words. A charged repeat now says "This room
charges for repeats.", and a free one no longer says "as it was hidden" unless it was.
*Owner, after the Music Night playtest.*

**"About this list" and "About this prompt" give quirks and definitions, not examples**: what a
list includes and leaves out ("Real fish only: sea mammals, shellfish and jellyfish are sea
creatures, not fish"), and how a letter prompt judges forms ("A verb's other forms count as the
verb here"), without lists of example words. *Owner, after the Music Night playtest.*

**Missing plurals are added, and forms nobody uses are left out.** 1,714 plurals of dictionary
words that the word lists lacked are in (*blurbs*): safe, as a plural is judged and scored as
its singular and no prompt asks for an S. Added forms (plurals and -ing/-ed forms) must appear
at least 10 times in film subtitles, which keeps *heckled* (85) and *creasing* (18) and drops
*plained* (1), *springed* (5) and *heliums*, which had shown under "Gone with it". 1,006 verb
forms are added now, not 2,358. *Owner, after the round 6 playtest.*

**Big prompts are left as they are for now.** The owner's "173 left" reaction was about not
being able to think of any of them, not the number; capping prompts, ending quiet ones (30
minutes is too short: players may be asleep) or a lower maximum were all set aside. Helping
players find the long tail may be the better direction. *Owner.*

**Players can leave a public room**: the seat is free at once, their words and points stay
(marked "left"), and they're never put back in that room, so leaving is a fresh start rather than
a way to dodge a full room's rules. Until now the only way out was a week away. A room more than
three quarters used up still takes no newcomers, freed seat or not. *Owner.*

**A verb's -ing and -ed forms count as the verb on letter prompts, and score as it**: *running*
is *run*, so it doesn't end in G, isn't 7 letters, and scores as *run*; *wrote* is *write*. Like
plurals, adding letters shouldn't earn points, and the root is what the player knew. A meaning
prompt judges a word as written, as its list names it (*swimming* for "A sport"). The forms
come from WordNet (`scripts/find-word-forms.py`, `data/verb-forms.txt`), which also fixed links
the ending rules got wrong (*browsed* was joined to *brow*, *earring* to *ear*), and 2,358
missing forms of dictionary verbs were added (*creasing*, *heckled*), as rare as their verb.
"Ends with G" prompts now have about 1,000 answers instead of 4,800. *Owner.*

**Players see "room", never "world"**: The Big Room, public rooms (the 60-player ones), private
rooms and competitions. The two words were used interchangeably; "room" is friendlier and
familiar from other games. Only what players see changed: the code, database and links still
say *world*. Names stored before the change ("Room 1", "The Big World, 14 Oct") were renamed
with one SQL statement on the server (see HANDOVER). *Owner.*

**Familiar words that subtitles under-count are placed by hand** (`data/rarity-overrides.txt`:
a word and the multiplier it should have). 47 so far: the rarity review's words still out under
the curve, the room playtest's (*housefly*, *woodlouse*, *bluebottle*) and everyday British
words (*mangetout* was ×2.7, *pushchair* ×2.3). Placing closed compounds by their parts was
tried and dropped, as it made obscure ones cheap (*froghopper*); a British frequency list would
fix more at once, but SUBTLEX-UK is for research use only. The 33 words beyond the review are
Claude's marks, open to change. *Claude, following the owner's marks.*

**Spiders and other creepy-crawlies count as bugs, but worms, slugs and snails don't.** The owner
decided spiders count; where to stop was Claude's call: a person might call a woodlouse or a
centipede a bug, but hardly an earthworm or a slug. The list's note says so. *Proposed by
Claude, open to change.*

**A verb's irregular forms are one answer** (go, went, gone; write, wrote, written), like
irregular plurals, but each is judged as typed, as regular forms are: *written* is 7 letters,
not *write*'s 5. Forms with an everyday meaning of their own are left out (*saw*, *found*,
*left*, *felt*, *rose*, *spoke*), so saying *saw* doesn't use up *see*. *Claude, following the
room playtest.*

**Lists' notes** ("About this list") are written for 30 lists, saying what's in and out where
players could reasonably differ (tomato is a fruit here; whales aren't fish; games count as
sports). Drafted by Claude for the owner to check.

**Repeats are free, unless a host or organiser chooses to charge.** Saying a word someone else
already said used to cost 2 points (unless it was said in the last minute). Every agent in the
room playtest found it unfair ("nothing tells you the word is old"), and in a room words stay
said for weeks. Now it's free everywhere by default; a private world's host or a competition's
organiser can tick "Repeating a word someone else said costs 2 points" for a sharper game
(never for your own word, a team-mate's, or one said in the last minute). Words that don't fit
still cost 2. *Owner.*

**"An insect or bug" includes spiders** (and other creepy-crawlies a person would call a bug).
*Owner.* The word-list review adds them (`dictionary-review/NEXT-REVIEWS.md`).

**Rooms' prompts have 40 to 200 answers** (other private worlds 10 to 80, the big world 60 to
400). A room holds up to 60 players, and in the room playtest a 10-answer prompt went in about
a minute with six: a slower player never got a turn. *Owner, after the room playtest.*

**Room cards show words said**, with the share left once it rounds below 100%: a room of 52,000
words read "100% words left" after dozens of words, as if nothing had happened. *Owner.*

## 14 October 2026

**Rarity follows a curve: `1 + 2 × p²`**, where p is a word's place in the list ÷ the list's
size (it was `1 + 2 × p`). Most words people know sit in the middle of the 52,000, so on a
straight line *teaspoon* scored ×1.8; on the curve familiar words stay near ×1 (*teaspoon*
×1.4, *kettle* ×1.0) and ×2 to ×3 is kept for really unusual words. Chosen from 140 everyday
words marked by Claude and checked by the owner (`dictionary-review/RARITY-REVIEW.md`); Curve B
came closest. A pack word that isn't an everyday word stays at ×2.2. British kitchen and
vegetable words (*courgette*, *saucepan*) still score too high, as subtitle counts under-rank
them: to fix next, by hand or with a British frequency list. *Owner.*

**Organisers list a word's other forms themselves.** Only the forms a pack lists, and their
plurals, count for its lists, so *grilling* doesn't count for "A way of cooking" unless it's
listed. Counting every -ing and -ed form would have done it automatically, but the game can't
tell a list of actions from a list of things (*forked* would count as cutlery). Instead the
pack editor's guide reminds organisers to add them, and we'll build fuller packs of our own
for organisers to start from. *Owner.*

**The world multiplier only in rooms and private worlds.** It rises as a world's words are
used up (×2 at half said, up to ×10), which suits worlds played over weeks: it's the story of
a world slowly running out, and why saving a word for later can pay. The big world's daily
round and competitions go without, as on a busy day it could climb fast and the time a word
was said would decide more than the word. Pack-only worlds go without too: in the Kitchen Cup
(80 answers) it reached ×6 in half an hour, so *teaspoon* scored 16 early and *butter knife*
90 late. The prompt bonus (up to ×2 as each prompt runs dry) still rewards pressing on.
*Owner, after the Kitchen Cup playtest.*

**Pack words score with their everyday rarity.** Rarity came from a word's place among the
pack's own words, so near neighbours in everyday English scored far apart. Now an everyday
word is as rare as it is in the everyday dictionary, and a word that isn't an everyday word
counts as fairly rare (0.6 of the way to the rarest, so ×2.2), not the rarest. *Owner, Kitchen Cup.*

**Pack lists: one answer per line, other forms after commas.** Lines without a comma used to
be split on spaces, which broke "wash up" and silently merged "egg cup" with *egg* and *cup*.
Packs saved before keep their meaning (format 1); the editor shows them with commas.
*Kitchen Cup.*

**A new prompt avoids the list just asked about**, when another list fits, so a small pack's
"Nobody said" answers aren't simply said on the next prompt. Holding back "Nobody said" was
the other option; this keeps it. *Kitchen Cup.*

**Accented letters count as plain ones** (*sauté* is *saute*): what the player meant, and an
accent in a wrong guess had broken a player's page for the rest of a competition. *Kitchen Cup.*

**Competition times as "Starts 20:00 Ends 20:30" with a countdown**, not a sentence: easier
to take in at a glance. Prompt lengths 5, 10, 15 and 30 minutes, an hour, 4 hours or a day,
defaulting to 4 to 6 prompts; the length is a maximum, as prompts end at 80% said. *Owner.*

**Not done: naming the winners in the reply to a word sent after the end.** The results card
already shows them; only API users would have benefited. *Owner.*

**Deleting an account** keeps the player's words said and scores on leaderboards as "Former
player", so nobody else's standings change; private worlds and competitions they run end at
once (their players get final standings), and their word packs are deleted. Leaving worlds
running without an owner was the alternative, but a world using one of their packs would lose
it. *Proposed by Claude, open to change.*

**Your account is a page (`/account`), not pop-ups**: room on a PC, a normal scrolling page on
a phone, and a link the daily email could use. *Owner.*

**Leaderboards show the top ten**, then ⋯ and your own row if you're further down; the "You:
1st of 5" line under the prompt went, as the leaderboard highlights you. *Owner.*

**Code only the tests use stays** (the old nickname join, adding words at runtime): the tests
use it to set things up. *Owner.*
