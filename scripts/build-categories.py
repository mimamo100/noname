"""Builds data/categories.json: which dictionary words belong to each meaning category.

Uses WordNet, so it only runs on a development machine, never on the web server:

    pip install nltk
    python -c "import nltk; nltk.download('wordnet')"
    python scripts/build-categories.py
    php scripts/build-dictionary.php

A word belongs to a category when its main meaning is a kind of that category:
either its most-used noun sense fits (when it's mostly used as a noun), or its
first listed noun sense fits and WordNet has seen that sense in real text. So
"dog" and "fly" are animals, but "does" isn't one just because a doe is a deer.
Fix individual words in data/category-overrides.txt rather than here.
"""
import json
import os
from nltk.corpus import wordnet as wn

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# id: (label, WordNet synsets whose kinds belong to the category)
CATEGORIES = {
    'animal': ('An animal', ['animal.n.01']),
    'mammal': ('A mammal', ['mammal.n.01']),
    'bird': ('A bird', ['bird.n.01']),
    'fish': ('A fish', ['fish.n.01']),
    'insect': ('An insect or bug', ['insect.n.01', 'arachnid.n.01']),
    'food': ('A food', ['food.n.02', 'food.n.01']),
    'fruit': ('A fruit', ['edible_fruit.n.01']),
    'vegetable': ('A vegetable', ['vegetable.n.01']),
    'drink': ('A drink', ['beverage.n.01']),
    'clothing': ('Something you wear', ['clothing.n.01', 'footwear.n.02', 'headdress.n.01', 'jewelry.n.01']),
    'body': ('A part of the body', ['body_part.n.01']),
    'job': ('A job', ['worker.n.01', 'professional.n.01']),
    'relative': ('A family member', ['relative.n.01']),
    'feeling': ('A feeling', ['feeling.n.01', 'emotion.n.01']),
    'tool': ('A tool', ['tool.n.01']),
    'vehicle': ('A vehicle', ['vehicle.n.01', 'craft.n.02']),
    'instrument': ('A musical instrument', ['musical_instrument.n.01']),
    'furniture': ('A piece of furniture', ['furniture.n.01']),
    'kitchen': ('Something in a kitchen', ['kitchen_utensil.n.01', 'kitchen_appliance.n.01', 'cutlery.n.02', 'crockery.n.01', 'cookware.n.01']),
    'building': ('A building or room', ['building.n.01', 'room.n.01']),
    'weapon': ('A weapon', ['weapon.n.01']),
    'sport': ('A sport or game', ['sport.n.01', 'game.n.01']),
    'plant': ('A plant or flower', ['plant.n.02', 'flower.n.01']),
    'tree': ('A tree', ['tree.n.01']),
    'weather': ('Weather', ['atmospheric_phenomenon.n.01', 'weather.n.01']),
    'landform': ('A landscape feature', ['geological_formation.n.01', 'body_of_water.n.01']),
    'disease': ('An illness', ['disease.n.01', 'illness.n.01']),
    'crime': ('A crime', ['crime.n.01']),
    'sound': ('A sound', ['sound.n.04', 'noise.n.01']),
    'colour': ('A colour', ['chromatic_color.n.01', 'achromatic_color.n.01']),
    'music': ('A kind of music or dance', ['dance.n.01', 'genre.n.03', 'musical_composition.n.01']),
}



def lemma_count(synset, base):
    return sum(l.count() for l in synset.lemmas() if l.name() == base)


def pos_count(word, pos):
    base = wn.morphy(word, pos)
    if not base:
        return 0, None
    return sum(lemma_count(s, base) for s in wn.synsets(base, pos)), base


def main_senses(word):
    """The noun senses that count as the word's main meaning."""
    noun, base = pos_count(word, wn.NOUN)
    if not base:
        return set()
    synsets = wn.synsets(base, wn.NOUN)
    senses = set()
    if lemma_count(synsets[0], base) > 0:  # First listed sense, seen in real text: "fly" the insect.
        senses.add(synsets[0])
    others = max(pos_count(word, p)[0] for p in (wn.VERB, wn.ADJ, wn.ADV))
    has_other_pos = any(pos_count(word, p)[1] for p in (wn.VERB, wn.ADJ, wn.ADV))
    if noun >= others and not (has_other_pos and noun == 0):  # Mostly a noun: "does" and "must" aren't.
        best = max(synsets, key=lambda s: lemma_count(s, base))
        senses.add(best if lemma_count(best, base) > 0 else synsets[0])
    return senses


def main():
    words = [w.strip() for w in open(os.path.join(ROOT, 'data', 'words.txt')) if w.strip()]
    senses = {w: main_senses(w) for w in words}
    out = {}
    for cat_id, (label, roots) in CATEGORIES.items():
        kinds = set()
        for name in roots:
            root = wn.synset(name)
            kinds |= {root} | set(root.closure(lambda s: s.hyponyms()))
        members = [w for w in words if senses[w] & kinds]
        out[cat_id] = {'label': label, 'words': members}
        print(f'{cat_id:12} {len(members):5}  {", ".join(members[:12])}')
    with open(os.path.join(ROOT, 'data', 'categories.json'), 'w') as f:
        json.dump(out, f, indent=1)
    print('Wrote data/categories.json')


if __name__ == '__main__':
    main()
