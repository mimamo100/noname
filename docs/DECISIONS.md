# Decisions

Why the game is the way it is: one entry per decision, newest first, with the reason and
where it came from. `DESIGN.md` says what the rules are; this says why. Earlier decisions
are at the top of each report in `playtests/`.

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
