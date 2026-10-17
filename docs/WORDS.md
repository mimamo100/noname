# Unsaid: the words

Everything about the words as it stands: what's playable, what counts as the same answer, how
forms and spellings are judged, offensive words, the meaning lists, phrases and the word packs'
own rules. Keep this up to date when a word decision is made. **Why** each rule is the way it is,
with dates, is in `DECISIONS.md`; the rest of the game (prompts, scoring, rooms) is in
`DESIGN.md`. The detailed reviews are in `dictionary-review/`, `word-packs/` and `playtests/`,
and the file formats in the READMEs in `data/categories/` and `data/packs/`.

## The dictionary

- **One fixed list for every world**: about **55,400 words** of 3 or more letters, built into
  `data/dictionary.php` by `php scripts/build-dictionary.php`. Each world uses up its own copy.
- **Sources**: common words from film subtitles that are also in ENABLE, a public word-game list
  (`data/words.txt`), plus words added by hand (`data/extra-words.txt`), plus missing forms and
  spellings the build adds (see below). Words are ranked by how common they are in subtitles;
  the rank sets their rarity, and hand-added words are placed by their own subtitle counts, so
  a fairly common one (*thicken*) doesn't score as one of the rarest. Familiar words the
  subtitles under-count are placed by hand (`data/rarity-overrides.txt`).
- **Ordinary words only.** Not names of people or places, not brands still used as names
  (*cluedo*, *jenga*, *motown*, *jetski*), not foreign words, not abbreviations. Brands that
  became ordinary words are fine (*frisbee*, *taser*, *stetson*, *hoover*, *pilates*,
  *kalashnikov*), and so are words named after places (*cheddar*, *chianti*, *alsatian*).
- **Languages are playable** (*english*, *spanish*, *latin*, *swahili*, *yoruba*, *xhosa*:
  about 80 in all).
- **Famous mythical beings** count, though they started as names (*minotaur*, *pegasus*,
  *cerberus*, *medusa*, *cupid*); single characters don't (*nessie*, *grendel*).
- **3 letters or more**: *ox*, *cd*, *lp* can't be listed.
- **Words reviewed and left out** are kept in `dictionary-review/rejected-words.txt`, so they
  don't come up again. Still to do: about 12,600 candidates in
  `dictionary-review/missing-words.txt`; one more batch, then stop when reviewers reject more
  than they add.

## Families: what counts as the same answer

- **Saying a word uses up its whole family**: *cat* uses up *cats*, *limp* uses up *limped*
  and *limping*, *big* uses up *bigger* and *biggest*. A family counts once in "words left
  unsaid".
- **Families come from**: suffix rules (plurals, -ed, -ing); the lists of forms below, which
  join a form to its word where the suffix rules can't; irregular plurals
  (`data/irregular-forms.txt`: *mouse, mice*; *child, children*); and UK and US spellings.
- **Words that only look related stand alone** (`data/family-breaks.txt`): *bunting* isn't
  from *bunt*, nor *railing* from *rail*, *earring* from *ear*, *morning* from *morn*, *broker*
  from *broke*, *butcher* from *butch*, *doner* from *done*, *gamer* from *game*, *outer* from
  *out*, *flatter* from *flat*, *hinder* from *hind*. *worsted* (the cloth) isn't *worst*'s, and
  *spruced* and *sprucing* (from *spruce up*) aren't the tree's.
- **Irregular verb forms and comparatives are words of their own**: *sing*, *sang* and *sung*
  are three answers, as are *write*, *wrote* and *written*, and *good*, *better* and *best*.
  Saying one doesn't use up the others for the rest of the round. Regular forms stay with their
  word (*singing* with *sing*), and so do irregular plurals (*mice* with *mouse*): a plural is the
  same thing.

## Forms: how a word is judged

On a **letter prompt** ("Ends with G", "7+ letters"), a form that stretches a word counts as
that word, for every letter rule and for points, so adding letters earns nothing:

- **Plurals count as their singular** for length rules and points, and so does a verb's -s
  form: *brothers* isn't 8 letters, *runs* is 3. Spelling rules check them as typed, and the
  game never sets a rule an S would satisfy for free ("contains S"). Words normally used in the
  plural count as written (*trousers*, *leggings*; `data/plural-words.txt`).
- **A verb's -ing and -ed forms count as the verb**: *running* is *run*, so it doesn't end
  in G. The links come from WordNet (`data/verb-forms.txt`), not from the endings, so *browsed*
  is *browse*, not *brow*.
- **Comparatives and superlatives count as their adjective**: *biggest* is *big*
  (`data/comparative-forms.txt`, from WordNet). WordNet decides here too, so *number* isn't more
  *numb*.
- **A word refused only because of this** (it would fit as typed) costs nothing; other words
  that don't fit cost 2, and the reply says why as written (*running* on "Starts and ends with
  R": "it doesn't start and end with R"). Trying the same wrong word again on the same prompt
  is free.
- **Accepted, a form is said as its word**: *hugging* on "5 letters or fewer" is listed as *hug*
  (the reply says "You said “hugging”, which counts as “hug”"), and "Nobody said" lists *register*,
  not *registered*.

These count **as written** instead:

- **Irregular forms**, as they don't stretch a word: *wrote*, *written*, *ran*, *broken*,
  *mice*, *children*, *better*, *more*. Most -en forms are irregular verb forms (*shown*,
  *swollen*, *molten*, *mown*, *torn*). *mice* still counts as *mouse* for "A small animal".
- **Forms with a meaning of their own** (`data/own-meaning.txt`, 146 words): things and events
  (*a building*, *a wedding*, *icing*, *savings*), adjectives whose meaning has moved
  (*crooked*, *learned*, *amazing*) and comparatives that are also nouns (*a stranger*,
  *a cleaner*, *a lighter*, *the latest*). They still use up their word. The test: the word
  means something other than doing the verb or having done it, so activities (*swimming*,
  *walking*) and states (*married*, *bored*) stay the verb.
- **Everything on a meaning prompt** ("A sport", "A plant or flower of exactly 6 letters"):
  *swimming* is a sport. Length rules on a meaning prompt still take a plural as its singular
  (*dahlias* has 6 letters).

**Missing forms are added**: plurals, -ing and -ed forms and comparatives that the word lists
lack but ENABLE has, if film subtitles use them at least 10 times (*heckled*, *creasing*,
*blurbs*, *crisper*; `scripts/find-word-forms.py`, which writes the three form files). They
take their word's rarity.

**About this prompt**, under a letter prompt, tells players these rules in a sentence.

## UK and US spelling

- **One answer, either spelling**: *colour* and *color* (with *colours*, *colored*) are one
  answer. The pairs come from VarCon (`scripts/build-spellings.php` makes
  `data/spellings.json`), and whichever spelling the word list lacks is added (about 400 words).
- **Not every pair is linked.** Spellings that are also everyday words on both sides stay
  separate, so *tired* doesn't use up *tyre*, *check* doesn't use up *cheque*, and *meter* (a gas
  meter) doesn't use up *metre*. Extra links, like *grey* and *gray*, go in
  `data/spelling-links.txt`.
- **A choice per private world**: UK and US (the default, and in public rooms), UK only, or US
  only. In a UK-only world *color* gets a free "This world uses UK spelling: try 'colour'".
  Counters leave out spellings the world doesn't accept.
- **The other spelling of a word already said is free**, even where repeats cost points.

## Offensive words

Two hand-kept lists. Both cover the words formed from a listed word, but never the other way:
*pubes* doesn't block or mask *pub*. Neither kind can appear in a prompt or a name, and the
fallback prompt never picks a letter string that is one ("Contains ASS").

- **Blocked** (`data/blocklist.txt`): slurs (*nigger*, *kike*, *faggot*, *retard*, *redskin*,
  *kaffir*, *yid*…), strong swearing (*fuck*, *cunt*…), sexual crimes and the strongest
  sexual terms (*rape*, *incest*, *pedophile*, *fellatio*). Removed from the dictionary with
  their forms (*fucking*), so they can never be played or shown. One exception: a **curated
  pack** may list blocked words it needs (the Law pack's *rape*, *incest*, *sodomy*,
  *bestiality*, *rapist*, *paedophile*), playable in that pack's worlds only. Organisers' own
  packs can't.
- **Masked** (`data/masked.txt`): crude words and body or sex terms (*arse*, *penis*,
  *orgasm*, *bunghole*, *knackers*, *whore*), and *shit* and its family. They're ordinary
  answers: playable, scoring, counted, and listed in categories where they fit (*penis* in "A
  part of the body"). Other players see them as *p\*\*\*s* in Just said, with a dotted
  underline that shows the word on a tap. Saying a masked word someone else said is free, as
  they only saw it masked. Innocent words formed from one stay visible with a `!` line
  (*!cocked*, *!pricked*).
- **Ordinary words**: words innocent in their main meaning (*tit* the bird, *cock*, *bitch*,
  *ass*, *hooker*, *prick*, *spade*, *queen*, *pansy*, *hoe*, *bimbo*), and pejoratives that
  aren't crude (*papist*, *shiksa*): masking is only for crude words.

## Meaning lists ("A bird", "A tool")

- **Hand-kept files**, one per category, in `data/categories/` (48 lists; format in its
  README). WordNet only drafts a brand-new category, once (`scripts/build-categories.py`).
- **No limit on answers**: every answer a player is likely to think of. A missing answer is
  frustrating and turns into a report to approve; accepting a surprising answer is better than
  refusing a sensible one.
- **Main and also**: *main* answers are clear answers, counted in "words left unsaid" and used
  to size prompts; *also* answers are accepted but not counted (other meanings, specialist
  words, phrases most people wouldn't give), and score without the prompt bonus.
- **Any meaning counts for accepting**: *squash* is a sport. A word is listed as *main* only
  where its main meaning fits: *fly* is an insect, but *does* isn't an animal.
- **Each word listed once, in the most specific category.** A category can include others:
  animal includes mammal, bird, fish, insect, reptile and sea creature; mammal includes dog;
  food includes dessert and dish; vehicle includes boat; "A landscape feature" includes the
  man-made and natural ones. **Drinks are not food.**
- **A category's own name isn't an answer** (*bird* for "A bird").
- **A plural counts if its singular does**, and an irregular one too (*mice* for "A small
  animal").
- **About this list** (a `note:` line): what the list means by its label and its quirks, not
  examples ("Trees, grasses, mosses, lichens, cacti and seaweed count. Fungi don't.").
- Decisions on single answers: *lichen* is a plant; *parsley* is a herb, not a vegetable;
  worms aren't bugs (*earthworm*); *cuckoo spit* is the froth, not the insect.

## Phrases

- Players type phrases naturally: "tea towel", "tea-towel" and "teatowel" are the same answer.
- **Shown the way people write them** (*tea towel*, *t-shirt*, *forget-me-not*, *habeas
  corpus*), plurals too (*tea towels*). In `extra-words.txt` they're written joined
  (`teatowel`); in category and pack files, as written.
- **Well-known phrases count** (*ice cream*, *pole vault*, *dressing gown*, *black hole*,
  *hip hop*); obscure ones are *also* answers. Phrases with no list to belong to aren't added
  (*lucky dip*, *lost property*, *lemon curd*).

## Word packs (Science, Law, Music)

The same rules, plus:

- **Well-known abbreviations are allowed** (not in everyday words), 3+ letters: *dna* counts;
  *rna*, *gbh*, *abh*, *asbo*, *cctv*, *dui* are *also* answers.
- **No names** of people, bands, composers or scientists, nor open-ended sets of named things
  (stars, moons, constellations): the lists would never end. Words named after people that are
  now ordinary words are fine (*newton*, *bunsen*). The planets are in, and the dwarf planets
  (*pluto*, *ceres* counted; *eris*, *haumea*, *makemake* accepted).
- **Forms are separated by commas** in pack files (`quark, quarks`), so phrases fit. A pack can
  leave out everyday forms its words would otherwise bring (*gassing*, *gusting* in Science).
- **Small categories are fine** (subatomic particles, singing voices): the game only picks
  prompts with enough answers.
- **Music, "A part of a song, or a way of playing"** accepts the theory and marking words it
  invites (*rhythm*, *cadence*, *bar*, *measure*, *rest*, *slur*, *trill*, *fermata*,
  *andante*) as *also* answers; "A musician" counts *chanteuse* and accepts *psalmist*,
  *kazooist*, *flugelhornist*, *cymbalist*.

## Changes from reports and playtests

Words added or settled one at a time, newest first. Rule changes go in the sections above.

- **17 October** (local Big Room playtest): added *rafter*, *referrer*, *rubberneck*, *rhymer*.
- **17 October** (Big Room playtest): added *fescue*, *sphagnum*, *colza*, *sea kale* (plants),
  *maltose*, *spruce up* (*spruced up*, *sprucing up*), *mown*; *lichen* counts as a plant.
- **15 October** (players' reports): added *cumulus* (*cumuli*), *succubi*, *uncurl*,
  *upturn*, *bestride* (*bestrode*, *bestridden*), *overwrite* (*overwrote*, *overwritten*),
  *tarmacked*, *dibbed*, *hoed*, *sunstruck*, *flowy*, *blowy*, *glowy*, *devil's coach horse*,
  *mummify*, *mermen*, *alumna*. Not added: *parsley* as a vegetable, *luckless* as a vehicle,
  *earthworm* and *cuckoo spit* as bugs, *title* for "A part of a song", *lucky dip*, *lost
  property*, *lemon curd*.
- **12 to 13 October**: the dictionary review (from 32,700 words to 49,400), the 48 meaning
  lists, phrases, the blocked and masked lists, and the three packs. Details in
  `dictionary-review/` and `word-packs/`.
