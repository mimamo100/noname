# Dictionary review: proposals

Stage 1 of [DICTIONARY-REVIEW.md](../DICTIONARY-REVIEW.md), 12 October 2026. **Nothing has been changed yet.** Reply with anything to leave out, change or add, or "approved".

## Summary

All 31 categories were reviewed. Every proposed word's status was checked with `scripts/category-report.php`. In total:

- **About 1,440 removes**, most of them from the *also* lists (everyday words that only fit through an obscure WordNet sense, such as *permit* as a fish or *world* as a horse). Counts include plural forms.
- **About 2,630 adds**: about 1,110 promoted from *also*, 730 already in the dictionary but in neither list, and 790 NOT IN DICTIONARY (these and their plurals would go into `extra-words.txt`).
- **Three label changes**: kitchen, weather and landform.
- **15 new categories**, measured against WordNet.

### The biggest problems found

1. **The first answers people give are missing from *main*, nearly everywhere.** WordNet files the word's main meaning somewhere else, so it lands in *also* or nowhere. Examples: cod, haddock and tuna (fish); banana and cherry (fruit); carrot and onion (vegetable); oak, ash and birch (tree); hammer and saw (tool); bus and van (vehicle); table (furniture); chess and cricket (sport); worry and guilt (feeling); fog and frost (weather).
2. **Some obvious answers aren't accepted at all.** *Train* isn't a vehicle. *Murder* isn't a crime. *Farmer*, *policeman*, *builder*, *engineer*, *actor* and *priest* aren't jobs. *Water* and *juice* aren't drinks. *Bomb* and *grenade* aren't weapons. *Fridge* and *sink* aren't in kitchen, and *cupboard* and *shelf* aren't furniture. *Office*, *shop* and *factory* aren't buildings. Music has no named dance at all, and no rock, pop or jazz in *main*.
3. **Many everyday words aren't in the dictionary**, so they can't be played anywhere. Examples: plaice, hake, kingfisher, chaffinch, courgette, aubergine, nectarine, prosecco, hob, colander, glockenspiel, maracas, foxglove, snowdrop, optician, anorak and covid. Many plurals are missing too (kiwis, gins, lagers, curries), so a plural answer is rejected even when the singular is fine.
4. **Some categories are swallowed by another.** Every drink is also in food, so *vodka* counts as a food. Animal is built on its own, so fixes to mammal, bird, fish and insect don't reach it (*britt* and *lotte*, removed from fish, are still animals).

### Things to decide besides the word lists

- **Food and drink:** the review proposes removing about 80 drinks from food by hand. A cleaner fix is a rule in `build-categories.py` that keeps drink words out of food. Which do you prefer?
- **Animal:** should animal's *main* automatically include every *main* word of mammal, bird, fish and insect, and drop their removes? Otherwise each fix has to be made twice.
- **Overrides and plurals:** a `-word` override doesn't remove the plural, so some leftovers from earlier fixes are still accepted: *professionals*, *academics*, *coles*, *quiets*, *wormholes*, *cavities*, *pests*. The proposals list them, and stage 2 will write the plurals out. Alternatively, `build-dictionary.php` could remove plurals automatically.
- **Two earlier overrides do nothing:** `+cyan` and `+ochre` (colour), because neither word is in the dictionary. They need adding to `extra-words.txt`.
- **The blocklist blocks whole word families**, so `pubes` also blocks *pub* and *pubs*. *Pricked/pricking*, *cocked/cocking* and *hump/humped* are blocked the same way. *Tit* and *cock* (birds) and *nipple* (body) are blocked directly. Should any of these be playable?
- **Long add lists:** job (about 170), sound (about 115), music (about 110), bird (about 105) and food go past the target of about 60. That's because these categories have the biggest gaps. You can approve them as they are, or ask for a trimmed list.
- **Hyphenated words** (in-laws, half-sister) can't be played at all. That is how the game works, not a list problem.

## Categories

Each section shows the label and current sizes, then the proposed changes. "Promote from also" words are already accepted but don't count. "In dictionary" words are playable but not in the category. Words marked NOT IN DICTIONARY would be added to the dictionary with the plural shown in brackets.

### animal: An animal

Now: 535 main, 567 also.

**Remove:** molly, mollie (obscure fish genus), mademoiselle, lotte, britt, brit, brits (obscure fish senses, already removed from fish), giant, giants (no one means an animal), queen, queens (queen bee/cat only), dominique, dominick, cochin (chicken breeds that read as names), biddy (dialect for hen), mavis, merle (archaic names for thrush, blackbird), ern, ren (archaic spellings of erne, wren), ridley (sea-turtle sense only), bot, bots (botfly larva), feeder, feeders (livestock being fattened), cooter (US turtle), punky (US midge), cooties (US slang for lice), dobson (US larva), fitch (polecat fur), reynard (storybook name for a fox), firebird (WordNet oriole, people think mythical), prancer, charger, chargers, pacer, dun (horse senses nobody gives), beef, beefs (meat), fetus, foetus, fetuses, foetuses (not an animal answer), females, males (female, male already removed), mankind, humankind (collective), world, worlds, entire, head, heads, game, games, grade, grades, poster, posters, plug, plugs, permit, permits, siren, sirens, tinker, sleeper, sleepers, steamer, steamers, poll, polls, stunt, stunts, whiff, spat, spats, lug, pinches, ephemeral, imagines, searcher, searchers, humanities, sweeper, sweepers, schoolmaster, sitter, snowflake, snowflakes, butterball, pacemaker, cardigan, wheeler, blower, grubby, painter, painters, torpedo, torpedoes, sierra, royal, royals, jade, squealer, glutton, gib, hack, hacks, toy, toys, hooter, hooters, solitaire, killer, killers, maverick, mavericks (all from also: everyday words that fit only via an obscure animal sense)

**Add, promote from also:** tiger, whale, donkey, chicken, turtle, butterfly, octopus, leopard, alligator, otter, beaver, badger, squid, shrimp, prawn, lobster, wasp, slug, ostrich, hippo, seal, ferret, skunk, weasel, mink, sloth, chameleon, heron, eel, ram, ewe, foal, cub, fawn, stag, doe, hog, sow, yak, emu, kiwi, raven, swallow, crane, gull, wren, thrush, lark, canary, cuckoo, albatross, dodo, mussel, scallop, crayfish, alpaca, chinchilla, lynx, gibbon, mole, wolverine, adder, boa, tuna, trout, cod, quail, partridge, grouse, scorpion, tick, piranha, bunny, amphibian, crustacean

**Add, in dictionary:** labrador, koi, zander, urchin

**Add, NOT IN DICTIONARY:** ox (oxen already in dictionary), meerkat (meerkats), stoat (stoats), polecat (polecats), dormouse (dormice), gnu (gnus), okapi (okapis), tapir (tapirs), ocelot (ocelots), bonobo (bonobos), marmoset (marmosets), capybara (capybaras), wallaby (wallabies), narwhal (narwhals), dugong (dugongs), pangolin (pangolins), axolotl (axolotls), seahorse (seahorses), limpet (limpets), whelk (whelks), cockle (cockles), woodlouse (woodlice), millipede (millipedes), kingfisher (kingfishers), toucan (toucans), macaw (macaws), cormorant (cormorants), kestrel (kestrels), terrapin (terrapins), plaice (plaice), hake (hake), aphid (aphids already in dictionary), bedbug (bedbugs already in dictionary)

**Notes:** Systematic: animal is built separately from mammal, bird, fish and insect, so their fixes don't flow through (britt, lotte, mademoiselle were removed from fish but are still in animal main; tiger, whale, chicken, butterfly are only also here as in the subcategories). Best fix: make animal main include every main word of those four categories after their review (including their NOT IN DICTIONARY adds), plus the reptile, mollusc and crustacean answers above, and apply their removes here too. The also list is very permissive (567 words, many everyday words via obscure senses); the removes above are only the clearest.

### mammal: A mammal

Now: 221 main, 205 also.

**Remove:** beef, beefs (meat), mankind, humankind (collective, not "a mammal"), dun, pinto (horse colours), fitch (polecat fur), charger, chargers, prancer, pacer, dobbin (rare horse senses), reynard (storybook name for a fox), world, worlds, entire, grade, grades, poster, posters, painter, painters, plug, plugs, glutton, pinches, humanities, wheeler, blower, pacemaker, cardigan, hack, hacks, toy, toys, royal, royals, jade, stock, stocks, killer, killers, squealer, whistler, harpy, brahma, pollard, gib, gray, grey, greys, grays, chestnut, chestnuts, bay, bays, roan, sorrel, devon, welsh, morgan, jersey, jerseys (all from also: everyday words that fit only via an obscure breed, colour or animal sense)

**Add, promote from also:** tiger, whale, dolphin, donkey, leopard, otter, beaver, badger, hippo, seal, ferret, skunk, weasel, mink, sloth, yak, alpaca, chinchilla, lynx, gibbon, mole, wolverine, shrew, gopher, ram, ewe, stag, doe, fawn, hog, sow, vixen, hart, kid, bunny, kitty, pussycat, tabby, pooch, mongrel, bulldog, husky, chihuahua, dalmatian, setter, basset, jackass, primate, mammoth, grizzly, beluga, humpback, ermine, sable, muskrat, chamois, jackrabbit, bighorn, wildcat, thoroughbred

**Add, in dictionary:** lamb, calf, kitten, piglet, foal, cub, colt, filly, labrador

**Add, NOT IN DICTIONARY:** ox (oxen already in dictionary), meerkat (meerkats), stoat (stoats), polecat (polecats), dormouse (dormice), gnu (gnus), okapi (okapis), tapir (tapirs), ocelot (ocelots), bonobo (bonobos), marmoset (marmosets), capybara (capybaras), wallaby (wallabies), narwhal (narwhals), dugong (dugongs), pangolin (pangolins), dromedary (dromedaries), alsatian (alsatians), corgi (corgis), whippet (whippets), lurcher (lurchers), labradoodle (labradoodles)

**Notes:** Tiger, whale, dolphin, donkey and seal, among the first answers anyone gives, are only in also. Young animals (lamb, calf, kitten, piglet, foal, cub, colt, filly) are in the dictionary but missing from mammal entirely, while puppy is main. Oxen is main but ox isn't in the dictionary. Collective words in main (livestock, cattle, carnivore, carnivores) are borderline; left in. Dolphin and beluga are currently main in fish; they belong here (see fish).

### bird: A bird

Now: 102 main, 121 also.

**Remove:** dominique, dominick, cochin (chicken breeds that read as names), biddy (dialect for hen), mavis, merle (archaic names for song thrush, blackbird), ern, ren (archaic spellings of erne, wren), firebird (WordNet oriole, people think mythical), poll, polls (parrot name), sitter (broody hen), snowflake, snowflakes (snow bunting), hooter, hooters, solitaire, butterball, tumbler, tumblers, screamer, scooter, scooters, adjutant, whistler, amazon, amazons, redhead, redheads (obscure species senses of everyday words)

**Add, promote from also:** chicken, raven, swallow, crane, jay, cuckoo, kite, rook, peacock, canary, lark, skylark, partridge, ostrich, quail, wren, albatross, kiwi, gull, grouse, heron, thrush, peregrine, emu, warbler, duckling, teal, martin, drake, merlin, swift, dodo, cardinal, rhea, snipe, bunting, nutcracker, dipper, loon, nighthawk, rail, stint, ruff, knot, squab, hobby

**Add, NOT IN DICTIONARY:** kingfisher (kingfishers), wagtail (wagtails), chaffinch (chaffinches), goldfinch (goldfinches), bullfinch (bullfinches), greenfinch (greenfinches), jackdaw (jackdaws), kestrel (kestrels), tern (terns), moorhen (moorhens), cormorant (cormorants), toucan (toucans), macaw (macaws), budgerigar (budgerigars), curlew (curlews), lapwing (lapwings), plover (plovers), egret (egrets), gannet (gannets), sparrowhawk (sparrowhawks), woodpigeon (woodpigeons), kookaburra (kookaburras), cockatiel (cockatiels), nuthatch (nuthatches), yellowhammer (yellowhammers), linnet (linnets), goldcrest (goldcrests), oystercatcher (oystercatchers), sandpiper (sandpipers), skua (skuas), eider (eiders), ptarmigan (ptarmigans), capercaillie (capercaillies), grebe (grebes), guillemot (guillemots), petrel (petrels), bittern (bitterns), nightjar (nightjars), pipit (pipits), dunnock (dunnocks), waxwing (waxwings), hoopoe (hoopoes), avocet (avocets), shrike (shrikes), harrier (harriers), redwing (redwings), fieldfare (fieldfares), chiffchaff (chiffchaffs), blackcap (blackcaps), siskin (siskins), redstart (redstarts), shelduck (shelducks), wigeon (wigeons), bantam (bantams), cassowary (cassowaries), roadrunner (roadrunners), hornbill (hornbills), oriole (orioles), lorikeet (lorikeets)

**Notes:** Biggest problem is the dictionary: most common British garden, farmland and coastal birds (kingfisher, chaffinch, jackdaw, kestrel, tern, wagtail, moorhen) aren't in it at all. Chicken, raven, swallow, cuckoo and ostrich are only in also. "tit" is on the blocklist, so the UK's commonest garden bird can't be played; worth deciding whether that's intended. Peacock is main in insect (the butterfly sense); it should come out of insect and go into bird main.

### fish: A fish

Now: 26 main, 92 also.

**Remove:** molly, mollie (obscure aquarium genus), coney (a rabbit to anyone in the UK), dolphin, dolphins (a mammal to a UK adult; fish sense is dolphinfish), beluga (the whale, or caviar), permit, permits, sierra, torpedo, torpedoes, tinker, sleeper, sleepers, runner, runners, whiff, hind, grubby, poacher, poachers, argentine, sweeper, sweepers, schoolmaster, drum, drums, sucker, suckers, brit, brits, cisco (from also: everyday words that fit only via an obscure species sense)

**Add, promote from also:** cod, haddock, trout, tuna, mackerel, herring, sardine, anchovy, carp, perch, pike, eel, bass, sole, halibut, turbot, skate, ray, bream, mullet, whiting, pollock, pollack, catfish, piranha, swordfish, hammerhead, manta, monkfish, flounder, snapper, grouper, ling, roach, smelt, char, dory, pout, puffer, blowfish, codfish, bonito, shad, chinook, rockfish, angler, parr, wahoo, porgy

**Add, in dictionary:** koi, zander

**Add, NOT IN DICTIONARY:** plaice (plaice), hake (hake), pilchard (pilchards), sprat (sprats), tilapia (tilapias), lamprey (lampreys), seahorse (seahorses), stickleback (sticklebacks), tench (tench), dace (dace), barbel (barbels), grayling (graylings), gurnard (gurnards), coley (coley), dogfish (dogfish), angelfish (angelfish), clownfish (clownfish), sailfish (sailfish), sunfish (sunfish), lungfish (lungfish), flatfish (flatfish), whitebait (whitebait), gudgeon (gudgeons), loach (loaches), wrasse (wrasses), garfish (garfish), lumpfish (lumpfish), triggerfish (triggerfish)

**Notes:** Worst of the five. Main has only 26 words and several are odd (molly, coney, dolphin, beluga), while every fish a UK adult names first (cod, haddock, trout, tuna, mackerel, sardine) is only in also, and plaice and hake aren't in the dictionary at all. Promoting the also list is the main fix. Kipper is in the dictionary but is a prepared herring, so left out. Dolphin and beluga belong in mammal (already in its also list; proposed for its main).

### insect: An insect or bug

Now: 62 main, 42 also.

**Remove:** queen, queens (queen bee only; reads as royalty or a cat), peacock, peacocks (everyone means the bird; belongs in bird main), punky (US name for a biting midge), cooties (US slang), crab, crabs (crab louse only; a crab isn't a bug), ephemeral (mayfly sense of an adjective), imagines (plural of imago, reads as a verb), searcher, searchers, sawyer, miller, millers, dobson (obscure beetle, moth and larva senses)

**Add, promote from also:** butterfly, wasp, tick, mite, scorpion, roach

**Add, in dictionary:** caterpillar, maggot, grub, larva, nymph, centipede, nit

**Add, NOT IN DICTIONARY:** aphid (aphids already in dictionary), bedbug (bedbugs already in dictionary), greenfly (greenflies), bluebottle (bluebottles), horsefly (horseflies), housefly (houseflies), mayfly (mayflies), cranefly (craneflies), damselfly (damselflies), hoverfly (hoverflies), lacewing (lacewings), silverfish (silverfish), woodlouse (woodlice), millipede (millipedes), glowworm (glowworms), cockchafer (cockchafers), woodworm (woodworms), mealworm (mealworms), sandfly (sandflies), gadfly (gadflies), botfly (botflies), sawfly (sawflies), tsetse (tsetses), caddis (caddises), springtail (springtails), katydid (katydids), yellowjacket (yellowjackets)

**Notes:** Butterfly and wasp, two of the first answers anyone gives, are only in also. Aphids and bedbugs are main but their singulars aren't in the dictionary, so "aphid" can't be played. The label covers spiders and other creepy-crawlies (spider, tarantula already main), so the scorpion, tick, mite, centipede and woodlouse adds fit it. Peacock should move from here to bird main.

### food: A food

Now: 526 main, 661 also.

**Remove:** coffee, coffees, tea, teas, wine, wines, beer, beers, champagne, alcohol, spirits, liquor, whiskey, whiskeys, whisky, whiskies, booze, vodka, vodkas, brandy, brandies, cocktail, cocktails, marc, sherry, tequila, lemonade, potion, potions, martini, martinis, bourbon, bourbons, vintage, ale, margarita, margaritas, latte, lattes, cognac, espresso, cappuccino, cappuccinos, beverage, beverages, cider, schnapps, decaf, java, hooch, eggnog, stinger, stingers, toddy, applejack, vino, liqueur, grog, vermouth, cuppa, seltzer, cabernet, rickey, sidecar, aperitif, daiquiri, daiquiris, tonics, highball, grappa, bitters, bock, spritzer, ouzo, marsala, kirsch, pastis (all drinks, not foods; see Notes), murphy (slang for potato), organs (offal sense only), hay (animal fodder), birdseed (bird feed), diet, diets (an eating plan), vitamin, vitamins, nutrient, nutrients (not foods), concoction (any mixture), grinder (US sandwich slang), cisco (obscure fish), duff (rare pudding sense), mulligan (US stew slang), milt (fish sperm), fryer (young chicken, US), sinker (US doughnut slang), puffer, blowfish (rarely eaten), cookout, potluck, seder (events), teatime (a time of day), delicatessen (a shop), larder (a room), windfall (fallen fruit, rare sense), uglies (plural of a brand name), pease (archaic), must, side, sides, white, whites, special, specials, jack, jacks, kiss, kisses, round, rounds, table, tables, rock, rocks, stick, sticks, mess, messes, green, board, boards, mate, mates, hero, heroes, neck, necks, center, centers, centre, centres, split, splits, cos, delicious, plate, plates, jerk, jerks, media, medium, mediums, emperor, emperors, empire, empires, mix, mixes, grass, grasses, scratch, scratches, western, westerns, bishop, bishops, host, hosts, snap, snaps, blade, blades, flip, flips, sack, sacks, dope, dopes, sucker, suckers, zombie, zombies, collins, brat, brats, napoleon, burton, submarine, submarines, bomber, bombers, dove, doves, mold, molds, mould, moulds, timothy, gem, gems, coral, corals, geneva, slug, slugs, torpedo, torpedoes, farce, wedge, wedges, congo, dodger, dodgers, kosher, eater, eaters, mast, masts, twister, generic, prima, divinity, ess, ails, dainty, coloring, colouring, macon, jumble, forage, fodder, doughboy, undercut, chinook, dowdy, phosphate, pasture, pastures, hays (also-list members that fit only through rare senses, slang, varieties or names)

**Add, promote from also:** cake, egg, fish, beef, lamb, turkey, duck, salmon, tuna, cod, prawn, shrimp, crab, burger, curry, stew, roast, chips, fries, popcorn, cereal, sweets, fudge, trifle, mousse, quiche, crepe, brownie, doughnut, macaroni, taco, jerky, cheddar, mince, rabbit, oyster, mash, stuffing, tart, oats, poultry, pasty, caramel, liquorice, olive, mushroom, onion, carrot, banana, cherry, peach, mango, pineapple, garlic, pheasant, fritter, wafer, chop, rib, relish

**Add, in dictionary:** nut, peanut, almond, cashew, walnut, hazelnut, chestnut, pistachio, crumble, meringue, sourdough, feta, cutlet, kimchi, vindaloo

**Add, NOT IN DICTIONARY:** flapjack (flapjacks), samosa (samosas), naan (naans), chapati (chapatis), poppadom (poppadoms), korma (kormas), tikka (tikkas), biryani (biryanis), dhal (dhals), moussaka (moussakas), fajita (fajitas), ramen (ramens), gyoza (gyozas), satay (satays), teacake (teacakes), kedgeree (kedgerees), blancmange (blancmanges), profiterole (profiteroles), pavlova (pavlovas), biscotti (biscotti), ciabatta (ciabattas), focaccia (focaccias), pitta (pittas), tzatziki (tzatzikis), halloumi (halloumis), camembert (camemberts), stilton (stiltons), parmesan (parmesans), gouda (goudas), gammon (gammons), terrine (terrines), plaice (plaice), hake (hakes), whitebait (whitebait), muesli (mueslis), sweetcorn (sweetcorns)

**Notes:** The biggest problem is that food swallows drink: every word in drink's main list is also in food main, and drink's whole also list sits inside food's also list. A typical UK adult would not answer "vodka" or "espresso" for "A food". The cleanest fix is probably a rule in build-categories.py that drops drink members from food, rather than the long list of -words above; that would also clear the drink-only also members (gin, lager, cola and so on). Deliberately kept: milk, chocolate, chocolates, cocoa, buttermilk (also eaten or used as cooking ingredients). Meanwhile everyday foods (cake, egg, beef, lamb, fish, curry, chips) are only in also. Flapjacks and samosas are already in main but their singulars are not in the dictionary. Plurals missing from the dictionary for proposed words, needed in extra-words.txt if they should play: salmons, tunas, curries, stews, quiches, mousses, hazelnuts, meringues, stuffings, fudges, cheddars (several others are mass nouns and don't matter). Note "hazelnut" is in the dictionary but "hazelnuts" is not.

### fruit: A fruit

Now: 28 main, 59 also.

**Remove:** windfall (any fallen fruit, not a kind of fruit), uglies (only the plural of the ugli brand name), jack, jacks (jackfruit, rare sense), delicious (apple variety), emperor, emperors, empire, empires (apple varieties), cling, clings (clingstone peach), prima (apple variety), wintergreen (a plant and flavouring)

**Add, promote from also:** banana, cherry, peach, plum, pineapple, raspberry, mango, watermelon, lime, blueberry, kiwi, grapefruit, apricot, tangerine, mandarin, satsuma, fig, date, cranberry, pomegranate, papaya, gooseberry, prune, guava, mulberry, quince, cantaloupe, persimmon, durian, huckleberry, tamarind, morello

**Add, in dictionary:** coconut, plantain

**Add, NOT IN DICTIONARY:** nectarine (nectarines), clementine (clementines), lychee (lychees), kumquat (kumquats), passionfruit (passionfruits), blackcurrant (blackcurrants), redcurrant (redcurrants), currant (currants), elderberry (elderberries), loganberry (loganberries), damson (damsons), greengage (greengages), sloe (sloes), bilberry (bilberries), kiwifruit (kiwifruits), pawpaw (pawpaws), starfruit (starfruits), dragonfruit (dragonfruits), jackfruit (jackfruits), breadfruit (breadfruits), physalis (physalis), crabapple (crabapples), medlar (medlars), boysenberry (boysenberries), tayberry (tayberries), lingonberry (lingonberries), cloudberry (cloudberries), rambutan (rambutans), mangosteen (mangosteens), pomelo (pomelos), ugli (uglis), yuzu (yuzus), loquat (loquats), ackee (ackees), bramble (brambles)

**Notes:** Main has only 28 words, and nearly every common fruit (banana, cherry, peach, plum, pineapple, mango) is only in also, because WordNet files their main meaning as the tree or plant. The dictionary itself is short of fruit: nectarine, clementine and lychee are missing, and so are many plurals of words already listed: kiwis, grapefruits, satsumas, pomegranates, mandarins, papayas, guavas, quinces, gooseberries, mulberries, cantaloupes, persimmons, durians, tamarinds, huckleberries, plantains, sultanas. These need adding to extra-words.txt or the plural answers will be rejected. Tomato, rhubarb and olive are "not in this category"; tomato is the classic pub-quiz fruit and rhubarb is commonly treated as one in the UK, so consider accepting both (rhubarb is already in vegetable main). Pippin, citrus and sultana are a bit loose but defensible, so left alone.

### vegetable: A vegetable

Now: 30 main, 77 also.

**Remove:** pease (archaic), green (singular; "greens" covers it), chips, fries (potato dishes, not vegetables), snap, snaps (snap bean, US), cole, coles (archaic word for cabbage), mustard (a condiment), chilly (misspelling sense), gumbo (a stew), dahl (a dish), cayenne (a spice), julienne (a way of cutting)

**Add, promote from also:** carrot, onion, pepper, mushroom, cucumber, lettuce, broccoli, cauliflower, spinach, sprout, pumpkin, leek, celery, parsnip, turnip, swede, beetroot, asparagus, kale, squash, marrow, zucchini, artichoke, fennel, beet, yam, okra, chard, spud, savoy, iceberg, cos, shallots, chickpeas, plantain, taro, chicory, sorrel, jalapeno, pimento, soybean, soya, pulse, truffle

**Add, in dictionary:** corn, garlic, gourd, rocket, seaweed

**Add, NOT IN DICTIONARY:** courgette (courgettes), aubergine (aubergines), sweetcorn (sweetcorns), shallot (shallots), chickpea (chickpeas), cress (cresses), watercress (watercresses), mangetout (mangetout), gherkin (gherkins), celeriac (celeriacs), kohlrabi (kohlrabis), endive (endives), radicchio (radicchios), butternut (butternuts), capsicum (capsicums), cassava (cassavas), scallion (scallions), salsify (salsifies), samphire (samphires), edamame (edamame), beansprout (beansprouts), tuber (tubers)

**Notes:** Same pattern as fruit: almost every everyday vegetable (carrot, onion, pepper, broccoli, cucumber) is only in also, so the 30-word main list is dominated by beans, lentils and oddities. The UK names courgette and aubergine are missing from the dictionary entirely (zucchini and eggplant are in), so UK players lose the most natural answers; the UK/US rule only helps once both are in. Shallots and chickpeas are in also but their singulars are not in the dictionary (listed above as NOT IN DICTIONARY; their plurals are already there). Plurals missing from the dictionary for proposed words: parsnips, lettuces, marrows, squashes, beetroots, jalapenos, pimentos, plantains, savoys, gourds, taros. Tater, taters and veg are slangy but fine. Rhubarb in main is botanically right; adding it to fruit as well would be fine.

### drink: A drink

Now: 82 main, 101 also.

**Remove:** vintage (a year of wine, not a drink), chocolates (sweets; the drink sense has no plural in use), bitters (a cocktail flavouring), must (unfermented grape juice), mate, mates (yerba mate, rare sense), cup, cups (fruit cup, rare sense), bishop, bishops (archaic mulled wine), corn, corns (corn whiskey, US), flip, flips (archaic eggy drink), sack, sacks (archaic sherry), dope, dopes (US slang for cola), burton (old slang), geneva (archaic word for gin), slug, slugs (a swig, not a kind of drink), herbal (adjective), cobbler, cobblers (rare cocktail sense), generic (unbranded product), bootleg (illicit liquor sense), macon (wine region, rare), refresher (vague), suds (US slang for beer), chicory (coffee substitute), phosphate (old US soda fountain drink)

**Add, promote from also:** gin, rum, lager, cola, coke, soda, pop, punch, port, smoothie, scotch, stout, porter, bitter, perry, mead, sake, rose, bubbly, tonic, cordial, mocha, brew, plonk, merlot, chardonnay, claret, riesling, chablis, burgundy, bordeaux, madeira, absinthe, moonshine, nectar, mixer, fizz, manhattan, screwdriver, mimosa, sling, rye, malt, sarsaparilla, hock, chartreuse, cooler, elixir, sour

**Add, in dictionary:** water, juice, squash, shake, sangria, snakebite, cosmopolitan, nightcap, dram, chaser, slush, infusion, camomile, chamomile, oolong, raki, pinot, kir

**Add, NOT IN DICTIONARY:** milkshake (milkshakes), prosecco (proseccos), cava (cavas), shandy (shandies), mojito (mojitos), chai (chais), americano (americanos), macchiato (macchiatos), frappe (frappes), kombucha (kombuchas), negroni (negronis), bellini (bellinis), orangeade (orangeades), sambuca (sambucas), amaretto (amarettos), limoncello (limoncellos), advocaat (advocaats), calvados (calvados), armagnac (armagnacs), rioja (riojas), chianti (chiantis), shiraz (shirazes), malbec (malbecs), sauvignon (sauvignons), zinfandel (zinfandels), beaujolais (beaujolais), lambrusco (lambruscos), retsina (retsinas), pilsner (pilsners), mezcal (mezcals), pisco (piscos), julep (juleps), gimlet (gimlets), lassi (lassis), kefir (kefirs), matcha (matchas), darjeeling (darjeelings), assam (assams), rooibos (rooibos), tisane (tisanes), tipple (tipples), snifter (snifters), digestif (digestifs)

**Notes:** The most obvious answers after tea and coffee are either not in the category at all (water, juice, squash) or only in also (gin, rum, lager, cola); water and juice being rejected outright is the worst gap. Several modern UK staples are missing from the dictionary: prosecco, milkshake, shandy, mojito, americano. Many plurals are missing too and need adding to extra-words.txt: gins, rums, lagers, colas, stouts, meads, merlots, chardonnays, clarets, rieslings, madeiras, cordials, mochas, nectars, manhattans, screwdrivers, squashes, sangrias, snakebites, cosmopolitans, nightcaps, drams, infusions, oolongs. The whole drink category also sits inside food (see food.md); the fix belongs on the food side. Potion, java, marc, rickey and stinger are a bit odd but are genuine drinks, so left in.

### clothing: Something you wear

Now: 234 main, 236 also.

**Remove:** laundry (washing, not a garment), diamond, diamonds, ruby, rubies, solitaire, sparkler, sparklers (gems, not things you wear), bead (not worn as such), bijou (trinket), trappings (figurative), furnishings (US "men's furnishings" sense only), wedgie (a prank), weeds (widow's weeds, archaic), wrapper, wrappers (packaging to a UK adult), trousseau (a bride's collection, not one item), pin, pins (only via the lapel-pin sense)

**Add, promote from also:** skirt, belt, glove, shorts, jumper, top, pants, bikini, outfit, ring, bracelet, helmet, hood, cloak, cape, brooch, frock, corset, tunic, thong, briefs, slip, nappy, jersey, bowler, trilby, panama, fez, sombrero, choker, clog, loafer, tutu, smock, girdle, stole, gauntlet, scrubs, habit, mackintosh

**Add, in dictionary:** trainer, hoodie, fleece, tracksuit, glasses, sunglasses, watch, stiletto, locket, pendant, bandana, headband, polo, bib, garter, heels (add the plural directly: "heel" alone is not something you wear)

**Add, NOT IN DICTIONARY:** wellington (wellingtons), welly (wellies), anorak (anoraks), cagoule (cagoules), onesie (onesies), dungarees (plural only, add as is), gilet (gilets), pinafore (pinafores), plimsoll (plimsolls), sarong (sarongs), kaftan (kaftans), camisole (camisoles), hijab (hijabs), burka (burkas), wetsuit (wetsuits), cufflink (cufflinks), bowtie (bowties), jodhpurs (plural only, add as is), boater (boaters), stetson (stetsons)

**Notes:** Skirt, belt, glove, shorts, jumper and top all being only also is the biggest gap. Several plurals are in main while their singular is not in the dictionary at all (moccasins, galoshes, leggings), so the singular can't be played; worth adding moccasin and legging to extra-words.txt. Jewellery is inconsistent: necklace, earring and bangle are main but ring, bracelet and brooch only also, which the promotions fix. "smalls" (underwear) sits in body main and belongs here instead.

### body: A part of the body

Now: 322 main, 423 also.

**Remove:** costa (Latin for rib), puss (US slang for face), buns (US slang), sticker, stickers (no body sense a player would know), horseback (riding term), orb, orbs (poetic for eye), nucleus, nuclei (cell biology), fuzz (slang for hair or police), leaflet, leaflets (heart-valve sense only), pathway, pathways (nerve-pathway sense), smalls (underwear), rami (anatomical Latin), tunic (tissue-layer sense), res, ora, ala, vas, vasa, vena, concha, pedes, duras (Latin anatomical fragments), frills, cistern, acumen (nonsense here), facials (beauty treatments), neb (dialect), prat (archaic buttocks sense), shunt (medical device), cellulite (not a part), receptor, receptors (biochemistry), lid, lids (eyelid is already there)

**Add, promote from also:** heart, hair, bottom, bum, backside, rib, heel, calf, sole, gum, appendix, temple, iris, abs, bust, bosom, molar, digit, skeleton, backbone, jawbone, diaphragm, prostate, ovary, placenta, crotch, butt, whisker, fang, tusk, horn, gill, fin, antenna, tentacle, muzzle, flank, pore, membrane, hamstring, jugular, loin, hock, withers, stinger, pincer, gob, kisser

**Add, in dictionary:** navel, bellybutton, eyebrow, eyelash, pupil, palate, fingertip, nape, mane

**Add, NOT IN DICTIONARY:** tonsil (tonsils), incisor (incisors), bicep (biceps), tricep (triceps), pec (pecs), glute (glutes), midriff (midriffs), uvula (uvulas), adenoid (adenoids), cuticle (cuticles), ribcage (ribcages), patella (patellae), cochlea (cochleas), haunch (haunches), jowl (jowls), instep (insteps), sideburn (sideburns), tum (tums)

**Notes:** The worst gap is heart, hair and bottom being only also. Main is padded with Latin anatomical fragments (res, ora, ala, vas, vena, pedes, duras) that look like WordNet abbreviations and would never be answers. Animal parts (tail, wing, paw, beak, gizzard) are in main, which suits "a part of the body", so promoting fin, gill and tusk keeps things consistent. Plurals tonsils, incisors and molars are in main though tonsil and incisor are not in the dictionary. Nipple, penis, vagina, anus, clitoris, hump and arse are blocked, so not proposed.

### job: A job

Now: 450 main, 318 also.

**Remove:** bunny, bunnies (Playboy sense), batman (officer's servant, archaic), gypsy, gypsies, gipsy (an ethnic group, not a job), accomplice, accomplices (role in a crime), vigilante, vigilantes (not a job), veteran, veterans (former soldier), professionals, academics (plurals of words already removed by override), rocker (music fan), gob, gobs (US sailor slang), hanger, hangers (coat hanger), topper (hat), hakeem, hakim (rare), lovely, lovelies (not a job), feller, fellers (read as "fellow"), reb, rebs (US Civil War soldier), iceman, demonstrators, punter, punters (customer or gambler), wiper, wipers (windscreen), router (network device), trawler (boat), yuppie, yuppies (lifestyle label), shifter, thrower, teaser, earner, breadwinner, caster, preserver, setter, scrubber, trier, scratcher (agent nouns no one would call a job), subsidiaries (companies), reaper, reapers (grim reaper), excavator, harvester, digger, diggers (machines), esquire (title), subordinate, subordinates, underling, underlings (rank relation, not a job), lackey, lackeys, flunky, peon, peons (insults), bridesmaid, bridesmaids (wedding role), mannequin, mannequins (dummy), doughboy, redcoats, highlanders (historical labels), doge (Venetian ruler), wrecker, blaster, blasters, stamper, washer, washers (machine or tap washer), man, men, woman, women, gentleman, gentlemen (not jobs), second, seconds (time), familiar (witch's familiar), regular, regulars, rat, rats, wave, waves, federal, slave, slaves, cookie, cookies, cooky, proves, stroke, strokes, temporary, independent, independents, rebel, rebels, instrument, instruments, pawn, pawns, poster, posters, hangover, hangovers, coyote, coyotes, supporter, supporters, decoy, decoys, mace, gallant, voluntary, provincial, settler, settlers, checker, checkers, seasonal, territorial, mans, swagger, pliers, scab, scabs, fodder, tier, tiers, bleachers, kamikaze, cog, cogs, wetter, poser, floater, lather, sourdough, gutter, gutters, confederate, confederates, marchers, tanker, tankers, privates, wheeler, peeler, puller, searcher, searchers, shill, itinerant, menial, flyer, flyers, flier, fliers, processor, processors, lister, hire, hires, stalker, stalkers, resident, residents (all fit only through a rare sense)

**Add, promote from also:** driver, secretary, baker, butcher, gardener, painter, cleaner, barber, model, coach, fireman, chemist, solicitor, referee, conductor, minister, caretaker, agent, shepherd, ranger, groom, steward, analyst, teller, joiner, mason, marine, stylist, counsellor, medic, intern, publisher, hostess, smith, miller, cooper, tanner, thatcher, usher, dishwasher, matron, deputy, scribe, sexton, caddie, recruiter, freelancer, chancellor

**Add, in dictionary:** farmer, policeman, policewoman, firefighter, builder, accountant, engineer, architect, scientist, artist, actor, actress, singer, musician, writer, author, journalist, reporter, banker, manager, vicar, priest, carer, shopkeeper, cashier, nanny, therapist, psychologist, decorator, chauffeur, cabbie, courier, janitor, guard, bouncer, detective, constable, inspector, sheriff, spy, astronaut, programmer, designer, photographer, dancer, comedian, clown, magician, presenter, newsreader, producer, translator, interpreter, economist, tailor, jeweller, florist, grocer, greengrocer, fishmonger, landlord, nun, monk, rabbi, imam, chaplain, jockey, trainer, umpire, footballer, bodyguard, doorman, auctioneer, surveyor, optometrist, sculptor, poet, novelist, composer, gamekeeper, zookeeper, curator, cobbler, seamstress, bookkeeper, coroner, warden, caterer, stockbroker, consultant, politician, mayor

**Add, NOT IN DICTIONARY:** optician (opticians), plasterer (plasterers), roofer (roofers), glazier (glaziers), dustman (dustmen), binman (binmen), physiotherapist (physiotherapists), childminder (childminders), newsagent (newsagents), publican (publicans), barista (baristas), headteacher (headteachers), anaesthetist (anaesthetists), radiographer (radiographers), chiropodist (chiropodists), osteopath (osteopaths), farrier (farriers), stonemason (stonemasons), scaffolder (scaffolders), tattooist (tattooists), actuary (actuaries), tiler (tilers), machinist (machinists), upholsterer (upholsterers), milliner (milliners), gravedigger (gravediggers), deckhand (deckhands), signalman (signalmen), draughtsman (draughtsmen), hotelier (hoteliers), silversmith (silversmiths), ghillie (ghillies)

**Notes:** The biggest hole of my five categories: almost every everyday job word is missing from main, and most are not even in also (farmer, policeman, builder, engineer, accountant, actor, singer, writer, priest), apparently because WordNet files them under other kinds of person. The adds go well past 60 because of this. More good in-dictionary adds if wanted: forester, beekeeper, brewer, archivist, columnist, playwright, pianist, drummer, guitarist, ballerina, choreographer, stuntman, cameraman, illustrator, cartoonist, goldsmith, watchmaker, masseur, masseuse, rector, pastor, coastguard, researcher, jailer, gaoler, warder, innkeeper, broker, trader, auditor, administrator, executive, councillor, fitter, developer, director, broadcaster, juggler, astronomer, physicist, biologist, archaeologist, historian, chiropractor, dressmaker, concierge, trucker, entrepreneur, businessman, inventor. Main leans heavily on military ranks and historical servants (commissar, vizier, generalissimo, hussars); fine as rare answers. The remove list is long but mostly from also, where sense matching dragged in agent nouns (thrower, earner, trier) and words like man and woman.

### relative: A family member

Now: 132 main, 49 also.

**Remove:** babe, babes (sweetheart, not a relation), signora (Italian title), matron (job or older woman), housewife, housewives, homemaker (roles, not relations), marchioness (title), cherub, cherubs (angel or sweet child), foundling (abandoned child, the opposite of a relation), honeymooners, newlywed, newlyweds (a couple, not a family member), quad, quads (only via quadruplet)

**Add, promote from also:** mum, child, kid, kin, relation, firstborn, heir, mammy, mater, groom, patriarch

**Add, in dictionary:** nana, nanny, godmother, godfather, matriarch, bro, grandkids (add the plural directly: the singular is not in the dictionary)

**Add, NOT IN DICTIONARY:** mam (mams), stepmum (stepmums), stepdad (stepdads), stepchild (stepchildren), stepparent (stepparents), godparent (godparents), godchild (godchildren), nanna (nannas), forebear (forebears), forefather (forefathers, already in the dictionary), triplet (triplets, already in the dictionary), grandkid (grandkids, already in the dictionary), wifey (wifeys), grampa (grampas)

**Notes:** The headline problem is "mum", the commonest UK answer, being only also while US "mom" is main. Child and kid are also only also. In-laws and half-siblings are normally hyphenated (mother-in-law, half-sister), so they can't be played under the a-z only rule; nothing to do there, but it explains the gap. Ma and pa are too short for the 3-letter minimum.

### feeling: A feeling

Now: 263 main, 269 also.

**Remove:** despite (archaic "contempt" sense), harassment (an act, not a feeling), dignity (a quality), ego, egos (the self), sincerity (a quality), penance (an act), preference, preferences (a choice), fetish, fetishes (an object or fixation), seriousness (a quality), grievance, grievances (a complaint), caprice (a whim, a trait), grieves, relieves (verb forms), warpath (idiom only), lividity (medical, post-mortem), throes (struggle), compatibility (a relation between things), phlegm (mucus to any UK adult), bereavement (an event), heartstrings (idiom only), sadism (a trait), propensity (a tendency), stewing (cooking), wildness (a quality), undertow (a current), conceit (a trait or literary device), tantrum, tantrums (behaviour), lightness (weight sense dominates), stupor (a state)

**Add, promote from also:** worry, guilt, hurt, pain, sympathy, anxiety, despair, misery, loneliness, depression, confusion, confidence, warmth, tenderness, craving, urge, annoyance, irritation, impatience, serenity, calmness, tranquillity, elation, loathing, dislike, fascination, insecurity, helplessness, humiliation, respect, gloom, torment, concern, triumph, comfort, peace, hostility, restlessness, agitation, nausea, itch, tingle, fatigue, woe, discomfort, reverence, sensation

**Add, in dictionary:** curiosity, bliss, unease, calm, stress, tension, doubt, upset, suspicion, trust, awkwardness, tiredness, exhaustion, weariness, hunger, thirst, vulnerability, lethargy, numbness, ache, nerves, jitters, butterflies (the last three as plurals directly: their singulars mean something else)

**Add, NOT IN DICTIONARY:** homesickness (no plural), exasperation (no plural), bewilderment (no plural), cheerfulness (no plural), despondency (no plural), dejection (no plural), disquiet (no plural), wistfulness (no plural), smugness (no plural), schadenfreude (no plural), ennui (no plural), giddiness (no plural), sleepiness (no plural), thankfulness (no plural)

**Notes:** Systematic problem: the commonest negative feelings (worry, guilt, anxiety, despair, misery, loneliness, pain, hurt) are all only also, while main carries traits, acts and verb forms. Physical sensations (hunger, thirst, tiredness, pain) are worth accepting since "a feeling" covers them for most people, and appetite is already main. Depression and anxiety also belong in disease; the overlap is fine.

### tool: A tool

Now: 47 main, 117 also.

**Remove:** sawmill (a building), cutlery (eating utensils, not a tool), benders (plural of the already removed bender), spreader (rare sense), rammer (rare), upset, upsets (rare metalworking sense), gang, gangs (rare sense), style, styles (rare sense), drove, droves (mason's chisel, rare), bill, bills (billhook, rare), slick (rare chisel), starter, starters (rare sense), gutter, gutters (rare sense), beetle, beetles (heavy mallet, rare), muller (grinding stone, rare), lister (plough, rare), scuffle (hoe, rare), heckle (flax comb, rare), rounder (rare sense), clincher (rare sense), pallet, pallets (potter's paddle, rare), slice, slices (rare sense), stamp, stamps (rare sense), lance, lances (a weapon), ram, rams (rare sense), hack, hacks (rare sense), scribe, scribes (marking tool, rare), spud, spuds (weeding tool, rare), crank, cranks (machine part), stamper (rare), pounder (rare), bolo (machete, rare)

**Add, promote from also:** hammer, saw, drill, wrench, spade, rake, mallet, trowel, sledgehammer, hatchet, scythe, shears, clippers, scissors, plane, file, pick, fork, jack, punch, rasp, gouge, router, cutter, plunger, maul, dibble, float

**Add, in dictionary:** crowbar, level, ruler, vice, clamp, ladder, wheelbarrow, tongs, anvil, lathe, stapler, paintbrush, brush, broom, mop, sandpaper, wedge, grinder, blowtorch, needle, whisk, ladle, grater, peeler

**Add, NOT IN DICTIONARY:** secateurs (plural only), jemmy (jemmies), awl (awls), bradawl (bradawls), mattock (mattocks), strimmer (strimmers), scraper (scrapers), handsaw (handsaws), fretsaw (fretsaws), adze (adzes), billhook (billhooks), loppers (plural only), whetstone (whetstones), protractor (protractors)

**Notes:** Core tools (hammer, saw, drill, wrench, spade, rake, scissors) are only in also, so the counter undercounts badly; crowbar, ladder, vice and clamp aren't in the category at all. Kitchen items (whisk, ladle, grater, peeler, sieve, corkscrew) overlap with "kitchen"; that's fine as long as both accept them. The also list is full of rare WordNet tool senses (drove, slick, heckle, scuffle, muller); worth pruning hard.

### vehicle: A vehicle

Now: 164 main, 189 also.

**Remove:** missile, missiles (a weapon, not a vehicle), shipwreck (a wreck, not a vehicle), kamikaze (the pilot or attack, not a vehicle), clarence (obscure carriage), stanhope (obscure carriage), shay (archaic chaise), hoy (obscure barge), nautilus (rare submarine sense), gig, gigs (obscure carriage sense), electric, electrics (rare sense), amphibians (rare sense), berlin (obscure carriage), ordinary (penny-farthing, obscure), bottom, bottoms (rare ship sense), flat, flats (rare sense), tower, towers (rare sense), technical (rare sense), sausage, sausages (rare sense), jolly, jollies (rare boat sense), pirate, pirates (rare ship sense), heap, heaps (slang, rare), dandy (rare boat sense), picket, pickets (rare sense), smoker, smokers (rare sense), pusher, pushers (rare sense), dinky (rare), stinger, stingers (a missile), sharpshooter, sharpshooters (rare sense), sharpie (rare boat sense), sidewinder (a missile), loaner (rare sense), derelict (rare sense), bogey, bogeys, bogie (a wheel unit, rare), shell, shells (rowing boat, rare), lighter, lighters (barge, rare), cat, cats (rare sense), hack, hacks (rare sense), packet, packets (packet boat, rare), smack, smacks (rare boat sense), tramp, tramps (tramp steamer, rare), wreck, wrecks, wrecker (not a vehicle in normal use), projectile, projectiles (not a vehicle), limber (gun carriage, obscure), machine, machines (slang, rare)

**Add, promote from also:** bus, van, coach, bike, motorbike, motorcycle, cab, ferry, tram, canoe, kayak, caravan, trailer, sleigh, sledge, moped, tug, liner, steamer, locomotive, camper, saloon, engine, sub, chopper, drone, trawler, schooner, snowmobile, blimp, seaplane, punt, unicycle, tandem, motorboat, steamroller, landau

**Add, in dictionary:** train, minibus, estate, raft, digger, crane, monorail, tube, metro, shuttle, zeppelin, jumbo

**Add, NOT IN DICTIONARY:** campervan (campervans), motorhome (motorhomes), catamaran (catamarans), pushchair (pushchairs), toboggan (toboggans), narrowboat (narrowboats), pedalo (pedalos), hydrofoil (hydrofoils), biplane (biplanes), dodgem (dodgems), kart (karts), minicab (minicabs), bobsleigh (bobsleighs), longboat (longboats), hansom (hansoms)

**Notes:** "train" is not in the category at all, and bus, van, coach, bike, motorbike, ferry and tram are only in also: the most obvious answers aren't counted. Overlap with "weapon": missile, stinger and sidewinder belong there, not here (tank, bomber and destroyer are fine in both). Main is padded with archaic carriages (clarence, stanhope, shay) while modern UK ones (estate, minibus, campervan) are missing.

### instrument: A musical instrument

Now: 46 main, 56 also.

**Remove:** wind, winds (a family of instruments, not one), woods, wood (orchestra section), brass (section, not an instrument), string, strings (section or part), reed, reeds (part of an instrument), trump, trumps (Jew's harp, obscure), kettle, kettles (kettledrum, obscure), syringes (panpipes, obscure), flue (organ pipe), drone, drones (bagpipe part), bones (folk percussion, obscure sense)

**Add, promote from also:** organ, recorder, triangle, horn, bell, whistle, viola, lute, gong, sax, synthesizer, bass, snare, chime, pipe, calliope, virginal, serpent, baritone, clarion

**Add, in dictionary:** keyboard, synth, conga, rattle, shaker

**Add, NOT IN DICTIONARY:** glockenspiel (glockenspiels), maraca (maracas), castanet (castanets), didgeridoo (didgeridoos), timpani (plural only), euphonium (euphoniums), sousaphone (sousaphones), flugelhorn (flugelhorns), marimba (marimbas), vibraphone (vibraphones), tabla (tablas), balalaika (balalaikas), zither (zithers), dulcimer (dulcimers), theremin (theremins), ocarina (ocarinas), panpipes (plural only), bodhran (bodhrans), concertina (concertinas), melodeon (melodeons), spinet (spinets), clavichord (clavichords), celesta (celestas), kettledrum (kettledrums), djembe (djembes), cowbell (cowbells), washboard (washboards), bouzouki (bouzoukis), uke (ukes), viol (viols), sackbut (sackbuts), crumhorn (crumhorns), shawm (shawms), autoharp (autoharps), pianola (pianolas), kalimba (kalimbas)

**Notes:** The biggest gap is the dictionary, not the category: well-known instruments (glockenspiel, maracas, castanets, didgeridoo, timpani, euphonium) aren't playable at all, so this category needs a batch of extra-words.txt additions. Organ, recorder, triangle and keyboard are first-thought answers that are only in also or missing. Overlap with "music" (kinds of music or dance) is minor.

### furniture: A piece of furniture

Now: 42 main, 63 also.

**Remove:** uppers (shoe uppers or drugs, not furniture), sectionals (US term, and its singular isn't even in the dictionary), wheelchair, wheelchairs (a mobility aid, not furniture), altar, altars (church fixture, not furniture to most), lower, lowers (rare sense), upper (rare sense), sack, sacks (rare sense), puff, puffs (rare sense), file, files (rare sense), box, boxes (rare sense), board, boards (rare sense), press, presses (rare cupboard sense), checkout (a till, not furniture), commissary (rare sense), deathbed (an occasion, not furniture), credence (obscure church table), squab (obscure), berth (a ship's bunk, rare), sleeper, sleepers (rare sense), secretary, secretaries (US desk sense, rare in UK)

**Add, promote from also:** table, chest, sideboard, cot, bunk, ottoman, bureau, closet, rocker, chesterfield, commode, settle, davenport, console, vanity, hammock, stand, seat, nest, lamp

**Add, in dictionary:** cupboard, shelf, bookshelf, futon, beanbag, nightstand, mattress, hutch, playpen, podium, rack, screen, mirror, trunk, locker, headboard, easel, whatnot

**Add, NOT IN DICTIONARY:** footstool (footstools), recliner (recliners), divan (divans), pouffe (pouffes), highchair (highchairs), deckchair (deckchairs), bunkbed (bunkbeds), sofabed (sofabeds), daybed (daybeds), loveseat (loveseats), tallboy (tallboys), dumbwaiter (dumbwaiters), hatstand (hatstands), coatstand (coatstands), workbench (workbenches), lectern (lecterns), sunlounger (sunloungers), lounger (loungers), barstool (barstools), credenza (credenzas), washstand (washstands), escritoire (escritoires), chiffonier (chiffoniers), hassock (hassocks), bassinet (bassinets), shelving (no plural), bedstead (bedsteads)

**Notes:** "table" is only in also and "cupboard" and "shelf" aren't in the category at all: three of the first five answers anyone would give. Main is small (42) and padded with plurals and odd entries (uppers, sectionals). Many UK furniture words (footstool, pouffe, divan, deckchair, highchair) need adding to the dictionary.

### kitchen: Something in a kitchen

Now: 33 main, 40 also.

**Remove:** chalice (church cup), cauldron, caldron (witches' pot, not kitchen kit), argyle (obscure gravy server), boat, boats (gravy boat, only via a rare sense), spider, spiders (old frying-pan sense), turner (US word for a fish slice, rare sense), grid, grids (rare griddle sense), popper, poppers (popcorn popper, rare sense), primus (camping stove)

**Add, promote from also:** bowl, microwave, toaster, whisk, cutlery, teaspoon, teacup, casserole, steamer, juicer, griddle, mixer, china, range, urn

**Add, in dictionary:** fridge, refrigerator, freezer, sink, tap, dishwasher, knife, plate, mug, glass, jug, ladle, sieve, tray, tin, jar, bin, cupboard, counter, apron, scales, timer, peeler, corkscrew, opener, tongs, chopstick, cleaver, mortar, funnel, grill, processor, platter, saucer, tumbler, flask, grinder, fryer, mould, cutter, scoop, skewer, rack, napkin, coaster, extractor, foil, cookbook, pitcher, decanter, dresser, aga, shaker, burner, utensil, crock, sponge, thermometer

**Add, NOT IN DICTIONARY:** colander (colanders), hob (hobs), worktop (worktops), tablespoon (tablespoons), pestle (pestles), strainer (strainers), cafetiere (cafetieres), percolator (percolators), masher (mashers), zester (zesters), ramekin (ramekins), tureen (tureens), stockpot (stockpots), tagine (tagines), trivet (trivets), carafe (carafes), baster (basters), placemat (placemats), serviette (serviettes), breadboard (breadboards)

**Label:** "A piece of kitchen equipment" (the current label invites food, tables, chairs and the cat; the list is cookware, crockery and appliances, and the additions above bring in fittings like sink and tap)

**Notes:** The category is tiny for its prompt: fridge, sink, knife, plate, mug and glass, which most people would say first, are all missing, while chalice and argyle are in main. The UK words hob, colander and worktop are not in the dictionary. Plurals missing from the dictionary for proposed words: dishwashers, ladles, sieves, peelers, corkscrews, cleavers, funnels, grinders, fryers, extractors, cookbooks, decanters, dressers, crocks, whisks, teacups, juicers, griddles, casseroles, thermoses. Overlap: dresser also fits furniture, and knife, cleaver and scissors-type items may overlap with tool or weapon; that's fine as long as they are clear answers here.

### building: A building or room

Now: 210 main, 142 also.

**Remove:** cab, cabs (a taxi or driver's cabin, not a building or room), architecture (the subject, not a building), locker, lockers (a cupboard, not a room), caboose (a railway wagon), jakes (archaic word for toilet), cuddy (obscure small cabin), bethel (obscure chapel sense), can, cans (US slang for toilet), well, wells (rare sense), head, heads (ship's toilet, rare), hold, holds (ship's hold, not a room to most), floor, floors (a storey, not a room), door, doors (part of a building), box, boxes (rare sense), car, cars (rare lift sense), ice, ices (rare sense), tank, tanks (prison slang, rare), boot, boots (rare sense), trunk, trunks (rare sense), bay, bays (rare sense), cabinet, cabinets (rare room sense), khan (obscure caravanserai), engineering (not a building), oracle (rare sense), ritz (a brand name), shambles (obscure slaughterhouse sense), ers (abbreviation), diwan (obscure), cooler, coolers (prison slang, rare), rotisserie (rare restaurant sense), gondola (rare sense), module, modules (rare sense), johns (US slang for toilet)

**Add, promote from also:** school, church, hall, cinema, clinic, gallery, villa, mansion, lodge, study, cell, dungeon, conservatory, larder, surgery, chamber, vault, den, canteen, mortuary, kennel, lounge

**Add, in dictionary:** office, shop, factory, warehouse, station, prison, jail, flat, apartment, tower, lighthouse, windmill, mill, stadium, museum, bank, supermarket, airport, hut, fort, barracks, hangar, workshop, attic, loft, cellar, basement, hallway, corridor, crypt, college, laboratory, penthouse, mausoleum, pavilion, pigsty

**Add, NOT IN DICTIONARY:** ensuite (ensuites), bedsit (bedsits), maisonette (maisonettes), launderette (launderettes), carport (carports), vestry (vestries), gurdwara (gurdwaras), aviary (aviaries), dovecote (dovecotes), bothy (bothies), treehouse (treehouses)

**Notes:** "pub" is blocked: blocklist.txt has "pubes", and build-dictionary.php blocks a blocked word's whole family, so pub and pubs go with it. Pub is probably the most common UK answer here and a normal word everywhere else, so it should be unblocked (the family rule likely catches other innocent words too, worth a check). School, church and hall are only in also, and office, shop, factory, prison, flat, attic and cellar aren't in the category at all, so main leans towards hotels and toilets (loo, privy, latrine, jakes, can, head, johns) while missing everyday buildings.

### weapon: A weapon

Now: 60 main, 71 also.

**Remove:** bern (not a word in English use, a city; probably a stray form of bren), grape, grapes (grapeshot, obscure), tuck, tucks (rapier, obscure), quarrel, quarrels (crossbow bolt, obscure), brand, brands (poetic for sword), steel (poetic), crease, creases (variant of kris, obscure), ball, balls (rare sense), bar, bars (rare sense), seeker, seekers (rare missile sense), sticker, stickers (rare sense), shaft, shafts (rare sense), slasher (rare sense), partisan, partisans (obscure polearm sense), rod, rods (gun slang, rare), peacekeeper, peacekeepers, peacemaker (rare missile and revolver senses)

**Add, promote from also:** knife, bow, arrow, cannon, pike, sabre, javelin, tomahawk, boomerang, dart, sling, foil, blade, pellet, colt, automatic, semiautomatic

**Add, in dictionary:** bomb, grenade, axe, club, catapult, truncheon, baton, torpedo, mine, rocket, bazooka, mortar, artillery, harpoon, slingshot, whip, nuke, napalm, flail, bludgeon, warhead, dynamite, explosive, blaster

**Add, NOT IN DICTIONARY:** rapier (rapiers), cudgel (cudgels), cosh (coshes), knuckleduster (knuckledusters), nunchucks (plural only), katana (katanas), broadsword (broadswords), epee (epees), halberd (halberds), longbow (longbows), blowpipe (blowpipes), landmine (landmines), howitzer (howitzers), kalashnikov (kalashnikovs), uzi (uzis), derringer (derringers), blunderbuss (blunderbusses), flintlock (flintlocks), taser (tasers), airgun (airguns), kukri (kukris), trebuchet (trebuchets), shuriken (shurikens), garrotte (garrottes), poleaxe (poleaxes), semtex (no plural)

**Notes:** Bomb, grenade, axe, club and catapult aren't in the category at all, and knife, bow, arrow and cannon are only in also. Overlap: missile, stinger and sidewinder are also listed as vehicles (see vehicle review); axe, hatchet, switchblade and penknife sit in "tool" too, which is fine for axe and hatchet.

### sport: A sport or game

Now: 77 main, 119 also.

**Remove:** monte (three-card trick, not a UK game name), sweepstake, sweepstakes (a prize draw, not a game), goes, numbers, stops, contract, contracts (bridge jargon), cast, casts, casting, flip, flips, crab, crabs, dip, dips, parliament, rum, napoleon, cinch, meld, samba, bolivia (obscure card games or jargon), bathe, bathes, floating, troll, trolls, trolling (fishing technique), steal, steals, draw, draws, drawing, drawings, auction, auctions, pitch, pitches, overcast (none of these name a sport or game)

**Add, promote from also:** running, boxing, chess, poker, cricket, bowling, wrestling, skiing, skating, cycling, rowing, squash, surfing, diving, polo, riding, croquet, softball, angling, monopoly, scrabble, checkers, roulette, blackjack, rummy, solitaire, patience, bridge, craps, crib, skittles, hurling, leapfrog, tag, jacks, sledding, snorkelling, racquetball

**Add, in dictionary:** marathon, triathlon, decathlon, javelin, discus, hurdles, sprinting, mountaineering, caving, rafting, skydiving, parachuting, gliding, motocross, rallying, speedway, shooting, jujitsu, kendo, aerobics, jousting, dodgeball, skeleton, snap, pontoon, brag, charades, sardines, hangman, twister, pinball, skipping

**Add, NOT IN DICTIONARY:** draughts (no plural), rounders (no plural), cribbage (no plural), ludo (no plural), quoits (no plural), conkers (no plural), tiddlywinks (no plural), pentathlon (pentathlons), heptathlon (heptathlons), biathlon (biathlons), bobsleigh (bobsleighs), luge (luges), slalom (slaloms), steeplechase (steeplechases), frisbee (frisbees), weightlifting (no plural), bodybuilding (no plural), showjumping (no plural), dressage (no plural), horseracing (no plural), motorsport (motorsports), karting (no plural), waterskiing (no plural), kitesurfing (no plural), trampolining (no plural), pilates (no plural), aikido (no plural), shinty (no plural), petanque (no plural), boules (no plural), paintball (no plural), punting (no plural), falconry (no plural)

**Notes:** Most of the obvious answers (chess, boxing, cricket, skiing, running, poker) are only in also, so main is short of everyday answers. The overrides file removes a word but not its plural (plunge and plunges had to be listed separately), so plurals need their own minus. footballs and baseballs in main are the balls, not the sports; harmless, but they inflate the count.

### plant: A plant or flower

Now: 312 main, 261 also.

**Remove:** tuna (prickly pear sense), popcorn (the snack), benny (sesame, US slang), chess (a grass, rare), ling (heather, rare), moms, mums (chrysanthemums, US), milo, milos (sorghum, rare), medic, medics (medick, rare), timothy (a grass, rare), coco (coconut palm, rare), tung (rare tree), andromeda (rare shrub), snowball, snowballs (guelder rose, rare), pia (rare), fuji (cherry variety), embryo, embryos (not a plant), strangler (fig, rare), dahl (food), absinthe (wormwood sense), paprika, cayenne (spices), belvedere (rare), chatterbox (orchid, rare), soma (rare), calypso (orchid, rare), titi (rare tree), bloomer (rare), wahoo (rare shrub), robles (Spanish oaks), pixy, pixie, pixies (moss, rare), arbor, arbour (a bower), mariposa (lily, rare), chardonnay, pinot, merlot, riesling (wines), coles (plural left behind when cole was removed), simple, escape, escapes, cheese, cheeses, charity, charities, cheat, cheats, galaxy, galaxies, brake, brakes, bent, torch, torches, poke, pokes, tis, cube, cubes, clocks, snag, snags, composite, archangel, weld, gumbo, samba, blackjack, mate, mates, maria, sang, oka, anil, kino, blinks, endemic, flamboyant, glad, flag, flags, ramp, ramps (all only via rare plant names)

**Add, promote from also:** oak, lavender, carnation, chrysanthemum, hyacinth, magnolia, bamboo, nettle, mint, sage, palm, bay, ash, birch, maple, fir, yew, hazel, chestnut, cypress, eucalyptus, sycamore, poplar, spruce, alder, elder, broom, dock, hemlock, sorrel, rush, stock, cosmos, honesty, bugle, succulent, evergreen, cotton, tobacco, hemp, flax, hop, papyrus, ginseng, mandrake, sugarcane, grapevine

**Add, in dictionary:** moss, mistletoe, seaweed, ficus

**Add, NOT IN DICTIONARY:** foxglove (foxgloves), snowdrop (snowdrops), crocus (crocuses), hydrangea (hydrangeas, already in dictionary), rhododendron (rhododendrons), clematis (no plural), begonia (begonias), delphinium (delphiniums), hollyhock (hollyhocks), snapdragon (snapdragons), sweetpea (sweetpeas), freesia (freesias), gladiolus (gladioli), cornflower (cornflowers), cyclamen (cyclamens), cowslip (cowslips), gorse (no plural), bramble (brambles), bindweed (no plural), ragwort (no plural), nasturtium (nasturtiums), lobelia (lobelias), poinsettia (poinsettias), amaryllis (amaryllises), forsythia (forsythias), laburnum (laburnums), buddleia (buddleias), privet (privets), oleander (oleanders), bougainvillea (bougainvilleas), sedge (sedges), bulrush (bulrushes), watercress (no plural), yarrow (no plural), celandine (celandines), hellebore (hellebores), gentian (gentians), zinnia (zinnias), gerbera (gerberas), lupine (lupines), cress (no plural)

**Notes:** Biggest problem: a large share of common British garden flowers (foxglove, snowdrop, crocus, rhododendron, clematis, begonia) are not in the dictionary at all, and hydrangea is missing though hydrangeas is in main. The overrides file removed cole but its plural coles survives in main, so every minus needs its plural too. Overlap with fruit and vegetable is inconsistent: banana, mango, broccoli, spinach and pumpkin are main here while apple, potato, tomato, carrot and onion are only also; decide whether food crops count and apply it both ways. Every tree is also valid here, so tree adds should be mirrored.

### tree: A tree

Now: 59 main, 83 also.

**Remove:** coco (rare), tung (rare), fuji (cherry variety), titi (rare), robles (Spanish oaks), arbor, arbour (a bower; arbor is only Latin for tree), peppermint (eucalyptus sense, rare), clove, cloves (spice), coffee, coffees, mate, mates, maria, blackjack, samba, snag, snags, flamboyant, kino (all rare tree names)

**Add, promote from also:** oak, ash, birch, maple, fir, yew, spruce, sycamore, poplar, alder, chestnut, walnut, hazel, apple, cherry, pear, plum, palm, olive, lime, lemon, orange, peach, fig, cypress, eucalyptus, magnolia, linden, mahogany, teak, ebony, hickory, pecan, coconut, avocado, gum, bay, locust, hemlock, rosewood, myrtle, cinnamon

**Add, in dictionary:** elder, hawthorn, juniper, redwood, evergreen, plane, date, sandalwood, rubber, box, cork

**Add, NOT IN DICTIONARY:** larch (larches), sequoia (sequoias), baobab (baobabs), conifer (conifers), hornbeam (hornbeams), blackthorn (blackthorns), damson (damsons), sloe (sloes), whitebeam (whitebeams), dogwood (dogwoods), laburnum (laburnums), jacaranda (jacarandas), ginkgo (ginkgos), balsa (balsas), boxwood (boxwoods), medlar (medlars), crabapple (crabapples), tamarisk (tamarisks), deodar (deodars), wattle (wattles), kapok (kapoks)

**Notes:** Main is badly short: the first answers anyone gives (oak, ash, birch, maple, fir, yew, apple, cherry) are all only in also, so main is mostly fruit and nut trees (mandarin, satsuma, papaya, durian, macadamia). Main also holds plural-only fruit entries (plums, almonds, apricots, tangerines) while the singulars plum and tangerine sit in also; promoting the singulars fixes this. Keep in step with the plant category, which accepts every tree.

### weather: Weather

Now: 58 main, 76 also.

**Remove:** elves (not weather), whiff, duster, clemency (mild weather, archaic), quiets (plural left behind when quiet was removed), bolt, bolts (only as thunderbolt), inversion (technical), air, airs, breath, breaths, pocket, pockets, sweat, sweats, easter (wind, archaic), puff, puffs, blow, blows, trade, trades, jet, jets, wave, waves, downfall, softness, roughness, scattering, stillness, calmness, conditions, warming (none would be given as a kind of weather)

**Add, promote from also:** fog, frost, cloud, shower, sunshine, sleet, thaw, squall, tempest, deluge, flurry, precipitation, westerly, southerly, front, depression, calm, lull, sprinkle

**Add, in dictionary:** mist, haze, thunder, ice, drought, humidity, heat, cold, rainbow, smog, slush, flood, sun, sunny, rainy, windy, cloudy, foggy, misty, stormy, snowy, frosty, icy, humid, hazy, chilly, freezing, breezy, mild, warm, hot, damp, wet, dry, fine

**Add, NOT IN DICTIONARY:** heatwave (heatwaves), hailstorm (hailstorms), cloudburst (cloudbursts), hoarfrost (no plural), rime (no plural), whiteout (whiteouts), snowdrift (snowdrifts), easterly (easterlies), northerly (northerlies), sirocco (siroccos), mistral (mistrals), waterspout (waterspouts), anticyclone (anticyclones), muggy (adjective), blustery (adjective), showery (adjective), drizzly (adjective), thundery (adjective)

**Label:** "A kind of weather" (the bare "Weather" reads as a topic, not a prompt like the others; also decide whether adjectives count)

**Notes:** The adjective question is the big one. Asked for weather, many people's first answers are sunny, rainy, windy, cloudy, foggy; overcast is already in main, so the list half-accepts adjectives. Either add the weather adjectives above (and the NOT IN DICTIONARY ones) or drop overcast and keep the list to nouns. Also, fog, frost, cloud, shower and sunshine are only in also, which is a clear miss. The overrides file removed quiet but left quiets in main; minus entries need plurals too.

### landform: A natural landscape feature

Now: 158 main, 159 also.

**Remove:** wormholes, cavities, collectors (plurals left behind by earlier removals of wormhole, cavity, collector), laguna (Spanish), drink, drinks, key, keys (cay sense, rare), table, tables (tableland, rare), raise, raises, upgrade, upgrades, decline, declines, climb, climbs, cant, recess, recesses, bench, benches, feeder, feeders, holler, hollers (US hollow), vein, veins, offing, briny, wallow, gut, guts, run, runs, wash, washes, sink, sinks, branch, branches, crossing, crossings, bottom, bottoms, kettle, kettles (geology only), descent, steep, elevation, uphill, downhill, swell, swells, drop, drops (all fit only via rare or generic senses)

**Add, promote from also:** volcano, gorge, cavern, geyser, falls, spring, mound, tor, highland, downs, range, channel, sound, trench, hollow, terrace, cascade, cataract, basin, shoal, shallows, firth, strand, mere, ford, puddle, quicksand, shelf, point, mouth

**Add, in dictionary:** peak, summit, mountaintop, chasm, abyss, crevice, fissure, cleft, rift, pass, col, gap, coastline, shoreline, spit, archipelago, harbour, watershed, whirlpool, fell, beck, burn, gill, arch, stack, outback, bush, taiga, grove, mire, quagmire, slough, shingle, coral

**Add, NOT IN DICTIONARY:** crag (crags), hillock (hillocks), outcrop (outcrops), escarpment (escarpments), scree (screes), crevasse (crevasses), sandbank (sandbanks), sandbar (sandbars), isthmus (isthmuses), islet (islets), promontory (promontories), tributary (tributaries), rivulet (rivulets), meander (meanders), oxbow (oxbows), floodplain (floodplains), lough (loughs), moraine (moraines), caldera (calderas), rainforest (rainforests), scrubland (scrublands), spinney (spinneys), foreshore (foreshores), rockpool (rockpools), combe (combes), cwm (cwms), wold (wolds), pampas (no plural), veld (velds), drumlin (drumlins), karst (no plural), foothill (foothills, already in dictionary), hummock (hummocks), clifftop (clifftops)

**Label:** "A landscape feature" (the list already includes field, pasture, hedgerow and other managed land, so "natural" promises less than it delivers)

**Notes:** The earlier pass fixed the main gaps; the remaining issue is that removals in the overrides file did not carry their plurals (wormholes, cavities, collectors still listed). Main still lacks volcano, gorge, peak, summit, cavern and geyser, which are among the first answers people give. Molehill and anthill are in main; borderline as they are animal-made, but harmless.

### disease: An illness

Now: 78 main, 50 also.

**Remove:** hickey (love bite), pimple, pimples, zit, zits (spots, not illnesses), dandruff (not an illness), pests ("pest" is already removed by an override but its plural survives), lichen (rare skin-disease sense; a plant), blackwater (only in "blackwater fever"), polyps (a growth, not an illness)

**Add, promote from also:** plague, aids, shingles, scurvy, malady, consumption, mono, clap

**Add, in dictionary:** malaria, dementia, stroke, epilepsy, migraine, fever, virus, bug, infection, ailment, disorder, syndrome, affliction, complaint, sepsis, tetanus, appendicitis, laryngitis, encephalitis, osteoporosis, anaemia, hypertension, hypothermia, jaundice, thrush, scabies, salmonella, diarrhoea, ulcer, hernia, gangrene, schizophrenia, anorexia, bulimia, insomnia, narcolepsy, allergy, sclerosis, lumbago, sciatica, thrombosis, embolism, aneurysm, vertigo, cough, depression, legionnaires

**Add, NOT IN DICTIONARY:** covid (no plural), ebola (no plural), norovirus (noroviruses), rubella (no plural), tonsillitis (no plural), sinusitis (no plural), gastroenteritis (no plural), conjunctivitis (no plural), cystitis (no plural), dermatitis (no plural), impetigo (no plural), ringworm (no plural), verruca (verrucas), alzheimers (no plural), parkinsons (no plural), lurgy (lurgies), hayfever (no plural), pleurisy (no plural), tinnitus (no plural), catarrh (no plural), osteoarthritis (no plural), carcinoma (carcinomas), sarcoma (sarcomas), haemophilia (no plural), septicaemia (no plural)

**Notes:** Main is very thin (78) for such a rich topic: malaria, dementia, stroke, epilepsy and migraine are missing, and generic words (virus, bug, infection, ailment) are absent while spots and dandruff are in. Modern illnesses (covid, ebola, norovirus) need extra-words.txt. Depression and anxiety overlap with feeling, which is fine.

### crime: A crime

Now: 38 main, 63 also.

**Remove:** mayhem (legal "maiming" sense, players mean chaos), job, jobs (slang for a robbery), protection, protections, attempt, attempts, rolling, capture, captures, commission, commissions, tribute, tributes, petty, rig, rigs, seizure, seizures (all rare senses), sting, stings (a police operation, not a crime), cheat, cheats (a person)

**Add, promote from also:** assault, kidnapping, blackmail, shoplifting, mugging, forgery, extortion, hijacking, carjacking, heist, scam, swindle, racketeering, bigamy, battery, offence, hijack, raid, con, graft, skimming, infringement, shakedown, hustle

**Add, in dictionary:** murder, manslaughter, homicide, arson, smuggling, trafficking, poaching, vandalism, trespass, terrorism, espionage, stalking, counterfeiting, corruption, looting, rioting, speeding, libel, slander, defamation, harassment, hacking, plagiarism, joyriding, graffiti, littering, loitering, bootlegging, laundering, genocide, assassination, poisoning, kidnap, stabbing, sabotage, mutiny, desertion, treachery, vagrancy, blasphemy, contempt, misconduct, cruelty, hooliganism, wounding, polygamy, usury, squatting, sacrilege, torture, slavery, lynching

**Add, NOT IN DICTIONARY:** pickpocketing (no plural), affray (affrays), cybercrime (cybercrimes), regicide (regicides), infanticide (infanticides), patricide (patricides), matricide (matricides), fratricide (fratricides)

**Notes:** Murder, the answer nearly everyone gives first, is not in the category at all, nor are arson, manslaughter, smuggling or vandalism. Main is tiny (38, of which 13 are plurals). "crime" and "crimes" sit in main as answers to "A crime"; harmless but could go. rape and incest are blocked, so they stay out.

### sound: A sound

Now: 117 main, 202 also.

**Remove:** angelus (a prayer and its bell, not a sound word), vibrato (singing technique), pants (trousers; pant itself can stay), report, reports (gunshot sense), plump, trample, tramp, tramps, grate, eruption, eruptions, scrapings (rare senses)

**Add, promote from also:** bang, crack, beep, whistle, whisper, scream, cry, sigh, snap, hum, hiss, ping, ding, creak, thump, howl, bark, meow, miaow, moo, purr, quack, honk, tweet, chirp, squawk, neigh, oink, baa, splat, plop, sizzle, whoosh, jingle, jangle, clink, clank, clunk, twang, swish, drip, slam, snore, shriek, toot, coo, bray, cackle, twitter, patter, caw, whinny, chatter, uproar, explosion, bleep, toll, knell, whizz, vroom, pop, scratch, gobble, ticktock

**Add, in dictionary:** echo, cough, sneeze, laugh, laughter, giggle, chuckle, hiccup, burp, belch, groan, moan, whine, wail, shout, yell, snort, sniff, gasp, sob, whimper, cheer, applause, chime, gong, siren, fizz, drone, croak, hoot, warble, trill, thwack, smack, slap, squelch, slurp, gulp, glug, babble, snarl, yap, woof, zoom

**Add, NOT IN DICTIONARY:** gurgle (gurgles, already in dictionary), crinkle (crinkles), yowl (yowls, already in dictionary), splutter (splutters, already in dictionary), tock (tocks), hiccough (hiccoughs), splosh (sploshes)

**Notes:** Systematic skew: main is full of plurals and -ing forms (grunts, knocking, whirring) while the basic singular sound words (bang, crack, beep, whistle, scream, hiss, and nearly every animal noise) sit only in also. Human sounds (cough, sneeze, laugh, shout, groan) are missing altogether. chink is blocked, so it stays out. Some overlap with music (song, chorus, voice); acceptable, both readings are sounds.

### colour: A colour

Now: 55 main, 70 also.

**Remove:** complementary (adjective, not a colour), redness (a quality, not a colour name), carnation, carnations (a flower; the colour sense is obscure)

**Add, promote from also:** rose, lemon, cherry, salmon, jade, chocolate, coffee, wine, pearl, blonde, sapphire, apricot, mahogany, tangerine, chestnut, hazel, saffron, vermilion, azure, caramel, mocha, chartreuse, straw, canary, sable, buff, cardinal, topaz, alabaster, bone

**Add, in dictionary:** mustard, lime, plum, sky, fuchsia, cobalt, auburn, ginger, mint, sage, slate, platinum, magnolia, primrose, sienna, periwinkle, amethyst, brick, sand, stone, denim, petrol, mulberry, raspberry, heather, honey, butterscotch, avocado, pistachio, champagne, cinnamon, oyster, nude, blush, marigold, orchid, rouge, flame

**Add, NOT IN DICTIONARY:** cyan (cyans), ochre (ochres), taupe (taupes), terracotta (terracottas), aubergine (aubergines), cerise (cerises), sepia (sepias), umber (umbers), ultramarine (ultramarines), aquamarine (aquamarines), puce (puces), ecru (ecrus), cerulean (ceruleans), russet (russets), pewter (pewters), cornflower (cornflowers), damson (damsons), gunmetal (gunmetals)

**Notes:** The existing override already has +cyan and +ochre, but both are NOT IN DICTIONARY, so those entries do nothing until cyan, cyans, ochre and ochres go into extra-words.txt. Many colour names are fruit, flower or material words whose main sense lies elsewhere, hence the long promote list.

### music: A kind of music or dance

Now: 37 main, 96 also.

**Remove:** ligature (a notation mark), orchestration (the act of arranging), piece, pieces, score, scores, movement, movements, arrangement, arrangements, passage, passages, phrase, phrases, extension, extensions, transition, transitions, cadence, instrumentation, realization, realisation, intro (parts or handling of a piece, not a kind of music), genre, genres (the word itself), troll, trolls, keen, dui, gradual, voluntary, lay, lays, study, studies (rare senses), rocks, punks, folks (stones and people)

**Add, promote from also:** rock, pop, jazz, punk, folk, soul, rap, disco, funk, gospel, classical, swing, jive, boogie, bebop, bluegrass, choral, carol, shanty, march, chant, fugue, canon, mass, round, duet, solo, trio, quartet, impromptu, lament, scat, pastoral

**Add, in dictionary:** country, house, garage, grime, metal, indie, ska, grunge, trance, ambient, baroque, calypso, ragtime, waltz, tango, salsa, samba, foxtrot, rumba, mambo, polka, twist, flamenco, ballet, tap, ballroom, conga, limbo, jig, reel, minuet, bolero, fandango, cancan, hula, morris, lindy, shimmy, prelude, overture, rhapsody, jingle, yodel, musical, cabaret, vaudeville, instrumental, soundtrack, chorus

**Add, NOT IN DICTIONARY:** techno (no plural), dubstep (no plural), electronica (no plural), rockabilly (no plural), skiffle (no plural), britpop (no plural), motown (no plural), emo (no plural), dixieland (no plural), bhangra (no plural), zumba (no plural), plainsong (no plural), quickstep (quicksteps), charleston (charlestons), breakdance (breakdances), macarena (macarenas), hornpipe (hornpipes), gavotte (gavottes), lambada (lambadas), merengue (merengues), ceilidh (ceilidhs), tarantella (tarantellas), madrigal (madrigals), motet (motets), oratorio (oratorios), cantata (cantatas), nocturne (nocturnes), polonaise (polonaises), mazurka (mazurkas), toccata (toccatas)

**Notes:** Main has no popular genre (rock, pop, jazz, folk all sit in also) and not a single named dance: the only dance is the word "dance" itself, so the "or dance" half of the label currently delivers nothing. The label is right once the dances are added. Hip hop is two words; "hiphop" is not in the dictionary and could be added if a one-word spelling is wanted. tap, twist, reel, jig, house, garage, metal, country and limbo all have strong other meanings, but each is a clear answer in this context.

## New categories

Sizes were measured, not estimated. NLTK and WordNet were installed and a throwaway script loaded `scripts/build-categories.py` itself (its `main_senses` and `all_senses`), ran it over every word in `data/words.txt` for each candidate's synsets, and left out blocklisted words. The counts below are distinct singular answers (a plural whose singular is also listed is counted once); `category-report.php` counts plural forms too, so its figures would be about a third higher (given in brackets). "Main" is before any overrides, so it still includes the odd members named under Caveats, and the everyday answers that are only in also need `+` overrides to count. Example answers marked (also) are in the dictionary but only in also; (not in dictionary) means extra-words.txt is needed.

### dessert: "A dessert or sweet"

Synsets: dessert.n.01, candy.n.01, cake.n.03, cookie.n.01, pudding.n.03, frozen_dessert.n.01. Size: about 35 main, 41 also (50 and 64 with plurals), measured.

Examples: cake (also), pudding (also), trifle (also), biscuit (also), fudge (also), brownie (also), doughnut (also), cookie, cupcake, cheesecake, toffee, lollipop, marshmallow, sundae, sorbet, gingerbread, shortbread, tiramisu, nougat, macaroon.

Caveats: the best-known answers (cake, pudding, biscuit, trifle, fudge, mousse, jelly, tart, brownie, liquorice, caramel, crumble) are only in also and need promoting, which takes it to about 60. Remove odd main members: tortilla, pancake, hotcakes, flapjacks, crumpet, waffle (arguable), sinker, duff, compote. Sits inside "A food" (all of main overlaps it), as fruit and vegetable already do. Leaving out sweet.n.03 keeps jam, marmalade and chewing gum out.

### dish: "A dish or meal"

Synsets: dish.n.02. Size: about 65 main, 33 also (81 and 51), measured.

Examples: pizza, soup, sandwich, salad, pasta, spaghetti, lasagne, curry (also), stew (also), burger (also), omelette, risotto, porridge, casserole, kebab, sushi, paella, goulash, chowder, haggis.

Caveats: wholly inside "A food", but it is a different question (a cooked dish rather than an ingredient). Promote curry, stew, burger, taco, hash, gumbo. Remove grinder, mulligan, crisp, patty, custard (a dessert), poi. Many British dishes (shepherd's pie, toad in the hole) are phrases and can't be answers; good singles to add if missing: roast, pie, hotpot, kedgeree, fry-up is two words.

### flavouring: "A herb, spice or sauce"

Synsets: flavorer.n.01. Size: about 28 main, 54 also (33 and 69), measured.

Examples: salt (also), pepper (also), mustard (also), ketchup, mayonnaise, gravy, vinegar, garlic (also), ginger (also), basil (also), mint (also), parsley (also), thyme (also), rosemary (also), cinnamon (also), nutmeg (also), paprika (also), chutney, pesto, salsa.

Caveats: the idea "A herb or spice" on its own fails: herb.n.02 and spice.n.02 give only 2 main answers, because WordNet's main sense of basil, sage, mint and the rest is the plant. Widening it to sauces and condiments gives a usable main list, and almost every herb and spice is already in also, ready to promote (about 30 of them), taking it to about 60. Remove from main: honey, sugar, syrup, treacle, molasses, sweetener, marge, margarine, oolong, carbonara (a dish). Overlaps "A food" (all of main) and, for herbs, "A plant or flower" (as also).

### fabric: "A fabric"

Synsets: fabric.n.01, leather.n.01. Size: about 56 main, 96 also (70 and 132), measured.

Examples: cotton (also), wool, silk, denim (also), linen, leather, velvet, nylon (also), polyester (also), lace (also), satin, tweed, cashmere, corduroy, suede, felt (also), fleece (also), canvas, chiffon, muslin.

Caveats: fabric.n.01 counts things made of cloth, so remove sail, towel, rag, handkerchief, hanky, hankie, tarp, bandana, mackintosh, macintosh, cobweb, needlepoint, yoke, tatters, swatches, mainsail, gunny, and leather's alligator, morocco and mocha. Promote cotton, denim, nylon, polyester, lace, felt, fleece, jersey, khaki, calico, brocade, terry. Adding fiber.n.01 would bring in hemp and flax but also straw, bristles and spindle, so better as `+` lines. No overlap with existing categories.

### metal: "A metal"

Synsets: metallic_element.n.01, alloy.n.01. Size: about 44 main, 16 also (48 and 19), measured, but only about 32 are real answers.

Examples: gold (also), silver, iron, steel, copper, tin, lead (also), brass, bronze, aluminium, zinc, nickel, platinum, mercury, titanium, chrome, uranium, magnesium, cobalt, tungsten.

Caveats: about a dozen main words are plurals of chemical symbols or letter names read as elements (las, tis, lis, bas, ess, ers, tas, bis) or odd (eureka, alum), and must be removed. Promote gold, lead, quicksilver, alloy. Pewter is not in the dictionary. On the small side; merging it with gems ("A metal or precious stone") is an option if the owner prefers, but rock below covers gems better.

### rock: "A rock, mineral or gem"

Synsets: rock.n.02, mineral.n.01, gem.n.02, jewel.n.01. Size: about 43 main, 25 also (51 and 32), measured.

Examples: stone (also), diamond, ruby, emerald, pearl, sapphire (also), granite, marble, slate (also), chalk (also), quartz (also), jade, opal, amethyst (also), limestone, sandstone, lava, gravel, flint, coal.

Caveats: covers the "gemstone" idea, which alone has only about 15 main. Remove concentrate, hyacinth, sienna, sima, tripoli, solitaire, sparkler, rhinestones, talcum, emery (arguable). Promote stone, rock, slate, chalk, quartz, sapphire, amethyst, topaz, pumice, coral (arguable). Flint, coal and crystal are in the dictionary but not reached by these synsets: add by hand. Overlaps "Something you wear" on about 11 gem words (jewel, pearl, diamond).

### container: "A container"

Synsets: bag.n.01, box.n.01, vessel.n.03, basket.n.01, case.n.05, bin.n.01, bottle.n.01, jar.n.01, can.n.01, bucket.n.01, tub.n.02, receptacle.n.01. Size: about 83 main, 81 also (118 and 116), measured.

Examples: box, bag, bottle, jar, tin (also), can, bucket, basket, bin, sack, crate, case (also), barrel (also), carton (also), tub, jug, vase, flask, suitcase, envelope (also).

Caveats: container.n.01 itself can't be used, because WordNet files cars, lorries and ambulances under it (75 overlaps with "A vehicle"); the listed subtrees avoid that. Remove toby, magnum, kit, compact, portfolio, cesspool, bidet, bedpan, bath, bathtub, boiler, sump, bier, pix, moneybags, beanbag. Promote tin, case, barrel, carton, chest, trunk, mug, pitcher, keg, hamper, purse, backpack, envelope, packet. Overlaps "Something in a kitchen" on a handful (kettle, teapot, pot).

### reptile: "A reptile or amphibian"

Synsets: reptile.n.01, amphibian.n.03. Size: about 32 main, 17 also (48 and 26), measured.

Examples: snake, lizard, frog, toad, crocodile, alligator (also), tortoise, turtle (also), newt, chameleon (also), gecko, iguana, cobra, python, viper, adder (also), rattlesnake, salamander, anaconda, dinosaur.

Caveats: promote alligator, turtle, chameleon, boa, adder, terrapin (not in the dictionary). Dinosaurs (tyrannosaurus, triceratops, pterodactyl) come in as reptiles; keep them, they're fun answers. Remove cooter, ridley (fine but obscure), constrictor (arguable). All of it sits inside "An animal", as bird and fish do.

### sea: "A sea creature (not a fish)"

Synsets: crustacean.n.01, mollusk.n.01, cetacean.n.01, echinoderm.n.01, coelenterate.n.01, pinniped_mammal.n.01, shellfish.n.01, sea_turtle.n.01, shark.n.01, ray.n.07, sea_cow.n.01, sponge.n.04, octopus.n.02, decapod.n.01. Size: about 25 main, 39 also (32 and 54), measured; about 55 after promotions.

Examples: whale (also), dolphin (also), seal (also), octopus (also), squid (also), crab, lobster, shrimp (also), prawn (also), jellyfish, starfish, oyster, mussel, clam, walrus, shark, coral (also), sponge (also), scallop (also), anemone (also).

Caveats: the weakest of the list before overrides, because whale, dolphin, seal, octopus and squid have other main senses; with about 20 promotions it is comfortably big enough. Remove snail and slater (land animals), crayfish (freshwater), quahog (obscure but fine), krill (fine). "(not a fish)" in the label keeps it from duplicating "A fish"; sharks and rays are included because players will think of them, which overlaps "A fish" a little. Seahorse is not in the dictionary.

### dog: "A kind of dog"

Synsets: dog.n.01. Size: about 22 main, 21 also (30 and 28), measured; about 40 after promotions.

Examples: poodle, labrador, spaniel, terrier, beagle, collie, greyhound, bulldog (also), boxer (also), husky (also), dalmatian (also), chihuahua (also), pug, dachshund, rottweiler, retriever, setter (also), pointer (also), sheepdog, puppy.

Caveats: needs promotions to clear 30: bulldog, boxer, husky, dalmatian, chihuahua, pointer, setter, springer, cocker, basset, mongrel, pooch, afghan, chow, cairn. Labrador is in the dictionary; alsatian, whippet, corgi, schnauzer and labradoodle are not. "A kind of dog" rather than "A dog breed" so puppy, mutt and hound also count. Inside "A mammal" and "An animal".

### unit: "A unit of measurement"

Synsets: linear_unit.n.01, mass_unit.n.01, volume_unit.n.01, area_unit.n.01, temperature_unit.n.01, angular_unit.n.01, electromagnetic_unit.n.01, work_unit.n.01, power_unit.n.01, pressure_unit.n.01. Size: about 63 main, 71 also (91 and 106), measured.

Examples: metre, inch, foot (also), mile, yard, pound, ounce (also), stone (also), ton, gram, kilo, litre, pint, gallon, acre, degree (also), watt, volt, calorie, second (also).

Caveats: the whole unit_of_measurement.n.01 tree mixes in currencies (dollar, penny, euro) and time words (tomorrow, yesterday, eve); using these subtrees keeps both out. Add second, minute, hour, day, week, month, year by override if time units should count. Remove henry, gilbert, lambert, lea, gal, mole, mol, fifth, mesh, cicero, nit, emu, ems, dal, dag, kor, hin, grad, rad. Promote foot, ounce, stone, degree, metric, fathom, knot, league. Centimeter is in the dictionary but centimetre is not, and neither millilitre nor milliliter is, so these need extra-words.txt or a spelling link.

### money: "A currency or coin"

Synsets: monetary_unit.n.01, coin.n.01. Size: about 57 main, 33 also (78 and 47), measured.

Examples: pound (also), penny, dollar, euro, cent, pence, yen (also), franc, rupee, peso, quid, dime, nickel (also), guinea, shilling (also), sixpence, farthing, lira, ruble, sovereign (rouble, the UK spelling, is not in the dictionary).

Caveats: many main words are foreign currencies few players know (hao, jiao, chon, fen, lek, lats, taka), which is fine as rare answers. Remove anna, mongo, zaire, leone, sen, heller, mil, agora (all clash with other everyday meanings or are obsolete and confusing). Promote pound, yen, nickel, shilling, crown, mark, won, real, sovereign. No overlap with existing categories; overlaps the unit idea only if the whole unit tree were used.

### myth: "A mythical creature"

Synsets: imaginary_being.n.01, mythical_being.n.01, spirit.n.04, fairy.n.01. Size: about 54 main, 28 also (82 and 42), measured.

Examples: dragon, unicorn, fairy, mermaid, vampire, werewolf, ghost (add by hand), troll, elf, goblin, giant (also), witch (also), zombie (also), phoenix (also), centaur, griffin, leprechaun, gnome, pixie, yeti.

Caveats: remove character, booger, bogey, bogie, puck, fay, argus, daimon, daemon, lamia, sandman (arguable), thunderbird, doppelganger (arguable). Promote giant, witch, zombie, phoenix, ogre, sphinx, ghoul, dwarf, harpy, alien. Ghost is not reached by these synsets and should be added by hand. Leaving out spiritual_being.n.01 keeps god, saint and angel out, avoiding a religious mix; devil and demon still come in through spirit.n.04, which seems fine. No overlap with existing categories.

### boat: "A boat or ship"

Synsets: boat.n.01, ship.n.01, vessel.n.02. Size: about 37 main, 44 also (51 and 70), measured.

Examples: boat, ship, yacht, canoe (also), kayak (also), ferry (also), barge, submarine, dinghy, raft (add by hand), liner (also), tug (also), trawler (also), lifeboat, frigate, galleon, punt (also), gondola (also), speedboat, battleship.

Caveats: remove scooter, gig, shipwreck, nautilus, showboat, hoy, pontoon (arguable). Promote canoe, kayak, ferry, liner, tug, trawler, punt, gondola, cruiser, schooner, clipper, steamer, ark, junk, dugout. All of it sits inside "A vehicle" (37 of its 164 main), a much smaller share than drink/alcohol below.

### subject: "A subject you can study"

Synsets: natural_science.n.01, social_science.n.01, mathematics.n.01, medicine.n.01, engineering.n.02, history.n.01, philosophy.n.01, literature.n.01, linguistics.n.01, law.n.04, architecture.n.01, fine_arts.n.01, music.n.04. Size: about 79 main, 59 also (87 and 78), measured; about 55 real answers before promotions.

Examples: maths, English (add by hand), history, geography, science (add by hand), physics, chemistry, biology, music (also), art (add by hand), law (also), economics, psychology (add by hand), sociology, philosophy, literature, astronomy, geology, medicine, engineering (also).

Caveats: discipline.n.01 itself can't be used, because performing_arts brings in singing, acting, tango and karaoke. philosophy.n.01 brings doctrines (creed, dogma, feminism, idealism, tenets, credo) and history.n.01 brings testament and mitzvah: remove those plus its, imitation, decomposition, triangulation, sutra, diagnostics, biometrics, eugenics. Promote music, law, engineering, politics, botany, zoology, ecology, calculus, computing, technology. Add science, English, French, drama, art, psychology, theology (all in the dictionary; English and science are not reached by these synsets). No overlap with existing categories.

### Rejected

a shape (main is mostly junk such as notch, groove and rut, and real shapes like sphere, pyramid and pentagon are only in also, hexagon not in the dictionary; it would have to be built almost entirely by hand), a toy (8 main, 17 also), a hairstyle (7 main, 13 also; bun, quiff and mullet are other senses), a game, board or card (14 main, and it duplicates "A sport or game", which already covers game.n.01), a gemstone on its own (about 15 main; folded into rock), a herb or spice on its own (2 main; folded into flavouring), something from a bakery (41 main but overlaps dessert heavily on cakes and biscuits, and lies inside food), an alcoholic drink (45 main, but that is over half of "A drink" itself), a medicine (25 main, and drug.n.01 pulls in all the alcohol and slang), a hat or a shoe (15 and 21 main, inside "Something you wear"), a language (only about 13 main and those are junk; most language names such as Spanish and Italian are not in the dictionary), a cheese (4 main), a nut (4 main), a planet or star (6 main), a dance (6 main, and already in "A kind of music or dance"), a religion or religious person (messy: wasp, goy and begum in main, and sensitive), a building material (20 main, mixed), a country, day or month (proper nouns are not in the dictionary).
